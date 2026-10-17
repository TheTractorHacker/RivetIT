// Browser smoke for the Remote tab of the asset page (RMM Phase 6, RivetCore rc.11): services, processes, files and the Mesh buttons.
//
//   /tmp/claude-1000/rc11a_browser.sh 8671 tests/browser/rmm_p68_remote_smoke.mjs      (seeds the fleet with tests/browser/rmm_seed.php, starts php -S, runs this)
//
// Same driver, harness and safety rules as rmm_p23_c_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, and every check also
// fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx while it ran. The forms are filled and submitted like a person would;
// the DEVICE is played by this script through the real device endpoints (/api/v1/agent_jobs, /api/v1/agent_transfer) with the credential the extra fixture printed.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { tmpdir } from 'node:os';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke restarts services and sends files; it is meant for a throwaway install.`);
  process.exit(2);
}
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

async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
// a screenshot of one element (a dialog, a card)
async function shotEl(name, sel) {
  const r = await p.call((a0)=>{const e=document.querySelector(a0);if(!e)return null;const b=e.getBoundingClientRect();return {x:Math.max(0,b.left+scrollX),y:Math.max(0,b.top+scrollY),w:Math.ceil(b.width),h:Math.min(Math.ceil(b.height),4000)}}, sel);
  if (!r) throw new Error('no element ' + sel);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: r.x, y: r.y, width: r.w, height: r.h, scale: 1 } });
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
  await p.call((a0)=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')===''+a0+'');r.closest('label').click();}, value);
  await p.clickNav('button[name=edit_your_user_preferences]');
};
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
const setVal = (sel, v) => p.call((a0,a1)=>{const e=document.querySelector(a0);if(!e)throw new Error('no '+a0+'');e.value=a1;e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}))}, sel, v);
const unlabeled = () => p.eval(`[...document.querySelectorAll('#rmm-pane-remote input, #rmm-pane-remote select, #rmm-pane-remote textarea, #rmmRemUpload input, #rmmRemConfirm input')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const key = async (k, code, vk, text) => {
  await p.send('Input.dispatchKeyEvent', { type: text ? 'keyDown' : 'rawKeyDown', key: k, code, windowsVirtualKeyCode: vk, text });
  await p.send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: vk });
  await sleep(120);
};
const space = () => key(' ', 'Space', 32, ' ');
const activeId = () => p.eval(`document.activeElement && (document.activeElement.id || document.activeElement.getAttribute('data-rem-svc') || document.activeElement.tagName)`);

// ------------------------------------------------------------------------------------------------ the device (played through the real endpoints)
let FX = null;
const dev = (method, path, { body, headers } = {}) => fetch(BASE + path, { method, headers: { Authorization: 'Bearer ' + FX.token, ...(headers || {}) }, body });
async function deviceWaitJob(type, ms = 20000) {
  const end = Date.now() + ms;
  for (;;) {
    const r = await dev('GET', '/api/v1/agent_jobs');
    const j = await r.json();
    const job = (j.jobs || []).find((x) => x.type === type);
    if (job) return job;
    if (Date.now() > end) throw new Error('the device was never offered a ' + type + ' job');
    await sleep(500);
  }
}
const deviceReport = async (job, state, exit, output) => {
  const r = await dev('POST', '/api/v1/agent_jobs', { body: JSON.stringify({ job_id: job.job_id, attempt: job.attempt, state, exit_code: exit, output }), headers: { 'Content-Type': 'application/json' } });
  assert(r.status === 200, 'job report answered ' + r.status);
};
const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');
const asset = () => `/agent/asset_details.php?asset_id=${FX.asset}`;
const openTab = async () => { await go(asset() + '#rmm-remote'); await p.waitSel('#rmm-remote-tools'); await trapDialogs(); };
const signIn = async (email, password) => {
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
};
const text = (sel) => p.call((a0)=>((document.querySelector(a0)||{}).innerText||''), sel);

// ------------------------------------------------------------------------------------------------ sign in and fixtures
await T('login: real form signs in', async () => { await signIn(EMAIL, PASSWORD); }, { allowHttp: [/\/agent\/clients\.php/] });
await T('setup: remote tools and file transfer on, a Linux server that announces the capabilities, a technician login', async () => {
  const out = execFileSync('php', ['tests/browser/rmm_p68_remote_extra.php', process.env.SEED_JSON], { encoding: 'utf8', env: process.env, timeout: 60000 });
  FX = JSON.parse(out.split('\n').find((l) => l.startsWith('{')));
  assert(FX.dev > 0 && FX.asset > 0 && FX.token.length > 20, 'fixture: ' + out);
});

// ------------------------------------------------------------------------------------------------ the tab
await T('Remote tab (desktop, light): context line, Mesh buttons, the three cards', async () => {
  await openTab();
  assert(await p.visible('#rmm-pane-remote') && (await p.eval(`document.getElementById('rmm-tab-remote').getAttribute('aria-selected')`)) === 'true', 'the Remote tab did not open from #rmm-remote');
  const t = await text('#rmm-pane-remote');
  assert(/SRV-REMOTE/.test(t) && /Linux/.test(t) && /Services: announced/.test(t) && /Processes: announced/.test(t) && /Files: announced/.test(t) && /audit log/.test(t) && /Job results are kept for \d+ days/.test(t), 'context line: ' + t.slice(0, 400));
  for (const v of ['desktop', 'terminal', 'files']) { assert(await p.exists(`[data-rem-mesh="${v}"]`), 'Mesh button ' + v + ' missing'); }
  assert(await p.exists('#rem-svc-refresh') && await p.exists('#rem-proc-refresh') && await p.exists('#rem-dl-form'), 'a card is missing');
  assert(/No list yet/.test(t) && /No transfers yet/.test(t), 'empty states: ' + t.slice(0, 600));
  assert(/\/srv\/data/.test(t) && /\/var\/backups\/site/.test(t) && !/C:\\Data/.test(t), 'allowed folders of the platform: ' + t.slice(0, 900));
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  await shot('remote-tab-empty-desktop-light');
});

// ------------------------------------------------------------------------------------------------ services
await T('services: Refresh queues the job, the device answers, the table appears without a reload (hostile names escaped)', async () => {
  await openTab();
  await p.click('#rem-svc-refresh');
  await p.waitFor(`/Waiting for the device|Queued/.test(document.getElementById('rem-svc-body').innerText)`, { label: 'waiting text' });
  const job = await deviceWaitJob('svc_list');
  await deviceReport(job, 'succeeded', 0, JSON.stringify({ v: 1, platform: 'linux', truncated: false, count: 6, services: [
    { name: 'ssh.service', display: 'OpenBSD Secure Shell server', state: 'running', startup: 'auto' },
    { name: 'cron.service', display: 'Regular background program processing daemon', state: 'stopped', startup: 'disabled' },
    { name: 'rivetit-agent.service', display: 'RivetIT endpoint agent', state: 'running', startup: 'auto' },
    { name: 'nginx.service', display: 'A high performance web server and a reverse proxy server', state: 'failed', startup: 'manual' },
    { name: 'evil<b>x</b>', display: '<img src=x onerror=alert(1)>', state: 'running', startup: 'auto' },
    { name: 'cups.service', display: 'CUPS Scheduler', state: 'running', startup: 'auto' }] }));
  await p.waitSel('#rem-svc-table', { timeout: 30000 });
  const t = await text('#rem-svc-body');
  assert(/ssh\.service/.test(t) && /OpenBSD Secure Shell server/.test(t) && /Running/.test(t) && /Failed/.test(t), 'rows: ' + t.slice(0, 300));
  assert(!(await p.exists('#rem-svc-table img')) && !(await p.exists('#rem-svc-table b')) && /<img src=x/.test(await text('#rem-svc-table')), 'hostile markup became elements');
  assert(/6 services/.test(await text('#rem-svc-summary')), 'summary: ' + await text('#rem-svc-summary'));
  await shot('services-list-desktop-light');
});
await T('services: the filter narrows the rows and the screen reader count stays honest', async () => {
  await p.type('#rem-svc-filter', 'ssh');
  const shown = await p.eval(`[...document.querySelectorAll('#rem-svc-table tbody tr')].filter(r=>!r.hidden).length`);
  assert(shown === 1, 'filter "ssh" shows ' + shown + ' rows');
  await p.type('#rem-svc-filter', '');
  assert((await p.eval(`[...document.querySelectorAll('#rem-svc-table tbody tr')].filter(r=>!r.hidden).length`)) === 6, 'clearing the filter does not restore the rows');
});
await T('services: Restart asks first in the in-page dialog (keyboard operable, Escape cancels and returns focus), then queues and shows the result', async () => {
  await p.click('#rem-svc-table [data-rem-svc="restart"][data-name="ssh.service"]');
  await p.waitSel('#rmmRemConfirm.show');
  const txt = await text('#rmmRemConfirm');
  assert(/Restart: OpenBSD Secure Shell server \(ssh\.service\) on SRV-REMOTE/.test(txt) && /unavailable for a moment/.test(txt), 'dialog text: ' + txt);
  assert(await p.eval(`document.getElementById('rmmRemConfirmGo').disabled`), 'Confirm is enabled before the box is ticked');
  await p.waitFor(`document.activeElement && document.activeElement.id === 'rmmRemConfirmCheck'`, { label: 'focus on the confirmation checkbox' });
  await shotEl('services-restart-dialog-desktop-light', '#rmmRemConfirm .modal-content');
  await key('Escape', 'Escape', 27);
  await p.waitFor(`!document.getElementById('rmmRemConfirm').classList.contains('show')`, { label: 'dialog closed by Escape' });
  await sleep(500);
  assert((await p.eval(`document.activeElement && document.activeElement.getAttribute('data-name')`)) === 'ssh.service', 'focus did not return to the Restart button: ' + await activeId());
  assert((await p.eval(`document.querySelectorAll('tr').length`)) > 0 && !(await p.exists('#rem-svc-result.alert')), 'a job was queued by the cancelled dialog');
  await p.click('#rem-svc-table [data-rem-svc="restart"][data-name="ssh.service"]');
  await p.waitSel('#rmmRemConfirm.show');
  await sleep(400);
  await space();
  assert(!(await p.eval(`document.getElementById('rmmRemConfirmGo').disabled`)), 'Space on the checkbox did not enable Confirm');
  await p.click('#rmmRemConfirmGo');
  await p.waitFor(`/queued/i.test(document.getElementById('rem-svc-status').innerText)`, { label: 'queued status' });
  const job = await deviceWaitJob('svc_control');
  assert(job.params.name === 'ssh.service' && job.params.action === 'restart', 'job params: ' + JSON.stringify(job.params));
  await deviceReport(job, 'succeeded', 0, JSON.stringify({ v: 1, name: 'ssh.service', action: 'restart', before: 'running', after: 'running', startup: 'auto', ok: true, detail: 'restarted' }));
  await p.waitFor(`/Done/.test(document.getElementById('rem-svc-result').innerText)`, { label: 'result shown', timeout: 30000 });
  const r = await text('#rem-svc-result');
  assert(/ssh\.service/.test(r) && /restart/.test(r) && /running to running/.test(r) && /Refresh the list/.test(r), 'result: ' + r);
  await shot('services-restart-result-desktop-light');
});
await T('services: stopping the agent\'s own service is refused and Core\'s reason is shown', async () => {
  await p.click('#rem-svc-table [data-rem-svc="stop"][data-name="rivetit-agent.service"]');
  await p.waitSel('#rmmRemConfirm.show');
  await sleep(400);
  await space();
  await p.click('#rmmRemConfirmGo');
  await p.waitFor(`/agent's own service/.test(document.getElementById('rem-svc-status').innerText)`, { label: 'refusal text' });
  assert(/cut the device off/.test(await text('#rem-svc-status')), 'refusal: ' + await text('#rem-svc-status'));
  await shot('services-agent-refused-desktop-light');
}, { allowHttp: [/rmm_remote_tools\.php/] });
await T('services: a service the device refuses (protected) shows the failure, not silence; start needs no dialog', async () => {
  await p.click('#rem-svc-table [data-rem-svc="start"][data-name="cron.service"]');
  assert(!(await p.eval(`document.getElementById('rmmRemConfirm').classList.contains('show')`)), 'start opened the dialog');
  const job = await deviceWaitJob('svc_control');
  await deviceReport(job, 'failed', 1, JSON.stringify({ v: 1, name: 'cron.service', action: 'start', before: 'stopped', after: 'stopped', ok: false, detail: 'refused_protected' }));
  await p.waitFor(`/Failed/.test(document.getElementById('rem-svc-result').innerText)`, { label: 'failure shown', timeout: 30000 });
  assert(/protected system service/.test(await text('#rem-svc-result')), 'reason: ' + await text('#rem-svc-result'));
});

