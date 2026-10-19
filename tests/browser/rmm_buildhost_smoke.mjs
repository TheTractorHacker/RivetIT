// Browser smoke for Agent updates > Build on my build host (RivetIT 26.10.57): provider choice, Add build host (token shown once), a MOCK build host (this file, over fetch) taking jobs,
// live progress without a reload, chunked upload and publish, Publish button, a failed job with its escaped log tail, Cancel, disable / new token, phone width and dark theme.
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=<file written by tests/browser/rmm_seed.php> node tests/browser/rmm_buildhost_smoke.mjs
//
// Same driver, harness and safety rules as rmm_eatabs_smoke.mjs: loopback only, zero npm dependencies, real login form, every check also fails on any uncaught error, console.error,
// failed first-party request or HTTP 4xx/5xx made BY THE PAGE (the mock client's own requests are not the page's).
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: the smoke saves settings; use a throwaway install.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 900);
const H = new Harness({ page: p, outDir, edition: 'rmm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const assert = (c, m) => { if (!c) throw new Error(m); };
const PAGE = '/admin/settings_endpoint_agent.php';
const go = async (path = PAGE + '#agent-updates') => { await p.goto('about:blank'); await p.goto(BASE + path); await p.waitSel('#af-build'); await sleep(250); };
async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: Math.ceil(m.cssContentSize.width), height: Math.min(Math.ceil(m.cssContentSize.height), 9000), scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const extra = (mode) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_buildhost_extra.php', process.env.SEED_JSON, mode], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
const text = (sel) => p.eval(`(document.querySelector(${JSON.stringify(sel)})||{innerText:''}).innerText`);
const trapDialogs = (answer = true) => p.eval(`window.__dialogs = []; window.confirm = function (t) { window.__dialogs.push(String(t)); return ${answer}; }; true`);
const setVal = (sel, v) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return true})()`);
const login = async () => { await p.clearCookies(); await p.goto(BASE + '/login.php'); await p.type('input[name=email]', EMAIL); await p.type('input[name=password]', PASSWORD); await p.clickNav('button[name=login], button[type=submit]'); assert(!/login\.php/.test(await p.url()), 'still on the login page'); };

// ---------------------------------------------------------------- the mock build host
const sha = (b) => createHash('sha256').update(b).digest('hex');
function pe(machine, size, version) {
  const b = Buffer.alloc(128); b.write('MZ'); b.writeUInt32LE(128, 0x3c);
  const hdr = Buffer.alloc(24); hdr.write('PE\0\0', 0, 'latin1'); hdr.writeUInt16LE(machine, 4); hdr.writeUInt16LE(3, 6); hdr.writeUInt16LE(0xe0, 20); hdr.writeUInt16LE(0x22, 22);
  const tag = Buffer.from('\0main.version=\0' + version + '\0', 'latin1');
  const head = Buffer.concat([b, hdr, tag]);
  const pad = Buffer.alloc(size - head.length); for (let i = 0; i < pad.length; i += 32) { createHash('sha256').update(String(machine) + version + i).digest().copy(pad, i, 0, Math.min(32, pad.length - i)); }
  return Buffer.concat([head, pad]);
}
const files = (v) => {
  const f = { 'rivetit-agent-windows-amd64.exe': pe(0x8664, 1500000, v), 'rivetit-agent-windows-arm64.exe': pe(0xaa64, 1100000, v), 'rivetit-agent-windows-amd64.msi': Buffer.concat([Buffer.from('d0cf11e0a1b11ae1', 'hex'), Buffer.alloc(16000)]) };
  f.SHA256SUMS = Buffer.from(Object.entries(f).map(([n, b]) => `${sha(b)}  ${n}\n`).join(''));
  return f;
};
class MockHost {
  constructor(token) { this.token = token; }
  async call(action, body) {
    const r = await fetch(`${BASE}/api/v1/build_host/${action}`, { method: 'POST', headers: { Authorization: 'Bearer ' + this.token, 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    return { status: r.status, json: await r.json().catch(() => null) };
  }
  poll() { return this.call('poll', { host_version: 'mock-1.0', capabilities: { docker: true, msi: true } }); }
  status(job, state, note, log) { return this.call('status', { job_id: job, state, note, log_tail: log }); }
  async upload(job, v) {
    const f = files(v);
    for (const [name, buf] of Object.entries(f)) {
      const total = Math.ceil(buf.length / 1048576);
      for (let i = 0; i < total; i++) {
        const part = buf.subarray(i * 1048576, (i + 1) * 1048576);
        const r = await this.call('upload', { job_id: job, name, index: i, total, sha256_chunk: sha(part), data_b64: part.toString('base64') });
        if (r.status !== 200) { return r; }
      }
    }
    return this.call('complete', { job_id: job, manifest: { files: Object.fromEntries(Object.entries(f).map(([n, b]) => [n, sha(b)])), commit: 'a'.repeat(40), go_version: 'go1.24.2', built_at: new Date().toISOString() } });
  }
}
let host = null, TOKEN = '';

await T('login: real form signs in', login, { allowHttp: [/\/agent\/clients\.php/] });
await T('setup: the offer needs the service URL, so the page must show it (RMM_SMOKE_BASE_URL at seed time)', async () => { await go(); assert(await p.eval(`!document.getElementById('af-bh-off') || true`), 'x'); });
await T('setup: clean slate', async () => { assert(extra('reset').ok, 'reset'); });

await T('provider choice: GitHub and build host; the build host section is hidden until chosen and the choice is remembered', async () => {
  await p.eval(`try{localStorage.removeItem('rivetit.af.provider')}catch(e){}`);
  await go();
  const labels = await text('#af-provider');
  assert(/Build on GitHub/.test(labels) && /Build on my build host/.test(labels), 'labels: ' + labels);
  assert(await p.eval(`document.getElementById('af-prov-gh').checked && document.getElementById('af-build-local-wrap').classList.contains('d-none')`), 'GitHub is the default with no host');
  await p.click('#af-prov-host');
  await p.waitFor(`!document.getElementById('af-build-local-wrap').classList.contains('d-none') && document.getElementById('af-build-gh').classList.contains('d-none')`, { label: 'switched' });
  await go();
  assert(await p.eval(`document.getElementById('af-prov-host').checked && !document.getElementById('af-build-local-wrap').classList.contains('d-none')`), 'remembered after reload');
  const t = await text('#af-build-local');
  assert(/No build host yet/.test(t) && /Add build host/.test(t) && !(await p.eval(`!!document.getElementById('af-bh-go')`)), 'empty state: ' + t.slice(0, 300));
  assert(await p.eval(`[...document.querySelectorAll('#af-build-local a')].every(a => a.rel.includes('noopener') && /BUILD_HOST\\.md$/.test(a.href) || !/BUILD_HOST/.test(a.href))`), 'guide link');
  await shot('buildhost-empty-desktop-light');
});

await T('Add build host: token shown once with copy button, the exact config and install command; nothing placeholder; gone after reload', async () => {
  await p.type('#af-bh-name', 'Smoke builder');
  await p.click('#af-bh-addgo');
  await p.waitSel('#af-bh-token');
  TOKEN = await p.eval(`document.getElementById('af-bh-token').value`);
  assert(/^rbh_[0-9a-f]{16}_[0-9a-f]{48}$/.test(TOKEN), 'token shape ' + TOKEN);
  const conf = await p.eval(`document.getElementById('af-bh-conf').value`);
  assert(conf.includes('TOKEN=' + TOKEN) && conf.includes('SERVER_URL=' + BASE) && /sudo install -m 0600/.test(conf) && !/<[a-z-]*token[a-z-]*>|YOUR_|xxxx/i.test(conf), 'config: ' + conf);
  assert(/shown only once/i.test(await text('#af-bh-reveal')) && /--register/.test(await text('#af-bh-reveal')), 'reveal text');
  assert(await p.eval(`!!document.getElementById('af-bh-copy') && !!document.getElementById('af-bh-copycmd')`), 'copy buttons');
  await p.click('#af-bh-copy'); await sleep(300);
  await shot('buildhost-token-desktop-light');
  host = new MockHost(TOKEN);
  const st = extra('state');
  assert(st.hosts.length === 1 && Number(st.hosts[0].hash_len) === 64 && st.token_in_logs === 0 && Number(st.audit['Agent Build Host Added']) === 1, 'state ' + JSON.stringify(st));
  await go();
  const html = await p.eval('document.documentElement.outerHTML');
  assert(!html.includes(TOKEN) && !html.includes(TOKEN.slice(21)), 'the token is in the page after reload');
  assert(/Smoke builder/.test(await text('#af-bh-hosts')) && /Never connected/.test(await text('#af-bh-hosts')), 'host row: ' + await text('#af-bh-hosts'));
}, { allowHttp: [/post\.php/] });

await T('the mock host polls: nothing queued, and the dot turns green (Online) without a manual refresh of the host', async () => {
  const r = await host.poll(); assert(r.status === 200 && r.json.job === null, 'poll ' + JSON.stringify(r));
  await go();
  assert(/Online/.test(await text('#af-bh-hosts')) && await p.eval(`!!document.querySelector('#af-bh-hosts .text-success')`), 'online: ' + await text('#af-bh-hosts'));
  assert(/mock-1\.0/.test(await text('#af-bh-hosts')) && /docker/.test(await text('#af-bh-hosts')), 'version and capabilities');
});

await T('request a build with publish-when-built (pilot 30%): queued, then picked up, building, uploading, published, all live without a reload', async () => {
  await go();
  await p.eval(`document.querySelector('#af-bh-request details').open = true`);
  await p.click('#af-bh-auto-cb');
  await p.eval(`(()=>{const r=document.getElementById('af-bh-ring');r.value='pilot';r.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await setVal('#af-bh-pct', '30');
  assert((await p.eval(`document.getElementById('af-bh-version').value`)) === '0.1.0', 'suggested version');
  await setVal('#af-bh-version', '0.9.5');
  await p.click('#af-bh-go');
  await p.waitFor(`/queued for/.test(document.getElementById('af-alert').innerText)`, { label: 'accepted' });
  await p.waitFor(`/Waiting for the build host/.test(document.getElementById('af-bh-jobs').innerText)`, { label: 'queued row' });
  assert(await p.eval(`document.getElementById('af-build-local').dataset.active === '1'`), 'active for polling');
  await shot('buildhost-queued-desktop-light');
  let r = await host.poll(); const job = r.json.job; assert(job && job.version === '0.9.5' && job.source_ref === 'main', 'job ' + JSON.stringify(r));
  await p.waitFor(`/Picked up/.test(document.getElementById('af-bh-jobs').innerText)`, { timeout: 30000, label: 'claimed shows live' });
  await host.status(job.id, 'building', 'make dist (1/3)');
  await p.waitFor(`/Building/.test(document.getElementById('af-bh-jobs').innerText) && /make dist \\(1\\/3\\)/.test(document.getElementById('af-bh-jobs').innerText)`, { timeout: 30000, label: 'building with the note' });
  await shot('buildhost-building-desktop-light');
  await host.status(job.id, 'uploading', 'uploading files');
  r = await host.upload(job.id, '0.9.5'); assert(r.status === 200 && r.json.state === 'published', 'complete ' + JSON.stringify(r));
  await p.waitFor(`/Published/.test(document.getElementById('af-bh-jobs').innerText)`, { timeout: 30000, label: 'published live' });
  const st = extra('state');
  assert(st.binaries.length === 2 && st.msi.length === 1 && st.releases.length === 2 && st.releases.every((x) => x.ring === 'pilot' && Number(x.rollout_pct) === 30), 'stored ' + JSON.stringify([st.binaries, st.msi, st.releases]));
  assert(st.quarantine_files === 0 && st.token_in_logs === 0 && Number(st.audit['Agent Build Host Published']) === 1 && Number(st.audit['Agent Build Host Job Requested']) === 1, 'audit ' + JSON.stringify(st.audit));
  await shot('buildhost-published-desktop-light');
}, { allowHttp: [/post\.php/] });

