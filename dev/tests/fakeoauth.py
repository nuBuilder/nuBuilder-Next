# Fake OAuth 2 provider for the e2e test of the SMTP login with OAuth 2 (0.8.445): python3 fakeoauth.py <log file> <port>
#   GET  /authorize?redirect_uri=…&state=…   → 302 to redirect_uri?code=code-1&state=… (consent "given" at once; ?deny=1 → error=access_denied)
#   POST /token  grant_type=authorization_code&code=code-1 → refresh_token rt-1, access token, id_token with email tester@example.com
#                grant_type=refresh_token&refresh_token=rt-1 → a new access token (tok-<n>)
# Client: id "cid", secret "csec" – anything else gets 401 invalid_client. Every request is logged (one line per request).
import base64, json, sys, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG, PORT = sys.argv[1], int(sys.argv[2])
n = [0]

def b64(d):
    return base64.urlsafe_b64encode(json.dumps(d).encode()).decode().rstrip('=')

class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def note(self, s):
        with open(LOG, 'a') as f:
            f.write(s + '\n')

    def answer(self, code, body):
        data = json.dumps(body).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = dict(urllib.parse.parse_qsl(u.query))
        self.note('GET ' + u.path + ' client_id=' + q.get('client_id', '') + ' scope=' + q.get('scope', ''))
        if u.path != '/authorize':
            return self.answer(404, {'error': 'not_found'})
        back = q.get('redirect_uri', '')
        extra = {'error': 'access_denied', 'error_description': 'The user said no'} if q.get('deny') else {'code': 'code-1'}
        self.send_response(302)
        self.send_header('Location', back + ('&' if '?' in back else '?') + urllib.parse.urlencode(dict(extra, state=q.get('state', ''))))
        self.end_headers()

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0))).decode()
        q = dict(urllib.parse.parse_qsl(body))
        self.note('POST ' + self.path + ' grant_type=' + q.get('grant_type', '') + ' redirect_uri=' + q.get('redirect_uri', ''))
        if self.path != '/token':
            return self.answer(404, {'error': 'not_found'})
        if q.get('client_id') != 'cid' or q.get('client_secret') != 'csec':
            return self.answer(401, {'error': 'invalid_client', 'error_description': 'Unknown client or wrong secret'})
        n[0] += 1
        if q.get('grant_type') == 'authorization_code' and q.get('code') == 'code-1':
            return self.answer(200, {'access_token': 'tok-%d' % n[0], 'expires_in': 3600, 'refresh_token': 'rt-1', 'token_type': 'Bearer',
                                     'id_token': b64({'alg': 'none'}) + '.' + b64({'email': 'tester@example.com'}) + '.'})
        if q.get('grant_type') == 'refresh_token' and q.get('refresh_token') == 'rt-1':
            return self.answer(200, {'access_token': 'tok-%d' % n[0], 'expires_in': 3600, 'token_type': 'Bearer'})
        return self.answer(400, {'error': 'invalid_grant', 'error_description': 'Bad code or refresh token'})

ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