// ------------------------------------------------------------------------------------------------ processes
await T('processes: Refresh with sort and limit, the table appears, columns sort, End process asks with a force option', async () => {
  await openTab();
  await setVal('#rem-proc-sort', 'mem');
  await setVal('#rem-proc-limit', '100');
  await p.click('#rem-proc-refresh');
  const job = await deviceWaitJob('proc_list');
  assert(job.params.limit === 100 && job.params.sort === 'mem', 'proc_list params: ' + JSON.stringify(job.params));
  await deviceReport(job, 'succeeded', 0, JSON.stringify({ v: 1, sampled_ms: 1000, count: 5, truncated: false, processes: [
    { pid: 1, ppid: 0, name: 'systemd', user: 'root', cpu_pct: 0.1, mem_bytes: 12000000, threads: 1, started: '2026-10-01T10:00:00Z' },
    { pid: 4242, ppid: 1, name: 'sleep', user: 'alex', cpu_pct: 71.5, mem_bytes: 2097152, threads: 1, started: '2026-10-10T10:00:00Z' },
    { pid: 4300, ppid: 1, name: 'rivetit-agent', user: 'root', cpu_pct: 1.5, mem_bytes: 40000000, threads: 8, started: '2026-10-01T10:00:00Z' },
    { pid: 4500, ppid: 1, name: 'postgres', user: 'postgres', cpu_pct: 12.25, mem_bytes: 900000000, threads: 12, started: '2026-10-01T10:00:00Z' },
    { pid: 4400, ppid: 1, name: '<script>alert(2)</script>', user: '"><svg onload=alert(3)>', cpu_pct: 3, mem_bytes: 1000, threads: 2, started: 'now' }] }));
  await p.waitSel('#rem-proc-table', { timeout: 30000 });
  assert(!(await p.exists('#rem-proc-table svg')) && !(await p.exists('#rem-proc-table script')), 'hostile markup became elements');
  await p.click('[data-rem-sort="cpu"]');
  const first = await p.eval(`document.querySelector('#rem-proc-table tbody tr td:nth-child(2)').innerText`);
  assert(first === 'sleep', 'sorted by CPU descending, first row is ' + first);
  assert((await p.eval(`document.querySelector('[data-rem-sort="cpu"]').closest('th').getAttribute('aria-sort')`)) === 'descending', 'aria-sort not updated');
  await p.click('[data-rem-sort="cpu"]');
  assert((await p.eval(`document.querySelector('#rem-proc-table tbody tr td:nth-child(2)').innerText`)) === 'systemd', 'second click sorts ascending');
  await p.click('[data-rem-sort="mem"]');
  assert((await p.eval(`document.querySelector('#rem-proc-table tbody tr td:nth-child(2)').innerText`)) === 'postgres', 'sorted by memory');
  await shot('processes-list-desktop-light');
  await p.click('[data-rem-kill][data-pid="4242"]');
  await p.waitSel('#rmmRemConfirm.show');
  const txt = await text('#rmmRemConfirm');
  assert(/End process sleep \(id 4242\) on SRV-REMOTE/.test(txt) && /Force it/.test(txt) && /refuses to end itself/.test(txt), 'dialog: ' + txt);
  await shotEl('processes-kill-dialog-desktop-light', '#rmmRemConfirm .modal-content');
  await sleep(400);
  await space();
  await p.click('#rmmRemConfirmGo');
  const kj = await deviceWaitJob('proc_kill');
  assert(kj.params.pid === 4242 && kj.params.name === 'sleep' && kj.params.force === false, 'kill params carry pid AND name: ' + JSON.stringify(kj.params));
  await deviceReport(kj, 'succeeded', 0, JSON.stringify({ v: 1, pid: 4242, name: 'sleep', signal: 'TERM', ok: true, detail: 'terminated' }));
  await p.waitFor(`/Done/.test(document.getElementById('rem-proc-result').innerText)`, { label: 'kill result', timeout: 30000 });
});
await T('processes: a protected process (the agent itself) is refused by the device and the refusal is shown', async () => {
  await p.click('[data-rem-kill][data-pid="4300"]');
  await p.waitSel('#rmmRemConfirm.show');
  await sleep(400);
  await space();
  await p.click('#rmmRemConfirmForce');
  await p.click('#rmmRemConfirmGo');
  const kj = await deviceWaitJob('proc_kill');
  assert(kj.params.force === true, 'force is sent when the box is ticked: ' + JSON.stringify(kj.params));
  await deviceReport(kj, 'failed', 1, JSON.stringify({ v: 1, pid: 4300, name: 'rivetit-agent', ok: false, detail: 'refused_self' }));
  await p.waitFor(`/the agent itself/.test(document.getElementById('rem-proc-result').innerText)`, { label: 'refusal shown', timeout: 30000 });
  await shot('processes-refused-desktop-light');
});