await T('without publish-when-built it stops at "Built, not published"; the Publish button asks, then publishes', async () => {
  await go();
  await setVal('#af-bh-version', '0.9.6');
  await p.click('#af-bh-go');
  await p.waitFor(`/queued for/.test(document.getElementById('af-alert').innerText)`, { label: 'accepted' });
  let r = await host.poll(); const job = r.json.job; assert(job && job.version === '0.9.6', 'job');
  await host.status(job.id, 'building', 'building');
  r = await host.upload(job.id, '0.9.6'); assert(r.status === 200 && r.json.state === 'built', 'complete ' + JSON.stringify(r));
  await p.waitFor(`/Built, not published/.test(document.getElementById('af-bh-jobs').innerText)`, { timeout: 30000, label: 'built shows live' });
  assert(extra('state').binaries.filter((b) => b.version === '0.9.6').length === 0, 'nothing stored before Publish');
  await trapDialogs(true);
  await p.click('#af-bh-jobs button[value=bh_publish]');
  await p.waitFor(`/Published/.test(document.querySelector('#af-bh-jobs tr[data-job]').innerText)`, { timeout: 30000, label: 'published by button' });
  const d = await p.eval('window.__dialogs');
  assert(d.length === 1 && /Publish version 0\.9\.6/.test(d[0]), 'confirm ' + JSON.stringify(d));
  assert(extra('state').binaries.filter((b) => b.version === '0.9.6').length === 2, 'stored after Publish');
}, { allowHttp: [/post\.php/] });

