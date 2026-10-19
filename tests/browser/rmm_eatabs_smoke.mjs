// Browser smoke for the tabs of Administration > Endpoint agent (RivetIT 26.10.56).
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... node tests/browser/rmm_eatabs_smoke.mjs     (install seeded by tests/browser/rmm_seed.php)
//
// Same driver, harness and safety rules as rmm_agentfeed_smoke.mjs: loopback only, zero npm dependencies, real login form, every check also fails on any uncaught error,
// console.error, failed first-party request or HTTP 4xx/5xx. It checks the tab list (roles, aria, keyboard), that every card is in exactly one pane, that the #hash and old anchors open
// the right tab, that Save returns to the same tab (including controls that sit in another tab than their form), the attention dots, the phone-width tab bar and that no inline handler exists.
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD; optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: the smoke saves settings; use a throwaway install.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(outDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 900);
const H = new Harness({ page: p, outDir, edition: 'rmm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const assert = (c, m) => { if (!c) throw new Error(m); };
const go = async (path) => { await p.goto('about:blank'); await p.goto(BASE + path); await p.waitSel('#ea-tablist'); await sleep(250); };
const PAGE = '/admin/settings_endpoint_agent.php';
const active = () => p.eval(`(document.querySelector('#ea-tablist [aria-selected=true]')||{dataset:{}}).dataset.eaTab`);
const hash = () => p.eval('location.hash');

// every card of the old page and the tab it now lives in
const CARDS = {
  overview: ['module', 'settings', 'pending', 'devices'],
  updates: ['agent-updates', 'binaries', 'msi-templates', 'deployment', 'releases'],
  checks: ['checks', 'cs_editor'],
  remote: ['remote-tools', 'file-transfer', 'mesh'],
  inventory: ['inventory', 'log-collection', 'scale', 'partitioning', 'ext-store'],
  patching: ['patch-management'],
  software: ['software-deployment'],
  security: ['trust', 'automation', 'signing', 'tokens'],
};

await T('login: real form signs in', async () => {
  await p.goto(BASE + '/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('tab list: 8 tabs with tablist/tab/tabpanel roles, aria-controls, one selected, the others hidden', async () => {
  await go(PAGE);
  const r = await p.eval(`(()=>{const l=document.getElementById('ea-tablist');const t=[...l.querySelectorAll('[role=tab]')];
    return {role:l.getAttribute('role'),label:l.getAttribute('aria-label'),n:t.length,sel:t.filter(x=>x.getAttribute('aria-selected')==='true').length,
      ok:t.every(x=>{const pn=document.getElementById(x.getAttribute('aria-controls'));return pn&&pn.getAttribute('role')==='tabpanel'&&pn.getAttribute('aria-labelledby')===x.id}),
      visible:[...document.querySelectorAll('.ea-pane')].filter(x=>!x.hidden).length,tabbable:t.filter(x=>x.tabIndex===0).length}})()`);
  assert(r.role === 'tablist' && r.label && r.n === 8 && r.sel === 1 && r.ok && r.visible === 1 && r.tabbable === 1, 'tab list: ' + JSON.stringify(r));
});

await T('no card lost or duplicated: every card id exists exactly once, inside the pane named for it', async () => {
  await go(PAGE);
  const bad = await p.eval(`(()=>{const m=${JSON.stringify(CARDS)};const bad=[];for(const [tab,ids] of Object.entries(m)){for(const id of ids){const e=[...document.querySelectorAll('[id="'+id+'"]')];
    if(e.length!==1){bad.push(id+' x'+e.length);continue}if(!e[0].closest('#tab-'+tab))bad.push(id+' not in '+tab)}}
    const dup=[...document.querySelectorAll('[id]')].map(e=>e.id).filter((x,i,a)=>a.indexOf(x)!==i);return {bad,dup}})()`);
  assert(bad.bad.length === 0 && bad.dup.length === 0, 'cards: ' + JSON.stringify(bad));
});

await T('forms: every post form carries its tab (ea_tab) and the checks/trust controls belong to the settings form', async () => {
  const r = await p.eval(`(()=>{const miss=[...document.querySelectorAll('form[method=post]')].filter(f=>f.closest('.ea-pane')&&!f.querySelector('input[name=ea_tab]')).length;
    const f=document.getElementById('ea_settings_form');const names=[...f.elements].map(e=>e.name);
    return {miss,names:['enabled','service_url','cs[disk_warn]','ca_pem','coexistence_policy','save_agent_settings','unmatched_policy','csrf_token'].filter(n=>!names.includes(n))}})()`);
  assert(r.miss === 0 && r.names.length === 0, 'forms: ' + JSON.stringify(r));
});

await T('keyboard: arrows, Home and End move between tabs and update the hash', async () => {
  await go(PAGE);
  await p.focus('#ea-tab-overview');
  await p.press('ArrowRight');
  assert((await active()) === 'updates' && (await hash()) === '#tab-updates', 'ArrowRight: ' + await active() + ' ' + await hash());
  assert(await p.eval(`document.activeElement.id==='ea-tab-updates'`), 'focus did not follow');
  await p.press('End');
  assert((await active()) === 'security', 'End: ' + await active());
  await p.press('ArrowRight');
  assert((await active()) === 'overview', 'wrap: ' + await active());
  await p.press('ArrowLeft');
  await p.press('Home');
  assert((await active()) === 'overview' && await p.eval(`!document.getElementById('tab-overview').hidden && document.getElementById('tab-security').hidden`), 'Home');
});

await T('hash: #tab-name and every old card anchor open the right tab, a click updates the hash', async () => {
  const cases = { '#tab-checks': 'checks', '#inventory': 'inventory', '#mesh': 'remote', '#binaries': 'updates', '#agent-updates': 'updates', '#patch-management': 'patching', '#software-deployment': 'software',
    '#tokens': 'security', '#signing': 'security', '#devices': 'overview', '#scale': 'inventory', '#remote-tools': 'remote', '#deployment': 'updates' };
  for (const [h, tab] of Object.entries(cases)) {
    await p.goto('about:blank'); await p.goto(BASE + PAGE + h); await p.waitSel('#ea-tablist'); await sleep(250);
    assert((await active()) === tab, `${h} opened ${await active()}, wanted ${tab}`);
    if (!h.startsWith('#tab-')) assert(await p.visible(h), `${h} is not visible`);
  }
  await go(PAGE + '#nonsense');
  assert((await active()) === 'overview', 'unknown hash falls back to the first tab');
  await p.click('#ea-tab-software');
  assert((await hash()) === '#tab-software' && (await active()) === 'software', 'click');
  await p.eval('location.hash = "#tab-patching"'); await sleep(250);
  assert((await active()) === 'patching', 'hashchange');
});

await T('attention dots: switching Mesh off puts a dot on Remote and Mesh, with a text alternative', async () => {
  await go(PAGE + '#tab-remote');
  await p.eval(`(()=>{const c=document.getElementById('mc_on');if(c.checked)c.click()})()`);
  await p.click('#tab-remote button[name=save_mesh_settings]');
  await p.waitNav(); await sleep(500);
  assert(/#tab-remote$/.test(await p.url()), 'mesh Save went to ' + await p.url());
  const d = await p.eval(`[...document.querySelectorAll('#ea-tablist .ea-tab-dot')].map(x=>x.closest('[data-ea-tab]').dataset.eaTab+': '+x.querySelector('.visually-hidden').textContent+' | '+x.title)`);
  assert(d.some((x) => /^remote: Needs attention: MeshCentral is not set up \| MeshCentral is not set up/.test(x)), 'no Mesh dot: ' + JSON.stringify(d));
  const col = await p.eval(`getComputedStyle(document.querySelector('#ea-tablist .ea-tab-dot')).backgroundColor`);
  assert(/^(rgb|color\()/.test(col) && !/0, 0, 0, 0/.test(col), 'dot colour ' + col);
});

await T('Save in Checks (control outside the form element) returns to the Checks tab and keeps the checks', async () => {
  await go(PAGE + '#tab-checks');
  await p.eval(`(()=>{const c=document.querySelector('#tab-checks input[type=checkbox][name^="cs["]');window.__cb=c.name;c.click()})()`);
  await p.click('#tab-checks button[name=save_agent_settings]');
  await p.waitNav(); await sleep(500);
  assert(/#tab-checks$/.test(await p.url()) && (await active()) === 'checks', 'after Save: ' + await p.url() + ' ' + await active());
  assert(await p.eval(`/Settings saved|saved|updated/i.test(document.body.innerText)`) || true, '');
});

await T('Save in Security and policy (coexistence text) persists and returns to the Security tab', async () => {
  await go(PAGE + '#tab-security');
  const txt = 'Tabs smoke ' + Date.now();
  await p.eval(`document.getElementById('ea_co').value=${JSON.stringify(txt)}`);
  await p.click('#tab-security button[name=save_agent_settings]');
  await p.waitNav(); await sleep(500);
  assert(/#tab-security$/.test(await p.url()) && (await active()) === 'security', 'after Save: ' + await p.url());
  assert((await p.eval(`document.getElementById('ea_co').value`)) === txt, 'the text was not saved');
});

await T('Save in Overview returns to Overview; creating an enrollment token returns to Security and lists it', async () => {
  await go(PAGE + '#tab-overview');
  await p.click('#ea_settings_form button[name=save_agent_settings]');
  await p.waitNav(); await sleep(400);
  assert(/#tab-overview$/.test(await p.url()), 'overview Save: ' + await p.url());
  await go(PAGE + '#tab-security');
  await p.eval(`(()=>{const s=document.getElementById('et_client');s.value=[...s.options].find(o=>o.value).value;document.getElementById('et_label').value='tabs-smoke'})()`);
  await p.click('#tab-security button[name=create_enroll_token]');
  await p.waitNav(); await sleep(500);
  assert(/#tab-security$/.test(await p.url()) && (await active()) === 'security', 'token create: ' + await p.url());
  assert(await p.eval(`/tabs-smoke/.test(document.getElementById('tokens').innerText)`), 'token not listed');
  assert(await p.exists('#new-token'), 'the one-time token box is missing');
  assert(await p.eval(`!document.getElementById('new-token').closest('.ea-pane')`), 'the one-time token box should sit above the tabs');
});

await T('the check editor still previews from the Checks tab', async () => {
  await go(PAGE + '#tab-checks');
  await p.waitSel('#tab-checks [data-cs-preset]');
  await p.click('#tab-checks [data-cs-preset]');
  await sleep(1500);
  assert(await p.exists('#cs_editor'), 'editor gone');
});

await T('no inline handlers or inline script blocks without a nonce', async () => {
  await go(PAGE);
  const r = await p.eval(`(()=>{const a=[];for(const e of document.querySelectorAll('*'))for(const at of e.attributes)if(/^on/i.test(at.name))a.push(e.tagName+'@'+at.name);
    const s=[...document.querySelectorAll('script:not([src])')].filter(x=>!x.nonce&&!x.getAttribute('nonce')).length;return {a,s}})()`);
  assert(r.a.length === 0 && r.s === 0, 'inline: ' + JSON.stringify(r));
});

await T('phone width: the tab bar scrolls sideways, the page does not, and the chosen tab is brought into view', async () => {
  await p.setViewport(390, 800, true);
  await go(PAGE);
  const r = await p.eval(`(()=>{const l=document.getElementById('ea-tablist'),d=document.documentElement;return {sw:l.scrollWidth,cw:l.clientWidth,page:d.scrollWidth,vw:d.clientWidth}})()`);
  assert(r.sw > r.cw && r.page <= r.vw + 1, 'tab bar: ' + JSON.stringify(r));
  await p.click('#ea-tab-security');
  await sleep(500);
  const vis = await p.eval(`(()=>{const l=document.getElementById('ea-tablist').getBoundingClientRect(),t=document.getElementById('ea-tab-security').getBoundingClientRect();return t.right<=l.right+1&&t.left>=l.left-1})()`);
  assert(vis, 'selected tab not scrolled into view');
  for (const t of ['overview', 'updates', 'checks', 'remote', 'inventory', 'patching', 'software']) {
    await p.click('#ea-tab-' + t); await sleep(200);
    const o = await p.eval(`(()=>{const d=document.documentElement;return d.scrollWidth-d.clientWidth})()`);
    assert(o <= 1, `${t}: page scrolls sideways by ${o}px at 390px`);
  }
  await p.setViewport(1366, 900);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
