"""Assets list filters respect department access: a user restricted to department A cannot read names or rows of department B through ids in the URL. Exit 0 = pass."""
from common import *
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
# two departments that each own an asset with a location and an assigned contact
rows = mysql("select a.asset_client_id, a.asset_location_id, a.asset_contact_id, a.asset_id, c.client_name, l.location_name, ct.contact_name from assets a join clients c on c.client_id=a.asset_client_id join locations l on l.location_id=a.asset_location_id join contacts ct on ct.contact_id=a.asset_contact_id where a.asset_archived_at is null and a.asset_location_id>0 and a.asset_contact_id>0 and c.client_archived_at is null")
byc = {}
for r in rows: byc.setdefault(r[0], r)
assert len(byc) >= 2, 'need two departments with located, assigned assets: ' + str(rows)
(ida, A), (idb, B) = list(byc.items())[:2]
import html as _h
# a location owned by department B alone (the demo's locations are all company-wide, which every user may see)
mysql(f"insert into locations (location_name, location_client_id, location_country) select 'QA-UI- Private B Room', {idb}, '' from dual where not exists (select 1 from locations where location_name='QA-UI- Private B Room')")
lb = mysql("select location_id from locations where location_name='QA-UI- Private B Room'")[0][0]
mysql(f"update assets set asset_location_id={lb} where asset_id={B[3]}")
B = list(B); B[1] = lb; B[5] = 'QA-UI- Private B Room'
for k in (A[4], B[4]): pass
roleid = mysql("select user_role_id from users where user_email='priya.nair@summitridge.example'")[0][0]
mysql(f"insert into users (user_name,user_email,user_password,user_status,user_role_id,user_type) select 'QA-UI- Restricted','qa.restricted@summitridge.example',user_password,1,{roleid},1 from users where user_email='priya.nair@summitridge.example' and not exists (select 1 from users where user_email='qa.restricted@summitridge.example')")
uid = mysql("select user_id from users where user_email='qa.restricted@summitridge.example'")[0][0]
mysql(f"insert into user_settings (user_id) select {uid} from dual where not exists (select 1 from user_settings where user_id={uid})")
mysql(f"update user_settings set user_config_records_per_page=500 where user_id={uid}")
mysql(f"delete from user_client_permissions where user_id={uid}")
mysql(f"insert into user_client_permissions (user_id, client_id) values ({uid}, {ida})")
USERS['restricted'] = ('qa.restricted@summitridge.example', 'DemoPass#2026')
p = sync_playwright().start()
def page_html(pg, qs):
    r = pg.goto(BASE + '/agent/assets.php?' + qs, wait_until='domcontentloaded'); time.sleep(.6)
    return r.status, pg.content(), pg.inner_text('body')
bad = re.compile(r'Warning:|Notice:|Fatal error|Undefined (variable|array key|index)|mysqli', re.I)
b, ctx, pg = login(p, 'restricted'); pg.set_viewport_size({'width': 1920, 'height': 1080})
st, html, txt = page_html(pg, f'client={idb}')
check('restricted: department B id in ?client= shows no B name, no B rows, no warnings', st == 200 and _h.escape(B[4]) not in html and not bad.search(txt) and pg.locator('.filter-chip').count() == 0, (st, pg.locator('.filter-chip').all_inner_texts()))
st, html, txt = page_html(pg, f'location={B[1]}')
check('restricted: location id of B reveals no location name and applies no filter', st == 200 and _h.escape(B[5]) not in html and pg.locator('.filter-chip').count() == 0 and not bad.search(txt), B[5])
st, html, txt = page_html(pg, f'assigned={B[2]}')
check('restricted: contact id of B reveals no contact name and applies no filter', st == 200 and _h.escape(B[6]) not in html and pg.locator('.filter-chip').count() == 0 and not bad.search(txt), B[6])
n_all = pg.locator('.filter-summary strong').first.inner_text()
n_assets_a = int(mysql(f"select count(*) from assets where asset_client_id={ida} and asset_archived_at is null and asset_type!='Firewall/Router'")[0][0])
check('restricted: an inaccessible filter id does not widen or narrow the list (still only A rows)', int(n_all) == n_assets_a, (n_all, n_assets_a))
st, html, txt = page_html(pg, f'client={ida}&location={A[1]}&assigned={A[2]}')
check('restricted: own department filters still show chips with names', pg.locator('.filter-chip').count() == 3 and _h.escape(A[4]) in html and _h.escape(A[5]) in html and _h.escape(A[6]) in html, pg.locator('.filter-chip').count())
st, html, txt = page_html(pg, '')
dd = pg.evaluate("() => [...document.querySelectorAll('select option')].map(o => o.textContent)")
leak = [x for x in dd if B[4] in x or B[5] in x or B[6] in x]
check('restricted: dropdown choices contain nothing from department B', not leak, leak[:3])
check('restricted: no rows from department B in the list', B[4] not in pg.inner_text('.assets-table') and pg.locator('.assets-table tbody tr').count() == n_assets_a, (pg.locator('.assets-table tbody tr').count(), n_assets_a))
r = pg.goto(BASE + f'/agent/assets.php?client_id={idb}', wait_until='domcontentloaded'); time.sleep(.5)
check('restricted: direct department scope of B is refused (no B name)', B[4] not in pg.content())
b.close()
b, ctx, pg = login(p, 'admin'); pg.set_viewport_size({'width': 1920, 'height': 1080})
st, html, txt = page_html(pg, f'client={idb}&location={B[1]}&assigned={B[2]}')
check('admin: sees department B names in chips and rows', st == 200 and pg.locator('.filter-chip').count() == 3 and _h.escape(B[4]) in html and _h.escape(B[5]) in html and _h.escape(B[6]) in html and not bad.search(txt), pg.locator('.filter-chip').count())
b.close()
mysql(f"delete from user_client_permissions where user_id={uid}")
mysql(f"update assets set asset_location_id=1 where asset_id={B[3]}")
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
