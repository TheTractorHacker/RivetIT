"""Unified Alerts centre (agent/alerts.php) on the scratch RMM install (RMM module on, own state dir): agent + RMM + backup + network in one list, counts, redirects of the
old URLs, one sidebar Alerts item with grouped Managed devices, per-source Acknowledge/Resolve/bulk. Scratch data only (QA-UI-). Exit 0 = pass."""
from common import *
import subprocess
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
subprocess.run(f'/home/sysadmin/demos/mysqlroot.sh {DB} < {HERE}/seed_rmm_alerts.sql', shell=True, capture_output=True)
def q1(sql):
    r = mysql(sql); return r[0][0] if r and r[0] else ''
p = sync_playwright().start(); b, ctx, pg = login(p, 'admin'); pg.set_viewport_size({'width': 1920, 'height': 1080})
def go(path): pg.goto(BASE + path, wait_until='domcontentloaded'); time.sleep(1)
def total():
    t = pg.evaluate("() => (document.querySelector('.rmmx-result-count') || {}).innerText || ''"); m = re.search(r'(\d+) alert', t); return int(m.group(1)) if m else 0

# --- counts: agent alerts come from BOTH stores, nothing is counted twice
go('/agent/alerts.php?source=agent&status=all')
agent_all = total()
exp = int(q1("select count(*) from rmm_alerts a join rmm_integrations i on i.id=a.integration_id and i.type='rivetit_agent'")) + int(q1("select count(*) from rmm_alert_meta m where not exists (select 1 from rmm_alerts a where a.id=m.alert_id)"))
check('agent source lists every endpoint-agent alert once (mirror rows + meta-only records)', agent_all == exp and agent_all >= 11, (agent_all, exp))
rows = pg.locator('#alertsTable tbody tr').count()
check('the table shows what the count says (one page)', rows == min(agent_all, 50), (rows, agent_all))
check('a meta-only (orphan) agent alert is listed', 'orphan agent alert' in pg.inner_text('body'))
for st, expect in (('new', 8), ('acknowledged', 2), ('resolved', 1)):
    go(f'/agent/alerts.php?source=agent&status={st}'); n = total()
    # mirror drift is healed on load, so the page, the meta record and the mirror row agree
    check(f'agent state {st}: {expect} alerts (meta record is authoritative; the drifted mirror row was healed)', n == expect, n)
go('/agent/alerts.php?source=agent&status=new&severity=critical'); check('agent open critical = 8', total() == 8, total())
go('/agent/alerts.php?source=agent&status=new&severity=warning'); check('severity filter: no open agent warnings in the seed', total() == 0)
check('drifted mirror row healed (rmm_alerts now says resolved)', q1("select status from rmm_alerts where tactical_alert_id='qa-ui-8'") == 'resolved')
go('/agent/alerts.php?status=all'); all_n = total()
parts = 0
for s in ('agent', 'rmm', 'backup'):
    go(f'/agent/alerts.php?status=all&source={s}'); parts += total()
check('all sources = agent + rmm + backup (network is a view of rmm rows)', all_n == parts, (all_n, parts))
# KPI card and sidebar badge agree
go('/agent/alerts.php')
kpi = pg.evaluate("() => [...document.querySelectorAll('.rmmx-kpi')].map(k => [k.querySelector('.rmmx-kpi-label').innerText, parseInt(k.querySelector('.rmmx-kpi-value').innerText)])")
badge = pg.evaluate("() => { const b = document.querySelector('a.nav-link[href=\"/agent/alerts.php\"] .badge'); return b ? parseInt(b.innerText) : 0 }")
crit = dict(kpi).get('Critical + error open')
check('sidebar Alerts badge equals the "Critical + error open" card', badge == crit, (badge, crit))
check('card values are consistent with the list (new alerts card = list total)', total() == dict(kpi)['New alerts'], (total(), kpi))
# --- old URLs redirect, filters carried
def loc(path):
    r = ctx.request.get(BASE + path, max_redirects=0); return r.status, r.headers.get('location', '')
