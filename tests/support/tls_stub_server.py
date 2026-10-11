#!/usr/bin/env python3
"""A tiny HTTPS stand-in for a MeshCentral server in tests: answers HEAD and GET on every path with 200 "ok" over the given certificate.
usage: tls_stub_server.py <port> <cert.pem> <key.pem>   (binds 127.0.0.1 only)
openssl s_server -www does not answer HEAD requests, and the publicly-trusted check of RivetCore's pin probe sends one."""
import http.server
import ssl
import sys


class H(http.server.BaseHTTPRequestHandler):
    def _ok(self, body):
        self.send_response(200)
        self.send_header('Content-Type', 'text/plain')
        self.send_header('Content-Length', '2')
        self.end_headers()
        if body:
            self.wfile.write(b'ok')

    def do_HEAD(self):
        self._ok(False)

    def do_GET(self):
        self._ok(True)

    def log_message(self, *a):
        pass


port, cert, key = int(sys.argv[1]), sys.argv[2], sys.argv[3]
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(cert, key)
srv = http.server.ThreadingHTTPServer(('127.0.0.1', port), H)
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
srv.serve_forever()
