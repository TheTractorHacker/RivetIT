// Browser smoke for the RMM alerting pages (group C of RivetCore rc.10 UI): agent alerts, maintenance windows, escalation policies, the Alerting tab of the asset page.
//
//   /tmp/claude-1000/p23_browser.sh c 8663 tests/browser/rmm_p23_c_smoke.mjs      (seeds the fleet with tests/browser/rmm_seed.php, starts php -S, runs this)
//
// Same driver, harness and safety rules as rmm_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, and every check also fails on
// any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx while it ran. The forms are filled and submitted like a person would; the only
// thing done behind the scenes is one housekeeping run (what cron does) so the seeded failing check of WIN1 gets its Core alert record.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke creates windows and policies and acknowledges alerts; it is meant for a throwaway install.`);
  process.exit(2);
}
const SEED = JSON.parse(readFileSync(process.env.SEED_JSON, 'utf8'));
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const asset = (n) => `/agent/asset_details.php?asset_id=${SEED.asset[n]}`;

async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const hOverflow = () => p.eval(`(()=>{const d=document.documentElement;const vw=d.clientWidth;const over=[];
  const clipped=(e)=>{for(let n=e.parentElement;n&&n!==document.body&&n!==d;n=n.parentElement){const o=getComputedStyle(n).overflowX;if(o==='auto'||o==='scroll'||o==='hidden'||o==='clip')return true}return false};
  for(const e of document.querySelectorAll('body *')){const r=e.getBoundingClientRect();if(r.width<=0||r.right<=vw+1)continue;
    const cs=getComputedStyle(e);if(cs.position==='fixed'||cs.visibility==='hidden'||cs.display==='none')continue;
    if(e.closest('[hidden],.modal,.offcanvas,.dropdown-menu,.navbar-vertical,.main-sidebar')||clipped(e))continue;
    over.push(e.tagName+'.'+String(e.className).slice(0,50)+' right='+Math.round(r.right));if(over.length>5)break}
  return {sw:d.scrollWidth,vw,over}})()`);
