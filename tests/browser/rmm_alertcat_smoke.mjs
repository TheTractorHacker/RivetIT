// Browser smoke for Administration > Settings > Integrations > RMM > "Alert tickets" (RivetIT 26.10.62).
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... node tests/browser/rmm_alertcat_smoke.mjs
//
// Throwaway install with the RMM module on, at least one Ticket category (e.g. "Hardware") and none called Maintenance. Same driver and safety rules as
// rmm_eatabs_smoke.mjs: loopback only, no npm dependencies, real login form, every check also fails on any uncaught error, console.error, failed
// first-party request or HTTP 4xx/5xx. It drives the card like a person: the one-click Maintenance button, the live sentence, adding / filling /
// removing a route, saving, reloading to see it kept, phone width, and that no inline handler exists.
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
const H = new Harness({ page: p, outDir, edition: 'alertcat', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const assert = (c, m) => { if (!c) throw new Error(m); };
const PAGE = '/admin/settings_integrations.php?tab=rmm';
const go = async () => { await p.goto('about:blank'); await p.goto(BASE + PAGE); await p.waitSel('#alert-ticket-card'); await sleep(250); };
const note = () => p.eval(`document.getElementById('alert-ticket-note-text').textContent`);
const setSel = (sel, val) => p.eval(`(()=>{const s=document.querySelector(${JSON.stringify(sel)});s.value=${JSON.stringify(val)};s.dispatchEvent(new Event('change',{bubbles:true}));return s.value})()`);

await T('login: real form signs in', async () => {
  await p.goto(BASE + '/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('card: shows the category list, the routes table, the note and the Maintenance button', async () => {
  await go();
  const r = await p.eval(`(()=>{const c=document.getElementById('alert-ticket-card');return {sel:!!c.querySelector('#alert_ticket_category_id'),add:!!c.querySelector('#alert-route-add'),
    maint:[...c.querySelectorAll('button')].some(b=>/Create a Maintenance category/.test(b.textContent)),inline:c.querySelectorAll('[onclick],[onchange],[onsubmit]').length,
    heading:c.querySelector('.card-title').textContent.trim(),opts:c.querySelectorAll('#alert_ticket_category_id option').length}})()`);
  assert(r.sel && r.add && r.maint && r.inline === 0 && r.heading === 'Alert tickets' && r.opts >= 2, 'card: ' + JSON.stringify(r));
});

await T('Maintenance button: creates the category and selects it as the default', async () => {
  await p.clickNav('button[name=create_rmm_alert_maintenance_category]');
  await p.waitSel('#alert-ticket-card');
  const r = await p.eval(`(()=>{const s=document.getElementById('alert_ticket_category_id');return {name:s.options[s.selectedIndex].text,maint:[...document.querySelectorAll('button[name=create_rmm_alert_maintenance_category]')].length}})()`);
  assert(r.name === 'Maintenance' && r.maint === 0, 'after the click: ' + JSON.stringify(r));
});

await T('live note: follows the severity boxes and the category', async () => {
  await go();
  await p.eval(`document.querySelectorAll('input[name="auto_ticket_severities[]"]').forEach(b=>{if(b.checked)b.click()})`);
  assert(/is off/.test(await note()), 'no severities: ' + await note());
  await p.click('#rmm_auto_ticket_critical'); await p.click('#rmm_auto_ticket_warning');
  assert((await note()) === 'New alerts at severity critical and warning create a ticket in the "Maintenance" category.', 'two severities: ' + await note());
  await setSel('#alert_ticket_category_id', '0');
  assert(/with no category/.test(await note()), 'no category: ' + await note());
  await setSel('#alert_ticket_category_id', await p.eval(`[...document.querySelectorAll('#alert_ticket_category_id option')].find(o=>o.text==='Maintenance').value`));
});

await T('routes: add a row, fill it, see the note count it, remove it', async () => {
  await p.click('#alert-route-add');
  assert((await p.eval(`document.querySelectorAll('#alert-route-rows tr').length`)) === 1, 'row not added');
  assert(/\(1\)\.$/.test(await note()), 'note: ' + await note());
  await p.click('.alert-route-remove');
  assert((await p.eval(`document.querySelectorAll('#alert-route-rows tr').length`)) === 0 && !/routes below/.test(await note()), 'row not removed: ' + await note());
});

await T('save: severities, a default and two routes survive a reload', async () => {
  await go();
  await p.eval(`(()=>{const b=document.getElementById('rmm_auto_ticket_critical');if(!b.checked)b.click()})()`);   // the severities have their own Save button
  await p.clickNav('button[name=save_rmm_auto_ticket_settings]');
  await p.waitSel('#alert-ticket-card');
  await p.click('#alert-route-add'); await p.click('#alert-route-add');
  const hw = await p.eval(`[...document.querySelectorAll('#alert_ticket_category_id option')].find(o=>o.text!=='Maintenance'&&o.value!=='0').value`);
  await setSel('#alert-route-rows tr:nth-child(1) select[name="route_when[]"]', 'check_prefix:disk');
  await setSel('#alert-route-rows tr:nth-child(1) select[name="route_category[]"]', hw);
  await setSel('#alert-route-rows tr:nth-child(1) select[name="route_priority[]"]', 'Low');
  await setSel('#alert-route-rows tr:nth-child(2) select[name="route_when[]"]', 'severity:warning');
  await setSel('#alert-route-rows tr:nth-child(2) select[name="route_category[]"]', hw);
  assert(/\(2\)\.$/.test(await note()), 'note: ' + await note());
  await p.clickNav('button[name=save_rmm_alert_ticket_settings]');
  await p.waitSel('#alert-ticket-card');
  const r = await p.eval(`(()=>[...document.querySelectorAll('#alert-route-rows tr')].map(t=>[...t.querySelectorAll('select')].map(s=>s.value).join('|')))()`);
  assert(r.length === 2 && r[0].startsWith('check_prefix:disk|' + hw + '|Low') && r[1].startsWith('severity:warning|' + hw + '|'), 'routes after save: ' + JSON.stringify(r));
  assert(/\(2\)\.$/.test(await note()), 'note after reload: ' + await note());
});

await T('save: an untouched blank row is ignored and the saved routes stay', async () => {
  await p.click('#alert-route-add');
  await p.clickNav('button[name=save_rmm_alert_ticket_settings]');
  await p.waitSel('#alert-ticket-card');
  const n = await p.eval(`document.querySelectorAll('#alert-route-rows tr').length`);
  assert(n === 2, 'routes after saving with a blank row: ' + n);
});

await T('cleanup: clear the routes (back to today\'s behaviour)', async () => {
  await go();
  await p.eval(`document.querySelectorAll('.alert-route-remove').forEach(b=>b.click())`);
  await setSel('#alert_ticket_category_id', '0');
  await p.clickNav('button[name=save_rmm_alert_ticket_settings]');
  await p.waitSel('#alert-ticket-card');
  assert((await p.eval(`document.querySelectorAll('#alert-route-rows tr').length`)) === 0 && (await p.eval(`document.getElementById('alert_ticket_category_id').value`)) === '0', 'not cleared');
});

await T('phone width: the card does not scroll the page sideways and the route table scrolls inside', async () => {
  await p.setViewport(390, 800, true);
  await go();
  await p.click('#alert-route-add');
  const o = await p.eval(`(()=>{const d=document.documentElement;return d.scrollWidth-d.clientWidth})()`);
  assert(o <= 1, `page scrolls sideways by ${o}px`);
  await p.setViewport(1366, 900);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
