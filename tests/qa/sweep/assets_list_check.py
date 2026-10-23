"""Assets list: visible sort on every column, real filters + chips, status badge contrast/size, sticky header, inline edit. Exit 0 = pass."""
from common import *
from urllib.parse import quote, unquote
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
BASEQ = "from assets a left join clients c on c.client_id=a.asset_client_id where a.asset_archived_at is null and c.client_archived_at is null and a.asset_type!='Firewall/Router'"
def dbcount(cond=''): return int(mysql(f"select count(*) {BASEQ} {('and ' + cond) if cond else ''}")[0][0])
def summary_count(pg):
    t = pg.inner_text('.filter-summary strong'); return int(t)
mysql("update user_settings set user_config_records_per_page=500 where user_id in (select user_id from users where user_email like '%summitridge.example')")
p = sync_playwright().start()
b, ctx, pg = login(p, 'admin'); pg.set_viewport_size({'width': 1920, 'height': 1080})
msgs = []; pg.on('console', lambda m: msgs.append(m.text) if m.type == 'error' else None)
pg.goto(BASE + '/agent/assets.php?records=500', wait_until='domcontentloaded'); time.sleep(2)
# ---- sorting ----
ths = pg.evaluate("() => [...document.querySelectorAll('.assets-table thead th')].map(t => ({txt: t.innerText.trim(), sort: t.getAttribute('aria-sort'), icon: !!t.querySelector('.sort-icon'), link: !!t.querySelector('a.sort-link')}))")
sortable = [t for t in ths if t['txt'] and t['txt'].upper() != 'ACTION']
check('every data column header is a sort link with an icon and aria-sort', all(t['link'] and t['icon'] and t['sort'] for t in sortable) and len(sortable) >= 8, sortable)
def col_values(pg, idx):
    return pg.evaluate("(i) => [...document.querySelectorAll('.assets-table tbody tr')].map(r => r.children[i].innerText.split('\\n')[0].trim().toLowerCase())", idx)
hdr = [t['txt'].upper() for t in pg.evaluate("() => [...document.querySelectorAll('.assets-table thead th')].map(t => ({txt: t.innerText.trim()}))")]
for label, col, idx in (('NAME', 'asset_name', None), ('TYPE', 'asset_type', None), ('STATUS', 'asset_status', None), ('DEPARTMENT', 'client_name', None), ('LOCATION', 'location_name', None), ('ASSIGNED TO', 'contact_name', None)):
    i = [k for k, h in enumerate(hdr) if h.startswith(label)][0] + 1   # +1: the checkbox <td> is row cell 0
    for order in ('ASC', 'DESC'):
        pg.goto(BASE + f'/agent/assets.php?records=500&sort={col}&order={order}', wait_until='domcontentloaded')
        vals = [v for v in col_values(pg, i) if v and v != '-']
        ok = vals == sorted(vals, reverse=(order == 'DESC'))
        check(f'sort {label} {order} orders the rows', ok, vals[:6])
