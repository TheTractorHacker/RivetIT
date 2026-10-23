"""Phase B: exercise every control on a page without touching destructive ones.
 - plain links: GET each (same-origin) and flag 4xx/5xx/fatal text
 - modal triggers (.ajax-modal / data-bs-toggle=modal): click, expect a modal, flag errors, Escape
 - dropdown toggles and tabs: click, flag JS errors
 usage: click_sweep.py <who> <label> <paths-file> [max-controls-per-page]"""
from ux_common import *
import re
who, label, pf = sys.argv[1:4]; MAXC = int(sys.argv[4]) if len(sys.argv) > 4 else 60
paths = [l.strip() for l in open(pf) if l.strip()]
DANGER = re.compile(r'post\.php|logout|delete|archive|restore|disable|activate|revoke|reset|purge|csrf_token|wipe|remove|sync|backup|update|cron|send|run|trigger|install|clear|empty|truncate|unlink|disconnect|stop|kill|export|download|\.csv|\.zip|\.pdf|webcal|feed', re.I)
p, b, ctx, pg = start(who=who, portal=(who == 'portal'))
req = ctx.request
res = {}
errs = []; pg.on('pageerror', lambda e: errs.append(str(e)[:160]))
ajax = []; pg.on('response', lambda r: ajax.append((r.status, r.url)) if r.status >= 400 else None)
def fatal(t): return any(x in t[:30000] for x in ('Fatal error', 'Uncaught', 'Stack trace', 'mysqli_sql_exception', 'Parse error', 'Warning: '))
for path in paths:
    r = {'links': 0, 'bad_links': [], 'modals_ok': 0, 'modals_bad': [], 'dropdowns': 0, 'tabs': 0, 'js_errors': [], 'http_errors': []}
    try:
        errs.clear(); ajax.clear()
        resp0 = pg.goto(BASE + path, timeout=25000); pg.wait_for_load_state('load'); time.sleep(.7)
        if resp0 and resp0.status >= 400: r['http_errors'].append((resp0.status, 'PAGE ' + path))
        origin = BASE
        # 1. links
        hrefs = pg.evaluate("""() => [...new Set([...document.querySelectorAll('a[href]')].map(a=>a.getAttribute('href')).filter(h=>h && !h.startsWith('#') && !h.startsWith('javascript') && !h.startsWith('mailto') && !h.startsWith('tel') && !h.startsWith('http') ))]""")
        base_url = pg.url.rsplit('/', 1)[0] + '/'
        from urllib.parse import urljoin
        for h in hrefs[:MAXC]:
            if DANGER.search(h): continue
            r['links'] += 1
            try:
                resp = req.get(urljoin(pg.url, h), max_redirects=3, timeout=20000)
                t = resp.text() if resp.status < 400 else ''
                if resp.status >= 400 or fatal(t): r['bad_links'].append((h[:90], resp.status))
            except Exception as e: r['bad_links'].append((h[:90], 'ERR ' + str(e)[:40]))
        # 2. modal triggers
        trig = pg.locator(".ajax-modal:visible, [data-bs-toggle=modal]:visible")
        n = min(trig.count(), MAXC)
        for i in range(n):
            el = trig.nth(i)
            try:
                url = el.get_attribute('data-modal-url') or el.get_attribute('data-bs-target') or ''
                if DANGER.search(url) and not re.search(r'modals/', url): continue
                if re.search(r'delete|archive|restore|reset|purge|disable|revoke|wipe|clear|remove', url, re.I): continue
                el.click(timeout=4000); time.sleep(.9)
                m = pg.locator('.modal.show')
                if m.count() == 0: r['modals_bad'].append((url[-60:], 'no modal'))
                else:
                    txt = m.first.inner_text()[:3000]
                    if fatal(txt) or re.search(r'(^|\n)(Error|Not allowed|Fatal)', txt): r['modals_bad'].append((url[-60:], 'error text'))
                    else: r['modals_ok'] += 1
                pg.keyboard.press('Escape'); time.sleep(.3)
                if pg.locator('.modal.show').count(): pg.goto(BASE + path); pg.wait_for_load_state('load'); time.sleep(.5); trig = pg.locator(".ajax-modal:visible, [data-bs-toggle=modal]:visible")
            except Exception as e:
                r['modals_bad'].append(((el.get_attribute('data-modal-url') or '?')[-60:], 'click: ' + str(e)[:50]))
                try: pg.goto(BASE + path); pg.wait_for_load_state('load'); time.sleep(.5); trig = pg.locator(".ajax-modal:visible, [data-bs-toggle=modal]:visible")
                except Exception: pass
        # 3. dropdowns + tabs
        for sel, key in (("[data-bs-toggle=dropdown]:visible", 'dropdowns'), ("[data-bs-toggle=tab]:visible, [data-bs-toggle=pill]:visible", 'tabs')):
            c = pg.locator(sel); k = min(c.count(), 25)
            for i in range(k):
                try: c.nth(i).click(timeout=3000); time.sleep(.2); r[key] += 1
                except Exception: pass
            pg.keyboard.press('Escape')
        r['js_errors'] = list(dict.fromkeys(errs))[:4]
        r['http_errors'] += [(s, u[-70:]) for s, u in ajax][:5]
    except Exception as e:
        r['fatal'] = str(e)[:140]
    res[path] = r
    flag = r['bad_links'] or r['modals_bad'] or r['js_errors'] or r['http_errors'] or r.get('fatal')
    print(('FAIL ' if flag else 'ok   ') + path, {k: v for k, v in r.items() if v and k not in ('links',)} if flag else f"links={r['links']} modals={r['modals_ok']} dd={r['dropdowns']} tabs={r['tabs']}", flush=True)
    json.dump(res, open(f'{OUT}/click_{label}.json', 'w'), indent=1)
b.close()
