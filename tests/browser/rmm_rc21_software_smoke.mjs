// Browser smoke for the RMM software deployment pages (RivetCore rc.21, Phase 5): the deployment block of the Software tab of the asset page, the Software catalog, Software assignments and
// Software report pages, the CSV and the Administration card.
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=<file written by tests/browser/rmm_seed.php> node tests/browser/rmm_rc21_software_smoke.mjs
//
// Same driver, harness and safety rules as rmm_rc20_patch_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, and every check also fails on
// any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx while it ran. The forms are filled and submitted like a person would (the catalog, the host list, the
// assignment, the dry run and the real run are all created through the pages). Behind the scenes only the fixtures of tests/browser/rmm_rc21_software_extra.php run: the software_deploy
// switch on (what an administrator does in Administration), two devices that answer a detection, a dry run or an install through the real device endpoints, and a login for the Dept B technician.
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
  console.error(`Refusing to run against ${BASE}: the smoke creates and deletes rules; it is meant for a throwaway install.`);
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
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await p.clickNav('button[name=edit_your_user_preferences]');
};
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
const setVal = (sel, v) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)throw new Error('no ${sel}');e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return e.value})()`);
const unlabeled = () => p.eval(`[...document.querySelectorAll('main input, main select, main textarea, .page-body input, .page-body select, .page-body textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const flash = async (re, label) => {
  try {
    await p.waitFor(`/${re.source}/i.test(document.body.innerText)`, { label: label || String(re) });
  } catch (e) {
    const alerts = await p.eval(`[...document.querySelectorAll('.alert, .toast, [role=alert]')].map(a=>a.innerText.trim()).filter(Boolean).join(' | ')`);
    throw new Error(`${e.message}; alerts on the page: ${alerts.slice(0, 400)}; url ${await p.url()}`);
  }
};
const pad = (n) => String(n).padStart(2, '0');
const utcInput = (offsetMin) => { const d = new Date(Date.now() + offsetMin * 60000); return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`; };
const extra = (mode, ...more) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_rc21_software_extra.php', process.env.SEED_JSON, mode, ...more], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
const login = async (email, password) => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page for ' + email);
};
const focused = () => p.eval(`(()=>{const a=document.activeElement;return a?((a.getAttribute('aria-label')||a.getAttribute('title')||a.innerText||a.id||a.tagName)+'').trim().slice(0,60):''})()`);
let fx = null;
const dialogGo = async () => { await p.waitSel('#rmmAutoConfirmGo'); await p.click('#rmmAutoConfirmGo'); };

await T('login: real form signs in', async () => {
  await login(EMAIL, PASSWORD);
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('setup: software deployment switched on, two devices enrolled, the three software pages are in the menu', async () => {
  fx = extra('setup');
  assert(fx.dev['SSMOKE-LNX'] > 0 && fx.dev['SSMOKE-B'] > 0, 'devices not enrolled: ' + JSON.stringify(fx));
  await go('/agent/rmm_fleet.php');
  assert(await p.exists('a[href="/agent/rmm_software.php"]') && await p.exists('a[href="/agent/rmm_software_assignments.php"]') && await p.exists('a[href="/agent/rmm_software_report.php"]'), 'Software menu entries missing');
});

// ------------------------------------------------------------------------------------------------ the catalog
await T('catalog: a package is created through the form, a hostile version is refused with words and keeps what was typed, a good apt version is added', async () => {
  await go('/agent/rmm_software.php');
  await trapDialogs();
  assert(/The catalog is empty/.test(await bodyText()), 'empty state: ' + (await bodyText()).slice(0, 200));
  await p.click('a[href="/agent/rmm_software.php?new=1"]');
  await p.waitSel('#pkg-form');
  await p.type('#pk_name', 'Smoke htop');
  await p.type('#pk_pub', 'Debian');
  await p.type('#pk_desc', 'a process viewer <b>bold</b>');
  assert((await unlabeled()).length === 0, 'unlabeled controls in the package form: ' + (await unlabeled()).join(','));
  await shot('catalog-new-desktop-light');
  await p.clickNav('#pkg-form button[type=submit]');
  await flash(/Package created/, 'package created');
  await p.waitSel('#ver-form');
  assert(!(await p.visible('#vf_url')) && !(await p.visible('#vf_sha')), 'Windows-only fields should be hidden for an apt version');
  assert(await p.visible('#vf_pkg'), 'the package name field should show for apt');
  // a hostile package name: refused by Core with words, the typed text stays in the field and is never markup
  await p.type('#vf_ver', '3.3.0');
  await p.type('#vf_pkg', '--allow-unauthenticated; rm -rf /');
  await p.eval(`document.querySelector('#vf_pkg').removeAttribute('pattern')`);
  await p.clickNav('#ver-form button[type=submit]');
  await p.waitFor(`/package name in lower case/i.test(document.body.innerText)`, { label: 'refusal words' });
  assert((await p.eval(`document.querySelector('#vf_pkg').value`)) === '--allow-unauthenticated; rm -rf /' && (await p.eval(`document.querySelectorAll('main b, .page-body b').length`)) === 0, 'the typed name was lost or became markup');
  await p.type('#vf_pkg', 'htop', { clear: true });
  await p.clickNav('#ver-form button[type=submit]');
  await flash(/Version added/, 'version added');
  const t = await p.eval(`document.getElementById('versions').innerText`);
  assert(/3\.3\.0/.test(t) && /htop/.test(t) && /never edited/.test(t), 'versions card: ' + t.slice(0, 300));
  assert(!(await p.exists('#versions form input[name=version_id]:not([type=hidden])')) , 'a version has an edit field');
  assert((await p.eval(`document.querySelector('#pk_desc') ? document.querySelector('#pk_desc').value : ''`)).includes('<b>bold</b>'), 'the description was not kept as text');
  await shot('catalog-package-desktop-light');
});
await T('catalog: format switch shows the Windows fields, an MSI with a pinned hash is added, the host list and the retire dialog work', async () => {
  await go('/agent/rmm_software.php?new=1');
  await p.waitSel('#pkg-form');
  await p.type('#pk_name', 'Smoke Agent MSI');
  await p.clickNav('#pkg-form button[type=submit]');
  await flash(/Package created/, 'package created');
  await p.waitSel('#vf_fmt');
  await setVal('#vf_fmt', 'msi');
  await p.waitSel('#vf_url');
  assert(!(await p.visible('#vf_pkg')) && await p.visible('#vf_sha') && await p.visible('#vf_code') && !(await p.visible('#vf_args')), 'field visibility for msi');
  await setVal('#vf_fmt', 'exe');
  assert(await p.visible('#vf_args') && !(await p.visible('#vf_code')), 'field visibility for exe');
  await setVal('#vf_fmt', 'msi');
  await p.type('#vf_ver', '2.4.1');
  await p.type('#vf_url', 'https://downloads.example.com/app-2.4.1.msi');
  await p.type('#vf_sha', 'ab'.repeat(32));
  await p.type('#vf_size', '1048576');
  await p.type('#vf_code', '{12345678-ABCD-1234-ABCD-1234567890AB}');
  await p.clickNav('#ver-form button[type=submit]');
  await flash(/Version added/, 'msi version added');
  const t = await p.eval(`document.getElementById('versions').innerText`);
  assert(/downloads\.example\.com\/app-2\.4\.1\.msi/.test(t) && /abababababababab/.test(t) && /1 MB/.test(t), 'msi version content: ' + t.slice(0, 300));
  // a host that is not allowed (the page was reloaded after the save, so the format is back to apt)
  await setVal('#vf_fmt', 'msi');
  await p.waitSel('#vf_url');
  await p.type('#vf_ver', '2.4.2');
  await p.type('#vf_url', 'https://evil.example.net/app.msi');
  await p.type('#vf_sha', 'cd'.repeat(32));
  await p.type('#vf_size', '10');
  await p.type('#vf_code', '{12345678-ABCD-1234-ABCD-1234567890AC}');
  await p.clickNav('#ver-form button[type=submit]');
  await p.waitFor(`/not on the list of hosts/i.test(document.body.innerText)`, { label: 'host refusal words' });
  // retire asks first
  await p.waitSel('#versions form button.btn-outline-secondary');
  await p.click('#versions form button.btn-outline-secondary');
  await p.waitSel('#rmmAutoConfirmGo');
  assert(/never installed again/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'the retire dialog does not say what it does');
  await dialogGo();
  await flash(/Version retired/, 'retired');
  assert(/Retired/.test(await p.eval(`document.getElementById('versions').innerText`)), 'retired pill missing');
  // the host list: add and see
  await go('/agent/rmm_software.php');
  await p.waitSel('#hosts');
  assert(/downloads\.example\.com/.test(await p.eval(`document.getElementById('hosts').innerText`)), 'the seeded host is missing');
  await p.type('#ho_host', 'cdn.example.org');
  await p.type('#ho_note', 'second vendor');
  await p.clickNav('#hosts form button.btn-primary');
  await flash(/Host allowed/, 'host allowed');
  assert(/cdn\.example\.org/.test(await bodyText()), 'new host not listed');
  await shot('catalog-desktop-light');
});

// ------------------------------------------------------------------------------------------------ assignments
await T('assignments: choose a package, assign it to Dept A, see it, remove it (the dialog asks first)', async () => {
  await go('/agent/rmm_software_assignments.php');
  await trapDialogs();
  assert(/Nothing is assigned/.test(await bodyText()) && /Rings spread a rollout/.test(await bodyText()), 'empty state / ring note');
  await setVal('#as_pick', String(await p.eval(`[...document.querySelectorAll('#as_pick option')].find(o=>/Smoke htop/.test(o.textContent)).value`)));
  await p.clickNav('form[method=get] button[type=submit]');
  await p.waitSel('#as_action');
  assert(/3\.3\.0/.test(await p.eval(`document.getElementById('as_ver').innerText`)), 'the version list is missing');
  await setVal('#asg_type', 'client');
  await p.waitSel('#asg_id_client');
  await setVal('#asg_id_client', String(await p.eval(`[...document.querySelectorAll('#asg_id_client option')].find(o=>/Dept A/.test(o.textContent)).value`)));
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  await shot('assignments-form-desktop-light');
  await p.clickNav('#assign form[method=post] button[type=submit]');
  await flash(/Assigned\. Devices in the scope/, 'assigned');
  let t = await bodyText();
  assert(/Smoke htop/.test(t) && /Dept A/.test(t) && /Live/.test(t), 'assignment row: ' + t.slice(0, 300));
  await shot('assignments-desktop-light');
  await p.click('#assignments button.btn-outline-danger');
  await p.waitSel('#rmmAutoConfirmGo');
  assert(/Nothing is uninstalled/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'the dialog does not say removing uninstalls nothing');
  await p.eval(`document.querySelector('#rmmAutoConfirm .btn-outline-secondary').click()`);
  assert(/Smoke htop/.test(await p.eval(`document.getElementById('assignments').innerText`)), 'cancelling the dialog removed the assignment');
});

// ------------------------------------------------------------------------------------------------ the device block
await T('asset page: the Software tab carries the deployment block; Detect now queues a detection and the answer turns the package Missing', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}#rmm-software`);
  await trapDialogs();
  await p.waitFor(`document.getElementById('rmm-tab-software') && document.getElementById('rmm-tab-software').getAttribute('aria-selected') === 'true'`, { label: 'Software tab from the hash' });
  assert(await p.visible('#rmm-deployment'), 'deployment block hidden');
  await p.waitSel('#rmm-deployment form button');
  await p.eval(`[...document.querySelectorAll('#rmm-deployment form button')].find(b=>/Detect now/.test(b.innerText)).click()`);
  await flash(/Detection queued/, 'detect flash');
  const a = extra('answer', 'SSMOKE-LNX', 'missing');
  assert(a.type === 'software_detect' && a.rules === 1, 'the device was not offered the detection: ' + JSON.stringify(a));
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}#rmm-software`);
  await p.waitSel('#rmm-deployment table');
  const t = await p.eval(`document.getElementById('rmm-deployment').innerText`);
  assert(/Smoke htop/.test(t) && /Missing/.test(t) && /not installed/.test(t), 'block content: ' + t.slice(0, 400));
  assert((await p.eval(`document.querySelectorAll('#rmm-deployment script, #rmm-deployment img').length`)) === 0, 'an element was injected');
  const own = await p.eval(`[...document.querySelectorAll('#rmm-deployment input, #rmm-deployment select')].filter(e=>{if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;return !((e.labels&&e.labels.length)||e.getAttribute('aria-label'))}).map(e=>e.name||e.id)`);
  assert(own.length === 0, 'unlabeled controls in the deployment block: ' + own.join(','));
  assert(await p.exists('#rmm-deployment table caption.visually-hidden'), 'table caption missing');
  await noOverflow('Software tab (desktop)');
  await shot('device-block-desktop-light');
});
await T('asset page: a dry run from the row shows "Would install" in the history once the device answers', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}#rmm-software`);
  await p.waitSel('#rmm-deployment table');
  await p.eval(`[...document.querySelectorAll('#rmm-deployment table form button')].find(b=>/Dry run/.test(b.innerText)).click()`);
  await flash(/Dry run queued/, 'dry run flash');
  const a = extra('answer', 'SSMOKE-LNX');
  assert(a.type === 'software_install' && a.mode === 'dry_run' && a.pkg === 'htop', 'dry run not offered as expected: ' + JSON.stringify(a));
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}#rmm-software`);
  await p.waitSel('#rmm-deployment details');
  await p.eval(`[...document.querySelectorAll('#rmm-deployment details')].forEach(d=>{d.open=true})`);
  const t = await p.eval(`document.getElementById('rmm-deployment').innerText`);
  assert(/Install history/.test(t) && /Would install/.test(t) && /Dry run/.test(t), 'history: ' + t.slice(0, 300));
});
await T('asset page: Install / remove now needs the confirmation box (the button stays off until ticked) and the dialog; then the device installs and the package is Compliant', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}#rmm-software`);
  await p.waitSel('#sw-run-form');
  await p.eval(`document.querySelector('#sw-run-form').closest('details').open = true`);
  await p.waitSel('#sw-run-form button[value=run]');
  assert(/Smoke htop/.test(await p.eval(`document.querySelector('#sw_pkg option:checked').textContent`)), 'the form should offer the package the device expects first');
  assert(await p.eval(`document.querySelector('#sw-run-form button[value=run]').disabled`), 'the real-run button is enabled before the confirmation box is ticked');
  await p.click('#sw_conf');
  assert(!(await p.eval(`document.querySelector('#sw-run-form button[value=run]').disabled`)), 'the real-run button stayed disabled after ticking');
  await shot('device-run-form-desktop-light');
  await p.click('#sw-run-form button[value=run]');
  await p.waitSel('#rmmAutoConfirmGo');
  assert(/changes software on the machine/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'the dialog does not say what the run does');
  await dialogGo();
  await flash(/Queued: a real installation/, 'run flash');
  const a = extra('answer', 'SSMOKE-LNX');
  assert(a.type === 'software_install' && a.mode === 'run' && a.action === 'install', 'run not offered as expected: ' + JSON.stringify(a));
  extra('answer', 'SSMOKE-LNX', 'present');   // (no further job; harmless)
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}#rmm-software`);
  await p.waitSel('#rmm-deployment table');
  const t = await p.eval(`document.getElementById('rmm-deployment').innerText`);
  assert(/Compliant/.test(t) && /Installed/.test(t), 'block after the install: ' + t.slice(0, 400));
  await shot('device-compliant-desktop-light');
});