pg.goto(BASE + '/agent/assets.php?sort=interface_ip&order=ASC', wait_until='domcontentloaded')
ips = [v for v in col_values(pg, [k for k, h in enumerate(hdr) if h.startswith('IP')][0] + 1) if v and v != '-']
nums = [tuple(int(x) for x in v.split('\n')[0].split('.')) for v in ips if re.match(r'^\d+\.\d+\.\d+\.\d+', v)]
check('IP sorts numerically (10.0.10.2 before 10.0.10.10)', nums == sorted(nums) and len(nums) > 5, ips[:8])
r = pg.goto(BASE + '/agent/assets.php?sort=zzz_not_a_column&order=SIDEWAYS', wait_until='domcontentloaded')
check('unknown sort column falls back to Name (no 500, no redirect loop)', r.status == 200 and pg.locator('.sort-link.is-active').first.inner_text().strip().upper().startswith('NAME'))
r = pg.goto(BASE + "/agent/assets.php?sort=asset_name%3Bdrop%20table%20x&order=ASC", wait_until='domcontentloaded')
check('hostile sort value is inert', r.status == 200 and 'error' not in pg.inner_text('body').lower()[:3000])
# ---- filters vs the database ----
cases = [
    ('status=Spare', 'status%5B%5D=Spare', "a.asset_status='Spare'"),
    ('status=Deployed+Retired', 'status%5B%5D=Deployed&status%5B%5D=Retired', "a.asset_status in ('Deployed','Retired')"),
    ('type=Laptop', 'atype%5B%5D=Laptop', "a.asset_type='Laptop'"),
    ('type=Laptop+Desktop,status=Deployed', 'atype%5B%5D=Laptop&atype%5B%5D=Desktop&status%5B%5D=Deployed', "a.asset_type in ('Laptop','Desktop') and a.asset_status='Deployed'"),
    ('unassigned', 'assigned=none', "(a.asset_contact_id=0 or a.asset_contact_id is null)"),
    ('warranty expired', 'warranty=expired', "a.asset_warranty_expire is not null and a.asset_warranty_expire<curdate()"),
    ('warranty none', 'warranty=none', "a.asset_warranty_expire is null"),
    ('warranty 90', 'warranty=90', "a.asset_warranty_expire between curdate() and date_add(curdate(), interval 90 day)"),
    ('managed none', 'managed=none', "not exists (select 1 from asset_mdm_links m where m.asset_id=a.asset_id) and not exists (select 1 from asset_rmm_links m where m.asset_id=a.asset_id)"),
    ('search+type', 'q=ubiquiti&atype%5B%5D=Access%20Point', "a.asset_type='Access Point' and (a.asset_make like '%ubiquiti%' or a.asset_name like '%ubiquiti%' or a.asset_description like '%ubiquiti%' or a.asset_model like '%ubiquiti%' or a.asset_serial like '%ubiquiti%' or a.asset_type like '%ubiquiti%' or a.asset_os like '%ubiquiti%')"),
]
cid = mysql("select asset_contact_id from assets where asset_contact_id>0 limit 1")[0][0]
cases.append(('assigned=contact', f'assigned={cid}', f"a.asset_contact_id={cid}"))
mk = mysql("select asset_make from assets where asset_make!='' group by 1 order by count(*) desc limit 1")[0][0]
cases.append(('manufacturer', 'make%5B%5D=' + quote(mk), "a.asset_make='" + mk.replace("'", "''") + "'"))
for name, qs, cond in cases:
    pg.goto(BASE + '/agent/assets.php?records=500&' + qs, wait_until='domcontentloaded'); time.sleep(.5)
    want = dbcount(cond); rows = pg.locator('.assets-table tbody tr').count()
    chips = pg.locator('.filter-chip').count()
    check(f'filter {name}: result count == database ({want}), rows shown, chip present', summary_count(pg) == want and rows == want and chips >= 1, (summary_count(pg), want, rows, chips))
# chip removal + clear all + persistence across sort and tab
pg.goto(BASE + '/agent/assets.php?atype%5B%5D=Laptop&status%5B%5D=Deployed', wait_until='domcontentloaded')
n0 = pg.locator('.filter-chip').count()
pg.locator('.filter-chip-x').first.click(); pg.wait_for_load_state('domcontentloaded')
check('removing one chip drops exactly that filter', pg.locator('.filter-chip').count() == n0 - 1, (n0, pg.locator('.filter-chip').count()))
pg.goto(BASE + '/agent/assets.php?atype%5B%5D=Laptop&status%5B%5D=Deployed', wait_until='domcontentloaded')
pg.click('.assets-table thead a.sort-link >> nth=1'); pg.wait_for_load_state('domcontentloaded')
check('filters survive a sort click', 'atype' in unquote(pg.url) and 'status' in unquote(pg.url) and 'sort=' in pg.url, pg.url)
pg.click('a:has-text("Workstations")'); pg.wait_for_load_state('domcontentloaded')
check('filters survive switching the type tab', 'status' in unquote(pg.url) and 'type=workstation' in pg.url, pg.url)
pg.click('a:has-text("Clear all")'); pg.wait_for_load_state('domcontentloaded')
check('Clear all removes filters but keeps the type tab', pg.locator('.filter-chip').count() == 0 and 'type=workstation' in pg.url, pg.url)
pg.goto(BASE + '/agent/assets.php?atype%5B%5D=Printer&status%5B%5D=Lost', wait_until='domcontentloaded')
check('zero results shows an explanation naming the way out', 'No assets match these filters' in pg.inner_text('body') and pg.locator('a:has-text("Clear all filters")').count() == 1)
for hostile in ("status%5B%5D=%27%20OR%201%3D1%20--%20", "atype%5B%5D=" + "A" * 500, "assigned=abc", "warranty=zzz", "managed=zzz", "location=-5", "status=notanarray"):
    r = pg.goto(BASE + '/agent/assets.php?' + hostile, wait_until='domcontentloaded')
    txt = pg.inner_text('body')
    check(f'hostile filter value is inert ({hostile[:30]})', r.status == 200 and 'mysqli' not in txt.lower() and 'fatal' not in txt.lower() and 'warning:' not in txt.lower(), r.status)
