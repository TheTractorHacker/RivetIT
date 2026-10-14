// Browser smoke for the Remote button of RivetCore 1.0.0-rc.17: the session opens in a NEW tab that is opened inside the click (before the launch call answers), is sent to the
// MeshCentral URL (login, gotonode, viewmode, hide) when the answer arrives, and falls back to a visible link when the browser refuses the tab. Against a MOCK MeshCentral
// (a Node HTTP server in this file): it proves RivetIT's side, NOT what a real MeshCentral shows.
//
//   tests/browser/rmm_rc17_remote_smoke.mjs     (seeded like rmm_p68_remote_smoke.mjs: rmm_seed.php, then rmm_p68_remote_extra.php runs from here)
//
// Same driver, harness and safety rules as rmm_p68_remote_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, and every check also
// fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx while it ran (the deliberately failing launch allows its own 4xx).
import { mkdirSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { Browser, Page, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: meant for a throwaway install.`);
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(join(outDir, 'shots'), { recursive: true });

// ------------------------------------------------------------------------------------------------ the mock MeshCentral
const meshLog = [];
let meshDown = false;
const mesh = createServer((req, res) => {
  const u = new URL(req.url, 'http://127.0.0.1');
  if (u.pathname === '/favicon.ico') { res.writeHead(204); res.end(); return; }
  if (u.pathname === '/health.ashx') { res.writeHead(meshDown ? 500 : 200); res.end(meshDown ? 'down' : 'OK'); return; }
  meshLog.push({ path: u.pathname, query: u.search });
  res.writeHead(200, { 'Content-Type': 'text/html' });
  // the page reports whether the tab can reach its opener (it must not)
  res.end('<!doctype html><title>mock-mesh</title><body><script>document.title = "mock-mesh opener=" + (window.opener === null ? "null" : "SET");</script>mock MeshCentral</body>');
});
await new Promise((r) => mesh.listen(0, '127.0.0.1', r));
const MESH_PORT = mesh.address().port;

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const text = (sel) => p.eval(`(document.querySelector(${JSON.stringify(sel)})||{}).innerText||''`);

// the page targets of the browser, from its HTTP endpoint: a tab opened by window.open appears here
const targets = async () => (await (await fetch(`http://127.0.0.1:${browser.port}/json/list`)).json()).filter((t) => t.type === 'page');
const baseline = new Set((await targets()).map((t) => t.id));   // the start page and the page this script drives
const fresh = async () => (await targets()).filter((t) => !baseline.has(t.id));
const others = fresh;
async function closeExtraTabs() {
  for (const t of await fresh()) await fetch(`http://127.0.0.1:${browser.port}/json/close/${t.id}`).catch(() => {});
  await sleep(300);
}
async function waitTab(pred, label, ms = 10000) {
  const end = Date.now() + ms;
  for (;;) {
    const hit = (await fresh()).find(pred);
    if (hit) return hit;
    if (Date.now() > end) throw new Error('no tab: ' + label + ' (new tabs: ' + (await fresh()).map((t) => t.url.slice(0, 80)).join(' | ') + ')');
    await sleep(100);
  }
}
const signIn = async (email, password) => {
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
};
// Make the launch call slow (so "the tab exists before the answer" is observable) and record every window.open the page makes.
const hookPage = (delayMs) => p.eval(`(()=>{
  window.__opens = []; window.__fetchDone = 0;
  if (!window.__origOpen) { window.__origOpen = window.open; window.__origFetch = window.fetch; }
  window.open = function () { window.__opens.push({ args: [].slice.call(arguments).map(String), fetchDone: window.__fetchDone }); return window.__origOpen.apply(window, arguments); };
  window.fetch = function (url, init) { return window.__origFetch.apply(window, arguments).then(function (r) { return new Promise(function (res) { setTimeout(function () { window.__fetchDone++; res(r); }, ${delayMs}); }); }); };
  return true; })()`);
const unhook = () => p.eval(`(()=>{ if (window.__origOpen) { window.open = window.__origOpen; window.fetch = window.__origFetch; } return true; })()`);
const extra = (hide, tools) => JSON.parse(execFileSync('php', ['tests/browser/rmm_rc17_extra.php', String(MESH_PORT), String(hide), tools === null ? '-' : String(tools)], { encoding: 'utf8', env: process.env, timeout: 60000 }).split('\n').find((l) => l.startsWith('{')));
let FX = null;
const asset = () => `/agent/asset_details.php?asset_id=${FX.asset}`;
const parseQuery = (u) => { const q = new URL(u).searchParams; return Object.fromEntries(q.entries()); };

await T('login: real form signs in', async () => { await signIn(EMAIL, PASSWORD); }, { allowHttp: [/\/agent\/clients\.php/] });
await T('setup: the Linux server with its Mesh node, remote tools on, the mock Mesh server, hide 31', async () => {
  const out = execFileSync('php', ['tests/browser/rmm_p68_remote_extra.php', process.env.SEED_JSON], { encoding: 'utf8', env: process.env, timeout: 60000 });
  FX = JSON.parse(out.split('\n').find((l) => l.startsWith('{')));
  assert(FX.dev > 0 && FX.asset > 0, 'fixture: ' + out);
  const e = extra(31, null);
  assert(e.hide === 31 && e.hide_tools === null, 'extra: ' + JSON.stringify(e));
});

await T('Remote button: a new tab exists at once (before the launch answers), then goes to the Mesh URL with login, gotonode, viewmode=11 and hide=31', async () => {
  await go(asset());
  await p.waitSel('#rmm-act-remote');
  assert(!(await p.eval(`document.getElementById('rmm-act-remote').disabled`)), 'the Remote button is disabled');
  await closeExtraTabs();
  await hookPage(1500);
  meshLog.length = 0;
  await p.click('#rmm-act-remote');
  // (1) within the first 1.2 seconds the launch call has not answered yet (it is held for 1.5 s), yet a tab already exists
  const blank = await waitTab((t) => t.url === 'about:blank' || /Opening remote session/.test(t.title), 'a blank tab', 1200);
  const early = await p.eval(`({ opens: window.__opens, done: window.__fetchDone })`);
  assert(early.done === 0 && early.opens.length === 1 && early.opens[0].fetchDone === 0 && early.opens[0].args[0] === 'about:blank', 'the tab was not opened synchronously in the click: ' + JSON.stringify(early));
  // (2) then it is sent to the session
  const t = await waitTab((x) => x.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`), 'the Mesh tab');
  assert(t.id === blank.id, 'the SAME tab was navigated (not a second one)');
  const q = parseQuery(t.url);
  assert(typeof q.login === 'string' && q.login.length > 40 && /^node\/\//.test(q.gotonode) && q.viewmode === '11' && q.hide === '31', 'URL: ' + t.url.replace(/login=[^&]+/, 'login=...'));
  assert(/&viewmode=11&hide=31$/.test(t.url), 'parameter order: ' + t.url.slice(-40));
  const after = await p.eval(`window.__opens`);
  assert(after.length === 1, 'window.open was called ' + after.length + ' times (expected the one blank tab)');
  await sleep(300);
  const t2 = (await fresh()).find((x) => x.id === t.id);
  assert(/opener=null/.test(t2.title), 'the tab can reach its opener: ' + t2.title);
  assert(meshLog.length === 1 && meshLog[0].path === '/' && /viewmode=11&hide=31$/.test(meshLog[0].query), 'the Mesh server saw: ' + JSON.stringify(meshLog));
  assert(/Remote session opened/.test(await text('#rmm-msg')), 'status: ' + await text('#rmm-msg'));
  assert(!(await p.exists('a.rmm-open-session-link')), 'a fallback link is shown although the tab opened');
  await unhook();
});

await T('every click opens its own NEW tab; the earlier one is left alone (never reused)', async () => {
  await go(asset());
  await p.waitSel('#rmm-act-remote');
  await closeExtraTabs();
  await hookPage(0);
  await p.click('#rmm-act-remote');
  const a = await waitTab((t) => t.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`), 'first Mesh tab');
  await p.click('#rmm-act-remote');
  await sleep(1200);
  const mine = (await fresh()).filter((t) => t.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`));
  assert(mine.length === 2 && mine[0].id !== mine[1].id, 'two clicks gave ' + mine.length + ' tabs');
  assert(parseQuery(mine[0].url).login !== parseQuery(mine[1].url).login, 'each launch has its own login token');
  assert(mine.some((t) => t.id === a.id && t.url === a.url), 'the first tab was changed by the second click');
  await unhook();
});

await T('the browser refuses the tab: an "Open remote session" link is shown (target _blank, noopener) and no tab appears', async () => {
  await go(asset());
  await p.waitSel('#rmm-act-remote');
  await closeExtraTabs();
  await p.eval(`window.open = function () { return null; }; true`);
  await p.click('#rmm-act-remote');
  await p.waitSel('a.rmm-open-session-link', { timeout: 8000 });
  const l = await p.eval(`(()=>{const a=document.querySelector('a.rmm-open-session-link');return {href:a.href,target:a.target,rel:a.rel,text:a.textContent,msg:document.getElementById('rmm-msg').innerText}})()`);
  assert(l.target === '_blank' && /noopener/.test(l.rel) && l.text === 'Open remote session' && /blocked/.test(l.msg), 'link: ' + JSON.stringify({ ...l, href: l.href.slice(0, 60) }));
  const q = parseQuery(l.href);
  assert(q.viewmode === '11' && q.hide === '31' && q.login && q.gotonode, 'link URL: ' + l.href.slice(0, 120));
  assert((await others()).length === 0, 'a tab appeared although the browser refused it');
  // the link works: a real click on it (a user gesture of its own) opens the session
  await p.eval(`delete window.open; true`);
  await p.click('a.rmm-open-session-link');
  await waitTab((t) => t.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`), 'the tab opened by the link');
});

