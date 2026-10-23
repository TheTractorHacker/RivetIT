"""Which technician pages render main content identical to the admin render? (hash of main innerText + all <img> srcs). Used to avoid re-viewing identical content."""
from common import *
import hashlib
T = json.load(open(os.path.join(HERE, 'out', 'targets.json'))) + json.load(open(os.path.join(HERE, 'out', 'targets_id.json')))
seen = set(); urls = []
for t in T:
    if t['module'] == 'agent' and not t['skip'] and not t['ajax'] and not t['sse'] and t['url'] not in seen: seen.add(t['url']); urls.append(t['url'])
JS = """() => { const m = document.querySelector('main') || document.body; const t = (m.innerText || '').replace(/\\s+/g, ' ').replace(/(Alex Morgan|Priya Nair|QA-UI- [A-Za-z ]+)/g, 'USER'); return {t, n: m.querySelectorAll('*').length, h: document.documentElement.scrollHeight} }"""
p = sync_playwright().start(); res = {}
out = {}
for who in ('admin', 'tech'):
    b, ctx, pg = login(p, who)
    for u in urls:
        try:
            r = pg.goto(BASE + u, timeout=30000, wait_until='domcontentloaded'); time.sleep(.5)
            try: pg.wait_for_load_state('load', timeout=5000)
            except Exception: pass
            d = pg.evaluate(JS); out.setdefault(u, {})[who] = {'sha': hashlib.sha1(d['t'].encode()).hexdigest()[:12], 'n': d['n'], 'status': r.status}
        except Exception as e: out.setdefault(u, {})[who] = {'err': str(e)[:50]}
    b.close()
json.dump(out, open('/home/sysadmin/qa-design-scratch/tech_vs_admin.json', 'w'))
same = [u for u, v in out.items() if v.get('admin', {}).get('sha') and v['admin'].get('sha') == v.get('tech', {}).get('sha')]
print(len(urls), 'pages;', len(same), 'identical main content')