for path, want in (('/agent/rmm_alerts.php?status=resolved&severity=critical&client_id=16', '/agent/alerts.php?status=resolved&severity=critical&client_id=16'),
                   ('/agent/rmm_alerts.php', '/agent/alerts.php'),
                   ('/agent/rmm_alerts.php?asset_id=92&status=all', '/agent/alerts.php?status=all&asset_id=92'),
                   ('/agent/rmm_agent_alerts.php', '/agent/alerts.php?source=agent&status=active'),
                   ('/agent/rmm_agent_alerts.php?state=open&severity=crit&view=check', '/agent/alerts.php?source=agent&status=new&severity=critical&group=check')):
    st, l = loc(path); check(f'redirect {path} -> {want}', st == 302 and l == want, (st, l))
aid = q1("select id from rmm_alerts where tactical_alert_id='qa-ui-1'")
st, l = loc(f'/agent/rmm_agent_alerts.php?alert_id={aid}'); check('an agent alert detail link still opens the detail page (no redirect)', st == 200, (st, l))
# --- sidebar
go('/agent/alerts.php')
nav = pg.evaluate("() => ({alerts: [...document.querySelectorAll('aside a')].filter(a => a.innerText.trim().startsWith('Alerts') || /alerts\\.php$/.test(a.getAttribute('href') || '')).map(a => a.getAttribute('href')),\
  texts: [...document.querySelectorAll('aside a')].map(a => a.innerText.trim()), groups: [...document.querySelectorAll('details.nav-subgroup summary')].map(s => s.innerText.trim()), hrefs: [...document.querySelectorAll('aside a')].map(a => a.getAttribute('href'))})")
check('exactly one Alerts item in the sidebar, no "Agent alerts" / "RMM Alerts"', nav['alerts'].count('/agent/alerts.php') == 1 and 'Agent alerts' not in nav['texts'] and 'RMM Alerts' not in nav['texts'], nav['alerts'])
check('Managed devices has the five groups', set(nav['groups']) >= {'Overview', 'Devices', 'Automation', 'Patching & software', 'Settings & logs'}, nav['groups'])
must = ['/agent/rmm_dashboard.php', '/agent/rmm_fleet.php', '/agent/rmm_assets.php', '/agent/rmm_hypervisors.php', '/agent/rmm_add_device.php', '/agent/rmm_policies.php', '/agent/rmm_script_library.php', '/agent/rmm_starter_pack.php',
        '/agent/rmm_schedules.php', '/agent/rmm_approvals.php', '/agent/rmm_maintenance.php', '/agent/rmm_escalations.php', '/agent/rmm_log_rules.php', '/agent/rmm_patches.php', '/agent/rmm_patch_updates.php',
        '/agent/rmm_patch_policies.php', '/agent/rmm_software.php', '/agent/rmm_software_assignments.php', '/agent/rmm_software_report.php', '/agent/rmm_fields.php', '/agent/rmm_logs.php', '/agent/rmm_checks.php', '/agent/network.php']
missing = [m for m in must if m not in nav['hrefs']]
check('no RMM page was dropped from the sidebar (every one still reachable)', not missing, missing)
# --- actions per source
def row_action(source, aid_, action):
    sel = f'.js-alert-action[data-source="{source}"][data-alert-id="{aid_}"][data-alert-action="{action}"]'
    pg.locator(sel).first.click(); time.sleep(1.6)
a1 = q1("select id from rmm_alerts where tactical_alert_id='qa-ui-1'")
go('/agent/alerts.php?source=agent'); row_action('agent', a1, 'acknowledge')
check('agent Acknowledge goes through RivetCore (meta record + mirror row both acknowledged)', q1(f"select state from rmm_alert_meta where alert_id={a1} order by meta_id desc limit 1") == 'acknowledged' and q1(f"select status from rmm_alerts where id={a1}") == 'acknowledged',
      (q1(f"select state from rmm_alert_meta where alert_id={a1}"), q1(f"select status from rmm_alerts where id={a1}")))
