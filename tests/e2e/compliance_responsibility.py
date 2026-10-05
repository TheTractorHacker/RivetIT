"""
End-to-end check of Administration > Who is responsible for compliance sections (an MSP or internal IT) through the real web stack: sign in, load the page, record reviews,
save a snapshot, export CSV/HTML, verify escaping, the database and the audit trail.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/compliance_responsibility.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
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
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
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
sql("delete from compliance_shared_report; delete from compliance_snapshots; delete from compliance_attestations; delete from compliance_responsibilities; delete from audit_events where event_type like 'compliance.%'; update settings set config_client_portal_enable=1, config_core_audit_enabled=1" if 'config_core_audit_enabled' in sql("show columns from settings like 'config_core_audit_enabled'") else "delete from compliance_shared_report; delete from compliance_snapshots; delete from compliance_attestations; delete from compliance_responsibilities; delete from audit_events where event_type like 'compliance.%'; update settings set config_client_portal_enable=1")
sql("delete from users where user_email='%s'; delete from contacts where contact_email='%s'" % (PORTAL_EMAIL, PORTAL_EMAIL))
cid = sql("select client_id from clients limit 1")
if not cid:
    sql("set session sql_mode=''; insert into clients (client_name, client_currency_code) values ('Scratch Client', 'USD')"); cid = sql("select max(client_id) from clients")
sql("insert into users (user_name, user_email, user_password, user_type, user_status, user_role_id) values ('Portal Person', '%s', '%s', 2, 1, 0)" % (PORTAL_EMAIL, hashed))
uid = sql("select user_id from users where user_email='%s'" % PORTAL_EMAIL)
sql("insert into contacts (contact_name, contact_email, contact_user_id, contact_client_id, contact_primary) values ('Portal Person', '%s', %s, %s, 1)" % (PORTAL_EMAIL, uid, cid))


sql("delete from compliance_responsibilities; delete from audit_events where event_type like 'compliance.%'")
sql("set session sql_mode=''; delete from vendors where vendor_name like 'Acme%'; insert into vendors (vendor_name) values ('Acme <b>MSP</b>'), ('Acme Archived')")
sql("update vendors set vendor_archived_at=NOW() where vendor_name='Acme Archived'")
V = sql("select vendor_id from vendors where vendor_name='Acme <b>MSP</b>'"); VA = sql("select vendor_id from vendors where vendor_name='Acme Archived'")
admin = Sess(); portal = Sess()
def login(s, email, pw):
    st, html, h = s.req('/login.php'); data = {'email': email, 'password': pw, 'login': ''}
    t = csrf(html)
    if t: data['csrf_token'] = t
    return s.req('/login.php', data)
check('admin signs in', login(admin, EMAIL, PASSWORD)[0] in (302, 303))
check('portal user signs in', login(portal, PORTAL_EMAIL, PORTAL_PW)[0] in (302, 303))
def save(pairs, token=None):
    page = admin.req('/admin/compliance_status.php')[1]
    d = {'csrf_token': token or csrf(page), 'save_compliance_responsibilities': '1', 'r_key[]': [k for k, _ in pairs], 'r_party[]': [p for _, p in pairs]}
    return admin.req('/admin/post.php', d, referer='/admin/compliance_status.php')

page = admin.req('/admin/compliance_status.php')[1]
check('page has the Who is responsible card with sections and Internal IT', 'Who is responsible' in page and 'section:Resilience' in page and 'Internal IT' in page)
check('vendors are offered, escaped; archived ones are not', 'Acme &lt;b&gt;MSP&lt;/b&gt;' in page and 'Acme Archived' not in page and 'Acme <b>MSP</b>' not in page)
check('no Responsible column while nothing is assigned', '<th>Responsible</th>' not in page)

save([('section:Resilience', V)])
check('assigning a section stores the vendor and its name', sql("select party_ref, party_name from compliance_responsibilities where assign_key='section:Resilience'") == V + "\tAcme <b>MSP</b>")
page = admin.req('/admin/compliance_status.php')[1]
check('Responsible column appears, name escaped, unassigned items say Internal', '<th>Responsible</th>' in page and 'Acme &lt;b&gt;MSP&lt;/b&gt;' in page and 'Internal' in page and 'Acme <b>MSP</b>' not in page)
check('an audit event records the change', sql("select count(*) from audit_events where event_type='compliance.responsibilities_changed'") == '1')

save([('item:backup_restore_test', 'internal')])
check('an item can be set to Internal IT even when its section is the MSP', sql("select party_name from compliance_responsibilities where assign_key='item:backup_restore_test'") == 'Internal IT')
save([('item:backup_restore_test', 'inherit')])
check('"Same as its section" removes the override', sql("select count(*) from compliance_responsibilities where assign_key='item:backup_restore_test'") == '0')

n = sql("select count(*) from compliance_responsibilities")
save([('section:Not A Section', V), ('item:nope', V), ('bogus', V), ('section:Resilience', '999999'), ('section:Resilience', VA), ('section:Resilience', "1; drop table users")])
check('unknown sections/items, unknown or archived vendors and junk are ignored', sql("select count(*) from compliance_responsibilities") == n and sql("select party_ref from compliance_responsibilities where assign_key='section:Resilience'") == V)
save([('section:Access control', V)], token='wrong')
check('a wrong CSRF token changes nothing', sql("select count(*) from compliance_responsibilities") == n)
check('the users table is intact', int(sql("select count(*) from users")) >= 1)

st, csvb, h = admin.req('/admin/compliance_report.php?format=csv')
check('CSV export has a Responsible column with the MSP and Internal', st == 200 and 'Responsible' in csvb.split('\r\n')[5] and 'Acme <b>MSP</b>' in csvb and 'Internal' in csvb, st)
st, htmlb, h = admin.req('/admin/compliance_report.php?format=html')
check('HTML export shows the column, escaped', 'Acme &lt;b&gt;MSP&lt;/b&gt;' in htmlb and 'Acme <b>MSP</b>' not in htmlb and '<th>Responsible</th>' in htmlb)

admin.req('/admin/post.php', {'csrf_token': csrf(admin.req('/admin/compliance_status.php')[1]), 'take_compliance_snapshot': '1'}, referer='/admin/compliance_status.php')
sid = sql("select max(snapshot_id) from compliance_snapshots")
sql("update vendors set vendor_name='Renamed Co' where vendor_id=" + V)
st, snapb, h = admin.req('/admin/compliance_report.php?format=csv&snapshot=' + sid)
check('a snapshot keeps the responsible party as it was', 'Acme <b>MSP</b>' in snapb)
admin.req('/admin/post.php', {'csrf_token': csrf(admin.req('/admin/compliance_status.php')[1]), 'snapshot_id': sid, 'note': '', 'publish_compliance_report': '1'}, referer='/admin/compliance_status.php')
portal = Sess(); login(portal, PORTAL_EMAIL, PORTAL_PW)
st, b, h = portal.req('/client/compliance.php')
check('the shared portal page shows who is responsible, escaped', st == 200 and '<th>Responsible</th>' in b and 'Acme &lt;b&gt;MSP&lt;/b&gt;' in b and 'Acme <b>MSP</b>' not in b, st)
save([('section:Resilience', 'internal')])
check('choosing Internal IT for the section clears the assignment', sql("select count(*) from compliance_responsibilities where assign_key='section:Resilience'") == '0')
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
