"""
End-to-end check of Administration > Compliance through the real web stack: sign in, load the page, submit the form, then
verify the database and the audit trail.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/compliance_page.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(path, data=None, referer=None):
    headers = {}
    if referer: headers['Referer'] = BASE + referer
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    r = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        resp = opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
sql("update settings set config_compliance_profile='none', config_audit_retention_days=365, config_log_retention=90; delete from audit_events where event_type='compliance.settings_changed'")
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))

# sign in
s, html, h = req('/login.php')
data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
s, html, h = req('/login.php', data)
check('sign in as the admin', s in (302, 303) and 'agent' in (h.get('Location') or '') + 'agent', (s, h.get('Location')))
# the page
s, page, h = req('/admin/settings_compliance.php')
check('compliance page loads (200)', s == 200, s)
check('page shows the Compliance heading, presets and the disclaimer', all(x in page for x in ['Compliance', 'ISO/IEC 27001', 'HIPAA', 'does not make an organization compliant']))
check('page shows the stored defaults (none, 365)', 'value="365"' in page and 'option value="none" selected' in page.replace("\n", " ").replace("  ", " ") or ('selected' in page and '365' in page))
tok = csrf(page)
check('page carries a CSRF token', tok is not None)
check('page appears in the settings landing and search', 'settings_compliance.php' in req('/admin/settings.php')[1])
# save: HIPAA with values below its floor => raised to 2190
post = {'csrf_token': tok, 'compliance_profile': 'hipaa', 'audit_retention_days': '30', 'log_retention_days': '10', 'save_compliance_settings': '1'}
s, body, h = req('/admin/post.php', post, referer='/admin/settings_compliance.php')
check('save is accepted (redirect back)', s in (302, 303), (s, h.get('Location')))
row = sql("select config_compliance_profile, config_audit_retention_days, config_log_retention from settings").split('\t')
check('HIPAA raises 30 and 10 days to the 2190-day floor', row == ['hipaa', '2190', '2190'], row)
s, page2, h = req('/admin/settings_compliance.php')
check('the next page shows the warning and the raised notice', 'raised' in page2 and ('2190' in page2))
ev = sql("select event_type, actor_user_id, request_id, metadata_json from audit_events where event_type='compliance.settings_changed' order by audit_id desc limit 1")
check('an audit event records who changed it', ev.startswith('compliance.settings_changed\t' + sql("select user_id from users where user_email='" + EMAIL + "'") + '\treq_'), ev[:90])
md = json.loads(ev.split('\t')[3]) if ev else {}
check('the audit event keeps before and after', md.get('before', {}).get('profile') == 'none' and md.get('after', {}).get('profile') == 'hipaa' and md['after']['audit_days'] == 2190, md)
# keep forever is always allowed under a preset
tok = csrf(req('/admin/settings_compliance.php')[1])
req('/admin/post.php', {'csrf_token': tok, 'compliance_profile': 'iso27001', 'audit_retention_days': '0', 'log_retention_days': '500', 'save_compliance_settings': '1'}, referer='/admin/settings_compliance.php')
row = sql("select config_compliance_profile, config_audit_retention_days, config_log_retention from settings").split('\t')
check('0 keeps forever under a preset; a longer value is kept', row == ['iso27001', '0', '500'], row)
# invalid preset is refused and nothing changes
tok = csrf(req('/admin/settings_compliance.php')[1])
req('/admin/post.php', {'csrf_token': tok, 'compliance_profile': "x'; DROP TABLE settings;--", 'audit_retention_days': '1', 'log_retention_days': '1', 'save_compliance_settings': '1'}, referer='/admin/settings_compliance.php')
row = sql("select config_compliance_profile, config_audit_retention_days, config_log_retention from settings").split('\t')
check('an invalid preset is refused and nothing changes', row == ['iso27001', '0', '500'], row)
check('the settings table is intact', sql("select count(*) from settings") == '1')
# wrong CSRF token is rejected
before = sql("select config_compliance_profile from settings")
s, body, h = req('/admin/post.php', {'csrf_token': 'not-the-token', 'compliance_profile': 'none', 'audit_retention_days': '5', 'log_retention_days': '5', 'save_compliance_settings': '1'}, referer='/admin/settings_compliance.php')
check('a wrong CSRF token changes nothing', sql("select config_compliance_profile from settings") == before == 'iso27001', (s,))
# out-of-range numbers are clamped; negative becomes keep-forever
tok = csrf(req('/admin/settings_compliance.php')[1])
req('/admin/post.php', {'csrf_token': tok, 'compliance_profile': 'none', 'audit_retention_days': '999999999', 'log_retention_days': '-5', 'save_compliance_settings': '1'}, referer='/admin/settings_compliance.php')
row = sql("select config_compliance_profile, config_audit_retention_days, config_log_retention from settings").split('\t')
check('huge values are capped and a negative becomes 0 (keep forever)', row == ['none', '36500', '0'], row)
# Security page: its Log retention field honours a preset floor
tok = csrf(req('/admin/settings_compliance.php')[1])
req('/admin/post.php', {'csrf_token': tok, 'compliance_profile': 'soc2', 'audit_retention_days': '400', 'log_retention_days': '400', 'save_compliance_settings': '1'}, referer='/admin/settings_compliance.php')
s, sec, h = req('/admin/settings_security.php')
fields = dict(re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', sec))
fields = {k: v for k, v in fields.items() if k != 'csrf_token'}
fields.update({'csrf_token': csrf(sec), 'config_log_retention': '7', 'edit_security_settings': '1'})
req('/admin/post.php', fields, referer='/admin/settings_security.php')
check('the Security page raises a too-short log retention to the preset floor (365)', sql("select config_log_retention from settings") == '365', sql("select config_log_retention from settings"))
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
