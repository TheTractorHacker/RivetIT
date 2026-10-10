// Browser smoke for the RMM asset panel and the agent fleet page (RivetIT).
//
//   php tests/browser/rmm_seed.php /tmp/rmm-seed.json            # on the throwaway install (see that file's header)
//   BASE_URL=http://127.0.0.1:8650 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=/tmp/rmm-seed.json \
//     SMOKE_OUT=/tmp/rmm-shots node tests/browser/rmm_smoke.mjs
//
// Same driver, harness and safety rules as suite.mjs (loopback only, zero npm dependencies, README.md has the install recipe). It signs in through the
// real login form, then checks both pages at desktop and phone width, light and dark, and writes full-page screenshots to SMOKE_OUT/shots.
// Every check also fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx that occurred while it ran.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke switches the signed-in user's theme and queues no jobs, but it is meant for a throwaway install.`);
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
// a hash-only change is not a navigation (no load event): leave the page first so the deep link is really loaded
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const asset = (n) => `/agent/asset_details.php?asset_id=${SEED.asset[n]}`;

// full-page screenshot: grow the capture to the document height
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
// count window.confirm / alert calls (the panel must use in-page dialogs only)
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });   // the landing page of a freshly wiped scratch database may fail for reasons unrelated to RMM

// ------------------------------------------------------------------------------------------------ asset page, desktop, light
await T('asset page: strip, gauges, alerts, checks and performance render (desktop, light)', async () => {
  await go(asset('WIN1'));
  await trapDialogs();
  await p.waitSel('#rmm-strip');
  const t = await bodyText();
  assert(/WIN1/.test(t) && /Online with critical alerts/.test(t), 'strip text missing');
  assert((await p.eval(`document.querySelectorAll('#rmm-panel svg.rmm-gauge[role=img]').length`)) >= 4, 'fewer than four gauges (CPU, memory, two volumes)');
  const labels = await p.eval(`[...document.querySelectorAll('#rmm-panel svg.rmm-gauge')].map(s=>s.getAttribute('aria-label'))`);
  assert(labels.includes('CPU 14 percent, OK') && labels.includes('Disk C: 91 percent, Critical'), 'gauge labels: ' + labels.join(' | '));
  assert(await p.visible('#rmm-checks table'), 'checks table not visible');
  assert(await p.visible('#rmm-alerts'), 'alerts card not visible');
  assert(/disk_c/.test(await p.eval(`document.querySelector('#rmm-checks').innerText`)), 'failing check not listed');
  assert(await p.exists('#rmm-panel [data-asset-metrics]'), 'the Metrics (Performance) section is not embedded');
  assert(await p.exists('.rmm-strip-crit'), 'strip edge class missing');
  assert(!(await p.exists('#rmmDetailTabs')), 'the vendor RMM card is drawn for an agent link');
  await shot('asset-overview-desktop-light');
});
await T('asset page: Performance charts draw (or say why not)', async () => {
  await p.waitFor(`document.querySelectorAll('#rmm-panel .ifm-chart canvas').length > 0 || document.querySelector('#rmm-panel .it-empty-state')`, { timeout: 15000, label: 'charts or an empty state' });
  const hasCharts = await p.eval(`document.querySelectorAll('#rmm-panel .ifm-chart canvas').length`);
  const reason = await p.eval(`(document.querySelector('#rmm-panel [data-asset-metrics] .it-empty-state')||{}).innerText || ''`);
  assert(hasCharts > 0 || /history|metrics|Charts appear/i.test(reason), 'neither charts nor a reason');
  writeFileSync(join(outDir, 'rmm-performance.txt'), hasCharts > 0 ? `charts: ${hasCharts}\n` : `empty state: ${reason}\n`);
});

