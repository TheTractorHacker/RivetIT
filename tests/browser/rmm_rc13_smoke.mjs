// Browser smoke for RivetCore rc.12 (the optional MeshCentral agent) and rc.13 (the RMM starter pack), in a real Chrome: the "Also install the MeshCentral agent" tick-box in the
// Add device dialog, the Mesh install settings card (with the pin read from a local TLS server), the Mesh agent state on the device page, and the Starter pack page with the
// badge, the risk chip and the "update waiting for review" marker in the script library. Desktop and 390 px, light and dark.
//
//   RIVETIT_WEBHOOK_ALLOW_PRIVATE=1 /tmp/claude-1000/rc13it_browser.sh 8741 tests/browser/rmm_rc13_smoke.mjs
//
// The variable must be set for THIS run only: php -S inherits it, which lets the "Read it from the server" button reach the loopback TLS stand-in this script starts (production
// refuses private addresses). Same driver, harness and safety rules as rmm_p68_admin_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form,
// every check also fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx while it ran (the few refusals that are the point of a check say so).
import { mkdirSync, writeFileSync, mkdtempSync, rmSync, readFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { tmpdir } from 'node:os';
import { createServer, connect } from 'node:net';
import { spawn, execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke installs and uninstalls starter scripts and changes Mesh settings; it is meant for a throwaway install.`);
  process.exit(2);
}
if (process.env.RIVETIT_WEBHOOK_ALLOW_PRIVATE !== '1') {
  console.error('Run with RIVETIT_WEBHOOK_ALLOW_PRIVATE=1 in front of the wrapper (the php -S server needs it to reach the loopback TLS stand-in).');
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });
const php = (args) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_rc13_extra.php', ...args], { cwd: process.cwd(), env: { ...process.env }, encoding: 'utf8' }).split('\n').find((l) => l.startsWith('{')));   // the harness prints its own pass/fail summary after the fixture's line

// ---- the Mesh stand-in: a self-signed TLS server (openssl s_server) on a free loopback port; the expected SHA-384 is computed here, independently of the product
const freePort = () => new Promise((res) => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });
const tlsDir = mkdtempSync(join(tmpdir(), 'rc13_tls_'));
execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2', '-subj', '/CN=mesh.smoke', '-addext', 'subjectAltName=IP:127.0.0.1', '-keyout', join(tlsDir, 'k.pem'), '-out', join(tlsDir, 'c.pem')], { stdio: 'ignore' });
const tlsPort = await freePort();
const tlsProc = spawn('openssl', ['s_server', '-accept', `127.0.0.1:${tlsPort}`, '-cert', join(tlsDir, 'c.pem'), '-key', join(tlsDir, 'k.pem'), '-www', '-quiet'], { stdio: 'ignore' });
const EXPECT_PIN = execFileSync('openssl', ['x509', '-in', join(tlsDir, 'c.pem'), '-noout', '-fingerprint', '-sha384'], { encoding: 'utf8' }).trim().split('=')[1].replace(/:/g, '').toLowerCase();
const MESH_TLS = `https://127.0.0.1:${tlsPort}`;
// rc.15: MeshCentral's URL form of a device group id (base64 of the 48 bytes, '@' for '+', '$' for '/'), computed here independently of the product; 'cd'x48 is the hex used below
const urlForm = (hex) => Buffer.from(hex, 'hex').toString('base64').replace(/\+/g, '@').replace(/\//g, '$');
const CD_HEX = 'cd'.repeat(48), CD_URL = urlForm(CD_HEX);
const stopTls = () => { try { tlsProc.kill(); } catch {} try { rmSync(tlsDir, { recursive: true, force: true }); } catch {} };
process.on('exit', stopTls);
const canConnect = (port) => new Promise((ok) => { const c = connect(port, '127.0.0.1', () => { c.destroy(); ok(true); }); c.on('error', () => ok(false)); });
for (let i = 0; i < 50 && !(await canConnect(tlsPort)); i++) await sleep(100);

const fixtures = php(['setup']);   // MeshCentral on (no group id yet), a technician login, five devices with Mesh agent states
const ASSET = fixtures.assets;

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-rc13', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); await sleep(500); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const ADMIN = '/admin/settings_endpoint_agent.php';
const FLEET = '/agent/rmm_fleet.php';
const LIB = '/agent/rmm_script_library.php';
const STARTER = '/agent/rmm_starter_pack.php';

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
async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
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
const setChecked = async (sel, want) => {
  for (let i = 0; i < 3 && (await checked(sel)) !== want; i++) { await click(`label[for="${sel.slice(1)}"]`); await sleep(350); }
  assert((await checked(sel)) === want, 'could not set ' + sel);
};
const unlabeled = (scope) => p.call((a0)=>([...document.querySelectorAll(a0 + ' input, ' + a0 + ' select, ' + a0 + ' textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)), scope);
const text = (sel) => p.call((a0)=>{const e=document.querySelector(a0);return e?e.innerText.replace(/\s+/g,' ').trim():''}, sel);
const setValue = (sel, v) => p.call((a0,a1)=>{const e=document.querySelector(a0);e.value=a1;e.dispatchEvent(new Event('change',{bubbles:true}));e.dispatchEvent(new Event('input',{bubbles:true}));}, sel, v);
const inRange = async (sel, label) => { assert(await p.exists(sel), `${label}: missing ${sel}`); };

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ================================================================================================ 1. the Add device dialog
const openDialog = async () => {
  await go(FLEET + '?installer=1');
  await p.waitSel('#rmmInstallerModal.show', { timeout: 8000 });
  await sleep(500);
};
const pickDepartment = async () => { await setValue('#rmm-inst-client', '1'); await sleep(500); };
await T('dialog: the MeshCentral tick-box is offered, OFF by default, and says what is missing (no group id yet)', async () => {
  await openDialog();
  assert(await p.exists('#rmm-inst-mesh'), 'no tick-box although MeshCentral is on with an address');
  assert(!(await checked('#rmm-inst-mesh')), 'the tick-box is on by default');
  const lab = await text('label[for=rmm-inst-mesh]');
  assert(lab === 'Also install the MeshCentral agent', 'label: ' + lab);
  const help = await text('#rmm-inst-mesh-help');
  assert(/Not ready yet/.test(help) && /device group id/.test(help) && (await p.exists('#rmm-inst-mesh-help a[href$="settings_endpoint_agent.php#mesh"]')), 'help: ' + help);
  assert((await p.eval(`document.getElementById('rmm-inst-mesh').getAttribute('aria-describedby')`)) === 'rmm-inst-mesh-help', 'the box is not tied to its help text');
  assert((await unlabeled('#rmmInstallerModal')).length === 0, 'unlabeled controls in the dialog: ' + (await unlabeled('#rmmInstallerModal')).join(','));
  await shotEl('#rmmInstallerModal .modal-content', 'dialog-mesh-notready-desktop-light');
});
await T('dialog: ticking it and downloading is refused with Core\'s text, inside the dialog, and nothing is downloaded', async () => {
  await pickDepartment();
  await setChecked('#rmm-inst-mesh', true);
  await click('#rmm-inst-go');
  await p.waitFor(`!document.getElementById('rmm-inst-error').classList.contains('d-none')`, { timeout: 15000 });
  const e = await text('#rmm-inst-error');
  assert(/device group id/.test(e), 'refusal text: ' + e);
  assert(await p.eval(`document.getElementById('rmm-inst-done').classList.contains('d-none')`), 'a success message is shown after a refusal');
  assert((await p.eval(`document.getElementById('rmmInstallerModal').getAttribute('data-state')`)) === null, 'the dialog claims it downloaded');
  await shotEl('#rmmInstallerModal .modal-content', 'dialog-mesh-refused-desktop-light');
}, { allowHttp: [/rmm_installer\.php/] });
await T('dialog, Linux: the same refusal for the command; unticking clears it', async () => {
  await click('#rmm-inst-tab-linux');
  await click('#rmm-inst-go');
  await p.waitFor(`!document.getElementById('rmm-inst-error').classList.contains('d-none') && /device group id/.test(document.getElementById('rmm-inst-error').innerText)`, { timeout: 15000 });
  assert(await p.eval(`document.getElementById('rmm-inst-linux-out').classList.contains('d-none')`), 'a command is shown after a refusal');
}, { allowHttp: [/rmm_installer\.php/] });

// ================================================================================================ 2. the Mesh settings card
await T('settings card: new fields, collapsed advanced section with the verify warning, step-by-step help, labelled', async () => {
  await go(ADMIN + '#mesh');
  await trapDialogs();
  for (const sel of ['#mc_group', '#mc_pin', '#mc_pin_probe', '#mc_overwrite', '#mc_ids', '#mc_args', '#mesh-install-help']) await inRange(sel, 'card');
  assert(!(await p.eval(`document.getElementById('mesh-install-advanced').open`)), 'the advanced section is open by default');
  assert(!(await p.eval(`document.getElementById('mesh-install-help').open`)), 'the help is open by default');
  assert((await unlabeled('#mesh')).length === 0, 'unlabeled controls: ' + (await unlabeled('#mesh')).join(','));
  assert(/Not ready: /.test(await text('#mesh-install-ready')), 'ready line: ' + await text('#mesh-install-ready'));
  // keyboard: Enter on the summary opens the advanced section
  await p.eval(`document.querySelector('#mesh-install-advanced summary').focus()`);
  await p.press('Enter');
  await sleep(300);
  assert(await p.eval(`document.getElementById('mesh-install-advanced').open`), 'Enter on the summary did not open it');
  const t = await text('#mesh-install-advanced');
  assert(/verify against your MeshCentral version/.test(t) && /least certain/.test(t) && /windows\/amd64/.test(t) && /built in/.test(t), 'advanced text: ' + t.slice(0, 300));
  await p.eval(`document.querySelector('#mesh-install-help summary').click()`);
  const h = await text('#mesh-install-help');
  assert(/Create the device group in MeshCentral/.test(h) && /Treat the group id like an invitation key/.test(h) && /not hex/.test(h) && /Check the result/.test(h), 'help text: ' + h.slice(0, 200));
  await shotEl('#mesh', 'card-mesh-desktop-light');
});
await T('settings card: a bad group id and a bad pin are refused with Core\'s text and what was typed stays in the form', async () => {
  await go(ADMIN + '#mesh');
  await p.type('#mc_group', 'not hex at all');
  await nav('button[name=save_mesh_settings]');
  const msg = await bodyText();
  assert(/device group id must be the meshid from the MeshCentral download address/.test(msg), 'no Core refusal on the page');
  assert((await p.eval(`document.getElementById('mc_group').value`)) === 'not hex at all', 'the typed group id was not kept');
  await go(ADMIN + '#mesh');
  await p.type('#mc_group', 'ab'.repeat(48));
  await p.type('#mc_pin', 'not-a-pin');
  await nav('button[name=save_mesh_settings]');
  assert(/certificate pin must be the SHA-384/.test(await bodyText()), 'no pin refusal');
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === 'not-a-pin', 'the typed pin was not kept');
});
await T('settings card (rc.15): the group id field is masked with a show toggle, takes the whole pasted address, warns about another host, and reads back the URL form', async () => {
  await go(ADMIN + '#mesh');
  const attrs = await p.eval(`(()=>{const e=document.getElementById('mc_group');return {type:e.type,pattern:e.getAttribute('pattern'),max:e.maxLength,ac:e.autocomplete}})()`);
  assert(attrs.type === 'password' && attrs.pattern === null && attrs.max === 2000, 'input attributes: ' + JSON.stringify(attrs));
  assert(/not hex/.test(await text('#mc_group_help')) && /invitation key/.test(await text('#mc_group_help')) && /Windows 64-bit installer/.test(await text('#mc_group_help')), 'help: ' + await text('#mc_group_help'));
  assert(/Leave this empty when the Mesh server/.test(await text('#mc_pin_help')) && /Let's Encrypt/.test(await text('#mc_pin_help')), 'pin help: ' + await text('#mc_pin_help'));
  assert((await p.eval(`document.getElementById('mc_pin').placeholder`)) === 'empty = public CA certificate', 'pin placeholder');
  const A = urlForm('ab'.repeat(48));
  const host = await p.eval(`new URL(document.getElementById('mc_url').value).hostname`);
  await p.type('#mc_group', `https://some-other-mesh.example.net/meshagents?id=4&meshid=${A}&installflags=0`);
  await click('#mc_group_show');
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'text', 'the show toggle did not reveal the id');
  assert((await p.eval(`document.getElementById('mc_group').value`)).startsWith('https://some-other-mesh'), 'the pasted address was altered in the field');
  await nav('button[name=save_mesh_settings]');
  const t = await bodyText();
  assert(/Warning: The pasted address is for the host some-other-mesh\.example\.net, but the MeshCentral address is set to /.test(t), 'no warning about the other host: ' + t.slice(0, 300));
  assert((await p.eval(`document.getElementById('mc_group').value`)) === A, 'the card did not read the id back in the URL form');
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'password', 'the id is not masked after reload');
  await click('#mc_group_show');
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'text', 'toggle (second time)');
  await click('#mc_group_show');
  assert((await p.eval(`document.getElementById('mc_group').type`)) === 'password', 'toggle back');
  await shotEl('#mesh', 'card-mesh-group-url-desktop-light');
  await p.type('#mc_group', '<img src=x onerror=alert(1)>');
  await nav('button[name=save_mesh_settings]');
  assert(/device group id must be the meshid/.test(await bodyText()), 'hostile value was not refused');
  assert((await p.eval(`document.getElementById('mc_group').value`)) === '<img src=x onerror=alert(1)>' && !(await p.exists('#mesh img')), 'the refused value must come back as text, not markup');
  await p.type('#mc_group', A);
  await nav('button[name=save_mesh_settings]');
  assert((await p.eval(`document.getElementById('mc_group').value`)) === A, 'the bare meshid was not saved');
});
await T('settings card: point it at the local TLS stand-in, read the pin from the server, compare, use it, save', async () => {
  php(['mesh', MESH_TLS, '-']);
  await go(ADMIN + '#mesh');
  assert((await p.eval(`document.getElementById('mc_url').value`)) === MESH_TLS, 'the address field');
  await p.type('#mc_group', CD_HEX);
  await p.type('#mc_pin', 'ee'.repeat(48));   // typed, but the probe button must NOT save it
  await nav('#mc_pin_probe');
  await p.waitSel('#mc_pin_proposed');
  const proposed = await text('#mc_pin_proposed');
  assert(proposed === EXPECT_PIN, `proposed pin ${proposed} !== independently computed ${EXPECT_PIN}`);
  assert(/Proposed pin \(not saved\)/.test(await text('#mc_pin_proposal')), 'not labelled as unsaved');
  assert(/not trusted by the system certificate store/.test(await text('#mc_pin_note')) && (await p.eval(`document.getElementById('mc_pin_proposal').getAttribute('data-publicly-trusted')`)) === '0', 'no self-signed note: ' + await text('#mc_pin_proposal'));
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === '', 'the pin field is not empty (a typed pin must not have been saved by the probe)');
  await shotEl('#mc_pin_proposal', 'card-mesh-pin-proposal-desktop-light');
  await click('[data-ea-use-pin]');
  await sleep(300);
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === EXPECT_PIN, '"Use this pin" did not fill the field');
  assert((await p.eval(`document.activeElement && document.activeElement.id`)) === 'mc_pin', 'focus did not move to the pin field');
  await nav('button[name=save_mesh_settings]');
  assert((await p.eval(`document.getElementById('mc_pin').value`)) === EXPECT_PIN, 'the pin was not saved');
  assert((await p.eval(`document.getElementById('mc_group').value`)) === CD_URL, 'the group id was not saved (the card shows the URL form ' + CD_URL + ')');
  assert(/Ready: the tick-box appears/.test(await text('#mesh-install-ready')), 'ready line: ' + await text('#mesh-install-ready'));
  assert(!(await p.exists('#mc_pin_proposal')), 'the proposal is still shown after saving');
});
await T('settings card: the overwrite switch and an agent-id correction are saved and shown', async () => {
  await go(ADMIN + '#mesh');
  await setChecked('#mc_overwrite', true);
  await p.eval(`document.getElementById('mesh-install-advanced').open = true`);
  await p.type('#mc_ids', '{"windows/arm64": 36}');
  await nav('button[name=save_mesh_settings]');
  assert(await checked('#mc_overwrite'), 'the switch is not on after saving');
  assert(/36/.test(await p.eval(`document.getElementById('mc_ids').value`)), 'the correction is not shown');
  assert(await p.exists('#mesh-install-advanced .badge.text-bg-warning'), 'no "corrected" marker');
  await p.eval(`document.getElementById('mesh-install-advanced').open = true`);
  await p.type('#mc_ids', '[1,2]');
  await nav('button[name=save_mesh_settings]');
  assert(/must each be a JSON object/.test(await bodyText()), 'a JSON list was not refused');
  await setChecked('#mc_overwrite', false);
  await p.type('#mc_ids', '');
  await nav('button[name=save_mesh_settings]');
  assert(!(await checked('#mc_overwrite')), 'the switch could not be turned off again');
});

// ================================================================================================ 3. the dialog again, now that Mesh is set up
await T('dialog, Windows: ticked and downloaded; the message says the Mesh agent comes too; the box is OFF again on the next opening', async () => {
  await openDialog();
  assert(/downloads the MeshCentral agent from your own Mesh server/.test(await text('#rmm-inst-mesh-help')) && (await p.eval(`document.getElementById('rmm-inst-mesh-help').getAttribute('data-ready')`)) === '1', 'help: ' + await text('#rmm-inst-mesh-help'));
  await pickDepartment();
  await setChecked('#rmm-inst-mesh', true);
  await click('#rmm-inst-go');
  await p.waitFor(`!document.getElementById('rmm-inst-done').classList.contains('d-none')`, { timeout: 30000 });
  const d = await text('#rmm-inst-done');
  assert(/Downloaded RivetIT-Agent-Setup-/.test(d) && /also installs the MeshCentral agent/.test(d), 'done text: ' + d);
  await shotEl('#rmmInstallerModal .modal-content', 'dialog-mesh-downloaded-desktop-light');
  await openDialog();
  assert(!(await checked('#rmm-inst-mesh')), 'the box stayed ticked when the dialog was opened again');
});
await T('dialog, Linux: ticked gives the command with the Mesh flags (and the pin); unticking drops the stale command; the plain command has no Mesh flags', async () => {
  await openDialog();
  await pickDepartment();
  await click('#rmm-inst-tab-linux');
  await click('#rmm-inst-go');
  await p.waitFor(`document.getElementById('rmm-inst-linux-cmd').innerText.length > 20`, { timeout: 20000 });
  const plain = await p.eval(`document.getElementById('rmm-inst-linux-cmd').innerText`);
  assert(/\.\/install-linux\.sh --server /.test(plain) && !/--install-mesh-agent|--mesh-/.test(plain), 'plain command: ' + plain.slice(-200));
  await setChecked('#rmm-inst-mesh', true);
  assert(await p.eval(`document.getElementById('rmm-inst-linux-out').classList.contains('d-none')`), 'a command made with the other choice stayed on screen');
  await click('#rmm-inst-go');
  await p.waitFor(`document.getElementById('rmm-inst-linux-cmd').innerText.length > 20`, { timeout: 20000 });
  const cmd = await p.eval(`document.getElementById('rmm-inst-linux-cmd').innerText`);
  assert(cmd.includes(`--install-mesh-agent --mesh-url '${MESH_TLS}' --mesh-group-id '${CD_URL}' --mesh-cert-pin '${EXPECT_PIN}'`), 'ticked command: ' + cmd.slice(-300));
  assert(/Mesh device group id: keep it secret/.test(await text('#rmm-inst-linux-meta')), 'no secrecy note: ' + await text('#rmm-inst-linux-meta'));
  await shotEl('#rmmInstallerModal .modal-content', 'dialog-mesh-linux-desktop-light');
});
await T('the department page dialog and a technician: the box only where an installer can be issued', async () => {
  await go('/agent/client_overview.php?client_id=1');
  assert(await p.exists('#rmm-inst-mesh') && (await p.eval(`document.getElementById('rmmInstallerModal').getAttribute('data-fixed')`)) === '1', 'the department dialog has no tick-box');
});

// ================================================================================================ 4. the device page
const devicePage = async (name) => { await go('/agent/asset_details.php?asset_id=' + ASSET[name] + '#rmm-remote'); await p.waitSel('#rmm-mesh-agent'); await sleep(300); };
await T('device page: mapped automatically (installed, version, node id, Mapped automatically, no warning); the node control stays', async () => {
  await devicePage('MESHOK');
  const t = await text('#rmm-mesh-agent');
  assert(/Installed/.test(t) && /1\.1\.4/.test(t) && /node\/\/Ab3Ab3/.test(t) && /Mapped automatically/.test(t), 'state: ' + t);
  assert(!(await p.exists('#rmm-mesh-agent [data-mesh-hint]')), 'a warning on a healthy device');
  assert((await p.eval(`document.getElementById('rmm-mesh-node').value`)).startsWith('node//Ab3'), 'the manual mapping control lost its value');
  await shotEl('#rmm-remote', 'device-mesh-mapped-desktop-light');
});
await T('device page: a conflict is a visible warning with a link to the other device', async () => {
  await devicePage('MESHDUP');
  assert(await p.exists('#rmm-mesh-agent[data-state=conflict] [data-mesh-hint=conflict]'), 'no conflict hint');
  const t = await text('#rmm-mesh-agent [data-mesh-hint=conflict]');
  assert(/already mapped to another device, so nothing was changed/.test(t) && /cloned disk image/.test(t), 'text: ' + t);
  assert(await p.exists(`#rmm-mesh-agent [data-mesh-hint=conflict] a[href*="asset_id=${ASSET.MESHOK}"]`), 'no link to the other device');
  assert((await p.eval(`document.querySelector('#rmm-mesh-agent [data-mesh-hint=conflict]').getAttribute('role')`)) === 'alert', 'the conflict is not role=alert');
  await shotEl('#rmm-remote', 'device-mesh-conflict-desktop-light');
});
await T('device page: a node kept because an administrator mapped it by hand is explained', async () => {
  await devicePage('MESHMAN');
  const t = await text('#rmm-mesh-agent [data-mesh-hint=kept_manual]');
  assert(/different Mesh node than the one an administrator mapped by hand, so the mapping was kept/.test(t) && /replace a node I mapped by hand/.test(t), 'text: ' + t);
  assert((await p.eval(`document.getElementById('rmm-mesh-node').value`)).startsWith('node//Ef6'), 'the manual node is not in the mapping control');
  await shotEl('#rmm-remote', 'device-mesh-kept-desktop-light');
});
await T('device page: an install error is shown, and a device that never reported says so', async () => {
  await devicePage('MESHERR');
  const t = await text('#rmm-mesh-agent [data-mesh-hint=error]');
  assert(/The Mesh agent install failed/.test(t) && /HTTP 404/.test(t) && /RivetIT agent itself is installed/.test(t), 'text: ' + t);
  await devicePage('MESHNONE');
  assert(/No Mesh agent has been reported/.test(await text('#rmm-mesh-agent')), 'no invitation: ' + await text('#rmm-mesh-agent'));
});
await T('device page: the manual node mapping still saves', async () => {
  await devicePage('MESHNONE');
  await p.type('#rmm-mesh-node', 'node//Zz9Zz9Zz9Zz9Zz9Zz9Zz9Zz9');
  await click('#rmm-mesh-form button[type=submit]');
  await sleep(1500);
  await devicePage('MESHNONE');
  assert((await p.eval(`document.getElementById('rmm-mesh-node').value`)).startsWith('node//Zz9'), 'the mapping was not saved');
}, { allowHttp: [/rmm_agent\.php/] });

// ================================================================================================ 5. the starter pack
await T('script library: a Starter pack button for the administrator; the page opens from it', async () => {
  await go(LIB);
  assert(await p.exists('#scr-starter-link'), 'no Starter pack link in the library');
  await nav('#scr-starter-link');
  assert(/rmm_starter_pack\.php/.test(await p.url()), 'url: ' + await p.url());
  assert(/Starter pack/.test(await text('h4')), 'heading');
});
await T('starter page: counts, filters, accessible, nothing installed, no blocker', async () => {
  await go(STARTER);
  await trapDialogs();
  const t = await text('#starter-counts');
  assert(/73 scripts/.test(t) && /41 Windows/.test(t) && /32 Linux/.test(t) && /57 read only/.test(t) && /10 change the system/.test(t) && /6 disruptive/.test(t) && /3 policy drafts/.test(t) && /0 installed/.test(t), 'counts: ' + t);
  assert(!(await p.exists('#starter-blocker')), 'blocker shown although the instance has a signing key');
  assert((await unlabeled('#starter-form')).length === 0, 'unlabeled controls: ' + (await unlabeled('#starter-form')).join(','));
  assert((await unlabeled('#starter-filters')).length === 0, 'unlabeled filter controls: ' + (await unlabeled('#starter-filters')).join(','));
  assert(/Nothing selected/.test(await text('#starter-selected')), 'selected text: ' + await text('#starter-selected'));
  for (const id of ['starter-plan', 'starter-install', 'starter-uninstall']) assert((await p.call((a0)=>(document.getElementById(''+a0+'').getAttribute('aria-disabled')), id)) === 'true', id + ' is usable with nothing selected');
  await shot('starter-desktop-light');
});
await T('starter page: filter by platform, risk and search narrows the table and keeps the filter in the form', async () => {
  await setValue('#st_platform', 'linux');
  await p.eval(`document.getElementById('st_risk_read_only').click()`);
  await nav('#starter-filters button[type=submit]');
  assert(/28 of 73 shown/.test(await text('#starter-card-scripts .card-header')), 'shown: ' + await text('#starter-card-scripts .card-header'));
  assert((await p.eval(`document.querySelectorAll('#starter-scripts input.starter-pick').length`)) === 28, 'rows');
  assert((await p.eval(`document.getElementById('st_platform').value`)) === 'linux' && (await checked('#st_risk_read_only')), 'the filter is not shown in the form');
});
let readOnlyLinux = 0;
await T('starter page: Select all shown ticks the shown rows; the count follows; Plan lists "Add" for each and changes nothing', async () => {
  await click('#starter-all');
  await sleep(300);
  readOnlyLinux = await p.eval(`document.querySelectorAll('#starter-scripts input.starter-pick:checked').length`);
  assert(readOnlyLinux === 28, 'ticked: ' + readOnlyLinux);
  assert(/28 selected/.test(await text('#starter-selected')), 'count: ' + await text('#starter-selected'));
  assert((await p.eval(`document.getElementById('starter-plan').getAttribute('aria-disabled')`)) === 'false', 'Plan is still disabled with a selection');
  const before = php(['scripts']);
  await nav('#starter-plan');
  await p.waitSel('#starter-result');
  const t = await text('#starter-result');
  assert(/Plan: nothing was changed/.test(t) && /Planned 28 items/.test(await bodyText()), 'result: ' + t.slice(0, 160));
  assert((await p.eval(`document.querySelectorAll('#starter-result tbody tr').length`)) === 28, 'rows in the plan');
  assert(/28/.test(await text('#starter-result [role=status]')), 'counts');
  const after = php(['scripts']);
  assert(before.all === after.all && before.policies === after.policies, 'the plan changed the library');
  assert((await p.eval(`document.querySelectorAll('#starter-scripts input.starter-pick:checked').length`)) === 28, 'the selection did not survive the plan');
  await shotEl('#starter-result', 'starter-plan-desktop-light');
});
await T('starter page: Install asks first (in-page dialog), then adds the 28 scripts, signed, and says so', async () => {
  const before = php(['scripts']);
  await click('#starter-install');
  await p.waitSel('#rmmAutoConfirm.show', { timeout: 8000 });
  assert(/Install the selected items\?/.test(await text('#rmmAutoConfirmText')), 'confirm text: ' + await text('#rmmAutoConfirmText'));
  assert((await p.eval('window.__dialogs')) === 0 || (await p.eval('window.__dialogs')) === undefined, 'a native dialog was used');
  const origin = await p.eval('performance.timeOrigin');
  await click('#rmmAutoConfirmGo');
  for (let i = 0; i < 300; i++) { await sleep(200); try { if (await p.call((a0)=>(performance.timeOrigin !== a0 && document.readyState === 'complete'), origin)) break; } catch {} }
  await sleep(600);
  await p.waitSel('#starter-result');
  assert(/Install result/.test(await text('#starter-result')) && /Install finished for 28 items/.test(await bodyText()), 'result: ' + (await text('#starter-result')).slice(0, 200));
  const after = php(['scripts']);
  assert(after.all === before.all + 28 && after.starter === 28, `library ${JSON.stringify(before)} -> ${JSON.stringify(after)}`);
  assert(/28 installed/.test(await text('#starter-counts')), 'counts after: ' + await text('#starter-counts'));
  await shotEl('#starter-result', 'starter-installed-desktop-light');
}, { allowHttp: [] });
await T('script library: the Starter badge and the risk chip, no raw starter tags, and the Starter pack link', async () => {
  await go(LIB + '?tag=starter');
  const n = await p.eval(`document.querySelectorAll('.rmm-starter-badge').length`);
  assert(n === 25 || n >= 25, 'badges on the first page: ' + n);   // 25 per page
  assert((await p.eval(`document.querySelectorAll('.rmm-risk-chip').length`)) === n, 'one risk chip per badge');
  assert(!/risk:read_only/.test(await bodyText()), 'raw risk tags are shown');
  await shot('library-starter-desktop-light');
});
await T('script page: the Starter badge and the version note in words', async () => {
  const id = await p.eval(`(()=>{const a=document.querySelector('table a[href*="script_id="]');return a?new URL(a.href).searchParams.get('script_id'):null})()`);
  await go(LIB + '?script_id=' + id);
  assert(await p.exists('.rmm-starter-badge') && /Installed from the starter pack/.test(await bodyText()), 'badge or note: ' + (await bodyText()).slice(0, 100));
});
await T('starter page: an administrator\'s edit is protected; the pack\'s newer text is parked and flagged "update waiting for review" everywhere', async () => {
  const e = php(['edit', 'lin-disk-usage']);
  assert(e.ok, 'edit failed: ' + e.error);
  await go(STARTER + '?platform=linux');
  assert(/Edited by you/.test(await bodyText()), 'state not shown');
  await p.eval(`document.getElementById('st_pick_lin-disk-usage').click()`);
  await click('#starter-plan');
  await sleep(1500);
  await p.waitSel('#starter-result');
  assert(/Park for review/.test(await text('#starter-result')), 'plan: ' + (await text('#starter-result')).slice(0, 200));
  await click('#starter-install');
  await p.waitSel('#rmmAutoConfirm.show', { timeout: 8000 });
  const origin = await p.eval('performance.timeOrigin');
  await click('#rmmAutoConfirmGo');
  for (let i = 0; i < 150; i++) { await sleep(200); try { if (await p.call((a0)=>(performance.timeOrigin !== a0 && document.readyState === 'complete'), origin)) break; } catch {} }
  await sleep(600);
  assert(/Parked for review/.test(await text('#starter-result')), 'install: ' + (await text('#starter-result')).slice(0, 200));
  assert(/1 update waiting for review/.test(await text('#starter-counts')), 'counts: ' + await text('#starter-counts'));
  await go(LIB);
  assert((await p.eval(`document.querySelectorAll('.rmm-starter-waiting').length`)) === 1, 'library marker count');
  await shotEl('table', 'library-update-waiting-desktop-light');
  await nav('.rmm-starter-waiting');
  assert(await p.exists('#scr-starter-waiting') && /waiting for your review/.test(await text('#scr-starter-waiting')), 'script page notice');
  await nav('#scr-starter-waiting a');
  assert(await p.exists('#scr-diff') && /Differences: version 2 to version 3/.test(await text('#scr-diff')), 'diff card: ' + await text('#scr-diff'));
  assert(/edited by the administrator/.test(await text('#scr-diff')), 'the diff does not show the administrator\'s line');
  await shotEl('#scr-diff', 'script-diff-desktop-light');
});
await T('starter page: uninstall previews first, then removes only the untouched ones and leaves the edited script', async () => {
  await go(STARTER + '?platform=linux&risk[]=read_only');
  await click('#starter-all');
  await nav('#starter-uninstall');
  await p.waitSel('#starter-result');
  const t = await text('#starter-result');
  assert(/Uninstall preview: nothing was changed/.test(t) && /Would be retired/.test(t) && /An administrator changed this script, so it was left alone/.test(t), 'preview: ' + t.slice(0, 200));
  const before = php(['scripts']);
  assert(before.retired === 0, 'the preview retired something');
  await click('#starter-uninstall-go');
  await p.waitSel('#rmmAutoConfirm.show', { timeout: 8000 });
  const origin = await p.eval('performance.timeOrigin');
  await click('#rmmAutoConfirmGo');
  for (let i = 0; i < 150; i++) { await sleep(200); try { if (await p.call((a0)=>(performance.timeOrigin !== a0 && document.readyState === 'complete'), origin)) break; } catch {} }
  await sleep(600);
  assert(/Uninstall result/.test(await text('#starter-result')), 'result: ' + (await text('#starter-result')).slice(0, 100));
  const after = php(['scripts']);
  assert(after.retired === 27 && after.all === before.all, `retired ${after.retired} (expected 27 of 28; the edited one stays)`);
});
await T('starter page, no signing key: the blocker is first and prominent, Install is disabled, a plan still works and says Blocked', async () => {
  php(['nokey']);
  await go(STARTER);
  assert(await p.visible('#starter-blocker'), 'no blocker');
  assert(/Nothing can be installed yet/.test(await text('#starter-blocker')) && /signing key is not available/.test(await text('#starter-blocker')), 'text: ' + await text('#starter-blocker'));
  assert(await p.eval(`document.getElementById('starter-install').disabled`), 'Install is not disabled');
  assert(await p.eval(`document.getElementById('starter-blocker').compareDocumentPosition(document.getElementById('starter-form')) & Node.DOCUMENT_POSITION_FOLLOWING`), 'the blocker is not above the catalog');
  await shotEl('#starter-blocker', 'starter-blocker-desktop-light');
  await p.eval(`document.getElementById('st_pick_win-flush-dns').click()`);
  await nav('#starter-plan');
  assert(/Blocked/.test(await text('#starter-result')), 'plan: ' + (await text('#starter-result')).slice(0, 200));
  php(['key']);
});
await T('a technician sees the Script library but not the Starter pack page', async () => {
  // sign out, sign in as the technician
  await go('/login.php?logout');
  await p.goto(BASE + '/logout.php').catch(() => {});
  await go('/login.php');
  await p.type('input[name=email]', 'tech@scratch.test');
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
  await go(LIB);
  assert(!(await p.exists('#scr-starter-link')), 'a technician has the Starter pack link');
  assert((await p.eval(`document.querySelectorAll('.rmm-starter-badge').length`)) > 0, 'a technician sees no Starter badge');
  assert(!/Update waiting for review/.test(await bodyText()), 'a technician sees the review marker');
  const r = await p.call((a0)=>(fetch(a0, {credentials:'same-origin'}).then(x=>x.status)), BASE + STARTER);
  assert(r === 403, 'starter page status for a technician: ' + r);
  await go('/logout.php');
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
}, { allowHttp: [/rmm_starter_pack\.php/, /login\.php/, /logout\.php/] });

// ================================================================================================ 6. dark mode and the phone
const checkSet = [
  ['dialog', async () => { await openDialog(); await pickDepartment(); await setChecked('#rmm-inst-mesh', true); }, '#rmmInstallerModal .modal-content', 'dialog'],
  ['mesh-card', async () => { await go(ADMIN + '#mesh'); await p.eval(`document.getElementById('mesh-install-advanced').open = true`); }, '#mesh', 'mesh card'],
  ['device-conflict', async () => { await devicePage('MESHDUP'); }, '#rmm-remote', 'device page, conflict'],
  ['device-kept', async () => { await devicePage('MESHMAN'); }, '#rmm-remote', 'device page, kept by hand'],
  ['starter', async () => { await go(STARTER); }, '#starter-form', 'starter page'],
  ['library', async () => { await go(LIB); }, 'table', 'script library'],
];
const looksDark = async (label) => {
  const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
  const [r, g, b] = bg.match(/\d+/g).map(Number);
  assert(r + g + b < 3 * 110, `${label}: the page is not dark in dark mode: ${bg}`);
};
await T('dark mode, desktop: every new surface is dark and readable (screenshots in shots/)', async () => {
  await setTheme(1);
  for (const [name, setup, sel, label] of checkSet) {
    await p.setViewport(1366, 900);
    await setup(); await sleep(500);
    await looksDark(label);
    await shotEl(sel, `${name}-desktop-dark`);
  }
});
await T('phone width, dark: nothing overflows the page', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  for (const [name, setup, sel, label] of checkSet) {
    await setup(); await sleep(500);
    if (name !== 'dialog') await noOverflow(`${label} dark phone`);
    await shotEl(sel, `${name}-phone-dark`);
  }
  await go(STARTER + '?platform=linux'); await sleep(400);
  await p.eval(`document.getElementById('starter-all').click()`);
  await sleep(200);
  await noOverflow('starter page, with a selection, dark phone');
  const bar = await p.eval(`(()=>{const b=document.getElementById('starter-actions').getBoundingClientRect();return {right:b.right,vw:document.documentElement.clientWidth}})()`);
  assert(bar.right <= bar.vw + 1, 'the action bar is wider than the phone');
});
await T('phone width, light: nothing overflows the page', async () => {
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(MOBILE_W, 800, true);
  for (const [name, setup, sel, label] of checkSet) {
    await setup(); await sleep(500);
    if (name !== 'dialog') await noOverflow(`${label} light phone`);
    await shotEl(sel, `${name}-phone-light`);
  }
  assert((await unlabeled('#starter-form')).length === 0, 'unlabeled controls on the phone starter page');
  await p.setViewport(1366, 900);
});
await T('no window.confirm / alert / prompt and no inline event handlers on the new surfaces', async () => {
  for (const path of [STARTER, ADMIN + '#mesh', LIB]) {
    await go(path);
    assert(await p.eval(`document.querySelectorAll('#starter-form [onclick],#starter-form [onchange],#starter-form [onsubmit],#mesh [onclick],#mesh [onchange],#mesh [onsubmit],#starter-filters [onclick],#starter-filters [onchange]').length === 0`), 'inline event handlers on ' + path);
  }
});

// put the install back the way the next smoke expects it
php(['mesh', 'https://mesh.example.test', '-']);
const fails = H.summary();
await browser.close();
stopTls();
process.exit(fails ? 1 : 0);
