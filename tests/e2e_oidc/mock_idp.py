import base64, hashlib, json, ssl, sys, threading, time, secrets
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs, urlencode
import jwt
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives import serialization

ISSUER = "https://localhost:9443"
CLIENT_ID = "rivetit-e2e"
CLIENT_SECRET = "e2e-client-secret-0123456789abcdef"
KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)
PUB = KEY.public_key().public_numbers()
def b64u(b): return base64.urlsafe_b64encode(b).rstrip(b"=").decode()
def i2b(n): return n.to_bytes((n.bit_length() + 7) // 8, "big")
JWK = {"kty": "RSA", "use": "sig", "alg": "RS256", "kid": "k1", "n": b64u(i2b(PUB.n)), "e": b64u(i2b(PUB.e))}
PEM = KEY.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption())
STATE = {"user": {"sub": "sub-none", "email": "none@example.test", "email_verified": True, "name": "None"}, "codes": {}, "tokens": {}}

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, code, body, ctype="application/json", headers=()):
        data = body if isinstance(body, bytes) else body.encode()
        self.send_response(code); self.send_header("Content-Type", ctype); self.send_header("Content-Length", str(len(data)))
        for k, v in headers: self.send_header(k, v)
        self.end_headers(); self.wfile.write(data)
    def do_GET(self):
        u = urlparse(self.path); q = {k: v[0] for k, v in parse_qs(u.query).items()}
        if u.path == "/.well-known/openid-configuration":
            return self.send(200, json.dumps({"issuer": ISSUER, "authorization_endpoint": ISSUER + "/authorize", "token_endpoint": ISSUER + "/token",
                "jwks_uri": ISSUER + "/jwks", "userinfo_endpoint": ISSUER + "/userinfo", "response_types_supported": ["code"],
                "subject_types_supported": ["public"], "id_token_signing_alg_values_supported": ["RS256"],
                "token_endpoint_auth_methods_supported": ["client_secret_basic"], "code_challenge_methods_supported": ["S256"]}))
        if u.path == "/jwks": return self.send(200, json.dumps({"keys": [JWK]}))
        if u.path == "/authorize":
            if q.get("client_id") != CLIENT_ID or q.get("response_type") != "code" or q.get("code_challenge_method") != "S256" or not q.get("code_challenge"):
                return self.send(400, "bad authorize request")
            code = secrets.token_urlsafe(24)
            STATE["codes"][code] = {"nonce": q.get("nonce"), "challenge": q["code_challenge"], "redirect": q["redirect_uri"], "user": dict(STATE["user"]), "used": False}
            loc = q["redirect_uri"] + "?" + urlencode({"code": code, "state": q.get("state", "")})
            return self.send(302, "", headers=[("Location", loc)])
        if u.path == "/userinfo":
            tok = (self.headers.get("Authorization") or "")[7:]
            user = STATE["tokens"].get(tok)
            if not user: return self.send(401, json.dumps({"error": "invalid_token"}))
            return self.send(200, json.dumps({"sub": user["sub"], "email": user["email"], "email_verified": user["email_verified"], "name": user["name"]}))
        self.send(404, "not found")
    def do_POST(self):
        u = urlparse(self.path); n = int(self.headers.get("Content-Length") or 0); raw = self.rfile.read(n).decode()
        if u.path == "/_set":   # test control: choose who signs in next
            STATE["user"] = json.loads(raw); return self.send(200, "{}")
        if u.path == "/token":
            f = {k: v[0] for k, v in parse_qs(raw).items()}
            auth = self.headers.get("Authorization") or ""
            try:
                cid, sec = base64.b64decode(auth[6:]).decode().split(":", 1)
            except Exception:
                return self.send(401, json.dumps({"error": "invalid_client"}))
            if cid != CLIENT_ID or sec != CLIENT_SECRET: return self.send(401, json.dumps({"error": "invalid_client"}))
            c = STATE["codes"].get(f.get("code", ""))
            if not c or c["used"] or f.get("redirect_uri") != c["redirect"]: return self.send(400, json.dumps({"error": "invalid_grant"}))
            c["used"] = True
            if b64u(hashlib.sha256(f.get("code_verifier", "").encode()).digest()) != c["challenge"]:
                return self.send(400, json.dumps({"error": "invalid_grant", "error_description": "pkce"}))
            at = secrets.token_urlsafe(24); STATE["tokens"][at] = c["user"]
            now = int(time.time())
            claims = {"iss": ISSUER, "sub": c["user"]["sub"], "aud": CLIENT_ID, "exp": now + 300, "iat": now, "nonce": c["nonce"],
                      "at_hash": b64u(hashlib.sha256(at.encode()).digest()[:16])}
            idt = jwt.encode(claims, PEM, algorithm="RS256", headers={"kid": "k1"})
            return self.send(200, json.dumps({"access_token": at, "id_token": idt, "token_type": "Bearer", "expires_in": 300}))
        self.send(404, "not found")

srv = ThreadingHTTPServer(("127.0.0.1", 9443), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.minimum_version = ssl.TLSVersion.TLSv1_2; ctx.load_cert_chain("srv.pem", "srv.key")
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
print("mock idp up", flush=True); srv.serve_forever()
