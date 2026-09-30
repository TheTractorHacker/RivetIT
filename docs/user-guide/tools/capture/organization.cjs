// Screenshots for docs/user-guide/02-departments-and-people.md and 02b-people-import-and-workflows.md
// (group "organization"). Needs seed/00-core.sql and seed/15-organization.sql applied.
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/organization.cjs
//
// Read-only with respect to app data: it opens pop-ups and types illustrative values but never saves.
// The one write-like step is the People Import preview (it only parses a file into the session and adds
// an audit-log line); it is always cancelled with "Cancel, Start Over".

const fs = require('fs');
const os = require('os');
const path = require('path');
const { launch, login, goto, shot, callout, clearCallouts, settle, OUT } = require('../lib.cjs');

const G = 'organization';
const W = 1440;

// ---------------------------------------------------------------- helpers ----
function fail(msg) { throw new Error('CAPTURE FAILED: ' + msg); }
const esc = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// Stop loudly on a PHP error page or an unexpectedly empty list.
async function check(page, { rows = 0, text = [] } = {}) {
  const body = await page.evaluate(() => document.body.innerText);
  if (/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:)/.test(body)) fail(`PHP error text on ${page.url()}`);
  if (/Nothing to see here|You don't have access/.test(body)) fail(`Unexpected page on ${page.url()}`);
  if (rows) {
    const n = await page.locator('table tbody tr').count();
    if (n < rows) fail(`expected at least ${rows} table rows on ${page.url()}, found ${n}`);
  }
  for (const t of text) if (!body.includes(t)) fail(`"${t}" not found on ${page.url()}`);
}

// Tag an element so callout() (plain CSS selectors) can find it.
async function mark(locator, id) {
  await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), id);
  return `[data-ug="${id}"]`;
}

// Screenshot a rectangle of the page (lib.shot() only does the viewport or one element).
async function shotClip(page, name, clip, { keepHover = false } = {}) {
  const file = path.join(OUT, G, `${name}.png`);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  if (!keepHover) await page.mouse.move(2, 2);
  await settle(page, 400);
  await page.screenshot({ path: file, clip });
  console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..'), file));
}

// The CRM cards (Open Opportunities, Activity Timeline) are not part of this guide; keep them out of the pictures.
async function hideCrmCards(page) {
  await page.evaluate(() => {
    document.querySelectorAll('h5.card-title, h3.card-title').forEach((h) => {
      if (/Open Opportunities|Activity Timeline/.test(h.textContent)) {
        const card = h.closest('.card');
        const row = card && card.closest('.row');
        (row && row.children.length === 1 ? row : card).style.display = 'none';
      }
    });
  });
}

async function setHeight(page, h) { await page.setViewportSize({ width: W, height: h }); }

