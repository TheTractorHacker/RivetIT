// Browser smoke for the RMM Phase 2 pages of group A: policies, custom fields and the "Policy and fields" tab of the asset page (RivetIT).
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


const setSelect = (sel, value) => p.eval(`(()=>{const s=document.querySelector(${JSON.stringify(sel)});s.value=${JSON.stringify(value)};s.dispatchEvent(new Event('change',{bubbles:true}));return s.value})()`);
const selectText = (sel, text) => p.eval(`(()=>{const s=document.querySelector(${JSON.stringify(sel)});const o=[...s.options].find(x=>x.textContent.trim()===${JSON.stringify(text)});if(!o)return null;s.value=o.value;s.dispatchEvent(new Event('change',{bubbles:true}));return o.value})()`);
const FL = `((document.querySelector('#toast-container')||{}).innerText||'')`;   // the app shows flash messages as toasts
const flash = async () => { await p.waitFor(`${FL}.length > 0`, { label: 'a flash message' }).catch(() => {}); return p.eval(FL); };
const submit = (form) => p.clickNav(`${form} button[type=submit]:not([name=clear])`);
const confirmGo = async () => { await p.waitSel('#rmmAutoConfirm.show'); await sleep(450); await p.clickNav('#rmmAutoConfirmGo'); };

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ------------------------------------------------------------------------------------------------ policies
await T('policies: empty list, then the new-policy form with the catalog-driven check rows', async () => {
  await go('/agent/rmm_policies.php');
  await trapDialogs();
  await p.waitSel('#pol-list');
  assert(/No policies yet/.test(await bodyText()), 'empty state missing');
  await shot('policies-empty-desktop-light');
  await go('/agent/rmm_policies.php?new=1');
  await p.waitSel('#pol-form');
  await p.type('#pol_name', 'Smoke policy');
  await p.type('#pol_desc', 'Made by the browser smoke');
  assert(!(await p.visible('#pol_ci_value')), 'the interval box is visible while the mode is "Not set"');
  await setSelect('#pol_ci_mode', 'override');
  await p.waitSel('#pol_ci_value');
  await p.type('#pol_ci_value', '120');
  await setSelect('#pol_ft_software_inventory', 'off');
  await p.click('[data-rmm-add="#pol-checks"]');
  await p.waitSel('#pc0_key');
  await p.type('#pc0_key', 'cpu_load');
  await setSelect('#pc0_type', 'cpu');
  await p.type('#pc0_iv', '60');
  await p.waitSel('#pc0_cpu_window_s');
  assert(!(await p.visible('#pc0_ping_host')), 'the ping inputs show for a CPU check');
  await p.type('#pc0_cpu_window_s', '5');
  await p.click('#pol-checks details summary');
  await p.waitSel('#pc0_warnv');
  await p.type('#pc0_warnv', '80');
  await p.type('#pc0_critv', '95');
  await p.type('#pc0_for_samples', '2');
  await p.click('[data-rmm-add="#pol-checks"]');
  await p.waitSel('#pc1_key');
  await p.type('#pc1_key', 'spooler');
  await setSelect('#pc1_type', 'service');
  await p.type('#pc1_iv', '300');
  await p.waitSel('#pc1_pj');
  await p.type('#pc1_pj', '{"name": "Spooler"}');
  await shot('policy-new-desktop-light');
  await submit('#pol-form');
  await p.waitSel('#pol-assignments');
  assert(/Policy created/.test(await flash()), 'no "Policy created" flash: ' + (await flash()));
  assert((await p.eval(`document.getElementById('pol_ci_value').value`)) === '120' && (await p.eval(`document.getElementById('pc0_key').value`)) === 'cpu_load', 'the saved settings do not come back in the editor');
});
await T('policies: a refused value comes back with the message and the form filled in', async () => {
  await go('/agent/rmm_policies.php');
  await p.clickText('Smoke policy');
  await p.waitSel('#pol-form');
  await p.type('#pol_ci_value', '9000');
  await submit('#pol-form');
  await p.waitSel('#pol-form');
  assert(/from 60 to 3600/.test(await flash()), 'validation message not shown: ' + (await flash()));
  assert((await p.eval(`document.getElementById('pol_ci_value').value`)) === '9000', 'the refused value was lost');
  await p.type('#pol_ci_value', '180');
  await submit('#pol-form');
  await p.waitFor(`/Policy saved/.test((document.querySelector('#toast-container')||{}).innerText||'')`, { label: 'saved' });
});
await T('policies: assign to a department, see it listed, explain a device', async () => {
  await p.waitSel('#pol-assignments');
  await selectText('#as_type', 'A department');
  await p.waitSel('#as_id_client');
  await selectText('#as_id_client', 'Dept A');
  await p.click('#as_enforce');
  await p.clickNav('#pol-assignments form button.btn-primary');
  await p.waitSel('#pol-assignments table');
  const t = await p.eval(`document.getElementById('pol-assignments').innerText`);
  assert(/Department Dept A/.test(t) && /Enforced/.test(t), 'assignment not listed: ' + t.slice(0, 200));
  await selectText('#pol_explain_dev', 'WIN1');
  await p.clickNav('#pol-explain-card form button[type=submit]');
  await p.waitSel('#pol-explain table');
  const e = await p.eval(`document.getElementById('pol-explain').innerText`);
  assert(/Settings in force/.test(e) && /Smoke policy/.test(e) && /180 seconds/.test(e) && /Enforced/.test(e) && /Check cpu_load/.test(e), 'explanation incomplete: ' + e.slice(0, 300));
  await shot('policy-detail-desktop-light');
});
await T('policies: versions panel with a readable disclosure; list shows who it reaches; delete asks first', async () => {
  const t = await p.eval(`document.getElementById('pol-versions').innerText`);
  assert(/Version|^\s*2/m.test(t) && /Current/.test(t) && /First version/.test(t), 'versions panel: ' + t.slice(0, 200));
  await p.click('#pol-versions details summary');
  await sleep(200);
  assert(/Check-in interval/.test(await p.eval(`document.querySelector('#pol-versions details[open]').innerText`)), 'the disclosure shows no readable settings');
  await shot('policy-versions-desktop-light');
  await go('/agent/rmm_policies.php');
  await p.waitSel('#pol-list table');
  const l = await p.eval(`document.getElementById('pol-list').innerText`);
  assert(/Smoke policy/.test(l) && /Department Dept A/.test(l), 'the list does not say who the policy reaches');
  await shot('policies-list-desktop-light');
  await trapDialogs();
  await p.click('#pol-list form button.btn-outline-danger');
  await p.waitSel('#rmmAutoConfirm.show');
  assert(/Delete the policy/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').innerText`)), 'no confirmation text');
  await sleep(450);
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmAutoConfirm.show')`, { label: 'closed' });
  assert(/Smoke policy/.test(await bodyText()), 'cancelling deleted the policy');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used for the delete confirmation');
});

// ------------------------------------------------------------------------------------------------ fields
await T('fields: define, set a department value, the secret is write-only', async () => {
  await go('/agent/rmm_fields.php');
  await p.waitSel('#fld-new');
  await p.type('#nf_name', 'vpn_gateway');
  await p.type('#nf_label', 'VPN gateway');
  await submit('#fld-new form');
  await p.waitFor(`/Field defined/.test((document.querySelector('#toast-container')||{}).innerText||'')`, { label: 'defined' });
  await p.type('#nf_name', 'api_secret');
  await setSelect('#nf_type', 'secret');
  assert(!(await p.visible('#nf_default')), 'a secret field offers a default');
  await submit('#fld-new form');
  await p.waitFor(`/Field defined/.test((document.querySelector('#toast-container')||{}).innerText||'')`, { label: 'defined 2' });
  await p.type('#nf_name', 'asset_tier');
  await setSelect('#nf_scope', 'device');
  await setSelect('#nf_type', 'list');
  await p.waitSel('#nf_options');
  await p.type('#nf_options', 'gold\nsilver');
  await submit('#fld-new form');
  await p.waitFor(`/Field defined/.test((document.querySelector('#toast-container')||{}).innerText||'')`, { label: 'defined 3' });
  const fid = await p.eval(`[...document.querySelectorAll('section[id^=fld-]')].filter(s=>/^fld-\\d+$/.test(s.id)).find(s=>/vpn_gateway/.test(s.innerText)).id`);
  await p.type(`#${fid} input[name=value]`, '10.0.0.1');
  await selectText(`#${fid} select[name=scope_id]`, 'Dept A');
  await p.clickNav(`#${fid} form button.btn-primary`);
  await p.waitSel(`#${fid} table`);
  assert(/10\.0\.0\.1/.test(await p.eval(`document.getElementById('${fid}').innerText`)), 'value not listed');
  const sid = await p.eval(`[...document.querySelectorAll('section[id^=fld-]')].filter(s=>/^fld-\\d+$/.test(s.id)).find(s=>/api_secret/.test(s.innerText)).id`);
  await p.type(`#${sid} input[name=value]`, 'S3cret-Smoke-Value');
  await p.clickNav(`#${sid} form button.btn-primary`);
  await p.waitSel(`#${sid} table`);
  const st = await bodyText();
  assert(!/S3cret-Smoke-Value/.test(await p.eval(`document.documentElement.outerHTML`)) && /Set/.test(await p.eval(`document.getElementById('${sid}').innerText`)), 'secret leaked or not marked as set');
  await shot('fields-desktop-light');
});
await T('asset page: the Policy and fields tab shows the policy source and sets a device value', async () => {
  await go(asset('WIN1') + '#rmm-policy');
  await p.waitFor(`document.getElementById('rmm-tab-policy').getAttribute('aria-selected') === 'true'`, { label: 'tab from the hash' });
  await p.waitSel('#rmm-policy-effective table');
  const t = await p.eval(`document.getElementById('rmm-policy-effective').innerText`);
  assert(/Smoke policy/.test(t) && /Department Dept A/.test(t) && /180 seconds/.test(t) && /Check cpu_load/.test(t), 'effective policy: ' + t.slice(0, 300));
  await shot('asset-policy-desktop-light');
  await selectText('#rmm-df-' + (await p.eval(`document.querySelector('#rmm-policy-fields input[name=field_id]').value`)), 'gold');
  await p.clickNav('#rmm-policy-fields form button.btn-primary');
  await p.waitFor(`/Value saved/.test((document.querySelector('#toast-container')||{}).innerText||'')`, { label: 'saved' });
  assert((await p.eval(`location.hash`)) === '#rmm-policy', 'did not return to the tab: ' + (await p.url()));
  await p.waitFor(`document.getElementById('rmm-tab-policy').getAttribute('aria-selected') === 'true'`, { label: 'tab reopened' });
  assert(/gold/.test(await p.eval(`document.getElementById('rmm-policy-fields').innerText`)), 'device value not shown');
  await shot('asset-fields-desktop-light');
  await go(asset('LNX1') + '#rmm-policy');
  await p.waitSel('#rmm-policy-effective');
  assert(/No policy reaches this device; it follows the global configuration/.test(await bodyText()), 'empty state missing');
});

// ------------------------------------------------------------------------------------------------ dark, phone
const pages = [['policies-list', '/agent/rmm_policies.php', '#pol-list table'], ['policy-new', '/agent/rmm_policies.php?new=1', '#pol-form'],
  ['fields', '/agent/rmm_fields.php', '#fld-new'], ['asset-policy', asset('WIN1') + '#rmm-policy', '#rmm-policy-effective table']];
await T('phone width, light: no horizontal scroll on every page', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  for (const [n, u, w] of pages) { await go(u); await p.waitSel(w); await sleep(300); await noOverflow(n + ' phone'); await shot(n + '-phone-light'); }
  await go('/agent/rmm_policies.php'); await go(await p.eval(`document.querySelector('#pol-list a[href*=policy_id]').getAttribute('href')`)); await p.waitSel('#pol-assignments');
  await p.eval(`document.getElementById('pol_ci_mode').scrollIntoView()`);
  await noOverflow('policy detail phone'); await shot('policy-detail-phone-light');
  await p.setViewport(1366, 900);
});
await T('dark theme: desktop and phone screenshots, tokens only', async () => {
  await setTheme(1);
  await go('/agent/rmm_policies.php'); await p.waitSel('#pol-list table');
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'not dark');
  const th = await p.eval(`getComputedStyle(document.querySelector('#pol-list thead th')).backgroundColor`);
  for (const [n, u, w] of pages) { await go(u); await p.waitSel(w); await sleep(300); await shot(n + '-desktop-dark'); }
  await go('/agent/rmm_policies.php'); await go(await p.eval(`document.querySelector('#pol-list a[href*=policy_id]').getAttribute('href')`)); await p.waitSel('#pol-assignments'); await sleep(300); await shot('policy-detail-desktop-dark');
  await p.setViewport(MOBILE_W, 800, true);
  for (const [n, u, w] of pages) { await go(u); await p.waitSel(w); await sleep(300); await noOverflow(n + ' dark phone'); await shot(n + '-phone-dark'); }
  await p.setViewport(1366, 900);
  await setTheme(0);
});
await T('cleanup: delete the smoke policy through the confirmation dialog', async () => {
  await go('/agent/rmm_policies.php'); await p.waitSel('#pol-list table');
  await p.click('#pol-list form button.btn-outline-danger');
  await confirmGo();
  await p.waitFor(`/Policy deleted/.test((document.querySelector('#toast-container')||{}).innerText||'')`, { label: 'deleted' });
  assert(/No policies yet/.test(await bodyText()), 'policy still listed');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