// ------------------------------------------------------------------------------------------------ the report
await T('report: grouped by package / department / device, filters, CSV link downloads text/csv; no horizontal scroll on a phone', async () => {
  await go('/agent/rmm_software_report.php');
  await trapDialogs();
  let t = await bodyText();
  assert(/Software report/.test(t) && /Smoke htop/.test(t) && /Compliant/.test(t) && /Download CSV/.test(t), 'content: ' + t.slice(0, 300));
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  await noOverflow('report (desktop)');
  await shot('report-desktop-light');
  await setVal('#sr_by', 'device');
  await p.clickNav('form[action="/agent/rmm_software_report.php"] button[type=submit]');
  t = await bodyText();
  assert(/SSMOKE-LNX/.test(t) && /Compliant/.test(t), 'by device: ' + t.slice(0, 300));
  assert(await p.exists('a[href*="asset_id=' + fx.asset['SSMOKE-LNX'] + '#rmm-software"]'), 'device link to its Software tab missing');
  const href = await p.eval(`document.querySelector('a[href^="/agent/rmm_software_report_csv.php"]').getAttribute('href')`);
  const csv = await p.eval(`fetch(${JSON.stringify(href)}, { credentials: 'same-origin' }).then(async r => ({ status: r.status, type: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), body: await r.text() }))`);
  assert(csv.status === 200 && /text\/csv/.test(csv.type) && /attachment/.test(csv.disp) && /SSMOKE-LNX/.test(csv.body) && /hostname/.test(csv.body), 'CSV: ' + JSON.stringify(csv).slice(0, 300));
  await p.setViewport(MOBILE_W, 800, true);
  await go('/agent/rmm_software_report.php?group_by=device');
  await noOverflow('report (phone)');
  await shot('report-phone-light');
  await p.setViewport(1366, 900);
});
await T('phone width and dark mode keep their layout on the catalog, assignments and the device block', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  for (const path of ['/agent/rmm_software.php', '/agent/rmm_software_assignments.php']) {
    await go(path);
    await noOverflow(path + ' (phone)');
  }
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}#rmm-software`);
  await p.waitSel('#rmm-deployment');
  await noOverflow('Software tab (phone)');
  await shot('device-block-phone-light');
  await p.setViewport(1366, 900);
  await setTheme('1');
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}#rmm-software`);
  await p.waitSel('#rmm-deployment');
  await shot('device-block-desktop-dark');
  await go('/agent/rmm_software.php');
  await shot('catalog-desktop-dark');
  await go('/agent/rmm_software_report.php');
  await shot('report-desktop-dark');
  await setTheme('0');
});

