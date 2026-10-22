"""color-contrast nodes with measured colours, grouped by selector class. usage: contrast_detail.py <who> <label>"""
from ux_common import *
import collections
who,label=sys.argv[1:3]
p,b,ctx,pg=start(who=who)
paths=[l.strip() for l in open(os.path.join(HERE,'pages_agent.txt')) if l.strip()]+['/admin/users.php','/admin/audit_log.php']
agg=collections.defaultdict(list)
for u in paths:
    try:
        pg.goto(BASE+u,timeout=20000); pg.wait_for_load_state('load'); time.sleep(.5); pg.evaluate(AXE)
        nodes=pg.evaluate("""async()=>{const r=await axe.run(document,{runOnly:{type:'rule',values:['color-contrast']}}); return r.violations.flatMap(v=>v.nodes.map(n=>{const d=(n.any[0]||{}).data||{}; return {sel:n.target.join(' ').slice(-70), html:(n.html||'').slice(0,90), fg:d.fgColor,bg:d.bgColor,ratio:d.contrastRatio,size:d.fontSize}}))}""")
        for n in nodes: agg[(n['fg'],n['bg'])].append((u,n))
    except Exception as e: print('ERR',u,str(e)[:60])
for (fg,bg),v in sorted(agg.items(), key=lambda kv:-len(kv[1]))[:25]:
    n=v[0][1]; print(f"{len(v):3} fg={fg} bg={bg} ratio={n['ratio']} {n['size']} e.g. {v[0][0]} {n['html'][:80]}")
json.dump({f'{k[0]}|{k[1]}':[(u,n['sel']) for u,n in v] for k,v in agg.items()}, open(f'{OUT}/contrast_{label}.json','w'))
b.close()
