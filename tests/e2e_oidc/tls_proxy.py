import ssl, http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
class P(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def log_message(self, *a): pass
    def fwd(self):
        n = int(self.headers.get("Content-Length") or 0); body = self.rfile.read(n) if n else None
        c = http.client.HTTPConnection("127.0.0.1", 9412, timeout=60)
        hdrs = {k: v for k, v in self.headers.items() if k.lower() not in ("host", "connection")}
        hdrs["Host"] = "localhost:9444"; hdrs["X-Forwarded-Proto"] = "https"
        c.request(self.command, self.path, body=body, headers=hdrs); r = c.getresponse(); data = r.read()
        self.send_response(r.status)
        for k, v in r.getheaders():
            if k.lower() in ("transfer-encoding", "connection", "content-length"): continue
            # Header names and values come from the upstream response: never let a CR or LF through (response splitting).
            self.send_header(k.replace("\r", "").replace("\n", ""), v.replace("\r", "").replace("\n", ""))
        self.send_header("Content-Length", str(len(data))); self.send_header("Connection", "close"); self.end_headers(); self.wfile.write(data)
    do_GET = do_POST = do_PUT = do_DELETE = do_HEAD = fwd
srv = ThreadingHTTPServer(("127.0.0.1", 9444), P)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.minimum_version = ssl.TLSVersion.TLSv1_2; ctx.load_cert_chain("srv.pem", "srv.key")
srv.socket = ctx.wrap_socket(srv.socket, server_side=True); print("tls proxy up", flush=True); srv.serve_forever()
