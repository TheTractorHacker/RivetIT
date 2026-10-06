"""
End-to-end check of the webhook platform presets (RivetCore Destinations) through the real web stack: the guided Add / Edit flow, the
guides page, server-side validation, secret handling, the event picker and event patterns, Send test / Preview payload, the Replay of
a logged delivery, and real delivery to a local mock receiver for a representative set of platforms (generic JSON, n8n with header and
bearer auth, ntfy, Discord, Telegram, custom template, Matrix client API with PUT and {txn}) plus a legacy row that has no preset.

Needs a THROWAWAY copy of the app (never a real site): installed with scripts/setup_cli.php from its scripts/ directory against a scratch
database, migrated to the latest version, $config_https_only = FALSE, served with
  RIVETIT_WEBHOOK_ALLOW_PRIVATE=1 php -S 127.0.0.1:<port> -t <app dir>        (the receiver listens on loopback; the retry worker this
script starts as a subprocess inherits the same variable) and a Redis the app can reach (RIVETIT_REDIS_HOST / RIVETIT_REDIS_PORT).
Saving a Discord or Telegram webhook only RESOLVES discord.com / api.telegram.org (the URL policy checks the address); nothing is sent there.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/webhook_platforms.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, json, subprocess, os, time, hmac, hashlib, threading, http.cookiejar, urllib.request, urllib.parse, urllib.error
from http.server import BaseHTTPRequestHandler, HTTPServer

BASE, DB, EMAIL, PASSWORD, APP = sys.argv[1:6]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
os.environ.setdefault('RIVETIT_WEBHOOK_ALLOW_PRIVATE', '1')
assert 'scratch' in DB
RXPORT = 9456
RX_URL = 'http://127.0.0.1:%d' % RXPORT

jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(path, data=None, referer=None):
    headers = {'Referer': BASE + referer} if referer else {}
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    try:
        r = opener.open(urllib.request.Request(BASE + path, data=body, headers=headers)); text = r.read().decode('utf-8', 'replace'); status = r.status
    except urllib.error.HTTPError as e:
        status = e.code; text = e.read().decode('utf-8', 'replace')
    if '/modals/' in path and not path.endswith('webhook_action.php') and status == 200:
        try: text = json.loads(text)['content']
        except Exception: pass
    return status, text
def sql(q):
    return subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True).stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r'csrf_token=([0-9a-f]{16,})', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:400] + ']') if detail and not ok else ''))

# ---- a local receiver: records every request; the path decides the answer (/fail/... -> 500)
RX = {'log': []}
class H(BaseHTTPRequestHandler):
    def _handle(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0) or 0))
        RX['log'].append({'method': self.command, 'path': self.path, 'headers': {k.lower(): v for k, v in self.headers.items()}, 'body': body})
        code = 500 if '/fail' in self.path else 200
        self.send_response(code); self.send_header('Content-Type', 'text/plain'); self.end_headers(); self.wfile.write(b'received')
    do_POST = do_PUT = _handle
    def log_message(self, *a): pass
srv = HTTPServer(('127.0.0.1', RXPORT), H); threading.Thread(target=srv.serve_forever, daemon=True).start()
def hits(pred): return [r for r in RX['log'] if pred(r)]
def wait_for(cond, secs=10):
    end = time.time() + secs
    while time.time() < end:
        if cond(): return True
        time.sleep(0.25)
    return False
def verifies(r, secret):
    """X-Rivet-Signature-V2 = t=<ts>,v1=hmac(secret, ts + '.' + rawbody); the legacy header signs the body alone."""
    h = r['headers']; m = re.fullmatch(r't=(\d+),v1=([0-9a-f]{64})', h.get('x-rivet-signature-v2', ''))
    if not m or abs(time.time() - int(m.group(1))) > 300: return False
    good = hmac.new(secret.encode(), m.group(1).encode() + b'.' + r['body'], hashlib.sha256).hexdigest()
    legacy = 'sha256=' + hmac.new(secret.encode(), r['body'], hashlib.sha256).hexdigest()
    return hmac.compare_digest(good, m.group(2)) and h.get('x-itflow-signature') == legacy and h.get('x-rivet-timestamp') == m.group(1)

def php(code):
    return subprocess.run(['php', '-r', 'chdir(%s); require "config.php"; require "functions.php"; require "includes/event_bus.php"; %s' % (json.dumps(APP), code)], capture_output=True, text=True, cwd=APP)
def emit(event, data):
    r = php('rivetEmitEvent(%s, json_decode(%s, true));' % (json.dumps(event), json.dumps(json.dumps(data))))
    return r.stdout + r.stderr
def worker():
    r = subprocess.run(['php', 'integration_worker.php'], cwd=APP + '/cron', capture_output=True, text=True); return r.stdout + r.stderr
def drain():
    sql("update integration_jobs set available_at = '2000-01-01 00:00:00' where status in ('pending')")
    return worker()

TICKET = {'ticket_id': 7, 'ticket_number': 'TCK-0007', 'ticket_subject': 'Printer <b>"quote"</b> jam', 'ticket_priority': 'High', 'ticket_status': 'Open', 'client_id': 1, 'client_name': 'Acme'}

# ---- sign in
s, html = req('/login.php'); d = {'email': EMAIL, 'password': PASSWORD, 'login': ''}; t = csrf(html)
if t: d['csrf_token'] = t
check('sign in', req('/login.php', d)[0] in (302, 303))
REF = '/admin/settings_webhooks.php'
def token():
    return csrf(req(REF)[1])
TOK = token()
def flash(): return req(REF)[1]
def wh_count(): return int(sql("select count(*) from webhooks") or 0)

def add(dest, name, url, events=('auth.login_failed',), **kw):
    data = {'csrf_token': TOK, 'add_webhook': '1', 'webhook_destination': dest, 'webhook_name': name, 'webhook_url': url, 'webhook_events[]': list(events), 'webhook_enabled': '1'}
    data.update(kw)
    st, _ = req('/admin/post.php', data, REF)
    return flash()
def row(name, cols):
    return sql("select %s from webhooks where webhook_name=%s" % (cols, json.dumps(name).replace('"', "'"))).split('\t')
def wid(name): return row(name, 'webhook_id')[0]

sql("delete from webhooks; delete from webhook_deliveries; delete from integration_jobs;")
RX['log'].clear()

# ================================================================== pages
from_core = subprocess.run(['php', '-r', 'require %s; echo json_encode(array_map(fn($d)=>[$d->id,$d->name], RivetCore\\Webhooks\\Destinations::all()));' % json.dumps(APP + '/vendor/autoload.php')], capture_output=True, text=True).stdout
dests = json.loads(from_core)
check('the catalog has 24 platform presets', len(dests) == 24, len(dests))
s, chooser = req('/admin/modals/webhook/webhook_add.php')
cards = re.findall(r'data-wh-dest="([^"]+)"', chooser)
check('the add flow chooser has a card for every preset (24)', sorted(cards) == sorted(i for i, _ in dests), (len(cards), set(i for i, _ in dests) ^ set(cards)))
check('the chooser has the search box, category headings, descriptions, Generic JSON and Custom template', 'data-wh-chooser-search' in chooser and 'Automation platforms' in chooser and 'Team chat' in chooser and 'Push and notification services' in chooser and 'Custom template' in chooser and 'Generic JSON' in chooser and 'Start a workflow from a Webhook node' in chooser)
check('the chooser links to the guides page', 'settings_webhook_guides.php' in chooser)
s, guides = req('/admin/settings_webhook_guides.php')
check('the guides page lists every preset with an anchor and a guide', s == 200 and all(('id="%s"' % i) in guides for i, _ in dests), s)
check('the guides carry setup steps, a sample curl with a copy button, signature snippets and the n8n walk-through', 'Setup' in guides and 'curl -sS -X POST' in guides and 'data-wh-copy' in guides and 'Verify our signature' in guides and 'X-Rivet-Signature-V2' in guides and 'Receiving in n8n: walk-through' in guides and 'n8n Code' in guides and 'verifyRivetSignature' in guides)
s, page = req(REF)
check('the webhooks page links to the guides and the add form opens the large modal', 'settings_webhook_guides.php' in page and 'data-modal-size="xl"' in page)
s, js = req('/js/event_picker.js'); s2, js2 = req('/js/webhook_form.js')
check('the picker and form scripts are served and loaded by the shared footer', s == 200 and s2 == 200 and 'event_picker.js' in page and 'webhook_form.js' in page)
s, cat = req('/modals/event_catalog.php')
try: catj = json.loads(cat)
except Exception: catj = {}
ids = [e['id'] for e in catj.get('events', [])]
check('the event catalog endpoint lists the grouped, described events', s == 200 and len(ids) >= 110 and 'ticket.created' in ids and 'ticket.sla_breached' in ids and all(e['description'] for e in catj['events'][:20]) and 'tickets' in catj.get('groups', {}), (s, len(ids)))
forms = {}
for i, nm in dests:
    s, f = req('/admin/modals/webhook/webhook_add.php?dest=' + i); forms[i] = f
    ok = s == 200 and ('name="webhook_destination" value="%s"' % i) in f and 'data-event-picker' in f and 'Setup guide' in f and 'data-wh-test' in f and 'data-wh-preview' in f
    if not ok: check('add form for ' + i, False, (s, f[:200]))
check('the add form of every preset renders with its guide, events picker, Send test and Preview', all(('name="webhook_destination" value="%s"' % i) in forms[i] and 'data-event-picker' in forms[i] for i, _ in dests))
check('the n8n form: URL hint, allowed auth modes and signing secret generator', 'https://n8n.example.com/webhook/' in forms['n8n'] and 'value="header"' in forms['n8n'] and 'value="bearer"' in forms['n8n'] and 'data-wh-gen-secret' in forms['n8n'])
check('the ntfy form asks for the topic, priority and tags', all(x in forms['ntfy'] for x in ['name="extra[topic]"', 'name="extra[priority]"', 'name="extra[tags]"']))
check('the Telegram form asks for the bot token (password field) and chat id', 'name="extra[bot_token]"' in forms['telegram'] and re.search(r'type="password"[^>]*name="extra\[bot_token\]"', forms['telegram']) and 'name="extra[chat_id]"' in forms['telegram'])
check('the Matrix client form offers PUT only (hidden), a room id and keeps {txn} in the URL hint', 'name="webhook_method" value="PUT"' in forms['matrix-client'] and 'name="extra[room_id]"' in forms['matrix-client'] and '{txn}' in forms['matrix-client'])
check('the custom template form has the editor, encoding, cheat-sheet and a method choice', all(x in forms['custom-template'] for x in ['name="webhook_template"', 'name="webhook_template_encoding"', 'data-wh-insert="{{data.ticket_subject}}"', '|truncate:80', 'name="webhook_method"']) and '<option value="PUT"' in forms['custom-template'])
check('Slack and Teams keep their routing filters; platforms without chat routing do not show them', 'name="webhook_min_priority"' in forms['slack'] and 'name="webhook_min_priority"' in forms['teams'] and 'name="webhook_min_priority"' in forms['ntfy'] and 'name="webhook_min_priority"' not in forms['n8n'])
check('the guide panel of a preset is rendered from the catalog (steps, notes, docs link, verify tabs)', 'Copy the Production URL' in forms['n8n'] and 'Things to know' in forms['n8n'] and 'https://docs.n8n.io/' in forms['n8n'] and 'data-bs-toggle="tab"' in forms['n8n'])
check('a platform that cannot verify signatures says so instead of showing snippets', 'cannot check a signature' in forms['discord'])

# ================================================================== events picker markup
n8n = forms['n8n']
check('the picker has search, chip area, All events, group container, a fetch URL and a no-JS fallback list', all(x in n8n for x in ['data-ep-search', 'data-ep-chips', 'data-ep-all', 'data-ep-groups', 'data-catalog-url="/modals/event_catalog.php?v=', '<noscript>', 'optgroup']))
s, rules = req('/admin/event_rules.php')
check('the event rules trigger uses the same picker in single-select mode', 'data-event-picker' in rules and 'data-mode="single"' in rules and 'name="trigger_event"' in rules, rules[:100])
css = req('/css/itflow_custom.css')[1]
check('the picker styles are appended to itflow_custom.css and use theme tokens', '.event-picker' in css and '.ep-chip' in css and 'var(--tblr-border-color' in css)

# ================================================================== create representative webhooks
SECRET = 'gen-secret-0123456789abcdef0123456789abcdef'
f = add('generic-json', 'T generic', RX_URL + '/generic', webhook_secret=SECRET, webhook_auth_mode='hmac')
r = row('T generic', 'webhook_destination, webhook_format, webhook_method, webhook_auth_mode, webhook_type, left(webhook_secret,5), webhook_url')
check('generic JSON saved: preset, format json, POST, plaintext URL, encrypted signing secret', r == ['generic-json', 'json', 'POST', 'hmac', 'generic', 'ENC2:', RX_URL + '/generic'], r)
add('n8n', 'T n8n header', RX_URL + '/webhook/h1', webhook_secret=SECRET, webhook_auth_mode='header', auth_header_name='X-N8N-Key', auth_header_value='topsecret-header-value')
r = row('T n8n header', 'webhook_destination, webhook_format, webhook_auth_mode, left(webhook_auth_enc,5), left(webhook_url,5), left(webhook_secret,5)')
check('n8n with header auth saved: auth blob and URL encrypted', r == ['n8n', 'json', 'header', 'ENC2:', 'ENC2:', 'ENC2:'], r)
add('n8n', 'T n8n bearer', RX_URL + '/webhook/b1', webhook_secret=SECRET, webhook_auth_mode='bearer', auth_token='bearer-token-abc123')
add('ntfy', 'T ntfy', RX_URL + '/{topic}', **{'extra[topic]': 'rivet-topic-7f3k', 'extra[priority]': '4', 'extra[tags]': 'alpha,beta'})
r = row('T ntfy', 'webhook_destination, webhook_format, left(webhook_url,5), webhook_extra')
check('ntfy with topic saved: URL (with the topic filled in) encrypted, priority and tags kept in the extra column', r[:3] == ['ntfy', 'ntfy', 'ENC2:'] and json.loads(r[3]) == {'priority': '4', 'tags': 'alpha,beta'}, r)
add('discord', 'T discord', 'https://discord.com/api/webhooks/123456789012/AbCdEf_ghIJ-klmno', **{'extra[username]': 'RivetBot'})
r = row('T discord', 'webhook_destination, webhook_format, left(webhook_url,5), webhook_extra, webhook_type')
check('Discord saved with its URL pattern accepted, URL encrypted, display name in extra', r[:3] == ['discord', 'discord', 'ENC2:'] and json.loads(r[3]) == {'username': 'RivetBot'} and r[4] == 'generic', r)
add('telegram', 'T telegram', '', **{'extra[bot_token]': '123456:ABC-DEFsecretbottoken', 'extra[chat_id]': '-1001234567890'})
r = row('T telegram', 'webhook_destination, webhook_format, left(webhook_url,5), webhook_extra')
check('Telegram saved: URL built from the preset and the bot token, chat id kept in extra, token only inside the encrypted URL', r[:3] == ['telegram', 'telegram', 'ENC2:'] and json.loads(r[3]) == {'chat_id': '-1001234567890'}, r)
TPL = '{"subject":"{{data.ticket_subject}}","n":{{data.ticket_id|json}},"who":"{{data.client_name|upper}}","ev":"{{event}}"}'
add('custom-template', 'T template', RX_URL + '/tpl', webhook_template=TPL, webhook_template_encoding='json', webhook_auth_mode='none')
r = row('T template', 'webhook_destination, webhook_format, webhook_template, webhook_extra')
check('custom template saved with its template and encoding', r[:3] == ['custom-template', 'template', TPL] and json.loads(r[3]) == {'template_encoding': 'json'}, r)
add('matrix-client', 'T matrix', RX_URL + '/_matrix/client/v3/rooms/{room_id}/send/m.room.message/{txn}', webhook_method='PUT', webhook_auth_mode='bearer', auth_token='matrix-access-token', **{'extra[room_id]': '!abc123:example.org'})
r = row('T matrix', 'webhook_destination, webhook_format, webhook_method, webhook_auth_mode')
check('Matrix client saved: PUT, bearer, room id filled into the URL, {txn} kept', r == ['matrix-client', 'matrix', 'PUT', 'bearer'], r)
check('the saved Matrix URL still ends in {txn} and carries the room id', php('echo decryptSetting((string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT webhook_url FROM webhooks WHERE webhook_name=\'T matrix\'"))[0]);').stdout.endswith('/rooms/%21abc123:example.org/send/m.room.message/{txn}'))
dump = sql("select concat_ws('|', webhook_name, webhook_url, webhook_secret, webhook_auth_enc, webhook_extra, webhook_template) from webhooks")
check('no secret (header value, bearer tokens, bot token, topic, signing secret) is readable in the database', not any(x in dump for x in ['topsecret-header-value', 'bearer-token-abc123', 'matrix-access-token', 'ABC-DEFsecretbottoken', 'rivet-topic-7f3k', SECRET, 'AbCdEf_ghIJ']), dump[:300])
page = flash()
check('the list shows the platform badge and the host only; never a secret URL path, token or topic', 'T n8n header' in page and 'n8n</span>' in page and 'Discord</span>' in page and not any(x in page for x in ['topsecret-header-value', 'bearer-token-abc123', 'rivet-topic-7f3k', 'AbCdEf_ghIJ', 'ABC-DEFsecretbottoken', '/webhook/h1']))
check('the plain-URL presets stay editable in the list (generic URL shown)', RX_URL + '/generic' in page)

# ================================================================== invalid inputs
n0 = wh_count()
def refuse(label, dest, expect, **kw):
    kw.setdefault('name', 'Bad one'); name = kw.pop('name'); url = kw.pop('url', RX_URL + '/x')
    msg = add(dest, name, url, **kw)
    m = re.search(r'(Webhook not saved:.{0,400}?)</', msg, re.S)
    text = re.sub(r'<[^>]+>', ' ', m.group(1)) if m else 'NO ALERT FOUND'
    check('refused: ' + label, wh_count() == n0 and expect.lower() in text.lower(), text)
refuse('an invalid body template (code is not a placeholder)', 'custom-template', 'template', webhook_template='{"x":"{{ system(\'id\') }}"}', webhook_template_encoding='json')
refuse('a template that is not valid JSON', 'custom-template', 'valid json', webhook_template='not json {{event}}', webhook_template_encoding='json')
refuse('a reserved header name (Content-Type)', 'n8n', 'reserved', webhook_auth_mode='header', auth_header_name='Content-Type', auth_header_value='x')
refuse('our own signature header name', 'n8n', 'reserved', webhook_auth_mode='header', auth_header_name='X-Rivet-Signature-V2', auth_header_value='x')
refuse('a line break in a header value', 'n8n', 'line breaks', webhook_auth_mode='header', auth_header_name='X-Key', auth_header_value='abc\r\nX-Evil: 1')
refuse('a bearer token with a space', 'n8n', 'bearer token', webhook_auth_mode='bearer', auth_token='two words')
refuse('an address that is not a Discord webhook', 'discord', 'does not look like a valid discord', url='https://evil.example.com/api/webhooks/1/x')
refuse('an auth mode the preset does not allow (Discord + bearer)', 'discord', 'not available for discord', url='https://discord.com/api/webhooks/123456789012/AbCdEf', webhook_auth_mode='bearer', auth_token='x')
refuse('PUT on a POST-only preset (n8n)', 'n8n', 'accepts post only', webhook_method='PUT')
refuse('a URL that is not http(s)', 'n8n', 'does not look like a valid n8n', url='javascript:alert(1)')
refuse('an unknown platform', 'no-such-platform', 'choose a platform')
refuse('no event chosen', 'n8n', 'at least one event', events=())
refuse('an event that does not exist', 'n8n', 'not a known event', events=('nope.nothing',))
refuse('a pattern that matches nothing', 'n8n', 'matches no known event', events=('bogus.*',))
refuse('an ntfy number field that is not a number', 'ntfy', 'must be a number', url=RX_URL + '/{topic}', **{'extra[topic]': 'abc', 'extra[priority]': 'high'})
refuse('a missing required field (Telegram chat id)', 'telegram', 'chat id is required', url='', **{'extra[bot_token]': '123456:ABC-DEFxyz'})
refuse('an unfilled {placeholder} left in the URL (ntfy topic missing)', 'ntfy', 'topic', url=RX_URL + '/{topic}')
req('/admin/post.php', {'csrf_token': 'bad', 'add_webhook': '1', 'webhook_destination': 'generic-json', 'webhook_name': 'NoCsrf', 'webhook_url': RX_URL + '/x', 'webhook_events[]': ['ticket.created']}, REF)
check('refused: a wrong CSRF token', wh_count() == n0)
st, xs = req('/admin/post.php', {'csrf_token': TOK, 'add_webhook': '1', 'webhook_destination': 'generic-json', 'webhook_name': '<script>alert(1)</script>', 'webhook_url': RX_URL + '/x', 'webhook_events[]': ['ticket.created']}, REF)
check('a hostile name is stored but escaped everywhere it is shown', '<script>alert(1)</script>' not in flash() and '<script>alert(1)</script>' not in req('/admin/modals/webhook/webhook_edit.php?id=' + sql("select max(webhook_id) from webhooks"))[1])
sql("delete from webhooks where webhook_name like '<script>%'")

# ================================================================== edit keeps secrets, edit modal never echoes them
nid = wid('T n8n header'); before = row('T n8n header', 'webhook_url, webhook_secret, webhook_auth_enc')
s, edit = req('/admin/modals/webhook/webhook_edit.php?id=' + nid)
check('the edit form shows the saved-secret hints and never the URL, token, header value or signing secret', 'Saved. Leave blank to keep it.' in edit and not any(x in edit for x in ['topsecret-header-value', SECRET, '/webhook/h1', 'ENC2:']) and 'X-N8N-Key' in edit, edit[:200])
req('/admin/post.php', {'csrf_token': TOK, 'edit_webhook': '1', 'webhook_id': nid, 'webhook_destination': 'n8n', 'webhook_name': 'T n8n header renamed', 'webhook_url': '', 'webhook_secret': '', 'webhook_auth_mode': 'header', 'auth_header_name': 'X-N8N-Key', 'auth_header_value': '', 'webhook_events[]': ['auth.*'], 'webhook_enabled': '1'}, REF)
after = row('T n8n header renamed', 'webhook_url, webhook_secret, webhook_auth_enc')
check('editing with blank secrets keeps the saved URL, signing secret and auth header value', after == before and row('T n8n header renamed', 'webhook_events') == ['auth.*'], (before, after))
req('/admin/post.php', {'csrf_token': TOK, 'edit_webhook': '1', 'webhook_id': nid, 'webhook_destination': 'n8n', 'webhook_name': 'T n8n header', 'webhook_url': '', 'webhook_secret': '', 'webhook_auth_mode': 'header', 'auth_header_name': 'X-N8N-Key', 'auth_header_value': 'rotated-value-9', 'webhook_events[]': ['auth.*'], 'webhook_enabled': '1'}, REF)
r2 = row('T n8n header', 'webhook_url, webhook_secret, webhook_auth_enc')
check('a new header value replaces only the auth blob', r2[0] == before[0] and r2[1] == before[1] and r2[2] != before[2] and 'rotated-value-9' not in sql("select webhook_auth_enc from webhooks where webhook_id=" + nid))
req('/admin/post.php', {'csrf_token': TOK, 'edit_webhook': '1', 'webhook_id': nid, 'webhook_destination': 'n8n', 'webhook_name': 'T n8n header', 'webhook_url': '', 'webhook_secret': '', 'webhook_auth_mode': 'bearer', 'auth_token': '', 'webhook_events[]': ['auth.*'], 'webhook_enabled': '1'}, REF)
check('switching to bearer without entering a token is refused (the header value is not carried over)', row('T n8n header', 'webhook_auth_mode') == ['header'])
req('/admin/post.php', {'csrf_token': TOK, 'edit_webhook': '1', 'webhook_id': nid, 'webhook_destination': 'n8n', 'webhook_name': 'T n8n header', 'webhook_url': '', 'webhook_secret': '', 'webhook_auth_mode': 'header', 'auth_header_name': 'X-N8N-Key', 'auth_header_value': 'topsecret-header-value', 'webhook_events[]': ['auth.*'], 'webhook_enabled': '1'}, REF)

# ================================================================== delivery to the local receiver
# point the platforms whose hosts are fixed (Discord, Telegram) at the receiver by editing the stored URL directly (plain text is accepted)
sql("update webhooks set webhook_url='%s/discord/hook' where webhook_name='T discord'" % RX_URL)
sql("update webhooks set webhook_url='%s/bot123456:ABC-DEF/sendMessage' where webhook_name='T telegram'" % RX_URL)
sql("update webhooks set webhook_events='auth.login_failed' where webhook_name in ('T n8n bearer','T ntfy','T discord','T telegram','T template','T matrix','T generic')")
# a legacy row with no preset (as created before this update)
sql("insert into webhooks (webhook_name, webhook_url, webhook_secret, webhook_events) values ('T legacy', '%s/legacy', '%s', 'auth.login_failed')" % (RX_URL, php('echo encryptSetting("legacy-secret");').stdout))
# patterns: ticket.* gets tickets only; * gets everything
add('generic-json', 'T pattern ticket', RX_URL + '/pat-ticket', events=('ticket.*',), webhook_auth_mode='none')
add('generic-json', 'T pattern all', RX_URL + '/pat-all', events=('*',), webhook_auth_mode='none')
check('patterns are stored as chosen (ticket.*, *)', row('T pattern ticket', 'webhook_events') == ['ticket.*'] and row('T pattern all', 'webhook_events') == ['*'])
RX['log'].clear(); sql("delete from integration_jobs")
out = emit('auth.login_failed', {'summary': 'Failed login attempt using nobody@example.com', 'action': 'failed'})
n_jobs = sql("select count(*) from integration_jobs where job_type='webhook.deliver'")
check('a failed login queued one delivery per subscribed webhook (8 explicit/pattern matches, not the ticket.* pattern)', n_jobs == '10', (n_jobs, out))
worker()
check('every queued delivery reached the receiver', wait_for(lambda: len(RX['log']) >= 10), len(RX['log']))
by = lambda p: hits(lambda r: r['path'].startswith(p))
check('ticket.* did not receive a security event, * did', not by('/pat-ticket') and len(by('/pat-all')) == 1)
g = by('/generic')
check('generic JSON: signed envelope body, no extra headers', len(g) == 1 and json.loads(g[0]['body'])['event'] == 'auth.login_failed' and verifies(g[0], SECRET) and 'authorization' not in g[0]['headers'] and g[0]['headers']['content-type'].startswith('application/json'), g[:1])
nb = by('/webhook/b1')
check('n8n bearer: Authorization header, JSON envelope, signature headers verify', len(nb) == 1 and nb[0]['headers'].get('authorization') == 'Bearer bearer-token-abc123' and json.loads(nb[0]['body'])['data']['action'] == 'failed' and verifies(nb[0], SECRET), nb[:1])
# n8n header webhook is subscribed to auth.* (set above)
sql("update webhooks set webhook_events='auth.login_failed' where webhook_name='T n8n header'")
nt = by('/rivet-topic-7f3k')
check('ntfy: plain-text body with Title, Priority and Tags headers; topic was in the URL path', len(nt) == 1 and nt[0]['headers']['content-type'].startswith('text/plain') and nt[0]['headers'].get('priority') == '4' and 'alpha' in nt[0]['headers'].get('tags', '') and nt[0]['headers'].get('title') and not nt[0]['body'].startswith(b'{') and verifies(nt[0], ''), nt[:1])
dc = by('/discord/hook')
dj = json.loads(dc[0]['body']) if dc else {}
hh = by('/webhook/h1')
check('n8n with header auth sends the custom header and a verifying signature', len(hh) == 1 and hh[0]['headers'].get('x-n8n-key') == 'topsecret-header-value' and verifies(hh[0], SECRET), hh[:1])
check('Discord: JSON in Discord shape (embeds/content, display name), no raw mention', len(dc) == 1 and dc[0]['headers']['content-type'].startswith('application/json') and ('embeds' in dj or 'content' in dj) and dj.get('username') == 'RivetBot' and '@everyone' not in dc[0]['body'].decode(), dc[:1])
tg = by('/bot123456:ABC-DEF/sendMessage')
tj = json.loads(tg[0]['body']) if tg else {}
check('Telegram: sendMessage JSON with chat id and HTML parse mode', len(tg) == 1 and str(tj.get('chat_id')) == '-1001234567890' and tj.get('parse_mode') == 'HTML' and tj.get('text'), tg[:1])
lg = by('/legacy')
check('a legacy row (no preset) still delivers the old way: signed envelope, no extra headers', len(lg) == 1 and json.loads(lg[0]['body'])['event'] == 'auth.login_failed' and verifies(lg[0], 'legacy-secret') and 'authorization' not in lg[0]['headers'], lg[:1])
mx = by('/_matrix/')
check('Matrix client API: PUT with the room id in the path, a 32-hex {txn}, bearer token', len(mx) == 1 and mx[0]['method'] == 'PUT' and re.fullmatch(r'/_matrix/client/v3/rooms/%21abc123:example\.org/send/m\.room\.message/[0-9a-f]{32}', mx[0]['path']) and mx[0]['headers'].get('authorization') == 'Bearer matrix-access-token', mx[:1])
txn1 = mx[0]['path'].rsplit('/', 1)[1] if mx else ''
# a ticket event: custom template rendered with escaping; ticket.* now matches
sql("update webhooks set webhook_events='ticket.created' where webhook_name='T template'")
RX['log'].clear(); sql("delete from integration_jobs")
emit('ticket.created', TICKET); worker()
wait_for(lambda: len(hits(lambda r: r['path'] == '/tpl')) >= 1)
tp = hits(lambda r: r['path'] == '/tpl')
tpj = json.loads(tp[0]['body']) if tp else {}
check('custom template rendered: subject escaped inside the JSON string, id as a number, filter applied', len(tp) == 1 and tpj == {'subject': TICKET['ticket_subject'], 'n': 7, 'who': 'ACME', 'ev': 'ticket.created'} and verifies(tp[0], ''), tp[:1])
check('ticket.* received the ticket event and * too; the auth-only webhooks did not', len(by('/pat-ticket')) == 1 and len(by('/pat-all')) == 1 and not by('/generic') and not by('/webhook/b1'))
pt = by('/pat-ticket')
check('the pattern webhook delivered the normal ticket envelope', pt and json.loads(pt[0]['body'])['data']['ticket_number'] == 'TCK-0007')
# Matrix retries keep the same {txn}: first attempt fails, the retry reuses the id
sql("update webhooks set webhook_events='ticket.resolved' where webhook_name='T matrix'")
RX['log'].clear(); sql("delete from integration_jobs")
# the first attempt fails: the receiver answers 500 to any path containing /fail
sql("update webhooks set webhook_url = '%s/fail/_matrix/client/v3/rooms/%%21r:example.org/send/m.room.message/{txn}' where webhook_name='T matrix'" % RX_URL)
emit('ticket.resolved', TICKET)
worker(); wait_for(lambda: hits(lambda r: r['method'] == 'PUT'))
sql("update integration_jobs set available_at = '2000-01-01 00:00:00' where status='pending'"); worker()
puts = hits(lambda r: r['method'] == 'PUT')
check('Matrix retry: two attempts (the first got a 500) reuse the same {txn} with a fresh signature', len(puts) == 2 and puts[0]['path'] == puts[1]['path'] and re.search(r'/[0-9a-f]{32}$', puts[0]['path']), [p['path'] for p in puts])

# the catch-all hook would also receive the audit events our own actions record; drop it before counting requests
sql("delete from webhooks where webhook_name='T pattern all'; delete from integration_jobs")
drain_ = worker()

# ================================================================== Send test and Preview
def action(data, wid_='', **kw):
    d = {'csrf_token': TOK, 'webhook_destination': 'n8n', 'webhook_name': 'Draft', 'webhook_url': RX_URL + '/webhook/draft', 'webhook_events[]': ['ticket.created'], 'webhook_auth_mode': 'bearer', 'auth_token': 'draft-token-XYZ789', 'webhook_secret': 'draft-secret'}
    if wid_: d['webhook_id'] = wid_
    d.update(kw); d['wh_action'] = data
    st, body = req('/admin/modals/webhook/webhook_action.php', d)
    try: return st, json.loads(body)
    except Exception: return st, {'raw': body[:300]}
RX['log'].clear(); n_del = sql("select count(*) from webhook_deliveries")
st, res = action('test')
rr = res.get('result', {})
check('Send test on an unsaved form: goes through the real path, returns HTTP status, duration and the response body', st == 200 and rr.get('ok') and rr.get('http_status') == 200 and rr.get('duration_ms') >= 0 and rr.get('response') == 'received', res)
dt = hits(lambda r: r['path'] == '/webhook/draft')
check('the test reached the receiver with the auth header and a verifying signature (the secret typed in the form)', len(dt) == 1 and dt[0]['headers'].get('authorization') == 'Bearer draft-token-XYZ789' and verifies(dt[0], 'draft-secret'), dt[:1])
check('an unsaved test leaves no delivery-log noise', sql("select count(*) from webhook_deliveries") == n_del)
bid = wid('T n8n bearer')
st, res = action('test', bid, webhook_name='T n8n bearer', webhook_url='', auth_token='', webhook_secret='')
rr = res.get('result', {})
check('Send test on a saved webhook with blank secret fields uses the saved ones (URL, token, signing secret)', rr.get('ok') and hits(lambda r: r['path'] == '/webhook/b1' and r['headers'].get('authorization') == 'Bearer bearer-token-abc123' and verifies(r, SECRET)), res)
check('a saved webhook logs exactly one test.<event> row', sql("select count(*) from webhook_deliveries where webhook_id=%s and event_type='test.ticket.created'" % bid) == '1' and sql("select count(*) from webhook_deliveries where webhook_id=%s and event_type like 'test.%%'" % bid) == '1')
st, res = action('test', webhook_url=RX_URL + '/fail/x')
rr = res.get('result', {})
check('a receiver that answers 500 is reported as a failed test with its status', st == 200 and rr.get('ok') is False and rr.get('http_status') == 500 and 'HTTP 500' in (rr.get('error') or ''), res)
n_before = len(RX['log'])
st, res = action('test', webhook_url='javascript:alert(1)')
check('Send test applies the same URL validation as saving (non-http URL refused, nothing sent)', res.get('ok') is False and res.get('errors') and len(RX['log']) == n_before, res)
st, res = action('test', webhook_auth_mode='header', auth_header_name='Host', auth_header_value='x')
check('Send test refuses a forbidden header name', res.get('ok') is False and any('reserved' in e for e in res.get('errors', [])), res)
st, res = req('/admin/modals/webhook/webhook_action.php', {'csrf_token': 'bad', 'wh_action': 'test'})
check('the test endpoint rejects a bad CSRF token', st == 403)
st, res = action('preview', auth_token='draft-token-XYZ789')
pv = res.get('preview', {})
alltxt = json.dumps(res)
check('Preview payload shows the exact body and headers with the bearer token masked and the secret never present', st == 200 and res.get('ok') and 'Authorization: Bearer ********' in pv.get('headers', []) and 'draft-token-XYZ789' not in alltxt and 'draft-secret' not in alltxt and any(h.startswith('X-Rivet-Signature-V2: t=') for h in pv['headers']) and '"event": "ticket.created"' in pv['body'], res)
check('the preview shows the host only, not the URL path', 'webhook/draft' not in pv.get('url', '') and '127.0.0.1' in pv.get('url', ''))
st, res = action('preview', webhook_destination='ntfy', webhook_url=RX_URL + '/{topic}', webhook_auth_mode='none', **{'extra[topic]': 'tp', 'extra[priority]': '5', 'sample_event': 'auth.login_failed'})
res2 = res.get('preview', {})
check('Preview for ntfy: text body and the Title / Priority headers for the chosen sample event', res.get('ok') and any(h.startswith('Priority: 5') for h in res2.get('headers', [])) and any(h.startswith('Title:') for h in res2.get('headers', [])) and res2.get('format') == 'ntfy' and res.get('event') == 'auth.login_failed', res)
st, res = action('preview', webhook_destination='custom-template', webhook_url=RX_URL + '/t', webhook_auth_mode='none', webhook_template=TPL, webhook_template_encoding='json')
check('Preview for a custom template renders the template with the sample ticket', res.get('ok') and json.loads(res['preview']['body'])['who'] == 'ACME CORP', res)
st, res = action('validate_template', webhook_template='{{ system("id") }}', webhook_template_encoding='json')
check('live template check reports an invalid template', res.get('ok') is False and res.get('errors'), res)
st, res = action('validate_template', webhook_template=TPL, webhook_template_encoding='json')
check('live template check accepts a valid template and shows a sample', res.get('ok') is True and 'ACME' in res.get('sample', ''), res)
# list-level test button
pre = len(RX['log'])
req('/admin/post.php', {'csrf_token': TOK, 'test_webhook': '1', 'webhook_id': bid}, REF)
check('the per-webhook test button in the list sends a test and flashes the HTTP result', len(RX['log']) == pre + 1 and re.search(r'HTTP 200 in \d+ ms', flash()), flash()[:300])

# ================================================================== delivery log: view payload and replay
dl = sql("select delivery_id from webhook_deliveries where webhook_id=%s and event_type='ticket.created' limit 1" % wid('T pattern ticket'))
s, view = req('/admin/modals/webhook/webhook_delivery.php?id=' + dl)
check('View payload shows the stored body and a Replay button for a standard JSON delivery', s == 200 and 'TCK-0007' in view and 'name="replay_webhook_delivery"' in view, view[:200])
sql("update webhook_deliveries set request_payload_json = '{\"event\":\"ticket.created\",\"timestamp\":\"2026-01-01T00:00:00Z\",\"data\":{\"ticket_id\":1,\"api_token\":\"SHOULD-BE-MASKED\",\"password\":\"hunter2\"}}' where delivery_id=" + dl)
s, view = req('/admin/modals/webhook/webhook_delivery.php?id=' + dl)
check('the viewed payload masks secret-looking keys', 'SHOULD-BE-MASKED' not in view and 'hunter2' not in view and '[redacted]' in view)
pre = len(hits(lambda r: r['path'] == '/pat-ticket'))
req('/admin/post.php', {'csrf_token': TOK, 'replay_webhook_delivery': '1', 'delivery_id': dl}, REF)
rp = hits(lambda r: r['path'] == '/pat-ticket')
check('Replay sends the logged event again through the dispatcher with a fresh signature', len(rp) == pre + 1 and json.loads(rp[-1]['body'])['data']['ticket_id'] == 1 and json.loads(rp[-1]['body'])['timestamp'] != '2026-01-01T00:00:00Z', flash()[:300])
nd = sql("select delivery_id from webhook_deliveries where webhook_id=%s order by delivery_id limit 1" % wid('T ntfy'))
s, view = req('/admin/modals/webhook/webhook_delivery.php?id=' + nd)
check('a delivery whose body cannot be rebuilt (ntfy text body) offers no Replay and says why', s == 200 and 'name="replay_webhook_delivery"' not in view and 'cannot be replayed' in view, view[:200])
before_n = len(RX['log'])
req('/admin/post.php', {'csrf_token': TOK, 'replay_webhook_delivery': '1', 'delivery_id': nd}, REF)
fl = flash()
check('a forced replay of such a delivery sends nothing', len(RX['log']) == before_n and 'cannot be replayed' in fl, fl[:100])

# ================================================================== events picker: stored patterns on the edit form
add('generic-json', 'T pattern all 2', RX_URL + '/pat-all2', events=('*',), webhook_auth_mode='none')
tid = wid('T pattern ticket')
s, edit = req('/admin/modals/webhook/webhook_edit.php?id=' + tid)
check('the edit form of a pattern webhook carries the pattern as the picker value (ticket.*)', 'name="webhook_events[]" value="ticket.*"' in edit)
s, edit = req('/admin/modals/webhook/webhook_edit.php?id=' + wid('T pattern all 2'))
check('the edit form of an all-events webhook carries *', 'name="webhook_events[]" value="*"' in edit)
check('the settings list shows patterns as chips (All events)', 'All events' in flash() and 'ticket.*' in flash())
s, ed = req('/admin/modals/webhook/webhook_edit.php?id=' + wid('T discord') + '&dest=ntfy')
check('the edit form can switch the platform (Change platform) and warns that secrets must be re-entered', s == 200 and 'name="webhook_destination" value="ntfy"' in ed and 're-enter its URL' in ed)

# ================================================================== event rules still work with the picker
st, _ = req('/admin/post.php', {'csrf_token': csrf(req('/admin/event_rules.php')[1]), 'rule_name': 'T rule via picker', 'trigger_event': 'ticket.sla_breached', 'action_type': 'notify_user', 'cfg_message': 'late', 'is_enabled': '1', 'save_event_rule': '1'}, '/admin/event_rules.php')
check('the event rules form (single picker value) still saves a rule', sql("select trigger_event from automation_rules where name='T rule via picker'") == 'ticket.sla_breached')
rid = sql("select rule_id from automation_rules where name='T rule via picker'")
s, rules = req('/admin/event_rules.php?edit=' + rid)
check('editing a rule preselects its trigger event in the picker', 'name="trigger_event" value="ticket.sla_breached"' in rules)
sql("delete from automation_rules where name='T rule via picker'")

# ================================================================== migration: upgrade path and backfill
sql("delete from webhooks")
LATEST = re.search(r'LATEST_DATABASE_VERSION", "([0-9.]+)"', open(APP + '/includes/database_version.php').read()).group(1)
sql("insert into webhooks (webhook_name, webhook_url, webhook_events, webhook_type) values ('M generic','http://x.example/h','ticket.created','generic'),('M slack','ENC2:aaaa','ticket.created','slack'),('M teams','ENC2:bbbb','ticket.created','teams')")
sql("update webhooks set webhook_destination='', webhook_format=''")
sql("update settings set config_current_database_version='2.6.141'")
env = dict(os.environ, RIVETIT_DB_PASSWORD=os.environ['TEST_DB_PASS'], ITFLOW_DB_PASSWORD=os.environ['TEST_DB_PASS'])
out = subprocess.run(['php', 'scripts/update_cli.php', '--update_db'], cwd=APP, capture_output=True, text=True, env=env)
check('the update runs the 2.6.142 step and ends at the latest version', sql("select config_current_database_version from settings") == LATEST and LATEST >= '2.6.142', out.stdout[-200:] + out.stderr[-200:])
check('the migration backfills legacy rows: generic -> generic-json/json, slack -> slack/slack, teams -> teams/teams', [row(n, 'webhook_destination, webhook_format') for n in ('M generic', 'M slack', 'M teams')] == [['generic-json', 'json'], ['slack', 'slack'], ['teams', 'teams']])
cols = sql("select column_name, column_type, column_default, is_nullable from information_schema.columns where table_schema=database() and table_name='webhooks' and column_name in ('webhook_destination','webhook_format','webhook_method','webhook_template','webhook_auth_mode','webhook_auth_enc','webhook_extra','webhook_events') order by column_name")
check('the new columns exist with the specified types and defaults', all(x in cols for x in ['webhook_destination\tvarchar(40)', 'webhook_format\tvarchar(24)', 'webhook_method\tvarchar(4)\t\'POST\'', 'webhook_auth_mode\tvarchar(12)\t\'none\'', 'webhook_events\tvarchar(4000)']) and cols.count('YES') >= 3, cols)
sql("delete from webhooks; delete from webhook_deliveries; delete from integration_jobs")

srv.shutdown()
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
