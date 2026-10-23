"""RMM pages on the shared kit (scratch RMM install): gutters, equal card radius, no sideways page scroll, KPI/toolbar/table geometry, Starter pack + Starter policies
behaviour, script form buttons/help, Hypervisors, Software report. Light/dark, 1920/1366/768. Exit 0 = pass. usage: rmm_ui_check.py [shots_dir]"""
from common import *
import subprocess
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
for sql in ('seed_rmm_alerts.sql', 'seed_rmm_hv.sql'):
    subprocess.run(f'/home/sysadmin/demos/mysqlroot.sh {DB} < {HERE}/{sql}', shell=True, capture_output=True)
PAGES = ['/agent/alerts.php', '/agent/rmm_starter_pack.php', '/agent/rmm_policies.php', '/agent/rmm_script_library.php?new=1', '/agent/rmm_hypervisors.php', '/agent/rmm_software_report.php',
         '/agent/rmm_software.php', '/agent/rmm_software_assignments.php', '/agent/rmm_dashboard.php', '/agent/rmm_fleet.php', '/agent/rmm_patches.php', '/agent/rmm_logs.php', '/agent/rmm_schedules.php', '/agent/rmm_approvals.php']
GEO = """() => { const main = document.querySelector('.content-wrapper, main, .page-body') || document.body; const mr = main.getBoundingClientRect();
  const cards = [...document.querySelectorAll('.rmmx-card, .rmmx-uicard, .rmmx-toolbar, .rmmx-kpi, .rmmx-info')].filter(e => e.offsetParent);
  const lefts = cards.map(c => c.getBoundingClientRect().left - mr.left); const rights = cards.map(c => mr.right - c.getBoundingClientRect().right);
  const radii = [...new Set(cards.filter(c => c.classList.contains('rmmx-card') || c.classList.contains('rmmx-uicard')).map(c => getComputedStyle(c).borderTopLeftRadius))];
  return {n: cards.length, minLeft: lefts.length ? Math.min(...lefts) : 99, minRight: rights.length ? Math.min(...rights) : 99, radii, overflow: document.documentElement.scrollWidth - innerWidth,
          squareLeft: cards.filter(c => (c.classList.contains('rmmx-card') || c.classList.contains('rmmx-uicard')) && parseFloat(getComputedStyle(c).borderTopLeftRadius) < 4).length} }"""
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    for (w, h) in ((1920, 1080), (1366, 768), (768, 1024)):
        b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h}); tag = f'{theme} {w}x{h}'
        for path in PAGES:
            pg.goto(BASE + path, wait_until='domcontentloaded'); time.sleep(1.1)
            g = pg.evaluate(GEO)
            check(f'{tag} {path.split("?")[0]}: cards keep a gutter (>=8px each side), same radius, nothing square, no sideways page scroll',
                  g['minLeft'] >= 8 and g['minRight'] >= 0 and len(g['radii']) <= 1 and g['squareLeft'] == 0 and g['overflow'] <= 1, g)
        if (who, w) == ('admin', 1366):
            # Starter pack
            pg.goto(BASE + '/agent/rmm_starter_pack.php', wait_until='domcontentloaded'); time.sleep(1.2)
            pg.evaluate('window.scrollTo(0, 400)'); time.sleep(.3)
            r = pg.evaluate("""() => { const hs = [...document.querySelectorAll('#starter-filters .form-control, #starter-filters .form-select, #starter-filters .btn')].map(e => Math.round(e.getBoundingClientRect().height)).filter(x => x > 0);
              const hdr = document.getElementById('starter-all'), row = document.querySelector('.starter-pick'); const cx = e => { const r = e.getBoundingClientRect(); return r.left + r.width / 2 };
              const bar = document.getElementById('starter-actions').getBoundingClientRect(); const dis = [...document.querySelectorAll('#starter-actions .btn[aria-disabled=true]')].length;
              return {hs: [...new Set(hs)], dx: Math.abs(cx(hdr) - cx(row)), barBottom: bar.bottom <= innerHeight + 1, disabledCount: dis, kpiLinks: document.querySelectorAll('a.rmmx-kpi').length, pad: getComputedStyle(document.getElementById('starter-form')).paddingBottom} }""")
            check('starter pack: filter controls share one height, header/row checkboxes aligned, KPI cards are filter links, sticky bar inside the window, page leaves room under it',
                  max(r['hs']) - min(r['hs']) <= 3 and r['dx'] <= 1 and r['kpiLinks'] >= 5 and r['barBottom'] and float(r['pad'][:-2]) >= 60, r)
            pg.locator('.starter-pick').first.check(); time.sleep(.3)
            check('starter pack: ticking a row shows "Install selected (1)" and enables Plan/Install', 'Install selected (1)' in pg.inner_text('#starter-actions') and pg.locator('#starter-plan[aria-disabled=true]').count() == 0)
            pg.goto(BASE + '/agent/rmm_starter_pack.php?platform=linux', wait_until='domcontentloaded'); time.sleep(1)
            check('starter pack: a KPI filter link filters (Linux only) and shows a removable chip', pg.locator('.rmmx-chip').count() >= 1 and 'Windows' not in pg.inner_text('#starter-scripts tbody'))
            # Starter policies
            pg.goto(BASE + '/agent/rmm_policies.php', wait_until='domcontentloaded'); time.sleep(1.2)
            txt = pg.inner_text('#pack-card')
            check('starter policies: OS shown as an icon plus a name (Windows/Linux), no raw "Pack:" prefix, an Install action per not-installed row', 'Windows' in txt and 'Pack:' not in txt and pg.locator('#pack-items tbody tr[data-state=not_installed] button:has-text("Install")').count() >= 1)
            pg.locator('[data-pack-filter="installed"]').click(); time.sleep(.3)
            check('starter policies: the state filter hides non-matching rows and marks the active segment', pg.locator('#pack-items tbody tr[data-state]:visible').count() == 0 and pg.locator('[data-pack-filter="installed"][aria-pressed=true]').count() == 1)
            pg.locator('[data-pack-filter="all"]').click(); time.sleep(.2)
            pg.locator('.rmmx-group-toggle').first.click(); time.sleep(.2)
            check('starter policies: a group collapses and expands (aria-expanded)', pg.locator('.rmmx-group-toggle').first.get_attribute('aria-expanded') == 'false')
            # script form
            pg.goto(BASE + '/agent/rmm_script_library.php?new=1', wait_until='domcontentloaded'); time.sleep(1.2)
            r = pg.evaluate("""() => { const bs = [...document.querySelectorAll('#scr-editor .rmmx-bar .btn, #scr-editor .rmmx-add-row')].map(b => Math.round(b.getBoundingClientRect().height));
               const help = document.querySelector('.rmmx-section-help'); return {bs, clipped: help.scrollWidth > help.clientWidth + 1, empty: !!document.querySelector('.rmmx-param-empty') && getComputedStyle(document.querySelector('.rmmx-param-empty')).display !== 'none'} }""")
            check('script form: Publish / Cancel / Add a parameter are >= 38px, help text wraps inside its box, empty state shown', min(r['bs']) >= 38 and not r['clipped'] and r['empty'], r)
            pg.click('.rmmx-add-row'); time.sleep(.4)
            pg.fill('#pd_0_name', 'Bad Name'); pg.fill('#scr_name', 'QA-UI-x'); pg.fill('#scr_body', 'echo hi')
            pg.click('#scr-editor .rmmx-primary-cta'); time.sleep(.6)
            check('script form: an invalid parameter name is refused with the message shown under the field', pg.locator('#pd_0_name.is-invalid').count() == 1 and pg.locator('#pd_0_name_fb').is_visible())
            pg.fill('#pd_0_name', 'ok_name'); pg.click('#scr-editor .rmm-row-remove'); time.sleep(.3)
            check('script form: removing the only parameter brings the empty state back', pg.locator('.rmmx-param-empty').is_visible())
            # hypervisors
            pg.goto(BASE + '/agent/rmm_hypervisors.php', wait_until='domcontentloaded'); time.sleep(1.2)
            r = pg.evaluate("""() => ({kpis: document.querySelectorAll('.rmmx-kpi').length, sort: document.querySelectorAll('#hv-hosts th[aria-sort]').length, probRows: document.querySelectorAll('#hv-hosts tr.rmmx-sev-warning').length, sticky: getComputedStyle(document.querySelector('#hv-hosts thead th')).position,
               raw: /<time/.test(document.querySelector('#hv-hosts').innerText), open: [...document.querySelectorAll('#hv-hosts a[aria-label^="Open the device"]')].length})""")
            check('hypervisors: KPI cards (incl. hosts with problems), sortable headers with aria-sort, problem rows marked, sticky header, no raw markup, named open-device links', r['kpis'] >= 5 and r['sort'] == 4 and r['probRows'] >= 1 and r['sticky'] == 'sticky' and not r['raw'] and r['open'] >= 1, r)
            pg.locator('#hv-hosts .rmmx-sort[data-sort="run"]').click(); time.sleep(.3)
            runs = pg.evaluate("() => [...document.querySelectorAll('#hv-hosts tbody tr')].map(r => +r.dataset.run)")
            check('hypervisors: sorting by VMs running orders the rows and sets aria-sort', runs == sorted(runs) and pg.locator('#hv-hosts th[aria-sort=ascending]').count() == 1, runs)
            pg.goto(BASE + '/agent/rmm_hypervisors.php?problems=1', wait_until='domcontentloaded'); time.sleep(1)
            check('hypervisors: "Problems only" filter works and is shown as a chip', pg.locator('.rmmx-chip').count() >= 1)
            # software report
            pg.goto(BASE + '/agent/rmm_software_report.php', wait_until='domcontentloaded'); time.sleep(1.2)
            r = pg.evaluate("""() => { const ks = [...document.querySelectorAll('.rmmx-kpi')].map(k => k.getBoundingClientRect()); const hs = [...document.querySelectorAll('.rmmx-toolbar .form-control, .rmmx-toolbar .form-select, .rmmx-toolbar .btn')].map(e => Math.round(e.getBoundingClientRect().height)).filter(x => x > 0);
               return {n: ks.length, clipped: ks.filter(k => k.right > innerWidth).length, hs: [...new Set(hs)], empty: /Nothing is expected yet/.test(document.body.innerText) && !!document.querySelector('.rmmx-empty a.btn'), dash: /—/.test(document.querySelector('.rmmx-kpis').innerText)} }""")
            check('software report: 7 KPI tiles fit the width, toolbar controls one height, "no data" is an em dash, the empty state offers a next step', r['n'] == 7 and r['clipped'] == 0 and max(r['hs']) - min(r['hs']) <= 3 and r['empty'] and r['dash'], r)
            if out:
                for nm, path in (('starter', '/agent/rmm_starter_pack.php'), ('policies', '/agent/rmm_policies.php'), ('hv', '/agent/rmm_hypervisors.php'), ('swreport', '/agent/rmm_software_report.php'), ('script', '/agent/rmm_script_library.php?new=1'), ('alerts', '/agent/alerts.php')):
                    pg.goto(BASE + path, wait_until='domcontentloaded'); time.sleep(1); pg.screenshot(path=f'{out}/{nm}_{theme}_{w}.png')
        b.close()
print('\nFAILS:', len(fails)); sys.exit(1 if fails else 0)