// ------------------------------------------------------------------------------------------------ keyboard and tabs
await T('keyboard: the tab strip is a tablist and arrow keys move between tabs', async () => {
  await go(asset('WIN1'));
  await p.waitSel('#rmm-tab-overview');
  assert((await p.eval(`document.querySelector('#rmm-panel [role=tablist]').getAttribute('aria-label')`)) !== null, 'tablist has no label');
  await p.focus('#rmm-tab-overview');
  await p.press('ArrowRight');
  await p.waitFor(`document.getElementById('rmm-tab-inventory').getAttribute('aria-selected') === 'true'`, { label: 'Inventory tab selected after ArrowRight' });
  assert(await p.visible('#rmm-pane-inventory'), 'inventory pane not shown');
  await p.press('ArrowRight');
  await p.waitFor(`document.getElementById('rmm-tab-software').getAttribute('aria-selected') === 'true'`, { label: 'Software tab selected' });
  assert(await p.visible('#rmm-pane-software'), 'software pane not shown');
  await p.press('ArrowRight');
  await p.waitFor(`document.getElementById('rmm-tab-jobs').getAttribute('aria-selected') === 'true'`, { label: 'Jobs tab selected' });
  await p.press('ArrowLeft'); await p.press('ArrowLeft'); await p.press('ArrowLeft');
  await p.waitFor(`document.getElementById('rmm-tab-overview').getAttribute('aria-selected') === 'true'`, { label: 'back to Overview' });
});
await T('tab deep link: #rmm-jobs opens the Jobs tab', async () => {
  await go(asset('WIN1') + '#rmm-jobs');
  await p.waitFor(`document.getElementById('rmm-tab-jobs').getAttribute('aria-selected') === 'true'`, { label: 'Jobs tab from the hash' });
  assert(await p.visible('#rmm-pane-jobs'), 'jobs pane hidden');
});
await T('inventory tab: hardware, OS, disks (with used bars), adapters; software points to its tab, services are an honest empty state', async () => {
  await go(asset('WIN1') + '#rmm-inventory');
  await p.waitSel('#rmm-pane-inventory table');
  const t = await p.eval(`document.getElementById('rmm-pane-inventory').innerText`);
  for (const s of ['Intel i7-1355U', 'Windows 11 23H2', 'C:', 'NTFS', 'Ethernet', 'aa:bb:cc:dd:ee:01', 'ACME\\alex']) assert(t.includes(s), 'inventory lacks ' + s);
  assert(/See the Software tab/.test(t) && /Not collected yet/.test(t) && /Phase 6/.test(t), 'software (points to the Software tab) / services empty states missing');
  assert((await p.eval(`document.querySelectorAll('#rmm-pane-inventory .progress[role=progressbar]').length`)) >= 2, 'disk used bars missing');
  await shot('asset-inventory-desktop-light');
});
await T('jobs tab: history, lazy output, hostile output stays text, no window.confirm', async () => {
  await go(asset('WIN1') + '#rmm-jobs');
  await trapDialogs();
  await p.waitSel('#rmm-pane-jobs table');
  const t = await p.eval(`document.getElementById('rmm-pane-jobs').innerText`);
  assert(/Collect inventory/.test(t) && /Failed/.test(t) && /Succeeded/.test(t) && /Queued/.test(t), 'job states missing: ' + t.slice(0, 200));
  assert(!/boom|collected: 2 disks/.test(await p.eval(`document.documentElement.outerHTML.replace(/<script[\\s\\S]*?<\\/script>/g,'')`)), 'job output is inlined in the page before it is requested');
  const failedId = SEED.job_failed;
  await p.click(`.rmm-output-btn[data-job="${failedId}"]`);
  await p.waitFor(`document.querySelector('#rmm-out-${failedId} pre').textContent.includes('boom')`, { label: 'output loaded' });
  const pre = await p.eval(`document.querySelector('#rmm-out-${failedId} pre').textContent`);
  assert(pre === 'boom <script>alert(1)</script>', 'output text: ' + pre);
  assert((await p.eval(`document.querySelectorAll('#rmm-out-${failedId} script, #rmm-out-${failedId} pre *').length`)) === 0, 'output created child elements (it must be text only)');
  assert((await p.eval(`document.querySelector('.rmm-output-btn[data-job="${failedId}"]').getAttribute('aria-expanded')`)) === 'true', 'aria-expanded not set');
  await shot('asset-jobs-desktop-light');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
});
await T('reboot: in-page dialog needs the checkbox, closes with Escape, never window.confirm', async () => {
  await go(asset('WIN1'));
  await trapDialogs();
  await p.waitSel('#rmm-act-reboot');
  await p.click('#rmm-act-reboot');
  await p.waitSel('#rmmRebootModal.show');
  assert(await p.eval(`document.getElementById('rmm-reboot-go').disabled`), 'the Reboot button is enabled before the confirmation');
  await sleep(500);
  await p.click('#rmm-reboot-confirm');
  assert(!(await p.eval(`document.getElementById('rmm-reboot-go').disabled`)), 'the Reboot button stays disabled after confirming');
  await shot('asset-reboot-dialog-desktop-light');
  await sleep(300);
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmRebootModal.show')`, { label: 'dialog closed by Escape' });
  await sleep(600);
  assert((await p.eval(`document.activeElement && document.activeElement.id`)) === 'rmm-act-reboot', 'focus did not return to the Reboot button');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
  // nothing was queued
  const t = await bodyText();
  assert(!/Reboot queued/.test(t), 'a reboot was queued by cancelling');
});
await T('run script dialog: saved script list, destructive confirmation appears', async () => {
  await go(asset('WIN1'));
  await p.waitSel('#rmm-act-script');
  await p.click('#rmm-act-script');
  await p.waitSel('#rmmRunModal.show');
  assert((await p.eval(`document.getElementById('rmm-run-saved').options.length`)) >= 1, 'saved script missing');
  assert(!(await p.visible('#rmm-run-confirm-wrap')), 'confirmation shown before the destructive box is ticked');
  await sleep(500);
  await p.click('#rmm-run-destructive');
  await p.waitSel('#rmm-run-confirm-wrap');
  await sleep(300);
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmRunModal.show')`, { label: 'dialog closed' });
});
await T('remote access: button disabled with the reason when no Mesh node is mapped', async () => {
  await go(asset('WIN1'));
  await p.waitSel('#rmm-act-remote');
  assert(await p.eval(`document.getElementById('rmm-act-remote').disabled`), 'remote enabled without a node');
  assert(/MeshCentral node/.test(await p.eval(`document.getElementById('rmm-act-remote').title`)), 'no reason in the tooltip');
  assert(/MeshCentral node first/.test(await p.eval(`document.querySelector('.rmm-reasons').innerText`)), 'no visible reason text');
});

// ------------------------------------------------------------------------------------------------ other states
await T('states: offline (banner, dimmed gauges), never checked in, Linux, bare readings', async () => {
  await go(asset('OFFL'));
  await p.waitSel('#rmm-strip');
  let t = await bodyText();
  assert(/Offline/.test(t) && /This device is offline/.test(t), 'offline banner missing');
  assert(await p.exists('#rmm-panel .rmm-dim'), 'gauges are not dimmed');
  assert(/last reading/.test(t), '"last reading" chip missing');
  await shot('asset-offline-desktop-light');
  await go(asset('NEVER'));
  await p.waitSel('#rmm-strip');
  t = await bodyText();
  assert(/Waiting for first check-in/.test(t) && /Waiting for the first check-in/.test(t), 'never-checked-in empty state missing');
  assert(!(await p.exists('#rmm-checks')), 'checks drawn for a device that never reported');
  assert(await p.eval(`document.getElementById('rmm-act-reboot').disabled`), 'Reboot enabled before the first check-in');
  await shot('asset-never-desktop-light');
  await go(asset('LNX1'));
  await p.waitSel('#rmm-strip');
  assert(await p.eval(`document.getElementById('rmm-act-script').disabled`), 'Run script enabled on Linux');
  assert(!(await p.eval(`document.getElementById('rmm-act-reboot').disabled`)), 'Reboot disabled on Linux');
  assert(!!(await p.exists('#rmm-strip .fa-linux')) && !(await p.exists('#rmm-strip .fa-windows')), 'Linux device shows a Windows icon');
  await shot('asset-linux-desktop-light');
  await go(asset('BARE'));
  await p.waitSel('#rmm-strip');
  const n = await p.eval(`document.querySelectorAll('#rmm-panel svg.rmm-gauge[aria-label$="no data"]').length`);
  assert(n >= 2, 'a device with no readings must show "no data" gauges, got ' + n);
  await shot('asset-nodata-desktop-light');
});
await T('hostile device strings are inert (hostname, model, CPU, check detail)', async () => {
  await go(asset('HOST'));
  await p.waitSel('#rmm-strip');
  assert((await p.eval(`document.querySelectorAll('#rmm-panel script:not([type="application/json"]), #rmm-panel img[src="x"], #rmm-panel img[onerror]').length`)) === 0, 'markup was injected into the panel');
  assert(/<script>alert\(9\)/.test(await p.eval(`document.getElementById('rmm-strip').innerText`)), 'hostile hostname should be visible as text');
});

