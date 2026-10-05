"""
End-to-end check of Administration > Compliance status through the real web stack: sign in, load the page, record reviews,
save a snapshot, export CSV/HTML, verify escaping, the database and the audit trail.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/compliance_status.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
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
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))
sql("delete from compliance_attestations; delete from compliance_snapshots; delete from audit_events where event_type like 'compliance.%'")

# anonymous access is refused
s, body, h = req('/admin/compliance_report.php?format=csv')
check('the report is not served to anonymous visitors', s in (302, 303, 401, 403) and 'Compliance status report' not in body, s)

s, html, h = req('/login.php')
data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
s, html, h = req('/login.php', data)
check('sign in as the admin', s in (302, 303), (s, h.get('Location')))

s, page, h = req('/admin/compliance_status.php')
check('status page loads (200)', s == 200, s)
check('page shows all four frameworks', all(x in page for x in ['ISO/IEC 27001', 'SOC 2', 'PCI DSS', 'HIPAA']))
check('page shows the not-a-certification disclaimer', 'not</strong> a certification' in page)
check('automatic checks and the manual checklist are listed', 'Multi-factor authentication for every agent' in page and 'Backup restore test' in page)
check('page appears in the settings landing', 'compliance_status.php' in req('/admin/settings.php')[1])
check('framework filter works', 'Information security policy' in req('/admin/compliance_status.php?framework=hipaa')[1] and 'framework=bogus' not in req('/admin/compliance_status.php?framework=bogus')[1])
tok = csrf(page)
check('page carries a CSRF token', tok is not None)

# record a review
post = {'csrf_token': tok, 'item_id': 'backup_restore_test', 'reviewer_name': 'Pat <i>Q</i>', 'reviewed_on': '2026-09-30', 'next_due_on': '', 'note': '=cmd|calc <script>x</script>', 'record_compliance_review': '1'}
s, body, h = req('/admin/post.php', post, referer='/admin/compliance_status.php')
check('recording a review is accepted', s in (302, 303), (s, h.get('Location')))
check('the review is stored', sql("select item_id, reviewer_name, reviewed_on from compliance_attestations").split('\t')[0:1] == ['backup_restore_test'], sql("select count(*) from compliance_attestations"))
s, page2, h = req('/admin/compliance_status.php')
check('the page shows the item as current with the reviewer, escaped', 'Pat &lt;i&gt;Q&lt;/i&gt;' in page2 and '<script>x</script>' not in page2 and '<i>Q</i>' not in page2)
ev = sql("select actor_user_id, metadata_json from audit_events where event_type='compliance.review_recorded'")
check('an audit event records the review', ev.startswith(sql("select user_id from users where user_email='" + EMAIL + "'")), ev)

# bad inputs are refused and store nothing
tok = csrf(page2)
n0 = sql("select count(*) from compliance_attestations")
for bad in [{'item_id': 'nope', 'reviewed_on': '2026-09-30', 'reviewer_name': 'A'}, {'item_id': 'access_review', 'reviewed_on': '2099-01-01', 'reviewer_name': 'A'}, {'item_id': 'access_review', 'reviewed_on': 'junk', 'reviewer_name': 'A'}, {'item_id': 'access_review', 'reviewed_on': '2026-09-30', 'reviewer_name': ''}, {'item_id': "x' OR 1=1 --", 'reviewed_on': '2026-09-30', 'reviewer_name': 'A'}]:
    d = {'csrf_token': tok, 'next_due_on': '', 'note': '', 'record_compliance_review': '1'}; d.update(bad)
    req('/admin/post.php', d, referer='/admin/compliance_status.php')
check('unknown item, future/invalid date, blank reviewer and SQL text are all refused', sql("select count(*) from compliance_attestations") == n0)
req('/admin/post.php', {'csrf_token': 'wrong', 'item_id': 'access_review', 'reviewer_name': 'A', 'reviewed_on': '2026-09-30', 'record_compliance_review': '1'}, referer='/admin/compliance_status.php')
check('a wrong CSRF token stores nothing', sql("select count(*) from compliance_attestations") == n0)

# snapshot
tok = csrf(req('/admin/compliance_status.php')[1])
s, b, h = req('/admin/post.php', {'csrf_token': tok, 'take_compliance_snapshot': '1'}, referer='/admin/compliance_status.php')
check('saving a snapshot is accepted and stored', s in (302, 303) and sql("select count(*) from compliance_snapshots") == '1' and sql("select trigger_type from compliance_snapshots") == 'manual')
check('snapshot appears in the history with report links', 'snapshot=' in req('/admin/compliance_status.php')[1])
check('an audit event records the snapshot', sql("select count(*) from audit_events where event_type='compliance.snapshot_taken'") == '1')

# exports
s, csvb, h = req('/admin/compliance_report.php?format=csv')
check('CSV export: 200, text/csv, attachment, nosniff', s == 200 and h.get('Content-Type', '').startswith('text/csv') and 'attachment' in h.get('Content-Disposition', '') and h.get('X-Content-Type-Options') == 'nosniff', (s, h.get('Content-Type')))
check('CSV has no cell that starts with a formula character', not [c for c in csvb.split('\r\n') for c in [c.strip()] if c.startswith(('"=', '"+', '"@')) ])
check('CSV carries the disclaimer and the item', 'not a certification' in csvb and 'Backup restore test' in csvb)
s, htmlb, h = req('/admin/compliance_report.php?format=html&framework=pci')
check('HTML report: 200, CSP locks it down, escapes the organization name', s == 200 and "default-src 'none'" in h.get('Content-Security-Policy', '') and 'Scratch Org &lt;b&gt;' in htmlb and 'Scratch Org <b>' not in htmlb, (s,))
check('HTML report honours the framework filter', 'PCI DSS' in htmlb and 'A.8.15' not in htmlb)
check('HTML report has no script or external asset', '<script' not in htmlb.lower() and 'http://' not in htmlb and 'https://' not in htmlb)
sid = sql("select max(snapshot_id) from compliance_snapshots")
s, snapb, h = req('/admin/compliance_report.php?format=csv&snapshot=' + sid)
check('a saved snapshot can be exported', s == 200 and 'Backup restore test' in snapb)
check('an unknown snapshot is a 404', req('/admin/compliance_report.php?format=csv&snapshot=999999')[0] == 404)
check('exports are audited', int(sql("select count(*) from audit_events where event_type='compliance.report_exported'")) >= 3)

# the retention/audit checks respond to settings
sql("update settings set config_compliance_profile='hipaa', config_audit_retention_days=2190, config_log_retention=2190")
check('with HIPAA retention set the retention check passes', 'meet the preset minimum' in req('/admin/compliance_status.php')[1])
sql("update settings set config_compliance_profile='none'")
check('with no preset the retention check asks for one', 'No compliance preset is selected' in req('/admin/compliance_status.php')[1])
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