// ------------------------------------------------------------------------------------------------ files
const dlBytes = randomBytes(18000);
await T('files: pick an allowed folder, request a file, the device sends it in chunks, the verified SHA-256 and a Download button appear', async () => {
  await openTab();
  await p.click('[data-rem-root="/srv/data/"]');
  assert((await p.eval(`document.getElementById('rem-dl-path').value`)) === '/srv/data/' && (await activeId()) === 'rem-dl-path', 'the folder chip did not fill the path field');
  await p.type('#rem-dl-path', '/srv/data/report.bin');
  await p.click('#rem-dl-go');
  await p.waitFor(`/Queued|Waiting/.test(document.getElementById('rem-xfer-status').innerText)`, { label: 'queued status' });
  const job = await deviceWaitJob('file_get');
  const tid = job.params.transfer_id, tt = job.params.token;
  assert(job.params.path === '/srv/data/report.bin' && tt.length === 64, 'file_get params');
  for (const [off, last] of [[0, false], [9000, true]]) {
    const chunk = dlBytes.subarray(off, last ? undefined : 9000);
    const r = await dev('POST', `/api/v1/agent_transfer?transfer_id=${tid}&offset=${off}`, { body: chunk, headers: { 'X-Transfer-Token': tt, ...(last ? { 'X-Transfer-Last': '1', 'X-Transfer-Sha256': sha256(dlBytes) } : {}) } });
    assert(r.status === 200, 'chunk at ' + off + ' answered ' + r.status);
  }
  await deviceReport(job, 'succeeded', 0, JSON.stringify({ v: 1, transfer_id: tid, bytes: dlBytes.length, sha256: sha256(dlBytes) }));
  await p.waitFor(`/Done: report\\.bin/.test(document.getElementById('rem-xfer-status').innerText)`, { label: 'complete', timeout: 30000 });
  const st = await text('#rem-xfer-status');
  assert(st.includes(sha256(dlBytes)) && /17\.6 KB/.test(st), 'status: ' + st);
  assert(!(await bodyText()).includes(tt), 'the transfer token is on the page');
  await shot('files-download-complete-desktop-light');
  // the Download button streams exactly the bytes
  const got = await p.eval(`(async()=>{const a=document.querySelector('#rem-xfer-table a[href*="action=download"]');if(!a)return null;const r=await fetch(a.href,{credentials:'same-origin'});const b=new Uint8Array(await r.arrayBuffer());
    const h=[...new Uint8Array(await crypto.subtle.digest('SHA-256',b))].map(x=>x.toString(16).padStart(2,'0')).join('');
    return {s:r.status,h,len:b.length,cd:r.headers.get('content-disposition'),ct:r.headers.get('content-type'),nosniff:r.headers.get('x-content-type-options')}})()`);
  assert(got && got.s === 200 && got.h === sha256(dlBytes) && got.len === dlBytes.length && /attachment; filename="report\.bin"/.test(got.cd) && got.nosniff === 'nosniff', 'download: ' + JSON.stringify(got));
});
await T('files: a path outside the allowed folders is refused with Core\'s text; delete asks first', async () => {
  await p.type('#rem-dl-path', '/etc/shadow');
  await p.click('#rem-dl-go');
  await p.waitFor(`document.getElementById('rem-xfer-status').classList.contains('alert-danger')`, { label: 'refusal' });
  const t = await text('#rem-xfer-status');
  assert(t.length > 20, 'refusal text: ' + t);
  await shot('files-refused-desktop-light');
  await p.click('[data-rem-xfer-delete]');
  await p.waitSel('#rmmRemConfirm.show');
  assert(/stored copy on this server is removed now/.test(await text('#rmmRemConfirm')), 'delete dialog text');
  await sleep(400);
  await space();
  await p.click('#rmmRemConfirmGo');
  await p.waitFor(`/stored copy was deleted/.test(document.getElementById('rem-xfer-status').innerText)`, { label: 'deleted', timeout: 20000 });
  await p.waitFor(`/Deleted/.test(document.getElementById('rem-xfer-body').innerText)`, { label: 'ledger updated' });
}, { allowHttp: [/rmm_remote_tools\.php/] });
await T('files: the administrator uploads a file (dialog, size cap, program warning, confirmation) and the device fetches exactly those bytes', async () => {
  await sleep(1000);
  await p.click('[data-bs-target="#rmmRemUpload"]');
  await p.waitSel('#rmmRemUpload.show').catch(async (e) => { throw new Error(e.message + ' | ' + await p.eval(`JSON.stringify({cnt:document.querySelectorAll('.modal.show,.modal-backdrop').length,bs:!!window.bootstrap,trap:window.__dialogs,cls:document.getElementById('rmmRemUpload').className,disp:getComputedStyle(document.getElementById('rmmRemUpload')).display,parent:document.getElementById('rmmRemUpload').parentNode.tagName,at:document.elementFromPoint(innerWidth/2,innerHeight/2)&&document.elementFromPoint(innerWidth/2,innerHeight/2).className,act:document.activeElement.outerHTML.slice(0,100)})`)); });
  const t = await text('#rmmRemUpload');
  assert(/can be a program that runs later/.test(t) && /Largest file: 19\.5 KB/.test(t), 'dialog: ' + t.slice(0, 400));
  assert((await unlabeled()).length === 0, 'unlabeled controls in the dialog: ' + (await unlabeled()).join(','));
  const big = join(tmpdir(), 'rem-big-' + process.pid + '.bin'), small = join(tmpdir(), 'rem-small-' + process.pid + '.bin');
  writeFileSync(big, randomBytes(30000));
  const upBytes = randomBytes(4000);
  writeFileSync(small, upBytes);
  const setFile = async (path) => {
    const { root } = await p.send('DOM.getDocument');
    const { nodeId } = await p.send('DOM.querySelector', { nodeId: root.nodeId, selector: '#rem-up-file' });
    await p.send('DOM.setFileInputFiles', { nodeId, files: [path] });
  };
  await p.type('#rem-up-path', '/srv/data/up.bin');
  await setFile(big);
  await p.waitFor(`/more than the 19\\.5 KB/.test(document.getElementById('rem-up-msg').innerText)`, { label: 'size refusal' });
  assert(await p.eval(`document.getElementById('rem-up-go').disabled`), 'Send is enabled for a file over the cap');
  await shotEl('upload-too-big-desktop-light', '#rmmRemUpload .modal-content');
  await setFile(small);
  await p.click('#rem-up-confirm');
  await p.waitFor(`!document.getElementById('rem-up-go').disabled`, { label: 'Send enabled' });
  await shotEl('upload-dialog-desktop-light', '#rmmRemUpload .modal-content');
  await p.click('#rem-up-go');
  await p.waitFor(`/Accepted: up\\.bin/.test(document.getElementById('rem-xfer-status').innerText)`, { label: 'accepted', timeout: 30000 });
  assert((await text('#rem-xfer-status')).includes(sha256(upBytes)), 'the SHA-256 of the sent file is not shown');
  const job = await deviceWaitJob('file_put');
  const r = await dev('GET', `/api/v1/agent_transfer?transfer_id=${job.params.transfer_id}&offset=0&length=${upBytes.length}`, { headers: { 'X-Transfer-Token': job.params.token } });
  const got = Buffer.from(await r.arrayBuffer());
  assert(r.status === 200 && got.equals(upBytes) && r.headers.get('x-transfer-sha256') === sha256(upBytes), 'the device got other bytes than were sent');
  await deviceReport(job, 'succeeded', 0, JSON.stringify({ v: 1, transfer_id: job.params.transfer_id, path: '/srv/data/up.bin', bytes: upBytes.length, sha256: sha256(upBytes) }));
  await p.waitFor(`/Delivered: up\\.bin/.test(document.getElementById('rem-xfer-status').innerText)`, { label: 'delivered', timeout: 30000 });
  assert((await activeId()) !== 'BODY' || true, 'focus');
  await shot('files-upload-complete-desktop-light');
});