// ------------------------------------------------------------------------------------------------ fleet page
await T('fleet page: KPIs, health donut, approvals, offline list, capacity, device table (desktop, light)', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-devices table');
  const t = await bodyText();
  for (const s of ['Agent fleet', 'Online', 'Offline', 'Stale', 'Never seen', 'Need approval', 'Open alerts', 'Fleet health', 'Devices needing approval', 'Offline and stale', 'Recent job failures', 'Agent versions and rings', 'Performance and capacity']) {
    assert(t.includes(s), 'fleet page lacks ' + s);
  }
  const label = await p.eval(`document.querySelector('#rmm-devices').parentElement.querySelector('svg[role=img]').getAttribute('aria-label')`);
  assert(/4 online, 1 offline, 1 stale, 2 never seen/.test(label), 'donut label: ' + label);
  assert(await p.visible('#rmm-capacity'), 'capacity panel hidden for the administrator');
  assert((await p.eval(`document.querySelectorAll('#rmm-devices tbody tr').length`)) === 8, 'device rows');
  assert(/PEND/.test(await p.eval(`document.getElementById('rmm-approvals').innerText`)), 'PEND missing from approvals');
  assert(await p.exists('.navbar-vertical a[href="/agent/rmm_fleet.php"]'), 'nav entry missing');
  await shot('fleet-desktop-light');
});
await T('fleet page: status filter and text filter use the real form', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-f-status');
  await p.eval(`(()=>{const s=document.getElementById('rmm-f-status');s.value='offline';})()`);
  await p.clickNav('#rmm-devices form button[type=submit]');
  assert(/status=offline/.test(await p.url()), 'url: ' + (await p.url()));
  const rows = await p.eval(`[...document.querySelectorAll('#rmm-devices tbody tr')].map(r=>r.innerText)`);
  assert(rows.length === 1 && /OFFL/.test(rows[0]), 'offline filter rows: ' + rows.length);
  await go('/agent/rmm_fleet.php?q=lnx');
  await p.waitSel('#rmm-devices table');
  const rows2 = await p.eval(`[...document.querySelectorAll('#rmm-devices tbody tr')].map(r=>r.innerText)`);
  assert(rows2.length === 1 && /LNX1/.test(rows2[0]), 'text filter rows: ' + rows2.length);
  await go('/agent/rmm_fleet.php?q=zzz-none');
  assert(/No device matches/.test(await bodyText()), 'empty filter result message');
});
await T('fleet page: keyboard reaches the device links', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-devices table');
  await p.focus('#rmm-f-q');
  for (let i = 0; i < 4; i++) await p.press('Tab');
  const tag = await p.eval(`document.activeElement.tagName + ':' + (document.activeElement.closest('#rmm-devices')?'in':'out')`);
  assert(/^(A|BUTTON|SELECT|INPUT):in$/.test(tag), 'focus left the device card: ' + tag);
});


