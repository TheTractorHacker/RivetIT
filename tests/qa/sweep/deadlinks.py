"""Which href='#'/empty anchors have NO handler hint (no ajax-modal/data-bs/onclick/role=button/drag-handle class)? usage: deadlinks.py <role> <modules>"""
from common import *
role = sys.argv[1]; mods = sys.argv[2].split(',')
targets = [t for t in json.load(open(os.path.join(OUT, 'targets.json'))) if t['module'] in mods and not t['skip'] and not t['ajax'] and not t['sse']]
p = sync_playwright().start(); b, ctx, pg = login(p, role); res = {}
JS = """() => [...document.querySelectorAll('a[href]')].filter(a => { const h = a.getAttribute('href'); return h === '#' || h === '' || /^javascript:/i.test(h) })
  .filter(a => !a.getAttribute('data-bs-toggle') && !a.getAttribute('onclick') && !a.getAttribute('data-bs-target') && a.getAttribute('role') !== 'button' && !/ajax-modal|loadModal|dropdown|drag|toggle|btn-add|js-|select-all|page-link|nav-link|carousel|collapse|bulk/i.test(a.className) && !a.closest('.dropdown-menu,.pagination,[data-bs-toggle]'))
  .map(a => (a.innerText||a.title||'').trim().slice(0,40) + ' <' + a.className.slice(0,50) + '>').slice(0, 8)"""
for t in targets:
    try:
        pg.goto(BASE + t['url'], timeout=25000, wait_until='domcontentloaded')
        try: pg.wait_for_load_state('load', timeout=5000)
        except Exception: pass
        if 'login' in pg.url: continue
        d = pg.evaluate(JS)
        if d: res[t['file']] = d
    except Exception as e: pass
json.dump(res, open(os.path.join(OUT, f'deadlinks_{role}_{"_".join(mods)}.json'), 'w'), indent=1)
for k, v in res.items(): print(k, v)
b.close(); p.stop()
