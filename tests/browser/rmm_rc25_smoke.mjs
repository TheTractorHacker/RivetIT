// Browser smoke for the RivetCore rc.25 adoption (RivetIT 26.10.65): per-OS schedule, the clean-up button, the clock notice, the single Add device menu entry, the Changes tab.
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=<tests/browser/rmm_seed.php output> node tests/browser/rmm_rc25_smoke.mjs
//
// Run after tests/browser/rmm_seed.php on a THROWAWAY install (loopback only; the smoke clicks the clean-up button). Same driver and rules as rmm_checks_smoke.mjs.
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD, SEED_JSON; optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE)) { console.error(`Refusing to run against ${BASE}: loopback throwaway installs only.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(outDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 900);
const H = new Harness({ page: p, outDir, edition: 'rmm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const assert = (c, m) => { if (!c) throw new Error(m); };
const extra = (mode) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_rc25_extra.php', process.env.SEED_JSON, mode], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
const go = async (path) => { await p.goto('about:blank'); await p.goto(BASE + path); await sleep(400); };
let S = null;

await T('login: real form signs in', async () => {
  await p.goto(BASE + '/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('setup: a Windows PC with the 8 Linux checks, one refused for its clock, one 6 minutes ahead, change monitoring on', async () => {
  S = extra('seed');
  assert(S.ok && S.mwa && S.change_on === true && S.schedule_errors.length === 0, JSON.stringify(S));
  const st = extra('state');
  assert(st.linux_rows === 8 && st.open_alerts >= 8, JSON.stringify(st));
});

await T('menu: Endpoints has ONE Add device entry and no Download installer or ?installer=1 link', async () => {
  await go('/agent/rmm_fleet.php');
  const r = await p.eval(`(()=>{const a=[...document.querySelectorAll('.navbar-vertical a, .dropdown-menu a')];return {add:a.filter(x=>x.getAttribute('href')==='/agent/rmm_add_device.php'&&/^\\s*Add device\\s*$/.test(x.textContent)).length,
    dl:a.filter(x=>/Download installer/.test(x.textContent)).length, old:a.filter(x=>/installer=1/.test(x.getAttribute('href')||'')).length}})()`);
  assert(r.add === 1 && r.dl === 0 && r.old === 0, JSON.stringify(r));
});

await T('rmm_fleet.php?installer=1 still works: it redirects to the Add device page', async () => {
  await go('/agent/rmm_fleet.php?installer=1');
  assert(/\/agent\/rmm_add_device\.php/.test(await p.url()), await p.url());
});

await T('Add device page: a Quick download button opens the quick dialog', async () => {
  await go('/agent/rmm_add_device.php');
  assert(await p.exists('[data-rmm-installer-open]'), 'no Quick download button');
  assert(/Quick download/.test(await p.eval(`document.querySelector('[data-rmm-installer-open]').textContent`)), 'wrong label');
  await p.click('[data-rmm-installer-open]');
  await p.waitFor(`document.querySelector('#rmmInstallerModal')&&document.querySelector('#rmmInstallerModal').classList.contains('show')`, { label: 'quick dialog open' });
});

await T('fleet page: the Add device button (quick dialog) is still there and the wrong-clock devices carry a Clock badge', async () => {
  await go('/agent/rmm_fleet.php');
  assert(await p.exists('[data-rmm-installer-open]'), 'no quick dialog button on the fleet page');
  const r = await p.eval(`(()=>{const b=[...document.querySelectorAll('.rmm-clock-badge')].map(x=>x.textContent.trim());return {b, line:!!document.getElementById('rmm-clock-fleet')}})()`);
  assert(r.b.length >= 2 && r.line && r.b.some((t) => /Clock 5 min ahead/.test(t)) && r.b.some((t) => /Clock 6 min ahead/.test(t)), JSON.stringify(r));
});

await T('device page of the refused PC: red notice, the reason replaces "not reported yet", hostile text inert', async () => {
  await go('/agent/asset_details.php?asset_id=' + S.asset_mwa);
  const r = await p.eval(`(()=>{const n=document.getElementById('rmm-clock-notice');return {notice:n?n.innerText:'',red:!!n&&n.classList.contains('alert-danger'),
    facts:document.getElementById('rmm-strip')?document.getElementById('rmm-strip').innerText:'',xss:window.__xss===1,img:!!document.querySelector('#rmm-strip img')}})()`);
  assert(/clock is 5 min ahead of the server: set the time zone and sync the time \(Windows: tzutil \/s/.test(r.notice) && /w32tm \/resync/.test(r.notice), r.notice);
  assert(r.red && !/OS and agent not reported yet/.test(r.facts) && /Not reported yet: the server refused its check-in \(collected_at is in the future/.test(r.facts), r.facts);
  assert(!r.xss && !r.img, 'hostile reason was interpreted');
});

await T('device page of the PC 6 minutes ahead: amber notice', async () => {
  await go('/agent/asset_details.php?asset_id=' + S.asset_ahead);
  const r = await p.eval(`(()=>{const n=document.getElementById('rmm-clock-notice');return {t:n?n.innerText:'',amber:!!n&&n.classList.contains('alert-warning')}})()`);
  assert(r.amber && /6 min ahead of the server/.test(r.t), JSON.stringify(r));
});

await T('Changes tab: listed, escaped, redacted marked', async () => {
  await go('/agent/asset_details.php?asset_id=' + S.asset_ahead + '#rmm-changes');
  await p.waitSel('#rmm-tab-changes');
  await p.click('#rmm-tab-changes');
  await p.waitFor(`document.getElementById('rmm-pane-changes').classList.contains('active')`, { label: 'changes pane' });
  const r = await p.eval(`(()=>{const t=document.getElementById('rmm-changes');return {txt:t.innerText,rows:t.querySelectorAll('tbody tr').length,xss:window.__xss===1,img:!!t.querySelector('img'),red:!!t.querySelector('.badge')}})()`);
  assert(r.rows === 2 && /Updater/.test(r.txt) && /b\.exe <img src=x/.test(r.txt) && r.red && !r.xss && !r.img, JSON.stringify(r));
});

await T('Checks tab: per-OS note, Windows/Linux dropdown for added services, the clean-up button', async () => {
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  const r = await p.eval(`(()=>{const c=document.getElementById('checks');return {note:/A check ticked for one OS is only sent to devices of that OS\\./.test(c.innerText),sel:[...document.querySelectorAll('select[name="cs[svc_extra_os]"] option')].map(o=>o.value+(o.selected?'*':'')),
    btn:!!document.querySelector('button[name=cleanup_stale_checks]'),extras:(document.getElementById('cs_svc_extra')||{}).value}})()`);
  assert(r.note && r.sel.join() === 'windows*,linux' && r.btn, JSON.stringify(r));
  assert(/(^|\n)linux: ssh/.test(r.extras) || /linux:/.test(r.extras), 'stored Linux extras are listed with their OS: ' + r.extras);
});

await T('clean-up button: the 8 Linux checks go, their alerts resolve, no ticket, pressing again changes nothing', async () => {
  const before = extra('state');
  await p.clickNav('button[name=cleanup_stale_checks]');
  await p.waitFor(`/Cleaned up 8 checks/.test(document.body.innerText)`, { label: 'flash' });
  const st = extra('state');
  assert(st.linux_rows === 0 && st.open_alerts === 0 && st.resolved_alerts >= 8 && st.tickets === before.tickets, JSON.stringify([before, st]));
  assert(st.os.svc_sshd === 'linux' && st.os.svc_eventlog === 'windows', 'schedule upgraded: ' + JSON.stringify(st.os));
  await go('/admin/settings_endpoint_agent.php#tab-checks');
  await p.clickNav('button[name=cleanup_stale_checks]');
  await p.waitFor(`/Nothing to clean up/.test(document.body.innerText)`, { label: 'flash 2' });
});

await T('metrics switch and change monitoring card on the administration page', async () => {
  await go('/admin/settings_endpoint_agent.php');
  const r = await p.eval(`(()=>({me:!!document.querySelector('input[name=metrics_extended_on]'),lab:/Send extra health details \\(memory, disks, per-core, battery\\)/.test(document.body.innerText),
    chg:!!document.getElementById('change-monitoring'),sw:!!document.querySelector('input[name=change_monitor]'),later:/later task/.test(document.getElementById('change-monitoring').innerText)}))()`);
  assert(r.me && r.lab && r.chg && r.sw && r.later, JSON.stringify(r));
});

await T('phone width: the clock notice does not overflow', async () => {
  await p.setViewport(390, 800, true);
  await go('/agent/asset_details.php?asset_id=' + S.asset_mwa);
  const w = await p.eval(`document.documentElement.scrollWidth - document.documentElement.clientWidth`);
  assert(w <= 4, 'horizontal overflow ' + w);
  await p.setViewport(1366, 900);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
