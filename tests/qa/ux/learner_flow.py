"""Training learner (portal user): find assigned course, open it, step through, see completion."""
from ux_common import *
import re
p,b,ctx,pg=start(who='portal',portal=True); clicks=1
pg.locator("a:has-text('Training')").first.click(); clicks+=1; pg.wait_for_load_state('domcontentloaded'); time.sleep(1)
pg.screenshot(path=f'{OUT}/learner_training.png')
el=pg.locator("main >> text=Forklift Safety Refresher").first
print('el', el.evaluate("e=>e.closest('a,button,form')?.outerHTML.slice(0,200)"))
el.click(); clicks+=1; time.sleep(4)
pgs=ctx.pages; c=pgs[-1]; print('pages', [x.url[-70:] for x in pgs])
c.wait_for_load_state('domcontentloaded'); print('title', c.title()[:60]); c.screenshot(path=f'{OUT}/learner_course.png', full_page=True)
print('controls', [t.strip()[:30] for t in c.locator('button, a.btn, input[type=submit]').all_text_contents() if t.strip()][:14])
seen=[]
names=["Start course","Start","Mark as read","Mark complete","Complete lesson","Complete","Continue","Next","Start exam","Begin","Submit","Finish","Done reading","I have read"]
for step in range(40):
    time.sleep(1.2)
    # answer any visible question: pick first option of each group
    c.evaluate("""()=>{const seen=new Set(); document.querySelectorAll('input[type=radio]').forEach(r=>{ if(!seen.has(r.name)){ seen.add(r.name); r.click(); } }); document.querySelectorAll('input[type=checkbox][data-q],input[type=checkbox].q').forEach(x=>{}); }""")
    done=False
    for n in names:
        btn=c.locator(f"main button:has-text('{n}'):visible, main a.btn:has-text('{n}'):visible, button.btn-primary:has-text('{n}'):visible, a.btn-primary:has-text('{n}'):visible").first
        if btn.count():
            t=btn.inner_text().strip()[:30]; seen.append(t); btn.click(); clicks+=1; done=True; break
    txt=c.evaluate("document.body.innerText.slice(0,600)")
    if re.search(r'passed|congratulations|certificate|you completed|all done', txt, re.I): print('COMPLETE signal at step', step); break
    if not done: print('no actionable button at step', step); break
print('steps', seen); c.screenshot(path=f'{OUT}/learner_end.png', full_page=True); print('end text:', c.evaluate("document.body.innerText.slice(0,300)").replace('\n',' | '))
print('clicks', clicks); b.close()
