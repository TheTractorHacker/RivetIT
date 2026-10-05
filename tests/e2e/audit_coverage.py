"""
End-to-end check that administrative/security actions reach the audit trail and show on Administration > Audit log:
sign-in, a settings change, an API key and a credential-related log type are mirrored into audit_events; ordinary
business logs (tickets) are not; turning audit off stops recording.

Needs a THROWAWAY app (scripts/setup_cli.php run from scripts/, $config_https_only = FALSE, `php -S`), TEST_DB_USER / TEST_DB_PASS:
  python3 tests/e2e/audit_coverage.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
"""
import re, sys, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(path, data=None, referer=None):
    headers = {'Referer': BASE + referer} if referer else {}
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        r = opener.open(urllib.request.Request(BASE + path, data=body, headers=headers)); return r.status, r.read().decode('utf-8', 'replace'), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q): return subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True).stdout.strip()
def csrf(h):
    m = re.search(r'name="csrf_token" value="([^"]+)"', h); return m.group(1) if m else None
res = []
def check(n, ok, d=''):
    res.append(bool(ok)); print(('PASS' if ok else 'FAIL') + '  ' + n + (('  [' + str(d) + ']') if d and not ok else ''))
IT = os.environ.get('EDITION') == 'it'  # RivetIT audits credential reveals explicitly (vault.credential_revealed), so the log mirror skips them
sql("update settings set config_core_audit_enabled=1") if not IT else None
sql("delete from audit_events")
s, h, _ = req('/login.php'); t = csrf(h)
req('/login.php', {'email': EMAIL, 'password': PASSWORD, 'login': '', 'csrf_token': t})
check('sign-in recorded', sql("select count(*) from audit_events where event_type='auth.login_success'") == '1')
s, sec, _ = req('/admin/settings_security.php')
fields = {k: v for k, v in re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', sec) if k != 'csrf_token'}
fields.update({'csrf_token': csrf(sec), 'edit_security_settings': '1'})
req('/admin/post.php', fields, referer='/admin/settings_security.php')
row = sql("select event_type, actor_user_id, entity_type, summary from audit_events where event_type='settings.edit' order by audit_id desc limit 1")
check('a settings change is audited (settings.edit) with the actor', row.startswith('settings.edit\t' + sql("select user_id from users where user_email='" + EMAIL + "'") + '\tsettings\t'), row)
sql("insert into logs set log_type='Ticket', log_action='Create', log_description='x', log_created_at=NOW()") if False else None
# direct: the mirror used by logAction, for each audited type
CRED = 'Edit' if IT else 'View'
php = r'''<?php chdir(APPDIR); require "config.php"; require "vendor/autoload.php"; $mysqli=mysqli_connect($dbhost,$dbusername,$dbpassword,$database);
$GLOBALS["mysqli"]=$mysqli; require "functions.php"; $session_user_id=1; $session_ip="127.0.0.1"; $session_user_agent="t"; $session_name="T";
logAction("Credential","CREDACTION","viewed",3,9); logAction("API Key","Create","made",0,4); logAction("User","Disable","off",0,2); logAction("Ticket","Create","not audited",1,5); logAction("Payment Provider","Edit","pp",0,1);'''
php = php.replace('APPDIR', repr(os.environ.get('APP_DIR', os.getcwd()))).replace('CREDACTION', CRED)
open('/tmp/_audit_probe.php', 'w').write(php)
out = subprocess.run(['php', '/tmp/_audit_probe.php'], capture_output=True, text=True); os.unlink('/tmp/_audit_probe.php')
ev = sql("select group_concat(event_type order by audit_id) from audit_events")
for e in (['credential.edit'] if IT else ['credential.view']) + ['api_key.create', 'user.disable', 'payment_provider.edit']:
    check('logAction mirrors ' + e, e in ev, (ev, out.stderr[:200]))
check('ordinary business logs (tickets) are not audited', 'ticket.create' not in ev)
CE = 'credential.edit' if IT else 'credential.view'
check('entity id and client carried', sql("select entity_id from audit_events where event_type='" + CE + "'") == '9' and '"client_id":3' in sql("select metadata_json from audit_events where event_type='" + CE + "'").replace(' ', ''))
s, page, _ = req('/admin/audit_trail.php')
check('the Audit trail page lists them (200)', s == 200 and ('credential.edit' if IT else 'credential.view') in page and 'settings.edit' in page, s)
s, page, _ = req('/admin/audit_trail.php?type=credential')
check('filtering by event group narrows the list', ('credential.edit' if IT else 'credential.view') in page and 'settings.edit' not in page and 'api_key.create' not in page)
s, page, _ = req('/admin/audit_trail.php?q=' + urllib.parse.quote("' OR 1=1 --"))
check('a hostile search string is handled safely (no rows, no error)', s == 200 and 'No events match' in page, s)
s, csvb, hh = req('/admin/audit_trail.php?export=csv')
check('CSV export downloads the events and records itself', s == 200 and 'text/csv' in (hh.get('Content-Type') or '') and ('credential.edit' if IT else 'credential.view') in csvb and sql("select count(*) from audit_events where event_type='audit.exported'") == '1', s)
s, page, _ = req('/admin/audit_trail.php?from=2000-01-01&to=2000-01-02')
check('date range filter excludes today', 'No events match' in page)
sql("delete from audit_events"); sql("update settings set config_core_audit_enabled=0") if not IT else None
if IT:  # a vault reveal is audited where it happens, so the mirror must not add a second entry
    php = php.replace('logAction("Credential","Edit"', 'logAction("Credential","View"')
subprocess.run(['php', '/tmp/_audit_probe.php'], capture_output=True) if os.path.exists('/tmp/_audit_probe.php') else None
open('/tmp/_audit_probe.php', 'w').write(php); subprocess.run(['php', '/tmp/_audit_probe.php'], capture_output=True); os.unlink('/tmp/_audit_probe.php')
check('with auditing off nothing is recorded' if not IT else 'credential reveals are not duplicated by the log mirror', sql("select count(*) from audit_events") == ('0' if not IT else '3'))
sql("update settings set config_core_audit_enabled=1") if not IT else None
print("SUMMARY %d/%d passed" % (sum(res), len(res)))
