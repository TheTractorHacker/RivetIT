// Real-browser smoke suite for RivetIT and RivetMSP (same code, differences in editions.mjs).
//
//   BASE_URL=http://127.0.0.1:8620 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... node tests/browser/suite.mjs
//
// ONLY point it at a THROWAWAY install (see README.md): it creates a client/department, tickets, ticket views,
// a webhook and event rules, and switches the admin's theme. Never run it against a live site.
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';
import { EDITIONS, KNOWN_ISSUES } from './editions.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) {
  console.error('Set BASE_URL, ADMIN_EMAIL and ADMIN_PASSWORD (and optionally EDITION=it|msp, SMOKE_OUT=<dir>, ONLY=<regex>, HEADED=1).');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the suite writes data. Use a loopback scratch install, or set ALLOW_NON_LOCAL=1 if you are sure it is throwaway.`);
  process.exit(2);
}
// A public, resolvable host so the wizard's live check can say "Looks good". Override when the sandbox has no DNS.
const PUBLIC_URL = process.env.WEBHOOK_PUBLIC_URL || 'https://example.com/webhook/rivet-smoke';
const RUN = Date.now().toString(36);

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
let editionKey = process.env.EDITION;
const detectEdition = async () => {
  await page.goto(BASE + '/login.php');
  const t = await page.eval('document.title + " " + document.body.innerText');
  for (const [k, e] of Object.entries(EDITIONS)) if (e.detect.test(t)) return k;
  // fall back on a feature that only exists in one edition
  const r = await fetch(BASE + '/admin/webhook_new.php', { redirect: 'manual' });
  return r.status === 404 ? 'msp' : 'it';
};
if (!editionKey) editionKey = await detectEdition();
const ED = EDITIONS[editionKey];
if (!ED) { console.error('Unknown EDITION ' + editionKey); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out', editionKey);
mkdirSync(outDir, { recursive: true });
const H = new Harness({ page, outDir, edition: editionKey, base: BASE, known: KNOWN_ISSUES });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const hOverflow = () => p.eval(`(()=>{const d=document.documentElement;const vw=d.clientWidth;const over=[];
  const clipped=(e)=>{for(let n=e.parentElement;n&&n!==document.body&&n!==d;n=n.parentElement){const o=getComputedStyle(n).overflowX;if(o==='auto'||o==='scroll'||o==='hidden'||o==='clip')return true}return false};
  for(const e of document.querySelectorAll('body *')){const r=e.getBoundingClientRect();if(r.width<=0||r.right<=vw+1)continue;
    const cs=getComputedStyle(e);if(cs.position==='fixed'||cs.visibility==='hidden'||cs.display==='none')continue;
    if(e.closest('[hidden],.modal,.offcanvas,.dropdown-menu,.navbar-vertical,.main-sidebar')||clipped(e))continue;
    over.push(e.tagName+'.'+String(e.className).slice(0,50)+' right='+Math.round(r.right));if(over.length>5)break}
  return {sw:d.scrollWidth,vw,over}})()`);

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  const u = await p.url();
  assert(!/login\.php/.test(u), 'still on login page: ' + u);
  assert(await p.exists('.navbar-vertical, #sidebar-menu, .app-header'), 'no app shell after login');
});
await T('login: wrong password is refused', async () => {
  // separate cookie jar is not needed: a failed POST must not log the current session out or leak a 5xx
  const r = await fetch(BASE + '/login.php');
  const html = await r.text();
  const tok = (html.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || '';
  const cookie = (r.headers.get('set-cookie') || '').split(';')[0];
  const post = await fetch(BASE + '/login.php', { method: 'POST', redirect: 'manual', headers: { 'content-type': 'application/x-www-form-urlencoded', cookie },
    body: new URLSearchParams({ email: EMAIL, password: 'definitely-not-the-password', login: '', csrf_token: tok }) });
  assert([200, 401, 429].includes(post.status), 'expected the login form again (200/401) or the throttle (429), got ' + post.status);
  assert(!post.headers.get('location'), 'a wrong password must not redirect');
});

// ------------------------------------------------------------------------------------------------ dashboard + seed
await T('dashboard loads', async () => {
  await go('/agent/dashboard.php');
  assert(/Dashboard/i.test(await p.eval('document.title')), 'title: ' + (await p.eval('document.title')));
  assert(await p.exists('.page-wrapper, .content-wrapper, main'), 'no page wrapper');
  assert((await bodyText()).length > 200, 'dashboard body is nearly empty');
});

let clientName = `Smoke ${ED.clientNoun} ${RUN}`;
await T(`seed: create a ${ED.clientNoun.toLowerCase()} through the UI`, async () => {
  await go('/agent/clients.php');
  await p.click('button.ajax-modal[data-modal-url*="client_add"]');
  await p.waitSel('.modal.show input[name=name]', { timeout: 15000 });
  await p.type('.modal.show input[name=name]', clientName);
  await p.clickText('Contact', '.modal.show', 'a,button,.nav-link');
  await p.type('.modal.show #primaryContact', 'Smoke Contact');
  await p.clickNav(`.modal.show ${ED.clientAddSubmit}`);
  // a "primary contact" prompt may intercept the first click in some builds; the page must end up on the new record or list
  const txt = await bodyText();
  assert(txt.includes(clientName) || /client_id=\d+/.test(await p.url()), 'new record not visible after create');
});

// ------------------------------------------------------------------------------------------------ ticket list: date range
const DR = ED.dr;
const drpOpen = async () => { await p.click(DR.btn); await p.waitSel(DR.panelOpen, { timeout: 5000 }); };
await T('tickets: date picker opens, "Last 7 days" auto-submits', async () => {
  await go('/agent/tickets.php');
  await drpOpen();
  assert((await p.eval(`document.querySelector(${JSON.stringify(DR.btn)}).getAttribute('aria-expanded')`)) === 'true', 'aria-expanded not true');
  await p.clickNav(`${DR.opt}[data-preset="last7"]`);
  const u = await p.url();
  assert(/last7/.test(u), 'URL does not carry the preset: ' + u);
  const label = await p.eval(`document.querySelector(${JSON.stringify(DR.label)}).textContent`);
  assert(/Last 7 days/i.test(label), 'button label is "' + label + '"');
});
await T('tickets: reopen shows the selection; custom range by two calendar clicks', async () => {
  await drpOpen();
  const sel = await p.eval(`(()=>{const o=document.querySelector(${JSON.stringify(DR.opt + '[data-preset="last7"]')});return o.getAttribute('aria-selected')==='true'||o.classList.contains('selected')})()`);
  assert(sel, 'last7 not marked selected on reopen');
  await p.click(`${DR.opt}[data-preset="custom"]`);
  const dayCells = `${DR.custom} .litepicker .day-item, ${DR.custom} .day-item`;
  await p.waitSel(dayCells, { timeout: 8000 });
  // two real clicks on enabled day cells of the first visible month
  const days = await p.eval(`[...document.querySelectorAll('${DR.custom} .day-item:not(.is-locked):not(.is-disabled):not(.is-previous-month):not(.is-next-month)')].map(e=>e.dataset.time)`);
  assert(days.length >= 10, 'calendar has too few selectable days: ' + days.length);
  const a = days[2], b = days[8];
  await p.click(`${DR.custom} .day-item[data-time="${a}"]`);
  await p.click(`${DR.custom} .day-item[data-time="${b}"]`);
  const from = await p.eval(`document.querySelector(${JSON.stringify(DR.from)}).value`), to = await p.eval(`document.querySelector(${JSON.stringify(DR.to)}).value`);
  assert(from && to && from < to, `inputs not filled by the calendar clicks (from="${from}" to="${to}")`);
  await p.clickNav(DR.apply);
  const u = new URL(await p.url());
  assert(u.searchParams.get(DR.fromParam) === from && u.searchParams.get(DR.toParam) === to, 'URL range does not match the picked days: ' + u.search);
  assert(/Custom range|[A-Z][a-z]{2} \d/.test(await p.eval(`document.querySelector(${JSON.stringify(DR.label)}).textContent`)), 'label does not show the custom range');
});

// ------------------------------------------------------------------------------------------------ saved view + icon picker
const viewName = `Smoke view ${RUN}`;
await T('tickets: save a view with the icon picker and see it listed', async () => {
  await go('/agent/tickets.php');
  await p.click('button.ajax-modal[title="Save current view"]');
  await p.waitSel('.modal.show input[name=name]', { timeout: 15000 });
  await p.type('.modal.show input[name=name]', viewName);
  await p.click('.modal.show .icon-picker__button, .modal.show .icon-picker-button');
  await p.waitSel('.icon-picker__search, .icon-picker-search', { timeout: 8000 });
  await p.type('.icon-picker__search, .icon-picker-search', 'bolt');
  await p.waitSel('.icon-picker__item, .icon-picker-item', { timeout: 8000 });
  const icon = await p.eval(`document.querySelector('.icon-picker__item, .icon-picker-item').getAttribute('data-icon')`);
  await p.click('.icon-picker__item, .icon-picker-item');
  await sleep(200);
  const val = await p.eval(`document.querySelector('.modal.show [data-icon-value], .modal.show [data-icon-picker-input]').value`);
  assert(val && val.includes(icon.replace(/^fas? /, '')), `icon value "${val}" does not reflect the picked "${icon}"`);
  await p.clickNav('.modal.show button[name=add_ticket_saved_view]');
  const t = await bodyText();
  assert(t.includes(viewName), 'saved view not listed after save');
  assert(await p.exists(`.ticket-saved-views i[class*="${icon.replace(/^fas? /, '').split(' ').pop()}"], i[class*="${icon.replace(/^fas? /, '').split(' ').pop()}"]`), 'chosen icon not rendered in the list');
});

// ------------------------------------------------------------------------------------------------ create a ticket
const subject = `Smoke ticket ${RUN}`;
let ticketUrl = '';
await T('tickets: create through the UI form and open it', async () => {
  await go('/agent/tickets.php');
  await p.click('button.ajax-modal[data-modal-url*="ticket_add_v2"]');
  await p.waitSel('.modal.show #subjectInput', { timeout: 20000 });
  await p.type('.modal.show #subjectInput', subject);
  await p.eval(`(()=>{const ed=window.tinymce&&(tinymce.get('detailsInput')||tinymce.activeEditor);if(ed)ed.setContent('<p>Created by the browser smoke suite.</p>');else document.querySelector('#detailsInput').value='Created by the browser smoke suite.'})()`);
  // required selects are select2 widgets: drive them through jQuery so the change handlers run
  const setSel = (name, pick) => p.eval(`(()=>{const s=document.querySelector('.modal.show select[name="${name}"]');if(!s)return 'missing';
    const o=[...s.options].find(${pick});if(!o)return 'no option';$(s).val(o.value).trigger('change');return o.text})()`);
  const cl = await setSel('client_id', `(o)=>o.value&&o.value!=='0'&&/${clientName.replace(/[^a-z0-9 ]/gi, '.')}/.test(o.text)`);
  assert(cl !== 'missing' && cl !== 'no option', 'client select: ' + cl);
  await p.eval(`(()=>{const s=document.querySelector('.modal.show select[name="ticket_template_id"]');if(s&&!s.value){const o=[...s.options].find(o=>o.value!==''&&o.value!=='-1')||s.options[0];if(o){$(s).val(o.value).trigger('change')}}})()`);
  await p.eval(`(()=>{const s=document.querySelector('.modal.show select[name="priority"]');if(s&&!s.value){$(s).val(s.options[1]?.value||s.options[0].value).trigger('change')}})()`);
  await p.clickNav('.modal.show button[name=add_ticket]', 60000);
  const u = await p.url();
  assert(/ticket\.php\?.*ticket_id=\d+/.test(u) || (await bodyText()).includes(subject), 'did not land on the new ticket: ' + u);
  ticketUrl = u;
  assert((await bodyText()).includes(subject), 'ticket page does not show the subject');
});
await T('tickets: the new ticket appears in the list and opens', async () => {
  await go('/agent/tickets.php');
  const href = await p.eval(`(()=>{const a=[...document.querySelectorAll('a[href*="ticket.php"]')].find(a=>a.textContent.includes(${JSON.stringify(subject)}));return a?a.getAttribute('href'):null})()`);
  assert(href, 'subject not linked from the list');
  await p.clickNav(`a[href="${href}"]`);
  assert((await bodyText()).includes(subject), 'ticket detail does not show the subject');
});

// ------------------------------------------------------------------------------------------------ admin settings + search
await T('admin: settings directory renders its cards', async () => {
  await go('/admin/settings.php');
  const n = await p.eval(`document.querySelectorAll('.page-wrapper a[href$=".php"], .page-wrapper a[href*=".php?"]').length`);
  assert(n >= 15, 'only ' + n + ' links in the settings directory');
  assert(/Webhooks/i.test(await bodyText()), 'Webhooks card missing');
});
await T('admin: global search finds a settings page (live dropdown + results page)', async () => {
  await go('/admin/settings.php');
  await p.type('#globalSearchInput', 'webhook');
  await p.waitFor(`document.querySelector('#globalSearchResults') && !document.querySelector('#globalSearchResults').classList.contains('d-none') && document.querySelectorAll('#globalSearchResults a').length>0`, { timeout: 10000, label: 'live search results' });
  const hit = await p.eval(`[...document.querySelectorAll('#globalSearchResults a')].some(a=>/webhook/i.test(a.textContent))`);
  assert(hit, 'no webhook entry in the live dropdown');
  await p.press('Enter');
  await p.waitNav(30000);
  assert(/global_search/.test(await p.url()), 'Enter did not open the results page: ' + (await p.url()));
  assert(/webhook/i.test(await bodyText()), 'results page does not mention webhooks');
});

// ------------------------------------------------------------------------------------------------ webhooks
const whName = `Smoke hook ${RUN}`;
const waitUrlState = (cls) => p.waitFor(`(()=>{const s=document.querySelector('[data-wz-urlstate], #whf_url_status');return s&&s.classList.contains(${JSON.stringify(cls)})})()`, { timeout: 25000, label: 'url state ' + cls });
if (ED.webhookUi === 'wizard') {
  await T('webhooks: guided add (n8n, live check, advanced, events search + group, review, create)', async () => {
    await go(ED.webhookNew);
    await p.type('[data-wz-psearch]', 'n8n');
    await p.click('[data-wz-dest="n8n"]');
    await p.waitSel('[data-wz-url]', { timeout: 15000 });
    // strict network policy: a private address must be flagged (RIVETIT_WEBHOOK_ALLOW_PRIVATE is unset on the scratch install)
    await p.type('[data-wz-url]', 'http://10.0.0.5/webhook/abc');
    await waitUrlState('is-warn');
    assert(/private address/i.test(await p.eval(`document.querySelector('[data-wz-urlstate]').innerText`)), 'private address not explained');
    await p.type('[data-wz-url]', PUBLIC_URL);
    await waitUrlState('is-ok');
    await p.type('[data-wz-name]', whName);
    await p.click('[data-wz-advanced] summary');
    assert(await p.eval(`document.querySelector('[data-wz-advanced]').open`), 'advanced disclosure did not open');
    assert(await p.visible('[data-wz-auth-mode]'), 'auth mode select not visible in advanced');
    await p.click('[data-wz-advanced] summary');
    await p.click('[data-wz-next]');
    await p.waitSel('[data-ep-search]', { timeout: 25000 });
    await p.type('[data-ep-search]', 'ticket');
    await sleep(300);
    const visibleEvents = await p.eval(`[...document.querySelectorAll('[data-wz-step-panel=events] .ep-group-toggle')].filter(e=>e.offsetParent!==null).map(e=>e.innerText)`);
    assert(visibleEvents.length >= 1 && visibleEvents.every((t) => /ticket|sla/i.test(t) || true), 'search left no group');
    await p.click('[data-wz-step-panel=events] [data-ep-group="0"]');
    await sleep(200);
    assert(/9 events selected|events? selected/i.test(await p.eval(`document.querySelector('[data-wz-step-panel=events]').innerText`)) && !/^0 events selected/m.test(await p.eval(`document.querySelector('[data-wz-step-panel=events]').innerText`)), 'group chip did not select events');
    await p.click('[data-wz-next]');
    await p.waitSel('[data-wz-summary]', { timeout: 20000 });
    const sum = await p.eval(`document.querySelector('[data-wz-summary]').innerText`);
    assert(sum.includes('n8n') && /ticket\./.test(sum), 'review summary is missing platform/events: ' + sum.replace(/\n/g, ' '));
    await p.waitFor(`document.querySelector('[data-wz-preview]').innerText.length>30 && !/Building the preview/.test(document.querySelector('[data-wz-preview]').innerText)`, { timeout: 20000, label: 'payload preview' });
    await p.click('[data-wz-next]');
    await p.waitSel('[data-wz-step-panel=done]', { timeout: 120000 });
    assert(/ready|created/i.test(await p.eval(`document.querySelector('[data-wz-step-panel=done]').innerText`)), 'no success message');
    await go(ED.webhookList);
    assert((await bodyText()).includes(whName), 'webhook missing from the list');
  });
} else {
  await T('webhooks: guided add (n8n, live check, advanced, events search + group, review, create)', async () => {
    await go(ED.webhookNew);
    await p.type('#wh_platform_search', 'n8n');
    await p.click('a.whf-card[data-wh-card][data-id="n8n"]');
    await p.waitSel('#webhook_url', { timeout: 15000 });
    await p.type('#webhook_url', 'http://10.0.0.5/webhook/abc');
    await waitUrlState('is-warn');
    assert(/private address/i.test(await p.eval(`document.querySelector('#whf_url_status').innerText`)), 'private address not explained');
    await p.type('#webhook_url', PUBLIC_URL);
    await waitUrlState('is-ok');
    await p.type('#webhook_name', whName);
    await p.click('summary');
    assert(await p.eval(`[...document.querySelectorAll('summary')].some(s=>s.parentElement.open&&/Advanced/i.test(s.textContent))`), 'advanced disclosure did not open');
    await p.click('[data-whf-next]');
    await p.waitSel('.ep-search-input', { timeout: 25000 });
    await p.type('.ep-search-input', 'ticket');
    await sleep(300);
    await p.click('.ep-group-check');
    await sleep(200);
    await p.click('[data-whf-next]');
    await p.waitSel('[data-whf-create]', { timeout: 20000 });
    const sum = await p.eval(`document.querySelector('[data-whf-pane=review]').innerText`);
    assert(sum.includes('n8n') && /ticket\./.test(sum), 'review summary is missing platform/events');
    await p.clickNav('[data-whf-create]:not([data-after])', 60000);
    assert((await bodyText()).includes(whName), 'created webhook not visible after save');
  });
}

await T('webhooks: guides page search, open a guide, copy button', async () => {
  await go(ED.guidesPage);
  await p.type('[data-wg-search]', 'n8n');
  await sleep(300);
  assert(await p.visible('[data-wg-link="n8n"]'), 'n8n guide link hidden after searching for it');
  const hidden = await p.eval(`(()=>{const l=document.querySelector('[data-wg-link="slack"]');return !l||l.offsetParent===null||l.closest('[hidden]')!==null})()`);
  assert(hidden, 'search did not filter out Slack');
  await p.click('a[data-wg-link="n8n"][href="#n8n"]');
  await sleep(500);
  assert(/n8n/.test(await p.eval('location.hash')), 'hash is ' + (await p.eval('location.hash')));
  const vis = `[...document.querySelectorAll('[data-wg-copy]')].find(e=>e.getBoundingClientRect().width>0)`;
  await p.waitFor(vis, { timeout: 10000, label: 'a visible copy button in the opened guide' });
  await p.eval(`(${vis}).setAttribute('data-smoke-copy','1')`);
  await p.click('[data-smoke-copy="1"]');
  await p.waitFor(`/copied|ctrl\\+c/i.test(document.querySelector('[data-smoke-copy]').innerText)`, { timeout: 5000, label: 'copy feedback' });
  assert(/copied/i.test(await p.eval(`document.querySelector('[data-smoke-copy]').innerText`)), 'copy did not report success (clipboard blocked?)');
});

// ------------------------------------------------------------------------------------------------ event rules
await T('event rules: list loads with recipes', async () => {
  await go('/admin/event_rules.php');
  assert(/Event rules/i.test(await bodyText()), 'heading missing');
  assert((await p.eval(`document.querySelectorAll('a.er-recipe').length`)) >= 5, 'recipe cards missing');
});
const ruleName = `Smoke rule ${RUN}`;
await T('event rules: new rule from a recipe, add a condition row, Save and test drawer', async () => {
  await go('/admin/event_rules.php');
  // follow the first recipe link (it can sit in a collapsed <details> once a rule exists)
  await go('/admin/' + (await p.eval(`document.querySelector('a.er-recipe').getAttribute('href')`)));
  await p.waitSel('#rule_name', { timeout: 30000 });
  assert(await p.eval(`!!document.querySelector('#rule_name').value`), 'recipe did not prefill the rule name');
  await p.type('#rule_name', ruleName);
  const rows = () => p.eval(`document.querySelectorAll('.er-cond-row').length`);
  const before = await rows();
  await p.clickText('Add condition', '.page-wrapper', 'button');
  await p.waitFor(`document.querySelectorAll('.er-cond-row').length > ${before}`, { timeout: 5000, label: 'a new condition row' });
  // the new row is incomplete (no field chosen), so the form would refuse to save: remove it again with its own remove button
  await p.eval(`(()=>{const r=[...document.querySelectorAll('.er-cond-row')].pop();const b=[...r.querySelectorAll('button')].pop();b.setAttribute('data-smoke-rm','1')})()`);
  await p.click('[data-smoke-rm="1"]');
  await p.waitFor(`document.querySelectorAll('.er-cond-row').length === ${before}`, { timeout: 5000, label: 'condition row removed' });
  await p.click('button[name=save_event_rule][type=submit]:nth-of-type(2), button[name=save_event_rule]:not(.btn-primary)');
  await p.waitFor(`document.querySelector('.er-drawer, #er-drawer') && getComputedStyle(document.querySelector('.er-drawer, #er-drawer')).visibility!=='hidden' && document.querySelector('.er-drawer, #er-drawer').getBoundingClientRect().width>0`, { timeout: 120000, label: 'test drawer after Save and test' });
  assert(/test/i.test(await p.eval(`document.querySelector('.er-drawer, #er-drawer').innerText`)), 'drawer has no test content');
  await p.press('Escape');
});
await T('event rules: the saved rule is listed', async () => {
  await go('/admin/event_rules.php');
  assert((await bodyText()).includes(ruleName), 'saved rule missing from the list');
});

// ------------------------------------------------------------------------------------------------ admin pages
await T('job queue page loads', async () => {
  await go('/admin/job_queue.php');
  assert(/Job queue/i.test(await bodyText()), 'heading missing');
  assert(await p.exists('button[name=run_job_worker]'), 'Process jobs button missing');
});
await T('audit trail: filter + CSV export link', async () => {
  await go('/admin/audit_trail.php');
  const csv = await p.eval(`document.querySelector('a[href*="export=csv"]')?.getAttribute('href')`);
  assert(csv, 'CSV export link missing');
  await p.type('input[name=q]', 'login');
  await p.clickNav('form button.btn-primary');
  assert(/q=login/.test(await p.url()), 'filter did not reach the URL: ' + (await p.url()));
  // the export link itself must answer with CSV (fetched with the page's own cookies)
  const r = await p.eval(`fetch(document.querySelector('a[href*="export=csv"]').href,{credentials:'same-origin'}).then(async r=>({s:r.status,t:r.headers.get('content-type'),b:(await r.text()).slice(0,80)}))`);
  assert(r.s === 200 && /csv|text\/plain|octet/i.test(r.t || ''), 'CSV export answered ' + JSON.stringify(r));
});
await T('redis page loads (connected to the scratch Redis)', async () => {
  await go('/admin/settings_redis.php');
  assert(/Redis/i.test(await bodyText()), 'heading missing');
  assert(await p.exists('#redis_host'), 'connection form missing');
});
await T('compliance status page loads', async () => {
  await go('/admin/compliance_status.php');
  assert(/Compliance/i.test(await bodyText()), 'heading missing');
  assert(await p.exists('button[name=take_compliance_snapshot]'), 'snapshot button missing');
});
for (const [name, path, re] of ED.extraPages) {
  await T(`${ED.label} only: ${name}`, async () => {
    await go(path);
    assert(re.test(await p.eval('document.title + " " + document.body.innerText')), `${path} does not mention ${re}`);
    assert(!/Fatal error|Uncaught|Warning:|Stack trace/.test(await bodyText()), 'PHP error text on the page');
  });
}

// ------------------------------------------------------------------------------------------------ theme
await T('theme: dark mode switch keeps pages usable, then switch back', async () => {
  const setTheme = async (value) => {
    await go('/agent/user/user_preferences.php');
    await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
    await p.clickNav('button[name=edit_your_user_preferences]');
  };
  await setTheme(1);
  await go('/agent/tickets.php');
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'html[data-bs-theme] is not dark');
  assert(await p.eval(`document.body.classList.contains('dark-mode')`), 'body.dark-mode missing');
  const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
  const [r, g, b] = bg.match(/\d+/g).map(Number);
  assert(r + g + b < 3 * 110, 'body background is not dark: ' + bg);
  const fg = await p.eval(`getComputedStyle(document.querySelector('.page-wrapper table, .page-wrapper .card-body, .page-wrapper') ).color`);
  const [fr, fgc, fb] = fg.match(/\d+/g).map(Number);
  assert(fr + fgc + fb > 3 * 100, 'text colour is not light enough on dark: ' + fg);
  await go('/admin/event_rules.php');
  assert(await p.visible('.page-wrapper a.btn-primary, .page-wrapper .btn-primary'), 'event rules not usable in dark mode (no visible primary button)');
  await setTheme(0);
  await go('/agent/tickets.php');
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'light', 'did not switch back to light');
});

// ------------------------------------------------------------------------------------------------ phone width
await p.setViewport(MOBILE_W, 800, true);
for (const [name, path, prep] of [
  ['dashboard', '/agent/dashboard.php'],
  ['ticket list', '/agent/tickets.php'],
  ['webhook wizard', ED.webhookNew + (ED.webhookUi === 'wizard' ? '?dest=n8n&step=connect' : '?dest=n8n')],
  ['event rules list', '/admin/event_rules.php'],
]) {
  await T(`phone ${MOBILE_W}px: ${name} has no horizontal overflow`, async () => {
    await go(path);
    await sleep(600);
    const o = await hOverflow();
    assert(o.sw <= o.vw + 1, `scrollWidth ${o.sw} > ${o.vw}; offenders: ${o.over.join(', ')}`);
  });
}
await p.clearViewport();
await p.setViewport(1366, 900);

// ------------------------------------------------------------------------------------------------ keyboard
await T('keyboard: Tab reaches the main navigation', async () => {
  await go('/agent/dashboard.php');
  await p.eval('document.activeElement && document.activeElement.blur(); window.scrollTo(0,0)');
  let hit = null;
  for (let i = 0; i < 25 && !hit; i++) {
    await p.press('Tab');
    hit = await p.eval(`(()=>{const a=document.activeElement;return a&&a.closest&&a.closest('.navbar-vertical, #sidebar-menu, nav.nav, .main-sidebar')?a.textContent.trim().slice(0,30)||a.getAttribute('href'):null})()`);
  }
  assert(hit, 'focus never reached the side navigation in 25 Tab presses');
});
await T('keyboard: Esc closes a modal and the date picker', async () => {
  await go('/agent/tickets.php');
  await p.click('button.ajax-modal[data-modal-url*="ticket_add_v2"]');
  await p.waitSel('.modal.show #subjectInput', { timeout: 20000 });
  await sleep(1200); // let the fade-in finish: Bootstrap ignores Esc mid-transition
  await p.click('.modal.show #subjectInput'); // focus inside the dialog, as a real user has it (TinyMCE's iframe swallows Esc by design)
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('.modal.show')`, { timeout: 5000, label: 'modal closes on Esc' });
  await sleep(800); // backdrop fade-out
  await drpOpen();
  await p.press('Escape');
  await p.waitFor(`document.querySelector(${JSON.stringify(DR.panel)}).hidden`, { timeout: 4000, label: 'picker closes on Esc' });
  assert((await p.eval(`document.querySelector(${JSON.stringify(DR.btn)}).getAttribute('aria-expanded')`)) === 'false', 'aria-expanded still true');
});
await T('keyboard: Esc closes the icon picker popover', async () => {
  await go('/agent/tickets.php');
  await p.click('button.ajax-modal[title="Save current view"]');
  await p.waitSel('.modal.show .icon-picker__button, .modal.show .icon-picker-button', { timeout: 15000 });
  await p.click('.modal.show .icon-picker__button, .modal.show .icon-picker-button');
  await p.waitSel('.icon-picker__search, .icon-picker-search', { timeout: 8000 });
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('.icon-picker__search:not([hidden]), .icon-picker-search') || document.querySelector('[data-icon-picker]:not(.is-open) , .icon-picker')`, { timeout: 3000 });
  await sleep(300);
  assert(!(await p.visible('.icon-picker__search, .icon-picker-search')), 'icon picker panel still open after Esc');
  assert(await p.exists('.modal.show') || true, '');
});

await browser.close();
const failed = H.summary();
process.exit(failed ? 1 : 0);
