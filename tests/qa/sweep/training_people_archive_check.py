"""Training > People: archive / restore a person, Odoo-sync safety, eligibility, permissions, menu clipping. Exit 0 = pass."""
from common import *
import subprocess
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
def cdb(cid): return mysql(f"select ifnull(contact_archived_at,''), ifnull((select max(odoo_archive_hold) from contact_odoo_links where contact_id={cid}),'x') from contacts where contact_id={cid}")[0]
def sync(emp_id, name, email, active):
    r = subprocess.run(['php', os.path.join(HERE, 'odoo_hold_check.php'), APP, str(emp_id), name, email, '1' if active else '0'], capture_output=True, text=True)
    return r.stdout.strip().splitlines()[-1] if r.stdout.strip() else r.stderr[-300:]
# ---- fixtures: two Odoo-synced people (QA-UI-), one per department ----
def person(name, email, client, emp):
    mysql(f"delete from contact_odoo_links where odoo_employee_id={emp}")
    mysql(f"delete from contacts where contact_email='{email}'")
    mysql(f"insert into contacts (contact_name, contact_email, contact_client_id, contact_title) values ('{name}', '{email}', {client}, 'Welder')")
    cid = int(mysql(f"select contact_id from contacts where contact_email='{email}'")[0][0])
    mysql(f"insert into contact_odoo_links (contact_id, odoo_integration_id, odoo_employee_id) values ({cid}, 1, {emp})")
    return cid
A = person('QA-UI- Archive One', 'qa.archive1@example.test', 16, 99001)
B = person('QA-UI- Archive Two', 'qa.archive2@example.test', 17, 99002)
mysql("update user_settings set user_config_records_per_page=500 where user_id in (select user_id from users where user_email like '%summitridge.example')")
p = sync_playwright().start()
b, ctx, pg = login(p, 'admin'); pg.set_viewport_size({'width': 1920, 'height': 1080})
msgs = []; pg.on('console', lambda m: msgs.append(m.text) if m.type == 'error' else None)
def open_people(qs='q=QA-UI-'):
    pg.goto(BASE + '/agent/training_people.php?' + qs, wait_until='domcontentloaded'); pg.wait_for_selector('#tro-pr-body tr[data-id]', timeout=15000); time.sleep(.5)
def kebab(cid):
    pg.locator(f'#tro-pr-body tr[data-id="{cid}"] .tro-actions button[aria-label^="Actions for"]').click(); time.sleep(.3)
