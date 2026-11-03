// Browser smoke for the lifecycle follow-ups: the portal HR request form, the HR queue, the Okta settings card and the legacy rules in the unified list,
// at 1280px and 390px, light and dark (dark = seed with the "dark" argument; the colour scheme is a server-side setting).
//
//   php tests/browser/life33_seed.php /tmp/life33-seed.json [dark]    # on the throwaway install (WIPES the scratch tables it fills)
//   php -S 127.0.0.1:8561 tests/browser/employee_portal_i32_router.php  # from the install root, config.php -> the scratch database
//   BASE_URL=http://127.0.0.1:8561 SEED_JSON=/tmp/life33-seed.json OUT=/tmp/life33-shots node tests/browser/life33_smoke.mjs [dark]
//
// Uses playwright-core with the Chromium that is installed on the dev box (PLAYWRIGHT_CORE and CHROME can override the two paths). Loopback only.
import { mkdirSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_CORE || '/home/sysadmin/.npm/_npx/db89d7302a373f10/node_modules/playwright-core');
const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
if (!/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(BASE) || !process.env.SEED_JSON) { console.error('Set BASE_URL (loopback) and SEED_JSON'); process.exit(2); }
const SEED = JSON.parse(readFileSync(process.env.SEED_JSON, 'utf8'));
const OUT = process.env.OUT || '/tmp/life33-shots';
const DARK = process.argv[2] === 'dark';
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: process.env.CHROME || '/home/sysadmin/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome', args: ['--no-sandbox'] });
let pass = 0; const fails = [];
const ok = (c, n, d = '') => { if (c) { pass++; console.log('  ok   ' + n); } else { fails.push(n); console.log('  FAIL ' + n + (d ? ' -- ' + d : '')); } };
const tag = DARK ? 'dark' : 'light';

async function login(ctx, who, agent) {
  const page = await ctx.newPage();
  await page.goto(BASE + '/login.php');
  await page.fill('input[name=email]', SEED[who][0]);
  await page.fill('input[name=password]', SEED[who][1]);
  await page.click('button[name=login]');
  await page.waitForSelector('button[name=role_choice], nav, .navbar, .page-body', { timeout: 20000 });
  const choice = await page.$('button[name=role_choice][value=' + (agent ? 'agent' : 'client') + ']');
  if (choice) { await choice.click(); await page.waitForURL((u) => !/login\.php/.test(u.pathname), { timeout: 20000 }); }
  await page.waitForLoadState('load');
  return page;
}
async function bodyText(page) {
  for (let i = 0; i < 40; i++) {
    try { await page.waitForLoadState('load'); const t = await page.evaluate(() => (document.body ? document.body.innerText : null)); if (t) { return t; } } catch (e) { /* navigating */ }
    await page.waitForTimeout(250);
  }
  return '';
}
async function flash(page) {
  try { await page.waitForSelector('#toast-container .toast', { timeout: 8000 }); return await page.evaluate(() => (document.getElementById('toast-container') || {}).innerText || ''); } catch (e) { return ''; }
}
async function overflow(page) { return page.evaluate(() => { const d = document.documentElement; return d.scrollWidth - d.clientWidth; }); }
async function shot(page, name) { await page.screenshot({ path: `${OUT}/${tag}-${name}.png`, fullPage: true }); }

// ---------------------------------------------------------------- portal: manager asks HR
const mctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const mona = await login(mctx, 'mona', false);
let txt = await bodyText(mona);
ok(/HR requests/.test(txt), 'a manager sees the "HR requests" menu entry');
await mona.goto(BASE + '/client/hr_request.php');
txt = await bodyText(mona);
ok(/Ask HR to start a workflow/.test(txt) && /My HR requests/.test(txt), 'the HR request page renders for a manager');
ok(await mona.isVisible('#hr_name') && !(await mona.isVisible('#hr_subject')), 'onboarding is the default: new hire fields visible, person picker hidden');
await shot(mona, 'portal-form-1280');

