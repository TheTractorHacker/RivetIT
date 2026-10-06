"""
End-to-end check of the webhook internal-network allow-list (Administration > Webhooks > Internal network access):
the save-time URL check, the networks form (validation, round trip, audit, detection suggestions, one-click add), admin-only access,
and real delivery to a receiver on this server's own private LAN address.

Needs a THROWAWAY copy of the app (never a real site), installed with scripts/setup_cli.php against a scratch database, migrated to
the latest version, $config_https_only = FALSE, and served WITHOUT RIVETIT_WEBHOOK_ALLOW_PRIVATE (the point is the real policy):
`php -S 127.0.0.1:<port> -t <app dir>`. Give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/webhook_networks.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error, threading, time
from http.server import BaseHTTPRequestHandler, HTTPServer
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def session():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(op, path, data=None, referer=None):
    headers = {}
    if referer: headers['Referer'] = BASE + referer
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    r = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        resp = op.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))
def login(op, email, pw):
    s, html, h = req(op, '/login.php'); d = {'email': email, 'password': pw, 'login': ''}
    t = csrf(html)
    if t: d['csrf_token'] = t
    return req(op, '/login.php', d)[0] in (302, 303)
def flash_of(op):
    """Follow the redirect target the handler sends us to and return the page (flash messages render there)."""
    return req(op, '/admin/settings_webhooks.php')[1]

admin = session()
check('sign in', login(admin, EMAIL, PASSWORD))
PAGE = '/admin/settings_webhooks.php'
def post(op, d, page=PAGE):
    pg = req(op, page)[1]; d = dict(d); d['csrf_token'] = csrf(pg)
    s, body, h = req(op, '/admin/post.php', d, referer=page)
    return s, req(op, page)[1].replace('\\/', '/')   # the flash (a JSON-encoded toast) shows on the next page load
def nets():  return sql("select config_webhook_allowed_networks from settings").replace('\\n', '\n')
def save_nets(text): return post(admin, {'webhook_allowed_networks': text, 'save_webhook_networks': '1'})
def add_hook(url, name):
    return post(admin, {'webhook_name': name, 'webhook_url': url, 'webhook_events[]': ['auth.login_failed'], 'webhook_enabled': '1', 'add_webhook': '1'})
def hook_count(name): return int(sql("select count(*) from webhooks where webhook_name='%s'" % name))

sql("delete from webhooks where webhook_name like 'WN %'; update settings set config_webhook_allowed_networks=''; delete from audit_events where event_type='webhooks.networks_changed'")

# ---- page + defaults
s, page, h = req(admin, PAGE)
check('page has the Internal network access card, textarea and detect button', s == 200 and 'Internal network access' in page and 'name="webhook_allowed_networks"' in page and 'Use this server' in page, s)
check('the setting defaults to empty (public only)', nets() == '')

# ---- save-time URL checks with no networks
add_hook('http://127.0.0.1:9455/x', 'WN loopback')
check('loopback URL rejected at save time', hook_count('WN loopback') == 0)
s, pg = add_hook('http://192.168.77.10:9/x', 'WN private-none')
check('private URL rejected with no networks, and the message states the effective rule', hook_count('WN private-none') == 0 and 'allowed: public addresses only' in pg)
add_hook('http://169.254.169.254/latest', 'WN metadata')
check('cloud-metadata URL rejected', hook_count('WN metadata') == 0)
add_hook('http://93.184.216.34/x', 'WN public')
check('public literal IP accepted', hook_count('WN public') == 1)

# ---- invalid network lists are refused with every parse error
for label, text, expect in [
    ('garbage', 'not-a-network\n192.168.1.0/99', ['Invalid network: not-a-network', 'Invalid network: 192.168.1.0/99']),
    ('public range', '8.8.8.0/24', ['Not a private network']),
    ('too wide', '10.0.0.0/4', ['Network too wide']),
    ('loopback', '127.0.0.0/8', ['Not a private network']),
    ('link-local', '169.254.0.0/16', ['Not a private network']),
    ('mixed good+bad', '192.168.5.0/24\n8.8.8.0/24', ['Not a private network']),
]:
    s, pg = save_nets(text)
    check('networks refused: ' + label, all(e in pg for e in expect) and nets() == '', (s, [e for e in expect if e not in pg]))
sql("update settings set config_webhook_allowed_networks=''")

# ---- detection suggestions
s, pg, _h = req(admin, PAGE + '?detect=1')
detected = re.findall(r'name="network" value="([^"]+)"', pg)
check('detect button renders suggestions (interface, CIDR, Add) or the none-detected note', s == 200 and ('id="detected-networks"' in pg) and (detected and 'name="add_webhook_network"' in pg or 'No private network was detected' in pg))
check('suggestions are only private CIDRs', all(re.match(r'^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|100\.(6[4-9]|[789]\d|1[01]\d|12[0-7])\.)', c) for c in detected), detected)

# ---- save a valid list; round trip; audit; URL checks
s, pg = save_nets('192.168.77.0/24, 10.9.0.0/16\n192.168.77.0/24')
check('valid networks saved (deduplicated, canonical, newline separated)', nets() == '192.168.77.0/24\n10.9.0.0/16', nets())
check('success flash lists the networks', '192.168.77.0/24' in pg and 'saved' in pg.lower())
check('textarea shows the saved list (round trip)', re.search(r'<textarea[^>]*webhook_allowed_networks[^>]*>192\.168\.77\.0/24\n10\.9\.0\.0/16</textarea>', req(admin, PAGE)[1]) is not None)
check('audit event webhooks.networks_changed written', sql("select count(*) from audit_events where event_type='webhooks.networks_changed'") == '1')
meta = sql("select * from audit_events where event_type='webhooks.networks_changed' limit 1")
check('audit row carries before/after', '192.168.77.0/24' in meta and 'before' in meta and 'after' in meta, meta)
add_hook('http://192.168.77.10:9/x', 'WN inside')
check('private URL INSIDE an allowed network accepted at save time', hook_count('WN inside') == 1)
add_hook('http://192.168.78.10:9/x', 'WN outside')
s, pg = add_hook('http://10.8.0.1:9/x', 'WN outside2')
check('private URL OUTSIDE the allowed networks rejected', hook_count('WN outside') == 0 and hook_count('WN outside2') == 0)
check('rejection text shows the effective rule with the networks', 'allowed: public addresses and 192.168.77.0/24, 10.9.0.0/16' in pg)
add_hook('http://127.0.0.1:9455/x', 'WN loopback2')
check('loopback still rejected while networks are set', hook_count('WN loopback2') == 0)
# the live URL check on the Add page says the same thing in friendly words (and makes no request to the address)
_tok = csrf(req(admin, '/admin/webhook_new.php?dest=generic-json&step=connect')[1])
def live(url, dest='generic-json'):
    s_, body, _h = req(admin, '/admin/modals/webhook/webhook_action.php', {'csrf_token': _tok, 'wh_action': 'check_url', 'webhook_destination': dest, 'webhook_url': url})
    return json.loads(body)
r = live('http://192.168.78.10:9/x')
check('live URL check: a private address outside the allowed networks is explained and points to Internal network access', r.get('state') == 'private' and not r.get('ok') and 'private address (192.168.78.10)' in r['message'] and 'Internal network access' in r['message'], r)
r = live('http://192.168.77.10:9/x')
check('live URL check: a private address inside an allowed network looks good', r.get('state') == 'ok' and r.get('ok'), r)
r = live('http://127.0.0.1:9455/x')
check('live URL check: loopback is reported as never allowed', r.get('state') == 'blocked' and 'can never be used' in r['message'], r)
r = live('http://169.254.169.254/latest')
check('live URL check: the cloud-metadata address is reported as never allowed', r.get('state') == 'blocked', r)
r = live('http://93.184.216.34/x')
check('live URL check: a public address looks good', r.get('state') == 'ok', r)
# edit path uses the same policy
wid = sql("select webhook_id from webhooks where webhook_name='WN inside'")
post(admin, {'webhook_id': wid, 'webhook_name': 'WN inside', 'webhook_url': 'http://192.168.99.1:9/x', 'webhook_events[]': ['auth.login_failed'], 'webhook_enabled': '1', 'edit_webhook': '1'})
check('edit to a URL outside the networks rejected', sql("select webhook_url from webhooks where webhook_id=" + wid) == 'http://192.168.77.10:9/x')
post(admin, {'webhook_id': wid, 'webhook_name': 'WN inside', 'webhook_url': 'http://10.9.4.4:9/x', 'webhook_events[]': ['auth.login_failed'], 'webhook_enabled': '1', 'edit_webhook': '1'})
check('edit to a URL inside another allowed network accepted', sql("select webhook_url from webhooks where webhook_id=" + wid) == 'http://10.9.4.4:9/x')

# ---- one-click add (suggestion)
s, pg = post(admin, {'network': '172.20.0.0/16', 'add_webhook_network': '1'})
check('one-click Add appends to the saved list', nets() == '192.168.77.0/24\n10.9.0.0/16\n172.20.0.0/16', nets())
s, pg = post(admin, {'network': '8.8.8.0/24', 'add_webhook_network': '1'})
check('one-click Add of a public range refused', '8.8.8.0/24' not in nets() and 'Not a private network' in pg)
# no-op save writes no extra audit row
before = sql("select count(*) from audit_events where event_type='webhooks.networks_changed'")
save_nets(nets())
check('re-saving an unchanged list writes no audit event', sql("select count(*) from audit_events where event_type='webhooks.networks_changed'") == before)

# ---- event rules use the same policy
s, pg = post(admin, {'rule_name': 'WN rule', 'trigger_event': 'auth.login_failed', 'action_type': 'send_webhook', 'cfg_url': 'http://10.1.2.3:9/x', 'is_enabled': '1', 'save_event_rule': '1'}, page='/admin/event_rules.php')
check('event rule send_webhook rejects a private URL outside the networks', sql("select count(*) from automation_rules where name='WN rule'") == '0')
s, pg = post(admin, {'rule_name': 'WN rule', 'trigger_event': 'auth.login_failed', 'action_type': 'send_webhook', 'cfg_url': 'http://172.20.1.2:9/x', 'is_enabled': '1', 'save_event_rule': '1'}, page='/admin/event_rules.php')
check('event rule send_webhook accepts a private URL inside the networks', sql("select count(*) from automation_rules where name='WN rule'") == '1')
sql("delete from automation_rules where name='WN rule'")

# ---- non-admin cannot save
hashed = subprocess.run(['php', '-r', 'echo password_hash(getenv("P"), PASSWORD_DEFAULT);'], capture_output=True, text=True, env=dict(os.environ, P='Tech-Pass-12345!')).stdout
sql("delete from users where user_email='tech@scratch.test'")
sql("insert into users (user_name, user_email, user_password, user_type, user_status, user_role_id) values ('Scratch Tech', 'tech@scratch.test', '%s', 1, 1, 2)" % hashed)
tech = session()
if login(tech, 'tech@scratch.test', 'Tech-Pass-12345!'):
    # a technician has no admin CSRF page; take a token from the main app and try the handler anyway
    tok = csrf(req(tech, '/agent/index.php')[1] or '') or csrf(req(tech, '/user/index.php')[1] or '') or 'x'
    req(tech, '/admin/post.php', {'csrf_token': tok, 'webhook_allowed_networks': '10.0.0.0/8', 'save_webhook_networks': '1'}, referer=PAGE)
    check('non-admin POST does not change the setting', nets() == '192.168.77.0/24\n10.9.0.0/16\n172.20.0.0/16', nets())
    s, body, h = req(tech, PAGE)
    check('non-admin cannot open the Webhooks page', 'Internal network access' not in body, s)
else:
    check('non-admin login (setup)', False)
sql("delete from users where user_email='tech@scratch.test'")

# ---- real delivery to a receiver on this server's private LAN address
detect = subprocess.run(['php', '-r', 'require "%s/vendor/autoload.php"; foreach (RivetCore\\Support\\LocalNetworks::detect() as $d) { echo $d["address"]."|".$d["cidr"]."\\n"; break; }' % APP], capture_output=True, text=True).stdout.strip()
RX = {'log': []}
class H(BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        RX['log'].append(body); self.send_response(200); self.end_headers(); self.wfile.write(b'ok')
    def log_message(self, *a): pass
def worker(): return subprocess.run(['php', 'integration_worker.php'], cwd=APP + '/cron', capture_output=True, text=True).stdout
def wait_for(cond, secs=10):
    end = time.time() + secs
    while time.time() < end:
        if cond(): return True
        time.sleep(0.3)
    return False
def fail_login():
    s, html, h = req(session(), '/login.php')
sql("delete from webhooks where webhook_name like 'WN %'; delete from integration_jobs; delete from webhook_deliveries")
if detect:
    ip, cidr = detect.split('|')
    srv = HTTPServer((ip, 9456), H); threading.Thread(target=srv.serve_forever, daemon=True).start()
    # loopback receiver too: delivery to it must be refused even though the allow-list is non-empty
    lo_log = []
    class HL(H):
        def do_POST(self): lo_log.append(1); super().do_POST()
    lo = HTTPServer(('127.0.0.1', 9457), HL); threading.Thread(target=lo.serve_forever, daemon=True).start()
    save_nets(cidr)
    sql("insert into webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) values ('WN lan', 'http://%s:9456/hook', 'topsecret', 'auth.login_failed', 1), ('WN lo', 'http://127.0.0.1:9457/hook', 'topsecret', 'auth.login_failed', 1)" % ip)
    s, html, h = req(session(), '/login.php'); op = session(); s, html, h = req(op, '/login.php')
    d = {'email': 'nobody@nowhere.test', 'password': 'wrong-password-xyz', 'login': ''}
    if csrf(html): d['csrf_token'] = csrf(html)
    req(op, '/login.php', d)
    time.sleep(1); worker(); worker()
    check('delivery reaches a receiver on the allowed private LAN address (%s, %s)' % (ip, cidr), wait_for(lambda: len(RX['log']) >= 1 or (worker() and False)), 'received %d' % len(RX['log']))
    time.sleep(1)
    check('delivery to loopback is still refused', len(lo_log) == 0, len(lo_log))
    save_nets('')
    n = len(RX['log'])
    sql("delete from integration_jobs; delete from webhook_deliveries")
    op = session(); s, html, h = req(op, '/login.php'); d['csrf_token'] = csrf(html); req(op, '/login.php', d)
    time.sleep(1); worker(); worker(); time.sleep(1)
    check('with the allow-list cleared, delivery to the LAN address is refused again', len(RX['log']) == n, (n, len(RX['log'])))
    srv.shutdown(); lo.shutdown()
else:
    print('SKIP  no private interface on this host: live LAN delivery not exercised')

sql("delete from webhooks where webhook_name like 'WN %'; update settings set config_webhook_allowed_networks=''")
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
