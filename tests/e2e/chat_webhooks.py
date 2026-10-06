"""
End-to-end check of Slack/Teams chat destinations and the Intune Sync Now button through the real web stack, against LOCAL mocks only.

Needs a THROWAWAY copy of the app installed with scripts/setup_cli.php against a scratch database. Its config.php needs
$config_https_only = FALSE and these test constants (they exist for tests; never set them on a real server):
  define('RIVETIT_CHAT_ALLOW_LOCAL_HTTP', true);
  define('RIVETIT_GRAPH_BASE_URL', 'http://127.0.0.1:18298/v1.0'); define('RIVETIT_GRAPH_AUTHORITY_URL', 'http://127.0.0.1:18298');
Serve it with `php -S 127.0.0.1:18299 -t <app dir>`, run tests/mock/graph_mock.php on 18298 and tests/mock/slack_webhook.php on 18297.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/chat_webhooks.py http://127.0.0.1:18299 <scratch db> <admin email> <admin password> <slack mock log file>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE, DB, EMAIL, PASSWORD, SLACKLOG = sys.argv[1:6]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
assert 'scratch' in DB
jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(path, data=None, referer=None):
    headers = {'Referer': BASE + referer} if referer else {}
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        r = opener.open(urllib.request.Request(BASE + path, data=body, headers=headers)); text = r.read().decode('utf-8', 'replace')
        if '/modals/' in path:
            text = json.loads(text)['content']   # modals answer {"content": "<html>"}
        return r.status, text
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')
def sql(q):
    return subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True).stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'csrf_token=([0-9a-f]{16,})', html); return m.group(1) if m else None
fails = 0
def check(name, ok, detail=''):
    global fails
    if not ok: fails += 1
    print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))
def flash():
    s, html = req('/admin/settings_webhooks.php')
    return html
REF = '/admin/settings_webhooks.php'

s, html = req('/login.php'); d = {'email': EMAIL, 'password': PASSWORD, 'login': ''}; t = csrf(html)
if t: d['csrf_token'] = t
check('sign in', req('/login.php', d)[0] in (302, 303))
s, page = req(REF)
s, add = req('/admin/modals/webhook/webhook_add.php'); tok = csrf(add)
check('webhooks page renders', s == 200 and 'Add Webhook' in page and tok)
check('add modal has destination type and routing fields', 'webhook_type' in add and 'webhook_min_priority' in add and 'webhook_client_ids' in add and 'Slack (Incoming Webhook)' in add)
SECRET_PATH = '/slack/ok'
def add_hook(name, typ, url, events=('ticket.created',), minp='', clients=(), token=None):
    data = {'csrf_token': token or tok, 'add_webhook': '1', 'webhook_name': name, 'webhook_url': url, 'webhook_type': typ, 'webhook_events[]': list(events), 'webhook_enabled': '1', 'webhook_min_priority': minp}
    if clients: data['webhook_client_ids[]'] = list(clients)
    return req('/admin/post.php', data, REF)

sql("delete from webhooks; delete from webhook_deliveries;")
# CSRF
add_hook('NoCsrf', 'slack', 'http://127.0.0.1:18297/slack/ok', token='bad')
check('CSRF: a post with a bad token creates nothing', sql("select count(*) from webhooks") == '0')
# SSRF refusals through the real handler
for bad in ['http://127.0.0.1:18297/slack/ok' if False else 'https://169.254.169.254/latest/meta-data/', 'https://10.0.0.5/services/x', 'http://example.com/services/x', 'https://localhost/x']:
    add_hook('Bad', 'slack', bad)
check('SSRF: private, metadata, plain-http and localhost-https chat URLs are rejected by the form', sql("select count(*) from webhooks") == '0')
# real accept (public literal IP: validated only, never contacted by this test)
add_hook('Ops Slack', 'slack', 'https://8.8.8.8/services/T000/B000/SUPERSECRETTOKEN', events=['ticket.created', 'ticket.replied'], minp='High', clients=['1', '2'])
row = sql("select webhook_type, webhook_min_priority, webhook_client_ids, left(webhook_url,5), webhook_secret from webhooks where webhook_name='Ops Slack'").split('\t')
check('chat webhook saved with type, priority and client filters', row[:3] == ['slack', 'High', '1,2'], row)
check('chat URL is stored encrypted (not plaintext)', row[3] == 'ENC2:' and 'SUPERSECRET' not in sql("select webhook_url from webhooks where webhook_name='Ops Slack'"))
wid = sql("select webhook_id from webhooks where webhook_name='Ops Slack'")
page = flash()
check('list shows a Slack badge and host only, never the secret path', 'Slack' in page and '8.8.8.8' in page and 'SUPERSECRETTOKEN' not in page and 'T000' not in page)
s, edit = req('/admin/modals/webhook/webhook_edit.php?id=' + wid)
check('edit modal never echoes the chat URL and offers the test button', 'SUPERSECRETTOKEN' not in edit and '8.8.8.8' not in edit and 'name="test_webhook"' in edit and 'selected' in edit)
# edit keeping URL
req('/admin/post.php', {'csrf_token': tok, 'edit_webhook': '1', 'webhook_id': wid, 'webhook_name': 'Ops Slack 2', 'webhook_url': '', 'webhook_type': 'slack', 'webhook_events[]': ['ticket.created'], 'webhook_enabled': '1', 'webhook_min_priority': 'Critical'}, REF)
row = sql("select webhook_name, webhook_min_priority, webhook_events from webhooks where webhook_id=" + wid).split('\t')
check('edit with a blank URL keeps the saved URL and updates the filters', row == ['Ops Slack 2', 'Critical', 'ticket.created'] and sql("select left(webhook_url,5) from webhooks where webhook_id=" + wid) == 'ENC2:')
# test button against the local mock (allowed by the test constant)
open(SLACKLOG, 'w').close()
add_hook('Mock Teams', 'teams', 'http://127.0.0.1:18297/teams/ok')
tid = sql("select webhook_id from webhooks where webhook_name='Mock Teams'")
req('/admin/post.php', {'csrf_token': tok, 'test_webhook': '1', 'webhook_id': tid}, REF)
lines = [json.loads(l) for l in open(SLACKLOG).read().splitlines() if l]
card = json.loads(lines[0]['body']) if lines else {}
check('test button: one labelled Adaptive Card message reached the mock', len(lines) == 1 and 'TEST MESSAGE' in lines[0]['body'] and card.get('attachments', [{}])[0].get('content', {}).get('version') == '1.4', lines)
page = flash()
check('flash shows the HTTP result without the URL; delivery log shows the Teams row', 'HTTP 202' in page and '127.0.0.1:18297/teams' not in page and 'test.teams' in page and 'Teams' in page)
# a test message to a generic hook is refused
add_hook('Plain', 'generic', 'https://8.8.8.8/hook')
gid = sql("select webhook_id from webhooks where webhook_name='Plain'")
sql("update webhooks set webhook_url='https://8.8.8.8/hook' where webhook_id=" + gid)
req('/admin/post.php', {'csrf_token': tok, 'test_webhook': '1', 'webhook_id': gid}, REF)
check('test button refuses generic webhooks (no outbound call)', len([l for l in open(SLACKLOG).read().splitlines() if l]) == 1)
check('generic webhook still saved as plaintext URL, type generic', sql("select webhook_type, webhook_url from webhooks where webhook_id=" + gid) == 'generic\thttps://8.8.8.8/hook')
# XSS: webhook name
add_hook('<script>alert(1)</script>', 'teams', 'http://127.0.0.1:18297/teams/ok')
check('webhook name is HTML-escaped in the list', '<script>alert(1)</script>' not in flash())

# ---------------- Intune: Sync Now and error classification through the page
def mock(s):
    c = urllib.request.Request('http://127.0.0.1:18298/__state', data=json.dumps(s).encode(), headers={'Content-Type': 'application/json'}); return urllib.request.urlopen(c).read()
mock({'__reset': 1})
sql("delete from microsoft_integrations; delete from intune_sync_log; delete from asset_intune_links; delete from assets;")
s, ipage = req('/admin/settings_integrations.php?tab=devicesync'); itok = csrf(ipage) or tok
IREF = '/admin/settings_integrations.php'
req('/admin/post.php', {'csrf_token': itok, 'save_microsoft_integration': '1', 'tenant_id': 'ok-tenant', 'client_id': 'client-1', 'client_secret': 'mock-secret', 'enabled': '1', 'intune_sync_enabled': '1', 'directory_sync_enabled': '0'}, IREF)
check('integration saved; secret stored encrypted', sql("select left(client_secret_enc,5) from microsoft_integrations") == 'ENC2:')
req('/admin/post.php', {'csrf_token': 'bad', 'sync_intune_devices': '1'}, IREF)
check('Sync Now with a bad CSRF token does nothing', sql("select count(*) from intune_sync_log") == '0')
req('/admin/post.php', {'csrf_token': itok, 'sync_intune_devices': '1'}, IREF)
check('Sync Now ran: 150 devices became assets, logged as success', sql("select status, devices_created from intune_sync_log order by id desc limit 1") == 'success\t150' and sql("select count(*) from assets") == '150')
check('access token cached (encrypted) with an expiry', sql("select left(token_cache_enc,5), token_expires_at is not null from microsoft_integrations") == 'ENC2:\t1')
tok_before = sql("select token_cache_enc from microsoft_integrations")
req('/admin/post.php', {'csrf_token': itok, 'sync_intune_devices': '1'}, IREF)
check('second Sync Now reused the cached token and created no duplicates', sql("select token_cache_enc from microsoft_integrations") == tok_before and sql("select count(*) from assets") == '150' and sql("select devices_created, devices_updated from intune_sync_log order by id desc limit 1") == '0\t150')
mock({'forbidden_devices': True})
req('/admin/post.php', {'csrf_token': itok, 'sync_intune_devices': '1'}, IREF)
s, ipage = req('/admin/settings_integrations.php?tab=devicesync')
check('missing consent: sync log has error_code and the page names DeviceManagementManagedDevices.Read.All', sql("select status, error_code from intune_sync_log order by id desc limit 1") == 'failed\tconsent_missing' and 'Admin consent missing for DeviceManagementManagedDevices.Read.All' in ipage and 'Last sync failed' in ipage)
req('/admin/post.php', {'csrf_token': itok, 'test_microsoft_integration': '1'}, IREF)
check('Test Connection records the classified failure', sql("select last_test_success, last_test_error_code from microsoft_integrations") == '0\tconsent_missing')
mock({'forbidden_devices': False})
req('/admin/post.php', {'csrf_token': itok, 'save_microsoft_integration': '1', 'tenant_id': 'badsecret', 'client_id': 'client-1', 'enabled': '1', 'intune_sync_enabled': '1', 'directory_sync_enabled': '0'}, IREF)
check('changing the tenant clears the cached token', sql("select token_cache_enc is null from microsoft_integrations") == '1')
req('/admin/post.php', {'csrf_token': itok, 'sync_intune_devices': '1'}, IREF)
check('bad secret is classified auth_failed', sql("select error_code from intune_sync_log order by id desc limit 1") == 'auth_failed')
req('/admin/post.php', {'csrf_token': itok, 'save_microsoft_integration': '1', 'tenant_id': 'ok-tenant', 'client_id': 'client-1', 'enabled': '1', 'intune_sync_enabled': '1', 'directory_sync_enabled': '0'}, IREF)
req('/admin/post.php', {'csrf_token': itok, 'test_microsoft_integration': '1'}, IREF)
check('Test Connection succeeds against the mock', sql("select last_test_success from microsoft_integrations") == '1')
print('\nALL PASSED' if fails == 0 else '\n%d FAILED' % fails); sys.exit(1 if fails else 0)
