// Browser smoke for the RMM patch management pages (RivetCore rc.20, Phase 4): the Patches tab of the asset page, Patch compliance, Patch approvals, Patch policies and the Administration card.
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=<file written by tests/browser/rmm_seed.php> node tests/browser/rmm_rc20_patch_smoke.mjs
//
// Same driver, harness and safety rules as rmm_p23_c_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, and every check also fails on
// any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx while it ran. The forms are filled and submitted like a person would. Behind the scenes only
// the fixtures of tests/browser/rmm_rc20_patch_extra.php run: the patching switch on (what an administrator does in Administration), two devices that answer a scan through
// the real device endpoints, and a login for the Dept B technician.
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
const flash = async (re, label) => p.waitFor(`/${re.source}/i.test(document.body.innerText)`, { label: label || String(re) });
const pad = (n) => String(n).padStart(2, '0');
const utcInput = (offsetMin) => { const d = new Date(Date.now() + offsetMin * 60000); return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`; };
const extra = (mode) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_rc20_patch_extra.php', process.env.SEED_JSON, mode], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
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
const tickByTitle = (text) => p.eval(`(()=>{const cb=[...document.querySelectorAll('input[name="update_ids[]"]')].find(c=>(c.getAttribute('aria-label')||'').includes(${JSON.stringify(text)}));if(!cb)throw new Error('no row ${text}');cb.checked=true;cb.dispatchEvent(new Event('change',{bubbles:true}));return true})()`);

await T('login: real form signs in', async () => {
  await login(EMAIL, PASSWORD);
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('setup: patching switched on, two devices answer a scan through the real endpoints, the three patch pages are in the menu', async () => {
  fx = extra('setup');
  assert(fx.updates === 7, 'scan results not applied: ' + JSON.stringify(fx));
  await go('/agent/rmm_fleet.php');
  assert(await p.exists('a[href="/agent/rmm_patches.php"]') && await p.exists('a[href="/agent/rmm_patch_updates.php"]') && await p.exists('a[href="/agent/rmm_patch_policies.php"]'), 'Patch menu entries missing');
});

// ------------------------------------------------------------------------------------------------ the Patches tab
await T('asset page: #rmm-patches opens the Patches tab with the scan state, the updates and the policy box (desktop, light)', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await trapDialogs();
  await p.waitFor(`document.getElementById('rmm-tab-patches') && document.getElementById('rmm-tab-patches').getAttribute('aria-selected') === 'true'`, { label: 'Patches tab from the hash' });
  assert(await p.visible('#rmm-pane-patches'), 'pane hidden');
  const t = await p.eval(`document.getElementById('rmm-pane-patches').innerText`);
  assert(/Windows Update/.test(t) && /Missing updates/.test(t) && /2026-10 Cumulative Update/.test(t) && /Security Intelligence Update/.test(t) && /Waiting for a decision/.test(t), 'tab content: ' + t.slice(0, 400));
  assert(/No patch policy applies/.test(t), 'the safe-defaults sentence is missing');
  assert(/Compliant/.test(t), 'verdict missing');
  assert((await p.eval(`document.querySelectorAll('#rmm-pane-patches script, #rmm-pane-patches img').length`)) === 0 && /<script>alert\(1\)<\/script>/.test(t), 'the hostile title became an element');
  const own = await p.eval(`[...document.querySelectorAll('#rmm-pane-patches input, #rmm-pane-patches select, #rmm-pane-patches textarea')].filter(e=>{if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;return !((e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title'))}).map(e=>e.name||e.id||e.tagName)`);
  assert(own.length === 0, 'unlabeled controls in the Patches tab: ' + own.join(','));
  assert(await p.exists('#rmm-pane-patches table caption.visually-hidden') && (await p.eval(`document.querySelectorAll('#rmm-pane-patches th[scope=col]').length`)) >= 6, 'table is not accessible');
  await noOverflow('Patches tab (desktop)');
  await shot('patches-tab-desktop-light');
});
await T('asset page: the header checkbox ticks every row, a cleared row un-ticks it; the tab filters keep the tab', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await p.waitSel('input[data-pat-all]');
  await p.click('input[data-pat-all]');
  assert((await p.eval(`[...document.querySelectorAll('input[name="update_ids[]"]')].every(c=>c.checked)`)), 'select-all did not tick every row');
  await p.click('input[name="update_ids[]"]');
  assert(!(await p.eval(`document.querySelector('input[data-pat-all]').checked`)), 'the header box stayed ticked');
  await setVal('#pt_kind', 'driver');
  await p.clickNav('.rmm-pat-filter button[type=submit]');
  await p.waitFor(`document.getElementById('rmm-tab-patches').getAttribute('aria-selected') === 'true'`, { label: 'still on the Patches tab after the filter' });
  const t = await p.eval(`document.getElementById('rmm-pane-patches').innerText`);
  assert(/Intel - Net/.test(t) && !/Cumulative Update/.test(t), 'kind filter: ' + t.slice(0, 300));
});
await T('asset page: approve the ticked update through the real form; the status changes and the flash says so', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await p.waitSel('input[name="update_ids[]"]');
  await tickByTitle('2026-10 Cumulative Update');
  await tickByTitle('Security Intelligence Update');
  await p.clickNav('#pat-dev-form button[name=action][value=approve]');
  await flash(/Approved 2 update row/, 'approve flash');
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}&pt_status=approved#rmm-patches`);
  await p.waitSel('#pat-dev-form');
  const t = await p.eval(`document.getElementById('rmm-pane-patches').innerText`);
  assert(/2026-10 Cumulative Update/.test(t) && /Security Intelligence/.test(t) && !/\.NET 9/.test(t) && /Approved/.test(t), 'approved filter: ' + t.slice(0, 300));
  await shot('patches-approved-desktop-light');
});
await T('asset page: a dry run is queued and, once the device answers, shows in the history', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await p.waitSel('#rmm-pane-patches form button[value=dry_run]');
  await p.clickNav('#rmm-pane-patches form button[value=dry_run]');
  await flash(/Dry run queued for 2 update/, 'dry run flash');
  const a = extra('answer');
  assert(a.mode === 'dry_run' && a.ids === 2, 'the device was not offered the dry run: ' + JSON.stringify(a));
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await p.waitSel('#rmm-pane-patches details');
  await p.eval(`[...document.querySelectorAll('#rmm-pane-patches details')].forEach(d=>{d.open=true})`);
  const t = await p.eval(`document.getElementById('rmm-pane-patches').innerText`);
  assert(/Install history/.test(t) && /Dry run/.test(t) && /Would install/.test(t), 'history: ' + t.slice(0, 300));
});
await T('asset page: Install needs the confirmation box; the dialog asks first; then only the approved updates are queued', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await p.waitSel('#rmm-pane-patches details');
  await p.eval(`document.querySelector('#rmm-pane-patches details summary.fw-semibold, #rmm-pane-patches details summary.small').click()`);
  await p.eval(`[...document.querySelectorAll('#rmm-pane-patches details')].find(d=>/Install for real/.test(d.innerText)).open=true`);
  await p.waitSel('button[name=action][value=install]');
  await p.click('button[name=action][value=install]');
  await p.waitSel('#rmmAutoConfirmGo');
  assert(/changes software on the machine/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'the dialog does not say what the install does');
  await shot('patches-install-dialog-desktop-light');
  await dialogGo();
  await flash(/Tick the confirmation box/, 'refusal without the box');
  await p.eval(`[...document.querySelectorAll('#rmm-pane-patches details')].find(d=>/Install for real/.test(d.innerText)).open=true`);
  await p.waitSel('#pat_conf');
  await p.click('#pat_conf');
  await p.click('button[name=action][value=install]');
  await dialogGo();
  await flash(/Install queued for 2 update/, 'install flash');
  const a = extra('answer');
  assert(a.mode === 'install' && a.ids === 2, 'the device was not offered the install: ' + JSON.stringify(a));
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}&pt_status=installed#rmm-patches`);
  await p.waitSel('#pat-dev-form');
  assert(/Cumulative Update/.test(await p.eval(`document.getElementById('rmm-pane-patches').innerText`)), 'the installed updates are not listed');
});

