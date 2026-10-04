import json, os, re, ssl, subprocess, sys, time, urllib.parse, urllib.request
from playwright.sync_api import sync_playwright

import os
PW = os.environ["E2E_DB_PASSWORD"]
APP = "https://localhost:9444"; KC = "https://localhost:9445"; REALM = "e2e"
CTX = ssl._create_unverified_context()
results = []
def check(name, ok, detail=""):
    results.append(ok); print(("PASS " if ok else "FAIL ") + name + (("  [" + detail + "]") if detail else ""))
def sql(q, one=False):
    o = subprocess.run(["mysql", "-u" + os.environ.get("E2E_DB_USER", "pscratch"), "-p" + PW, os.environ.get("E2E_DB_NAME", "pscratch"), "-N", "-e", q], capture_output=True, text=True)
    if o.returncode: raise RuntimeError(o.stderr)
    rows = [l.split("\t") for l in o.stdout.strip().split("\n") if l]
    return (rows[0][0] if rows and rows[0] else None) if one else rows

def kc(method, path, data=None, token=None, form=False):
    h = {}
    if token: h["Authorization"] = "Bearer " + token
    body = None
    if data is not None:
        if form: body = urllib.parse.urlencode(data).encode(); h["Content-Type"] = "application/x-www-form-urlencoded"
        else: body = json.dumps(data).encode(); h["Content-Type"] = "application/json"
    r = urllib.request.Request(KC + path, data=body, headers=h, method=method)
    try:
        resp = urllib.request.urlopen(r, context=CTX); raw = resp.read()
        return resp.status, (json.loads(raw) if raw and raw[:1] in b"[{" else raw.decode()), dict(resp.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(), {}

# ---- configure Keycloak: realm, confidential client with PKCE S256, two users
st0, tok, _ = kc("POST", "/realms/master/protocol/openid-connect/token", {"grant_type": "password", "client_id": "admin-cli", "username": "admin", "password": "admin"}, form=True)
assert st0 == 200, ("admin token failed", st0, tok)
T = tok["access_token"]
kc("POST", "/admin/realms", {"realm": REALM, "enabled": True, "sslRequired": "none"}, T)
CB = APP + "/client/login_oidc.php"
st, _, _ = kc("POST", f"/admin/realms/{REALM}/clients", {"clientId": "rivetit-kc", "enabled": True, "publicClient": False, "secret": "kc-client-secret-0123456789abcdef",
    "standardFlowEnabled": True, "implicitFlowEnabled": False, "directAccessGrantsEnabled": False, "redirectUris": [CB], "protocol": "openid-connect",
    "clientAuthenticatorType": "client-secret", "attributes": {"pkce.code.challenge.method": "S256"}}, T)
print("client create:", st)
def mkuser(username, email, verified):
    kc("POST", f"/admin/realms/{REALM}/users", {"username": username, "email": email, "emailVerified": verified, "enabled": True, "firstName": username, "lastName": "T",
        "credentials": [{"type": "password", "value": "KcPass123!", "temporary": False}]}, T)
    _, users, _ = kc("GET", f"/admin/realms/{REALM}/users?username={username}&exact=true", token=T)
    return users[0]["id"]
agent_sub = mkuser("kcagent", "kcagent@e2e.test", True)
portal_sub = mkuser("kcportal", "kcportal@e2e.test", True)
print("keycloak subjects:", agent_sub, portal_sub)

with sync_playwright() as p:
    b = p.chromium.launch()
    # ---- point RivetIT at Keycloak through the real admin screen
    ctx = b.new_context(ignore_https_errors=True); pg = ctx.new_page()
    pg.goto(APP + "/login.php"); pg.fill("input[name=email]", "admin@scratch.test"); pg.fill("input[name=password]", "ScratchPass123!"); pg.click("button[name=login]"); pg.wait_for_load_state()
    pg.goto(APP + "/admin/identity_provider.php")
    if not pg.locator("#oidc_enabled").is_checked(): pg.check("#oidc_enabled")
    if not pg.locator("#oidc_agent_enabled").is_checked(): pg.check("#oidc_agent_enabled")
    pg.fill("#oidc_issuer", f"{KC}/realms/{REALM}"); pg.fill("#oidc_client_id", "rivetit-kc"); pg.fill("#oidc_client_secret", "kc-client-secret-0123456789abcdef")
    pg.click("button[name=edit_oidc_provider]"); pg.wait_for_load_state(); pg.wait_for_timeout(700)
    check("Keycloak discovery accepted by RivetIT", sql("select config_oidc_issuer from settings", one=True) == f"{KC}/realms/{REALM}")

    # ---- accounts mapped to Keycloak subjects
    role = sql("select role_id from user_roles where role_is_admin=0 order by role_id limit 1", one=True)
    sql("delete from user_settings where user_id in (select user_id from users where user_email like 'kc%@e2e.test'); delete from contacts where contact_email like 'kc%@e2e.test'; delete from users where user_email like 'kc%@e2e.test'")
    sql("insert into users (user_name,user_email,user_password,user_auth_method,user_type,user_status,user_role_id,user_sso_issuer,user_sso_subject,user_created_at) values ('KC Agent','kcagent@e2e.test','x','local',1,1,%s,'%s/realms/%s','%s',now())" % (role, KC, REALM, agent_sub))
    aid = sql("select user_id from users where user_email='kcagent@e2e.test'", one=True); sql("insert into user_settings (user_id,user_config_records_per_page) values (%s,10)" % aid)
    sql("insert into clients (client_name,client_currency_code,client_net_terms,client_created_at) values ('KC Dept','USD',30,now())"); cid = sql("select max(client_id) from clients", one=True)
    sql("insert into users (user_name,user_email,user_password,user_auth_method,user_type,user_status,user_oidc_issuer,user_oidc_subject,user_created_at) values ('KC Portal','kcportal@e2e.test','x','oidc',2,1,'%s/realms/%s','%s',now())" % (KC, REALM, portal_sub))
    pid = sql("select user_id from users where user_email='kcportal@e2e.test'", one=True)
    sql("insert into contacts (contact_name,contact_email,contact_client_id,contact_user_id,contact_created_at) values ('KC Portal','kcportal@e2e.test',%s,%s,now())" % (cid, pid)); sql("insert into user_settings (user_id,user_config_records_per_page) values (%s,10)" % pid)
    sql("update settings set config_client_portal_enable=1")
    ctx.close()

    def kc_login(url_start, user, password="KcPass123!", click=None):
        c = b.new_context(ignore_https_errors=True); pgx = c.new_page()
        pgx.goto(APP + "/login.php") if click else pgx.goto(url_start)
        if click: pgx.locator(click).click()
        pgx.wait_for_selector("input[name=username]", timeout=20000)
        pgx.fill("input[name=username]", user); pgx.fill("input[name=password]", password); pgx.click("#kc-login, input[type=submit], button[type=submit]")
        pgx.wait_for_load_state(); pgx.wait_for_timeout(2000)
        return c, pgx
    c, pgx = kc_login(None, "kcagent", click="a[href*='as=agent']")
    check("Keycloak agent SSO sign-in reaches the agent app", "/agent/" in pgx.url, pgx.url.replace(APP, "")[:60])
    c.close()
    c, pgx = kc_login(APP + "/client/login_oidc.php", "kcportal")
    check("Keycloak Department Portal sign-in reaches the portal", "/client/" in pgx.url and "login" not in pgx.url, pgx.url.replace(APP, "")[:60])
    c.close()
    # the portal subject must not open the agent app, and vice versa
    c, pgx = kc_login(None, "kcportal", click="a[href*='as=agent']")
    check("Keycloak portal user refused on the agent button", "/agent/" not in pgx.url and "Single sign-on could not complete" in pgx.content(), pgx.url.replace(APP, "")[:60])
    c.close()
    b.close()
print("\nSUMMARY:", sum(results), "of", len(results), "passed"); sys.exit(0 if all(results) else 1)
