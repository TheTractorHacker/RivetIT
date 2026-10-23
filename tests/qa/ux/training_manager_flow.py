"""Training manager: assign a course to a person, then export compliance CSV."""
from ux_common import *
p,b,ctx,pg=start(who='admin'); clicks=1
def toast(): return [t.strip()[:110] for t in pg.locator('.toast, .alert').all_text_contents() if t.strip()][:2]
pg.goto(BASE+'/agent/training.php'); pg.wait_for_load_state('load'); 
pg.locator("a:has-text('Assignments')").first.click(); clicks+=1; pg.wait_for_load_state('load'); time.sleep(.8)
pg.screenshot(path=f'{OUT}/training_assignments.png')
print('buttons:', [t.strip() for t in pg.locator('main a.btn, main button.btn').all_text_contents() if t.strip()][:12])
pg.locator("main a:has-text('Assign training'), main button:has-text('Assign training')").first.click(); clicks+=1; time.sleep(1.5)
pg.screenshot(path=f'{OUT}/training_assign_modal.png')
def assign(idx):
    global clicks
    pg.goto(BASE+'/agent/training_assignments.php'); pg.wait_for_load_state('load'); time.sleep(.6)
    pg.locator("main a:has-text('Assign training'), main button:has-text('Assign training')").first.click(); clicks+=1; time.sleep(1.2)
    pg.fill("input[placeholder='Type a name, title or department']", 'Sophie'); time.sleep(1)
    pg.locator("[role=option], .trq-option, li:has-text('Sophie Tran')").first.click(); clicks+=1
    sel=pg.locator("select:has(option:has-text('Choose a course'))").first
    sel.select_option(index=idx); clicks+=1
    pg.locator("button:has-text('In 14 days')").click(); clicks+=1
    pg.locator("button:has-text('Assign')").last.click(); clicks+=1; time.sleep(2.5); return toast()
for idx in range(1,6):
    t=assign(idx); print('assign course idx', idx, t)
    if t and not any('No one' in x for x in t): break
pg.fill("input[placeholder*='Search people']", 'Sophie'); time.sleep(1.5)
print('rows for Sophie:', pg.locator('table tbody tr').count())
pg.screenshot(path=f'{OUT}/training_assign_done.png')
# compliance export
pg.goto(BASE+'/agent/training.php'); pg.wait_for_load_state('load')
with pg.expect_download(timeout=15000) as dl: pg.locator("a:has-text('Export CSV'), button:has-text('Export CSV')").first.click(); clicks+=1
d=dl.value; path=f'{OUT}/compliance_export.csv'; d.save_as(path); txt=open(path).read(); print('export', d.suggested_filename, len(txt), 'bytes', txt.splitlines()[:2])
print('clicks', clicks)
b.close()
