"""Training overview: KPI grid never clips, zero counts are not alarming, trend chart is honest and readable, filters really filter. Exit 0 = pass."""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
DEPTS = [r[0] for r in mysql("select client_id from clients where client_id between 16 and 22 order by client_id")]
def seed(points):
    """points: list of (date, current, required) applied to every department; [] clears all snapshots."""
    mysql("delete from training_compliance_daily")
    for d, cur, req in points:
        mysql(f"insert into training_compliance_daily (tdaily_date,tdaily_client_id,tdaily_course_id,tdaily_eligible_people,tdaily_required_pairs,tdaily_current_pairs) select '{d}', client_id, 0, 3, {req}, {cur} from clients where client_id between 16 and 22")
today = mysql("select curdate()")[0][0]
import datetime
def month_end(back):
    d = datetime.date.fromisoformat(today).replace(day=1)
    for _ in range(back): d = (d - datetime.timedelta(days=1)).replace(day=1)
    return (d - datetime.timedelta(days=1)).isoformat() if back else today
p = sync_playwright().start()
b, ctx, pg = login(p, 'admin')
msgs = []; pg.on('console', lambda m: msgs.append(m.text) if m.type == 'error' else None)
def goto(qs='', w=1920, h=1200):
    pg.set_viewport_size({'width': w, 'height': h}); pg.goto(BASE + '/agent/training_dashboard.php' + qs, wait_until='domcontentloaded'); time.sleep(1.8)
# ---- KPI grid at every width ----
seed([(month_end(1), 0, 3), (today, 3, 3)])
for w in (2560, 1920, 1536, 1366, 1280, 1100, 900, 768, 600, 390):
    goto('', w)
    r = pg.evaluate("""() => { const c = document.querySelector('.trr-kpis').getBoundingClientRect(); const t = [...document.querySelectorAll('.trr-kpis > .trr-kpi')].map(e => e.getBoundingClientRect());
        return {clipped: t.filter(x => x.right > c.right + 1 || x.left < c.left - 1).length, n: t.length, cols: new Set(t.map(x => Math.round(x.left))).size, pageOver: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                inner: [...document.querySelectorAll('.trr-kpi')].filter(e => e.scrollWidth > e.clientWidth + 1).length} }""")
    check(f'{w}: KPI tiles fit the container (no clipped tile, no page scroll, no overflowing text)', r['clipped'] == 0 and r['pageOver'] <= 1 and r['inner'] == 0 and r['n'] == 5, r)
# ---- zero counts ----
goto('')
r = pg.evaluate("() => [...document.querySelectorAll('.trr-kpi')].map(k => ({t: k.querySelector('.trr-kpi__label').innerText.trim(), icon: (k.querySelector('.trr-kpi__icon')||{}).className||'', alert: k.classList.contains('trr-kpi--alert'), v: k.querySelector('.trr-kpi__value').innerText.trim()}))")
ov = [x for x in r if x['t'].startswith('Overdue')][0]; ex = [x for x in r if x['t'].startswith('Expiring')][0]
check('overdue > 0: red icon and alert border', int(ov['v']) > 0 and 'icon--err' in ov['icon'] and ov['alert'], ov)
check('expiring = 0: neutral icon, not amber', ex['v'] == '0' and 'icon--neutral' in ex['icon'], ex)
mysql("update training_assignments set tassign_status='cancelled' where tassign_status='open' and tassign_due_at < now()") if False else None
# a department with nothing overdue
quiet = None
for d in DEPTS:
    goto(f'?client_id={d}')
    k = pg.evaluate("() => { const o = [...document.querySelectorAll('.trr-kpi')].find(k => k.querySelector('.trr-kpi__label').innerText.trim().startsWith('Overdue')); return o ? {v: o.querySelector('.trr-kpi__value').innerText.trim(), icon: o.querySelector('.trr-kpi__icon').className, alert: o.classList.contains('trr-kpi--alert')} : null }")
    if k and k['v'] == '0': quiet = (d, k); break
check('overdue = 0 (a department with none): green check icon and no alert border', quiet is not None and 'icon--ok' in quiet[1]['icon'] and not quiet[1]['alert'], quiet)
# ---- chart honesty and legibility ----
def canvas_edges(pg):
    return pg.evaluate("""() => { const c = document.getElementById('trr-trend-chart'); if (!c) return null; const g = c.getContext('2d'); const w = c.width, h = c.height;
        const ink = (x, y, ww, hh) => { const d = g.getImageData(x, y, ww, hh).data; let n = 0; for (let i = 3; i < d.length; i += 4) if (d[i] > 20) n++; return n };
        return {total: ink(0, 0, w, h), top: ink(0, 0, w, 2), left: ink(0, 0, 2, h), right: ink(w - 2, 0, 2, h), bottom: ink(0, h - 2, w, 2), labels: window.Chart.getChart(c).data.labels.length} }""")
