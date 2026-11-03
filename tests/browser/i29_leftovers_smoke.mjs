// Browser/HTTP smoke for the issue #29 leftovers (docs/user-guide/tools/app-findings.md) on a THROWAWAY install with fictional data.
//
//   php tests/browser/i29_leftovers_seed.php /tmp/i29-seed.json            (WIPES the scratch tables it fills; refuses a DB without "scratch" in its name)
//   php -S 127.0.0.1:8291 -t . tests/browser/employee_portal_i32_router.php  (from the install root; config.php -> the scratch database)
//   BASE_URL=http://127.0.0.1:8291 SEED_JSON=/tmp/i29-seed.json DB_NAME=rivetit_scratch_x DB_USER=... DB_PASS=... SHOTS=/tmp/i29-shots node tests/browser/i29_leftovers_smoke.mjs
//
// Needs playwright-core (CHROMIUM=/path/to/chrome if it is not on the default path). Loopback only.
import { readFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
if (!/^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(BASE) || !process.env.SEED_JSON) { console.error('Set BASE_URL (loopback) and SEED_JSON.'); process.exit(2); }
if (!/scratch/i.test(process.env.DB_NAME || '')) { console.error('DB_NAME must be a scratch database.'); process.exit(2); }
const SEED = JSON.parse(readFileSync(process.env.SEED_JSON, 'utf8'));
const SHOTS = process.env.SHOTS || '/tmp/i29-shots'; mkdirSync(SHOTS, { recursive: true });
const sql = (s) => execFileSync('mysql', ['-u', process.env.DB_USER, '-N', '-B', process.env.DB_NAME, '-e', s], { env: { ...process.env, MYSQL_PWD: process.env.DB_PASS } }).toString().trim();

let fail = 0, n = 0;
const T = async (name, fn) => { n++; try { await fn(); console.log('PASS  ' + name); } catch (e) { fail++; console.log('FAIL  ' + name + '  -> ' + e.message); } };
const assert = (c, m) => { if (!c) throw new Error(m); };

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/home/sysadmin/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome', args: ['--no-sandbox'] });
const sessions = {};
async function login(email, who) {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/login.php');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', SEED.password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[name=login], button[type=submit]')]);
  assert(!/login\.php/.test(page.url()), who + ' did not sign in: ' + page.url());
  sessions[who] = { ctx, page };
  return sessions[who];
}
/** The CSRF token of a signed-in session: every pop-up form carries it, so read it from one (the pages themselves do not always have a form). */
const tokens = {};
async function csrf(who) {
  if (!tokens[who]) {
    const r = await sessions[who].ctx.request.get(BASE + '/agent/modals/vendor/vendor_add.php?client_id=0');
    const m = String((await r.json()).content || '').match(/name="csrf_token" value="([^"]+)"/);
    assert(m, who + ': no CSRF token found in the pop-up');
    tokens[who] = m[1];
  }
  return tokens[who];
}
/** A pop-up's HTML (the modal endpoints answer {"content": "<html>"}). */
async function modal(who, path) {
  const r = await get(who, path, '/agent/vendors.php');
  assert(r.status() === 200, 'modal ' + path + ' status ' + r.status());
  const j = await r.json(); assert(j.content && j.content.length > 200, 'modal ' + path + ' has no content');
  return j.content;
}
/** POST to agent/post.php or admin/post.php the way the browser does: the Referer picks the handler. */
async function post(who, area, referPage, fields, extraQuery = '') {
  const { ctx } = sessions[who];
  const token = await csrf(who);
  // Repeated keys (assets[] = 32, 34) must go out as separate pairs, which Playwright's `form` option does not do for arrays.
  const body = new URLSearchParams(); body.append('csrf_token', token);
  for (const [k, v] of Object.entries(fields)) { for (const one of (Array.isArray(v) ? v : [v])) { body.append(k, one); } }
  const res = await ctx.request.post(`${BASE}/${area}/post.php${extraQuery}`, { data: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded', Referer: `${BASE}/${area}/${referPage}` }, maxRedirects: 0 });
  return res;
}
async function get(who, path, referer) {
  const { ctx } = sessions[who];
  return ctx.request.get(BASE + path, { headers: referer ? { Referer: BASE + referer } : {}, maxRedirects: 0 });
}
const tokenFor = async (who) => csrf(who);

await login(SEED.admin, 'admin'); await login(SEED.support, 'support'); await login(SEED.finance, 'finance');

// ---------------- vendors: company-wide permission model ----------------
await T('vendors: Support-only user lists company-wide vendors but not the account number', async () => {
  const p = sessions.support.page; await p.goto(BASE + '/agent/vendors.php');
  const body = await p.content();
  assert(body.includes('Acme Telecom (scratch)'), 'vendor not listed');
  assert(!body.includes('ACCT-SECRET-777'), 'account number leaked in the list');
});
await T('vendors: details modal opens for Support, without the account number', async () => {
  const t = await modal('support', '/agent/modals/vendor/vendor_details.php?id=51'); assert(t.includes('Acme Telecom'), 'details modal has no vendor');
  assert(!t.includes('ACCT-SECRET-777') && !t.includes('Account Number'), 'account number shown');
});
await T('vendors: edit modal for Support has no account-number field', async () => {
  const t = await modal('support', '/agent/modals/vendor/vendor_edit.php?id=51');
  assert(t.includes('name="name"'), 'edit modal has no name field');
  assert(!t.includes('name="account_number"') && !t.includes('ACCT-SECRET-777'), 'account number field present');
});
await T('vendors: Support edits a company-wide vendor; the stored account number is unchanged even if forged', async () => {
  await post('support', 'agent', 'vendors.php', { edit_vendor: '1', vendor_id: '51', vendor_template_id: '0', name: 'Acme Telecom (scratch)', description: 'edited by support', account_number: 'FORGED', contact_name: 'Vic Vendor', phone_country_code: '', phone: '', extension: '', email: '', website: '', hours: '', sla: '', code: '', notes: '' });
  assert(sql("SELECT vendor_description FROM vendors WHERE vendor_id=51") === 'edited by support', 'edit did not save');
  assert(sql("SELECT vendor_account_number FROM vendors WHERE vendor_id=51") === 'ACCT-SECRET-777', 'account number changed');
});
await T('vendors: Support can add a company-wide vendor (no account number stored)', async () => {
  await post('support', 'agent', 'vendors.php', { add_vendor: '1', client_id: '0', name: 'New Co-wide Vendor', description: '', account_number: 'FORGED2', contact_name: '', phone_country_code: '', phone: '', extension: '', email: '', website: '', hours: '', sla: '', code: '', notes: '' });
  assert(sql("SELECT COUNT(*) FROM vendors WHERE vendor_name='New Co-wide Vendor' AND vendor_client_id=0 AND vendor_account_number=''") === '1', 'vendor not created clean');
});
await T('vendors: Support cannot delete a company-wide vendor (Finance only)', async () => {
  const tok = await tokenFor('support');
  const r = await get('support', `/agent/post.php?delete_vendor=51&csrf_token=${tok}`, '/agent/vendors.php');
  assert(sql("SELECT COUNT(*) FROM vendors WHERE vendor_id=51") === '1', 'vendor was deleted');
});
await T('vendors: Finance user (Support read only) sees and can set the account number', async () => {
  const t = await modal('finance', '/agent/modals/vendor/vendor_edit.php?id=51');
  assert(t.includes('ACCT-SECRET-777') && t.includes('name="account_number"'), 'finance does not see the account number');
});

// ---------------- users CSV ----------------
await T('users CSV: archived agents and portal logins are left out by default', async () => {
  const r = await post('admin', 'admin', 'users.php', { export_users_csv: '1' });
  const t = await r.text();
  assert(t.includes('Sam Support') && !t.includes('Olive Archived') && !t.includes('Portal Pat'), 'unexpected rows:\n' + t);
});
await T('users CSV: include_archived adds archived agents, marked Archived', async () => {
  const r = await post('admin', 'admin', 'users.php', { export_users_csv: '1', include_archived: '1' });
  const t = await r.text();
  assert(/Olive Archived.*Archived/.test(t) && !t.includes('Portal Pat'), 'unexpected rows:\n' + t);
});

// ---------------- projects / tasks ----------------
await T('closed ticket links to a No-Department project (NULL updated_at stays NULL)', async () => {
  await post('admin', 'agent', 'project_details.php?project_id=61', { link_closed_ticket_to_project: '1', project_id: '61', ticket_number: '7001' });
  assert(sql("SELECT ticket_project_id FROM tickets WHERE ticket_id=71") === '61', 'ticket not linked');
  assert(sql("SELECT ticket_updated_at IS NULL FROM tickets WHERE ticket_id=71") === '1', 'updated_at was written');
});
await T('closed ticket still refused for a department project when the ticket is in another department', async () => {
  sql("UPDATE tickets SET ticket_project_id=0 WHERE ticket_id=71");
  sql("INSERT INTO projects SET project_id=63, project_name='Beta Project', project_client_id=2, project_prefix='PRJ-', project_number=3");
  await post('admin', 'agent', 'project_details.php?project_id=63', { link_closed_ticket_to_project: '1', project_id: '63', ticket_number: '7001' });
  assert(sql("SELECT ticket_project_id FROM tickets WHERE ticket_id=71") === '0', 'cross-department link allowed');
});
await T('task: Kanban move to Done, then un-tick on Details returns it to To Do with progress 0', async () => {
  const tok = await tokenFor('admin');
  const { ctx } = sessions.admin;
  await ctx.request.post(`${BASE}/agent/ajax.php`, { form: { csrf_token: tok, update_project_task_kanban: '1', 'positions[0][task_id]': '81', 'positions[0][task_order]': '1', 'positions[0][task_status]': 'Done' }, headers: { Referer: BASE + '/agent/project_kanban.php?project_id=62' } });
  assert(sql("SELECT task_status, task_progress, task_completed_at IS NOT NULL FROM tasks WHERE task_id=81") === 'Done\t100\t1', 'not Done after Kanban: ' + sql("SELECT task_status, task_progress, task_completed_at FROM tasks WHERE task_id=81"));
  const tok2 = await tokenFor('admin');
  await get('admin', `/agent/post.php?undo_complete_task=81&csrf_token=${tok2}`, '/agent/project_details.php?project_id=62');
  assert(sql("SELECT task_status, task_progress, task_completed_at IS NULL FROM tasks WHERE task_id=81") === 'To Do\t0\t1', 'not reset: ' + sql("SELECT task_status, task_progress, task_completed_at FROM tasks WHERE task_id=81"));
  // and on the board it is back in the To Do lane
  const p = sessions.admin.page; await p.goto(BASE + '/agent/project_kanban.php?project_id=62');
  assert(await p.evaluate(() => !!document.body.innerText.includes('Plan cutover')), 'task missing from board');
});
await T('task: complete on Details lands in the Done lane; moving it out of Done clears progress 100', async () => {
  const tok = await tokenFor('admin');
  await get('admin', `/agent/post.php?complete_task=82&csrf_token=${tok}`, '/agent/project_details.php?project_id=62');
  assert(sql("SELECT task_completed_at IS NOT NULL, task_progress FROM tasks WHERE task_id=82") === '1\t100', 'not completed');
  const tok2 = await tokenFor('admin');
  await sessions.admin.ctx.request.post(`${BASE}/agent/ajax.php`, { form: { csrf_token: tok2, update_project_task_kanban: '1', 'positions[0][task_id]': '82', 'positions[0][task_order]': '1', 'positions[0][task_status]': 'In Progress' }, headers: { Referer: BASE + '/agent/project_kanban.php?project_id=62' } });
  assert(sql("SELECT task_status, task_progress, task_completed_at IS NULL FROM tasks WHERE task_id=82") === 'In Progress\t0\t1', 'not reset: ' + sql("SELECT task_status, task_progress FROM tasks WHERE task_id=82"));
});
await T('project list task bar counts project-only tasks as well as ticket tasks', async () => {
  const p = sessions.admin.page; await p.goto(BASE + '/agent/projects.php');
  const row = await p.evaluate(() => { const tr = [...document.querySelectorAll('tbody tr')].find(r => r.innerText.includes('Alpha Migration')); return tr ? tr.innerText.replace(/\s+/g, ' ') : ''; });
  // 3 tasks (2 project-only + 1 ticket task), none complete now
  assert(/Alpha Migration/.test(row), 'project row missing: ' + row);
  const widths = await p.evaluate(() => { const tr = [...document.querySelectorAll('tbody tr')].find(r => r.innerText.includes('Alpha Migration')); return [...tr.querySelectorAll('.progress-bar')].map(b => b.getAttribute('aria-valuenow') + '/' + (b.parentElement.getAttribute('title') || b.innerText)); });
  sql("UPDATE tasks SET task_completed_at=NOW(), task_progress=100 WHERE task_id=83");
  await p.goto(BASE + '/agent/projects.php');
  const now = await p.evaluate(() => { const tr = [...document.querySelectorAll('tbody tr')].find(r => r.innerText.includes('Alpha Migration')); return tr.innerText.replace(/\s+/g, ' '); });
  assert(/33\s*%|1\s*\/\s*3/.test(now), 'task bar does not reflect 1 of 3 tasks: ' + now + ' | before ' + JSON.stringify(widths));
});

// ---------------- departments / people ----------------
await T('bulk department email: nothing queued without a recipient group; archived people never mailed', async () => {
  await post('admin', 'agent', 'clients.php', { bulk_send_client_email: '1', 'client_ids[]': '1', mail_from: 'noreply@scratch.test', mail_from_name: 'Scratch', subject: 'Hello', body: 'Body', queued_at: '2030-01-01 00:00:00' });
  assert(sql("SELECT COUNT(*) FROM email_queue") === '0', 'mail queued without a group');
});
await T('bulk department email: Primary group queues Ann only (Dan is archived, Cora is in another department)', async () => {
  await post('admin', 'agent', 'clients.php', { bulk_send_client_email: '1', 'client_ids[]': '1', primary_contacts: '1', mail_from: 'noreply@scratch.test', mail_from_name: 'Scratch', subject: 'Hello', body: 'Body', queued_at: '2030-01-01 00:00:00' });
  const rows = sql("SELECT email_recipient FROM email_queue ORDER BY email_recipient").split('\n');
  assert(rows.length === 1 && rows[0] === 'ann@a.example', 'queued: ' + rows.join(','));
  sql("DELETE FROM email_queue");
});
await T('bulk department email: an archived department is never mailed', async () => {
  await post('admin', 'agent', 'clients.php', { bulk_send_client_email: '1', 'client_ids[]': '3', primary_contacts: '1', mail_from: 'noreply@scratch.test', mail_from_name: 'Scratch', subject: 'Hello', body: 'Body', queued_at: '2030-01-01 00:00:00' });
  assert(sql("SELECT COUNT(*) FROM email_queue") === '0', 'mail queued for an archived department');
});
await T('bulk department email: Technical group reaches every active technical contact once, never the archived one', async () => {
  await post('admin', 'agent', 'clients.php', { bulk_send_client_email: '1', 'client_ids[]': '1', technical_contacts: '1', mail_from: 'noreply@scratch.test', mail_from_name: 'Scratch', subject: 'Hello', body: 'Body', queued_at: '2030-01-01 00:00:00' });
  const rows = sql("SELECT email_recipient FROM email_queue ORDER BY email_recipient").split('\n');
  assert(rows.join(',') === 'ann@a.example,bob@a.example', 'queued: ' + rows.join(','));
  sql("DELETE FROM email_queue");
});
await T('Departments date range never shows 1970 (closed, open, custom, From-only, back to All time)', async () => {
  const p = sessions.admin.page; await p.goto(BASE + '/agent/clients.php'); await p.waitForTimeout(300);
  await p.click('[data-bs-target="#advancedFilter"]'); await p.waitForSelector('#advancedFilter.show .drp-btn');
  const visible = () => p.evaluate(() => { const r = document.querySelector('#advancedFilter .drp'); return r.innerText + ' | ' + [...r.querySelectorAll('input')].map(i => i.value).join(',') + ' | ' + r.querySelector('.drp-btn').getAttribute('aria-label'); });
  let t = await visible(); assert(!/1970|2099/.test(t), 'closed picker shows an extreme date: ' + t);
  assert(/All time/i.test(t), 'closed picker should say All time: ' + t);
  await p.click('#advancedFilter .drp-btn'); await p.waitForTimeout(300);
  t = await visible(); assert(!/1970|2099/.test(t), 'open picker shows an extreme date: ' + t);
  await p.screenshot({ path: SHOTS + '/clients-date-range-open.png' });
  await p.click('#advancedFilter .drp-opt[data-preset="custom"]'); await p.waitForTimeout(300);
  t = await visible(); assert(!/1970|2099/.test(t.replace(/min="[^"]*"/g, '')), 'custom panel shows an extreme date: ' + t);
  await p.fill('#advancedFilter .drp-from', '2026-01-15');
  const apply = p.locator('#advancedFilter .drp-apply'); if (await apply.count()) { await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), apply.first().click()]); }
  await p.waitForSelector('#advancedFilter .drp-btn', { state: 'attached' });
  if (!(await p.evaluate(() => document.querySelector('#advancedFilter').classList.contains('show')))) { await p.click('[data-bs-target="#advancedFilter"]'); await p.waitForSelector('#advancedFilter.show .drp-btn'); }
  t = await visible(); assert(!/1970|2099/.test(t), 'From-only range shows an extreme date: ' + t);
  assert(/From|Jan/.test(t), 'From-only range not displayed: ' + t);
  await p.screenshot({ path: SHOTS + '/clients-date-range-from-only.png' });
});
await T('Move department: person moves, devices unassigned, location reset, no longer primary, audited', async () => {
  sql("UPDATE settings SET config_lifecycle_auto_start = 1 WHERE company_id = 1");
  // Ann (21): Alpha primary, location 11 (Alpha-only), asset 31 and licence seat in Alpha
  await post('admin', 'agent', 'contacts.php', { move_contact_department: '1', contact_id: '21', new_client_id: '2', unassign_items: '1' });
  assert(sql("SELECT contact_client_id, contact_primary, contact_location_id FROM contacts WHERE contact_id=21") === '2\t0\t0', 'contact row: ' + sql("SELECT contact_client_id, contact_primary, contact_location_id FROM contacts WHERE contact_id=21"));
  assert(sql("SELECT asset_contact_id FROM assets WHERE asset_id=31") === '0', 'asset still assigned');
  assert(sql("SELECT COUNT(*) FROM software_contacts WHERE contact_id=21") === '0', 'licence seat kept');
  assert(sql("SELECT COUNT(*) FROM logs WHERE log_type='Contact' AND log_action='Move' AND log_description LIKE '%Alpha Dept to Beta Dept%'") === '1', 'no audit log line');
  assert(Number(sql("SELECT COUNT(*) FROM audit_events WHERE event_type='employee.transferred'")) >= 1 || Number(sql("SELECT COUNT(*) FROM audit_events WHERE action='detected'")) >= 1, 'no lifecycle transfer event recorded');
});
await T('Move department: shared location is kept; a move to the same / an archived / a missing department is refused', async () => {
  await post('admin', 'agent', 'contacts.php', { move_contact_department: '1', contact_id: '22', new_client_id: '2' });
  assert(sql("SELECT contact_client_id, contact_location_id FROM contacts WHERE contact_id=22") === '2\t12', 'Bob should keep the shared site: ' + sql("SELECT contact_client_id, contact_location_id FROM contacts WHERE contact_id=22"));
  assert(sql("SELECT asset_contact_id FROM assets WHERE asset_id=32") === '22', 'devices must stay assigned when the box is not ticked');
  for (const bad of ['2', '3', '999', '']) {
    await post('admin', 'agent', 'contacts.php', { move_contact_department: '1', contact_id: '23', new_client_id: bad });
    assert(sql("SELECT contact_client_id FROM contacts WHERE contact_id=23") === '2', 'Cora moved to ' + bad);
  }
});
await T('Move department: a user without Departments write access cannot move anyone', async () => {
  sql("UPDATE user_role_permissions SET user_role_permission_level = 1 WHERE user_role_id = 21 AND module_id = (SELECT module_id FROM modules WHERE module_name='module_client')");
  const { page } = sessions.support; await page.goto(BASE + '/agent/contacts.php');
  const r = await post('support', 'agent', 'contacts.php', { move_contact_department: '1', contact_id: '23', new_client_id: '1' });
  assert(sql("SELECT contact_client_id FROM contacts WHERE contact_id=23") === '2', 'read-only user moved a person');
  sql("UPDATE user_role_permissions SET user_role_permission_level = 2 WHERE user_role_id = 21 AND module_id = (SELECT module_id FROM modules WHERE module_name='module_client')");
});
await T('Move department: row menu offers the action and the dialog renders at 390px', async () => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 800 }, storageState: await sessions.admin.ctx.storageState() });
  const html = await modal('admin', '/agent/modals/contact/contact_move_department.php?id=22'); assert(/Move .*to another department/.test(html) && html.includes('name="new_client_id"'), 'dialog did not render');
  const p = await ctx.newPage(); await p.goto(BASE + '/agent/contacts.php?client_id=2');
  await p.evaluate(async (h) => { const d = document.createElement('div'); d.className = 'modal-content'; d.style.cssText = 'position:fixed;inset:0;overflow:auto;background:var(--bs-body-bg,#fff);z-index:99999'; d.innerHTML = h; document.body.appendChild(d); }, html);
  const ov = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth); assert(ov <= 1, 'horizontal overflow ' + ov);
  await p.screenshot({ path: SHOTS + '/move-department-390.png' });
  const list = await ctx.newPage(); await list.goto(BASE + '/agent/contacts.php?client_id=2');
  assert((await list.content()).includes('Move department'), 'row menu has no Move department entry');
  await ctx.close();
});