go('/agent/alerts.php?source=agent&status=acknowledged'); row_action('agent', a1, 'resolve')
check('agent Resolve (meta + mirror)', q1(f"select state from rmm_alert_meta where alert_id={a1} order by meta_id desc limit 1") == 'resolved' and q1(f"select status from rmm_alerts where id={a1}") == 'resolved')
r1 = q1("select id from rmm_alerts a where status='new' and integration_id=1 order by id limit 1")
go('/agent/alerts.php?source=rmm'); row_action('rmm', r1, 'acknowledge')
check('RMM-integration Acknowledge', q1(f"select status from rmm_alerts where id={r1}") == 'acknowledged')
c1 = q1("select alert_id from comet_backup_alerts where alert_status='new' order by alert_id limit 1")
if c1:
    go('/agent/alerts.php?source=backup'); row_action('backup', c1, 'acknowledge')
    check('Backup Acknowledge', q1(f"select alert_status from comet_backup_alerts where alert_id={c1}") == 'acknowledged')
go('/agent/alerts.php?source=agent&status=new')
boxes = pg.locator('.alert-chk'); n = min(2, boxes.count()); ids = []
for i in range(n): ids.append(boxes.nth(i).get_attribute('data-id')); boxes.nth(i).check()
check('selecting rows shows the sticky bulk bar with a count', pg.locator('#alertsBar').is_visible() and f'{n} selected' in pg.inner_text('#alertsBarCount'))
pg.locator('.js-bulk-alert-action[data-bulk-action="acknowledge"]').click(); time.sleep(.8)
pg.locator('.modal.show .btn-primary, .modal.show .btn-danger, .rivet-dialog .btn-primary').first.click(); time.sleep(2.5)
check('bulk Acknowledge updates every selected alert', all((q1(f"select status from rmm_alerts where id={i}") or q1(f"select state from rmm_alert_meta where alert_id={i} order by meta_id desc limit 1")) in ('acknowledged',) for i in ids), ids)
# --- a11y + layout
go('/agent/alerts.php?status=all')
r = pg.evaluate("() => ({unlabeled: [...document.querySelectorAll('#alertsTable input[type=checkbox]')].filter(c => !c.getAttribute('aria-label')).length, scope: document.querySelectorAll('#alertsTable th[scope=col]').length > 5,\
   sticky: getComputedStyle(document.querySelector('#alertsTable thead th')).position, h: [...document.querySelectorAll('.rmmx-toolbar .form-control, .rmmx-toolbar .form-select, .rmmx-toolbar .btn')].map(e => Math.round(e.getBoundingClientRect().height))})")
check('checkboxes named, column headers scoped, sticky header, toolbar controls one height', r['unlabeled'] == 0 and r['scope'] and r['sticky'] == 'sticky' and max(r['h']) - min(r['h']) <= 2, r)
# --- 5,000 alerts stay fast
mysql("insert into rmm_alerts (asset_id, client_id, integration_id, tactical_alert_id, severity, status, message, created_at) select 92, 16, 1, concat('qa-ui-bulk-', seq), 'warning', 'new', concat('QA-UI- bulk alert ', seq), now() - interval seq minute from (select a.n + b.n*10 + c.n*100 + d.n*1000 as seq from (select 0 n union select 1 union select 2 union select 3 union select 4 union select 5 union select 6 union select 7 union select 8 union select 9) a, (select 0 n union select 1 union select 2 union select 3 union select 4 union select 5 union select 6 union select 7 union select 8 union select 9) b, (select 0 n union select 1 union select 2 union select 3 union select 4 union select 5 union select 6 union select 7 union select 8 union select 9) c, (select 0 n union select 1 union select 2 union select 3 union select 4) d) s")
t0 = time.time(); go('/agent/alerts.php?status=all'); dt = time.time() - t0
check('5,000 extra alerts: the page still answers in under 4 s and paginates (50 rows)', dt < 4 and pg.locator('#alertsTable tbody tr').count() == 50 and 'Page 1 of' in pg.inner_text('body'), (round(dt, 2), pg.locator('#alertsTable tbody tr').count()))
mysql("delete from rmm_alerts where tactical_alert_id like 'qa-ui-bulk-%'")
if out: go('/agent/alerts.php'); pg.screenshot(path=f'{out}/alerts_light_1920.png')
b.close()
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