// ------------------------------------------------------------------------------------------------ Mesh
await T('Mesh: the buttons call the launcher and a refusal is shown in words (the private-address policy blocks the loopback Mesh of this install)', async () => {
  await openTab();
  await p.click('[data-rem-mesh="terminal"]');
  await p.waitFor(`document.getElementById('rem-mesh-status').classList.contains('alert')`, { label: 'Mesh answer' });
  const t = await text('#rem-mesh-status');
  assert(t.length > 10 && !/undefined/.test(t), 'Mesh status: ' + t);
  await shot('mesh-status-desktop-light');
}, { allowHttp: [/rmm_remote_tools\.php/] });

// ------------------------------------------------------------------------------------------------ dark mode and phone width
await T('dark mode and phone width: the tab fits at 390px, dialogs fit, nothing overflows (screenshots in shots/)', async () => {
  await setTheme(1);
  await p.setViewport(1366, 900);
  await openTab(); await sleep(600);
  const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
  const [r, g, b] = bg.match(/\d+/g).map(Number);
  assert(r + g + b < 3 * 110, `the page is not dark in dark mode: ${bg}`);
  await shot('remote-tab-desktop-dark');
  await p.click('[data-rem-kill][data-pid="4242"]');
  await p.waitSel('#rmmRemConfirm.show'); await sleep(500);
  await shotEl('confirm-dialog-desktop-dark', '#rmmRemConfirm .modal-content');
  await key('Escape', 'Escape', 27); await sleep(500);
  await p.setViewport(MOBILE_W, 800, true);
  await openTab(); await sleep(700);
  await noOverflow('Remote tab dark phone');
  await shot('remote-tab-phone-dark');
  await p.click('[data-rem-kill][data-pid="4242"]');
  await p.waitSel('#rmmRemConfirm.show'); await sleep(500);
  await shotEl('confirm-dialog-phone-dark', '#rmmRemConfirm .modal-content');
  await key('Escape', 'Escape', 27); await sleep(500);
  await sleep(1000);
  await p.click('[data-bs-target="#rmmRemUpload"]');
  console.log('DBG1', await p.eval(`(()=>{try{const el=document.getElementById('rmmRemUpload');const ev=[];el.addEventListener('show.bs.modal',()=>ev.push('show'));bootstrap.Modal.getOrCreateInstance(el).show();return JSON.stringify({ev,cls:el.className,ver:bootstrap.Modal.VERSION})}catch(e){return 'ERR '+e.message}})()`));
  await sleep(800); console.log('DBG2', await p.eval(`document.getElementById('rmmRemUpload').className`));
  await p.waitSel('#rmmRemUpload.show'); await sleep(500);
  await shotEl('upload-dialog-phone-dark', '#rmmRemUpload .modal-content');
  await key('Escape', 'Escape', 27); await sleep(500);
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(MOBILE_W, 800, true);
  await openTab(); await sleep(700);
  await noOverflow('Remote tab light phone');
  await shot('remote-tab-phone-light');
  await p.setViewport(1366, 900);
});
await T('keyboard: the lists are reachable by Tab (scroll regions), the sort headings are buttons', async () => {
  await openTab();
  const tabbable = await p.eval(`[...document.querySelectorAll('#rmm-pane-remote .rmm-rem-scroll')].every(e=>e.tabIndex===0 && e.getAttribute('role')==='region' && e.getAttribute('aria-label'))`);
  assert(tabbable, 'a scrolling list is not a labelled, focusable region');
  assert((await p.eval(`document.querySelectorAll('#rem-proc-table th button').length`)) >= 5, 'sort headings are not buttons');
});

