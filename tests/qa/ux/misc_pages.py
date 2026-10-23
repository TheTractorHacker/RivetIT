"""Guest / kiosk / SSO+MFA pages: status, h1/main, axe (all rules), console errors, forms present."""
from ux_common import *
import re
who=sys.argv[1] if len(sys.argv)>1 else 'admin'
p,b,ctx,pg=start(who=who)
errs=[]; pg.on('pageerror', lambda e: errs.append(str(e)[:100]))
for u in [l.strip() for l in open(os.path.join(HERE,'pages_misc.txt')) if l.strip()]:
    errs.clear()
    try:
        r=pg.goto(BASE+u,timeout=20000); pg.wait_for_load_state('load'); time.sleep(.7)
        v=axe(pg); ids=sorted({x['id'] for x in v})
        info=pg.evaluate("[document.querySelectorAll('main').length, document.querySelectorAll('h1,[role=heading][aria-level=\"1\"]').length, document.querySelectorAll('form').length, document.body.innerText.slice(0,70).replace(/\\n/g,' ')]")
        print(r.status, u[:60], info, 'axe:', ids, 'jserr:', errs[:1], flush=True)
    except Exception as e: print('ERR', u[:60], str(e)[:80])
b.close()
