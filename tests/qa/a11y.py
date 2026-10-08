"""axe-core accessibility scan of key pages. usage: a11y.py <it|msp> <path to axe.min.js>"""
import sys, json, collections
from common import *
ed, axe = sys.argv[1], open(sys.argv[2]).read()
pages = ['/login.php', '/agent/dashboard.php', '/agent/clients.php', '/agent/tickets.php', '/agent/assets.php', '/agent/contacts.php', '/agent/kb_articles.php', '/admin/users.php', '/agent/user/user_security.php', '/agent/reports/']
p, b, ctx, pg, base = session(ed)
agg = collections.defaultdict(lambda: {'impact': '', 'pages': set(), 'n': 0, 'help': ''})
# login page is scanned before auth in a fresh context
c0 = b.new_context(ignore_https_errors=True); l = c0.new_page(); l.goto(base + '/login.php'); l.evaluate(axe)
for v in l.evaluate("axe.run().then(r=>r.violations.map(v=>({id:v.id,impact:v.impact,help:v.help,n:v.nodes.length})))"):
    a = agg[v['id']]; a['impact'] = v['impact']; a['help'] = v['help']; a['pages'].add('/login.php'); a['n'] += v['n']
for path in pages[1:]:
    try: pg.goto(base + path, wait_until='domcontentloaded'); pg.wait_for_timeout(900); pg.evaluate(axe)
    except Exception as e: print('skip', path, str(e)[:60]); continue
    for v in pg.evaluate("axe.run().then(r=>r.violations.map(v=>({id:v.id,impact:v.impact,help:v.help,n:v.nodes.length})))"):
        a = agg[v['id']]; a['impact'] = v['impact']; a['help'] = v['help']; a['pages'].add(path); a['n'] += v['n']
order = {'critical': 0, 'serious': 1, 'moderate': 2, 'minor': 3}
for k, v in sorted(agg.items(), key=lambda kv: (order.get(kv[1]['impact'], 9), -kv[1]['n'])):
    print(f"{v['impact']:9} {k:32} nodes={v['n']:4} pages={len(v['pages'])}/{len(pages)}  {v['help'][:70]}")
b.close(); p.stop()
