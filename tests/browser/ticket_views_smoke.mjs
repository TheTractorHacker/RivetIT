// Browser smoke for multi-select ticket filters and the default ticket view (RivetIT).
//
//   php tests/browser/ticket_views_seed.php                      # on the throwaway install (README.md has the recipe)
//   BASE_URL=http://127.0.0.1:8471 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD='Scratch-Admin-1234' \
//     SMOKE_OUT=/tmp/tv-shots node tests/browser/ticket_views_smoke.mjs
//
// Same driver, harness and safety rules as rmm_smoke.mjs (loopback only, zero npm dependencies).
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) {
  console.error('Set BASE_URL, ADMIN_EMAIL and ADMIN_PASSWORD (after tests/browser/ticket_views_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke creates and deletes saved views; it is meant for a throwaway install.`);
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'ticket-views', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const TICKETS = '/agent/tickets.php';
const SUBJECTS = ['Alpha high mine', 'Bravo medium bob', 'Charlie low unassigned', 'Delta high cara', 'Echo medium unassigned', 'Foxtrot low bob'];

async function shot(name) {
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const shown = async () => {
  const text = await p.eval(`document.body.innerText`);
  return SUBJECTS.filter((s) => text.includes(s));
};
const same = (a, b) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort());
const search = () => p.eval(`decodeURIComponent(location.search)`);
// wait for the page to reload after a filter dropdown closes
async function closeDropdownAndWait() {
  const origin = await p.eval('performance.timeOrigin');
  await p.press('Escape');
  const end = Date.now() + 30000;
  for (;;) {
    await sleep(200);
    try { if (await p.eval(`performance.timeOrigin !== ${origin} && document.readyState === 'complete'`)) break; } catch {}
    if (Date.now() > end) throw new Error('the filter bar did not apply when the dropdown closed');
  }
  await sleep(300);
}
async function openGroup(id) {
  await p.click(`#${id}_btn`);
  await p.waitFor(`document.querySelector('#${id}_btn').getAttribute('aria-expanded') === 'true'`);
}
const tick = async (id, value) => p.click(`#${id}_btn + .dropdown-menu input[value="${value}"]`);
const count = (id) => p.eval(`(()=>{const b=document.querySelector('#${id}_btn [data-tv-count]');return b&&!b.hidden?b.textContent.trim():''})()`);

await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('tickets page: no default yet, every open ticket is shown', async () => {
  await go(TICKETS);
  await p.waitSel('.ticket-filter-bar');
  assert(same(await shown(), SUBJECTS), 'expected all six tickets, saw ' + (await shown()).join(', '));
  assert(!(await p.exists('.tv-default-banner')), 'a default banner without a default');
  await shot('01-tickets-desktop-light');
});

await T('filter bar: Priority is a checkbox dropdown; High + Medium applies when it closes, with a count badge', async () => {
  await openGroup('tvp_priority');
  assert((await p.eval(`document.querySelectorAll('#tvp_priority_btn + .dropdown-menu input[type=checkbox]').length`)) === 3, 'three priority checkboxes expected');
  assert(await p.eval(`!!document.querySelector('#tvp_priority_btn + .dropdown-menu fieldset legend')`), 'the group has no legend (accessible name)');
  await tick('tvp_priority', 'High');
  await tick('tvp_priority', 'Medium');
  assert((await count('tvp_priority')) === '2', 'the badge should say 2, says ' + (await count('tvp_priority')));
  await shot('02-priority-dropdown-open');
  await closeDropdownAndWait();
  const s = await search();
  assert(/priority\[\]=High/.test(s) && /priority\[\]=Medium/.test(s), 'URL lacks both priorities: ' + s);
  assert(same(await shown(), ['Alpha high mine', 'Bravo medium bob', 'Delta high cara', 'Echo medium unassigned']), 'wrong tickets: ' + (await shown()).join(', '));
  assert((await count('tvp_priority')) === '2', 'badge lost after reload');
  assert(await p.eval(`document.querySelector('#tvp_priority_btn + .dropdown-menu input[value=High]').checked && document.querySelector('#tvp_priority_btn + .dropdown-menu input[value=Medium]').checked`), 'the choices are not ticked after reload');
});

await T('filter bar: Select all / Clear in a group, and Assigned to me + Unassigned', async () => {
  await go(TICKETS + '?status=Open');
  await openGroup('tvp_assigned');
  await tick('tvp_assigned', 'me');
  await tick('tvp_assigned', 'unassigned');
  assert((await count('tvp_assigned')) === '2', 'assigned badge');
  await p.eval(`document.querySelector('#tvp_assigned_btn + .dropdown-menu [data-tv-clear]').click()`);
  assert((await count('tvp_assigned')) === '', 'Clear should empty the badge');
  await p.eval(`document.querySelector('#tvp_assigned_btn + .dropdown-menu [data-tv-all]').click()`);
  assert((await count('tvp_assigned')) === '4', 'Select all should tick Me, Unassigned, Bob and Cara: ' + (await count('tvp_assigned')));
  await p.eval(`document.querySelector('#tvp_assigned_btn + .dropdown-menu [data-tv-clear]').click()`);
  await tick('tvp_assigned', 'me');
  await tick('tvp_assigned', 'unassigned');
  await closeDropdownAndWait();
  assert(/assigned\[\]=me/.test(await search()) && /assigned\[\]=unassigned/.test(await search()), 'URL: ' + (await search()));
  assert(same(await shown(), ['Alpha high mine', 'Charlie low unassigned', 'Echo medium unassigned']), 'wrong tickets: ' + (await shown()).join(', '));
});

await T('filter bar: the status dropdown mixes specific statuses and the open/closed/all choice without conflict', async () => {
  await go(TICKETS + '?status=Open');
  await openGroup('tvp_status');
  assert(await p.eval(`document.querySelector('#tvp_status_btn + .dropdown-menu input[type=radio][value=Open]').checked`), 'All open should start selected');
  await p.click('#tvp_status_btn + .dropdown-menu input[type=checkbox][value="2"]');
  assert(await p.eval(`!document.querySelector('#tvp_status_btn + .dropdown-menu input[type=radio]:checked')`), 'ticking a specific status should clear the radio choice');
  await p.click('#tvp_status_btn + .dropdown-menu input[type=radio][value=Closed]');
  assert(await p.eval(`!document.querySelector('#tvp_status_btn + .dropdown-menu input[type=checkbox]:checked')`), 'choosing All closed should clear the specific statuses');
  await p.click('#tvp_status_btn + .dropdown-menu input[type=checkbox][value="2"]');
  await closeDropdownAndWait();
  assert(/status\[\]=2/.test(await search()) && !/status=/.test((await search()).replace(/status\[\]/g, '')), 'URL: ' + (await search()));
});

await T('save a view: two priorities and two agents through the checkboxes, set as my default', async () => {
  await go(TICKETS + '?status=Open&priority[]=High&priority[]=Medium');
  await p.click('.btn-add-view');
  await p.waitSel('.modal.show form input[name=name]');
  assert(await p.eval(`document.querySelector('.modal.show input[name="f_priority[]"][value=High]').checked && document.querySelector('.modal.show input[name="f_priority[]"][value=Medium]').checked && !document.querySelector('.modal.show input[name="f_priority[]"][value=Low]').checked`), 'the dialog should start from the page filters');
  assert((await p.eval(`document.querySelectorAll('.modal.show fieldset.tv-fieldset').length`)) >= 7, 'filter groups missing in the dialog');
  await p.type('.modal.show input[name=name]', 'Bob and Cara urgent');
  await p.click('.modal.show input[name="f_assigned[]"][value="2"]');
  await p.click('.modal.show input[name="f_assigned[]"][value="3"]');
  const legend = await p.eval(`document.querySelector('.modal.show input[name="f_assigned[]"][value="2"]').closest('fieldset').querySelector('[data-tv-count]').textContent.trim()`);
  assert(legend === '2', 'the group count badge should say 2: ' + legend);
  await p.click('.modal.show #savedViewDefault');
  await shot('03-save-view-dialog');
  await p.clickNav('.modal.show button[name=add_ticket_saved_view]');
  await p.waitSel('.ticket-saved-views');
  const text = await p.eval(`document.querySelector('.ticket-saved-views').innerText`);
  assert(/Bob and Cara urgent/.test(text), 'the new view is not in the sidebar');
  assert(await p.exists('.saved-view-star.is-default'), 'the new view should show the filled default star');
});

await T('default view: opening Tickets with no filter applies it, and says so', async () => {
  await go(TICKETS);
  await p.waitSel('.tv-default-banner');
  const banner = await p.eval(`document.querySelector('.tv-default-banner').innerText`);
  assert(/Bob and Cara urgent/.test(banner) && /Priority: High, Medium/.test(banner) && /Assigned to: Bob Agent, Cara Agent/.test(banner), 'banner: ' + banner);
  assert(same(await shown(), ['Bravo medium bob', 'Delta high cara']), 'wrong tickets: ' + (await shown()).join(', '));
  assert((await p.url()).endsWith(TICKETS), 'the URL should stay plain');
  await shot('04-default-applied');
});

await T('an explicit filter overrides the default; "Show all open" and Reset to my default both work', async () => {
  await go(TICKETS + '?priority[]=Low');
  assert(!(await p.exists('.tv-default-banner')), 'banner on an explicit filter');
  assert(same(await shown(), ['Charlie low unassigned', 'Foxtrot low bob']), 'wrong tickets for Low: ' + (await shown()).join(', '));
  assert(/Reset to my default/.test(await p.eval(`document.querySelector('.ticket-filter-bar').innerText`)), 'the reset button should offer the default');
  await p.clickText(/Reset to my default/, '.ticket-filter-bar');
  await p.waitSel('.tv-default-banner');
  assert(same(await shown(), ['Bravo medium bob', 'Delta high cara']), 'reset should bring the default back');
  await p.clickText('Show all open tickets', '.tv-default-banner');
  await p.waitSel('.ticket-filter-bar');
  assert(same(await shown(), SUBJECTS) && !(await p.exists('.tv-default-banner')), 'Show all open tickets should show all six');
});

await T('kanban without a filter uses the default view too', async () => {
  await go(TICKETS + '?view=kanban');
  await p.waitSel('.tv-default-banner');
  assert(same(await shown(), ['Bravo medium bob', 'Delta high cara']), 'kanban cards: ' + (await shown()).join(', '));
  await shot('05-kanban-default');
});

await T('edit the view: the dialog shows both agents ticked; untick one and save', async () => {
  await go(TICKETS + '?status=Open');
  // open the actions menu of our view and choose Edit
  await p.eval(`(()=>{const li=[...document.querySelectorAll('.ticket-saved-views li')].find(x=>/Bob and Cara urgent/.test(x.innerText));li.querySelector('.saved-view-actions button').click();})()`);
  await p.clickText('Edit', '.ticket-saved-views .dropdown-menu.show');
  await p.waitSel('.modal.show input[name=name]');
  assert(await p.eval(`document.querySelector('.modal.show input[name="f_assigned[]"][value="2"]').checked && document.querySelector('.modal.show input[name="f_assigned[]"][value="3"]').checked`), 'both agents should be ticked');
  assert(await p.eval(`document.querySelector('.modal.show #savedViewDefault').checked`), 'the default box should be ticked');
  assert(/Priority: High, Medium · Assigned to: Bob Agent, Cara Agent/.test(await p.eval(`document.querySelector('.modal.show .modal-body').innerText`)), 'the description line is missing');
  await p.click('.modal.show input[name="f_assigned[]"][value="3"]');
  await p.clickNav('.modal.show button[name=edit_ticket_saved_view]');
  await go(TICKETS);
  await p.waitSel('.tv-default-banner');
  assert(same(await shown(), ['Bravo medium bob']), 'after untick: ' + (await shown()).join(', '));
});

await T('star in the views list clears and sets the default', async () => {
  await go(TICKETS + '?status=Open');
  await p.click('.saved-view-star.is-default');
  await p.waitSel('.ticket-saved-views');
  assert(!(await p.exists('.saved-view-star.is-default')), 'the default star should be cleared');
  await go(TICKETS);
  assert(same(await shown(), SUBJECTS) && !(await p.exists('.tv-default-banner')), 'with no default the plain list returns');
  await p.eval(`(()=>{const li=[...document.querySelectorAll('.ticket-saved-views li')].find(x=>/Bob and Cara urgent/.test(x.innerText));li.querySelector('.saved-view-star').setAttribute('data-smoke-target','1');})()`);
  await p.clickNav('.saved-view-star[data-smoke-target="1"]');
  assert(await p.exists('.saved-view-star.is-default'), 'the star should be filled again');
});

await T('administrator: can make a shared view the default for everyone', async () => {
  await go(TICKETS + '?status=Open');
  await p.click('.btn-add-view');
  await p.waitSel('.modal.show input[name=name]');
  assert(await p.exists('.modal.show #savedViewSharedDefault'), 'the shared-default box is missing for an administrator');
  await p.type('.modal.show input[name=name]', 'Everyone sees this');
  await p.click('.modal.show #savedViewShared');
  await p.click('.modal.show #savedViewSharedDefault');
  await p.clickNav('.modal.show button[name=add_ticket_saved_view]');
  const text = await p.eval(`document.querySelector('.ticket-saved-views').innerText`);
  assert(/Everyone sees this\s*Default for all/.test(text.replace(/\n/g, ' ')) || /Default for all/.test(text), 'the shared default badge is missing: ' + text);
});

await T('keyboard: a filter dropdown opens with Enter, boxes toggle with Space, Escape closes and applies', async () => {
  await go(TICKETS + '?status=Open');
  await p.focus('#tvp_priority_btn');
  await p.press('Enter');
  await p.waitFor(`document.querySelector('#tvp_priority_btn').getAttribute('aria-expanded') === 'true'`);
  await p.focus('#tvp_priority_btn + .dropdown-menu input[value=Low]');
  await p.press(' ');
  assert(await p.eval(`document.querySelector('#tvp_priority_btn + .dropdown-menu input[value=Low]').checked`), 'Space should tick the box');
  await closeDropdownAndWait();
  assert(/priority\[\]=Low/.test(await search()), 'URL: ' + (await search()));
});

await T('phone width (390 px): the dropdown fits the screen and the page does not scroll sideways', async () => {
  await p.setViewport(390, 800, true);
  await go(TICKETS + '?status=Open');
  await openGroup('tvp_priority');
  const r = await p.eval(`(()=>{const m=document.querySelector('#tvp_priority_btn + .dropdown-menu').getBoundingClientRect();const d=document.documentElement;return {left:m.left,right:m.right,vw:d.clientWidth,sw:d.scrollWidth}})()`);
  assert(r.left >= 0 && r.right <= r.vw + 1, `menu outside the screen: ${JSON.stringify(r)}`);
  assert(r.sw <= r.vw + 1, `page scrolls sideways: ${JSON.stringify(r)}`);
  await shot('06-phone-dropdown');
  await p.press('Escape');
  await p.setViewport(1366, 900);
});

await T('dark mode: the dropdown and count badge are readable', async () => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='1');r.closest('label').click();})()`);
  await p.clickNav('button[name=edit_your_user_preferences]');
  await go(TICKETS + '?status=Open&priority[]=High');
  await openGroup('tvp_priority');
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'not dark');
  const c = await p.eval(`(()=>{const m=document.querySelector('#tvp_priority_btn + .dropdown-menu');const l=m.querySelector('.form-check-label');return {ink:getComputedStyle(l).color,bg:getComputedStyle(m).backgroundColor}})()`);
  const sum = (x) => x.match(/\d+/g).slice(0, 3).map(Number).reduce((a, b) => a + b, 0);
  assert(sum(c.ink) > sum(c.bg), `option text is not lighter than its menu in dark mode: ${c.ink} on ${c.bg}`);
  await shot('07-dark-dropdown');
  await p.press('Escape');
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='0');r.closest('label').click();})()`);
  await p.clickNav('button[name=edit_your_user_preferences]');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
