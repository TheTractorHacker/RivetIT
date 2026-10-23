from ux_common import *
p,b,ctx,pg=start(who='scratch')
errs=[]; pg.on('pageerror', lambda e: errs.append(str(e)[:90]))
paths=[l.strip() for l in open(os.path.join(HERE,'pages_rmm.txt')) if l.strip()]
for u in paths:
    errs.clear(); t=time.time()
    try:
        r=pg.goto(BASE+u,timeout=25000); pg.wait_for_load_state('load',timeout=20000); time.sleep(.5)
        v=axe(pg); txt=pg.evaluate("document.querySelector('main')?.innerText.slice(0,60).replace(/\\n/g,' ')")
        print(r.status, u, f'{time.time()-t:.1f}s', [(x['id'],x['n']) for x in v], errs[:1], '|', txt, flush=True)
    except Exception as e: print('ERR',u,str(e)[:70], flush=True)
b.close()
