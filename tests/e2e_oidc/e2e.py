import base64, hashlib, hmac, json, re, struct, subprocess, sys, time, urllib.request, ssl
from playwright.sync_api import sync_playwright

import os
DB_USER = os.environ.get("E2E_DB_USER", "pscratch")
DB_NAME = os.environ.get("E2E_DB_NAME", "pscratch")
DB_PASSWORD = os.environ["E2E_DB_PASSWORD"]
ADMIN_EMAIL = os.environ.get("E2E_ADMIN_EMAIL", "admin@scratch.test")
ADMIN_PASSWORD = os.environ.get("E2E_ADMIN_PASSWORD", "ScratchPass123!")
APP = "https://localhost:9444"
IDP = "https://localhost:9443"
CTX = ssl._create_unverified_context()
results = []

def sql(q, one=False):
    out = subprocess.run(["mysql", "-u" + DB_USER, "-p" + DB_PASSWORD, DB_NAME, "-N", "-e", q], capture_output=True, text=True)
    if out.returncode: raise RuntimeError(out.stderr)
    rows = [l.split("\t") for l in out.stdout.strip().split("\n") if l]
    return (rows[0][0] if rows and rows[0] else None) if one else rows

def idp_set(sub, email="x@e2e.test", verified=True):
    req = urllib.request.Request(IDP + "/_set", data=json.dumps({"sub": sub, "email": email, "email_verified": verified, "name": sub}).encode(), method="POST")
    urllib.request.urlopen(req, context=CTX).read()

def totp(secret_b32):
    key = base64.b32decode(secret_b32)
    ctr = int(time.time()) // 30
    h = hmac.new(key, struct.pack(">Q", ctr), hashlib.sha1).digest()
    o = h[-1] & 15
    return "%06d" % ((struct.unpack(">I", h[o:o+4])[0] & 0x7fffffff) % 1000000)

def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS " if ok else "FAIL ") + name + (("  [" + detail + "]") if detail else ""))

def last_log(like):
    return sql("select concat(log_action,' | ',left(log_description,110)) from logs where log_description like '%s' order by log_id desc limit 1" % like, one=True)

def admin_login(b):
    ctx = b.new_context(ignore_https_errors=True, viewport={"width": 1280, "height": 900}); pg = ctx.new_page()
    pg.goto(APP + "/login.php"); pg.fill("input[name=email]", ADMIN_EMAIL); pg.fill("input[name=password]", ADMIN_PASSWORD)
    pg.click("button[name=login]"); pg.wait_for_load_state()
    return ctx, pg

def edit_user_modal(pg, uid, subject):
    """Open the real edit-user modal HTML, set the SSO field, submit the real form. Returns the flash text."""
    pg.goto(APP + "/admin/users.php"); pg.wait_for_timeout(500)
    html = pg.evaluate("async (id)=>{const r=await fetch('modals/user/user_edit.php?id='+id);return (await r.json()).content}", uid)
    present = 'name="sso_subject"' in html
    if not present: return None, False
    pg.evaluate("""([h,s])=>{const d=document.createElement('div');d.innerHTML=h;document.body.appendChild(d);
        const f=d.querySelector('form'); f.querySelector('[name=sso_subject]').value=s;
        const i=document.createElement('input');i.type='hidden';i.name='edit_user';i.value='1';f.appendChild(i);f.submit();}""", [html, subject])
    pg.wait_for_load_state(); pg.wait_for_timeout(600)
    txt = pg.evaluate("document.body.innerText")
    return txt, True

def sso_login(b, as_agent=True, expect_mfa=False):
    ctx = b.new_context(ignore_https_errors=True, viewport={"width": 1280, "height": 900}); pg = ctx.new_page()
    pg.goto(APP + "/login.php"); pg.wait_for_load_state()
    return ctx, pg

