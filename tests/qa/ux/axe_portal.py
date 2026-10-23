from ux_common import *
p,b,ctx,pg=start(who='portal',portal=True)
paths=[l.strip() for l in open(os.path.join(HERE,'pages_portal.txt')) if l.strip()]
miss=[]
for u in paths:
    pg.goto(BASE+u); pg.wait_for_load_state('domcontentloaded'); time.sleep(.6)
    v=axe(pg,['page-has-heading-one','landmark-one-main'])
    if v: miss.append((u,[x['id'] for x in v]))
print(len(paths),'portal pages; landmark/h1 violations:',miss)
b.close()