// onboarding
await mona.fill('#hr_name', 'Nina Newhire'); await mona.fill('#hr_email', 'nina@example.test');
const start = new Date(Date.now() + 14 * 864e5).toISOString().slice(0, 10);
await mona.fill('#hr_start', start); await mona.fill('#hr_title', 'Support Analyst');
await Promise.all([mona.waitForNavigation({ waitUntil: 'load' }), mona.click('button[name=submit_hr_request]')]);
txt = await bodyText(mona);
ok(/Request sent/.test(txt) && /Nina Newhire/.test(txt) && /Waiting for HR/.test(txt), 'onboarding request sent and shown as waiting for HR');
// offboarding: only Mona's reports are listed
await mona.selectOption('#hr_type', 'offboarding');
ok(await mona.isVisible('#hr_subject') && !(await mona.isVisible('#hr_name')), 'switching to offboarding swaps the fields');
const opts = await mona.$$eval('#hr_subject option', (o) => o.map((x) => x.textContent.trim()));
ok(opts.some((o) => /Rita Report/.test(o)) && opts.some((o) => /Ron Report/.test(o)) && !opts.some((o) => /Dee|Eve|Fay|Mona/.test(o)), 'person picker lists only the manager\'s reports', opts.join('|'));
await mona.selectOption('#hr_subject', { label: opts.find((o) => /Rita/.test(o)) });
await mona.fill('#hr_date', new Date(Date.now() + 20 * 864e5).toISOString().slice(0, 10));
await Promise.all([mona.waitForNavigation({ waitUntil: 'load' }), mona.click('button[name=submit_hr_request]')]);
txt = await bodyText(mona);
ok(/Offboarding/.test(txt) && /Rita Report/.test(txt), 'offboarding request listed');
// role change with an error first (same title)
await mona.selectOption('#hr_type', 'role_change');
await mona.selectOption('#hr_subject', { label: (await mona.$$eval('#hr_subject option', (o) => o.map((x) => x.textContent.trim()))).find((o) => /Ron/.test(o)) });
await mona.fill('#hr_date', new Date(Date.now() + 10 * 864e5).toISOString().slice(0, 10));
await mona.fill('#hr_newtitle', 'Clerk');
await Promise.all([mona.waitForNavigation({ waitUntil: 'load' }), mona.click('button[name=submit_hr_request]')]);
txt = await bodyText(mona);
ok(/already their job title/.test(txt), 'a role change to the same title is refused with a message');
ok((await mona.inputValue('#hr_type')) === 'role_change', 'the form comes back on the same request type');
await mona.fill('#hr_newtitle', 'Lead Clerk');
await Promise.all([mona.waitForNavigation({ waitUntil: 'load' }), mona.click('button[name=submit_hr_request]')]);
txt = await bodyText(mona);
ok(/Role change/.test(txt) && /Ron Report/.test(txt), 'role change request listed');
ok((await mona.$$('button[name=cancel_hr_request]')).length === 3, 'three pending requests, each can be withdrawn');
await Promise.all([mona.waitForNavigation({ waitUntil: 'load' }), mona.click('button[name=cancel_hr_request] >> nth=0')]);
txt = await bodyText(mona);
ok(/Withdrawn/.test(txt) && (await mona.$$('button[name=cancel_hr_request]')).length === 2, 'withdrawing a request marks it withdrawn');
// phone width
await mona.setViewportSize({ width: 390, height: 844 });
await mona.goto(BASE + '/client/hr_request.php');
ok((await overflow(mona)) <= 1, '390px: HR request page does not scroll sideways', String(await overflow(mona)));
await shot(mona, 'portal-form-390');
await mona.setViewportSize({ width: 1280, height: 900 });

// ordinary employee: no entry, direct URL refused
const ectx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const eve = await login(ectx, 'eve', false);
txt = await bodyText(eve);
ok(!/HR requests/.test(txt), 'an ordinary employee has no "HR requests" entry');
await eve.goto(BASE + '/client/hr_request.php');
ok(!/hr_request\.php/.test(eve.url()) && !/Ask HR to start/.test(await bodyText(eve)), 'and the page itself bounces them');
const dctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const dee = await login(dctx, 'dee', false);
await dee.goto(BASE + '/client/hr_request.php');
const dopts = await dee.$$eval('#hr_subject option', (o) => o.map((x) => x.textContent.trim()));
ok(/Ask HR/.test(await bodyText(dee)) && dopts.some((o) => /Eve/.test(o)) && !dopts.some((o) => /Fay|Dee/.test(o)), 'a department administrator may ask, for anyone in their own department only', dopts.join('|'));
ok(!/Nina|Rita|Ron/.test(await dee.evaluate(() => document.querySelector('.card:last-of-type')?.innerText || '')), 'and does not see the manager\'s requests in "My HR requests"');

// ---------------------------------------------------------------- agent: HR queue
const actx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const admin = await login(actx, 'admin', true);
await admin.goto(BASE + '/agent/hr_requests.php');
txt = await bodyText(admin);
ok(/HR requests/.test(txt) && /Nina Newhire/.test(txt) && /Rita Report/.test(txt) && !/Ron Report/.test(txt), 'the HR queue lists the pending requests');
ok(/Nothing has been started or changed/.test(txt), 'and says nothing has started');
await shot(admin, 'agent-queue-1280');
// reject without a note is refused client-side by the server
await Promise.all([admin.waitForNavigation({ waitUntil: 'load' }), admin.click('button[value=reject] >> nth=0')]);
ok(/Say why you are rejecting/.test(await bodyText(admin)), 'rejecting without a reason is refused');
// approve the onboarding
const cardIdx = await admin.$$eval('h5', (h) => h.findIndex((x) => /Onboarding.*Nina/.test(x.textContent)));
ok(cardIdx >= 0, 'onboarding card found');
await Promise.all([admin.waitForNavigation({ waitUntil: 'load' }), admin.click(`.card:has(h5:has-text("Nina Newhire")) button[value=approve]`)]);
txt = await bodyText(admin);
ok(/Approved\. The workflow has started/.test(txt), 'approving starts the workflow');
await admin.goto(BASE + '/agent/hr_requests.php?status=approved');
ok(/Open the run/.test(await bodyText(admin)), 'the approved request links to its run');
await admin.setViewportSize({ width: 390, height: 844 });
await admin.goto(BASE + '/agent/hr_requests.php');
ok((await overflow(admin)) <= 1, '390px: HR queue does not scroll sideways', String(await overflow(admin)));
await shot(admin, 'agent-queue-390');
await admin.setViewportSize({ width: 1280, height: 900 });

