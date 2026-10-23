"""Ticket detail + list polish: compact reply editor, tidy Time Entry card, neutral split-button menu, no Board column. Exit 0 = pass."""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:220] if detail and not ok else ''))
    if not ok: fails.append(name)
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    for (w, h) in ((1920, 1080), (1366, 768)):
        b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h}); tag = f'{theme} {w}x{h}'
        pg.goto(BASE + '/agent/ticket.php?client_id=16&ticket_id=248', wait_until='domcontentloaded'); time.sleep(2.5)
        r = pg.evaluate("""() => { const ed = document.querySelector('.tox-tinymce').getBoundingClientRect(); const tool = document.querySelector('.tox-editor-header').getBoundingClientRect();
            const inputs = document.querySelector('.time-entry-card .d-flex.justify-content-center').getBoundingClientRect(); const acts = document.querySelector('.time-entry-actions').getBoundingClientRect();
            const btns = [...document.querySelectorAll('.time-entry-actions .btn')].map(x => x.getBoundingClientRect());
            const bg = e => getComputedStyle(e).backgroundColor; const cards = [...document.querySelectorAll('.col-lg-3 .card, .card.time-entry-card ~ .card')];
            return {editorH: Math.round(ed.height - tool.height), cx: Math.abs((inputs.left + inputs.width / 2) - (acts.left + acts.width / 2)), gap: Math.round(btns[1].left - btns[0].right), hs: [...new Set(btns.map(x => Math.round(x.height)))], wDiff: Math.abs(btns[0].width - btns[1].width),
                    cardBg: bg(document.querySelector('.time-entry-card')), sibBg: cards.length ? bg(cards[0]) : null,
                    label: document.getElementById('startStopTimer').innerText.trim()} }""")
        check(f'{tag}: empty reply editor body is compact (<= 150px), not a tall dead band', r['editorH'] <= 150, r)
        check(f'{tag}: timer inputs centred over equal-width Start/Reset with a gap and one height', r['cx'] <= 2 and r['gap'] >= 6 and len(r['hs']) == 1 and r['wDiff'] <= 1 and r['label'] == 'Start', r)
        check(f'{tag}: Time Entry card has the same surface as its sibling cards', r['sibBg'] is None or r['cardBg'] == r['sibBg'], r)
        r = pg.evaluate("""() => { const t = document.querySelector('.dropdown-toggle-split'); const s = getComputedStyle(t); const sub = t.previousElementSibling.getBoundingClientRect(), tb = t.getBoundingClientRect();
            return {divider: s.borderLeftWidth, sameH: Math.abs(sub.height - tb.height) <= 1, label: t.getAttribute('aria-label')} }""")
        check(f'{tag}: split-button caret has a divider, matching height and an accessible name', r['divider'] != '0px' and r['sameH'] and r['label'], r)
        pg.click('.dropdown-toggle-split'); time.sleep(.4)
        item = pg.locator('.dropdown-menu.show .dropdown-item').nth(1); item.hover(); time.sleep(.2)
        bg = pg.evaluate("(e) => getComputedStyle(e).backgroundColor", item.element_handle())
        m = [float(x) for x in re.findall(r'[\d.]+', bg)[:3]]
        check(f'{tag}: menu hover is neutral (not the theme colour)', abs(m[0] - m[1]) < 40 and abs(m[1] - m[2]) < 40, bg)
        if out: pg.screenshot(path=f'{out}/ticket_{theme}_{w}.png')
        pg.goto(BASE + '/agent/ticket_list.php', wait_until='domcontentloaded'); time.sleep(1)
        ths = pg.evaluate("() => [...document.querySelectorAll('table thead th')].map(t => t.innerText.trim())")
        check(f'{tag}: ticket list has no Board column but keeps Category', 'BOARD' not in [t.upper() for t in ths] and 'CATEGORY' in [t.upper() for t in ths], ths)
        b.close()
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