// ------------------------------------------------------------------------------------------------ "Add device" / "Download installer"
// Needs the seed to have published the placeholder binaries (RMM_SMOKE_BASE_URL set when tests/browser/rmm_seed.php ran).
const DL_DIR = join(outDir, 'downloads');
mkdirSync(DL_DIR, { recursive: true });
const openInstaller = async (path = '/agent/rmm_fleet.php') => {
  await go(path);
  await p.waitSel('[data-rmm-installer-open]');
  // keep the blobs the dialog saves, so the bytes can be checked (the file also lands in DL_DIR)
  await p.eval(`window.__blobs = []; const o = URL.createObjectURL; URL.createObjectURL = function (b) { window.__blobs.push(b); return o.call(URL, b); }; true`);
  await p.click('[data-rmm-installer-open]');
  await p.waitSel('#rmmInstallerModal.show');
  await sleep(450);
};
const pickDepartment = async () => {
  const v = await p.eval(`(()=>{const s=document.getElementById('rmm-inst-client');const o=[...s.options].find(x=>x.value);s.value=o.value;s.dispatchEvent(new Event('change',{bubbles:true}));return o.value})()`);
  return v;
};
const modalFits = async (label) => {
  const o = await p.eval(`(()=>{const d=document.querySelector('#rmmInstallerModal .modal-dialog');const r=d.getBoundingClientRect();const c=document.querySelector('#rmmInstallerModal .modal-content');return {right:Math.round(r.right),left:Math.round(r.left),vw:document.documentElement.clientWidth,sw:c.scrollWidth,cw:c.clientWidth}})()`);
  assert(o.left >= 0 && o.right <= o.vw + 1 && o.sw <= o.cw + 1, `${label}: the dialog overflows the viewport: ${JSON.stringify(o)}`);
};
await T('installer dialog: Add device opens with the safe defaults (desktop, light)', async () => {
  await openInstaller();
  const d = await p.eval(`(()=>{const $=(i)=>document.getElementById(i);return {title:$('rmmInstallerTitle').innerText,x64:$('rmm-inst-arch-amd64').checked,ring:$('rmm-inst-ring').value,uses:$('rmm-inst-uses').value,ttl:$('rmm-inst-ttl').value,multi:$('rmm-inst-multi').checked,
    adv:$('rmm-inst-adv').open,os:document.querySelector('#rmmInstallerModal .nav-link.active').dataset.os,label:$('rmm-inst-go-label').innerText,noBin:!$('rmm-inst-nobinary').classList.contains('d-none'),dis:$('rmm-inst-go').disabled,
    csrf:document.querySelector('#rmm-inst-form input[name=csrf_token]').value.length>10,steps:$('rmm-inst-steps').innerText}})()`);
  assert(/Add device/.test(d.title) && d.x64 && d.ring === 'stable' && d.uses === '1' && d.ttl === '24' && !d.multi && !d.adv && d.os === 'windows', 'defaults: ' + JSON.stringify(d));
  assert(d.label === 'Download installer' && !d.noBin && !d.dis && d.csrf, 'primary action: ' + JSON.stringify(d));
  assert(/Copy the downloaded file/.test(d.steps) && /double-click/i.test(d.steps) && /administrator prompt/.test(d.steps) && /setup --silent/.test(d.steps), 'next-step text: ' + d.steps);
  await shot('installer-dialog-desktop-light');
});
await T('installer dialog: Download installer streams the stamped .exe and shows what to do next', async () => {
  await p.send('Page.setDownloadBehavior', { behavior: 'allow', downloadPath: DL_DIR });
  await p.eval(`document.getElementById('rmm-inst-multi').click(); true`);
  assert((await p.eval(`document.getElementById('rmm-inst-uses').value`)) === '25', 'the "several PCs" switch should set 25 uses');
  await p.eval(`document.getElementById('rmm-inst-multi').click(); true`);
  assert((await p.eval(`document.getElementById('rmm-inst-uses').value`)) === '1', 'switching it off should go back to 1 use');
  // no department chosen: the dialog says so and sends nothing
  await p.click('#rmm-inst-go');
  await p.waitSel('#rmm-inst-error');
  assert(/Choose the department/.test(await p.eval(`document.getElementById('rmm-inst-error').innerText`)), 'no message for a missing department');
  assert((await p.eval(`window.__blobs.length`)) === 0, 'something was downloaded without a department');
  await pickDepartment();
  await p.click('#rmm-inst-go');
  await p.waitFor(`document.getElementById('rmmInstallerModal').getAttribute('data-state') === 'downloaded'`, { timeout: 30000, label: 'download finished' });
  const r = await p.eval(`(async()=>{const b=window.__blobs[0];const head=await b.slice(0,2).text();const tail=await b.slice(b.size-16).text();return {size:b.size,head,tail,done:document.getElementById('rmm-inst-done').innerText,hidden:document.getElementById('rmm-inst-done').classList.contains('d-none'),name:document.getElementById('rmm-inst-silent-name').innerText}})()`);
  assert(r.head === 'MZ' && r.tail === 'RIVETIT-EMBED-v1' && r.size > 100000, 'the streamed file is not a stamped PE: ' + JSON.stringify(r));
  assert(!r.hidden && /Downloaded RivetIT-Agent-Setup-.*-x64\.exe/.test(r.done) && /double-click it and accept the administrator prompt/.test(r.done) && /works for 1 PC for 24 hours/.test(r.done), 'next step text: ' + r.done);
  assert(/^RivetIT-Agent-Setup-.*-x64\.exe$/.test(r.name), 'silent command file name: ' + r.name);
  await sleep(1200);
  const { readdirSync, readFileSync } = await import('node:fs');
  const files = readdirSync(DL_DIR).filter((f) => /\.exe$/.test(f));
  assert(files.length >= 1, 'no .exe reached the download folder');
  const bytes = readFileSync(join(DL_DIR, files[0]));
  assert(bytes.subarray(0, 2).toString() === 'MZ' && bytes.subarray(bytes.length - 16).toString() === 'RIVETIT-EMBED-v1', 'the saved file is not a stamped PE');
  await shot('installer-dialog-downloaded-desktop-light');
});
await T('installer dialog: the advanced fields open and the silent-install file name follows the architecture', async () => {
  await p.click('#rmm-inst-adv summary');
  await p.waitSel('#rmm-inst-label');
  await p.type('#rmm-inst-ttl', '9999');   // over the maximum: the server clamps it, it never errors
  await p.click('label[for=rmm-inst-arch-arm64]');
  assert(await p.eval(`document.getElementById('rmm-inst-arch-arm64').checked`), 'ARM64 not selected');
  assert((await p.eval(`document.getElementById('rmm-inst-silent-name').innerText`)).endsWith('-arm64.exe'), 'the silent command file name should follow the architecture');
  await shot('installer-dialog-advanced-desktop-light');
});
await T('installer dialog: Linux tab shows the install command with a copy button (no download)', async () => {
  await openInstaller();
  await p.click('#rmm-inst-tab-linux');
  assert((await p.eval(`document.getElementById('rmm-inst-tab-linux').getAttribute('aria-selected')`)) === 'true', 'Linux tab not selected');
  assert((await p.eval(`document.getElementById('rmm-inst-go-label').innerText`)) === 'Create install command', 'primary label on the Linux tab');
  assert(!(await p.visible('#rmm-inst-windows-next')), 'the Windows steps are shown on the Linux tab');
  await pickDepartment();
  await p.click('#rmm-inst-go');
  await p.waitFor(`document.getElementById('rmmInstallerModal').getAttribute('data-state') === 'linux-command'`, { timeout: 20000, label: 'command created' });
  const cmd = await p.eval(`document.getElementById('rmm-inst-linux-cmd').textContent`);
  assert(/install-linux\.sh --server/.test(cmd) && /rvte1\.[0-9a-f]{12}\.[0-9a-f]{40}/.test(cmd), 'command: ' + cmd.slice(0, 200));
  assert((await p.eval(`window.__blobs.length`)) === 0, 'the Linux tab downloaded a file');
  await p.click('#rmm-inst-copy');
  await p.waitFor(`document.querySelector('#rmm-inst-copy span').innerText === 'Copied'`, { label: 'copy confirmation' });
  await shot('installer-dialog-linux-desktop-light');
});
await T('installer dialog: keyboard (arrow keys switch the OS tabs, Esc closes and returns focus to the button)', async () => {
  await openInstaller();
  await p.focus('#rmm-inst-tab-windows');
  await p.press('ArrowRight');
  await p.waitFor(`document.getElementById('rmm-inst-tab-linux').getAttribute('aria-selected') === 'true' && document.activeElement.id === 'rmm-inst-tab-linux'`, { label: 'ArrowRight moves to Linux' });
  await p.press('ArrowLeft');
  await p.waitFor(`document.getElementById('rmm-inst-tab-windows').getAttribute('aria-selected') === 'true'`, { label: 'ArrowLeft back to Windows' });
  // Tab order reaches the primary button
  await p.focus('#rmm-inst-label');
  await p.press('Tab').catch(() => {});
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'dialog closed by Escape' });
  await sleep(700);
  assert((await p.eval(`document.activeElement && document.activeElement.hasAttribute('data-rmm-installer-open')`)), 'focus did not return to the Add device button');
});
await T('Endpoints menu: "Download installer" opens the dialog on the fleet page', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-devices table');
  assert(await p.exists('.navbar-vertical a[href="/agent/rmm_fleet.php?installer=1"]'), 'menu entry missing');
  await go('/agent/rmm_fleet.php?installer=1');
  await p.waitSel('#rmmInstallerModal.show');
  await sleep(400);
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'closed' });
});
await T("department page: 'Download agent installer' in the actions menu opens the dialog for that department", async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-devices table');
  const cid = await p.eval(`(()=>{const o=[...document.getElementById('rmm-inst-client').options].find(x=>x.value);return o.value})()`);
  await go('/agent/client_overview.php?client_id=' + cid);
  await p.waitSel('button[aria-label="Department actions"]');
  await p.click('button[aria-label="Department actions"]');
  await p.waitSel('[data-rmm-installer-client="' + cid + '"]');
  await p.click('[data-rmm-installer-client="' + cid + '"]');
  await p.waitSel('#rmmInstallerModal.show');
  await sleep(450);
  assert(!(await p.exists('select#rmm-inst-client')) && (await p.eval(`document.getElementById('rmm-inst-client').value`)) === cid, 'the department is not fixed to the page');
  assert((await p.eval(`document.getElementById('rmmInstallerModal').getAttribute('data-fixed')`)) === '1', 'not marked fixed');
  await shot('installer-dialog-department-desktop-light');
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'closed' });
});