await T('a failed launch (Mesh down): the blank tab is closed again and the error is shown', async () => {
  await go(asset());
  await p.waitSel('#rmm-act-remote');
  await closeExtraTabs();
  meshDown = true;
  await hookPage(600);
  await p.click('#rmm-act-remote');
  await waitTab((t) => t.url === 'about:blank' || /Opening/.test(t.title), 'the blank tab', 1500);
  await p.waitFor(`document.getElementById('rmm-msg').className.includes('alert-danger')`, { timeout: 8000, label: 'error alert' });
  await sleep(500);
  assert((await others()).length === 0, 'the blank tab was not closed: ' + (await others()).map((t) => t.url).join(','));
  assert(!(await p.exists('a.rmm-open-session-link')) && /MeshCentral|answer|Try again/i.test(await text('#rmm-msg')), 'error text: ' + await text('#rmm-msg'));
  meshDown = false;
  await unhook();
}, { allowHttp: [/rmm_agent\.php/] });

await T('mesh_hide = 0: the URL is the old one (no hide parameter)', async () => {
  extra(0, null);
  await go(asset());
  await p.waitSel('#rmm-act-remote');
  await closeExtraTabs();
  await p.click('#rmm-act-remote');
  const t = await waitTab((x) => x.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`), 'Mesh tab');
  const q = parseQuery(t.url);
  assert(!('hide' in q) && q.viewmode === '11' && /&viewmode=11$/.test(t.url), 'URL: ' + t.url.replace(/login=[^&]+/, 'login=...'));
});

await T('Remote tab: Terminal and Files use the tools value (empty = same as the desktop; 0 allowed)', async () => {
  extra(31, null);
  await go(asset() + '#rmm-remote');
  await p.waitSel('#rmm-remote-tools');
  await closeExtraTabs();
  await p.click('[data-rem-mesh="terminal"]');
  let t = await waitTab((x) => x.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`), 'terminal tab');
  let q = parseQuery(t.url);
  assert(q.viewmode === '12' && q.hide === '31', 'terminal: ' + t.url.replace(/login=[^&]+/, 'login=...'));
  assert(/session opened/i.test(await text('#rem-mesh-status')), 'status: ' + await text('#rem-mesh-status'));
  extra(31, 0);
  await closeExtraTabs();
  await go(asset() + '#rmm-remote');
  await p.waitSel('#rmm-remote-tools');
  await p.click('[data-rem-mesh="files"]');
  t = await waitTab((x) => x.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`), 'files tab');
  q = parseQuery(t.url);
  assert(q.viewmode === '13' && !('hide' in q), 'files with tools=0: ' + t.url.replace(/login=[^&]+/, 'login=...'));
  await closeExtraTabs();
  await p.click('[data-rem-mesh="desktop"]');
  t = await waitTab((x) => x.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`), 'desktop tab from the Remote tab');
  q = parseQuery(t.url);
  assert(q.viewmode === '11' && q.hide === '31', 'desktop from the Remote tab keeps the desktop value: ' + t.url.replace(/login=[^&]+/, 'login=...'));
});

