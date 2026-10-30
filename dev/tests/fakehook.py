"""Webhook receiver for the e2e test webhooks (0.8.715): python3 -I fakehook.py <port> <log file>
   /ok → 200 · /flaky → 500 for the first two calls, then 200 · /deny → 403 · /slow → waits 8 s (beyond the 5 s timeout).
   Every request is appended to the log file as one JSON line {path, headers, body}."""
import json, sys, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT, LOG = int(sys.argv[1]), sys.argv[2]
hits = {}


class H(BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length') or 0)).decode('utf-8', 'replace')
        with open(LOG, 'a', encoding='utf-8') as f:
            f.write(json.dumps({'path': self.path, 'headers': {k.lower(): v for k, v in self.headers.items()}, 'body': body}) + '\n')
        n = hits[self.path] = hits.get(self.path, 0) + 1
        code = 200
        if self.path.startswith('/flaky') and n <= 2:
            code = 500
        elif self.path.startswith('/deny'):
            code = 403
        elif self.path.startswith('/slow'):
            time.sleep(8)
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps({'received': n}).encode())

    def log_message(self, *a):
        pass


ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
