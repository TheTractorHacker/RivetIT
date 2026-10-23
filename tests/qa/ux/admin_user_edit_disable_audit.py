"""Admin persona: edit + disable (archive) QA-UI- users, then find the activity in the audit log."""
from ux_common import *
p,b,ctx,pg=start(who='admin'); clicks=1
def msg(): return [t.strip()[:110] for t in pg.locator('.toast, .alert').all_text_contents() if t.strip()][:2]
pg.goto(BASE+'/admin/users.php'); pg.wait_for_load_state('load'); time.sleep(.5)
row=pg.locator("tr:has-text('qa-ui-user@example.test')").first
row.locator("a:has-text('Edit'), button:has-text('Edit')").first.click(); clicks+=1; time.sleep(1.2)
pg.fill('.modal.show input[name=name]','QA-UI- Test User (edited)')
pg.locator('.modal.show button[name=edit_user]').click(); clicks+=1; time.sleep(2); print('edit   ', msg())
pg.goto(BASE+'/admin/users.php'); pg.wait_for_load_state('load')
row=pg.locator("tr:has-text('qa-ui-user@example.test')").first
row.locator("button[data-bs-toggle=dropdown]").first.click(); clicks+=1; time.sleep(.4)
pg.screenshot(path=f'{OUT}/admin_user_row_menu.png')
row.locator(".dropdown-menu a:has-text('Disable')").first.click(); clicks+=1; time.sleep(1)
pg.screenshot(path=f'{OUT}/admin_user_disable_confirm.png')
pg.locator('.modal.show button:has-text("Yes"), .modal.show button:has-text("Confirm"), .modal.show button:has-text("Disable"), .modal.show .btn-danger').first.click(); clicks+=1; time.sleep(2); print('disable', msg())
pg.goto(BASE+'/admin/users.php'); pg.wait_for_load_state('load')
print('row status:', pg.locator("tr:has-text('qa-ui-user@example.test') td").all_text_contents()[3:4])
# audit log lookup
pg.goto(BASE+'/admin/'); 
for sel in ["a:has-text('Audit')","a:has-text('Logs')"]:
    print(sel, pg.locator(sel).count())
pg.goto(BASE+'/admin/audit_log.php'); pg.wait_for_load_state('load'); time.sleep(.8)
print('audit url', pg.url, 'rows', pg.locator('table tbody tr').count())
pg.screenshot(path=f'{OUT}/admin_audit_log.png', full_page=False)
q=pg.locator("input[name=q], input[type=search]").first
q.fill('QA-UI-'); q.press('Enter'); pg.wait_for_load_state('load'); time.sleep(1)
print('audit search rows', pg.locator('table tbody tr').count(), [t[:100] for t in pg.locator('table tbody tr').all_text_contents()[:3]])
pg.screenshot(path=f'{OUT}/admin_audit_log_search.png')
print('clicks',clicks)
b.close()
