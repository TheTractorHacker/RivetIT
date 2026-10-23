from ux_common import *
p,b,ctx,pg=start(who='admin')
pg.goto(BASE+'/agent/training_devices.php'); pg.wait_for_load_state('load'); time.sleep(1)
pg.locator("button:has-text('People & PINs')").first.click(); time.sleep(1.2)
pg.fill("input[type=search]:visible, input[placeholder*='earch']:visible", 'Sophie'); time.sleep(1.2)
print([t.strip()[:30] for t in pg.locator('button:visible, a.btn:visible').all_text_contents() if t.strip()][:24])
pg.screenshot(path=f'{OUT}/pins.png')
b.close()
