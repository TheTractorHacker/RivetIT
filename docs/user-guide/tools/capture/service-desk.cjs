// Screenshots for the Service Desk guide pages (03-tickets.md, 03b-..., 03c-...).
// Read-only: it opens pages and pop-ups, types illustrative text, and never presses a save button.
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/service-desk.cjs
//
// Needs the data from seed/30-service-desk.sql. Tickets are found by subject (their numbers depend on
// what else was seeded), so nothing here hard-codes a ticket id.
const { launch, login, goto, shot, callout, clearCallouts, settle, BASE } = require('../lib.cjs');

const G = 'service-desk';
const problems = [];

function fail(msg) { problems.push(msg); console.error('PROBLEM:', msg); }

// Every page must render without a PHP error or an empty state where data is expected.
async function check(page, what, { expectSelector } = {}) {
  const text = await page.evaluate(() => document.body.innerText || '');
  if (/(Fatal error|Warning:|Notice:|Parse error|Uncaught|Stack trace)/.test(text)) fail(`${what}: PHP error text on page`);
  if (expectSelector && !(await page.locator(expectSelector).count())) fail(`${what}: expected ${expectSelector}`);
}

// Find a ticket's URL by searching the list for its subject (exact link-text match).
async function ticketUrl(page, subject) {
  await goto(page, `/agent/tickets.php?status=All&q=${encodeURIComponent(subject)}`);
  const href = await page.evaluate((s) => {
    const a = Array.from(document.querySelectorAll('#bulkActions a[href^="ticket.php?ticket_id="]')).find((x) => x.textContent.trim() === s);
    return a ? a.getAttribute('href') : null;
  }, subject);
  if (!href) { fail(`ticket not found: ${subject}`); return null; }
  return `/agent/${href.replace(/&client_id=\d+/, '')}`;
}

async function openModal(page, trigger, { wait = 900 } = {}) {
  await page.locator(trigger).first().click();
  await page.waitForSelector('.modal.show .modal-content', { timeout: 15000 });
  await page.waitForTimeout(wait);
}

async function closeModal(page) {
  await page.evaluate(() => {
    document.querySelectorAll('.modal.show').forEach((m) => {
      const inst = window.bootstrap && window.bootstrap.Modal.getInstance(m);
      if (inst) inst.hide();
    });
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove()));
}

// Close any open drop-down menu without clicking anything on the page.
async function dismiss(page) {
  await page.keyboard.press('Escape');
  await page.evaluate(() => {
    document.querySelectorAll('.dropdown-menu.show, .dropdown-toggle.show').forEach((e) => e.classList.remove('show'));
    if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
  });
  await page.waitForTimeout(200);
}

async function collapseSidebar(page) {
  await page.click('#itflowSidebarToggle');
  await page.waitForTimeout(700);
}

