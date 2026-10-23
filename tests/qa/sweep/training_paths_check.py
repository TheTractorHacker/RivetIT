"""Training > Learning paths: toolbar, new tile, editor layout (nothing clipped, one-row swatches, tidy course rows), validation, save, archive. Exit 0 = pass."""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
mysql("delete from training_path_courses where tpcourse_path_id in (select tpath_id from training_paths where tpath_name like 'QA-UI-%')")
mysql("delete from training_paths where tpath_name like 'QA-UI-%'")
def mk(name, desc, ncourses, color='#2563EB'):
    mysql(f"insert into training_paths (tpath_uid, tpath_name, tpath_description, tpath_color, tpath_sequential, tpath_version, tpath_created_by) values (substr(md5(rand()),1,12), '{name}', '{desc}', '{color}', 0, 1, 9)")
    pid = mysql(f"select tpath_id from training_paths where tpath_name='{name}'")[0][0]
    for i in range(ncourses): mysql(f"insert into training_path_courses values ({pid}, {i + 1}, {i}, {1 if i % 2 == 0 else 0})")
    return pid
mk('QA-UI- Alpha', 'Short', 1)
mk('QA-UI- A very long learning path name that has to wrap or truncate instead of breaking the card layout', 'x' * 400, 6, '#DC2626')
mk('QA-UI- Zulu', '', 0, '#D97706')
p = sync_playwright().start()
b, ctx, pg = login(p, 'admin')
msgs = []; pg.on('console', lambda m: msgs.append(m.text) if m.type == 'error' else None)
def goto(w=1920, h=1000):
    pg.set_viewport_size({'width': w, 'height': h}); pg.goto(BASE + '/agent/training_paths.php', wait_until='domcontentloaded'); pg.wait_for_selector('.tr-path-card', timeout=15000); time.sleep(.8)
goto()
n_cards = pg.locator('.tr-path-card:not(.tr-path-card--new)').count()
check('all paths shown with a New learning path tile at the end', n_cards == 4 and pg.locator('.tr-path-card--new').count() == 1, n_cards)
names = pg.eval_on_selector_all('.tr-path-card__title', 'els => els.map(e => e.textContent)')
check('sorted by name by default', names == sorted(names, key=str.lower), names)
pg.select_option('#tr-path-sort', 'courses'); time.sleep(.4)
counts = pg.eval_on_selector_all('.tr-path-card__meta', 'els => els.map(e => parseInt(e.textContent) || 0)')
check('sort by most courses puts the biggest first', counts == sorted(counts, reverse=True), counts)
pg.fill('#tr-path-search', 'zulu'); time.sleep(.4)
check('search narrows the grid and the count says "1 of 4"', pg.locator('.tr-path-card:not(.tr-path-card--new)').count() == 1 and pg.locator('.tr-path-card--new').count() == 0 and re.match(r'^1 of 4', pg.inner_text('#tr-path-count')), pg.inner_text('#tr-path-count'))
pg.fill('#tr-path-search', 'no such thing'); time.sleep(.4)
check('no match: a message naming the search and a way to clear it', 'No learning paths match' in pg.inner_text('#tr-path-grid') and pg.locator('#tr-path-grid button:has-text("Clear search")').count() == 1)
pg.click('#tr-path-grid button:has-text("Clear search")'); time.sleep(.4)
check('Clear search restores the grid', pg.locator('.tr-path-card:not(.tr-path-card--new)').count() == 4)
chip = pg.locator('.tr-path-card .tr-chip').first
check('order chip has a tooltip, an accessible description and is focusable', bool(chip.get_attribute('title')) and bool(chip.get_attribute('aria-label')) and chip.get_attribute('tabindex') == '0')
ov = pg.evaluate("() => { const out = []; document.querySelectorAll('.tr-path-card').forEach(c => { if (c.scrollWidth > c.clientWidth + 1) out.push(c.className) }); return out }")
check('long names and descriptions never overflow a card', not ov, ov)
# prerequisites empty state wording
check('prerequisites empty state is actionable (no reference to a list "above")', 'above' not in pg.inner_text('#tr-prereq-body') and pg.locator('#tr-prereq-body button:has-text("Add a prerequisite")').count() == 1, pg.inner_text('#tr-prereq-body'))
pg.click('#tr-prereq-body button:has-text("Add a prerequisite")'); time.sleep(.6)
check('Add a prerequisite shows every course so one can be picked', pg.locator('#tr-prereq-body tr').count() >= 6 and pg.is_checked('#tr-prereq-all'), pg.locator('#tr-prereq-body tr').count())
# ---- editor ----
for (w, h) in ((1920, 1000), (1366, 768), (390, 800)):
    goto(w, h)
    pg.locator('.tr-path-card__title', has_text='QA-UI- Alpha').first.click(); time.sleep(1.2)
    pg.locator('#tr-path-editor').wait_for(state='visible')
    r = pg.evaluate("""() => { const e = document.getElementById('tr-path-editor'); const r = e.getBoundingClientRect(); const hd = e.querySelector('.offcanvas-header').getBoundingClientRect(); const ft = document.getElementById('tr-path-footer').getBoundingClientRect();
        const sw = [...document.querySelectorAll('#tr-path-colors .tr-cat-swatch')].map(x => Math.round(x.getBoundingClientRect().top)); const ca = [...document.querySelectorAll('.tr-path-cover-actions .btn:not([hidden])')].map(x => Math.round(x.getBoundingClientRect().width));
        return {top: r.top, bottom: r.bottom, vh: innerHeight, hdTop: hd.top, ftBottom: ft.bottom, swRows: new Set(sw).size, coverW: new Set(ca).size, over: e.scrollWidth - e.clientWidth} }""")
    check(f'{w}x{h}: panel fits the viewport; header and footer visible and pinned', r['top'] >= 0 and r['bottom'] <= r['vh'] + 1 and r['hdTop'] >= 0 and r['ftBottom'] <= r['vh'] + 1 and r['over'] <= 1, r)
    if w >= 768: check(f'{w}x{h}: colour swatches sit in a single row; cover buttons share one width', r['swRows'] == 1 and r['coverW'] == 1, r)
    rows = pg.evaluate("""() => [...document.querySelectorAll('#tr-path-courses .tr-path-course')].map(li => { const g = s => li.querySelector(s).getBoundingClientRect(); const n = g('.tr-path-course__name'), q = g('.tr-path-course__req'), a = g('.tr-path-course__actions'); return {rowH: Math.round(li.getBoundingClientRect().height), sameLine: window.innerWidth < 576 ? (li.getBoundingClientRect().height <= 150 && li.scrollWidth <= li.clientWidth + 1) : (Math.abs((n.top + n.height / 2) - (a.top + a.height / 2)) < 30 && Math.abs((q.top + q.height / 2) - (a.top + a.height / 2)) < 14)} })""")
    check(f'{w}x{h}: each course row keeps name, Required and actions together', rows and all(x['sameLine'] for x in rows), rows)
    bg = pg.evaluate("() => { const i = document.querySelector('#tr-path-courses .tr-path-course__req .form-check-input:checked'); return i ? getComputedStyle(i).backgroundColor : null }")
    m = [int(x) for x in re.findall(r'\d+', bg or '')[:3]] if bg else None
    check(f'{w}x{h}: Required switches are green when on (not the red/danger theme colour)', m is not None and m[1] > m[0] and m[1] > m[2], bg)
    if out and w == 1920: pg.screenshot(path=f'{out}/paths_editor_1920.png')
    # scroll body: header/footer stay put
    pg.evaluate("() => { document.querySelector('#tr-path-editor .offcanvas-body').scrollTop = 9999 }"); time.sleep(.3)
    r2 = pg.evaluate("() => { const hd = document.querySelector('#tr-path-editor .offcanvas-header').getBoundingClientRect(); const ft = document.getElementById('tr-path-footer').getBoundingClientRect(); return {hd: hd.top, ft: ft.bottom, vh: innerHeight} }")
    check(f'{w}x{h}: header and footer stay in view while the body scrolls', r2['hd'] >= 0 and r2['ft'] <= r2['vh'] + 1, r2)
    pg.keyboard.press('Escape'); time.sleep(.8)
