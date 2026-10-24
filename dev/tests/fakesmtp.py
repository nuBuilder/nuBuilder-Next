# Minimal SMTP server for the e2e tests of the e-mail connection check (0.8.432) and OAuth 2 (0.8.445):
# python3 fakesmtp.py <log file> [port]. Answers EHLO with AUTH, AUTH PLAIN / LOGIN (user u / password p), AUTH XOAUTH2 (any user,
# "auth=Bearer tok-…" – the tokens of fakeoauth.py), MAIL, RCPT, DATA, RSET, QUIT. Logs the commands; for XOAUTH2 also "XOAUTH2 <user>".
import socketserver, base64, sys
LOG = sys.argv[1]
class H(socketserver.StreamRequestHandler):
    def w(self, s): self.wfile.write((s + '\r\n').encode())
    def handle(self):
        log = open(LOG, 'a'); self.w('220 fake ESMTP')
        while True:
            line = self.rfile.readline().decode(errors='replace').rstrip('\r\n')
            if not line: break
            cmd = line.split(' ')[0].upper(); log.write(cmd + '\n'); log.flush()
            if cmd in ('EHLO', 'HELO'): self.w('250-fake\r\n250-AUTH PLAIN LOGIN XOAUTH2\r\n250 8BITMIME')
            elif cmd == 'AUTH':
                parts = line.split(' ')
                kind = parts[1].upper() if len(parts) > 1 else ''
                if kind == 'PLAIN':
                    cred = base64.b64decode(parts[2]).split(b'\0') if len(parts) > 2 else []
                    self.w('235 ok' if cred[1:] == [b'u', b'p'] else '535 bad credentials')
                elif kind == 'XOAUTH2':
                    raw = base64.b64decode(parts[2]).decode(errors='replace') if len(parts) > 2 else ''
                    fields = dict(f.split('=', 1) for f in raw.split('\x01') if '=' in f)
                    log.write('XOAUTH2 ' + fields.get('user', '') + '\n'); log.flush()
                    if fields.get('auth', '').startswith('Bearer tok-'): self.w('235 ok')
                    else:
                        self.w('334 ' + base64.b64encode(b'{"status":"401","schemes":"bearer"}').decode()); self.rfile.readline(); self.w('535 bad token')
                else:
                    self.w('334 VXNlcm5hbWU6'); u = base64.b64decode(self.rfile.readline().strip())
                    self.w('334 UGFzc3dvcmQ6'); p = base64.b64decode(self.rfile.readline().strip())
                    self.w('235 ok' if (u, p) == (b'u', b'p') else '535 bad credentials')
            elif cmd == 'MAIL': self.w('250 sender ok' if 'bad@' not in line else '550 sender refused')
            elif cmd == 'RCPT': self.w('250 recipient ok')
            elif cmd == 'DATA':
                self.w('354 go on')
                while True:
                    d = self.rfile.readline()
                    if not d or d.rstrip(b'\r\n') == b'.': break
                self.w('250 queued')
            elif cmd in ('RSET', 'NOOP'): self.w('250 ok')
            elif cmd == 'QUIT': self.w('221 bye'); break
            else: self.w('502 not here')
socketserver.ThreadingTCPServer.allow_reuse_address = True
socketserver.ThreadingTCPServer(('127.0.0.1', int(sys.argv[2]) if len(sys.argv) > 2 else 2525), H).serve_forever()
