"""
End-to-end check of the Webhook guides hub (admin/settings_webhook_guides.php) on a THROWAWAY install (scratch database, see
webhook_platforms.py for the recipe): page renders without PHP warnings, all RivetCore destinations are in the sidebar, the index and have
a full guide (steps, curl, signature tabs, example payload from PayloadFormatter, troubleshooting), anchors, 'Add this webhook' links that
open the add flow preselected, search / filter / tab markup, non-admin refusal, escaping of hostile destination text, and the assets.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/webhook_guides.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, json, subprocess, os, html as htmllib, http.cookiejar, urllib.request, urllib.parse, urllib.error

BASE, DB, EMAIL, PASSWORD, APP = sys.argv[1:6]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
assert 'scratch' in DB

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def session():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
def req(op, path, data=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        r = op.open(urllib.request.Request(BASE + path, data=body)); return r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')
def sql(q): return subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True).stdout.strip()
def login(op, email, pw):
    s, h = req(op, '/login.php'); d = {'email': email, 'password': pw, 'login': ''}
    m = re.search(r'name="csrf_token" value="([^"]+)"', h)
    if m: d['csrf_token'] = m.group(1)
    return req(op, '/login.php', d)[0] in (302, 303)
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))

dests = json.loads(subprocess.run(['php', '-r', 'require "%s/vendor/autoload.php"; echo \\RivetCore\\Webhooks\\Destinations::toJson();' % APP], capture_output=True, text=True).stdout)['destinations']
check('catalog has 24 destinations', len(dests) == 24, len(dests))

admin = session()
check('admin sign in', login(admin, EMAIL, PASSWORD))
s, page = req(admin, '/admin/settings_webhook_guides.php')
check('page is 200', s == 200, s)
check('no PHP warnings / notices / fatal errors', not re.search(r'(Warning|Notice|Deprecated|Fatal error|Parse error)\s*:', page) and 'Stack trace' not in page, re.findall(r'.{40}(?:Warning|Notice|Deprecated|Fatal error):.{80}', page)[:2])
check('existing content kept: signing section, n8n walk-through, Webhooks/Guides tabs, Add Webhook button',
      'id="signing"' in page and 'id="n8n-walkthrough"' in page and 'settings_webhook_guides.php' in page and 'X-Rivet-Signature-V2' in page and 'Add Webhook' in page)
check('hero: pitch, quick links, how-it-works strip', 'Connect RivetIT events to n8n, Home Assistant, ntfy, Discord and more' in page and all(x in page for x in ('Event</strong>', 'Format</strong>', 'Sign</strong>', 'Deliver</strong>', 'Retry</strong>')) and 'href="#signing"' in page and 'href="#networks"' in page)
check('general signing section has tabbed snippets and the allowed-networks explainer linking to the card', 'id="wg-signing-tab-node"' in page and 'id="networks"' in page and 'settings_webhooks.php#internal-networks' in page)
check('landmarks and a11y hooks: skip link, main, labelled nav, tablist, live region script', all(x in page for x in ('class="wg-skip"', '<main class="wg-main"', 'aria-label="Platforms"', 'role="tablist"', 'aria-label="Filter by category"')))
check('search box, category chips and phone dropdown markup present', all(x in page for x in ('data-wg-search', 'data-wg-cat="automation"', 'data-wg-cat="chat"', 'data-wg-jump', 'data-wg-empty')))
check('stylesheet and script are linked on this page', 'css/webhook_guides.css' in page and 'js/webhook_guides.js' in page)
check('no external fonts / CDNs added by the page', not re.search(r'(?:src|href)="https?://(?!docs\.|[a-z0-9.-]*(?:n8n|nodered|home-assistant|ntfy|discord|slack|microsoft|telegram|matrix|gotify|apprise|zapier|make|pipedream|ifttt|windmill|activepieces|huginn|mattermost|rocket|learn|github|developers|api|www))', page))

for d in dests:
    i = d['id']
    sec = re.search(r'<section class="wg-guide" id="%s".*?(?=<section class="wg-guide" id="|\Z)' % re.escape(i), page, re.S)
    ok = sec is not None
    check('[%s] sidebar link, select option, index card, guide section' % i, ok and ('href="#%s" data-wg-link="%s"' % (i, i)) in page and ('<option value="%s"' % i) in page and ('<h3 class="wg-card-title">%s</h3>' % htmllib.escape(d['name'], quote=True)) in page and ('href="settings_webhooks.php?add=%s"' % urllib.parse.quote(i)) in page, i)
    if not ok: continue
    g = sec.group(0)
    steps = len(re.findall(r'class="wg-step-num"', g)) - (8 if i == 'n8n' else 0)
    check('[%s] %d setup steps, curl block, docs link, troubleshooting table' % (i, len(d['setupSteps'])), steps == len(d['setupSteps']) and 'id="wg-%s-curl"' % re.sub(r'[^a-z0-9]+', '-', i) in g and 'class="wg-table"' in g and (not d['docsUrl'].startswith('http') or ('href="%s"' % htmllib.escape(d['docsUrl'], quote=True)) in g), steps)
    if d['verifySnippets']:
        check('[%s] signature tabs for %s' % (i, '/'.join(d['verifySnippets'])), all('data-wg-lang="%s"' % re.sub(r'[^a-z0-9]+', '-', l) in g for l in d['verifySnippets']) and 'role="tabpanel"' in g)
    else:
        check('[%s] no-signature platforms explain why' % i, 'cannot check a signature' in g)
    ex = re.search(r'<code id="wg-[a-z0-9-]+-example">(.*?)</code>', g, re.S)
    body = htmllib.unescape(re.sub(r'<[^>]+>', '', ex.group(1))) if ex else ''
    secrets = [f['example'] for f in d['extraFields'] if f['type'] == 'secret' and f['example']]
    check('[%s] example payload rendered (non-empty) with no secret values' % i, bool(body.strip()) and not any(sx in body for sx in secrets) and ('TCK-1042' in body or 'Printer' in body), body[:120])
    check('[%s] Add this webhook link carries the preselection' % i, ('href="settings_webhooks.php?add=%s"' % urllib.parse.quote(i)) in g and 'Add this webhook' in g)

check('prev/next navigation and related links exist', page.count('rel="next"') >= 23 and page.count('rel="prev"') >= 23 and 'class="wg-related"' in page)
check('n8n walk-through steps remain (8) inside the n8n guide', page.count('Mark walk-through step') == 8)

# the add flow loads for the preselected platforms (page opens the modal; the modal itself loads with ?dest=)
s, wp = req(admin, '/admin/settings_webhooks.php?add=n8n')
check('settings_webhooks.php?add=n8n loads and opens the modal on n8n', s == 200 and 'webhook_add.php?dest=n8n' in wp, s)
s, mod = req(admin, '/admin/modals/webhook/webhook_add.php?dest=n8n')
mod = (json.loads(mod).get('content', mod) if mod.startswith('{') else mod) if s == 200 else ''
check('the add-webhook modal loads preselected on n8n', s == 200 and 'n8n' in mod, s)
s, mod = req(admin, '/admin/modals/webhook/webhook_add.php?dest=telegram')
check('the add-webhook modal loads on telegram', s == 200 and 'Bot token' in mod or 'telegram' in mod.lower(), s)

for path, kind in (('/css/webhook_guides.css', 'css'), ('/js/webhook_guides.js', 'js')):
    s, t = req(admin, path)
    check('asset %s served' % path, s == 200 and len(t) > 2000, s)
check('guide CSS is not appended to itflow_custom.css', 'wg-guide' not in open(APP + '/css/itflow_custom.css').read())

# non-admin refused
hashed = subprocess.run(['php', '-r', 'echo password_hash(getenv("P"), PASSWORD_DEFAULT);'], capture_output=True, text=True, env=dict(os.environ, P='Tech-Pass-12345!')).stdout
sql("delete from users where user_email='tech-guides@scratch.test'")
sql("insert into users (user_name, user_email, user_password, user_type, user_status, user_role_id) values ('Scratch Tech', 'tech-guides@scratch.test', '%s', 1, 1, 2)" % hashed)
tech = session()
if login(tech, 'tech-guides@scratch.test', 'Tech-Pass-12345!'):
    s, b = req(tech, '/admin/settings_webhook_guides.php')
    check('non-admin is refused (no guide content)', 'wg-guide' not in b and 'How signing works' not in b, s)
else:
    check('non-admin login (setup)', False)
sql("delete from users where user_email='tech-guides@scratch.test'")
s, b = req(session(), '/admin/settings_webhook_guides.php')
check('anonymous request is redirected or refused', s in (301, 302, 303, 401, 403) and 'wg-guide' not in b, s)

# escaping unit check + icon check
r = subprocess.run(['php', 'tests/e2e/webhook_guides_escape.php', APP], capture_output=True, text=True, cwd=APP)
check('hostile destination text is HTML-escaped everywhere', r.returncode == 0, r.stdout + r.stderr)
r = subprocess.run(['php', 'tests/fa_icons.php'], capture_output=True, text=True, cwd=APP)
check('tests/fa_icons.php passes', r.returncode == 0, r.stdout + r.stderr)

print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