// ------------------------------------------------------------------------------------------------ administration
await T('administration: the Software deployment card, live installs need the acknowledgement', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await trapDialogs();
  await p.waitSel('#software-deployment');
  assert(/once a day \(a short read-only check\)/.test(await p.eval(`document.getElementById('software-deployment').innerText`)), 'cost text missing');
  await p.eval(`(()=>{const c=document.querySelector('#ea_sd_live');c.scrollIntoView();c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.clickNav('#software-deployment button[name=save_software_settings]');
  await flash(/Tick the acknowledgement|No change to save/, 'live install refusal');
  assert(/Tick the acknowledgement/.test(await bodyText()), 'live installs were not refused without the acknowledgement: ' + (await bodyText()).slice(0, 200));
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#ea_sd_detect');
  await p.type('#ea_sd_detect', '43200', { clear: true });
  await p.clickNav('#software-deployment button[name=save_software_settings]');
  await flash(/Software deployment settings saved/, 'saved');
  assert((await p.eval(`document.querySelector('#ea_sd_detect').value`)) === '43200' && !(await p.eval(`document.querySelector('#ea_sd_live').checked`)), 'saved values');
  await shot('admin-card-desktop-light');
});

// ------------------------------------------------------------------------------------------------ a user limited to Dept B
await T('Dept B technician: sees only Dept B on the report and assignments; the catalog is read only; Dept A device has no deployment block', async () => {
  await login('deptb@scratch.test', 'Scratch-Deptb-1234');
  await go('/agent/rmm_software_report.php?group_by=device');
  let t = await bodyText();
  assert(!/SSMOKE-LNX/.test(t), 'report leaks Dept A: ' + t.slice(0, 300));
  await go('/agent/rmm_software_assignments.php');
  t = await bodyText();
  assert(!/Dept A/.test(await p.eval(`document.getElementById('assignments').innerText`)), 'assignments leak Dept A');
  await go('/agent/rmm_software.php');
  t = await bodyText();
  assert(/only a user with access to every department can change it/.test(t) && !(await p.exists('#pkg-form')) && !(await p.exists('a[href="/agent/rmm_software.php?new=1"]')), 'catalog for a limited user');
  await go(`/agent/asset_details.php?asset_id=${fx.asset['SSMOKE-LNX']}`);
  assert(!(await p.exists('#rmm-deployment')), 'a Dept A device page shows a deployment block to a Dept B user');
  await shot('deptb-catalog-desktop-light');
  await login(EMAIL, PASSWORD);
}, { allowHttp: [/\/agent\/asset_details\.php/, /\/agent\/clients\.php/, /\/agent\/rmm_/] });

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
