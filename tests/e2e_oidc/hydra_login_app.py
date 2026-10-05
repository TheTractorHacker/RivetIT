# Minimal Hydra login & consent app: accepts whoever the test set via POST /_set (no UI), granting the requested scopes.
import json, ssl, urllib.request, urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
ADMIN = "https://localhost:9448"
CTX = ssl._create_unverified_context()
STATE = {"sub": "none", "email": "none@e2e.test", "email_verified": True, "name": "None"}
def admin_put(path, body):
    r = urllib.request.Request(ADMIN + path, data=json.dumps(body).encode(), headers={"Content-Type": "application/json"}, method="PUT")
    return json.loads(urllib.request.urlopen(r, context=CTX).read())
def admin_get(path):
    return json.loads(urllib.request.urlopen(ADMIN + path, context=CTX).read())
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def redirect(self, to):
        self.send_response(302); self.send_header("Location", to); self.send_header("Content-Length", "0"); self.end_headers()
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0); STATE.update(json.loads(self.rfile.read(n))); self.send_response(200); self.send_header("Content-Length", "2"); self.end_headers(); self.wfile.write(b"{}")
    def do_GET(self):
        u = urllib.parse.urlparse(self.path); q = {k: v[0] for k, v in urllib.parse.parse_qs(u.query).items()}
        if u.path == "/login":
            ch = q["login_challenge"]
            res = admin_put("/admin/oauth2/auth/requests/login/accept?login_challenge=" + urllib.parse.quote(ch), {"subject": STATE["sub"], "remember": False})
            return self.redirect(res["redirect_to"])
        if u.path == "/consent":
            ch = q["consent_challenge"]; req = admin_get("/admin/oauth2/auth/requests/consent?consent_challenge=" + urllib.parse.quote(ch))
            res = admin_put("/admin/oauth2/auth/requests/consent/accept?consent_challenge=" + urllib.parse.quote(ch), {
                "grant_scope": req["requested_scope"], "grant_access_token_audience": req.get("requested_access_token_audience", []),
                "remember": False, "session": {"id_token": {"email": STATE["email"], "email_verified": STATE["email_verified"], "name": STATE["name"]}}})
            return self.redirect(res["redirect_to"])
        self.send_response(404); self.send_header("Content-Length", "0"); self.end_headers()
srv = ThreadingHTTPServer(("127.0.0.1", 9447), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.minimum_version = ssl.TLSVersion.TLSv1_2; ctx.load_cert_chain("srv.pem", "srv.key"); srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
print("hydra login app up", flush=True); srv.serve_forever()