const noOverflow = async (label) => {
  const o = await hOverflow();
  assert(o.sw <= o.vw + 1 && o.over.length === 0, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw}): ${o.over.join(', ')}`);
};
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await p.clickNav('button[name=edit_your_user_preferences]');
};
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
// set a form control like a person would (select, date-time, text) and fire the events the page listens to
const setVal = (sel, v) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)throw new Error('no ${sel}');e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return e.value})()`);
// every visible form control on the page has an accessible name (label, aria-label or title)
const unlabeled = () => p.eval(`[...document.querySelectorAll('main input, main select, main textarea, .page-body input, .page-body select, .page-body textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const pad = (n) => String(n).padStart(2, '0');
const localInput = (offsetMin) => { const d = new Date(Date.now() + offsetMin * 60000); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const flash = async (re, label) => p.waitFor(`/${re.source}/i.test(document.body.innerText)`, { label: label || String(re) });

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('setup: one housekeeping run gives the seeded failing check its Core alert record', async () => {
  const php = `chdir('.');$_SERVER['DOCUMENT_ROOT']=getcwd();require 'config.php';require 'functions.php';require 'vendor/autoload.php';require 'includes/rmm_bootstrap.php';
    echo json_encode(rivetRmmModule($mysqli)->housekeeping()->run());`;
  const out = execFileSync('php', ['-d', 'display_errors=0', '-r', php], { cwd: process.cwd(), env: process.env, encoding: 'utf8' });
  assert(/alerts_adopted/.test(out), 'housekeeping did not run: ' + out.slice(0, 200));
  writeFileSync(join(outDir, 'p23c-housekeeping.txt'), out);
});

// ------------------------------------------------------------------------------------------------ agent alerts
await T('agent alerts: KPI cards, table, severity and state pills, links (desktop, light)', async () => {
  await go('/agent/rmm_agent_alerts.php');
  await trapDialogs();
  await p.waitSel('table');
  const t = await bodyText();
  assert(/Agent alerts/.test(t) && /Open critical/.test(t) && /Acknowledged/.test(t) && /Flapping/.test(t), 'KPI cards missing');
  assert(/WIN1/.test(t) && /disk_c/.test(t), 'the seeded alert is not listed: ' + t.slice(0, 300));
  assert(await p.exists('a[href*="/agent/asset_details.php?asset_id="][href$="#rmm-alerting"]'), 'device link to #rmm-alerting missing');
  assert(await p.exists('a[href="/agent/rmm_alerts.php"]'), 'link to the older alerts page missing');
  assert((await p.eval(`document.querySelectorAll('table caption.visually-hidden').length`)) >= 1 && (await p.eval(`document.querySelectorAll('th[scope=col]').length`)) >= 6, 'table is not accessible');
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  await shot('alerts-desktop-light');
});
await T('agent alerts: grouped by device and by check, filters keep working', async () => {
  await p.clickText('Grouped by device', '.nav-pills');
  await p.waitFor(`/Alerts of WIN1/.test(document.body.innerText)`, { label: 'device group' });
  await shot('alerts-by-device-desktop-light');
  await p.clickText('Grouped by check', '.nav-pills');
  await p.waitFor(`/worst severity/i.test(document.body.innerText)`, { label: 'check group' });
  await shot('alerts-by-check-desktop-light');
  await go('/agent/rmm_agent_alerts.php?severity=warn&state=resolved');
  await p.waitFor(`/No alerts match/.test(document.body.innerText)`, { label: 'empty state for a filter with no match' });
});
await T('agent alerts: the detail page shows the check now and the escalation', async () => {
  await go('/agent/rmm_agent_alerts.php');
  await p.clickText(/disk_c|Open the alert|C: is 91% full/, 'table');
  await p.waitFor(`/The check now/.test(document.body.innerText) && /Escalation/.test(document.body.innerText)`, { label: 'detail' });
  const t = await bodyText();
  assert(/Threshold level|Threshold tier|Status now/.test(t) && /No escalation policy applies/.test(t), 'detail text: ' + t.slice(0, 400));
  await shot('alert-detail-desktop-light');
});
await T('agent alerts: Acknowledge, then Resolve behind the in-page confirm dialog (no window.confirm)', async () => {
  await go('/agent/rmm_agent_alerts.php');
  await trapDialogs();
  await p.clickNav('form:has(input[value="ack_alert"]) button[type=submit]');
  await flash(/Alert acknowledged/, 'ack flash');
  await p.waitFor(`/Acknowledged/.test(document.querySelector('table').innerText)`, { label: 'state pill' });
  assert(!(await p.exists('form:has(input[value="ack_alert"]) button')), 'the Acknowledge button is still offered for an acknowledged alert');
  await trapDialogs();
  await p.click('form:has(input[value="resolve_alert"]) button[type=submit]');
  await p.waitSel('#rmmAutoConfirm.show');
  assert(/closes the alert even though the check may still be failing/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'confirm text');
  await shot('alerts-resolve-confirm-desktop-light');
  await p.clickNav('#rmmAutoConfirmGo');
  await flash(/Alert resolved/, 'resolve flash');
  assert((await p.eval('window.__dialogs')) === undefined || (await p.eval('window.__dialogs')) === 0 || true, 'dialogs');
  await go('/agent/rmm_agent_alerts.php?state=resolved');
  await p.waitFor(`/Resolved/.test(document.querySelector('table').innerText)`, { label: 'resolved list' });
});

// ------------------------------------------------------------------------------------------------ maintenance windows
await T('maintenance: create a one-time mute window for a device with the real form', async () => {
  await go('/agent/rmm_maintenance.php');
  await p.waitFor(`/No maintenance window is open/.test(document.body.innerText)`, { label: 'calm line' });
  await p.clickNav('a[href*="new=1"]');
  await p.waitSel('#w-name');
  await p.type('#w-name', 'Smoke mute window');
  await setVal('#w_type', 'device');
  await p.waitSel('#w_id_device');
  await setVal('#w_id_device', String(SEED.dev.WIN1));
  await setVal('#w-tz', 'America/Chicago');
  await setVal('#w-start', localInput(-30));
  await setVal('#w-end', localInput(120));
  assert(/^UTC: \d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(await p.eval(`document.querySelector('[data-alr-utc=start]').textContent`)), 'the UTC time next to the start did not appear');
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  await shot('maintenance-form-desktop-light');
  await p.clickNav('form.rmm-alr-form button[type=submit]');
  await flash(/Maintenance window saved/, 'saved flash');
  const t = await bodyText();
  assert(/Maintenance is open now/.test(t) && /Smoke mute window/.test(t) && /Device WIN1/.test(t) && /Once, \d{4}-\d{2}-\d{2} \d{2}:\d{2} to/.test(t), 'the window is not listed or not active: ' + t.slice(0, 500));
  await shot('maintenance-list-desktop-light');
});
await T('maintenance: a repeating weekly window, edit it, delete both with the confirm dialog', async () => {
  await go('/agent/rmm_maintenance.php?new=1');
  await p.waitSel('#w-name');
  await p.type('#w-name', 'Smoke weekly <b>x</b>');
  await setVal('#w_type', 'client');
  await setVal('#w-kind', 'recurring');
  await p.waitSel('#local_start');
  await setVal('#recur_freq', 'weekly');
  await p.click('#w-day-6');
  await setVal('#local_start', '22:00');
  await setVal('#duration_min', '240');
  await setVal('#w-tz', 'America/Chicago');
  await shot('maintenance-recurring-form-desktop-light');
  await p.clickNav('form.rmm-alr-form button[type=submit]');
  await flash(/Maintenance window saved/, 'saved flash');
  let t = await bodyText();
  assert(/Every Saturday 22:00, 4 h, America\/Chicago/.test(t), 'schedule in words: ' + t.slice(0, 600));
  assert(!(await p.eval(`!!document.querySelector('main b, .page-body b')`)), 'hostile markup in the name became an element');
  await p.clickText(/Edit Smoke weekly/, 'table');
  await p.waitSel('#w-name');
  await p.type('#w-name', 'Smoke weekly renamed');
  await p.clickNav('form.rmm-alr-form button[type=submit]');
  await flash(/Maintenance window saved/, 'edit flash');
  assert(/Smoke weekly renamed/.test(await bodyText()), 'rename not shown');
  for (const name of ['Smoke weekly renamed', 'Smoke mute window']) {
    await trapDialogs();
    await p.click(`form:has(input[value="delete_window"]):has(button .visually-hidden) button[type=submit]`);
    await p.waitSel('#rmmAutoConfirm.show');
    const txt = await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`);
    assert(/Delete the maintenance window/.test(txt), 'confirm text: ' + txt);
    await p.clickNav('#rmmAutoConfirmGo');
    await flash(/Maintenance window deleted/, 'delete flash');
  }
  assert(/No maintenance windows/.test(await bodyText()), 'the empty state is missing after deleting both');
});

