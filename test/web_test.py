import json
import re
import secrets
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from web import create_app


class PrivateWebTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.password = secrets.token_urlsafe(24)
        self.config = {'TESTING': True, 'SECRET_KEY': secrets.token_urlsafe(32),
                       'DASHBOARD_PASSWORD': self.password, 'SESSION_COOKIE_SECURE': False,
                       'DATABASE_PATH': str(Path(self.temp.name) / 'state.sqlite3')}
        self.app = create_app(self.config)
        self.client = self.app.test_client()

    def login(self, client=None):
        client = client or self.client
        html = client.get('/login').text
        token = re.search(r'name="csrf" value="([^"]+)"', html).group(1)
        response = client.post('/login', data={'username': 'admin', 'password': self.password, 'csrf': token})
        self.assertEqual(response.status_code, 302)
        return client.get('/api/events').json

    def test_dashboard_data_and_assets_require_login(self):
        for path in ['/', '/src/dashboard.js', '/data/pisa-2025-settimane.csv']:
            self.assertEqual(self.client.get(path).status_code, 302)
        for path in ['/api/events', '/api/endu/counters']:
            self.assertEqual(self.client.get(path).status_code, 401)
        self.assertEqual(self.client.get('/healthz').status_code, 200)

    def test_login_csrf_wrong_password_logout_and_no_repository_leak(self):
        self.assertEqual(self.client.post('/login', data={'username': 'admin', 'password': self.password}).status_code, 403)
        self.login()
        with self.client.get('/') as response:
            self.assertEqual(response.status_code, 200)
        self.assertEqual(self.client.get('/.git/config').status_code, 404)
        self.assertEqual(self.client.get('/web.py').status_code, 404)
        with self.client.session_transaction() as session:
            token = session['csrf']
        self.assertEqual(self.client.post('/logout', data={'csrf': token}).status_code, 302)
        self.assertEqual(self.client.get('/api/events').status_code, 401)

    def test_events_persist_across_clients_and_restart_with_conflict_detection(self):
        state = self.login()
        event = {'id': 'future', 'name': 'Golfo 2027', 'url': '', 'history': {'mode': 'weekly', 'text': 'data;iscrizioni\n2026-10-03;195'}}
        response = self.client.put('/api/events', json={'events': [event], 'revision': state['revision']}, headers={'X-CSRF-Token': state['csrf']})
        self.assertEqual(response.status_code, 200)
        response = self.client.put('/api/events', json={'events': [event], 'revision': state['revision']}, headers={'X-CSRF-Token': state['csrf']})
        self.assertEqual(response.status_code, 409)
        restarted = create_app(self.config).test_client()
        self.assertEqual(self.login(restarted)['events'], [event])

    def test_write_requires_csrf_and_rejects_external_sources(self):
        state = self.login()
        event = {'id': 'x', 'name': 'X', 'url': 'https://example.com/entrants.json', 'history': None}
        self.assertEqual(self.client.put('/api/events', json={'events': [event], 'revision': 0}).status_code, 403)
        self.assertEqual(self.client.put('/api/events', json={'events': [event], 'revision': 0}, headers={'X-CSRF-Token': state['csrf']}).status_code, 400)
        self.assertEqual(self.client.get('/api/endu/counters?url=https://example.com').status_code, 400)

    def test_login_rate_limit(self):
        self.client.get('/login')
        with self.client.session_transaction() as session:
            token = session['csrf']
        with patch('web.check_password_hash', return_value=False):
            for _ in range(10):
                response = self.client.post('/login', data={'username': 'admin', 'password': 'wrong', 'csrf': token})
                self.assertIn('Credenziali non valide', response.text)
            self.assertEqual(self.client.post('/login', data={'csrf': token}).status_code, 429)

    def test_missing_secrets_prevent_startup(self):
        for name in ['SECRET_KEY', 'DASHBOARD_PASSWORD']:
            config = dict(self.config)
            config[name] = ''
            with self.assertRaises(RuntimeError):
                create_app(config)