(async () => {
  const { browser, page } = await launch();
  await login(page, 'admin');

  // ------------------------------------------------------------------ 03-tickets
  // 01 - where to find it: the Service Desk group of the sidebar
  await goto(page, '/agent/tickets.php');
  await check(page, 'tickets', { expectSelector: '#bulkActions' });
  await shot(page, `${G}/01-sidebar-service-desk`, { selector: 'aside.navbar-vertical' });

  // 02 - list tour (wide window so every column fits; sidebar collapsed to save room)
  await page.setViewportSize({ width: 1800, height: 1000 });
  const hardware = page.locator('.ticket-saved-views a', { hasText: 'Hardware' }).first();
  await goto(page, '/agent/tickets.php');
  const hwHref = await hardware.getAttribute('href');
  await goto(page, `/agent/tickets.php${hwHref}`);
  await collapseSidebar(page);
  await check(page, 'tickets board', { expectSelector: '.ticket-group-header' });
  await callout(page, [
    { selector: '.ticket-stat-row', n: 1 },
    { selector: '.ticket-saved-views ul.nav', n: 2 },
    { selector: '.ticket-filter-bar .filter-toolbar', n: 3 },
    { selector: 'button[data-modal-url*="ticket_add_v2"]', n: 4, side: 'tr' },
    { selector: '#bulkActions table thead', n: 5 },
  ]);
  await shot(page, `${G}/02-ticket-list-tour`);
  await clearCallouts(page);

  // 03 - More Filters
  await page.click('button[data-bs-target="#advancedFilter"]');
  await page.waitForTimeout(600);
  await shot(page, `${G}/03-ticket-list-more-filters`, { selector: 'form.ticket-filter-bar' });

  // 04 - quick change from the list: the status pill menu
  await page.click('button[data-bs-target="#advancedFilter"]');
  await page.waitForTimeout(400);
  await page.locator('#bulkActions .tkt-pill-badge.dropdown-toggle', { hasText: /^\s*(Open|New)\s*$/ }).first().click();
  await page.waitForTimeout(500);
  await shot(page, `${G}/04-ticket-list-status-menu`);
  await page.keyboard.press('Escape');
  await dismiss(page);

  // 05 - bulk actions
  await page.evaluate(() => window.scrollTo(0, 0));
  const boxes = page.locator('#bulkActions input.bulk-select');
  await boxes.nth(0).check();
  await boxes.nth(1).check();
  await page.waitForTimeout(400);
  await page.click('#bulkActionButton button.dropdown-toggle');
  await page.waitForTimeout(500);
  await shot(page, `${G}/05-ticket-list-bulk-actions`);
  await page.keyboard.press('Escape');
  await boxes.nth(0).uncheck();
  await boxes.nth(1).uncheck();

  // 06 - kanban view (sidebar back to its normal state for the rest of the run)
  await collapseSidebar(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await goto(page, '/agent/tickets.php?view=kanban&status=Open');
  await check(page, 'kanban', { expectSelector: '#kanban-board' });
  await shot(page, `${G}/06-ticket-kanban`);

  // 07..09 - New Ticket form, three tabs
  await goto(page, '/agent/tickets.php');
  await openModal(page, 'button[data-modal-url*="ticket_add_v2"]', { wait: 1500 });
  await page.fill('#subjectInput', 'Second monitor for the design studio');
  await shot(page, `${G}/07-new-ticket-details`, { selector: '.modal.show .modal-content' });
  await page.click('.modal.show a[href="#pills-add-contacts"]');
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const sel = document.getElementById('changeClientSelect');
    const opt = Array.from(sel.options).find((o) => /Finance/.test(o.text));
    if (opt && sel.tomselect) sel.tomselect.setValue(opt.value);
  });
  await page.waitForTimeout(1500);
  await page.evaluate(() => {
    const sel = document.getElementById('contactSelect');
    const opt = Array.from(sel.options).find((o) => /Grace/.test(o.text));
    if (opt && sel.tomselect) sel.tomselect.setValue(opt.value);
  });
  await page.waitForTimeout(500);
  await shot(page, `${G}/08-new-ticket-contact`, { selector: '.modal.show .modal-content' });
  await page.click('.modal.show a[href="#pills-add-relationships"]');
  await page.waitForTimeout(500);
  await shot(page, `${G}/09-new-ticket-assignment`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  // 10 - department workspace list
  await goto(page, '/agent/clients.php?q=Finance');
  const financeUrl = await page.evaluate(() => {
    const a = Array.from(document.querySelectorAll('a[href*="client_id="]')).find((x) => /Finance/.test(x.textContent));
    const m = a && a.getAttribute('href').match(/client_id=(\d+)/);
    return m ? m[1] : null;
  });
  if (financeUrl) {
    const boardId = ((hwHref || '').match(/board=(\d+)/) || [])[1];
    await page.setViewportSize({ width: 1800, height: 1000 });
    await goto(page, `/agent/tickets.php?client_id=${financeUrl}${boardId ? `&board=${boardId}` : ''}`);
    await check(page, 'department tickets', { expectSelector: '#bulkActions' });
    await shot(page, `${G}/10-department-ticket-list`);
    await page.setViewportSize({ width: 1440, height: 900 });
  } else fail('Finance department id not found');

  // 11 - ticket page tour (Laptop will not boot: the fully worked example)
  const laptop = await ticketUrl(page, 'Laptop will not boot after Windows update');
  if (laptop) {
    await goto(page, laptop);
    await check(page, 'ticket page', { expectSelector: '#ticketReplyForm' });
    await callout(page, [
      { selector: '.ticket-header-compact h4', n: 1 },
      { selector: 'a[data-modal-url*="ticket_status.php"]', n: 2 },
      { selector: '#quickAssignSelect', n: 3 },
      { selector: '.ticket-header-compact div.border-top.pt-2', n: 4 },
      { selector: '#ticketReplyForm .btn-group.js-btn-group-toggle', n: 5 },
      { selector: '.time-entry-card', n: 6, side: 'tr' },
    ]);
    await shot(page, `${G}/11-ticket-page-tour`);
    await clearCallouts(page);

    // 12 - the whole thread with each kind of entry
    await shot(page, `${G}/12-ticket-full-page`, { fullPage: true });

    // 13 - reply composer with the canned response list
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click('#cannedResponseDropdown');
    await page.waitForTimeout(400);
    await shot(page, `${G}/13-reply-composer-canned`, { selector: '.col-md-9 > form#ticketReplyForm, #ticketReplyForm' });
    await page.keyboard.press('Escape');
    await dismiss(page);

    // 14 - submit & set status menu
    await page.locator('#ticketReplyForm button.dropdown-toggle-split').scrollIntoViewIfNeeded();
    await page.evaluate(() => {
      const b = document.querySelector('#ticketReplyForm button.dropdown-toggle-split');
      const r = b.getBoundingClientRect();
      window.scrollBy(0, r.top - 200);
    });
    await page.click('#ticketReplyForm button.dropdown-toggle-split');
    await page.waitForTimeout(400);
    await shot(page, `${G}/14-reply-status-menu`, { keepHover: true });
    await page.keyboard.press('Escape');
    await dismiss(page);

    // 15 - the ticket's action menu
    await page.click('#dropdownMenuButton');
    await page.waitForTimeout(400);
    await shot(page, `${G}/15-ticket-action-menu`);
    await page.keyboard.press('Escape');
    await dismiss(page);
  }

  // 16 - status change pop-up, 17 - merge pop-up
  const printer = await ticketUrl(page, 'Finance floor printer shows Offline for everyone');
  if (printer) {
    await goto(page, printer);
    await openModal(page, 'a[data-modal-url*="ticket_status.php"]');
    await shot(page, `${G}/16-ticket-status-modal`, { selector: '.modal.show .modal-content' });
    await closeModal(page);
    await page.click('#dropdownMenuButton');
    await page.locator('a[data-modal-url*="ticket_merge.php"]').click();
    await page.waitForSelector('.modal.show .modal-content');
    await page.waitForTimeout(900);
    await shot(page, `${G}/17-ticket-merge-modal`, { selector: '.modal.show .modal-content' });
    await closeModal(page);
  }

  // 18 - sidebar cards of a busy ticket (tasks, watchers, appointments, technicians, live chat)
  const cad = await ticketUrl(page, 'CAD workstation crashes when opening large assemblies');
  if (cad) {
    await goto(page, cad);
    for (const id of ['liveChat', 'activitySummary', 'timeEntryLog']) {
      const t = page.locator(`h5[data-bs-target="#sidebarBody-${id}"]`);
      if (await t.count()) await t.click();
    }
    await page.waitForTimeout(800);
    await shot(page, `${G}/18-ticket-sidebar-cards`, { selector: '.ticket-sidebar' });
  }
  const scanner = await ticketUrl(page, 'Handheld scanner will not sync to the inventory system');
  if (scanner) {
    await goto(page, scanner);
    for (const id of ['watchers', 'appointments', 'technicians']) {
      const t = page.locator(`h5[data-bs-target="#sidebarBody-${id}"]`);
      if (await t.count()) await t.click();
    }
    await page.waitForTimeout(800);
    await shot(page, `${G}/19-ticket-appointments-technicians`, { selector: '.ticket-sidebar' });
  }

  // 20 - a closed ticket with its CSAT rating
  const closed = await ticketUrl(page, 'Password reset - locked out after the long weekend');
  if (closed) {
    await goto(page, closed);
    const t = page.locator('h5[data-bs-target="#sidebarBody-activitySummary"]');
    if (await t.count()) await t.click();
    await page.waitForTimeout(700);
    await callout(page, [
      { selector: 'a[href*="reopen_ticket"]', n: 1 },
      { selector: '#sidebarBody-activitySummary', n: 2 },
    ]);
    await shot(page, `${G}/20-closed-ticket`, { fullPage: true });
    await clearCallouts(page);
  }

  // 21 - saved view pop-up
  await goto(page, '/agent/tickets.php?status=Open&priority=High');
  await openModal(page, 'button[data-modal-url*="ticket_saved_view_add"]');
  await page.fill('.modal.show input[name=name]', 'High priority open');
  await shot(page, `${G}/21-save-view-modal`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  // ------------------------------------------------------------------ 03b
  await goto(page, '/agent/recurring_tickets.php');
  await check(page, 'recurring', { expectSelector: 'table tbody tr' });
  await callout(page, [
    { selector: 'button[data-modal-url*="recurring_ticket_add"]', n: 1, side: 'tr' },
    { selector: 'table thead th:nth-child(2)', n: 2 },
    { selector: 'table thead th:nth-child(6)', n: 3 },
    { selector: 'table tbody tr:first-child button[data-bs-toggle="dropdown"]', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/22-recurring-tickets-list`);
  await clearCallouts(page);
  await page.click('table tbody tr:first-child button[data-bs-toggle="dropdown"]');
  await page.waitForTimeout(400);
  await shot(page, `${G}/23-recurring-row-menu`);
  await page.keyboard.press('Escape');
  await dismiss(page);
  await openModal(page, 'table tbody tr:first-child td:nth-child(3) a', { wait: 1500 });
  await shot(page, `${G}/24-recurring-edit-details`, { selector: '.modal.show .modal-content' });
  await page.click('.modal.show a[href="#pills-edit-schedule"]');
  await page.waitForTimeout(500);
  await shot(page, `${G}/25-recurring-edit-schedule`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  await goto(page, '/agent/service_catalog.php');
  await check(page, 'service catalog', { expectSelector: '.service-catalog-tile' });
  await callout(page, [
    { selector: '.service-catalog-tile', n: 1 },
    { selector: '.card-header .btn-secondary', n: 2, side: 'tr' },
  ]);
  await shot(page, `${G}/26-request-something`);
  await clearCallouts(page);
  await page.locator('.service-catalog-tile').first().click();
  await page.waitForSelector('.modal.show .modal-content');
  await page.waitForTimeout(1500);
  await shot(page, `${G}/27-request-something-prefilled`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  // ------------------------------------------------------------------ 03c
  await goto(page, '/agent/problems.php');
  await check(page, 'problems', { expectSelector: 'table tbody tr' });
  await callout(page, [
    { selector: 'button[data-modal-url*="problem_add"]', n: 1, side: 'tr' },
    { selector: '.ts-wrapper', n: 2 },
    { selector: 'table thead th:nth-child(3)', n: 3 },
  ]);
  await shot(page, `${G}/28-problems-list`);
  await clearCallouts(page);
  await openModal(page, 'button[data-modal-url*="problem_add"]');
  await page.fill('.modal.show input[name=title]', 'Example: file server keeps restarting');
  await shot(page, `${G}/29-problem-new`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  await goto(page, '/agent/problems.php');
  await page.locator('table tbody a', { hasText: 'Intermittent VPN disconnects' }).first().click();
  await page.waitForLoadState('domcontentloaded');
  await settle(page);
  await check(page, 'problem details', { expectSelector: 'button[name=link_problem_ticket]' });
  await callout(page, [
    { selector: '.card-body form button[name=set_problem_status]', n: 1 },
    { selector: 'button[name=link_problem_ticket]', n: 2 },
    { selector: 'button[name=unlink_problem_change]', n: 3 },
  ]);
  await shot(page, `${G}/30-problem-details`);
  await clearCallouts(page);

  await goto(page, '/agent/problems.php');
  await page.locator('table tbody a', { hasText: 'Weak Wi-Fi coverage' }).first().click();
  await page.waitForLoadState('domcontentloaded');
  await settle(page);
  await shot(page, `${G}/31-problem-without-change`);

  await goto(page, '/agent/changes.php');
  await check(page, 'changes', { expectSelector: 'table tbody tr' });
  await shot(page, `${G}/32-changes-list`);
  await openModal(page, 'button[data-modal-url*="change_add"]');
  await page.fill('.modal.show input[name=title]', 'Example: move the file server to new hardware');
  await shot(page, `${G}/33-change-new`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  await goto(page, '/agent/changes.php');
  await page.locator('table tbody a', { hasText: 'Update the edge firewall' }).first().click();
  await page.waitForLoadState('domcontentloaded');
  await settle(page);
  await check(page, 'change details', { expectSelector: 'button[name=set_change_status]' });
  await callout(page, [
    { selector: 'button[name=set_change_status]', n: 1 },
    { selector: 'button[name=reschedule_change]', n: 2 },
  ]);
  await shot(page, `${G}/34-change-details`);
  await clearCallouts(page);

  await goto(page, '/agent/mail_requests.php');
  await check(page, 'requests', { expectSelector: 'table tbody tr' });
  await callout(page, [
    { selector: 'table tbody tr:first-child .btn-secondary', n: 1 },
    { selector: 'table tbody tr:first-child .btn-primary', n: 2 },
    { selector: 'table tbody tr:first-child .btn-danger', n: 3 },
  ]);
  await shot(page, `${G}/35-requests-list`);
  await clearCallouts(page);
  await openModal(page, 'table tbody tr:first-child a[data-modal-url*="mail_request_view"]');
  await shot(page, `${G}/36-request-view`, { selector: '.modal.show .modal-content' });
  await closeModal(page);
  await openModal(page, 'table tbody tr:first-child a[data-modal-url*="mail_request_convert"]');
  await shot(page, `${G}/37-request-convert`, { selector: '.modal.show .modal-content' });
  await closeModal(page);

  await page.setViewportSize({ width: 1440, height: 900 });
  await goto(page, '/agent/csat.php');
  await check(page, 'csat', { expectSelector: 'table tbody tr' });
  await callout(page, [
    { selector: 'a[href*="reports/csat.php"]', n: 1, side: 'tr' },
    { selector: 'select[name=rating]', n: 2 },
    { selector: 'a[href*="followup="]', n: 3 },
    { selector: 'table tbody tr.table-danger', n: 4, side: 'tr' },
  ]);
  await shot(page, `${G}/38-csat-ratings`);
  await clearCallouts(page);

  await browser.close();
  if (problems.length) { console.error(`\n${problems.length} problem(s)`); process.exit(1); }
})().catch((e) => { console.error(e); process.exit(1); });
