"""AI service stand-in for the e2e test ai_providers (0.8.1099): python3 -I fakeai.py <port> <log file> <answers file>
   Speaks the three request shapes of core/nuaiprovider.php below /anthropic, /openai and /gemini (key: test-key, else 401):
     POST /anthropic/v1/messages · POST /openai/chat/completions · POST /gemini/models/<model>:generateContent · GET …/models
   The answer text is the first entry of the JSON list in the answers file (removed when used); "OK" when the list is empty.
   Every request is appended to the log file as one JSON line {path, headers, body}."""
import json, os, sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PORT, LOG, ANSWERS = int(sys.argv[1]), sys.argv[2], sys.argv[3]


def next_answer():
    try:
        with open(ANSWERS, encoding='utf-8') as f:
            q = json.load(f)
    except (OSError, ValueError):
        q = []
    if not q:
        return 'OK'
    with open(ANSWERS, 'w', encoding='utf-8') as f:
        json.dump(q[1:], f)
    return q[0]


class H(BaseHTTPRequestHandler):
    def key_ok(self):
        h = {k.lower(): v for k, v in self.headers.items()}
        return 'test-key' in (h.get('x-api-key'), h.get('x-goog-api-key'), h.get('authorization', '').replace('Bearer ', ''))

    def send(self, code, obj):
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps(obj).encode())

    def log(self, body):
        with open(LOG, 'a', encoding='utf-8') as f:
            f.write(json.dumps({'path': self.path, 'headers': {k.lower(): v for k, v in self.headers.items()}, 'body': body}) + '\n')

    def do_GET(self):
        self.log('')
        if not self.key_ok():
            return self.send(401, {'error': {'message': 'invalid key'}})
        if self.path.startswith('/gemini'):
            return self.send(200, {'models': [{'name': 'models/fake-flash', 'supportedGenerationMethods': ['generateContent']}, {'name': 'models/fake-embed', 'supportedGenerationMethods': ['embedContent']}]})
        return self.send(200, {'data': [{'id': 'fake-large'}, {'id': 'fake-small'}]})

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length') or 0)).decode('utf-8', 'replace')
        self.log(body)
        if not self.key_ok():
            return self.send(401, {'error': {'message': 'invalid key'}})
        text = next_answer()
        if self.path.startswith('/anthropic'):
            return self.send(200, {'content': [{'type': 'text', 'text': text}], 'stop_reason': 'end_turn', 'usage': {'input_tokens': 120, 'output_tokens': 30}})
        if self.path.startswith('/gemini'):
            return self.send(200, {'candidates': [{'content': {'parts': [{'text': text}]}, 'finishReason': 'STOP'}], 'usageMetadata': {'promptTokenCount': 120, 'candidatesTokenCount': 30}})
        return self.send(200, {'choices': [{'message': {'role': 'assistant', 'content': text}, 'finish_reason': 'stop'}], 'usage': {'prompt_tokens': 120, 'completion_tokens': 30}})

    def log_message(self, *a):
        """Keep the test output quiet: the request log of the base class is not written."""
        return


if not os.path.exists(ANSWERS):
    with open(ANSWERS, 'w', encoding='utf-8') as fh:
        fh.write('[]')
ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
