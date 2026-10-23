"""Technician persona remaining tasks (navigation only, click counts)."""
from ux_common import *
p,b,ctx,pg=start(who='tech'); 
def go(label, steps):
    n=0
    for sel in steps:
        pg.locator(sel).first.click(); n+=1; pg.wait_for_load_state('domcontentloaded'); time.sleep(.7)
    print(f'{label}: {n} clicks -> {pg.url[-55:]}'); return n
# find an asset and see history
go('Asset page', ["nav a:has-text('Infrastructure'), a.nav-link:has-text('Infrastructure')", "a[href$='assets.php']"])
pg.locator("table tbody tr a[href*='asset_details']").first.click(); time.sleep(1.2); print('asset opened', pg.url[-50:])
tabs=[t.strip() for t in pg.locator("[data-bs-toggle=tab], [data-bs-toggle=pill], .nav-tabs a").all_text_contents() if t.strip()]; print('asset tabs/sections', tabs[:14])
pg.screenshot(path=f'{OUT}/tech_asset_detail.png', full_page=True)
print('history words:', pg.locator("text=/history|activity|log/i").count())
# contact call/email
pg.goto(BASE+'/agent/dashboard.php')
go('Contacts page', ["a.nav-link:has-text('Organization')", "a[href$='contacts.php']"])
print('contact tel/mailto links on first row:', pg.locator("table tbody tr:first-child a[href^='tel:'], table tbody tr:first-child a[href^='mailto:']").count())
# filter my tickets by priority
pg.goto(BASE+'/agent/tickets.php'); pg.wait_for_load_state('load'); time.sleep(.8)
pg.locator("button:has-text('Priority'), a:has-text('Priority')").first.click(); time.sleep(.5); pg.screenshot(path=f'{OUT}/tech_priority_filter.png')
print('priority options', [t.strip() for t in pg.locator('.dropdown-menu.show label, .dropdown-menu.show a, .dropdown-menu.show .form-check-label').all_text_contents() if t.strip()][:6])
pg.keyboard.press('Escape')
# bulk: tick 3 rows
boxes=pg.locator("table tbody input[type=checkbox]"); print('row checkboxes', boxes.count())
for i in range(3): boxes.nth(i).check()
time.sleep(.6); pg.screenshot(path=f'{OUT}/tech_bulk.png')
print('bulk controls visible:', [t.strip()[:25] for t in pg.locator("button:visible, a.btn:visible").all_text_contents() if re.search('bulk|assign|status|merge|close|resolve|priority', t, re.I)][:8]) if (re:=__import__('re')) else 0
b.close()
