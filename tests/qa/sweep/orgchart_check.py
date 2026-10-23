"""Org chart: toggles clear of the text, tooltips + labels, fit on load, every control works, keyboard focus on toggles. Exit 0 = pass.
usage: orgchart_check.py [shots_dir]"""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:200] if detail and not ok else ''))
    if not ok: fails.append(name)
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    for (w, h) in ((1920, 1080), (1366, 768)):
        b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h})
        errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
        pg.goto(BASE + '/agent/org_chart.php', wait_until='domcontentloaded'); time.sleep(1.5)
        tag = f'{theme} {w}x{h}'
        pg.click('#orgChartShowMap'); pg.wait_for_selector('#orgChartCanvas svg .node', timeout=15000); time.sleep(1)
        pg.click('#orgChartExpandAll'); time.sleep(.8)
        if out: pg.screenshot(path=f'{out}/org_{theme}_{w}.png')
        r = pg.evaluate("""() => {
          const frame = document.querySelector('.org-d3-frame').getBoundingClientRect();
          const cards = [...document.querySelectorAll('.org-d3-person')];
          const toggles = [...document.querySelectorAll('.org-d3-toggle')];
          let overlap = 0;
          toggles.forEach(t => { const tr = t.getBoundingClientRect(); const card = t.closest('.node-button-foreign-object') ; 
            cards.forEach(c => { const sp = c.querySelector('.org-d3-text span'); if (!sp) return; const sr = sp.getBoundingClientRect(); const cr = c.getBoundingClientRect();
              if (tr.left < sr.right && tr.right > sr.left && tr.top < sr.bottom - 1 && tr.bottom > sr.top + 1) overlap++; }); });
          const clipped = cards.filter(c => { const r = c.getBoundingClientRect(); return r.left < frame.left - 1 || r.right > frame.right + 1 || r.top < frame.top - 1 || r.bottom > frame.bottom + 1 }).length;
          return {cards: cards.length, toggles: toggles.length, overlap, clipped,
                  unlabeled: toggles.filter(t => !t.getAttribute('aria-label')).length,
                  noTip: cards.filter(c => !c.title).length,
                  badges: [...document.querySelectorAll('.org-d3-count')].filter(x => !x.title || !x.getAttribute('aria-label')).length}
        }""")
        check(f'{tag}: toggle buttons do not overlap job-title text', r['overlap'] == 0 and r['toggles'] > 0, r)
        check(f'{tag}: every card has a full-text tooltip, every toggle an accessible name, every count badge a label', r['noTip'] == 0 and r['unlabeled'] == 0 and r['badges'] == 0, r)
        pg.click('#orgChartZoomFit'); time.sleep(.6)
        r2 = pg.evaluate("""() => { const frame = document.querySelector('.org-d3-frame').getBoundingClientRect(); return [...document.querySelectorAll('.org-d3-person, .org-d3-group')].filter(c => { const r = c.getBoundingClientRect(); return r.left < frame.left - 1 || r.right > frame.right + 1 || r.top < frame.top - 1 || r.bottom > frame.bottom + 1 }).length }""")
        check(f'{tag}: Fit puts every node inside the frame', r2 == 0, r2)
        for cid in ('orgChartZoomIn', 'orgChartZoomOut', 'orgChartZoomReset', 'orgChartCollapseAll', 'orgChartExpandAll', 'orgChartZoomFit'):
            pg.click('#' + cid); time.sleep(.3)
        pg.fill('#orgChartSearch', 'manager'); time.sleep(.5); pg.press('#orgChartSearch', 'Enter'); time.sleep(.5)
        check(f'{tag}: search finds matches and navigates', 'match' in pg.inner_text('#orgChartSearchCounter'))
        # keyboard on a toggle
        pg.click('#orgChartCollapseAll'); time.sleep(.5)
        n0 = pg.evaluate("() => document.querySelectorAll('.org-d3-person, .org-d3-group').length")
        pg.evaluate("() => (document.querySelector('.org-d3-toggle[aria-expanded=false]') || document.querySelector('.org-d3-toggle')).focus()")
        focused = pg.evaluate("() => document.activeElement.classList.contains('org-d3-toggle')")
        pg.keyboard.press('Enter'); time.sleep(.6)
        n1 = pg.evaluate("() => document.querySelectorAll('.org-d3-person, .org-d3-group').length")
        check(f'{tag}: a toggle takes keyboard focus and Enter toggles it', focused and n1 != n0, (focused, n0, n1))
        check(f'{tag}: no JS errors', not errs, errs)
        b.close()
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
