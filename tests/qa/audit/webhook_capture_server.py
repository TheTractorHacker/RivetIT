import http.server, json, sys
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        n = int(self.headers.get('Content-Length') or 0); b = self.rfile.read(n)
        open('/tmp/claude-1000/webhook_capture.jsonl', 'ab').write(json.dumps({'path': self.path, 'ct': self.headers.get('Content-Type'), 'body': b.decode('utf-8', 'replace')}).encode() + b'\n')
        self.send_response(200); self.end_headers(); self.wfile.write(b'ok')
    def log_message(self, *a): pass
http.server.HTTPServer(('127.0.0.1', 8997), H).serve_forever()