// ---------------- software seat links ----------------
await T('licence edit keeps seat links outside its department and ignores forged ones', async () => {
  // asset 33 (Beta) is linked to the Alpha licence 41; the edit form only offers Alpha assets
  await post('admin', 'agent', 'software.php?client_id=1', { edit_software: '1', software_id: '41', name: 'Scratch Suite', version: '', description: '', type: 'Productivity', license_type: 'Volume', key: '', seats: '5', purchase_reference: '', purchase: '', expire: '', notes: '', vendor: '0', 'assets[]': ['32', '33'] });
  const links = sql("SELECT asset_id FROM software_assets WHERE software_id=41 ORDER BY asset_id").split('\n').join(',');
  assert(links === '32,33', 'links after edit: ' + links + ' (31 unticked removed, 32 added, out-of-department 33 untouched and not duplicated)');
  // a forged id for another department's asset (34, in Beta) is ignored
  await post('admin', 'agent', 'software.php?client_id=1', { edit_software: '1', software_id: '41', name: 'Scratch Suite', version: '', description: '', type: 'Productivity', license_type: 'Volume', key: '', seats: '5', purchase_reference: '', purchase: '', expire: '', notes: '', vendor: '0', 'assets[]': ['32', '34'] });
  const links2 = sql("SELECT asset_id FROM software_assets WHERE software_id=41 ORDER BY asset_id").split('\n').join(',');
  assert(links2 === '32,33', 'links after the forged edit: ' + links2 + ' (34 must not be linked)');
});