for name, pts, note in (('2 points (Sep 0%, now 100%)', [(month_end(1), 0, 3), (today, 3, 3)], True),
                        ('6 points', [(month_end(i), [1, 1, 2, 2, 3, 3][i], 3) for i in range(1, 6)] + [(today, 3, 3)], True),
                        ('12 points, full history', [(month_end(i), 1 + (i % 2), 3) for i in range(1, 12)] + [(today, 3, 3)], False)):
    seed(pts)
    for theme, who in (('light', 'admin'),):
        goto('')
        e = canvas_edges(pg)
        check(f'trend {name}: nothing is clipped at the canvas edges (labels inside the plot)', e is not None and e['total'] > 1500 and e['top'] == 0 and e['right'] == 0 and e['left'] == 0, e)
        has_note = pg.locator('.trr-trend__note').count() == 1
        check(f'trend {name}: history note {"shown" if note else "absent"}', has_note == note)
        rows = pg.locator('table.visually-hidden tbody tr').count()
        al = pg.get_attribute('#trr-trend-chart', 'aria-label') or ''
        check(f'trend {name}: text alternative lists every month and the values', rows == 12 and '%' in al and 'target' in al, (rows, al[:80]))
        if out and name.startswith('2'): pg.screenshot(path=f'{out}/overview_trend_2pt.png', clip={'x': 256, 'y': 380, 'width': 1100, 'height': 480})
seed([])
goto('')
check('no snapshots: placeholder instead of a broken chart', pg.locator('.trr-placeholder').count() == 1 and pg.locator('#trr-trend-chart').count() == 0)
# ---- filters ----
seed([(month_end(1), 1, 3), (today, 2, 3)])
goto('')
all_roster = int(re.search(r'(\d+) people? on the roster', pg.inner_text('.trr-meta')).group(1))
dept = DEPTS[1]
want = int(mysql(f"select count(*) from contacts c left join training_roster r on r.roster_contact_id=c.contact_id where c.contact_client_id={dept} and c.contact_archived_at is null and COALESCE(r.roster_state,'auto')<>'exclude'")[0][0])
pg.select_option('select[name=client_id]', str(dept)); pg.wait_for_load_state('domcontentloaded'); time.sleep(1.5)
got = int(re.search(r'(\d+) people? on the roster', pg.inner_text('.trr-meta')).group(1))
check('Department filter: roster count matches the database for that department', got == want and got != all_roster and f'client_id={dept}' in pg.url, (got, want, all_roster))
check('Department filter: selection survives reload (and is in the URL)', (pg.reload(wait_until='domcontentloaded'), time.sleep(1.2), pg.eval_on_selector('select[name=client_id]', 'e => e.value'))[2] == str(dept))
course = mysql("select course_id from training_courses where course_published_at is not null limit 1")[0][0] if mysql("select count(*) from information_schema.columns where table_schema=DATABASE() and table_name='training_courses' and column_name='course_published_at'")[0][0] == '1' else None
if course:
    pg.select_option('select[name=course_id]', str(course)); pg.wait_for_load_state('domcontentloaded'); time.sleep(1.2)
    check('Course filter: applied and shown in the URL', f'course_id={course}' in pg.url)
goto('?months=6')
e = canvas_edges(pg)
check('Period filter: 6 months gives a 6-month chart', e is not None and e['labels'] == 6 and pg.eval_on_selector('select[name=months]', 'e => e.value') == '6', e)
goto('?client_id=99999&course_id=99999&months=7')
check('out-of-range filter values are ignored, not an error page', 'could not be calculated' not in pg.inner_text('body') and pg.locator('.trr-kpis').count() == 1)
# ---- dark mode ----
bd, cd, pd = login(p, 'admin_d'); pd.set_viewport_size({'width': 1920, 'height': 1200})
pd.goto(BASE + '/agent/training_dashboard.php', wait_until='domcontentloaded'); time.sleep(2)
if out: pd.screenshot(path=f'{out}/overview_dark.png', full_page=False)
e = canvas_edges(pd)
check('dark: chart drawn and not clipped', e is not None and e['total'] > 1500 and e['top'] == 0 and e['right'] == 0, e)
bd.close()
check('no console errors', not [m for m in msgs if 'favicon' not in m], msgs[:3])
b.close(); seed([])
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