await T('Remote tab: the browser refuses the tab: the status line carries the link', async () => {
  extra(31, null);
  await go(asset() + '#rmm-remote');
  await p.waitSel('#rmm-remote-tools');
  await closeExtraTabs();
  await p.eval(`window.open = function () { return null; }; true`);
  await p.click('[data-rem-mesh="terminal"]');
  await p.waitSel('#rem-mesh-status a.rmm-open-session-link', { timeout: 8000 });
  const q = parseQuery(await p.eval(`document.querySelector('#rem-mesh-status a.rmm-open-session-link').href`));
  assert(q.viewmode === '12' && q.hide === '31', 'link for the terminal: ' + JSON.stringify({ ...q, login: '...' }));
  assert((await others()).length === 0, 'a tab appeared');
});

await T('Administration: the Mesh card has the hide field; saving 7 and an empty tools value changes the next launch; a bad value is refused', async () => {
  extra(31, null);
  await go('/admin/settings_endpoint_agent.php#mesh');
  await p.waitSel('#mc_hide');
  const t0 = await text('#mesh');
  assert(/Hide MeshCentral interface elements/.test(t0) && /31 = show only the remote screen/.test(t0) && /Connect/.test(t0), 'card text');
  assert((await p.eval(`document.getElementById('mc_hide').value`)) === '31', 'default value 31');
  assert(await p.eval(`!!document.querySelector('label[for=mc_hide]') && !!document.querySelector('label[for=mc_hide_tools]') && document.getElementById('mc_hide').getAttribute('aria-describedby') === 'mc_hide_help'`), 'labels / described-by');
  await p.type('#mc_hide', '7');
  await p.clickNav('button[name=save_mesh_settings]');
  await go('/admin/settings_endpoint_agent.php#mesh');
  assert((await p.eval(`document.getElementById('mc_hide').value`)) === '7', 'saved: ' + await p.eval(`document.getElementById('mc_hide').value`));
  await go(asset()); await p.waitSel('#rmm-act-remote'); await closeExtraTabs();
  await p.click('#rmm-act-remote');
  const t = await waitTab((x) => x.url.startsWith(`http://127.0.0.1:${MESH_PORT}/`), 'Mesh tab');
  assert(parseQuery(t.url).hide === '7', 'hide=7 in the launch: ' + t.url.slice(-30));
  await go('/admin/settings_endpoint_agent.php#mesh');
  await p.waitSel('#mc_hide');
  await p.eval(`(()=>{const e=document.getElementById('mc_hide');e.removeAttribute('max');e.value='999';return true})()`);
  await p.clickNav('button[name=save_mesh_settings]');
  assert(/0 to 255|whole number/.test(await p.eval(`document.body.innerText`)), 'the refusal is shown');
  await go('/admin/settings_endpoint_agent.php#mesh');
  assert((await p.eval(`document.getElementById('mc_hide').value`)) === '7', 'nothing was saved for 999');
  await p.type('#mc_hide', '31');
  await p.clickNav('button[name=save_mesh_settings]');
});

const fails = H.summary();
mesh.close();
await browser.close();
process.exit(fails ? 1 : 0);
