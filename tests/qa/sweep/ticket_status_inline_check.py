"""Ticket detail: the header status pill changes status inline (dropdown, no modal), keeps server behaviour. Exit 0 = pass."""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:220] if detail and not ok else ''))
    if not ok: fails.append(name)
def status(tid): return mysql(f"select ticket_status, ifnull(ticket_closed_at,'') from tickets where ticket_id={tid}")[0]
def hist(tid): return int(mysql(f"select count(*) from ticket_history where ticket_history_ticket_id={tid}")[0][0])
TID = 248
mysql(f"update tickets set ticket_status=2, ticket_closed_at=NULL, ticket_resolved_at=NULL where ticket_id={TID}")
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    for (w, h) in ((1920, 1080), (1366, 768)):
        tag = f'{theme} {w}x{h}'
        b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h})
        msgs = []; pg.on('console', lambda m: msgs.append(m.text) if m.type == 'error' else None)
        mysql(f"update tickets set ticket_status=2, ticket_closed_at=NULL, ticket_resolved_at=NULL where ticket_id={TID}")
        pg.goto(BASE + f'/agent/ticket.php?ticket_id={TID}', wait_until='domcontentloaded'); time.sleep(2)
        check(f'{tag}: status control is a dropdown, not a modal trigger', pg.locator('#ticketStatusPill').count() == 1 and pg.locator('a[data-modal-url*="ticket_status.php"]').count() == 0)
        check(f'{tag}: pill has an accessible name', 'Change status' in (pg.get_attribute('#ticketStatusPill', 'aria-label') or ''))
        h0 = hist(TID)
        pg.click('#ticketStatusPill'); time.sleep(.3)
        check(f'{tag}: menu opens and lists the active statuses', pg.locator('#ticketStatusMenu .ticket-status-set').count() == 7, pg.locator('#ticketStatusMenu .ticket-status-set').count())
        if out and w == 1920: pg.screenshot(path=f'{out}/status_menu_{theme}.png', clip={'x': 0, 'y': 80, 'width': 900, 'height': 420})
        pg.click('#ticketStatusMenu .ticket-status-set[data-status-id="3"]'); time.sleep(1.2)
        check(f'{tag}: no modal opened', pg.locator('.modal.show').count() == 0)
        check(f'{tag}: pill text and server state changed to On Hold', pg.inner_text('#ticketStatusPill .ticket-status-label').strip() == 'On Hold' and status(TID)[0] == '3', (pg.inner_text('#ticketStatusPill'), status(TID)))
        check(f'{tag}: success toast shown', pg.locator('.toast-message, .toast-body, #toast-container').count() > 0)
        check(f'{tag}: one history entry written, reply-form status synced', hist(TID) == h0 + 1 and pg.input_value('#reply_status_val') == '3', (h0, hist(TID)))
        pg.reload(wait_until='domcontentloaded'); time.sleep(1.5)
        check(f'{tag}: change persists after reload', pg.inner_text('#ticketStatusPill').strip() == 'On Hold')
        # same-status click is a no-op; rapid double change writes once per distinct target
        pg.click('#ticketStatusPill'); time.sleep(.2)
        pg.click('#ticketStatusMenu .ticket-status-set[data-status-id="3"]'); time.sleep(.5)
        check(f'{tag}: choosing the current status does nothing', hist(TID) == h0 + 1)
        pg.keyboard.press('Escape'); time.sleep(.2)
        pg.focus('#ticketStatusPill'); pg.keyboard.press('Enter'); time.sleep(.3)
        check(f'{tag}: keyboard opens the menu', pg.locator('#ticketStatusMenu .dropdown-menu.show').count() == 1)
        pg.keyboard.press('Escape'); time.sleep(.2)
        # closing status needs an inline second click and keeps deliberate Resolved -> closed behaviour
        pg.click('#ticketStatusPill'); time.sleep(.2)
        pg.click('#ticketStatusMenu .ticket-status-set[data-status-id="4"]'); time.sleep(.5)
        check(f'{tag}: closing status asks for an inline confirmation first (menu stays open, nothing saved)', pg.locator('#ticketStatusMenu .dropdown-menu.show').count() == 1 and status(TID)[0] == '3' and 'confirm' in pg.inner_text('#ticketStatusMenu .ticket-status-set[data-status-id="4"]'))
        pg.click('#ticketStatusMenu .ticket-status-set[data-status-id="4"]'); time.sleep(2)
        st = status(TID)
        check(f'{tag}: Resolved still closes the ticket, page reloads without the control', st[0] == '5' and st[1] != '' and pg.locator('#ticketStatusPill').count() == 0, st)
        check(f'{tag}: no console errors', not [m for m in msgs if 'favicon' not in m], msgs[:3])
        b.close()
# permission: a user with module_support < 2 cannot change status via the endpoint
mysql("insert into user_roles (role_name,role_description,role_is_admin) select 'QA-UI-ReadOnly','qa',0 from dual where not exists (select 1 from user_roles where role_name='QA-UI-ReadOnly')")
rid = mysql("select role_id from user_roles where role_name='QA-UI-ReadOnly'")[0][0]
mysql(f"delete from user_role_permissions where user_role_id={rid}")
mysql(f"insert into user_role_permissions select {rid},module_id,1 from modules where module_name in ('module_support','module_client')")
mysql(f"insert into users (user_name,user_email,user_password,user_status,user_role_id,user_type) select 'QA-UI- ReadOnly','qa.ro@summitridge.example',user_password,1,{rid},1 from users where user_id=10 and not exists (select 1 from users where user_email='qa.ro@summitridge.example')")
mysql("insert into user_settings (user_id) select user_id from users where user_email='qa.ro@summitridge.example' and user_id not in (select user_id from user_settings)")
USERS['ro'] = ('qa.ro@summitridge.example', 'DemoPass#2026')
mysql(f"update tickets set ticket_status=2, ticket_closed_at=NULL where ticket_id={TID}")
b, ctx, pg = login(p, 'ro')
pg.goto(BASE + f'/agent/ticket.php?ticket_id={TID}', wait_until='domcontentloaded'); time.sleep(1.5)
check('read-only user sees a plain status badge, no dropdown', pg.locator('#ticketStatusPill').count() == 0)
csrf = pg.evaluate("() => (document.querySelector('[data-csrf]')||document.querySelector('input[name=csrf_token]')).getAttribute('data-csrf') || document.querySelector('input[name=csrf_token]').value")
r = pg.evaluate("async (c) => { const f = new URLSearchParams({quick_status_ticket:1,ticket_id:%d,ticket_status_id:3,csrf_token:c}); const x = await fetch('/agent/post.php',{method:'POST',body:f,headers:{'Content-Type':'application/x-www-form-urlencoded'}}); return x.status + ' ' + (await x.text()).slice(0,80) }" % TID, csrf)
check('read-only user cannot change status through the endpoint', status(TID)[0] == '2', r)
b.close()
mysql(f"update tickets set ticket_status=2, ticket_closed_at=NULL, ticket_resolved_at=NULL, ticket_closed_by=0 where ticket_id={TID}")   # leave the fixture ticket open for the other scripts
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
