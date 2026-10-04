// Screenshots for docs/user-guide/11-employee-portal.md (group "portal").
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/portal.cjs
//
// Needs seed/70-portal.php (the two employee logins, their tickets and the rest).
// READ-ONLY: nothing is submitted. Forms are filled in only to be photographed, then left.
// The only side effects are the ones the app itself has: entering/leaving a portal preview and
// opening a share link are written to the audit log, and the share link's view counter goes up by one.

const { launch, login, goto, shot, callout, clearCallouts, settle, BASE } = require('../lib.cjs');

const PASSWORD = 'DemoPass#2026';
const SOPHIE = { email: 'sophie.tran@summitridge.example', password: PASSWORD };
const GRACE = { email: 'grace.okafor@summitridge.example', password: PASSWORD };
// Fixed keys written by seed/70-portal.php - keep in step.
const GUEST_TICKET_KEY = 'ugportalprinterticketkey00000001';
const SHARE_DOC_KEY = 'ugportalsharedocumentkey000000001';

/** Fail loudly on a PHP error or when the page does not show what it should. */
async function check(page, label, mustShow) {
  const text = await page.evaluate(() => document.body.innerText);
  const bad = text.match(/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:)/);
  if (bad) throw new Error(`${label}: page shows a PHP problem (${bad[1]})`);
  for (const s of [].concat(mustShow || [])) {
    if (!text.toLowerCase().includes(s.toLowerCase())) throw new Error(`${label}: expected to find "${s}" on the page (${page.url()})`);
  }
}

async function open(page, url, label, mustShow) {
  await goto(page, url);
  await check(page, label, mustShow);
}

/** href of the portal ticket whose subject matches, read from the employee's ticket list. */
async function ticketHref(page, subject) {
  await goto(page, '/client/tickets.php?status=%25');
  const link = page.locator('table a', { hasText: subject }).first();
  if (!(await link.count())) throw new Error(`ticket "${subject}" not found in the list`);
  return link.getAttribute('href');
}

