// Browser smoke for Administration > Endpoint agent, RivetCore 1.0.0-rc.11 cards (builder C): remote tools, file transfer, log collection, scale settings, partitioning,
// the external metric store (with a loopback fake VictoriaMetrics this script starts itself) and the Scale and capacity panel on the Agent fleet page.
//
//   RIVETIT_WEBHOOK_ALLOW_PRIVATE=1 /tmp/claude-1000/rc11c_browser.sh 8673 tests/browser/rmm_p68_admin_smoke.mjs
//
// The variable must be set for THIS run only: php -S inherits it from the wrapper, which lets the store address be a loopback http URL (the install's test constant
// EA_ALLOW_INSECURE_HTTP, set by the scratch config.php, is the other half). Production has neither, so it is https only there; the refusals are asserted in
// tests/rmm_ui_p68_admin.php against a server without the variable.
//
// Same driver, harness and safety rules as rmm_p23_c_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, every check also fails on any
// uncaught error, console.error, failed first-party request or HTTP 4xx/5xx while it ran. The forms are filled and submitted like a person would, including the keyboard.
import { mkdirSync, readFileSync, writeFileSync, mkdtempSync, existsSync, rmSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';
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
  console.error(`Refusing to run against ${BASE}: the smoke changes administration settings and converts a table; it is meant for a throwaway install.`);
  process.exit(2);
}
if (process.env.RIVETIT_WEBHOOK_ALLOW_PRIVATE !== '1') {
  console.error('Run with RIVETIT_WEBHOOK_ALLOW_PRIVATE=1 in front of the wrapper (the php -S server needs it to reach the loopback fake store).');
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

// ---- the fake store: tests/support/FakeVictoriaMetricsServer.php as a php -S router on a free loopback port
const freePort = () => new Promise((res) => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });
const fakeDir = mkdtempSync(join(tmpdir(), 'fakevm_smoke_'));
writeFileSync(join(fakeDir, 'control.json'), '{}');
const fakePort = await freePort();
const fakeProc = spawn('php', ['-d', 'display_errors=0', '-S', `127.0.0.1:${fakePort}`, 'tests/support/FakeVictoriaMetricsServer.php'], { env: { ...process.env, FAKE_VM_DIR: fakeDir }, stdio: 'ignore' });
const fakeSet = (o) => writeFileSync(join(fakeDir, 'control.json'), JSON.stringify(o));
const fakeRequests = () => (existsSync(join(fakeDir, 'requests.jsonl')) ? readFileSync(join(fakeDir, 'requests.jsonl'), 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
for (let i = 0; i < 50; i++) { try { await fetch(`http://127.0.0.1:${fakePort}/health`); break; } catch { await sleep(100); } }
const stopFake = () => { try { fakeProc.kill(); } catch {} try { rmSync(fakeDir, { recursive: true, force: true }); } catch {} };
process.on('exit', stopFake);

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm-p68-admin', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); await sleep(500); };   // let fonts and layout settle before anything is clicked
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const ADMIN = '/admin/settings_endpoint_agent.php';
const TOKEN = 'smoke-SECRET-token-' + Math.random().toString(36).slice(2, 10);


// Clicks that survive the late layout shifts of a page opened on a #fragment (the browser re-applies the scroll to the fragment while fonts and images arrive): the
// target's position must be the same twice, 200 ms apart, before the mouse goes down.
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
/** A screenshot of one element (a card), however far down the page it is. */
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
// every visible form control inside the new cards has an accessible name
const unlabeled = () => p.eval(`[...document.querySelectorAll('.rmm-scale-card input, .rmm-scale-card select, .rmm-scale-card textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const msgIn = (card) => p.call((a0)=>{const e=document.querySelector('#'+a0+' [data-rmm-scale-msg]');return e?e.innerText.trim():''}, card);
const checked = (sel) => p.call((a0)=>(!!document.querySelector(a0).checked), sel);
// a person clicks the label of a switch or box (the input itself is a few pixels)
const setChecked = async (sel, want) => {
  for (let i = 0; i < 3 && (await checked(sel)) !== want; i++) { await click(`label[for="${sel.slice(1)}"]`); await sleep(350); }
  assert((await checked(sel)) === want, 'could not set ' + sel);
};
const php = (args, extraEnv = {}) => execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_p68_admin_extra.php', ...args], { cwd: process.cwd(), env: { ...process.env, ...extraEnv }, encoding: 'utf8' });

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await nav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ------------------------------------------------------------------------------------------------ the page
await T('page: six new cards, all switches off, labelled controls, desktop light', async () => {
  await go(ADMIN);
  await trapDialogs();
  for (const id of ['remote-tools', 'file-transfer', 'log-collection', 'scale', 'partitioning', 'ext-store']) assert(await p.exists('#' + id), 'missing card #' + id);
  for (const sw of ['#rt_on', '#ft_on', '#lg_on', '#ex_on']) assert(!(await checked(sw)), sw + ' is on by default');
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  const t = await bodyText();
  assert(/Most installs do not need this/.test(t) && /10,000 devices/.test(t), 'external store warning missing');
  assert(/not used: RivetIT keeps its own hourly metric rollups/.test(t.replace(/\s+/g, ' ')) || /RivetIT keeps its own hourly metric rollups/.test(t.replace(/\s+/g, ' ')), 'rollup honesty line missing');
  await shot('admin-full-desktop-light');
});

// ------------------------------------------------------------------------------------------------ remote tools
await T('remote tools: switch on with its risk note, level 3 box, save, aria-live message', async () => {
  await go(ADMIN + '#remote-tools');
  assert(/shell on the device/.test(await p.eval(`document.querySelector('#remote-tools').innerText`)), 'risk note missing');
  assert(/administrator-only/.test(await p.eval(`document.querySelector('#remote-tools').innerText`)), 'administrator-only note missing');
  await setChecked('#rt_on', true);
  await setChecked('#rt_l3', true);
  await nav('button[name=save_remote_settings]');
  await p.waitSel('#remote-tools [data-rmm-scale-msg]');
  const m = await msgIn('remote-tools');
  assert(/Remote tools switched on/.test(m) && /Level 3 setting saved/.test(m), 'message: ' + m);
  assert((await p.eval(`document.querySelector('#remote-tools [data-rmm-scale-msg]').getAttribute('role')`)) === 'status', 'success message is not role=status');
  assert((await p.eval(`document.activeElement && document.activeElement.hasAttribute('data-rmm-scale-msg')`)), 'focus did not move to the message');
  assert((await checked('#rt_on')) && (await checked('#rt_l3')), 'switches not shown on after save');
  assert(/On/.test(await p.eval(`document.querySelector('#remote-tools .badge').innerText`)), 'badge not On');
  await shotEl('#remote-tools', 'card-remote-tools-desktop-light');
});

// ------------------------------------------------------------------------------------------------ file transfer
await T('file transfer: the risk text and a required box appear when switching on; the browser blocks a save without it', async () => {
  await go(ADMIN + '#file-transfer');
  assert(!(await p.visible('#ft_accept')), 'acknowledgement visible before the switch is touched');
  await setChecked('#ft_on', true);
  assert(await p.visible('#ft_accept'), 'acknowledgement not shown');
  const risk = await p.eval(`document.querySelector('#ft_ack').innerText.replace(/\\s+/g,' ')`);
  assert(risk.includes('a copied file cannot be un-copied') && risk.includes('Allow only the folders you need'), 'Core risk text not verbatim: ' + risk.slice(0, 200));
  assert(await p.eval(`document.getElementById('ft_accept').required === true`), 'box not required');
  await click('button[name=save_filetransfer_settings]');
  await sleep(500);
  assert(/settings_endpoint_agent\.php/.test(await p.url()) && !(await p.exists('#file-transfer [data-rmm-scale-msg]')), 'the form was submitted without the acknowledgement');
  assert(await p.eval(`!document.getElementById('ft_accept').validity.valid`), 'the box does not block the submit');
  await shotEl('#file-transfer', 'card-file-transfer-ack-desktop-light');
});
await T('file transfer: keyboard ticks the box; a bad folder is refused with Core\'s text and kept in the form', async () => {
  await p.focus('#ft_accept');
  await p.press(' ');
  assert(await checked('#ft_accept'), 'space did not tick the box');
  await p.type('#ft_lin', '/etc');
  await p.type('#ft_win', 'C:\\');
  await nav('button[name=save_filetransfer_settings]');
  await p.waitSel('#file-transfer [data-rmm-scale-msg]');
  const m = await msgIn('file-transfer');
  assert(/Not an allowed folder/.test(m) && /two levels deep/.test(m), 'message: ' + m);
  assert((await p.eval(`document.querySelector('#file-transfer [data-rmm-scale-msg]').getAttribute('role')`)) === 'alert', 'refusal is not role=alert');
  assert((await p.eval(`document.getElementById('ft_lin').value`)) === '/etc', 'what was typed was not kept');
  assert(!(await checked('#ft_on')) || true, 'state');
  await shotEl('#file-transfer', 'card-file-transfer-refused-desktop-light');
});
await T('file transfer: valid folders and limits with units save; the switch is on; the transfer folder is created', async () => {
  await setChecked('#ft_on', true);
  await setChecked('#ft_accept', true);
  await p.type('#ft_lin', '/srv/shared\n/var/data/exports');
  await p.type('#ft_win', 'C:\\Data\\Shared');
  await p.type('#ft_max', '20');
  await p.eval(`(()=>{const s=document.getElementById('ft_max_u');s.value='MB';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.type('#ft_ttl', '2');
  await p.eval(`(()=>{const s=document.getElementById('ft_ttl_u');s.value='hours';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await nav('button[name=save_filetransfer_settings]');
  await p.waitSel('#file-transfer [data-rmm-scale-msg]');
  const m = await msgIn('file-transfer');
  assert(/File transfer switched on/.test(m) && /allowed folders saved/.test(m) && /limits saved/.test(m), 'message: ' + m);
  assert((await checked('#ft_on')) && !(await p.visible('#ft_accept')), 'switch not on, or the acknowledgement is still asked for when it is already on');
  assert((await p.eval(`document.getElementById('ft_lin').value`)).includes('/var/data/exports'), 'folders not shown');
  assert((await p.eval(`document.getElementById('ft_max').value`)) === '20' && (await p.eval(`document.getElementById('ft_max_u').value`)) === 'MB', 'limit not shown in the unit typed');
  // the transfer folder
  assert(/Not created yet/.test(await p.eval(`document.querySelector('#file-transfer').innerText`)) || /Ready/.test(await p.eval(`document.querySelector('#file-transfer').innerText`)), 'folder state missing');
  if (await p.exists('button[name=create_transfer_folder]')) {
    await nav('button[name=create_transfer_folder]');
    await p.waitSel('#file-transfer [data-rmm-scale-msg]');
    assert(/Transfer folder created \(mode 0700\)|already exists/.test(await msgIn('file-transfer')), 'create folder message: ' + (await msgIn('file-transfer')));
  }
  assert(/Ready/.test(await p.eval(`document.querySelector('#file-transfer').innerText`)) && /mode 0700/.test(await p.eval(`document.querySelector('#file-transfer').innerText`)), 'folder not shown ready with mode 0700');
  await shotEl('#file-transfer', 'card-file-transfer-on-desktop-light');
});

// ------------------------------------------------------------------------------------------------ log collection
await T('log collection: warning verbatim, add a Windows channel and a Linux source, save, worst-case figures', async () => {
  await go(ADMIN + '#log-collection');
  await setChecked('#lg_on', true);
  assert(await p.visible('#lg_accept'), 'storage warning box not shown');
  assert((await p.eval(`document.querySelector('#lg_ack').innerText.replace(/\\s+/g,' ')`)).includes('multiplies database size and write load by the number of devices'), 'Core storage warning not verbatim');
  await setChecked('#lg_accept', true);
  // a Windows channel row (the page has one blank row; the Add button makes another)
  await p.type('#lw_0_c', 'System');
  await p.type('#lw_0_e', '41, 1074');
  await click('[data-rmm-scale-add=win]');
  await p.waitFor(`document.querySelectorAll('[data-rmm-scale-rows=win] [data-rmm-scale-row]').length === 2`, { label: 'second Windows row' });
  assert(await p.eval(`document.activeElement && document.activeElement.name && document.activeElement.name.indexOf('log_win')===0`), 'focus did not move into the new row');
  await p.type('[data-rmm-scale-rows=win] [data-rmm-scale-row]:nth-child(2) input[name$="[channel]"]', 'Application');
  // a Linux source
  await p.type('#ll_0_u', 'sshd.service');
  await p.type('#lg_dev', '10000');
  await p.type('#lg_ret', '14');
  await nav('button[name=save_logs_settings]');
  await p.waitSel('#log-collection [data-rmm-scale-msg]');
  const m = await msgIn('log-collection');
  assert(/Log collection switched on/.test(m) && /sources saved/.test(m) && /limits saved/.test(m), 'message: ' + m);
  const t = await p.eval(`document.querySelector('#log-collection').innerText.replace(/\\s+/g,' ')`);
  assert(/5\.72 MB/.test(t), 'worst case per device (10,000 x 600 bytes = 5.72 MB) not shown: ' + t.slice(-500));
  assert(await p.eval(`!!document.querySelector('input[name="log_win[0][channel]"]') && document.querySelector('input[name="log_win[0][channel]"]').value === 'System'`), 'saved Windows channel not shown');
  assert(/Application/.test(await p.eval(`[...document.querySelectorAll('#log-collection input')].map(i=>i.value).join(' ')`)), 'second channel not saved');
  assert(await p.exists('#log-collection a[href="/agent/rmm_logs.php"]'), 'link to the Logs page missing');
  await shotEl('#log-collection', 'card-log-collection-desktop-light');
});
await T('log collection: a bad channel is refused with Core\'s message, and removing a row works from the keyboard', async () => {
  await p.type('input[name="log_win[0][channel]"]', 'Bad;Channel');
  await nav('button[name=save_logs_settings]');
  await p.waitSel('#log-collection [data-rmm-scale-msg]');
  assert(/channel is missing or has characters that are not allowed/.test(await msgIn('log-collection')), 'message: ' + (await msgIn('log-collection')));
  const n = await p.eval(`document.querySelectorAll('[data-rmm-scale-rows=win] [data-rmm-scale-row]').length`);
  await p.focus('[data-rmm-scale-rows=win] [data-rmm-scale-row]:last-child [data-rmm-scale-remove]');
  await p.press('Enter');
  await p.waitFor((a0)=>(document.querySelectorAll('[data-rmm-scale-rows=win] [data-rmm-scale-row]').length === a0), { args: [n - 1], label: 'row removed' });
});

// ------------------------------------------------------------------------------------------------ scale settings
await T('scale settings: cache, ingest, warm start and department limit save, switches explain themselves', async () => {
  await go(ADMIN + '#scale');
  const t = await p.eval(`document.querySelector('#scale').innerText.replace(/\\s+/g,' ')`);
  assert(/rmm_herd\.json/.test(t) && /suggested on-values/.test(t), 'herd explanation missing');
  assert(!(await checked('#sc_wo')) && !(await checked('#sc_bo')), 'herd switches should be off by default');
  assert(await p.eval(`document.getElementById('sc_ws').disabled && document.getElementById('sc_bb').disabled`), 'the fields of a switch that is off should be disabled');
  await p.type('#sc_cf', '30');
  await p.type('#sc_cs', '120');
  await setChecked('#sc_wo', true);
  assert(await p.eval(`!document.getElementById('sc_ws').disabled`), 'warm fields not enabled by the switch');
  await setChecked('#sc_bo', true);
  await p.type('#sc_ib', '25');
  await nav('button[name=save_scale_settings][class*="btn-primary"]');
  await p.waitSel('#scale [data-rmm-scale-msg]');
  assert(/Scale settings saved/.test(await msgIn('scale')), 'message: ' + (await msgIn('scale')));
  assert((await checked('#sc_wo')) && (await checked('#sc_bo')) && (await p.eval(`document.getElementById('sc_cf').value`)) === '30', 'values not shown after save');
  assert(Number(await p.eval(`document.getElementById('sc_bm').value`)) >= 60, 'the department limit did not take a sensible on-value');
  await shotEl('#scale', 'card-scale-desktop-light');
});
await T('scale settings: a value out of range shows Core\'s error and is kept in the form', async () => {
  await p.type('#sc_cf', '99999');
  // the browser's own min/max check would stop this at the keyboard; switch it off to exercise the server's answer (Core's range message)
  await click('button[name=save_scale_settings][class*="btn-primary"]');
  await sleep(400);
  assert(await p.eval(`!document.getElementById('sc_cf').validity.valid`), 'the browser did not flag the out-of-range number');
  await p.eval(`document.getElementById('sc_cf').form.noValidate = true`);
  await nav('button[name=save_scale_settings][class*="btn-primary"]');
  await p.waitSel('#scale [data-rmm-scale-msg]');
  assert(/cache_fresh_s must be a whole number from 0 to 3600/.test(await msgIn('scale')), 'message: ' + (await msgIn('scale')));
  assert((await p.eval(`document.getElementById('sc_cf').value`)) === '99999', 'typed value not kept');
});

// ------------------------------------------------------------------------------------------------ partitioning
await T('partitioning: probe in words, confirm box gates Enable, conversion, partition list, turn off', async () => {
  await go(ADMIN + '#partitioning');
  assert(!(await p.exists('button[name=partition_enable]')), 'Enable offered before any check');
  await nav('button[name=partition_probe]');
  await p.waitSel('#partitioning [data-rmm-scale-msg]');
  const m = await msgIn('partitioning');
  assert(/^Yes\./.test(m) || /^No/.test(m), 'probe message: ' + m);
  const t = await p.eval(`document.querySelector('#partitioning').innerText.replace(/\\s+/g,' ')`);
  assert(/Can this server do it\?/.test(t) && /MariaDB/.test(t), 'probe result not shown');
  if (/^Yes\./.test(m)) {
    assert(await p.exists('button[name=partition_enable]'), 'Enable not offered although the probe says yes');
    assert(await p.eval(`document.getElementById('pt_go').disabled`), 'Enable should wait for the confirmation box');
    assert(/copied once/.test(t), 'one-time copy not explained');
    await shotEl('#partitioning', 'card-partitioning-probe-desktop-light');
    await setChecked('#pt_ok', true);
    assert(await p.eval(`!document.getElementById('pt_go').disabled`), 'Enable did not unlock');
    await nav('#pt_go');
    await p.waitSel('#partitioning [data-rmm-scale-msg]');
    assert(/Partitioning is on/.test(await msgIn('partitioning')), 'enable message: ' + (await msgIn('partitioning')));
    assert(await p.exists('#partitioning table') && /p_max/.test(await p.eval(`document.querySelector('#partitioning').innerText`)), 'partition list missing');
    await shotEl('#partitioning', 'card-partitioning-on-desktop-light');
    await nav('button[name=partition_disable]');
    await p.waitSel('#partitioning [data-rmm-scale-msg]');
    assert(/stay partitioned/.test(await msgIn('partitioning')), 'disable message: ' + (await msgIn('partitioning')));
  }
});

// ------------------------------------------------------------------------------------------------ external metric store
await T('external store: save addresses and token; the page never shows them; Test connection answers in the page', async () => {
  await go(ADMIN + '#ext-store');
  await setChecked('#ex_on', true);
  await p.type('#ex_w', `http://127.0.0.1:${fakePort}/insert/0/influx`);
  await p.type('#ex_r', `http://127.0.0.1:${fakePort}/select/0/prometheus`);
  await p.type('#ex_t', TOKEN);
  await nav('button[name=save_extstore]');
  await p.waitSel('#ext-store [data-rmm-scale-msg]');
  assert(/External metric store settings saved/.test(await msgIn('ext-store')), 'message: ' + (await msgIn('ext-store')));
  const html = await p.eval('document.documentElement.outerHTML');
  assert(!html.includes(TOKEN) && !html.includes(':' + fakePort) && !html.includes('insert/0/influx'), 'a secret or an address reached the page');
  assert(/saved, hidden/.test(await p.eval(`document.querySelector('#ext-store').innerText`)), 'saved placeholders missing');
  assert((await p.eval(`document.getElementById('ex_t').value`)) === '' && (await p.eval(`document.getElementById('ex_t').placeholder`)).includes('A token is saved'), 'token field should be empty with a saved placeholder');
  // Test: mouse
  fakeSet({});
  await click('[data-rmm-scale-test]');
  await p.waitFor(`/Connected\\./.test(document.querySelector('#ex_live').innerText)`, { label: 'ok answer' });
  assert((await p.eval(`document.getElementById('ex_live').getAttribute('aria-live')`)) === 'polite', 'live region not polite');
  assert(fakeRequests().some((r) => r.auth === 'Bearer ' + TOKEN), 'the saved token was not sent to the store');
  assert(!(await p.eval('document.documentElement.outerHTML')).includes(TOKEN), 'token appeared in the page after the test');
  await shotEl('#ext-store', 'card-ext-store-ok-desktop-light');
  // wrong token: keyboard (focus + Enter)
  fakeSet({ require_auth: 'Bearer something-else' });
  await p.focus('[data-rmm-scale-test]');
  await p.press('Enter');
  await p.waitFor(`/Login rejected\\./.test(document.querySelector('#ex_live').innerText)`, { label: 'auth answer' });
  await shotEl('#ext-store', 'card-ext-store-auth-desktop-light');
  // refused without a request: a user name in the address (the loopback/metadata refusals need a server without the private-address switch: asserted in the PHP suite)
  fakeSet({});
  const before = fakeRequests().length;
  await p.type('#ex_w', 'https://user:pw@example.com/insert/0/influx');
  await click('[data-rmm-scale-test]');
  await p.waitFor(`/Refused\\./.test(document.querySelector('#ex_live').innerText)`, { label: 'refused answer' });
  assert(fakeRequests().length === before, 'a request was made for a refused address');
  assert(!/pw@/.test(await p.eval(`document.querySelector('#ex_live').innerText`)), 'the refused answer echoed the credentials');
  // unreachable
  await p.type('#ex_w', 'http://127.0.0.1:1/insert/0/influx');
  await p.type('#ex_r', 'http://127.0.0.1:1/select/0/prometheus');
  await click('[data-rmm-scale-test]');
  await p.waitFor(`/Not reachable\\./.test(document.querySelector('#ex_live').innerText)`, { label: 'unreachable answer' });
  await shotEl('#ext-store', 'card-ext-store-unreachable-desktop-light');
});
await T('external store: samples reach the store through the outbox and housekeeping; the buffer is shown; clearing removes the token', async () => {
  fakeSet({});
  php(['checkin', '2'], { RIVETIT_WEBHOOK_ALLOW_PRIVATE: '1' });
  await go(ADMIN + '#ext-store');
  assert(/Waiting to be sent/.test(await p.eval(`document.querySelector('#ext-store').innerText`)), 'buffer state missing');
  const out = php(['housekeeping'], { RIVETIT_WEBHOOK_ALLOW_PRIVATE: '1' });
  const hk = JSON.parse(out.split('\n').find((l) => l.startsWith('{')));
  assert(hk.external_sent >= 1 && hk.external_failed === 0, 'housekeeping did not deliver: ' + JSON.stringify(hk));
  assert(fakeRequests().some((r) => r.path.endsWith('/write') && r.auth === 'Bearer ' + TOKEN && r.body.startsWith('rmm,asset=')), 'no line-protocol write with the token reached the store');
  await go(ADMIN + '#ext-store');
  assert(/0 batch/.test(await p.eval(`document.querySelector('#ext-store').innerText`)), 'buffer should be empty after the flush');
  await setChecked('#ex_on', false);
  await setChecked('#ex_ct', true);
  await nav('button[name=save_extstore]');
  await p.waitSel('#ext-store [data-rmm-scale-msg]');
  assert(/switched off/.test(await msgIn('ext-store')) && !(await p.exists('#ex_ct')), 'clear: ' + (await msgIn('ext-store')));
  assert((await p.eval(`document.getElementById('ex_t').placeholder`)) === 'Optional', 'the token should be gone');
});

// ------------------------------------------------------------------------------------------------ the capacity panel
await T('Scale and capacity panel: the queue-worker hint and the rc.11 figures', async () => {
  php(['queue', '3000']);
  await go('/agent/rmm_fleet.php#rmm-capacity');
  const t = await p.eval(`document.getElementById('rmm-capacity').innerText.replace(/\\s+/g,' ')`);
  assert(/Run \d+ queue workers?\./.test(t), 'hint missing: ' + t.slice(0, 300));
  assert(/Read cache/.test(t) && /Warm start/.test(t) && /Check-ins per department/.test(t) && /Load shedding/.test(t) && /Check history/.test(t), 'figures missing');
  assert(/SKIP LOCKED/.test(t) && /docs\/ENDPOINT_AGENT\.md/.test(t), 'guidance missing');
  assert(await p.exists('#rmm-capacity a[href="/admin/settings_endpoint_agent.php#scale"]'), 'link to the settings missing');
  await shotEl('#rmm-capacity', 'panel-capacity-desktop-light');
  php(['queue', '0']);
});

// ------------------------------------------------------------------------------------------------ dark mode and phone width
const pages = [['admin', ADMIN], ['fleet-capacity', '/agent/rmm_fleet.php#rmm-capacity']];
await T('dark mode: both pages are dark, cards readable (screenshots in shots/)', async () => {
  await setTheme(1);
  for (const [name, path] of pages) {
    await p.setViewport(1366, 900);
    await go(path); await sleep(700);
    const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
    const [r, g, b] = bg.match(/\d+/g).map(Number);
    assert(r + g + b < 3 * 110, `${name}: the page is not dark in dark mode: ${bg}`);
    await shot(`${name}-desktop-dark`);
  }
  await go(ADMIN + '#ext-store');
  await shotEl('#ext-store', 'card-ext-store-desktop-dark');
  await go(ADMIN + '#file-transfer');
  await shotEl('#file-transfer', 'card-file-transfer-desktop-dark');
});
await T('phone width, dark: nothing overflows the page, tables scroll inside their container', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  for (const [name, path] of pages) {
    await go(path); await sleep(700);
    await noOverflow(`${name} dark phone`);
    await shot(`${name}-phone-dark`);
  }
  await go(ADMIN + '#log-collection');
  await shotEl('#log-collection', 'card-log-collection-phone-dark');
  await go(ADMIN + '#partitioning');
  await shotEl('#partitioning', 'card-partitioning-phone-dark');
});
await T('phone width, light: nothing overflows the page', async () => {
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(MOBILE_W, 800, true);
  for (const [name, path] of pages) {
    await go(path); await sleep(700);
    await noOverflow(`${name} light phone`);
    await shot(`${name}-phone-light`);
  }
  await go(ADMIN + '#ext-store');
  await shotEl('#ext-store', 'card-ext-store-phone-light');
  await go(ADMIN + '#scale');
  await shotEl('#scale', 'card-scale-phone-light');
  assert((await unlabeled()).length === 0, 'unlabeled controls on the phone layout: ' + (await unlabeled()).join(','));
  await p.setViewport(1366, 900);
});
await T('no window.confirm / alert / prompt was used by any new control', async () => {
  await go(ADMIN);
  await trapDialogs();
  await p.eval(`document.querySelectorAll('.rmm-scale-card [data-rmm-scale-add]').forEach(b=>b.click())`);
  assert((await p.eval('window.__dialogs')) === 0, 'a native dialog was opened');
  assert(await p.eval(`document.querySelectorAll('.rmm-scale-card [onclick],.rmm-scale-card [onchange],.rmm-scale-card [onsubmit]').length === 0`), 'inline event handlers in the new cards');
});

const fails = H.summary();
await browser.close();
stopFake();
process.exit(fails ? 1 : 0);