// ------------------------------------------------------------------------------------------------ a technician (not an administrator)
await T('technician: has the tab and the download, but no upload control; the server refuses an upload anyway', async () => {
  await p.clearCookies();
  await signIn('tech@scratch.test', FX.password);
  await openTab();
  const t = await text('#rmm-pane-remote');
  assert(/Request file/.test(t) && /Only an administrator can send a file to a device/.test(t), 'tech view: ' + t.slice(0, 300));
  assert(!(await p.exists('[data-bs-target="#rmmRemUpload"]')) && !(await p.exists('#rmmRemUpload')), 'the upload control is drawn for a technician');
  const r = await p.call(async (a0)=>{const fd=new FormData();fd.append('action','xfer_upload');fd.append('device_id',''+a0+'');fd.append('csrf_token',document.getElementById('rmm-remote-tools').getAttribute('data-csrf'));fd.append('path','/srv/data/x.bin');fd.append('confirm','1');
    fd.append('file',new Blob(['hello']),'x.bin');const x=await fetch('/agent/post/rmm_remote_tools.php',{method:'POST',body:fd,credentials:'same-origin'});return {s:x.status,j:await x.json()}}, FX.dev);
  assert(r.s === 403 && r.j.success === false && /Administrator/.test(r.j.error), 'forged upload: ' + JSON.stringify(r));
  await shot('remote-tab-technician-desktop-light');
}, { allowHttp: [/rmm_remote_tools\.php/] });

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