def menu_items(): return [t.strip() for t in pg.locator('#tro-pr-body .dropdown-menu.show .dropdown-item').all_inner_texts()]
open_people()
check('both fixture people are listed', pg.locator('#tro-pr-body tr[data-id]').count() == 2)
kebab(A); items = menu_items()
check('active person menu offers Archive…', any(i.startswith('Archive') for i in items) and not any(i.startswith('Restore') for i in items), items)
pg.locator('#tro-pr-body .dropdown-menu.show .dropdown-item', has_text='Archive').click(); time.sleep(.6)
check('Archive opens a confirmation naming the person and what happens', pg.locator('.modal.show, .offcanvas.show').count() >= 1 and 'QA-UI- Archive One' in pg.inner_text('body') and 'Nothing is deleted' in pg.inner_text('.modal.show, .offcanvas.show'))
pg.keyboard.press('Escape'); time.sleep(.6)
check('Esc cancels: nothing archived', cdb(A)[0] == '' and cdb(A)[1] == '0', cdb(A))
kebab(A); pg.locator('#tro-pr-body .dropdown-menu.show .dropdown-item', has_text='Archive').click(); time.sleep(.6)
pg.fill('.modal.show textarea, .modal.show input[name=reason], .offcanvas.show textarea, .offcanvas.show input[name=reason]', 'QA-UI- left the company')
btn = pg.locator('.modal.show button:has-text("Archive"), .offcanvas.show button:has-text("Archive")').last
btn.dblclick(); time.sleep(2)
st = cdb(A)
check('double-clicking Archive archives once', st[0] != '' and st[1] == '1', st)
check('audit log has exactly one Archive entry for the person', int(mysql(f"select count(*) from logs where log_type='Contact' and log_action='Archive' and log_entity_id={A}")[0][0]) == 1)
check('person left the default roster list', pg.locator(f'#tro-pr-body tr[data-id="{A}"]').count() == 0)
# Archived filter
pg.select_option('#tro-pr-state', 'archived') if pg.locator('#tro-pr-state option[value=archived]').count() else None
time.sleep(1.5)
check('Archived filter lists them with an Archived pill', pg.locator(f'#tro-pr-body tr[data-id="{A}"]').count() == 1 and 'Archived' in pg.inner_text(f'#tro-pr-body tr[data-id="{A}"]'), pg.locator('#tro-pr-body tr').count())
if out: pg.screenshot(path=f'{out}/people_archived_1920.png')
kebab(A); items = menu_items()
check('archived person menu offers Restore… and not Archive…', any(i.startswith('Restore') for i in items) and not any(i.startswith('Archive') for i in items), items)
pg.keyboard.press('Escape')
# ---- Odoo sync safety ----
r = sync(99001, 'QA-UI- Archive One', 'qa.archive1@example.test', True)
check('nightly Odoo sync (employee still active) does NOT un-archive a held person', cdb(A)[0] != '', (r, cdb(A)))
# restore through the UI
kebab(A); pg.locator('#tro-pr-body .dropdown-menu.show .dropdown-item', has_text='Restore').click(); time.sleep(.6)
pg.locator('.modal.show button:has-text("Restore"), .offcanvas.show button:has-text("Restore")').last.click(); time.sleep(2)
st = cdb(A); check('Restore clears the archive and the hold', st[0] == '' and st[1] == '0', st)
check('audit log has the Restore entry', int(mysql(f"select count(*) from logs where log_type='Contact' and log_action='Restore' and log_entity_id={A}")[0][0]) == 1)
# baseline (unchanged behaviour): a contact archived elsewhere, no hold, still active in Odoo -> the sync un-archives
mysql(f"update contacts set contact_archived_at=NOW() where contact_id={B}")
r = sync(99002, 'QA-UI- Archive Two', 'qa.archive2@example.test', True)
check('baseline: archived WITHOUT the hold is still re-activated by the sync (behaviour unchanged)', cdb(B)[0] == '', (r, cdb(B)))
r = sync(99002, 'QA-UI- Archive Two', 'qa.archive2@example.test', False)
check('baseline: Odoo-terminated people are still archived by the sync', cdb(B)[0] != '', (r, cdb(B)))
mysql(f"update contacts set contact_archived_at=NULL where contact_id={B}")
# ---- eligibility / assignments ----
open_people('q=Raj')
RAJ = 1
before_open = int(mysql(f"select count(*) from training_assignments where tassign_contact_id={RAJ} and tassign_status='open'")[0][0])
kebab(RAJ); pg.locator('#tro-pr-body .dropdown-menu.show .dropdown-item', has_text='Archive').click(); time.sleep(.6)
pg.locator('.modal.show button:has-text("Archive"), .offcanvas.show button:has-text("Archive")').last.click(); time.sleep(2.5)
after_open = int(mysql(f"select count(*) from training_assignments where tassign_contact_id={RAJ} and tassign_status='open'")[0][0])
check('archiving cancels the person\'s open assignments (they leave compliance)', before_open >= 1 and after_open == 0, (before_open, after_open))
elig = mysql(f"select count(*) from contacts c left join training_roster tr_r on tr_r.roster_contact_id=c.contact_id where c.contact_id={RAJ} and c.contact_archived_at is null")[0][0]
check('archived person is not eligible (same ELIGIBLE rule as the roster, kiosk and reports)', elig == '0')
kebab(RAJ) if pg.locator(f'#tro-pr-body tr[data-id="{RAJ}"]').count() else (pg.select_option('#tro-pr-state', 'archived'), time.sleep(1.5), kebab(RAJ))
pg.locator('#tro-pr-body .dropdown-menu.show .dropdown-item', has_text='Restore').click(); time.sleep(.6)
pg.locator('.modal.show button:has-text("Restore"), .offcanvas.show button:has-text("Restore")').last.click(); time.sleep(2.5)
reopened = int(mysql(f"select count(*) from training_assignments where tassign_contact_id={RAJ} and tassign_status='open'")[0][0])
check('restoring puts them back on the roster and their required training is assigned again', cdb(RAJ)[0] == '' and reopened >= 1, (cdb(RAJ), reopened))
# primary contact
open_people('q=Helen')
kebab(2); pg.locator('#tro-pr-body .dropdown-menu.show .dropdown-item', has_text='Archive').click(); time.sleep(.6)
pg.locator('.modal.show button:has-text("Archive"), .offcanvas.show button:has-text("Archive")').last.click(); time.sleep(1.5)
check('a department\'s primary contact cannot be archived (message shown, nothing changed)', cdb(2)[0] == '' and 'primary contact' in pg.inner_text('body').lower(), cdb(2))
pg.keyboard.press('Escape'); time.sleep(.5)
# ---- menu clipping: last row of a long list ----
open_people('')
n = pg.locator('#tro-pr-body tr[data-id]').count()
res = []
for (w, h) in ((1920, 1080), (1366, 768)):
    pg.set_viewport_size({'width': w, 'height': h}); time.sleep(.3)
    last = pg.locator('#tro-pr-body tr[data-id]').last; last.scroll_into_view_if_needed(); time.sleep(.3)
    last.locator('.tro-actions button[aria-label^="Actions for"]').click(); time.sleep(.5)
    r = pg.evaluate("() => { const m = document.querySelector('#tro-pr-body .dropdown-menu.show'); const b = m.getBoundingClientRect(); return {top: b.top, bottom: b.bottom, vh: innerHeight, right: b.right, vw: innerWidth} }")
    res.append(r); pg.keyboard.press('Escape'); pg.mouse.click(5, 5); time.sleep(.2)
    check(f'{w}x{h}: kebab menu on the last of {n} rows stays inside the viewport', r['top'] >= 0 and r['bottom'] <= r['vh'] and r['right'] <= r['vw'], r)
