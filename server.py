"""Local dashboard server with a fixed, read-only Endu integration."""
import json
from datetime import datetime, timezone
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import urlopen

BASE = 'https://egs-eventi.s3.eu-west-1.amazonaws.com/00000000/00100000/00102700/00102708/data/'
FILES = ('entrants.json',)


def read_counter(filename):
    try:
        with urlopen(BASE + filename, timeout=15) as response:
            body = response.read(2_000_001)
            if len(body) > 2_000_000:
                raise ValueError('Risposta troppo grande')
            payload = json.loads(body)
        return filename, {'data': payload}
    except HTTPError as error:
        return filename, {'error': f'Endu ha risposto HTTP {error.code}.'}
    except URLError:
        return filename, {'error': 'Destinazione non raggiungibile. Verificare accesso di rete e disponibilità Endu.'}
    except (ValueError, TimeoutError, OSError):
        return filename, {'error': 'Risposta JSON non valida o servizio temporaneamente non disponibile.'}


def load_counters():
    results = dict(read_counter(filename) for filename in FILES)
    return {'eventId': '00102708', 'retrievedAt': datetime.now(timezone.utc).isoformat(), 'sources': results}


class Handler(SimpleHTTPRequestHandler):
    def do_GET(self):
        if self.path.split('?')[0] == '/api/endu/counters':
            payload = load_counters()
            body = json.dumps(payload, allow_nan=False).encode()
            self.send_response(200 if any('data' in r for r in payload['sources'].values()) else 502)
            self.send_header('Content-Type', 'application/json; charset=utf-8')
            self.send_header('Cache-Control', 'no-store')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            super().do_GET()


if __name__ == '__main__':
    handler = partial(Handler, directory=str(Path(__file__).resolve().parent))
    ThreadingHTTPServer(('0.0.0.0', 5173), handler).serve_forever()