// ------------------------------------------------------------------------------------------------ RMM Phase 1 (RivetCore 1.0.0-rc.9)
const unlabeled = (scope) => p.eval(`[...document.querySelectorAll(${JSON.stringify(scope)} + ' input:not([type=hidden]):not([type=checkbox]), ' + ${JSON.stringify(scope)} + ' select, ' + ${JSON.stringify(scope)} + ' textarea')].filter(e=>{
  const id=e.id;const byFor=id&&document.querySelector('label[for="'+id+'"]');return !(byFor||e.getAttribute('aria-label')||e.closest('label'))}).map(e=>e.name||e.id)`);
await T('software tab: list, paging, search, removed items and the change log (desktop, light)', async () => {
  await go(asset('WIN1') + '#rmm-software');
  await trapDialogs();
  await p.waitSel('#rmm-pane-software table');
  await p.waitFor(`document.getElementById('rmm-tab-software').getAttribute('aria-selected') === 'true'`, { label: 'Software tab from the hash' });
  const cur = await p.eval(`document.getElementById('rmm-sw-current').innerText`);
  assert(/7-Zip/.test(cur) && /Vendor Tool 001/.test(cur), 'current list: ' + cur.slice(0, 200));
  assert(!/Mozilla Firefox/.test(cur), 'a removed item is in the current list');
  assert(/<script>alert\(5\)<\/script>/.test(cur), 'the hostile name should be visible as text');
  assert((await p.eval(`document.querySelectorAll('#rmm-pane-software script, #rmm-pane-software img[src="x"], #rmm-pane-software [onerror]').length`)) === 0, 'markup injected through a software name');
  assert(/Page 1 of 2/.test(cur), 'paging text: ' + cur.slice(-120));
  const hist = await p.eval(`document.getElementById('rmm-sw-history').innerText`);
  assert(/Upgraded/.test(hist) && /Installed/.test(hist) && /Removed/.test(hist) && /120\.0\.6099\.1/.test(hist) && /125\.0\.6422\.1/.test(hist), 'change log: ' + hist.slice(0, 300));
  assert((await unlabeled('#rmm-pane-software')).length === 0, 'unlabelled controls in the Software tab: ' + (await unlabeled('#rmm-pane-software')).join(','));
  await shot('asset-software-desktop-light');
  // next page: the link keeps the tab (fragment) and the page number
  await p.clickNav('#rmm-sw-current a[rel=next]');
  assert(/swp=2/.test(await p.url()) && /#rmm-software$/.test(await p.url()), 'next page url: ' + (await p.url()));
  await p.waitFor(`document.getElementById('rmm-tab-software').getAttribute('aria-selected') === 'true'`, { label: 'still on the Software tab after paging' });
  assert(/Page 2 of 2/.test(await p.eval(`document.getElementById('rmm-sw-current').innerText`)), 'page 2 text');
  // search through the real form
  await go(asset('WIN1') + '#rmm-software');
  await p.waitSel('#rmm-sw-q');
  await p.type('#rmm-sw-q', 'chrome');
  await p.clickNav('#rmm-pane-software form[role=search] button[type=submit]');
  assert(/swq=chrome/.test(await p.url()), 'search url: ' + (await p.url()));
  await p.waitFor(`document.getElementById('rmm-tab-software').getAttribute('aria-selected') === 'true'`, { label: 'Software tab after a search (fragment kept by the form)' });
  const found = await p.eval(`[...document.querySelectorAll('#rmm-sw-current tbody tr')].map(r=>r.innerText)`);
  assert(found.length === 1 && /Google Chrome/.test(found[0]) && /125\.0\.6422\.1/.test(found[0]), 'search rows: ' + JSON.stringify(found));
  // include removed
  await go(asset('WIN1') + '&swq=firefox&swr=1#rmm-software');
  await p.waitSel('#rmm-sw-current tbody tr');
  const rem = await p.eval(`document.getElementById('rmm-sw-current').innerText`);
  assert(/Mozilla Firefox/.test(rem) && /Removed/.test(rem), 'removed item not listed with the checkbox: ' + rem.slice(0, 200));
  assert((await p.eval(`document.getElementById('rmm-sw-removed').checked`)), 'the checkbox does not reflect the state');
  // ask for a full list: an in-page message, never window.confirm
  await go(asset('WIN1') + '#rmm-software');
  await trapDialogs();
  await p.waitSel('#rmm-sw-refresh');
  await p.click('#rmm-sw-refresh');
  await p.waitFor(`/full software list/.test(document.getElementById('rmm-msg').innerText)`, { label: 'refresh confirmation message' });
  assert((await p.eval(`document.getElementById('rmm-msg').classList.contains('alert-success')`)), 'refresh message is not a success');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
});
await T('software tab: a device that never reported says so and offers the next step', async () => {
  await go(asset('LNX1') + '#rmm-software');
  await p.waitSel('#rmm-pane-software');
  const t = await p.eval(`document.getElementById('rmm-pane-software').innerText`);
  assert(/No software list yet/.test(t) && /has not announced software inventory/.test(t), 'LNX1 software tab: ' + t.slice(0, 200));
});
await T('tags and groups: add with autocomplete, validation, remove, groups shown (desktop, light)', async () => {
  await go(asset('WIN1'));
  await trapDialogs();
  await p.waitSel('#rmm-tags');
  let t = await p.eval(`document.getElementById('rmm-tags').innerText`);
  assert(/VIP/.test(t) && /Finance PCs/.test(t), 'tags / groups line: ' + t);
  assert((await p.eval(`!!document.querySelector('label[for=rmm-tag-input]')`)), 'the tag field has no label');
  const opts = await p.eval(`[...document.querySelectorAll('#rmm-tag-list option')].map(o=>o.value)`);
  assert(opts.includes('Servers') && opts.includes('Kiosk') && !opts.includes('VIP'), 'autocomplete options: ' + opts.join(','));
  assert((await p.eval(`document.getElementById('rmm-tag-input').getAttribute('list')`)) === 'rmm-tag-list', 'input is not tied to the datalist');
  // an invalid name: the server's reason appears in the page message
  await p.type('#rmm-tag-input', '<b>bad</b>');
  await p.press('Enter');
  await p.waitFor(`/letters, digits/.test(document.getElementById('rmm-msg').innerText)`, { label: 'validation message' });
  assert((await p.eval(`document.getElementById('rmm-msg').classList.contains('alert-danger')`)), 'validation message is not an error');
  // a valid one, with the keyboard only
  await p.focus('#rmm-tag-input');
  await p.type('#rmm-tag-input', 'Servers');
  await shot('asset-tags-desktop-light');
  await p.press('Enter');
  await p.waitFor(`/Servers/.test((document.getElementById('rmm-tags')||{innerText:''}).innerText) && !!document.querySelector('button[aria-label="Remove tag Servers"]')`, { timeout: 20000, label: 'tag added after reload' });
  // remove it again
  await p.click('button[aria-label="Remove tag Servers"]');
  await p.waitFor(`!document.querySelector('button[aria-label="Remove tag Servers"]') && !!document.getElementById('rmm-tags')`, { timeout: 20000, label: 'tag removed after reload' });
  assert(!/Servers/.test(await p.eval(`document.getElementById('rmm-tags').innerText`)), 'the tag is still there');
}, { allowHttp: [/\/agent\/post\/rmm_agent\.php/] });   // the invalid name is answered 422 on purpose
await T('checks table: a trend sparkline with an accessible name and a history table per check', async () => {
  await go(asset('WIN1'));
  await p.waitSel('#rmm-checks table');
  assert(/Trend \(24 h\)/i.test(await p.eval(`document.getElementById('rmm-checks').innerText`)), 'no Trend column');
  const labels = await p.eval(`[...document.querySelectorAll('#rmm-checks svg.rmm-spark[role=img]')].map(s=>s.getAttribute('aria-label'))`);
  assert(labels.length >= 3 && labels.every((l) => /^Last 24 hours of \S+: ([0-9.]+% passing|availability not known), \d+ status changes?$/.test(l)), 'sparkline labels: ' + JSON.stringify(labels));
  assert(labels.some((l) => /svc_eventlog/.test(l) && /1 status change$/.test(l)), 'svc_eventlog should have one recorded change: ' + JSON.stringify(labels));
  await p.eval(`document.querySelector('#rmm-checks details summary').click(); true`);
  await p.waitFor(`document.querySelector('#rmm-checks details[open] table caption')`, { label: 'history table opened' });
  const tbl = await p.eval(`document.querySelector('#rmm-checks details[open]').innerText`);
  assert(/Failing|Passing/.test(tbl), 'history table: ' + tbl.slice(0, 200));
  await shot('asset-checks-trend-desktop-light');
});
await T('network tile: receive and send against the 24 hour peak', async () => {
  await go(asset('WIN1'));
  await p.waitSel('#rmm-net');
  const t = await p.eval(`document.getElementById('rmm-net').innerText`);
  assert(/24 h peak 16 Mbit\/s/.test(t) && /24 h peak 4 Mbit\/s/.test(t) && /50% of/.test(t), 'network tile: ' + t);
  assert((await p.eval(`document.querySelectorAll('#rmm-net .progress[role=progressbar][aria-label]').length`)) === 2, 'two labelled bars');
  assert(!/link speed not reported/.test(t), 'the old empty-state text is back');
  await go(asset('LNX1'));
  await p.waitSel('#rmm-net');
  assert(/no 24 hour history yet|24 h peak/.test(await p.eval(`document.getElementById('rmm-net').innerText`)), 'LNX1 network tile has no honest text');
});
await T('fleet page: tag, group and software filters; outdated software card (desktop, light)', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-f-tag');
  assert((await unlabeled('#rmm-devices')).length === 0 && (await unlabeled('#rmm-outdated-sw')).length === 0, 'unlabelled filter controls');
  await p.eval(`(()=>{const s=document.getElementById('rmm-f-tag');s.value='VIP';})()`);
  await p.clickNav('#rmm-devices form button[type=submit]');
  assert(/tag=VIP/.test(await p.url()), 'tag url: ' + (await p.url()));
  let rows = await p.eval(`[...document.querySelectorAll('#rmm-devices tbody tr')].map(r=>r.innerText)`);
  assert(rows.length === 1 && /WIN1/.test(rows[0]) && /VIP/.test(rows[0]), 'tag filter rows: ' + JSON.stringify(rows));
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-f-group');
  await p.eval(`(()=>{const s=document.getElementById('rmm-f-group');const o=[...s.options].find(x=>/Finance PCs/.test(x.text));s.value=o.value;})()`);
  await p.clickNav('#rmm-devices form button[type=submit]');
  rows = await p.eval(`[...document.querySelectorAll('#rmm-devices tbody tr')].map(r=>r.innerText)`);
  assert(rows.length === 2 && rows.some((r) => /WIN1/.test(r)) && rows.some((r) => /OFFL/.test(r)), 'group filter rows: ' + JSON.stringify(rows));
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-f-sw');
  await p.type('#rmm-f-sw', 'notepad');
  await p.clickNav('#rmm-devices form button[type=submit]');
  rows = await p.eval(`[...document.querySelectorAll('#rmm-devices tbody tr')].map(r=>r.innerText)`);
  assert(rows.length === 1 && /WIN1/.test(rows[0]), 'software filter rows: ' + JSON.stringify(rows));
  // outdated software through its own form
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-outdated-sw');
  assert(/Enter a product name/.test(await p.eval(`document.getElementById('rmm-outdated-sw').innerText`)), 'instruction before a question');
  await p.type('#rmm-osw', 'chrome');
  await p.type('#rmm-osv', '126.0');
  await p.clickNav('#rmm-outdated-sw form button[type=submit]');
  assert(/osw=chrome/.test(await p.url()) && /osv=126\.0/.test(await p.url()), 'outdated url: ' + (await p.url()));
  const card = await p.eval(`document.getElementById('rmm-outdated-sw').innerText`);
  assert(/WIN1/.test(card) && /125\.0\.6422\.1/.test(card) && /1 outdated/.test(card), 'outdated card: ' + card.slice(0, 300));
  await shot('fleet-outdated-desktop-light');
  await p.type('#rmm-osv', '125.0');
  await p.clickNav('#rmm-outdated-sw form button[type=submit]');
  assert(/No device runs a version of "chrome" older than 125\.0\./.test(await p.eval(`document.getElementById('rmm-outdated-sw').innerText`)), 'all-clear sentence');
});
await T('administration: software inventory switch and retention limits save and show again', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#inventory');
  assert((await p.eval(`document.getElementById('ea_inv_sw').checked`)), 'the software switch should be on (the seed turned it on)');
  assert((await unlabeled('#inventory')).length === 0, 'unlabelled controls in the card');
  assert(await p.eval(`!!document.querySelector('#ea_chd[aria-describedby]') && !!document.querySelector('#ea_shd[aria-describedby]')`), 'limits have descriptions');
  await p.type('#ea_chd', '5');
  await p.type('#ea_shd', '120');
  await p.clickNav('#inventory button[name=save_inventory_settings]');
  await p.waitSel('#inventory');
  assert((await p.eval(`document.getElementById('ea_chd').value`)) === '5' && (await p.eval(`document.getElementById('ea_shd').value`)) === '120', 'saved values not shown again');
  assert(/saved/i.test(await bodyText()), 'no confirmation message');
  await shot('admin-inventory-desktop-light');
  await p.type('#ea_chd', '7');
  await p.type('#ea_shd', '365');
  await p.clickNav('#inventory button[name=save_inventory_settings]');
  assert((await p.eval(`document.getElementById('ea_chd').value`)) === '7', 'restore failed');
});
await T('event picker offers the rmm.* events (Webhooks and Event rules)', async () => {
  await go('/modals/event_catalog.php');
  const t = await p.eval(`document.body.innerText`);
  for (const id of ['rmm.device.enrolled', 'rmm.device.offline', 'rmm.device.online', 'rmm.check.failed', 'rmm.check.recovered', 'rmm.job.completed', 'rmm.job.failed', 'rmm.software.installed', 'rmm.software.removed']) assert(t.includes(id), 'catalog lacks ' + id);
});