pg.set_viewport_size({'width': 1920, 'height': 1080})
# ---- tabs strip has no stray scrollbar fragment ----
sb = pg.evaluate("() => { const n = document.querySelector('.tro-tabs .nav'); return {v: n.scrollHeight - n.clientHeight, h: n.scrollWidth - n.clientWidth} }")
check('tab strip has no vertical scroll (stray scrollbar fragment)', sb['v'] <= 0, sb)
job = pg.evaluate("() => [...document.querySelectorAll('#tro-pr-body tr td:nth-child(3)')].filter(t => /No job position/.test(t.innerText)).length")
check('"No job position" is not repeated down the column', job == 0, job)
b.close()
# ---- permissions ----
bl2, cl2, pl2 = login(p, 'l2'); pl2.set_viewport_size({'width': 1920, 'height': 1080})
pl2.goto(BASE + '/agent/training_people.php?q=QA-UI-', wait_until='domcontentloaded'); time.sleep(2)
txt_items = pl2.evaluate("() => [...document.querySelectorAll('#tro-pr-body .dropdown-item')].map(i => i.textContent.trim())")
check('Training level 2 sees no Archive/Restore item', not any(t.startswith(('Archive', 'Restore')) for t in txt_items), txt_items[:6])
r = pl2.evaluate("(id) => window.TrainingApi.post('roster_archive', {contact_id: id, archived: true}).then(() => 'ok', e => (e.status + ':' + e.code))", B)
check('Training level 2 cannot archive through the endpoint', r != 'ok' and cdb(B)[0] == '', r)
bl2.close()
# level 3 restricted to department A: may archive A's people, B's person is not found
l3 = mysql("select user_id from users where user_email='qa.l3@summitridge.example'")[0][0]
mysql(f"delete from user_client_permissions where user_id={l3}"); mysql(f"insert into user_client_permissions (user_id, client_id) values ({l3}, 16)")
bl3, cl3, pl3 = login(p, 'l3'); pl3.set_viewport_size({'width': 1920, 'height': 1080})
pl3.goto(BASE + '/agent/training_people.php?q=QA-UI-', wait_until='domcontentloaded'); time.sleep(2)
r = pl3.evaluate("(id) => window.TrainingApi.post('roster_archive', {contact_id: id, archived: true}).then(() => 'ok', e => (e.status + ':' + e.code))", B)
check('Training L3 limited to department A cannot archive a person of department B', r != 'ok' and cdb(B)[0] == '', r)
r = pl3.evaluate("(id) => window.TrainingApi.post('roster_archive', {contact_id: id, archived: true}).then(() => 'ok', e => (e.status + ':' + e.code))", A)
check('Training L3 limited to department A can archive a person of department A', r == 'ok' and cdb(A)[0] != '', (r, cdb(A)))
mysql(f"update contacts set contact_archived_at=NULL where contact_id={A}"); mysql(f"update contact_odoo_links set odoo_archive_hold=0 where contact_id={A}")
mysql(f"delete from user_client_permissions where user_id={l3}")
bl3.close()
check('no console errors (the one expected 422 for the primary contact is allowed)', not [m for m in msgs if 'favicon' not in m and '422' not in m], msgs[:3])
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