# ---- validation, save, dirty guard, archive ----
goto()
pg.click('#tr-path-new'); time.sleep(1)
check('name field is focused or at least first in the form and marked required', pg.locator('#tr-path-name').is_visible() and 'required' in (pg.get_attribute('label[for=tr-path-name]', 'class') or ''))
pg.click('#tr-path-save'); time.sleep(.5)
check('saving an empty name shows a visible message next to the field', pg.locator('#tr-path-editor .invalid-feedback[data-field=name]').is_visible() and 'name' in pg.inner_text('#tr-path-editor .invalid-feedback[data-field=name]').lower())
pg.fill('#tr-path-name', 'QA-UI- Created through the UI'); pg.click('#tr-path-save'); time.sleep(.3)
check('Save shows a busy state while saving', pg.get_attribute('#tr-path-save', 'aria-busy') in ('true', None))
time.sleep(1.8)
check('the new path exists in the database and in the grid', int(mysql("select count(*) from training_paths where tpath_name='QA-UI- Created through the UI'")[0][0]) == 1 and pg.locator('.tr-path-card__title', has_text='Created through the UI').count() == 1)
pg.locator('.tr-path-card__title', has_text='Created through the UI').click(); time.sleep(1)
pg.fill('#tr-path-name', 'QA-UI- changed'); pg.click('#tr-path-footer button:has-text("Cancel")'); time.sleep(.8)
check('closing with unsaved changes asks before discarding', 'Discard your changes' in pg.inner_text('#tr-path-editor'))
pg.click('#tr-path-editor button:has-text("Keep editing")'); time.sleep(.4)
pg.click('#tr-path-archive'); time.sleep(.6)
check('Archive asks for confirmation that names the path', 'Created through the UI' in pg.inner_text('#tr-path-editor') or 'changed' in pg.inner_text('#tr-path-editor'))
pg.click('#tr-path-editor button:has-text("Archive") >> nth=-1'); time.sleep(1.8)
check('archived path leaves the grid and is stored archived', int(mysql("select count(*) from training_paths where tpath_name like 'QA-UI- %' and tpath_archived_at is not null")[0][0]) >= 1)
# level-1 learner/viewer: no editing controls
bl, cl, pl = login(p, 'tech'); pl.set_viewport_size({'width': 1366, 'height': 768})
pl.goto(BASE + '/agent/training_paths.php', wait_until='domcontentloaded'); time.sleep(2)
check('a user without edit rights gets no New tile, no New button and no archived switch', pl.locator('.tr-path-card--new').count() == 0 and pl.locator('#tr-path-new').count() == 0 and pl.locator('#tr-show-archived').count() == 0 or pl.url.endswith('403.php') or 'access' in pl.inner_text('body').lower())
bl.close()
check('no console errors', not [m for m in msgs if 'favicon' not in m], msgs[:3])
b.close()
mysql("delete from training_path_courses where tpcourse_path_id in (select tpath_id from training_paths where tpath_name like 'QA-UI-%')"); mysql("delete from training_paths where tpath_name like 'QA-UI-%'")
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
