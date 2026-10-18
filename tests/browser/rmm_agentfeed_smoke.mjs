// Browser smoke for the Agent updates card (Administration > Endpoint agent) and the Add device / Build installer page (RivetIT 26.10.52).
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=<file written by tests/browser/rmm_seed.php> MOCK_GH_DIR=<dir> node tests/browser/rmm_agentfeed_smoke.mjs
//
// The install's web server must be started with RIVET_AGENT_FEED_TEST_ORIGIN=http://127.0.0.1:<mock port> (the scratch config.php defines EA_ALLOW_INSECURE_HTTP, which is what allows it), and a
// mock GitHub must be serving MOCK_GH_DIR: `MOCK_GH_DIR=<dir> php -S 127.0.0.1:<mock port> tests/support/mock_github.php`. Nothing touches the network.
//
// Same driver, harness and safety rules as rmm_rc21_software_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, and every check also fails on any uncaught
// error, console.error, failed first-party request or HTTP 4xx/5xx while it ran. Everything is done the way a person does it: the Check button, the Publish button with its options, the
// confirmation dialog, the progress list; then the Add device page: the .exe and .msi downloads (the bytes are checked), the silent-install commands, the Linux command and the packages.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
const MOCK = process.env.MOCK_GH_DIR;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON || !MOCK) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD, SEED_JSON (the file written by tests/browser/rmm_seed.php) and MOCK_GH_DIR; optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke publishes agent files; it is meant for a throwaway install.`);
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
const trapDialogs = (answer = true) => p.eval(`window.__dialogs = []; window.confirm = function (t) { window.__dialogs.push(String(t)); return ${answer ? 'true' : 'false'}; }; window.alert = window.prompt = function () { return true; }; true`);
const setVal = (sel, v) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)throw new Error('no ${sel}');e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return true})()`);
const unlabeled = (scope) => p.eval(`[...document.querySelectorAll(${JSON.stringify(scope)} + ' input, ' + ${JSON.stringify(scope)} + ' select, ' + ${JSON.stringify(scope)} + ' textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0)&&e.type!=='radio')return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const flash = async (re, label) => {
  try {
    await p.waitFor(`/${re.source}/i.test(document.body.innerText)`, { label: label || String(re) });
  } catch (e) {
    const alerts = await p.eval(`[...document.querySelectorAll('.alert, .toast, [role=alert]')].map(a=>a.innerText.trim()).filter(Boolean).join(' | ')`);
    throw new Error(`${e.message}; alerts on the page: ${alerts.slice(0, 400)}; url ${await p.url()}`);
  }
};
const extra = (mode, ...more) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_agentfeed_extra.php', process.env.SEED_JSON, mode, ...more], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
const login = async (email, password) => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page for ' + email);
};
const cardText = () => p.eval(`document.getElementById('agent-updates').innerText`);
const row = (v) => `#af-table tr[data-tag="agent-v${v}"]`;
const openPublish = async (v) => {
  await p.waitSel(`${row(v)} button[data-bs-toggle=collapse]`);
  await p.click(`${row(v)} button[data-bs-toggle=collapse]`);
  await p.waitSel(`form[data-tag="agent-v${v}"] [data-af-go]`);
  await sleep(350);
};
const waitDone = (re, label) => p.waitFor(`(()=>{const a=document.getElementById('af-alert');return !!a && /${re.source}/i.test(a.innerText)})()`, { timeout: 90000, label });

await T('login: real form signs in', async () => {
  await login(EMAIL, PASSWORD);
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('setup: the mock GitHub has agent-v0.9.2 and agent-v0.9.1 (plus a core release that must be ignored)', async () => {
  const r = extra('fixtures', MOCK);
  assert(r.ok, 'fixtures: ' + JSON.stringify(r));
  assert(extra('clear').ok, 'clear');
});

// ------------------------------------------------------------------------------------------------ the card
await T('Agent updates: before the first check the card says so and contacts nobody', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#agent-updates');
  const t = await cardText();
  assert(/Not checked yet/.test(t) && /Press\s+Check for updates\s+to read/.test(t) && !/0\.9\.2/.test(t), 'first look: ' + t.slice(-500));
  assert((await unlabeled('#agent-updates')).length === 0, 'unlabeled controls in the card: ' + (await unlabeled('#agent-updates')).join(','));
  await shot('agent-updates-first-look-desktop-light');
});

await T('Agent updates: Check for updates lists the agent releases, newest first, with pre-release, files, state and a new-version badge', async () => {
  await trapDialogs();
  await p.click('#af-check');
  await p.waitSel(row('0.9.2'));
  const t = await cardText();
  assert(/0\.9\.2/.test(t) && /0\.9\.1/.test(t) && !/rc\.21/.test(t) && !/v1\.0\.0/.test(t), 'releases: ' + t.slice(0, 400));
  assert(/Pre-release/.test(t) && /Not published/.test(t) && /New version available: 0\.9\.2/.test(t), 'flags: ' + t.slice(0, 500));
  const order = await p.eval(`[...document.querySelectorAll('#af-table tr[data-tag]')].map(r=>r.dataset.tag)`);
  assert(JSON.stringify(order) === JSON.stringify(['agent-v0.9.2', 'agent-v0.9.1']), 'order ' + JSON.stringify(order));
  assert(/Windows x64 agent/.test(t) && /Windows x64 MSI/.test(t) && /Linux x64 \.deb/.test(t) && /install-linux\.sh/.test(t), 'file chips missing: ' + t.slice(0, 800));
  assert(/Last checked/.test(t) && !/Not checked yet/.test(t), 'last checked time');
  assert(extra('state').audit_check === 1, 'the check was not audited');
  await shot('agent-updates-listed-desktop-light');
});

await T('Agent updates: a different repository needs the trust box; the default stays', async () => {
  await go('/admin/settings_endpoint_agent.php#agent-updates');
  await p.waitSel('#af-repo-in');
  await p.eval(`document.getElementById('af-repo').open = true`);
  await p.type('#af-repo-in', 'someone/evil-fork');
  await p.clickNav('#af-repo button[name=agent_feed]');
  await flash(/Tick .I trust this repository/, 'trust refusal');
  await go('/admin/settings_endpoint_agent.php');
  assert(/TheTractorHacker\/rivet-core/.test(await cardText()) && !/someone\/evil-fork/.test(await cardText()), 'the repository changed without confirmation');
});

await T('Agent updates: cancelling the confirmation dialog downloads and stores nothing', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel(row('0.9.1'));
  await trapDialogs(false);
  await openPublish('0.9.1');
  await p.click('form[data-tag="agent-v0.9.1"] [data-af-go]');
  await sleep(600);
  const st = extra('state');
  assert(st.binaries.length === 0 && st.msi === 0 && st.linux === 0 && st.releases.length === 0, 'something was stored after Cancel: ' + JSON.stringify(st));
  const d = await p.eval('window.__dialogs');
  assert(d.length === 1 && /Publish agent-v0\.9\.1\?/.test(d[0]) && /checked against the release checksums/.test(d[0]), 'the dialog text: ' + JSON.stringify(d));
});

await T('Agent updates: Publish 0.9.1 to the pilot ring at 40% shows the progress and stores, verifies and records every file', async () => {
  await trapDialogs(true);
  const f = 'form[data-tag="agent-v0.9.1"]';
  // no pilot device yet: stable is preselected and the page says why
  assert((await p.eval(`document.querySelector('${f} [name=ring]').value`)) === 'stable' && /no pilot devices yet/.test(await p.eval(`document.querySelector('${f}').innerText`)), 'ring default');
  assert((await p.eval(`document.querySelector('${f} [name=rollout_pct]').value`)) === '100' && (await p.eval(`document.querySelector('${f} [name=activate]').checked && document.querySelector('${f} [name=offer]').checked`)), 'defaults: 100%, make current, offer');
  await p.eval(`(()=>{const r=document.querySelector('${f} [name=ring]');r.value='pilot';r.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await setVal(`${f} [name=rollout_pct]`, '40');
  assert((await p.eval(`document.querySelector('${f} [data-af-pct-out]').textContent`)) === '40', 'the slider value is not shown');
  await shot('agent-updates-publish-options-desktop-light');
  await p.click(`${f} [data-af-go]`);
  const d = await p.eval('window.__dialogs');
  assert(d.length === 1 && /pilot ring at 40%/.test(d[0]), 'the dialog should name the ring and the percentage: ' + JSON.stringify(d));
  await p.waitFor(`document.querySelectorAll('${f} [data-af-steps] li').length >= 1`, { timeout: 20000, label: 'progress list' });
  await waitDone(/Published 0\.9\.1: all 12 files are in place/, 'publish finished');
  const items = await p.eval(`[...document.querySelectorAll('#af-alert [data-af-result] li')].map(l=>l.innerText)`);
  assert(items.length === 12 && items.every((t) => !/not match|refused|failed/i.test(t)), 'the per-file results: ' + JSON.stringify(items));
  assert(items.some((t) => /rivetit-agent-windows-amd64\.exe verified/.test(t)) && items.some((t) => /rivetit-agent-windows-amd64\.msi verified/.test(t)) && items.some((t) => /install-linux\.sh recorded and verified/.test(t)), 'result lines: ' + JSON.stringify(items));
  const st = extra('state');
  assert(st.binaries.length === 2 && st.binaries.every((b) => b.version === '0.9.1' && Number(b.is_current) === 1) && st.msi === 1 && st.linux === 9, 'stored: ' + JSON.stringify(st));
  assert(st.releases.length === 2 && st.releases.every((r) => r.ring === 'pilot' && Number(r.rollout_pct) === 40 && r.version === '0.9.1'), 'offered to pilot at 40%: ' + JSON.stringify(st.releases));
  assert(st.audit_published === 3, 'audit rows for the three stored Windows files: ' + st.audit_published);
  const t = await cardText();
  assert(/Published/.test(await p.eval(`document.querySelector('${row('0.9.1')}').innerText`)) && /pilot 40%/.test(t) && /Linux files: 9/.test(t), 'the row after the publish: ' + t.slice(0, 600));
  await shot('agent-updates-published-desktop-light');
});

await T('the other cards show what the publish stored (Agent binaries, MSI template, Agent updates and rings)', async () => {
  await go('/admin/settings_endpoint_agent.php');
  const t = await p.eval(`document.getElementById('binaries').innerText + '\\n' + document.getElementById('msi-templates').innerText + '\\n' + document.getElementById('releases').innerText`);
  const fl = { v091: /0\.9\.1/.test(t), current: /Current/.test(t), pilot: /pilot/.test(t), pct: (await p.eval(`[...document.querySelectorAll('#releases input[name=rollout_pct]')].map(i=>i.value)`)).includes('40') };
  assert(Object.values(fl).every(Boolean), 'cards: ' + JSON.stringify(fl) + ' ' + t.replace(/\s+/g, ' ').slice(-900));
});

await T('Agent updates: a download that does not match its checksum is refused, shown in red, audited, and nothing of it is stored', async () => {
  await go('/admin/settings_endpoint_agent.php');
  extra('scenario', MOCK, JSON.stringify({ asset_mode: 'tamper', tamper: 'rivetit-agent-windows-amd64.exe' }));
  await p.waitSel(row('0.9.2'));
  await trapDialogs(true);
  await openPublish('0.9.2');
  const f = 'form[data-tag="agent-v0.9.2"]';
  await p.eval(`(()=>{const o=document.querySelector('${f} [name=offer]');o.checked=false;o.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.click(`${f} [data-af-go]`);
  await waitDone(/files of 0\.9\.2 were not stored/, 'finished with a failure');
  const items = await p.eval(`[...document.querySelectorAll('#af-alert [data-af-result] li')].map(l=>l.innerText)`);
  assert(items.filter((x) => /does not match/.test(x)).length >= 1, 'the failing file should be named: ' + JSON.stringify(items));
  const st = extra('state');
  assert(!st.binaries.some((b) => b.version === '0.9.2' && b.arch === 'amd64'), 'the tampered file was stored: ' + JSON.stringify(st.binaries));
  assert(st.binaries.some((b) => b.version === '0.9.2' && b.arch === 'arm64'), 'the other files of the release should still be stored: ' + JSON.stringify(st.binaries));
  assert(st.audit_verify >= 1, 'the verification failure was not audited');
  assert(st.releases.every((r) => r.version !== '0.9.2'), 'not offered although "offer" was unticked: ' + JSON.stringify(st.releases));
  extra('scenario', MOCK, '{}');
  await shot('agent-updates-tampered-desktop-light');
}, { allowHttp: [/\/admin\/post\.php/] });

await T('Agent updates: GitHub\'s rate limit is explained in words and the saved list stays', async () => {
  extra('age');
  extra('scenario', MOCK, JSON.stringify({ api_status: 403, api_headers: { 'X-RateLimit-Remaining': '0', 'X-RateLimit-Reset': String(Math.floor(Date.now() / 1000) + 1800) } }));
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#af-check');
  await p.click('#af-check');
  await waitDone(/rate limit/, 'rate limit message');
  const t = await cardText();
  assert(/0\.9\.2/.test(t) && /0\.9\.1/.test(t), 'the saved list disappeared: ' + t.slice(0, 300));
  extra('age');
  extra('scenario', MOCK, '{}');
});

await T('Agent updates: offline (GitHub unreachable) keeps the saved list', async () => {
  // the mock answers 500 with a non-JSON body: the same code path as an unreachable host for the page
  extra('age');
  extra('scenario', MOCK, JSON.stringify({ api_status: 500, api_raw: 'oops' }));
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#af-check');
  await p.click('#af-check');
  await waitDone(/GitHub answered HTTP 500/, 'error message');
  assert(/0\.9\.2/.test(await cardText()), 'the saved list disappeared');
  extra('age');
  extra('scenario', MOCK, '{}');
});

await T('Agent updates: with a pilot device the Pilot ring is preselected', async () => {
  extra('pilot');
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel(row('0.9.2'));
  await openPublish('0.9.2');
  assert((await p.eval(`document.querySelector('form[data-tag="agent-v0.9.2"] [name=ring]').value`)) === 'pilot', 'pilot should be preselected');
});

await T('Agent updates: phone width, no horizontal scroll', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await go('/admin/settings_endpoint_agent.php#agent-updates');
  await p.waitSel('#agent-updates');
  await noOverflow('agent updates card');
  await shot('agent-updates-mobile-light');
  await p.setViewport(1366, 900);
});

// ------------------------------------------------------------------------------------------------ Add device / Build installer
const pickDept = () => p.eval(`(()=>{const s=document.getElementById('ad-client');const o=[...s.options].find(x=>x.value);s.value=o.value;s.dispatchEvent(new Event('change',{bubbles:true}));return o.value})()`);
const hookBlobs = () => p.eval(`window.__blobs = []; const o = URL.createObjectURL; URL.createObjectURL = function (b) { window.__blobs.push(b); return o.call(URL, b); }; true`);
const blobInfo = (i) => p.eval(`(async()=>{const b=window.__blobs[${i}];const h=new Uint8Array(await b.slice(0,4).arrayBuffer());return {size:b.size,head:[...h],headText:await b.slice(0,2).text(),tail:await b.slice(b.size-16).text()}})()`);

await T('Add device page: the menu entry opens it; the safe defaults; nothing is created until a button is pressed', async () => {
  await go('/agent/rmm_fleet.php');
  assert(await p.exists('a[href="/agent/rmm_add_device.php"]') && await p.exists('a[href="/agent/rmm_fleet.php?installer=1"]'), 'menu entries');
  const tok0 = extra('state').tokens;
  await go('/agent/rmm_add_device.php');
  await p.waitSel('#ad-root');
  const d = await p.eval(`(()=>{const $=(i)=>document.getElementById(i);return {x64:$('ad-p-wx64').checked,ring:$('ad-ring').value,uses:$('ad-uses').value,ttl:$('ad-ttl').value,adv:$('ad-adv').open,
    win:!$('ad-windows').classList.contains('d-none'),lin:$('ad-linux').classList.contains('d-none'),exeDis:$('ad-dl-exe').disabled,msiDis:$('ad-dl-msi').disabled}})()`);
  assert(d.x64 && d.ring === 'stable' && d.uses === '1' && d.ttl === '24' && !d.adv && d.win && d.lin && !d.exeDis && !d.msiDis, 'defaults: ' + JSON.stringify(d));
  assert(extra('state').tokens === tok0, 'a token was created by opening the page');
  assert((await unlabeled('#ad-root')).length === 0, 'unlabeled controls: ' + (await unlabeled('#ad-root')).join(','));
  await shot('add-device-desktop-light');
});

await T('Add device page: no department chosen says so and downloads nothing', async () => {
  await hookBlobs();
  await p.click('#ad-dl-exe');
  await p.waitSel('#ad-error');
  assert(/Choose the department/.test(await p.eval(`document.getElementById('ad-error').innerText`)) && (await p.eval('window.__blobs.length')) === 0, 'no message / something downloaded');
});

await T('Add device page: the .exe installer downloads, is a stamped PE, and tells what to do next', async () => {
  await pickDept();
  await p.click('#ad-dl-exe');
  await p.waitFor(`window.__blobs.length === 1`, { timeout: 30000, label: 'exe download' });
  const b = await blobInfo(0);
  assert(b.headText === 'MZ' && b.tail === 'RIVETIT-EMBED-v1' && b.size > 50000, 'not a stamped PE: ' + JSON.stringify(b));
  assert(/Downloaded RivetIT-Agent-Setup-.*-x64\.exe/.test(await p.eval(`document.getElementById('ad-done').innerText`)), 'next-step text');
});

await T('Add device page: the .msi installer downloads as a stamped OLE file', async () => {
  await p.click('#ad-dl-msi');
  await p.waitFor(`window.__blobs.length === 2`, { timeout: 30000, label: 'msi download' });
  const b = await blobInfo(1);
  assert(b.head[0] === 0xD0 && b.head[1] === 0xCF && b.tail === 'RIVETIT-EMBED-v1', 'not a stamped MSI: ' + JSON.stringify(b));
  assert(/Downloaded RivetIT-Agent-.*-x64\.msi/.test(await p.eval(`document.getElementById('ad-done').innerText`)) && /unsigned/.test(await p.eval(`document.getElementById('ad-done').innerText`)), 'msi text');
});

await T('Add device page: the silent-install commands (PowerShell and MSI) appear with copy buttons', async () => {
  await p.click('#ad-w-cmds');
  await p.waitFor(`!document.getElementById('ad-w-out').classList.contains('d-none')`, { timeout: 20000, label: 'commands' });
  const v = await p.eval(`(()=>{const $=(i)=>document.getElementById(i).value;return {ps:$('ad-c-ps'),msiexec:$('ad-c-msiexec'),msips:$('ad-c-msips'),props:$('ad-c-props'),un:$('ad-c-uninst'),msiVisible:!document.getElementById('ad-w-msi-out').classList.contains('d-none'),copies:document.querySelectorAll('#ad-w-out [data-ad-copy]').length}})()`);
  assert(/agent_installer/.test(v.ps) && /rvte1\./.test(v.ps) && v.msiVisible && /msiexec \/i "RivetIT-Agent-.*-x64\.msi" \/qn/.test(v.msiexec) && /format = 'msi'/.test(v.msips) && /ENROLL_TOKEN=/.test(v.props) && /RivetIT Agent/.test(v.un) && v.copies === 5, 'commands: ' + JSON.stringify(v).slice(0, 500));
  await p.click('#ad-w-out [data-ad-copy="ad-c-ps"]');
  await sleep(300);
  await shot('add-device-windows-commands-desktop-light');
});

await T('Add device page: ARM64 has no MSI template and the page says so; x64 comes back', async () => {
  await p.click('label[for=ad-p-warm]');
  const d = await p.eval(`(()=>{const $=(i)=>document.getElementById(i);return {msiDis:$('ad-dl-msi').disabled,msg:!$('ad-msi-missing').classList.contains('d-none'),exeDis:$('ad-dl-exe').disabled}})()`);
  assert(d.msiDis && d.msg && !d.exeDis, 'arm64 state: ' + JSON.stringify(d));
  await p.click('label[for=ad-p-wx64]');
  assert(!(await p.eval(`document.getElementById('ad-dl-msi').disabled`)), 'x64 should offer the MSI again');
});

await T('Add device page: the Linux command is one self-contained line set with hashes, and the packages are linked', async () => {
  await p.click('label[for=ad-p-lx64]');
  await p.waitFor(`!document.getElementById('ad-linux').classList.contains('d-none')`, { label: 'linux section' });
  await p.click('#ad-l-go');
  await p.waitFor(`!document.getElementById('ad-l-out').classList.contains('d-none')`, { timeout: 20000, label: 'linux command' });
  const c = await p.eval(`document.getElementById('ad-l-cmd').value`);
  assert(/sha256sum -c/.test(c) && /bash "\$S" --binary "\$B" --server/.test(c) && /rvte1\./.test(c) && !/unpacked/.test(c), 'command: ' + c.slice(0, 400));
  const pk = await p.eval(`(()=>{const v=document.querySelector('[data-ad-pkgs=amd64]');return {shown:!v.classList.contains('d-none'),text:v.innerText,links:[...v.querySelectorAll('a')].map(a=>a.href),arm:document.querySelector('[data-ad-pkgs=arm64]').classList.contains('d-none')}})()`);
  const pf = { shown: pk.shown, arm: pk.arm, deb: /\.deb/.test(pk.text), rpm: /\.rpm/.test(pk.text), apt: /sudo apt install \.\/rivetit-agent_0\.9\.[12]_amd64\.deb/.test(pk.text), links: pk.links.length >= 4, hrefs: pk.links.every((h) => /\/releases\/download\/agent-v0\.9\.[12]\//.test(h)) };
  assert(Object.values(pf).every(Boolean), 'packages: ' + JSON.stringify(pf) + ' ' + JSON.stringify(pk.links));
  await shot('add-device-linux-desktop-light');
  await p.click('label[for=ad-p-larm]');
  assert(await p.eval(`document.querySelector('[data-ad-pkgs=amd64]').classList.contains('d-none') && !document.querySelector('[data-ad-pkgs=arm64]').classList.contains('d-none')`), 'the ARM64 package block should replace the x64 one');
});

await T('Add device page: phone width, no horizontal scroll', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await go('/agent/rmm_add_device.php');
  await p.waitSel('#ad-root');
  await noOverflow('add device (windows)');
  await p.click('label[for=ad-p-lx64]');
  await noOverflow('add device (linux)');
  await shot('add-device-mobile-light');
  await p.setViewport(1366, 900);
});

await T('the old Add device dialog on the fleet page still works and links to the new page', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('[data-rmm-installer-open]');
  await p.click('[data-rmm-installer-open]');
  await p.waitSel('#rmmInstallerModal.show');
  await sleep(400);
  assert(await p.eval(`!!document.querySelector('#rmmInstallerModal a[href="/agent/rmm_add_device.php"]')`), 'no link to the full page');
  await p.click('#rmm-inst-tab-linux');
  const dept = await p.eval(`(()=>{const s=document.getElementById('rmm-inst-client');const o=[...s.options].find(x=>x.value);s.value=o.value;s.dispatchEvent(new Event('change',{bubbles:true}));return o.value})()`);
  await p.click('#rmm-inst-go');
  await p.waitFor(`!document.getElementById('rmm-inst-linux-out').classList.contains('d-none')`, { timeout: 20000, label: 'dialog linux command' });
  const t = await p.eval(`({cmd:document.getElementById('rmm-inst-linux-cmd').textContent,self:!document.getElementById('rmm-inst-linux-self').classList.contains('d-none'),old:document.getElementById('rmm-inst-linux-intro').classList.contains('d-none')})`);
  assert(dept && t.self && t.old && /sha256sum -c/.test(t.cmd), 'dialog Linux answer: ' + JSON.stringify(t).slice(0, 300));
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
