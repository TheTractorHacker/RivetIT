"""usage: shots.py <who> <WxH> <label> path... -> out/<label>_<slug>.png + hscroll report"""
from ux_common import *
who=sys.argv[1]; w,h=map(int,sys.argv[2].split('x')); label=sys.argv[3]; paths=sys.argv[4:]
portal = who=='portal'
p,b,ctx,pg=start(w,h,who,portal)
for path in paths:
    pg.goto(BASE+path); pg.wait_for_load_state('load'); time.sleep(.8)
    hs=pg.evaluate("document.documentElement.scrollWidth>document.documentElement.clientWidth+2")
    n=path.strip('/').replace('/','_').replace('.php','').replace('?','_').replace('=','')
    pg.screenshot(path=f"{OUT}/{label}_{n}.png", full_page=False)
    print(path, 'hscroll' if hs else 'ok', pg.evaluate("[document.querySelectorAll('h1,[role=heading][aria-level=\"1\"]').length, !!document.querySelector('main')]"))
b.close()