// ---------------- RMM policy delete (the real client points at a closed port, so removal fails) ----------------
await T('RMM: deleting a policy whose checks cannot be removed keeps the policy and marks the deployments', async () => {
  const tok = await tokenFor('admin');
  const { ctx } = sessions.admin;
  const r = await ctx.request.post(BASE + '/agent/post/rmm_check.php', { form: { csrf_token: tok, action: 'delete_policy', policy_id: '94' }, headers: { Referer: BASE + '/agent/rmm_checks.php' } });
  const j = await r.json();
  assert(j.success === false && j.needs_force === true && j.failed.length === 2, 'response: ' + JSON.stringify(j));
  assert(sql("SELECT COUNT(*) FROM rmm_check_policies WHERE id=94") === '1', 'policy was deleted');
  assert(sql("SELECT COUNT(*) FROM rmm_check_deployments WHERE policy_id=94 AND status='remove_failed'") === '2', 'deployments not marked');
});
await T('RMM: "delete anyway" deletes the policy and logs the checks left on the agents', async () => {
  const tok = await tokenFor('admin');
  const r = await sessions.admin.ctx.request.post(BASE + '/agent/post/rmm_check.php', { form: { csrf_token: tok, action: 'delete_policy', policy_id: '94', force: '1' }, headers: { Referer: BASE + '/agent/rmm_checks.php' } });
  const j = await r.json();
  assert(j.success === true && j.left_on_agents === 2, 'response: ' + JSON.stringify(j));
  assert(sql("SELECT COUNT(*) FROM rmm_check_policies WHERE id=94") === '0' && sql("SELECT COUNT(*) FROM rmm_check_deployments WHERE policy_id=94") === '0', 'rows remain');
  assert(sql("SELECT COUNT(*) FROM logs WHERE log_type='RMM' AND log_description LIKE '%check 501%'") === '1', 'left-over check ids not logged');
});

