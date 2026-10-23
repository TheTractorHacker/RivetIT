"""Open every distinct ajax modal (agent+admin pages) and screenshot the dialog. usage: modal_shoot.py <light|dark> <WxH> [part/of] 
Opens only; never submits. Output scratch dir."""
from common import *
theme, vp = sys.argv[1], sys.argv[2]; part = sys.argv[3] if len(sys.argv) > 3 else '1/1'
pi, pn = map(int, part.split('/'))
W, H = map(int, vp.split('x')); ROOT = '/home/sysadmin/qa-design-scratch'
who = 'admin' if theme == 'light' else 'admin_d'
T = json.load(open(os.path.join(HERE, 'out', 'targets.json')))
pages = [t['url'] for t in T if t['module'] in ('admin', 'agent') and not t['skip'] and not t['ajax'] and not t['sse']]
d = os.path.join(ROOT, 'modals', theme, vp); os.makedirs(d, exist_ok=True)
PROBES = open(os.path.join(HERE, 'probes.js')).read()
p = sync_playwright().start(); b = p.chromium.launch(); ctx = b.new_context(viewport={'width': W, 'height': H}); pg = ctx.new_page()
e, pw = USERS[who]; pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw); pg.locator('button[type=submit]').first.click(); time.sleep(2)
seen = {}; res = []
from urllib.parse import urljoin
for i, u in enumerate(pages):
    if i % pn != pi - 1: continue
    try:
        pg.goto(BASE + u, timeout=30000, wait_until='domcontentloaded'); time.sleep(.8)
        trig = pg.evaluate("""() => [...document.querySelectorAll('.ajax-modal[data-modal-url]')].map((e, i) => ({i, u: e.getAttribute('data-modal-url'), t: ((e.innerText||'')+' '+(e.title||'')).trim().slice(0, 40)}))""")
    except Exception as ex: res.append({'page': u, 'error': str(ex)[:80]}); continue
    n = 0
    for tr in trig:
        key = tr['u']
        if not key or key in seen: continue
        seen[key] = 1; n += 1
        if n > 40: break
        slug = re.sub(r'[^a-z0-9]+', '_', key.lower()).strip('_')[:100]
        try:
            pg.evaluate("(i) => document.querySelectorAll('.ajax-modal[data-modal-url]')[i].click()", tr['i'])
            try: pg.wait_for_selector('.modal.show .modal-content', timeout=7000); shown = True
            except Exception: shown = False
            time.sleep(.7)
            rec = {'page': u, 'modal': key, 'trigger': tr['t'], 'shown': shown}
            if shown:
                rec['dlg'] = pg.evaluate("() => { const dl = document.querySelector('.modal.show .modal-dialog'); const r = dl.getBoundingClientRect(); return {w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), vh: innerHeight, cls: dl.className, scroll: dl.classList.contains('modal-dialog-scrollable'), hasHeader: !!dl.querySelector('.modal-header'), hasFooter: !!dl.querySelector('.modal-footer'), title: (dl.querySelector('.modal-title')||{}).innerText||null, hdrClass: (dl.querySelector('.modal-header')||{className:''}).className, primary: [...dl.querySelectorAll('.modal-footer .btn, .modal-footer button')].map(b => b.innerText.trim().slice(0,20) + '|' + b.className.replace(/\\s+/g,' ').slice(0,40) + '|' + Math.round(b.getBoundingClientRect().height)) } }")
                f = slug + '.jpg'
                pg.locator('.modal.show .modal-content').first.screenshot(path=os.path.join(d, f), type='jpeg', quality=65); rec['shot'] = f
            res.append(rec)
            pg.keyboard.press('Escape'); time.sleep(.3)
            pg.evaluate("() => document.querySelectorAll('.modal.show').forEach(m => window.bootstrap && bootstrap.Modal.getInstance(m)?.hide())"); time.sleep(.25)
        except Exception as ex:
            res.append({'page': u, 'modal': key, 'error': str(ex)[:80]})
    if i % 20 == 0: print(theme, vp, i, len(pages), len(seen), flush=True)
json.dump(res, open(os.path.join(d, f'modals_{pi}of{pn}.json'), 'w'))
print('done', theme, vp, len(res)); b.close(); p.stop()
