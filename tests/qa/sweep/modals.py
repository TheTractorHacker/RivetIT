"""Modal sweep: finds every .ajax-modal trigger per page, GETs each modal's content URL (what the click does) and checks it renders without PHP/5xx;
also really clicks up to 2 visible non-destructive triggers per page. Never submits. usage: modals.py <role> <modules>"""
from common import *
from urllib.parse import urljoin
role = sys.argv[1]; mods = sys.argv[2].split(','); label = role + '_' + '_'.join(mods)
targets = [t for t in json.load(open(os.path.join(OUT, 'targets.json'))) if t['module'] in mods and not t['skip'] and not t['ajax'] and not t['sse']]
p = sync_playwright().start(); b, ctx, pg = login(p, role)
errs = []; pg.on('pageerror', lambda e: errs.append(str(e)[:200]))
seen = {}; res = []; clicks = []
for i, t in enumerate(targets):
    try:
        pg.goto(BASE + t['url'], timeout=25000, wait_until='domcontentloaded')
        try: pg.wait_for_load_state('load', timeout=6000)
        except Exception: pass
        if 'login' in pg.url: continue
        trig = pg.evaluate("""() => [...document.querySelectorAll('.ajax-modal[data-modal-url],[data-bs-toggle="modal"][data-modal-url]')].map(e => ({u: e.getAttribute('data-modal-url'), t: ((e.innerText||'')+' '+(e.title||'')+' '+(e.getAttribute('aria-label')||'')).trim().slice(0,40)}))""")
    except Exception as e:
        res.append({'page': t['file'], 'error': str(e)[:100]}); continue
    base = pg.url
    for tr in trig[:60]:
        u = tr['u']
        if not u or DANGER.search(tr['t']) or DANGER.search(u): continue
        full = urljoin(base, u)
        if full in seen: continue
        seen[full] = 1
        try:
            r = ctx.request.get(full, timeout=15000); body = r.text()
            rec = {'role': role, 'page': t['file'], 'modal': full.replace(BASE, ''), 'trigger': tr['t'], 'status': r.status, 'bytes': len(body),
                   'php_error': PHP_ERR.search(body).group(0) if PHP_ERR.search(body) else None, 'empty': len(body.strip()) < 30}
            if r.status >= 400 or rec['php_error'] or rec['empty']: res.append(rec)
        except Exception as e:
            res.append({'role': role, 'page': t['file'], 'modal': full.replace(BASE, ''), 'exception': str(e)[:100]})
    # real click on up to 2 visible triggers
    vis = pg.locator('.ajax-modal[data-modal-url]:visible')
    done = 0
    for k in range(min(vis.count(), 12)):
        if done >= 2: break
        el = vis.nth(k)
        try:
            lab = ((el.inner_text() or '') + ' ' + (el.get_attribute('title') or '')).strip()
            if DANGER.search(lab) or DANGER.search(el.get_attribute('data-modal-url') or ''): continue
            done += 1; e0 = len(errs)
            el.click(timeout=2500)
            try: pg.wait_for_selector('.modal.show .modal-content', timeout=6000); shown = True
            except Exception: shown = False
            time.sleep(.5)
            info = pg.evaluate("() => { const m = document.querySelector('.modal.show .modal-dialog'); if(!m) return null; const r = m.getBoundingClientRect(); return {len: m.innerText.trim().length, top: r.top, bottom: r.bottom - innerHeight, h: r.height, vh: innerHeight, scroll: !!document.querySelector('.modal.show .modal-body') && getComputedStyle(document.querySelector('.modal.show .modal-body')).overflowY} }")
            clicks.append({'role': role, 'page': t['file'], 'trigger': lab[:40], 'shown': shown, 'info': info, 'js_errors': errs[e0:]})
            pg.keyboard.press('Escape'); time.sleep(.3)
            pg.evaluate("() => document.querySelectorAll('.modal.show').forEach(m => window.bootstrap && bootstrap.Modal.getInstance(m)?.hide())"); time.sleep(.2)
        except Exception as e:
            clicks.append({'role': role, 'page': t['file'], 'trigger': '?', 'error': str(e)[:90]})
    if i % 25 == 0: print(role, i, len(targets), len(seen), flush=True)
json.dump({'unique_modals': len(seen), 'failures': res, 'clicks': clicks}, open(os.path.join(OUT, f'modals_{label}.json'), 'w'), indent=1)
print('done', label, 'unique', len(seen), 'failures', len(res), 'clicks', len(clicks))
b.close(); p.stop()
