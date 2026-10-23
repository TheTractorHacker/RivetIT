"""Follow the alias admin redirects as technician / Training L2 / portal and record the final status + first words."""
from common import *
b0 = json.load(open(os.path.join(OUT, 'boundary.json')))
alias = [(x['file'], x['location']) for x in b0 if x['who'] == 'tech' and x['file'].startswith('admin/') and x.get('status') in (301, 302, 303)]
p = sync_playwright().start(); res = []
for who in ('tech', 'l2', 'portal'):
    b, ctx, pg = login(p, who)
    for f, loc in alias:
        r = pg.goto(BASE + '/' + f); time.sleep(.4)
        txt = pg.evaluate("() => (document.querySelector('main, .page-body, body').innerText||'').trim().replace(/\\s+/g,' ')")
        res.append({'who': who, 'page': f, 'status': r.status, 'final': pg.url.replace(BASE, ''), 'says': txt[:140] if who != 'portal' else txt[:60]})
    b.close()
json.dump(res, open(os.path.join(OUT, 'alias_follow.json'), 'w'), indent=1)
for x in res: print(x['who'].ljust(7), x['page'].ljust(34), x['status'], x['final'][:40].ljust(40), '|', x['says'][:90])
p.stop()
