# Fake OpenID Connect provider for the e2e test of single sign-on (0.8.863): python3 fakeoidc.py <log file> <port>
#   GET  /.well-known/openid-configuration      issuer http://127.0.0.1:<port>, endpoints below
#   GET  /jwks                                  one RSA key (kid k1), generated at start
#   POST /set   JSON {email, name, groups, email_verified, deny, bad_sig, nonce, aud}  the "person" of the next sign-ins (the test sets it)
#   GET  /authorize?…                           checks client_id cid, S256 challenge, redirect_uri, state, nonce → 302 redirect_uri?code=…&state=…
#   POST /token                                 code + code_verifier (S256) + client cid / csec → id_token RS256 (wrong key with bad_sig)
# Every request is logged as one line. Needs the Python package "cryptography".
import base64, hashlib, json, secrets, sys, time, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from cryptography.hazmat.primitives.asymmetric import rsa, padding
from cryptography.hazmat.primitives import hashes

LOG, PORT = sys.argv[1], int(sys.argv[2])
ISS = 'http://127.0.0.1:%d' % PORT
KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)
OTHER = rsa.generate_private_key(public_exponent=65537, key_size=2048)
person = {'email': 'sso.user@example.com', 'name': 'SSO User', 'groups': []}
codes = {}


def b64u(b):
    return base64.urlsafe_b64encode(b).decode().rstrip('=')


def intb(i):
    return i.to_bytes((i.bit_length() + 7) // 8, 'big')


def jwt(claims, key):
    h = b64u(json.dumps({'alg': 'RS256', 'typ': 'JWT', 'kid': 'k1'}).encode())
    p = b64u(json.dumps(claims).encode())
    sig = key.sign((h + '.' + p).encode(), padding.PKCS1v15(), hashes.SHA256())
    return h + '.' + p + '.' + b64u(sig)


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def note(self, s):
        with open(LOG, 'a') as f:
            f.write(s + '\n')

    def answer(self, code, body, ctype='application/json'):
        data = body if isinstance(body, bytes) else json.dumps(body).encode()
        self.send_response(code)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = dict(urllib.parse.parse_qsl(u.query))
        self.note('GET ' + u.path)
        if u.path == '/.well-known/openid-configuration':
            return self.answer(200, {'issuer': ISS, 'authorization_endpoint': ISS + '/authorize', 'token_endpoint': ISS + '/token', 'jwks_uri': ISS + '/jwks',
                                     'response_types_supported': ['code'], 'id_token_signing_alg_values_supported': ['RS256'], 'code_challenge_methods_supported': ['S256']})
        if u.path == '/jwks':
            n = KEY.public_key().public_numbers()
            return self.answer(200, {'keys': [{'kty': 'RSA', 'use': 'sig', 'alg': 'RS256', 'kid': 'k1', 'n': b64u(intb(n.n)), 'e': b64u(intb(n.e))}]})
        if u.path == '/authorize':
            ok = q.get('client_id') == 'cid' and q.get('response_type') == 'code' and q.get('code_challenge_method') == 'S256' and q.get('code_challenge') and q.get('nonce') \
                and q.get('state', '').startswith('sso.') and 'openid' in q.get('scope', '').split()
            self.note('authorize ok=%s redirect=%s' % (ok, q.get('redirect_uri')))
            red = q.get('redirect_uri', '')
            if person.get('deny') or not ok:
                loc = red + '?' + urllib.parse.urlencode({'error': 'access_denied', 'error_description': 'denied by the test', 'state': q.get('state', '')})
            else:
                code = secrets.token_hex(8)
                codes[code] = {'challenge': q['code_challenge'], 'nonce': q['nonce'], 'redirect': red}
                loc = red + '?' + urllib.parse.urlencode({'code': code, 'state': q['state']})
            self.send_response(302)
            self.send_header('Location', loc)
            self.end_headers()
            return
        self.answer(404, {'error': 'not_found'})

    def do_POST(self):
        u = urllib.parse.urlparse(self.path)
        body = self.rfile.read(int(self.headers.get('Content-Length') or 0)).decode()
        if u.path == '/set':
            person.clear()
            person.update(json.loads(body or '{}'))
            self.note('set ' + json.dumps(person))
            return self.answer(200, {'ok': True})
        if u.path == '/token':
            f = dict(urllib.parse.parse_qsl(body))
            c = codes.pop(f.get('code', ''), None)
            self.note('token code=%s' % ('ok' if c else 'bad'))
            if f.get('client_id') != 'cid' or f.get('client_secret') != 'csec':
                return self.answer(401, {'error': 'invalid_client'})
            if not c or f.get('redirect_uri') != c['redirect'] or b64u(hashlib.sha256(f.get('code_verifier', '').encode()).digest()) != c['challenge']:
                self.note('token pkce or code refused')
                return self.answer(400, {'error': 'invalid_grant'})
            now = int(time.time())
            claims = {'iss': ISS, 'sub': 'sub-' + person.get('email', ''), 'aud': person.get('aud', 'cid'), 'iat': now, 'exp': now + 300,
                      'nonce': person.get('nonce', c['nonce']), 'email': person.get('email'), 'name': person.get('name', ''), 'groups': person.get('groups', [])}
            if 'email_verified' in person:
                claims['email_verified'] = person['email_verified']
            return self.answer(200, {'access_token': 'at-' + secrets.token_hex(4), 'token_type': 'Bearer', 'expires_in': 300,
                                     'id_token': jwt(claims, OTHER if person.get('bad_sig') else KEY)})
        self.answer(404, {'error': 'not_found'})


ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