with sync_playwright() as p:
    b = p.chromium.launch()

    # ---- 1. configure the provider through the real admin screen
    ctx, pg = admin_login(b)
    pg.goto(APP + "/admin/identity_provider.php")
    pg.check("#oidc_enabled"); pg.fill("#oidc_issuer", IDP); pg.fill("#oidc_client_id", "rivetit-e2e"); pg.fill("#oidc_client_secret", "e2e-client-secret-0123456789abcdef")
    pg.click("button[name=edit_oidc_provider]"); pg.wait_for_load_state(); pg.wait_for_timeout(500)
    check("provider saved and discovery verified", sql("select config_oidc_enabled from settings", one=True) == "1", pg.evaluate("document.body.innerText").replace("\n", " ")[:0])

    # agent SSO is OFF by default: no button, direct URL refused
    ctx2, pg2 = sso_login(b)
    check("agent SSO off: no company SSO button", pg2.locator("a[href*='as=agent']").count() == 0)
    pg2.goto(APP + "/client/login_oidc.php?as=agent"); pg2.wait_for_load_state()
    check("agent SSO off: direct start is refused", "login.php" in pg2.url and "Single sign-on could not complete" in pg2.content(), pg2.url.replace(APP, ""))
    check("agent SSO off: refusal logged as disabled", "disabled" in (last_log("OpenID Connect sign-in failed%") or ""))
    ctx2.close()

    # turn agent SSO on
    pg.goto(APP + "/admin/identity_provider.php"); pg.check("#oidc_agent_enabled"); pg.click("button[name=edit_oidc_provider]"); pg.wait_for_load_state(); pg.wait_for_timeout(400)
    check("agent SSO setting saved", sql("select config_oidc_agent_enabled from settings", one=True) == "1")

    # ---- 2. seed accounts
    role = sql("select role_id from user_roles where role_is_admin=0 order by role_id limit 1", one=True)
    sql("insert into users (user_name,user_email,user_password,user_auth_method,user_type,user_status,user_role_id,user_created_at) values "
        "('Agent One','agent1@e2e.test','x','local',1,1,%s,now()),('Agent Two','agent2@e2e.test','x','local',1,1,%s,now()),"
        "('Agent Three','agent3@e2e.test','x','local',1,1,%s,now()),('Agent Four','agent4@e2e.test','x','local',1,0,%s,now())" % (role, role, role, role))
    ids = {r[1]: r[0] for r in sql("select user_id,user_email from users where user_email like '%@e2e.test'")}
    for u in ids.values(): sql("insert into user_settings (user_id,user_config_records_per_page) values (%s,10)" % u)
    secret = "JBSWY3DPEHPK3PXP"
    sql("update users set user_token='%s' where user_id=%s" % (secret, ids["agent2@e2e.test"]))
    admin_id = sql("select user_id from users where user_email='%s'" % ADMIN_EMAIL, one=True)

    # ---- 3. link agents through the real edit-user form (validation paths included)
    txt, shown = edit_user_modal(pg, ids["agent1@e2e.test"], "sub-agent1")
    check("edit-user form shows the SSO field when agent SSO is on", shown)
    check("agent1 linked", sql("select user_sso_subject from users where user_id=%s" % ids["agent1@e2e.test"], one=True) == "sub-agent1")
    edit_user_modal(pg, ids["agent2@e2e.test"], "sub-agent2")
    edit_user_modal(pg, ids["agent4@e2e.test"], "sub-agent4")
    txt, _ = edit_user_modal(pg, ids["agent3@e2e.test"], "sub-agent1")
    check("duplicate subject refused", "already linked" in (txt or "") and sql("select user_sso_subject from users where user_id=%s" % ids["agent3@e2e.test"], one=True) in (None, "NULL"))
    txt, _ = edit_user_modal(pg, admin_id, "sub-admin")
    check("administrator cannot be linked", "Administrators keep local sign-in" in (txt or ""), (txt or "")[:0])
    check("link change audited", "company SSO linked" in (sql("select log_description from logs where log_description like '%company SSO linked%' order by log_id desc limit 1", one=True) or ""))
    ctx.close()

    # ---- 4. agent without local 2FA signs in with SSO
    idp_set("sub-agent1", "agent1@e2e.test")
    ctx3, pg3 = sso_login(b)
    btn = pg3.locator("a[href*='as=agent']")
    check("agent SSO on: company SSO button shown", btn.count() == 1)
    btn.click(); pg3.wait_for_load_state(); pg3.wait_for_timeout(1500)
    check("agent1 lands in the agent app", "/agent/" in pg3.url, pg3.url.replace(APP, ""))
    check("agent1 session is agent1", sql("select log_user_id from logs where log_description like '%Agent One successfully logged in via company SSO%' order by log_id desc limit 1", one=True) == ids["agent1@e2e.test"])
    pg3.goto(APP + "/agent/user/user_security.php"); pg3.wait_for_load_state()
    check("agent session works on another page", "login.php" not in pg3.url, pg3.url.replace(APP, ""))
    ctx3.close()

    # ---- 5. agent WITH local 2FA still must enter the code
    idp_set("sub-agent2", "agent2@e2e.test")
    ctx4, pg4 = sso_login(b)
    pg4.locator("a[href*='as=agent']").click(); pg4.wait_for_load_state(); pg4.wait_for_timeout(1500)
    check("agent2 is sent to the 2FA step, not the app", "login.php" in pg4.url and "sso_mfa=1" in pg4.url and pg4.locator("input[name=current_code]").count() == 1, pg4.url.replace(APP, ""))
    pg4.goto(APP + "/agent/dashboard.php"); pg4.wait_for_load_state()
    check("agent2 cannot reach the app before the code", "login.php" in pg4.url, pg4.url.replace(APP, ""))
    pg4.goto(APP + "/login.php?sso_mfa=1"); pg4.wait_for_load_state()
    if pg4.locator("input[name=current_code]").count() == 1:
        pg4.fill("input[name=current_code]", "000000"); pg4.click("button[name=mfa_login]"); pg4.wait_for_load_state(); pg4.wait_for_timeout(600)
        check("wrong 2FA code does not sign in", "/agent/" not in pg4.url, pg4.url.replace(APP, ""))
        if pg4.locator("input[name=current_code]").count() == 1:
            pg4.fill("input[name=current_code]", totp(secret)); pg4.click("button[name=mfa_login]"); pg4.wait_for_load_state(); pg4.wait_for_timeout(1000)
            check("correct 2FA code signs in", "/agent/" in pg4.url, pg4.url.replace(APP, ""))
        else:
            check("2FA form still offered after a wrong code", False)
    else:
        check("2FA step reachable on revisit", False, pg4.url.replace(APP, ""))
    ctx4.close()

    # ---- 6. refusals
    def expect_refused(name, sub, email, reason_part, as_agent=True, verified=True):
        idp_set(sub, email, verified)
        c, pgx = sso_login(b)
        if as_agent:
            pgx.locator("a[href*='as=agent']").click()
        else:
            pgx.goto(APP + "/client/login_oidc.php")
        pgx.wait_for_load_state(); pgx.wait_for_timeout(1200)
        refused = "/agent/" not in pgx.url and "/client/index" not in pgx.url and "Single sign-on could not complete" in pgx.content()
        reason = last_log("OpenID Connect sign-in failed%") or ""
        check(name, refused and reason_part in reason, reason[-70:])
        c.close()
    expect_refused("unlinked subject refused", "sub-nobody", "n@e2e.test", "no_agent_linked_to_this_subject")
    expect_refused("disabled agent refused", "sub-agent4", "agent4@e2e.test", "no_agent_linked_to_this_subject")
    sql("update users set user_sso_issuer='%s', user_sso_subject='sub-admin' where user_id=%s" % (IDP, admin_id))
    expect_refused("administrator refused even if a link is forced in the database", "sub-admin", ADMIN_EMAIL, "no_agent_linked_to_this_subject")
    sql("update users set user_sso_issuer=NULL, user_sso_subject=NULL where user_id=%s" % admin_id)

    # ---- 7. audience separation: portal mapping cannot enter via the agent button, agent mapping not via the portal link
    sql("insert into clients (client_name,client_currency_code,client_net_terms,client_created_at) values ('E2E Dept','USD',30,now())")
    cid = sql("select max(client_id) from clients", one=True)
    sql("insert into users (user_name,user_email,user_password,user_auth_method,user_type,user_status,user_oidc_issuer,user_oidc_subject,user_created_at) values ('Portal P','portal@e2e.test','x','oidc',2,1,'%s','sub-portal',now())" % IDP)
    pid = sql("select user_id from users where user_email='portal@e2e.test'", one=True)
    sql("insert into contacts (contact_name,contact_email,contact_client_id,contact_user_id,contact_created_at) values ('Portal P','portal@e2e.test',%s,%s,now())" % (cid, pid))
    sql("insert into user_settings (user_id,user_config_records_per_page) values (%s,10)" % pid)
    sql("update settings set config_client_portal_enable=1")
    expect_refused("portal-only subject refused through the agent button", "sub-portal", "portal@e2e.test", "no_agent_linked_to_this_subject")
    expect_refused("agent-only subject refused through the portal link", "sub-agent1", "agent1@e2e.test", "account_ineligible", as_agent=False)
    idp_set("sub-portal", "portal@e2e.test")
    c, pgx = sso_login(b); pgx.goto(APP + "/client/login_oidc.php"); pgx.wait_for_load_state(); pgx.wait_for_timeout(1200)
    check("portal sign-in still works (regression)", "/client/" in pgx.url and "login" not in pgx.url, pgx.url.replace(APP, ""))
    c.close()

    # ---- 8. replay of a used callback is refused
    idp_set("sub-agent1", "agent1@e2e.test")
    c, pgx = sso_login(b)
    seen = []
    pgx.on("request", lambda r: seen.append(r.url) if "login_oidc.php?code=" in r.url else None)
    pgx.locator("a[href*='as=agent']").click(); pgx.wait_for_load_state(); pgx.wait_for_timeout(1200)
    cb = seen[0] if seen else None
    c2 = b.new_context(ignore_https_errors=True); p2 = c2.new_page()
    if cb:
        p2.goto(cb); p2.wait_for_load_state(); p2.wait_for_timeout(600)
    check("callback replayed in another browser is refused", cb is not None and "/agent/" not in p2.url, (p2.url or "").replace(APP, ""))
    c.close(); c2.close()
    b.close()

print("\nSUMMARY:", sum(results), "of", len(results), "passed")
sys.exit(0 if all(results) else 1)