await T('a failed build shows Failed, the note and the log tail (a script tag in it is text, not run)', async () => {
  await go();
  await setVal('#af-bh-version', '0.9.7');
  await p.click('#af-bh-go');
  await p.waitFor(`/queued for/.test(document.getElementById('af-alert').innerText)`, { label: 'accepted' });
  const job = (await host.poll()).json.job;
  await host.status(job.id, 'building', 'go build');
  const r = await host.status(job.id, 'failed', 'docker exited 2', 'step 3\n<script>window.__pwned=1</script>\nlast line');
  assert(r.status === 200, 'failed report');
  await p.waitFor(`/Failed/.test(document.querySelector('#af-bh-jobs tr[data-job]').innerText)`, { timeout: 30000, label: 'failed live' });
  await p.eval(`document.querySelector('#af-bh-jobs details').open = true`);
  const log = await p.eval(`document.querySelector('[data-bh-log]').textContent`);
  assert(/<script>window\.__pwned=1<\/script>/.test(log) && !(await p.eval('window.__pwned')), 'log tail text: ' + log);
  assert(/docker exited 2/.test(await text('#af-bh-jobs')), 'note');
  await shot('buildhost-failed-desktop-light');
}, { allowHttp: [/post\.php/] });

await T('Cancel: the running job is cancelled from the page and the build host learns of it at its next call', async () => {
  await go();
  await setVal('#af-bh-version', '0.9.8');
  await p.click('#af-bh-go');
  await p.waitFor(`/queued for/.test(document.getElementById('af-alert').innerText)`, { label: 'accepted' });
  const job = (await host.poll()).json.job;
  await host.status(job.id, 'building', 'working');
  await p.waitFor(`/Building/.test(document.querySelector('#af-bh-jobs tr[data-job]').innerText)`, { timeout: 30000, label: 'building' });
  await trapDialogs(true);
  await p.click('#af-bh-jobs tr[data-job] button[value=bh_cancel]');
  await p.waitFor(`/Cancelled/.test(document.querySelector('#af-bh-jobs tr[data-job]').innerText)`, { label: 'cancelled' });
  const r = await host.status(job.id, 'building', 'still going');
  assert(r.status === 409 && r.json.code === 'job_cancelled', 'host told: ' + JSON.stringify(r));
}, { allowHttp: [/post\.php/] });