// ------------------------------------------------------------------------------------------------ compliance
await T('compliance page: totals, department table, links; no horizontal scroll on a phone', async () => {
  await go('/agent/rmm_patches.php');
  await trapDialogs();
  const t = await bodyText();
  assert(/Patch compliance/.test(t) && /Devices/.test(t) && /Never scanned/.test(t) && /Dept A/.test(t) && /Dept B/.test(t), 'content: ' + t.slice(0, 300));
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  await noOverflow('compliance (desktop)');
  await shot('compliance-desktop-light');
  await p.setViewport(MOBILE_W, 800, true);
  await go('/agent/rmm_patches.php');
  await noOverflow('compliance (phone)');
  await shot('compliance-phone-light');
  await p.setViewport(1366, 900);
});

// ------------------------------------------------------------------------------------------------ approvals
await T('approvals page: updates of the fleet, select all, decline across a department with a dialog', async () => {
  await go('/agent/rmm_patch_updates.php');
  await trapDialogs();
  const t = await bodyText();
  assert(/Patch approvals/.test(t) && /openssl/.test(t) && /libc6:amd64/.test(t) && /\.NET 9 optional update/.test(t), 'content: ' + t.slice(0, 300));
  await p.waitSel('input[data-pat-all]');
  await p.click('input[data-pat-all]');
  assert((await p.eval(`[...document.querySelectorAll('#pat-fleet-form input[name="update_ids[]"]')].every(c=>c.checked)`)), 'select-all');
  await p.click('input[data-pat-all]');
  await p.eval(`[...document.querySelectorAll('#pat-fleet-form input[name="update_ids[]"]')].find(c=>/\\.NET 9/.test(c.getAttribute('aria-label')||'')).checked=true`);
  await shot('approvals-desktop-light');
  await p.click('#pat-fleet-form button[value=decline]');
  await dialogGo();
  await flash(/Declined 1 update row/, 'decline flash');
  await go('/agent/rmm_patch_updates.php?status=declined');
  assert(/\.NET 9 optional update/.test(await bodyText()), 'the declined update is not under the declined filter');
});

