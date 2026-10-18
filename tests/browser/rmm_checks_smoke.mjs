// Browser smoke for the plain-language check schedule on Administration > Endpoint agent, the check parameters of the Tactical policy page, the older check types in a policy and the
// MeshCentral corrections (RivetIT 26.10.53). Nothing in these forms takes JSON any more; this proves it in a real browser.
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=<file written by tests/browser/rmm_seed.php> node tests/browser/rmm_checks_smoke.mjs
//
//
// Same driver, harness and safety rules as rmm_agentfeed_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, and every check also fails on any uncaught
// error, console.error, failed first-party request or HTTP 4xx/5xx while it ran. Everything is done the way a person does it: presets, switches, drop-downs, the Save button.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD, SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
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
const extra = (mode, ...more) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_checks_extra.php', process.env.SEED_JSON, mode, ...more], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());

const login = async (email, password) => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page for ' + email);
};
const summary = () => p.eval(`document.getElementById('cs_summary').innerText.trim()`);
const waitSummary = (re, label) => p.waitFor(`/${re.source}/i.test(document.getElementById('cs_summary').innerText)`, { label: label || String(re), timeout: 10000 });
const errorsText = () => p.eval(`document.getElementById('cs_errors').innerText.trim()`);
const openPage = async () => { await go('/admin/settings_endpoint_agent.php'); await p.waitSel('#cs_editor'); await waitSummary(/check/); };
const setSel = (sel, v) => setVal(sel, v);
const tick = (sel, on) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)throw new Error('no ${sel}');if(e.checked!==${on}){e.click()}return e.checked})()`);
const saveSettings = async () => { await p.clickNav('button[name=save_agent_settings]'); };
const stored = () => extra('state');

await T('login: real form signs in', async () => { await login(EMAIL, PASSWORD); }, { allowHttp: [/\/agent\/clients\.php/] });
await T('setup: the stored list is cleared (built-in checks apply)', async () => { assert(extra('reset').ok, 'reset'); });

await T('the check schedule is plain-language options: no JSON box, no type dropdown, no seconds', async () => {
  await openPage();
  const r = await p.eval(`(()=>{const f=document.querySelector('#cs_editor');
    const jsonNamed=[...document.querySelectorAll('textarea,input')].filter(e=>/json/i.test(e.name||'')||/json/i.test(e.id||'')).map(e=>e.name||e.id);
    const txt=f.innerText;
    return {jsonNamed, hasJsonWord:/\bJSON\b/.test(txt), checkTypeSelect:!!f.querySelector('select[name*="type"]'), seconds:/\\(s\\)|\\(seconds\\)|in seconds/i.test(txt), presets:[...f.querySelectorAll('[data-cs-preset]')].map(b=>b.innerText.trim()), defaults:!!f.querySelector('[data-cs-defaults]'),
      adv:document.getElementById('cs_adv').open, scriptsOn:document.getElementById('cs_scripts_on').checked, explainers:[...f.querySelectorAll('p.small.text-muted')].length}})()`);
  assert(r.jsonNamed.length === 0, 'a field named like json: ' + r.jsonNamed.join(','));
  assert(!r.hasJsonWord && !r.checkTypeSelect && !r.seconds, 'JSON/type/seconds wording: ' + JSON.stringify(r));
  assert(JSON.stringify(r.presets) === JSON.stringify(['Recommended', 'Light', 'Strict']) && r.defaults, 'presets: ' + JSON.stringify(r.presets));
  assert(!r.adv && !r.scriptsOn, 'custom scripts must be collapsed and off by default');
  assert(r.explainers >= 3, 'each option has a one-line explanation');
  assert((await unlabeled('#cs_editor')).length === 0, 'unlabeled controls: ' + (await unlabeled('#cs_editor')).join(','));
  await shot('checks-first-look-desktop-light');
});

await T('the summary line and a read-only technical view follow the options', async () => {
  const s = await summary();
  assert(/^3 checks every 5 minutes to every hour$/.test(s), 'built-in summary: ' + s);
  const ro = await p.eval(`(()=>{const t=document.getElementById('cs_tech');return {tag:t.tagName, editable:t.isContentEditable, text:t.textContent.slice(0,120)}})()`);
  assert(ro.tag === 'PRE' && !ro.editable && /"key"/.test(ro.text), 'technical view: ' + JSON.stringify(ro));
});

await T('preset Recommended fills the options; the summary says "3 checks every 15 minutes"; Save stores them and every check verifies against the instance key', async () => {
  await p.click('[data-cs-preset=recommended]');
  await waitSummary(/^3 checks every 15 minutes$/, 'recommended summary');
  const v = await p.eval(`({disk:document.getElementById('cs_disk_warn').value, iv:document.getElementById('cs_interval').value, svc:[...document.querySelectorAll('input[name="cs[svc][]"]:checked')].map(e=>e.value)})`);
  assert(v.disk === '15' && v.iv === '900' && JSON.stringify(v.svc) === '["EventLog"]', 'values ' + JSON.stringify(v));
  await saveSettings();
  await flash(/Endpoint agent settings saved/, 'saved flash');
  const st = stored();
  assert(Array.isArray(st.stored) && st.stored.length === 3 && st.stored[0].params.warn_free_pct === 15 && st.stored[0].params.fail_free_pct === 7 && st.stored.every((c) => c.interval_s === 900), 'stored ' + JSON.stringify(st.stored));
  assert(st.signed === 3 && st.verified === 3, 'signed/verified ' + st.signed + '/' + st.verified);
  assert(st.audit_settings >= 1, 'no audit row');
});

await T('the page after saving shows what was saved (Recommended), timing 15 minutes', async () => {
  await openPage();
  const v = await p.eval(`({disk:document.getElementById('cs_disk_warn').value, iv:document.getElementById('cs_interval').value, on:document.getElementById('cs_disk_on').checked})`);
  assert(v.disk === '15' && v.iv === '900' && v.on, JSON.stringify(v));
  await waitSummary(/^3 checks every 15 minutes$/);
});

await T('switches and drop-downs: low disk space off and 4 services on -> "5 checks every 5 minutes"; Save', async () => {
  await tick('#cs_disk_on', false);
  await setSel('#cs_interval', '300');
  for (const id of ['cs_svc_wuauserv', 'cs_svc_WinDefend', 'cs_svc_Spooler']) await tick('#' + id, true);
  await waitSummary(/^5 checks every 5 minutes$/, 'five checks');
  await saveSettings();
  await flash(/Endpoint agent settings saved/);
  const st = stored();
  assert(st.stored.map((c) => c.key).join() === 'pending_reboot,svc_eventlog,svc_wuauserv,svc_windefend,svc_spooler', 'keys ' + st.stored.map((c) => c.key).join());
  assert(st.stored[0].interval_s === 300 && st.verified === 5, 'timing/verified ' + JSON.stringify([st.stored[0].interval_s, st.verified]));
});

await T('"add another service": a good name is added; a hostile one gets a plain message and Save stores nothing new', async () => {
  await openPage();
  await setVal('#cs_svc_extra', 'nginx');
  await waitSummary(/^6 checks/, 'six checks');
  await setVal('#cs_svc_extra', 'a"; rm -rf / <img src=x onerror=alert(1)>');
  await p.waitFor(`document.getElementById('cs_errors').innerText.trim().length > 0`, { label: 'inline error' });
  const e = await errorsText();
  assert(/is not a service name/.test(e) && /short service name/.test(e), 'message: ' + e);
  assert(await p.eval(`!document.querySelector('#cs_errors img, #cs_editor img')`), 'the hostile text became an element');
  const before = JSON.stringify(stored().stored);
  await saveSettings();
  await flash(/check options were not saved/, 'refusal flash');
  assert(JSON.stringify(stored().stored) === before, 'a refused save changed the stored list');
  assert(await p.eval(`!document.querySelector('img[src=x]')`), 'the hostile text became an element after the flash');
});

await T('Advanced: your own script is collapsed with a warning; limits shown; an over-long script and a bad name are refused inline; a good one saves signed', async () => {
  await openPage();
  await p.eval(`document.getElementById('cs_adv').open = true`);
  const t = await p.eval(`document.getElementById('cs_adv').innerText`);
  assert(/Careful/.test(t) && /8 KiB/.test(t) && /1 minute/.test(t) && /SYSTEM on Windows/.test(t), 'warning/limits: ' + t.slice(0, 300));
  await tick('#cs_scripts_on', true);
  await setVal('input[name="cs[scripts][0][name]"]', 'has space');
  await setVal('textarea[name="cs[scripts][0][script]"]', 'exit 0');
  await p.waitFor(`/short name/.test(document.getElementById('cs_errors').innerText)`, { label: 'name error' });
  await setVal('input[name="cs[scripts][0][name]"]', 'share_check');
  await setVal('textarea[name="cs[scripts][0][script]"]', 'x'.repeat(8300));
  await p.waitFor(`/8 KiB/.test(document.getElementById('cs_errors').innerText)`, { label: 'size error' });
  await setVal('textarea[name="cs[scripts][0][script]"]', 'if (Test-Path "C:\\\\Windows") { exit 0 } else { exit 2 }');
  await setSel('select[name="cs[scripts][0][timeout_s]"]', '10');
  await p.waitFor(`document.getElementById('cs_errors').innerText.trim()===''`, { label: 'errors clear' });
  await shot('checks-advanced-desktop-light');
  await saveSettings();
  await flash(/Endpoint agent settings saved/);
  const st = stored();
  const sc = st.stored.find((c) => c.key === 'share_check');
  assert(sc && sc.type === 'script' && sc.params.timeout_s === 10 && /Test-Path/.test(sc.params.script) && st.signed === st.verified, 'stored script ' + JSON.stringify(sc));
});

await T('script rows can be added and removed', async () => {
  await openPage();
  assert(await p.eval(`document.getElementById('cs_adv').open`), 'the Advanced section opens by itself when scripts exist');
  await p.click('[data-cs-add-script]');
  assert((await p.eval(`document.querySelectorAll('.cs-script-row').length`)) === 2, 'row added');
  await p.eval(`document.querySelectorAll('.cs-script-row')[1].querySelector('[data-cs-remove-script]').click(); true`);
  assert((await p.eval(`document.querySelectorAll('.cs-script-row').length`)) === 1, 'row removed');
});

await T('turning "Run my own scripts" off removes them on Save', async () => {
  await tick('#cs_scripts_on', false);
  await saveSettings();
  await flash(/Endpoint agent settings saved/);
  assert(!stored().stored.some((c) => c.type === 'script'), 'script still stored');
});

await T('a check the options cannot show is listed in words and kept; ticking Remove drops it', async () => {
  assert(extra('custom').ok, 'custom');
  await openPage();
  const t = await p.eval(`document.getElementById('cs_editor').innerText`);
  assert(/Other checks set up earlier/.test(t) && /Other check "cpu_hi" \(cpu\)/.test(t), 'listing: ' + t.slice(0, 400));
  await saveSettings();
  await flash(/Endpoint agent settings saved/);
  assert(stored().stored.some((c) => c.key === 'cpu_hi'), 'kept check lost');
  await openPage();
  await p.eval(`document.querySelector('input[name="cs[remove][]"][value="cpu_hi"]').click(); true`);
  await saveSettings();
  await flash(/Endpoint agent settings saved/);
  assert(!stored().stored.some((c) => c.key === 'cpu_hi'), 'cpu_hi still stored');
});

await T('Strict and Light presets; Restore defaults saves back to the built-in list', async () => {
  await openPage();
  await p.click('[data-cs-preset=strict]');
  await waitSummary(/^6 checks every 5 minutes$/, 'strict summary');
  await p.click('[data-cs-preset=light]');
  await waitSummary(/^1 check every hour$/, 'light summary');
  await p.click('[data-cs-defaults]');
  await waitSummary(/^3 checks every 5 minutes to every hour$/, 'defaults summary');
  await saveSettings();
  await flash(/Endpoint agent settings saved/);
  const st = stored();
  assert(st.stored === null && st.signed === 3 && st.verified === 3, 'defaults ' + JSON.stringify(st));
});

await T('editing after Restore defaults cancels it (the options win)', async () => {
  await openPage();
  await p.click('[data-cs-defaults]');
  await tick('#cs_reboot_on', false);
  assert((await p.eval(`document.getElementById('cs_defaults').value`)) === '', 'defaults flag still set after an edit');
});

await T('the technical view is HTML-escaped when it shows hostile text', async () => {
  await openPage();
  await tick('#cs_scripts_on', true);
  await p.eval(`document.getElementById('cs_adv').open = true`);
  await setVal('input[name="cs[scripts][0][name]"]', 'xss');
  await setVal('textarea[name="cs[scripts][0][script]"]', '</pre><img src=x onerror=alert(1)>');
  await p.waitFor(`/xss/.test(document.getElementById('cs_tech').textContent)`, { label: 'technical view' });
  assert(await p.eval(`!document.querySelector('#cs_tech img') && document.getElementById('cs_tech').textContent.includes('<img')`), 'script text must stay text');
});

await T('phone width: the editor has no horizontal scroll', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await openPage();
  await p.eval(`document.getElementById('cs_adv').open = true`);
  await noOverflow('check editor');
  await shot('checks-mobile-light');
  await p.setViewport(1366, 900);
});

await T('Agent updates card and the fleet page have a prominent Add device / Build installer button that opens the installer page', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#af-installer-link');
  const vis = await p.eval(`(()=>{const a=document.getElementById('af-installer-link');const r=a.getBoundingClientRect();return {w:r.width,h:r.height,text:a.innerText.trim(),cls:a.className}})()`);
  assert(vis.w > 100 && vis.h > 20 && /Add device \/ Build installer/.test(vis.text) && /btn-primary/.test(vis.cls), JSON.stringify(vis));
  await go('/agent/rmm_fleet.php');
  await p.waitSel('#rmm-build-installer');
  await p.clickNav('#rmm-build-installer');
  await p.waitSel('#ad-root');
  assert(/rmm_add_device\.php/.test(await p.url()), 'url ' + await p.url());
});

await T('the Mesh agent id and install argument corrections are plain fields, not JSON', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#mesh-install-advanced');
  const r = await p.eval(`(()=>{const d=document.getElementById('mesh-install-advanced');return {open:d.open, ids:d.querySelectorAll('input[name^="mesh_aid["]').length, args:d.querySelectorAll('input[name^="mesh_args["]').length, ta:[...d.querySelectorAll('textarea')].length, json:/JSON/.test(d.innerText)}})()`);
  assert(!r.open && r.ids === 8 && r.args === 2 && r.ta === 0 && !r.json, JSON.stringify(r));
});

await T('policies: the older check types have plain fields; no JSON box anywhere on the policy editor', async () => {
  await go('/agent/rmm_policies.php?new=1');
  await p.waitSel('[data-rmm-add="#pol-checks"]');
  await p.click('[data-rmm-add="#pol-checks"]');
  await sleep(300);
  const r = await p.eval(`(()=>{const sel=document.querySelector('#pol-checks select[name$="[type]"]');sel.value='service';sel.dispatchEvent(new Event('change',{bubbles:true}));
    const names=[...document.querySelectorAll('#pol-checks [name*="[lg][service]"]')].map(e=>e.name);
    return {names, json:[...document.querySelectorAll('textarea,input')].filter(e=>/json/i.test(e.name||'')).length, text:/JSON/.test(document.querySelector('#pol-checks').innerText)}})()`);
  assert(r.names.length === 3 && r.json === 0 && !r.text, JSON.stringify(r));
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
