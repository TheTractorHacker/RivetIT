"""Learner passes the exam using the correct answers (read from the DB export out/answers.jsonl) and opens the certificate."""
from ux_common import *
import re
ans=[json.loads(l) for l in open(os.path.join(OUT,'answers.jsonl')) if l.startswith('{')]
tf=[json.loads(l) for l in open(os.path.join(OUT,'tf.jsonl')) if l.startswith('{')]
p,b,ctx,pg=start(who='portal',portal=True); clicks=1
pg.goto(BASE+'/client/training.php'); pg.wait_for_load_state('domcontentloaded'); time.sleep(.8)
pg.locator("main >> text=Forklift Safety Refresher").first.click(); clicks+=1; time.sleep(3)
c=ctx.pages[-1]; c.wait_for_load_state('domcontentloaded'); time.sleep(1)
for name in ["Try again","Retry","Start the exam","Resume","Continue","Start course"]:
    x=c.locator(f"button:has-text('{name}'):visible").first
    if x.count(): print('click',name); x.click(); clicks+=1; time.sleep(1.5); break
print([t.strip()[:28] for t in c.locator('button:visible, a.btn:visible').all_text_contents() if t.strip()][:12]); print(c.evaluate('document.body.innerText.slice(0,200)').replace('\\n',' | '))
x=c.locator("button:has-text('Start the exam'):visible").first
if x.count(): x.click(); clicks+=1; time.sleep(2)
for q in range(8):
    txt=c.evaluate("document.body.innerText")
    m=[a for a in ans if a['q'][:30] in txt]
    right={x['a'].strip() for x in m if x['q'][:30] in txt}
    opts=c.locator("button.trp-opt:visible"); n=opts.count()
    if n==0: break
    for t_ in [x for x in tf if x['q'][:30] in txt]:
        opts.nth(int(t_['s'])).click(); clicks+=1; time.sleep(.3)
    for i in range(n):
        t=re.sub(r'^[A-H]\s*','',opts.nth(i).inner_text().strip()).strip()
        if any(t.startswith(r[:30]) or r.startswith(t[:30]) for r in right): opts.nth(i).click(); clicks+=1; time.sleep(.3)
    nxt=c.locator("button:has-text('Next question'):visible, button:has-text('Review answers'):visible").first
    if not nxt.count(): break
    t=nxt.inner_text(); nxt.click(); clicks+=1; time.sleep(1)
    if 'Review' in t: break
c.locator("button:has-text('Submit')").first.click(); clicks+=1; time.sleep(3)
c.screenshot(path=f'{OUT}/learner_pass.png', full_page=True)
print('RESULT:', c.evaluate("document.body.innerText.slice(0,1500)").replace('\n',' | '))
cert=c.locator("a:has-text('certificate'), a:has-text('Certificate'), button:has-text('certificate')").first
print('certificate control:', cert.count())
if cert.count():
    href=cert.get_attribute('href'); print('cert href', href)
    if href:
        r=ctx.request.get(href if href.startswith('http') else BASE+href); print('cert fetch', r.status, r.headers.get('content-type'), len(r.body()))
print('clicks',clicks); b.close()