// ---------------- visual items ----------------
const lum = (rgb) => { const [r, g, b] = rgb.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrastProbe = `(sel) => {
  const el = document.querySelector(sel); if (!el) return null;
  const parse = (c) => { const v = (c.match(/-?[\\d.]+(?:e-?\\d+)?/g) || []).map(Number); return /^color\\(/.test(c) ? [v[0] * 255, v[1] * 255, v[2] * 255, v.length > 3 ? v[3] : 1] : v; };
  const fg = parse(getComputedStyle(el).color);
  let bg = null, n = el;
  while (n) { const c = parse(getComputedStyle(n).backgroundColor); if (c.length >= 3 && (c.length === 3 || c[3] > 0.9)) { bg = c; break; } n = n.parentElement; }
  return { fg, bg: bg || [255, 255, 255], text: el.innerText.trim().slice(0, 40) };
}`;
const ratio = (a, b) => { const l1 = lum(a.slice(0, 3)), l2 = lum(b.slice(0, 3)); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
async function checkContrast(page, sel, label) { const r = await page.evaluate(`(${contrastProbe})(${JSON.stringify(sel)})`); assert(r, label + ': element ' + sel + ' not found'); const c = ratio(r.fg, r.bg); assert(c >= 4.5, `${label}: contrast ${c.toFixed(2)} for "${r.text}" fg=${r.fg} bg=${r.bg}`); return c; }

await T('New License > Devices tab header text is readable (light and dark)', async () => {
  const { page } = sessions.admin;
  for (const dark of [false, true]) {
    sql(`UPDATE settings SET config_theme_dark_default = ${dark ? 1 : 0} WHERE company_id = 1`);
    sql(`UPDATE user_settings SET user_config_theme_dark = 0 WHERE user_id = 1`);
    await page.goto(BASE + '/agent/software.php?client_id=1');
    await page.click('button.ajax-modal[data-modal-url*="software_add"], a.ajax-modal[data-modal-url*="software_add"]');
    await page.waitForSelector('.modal.show a[href="#pills-device-licenses"]');
    await page.click('.modal.show a[href="#pills-device-licenses"]');
    await page.waitForSelector('.modal.show #pills-device-licenses.show.active');
    await page.waitForTimeout(400);
    const theme = await page.evaluate(() => document.documentElement.getAttribute('data-bs-theme') || 'light');
    assert(theme === (dark ? 'dark' : 'light'), 'expected the ' + (dark ? 'dark' : 'light') + ' theme, page is ' + theme);
    const c = await checkContrast(page, '.modal.show #pills-device-licenses .list-group-item.bg-dark .form-check-label', `Devices header (${theme})`);
    console.log(`      Devices header contrast (${theme}): ${c.toFixed(2)}`);
    await page.screenshot({ path: SHOTS + `/new-license-devices-${theme}.png` });
  }
  sql("UPDATE settings SET config_theme_dark_default = 0 WHERE company_id = 1");
});

await T('admin/category.php tab buttons do not overlap at 1440px, 1024px and 390px', async () => {
  const { ctx } = sessions.admin;
  for (const w of [1440, 1024, 390]) {
    const p = await ctx.newPage(); await p.setViewportSize({ width: w, height: 900 }); await p.goto(BASE + '/admin/category.php');
    const res = await p.evaluate(() => { const g = document.querySelector('.btn-group.flex-wrap'); const bs = [...g.children].map(b => b.getBoundingClientRect()); let overlaps = 0; for (let i = 0; i < bs.length; i++) for (let j = i + 1; j < bs.length; j++) { const a = bs[i], b = bs[j]; if (a.left < b.right - 1.5 && b.left < a.right - 1.5 && a.top < b.bottom - 1.5 && b.top < a.bottom - 1.5) overlaps++; } return { overlaps, count: bs.length, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; });
    await p.screenshot({ path: SHOTS + `/category-${w}.png` }); await p.close();
    assert(res.overlaps === 0 && res.overflow <= 1, `width ${w}: ${JSON.stringify(res)}`);
  }
});

// portal + guest card titles: seed a portal login, a ticket, and read the header title colour against its header
sql("INSERT IGNORE INTO contacts SET contact_id=25, contact_name='Portal Pat', contact_email='portal.pat@scratch.test', contact_client_id=1, contact_user_id=5");
sql("INSERT IGNORE INTO tickets SET ticket_id=73, ticket_prefix='T-', ticket_number=7003, ticket_subject='Portal ticket', ticket_details='x', ticket_status=2, ticket_created_by=1, ticket_client_id=1, ticket_contact_id=25, ticket_url_key='abcdef0123456789abcdef0123456789abcd'");
sql("UPDATE settings SET config_client_portal_enable = 1 WHERE company_id = 1");
await T('portal ticket header title and guest ticket header title are readable in light and dark', async () => {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage();
  await p.goto(BASE + '/login.php'); await p.fill('input[name=email]', 'portal.pat@scratch.test'); await p.fill('input[name=password]', SEED.password);
  await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('button[name=login], button[type=submit]')]);
  const rc = p.locator('button[name=role_choice][value=client], button[value=client]'); if (await rc.count()) { await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), rc.first().click()]); }
  for (const theme of ['light', 'dark']) {
    await p.goto(BASE + '/client/ticket.php?id=73');
    await p.evaluate((t) => document.documentElement.setAttribute('data-bs-theme', t), theme);
    console.log(`      portal title contrast (${theme}): ` + (await checkContrast(p, '.card-header.bg-dark .card-title', `portal ticket title (${theme})`)).toFixed(2));
    await p.screenshot({ path: SHOTS + `/portal-ticket-${theme}.png` });
    await p.goto(BASE + '/guest/guest_view_ticket.php?ticket_id=73&url_key=abcdef0123456789abcdef0123456789abcd');
    await p.evaluate((t) => document.documentElement.setAttribute('data-bs-theme', t), theme);
    console.log(`      guest title contrast (${theme}): ` + (await checkContrast(p, '.card-header.bg-dark h4', `guest ticket title (${theme})`)).toFixed(2));
    await p.screenshot({ path: SHOTS + `/guest-ticket-${theme}.png` });
  }
  await ctx.close();
});

await browser.close();
console.log(`\n${n - fail}/${n} passed`);
process.exit(fail ? 1 : 0);
