from ux_common import *
p,b,ctx,pg=start(who='admin')
pg.goto(BASE+'/agent/training_locked.php'); pg.wait_for_load_state('load'); time.sleep(1)
row=pg.locator("tr:has-text('Sophie Tran'), .card:has-text('Sophie Tran')").first
print('rows', pg.locator('tr:has-text("Sophie Tran")').count(), [t.strip()[:30] for t in pg.locator("button:visible, a.btn:visible").all_text_contents() if t.strip()][:14])
pg.locator("button:has-text('Give 1 more try')").first.click(); time.sleep(1.2)
pg.fill("input[placeholder^='Why one more try']", 'QA-UI- reviewed the material'); pg.locator("button:has-text('Give 1 more try')").first.click()
time.sleep(2); print('toast', [t.strip()[:100] for t in pg.locator('.toast,.alert').all_text_contents() if t.strip()][:2])
b.close()
