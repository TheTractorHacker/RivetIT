"""ManageEngine MDM 'Users to contacts' (fake QA-UI- rows seeded by seed_mdm.sql; nothing calls ManageEngine): picker, layout, KPI cards. Exit 0 = pass."""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:220] if detail and not ok else ''))
    if not ok: fails.append(name)
subprocess.run(f'/home/sysadmin/demos/mysqlroot.sh {DB} < {HERE}/seed_mdm.sql', shell=True, capture_output=True)
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    for (w, h) in ((1920, 1080), (1366, 768), (768, 1024)):
        b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h}); tag = f'{theme} {w}x{h}'
        pg.goto(BASE + '/agent/mdm_devices.php?view=users', wait_until='domcontentloaded'); time.sleep(1.2)
        r = pg.evaluate("""() => { const cards = [...document.querySelectorAll('.mdm-kpis .small-box')].map(c => c.getBoundingClientRect());
            const sc = document.querySelector('.mdm-scroll'); const act = [...document.querySelectorAll('.mdm-map-form .btn')].map(x => x.getBoundingClientRect());
            return {cardsRight: Math.max(...cards.map(c => c.right)), vw: innerWidth, docOverflow: document.documentElement.scrollWidth - innerWidth,
                    tableInCard: !!sc && sc.getBoundingClientRect().right <= innerWidth + 1, ignoreVisible: act.every(a => a.right <= innerWidth + 1 || sc.scrollWidth > sc.clientWidth),
                    btnH: [...new Set(act.map(a => Math.round(a.height)))] } }""")
        check(f'{tag}: KPI cards and the table card fit the viewport, no page-level sideways scroll', r['cardsRight'] <= r['vw'] and r['docOverflow'] <= 1 and r['tableInCard'], r)
        check(f'{tag}: Map and Ignore share one height', len(r['btnH']) == 1, r)
        if out: pg.screenshot(path=f'{out}/mdm_{theme}_{w}.png')
        if w == 1366:
            inp = pg.locator('.mdm-typeahead').nth(1); inp.click(); inp.type('Mar', delay=80); time.sleep(1.2)
            r = pg.evaluate("""() => { const l = document.querySelector('.mdm-pick-list'); if (!l) return null; const lr = l.getBoundingClientRect(); const items = [...l.querySelectorAll('.mdm-pick-item')];
                const cx = lr.left + lr.width / 2, cy = lr.top + Math.min(lr.height - 4, 20); const top = document.elementFromPoint(cx, cy);
                const input = document.querySelectorAll('.mdm-typeahead')[1].getBoundingClientRect();
                return {n: items.length, inBody: l.parentElement === document.body, onTop: l.contains(top), h: items.length ? Math.round(items[0].getBoundingClientRect().height) : 0,
                        below: lr.top >= input.bottom - 1 || lr.bottom <= input.top + 1, filtered: items.every(i => /mar/i.test(i.innerText)), maxH: lr.height <= 305, mapDisabled: document.querySelectorAll('.mdm-map')[1].disabled} }""")
            check(f'{tag}: picker opens as a body-level list, on top, 32-60px rows, filtered by the typed text, Map still disabled', r is not None and r['inBody'] and r['onTop'] and 32 <= r['h'] <= 64 and r['filtered'] and r['maxH'] and r['mapDisabled'] and r['below'], r)
            if out: pg.screenshot(path=f'{out}/mdm_pick_{theme}.png')
            pg.keyboard.press('ArrowDown'); pg.keyboard.press('Enter'); time.sleep(.4)
            r = pg.evaluate("() => ({v: document.querySelectorAll('.mdm-typeahead')[1].value, mapOn: !document.querySelectorAll('.mdm-map')[1].disabled, open: !!document.querySelector('.mdm-pick-list')})")
            check(f'{tag}: arrow + Enter choose a contact, list closes, Map enables', r['v'] != 'Mar' and r['mapOn'] and not r['open'], r)
            inp.fill(''); inp.type('zzzqq', delay=40); time.sleep(1); 
            check(f'{tag}: no match shows a No match row', 'No match' in (pg.evaluate("() => (document.querySelector('.mdm-pick-list')||{}).innerText || ''")))
            pg.keyboard.press('Escape')
        b.close()
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