// ------------------------------------------------------------------------------------------------ phone width
await T('phone width (390 px): asset page has no horizontal scroll; tabs scroll inside their container', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await go(asset('WIN1'));
  await p.waitSel('#rmm-strip');
  await sleep(600);
  await noOverflow('asset overview');
  assert(await p.eval(`(()=>{const t=document.querySelector('.rmm-tabbar');const s=getComputedStyle(t);return s.overflowX==='auto'||s.overflowX==='scroll'})()`), 'tab bar does not scroll in its own container');
  await shot('asset-overview-phone-light');
  await go(asset('WIN1') + '#rmm-inventory'); await p.waitSel('#rmm-pane-inventory table'); await sleep(300);
  await noOverflow('asset inventory');
  await shot('asset-inventory-phone-light');
  await go(asset('WIN1') + '#rmm-jobs'); await p.waitSel('#rmm-pane-jobs table'); await sleep(300);
  await noOverflow('asset jobs');
  await shot('asset-jobs-phone-light');
  await go(asset('WIN1') + '#rmm-software'); await p.waitSel('#rmm-pane-software table'); await sleep(300);
  await noOverflow('asset software');
  await shot('asset-software-phone-light');
  await go(asset('WIN1')); await p.waitSel('#rmm-checks table'); await sleep(300);
  await noOverflow('asset checks with trends and tags');
  assert(await p.visible('#rmm-tags'), 'tags line hidden on a phone');
  assert((await p.eval(`document.querySelector('#rmm-tag-input').getBoundingClientRect().right <= document.documentElement.clientWidth`)), 'the tag field runs off the screen');
  await shot('asset-checks-phone-light');
  // the visible reasons for disabled actions exist on a narrow screen (a tooltip cannot be hovered)
  await go(asset('NEVER')); await p.waitSel('#rmm-strip');
  assert(await p.visible('.rmm-reasons'), 'reason text for disabled buttons is not visible on a phone');
  await noOverflow('asset never');
});
await T('phone width (390 px): the installer dialog fits (Windows and Linux tabs, advanced open)', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await openInstaller();
  await modalFits('windows');
  await p.click('#rmm-inst-adv summary'); await sleep(200);
  await modalFits('advanced');
  assert(await p.visible('#rmm-inst-go'), 'the primary button is not reachable on a phone');
  await shot('installer-dialog-phone-light');
  await p.click('#rmm-inst-tab-linux');
  await pickDepartment();
  await p.click('#rmm-inst-go');
  await p.waitFor(`document.getElementById('rmmInstallerModal').getAttribute('data-state') === 'linux-command'`, { timeout: 20000, label: 'command created' });
  await modalFits('linux');
  await shot('installer-dialog-linux-phone-light');
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'closed' });
  await go('/agent/rmm_fleet.php'); await p.waitSel('#rmm-devices table');
  await noOverflow('fleet with the Add device button');
});
await T('phone width (390 px): fleet page has no horizontal scroll', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-devices table');
  await sleep(400);
  await noOverflow('fleet');
  await shot('fleet-phone-light');
  await go('/agent/rmm_fleet.php?osw=chrome&osv=126.0&tag=VIP'); await p.waitSel('#rmm-outdated-sw table'); await sleep(300);
  await noOverflow('fleet with the outdated software card and a tag filter');
  await shot('fleet-outdated-phone-light');
  await go('/admin/settings_endpoint_agent.php#inventory'); await p.waitSel('#inventory'); await sleep(300);
  await noOverflow('administration software card');
});