async function openModal(page, opener) {
  await opener.click();
  await page.waitForSelector('.modal.show .modal-content');
  await page.waitForTimeout(900);
}
async function closeModal(page) {
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('.modal.show'), null, { timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(500);
}

// Row of a list whose bold name equals `name`.
function deptRow(page, name) {
  return page.locator('table tbody tr')
    .filter({ has: page.locator('td .fw-bold', { hasText: new RegExp(`^\\s*${esc(name)}\\s*$`) }) })
    .first();
}
async function deptHref(page, name, archived = false) {
  await goto(page, `/agent/clients.php?sort=client_name&order=ASC${archived ? '&archived=1' : ''}`);
  const href = await deptRow(page, name).locator('a[href^="client_overview.php"]').first().getAttribute('href');
  if (!href) fail(`department "${name}" not found`);
  return '/agent/' + href;
}
async function personHref(page, name) {
  await goto(page, `/agent/contacts.php?q=${encodeURIComponent(name)}`);
  const href = await page.locator('table tbody tr a[href^="contact_details.php"]').first().getAttribute('href');
  if (!href) fail(`person "${name}" not found`);
  return '/agent/' + href;
}

// ------------------------------------------------------------------- main ----
(async () => {
  const { browser, page } = await launch({ width: W, height: 900 });
  await login(page, 'admin');

  // ===== 01  Departments list: quick tour =====================================================
  await goto(page, '/agent/clients.php?sort=client_name&order=ASC');
  await check(page, { rows: 7, text: ['Production', 'Finance & Accounting'] });
  await callout(page, [
    { selector: 'input[name=q]', n: 1 },
    { selector: 'button[data-bs-target="#advancedFilter"]', n: 2, side: 'tr' },
    { selector: '.btn-toolbar .btn-group > a.btn', n: 3 },
    { selector: '.card-tools .btn-group', n: 4, side: 'tr' },
    { selector: 'table tbody tr:first-child button.btn-secondary', n: 5, side: 'tl' },
  ]);
  await shot(page, `${G}/01-departments-list`);
  await clearCallouts(page);

  // ===== 02  Filters: tag filter applied =========================================================
  await goto(page, '/agent/clients.php?sort=client_name&order=ASC');
  const tagId = await page.locator('select[name="tags[]"] option', { hasText: 'Regulated' }).first().getAttribute('value');
  if (!tagId) fail('tag "Regulated" missing (seed 15-organization)');
  await goto(page, `/agent/clients.php?sort=client_name&order=ASC&tags%5B%5D=${tagId}`);
  await page.waitForTimeout(700); // the filter panel opens by itself because a filter is active
  await check(page, { rows: 3 });
  await callout(page, [
    { selector: '#dateFilter', n: 1 },
    { selector: 'select[name="tags[]"] + .ts-wrapper', n: 2 },
    { selector: 'select[name=industry] + .ts-wrapper', n: 3 },
    { selector: 'select[name=referral] + .ts-wrapper', n: 4 },
  ]);
  await shot(page, `${G}/02-department-filters`);
  await clearCallouts(page);

  // ===== 03  Records pop-up ===================================================================
  await goto(page, '/agent/clients.php?sort=client_name&order=ASC');
  await deptRow(page, 'Finance & Accounting').locator('a[aria-label="Related records"]').hover();
  await page.waitForTimeout(1000);
  await shot(page, `${G}/03-department-records-popup`, { keepHover: true });

  // ===== 04 / 05  New Department pop-up (Details, Contact) =====================================
  await setHeight(page, 1300);
  await goto(page, '/agent/clients.php?sort=client_name&order=ASC');
  await openModal(page, page.locator('button.ajax-modal', { hasText: 'New Department' }).first());
  await page.fill('.modal.show input[name=name]', 'Quality Assurance');
  await page.fill('.modal.show input[name=abbreviation]', 'QA');
  await page.fill('.modal.show input[name=cost_center]', 'CC-520');
  await page.selectOption('.modal.show select[name="tags[]"]', [{ label: 'Regulated' }]);
  await page.mouse.move(2, 2);
  const contactTab = await mark(page.locator('.modal.show a.nav-link', { hasText: 'Contact' }), 'nd-contact-tab');
  await callout(page, [
    { selector: '.modal.show input[name=name]', n: 1 },
    { selector: '.modal.show input[name=cost_center]', n: 2 },
    { selector: contactTab, n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/04-new-department-details`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);

  await page.click(contactTab);
  await page.waitForTimeout(500);
  await page.fill('.modal.show input[name=contact]', 'Rosa Chen');
  await page.fill('.modal.show input[name=title]', 'QA Lead');
  await page.fill('.modal.show input[name=contact_phone]', '6085550155');
  await page.fill('.modal.show input[name=contact_email]', 'rosa.chen@summitridge.example');
  await callout(page, [
    { selector: '.modal.show input[name=contact]', n: 1 },
    { selector: '.modal.show button[name=add_client]', n: 2, side: 'tr' },
  ]);
  await shot(page, `${G}/05-new-department-contact`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);
  await setHeight(page, 900);

  // ===== 06  Archived departments, row menu with Restore ==========================================
  await goto(page, '/agent/clients.php?archived=1');
  await check(page, { rows: 1, text: ['Facilities & Maintenance'] });
  await page.locator('table tbody tr').first().locator('button.btn-secondary').click();
  await page.waitForTimeout(400);
  const restoreItem = await mark(page.locator('.dropdown-menu.show a', { hasText: 'Restore' }), 'restore-item');
  await callout(page, [
    { selector: '.btn-toolbar .btn-group > a.btn', n: 1 },
    { selector: restoreItem, n: 2, side: 'tl' },
  ]);
  await shot(page, `${G}/06-departments-archived`);
  await clearCallouts(page);
  await page.keyboard.press('Escape');

  // ===== 07  Department workspace ==================================================================
  const productionUrl = await deptHref(page, 'Production');
  const productionId = new URL(productionUrl, 'http://x').searchParams.get('client_id');
  await goto(page, productionUrl);
  await check(page, { text: ['Key Contacts', 'Quick Notes'] });
  await hideCrmCards(page);
  await callout(page, [
    { selector: 'a.client-nav-back', n: 1 },
    { selector: 'a.client-nav-header', n: 2, side: 'tr' },
    { selector: 'aside a[href*="/agent/contacts.php?client_id="]', n: 3, side: 'tr' },
    { selector: 'button[aria-label="Department actions"]', n: 4, side: 'tl' },
    { selector: '.js-dept-stats', n: 5, side: 'tl' },
  ]);
  await shot(page, `${G}/07-department-workspace`);
  await clearCallouts(page);

  // ===== 08  Department actions menu ===============================================================
  await page.click('button[aria-label="Department actions"]');
  await page.waitForTimeout(500);
  const menu = page.locator('.card-tools .dropdown-menu.show');
  const editItem = await mark(menu.locator('a', { hasText: 'Edit Department' }), 'edit-dept-item');
  const portalItem = await mark(menu.locator('a', { hasText: 'View Department Portal' }), 'portal-item');
  const archiveItem = await mark(menu.locator('a', { hasText: 'Archive Department' }), 'archive-dept-item');
  await callout(page, [
    { selector: editItem, n: 1, side: 'tl' },
    { selector: portalItem, n: 2, side: 'tl' },
    { selector: archiveItem, n: 3, side: 'tl' },
  ]);
  await shotClip(page, '08-department-actions-menu', { x: 704, y: 84, width: 736, height: 380 });
  await clearCallouts(page);
  await page.keyboard.press('Escape');

  // ===== 09  Delete department (archived department only) ==========================================
  const archivedUrl = await deptHref(page, 'Facilities & Maintenance', true);
  await goto(page, archivedUrl);
  await check(page, { text: ['(archived)'] });
  await page.click('button[aria-label="Department actions"]');
  await page.waitForTimeout(400);
  await page.locator('.card-tools .dropdown-menu.show a', { hasText: 'Delete Department' }).click();
  await page.waitForSelector('.modal.show .modal-content');
  await page.waitForTimeout(800);
  await page.locator('.modal.show .js-validate-client-delete').pressSequentially('Facilities & Maintenance', { delay: 15 });
  await page.waitForTimeout(400);
  await shot(page, `${G}/09-delete-department`, { selector: '.modal.show .modal-content' });
  await closeModal(page); // never press "Yes, Delete!"

  // ===== 10 / 11  Org chart =====================================================================
  await goto(page, `/agent/org_chart.php?client_id=${productionId}`);
  await page.waitForSelector('.org-node');
  await page.click('#orgChartExpandAll');
  await page.waitForTimeout(1000);
  await check(page, { text: ['Carlos Mendoza', 'Aisha Rahman', 'Nadia Kowalski'] });
  const aisha = page.locator('.org-node', { hasText: 'Aisha Rahman' }).first();
  await callout(page, [
    { selector: 'select[name=client_id] + .ts-wrapper', n: 1 },
    { selector: '#orgChartSearch', n: 2 },
    { selector: '.card-tools .btn-group', n: 3, side: 'tl' },
    { selector: await mark(aisha.locator('.org-node-trace-btn'), 'trace-btn'), n: 4, side: 'tr' },
    { selector: await mark(aisha.locator('.org-node-preview-btn'), 'preview-btn'), n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/10-org-chart`);
  await clearCallouts(page);

  await page.locator('.org-node', { hasText: 'Nadia Kowalski' }).first().hover();
  await page.waitForTimeout(700);
  await shot(page, `${G}/11-org-chart-preview`, { keepHover: true });

  // ===== 12  People list (company-wide) ============================================================
  await goto(page, '/agent/contacts.php');
  await check(page, { rows: 10, text: ['Company-wide', 'Primary Contact'] });
  await callout(page, [
    { selector: 'input[name=q]', n: 1 },
    { selector: 'select[name="tags[]"] + .ts-wrapper', n: 2 },
    { selector: 'select[name=client] + .ts-wrapper', n: 3 },
    { selector: 'a[href*="archived=1"].btn', n: 4 },
    { selector: '.card-tools .btn-group', n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/12-people-list`);
  await clearCallouts(page);

  // ===== 13 / 14  New person (Details, Access) ===================================================
  await setHeight(page, 1300);
  await goto(page, '/agent/contacts.php');
  await openModal(page, page.locator('button.ajax-modal', { hasText: 'New Contact' }).first());
  await page.selectOption('.modal.show select[name=client_id]', { label: 'Production' });
  await page.fill('.modal.show input[name=name]', 'Lucas Ferreira');
  await page.fill('.modal.show input[name=title]', 'Line Lead');
  await page.fill('.modal.show input[name=department]', 'Assembly Line 2');
  await page.fill('.modal.show input[name=start_date]', '2026-11-09');
  await page.fill('.modal.show input[name=phone]', '6085550156');
  await page.fill('.modal.show input[name=email]', 'lucas.ferreira@summitridge.example');
  await page.mouse.move(2, 2);
  await callout(page, [
    { selector: '.modal.show select[name=client_id] + .ts-wrapper', n: 1 },
    { selector: '.modal.show input[name=contact_primary]', n: 2, side: 'tr' },
    { selector: '.modal.show input[name=department]', n: 3 },
    { selector: '.modal.show input[name=start_date]', n: 4 },
  ]);
  await shot(page, `${G}/13-new-person-details`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);

  await page.click('.modal.show a.nav-link:has-text("Access")');
  await page.waitForTimeout(500);
  await callout(page, [
    { selector: '.modal.show input[name=pin]', n: 1 },
    { selector: '.modal.show select[name=auth_method] + .ts-wrapper', n: 2 },
    { selector: '.modal.show input[name=contact_important]', n: 3, side: 'tr' },
    { selector: '.modal.show input[name=contact_technical]', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/14-new-person-access`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);

  // ===== 15  Edit person (Employment fields) =======================================================
  await setHeight(page, 1500);
  await goto(page, '/agent/contacts.php?q=Nadia');
  await page.locator('table tbody tr').first().locator('button.btn-secondary').click();
  await page.waitForTimeout(300);
  await openModal(page, page.locator('.dropdown-menu.show a', { hasText: 'Edit' }).first());
  await page.mouse.move(2, 2);
  await callout(page, [
    { selector: '.modal.show input[name=employee_id]', n: 1 },
    { selector: '.modal.show select[name=manager_id] + .ts-wrapper', n: 2 },
    { selector: '.modal.show select[name=employee_type] + .ts-wrapper', n: 3 },
    { selector: '.modal.show select[name=employment_status] + .ts-wrapper', n: 4 },
    { selector: '.modal.show select[name=work_arrangement] + .ts-wrapper', n: 5 },
  ]);
  await shot(page, `${G}/15-edit-person`, { selector: '.modal.show .modal-content' });
  await clearCallouts(page);
  await closeModal(page);

  // ===== 16  Person page =========================================================================
  const nadiaUrl = await personHref(page, 'Nadia Kowalski');
  await goto(page, nadiaUrl);
  await check(page, { text: ['Employment', 'Workflows', 'Reports to'] });
  await hideCrmCards(page);
  await callout(page, [
    { selector: 'button.float-end.ajax-modal', n: 1, side: 'tr' },
    { selector: await mark(page.locator('.card', { has: page.locator('h5.card-title', { hasText: 'Employment' }) }), 'employment-card'), n: 2 },
    { selector: await mark(page.locator('.card', { has: page.locator('h5.card-title', { hasText: 'Workflows' }) }), 'workflows-card'), n: 3 },
    { selector: 'ol.breadcrumb + .btn-group', n: 4 },
    { selector: await mark(page.locator('.card', { has: page.locator('h3.card-title', { hasText: 'History' }) }), 'history-card'), n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/16-person-page`);
  await clearCallouts(page);

  // ===== 17  People, archived view with the bulk menu ================================================
  await setHeight(page, 900);
  await goto(page, '/agent/contacts.php?archived=1');
  await check(page, { rows: 2, text: ['Dale Whitaker'] });
  await page.locator('input.bulk-select').first().check();
  await page.waitForTimeout(400);
  await page.click('#bulkActionButton button.dropdown-toggle');
  await page.waitForTimeout(400);
  const bulkRestore = await mark(page.locator('#bulkActionButton .dropdown-menu button', { hasText: 'Restore' }), 'bulk-restore');
  const bulkDelete = await mark(page.locator('#bulkActionButton .dropdown-menu button', { hasText: 'Delete' }), 'bulk-delete');
  await callout(page, [
    { selector: 'a[href*="archived=0"].btn', n: 1 },
    { selector: bulkRestore, n: 2, side: 'tl' },
    { selector: bulkDelete, n: 3, side: 'tl' },
  ]);
  await shot(page, `${G}/17-people-archived`);
  await clearCallouts(page);
  await page.keyboard.press('Escape');

  // ===== 18  People Import (Administration) ========================================================
  await goto(page, '/admin/people_import.php');
  await check(page, { text: ['People Import', 'Required columns'] });
  await callout(page, [
    { selector: await mark(page.locator('a', { hasText: 'Download a sample CSV template' }), 'csv-template'), n: 1 },
    { selector: 'input[type=file][name=file]', n: 2 },
    { selector: 'button[name=preview_people_import]', n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/18-people-import`);
  await clearCallouts(page);

  // ===== 19  People Import preview =================================================================
  const csv = path.join(os.tmpdir(), 'rivetit-people-import-demo.csv');
  fs.writeFileSync(csv, [
    'employee_id,name,email,job_title,department,site,manager_email,phone,mobile,start_date,employee_type,employment_status,work_arrangement',
    'E1080,Anika Desai,anika.desai@summitridge.example,CAD Technician,Engineering,,yuki.tanaka@summitridge.example,6085550180,,2026-11-02,employee,pre-hire,hybrid',
    'E1081,Lucas Ferreira,lucas.ferreira@summitridge.example,Line Lead,Production,,carlos.mendoza@summitridge.example,6085550181,,2026-11-09,employee,pre-hire,onsite',
    'E1044,Nadia Kowalski,nadia.kowalski@summitridge.example,Quality Inspector,Production,,aisha.rahman@summitridge.example,6085550144,,2026-10-12,employee,pre-hire,onsite',
    'E1082,Sam Okoye,sam.okoye@summitridge.example,Buyer,Purchasing,,,6085550182,,2026-11-09,employee,pre-hire,onsite',
    '',
  ].join('\n'));
  await page.setInputFiles('input[type=file][name=file]', csv);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=preview_people_import]')]);
  await settle(page, 600);
  await check(page, { text: ['to create', 'to update', 'with errors'] });
  await callout(page, [
    { selector: 'div.card-body > p:first-of-type', n: 1 },
    { selector: await mark(page.locator('table tbody tr').nth(0).locator('td .badge'), 'imp-create'), n: 2 },
    { selector: await mark(page.locator('table tbody tr').nth(2).locator('td .badge'), 'imp-update'), n: 3 },
    { selector: await mark(page.locator('table tbody tr').nth(3).locator('td .badge'), 'imp-skip'), n: 4 },
    { selector: 'button[name=approve_people_import]', n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/19-people-import-preview`);
  await clearCallouts(page);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[name=cancel_people_import]')]);
  await settle(page, 400);
  fs.rmSync(csv, { force: true });

  // ===== 20  Employee Workflow Templates ===========================================================
  await goto(page, '/admin/employee_workflow_templates.php');
  await check(page, { rows: 3, text: ['New Employee Onboarding', 'Employee Offboarding'] });
  await callout(page, [
    { selector: 'button.ajax-modal', n: 1, side: 'tr' },
    { selector: 'table tbody tr:first-child td:first-child a', n: 2 },
    { selector: 'table tbody tr:first-child a.btn', n: 3, side: 'tr' },
    { selector: 'table tbody tr:first-child .dropdown button', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/20-workflow-templates`);
  await clearCallouts(page);

  // ===== 21  Template tasks ========================================================================
  await setHeight(page, 1060);
  await page.locator('table tbody tr a', { hasText: 'New Employee Onboarding' }).first().click();
  await settle(page, 600);
  await check(page, { text: ['Add Task', 'Create the network account and mailbox'] });
  await callout(page, [
    { selector: 'table tbody tr:first-child button[name=move_employee_workflow_template_task_down]', n: 1, side: 'tr' },
    { selector: await mark(page.locator('table thead th', { hasText: 'Required' }), 'required-col'), n: 2, side: 'tr' },
    { selector: 'form input[name=title]', n: 3 },
    { selector: 'button[name=add_employee_workflow_template_task]', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/21-workflow-template-tasks`);
  await clearCallouts(page);
  await setHeight(page, 900);

  // ===== 22  Person page, Workflows card =============================================================
  await goto(page, nadiaUrl);
  const wfCard = page.locator('.card', { has: page.locator('h5.card-title', { hasText: 'Workflows' }) }).first();
  await callout(page, [
    { selector: await mark(wfCard.locator('a[href*="workflow_run.php"]'), 'run-link'), n: 1 },
    { selector: await mark(wfCard.locator('form select[name=workflow_template_id], form .ts-wrapper').first(), 'wf-select'), n: 2 },
    { selector: await mark(wfCard.locator('button[name=start_employee_workflow]'), 'wf-start'), n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/22-person-workflows-card`, { selector: await mark(wfCard, 'wf-card-shot') });
  await clearCallouts(page);
  const runHref = await wfCard.locator('a[href*="workflow_run.php"]').first().getAttribute('href');

  // ===== 23  Workflow checklist ======================================================================
  await goto(page, '/agent/' + runHref);
  await check(page, { text: ['Checklist', 'Cancel Workflow', 'Skipped:'] });
  await callout(page, [
    { selector: 'h3.card-title .badge', n: 1 },
    { selector: 'button[name=complete_workflow_run_task]', n: 2, side: 'tl' },
    { selector: 'button[data-bs-target^="#skipTaskModal"]', n: 3, side: 'tr' },
    { selector: 'button[name=reopen_workflow_run_task]', n: 4, side: 'tl' },
    { selector: 'button[name=cancel_workflow_run]', n: 5 },
  ]);
  await shot(page, `${G}/23-workflow-checklist`);
  await clearCallouts(page);

  // ===== 24  Onboarding Template (department onboarding checklist) ===================================
  await goto(page, '/admin/onboarding_templates.php');
  await check(page, { rows: 1, text: ['New Department Onboarding'] });
  await page.locator('table tbody tr a', { hasText: 'New Department Onboarding' }).first().click();
  await settle(page, 600);
  await check(page, { text: ['Onboarding Checklist'] });
  const obCard = page.locator('.card', { has: page.locator('h5', { hasText: 'Onboarding Checklist' }) }).first();
  await callout(page, [
    { selector: 'input[name=task_name]', n: 1 },
    { selector: '#checklist_tasks tr:first-child .drag-handle', n: 2 },
    { selector: '#checklist_tasks tr:first-child button[data-bs-toggle=dropdown]', n: 3, side: 'tr' },
  ]);
  await shot(page, `${G}/24-onboarding-template`, { selector: await mark(obCard, 'ob-card-shot') });
  await clearCallouts(page);

  await browser.close();
  console.log('organization captures done');
})().catch((e) => { console.error(e); process.exit(1); });
