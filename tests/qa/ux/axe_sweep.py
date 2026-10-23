"""axe-core over a page list. usage: axe_sweep.py <who> <label> [rules,comma] [paths-file]"""
from ux_common import *
who = sys.argv[1]; label = sys.argv[2]
rules = sys.argv[3].split(',') if len(sys.argv) > 3 and sys.argv[3] != 'all' else None
paths = [l.strip() for l in open(sys.argv[4])] if len(sys.argv) > 4 else [l.strip() for l in open(os.path.join(HERE, 'pages_agent.txt'))]
p, b, ctx, pg = start(who=who)
res = {}
for path in paths:
    if not path: continue
    try:
        pg.goto(BASE + path, timeout=20000); pg.wait_for_load_state('load'); time.sleep(.6)
        res[path] = axe(pg, rules)
    except Exception as e:
        res[path] = [{'id': 'ERROR', 'impact': 'x', 'n': 0, 'sample': [str(e)[:120]]}]
json.dump(res, open(os.path.join(OUT, f'axe_{label}.json'), 'w'), indent=1)
agg = {}
for path, vs in res.items():
    for v in vs: agg.setdefault(v['id'], []).append((path, v['n'], v['impact']))
for k, v in sorted(agg.items(), key=lambda kv: -len(kv[1])):
    print(f"{k:35} pages={len(v):3} nodes={sum(x[1] for x in v):4} {v[0][2]}  e.g. {v[0][0]}")
b.close()
