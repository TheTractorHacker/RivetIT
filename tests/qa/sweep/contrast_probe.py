"""Pixel-based text contrast probe (catches gradient/image backgrounds axe cannot judge). usage: contrast_probe.py <admin|tech|portal> <light|dark> [part/of]
For each text element: contrast between its computed colour and the most common rendered pixel colour inside its box. Leads only."""
from common import *
import base64
role, theme = sys.argv[1], sys.argv[2]; part = sys.argv[3] if len(sys.argv) > 3 else '1/1'; pi, pn = map(int, part.split('/'))
who = {('admin', 'light'): 'admin', ('admin', 'dark'): 'admin_d', ('tech', 'light'): 'tech', ('tech', 'dark'): 'tech_d', ('portal', 'light'): 'portal2', ('portal', 'dark'): 'portal2'}[(role, theme)]
mods = {'admin': ['admin', 'agent'], 'tech': ['agent'], 'portal': ['client']}[role]
T = json.load(open(os.path.join(HERE, 'out', 'targets.json'))) + json.load(open(os.path.join(HERE, 'out', 'targets_id.json')))
seen = set(); urls = []
for t in T:
    if t['module'] in mods and not t['skip'] and not t['ajax'] and not t['sse'] and t['url'] not in seen: seen.add(t['url']); urls.append(t['url'])
urls = [u for i, u in enumerate(urls) if i % pn == pi - 1]
JS = """async (b64) => {
  const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
  const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; const x = c.getContext('2d', {willReadFrequently: true}); x.drawImage(img, 0, 0);
  const dpr = img.width / document.documentElement.scrollWidth;
  const lum = (r, g, b) => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4) }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b) };
  const out = []; const els = [...document.querySelectorAll('body *')];
  for (const e of els) {
    if (![...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim().length > 1)) continue;
    const s = getComputedStyle(e); if (e.closest('.skip-link, .visually-hidden, .sr-only')) continue; if (s.visibility === 'hidden' || s.display === 'none' || parseFloat(s.opacity) === 0) continue;
    const r = e.getBoundingClientRect(); if (r.width < 8 || r.height < 6) continue;
    const X = Math.max(0, Math.floor((r.left + scrollX) * dpr)), Y = Math.max(0, Math.floor((r.top + scrollY) * dpr)), W = Math.min(Math.ceil(r.width * dpr), c.width - X), H = Math.min(Math.ceil(r.height * dpr), c.height - Y, 80);
    if (W < 6 || H < 4) continue;
    const d = x.getImageData(X, Y, W, H).data; const cnt = new Map();
    for (let i = 0; i < d.length; i += 16) { const k = (d[i] >> 3) + ',' + (d[i + 1] >> 3) + ',' + (d[i + 2] >> 3); cnt.set(k, (cnt.get(k) || 0) + 1); }
    let best = null, bn = 0; for (const [k, n] of cnt) if (n > bn) { bn = n; best = k }
    const [br, bg, bb] = best.split(',').map(v => (+v << 3) + 4);
    let m = s.color.match(/[\\d.]+/g).map(Number); if (s.color.startsWith('color(')) m = [m[0] * 255, m[1] * 255, m[2] * 255, m[3] === undefined ? 1 : m[3]]; const a = m[3] === undefined ? 1 : m[3];
    const L1 = lum(m[0], m[1], m[2]), L2 = lum(br, bg, bb); const cr = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    if (a > 0.3 && cr < 3.0) out.push({t: (e.innerText || '').trim().slice(0, 40), cr: +cr.toFixed(2), fg: s.color, bg: `rgb(${br},${bg},${bb})`, el: e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\\s+/).slice(0, 2).join('.') : ''), fs: s.fontSize});
  }
  return out.slice(0, 12);
}"""
p = sync_playwright().start(); b = p.chromium.launch(); ctx = b.new_context(viewport={'width': 1920, 'height': 1080}); pg = ctx.new_page()
e, pw = USERS[who]; pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw); pg.locator('button[type=submit]').first.click(); time.sleep(2)
res = {}
for i, u in enumerate(urls):
    try:
        pg.goto(BASE + u, timeout=30000, wait_until='domcontentloaded')
        try: pg.wait_for_load_state('load', timeout=6000)
        except Exception: pass
        time.sleep(.5)
        png = pg.screenshot(full_page=True, type='png')
        res[u] = pg.evaluate(JS, base64.b64encode(png).decode())
    except Exception as ex: res[u] = [{'error': str(ex)[:100]}]
    if i % 20 == 0: print(role, theme, i, len(urls), flush=True)
os.makedirs('/home/sysadmin/qa-design-scratch/contrast', exist_ok=True)
json.dump(res, open(f'/home/sysadmin/qa-design-scratch/contrast/{role}_{theme}_{pi}of{pn}.json', 'w'))
print('done', role, theme, sum(1 for v in res.values() if v and not v[0].get('error')), 'pages with leads')
b.close(); p.stop()
