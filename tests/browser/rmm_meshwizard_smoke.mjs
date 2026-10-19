// Browser smoke for the Create installer button (RMM dashboard, Agent fleet) and the Remote access (MeshCentral) setup page (RivetIT 26.10.54).
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... MESH_STUB_URL=http://127.0.0.1:<port2> node tests/browser/rmm_meshwizard_smoke.mjs
//
// The install must be a throwaway one seeded by tests/browser/rmm_seed.php. MESH_STUB_URL is any server that answers /health.ashx with 200 (tests/support/mesh_health_router.php under `php -S`).
// Same driver and rules as rmm_agentfeed_smoke.mjs: loopback only, no npm dependencies, signs in through the real login form, every check also fails on any console.error,
// uncaught error, failed first-party request or HTTP 4xx/5xx. Everything is done the way a person does it: click, type, read what the page says.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD, STUB = (process.env.MESH_STUB_URL || '').replace(/\/+$/, '');
if (!BASE || !EMAIL || !PASSWORD || !STUB) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and MESH_STUB_URL; optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: it saves MeshCentral settings.`); process.exit(2); }
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
const assert = (c, m) => { if (!c) throw new Error(m); };
const text = (sel) => p.eval(`(document.querySelector(${JSON.stringify(sel)})||{innerText:''}).innerText`);
async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: Math.ceil(m.cssContentSize.width), height: Math.min(Math.ceil(m.cssContentSize.height), 9000), scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const noOverflow = async (label) => {
  const o = await p.eval(`({sw:document.documentElement.scrollWidth,vw:document.documentElement.clientWidth})`);
  assert(o.sw <= o.vw + 1, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw})`);
};
const badge = (n) => p.eval(`document.getElementById('mw-badge-${n}').getAttribute('data-state')`);
const fill = (sel, v) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}))})()`);

await T('login: real form signs in', async () => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

for (const [name, path] of [['Agent fleet', '/agent/rmm_fleet.php'], ['RMM dashboard', '/agent/rmm_dashboard.php']]) {
  await T(`${name}: a visible primary Create installer button opens the installer page`, async () => {
    await go(path);
    await p.waitSel('#rmm-build-installer');
    const v = await p.eval(`(()=>{const e=document.getElementById('rmm-build-installer');const r=e.getBoundingClientRect();return {w:r.width,h:r.height,cls:e.className,t:e.innerText.trim()}})()`);
    assert(v.w > 80 && v.h > 20 && /btn-primary/.test(v.cls) && /Create installer/.test(v.t), JSON.stringify(v));
    await shot(name.replace(/\W+/g, '-').toLowerCase() + '-header');
    await p.clickNav('#rmm-build-installer');
    await p.waitSel('#ad-root');
    assert(/rmm_add_device\.php/.test(await p.url()), 'url ' + (await p.url()));
  }, { allowHttp: [/rmm_add_device|rmm_fleet|rmm_dashboard/] });
}

await T('Add device dialog has a Create installer button to the full page', async () => {
  await go('/agent/rmm_fleet.php');
  await p.waitSel('[data-rmm-installer-open]');
  await p.click('[data-rmm-installer-open]');
  await p.waitSel('#rmmInstallerModal.show');
  assert(await p.eval(`!!document.querySelector('#rmm-inst-create-link[href="/agent/rmm_add_device.php"]')`), 'no link');
});

await T('wizard: five steps, amber where nothing is saved, reachable from the Endpoint agent header', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#rmm-mesh-wizard-link');
  await p.clickNav('#rmm-mesh-wizard-link');
  await p.waitSel('#mw-root');
  assert((await p.eval(`document.querySelectorAll('.mw-step').length`)) === 5, 'five steps');
  assert((await badge(2)) === 'todo' && (await badge(4)) === 'todo', 'steps 2 and 4 should be amber before a group is saved');
  await shot('wizard-first-look-desktop');
});

await T('wizard step 1: type the address and the key, Save and test connection: green tick, key never shown again', async () => {
  const key = 'ab12'.repeat(16);
  await go('/admin/settings_mesh_wizard.php');
  await p.waitSel('#mw_url');
  await fill('#mw_url', STUB);
  await fill('#mw_key', key);
  await p.clickNav('#mw-save-test');
  await p.waitSel('#mw-root');
  await p.waitFor(`document.getElementById('mw-badge-1').getAttribute('data-state')==='ok'`, { label: 'step 1 green' });
  const body = await p.eval('document.body.innerHTML');
  assert(!body.includes(key), 'the login key is printed back');
  assert(/stored encrypted/.test(await text('#step-1')), 'stored encrypted badge');
  await p.waitFor(`/MeshCentral answered/.test(document.getElementById('mw-live-conn').innerText)`, { label: 'live connection check' });
  await shot('wizard-step1-done-desktop');
});

await T('wizard step 2: the whole pasted download address is accepted; step 4 turns green; the id is never shown', async () => {
  const id = 'A'.repeat(30) + '@$' + 'b'.repeat(32);
  await go('/admin/settings_mesh_wizard.php');
  await p.waitSel('#mw_group');
  await fill('#mw_group', `${STUB}/meshagents?id=4&meshid=${id}&installflags=0`);
  await p.clickNav('#mw-save-group');
  await p.waitSel('#mw-root');
  assert((await badge(2)) === 'ok' && (await badge(4)) === 'ok', 'steps 2 and 4: ' + (await badge(2)) + ' ' + (await badge(4)));
  assert(!(await p.eval('document.body.innerHTML')).includes(id), 'the group id is printed back');
});

await T('wizard step 2: nonsense is refused with a plain message and the saved group stays', async () => {
  await go('/admin/settings_mesh_wizard.php');
  await p.waitSel('#mw_group');
  await fill('#mw_group', '<script>alert(1)</script>');
  await p.clickNav('#mw-save-group');
  await p.waitSel('#mw-root');
  assert((await badge(2)) === 'ok', 'group lost');
  assert(!(await p.eval('document.body.innerHTML')).includes('<script>alert(1)</script>'), 'unescaped');
});

await T('wizard step 3: a plain-http stub has no certificate; the live check says so and Read it from the server warns, saving nothing', async () => {
  await go('/admin/settings_mesh_wizard.php');
  await p.waitSel('#mw_probe');
  await p.clickNav('#mw_probe');
  await p.waitSel('#mw_pin_result');
  assert(/Could not read the certificate/.test(await text('#mw_pin_result')), await text('#mw_pin_result'));
  assert((await p.eval(`document.getElementById('mw_pin').value`)) === '', 'pin must stay empty');
});

await T('wizard: troubleshooting items open, the page fits a phone and every control has a label', async () => {
  await p.setViewport(390, 800, true);
  await go('/admin/settings_mesh_wizard.php#troubleshooting');
  await p.waitSel('#ts-home');
  await p.click('#ts-home summary');
  assert(/gotonode|Mesh node|home page/i.test(await text('#ts-home')), 'ts-home text');
  await noOverflow('wizard phone');
  await shot('wizard-mobile');
  await p.setViewport(1366, 900);
  const un = await p.eval(`[...document.querySelectorAll('#mw-root input, #mw-root select, #mw-root textarea')].filter(e=>e.type!=='hidden'&&!(e.labels&&e.labels.length)&&!e.getAttribute('aria-label')).map(e=>e.name||e.id)`);
  assert(un.length === 0, 'unlabeled: ' + un.join(','));
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