// ---------------------------------------------------------------- admin: settings, Okta card, legacy rules
await admin.goto(BASE + '/admin/settings_portal.php');
ok(await admin.isChecked('#portal_hr'), 'portal settings: the HR requests switch shows its saved state (on in this seed)');
await admin.goto(BASE + '/admin/employee_workflow_templates.php');
txt = await bodyText(admin);
ok(/Okta actions/.test(txt) && !(await admin.isChecked('#lifecycle_okta_actions')), 'Okta card renders and "Allow workflows to change Okta" is OFF by default');
await admin.fill('#okta_domain', 'https://bad.example');
await Promise.all([admin.waitForNavigation({ waitUntil: 'load' }), admin.click('button[name=save_okta_settings]')]);
ok(/bare host name/.test(await flash(admin)), 'a malformed Okta domain is refused with a message');
await admin.fill('#okta_domain', 'example.okta.com'); await admin.fill('#okta_token', 'fake-token-for-test');
await admin.check('#lifecycle_okta_actions');
await Promise.all([admin.waitForNavigation({ waitUntil: 'load' }), admin.click('button[name=save_okta_settings]')]);
txt = await bodyText(admin);
const flashed = await flash(admin);
const html = await admin.content();
ok(/Okta settings saved/.test(flashed) && /saved/.test(txt) && !html.includes('fake-token-for-test'), 'saved; the token is never echoed back into the page');
ok(await admin.isChecked('#lifecycle_okta_actions') && (await admin.inputValue('#okta_domain')) === 'example.okta.com', 'saved values show');
await admin.setViewportSize({ width: 390, height: 844 });
await admin.goto(BASE + '/admin/employee_workflow_templates.php');
ok((await overflow(admin)) <= 1, '390px: Okta card does not scroll sideways', String(await overflow(admin)));
await shot(admin, 'okta-390');
await admin.setViewportSize({ width: 1280, height: 900 });
// switch it back off so the seed stays default-off
await admin.goto(BASE + '/admin/employee_workflow_templates.php');
await admin.uncheck('#lifecycle_okta_actions'); await admin.check('#okta_clear_token');
await Promise.all([admin.waitForNavigation({ waitUntil: 'load' }), admin.click('button[name=save_okta_settings]')]);

await admin.goto(BASE + '/admin/automation.php');
txt = await bodyText(admin);
ok(/Older ticket rules/.test(txt) && /Tell the tech when a server goes offline/.test(txt) && /Bump old tickets/.test(txt), 'the event rules tab lists the legacy ticket rules read-only');
ok(/Running as a ticket rule/.test(txt), 'each shows it is still running as a ticket rule');
ok((await admin.$$('button[name=convert_legacy_rule]')).length === 1, 'only the rule with an equivalent offers Convert');
await shot(admin, 'legacy-1280');
await Promise.all([admin.waitForNavigation({ waitUntil: 'load' }), admin.click('button[name=convert_legacy_rule]')]);
txt = await bodyText(admin);
ok(/Copy ready, switched off/.test(txt) && /Switch over/.test(txt), 'convert makes the copy and offers Switch over');
await admin.click('button[name=switch_legacy_rule]');
await admin.waitForSelector('.modal.show [role=alertdialog], .modal.show', { timeout: 10000 });
ok(/only the event rule acts/.test(await bodyText(admin)), 'switch over asks first, in the app dialog (no native confirm)');
await Promise.all([admin.waitForNavigation({ waitUntil: 'load' }), admin.click('.modal.show .btn-danger, .modal.show .btn-primary')]);
txt = await bodyText(admin);
ok(/Switched over/.test(txt), 'switch over turns the copy on and the old rule off');
await admin.setViewportSize({ width: 390, height: 844 });
await admin.goto(BASE + '/admin/automation.php');
ok((await overflow(admin)) <= 1, '390px: unified rules list does not scroll sideways', String(await overflow(admin)));
await shot(admin, 'legacy-390');

await browser.close();
console.log(`\n${pass} passed, ${fails.length} failed` + (fails.length ? '\nFAILED: ' + fails.join('; ') : ''));
process.exit(fails.length ? 1 : 0);
