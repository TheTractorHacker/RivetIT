"""
End-to-end check of Administration > Shared compliance report (publish to the portal) through the real web stack: sign in, load the page, record reviews,
save a snapshot, export CSV/HTML, verify escaping, the database and the audit trail.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/compliance_shared.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
class Sess:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, path, data=None, referer=None):
        headers = {}
        if referer: headers['Referer'] = BASE + referer
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, headers=headers)
        try:
            resp = self.opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    if out.returncode: print('SQL ERROR:', out.stderr.strip()[-300:])
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))
import subprocess as sp
PORTAL_EMAIL = 'portal@scratch.test'; PORTAL_PW = 'Portal-Pass-12345'
hashed = sp.run(['php', '-r', 'echo password_hash(getenv("P"), PASSWORD_DEFAULT);'], capture_output=True, text=True, env=dict(os.environ, P=PORTAL_PW)).stdout
sql("delete from compliance_shared_report; delete from compliance_snapshots; delete from compliance_attestations; delete from audit_events where event_type like 'compliance.%'; update settings set config_client_portal_enable=1, config_core_audit_enabled=1" if 'config_core_audit_enabled' in sql("show columns from settings like 'config_core_audit_enabled'") else "delete from compliance_shared_report; delete from compliance_snapshots; delete from compliance_attestations; delete from audit_events where event_type like 'compliance.%'; update settings set config_client_portal_enable=1")
sql("delete from users where user_email='%s'; delete from contacts where contact_email='%s'" % (PORTAL_EMAIL, PORTAL_EMAIL))
cid = sql("select client_id from clients limit 1")
if not cid:
    sql("set session sql_mode=''; insert into clients (client_name, client_currency_code) values ('Scratch Client', 'USD')"); cid = sql("select max(client_id) from clients")
sql("insert into users (user_name, user_email, user_password, user_type, user_status, user_role_id) values ('Portal Person', '%s', '%s', 2, 1, 0)" % (PORTAL_EMAIL, hashed))
uid = sql("select user_id from users where user_email='%s'" % PORTAL_EMAIL)
sql("insert into contacts (contact_name, contact_email, contact_user_id, contact_client_id, contact_primary) values ('Portal Person', '%s', %s, %s, 1)" % (PORTAL_EMAIL, uid, cid))

admin = Sess(); portal = Sess()
def login(s, email, pw):
    st, html, h = s.req('/login.php'); data = {'email': email, 'password': pw, 'login': ''}
    t = csrf(html)
    if t: data['csrf_token'] = t
    return s.req('/login.php', data)
st, b, h = login(admin, EMAIL, PASSWORD)
check('admin signs in', st in (302, 303), st)
st, b, h = login(portal, PORTAL_EMAIL, PORTAL_PW)
check('portal user signs in', st in (302, 303), (st, h.get('Location')))

st, b, h = portal.req('/client/compliance.php')
check('portal: nothing published => redirected away, not shown', st in (302, 303) and 'Security and compliance' not in b, st)
st, home, h = portal.req('/client/index.php')
check('portal menu has no Security link while nothing is published', '/client/compliance.php' not in home)

# admin: snapshot with data that must NOT leak, then publish
admin.req('/admin/post.php', {'csrf_token': csrf(admin.req('/admin/compliance_status.php')[1]), 'item_id': 'access_review', 'reviewer_name': 'Hidden Reviewer', 'reviewed_on': '2026-09-30', 'next_due_on': '', 'note': 'PRIVATE-EVIDENCE-NOTE', 'record_compliance_review': '1'}, referer='/admin/compliance_status.php')
admin.req('/admin/post.php', {'csrf_token': csrf(admin.req('/admin/compliance_status.php')[1]), 'take_compliance_snapshot': '1'}, referer='/admin/compliance_status.php')
sid = sql("select max(snapshot_id) from compliance_snapshots")
page = admin.req('/admin/compliance_status.php')[1]
check('admin page shows Share on a snapshot and explains the reduced view', 'Share' in page and 'never shared' in page)
st, b, h = admin.req('/admin/post.php', {'csrf_token': csrf(page), 'snapshot_id': '999999', 'note': 'x', 'publish_compliance_report': '1'}, referer='/admin/compliance_status.php')
check('publishing a missing snapshot is refused', sql("select count(*) from compliance_shared_report") == '0')
st, b, h = admin.req('/admin/post.php', {'csrf_token': 'wrong', 'snapshot_id': sid, 'note': 'x', 'publish_compliance_report': '1'}, referer='/admin/compliance_status.php')
check('a wrong CSRF token publishes nothing', sql("select count(*) from compliance_shared_report") == '0')
st, b, h = portal.req('/admin/post.php', {'csrf_token': 'x', 'snapshot_id': sid, 'note': 'x', 'publish_compliance_report': '1'}, referer='/admin/compliance_status.php')
check('a portal user cannot publish', sql("select count(*) from compliance_shared_report") == '0')
portal = Sess(); login(portal, PORTAL_EMAIL, PORTAL_PW)  # the admin endpoint ends a portal session; sign in again
st, b, h = admin.req('/admin/post.php', {'csrf_token': csrf(page), 'snapshot_id': sid, 'note': 'Hello <b>team</b>', 'publish_compliance_report': '1'}, referer='/admin/compliance_status.php')
check('admin publishes the snapshot', st in (302, 303) and sql("select snapshot_id from compliance_shared_report") == sid)
check('an audit event records publishing', sql("select count(*) from audit_events where event_type='compliance.report_published'") == '1')

st, b, h = portal.req('/client/compliance.php')
check('portal user now sees the report (200)', st == 200 and 'Security and compliance' in b, st)
check('the note is shown, escaped', 'Hello &lt;b&gt;team&lt;/b&gt;' in b and 'Hello <b>team' not in b)
check('framework scores and checklist item titles are shown', 'ISO/IEC 27001' in b and 'HIPAA' in b and 'User access review' in b)
check('the not-a-certification disclaimer is shown', 'not a certification' in b)
for leak in ['Hidden Reviewer', 'PRIVATE-EVIDENCE-NOTE', 'settings_security.php', 'users.php', 'agents have no MFA', 'agents use MFA']:
    check('portal page does not leak: ' + leak, leak not in b)
check('portal menu now has the Security link', '/client/compliance.php' in portal.req('/client/index.php')[1])
check('framework filter works and tolerates junk', portal.req('/client/compliance.php?framework=pci')[0] == 200 and portal.req('/client/compliance.php?framework=%27%3Cscript%3E')[0] == 200)
check('portal user cannot reach the admin page or the export', portal.req('/admin/compliance_status.php')[0] in (302, 303, 403) and portal.req('/admin/compliance_report.php?format=csv')[0] in (302, 303, 403))
portal = Sess(); login(portal, PORTAL_EMAIL, PORTAL_PW)
anon = Sess()
st, b, h = anon.req('/client/compliance.php')
check('anonymous visitors are sent to sign in', st in (302, 303) and 'Security and compliance' not in b, st)

# a later snapshot does not change what is shared; unpublish hides it
admin.req('/admin/post.php', {'csrf_token': csrf(admin.req('/admin/compliance_status.php')[1]), 'take_compliance_snapshot': '1'}, referer='/admin/compliance_status.php')
check('a newer snapshot does not replace the published one', sql("select snapshot_id from compliance_shared_report") == sid)
admin.req('/admin/post.php', {'csrf_token': csrf(admin.req('/admin/compliance_status.php')[1]), 'unpublish_compliance_report': '1'}, referer='/admin/compliance_status.php')
check('unpublishing removes it', sql("select count(*) from compliance_shared_report") == '0')
st, b, h = portal.req('/client/compliance.php')
check('portal user is redirected away again', st in (302, 303) and 'Security and compliance' not in b)
check('an audit event records unpublishing', sql("select count(*) from audit_events where event_type='compliance.report_unpublished'") == '1')
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
