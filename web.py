"""Authenticated deployment with persistent shared configuration."""
import hmac
import json
import os
import secrets
import sqlite3
import time
from datetime import timedelta
from pathlib import Path
from threading import Lock

from flask import Flask, jsonify, redirect, render_template_string, request, send_from_directory, session
from werkzeug.security import check_password_hash, generate_password_hash
from server import BASE, load_counters, validate_source

ROOT = Path(__file__).resolve().parent
LOGIN = '''<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Accedi · Iscrizioni</title><style>body{font:16px system-ui;background:#f6f8f6;color:#23352f;max-width:360px;margin:10vh auto;padding:24px}form{display:grid;gap:16px}input,button{font:inherit;padding:12px;border:1px solid #ccd7c5;border-radius:6px}button{background:#1d4032;color:white}label{display:grid;gap:6px}</style><h1>Iscrizioni</h1><p>Accesso riservato</p><p role="alert">{{ error }}</p><form method="post"><input type="hidden" name="csrf" value="{{ csrf }}"><label>Nome utente<input name="username" autocomplete="username" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Accedi</button></form></html>'''


def create_app(config=None):
    app = Flask(__name__, static_folder=None)
    app.config.update(SECRET_KEY=os.environ.get('APP_SECRET'),
                      DASHBOARD_USER=os.environ.get('DASHBOARD_USER', 'admin'),
                      DASHBOARD_PASSWORD=os.environ.get('DASHBOARD_PASSWORD'),
                      DATABASE_PATH=os.environ.get('DATABASE_PATH', str(ROOT / '.local/dashboard.sqlite3')),
                      SESSION_COOKIE_HTTPONLY=True, SESSION_COOKIE_SAMESITE='Strict',
                      SESSION_COOKIE_SECURE=os.environ.get('COOKIE_SECURE', 'true').lower() == 'true',
                      PERMANENT_SESSION_LIFETIME=timedelta(hours=12), MAX_CONTENT_LENGTH=10_000_000)
    if config:
        app.config.update(config)
    if not app.config['SECRET_KEY'] or len(app.config['SECRET_KEY']) < 32:
        raise RuntimeError('Configurare APP_SECRET con almeno 32 caratteri casuali.')
    password = app.config.pop('DASHBOARD_PASSWORD', None)
    if not password or len(password) < 12:
        raise RuntimeError('Configurare DASHBOARD_PASSWORD con almeno 12 caratteri.')
    password_hash = generate_password_hash(password)
    del password
    db_path = Path(app.config['DATABASE_PATH'])
    db_path.parent.mkdir(parents=True, exist_ok=True)
    initial = [{'id': 'pisa', 'name': 'Maratona di Pisa', 'url': BASE + 'entrants.json', 'history': None}]
    with sqlite3.connect(db_path) as db:
        db.execute('CREATE TABLE IF NOT EXISTS configuration (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, events TEXT NOT NULL)')
        db.execute('INSERT OR IGNORE INTO configuration VALUES (1, 0, ?)', (json.dumps(initial),))
    attempts, lock = {}, Lock()

    def csrf():
        if 'csrf' not in session:
            session['csrf'] = secrets.token_urlsafe(32)
        return session['csrf']

    @app.before_request
    def protect():
        if request.path == '/healthz':
            return None
        if request.method in ('POST', 'PUT', 'DELETE'):
            token = request.form.get('csrf', '') if request.path in ('/login', '/logout') else request.headers.get('X-CSRF-Token', '')
            if not token or not hmac.compare_digest(token.encode(), session.get('csrf', '').encode()):
                return jsonify(error='Sessione non valida. Ricaricare la pagina.'), 403
        if request.path != '/login' and not session.get('authenticated'):
            if request.path.startswith('/api/'):
                return jsonify(error='Accesso richiesto.'), 401
            return redirect('/login')

    @app.after_request
    def headers(response):
        response.headers['Cache-Control'] = 'no-store'
        response.headers['X-Content-Type-Options'] = 'nosniff'
        response.headers['X-Frame-Options'] = 'DENY'
        response.headers['Referrer-Policy'] = 'same-origin'
        return response

    @app.get('/healthz')
    def health():
        return jsonify(status='ok')

    @app.route('/login', methods=['GET', 'POST'])
    def login():
        error = ''
        if request.method == 'POST':
            identity, now = request.remote_addr or 'unknown', time.monotonic()
            with lock:
                expired = [key for key, value in attempts.items() if now - value[0] > 900]
                for key in expired:
                    attempts.pop(key)
                start, count = attempts.get(identity, (now, 0))
                if count >= 10:
                    return render_template_string(LOGIN, error='Troppi tentativi. Riprovare tra 15 minuti.', csrf=csrf()), 429
                attempts[identity] = (start, count + 1)
            valid_password = check_password_hash(password_hash, request.form.get('password', ''))
            if valid_password and hmac.compare_digest(request.form.get('username', '').encode(), app.config['DASHBOARD_USER'].encode()):
                with lock:
                    attempts.pop(identity, None)
                session.clear()
                session['authenticated'] = True
                session.permanent = True
                csrf()
                return redirect('/')
            error = 'Credenziali non valide.'
        return render_template_string(LOGIN, error=error, csrf=csrf())

    @app.post('/logout')
    def logout():
        session.clear()
        return redirect('/login')

    @app.get('/')
    def index():
        return send_from_directory(ROOT, 'index.html')

    @app.get('/src/<path:filename>')
    def source(filename):
        if not filename.endswith(('.js', '.css')):
            return '', 404
        return send_from_directory(ROOT / 'src', filename)

    @app.get('/data/<path:filename>')
    def data(filename):
        if not filename.endswith('.csv'):
            return '', 404
        return send_from_directory(ROOT / 'data', filename)

    @app.route('/api/events', methods=['GET', 'PUT'])
    def events():
        with sqlite3.connect(db_path) as db:
            if request.method == 'PUT':
                payload = request.get_json(silent=True) or {}
                if not isinstance(payload, dict):
                    return jsonify(error='Richiesta non valida.'), 400
                items = payload.get('events')
                if not isinstance(items, list) or not 1 <= len(items) <= 100:
                    return jsonify(error='Configurazione eventi non valida.'), 400
                seen = set()
                for event in items:
                    if not isinstance(event, dict) or not isinstance(event.get('id'), str) or not event['id'] or event['id'] in seen:
                        return jsonify(error='ID evento non valido o duplicato.'), 400
                    seen.add(event['id'])
                    if not isinstance(event.get('name'), str) or not 1 <= len(event['name'].strip()) <= 150 or not isinstance(event.get('url'), str):
                        return jsonify(error='Nome o link evento non valido.'), 400
                    try:
                        if event['url']:
                            validate_source(event['url'])
                    except ValueError:
                        return jsonify(error='Link Endu non valido.'), 400
                    history = event.get('history')
                    if history is not None and (not isinstance(history, dict) or history.get('mode') not in ('daily', 'weekly', 'cumulative') or not isinstance(history.get('text'), str) or len(history['text'].encode()) > 2_000_000):
                        return jsonify(error='Storico non valido.'), 400
                cursor = db.execute('UPDATE configuration SET events=?, revision=revision+1 WHERE id=1 AND revision=?', (json.dumps(items), payload.get('revision')))
                if cursor.rowcount != 1:
                    return jsonify(error='Configurazione modificata da un’altra pagina. Ricaricare prima di salvare.'), 409
            revision, saved = db.execute('SELECT revision, events FROM configuration WHERE id=1').fetchone()
        return jsonify(events=json.loads(saved), revision=revision, csrf=csrf())

    @app.get('/api/endu/counters')
    def counters():
        try:
            payload = load_counters(request.args.get('url'))
        except ValueError:
            return jsonify(error='Link Endu non valido.'), 400
        return jsonify(payload), 200 if any('data' in value for value in payload['sources'].values()) else 502

    return app
