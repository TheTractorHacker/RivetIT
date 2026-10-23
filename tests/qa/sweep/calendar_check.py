"""Calendar look and legend: coloured chips with readable ink, quiet day numbers, one toolbar style, working legend toggles, no overflow. Exit 0 = pass."""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
JS_CONTRAST = """() => { const lin = v => { v/=255; return v<=0.03928? v/12.92 : Math.pow((v+0.055)/1.055,2.4) }; const L = c => 0.2126*lin(c[0])+0.7152*lin(c[1])+0.0722*lin(c[2]);
    const cv = document.createElement('canvas'); cv.width = cv.height = 1; const cx = cv.getContext('2d', {willReadFrequently: true});
    const rgb = s => { cx.clearRect(0,0,1,1); cx.fillStyle = '#000'; cx.fillStyle = s; cx.fillRect(0,0,1,1); const d = cx.getImageData(0,0,1,1).data; return [d[0], d[1], d[2]] };
    const rows = []; document.querySelectorAll('.cal-surface .fc-daygrid-event').forEach(e => { const t = e.querySelector('.fc-event-title'); if (!t) return; const cs = getComputedStyle(e); const f = rgb(getComputedStyle(t).color), g = rgb(cs.backgroundColor);
        rows.push({c: e.style.getPropertyValue('--ev-color'), cr: (Math.max(L(f),L(g))+.05)/(Math.min(L(f),L(g))+.05)}) }); return rows }"""
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    b, ctx, pg = login(p, who); pg.set_viewport_size({'width': 1920, 'height': 1080})
    msgs = []; pg.on('console', lambda m: msgs.append(m.text) if m.type == 'error' else None)
    pg.goto(BASE + '/agent/calendar.php', wait_until='domcontentloaded'); pg.wait_for_selector('.fc-daygrid-event', timeout=15000); time.sleep(1)
    n = pg.locator('.cal-surface .fc-daygrid-event').count()
    check(f'{theme}: month view renders events as chips ({n})', n > 20)
    rows = pg.evaluate(JS_CONTRAST)
    bad = [r for r in rows if r['cr'] < 4.5]
    check(f'{theme}: every chip text is >= 4.5:1 on its tint ({len(rows)} chips, {len({r["c"] for r in rows})} colours)', rows and not bad, bad[:3])
    ink = pg.evaluate("() => getComputedStyle(document.querySelector('.cal-surface')).color")
    t_col = pg.evaluate("() => getComputedStyle(document.querySelector('.cal-surface a.fc-event .fc-event-title')).color")
    check(f'{theme}: linked events use the normal ink colour, not the theme link colour', t_col == ink, (t_col, ink))
    nums = pg.evaluate("() => [...document.querySelectorAll('.cal-surface .fc-daygrid-day:not(.fc-day-other):not(.fc-day-today) .fc-daygrid-day-number')].map(e => getComputedStyle(e).color)")
    check(f'{theme}: day numbers (weekends included) are neutral ink, never red', len(set(nums)) == 1 and nums[0] == ink, set(nums))
    today = pg.evaluate("() => { const e = document.querySelector('.cal-surface .fc-day-today .fc-daygrid-day-number'); const s = getComputedStyle(e); return {bg: s.backgroundColor, color: s.color} }")
    check(f'{theme}: today is a filled badge on a quiet tint (no yellow cell)', today['bg'] != 'rgba(0, 0, 0, 0)' and today['color'] == 'rgb(255, 255, 255)', today)
    hs = pg.evaluate("() => [...document.querySelectorAll('.cal-surface .fc-header-toolbar .fc-button')].map(b => Math.round(b.getBoundingClientRect().height))")
    check(f'{theme}: all toolbar buttons share one height', len(set(hs)) == 1 and len(hs) >= 6, hs)
    ne = pg.evaluate("() => { const b = document.querySelector('.fc-newEvent-button'); const s = getComputedStyle(b); return {bg: s.backgroundColor, c: s.color} }")
    check(f'{theme}: New Event is the one filled primary action', ne['c'] == 'rgb(255, 255, 255)' and ne['bg'] != 'rgba(0, 0, 0, 0)', ne)
    clip = pg.evaluate("() => { let n = 0; document.querySelectorAll('.cal-surface .fc-daygrid-event').forEach(e => { const cell = e.closest('.fc-daygrid-day-frame').getBoundingClientRect(); const r = e.getBoundingClientRect(); if (r.right > cell.right + 1 || r.left < cell.left - 1) n++; }); return n }")
    check(f'{theme}: no chip spills outside its day cell', clip == 0, clip)
    titles = pg.evaluate("() => [...document.querySelectorAll('.cal-surface .fc-daygrid-event')].filter(e => !e.getAttribute('title')).length")
    check(f'{theme}: every chip has its full title as a tooltip', titles == 0, titles)
    if out: pg.screenshot(path=f'{out}/calendar_{theme}.png')
    if theme == 'light':
        # legend toggles
        boxes = pg.locator('.cal-legend__check').count()
        names = pg.eval_on_selector_all('.cal-legend__item', 'els => els.map(e => e.innerText.trim())')
        check(f'legend: {boxes} calendars each a labelled check box', boxes >= 8 and all(names), names)
        before = pg.locator('.cal-surface .fc-daygrid-event[data-src="tickets"]').count()
        pg.locator('.cal-legend__item', has_text='Tickets (Created)').click(); time.sleep(.6)
        after = pg.locator('.cal-surface .fc-daygrid-event[data-src="tickets"]:visible').count()
        check('legend: unchecking Tickets (Created) hides its chips and keeps the others', before > 0 and after == 0 and pg.locator('.cal-surface .fc-daygrid-event:visible').count() > 0, (before, after))
        pg.reload(wait_until='domcontentloaded'); pg.wait_for_selector('.fc-daygrid-event', timeout=15000); time.sleep(1)
        check('legend: the choice is remembered after a reload', not pg.locator('.cal-legend__check[data-src="tickets"]').is_checked() and pg.locator('.cal-surface .fc-daygrid-event[data-src="tickets"]:visible').count() == 0)
        pg.focus('.cal-legend__check[data-src="tickets"]'); pg.keyboard.press('Space'); time.sleep(.6)
        check('legend: Space on the focused box brings them back (keyboard operable)', pg.locator('.cal-legend__check[data-src="tickets"]').is_checked() and pg.locator('.cal-surface .fc-daygrid-event[data-src="tickets"]:visible').count() > 0 and 'tickets' not in pg.evaluate("() => Object.keys(localStorage).map(k => localStorage.getItem(k)).join('|')"))
        # views
        for label, sel, btn in (('Week', '.fc-timeGridWeek-view', '.fc-timeGridWeek-button'), ('Day', '.fc-timeGridDay-view', '.fc-timeGridDay-button'), ('List', '.fc-listMonth-view', '.fc-listMonth-button'), ('Month', '.fc-dayGridMonth-view', '.fc-dayGridMonth-button')):
            pg.click(f'.cal-surface {btn}'); time.sleep(.8)
            check(f'view {label}: renders and the button shows active', pg.locator(sel).count() == 1 and 'fc-button-active' in (pg.get_attribute(f'.cal-surface {btn}', 'class') or ''))
            if out and label in ('Week', 'List'): pg.screenshot(path=f'{out}/calendar_{label.lower()}.png')
        # +N more
        pg.locator('.cal-surface .fc-daygrid-more-link').first.click(); time.sleep(.6)
        pop = pg.evaluate("() => { const p = document.querySelector('.fc-popover'); if (!p) return null; const r = p.getBoundingClientRect(); return {l: r.left, t: r.top, r: r.right, b: r.bottom, vw: innerWidth, vh: innerHeight} }")
        check('+N more opens a popover that stays inside the window', pop is not None and pop['l'] >= 0 and pop['t'] >= 0 and pop['r'] <= pop['vw'] and pop['b'] <= pop['vh'], pop)
        pg.keyboard.press('Escape')
        # sync card
        urls = pg.evaluate("() => [...document.querySelectorAll('.cal-sync-url')].map(i => ({t: i.title.length > 10, a: !!i.getAttribute('aria-label'), w: i.getBoundingClientRect().width}))")
        check('sync URLs: full address in a tooltip, labelled, and the box is wide enough to read the start', len(urls) == 2 and all(u['t'] and u['a'] and u['w'] > 150 for u in urls), urls)
        pg.locator('.copy-btn').first.click(); time.sleep(.5)
        check('sync URLs: Copy confirms (Copied, or tells the user what to press)', pg.locator('.copy-btn').first.inner_text().strip() in ('Copied', 'Press Ctrl+C'), pg.locator('.copy-btn').first.inner_text())
        # sizes
        for (w, h) in ((1366, 768), (1024, 768), (768, 1024), (390, 800)):
            pg.set_viewport_size({'width': w, 'height': h}); pg.goto(BASE + '/agent/calendar.php', wait_until='domcontentloaded'); pg.wait_for_selector('.fc-daygrid-event', timeout=15000); time.sleep(.8)
            ov = pg.evaluate("() => document.documentElement.scrollWidth - document.documentElement.clientWidth")
            tb = pg.evaluate("() => { const t = document.querySelector('.cal-surface .fc-header-toolbar').getBoundingClientRect(); return [...document.querySelectorAll('.cal-surface .fc-header-toolbar .fc-button')].filter(b => { const r = b.getBoundingClientRect(); return r.right > t.right + 1 || r.left < t.left - 1 }).length }")
            check(f'{w}x{h}: no page overflow and the toolbar keeps every button inside', ov <= 1 and tb == 0, (ov, tb))
            if out and w in (768, 390): pg.screenshot(path=f'{out}/calendar_{w}.png')
    check(f'{theme}: no console errors', not [m for m in msgs if 'favicon' not in m], msgs[:3])
    b.close()
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
