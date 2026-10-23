import json,glob,collections,re,sys
R='/home/sysadmin/qa-design-scratch/shots'
cat=sys.argv[1]; filt=sys.argv[2] if len(sys.argv)>2 else '.*'
c=collections.defaultdict(set)
def norm(s): return re.sub(r'"[^"]*"','"…"',re.sub(r'\d+(\.\d+)?','N',s))[:110]
for f in glob.glob(f'{R}/*/*/*/probes.json'):
    role,theme,vp=f.split('/')[-4:-1]
    d=json.load(open(f))
    for u,v in d.items():
        for pr in [v.get('probes')]+[t.get('probes') for t in v.get('tabs',[]) if t.get('probes')]:
            if not pr: continue
            for s in pr.get(cat,[]):
                if re.search(filt,s): c[norm(s)].add((role,theme,vp,u))
for k,v in sorted(c.items(),key=lambda kv:-len(kv[1]))[:int(sys.argv[3]) if len(sys.argv)>3 else 25]:
    pages=sorted({x[3] for x in v}); print(len(v),'x',len(pages),'pages',k,'| e.g.',pages[0])
