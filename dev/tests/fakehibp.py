#!/usr/bin/env python3
"""Fake Have I Been Pwned range API for e2e test pw_policy (0.8.745): python3 -I fakehibp.py <port> <password> <count> <log>
GET /range/<5 hex> answers like api.pwnedpasswords.com (suffix:count lines, padding lines with count 0); every requested prefix is
appended to <log> – the test checks that only 5 characters arrive."""
import hashlib, http.server, os, sys

port, pw, count, log = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]
sha = hashlib.sha1(pw.encode()).hexdigest().upper()


class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        prefix = self.path.rsplit('/', 1)[-1]
        with open(log, 'a') as f:
            f.write(self.path + '\n')
        lines = [f'{i * 7919:035X}:0' for i in range(1, 6)]
        if prefix == sha[:5]:
            lines.append(sha[5:] + ':' + count)
        body = '\r\n'.join(lines).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'text/plain')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format, *args):   # the base class's signature (pylint: disable=redefined-builtin)
        """Keeps the test output quiet: the request log is written only with NUNEXT_FAKE_VERBOSE=1."""
        if os.environ.get('NUNEXT_FAKE_VERBOSE'):
            super().log_message(format, *args)


http.server.HTTPServer(('127.0.0.1', port), H).serve_forever()
