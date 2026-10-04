import json, ssl, subprocess, sys, urllib.request
from playwright.sync_api import sync_playwright
import os
PW = os.environ["E2E_DB_PASSWORD"]
APP = "https://localhost:9444"; HY = "https://localhost:9446"; ADMIN = "https://localhost:9448"; LOGIN = "https://localhost:9447"
CTX = ssl._create_unverified_context(); results = []
def check(name, ok, detail=""):
    results.append(ok); print(("PASS " if ok else "FAIL ") + name + (("  [" + detail + "]") if detail else ""))
def sql(q, one=False):
    o = subprocess.run(["mysql", "-u" + os.environ.get("E2E_DB_USER", "pscratch"), "-p" + PW, os.environ.get("E2E_DB_NAME", "pscratch"), "-N", "-e", q], capture_output=True, text=True)
    if o.returncode: raise RuntimeError(o.stderr)
    rows = [l.split("\t") for l in o.stdout.strip().split("\n") if l]
    return (rows[0][0] if rows and rows[0] else None) if one else rows
def set_identity(sub, email, verified=True):
    urllib.request.urlopen(urllib.request.Request(LOGIN + "/_set", data=json.dumps({"sub": sub, "email": email, "email_verified": verified, "name": sub}).encode(), method="POST"), context=CTX).read()
# register the RivetIT client in Hydra (admin API)
body = {"client_id": "rivetit-hydra", "client_secret": "hydra-client-secret-0123456789abcdef", "grant_types": ["authorization_code"], "response_types": ["code"],
        "scope": "openid profile email", "redirect_uris": [APP + "/client/login_odoo.php".replace("login_odoo", "login_oidc")], "token_endpoint_auth_method": "client_secret_basic"}
try:
    urllib.request.urlopen(urllib.request.Request(ADMIN + "/admin/clients", data=json.dumps(body).encode(), headers={"Content-Type": "application/json"}, method="POST"), context=CTX).read()
except urllib.error.HTTPError as e:
    print("client create:", e.code, e.read().decode()[:120])
ISS = HY
with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(ignore_https_errors=True); pg = ctx.new_page()
    pg.goto(APP + "/login.php"); pg.fill("input[name=email]", "admin@scratch.test"); pg.fill("input[name=password]", "ScratchPass123!"); pg.click("button[name=login]"); pg.wait_for_load_state()
    pg.goto(APP + "/admin/identity_provider.php")
    if not pg.locator("#oidc_enabled").is_checked(): pg.check("#oidc_enabled")
    if not pg.locator("#oidc_agent_enabled").is_checked(): pg.check("#oidc_agent_enabled")
    pg.fill("#oidc_issuer", ISS); pg.fill("#oidc_client_id", "rivetit-hydra"); pg.fill("#oidc_client_secret", "hydra-client-secret-0123456789abcdef")
    pg.click("button[name=edit_oidc_provider]"); pg.wait_for_load_state(); pg.wait_for_timeout(700)
    check("Hydra discovery accepted by RivetIT", sql("select config_oidc_issuer from settings", one=True) == ISS)
    role = sql("select role_id from user_roles where role_is_admin=0 order by role_id limit 1", one=True)
    sql("delete from user_settings where user_id in (select user_id from users where user_email like 'hy%@e2e.test'); delete from contacts where contact_email like 'hy%@e2e.test'; delete from users where user_email like 'hy%@e2e.test'")
    sql("insert into users (user_name,user_email,user_password,user_auth_method,user_type,user_status,user_role_id,user_sso_issuer,user_sso_subject,user_created_at) values ('HY Agent','hyagent@e2e.test','x','local',1,1,%s,'%s','hy-agent-subject',now())" % (role, ISS))
    aid = sql("select user_id from users where user_email='hyagent@e2e.test'", one=True); sql("insert into user_settings (user_id,user_config_records_per_page) values (%s,10)" % aid)
    sql("insert into clients (client_name,client_currency_code,client_net_terms,client_created_at) values ('HY Dept','USD',30,now())"); cid = sql("select max(client_id) from clients", one=True)
    sql("insert into users (user_name,user_email,user_password,user_auth_method,user_type,user_status,user_oidc_issuer,user_oidc_subject,user_created_at) values ('HY Portal','hyportal@e2e.test','x','oidc',2,1,'%s','hy-portal-subject',now())" % ISS)
    pid = sql("select user_id from users where user_email='hyportal@e2e.test'", one=True)
    sql("insert into contacts (contact_name,contact_email,contact_client_id,contact_user_id,contact_created_at) values ('HY Portal','hyportal@e2e.test',%s,%s,now())" % (cid, pid)); sql("insert into user_settings (user_id,user_config_records_per_page) values (%s,10)" % pid)
    sql("update settings set config_client_portal_enable=1"); ctx.close()
    def go(start, sub, email, click=None):
        set_identity(sub, email)
        c = b.new_context(ignore_https_errors=True); pgx = c.new_page()
        pgx.goto(APP + "/login.php") if click else pgx.goto(start)
        if click: pgx.locator(click).click()
        pgx.wait_for_load_state(); pgx.wait_for_timeout(2500); return c, pgx
    c, x = go(None, "hy-agent-subject", "hyagent@e2e.test", "a[href*='as=agent']")
    check("Hydra agent SSO sign-in reaches the agent app", "/agent/" in x.url, x.url.replace(APP, "")[:60]); c.close()
    c, x = go(APP + "/client/login_oidc.php", "hy-portal-subject", "hyportal@e2e.test")
    check("Hydra Department Portal sign-in reaches the portal", "/client/" in x.url and "login" not in x.url, x.url.replace(APP, "")[:60]); c.close()
    c, x = go(None, "hy-portal-subject", "hyportal@e2e.test", "a[href*='as=agent']")
    check("Hydra portal subject refused on the agent button", "/agent/" not in x.url and "Single sign-on could not complete" in x.content(), x.url.replace(APP, "")[:60]); c.close()
    b.close()
print("\nSUMMARY:", sum(results), "of", len(results), "passed"); sys.exit(0 if all(results) else 1)