(async () => {
  let ticketId = null;

  // ---- IT staff: the switch, the People form, the preview (administrator) ----------------------------------------------
  {
    const { browser, page } = await launch();
    try {
      await login(page, 'admin');

      await open(page, '/admin/settings_module.php', 'modules', 'Enable Department Portal');
      await callout(page, [{ selector: '.form-check:has(#customSwitch4)', n: 1 }]);
      await shot(page, 'portal/01-modules-toggle');

      // Department id of Human Resources, read from the preview page's own links.
      await open(page, '/admin/portal_preview.php', 'preview list', ['Department Portal Preview', 'Portal Enabled']);
      const hrHref = await page.locator('table a[href*="client_overview.php"]', { hasText: 'Human Resources' }).first().getAttribute('href');
      const hrId = new URL(hrHref, BASE).searchParams.get('client_id');

      await open(page, `/agent/contacts.php?client_id=${hrId}`, 'People (Human Resources)', 'Sophie Tran');
      await page.locator('tr', { hasText: 'Sophie Tran' }).first()
        .locator('a.dropdown-item.ajax-modal[data-modal-url*="contact_edit.php"]').evaluate((el) => el.click());
      await page.waitForSelector('.modal.show .modal-content');
      await page.locator('.modal.show a.nav-link', { hasText: 'Access' }).click();
      await page.waitForSelector('.modal.show .authForm', { state: 'visible' });
      await callout(page, [{ selector: '.modal.show .authForm .input-group', n: 1 }]);
      await shot(page, 'portal/02-people-portal-access', { selector: '.modal.show .modal-content' });
      await clearCallouts(page);
      await page.keyboard.press('Escape');

      await open(page, '/admin/portal_preview.php', 'preview list', 'Finance & Accounting');
      await page.locator('tr', { hasText: 'Finance & Accounting' }).locator('a.btn').first().evaluate((el) => el.setAttribute('data-ug', 'finance-view'));
      await callout(page, [{ selector: '.card-header .badge', n: 1, side: 'tr' }, { selector: '[data-ug=finance-view]', n: 2 }]);
      await shot(page, 'portal/03-portal-preview-list');
      await clearCallouts(page);

      // Enter the preview, photograph the banner, leave again.
      await Promise.all([page.waitForURL('**/client/index.php'), page.click('[data-ug=finance-view]')]);
      await settle(page);
      await check(page, 'preview', ['Read-only preview', 'Finance & Accounting']);
      await page.setViewportSize({ width: 1440, height: 480 });
      await callout(page, [{ selector: 'div.sticky-top strong.text-uppercase', n: 1, side: 'bl' }, { selector: 'div.sticky-top a.btn', n: 2, side: 'bl' }]);
      await shot(page, 'portal/04-preview-banner');
      await clearCallouts(page);
      await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('div.sticky-top a.btn')]);
      await settle(page);
    } finally {
      await browser.close();
    }
  }

  // ---- the sign-in page (signed out) ---------------------------------------------------------------------------------
  {
    const { browser, page } = await launch({ height: 820 });
    try {
      await open(page, '/login.php', 'sign in', 'Sign In');
      await callout(page, [
        { selector: 'input[name=email]', n: 1 },
        { selector: 'input[name=password]', n: 2 },
        { selector: 'button[name=login]', n: 3 },
      ]);
      await shot(page, 'portal/05-sign-in');
    } finally {
      await browser.close();
    }
  }

  // ---- an ordinary employee: Sophie Tran, HR Coordinator ---------------------------------------------------------------
  {
    const { browser, page } = await launch({ height: 1040 });
    try {
      await login(page, SOPHIE);
      await open(page, '/client/index.php', 'employee home', ['Human Resources Department Portal', 'Recent tickets', 'LT-HR-06']);
      await callout(page, [
        { selector: '#navbarSupportedContent .navbar-nav.me-auto', n: 1, side: 'bl' },
        { selector: '.portal-hero-actions a.btn-light', n: 2 },
        { selector: '.portal-hero-actions a.btn-glass', n: 3 },
        { selector: 'a.portal-stat', n: 4 },
        { selector: '.col-lg-7 .portal-card', n: 5 },
        { selector: '#navbarDropdown', n: 6, side: 'bl' },
      ]);
      await shot(page, 'portal/06-employee-home');
      await clearCallouts(page);

      await page.setViewportSize({ width: 1440, height: 900 });
      await open(page, '/client/tickets.php', 'ticket list', ['Open Tickets', 'HR office printer prints blank pages']);
      await callout(page, [
        { selector: '.col-md-9 .card', n: 1 },
        { selector: '.col-md-3 .list-group', n: 2 },
        { selector: '.col-md-3 > a.btn-primary', n: 3 },
      ]);
      await shot(page, 'portal/08-ticket-list');
      await clearCallouts(page);

      // New ticket form: fill it in to photograph it, never submit it.
      await page.setViewportSize({ width: 1440, height: 1020 });
      await open(page, '/client/ticket_add.php', 'new ticket', 'Raise a new ticket');
      await page.fill('input[name=subject]', 'Laptop fan is very loud');
      await page.selectOption('select[name=priority]', 'Medium');
      await page.selectOption('select[name=category]', { label: 'Hardware' });
      await page.selectOption('select[name=asset]', { label: 'LT-HR-06 (Laptop)' });
      await page.waitForSelector('.tox-tinymce');
      await page.evaluate(() => window.tinymce.get(0).setContent('<p>The fan on my laptop runs at full speed all day and I can hear it in meetings. It started after last week\'s update.</p>'));
      await callout(page, [
        { selector: 'input[name=subject]', n: 1, side: 'tr' },
        { selector: 'select[name=priority]', n: 2, side: 'tr' },
        { selector: 'select[name=category]', n: 3, side: 'tr' },
        { selector: '.tox-tinymce', n: 4, side: 'tr' },
        { selector: 'button[name=add_ticket]', n: 5 },
      ]);
      await shot(page, 'portal/07-new-ticket');
      await clearCallouts(page);

      // A ticket in progress.
      const printerHref = await ticketHref(page, 'HR office printer prints blank pages');
      ticketId = new URL(printerHref, BASE + '/client/').searchParams.get('id');
      await page.setViewportSize({ width: 1440, height: 1500 });
      await open(page, '/client/' + printerHref, 'ticket', ['HR office printer prints blank pages', 'Priya Nair']);
      await page.waitForSelector('.tox-tinymce');
      await page.waitForFunction(() => document.querySelectorAll('#ticket-chat-messages > *').length > 0);
      await callout(page, [
        { selector: '.card-tools a', n: 1, side: 'tr' },
        { selector: '.card[data-ticket-id] .card-body p', n: 2 },
        { selector: '.card:has(#ticket-chat-messages)', n: 3 },
        { selector: '.tox-tinymce', n: 4 },
        { selector: 'button[name=add_ticket_comment]', n: 5 },
      ]);
      await shot(page, 'portal/09-ticket');
      await clearCallouts(page);

      // A closed ticket that has not been rated yet.
      const closedHref = await ticketHref(page, 'Monitor at the reception desk flickers');
      await page.setViewportSize({ width: 1440, height: 1000 });
      await open(page, '/client/' + closedHref, 'closed ticket', ['How did we do?', 'Ticket closed.']);
      await callout(page, [{ selector: '.csat-faces', n: 1 }, { selector: 'button[name=add_ticket_feedback]', n: 2 }]);
      await shot(page, 'portal/10-rate-ticket');
      await clearCallouts(page);

      await page.setViewportSize({ width: 1440, height: 900 });
      await open(page, '/client/service_catalog.php', 'request something', ['Request Something', 'Report a lost or stolen device']);
      await callout(page, [
        { selector: '.portal-search', n: 1 },
        { selector: '.portal-chips', n: 2 },
        { selector: '.portal-request:nth-child(3)', n: 3 },
      ]);
      await shot(page, 'portal/11-request-something');
      await clearCallouts(page);

      // Selecting a tile opens the new ticket form pre-filled (nothing is submitted).
      await page.locator('.portal-request', { hasText: 'Password reset or locked account' }).first().click();
      await page.waitForURL('**/ticket_add.php?catalog_item_id=*');
      await settle(page);
      await check(page, 'pre-filled ticket form', ['Raise a new ticket', 'Password reset or locked account']);
      await page.setViewportSize({ width: 1440, height: 760 });
      await callout(page, [
        { selector: '.portal-request-banner', n: 1 },
        { selector: '.portal-request-banner a.btn', n: 2, side: 'tr' },
        { selector: 'input[name=subject]', n: 3, side: 'tr' },
        { selector: 'select[name=priority]', n: 4, side: 'tr' },
        { selector: 'select[name=category]', n: 5, side: 'tr' },
      ]);
      await shot(page, 'portal/12-request-prefilled');
      await clearCallouts(page);

      await page.setViewportSize({ width: 1440, height: 1100 });
      await open(page, '/client/kb_articles.php', 'knowledge base', ['Knowledge Base', 'Connect to the staff Wi-Fi']);
      await callout(page, [{ selector: '.portal-search-group', n: 1 }, { selector: '.badge.text-bg-info', n: 2, side: 'tr' }]);
      await shot(page, 'portal/13-knowledge-base');
      await clearCallouts(page);

      await page.setViewportSize({ width: 1440, height: 1000 });
      await open(page, '/client/profile.php', 'account', ['Your details', 'Training PIN', 'Save password', 'Two-factor authentication']);
      await callout(page, [
        { selector: 'input[name=new_password]', n: 1, side: 'tr' },
        { selector: 'button[name=edit_profile]', n: 2 },
        { selector: 'button[name=enable_portal_mfa]', n: 3 },
      ]);
      await shot(page, 'portal/14-account');

      // Training (read-only; Start training is not pressed, it would create a kiosk session).
      await page.setViewportSize({ width: 1440, height: 640 });
      await open(page, '/client/training.php', 'my training', ['My training', 'Start training', 'Report a training problem', 'Cybersecurity Awareness']);
      await callout(page, [
        { selector: '.col-auto > form[action="training_start.php"] button', n: 1 },
        { selector: 'a[href="ticket_add.php"].btn', n: 2 },
        { selector: '.card.card-outline.card-primary', n: 3 },
      ]);
      await shot(page, 'portal/17-training-mine');
    } finally {
      await browser.close();
    }
  }

  // ---- a department lead: Grace Okafor, Finance Director (primary contact) ---------------------------------------------
  {
    const { browser, page } = await launch({ height: 1430 });
    try {
      await login(page, GRACE);
      await open(page, '/client/index.php', 'lead home', ['Needs attention', 'needing attention', 'Assigned asset']);
      await callout(page, [
        { selector: '#navbarDropdown2', n: 1, side: 'bl' },
        { selector: 'a.portal-stat--amber', n: 2 },
        { selector: '#tech-alerts', n: 3 },
      ]);
      await shot(page, 'portal/15-lead-home');
      await clearCallouts(page);

      await page.setViewportSize({ width: 1440, height: 620 });
      await open(page, '/client/contacts.php', 'contacts', ['Contacts', 'Tom Kessler']);
      await callout(page, [{ selector: 'a[href="contact_add.php"]', n: 1 }, { selector: 'table', n: 2 }]);
      await shot(page, 'portal/16-contacts');

      // Training: the lead's own page, then the department summary.
      await page.setViewportSize({ width: 1440, height: 760 });
      await open(page, '/client/training_manage.php', 'manage training', ['Manage training', 'People in department', 'By person', 'People by course']);
      await page.setViewportSize({ width: 1440, height: 1100 });
      await callout(page, [
        { selector: 'a[href="training.php"]', n: 1, side: 'tr' },
        { selector: 'a[href*="export=csv"]', n: 2, side: 'tr' },
        { selector: '.row.mb-3', n: 3 },
        { selector: '.card-outline.card-warning', n: 4 },
        { selector: '.card-outline.card-primary', n: 5 },
        { selector: '.card-outline.card-secondary', n: 6 },
      ]);
      await shot(page, 'portal/18-training-team');
      await clearCallouts(page);
    } finally {
      await browser.close();
    }
  }

  // ---- links people receive by e-mail: no sign-in ---------------------------------------------------------------------
  {
    const { browser, page } = await launch({ height: 1000 });
    try {
      if (!ticketId) throw new Error('ticket id was not captured');
      await open(page, `/guest/guest_view_ticket.php?ticket_id=${ticketId}&url_key=${GUEST_TICKET_KEY}`, 'guest ticket', ['HR office printer prints blank pages', 'log in']);
      await callout(page, [{ selector: '.card-header.bg-dark', n: 1 }, { selector: '.tkt-pill-badge', n: 2 }, { selector: 'h6:has(i)', n: 3 }]);
      await shot(page, 'portal/19-guest-ticket');
      await clearCallouts(page);

      // The share link's numeric id is not known in advance: try ids until the fixed key matches.
      let found = false;
      for (let id = 1; id <= 300 && !found; id++) {
        await page.goto(`${BASE}/guest/guest_view_item.php?id=${id}&key=${SHARE_DOC_KEY}`, { waitUntil: 'domcontentloaded' });
        found = (await page.evaluate(() => document.body.innerText)).includes('Secure link intended for');
      }
      if (!found) throw new Error('shared document link not found (is seed/70-portal.php applied?)');
      await settle(page);
      await check(page, 'shared document', ['Finance systems quick reference', 'grace.okafor@summitridge.example']);
      await callout(page, [{ selector: '.card-header.bg-dark', n: 1 }, { selector: '.card-body .prettyContent', n: 2 }]);
      await shot(page, 'portal/20-shared-document');
    } finally {
      await browser.close();
    }
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
