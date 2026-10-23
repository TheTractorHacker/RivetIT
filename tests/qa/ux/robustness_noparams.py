"""Every *_details / detail page requested with no id and with id=0 must not 500."""
from ux_common import *
import glob
p,b,ctx,pg=start(who='admin')
root=os.path.join(HERE,'..','..','..')
pages=sorted(set(glob.glob(root+'/agent/*detail*.php')+glob.glob(root+'/agent/*_view.php')+glob.glob(root+'/admin/*detail*.php')+glob.glob(root+'/agent/*overview*.php')+glob.glob(root+'/agent/training_*.php')))
bad=[]
for f in pages:
    u='/'+os.path.relpath(f,root)
    for q in ['','?id=0','?client_id=0','?asset_id=0&client_id=0']:
        try:
            r=ctx.request.get(BASE+u+q,max_redirects=0,timeout=20000); t=r.text()[:30000]
            if r.status>=500 or any(x in t for x in ('Fatal error','Uncaught','Stack trace')): bad.append((u+q,r.status))
        except Exception as e: bad.append((u+q,'ERR'))
print(len(pages),'pages probed; BAD',len(bad))
for x in bad[:40]: print(x)
b.close()