// ------------------------------------------------------------------------------------------------ dark mode
await T('dark mode: asset panel and fleet page (desktop and phone)', async () => {
  await p.setViewport(1366, 900);
  await setTheme(1);
  await go(asset('WIN1'));
  await p.waitSel('#rmm-strip');
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'not dark');
  const gaugeInk = await p.eval(`getComputedStyle(document.querySelector('#rmm-panel svg.rmm-gauge text')).fill`);
  const [r, g, b] = gaugeInk.match(/\d+/g).map(Number);
  assert(r + g + b > 3 * 120, 'gauge value text is not light in dark mode: ' + gaugeInk);
  await sleep(500);
  await shot('asset-overview-desktop-dark');
  await go(asset('WIN1') + '#rmm-jobs'); await p.waitSel('#rmm-pane-jobs table'); await shot('asset-jobs-desktop-dark');
  await go(asset('WIN1') + '#rmm-inventory'); await p.waitSel('#rmm-pane-inventory table'); await shot('asset-inventory-desktop-dark');
  await go(asset('WIN1') + '#rmm-software'); await p.waitSel('#rmm-pane-software table'); await sleep(300); await shot('asset-software-desktop-dark');
  const chip = await p.eval(`getComputedStyle(document.querySelector('#rmm-tags .rmm-tag')).color`);
  const [cr, cg, cb] = chip.match(/\d+/g).map(Number);
  assert(cr + cg + cb > 3 * 120, 'tag chip text is not light in dark mode: ' + chip);
  await go(asset('WIN1')); await p.waitSel('#rmm-net'); await sleep(300); await shot('asset-network-trend-desktop-dark');
  await go('/agent/rmm_fleet.php?osw=chrome&osv=126.0'); await p.waitSel('#rmm-outdated-sw table'); await sleep(300); await shot('fleet-outdated-desktop-dark');
  await go('/agent/rmm_fleet.php'); await p.waitSel('#rmm-devices table'); await sleep(300); await shot('fleet-desktop-dark');
  await openInstaller();
  const bg = await p.eval(`getComputedStyle(document.querySelector('#rmmInstallerModal .modal-content')).backgroundColor`);
  const [br, bgc, bb] = bg.match(/\d+/g).map(Number);
  assert(br + bgc + bb < 3 * 110, 'the installer dialog is not dark in dark mode: ' + bg);
  const ink = await p.eval(`getComputedStyle(document.getElementById('rmmInstallerTitle')).color`);
  const [ir, ig, ib] = ink.match(/\d+/g).map(Number);
  assert(ir + ig + ib > 3 * 120, 'the dialog title is not light in dark mode: ' + ink);
  await shot('installer-dialog-desktop-dark');
  await p.press('Escape'); await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'closed' });
  await p.setViewport(MOBILE_W, 800, true);
  await openInstaller(); await modalFits('dark phone'); await shot('installer-dialog-phone-dark');
  await p.press('Escape'); await p.waitFor(`!document.querySelector('#rmmInstallerModal.show')`, { label: 'closed' });
  await go(asset('WIN1')); await p.waitSel('#rmm-strip'); await sleep(500); await noOverflow('asset dark phone'); await shot('asset-overview-phone-dark');
  await go(asset('WIN1') + '#rmm-software'); await p.waitSel('#rmm-pane-software table'); await sleep(300); await noOverflow('asset software dark phone'); await shot('asset-software-phone-dark');
  await go('/agent/rmm_fleet.php'); await p.waitSel('#rmm-devices table'); await sleep(300); await noOverflow('fleet dark phone'); await shot('fleet-phone-dark');
  await p.setViewport(1366, 900);
  await setTheme(0);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
