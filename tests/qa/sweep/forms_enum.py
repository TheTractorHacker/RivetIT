"""Inventory every POST form: page-level forms and one instance of each distinct ajax modal path. Output out/forms_<role>.json. usage: forms_enum.py <admin|portal>"""
from common import *
role = sys.argv[1]
FJS = open(os.path.join(HERE, 'forms.js')).read()
who = 'admin' if role == 'admin' else 'portal2'; mods = ['admin', 'agent'] if role == 'admin' else ['client']
T = json.load(open(os.path.join(OUT, 'targets.json'))) + json.load(open(os.path.join(OUT, 'targets_id.json')))
pages = []; seen = set()
for t in T:
    if t['module'] in mods and not t['skip'] and not t['ajax'] and not t['sse'] and t['url'] not in seen: seen.add(t['url']); pages.append(t['url'])
b, ctx, pg = login(sync_playwright().start(), who)
out = {'pageforms': [], 'modals': []}; seen_modal = set()
for u in pages:
    try:
        pg.goto(BASE + u, timeout=30000, wait_until='domcontentloaded'); time.sleep(.8)
        n = pg.evaluate("() => [...document.querySelectorAll('form')].filter(f => (f.method||'get').toLowerCase()==='post' && !f.closest('.modal')).length")
        for k in range(n):
            info = pg.evaluate("([js, k]) => { const f = [...document.querySelectorAll('form')].filter(f => (f.method||'get').toLowerCase()==='post' && !f.closest('.modal'))[k]; return (new Function('return ' + js))()(f) }", [FJS, k])
            info.update({'page': u, 'idx': k}); out['pageforms'].append(info)
        trig = pg.evaluate("() => [...document.querySelectorAll('.ajax-modal[data-modal-url]')].map((e, i) => ({i, u: e.getAttribute('data-modal-url'), t: ((e.innerText||'')+' '+(e.title||'')).trim().slice(0, 40)}))")
    except Exception as ex: out['modals'].append({'page': u, 'error': str(ex)[:80]}); continue
    for tr in trig:
        path = tr['u'].split('?')[0]
        if path in seen_modal: continue
        seen_modal.add(path)
        try:
            pg.evaluate("(i) => document.querySelectorAll('.ajax-modal[data-modal-url]')[i].click()", tr['i'])
            pg.wait_for_selector('.modal.show .modal-content', timeout=7000); time.sleep(.5)
            nf = pg.evaluate("() => [...document.querySelectorAll('.modal.show form')].length")
            forms = []
            for k in range(nf):
                forms.append(pg.evaluate("([js, k]) => { const f = [...document.querySelectorAll('.modal.show form')][k]; return (new Function('return ' + js))()(f) }", [FJS, k]))
            out['modals'].append({'page': u, 'modal': tr['u'], 'path': path, 'trigger': tr['t'], 'forms': forms})
            pg.keyboard.press('Escape'); time.sleep(.25)
            pg.evaluate("() => document.querySelectorAll('.modal.show').forEach(m => window.bootstrap && bootstrap.Modal.getInstance(m)?.hide())"); time.sleep(.2)
        except Exception as ex:
            out['modals'].append({'page': u, 'modal': tr['u'], 'path': path, 'error': str(ex)[:80]})
json.dump(out, open(os.path.join(OUT, f'forms_{role}.json'), 'w'), indent=1)
pf = out['pageforms']; mf = [m for m in out['modals'] if m.get('forms')]
print('pageforms', len(pf), 'fields', sum(f['nfields'] for f in pf), '| modals', len(out['modals']), 'with forms', len(mf), 'fields', sum(f['nfields'] for m in mf for f in m['forms']))