# ---- status badge ----
def badge_probe(pg):
    return pg.evaluate("""() => { const lin = v => { v/=255; return v<=0.03928? v/12.92 : Math.pow((v+0.055)/1.055,2.4) };
        const L = c => 0.2126*lin(c[0])+0.7152*lin(c[1])+0.0722*lin(c[2]);
        const rgb = s => (s.match(/[\\d.]+/g)||[]).slice(0,3).map(Number);
        return [...document.querySelectorAll('.assets-table .status-badge')].map(e => { const cs = getComputedStyle(e); const f = rgb(cs.color), g = rgb(cs.backgroundColor);
            const r = e.getBoundingClientRect(); return {t: e.innerText.trim(), cr: (Math.max(L(f),L(g))+.05)/(Math.min(L(f),L(g))+.05), h: Math.round(r.height), clipped: e.scrollWidth > e.clientWidth + 1} }) }""")
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    b2, ctx2, pg2 = login(p, who); pg2.set_viewport_size({'width': 1920, 'height': 1080})
    pg2.goto(BASE + '/agent/assets.php?records=500', wait_until='domcontentloaded'); time.sleep(1.5)
    pr = badge_probe(pg2); names = sorted({x['t'] for x in pr})
    check(f'{theme}: status badges present for {len(names)} statuses', len(pr) >= 30 and len(names) >= 4, names)
    check(f'{theme}: every badge text is >= 4.5:1 on its tint', all(x['cr'] >= 4.5 for x in pr), [(x['t'], round(x['cr'], 2)) for x in pr if x['cr'] < 4.5][:4])
    check(f'{theme}: all badges share one height and none is clipped', len({x['h'] for x in pr}) == 1 and not any(x['clipped'] for x in pr), ({x['h'] for x in pr}))
    if out: pg2.screenshot(path=f'{out}/assets_{theme}.png')
    # sticky header
    sc = pg2.evaluate("() => { const w = document.querySelector('.table-sticky-head'); w.scrollTop = 300; const th = w.querySelector('thead th').getBoundingClientRect(); const wr = w.getBoundingClientRect(); return {top: Math.round(th.top - wr.top), scrollable: w.scrollHeight > w.clientHeight} }")
    check(f'{theme}: header stays at the top of the scrolling list', sc['scrollable'] and abs(sc['top']) <= 1, sc)
    # every inline trigger has an accessible name; no stray caret next to an empty cell until hover
    nm = pg2.evaluate("() => [...document.querySelectorAll('.asset-inline-trigger')].filter(b => !(b.getAttribute('aria-label')||'').trim()).length")
    check(f'{theme}: every inline-edit trigger has an accessible name', nm == 0, nm)
    b2.close()
# ---- inline edit still works and re-renders the badge ----
b3, ctx3, pg3 = login(p, 'admin'); pg3.set_viewport_size({'width': 1920, 'height': 1080})
aid = mysql("select asset_id from assets where asset_status='Deployed' and asset_archived_at is null limit 1")[0][0]
pg3.goto(BASE + '/agent/assets.php?records=500', wait_until='domcontentloaded'); time.sleep(1.5)
row = pg3.locator(f'tr[data-asset-id="{aid}"]')
row.locator('.asset-inline-cell[data-field="status"] .asset-inline-trigger').click(); time.sleep(.8)
pg3.locator('.ts-dropdown .option:has-text("Retired")').first.click(); time.sleep(1.5)
txt = row.locator('.asset-inline-cell[data-field="status"] .status-badge').inner_text().strip()
db = mysql(f"select asset_status from assets where asset_id={aid}")[0][0]
check('inline status change saves and re-renders the new badge', txt == 'Retired' and db == 'Retired', (txt, db))
mysql(f"update assets set asset_status='Deployed' where asset_id={aid}")
b3.close()
# ---- tablet / phone: no page overflow; filters collapse behind a button below 768 ----
b4, ctx4, pg4 = login(p, 'admin'); pg4.set_viewport_size({'width': 768, 'height': 1024})
pg4.goto(BASE + '/agent/assets.php', wait_until='domcontentloaded'); time.sleep(1.5)
ov = pg4.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")
check('768 wide: no horizontal page scroll', ov <= 1, ov)
check('768 wide: filters are visible without a toggle', pg4.locator('#assetMoreFilters').is_visible())
if out: pg4.screenshot(path=f'{out}/assets_768.png')
pg4.set_viewport_size({'width': 600, 'height': 900}); pg4.reload(wait_until='domcontentloaded'); time.sleep(1.2)
check('600 wide: extra filters are behind a More filters button', pg4.locator('button:has-text("More filters")').is_visible() and not pg4.locator('#assetMoreFilters').is_visible())
pg4.click('button:has-text("More filters")'); time.sleep(.6)
check('600 wide: button reveals the filters', pg4.locator('#assetMoreFilters').is_visible())
ov = pg4.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")
check('600 wide: no horizontal page scroll', ov <= 1, ov)
b4.close()
b5, ctx5, pg5 = login(p, 'admin'); pg5.set_viewport_size({'width': 1366, 'height': 768})
pg5.goto(BASE + '/agent/assets.php', wait_until='domcontentloaded'); time.sleep(1.5)
ov = pg5.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")
check('1366 wide: no horizontal page scroll', ov <= 1, ov)
if out: pg5.screenshot(path=f'{out}/assets_1366.png')
check('no console errors', not [m for m in msgs if 'favicon' not in m], msgs[:3])
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