// ------------------------------------------------------------------------------------------------ policies
await T('policies page: new policy form shows the window fields on demand, saves, assigns, shows on the device tab', async () => {
  await go('/agent/rmm_patch_policies.php?new=1');
  await trapDialogs();
  await p.waitSel('#pol-form');
  assert(!(await p.visible('#pp_ws')), 'the daily window fields should be hidden until chosen');
  await p.type('#pp_name', 'Smoke workstations');
  await setVal('#pp_ring', 'pilot');
  await setVal('#pp_mode', 'auto');
  await p.type('#pp_ad_critical', '1');
  await p.type('#pp_ad_security', '3');
  await setVal('#pp_inst', '1');
  await setVal('#pat_window_mode', 'daily');
  await p.waitSel('#pp_ws');
  await p.click('#pp_day_sat');
  await p.click('#pp_day_sun');
  await p.type('#pp_ws', '02:30');
  await p.type('#pp_wm', '90');
  await setVal('#pp_wtz', 'Europe/Berlin');
  assert(!(await p.visible('#pp_rbc')), 'the restart confirmation should be hidden until "restart when needed" is chosen');
  await setVal('#pat_reboot', 'if_required_now');
  await p.waitSel('#pp_rbc');
  await setVal('#pat_reboot', 'if_required_defer');
  const ownP = await p.eval(`[...document.querySelectorAll('#pol-form input, #pol-form select, #pol-form textarea')].filter(e=>{if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;return !((e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title'))}).map(e=>e.name||e.id||e.tagName)`);
  assert(ownP.length === 0, 'unlabeled controls in the policy form: ' + ownP.join(','));
  await shot('policy-form-desktop-light');
  await p.clickNav('#pol-form button[type=submit]');
  await flash(/Policy created/, 'policy created');
  await p.waitSel('#pol-assign form');
  await setVal('#asg_type', 'client');
  await p.waitSel('#asg_id_client');
  await setVal('#asg_id_client', String(await p.eval(`[...document.querySelectorAll('#asg_id_client option')].find(o=>/Dept A/.test(o.textContent)).value`)));
  await p.clickNav('#pol-assign form button[type=submit]');
  await flash(/Policy assigned/, 'assigned');
  const t = await bodyText();
  assert(/Department Dept A/.test(t) && /Smoke workstations/.test(t) && /sat,sun 02:30 for 90 min/.test(t), 'policy list: ' + t.slice(0, 400));
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await p.waitSel('.rmm-pat-policy');
  const pt = await p.eval(`document.querySelector('.rmm-pat-policy').innerText`);
  assert(/pilot/.test(pt) && /by itself/.test(pt) && /sat,sun/.test(pt), 'the device tab does not show the effective policy: ' + pt);
  await shot('policy-effective-desktop-light');
});
await T('policies page: a refused form keeps what was typed; rings and rules; delete asks first', async () => {
  await go('/agent/rmm_patch_policies.php?new=1');
  await p.waitSel('#pol-form');
  await p.type('#pp_name', 'Bad <b>policy</b>');
  await setVal('#pat_window_mode', 'daily');
  await p.waitSel('#pp_ws');
  await p.type('#pp_ws', '99:99', { clear: true });
  await p.eval(`document.querySelector('#pp_ws').removeAttribute('pattern')`);
  await p.clickNav('#pol-form button[type=submit]');
  await p.waitFor(`/window_days|HH:MM|daily/i.test(document.body.innerText)`, { label: 'refusal words' });
  assert((await p.eval(`document.querySelector('#pp_name').value`)) === 'Bad <b>policy</b>' && (await p.eval(`document.querySelectorAll('main b, .page-body b').length`)) === 0, 'the typed name was lost or became markup');
  await go('/agent/rmm_patch_policies.php');
  await p.type('#rg_name', 'canary');
  await p.type('#rg_delay', '2');
  await p.clickNav('#ring-form button[type=submit]');
  await p.waitFor(`/Ring saved/.test(document.body.innerText)`, { label: 'ring saved' });
  await p.type('#ru_name', 'Security fast');
  await p.type('#ru_days', '1');
  await p.click('#ru_k_security');
  await p.clickNav('#rule-form button[type=submit]');
  await p.waitFor(`/Rule saved/.test(document.body.innerText)`, { label: 'rule saved' });
  assert(/Security fast/.test(await bodyText()) && /canary/.test(await bodyText()), 'ring or rule not listed');
  await shot('policies-desktop-light');
  await p.click('#policies a.btn-outline-primary');
  await p.waitSel('#pol-form');
  await go('/agent/rmm_patch_policies.php');
  await p.click('#policies button.btn-outline-danger');
  await p.waitSel('#rmmAutoConfirmGo');
  assert(/Delete the policy/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'delete did not ask first');
  await p.eval(`document.querySelector('#rmmAutoConfirm .btn-outline-secondary').click()`);
  assert(/Smoke workstations/.test(await bodyText()), 'cancelling the dialog deleted the policy');
});
await T('policies page: phone width and dark mode keep their layout', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await go('/agent/rmm_patch_policies.php');
  await noOverflow('policies (phone)');
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await p.waitSel('#rmm-pane-patches');
  await noOverflow('Patches tab (phone)');
  await shot('patches-tab-phone-light');
  await go('/agent/rmm_patch_updates.php');
  await noOverflow('approvals (phone)');
  await p.setViewport(1366, 900);
  await setTheme('1');
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}#rmm-patches`);
  await p.waitSel('#rmm-pane-patches');
  await shot('patches-tab-desktop-dark');
  await go('/agent/rmm_patches.php');
  await shot('compliance-desktop-dark');
  await setTheme('0');
});