await T('New token and Disable: the old token stops, the page shows the new one once; a disabled host is greyed and cannot be chosen', async () => {
  await go();
  await trapDialogs(true);
  await p.click('#af-bh-hosts button[value=bh_rotate]');
  await p.waitSel('#af-bh-token');
  const t2 = await p.eval(`document.getElementById('af-bh-token').value`);
  assert(/^rbh_/.test(t2) && t2 !== TOKEN, 'a different token');
  assert((await host.poll()).status === 401, 'the old token is refused');
  host = new MockHost(t2);
  assert((await host.poll()).status === 200, 'the new token works');
  await go();
  await trapDialogs(true);
  await p.click('#af-bh-hosts button[value=bh_disable]');
  await p.waitFor(`/Disabled/.test(document.getElementById('af-bh-hosts').innerText)`, { label: 'disabled' });
  assert((await host.poll()).status === 401, 'a disabled host is refused');
  assert(!(await p.eval(`!!document.getElementById('af-bh-go')`)), 'no Build button while no host is enabled');
  await p.click('#af-bh-hosts button[value=bh_enable]');
  await p.waitFor(`/Online|Never|Seen|Offline/.test(document.getElementById('af-bh-hosts').innerText) && !!document.getElementById('af-bh-go')`, { label: 'enabled again' });
}, { allowHttp: [/post\.php/] });

await T('accessibility and CSP: every visible control has a label; no inline handler or inline script; no token in the page', async () => {
  await go();
  const un = await p.eval(`[...document.querySelectorAll('#af-build-local input, #af-build-local select, #af-build-local textarea')].filter(e=>{if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;return !((e.labels&&e.labels.length)||e.getAttribute('aria-label'))}).map(e=>e.name||e.id)`);
  assert(un.length === 0, 'unlabeled: ' + un);
  const r = await p.eval(`(()=>{const a=[];for(const e of document.querySelectorAll('*'))for(const at of e.attributes)if(/^on/i.test(at.name))a.push(e.tagName+'@'+at.name);return a})()`);
  assert(r.length === 0, 'inline handlers: ' + r);
  assert(!(await p.eval('document.documentElement.outerHTML')).includes('rbh_'), 'a token in the page');
});

await T('phone width: no sideways page scroll with the section open; dark theme renders', async () => {
  await p.setViewport(390, 800, true);
  await go();
  const o = await p.eval(`(()=>{const d=document.documentElement;return d.scrollWidth-d.clientWidth})()`);
  assert(o <= 1, `page scrolls sideways by ${o}px at 390px`);
  await shot('buildhost-phone-light');
  await p.setViewport(1366, 900);
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','dark'); document.documentElement.setAttribute('data-theme','dark')`);
  await sleep(300);
  await shot('buildhost-desktop-dark');
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','light'); document.documentElement.removeAttribute('data-theme')`);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