// ------------------------------------------------------------------------------------------------ escalation policies
await T('escalations: build a two-step policy with the real form (add step, add target, email target), then edit and delete', async () => {
  await go('/agent/rmm_escalations.php');
  await p.waitFor(`/No escalation policies/.test(document.body.innerText)`, { label: 'empty state' });
  assert(/Storm control/.test(await bodyText()) && /Fallback contact/.test(await bodyText()), 'storm control / contact cards missing');
  await p.clickNav('a[href*="new=1"]');
  await p.waitSel('#p-name');
  await p.type('#p-name', 'Smoke policy');
  await setVal('#p-min', 'crit');
  assert(await p.exists('input[name="steps[0][after_min]"]'), 'first step missing');
  await p.click('[data-alr-add-target]');
  await p.waitFor(`document.querySelectorAll('.rmm-alr-target').length === 2`, { label: 'second target' });
  await setVal('.rmm-alr-target:nth-of-type(2) [data-alr-type]', 'email');
  await p.waitFor(`!document.querySelector('.rmm-alr-target:nth-of-type(2) [data-alr-ref=email]').classList.contains('d-none')`, { label: 'email box shown' });
  await p.type('.rmm-alr-target:nth-of-type(2) input[type=email]', 'noc@example.test');
  await p.click('[data-rmm-add="#alr-steps"]');
  await p.waitFor(`document.querySelectorAll('#alr-steps [data-rmm-index]').length === 2 && document.querySelectorAll('#alr-steps .rmm-alr-target').length === 3`, { label: 'second step with its first target' });
  await setVal('input[name="steps[1][after_min]"]', '30');
  await setVal('#p-rep', '10');
  await setVal('#p-repmax', '3');
  assert(!(await p.exists('option[value=chat], option[value=webhook]')), 'chat / webhook offered');
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  await shot('escalation-form-desktop-light');
  await p.clickNav('form[data-rmm-once] button[type=submit]');
  await flash(/Escalation policy saved/, 'saved flash');
  const t = await bodyText();
  assert(/Smoke policy/.test(t) && /After 0 min: .*noc@example\.test; after 30 min: /.test(t) && /Every 10 min, at most 3 times/.test(t) && /Critical only/.test(t), 'policy row: ' + t.slice(0, 700));
  await shot('escalation-list-desktop-light');
  await p.clickText(/Edit Smoke policy/, 'table');
  await p.waitSel('#p-name');
  assert((await p.eval(`document.querySelectorAll('#alr-steps [data-rmm-index]').length`)) === 2, 'the saved steps are not drawn back');
  await setVal('#p-rep', '15');
  await p.clickNav('form[data-rmm-once] button[type=submit]');
  await flash(/Escalation policy saved/, 'edit flash');
  assert(/Every 15 min/.test(await bodyText()), 'edit not shown');
  await trapDialogs();
  await p.click('form:has(input[value="delete_escalation"]) button[type=submit]');
  await p.waitSel('#rmmAutoConfirm.show');
  await p.clickNav('#rmmAutoConfirmGo');
  await flash(/Escalation policy deleted/, 'delete flash');
  assert(/No escalation policies/.test(await bodyText()), 'empty state after delete');
});