// ------------------------------------------------------------------------------------------------ administration
await T('administration: the Patch management card, live installs need the acknowledgement', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await trapDialogs();
  await p.waitSel('#patch-management');
  assert(/once a day \(a short read-only check, no download\)/.test(await p.eval(`document.getElementById('patch-management').innerText`)), 'cost text missing');
  await p.eval(`(()=>{const c=document.querySelector('#ea_pt_live');c.scrollIntoView();c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.clickNav('#patch-management button[name=save_patch_settings]');
  await flash(/Tick the acknowledgement|No change to save/, 'live install refusal');
  assert(/Tick the acknowledgement/.test(await bodyText()), 'live installs were not refused without the acknowledgement: ' + (await bodyText()).slice(0, 200));
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#ea_pt_scan');
  await p.type('#ea_pt_scan', '43200');
  await p.clickNav('#patch-management button[name=save_patch_settings]');
  await flash(/Patch management settings saved/, 'saved');
  assert((await p.eval(`document.querySelector('#ea_pt_scan').value`)) === '43200' && !(await p.eval(`document.querySelector('#ea_pt_live').checked`)), 'saved values');
  await shot('admin-card-desktop-light');
});

// ------------------------------------------------------------------------------------------------ a user limited to Dept B
await T('Dept B technician: sees only Dept B on the compliance and approvals pages; the policy form is locked', async () => {
  await login('deptb@scratch.test', 'Scratch-Deptb-1234');
  await go('/agent/rmm_patches.php');
  let t = await bodyText();
  assert(/Dept B/.test(t) && !/Dept A/.test(t) && !/PSMOKE-WIN/.test(t), 'compliance leaks Dept A: ' + t.slice(0, 300));
  await go('/agent/rmm_patch_updates.php');
  t = await bodyText();
  assert(/openssl/.test(t) && !/Cumulative Update/.test(t), 'approvals leaks Dept A');
  await go('/agent/rmm_patch_policies.php');
  t = await bodyText();
  assert(/only a user with access to every department can change them/.test(t) && !(await p.exists('#pol-form')) && (await p.exists('#rule-form')), 'policy page for a limited user');
  await go(`/agent/asset_details.php?asset_id=${fx.asset['PSMOKE-WIN']}`);
  assert(!(await p.exists('#rmm-tab-patches')), 'a Dept A device page shows a Patches tab to a Dept B user');
  await shot('deptb-policies-desktop-light');
  await login(EMAIL, PASSWORD);
}, { allowHttp: [/\/agent\/asset_details\.php/, /\/agent\/clients\.php/, /\/agent\/rmm_/] });

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
