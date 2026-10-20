// Browser smoke for RivetCore rc.16 in a real Chrome: pushing the MeshCentral agent to enrolled devices (Install / Remove MeshCentral agent on the device page, the Remote tab and
// the legacy device page, with the confirm dialog, the idempotent states and the job result; the bulk dialog on Agent Fleet) and the MSI installer (the Format selector of the
// Add device dialog and the download, the MSI template card, the Deployment card and the deployment commands). Desktop and 390 px, light and dark.
//
//   BASE_URL=http://127.0.0.1:8861 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=<rmm_seed.php output> [RC16_REAL_MSI=<dist/rivetit-agent-windows-amd64.msi>] node tests/browser/rmm_rc16_smoke.mjs
// (after tests/browser/rmm_seed.php on a throwaway install, with RMM_SMOKE_BASE_URL set to the same address, as for the other smokes)
//
// Same driver, harness and safety rules as rmm_rc13_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, every check also fails on any uncaught error,
// console.error, failed first-party request or HTTP 4xx/5xx while it ran (the few refusals that are the point of a check say so). php -S runs with PHP's default 2M upload limit, so the
// real 3.8 MB template (RC16_REAL_MSI, optional) is REFUSED by the page with the limit in words, and the small wixl fixture is uploaded for the success path.
import { mkdirSync, writeFileSync, statSync, existsSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1, RC16_REAL_MSI.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke queues Mesh jobs and publishes MSI templates; it is meant for a throwaway install.`);
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });
const php = (args) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_rc16_extra.php', ...args], { cwd: process.cwd(), env: { ...process.env }, encoding: 'utf8' }).split('\n').find((l) => l.startsWith('{') || l.startsWith('[')));
const FIXTURE = resolve('vendor/rivet/rivet-core/tests/Fixtures/rmm/msi/template-x64.msi');
const REAL = process.env.RC16_REAL_MSI || '';

const fixtures = php(['setup']);   // Mesh on with a random placeholder group id, remote tools on, a technician, PUSH1 PUSH2 PUSHI PUSHL
const ASSET = fixtures.assets, DEV = fixtures.dev;

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-rc16', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); await sleep(500); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const ADMIN = '/admin/settings_endpoint_agent.php';
const FLEET = '/agent/rmm_fleet.php';
const assetPage = (n, hash = '#rmm-overview') => `/agent/asset_details.php?asset_id=${ASSET[n]}${hash}`;

const click = async (sel) => {
  let a = await p.centre(sel);
  for (let i = 0; i < 8; i++) {
    await sleep(200);
    const b = await p.centre(sel);
    const same = Math.abs(a.x - b.x) < 1 && Math.abs(a.y - b.y) < 1;
    a = b;
    if (same) break;
  }
  await p.clickAt(a.x, a.y);
};
const nav = async (sel, timeout = 90000) => {
  const origin = await p.eval('performance.timeOrigin');
  await click(sel);
  const end = Date.now() + timeout;
  for (;;) {
    await sleep(200);
    try { if (await p.call((a0)=>(performance.timeOrigin !== a0 && document.readyState === 'complete'), origin)) break; } catch { /* the execution context is gone mid-navigation */ }
    if (Date.now() > end) throw new Error('page did not navigate after clicking ' + sel);
  }
  await sleep(600);
};
async function shotEl(sel, name) {
  const r = await p.call((a0)=>{const e=document.querySelector(a0);if(!e)return null;const b=e.getBoundingClientRect();return {x:b.x+scrollX,y:b.y+scrollY,w:b.width,h:b.height}}, sel);
  assert(r, 'no element ' + sel);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: Math.max(0, r.x), y: Math.max(0, r.y), width: Math.ceil(r.w), height: Math.min(Math.ceil(r.h), 6000), scale: 1 } });
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
  await nav('button[name=edit_your_user_preferences]');
};
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
const checked = (sel) => p.call((a0)=>(!!document.querySelector(a0).checked), sel);
const text = (sel) => p.call((a0)=>{const e=document.querySelector(a0);return e?e.innerText.replace(/\s+/g,' ').trim():''}, sel);
const unlabeled = (scope) => p.call((a0)=>([...document.querySelectorAll(a0 + ' input, ' + a0 + ' select, ' + a0 + ' textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)), scope);
const setFile = async (sel, path) => {
  await p.send('DOM.enable');
  const { root } = await p.send('DOM.getDocument', { depth: 0 });
  const { nodeId } = await p.send('DOM.querySelector', { nodeId: root.nodeId, selector: sel });
  assert(nodeId, 'no file input ' + sel);
  await p.send('DOM.setFileInputFiles', { files: [path], nodeId });
};
const jobs = () => php(['jobs']);
const dialogOpen = async (sel) => { await p.waitSel(sel + '.show', { timeout: 8000 }); await sleep(450); };
const dialogClosed = async (sel) => { await p.waitFor((a0)=>(!document.querySelector(a0).classList.contains('show')), { args: [sel], timeout: 8000 }); await sleep(350); };
const pushStatus = (scope) => text(`${scope} [data-mesh-push-status]`);
const waitStatus = (scope, re, timeout = 20000) => p.waitFor((a0,a1)=>(new RegExp(a0,'i').test((document.querySelector(a1)||{innerText:''}).innerText)), { args: [re, scope + ' [data-mesh-push-status]'], timeout });

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ================================================================================================ 1. one device
await T('device page: Install MeshCentral agent is on the Remote access card; nothing to remove yet; the group id is nowhere on the page', async () => {
  await go(assetPage('PUSH1'));
  await p.waitSel('#rmm-remote [data-mesh-push-action="install"]');
  assert(!(await p.exists('#rmm-remote [data-mesh-push-action="uninstall"]')), 'a Remove button although no Mesh agent is installed');
  assert(await p.eval(`!document.querySelector('#rmm-remote [data-mesh-push-action="install"]').disabled`), 'the install button is disabled');
  assert(/Sends a signed job/.test(await text('#rmm-remote [data-mesh-push] [data-mesh-push-hint]')), 'no explanation under the button');
  assert(!(await bodyText()).match(/[0-9a-f]{96}/), 'a 96-character hex id is on the page');
  await shotEl('#rmm-remote', 'device-push-desktop-light');
});
await T('install: the confirm dialog opens and names the device; Escape cancels, focus returns to the button, nothing is queued', async () => {
  await click('#rmm-remote [data-mesh-push-action="install"]');
  await dialogOpen('#rmmMeshPushConfirm');
  assert(/Install the MeshCentral agent on PUSH1\?/.test(await text('#rmmMeshPushText')), 'dialog text: ' + await text('#rmmMeshPushText'));
  assert(!(await p.visible('#rmmMeshPushForceWrap')) && !(await p.visible('#rmmMeshPushCheckWrap')), 'force/confirm boxes shown for a first install');
  assert((await unlabeled('#rmmMeshPushConfirm')).length === 0, 'unlabeled controls in the dialog: ' + (await unlabeled('#rmmMeshPushConfirm')).join(','));
  await shotEl('#rmmMeshPushConfirm .modal-content', 'dialog-push-install-desktop-light');
  await p.press('Escape');
  await dialogClosed('#rmmMeshPushConfirm');
  await sleep(600);
  assert((await p.eval(`document.activeElement && document.activeElement.getAttribute('data-mesh-push-action')`)) === 'install', 'focus did not return to the Install button: ' + await p.eval(`document.activeElement ? document.activeElement.outerHTML.slice(0, 200) : 'none'`));
  assert(JSON.stringify(jobs()) === '{}', 'a job was queued by a cancelled dialog: ' + JSON.stringify(jobs()));
});
await T('install: confirmed, it is queued once; a second click says it is already queued and queues nothing', async () => {
  await click('#rmm-remote [data-mesh-push-action="install"]');
  await dialogOpen('#rmmMeshPushConfirm');
  await click('#rmmMeshPushGo');
  await dialogClosed('#rmmMeshPushConfirm');
  await waitStatus('#rmm-remote', 'Queued|waiting for the device');
  const j = jobs();
  assert(j.PUSH1 && j.PUSH1['mesh_install:queued'] === 1, 'jobs: ' + JSON.stringify(j));
  await click('#rmm-remote [data-mesh-push-action="install"]');
  await dialogOpen('#rmmMeshPushConfirm');
  await click('#rmmMeshPushGo');
  await dialogClosed('#rmmMeshPushConfirm');
  await waitStatus('#rmm-remote', 'already queued');
  assert(jobs().PUSH1['mesh_install:queued'] === 1, 'a second job was queued');
  await shotEl('#rmm-remote', 'device-push-queued-desktop-light');
});
await T('install: the device reports a failure; the page shows it (escaped), without reloading', async () => {
  php(['finish', 'PUSH1', 'failed', JSON.stringify({ v: 1, ok: false, detail: 'install_failed', installed: false, error: 'pin mismatch <b id="xss">bold</b>', exit_code: 3, log: 'last line <i>x</i>' })]);
  await p.waitFor(`!!document.querySelector('#rmm-remote .rmm-mesh-result[data-state="failed"]')`, { timeout: 25000 });
  const t = await pushStatus('#rmm-remote');
  assert(/Failed/.test(t) && /pin mismatch <b id="xss">bold<\/b>/.test(t) && /Installer exit code 3/.test(t), 'result text: ' + t);
  assert(!(await p.exists('#xss')) && !(await p.exists('#rmm-remote [data-mesh-push-status] i')), 'device text was rendered as markup');
  await shotEl('#rmm-remote', 'device-push-failed-desktop-light');
});
await T('job history: the install is labelled and "View result" opens and closes it', async () => {
  await go(assetPage('PUSH1', '#rmm-jobs'));
  await p.eval(`document.getElementById('rmm-tab-jobs').click()`);
  await p.waitSel('#rmm-jobs-card');
  assert(/Install MeshCentral agent/.test(await text('#rmm-jobs-card')), 'job label missing: ' + await text('#rmm-jobs-card'));
  await click('#rmm-jobs-card [data-mesh-push-result]');
  await p.waitFor(`/Failed/.test(document.querySelector('#rmm-jobs-card [data-mesh-push-result-body]').innerText)`, { timeout: 8000 });
  assert((await p.eval(`document.querySelector('#rmm-jobs-card [data-mesh-push-result]').getAttribute('aria-expanded')`)) === 'true', 'aria-expanded');
  assert(/pin mismatch/.test(await text('#rmm-jobs-card [data-mesh-push-result-body]')), 'result body: ' + await text('#rmm-jobs-card [data-mesh-push-result-body]'));
  await shotEl('#rmm-jobs-card', 'device-push-history-desktop-light');
  await click('#rmm-jobs-card [data-mesh-push-result]');
  await sleep(300);
  assert((await p.eval(`document.querySelector('#rmm-jobs-card [data-mesh-push-result]').getAttribute('aria-expanded')`)) === 'false', 'did not close');
});
await T('Remote tab: the same buttons; after a failure a new install can be queued from here', async () => {
  await go(assetPage('PUSH1', '#rmm-remote'));
  await p.eval(`document.getElementById('rmm-tab-remote').click()`);
  await p.waitSel('#rmm-pane-remote [data-mesh-push-action="install"]');
  await click('#rmm-pane-remote [data-mesh-push-action="install"]');
  await dialogOpen('#rmmMeshPushConfirm');
  await click('#rmmMeshPushGo');
  await dialogClosed('#rmmMeshPushConfirm');
  await waitStatus('#rmm-pane-remote', 'Queued|waiting for the device');
  assert(jobs().PUSH1['mesh_install:queued'] === 1, 'jobs: ' + JSON.stringify(jobs().PUSH1));
  php(['finish', 'PUSH1', 'succeeded', JSON.stringify({ v: 1, ok: true, detail: 'installed', installed: true, node_id: 'node//' + 'Qq'.repeat(20) })]);
  await p.waitFor(`!!document.querySelector('#rmm-pane-remote .rmm-mesh-result[data-state="succeeded"]')`, { timeout: 25000 });
  assert(/The Mesh agent was installed\./.test(await pushStatus('#rmm-pane-remote')) && /Reload the page/.test(await pushStatus('#rmm-pane-remote')), 'status: ' + await pushStatus('#rmm-pane-remote'));
  await shotEl('#rmm-pane-remote [data-mesh-push]', 'remote-tab-push-desktop-light');
});
await T('an agent that did not announce the capability: the button is disabled and the reason is in words', async () => {
  await go(assetPage('PUSH2'));
  await p.waitSel('#rmm-remote [data-mesh-push-action="install"]');
  assert(await p.eval(`document.querySelector('#rmm-remote [data-mesh-push-action="install"]').disabled`), 'the button is enabled');
  assert(/too old to install the MeshCentral agent by push/.test(await text('#rmm-remote')) && /Update the agent first/.test(await text('#rmm-remote')), 'reason: ' + await text('#rmm-remote'));
});
await T('a device that reports the Mesh agent: Remove (explicit confirm) and Install-with-force; "already installed" is the answer without force', async () => {
  await go(assetPage('PUSHI'));
  await p.waitSel('#rmm-remote [data-mesh-push-action="uninstall"]');
  // install without force: the answer is "already installed", nothing queued
  await click('#rmm-remote [data-mesh-push-action="install"]');
  await dialogOpen('#rmmMeshPushConfirm');
  assert(await p.visible('#rmmMeshPushForceWrap'), 'no force box for an installed device');
  await click('#rmmMeshPushGo');
  await dialogClosed('#rmmMeshPushConfirm');
  await waitStatus('#rmm-remote', 'already installed');
  assert(JSON.stringify(jobs().PUSHI || {}) === '{}', 'a job was queued on an installed device: ' + JSON.stringify(jobs().PUSHI));
  // Remove: the confirm button stays disabled until the box is ticked
  await click('#rmm-remote [data-mesh-push-action="uninstall"]');
  await dialogOpen('#rmmMeshPushConfirm');
  assert(await p.eval(`document.getElementById('rmmMeshPushGo').disabled`), 'Remove is enabled before the box is ticked');
  assert(/Remove the MeshCentral agent from PUSHI/.test(await text('#rmmMeshPushText')) && /own uninstaller/.test(await text('#rmmMeshPushTarget')), 'texts: ' + await text('#rmmMeshPushText'));
  assert(/I understand and want to remove the MeshCentral agent from PUSHI/.test(await text('#rmmMeshPushCheckWrap')), 'check label');
  await shotEl('#rmmMeshPushConfirm .modal-content', 'dialog-push-remove-desktop-light');
  await click('label[for="rmmMeshPushCheck"]');
  await sleep(200);
  assert(await p.eval(`!document.getElementById('rmmMeshPushGo').disabled`), 'Remove stays disabled after ticking');
  await click('#rmmMeshPushGo');
  await dialogClosed('#rmmMeshPushConfirm');
  await waitStatus('#rmm-remote', 'Queued|waiting for the device');
  assert(jobs().PUSHI['mesh_uninstall:queued'] === 1, 'jobs: ' + JSON.stringify(jobs().PUSHI));
  php(['finish', 'PUSHI', 'succeeded', JSON.stringify({ v: 1, ok: true, detail: 'uninstalled', installed: false })]);
  await p.waitFor(`/The Mesh agent was removed\\./.test(document.querySelector('#rmm-remote [data-mesh-push-status]').innerText)`, { timeout: 25000 });
  php(['installed', 'PUSHI', '0']);
  await go(assetPage('PUSHI'));
  await p.waitSel('#rmm-remote [data-mesh-push-action="install"]');
  assert(!(await p.exists('#rmm-remote [data-mesh-push-action="uninstall"]')), 'Remove is still offered after the device reports it gone');
});
await T('legacy device page: the card with the same buttons', async () => {
  await go('/agent/rmm_agent_device.php?device_id=' + DEV.PUSHL);
  await p.waitSel('[data-mesh-push-action="install"]');
  await click('[data-mesh-push-action="install"]');
  await dialogOpen('#rmmMeshPushConfirm');
  await click('#rmmMeshPushGo');
  await dialogClosed('#rmmMeshPushConfirm');
  await waitStatus('body', 'Queued|waiting for the device');
  assert(jobs().PUSHL && jobs().PUSHL['mesh_install:queued'] === 1, 'jobs: ' + JSON.stringify(jobs().PUSHL));
  await go('/agent/rmm_agent_device.php?device_id=' + DEV.PUSHL);
  assert(await p.exists('[data-mesh-push-result]'), 'no result button in the job table');
  php(['finish', 'PUSHL', 'failed', JSON.stringify({ v: 1, ok: false, detail: 'install_failed', error: 'x' })]);
});

// ================================================================================================ 2. Agent Fleet: bulk
const openBulk = async () => { await go(FLEET + '?tag=rc16push'); await click('[data-mesh-bulk-open]'); await dialogOpen('#rmmMeshBulk'); };
await T('fleet: the bulk dialog preselects the filtered tag; the first call shows Core\'s count, the confirm box gates the install', async () => {
  php(['installed', 'PUSH1', '0']);
  php(['installed', 'PUSHI', '1']);   // PUSH1 has no Mesh agent (installs), PUSHI has one (skipped), PUSH2 is too old
  await openBulk();
  assert((await p.eval(`document.getElementById('rmm-mb-target').selectedOptions[0].textContent`)) === 'Tag: rc16push', 'preselected: ' + await p.eval(`document.getElementById('rmm-mb-target').selectedOptions[0].textContent`));
  assert((await unlabeled('#rmmMeshBulk')).length === 0, 'unlabeled controls: ' + (await unlabeled('#rmmMeshBulk')).join(','));
  await shotEl('#rmmMeshBulk .modal-content', 'dialog-bulk-desktop-light');
  await click('#rmm-mb-go');
  await p.waitFor(`!document.getElementById('rmm-mb-confirm').classList.contains('d-none')`, { timeout: 15000 });
  assert(/up to 3 devices/.test(await text('#rmm-mb-confirm-text')), 'count text: ' + await text('#rmm-mb-confirm-text'));
  assert(await p.eval(`document.getElementById('rmm-mb-go').disabled`) && /Install on 3 devices/.test(await text('#rmm-mb-go')), 'the install button is not gated by the box');
  assert(JSON.stringify(jobs().PUSH1 || {}) === '{}' || !jobs().PUSH1['mesh_install:queued'], 'the unconfirmed call queued something');
  await shotEl('#rmmMeshBulk .modal-content', 'dialog-bulk-confirm-desktop-light');
  await click('label[for="rmm-mb-check"]');
  await sleep(200);
  assert(await p.eval(`!document.getElementById('rmm-mb-go').disabled`), 'still disabled after ticking');
  await click('#rmm-mb-go');
  await p.waitFor(`/device\\(s\\) reached/.test(document.getElementById('rmm-mb-result').innerText)`, { timeout: 20000 });
  const r = await text('#rmm-mb-result');
  assert(/3 device\(s\) reached: 1 queued; 1 already have the Mesh agent; 1 run an agent too old/.test(r), 'result: ' + r);
  assert(jobs().PUSH1['mesh_install:queued'] === 1 && !(jobs().PUSH2 || {})['mesh_install:queued'], 'jobs: ' + JSON.stringify(jobs()));
  await shotEl('#rmmMeshBulk .modal-content', 'dialog-bulk-result-desktop-light');
}, { allowHttp: [/rmm_mesh_push\.php/] });   // the first, unconfirmed call is Core's 422 confirmation_required: that answer is the point
await T('fleet: repeating the bulk call is safe (the open install is counted, not duplicated); the approval rule is a refusal in Core\'s words', async () => {
  await click('#rmm-mb-go');
  await p.waitFor(`!document.getElementById('rmm-mb-confirm').classList.contains('d-none')`, { timeout: 15000 });
  await click('label[for="rmm-mb-check"]');
  await sleep(200);
  await click('#rmm-mb-go');
  await p.waitFor(`/already have an install queued/.test(document.getElementById('rmm-mb-result').innerText)`, { timeout: 20000 });
  assert(jobs().PUSH1['mesh_install:queued'] === 1, 'a duplicate was queued: ' + JSON.stringify(jobs().PUSH1));
  php(['limits', '{"approval_bulk_threshold":2}']);
  await click('#rmm-mb-go');
  await p.waitFor(`/approval threshold of 2/.test(document.getElementById('rmm-mb-result').innerText)`, { timeout: 15000 });
  assert(/install in batches/.test(await text('#rmm-mb-result')) && (await p.eval(`document.getElementById('rmm-mb-result').classList.contains('alert-danger')`)), 'refusal: ' + await text('#rmm-mb-result'));
  php(['limits', '{"approval_bulk_threshold":0}']);
  php(['finish', 'PUSH1', 'succeeded', '{"v":1,"ok":true,"detail":"installed"}']);
}, { allowHttp: [/rmm_mesh_push\.php/] });

// ================================================================================================ 3. Add device dialog: Format
const openDialog = async () => { await go(FLEET + '?installer=1'); await p.waitSel('#rmmInstallerModal.show', { timeout: 8000 }); await sleep(500); };
const captureBlob = () => p.eval(`window.__blobHead = null; window.__blobSize = 0; (function () { var o = URL.createObjectURL; URL.createObjectURL = function (b) { b.slice(0, 8).arrayBuffer().then(function (a) { window.__blobHead = Array.from(new Uint8Array(a)).map(function (x) { return x.toString(16).padStart(2, '0'); }).join(''); window.__blobSize = b.size; }); return o.call(URL, b); }; })(); true`);
await T('Add device: Format exe is the default; MSI is disabled with the reason (no template), ARM64 says so; the .exe download is unchanged', async () => {
  php(['msi', 'none']);
  await openDialog();
  assert(await checked('#rmm-inst-format-exe') && !(await checked('#rmm-inst-format-msi')), 'exe is not the default');
  assert(await p.eval(`document.getElementById('rmm-inst-format-msi').disabled`), 'MSI is enabled with no template');
  assert(/no MSI template is published for x64/.test(await text('#rmm-inst-format-help')) && (await p.visible('#rmm-inst-format-link')), 'help: ' + await text('#rmm-inst-format-help'));
  await click('label[for="rmm-inst-arch-arm64"]');
  await sleep(200);
  assert(/not available for ARM64/.test(await text('#rmm-inst-format-help')), 'ARM64 help: ' + await text('#rmm-inst-format-help'));
  await click('label[for="rmm-inst-arch-amd64"]');
  assert((await unlabeled('#rmmInstallerModal')).length === 0, 'unlabeled controls: ' + (await unlabeled('#rmmInstallerModal')).join(','));
  await shotEl('#rmmInstallerModal .modal-content', 'dialog-format-nomsi-desktop-light');
  await p.eval(`(()=>{const e=document.getElementById('rmm-inst-client');e.value='1';e.dispatchEvent(new Event('change',{bubbles:true}));})()`);
  await captureBlob();
  await click('#rmm-inst-go');
  await p.waitFor(`!document.getElementById('rmm-inst-done').classList.contains('d-none')`, { timeout: 30000 });
  await sleep(500);
  assert((await p.eval('window.__blobHead')).startsWith('4d5a'), 'the .exe is not an MZ file: ' + await p.eval('window.__blobHead'));
  assert(/Downloaded RivetIT-Agent-Setup-.*\.exe/.test(await text('#rmm-inst-done')), 'done: ' + await text('#rmm-inst-done'));
});
await T('Add device: with a template published, MSI is offered for x64 only; picking it shows the unverified warning and the msiexec hint; the download is an OLE compound file', async () => {
  php(['msi', FIXTURE]);
  await openDialog();
  assert(await p.eval(`!document.getElementById('rmm-inst-format-msi').disabled`), 'MSI still disabled');
  await p.eval(`(()=>{const e=document.getElementById('rmm-inst-client');e.value='1';e.dispatchEvent(new Event('change',{bubbles:true}));})()`);
  await click('label[for="rmm-inst-format-msi"]');
  await sleep(250);
  assert(await checked('#rmm-inst-format-msi') && (await p.visible('#rmm-inst-msi-warn')), 'the warning is not shown');
  assert(/unsigned: SmartScreen/.test(await text('#rmm-inst-msi-warn')) && /Upgrade, repair and uninstall are not yet tested/.test(await text('#rmm-inst-msi-warn')) && !/Not yet verified on Windows/.test(await text('#rmm-inst-msi-warn')), 'warning: ' + await text('#rmm-inst-msi-warn'));
  assert(await p.visible('#rmm-inst-silent-msi') && !(await p.visible('#rmm-inst-silent-exe')) && /msiexec \/i ".*\.msi" \/qn \/norestart/.test(await text('#rmm-inst-silent-msi')), 'hint: ' + await text('#rmm-inst-silent-msi'));
  await shotEl('#rmmInstallerModal .modal-content', 'dialog-format-msi-desktop-light');
  await captureBlob();
  await click('#rmm-inst-go');
  await p.waitFor(`!document.getElementById('rmm-inst-done').classList.contains('d-none')`, { timeout: 30000 });
  await sleep(500);
  assert((await p.eval('window.__blobHead')) === 'd0cf11e0a1b11ae1', 'the MSI is not an OLE compound file: ' + await p.eval('window.__blobHead'));
  assert(/Downloaded RivetIT-Agent-.*-x64\.msi/.test(await text('#rmm-inst-done')) && /unsigned: SmartScreen/.test(await text('#rmm-inst-done')), 'done: ' + await text('#rmm-inst-done'));
  // ARM64: MSI is disabled and the selection falls back to the .exe
  await click('label[for="rmm-inst-arch-arm64"]');
  await sleep(250);
  assert(await p.eval(`document.getElementById('rmm-inst-format-msi').disabled`) && await checked('#rmm-inst-format-exe') && !(await p.visible('#rmm-inst-msi-warn')), 'ARM64 did not fall back to the .exe');
  assert(/not available for ARM64/.test(await text('#rmm-inst-format-help')), 'ARM64 help');
  // Linux hides the Format
  await click('#rmm-inst-tab-linux');
  assert(!(await p.visible('#rmm-inst-format-col')), 'Format is shown for Linux');
});

// ================================================================================================ 4. Administration
await T('admin: the MSI template card (short unsigned/untested-paths note, no stale unverified banner) and the Deployment format selector', async () => {
  php(['msi', 'none']);
  await go(ADMIN + '#msi-templates');
  await trapDialogs();
  await p.waitSel('#msi-unverified');
  const w = await text('#msi-unverified');
  assert(/unsigned: SmartScreen/.test(w) && /application-control policy may warn/.test(w) && /Upgrade, repair and uninstall are not yet tested/.test(w) && !/UNVERIFIED on Windows/.test(w) && !/never been run on a real Windows PC/.test(w), 'warning: ' + w.slice(0, 300));
  assert((await p.eval(`document.getElementById('msi-unverified').classList.contains('alert-info')`)) && (await p.eval(`document.getElementById('msi-unverified').getAttribute('role')`)) === 'note', 'the note uses the neutral alert-info style, not the old alarm style');
  const c = await text('#msi-templates');
  assert(/Only x64 \(amd64\) is built/.test(c) && /Largest accepted upload now: 2 MiB/.test(c) && /endpoint_agent_publish\.php rivetit-agent-windows-amd64\.msi --msi/.test(c) && /No MSI template published yet/.test(c), 'card: ' + c.slice(0, 400));
  assert((await unlabeled('#msi-templates')).length === 0, 'unlabeled controls: ' + (await unlabeled('#msi-templates')).join(','));
  assert(await p.eval(`document.querySelector('#dp_format option[value=msi]').disabled`), 'Deployment: MSI is offered with no template');
  assert(/no current MSI template is published for x64/.test(await text('#dp_format_help')), 'Deployment help: ' + await text('#dp_format_help'));
  await shotEl('#msi-templates', 'card-msi-desktop-light');
});
await T('admin: the real 3.8 MB template is refused by PHP\'s 2 MiB limit with the limit in words and the CLI path; the small fixture uploads, becomes current, deactivates and reactivates', async () => {
  if (REAL && existsSync(REAL) && statSync(REAL).size > 2 * 1048576) {
    await go(ADMIN + '#msi-templates');
    await setFile('#msi_file', REAL);
    await p.type('#msi_v', '0.6.0');
    await nav('button[name=upload_msi_template]');
    assert(/larger than the limit of 2 MiB/.test(await bodyText()) && /endpoint_agent_publish\.php --msi/.test(await bodyText()), 'no limit message: ' + (await bodyText()).slice(0, 300));
    assert(php(['templates']).length === 0, 'a template was stored by a refused upload');
  }
  await go(ADMIN + '#msi-templates');
  await trapDialogs();
  await setFile('#msi_file', FIXTURE);
  await p.type('#msi_v', '0.6.9');
  await nav('button[name=upload_msi_template]');
  const t = php(['templates']);
  assert(t.length === 1 && t[0].version === '0.6.9' && t[0].current === 1 && t[0].active === 1 && t[0].sha256 === t[0].file_sha256, 'templates: ' + JSON.stringify(t));
  assert(/MSI template 0\.6\.9 \(amd64\) stored\./.test(await bodyText()), 'no confirmation');
  await p.waitSel('#msi-templates .badge.bg-success');
  assert(/Current/.test(await text('#msi-templates table')) && /the package/.test(await text('#msi-templates table')) && t[0].sha256 === (await text('#msi-templates td.font-monospace')), 'list: ' + await text('#msi-templates table'));
  assert(await p.eval(`!document.querySelector('#dp_format option[value=msi]').disabled`), 'Deployment: MSI is not offered although a template is current');
  await nav('#msi-templates button[value=deactivate]');
  assert(/Inactive/.test(await text('#msi-templates table')) && php(['templates'])[0].active === 0, 'deactivate: ' + JSON.stringify(php(['templates'])));
  await nav('#msi-templates button[value=activate]');
  await nav('#msi-templates button[value=make_current]');
  assert(php(['templates'])[0].active === 1 && php(['templates'])[0].current === 1, 'reactivate + make current: ' + JSON.stringify(php(['templates'])));
  await shotEl('#msi-templates', 'card-msi-published-desktop-light');
});
await T('admin: Deployment, Format MSI: the deployment commands panel shows the msi block (msiexec, PowerShell downloader, property form, uninstall, detection)', async () => {
  await go(ADMIN + '#deployment');
  await p.eval(`(()=>{const s=document.getElementById('dp_client');s.value='1';})()`);
  await p.eval(`(()=>{const s=document.getElementById('dp_format');s.value='msi';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
  await nav('button[name=show_deploy_commands]');
  await p.waitSel('#deploy-commands');
  const d = await text('#deploy-commands');
  assert(/Windows x64, MSI\)/.test(d) && /Upgrade, repair and uninstall are not yet tested/.test(d) && !/Not yet verified on Windows/.test(d), 'header/warning: ' + d.slice(0, 200));
  assert(/msiexec \/i "RivetIT-Agent-.*-x64\.msi" \/qn \/norestart/.test(await p.eval(`document.getElementById('ea_msi_msiexec').value`)), 'msiexec');
  assert(/format = 'msi'/.test(await p.eval(`document.getElementById('ea_msi_ps').value`)) && /ENROLL_TOKEN=/.test(await p.eval(`document.getElementById('ea_msi_props').value`)) && /REMOVE_MESHAGENT/.test(d) && /\{364538BF-62D7-4685-84C5-43B83B318852\}/.test(d), 'blocks');
  assert(!/setup --silent/.test(d), 'the exe instructions are mixed in');
  await shotEl('#deploy-commands', 'deploy-msi-desktop-light');
  // ARM64 disables MSI with the reason
  await go(ADMIN + '#deployment');
  await p.eval(`(()=>{const s=document.getElementById('dp_arch');s.value='arm64';s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
  assert(await p.eval(`document.querySelector('#dp_format option[value=msi]').disabled`) && /not available for ARM64/.test(await text('#dp_format_help')), 'ARM64: ' + await text('#dp_format_help'));
});

// ================================================================================================ 5. phone width, dark mode, no inline handlers
const surfaces = [
  ['device', async () => { await go(assetPage('PUSHI')); await p.waitSel('#rmm-remote [data-mesh-push-action="install"]'); }, '#rmm-remote', 'device page Remote access card'],
  ['push-dialog', async () => { await go(assetPage('PUSHI')); await click('#rmm-remote [data-mesh-push-action="uninstall"]'); await dialogOpen('#rmmMeshPushConfirm'); }, '#rmmMeshPushConfirm .modal-content', 'push dialog'],
  ['bulk', async () => { await openBulk(); }, '#rmmMeshBulk .modal-content', 'bulk dialog'],
  ['add-device', async () => { php(['msi', FIXTURE]); await openDialog(); await click('label[for="rmm-inst-format-msi"]'); }, '#rmmInstallerModal .modal-content', 'Add device dialog, MSI'],
  ['admin-msi', async () => { await go(ADMIN + '#msi-templates'); }, '#msi-templates', 'MSI template card'],
];
await T('dark mode, desktop: every new surface is dark and readable (screenshots in shots/)', async () => {
  php(['installed', 'PUSHI', '1']);   // the device reports the Mesh agent again, so the Remove button is on the page
  await setTheme(1);
  for (const [name, setup, sel] of surfaces) {
    await p.setViewport(1366, 900);
    await setup(); await sleep(500);
    const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
    const [r, g, b] = bg.match(/\d+/g).map(Number);
    assert(r + g + b < 3 * 110, `${name}: the page is not dark in dark mode: ${bg}`);
    await shotEl(sel, `${name}-desktop-dark`);
  }
});
await T('phone width, dark and light: nothing overflows the page', async () => {
  for (const theme of [1, 0]) {
    await p.setViewport(1366, 900);
    if (theme === 0) await setTheme(0);
    await p.setViewport(MOBILE_W, 800, true);
    for (const [name, setup, sel, label] of surfaces) {
      await setup(); await sleep(500);
      if (!/dialog/i.test(label)) await noOverflow(`${label} ${theme ? 'dark' : 'light'} phone`);
      else { const r = await p.call((a0)=>{const e=document.querySelector(a0);const b=e.getBoundingClientRect();return {l:b.left,r:b.right,vw:document.documentElement.clientWidth}}, sel); assert(r.l >= -1 && r.r <= r.vw + 1, `${label}: wider than the phone (${JSON.stringify(r)})`); }
      await shotEl(sel, `${name}-phone-${theme ? 'dark' : 'light'}`);
    }
  }
  await p.setViewport(1366, 900);
});
await T('no window.confirm / alert / prompt and no inline event handlers on the new surfaces', async () => {
  for (const path of [assetPage('PUSHI'), FLEET + '?tag=rc16push', ADMIN + '#msi-templates']) {
    await go(path);
    assert(await p.eval(`document.querySelectorAll('[data-mesh-push] [onclick],#rmmMeshPushConfirm [onclick],#rmmMeshBulk [onclick],#msi-templates [onclick],#rmmInstallerModal [onclick],#rmmMeshBulk [onchange],#rmmInstallerModal [onchange]').length === 0`), 'inline event handlers on ' + path);
  }
  const src = await (await fetch(BASE + '/js/rmm_mesh_push.js')).text();
  assert(!/\b(window\.)?(confirm|alert|prompt)\(/.test(src), 'rmm_mesh_push.js uses a native dialog');
});

php(['msi', 'none']);
const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