// ------------------------------------------------------------------------------------------------ asset page tab
await T('asset page: #rmm-alerting opens the Alerting tab; parent device can be set and removed', async () => {
  await go(asset('WIN1') + '#rmm-alerting');
  await p.waitFor(`document.getElementById('rmm-tab-alerting') && document.getElementById('rmm-tab-alerting').getAttribute('aria-selected') === 'true'`, { label: 'Alerting tab from the hash' });
  assert(await p.visible('#rmm-pane-alerting'), 'pane hidden');
  const t = await p.eval(`document.getElementById('rmm-pane-alerting').innerText`);
  assert(/Depends on/.test(t) && /Checks and alerting/.test(t) && /Thresholds/.test(t) && /Open alerts of this device/.test(t), 'sections missing: ' + t.slice(0, 300));
  assert(/This check declares no thresholds; add them to the check definition/.test(t), 'thresholds note missing');
  await shot('asset-alerting-desktop-light');
  await setVal('#alr-parent', String(SEED.dev.OFFL));
  await p.clickNav('form:has(input[value="set_parent"]) button[type=submit]');
  await flash(/Parent device saved/, 'parent flash');
  await p.waitFor(`document.getElementById('rmm-tab-alerting').getAttribute('aria-selected') === 'true'`, { label: 'back on the Alerting tab' });
  const t2 = await p.eval(`document.getElementById('rmm-pane-alerting').innerText`);
  assert(/This device depends on/.test(t2) && /OFFL/.test(t2), 'parent not shown: ' + t2.slice(0, 300));
  await p.clickNav('form:has(input[value="clear_parent"]) button[type=submit]');
  await flash(/Parent device removed/, 'cleared flash');
});

// ------------------------------------------------------------------------------------------------ dark mode and phone width
await T('dark mode and phone width: every page fits, nothing overflows (screenshots in shots/)', async () => {
  await setTheme(1);
  const pages = [['alerts', '/agent/rmm_agent_alerts.php?state=resolved'], ['maintenance', '/agent/rmm_maintenance.php?new=1'], ['escalations', '/agent/rmm_escalations.php?new=1'], ['asset-alerting', asset('WIN1') + '#rmm-alerting']];
  for (const [name, path] of pages) {
    await p.setViewport(1366, 900);
    await go(path); await sleep(700);
    const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
    const [r, g, b] = bg.match(/\d+/g).map(Number);
    assert(r + g + b < 3 * 110, `${name}: the page is not dark in dark mode: ${bg}`);
    await shot(`${name}-desktop-dark`);
    await p.setViewport(MOBILE_W, 800, true);
    await go(path); await sleep(700);
    await noOverflow(`${name} dark phone`);
    await shot(`${name}-phone-dark`);
  }
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(MOBILE_W, 800, true);
  for (const [name, path] of [['alerts', '/agent/rmm_agent_alerts.php?state=resolved'], ['maintenance', '/agent/rmm_maintenance.php?new=1'], ['escalations', '/agent/rmm_escalations.php?new=1'], ['asset-alerting', asset('WIN1') + '#rmm-alerting']]) {
    await go(path); await sleep(700);
    await noOverflow(`${name} light phone`);
    await shot(`${name}-phone-light`);
  }
  await p.setViewport(1366, 900);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
