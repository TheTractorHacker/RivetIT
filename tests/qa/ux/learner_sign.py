from ux_common import *
p,b,ctx,pg=start(who='portal',portal=True)
pg.goto(BASE+'/client/training.php'); pg.wait_for_load_state('domcontentloaded'); time.sleep(.8)
pg.locator("main >> text=Forklift Safety Refresher").first.click(); time.sleep(3)
c=ctx.pages[-1]; c.wait_for_load_state('domcontentloaded'); time.sleep(1.5)
print([t.strip()[:28] for t in c.locator('button:visible, a.btn:visible').all_text_contents() if t.strip()][:12])
for n in ["Sign to finish","Sign"]:
    x=c.locator(f"button:has-text('{n}'):visible").first
    if x.count(): x.click(); time.sleep(1.5); break
c.screenshot(path=f'{OUT}/learner_sign.png')
print(c.evaluate("document.body.innerText.slice(0,500)").replace('\n',' | '))
print('inputs', c.evaluate("[...document.querySelectorAll('input:not([type=hidden])')].filter(e=>e.offsetParent).map(e=>e.type+':'+(e.name||e.id)+':'+(e.placeholder||''))"))
b.close()
