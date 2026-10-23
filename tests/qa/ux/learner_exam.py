from ux_common import *
import re
p,b,ctx,pg=start(who='portal',portal=True); clicks=1
pg.goto(BASE+'/client/training.php'); pg.wait_for_load_state('domcontentloaded'); time.sleep(.8)
pg.locator("main >> text=Forklift Safety Refresher").first.click(); clicks+=1; time.sleep(3)
c=ctx.pages[-1]; c.wait_for_load_state('domcontentloaded'); time.sleep(1)
for b_ in ["Start course","Continue","Resume"]:
    x=c.locator(f"button:has-text('{b_}'):visible, a:has-text('{b_}'):visible").first
    if x.count(): x.click(); clicks+=1; time.sleep(2); break
print(c.url[-60:], c.evaluate("document.body.innerText.slice(0,200)").replace('\n',' | '))
c.screenshot(path=f'{OUT}/learner_step1.png')
c.locator("button:has-text('Start the exam')").click(); clicks+=1; time.sleep(2)
print(c.evaluate("[...document.querySelectorAll('button,[role=radio],[role=checkbox],input,label')].filter(e=>e.offsetParent).map(e=>e.tagName+'.'+(e.className||'').toString().slice(0,34)+':'+(e.textContent||e.value||'').trim().slice(0,22))")[:30])
for q in range(9):
    opt=c.locator("[role=radio]:visible, [role=checkbox]:visible, button.trp-opt:visible, label.trp-opt:visible, input[type=radio]:visible").first
    if opt.count(): opt.click(); clicks+=1; time.sleep(.5)
    nxt=c.locator("button:has-text('Next question'):visible, button:has-text('Submit'):visible, button:has-text('Finish'):visible").first
    if not nxt.count(): print('no next at q',q); break
    t=nxt.inner_text().strip(); nxt.click(); clicks+=1; time.sleep(1.2)
    if re.search('Submit|Finish', t): break
c.locator("button:has-text('Review answers')").click(); clicks+=1; time.sleep(1.5)
c.screenshot(path=f'{OUT}/learner_review.png', full_page=True)
print('review controls', [t.strip()[:30] for t in c.locator('button:visible').all_text_contents() if t.strip()])
sub=c.locator("button:has-text('Submit'), button:has-text('Finish'), button:has-text('Hand in')").first
sub.click(); clicks+=1; time.sleep(2)
cf=c.locator("button:has-text('Yes'), button:has-text('Confirm'), button:has-text('Submit')").last
if cf.count() and cf.is_visible(): cf.click(); clicks+=1; time.sleep(3)
time.sleep(2); c.screenshot(path=f'{OUT}/learner_result.png', full_page=True)
print('RESULT:', c.evaluate("document.body.innerText.slice(0,500)").replace('\n',' | '))
print('clicks', clicks)
