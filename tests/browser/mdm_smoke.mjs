// Browser smoke for the ManageEngine MDM integration: the settings form (edition switch, expiry warning, Test connection, Sync now), the Mobile devices page
// (filters, search, escaping) and the MDM card on an asset, at desktop and 390 px, light and dark.
//
//   MDM_MOCK_URL=http://127.0.0.1:8671 php tests/browser/mdm_seed.php          # on the throwaway install, with the mock running (see that file)
//   BASE_URL=http://127.0.0.1:8670 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SMOKE_OUT=/tmp/mdm-shots node tests/browser/mdm_smoke.mjs
//
// Same driver and safety rules as rmm_smoke.mjs (loopback only, zero npm dependencies). Every check also fails on any uncaught error, console.error, failed
// first-party request or HTTP 4xx/5xx that occurred while it ran.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) { console.error('Set BASE_URL, ADMIN_EMAIL and ADMIN_PASSWORD; optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: throwaway install only.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'mdm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
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
const noOverflow = async (label) => { const o = await hOverflow(); assert(o.sw <= o.vw + 1 && o.over.length === 0, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw}): ${o.over.join(', ')}`); };
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await p.clickNav('button[name=edit_your_user_preferences]');
};
const flash = () => p.eval(`(document.documentElement.innerHTML.match(/toastr\\["[a-z]+"\\]\\("([^"]*)"\\)/)||[])[1]||''`);
const SET = '/admin/settings_integrations.php?tab=devicesync';
const LIST = '/agent/mdm_devices.php';

await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('settings: on-premises form shown, cloud fields hidden, expiry warning visible, key never rendered', async () => {
  await go(SET);
  await p.waitSel('#mdm_edition');
  assert((await p.eval(`document.getElementById('mdm_edition').value`)) === 'onprem', 'edition is on-premises');
  assert(await p.visible('#mdm_server_url') && await p.visible('#mdm_api_key') && await p.visible('#mdm_key_expires'), 'on-prem fields visible');
  assert(!(await p.visible('#mdm_client_id')), 'cloud client id hidden for on-prem');
  const html = await p.eval('document.documentElement.innerHTML');
  assert(!html.includes('onprem-good-key'), 'the API key is not in the page');
  assert(/expires on \d{4}-\d\d-\d\d \(12 days\)/.test(await p.eval('document.body.innerText')), 'the 30-day expiry warning shows');
  await shot('settings-onprem-desktop-light');
});

await T('settings: switching the edition to Cloud shows the Zoho fields and hides the server/key fields', async () => {
  await p.eval(`(()=>{const e=document.getElementById('mdm_edition');e.value='cloud';e.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await sleep(150);
  assert(await p.visible('#mdm_client_id') && await p.visible('#mdm_data_center') && !(await p.visible('#mdm_api_key')), 'cloud fields shown');
  await shot('settings-cloud-desktop-light');
  await go(SET);   // leave the saved edition alone
});

await T('Test connection against the mock reports the device count', async () => {
  await go(SET);
  await p.waitSel('button[name=test_mdm_connection]');
  await p.clickNav('button[name=test_mdm_connection]');
  const f = await flash();
  assert(/Connection successful/.test(f) && /4 devices/.test(f), 'flash: ' + f);
});

await T('Sync now creates assets and links the existing iPad', async () => {
  await go(SET);
  await p.clickNav('button[name=sync_mdm_now]');
  const f = await flash();
  assert(/sync complete: 4 devices/.test(f) && /3 assets created/.test(f), 'flash: ' + f);
  await go(SET);
  assert(/Recent syncs/.test(await p.eval('document.body.innerText')), 'sync log card');
  await shot('settings-after-sync-desktop-light');
});

await T('Mobile devices: four linked devices, hostile name escaped, counts and filters work', async () => {
  await go(LIST);
  await p.waitSel('table');
  assert((await p.eval(`document.querySelectorAll('a.fw-bold[href*="asset_details"]').length`)) === 4, 'four rows');
  assert(!(await p.eval(`!!document.querySelector('img[src="x"]')`)), 'the hostile device name did not become markup');
  assert(/Evil/.test(await p.eval('document.body.innerText')), 'the name is shown as text');
  await shot('devices-desktop-light');
  await go(LIST + '?state=noncompliant');
  assert((await p.eval(`document.querySelectorAll('a.fw-bold[href*="asset_details"]').length`)) === 1, 'not compliant: one row');
  await go(LIST + '?older=30');
  assert((await p.eval(`document.querySelectorAll('a.fw-bold[href*="asset_details"]').length`)) === 1, 'older than 30 days: one row');
  await go(LIST + '?q=Galaxy');
  assert((await p.eval(`document.querySelectorAll('a.fw-bold[href*="asset_details"]').length`)) === 1, 'search by model: one row');
  await go(LIST);
  await p.eval(`(()=>{const e=document.querySelector('input[name=q]');e.value='DMPX0000003';})()`);
  await p.clickNav('form button.btn-primary');
  assert((await p.eval(`document.querySelectorAll('a.fw-bold[href*="asset_details"]').length`)) === 1, 'the Filter button submits the search');
});

await T('Asset page shows the MDM card with ownership, enrolled date and the console link', async () => {
  await go(LIST);
  const href = await p.eval(`document.querySelector('a.fw-bold[href*="asset_details"]').getAttribute('href')`);
  await go(href);
  await p.waitFor(`/ManageEngine MDM:/.test(document.body.innerText)`, { timeout: 10000, label: 'card' });
  const t = await p.eval('document.body.innerText');
  assert(/Corporate-owned/.test(t) && /Enrolled: 2025-01-01/.test(t) && /Passcode/.test(t), 'card facts');
  await shot('asset-card-desktop-light');
});

await T('phone width, light: settings, list and asset card fit', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await go(SET); await p.waitSel('#mdm_edition'); await sleep(250); await noOverflow('settings phone'); await shot('settings-phone-light');
  await go(LIST); await p.waitSel('table'); await sleep(250); await noOverflow('list phone'); await shot('devices-phone-light');
  const href = await p.eval(`document.querySelector('a.fw-bold[href*="asset_details"]').getAttribute('href')`);
  await go(href); await sleep(400); await noOverflow('asset phone'); await shot('asset-card-phone-light');
  await p.setViewport(1366, 900);
});

await T('dark theme: desktop and phone render and fit', async () => {
  await setTheme(1);
  await go(SET); await p.waitSel('#mdm_edition'); await shot('settings-onprem-desktop-dark');
  await go(LIST); await p.waitSel('table'); await shot('devices-desktop-dark');
  await p.setViewport(MOBILE_W, 800, true);
  await go(SET); await p.waitSel('#mdm_edition'); await sleep(250); await noOverflow('settings phone dark'); await shot('settings-phone-dark');
  await go(LIST); await p.waitSel('table'); await sleep(250); await noOverflow('list phone dark'); await shot('devices-phone-dark');
  await p.setViewport(1366, 900);
  await setTheme(0);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
