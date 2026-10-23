"""Design-review capture. usage: design_shoot.py <admin|tech|portal> <light|dark> <WxH> [outroot]
Full-page JPEG of every swept page (+ each tab at 1920), plus DOM design probes (see probes.js). Output is scratch (not in git)."""
from common import *
role, theme, vp = sys.argv[1], sys.argv[2], sys.argv[3]
ROOT = sys.argv[4] if len(sys.argv) > 4 else '/home/sysadmin/qa-design-scratch'
W, H = map(int, vp.split('x'))
who = {('admin', 'light'): 'admin', ('admin', 'dark'): 'admin_d', ('tech', 'light'): 'tech', ('tech', 'dark'): 'tech_d', ('portal', 'light'): 'portal2', ('portal', 'dark'): 'portal2'}[(role, theme)]
mods = {'admin': ['admin', 'agent'], 'tech': ['agent'], 'portal': ['client']}[role]
T = json.load(open(os.path.join(HERE, 'out', 'targets.json'))) + json.load(open(os.path.join(HERE, 'out', 'targets_id.json')))
seen = set(); targets = []
for t in T:
    if t['module'] not in mods or t['skip'] or t['ajax'] or t['sse']: continue
    urls = [t['url']] + ['/' + t['file'] + '?tab=' + tb for tb in t['tabs']]
    for u in urls:
        if u not in seen: seen.add(u); targets.append(u)
PROBES = open(os.path.join(HERE, 'probes.js')).read()
d = os.path.join(ROOT, 'shots', role, theme, vp); os.makedirs(d, exist_ok=True)
slug = lambda u: re.sub(r'[^a-z0-9]+', '_', u.lower()).strip('_')[:110]
p = sync_playwright().start()
b = p.chromium.launch(); ctx = b.new_context(viewport={'width': W, 'height': H}); pg = ctx.new_page()
e, pw = USERS[who]; pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw); pg.locator('button[type=submit]').first.click()
try: pg.wait_for_url(lambda u: 'login' not in u, timeout=15000)
except Exception: pass
res = {}
for i, u in enumerate(targets):
    try:
        if 'login' in pg.url:
            pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw); pg.locator('button[type=submit]').first.click(); time.sleep(1.5)
        r = pg.goto(BASE + u, timeout=30000, wait_until='domcontentloaded')
        try: pg.wait_for_load_state('load', timeout=8000)
        except Exception: pass
        time.sleep(.6)
        rec = {'url': u, 'status': r.status if r else None, 'final': pg.url.replace(BASE, '')}
        rec['probes'] = pg.evaluate(PROBES)
        f = slug(u) + '.jpg'; pg.screenshot(path=os.path.join(d, f), full_page=True, type='jpeg', quality=62); rec['shot'] = f
        if W == 1920 and role != 'portal' or W == 1920:
            tabs = pg.locator('[data-bs-toggle="tab"]:visible, [data-bs-toggle="pill"]:visible'); rec['tabs'] = []
            for k in range(min(tabs.count(), 14)):
                try:
                    el = tabs.nth(k); lab = re.sub(r'[^a-z0-9]+', '', (el.inner_text() or 'tab').lower())[:20]
                    el.click(timeout=2000); time.sleep(.4)
                    tf = f"{slug(u)}__tab{k}_{lab}.jpg"; pg.screenshot(path=os.path.join(d, tf), full_page=True, type='jpeg', quality=62)
                    rec['tabs'].append({'tab': lab, 'shot': tf, 'probes': pg.evaluate(PROBES)})
                except Exception as ex: rec['tabs'].append({'error': str(ex)[:60]})
        res[u] = rec
    except Exception as ex:
        res[u] = {'url': u, 'error': str(ex)[:120]}
    if i % 25 == 0: print(role, theme, vp, i, len(targets), flush=True); json.dump(res, open(os.path.join(d, 'probes.json'), 'w'))
json.dump(res, open(os.path.join(d, 'probes.json'), 'w'))
print('done', role, theme, vp, len(res)); b.close(); p.stop()
