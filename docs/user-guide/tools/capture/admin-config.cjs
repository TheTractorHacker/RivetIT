// Screenshots for the Administration configuration pages of the user guide
// (docs/user-guide/13-administration-settings.md and 13b-administration-ticketing-and-automation.md).
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/admin-config.cjs
//   ... admin-config.cjs --only=webhook      (run just the shots whose name contains "webhook")
//
// READ-ONLY: it only opens pages and pop-ups, and types illustrative values into a few fields that are
// never submitted. Needs seed/85-admin-config.(sql|php) applied first. Exits non-zero on a PHP error or
// an unexpectedly empty list.

const path = require('path');
const fs = require('fs');
const { launch, login, goto, shot, callout, clearCallouts, settle, OUT } = require('../lib.cjs');

const ONLY = (process.argv.find((a) => a.startsWith('--only=')) || '').slice(7);
const G = 'admin-config';
const problems = [];

// ---- helpers ---------------------------------------------------------------------------------------
const VIEW = { width: 1440, height: 900 };

// Element screenshot with a margin, so call-out badges that hang over the element's edge are not cut off.
// Modal pop-ups are fixed-position, so those are shot from a tall viewport (no page scroll) instead of fullPage.
async function shotEl(page, rel, selector, { pad = null, modal = false } = {}) {
  if (pad === null) pad = modal ? 0 : 12;
  await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
  await page.mouse.move(2, 2);
  await settle(page, 400);
  const loc = page.locator(selector).first();
  await loc.scrollIntoViewIfNeeded();
  const b = await loc.boundingBox();
  const m = await page.evaluate(() => ({
    sx: window.scrollX, sy: window.scrollY,
    w: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight,
  }));
  const bx = b.x + m.sx, by = b.y + m.sy;
  const x0 = Math.max(0, bx - pad), y0 = Math.max(0, by - pad);
  const x1 = Math.min(m.w, bx + b.width + pad), y1 = Math.min(m.h, by + b.height + pad);
  const file = path.join(OUT, rel.endsWith('.png') ? rel : `${rel}.png`);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.screenshot({
    path: file,
    fullPage: !modal,
    clip: { x: x0, y: y0, width: x1 - x0, height: y1 - y0 },
  });
  console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..', '..'), file));
}

async function phpErrors(page, label) {
  const txt = await page.evaluate(() => document.body.innerText || '');
  if (/Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:/.test(txt)) {
    problems.push(`${label}: PHP error text on the page`);
  }
  if (/don't have access to this page|Access denied/i.test(txt)) problems.push(`${label}: access denied`);
}

// The list must show at least `min` table body rows.
async function needRows(page, label, min = 1, sel = 'table tbody tr') {
  const n = await page.locator(sel).count();
  if (n < min) problems.push(`${label}: expected at least ${min} rows (${sel}), found ${n}`);
}

async function need(page, label, sel) {
  if (!(await page.locator(sel).count())) problems.push(`${label}: missing ${sel}`);
}

// Fixed-size crop of the viewport (used for the user menu in the page header).
async function shotClip(page, rel, clip) {
  await page.mouse.move(2, 2);
  await page.waitForTimeout(200);
  const file = path.join(OUT, rel.endsWith('.png') ? rel : `${rel}.png`);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.screenshot({ path: file, clip });
  console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..', '..'), file));
}

async function openModal(page, clickSel, height = 1500) {
  await page.setViewportSize({ width: VIEW.width, height });
  await page.waitForTimeout(300);
  await page.locator(clickSel).first().click();
  await page.waitForSelector('.modal.show .modal-content', { timeout: 15000 });
  await settle(page, 900);
}

async function closeModal(page) {
  await page.evaluate(() => {
    const m = document.querySelector('.modal.show');
    if (m && window.bootstrap) window.bootstrap.Modal.getInstance(m)?.hide();
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  });
  await page.setViewportSize(VIEW);
  await page.waitForTimeout(300);
}

// Phrase-based element shot for a card by its heading text.
const cardWith = (text) => `.card:has(.card-title:has-text("${text}"))`;

(async () => {
  const { browser, page } = await launch();
  await login(page, 'admin');
  const want = (name) => !ONLY || name.includes(ONLY);

  // 01 - Opening Administration from the user menu (top right of every agent page)
  if (want('open-administration')) {
    await goto(page, '/agent/clients.php');
    await phpErrors(page, 'open-administration');
    await page.locator('li.user-menu > a').click();
    await page.waitForSelector('li.user-menu .dropdown-menu.show');
    await page.waitForTimeout(300);
    await callout(page, [
      { selector: 'li.user-menu > a', n: 1, side: 'bl' },
      { selector: 'li.user-menu a[href="/admin/"]', n: 2, side: 'tl' },
    ]);
    await shotClip(page, `${G}/01-open-administration`, { x: 940, y: 0, width: 500, height: 330 });
    await clearCallouts(page);
    await page.keyboard.press('Escape');
    await page.mouse.click(700, 500).catch(() => {});
  }

  // 02 - Modules (with a tour of the Administration shell)
  if (want('modules')) {
    await goto(page, '/admin/settings_module.php');
    await phpErrors(page, 'modules');
    await need(page, 'modules', '#customSwitch1');
    await callout(page, [
      { selector: 'a.section-nav-back', n: 1, side: 'br' },
      { selector: 'aside a.nav-link[href="/admin/settings.php"]', n: 2, side: 'tr' },
      { selector: '.card-body form', n: 3 },
      { selector: 'button[name=edit_module_settings]', n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/02-modules`);
    await clearCallouts(page);
  }

  // 02 - Company details (the app's placeholder examples are replaced with made-up values; nothing is saved)
  if (want('company')) {
    await goto(page, '/admin/settings_company.php');
    await phpErrors(page, 'company');
    await page.fill('input[name=ms_tenant_id]', '00000000-1111-4222-8333-444444444444');
    await page.fill('input[name=default_email_domain]', 'summitridge.example');
    await page.fill('input[name=security_contact_email]', 'security@summitridge.example');
    await page.fill('input[name=hr_contact_email]', 'hr@summitridge.example');
    await shotEl(page, `${G}/03-company-details`, '.card');
  }

  // 04 - Appearance
  if (want('appearance')) {
    await goto(page, '/admin/settings_appearance.php');
    await phpErrors(page, 'appearance');
    await callout(page, [
      { selector: '.accent-swatch-grid', n: 1 },
      { selector: '#custom_accent_hex', n: 2, side: 'tr' },
      { selector: '#darkModeDefaultSwitch', n: 3 },
    ]);
    await shotEl(page, `${G}/04-appearance`, '.card >> nth=0');
    await clearCallouts(page);
  }

  // 05 - Notification settings (first form only)
  if (want('notifications')) {
    await goto(page, '/admin/settings_notification.php');
    await phpErrors(page, 'notifications');
    // The Invoice and Quote cards belong to billing features this guide does not cover; leave them out of the picture.
    await page.evaluate(() => {
      document.querySelectorAll('.notif-section').forEach((el) => {
        if (/Invoice Notifications|Quote Notifications/.test(el.textContent)) el.style.display = 'none';
      });
    });
    await callout(page, [
      { selector: '#enableCronSwitch', n: 1, side: 'tr' },
      { selector: 'input[name=config_ticket_new_ticket_notification_email]', n: 2, side: 'tr' },
      { selector: '#clientNotifSwitch', n: 3 },
      { selector: '#domainExpireSwitch', n: 4 },
    ]);
    await shotEl(page, `${G}/05-notifications`, 'form:has(#enableCronSwitch)');
    await clearCallouts(page);
  }

  // 06 - Mail settings: choose Standard SMTP and type example values (not saved)
  if (want('mail-settings')) {
    await goto(page, '/admin/settings_mail.php');
    await phpErrors(page, 'mail-settings');
    await page.selectOption('#config_smtp_provider', 'standard_smtp');
    await page.waitForTimeout(400);
    await page.fill('input[name=config_smtp_host]', 'smtp.summitridge.example');
    await page.fill('input[name=config_smtp_port]', '587');
    await page.selectOption('select[name=config_smtp_encryption]', 'tls');
    await page.fill('input[name=config_smtp_username]', 'it-support@summitridge.example');
    await callout(page, [
      { selector: '#config_smtp_provider', n: 1, side: 'tr' },
      { selector: 'input[name=config_smtp_host]', n: 2, side: 'tr' },
      { selector: 'button[name=edit_mail_smtp_settings]', n: 3, side: 'tr' },
    ]);
    await shotEl(page, `${G}/06-mail-smtp`, '.card >> nth=0');
    await clearCallouts(page);
  }

  // 07 - Mailboxes list
  if (want('mailboxes')) {
    await goto(page, '/admin/mailbox.php');
    await phpErrors(page, 'mailboxes');
    await needRows(page, 'mailboxes', 2);
    await callout(page, [
      { selector: 'button[data-modal-url*="mailbox_add"]', n: 1, side: 'tr' },
      { selector: 'table thead th:nth-child(4)', n: 2, side: 'tr' },
      { selector: 'table thead th:nth-child(5)', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/07-mailboxes`);
    await clearCallouts(page);

    // 08 - Add Mailbox pop-up
    await openModal(page, 'button[data-modal-url*="mailbox_add"]');
    await page.fill('.modal.show input[name=mailbox_name]', 'Finance Requests');
    await page.fill('.modal.show input[name=mailbox_email]', 'finance-it@summitridge.example');
    await callout(page, [
      { selector: '.modal.show select[name=mailbox_type]', n: 1, side: 'tr' },
      { selector: '.modal.show input[name=mailbox_parse_unknown_senders]', n: 2 },
      { selector: '.modal.show select[name=mailbox_default_client_id] + .ts-wrapper', n: 3, side: 'tr' },
    ]);
    await shotEl(page, `${G}/08-mailbox-add`, '.modal.show .modal-content', { modal: true });
    await clearCallouts(page);
    await closeModal(page);
  }

  // 09 - Mail queue
  if (want('mail-queue')) {
    await goto(page, '/admin/mail_queue.php');
    await phpErrors(page, 'mail-queue');
    await needRows(page, 'mail-queue', 4);
    await callout(page, [
      { selector: 'table thead th:nth-child(7)', n: 1, side: 'tr' },
      { selector: 'table thead th:nth-child(8)', n: 2, side: 'tr' },
    ]);
    await shot(page, `${G}/09-mail-queue`);
    await clearCallouts(page);
  }

  // 10 - Requests (unknown senders)
  if (want('mail-requests')) {
    await goto(page, '/admin/mail_requests.php');
    await phpErrors(page, 'mail-requests');
    await needRows(page, 'mail-requests', 2);
    await callout(page, [
      { selector: 'table tbody tr:first-child a[data-modal-url*="mail_request_view"]', n: 1 },
      { selector: 'table tbody tr:first-child a[data-modal-url*="mail_request_convert"]', n: 2 },
      { selector: 'table tbody tr:first-child a[href*="dismiss_mail_request"]', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/10-mail-requests`);
    await clearCallouts(page);
  }

  // 11 - Integrations overview
  if (want('integrations')) {
    await goto(page, '/admin/settings_integrations.php');
    await phpErrors(page, 'integrations');
    await need(page, 'integrations', 'a[data-tabkey="odoo"]');
    await callout(page, [
      { selector: 'a[data-tabkey="rmm"]', n: 1 },
      { selector: 'a[data-tabkey="backups"]', n: 2 },
      { selector: 'a[data-tabkey="firewalls"]', n: 3 },
      { selector: 'a[data-tabkey="unifi"]', n: 4 },
      { selector: 'a[data-tabkey="directorysync"]', n: 5 },
      { selector: 'a[data-tabkey="odoo"]', n: 6 },
      { selector: 'a[data-tabkey="devicesync"]', n: 7 },
    ]);
    await shot(page, `${G}/11-integrations`);
    await clearCallouts(page);
  }

  // 12 - Webhooks (list + Add Webhook pop-up)
  if (want('webhook')) {
    await goto(page, '/admin/settings_webhooks.php');
    await phpErrors(page, 'webhooks');
    await needRows(page, 'webhooks', 2, '.card:first-of-type table tbody tr');
    await callout(page, [
      { selector: 'button[data-modal-url*="webhook_add"]', n: 1, side: 'tr' },
      { selector: 'table tbody tr:first-child td:nth-child(3)', n: 2 },
      { selector: 'table tbody tr:first-child td:nth-child(5)', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/12-webhooks`);
    await clearCallouts(page);

    await openModal(page, 'button[data-modal-url*="webhook_add"]');
    await page.fill('.modal.show input[name=webhook_name]', 'Ops Chat Notifier');
    await page.fill('.modal.show input[name=webhook_url]', 'https://hooks.summitridge.example/rivetit/tickets');
    await page.check('.modal.show input[value="ticket.created"]');
    await page.check('.modal.show input[value="ticket.resolved"]');
    await shotEl(page, `${G}/13-webhook-add`, '.modal.show .modal-content', { modal: true });
    await closeModal(page);
  }

  // 14 - AI settings and providers (one page: Settings -> AI, also reached from Tags & Categories -> AI settings)
  if (want('ai-providers')) {
    await goto(page, '/admin/settings_ai.php');
    await phpErrors(page, 'ai-providers');
    await needRows(page, 'ai-providers', 1);
    await callout(page, [
      { selector: 'button[data-modal-url*="ai_provider_add"]', n: 1, side: 'tr' },
      { selector: 'table thead th:nth-child(3)', n: 2, side: 'tr' },
      { selector: 'table thead th:nth-child(4)', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/14-ai-providers`);
    await clearCallouts(page);
  }

  // 15 - Telemetry (the installation id also signs calendar-feed links, so it is blanked in the picture)
  if (want('telemetry')) {
    await goto(page, '/admin/settings_telemetry.php');
    await phpErrors(page, 'telemetry');
    await page.evaluate(() => {
      const s = document.querySelector('.card-body p strong');
      if (s) s.textContent = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    });
    await shotEl(page, `${G}/15-telemetry`, '.card >> nth=0');
  }

  // Backup page
  if (want('backup')) {
    await goto(page, '/admin/backup.php');
    await phpErrors(page, 'backup');
    await need(page, 'backup', 'a[href*="backup_serve"]');
    await callout(page, [
      { selector: 'a[href*="backup_download_fresh"]', n: 1 },
      { selector: 'a[href*="backup_save"]', n: 2, side: 'tr' },
      { selector: '#backup_auto', n: 3 },
      { selector: 'button[name=backup_master_key]', n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/16-backup`, { fullPage: true });
    await clearCallouts(page);
  }

  // ---------------------------------------------------------------- 13b: ticketing and organising data
  // 18 - Ticket settings
  if (want('ticket-settings')) {
    await goto(page, '/admin/settings_ticket.php');
    await phpErrors(page, 'ticket-settings');
    await callout(page, [
      { selector: 'input[name=config_ticket_prefix]', n: 1, side: 'tr' },
      { selector: '#emailToTicketParseSwitch', n: 2 },
      { selector: 'input[name=config_ticket_autoclose_hours]', n: 3, side: 'tr' },
      { selector: 'select[name=config_ticket_default_technician_id] + .ts-wrapper', n: 4, side: 'tr' },
      { selector: '#csatEnableSwitch', n: 5 },
    ]);
    await shotEl(page, `${G}/17-ticket-settings`, '.card >> nth=0');
    await clearCallouts(page);
  }

  // 19 - Ticket statuses
  if (want('ticket-statuses')) {
    await goto(page, '/admin/ticket_status.php');
    await phpErrors(page, 'ticket-statuses');
    await needRows(page, 'ticket-statuses', 6);
    await callout(page, [
      { selector: 'button[data-modal-url*="ticket_status_add"]', n: 1, side: 'tr' },
      { selector: 'table tbody tr:last-child td:nth-child(2) span', n: 2, side: 'tr' },
      { selector: 'table tbody tr:last-child button', n: 3, side: 'tl' },
    ]);
    await shotEl(page, `${G}/18-ticket-statuses`, '.card >> nth=0');
    await clearCallouts(page);
  }

  // 20 / 21 - SLA business-hours calendars and the edit pop-up
  if (want('sla-calendars')) {
    await goto(page, '/admin/sla_calendars.php');
    await phpErrors(page, 'sla-calendars');
    await needRows(page, 'sla-calendars', 2);
    await callout(page, [
      { selector: 'button[data-modal-url*="calendar_add"]', n: 1, side: 'tr' },
      { selector: 'table tbody tr:first-child td:nth-child(2)', n: 2 },
      { selector: 'table tbody tr:first-child td:nth-child(4)', n: 3, side: 'tr' },
    ]);
    await shotEl(page, `${G}/19-sla-calendars`, '.card >> nth=0');
    await clearCallouts(page);

    await openModal(page, 'table tbody tr a.ajax-modal:has-text("Standard Support")', 2300);
    await shotEl(page, `${G}/20-sla-calendar-edit`, '.modal.show .modal-content', { modal: true });
    await closeModal(page);
  }

  // 22 / 23 - SLA policies and the edit pop-up
  if (want('sla-policies')) {
    await goto(page, '/admin/sla_policies.php');
    await phpErrors(page, 'sla-policies');
    await needRows(page, 'sla-policies', 2);
    await callout(page, [
      { selector: 'table thead th:nth-child(2)', n: 1, side: 'tr' },
      { selector: 'table thead th:nth-child(3)', n: 2, side: 'tr' },
      { selector: 'table thead th:nth-child(4)', n: 3, side: 'tr' },
    ]);
    await shotEl(page, `${G}/21-sla-policies`, '.card >> nth=0');
    await clearCallouts(page);

    await openModal(page, 'table tbody tr a.ajax-modal:has-text("Standard Support")');
    await shotEl(page, `${G}/22-sla-policy-edit`, '.modal.show .modal-content', { modal: true });
    await closeModal(page);
  }

  // 24 / 25 - Ticket templates: list and one template's page
  if (want('ticket-templates')) {
    await goto(page, '/admin/ticket_template.php');
    await phpErrors(page, 'ticket-templates');
    await needRows(page, 'ticket-templates', 3);
    await callout(page, [
      { selector: 'button[data-modal-url*="ticket_template_add"]', n: 1, side: 'tr' },
      { selector: 'table thead th:nth-child(2)', n: 2, side: 'tr' },
    ]);
    await shotEl(page, `${G}/23-ticket-templates`, '.card >> nth=0');
    await clearCallouts(page);

    const href = await page.locator('table tbody tr a[href*="ticket_template_details"]:has-text("New Hire IT Setup")').first().getAttribute('href');
    await goto(page, `/admin/${href}`);
    await phpErrors(page, 'ticket-template-details');
    await need(page, 'ticket-template-details', '#tasks');
    await callout(page, [
      { selector: 'button[data-bs-target="#editTicketTemplateModal"]', n: 1, side: 'tr' },
      { selector: 'input[name=task_name]', n: 2, side: 'tr' },
      { selector: '#tasks .drag-handle', n: 3 },
    ]);
    await shot(page, `${G}/24-ticket-template-details`);
    await clearCallouts(page);
  }

  // 26 - Canned responses
  if (want('canned')) {
    await goto(page, '/admin/canned_responses.php');
    await phpErrors(page, 'canned');
    await needRows(page, 'canned', 4);
    await callout(page, [
      { selector: 'button[data-modal-url*="canned_response_add"]', n: 1, side: 'tr' },
      { selector: 'table tbody tr:first-child td:nth-child(1) a', n: 2 },
    ]);
    await shotEl(page, `${G}/25-canned-responses`, '.card >> nth=0');
    await clearCallouts(page);
  }

  // 27 - Service catalog
  if (want('catalog')) {
    await goto(page, '/admin/service_catalog.php');
    await phpErrors(page, 'catalog');
    await needRows(page, 'catalog', 4);
    await callout(page, [
      { selector: 'button[data-modal-url*="service_catalog_item_add"]', n: 1, side: 'tr' },
      { selector: 'table thead th:nth-child(1)', n: 2, side: 'tr' },
      { selector: 'table thead th:nth-child(6)', n: 3, side: 'tr' },
    ]);
    await shotEl(page, `${G}/26-service-catalog`, '.card >> nth=0');
    await clearCallouts(page);
  }

  // 28 / 29 - Ticket automation: rule list and New Rule pop-up
  if (want('automation')) {
    await goto(page, '/admin/ticket_automation.php');
    await phpErrors(page, 'automation');
    await needRows(page, 'automation', 3);
    await callout(page, [
      { selector: 'a[href="ticket_automation_log.php"]', n: 1, side: 'tr' },
      { selector: 'button[data-modal-url*="add_rule"]', n: 2, side: 'tr' },
      { selector: 'table thead th:nth-child(3)', n: 3, side: 'tr' },
      { selector: 'table thead th:nth-child(4)', n: 4, side: 'tr' },
      { selector: 'table thead th:nth-child(5)', n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/27-ticket-automation`);
    await clearCallouts(page);

    await openModal(page, 'button[data-modal-url*="add_rule"]');
    await page.fill('.modal.show input[name=rule_name]', 'Example: raise priority on stale tickets');
    await page.selectOption('.modal.show #ruleTrigger', 'schedule');
    await callout(page, [
      { selector: '.modal.show #ruleTrigger', n: 1, side: 'tr' },
      { selector: '#conditionsWrap', n: 2 },
      { selector: '#actionsWrap', n: 3 },
    ]);
    await shotEl(page, `${G}/28-automation-new-rule`, '.modal.show .modal-content', { modal: true });
    await clearCallouts(page);
    await closeModal(page);
  }

  // 30 - Ticket categories (Ticket tab)
  if (want('categories')) {
    // The tab strip is wider than its column at 1440px and runs over the search box, so this one uses a wider window.
    await page.setViewportSize({ width: 1920, height: 1000 });
    await goto(page, '/admin/category.php?category=Ticket');
    await phpErrors(page, 'categories');
    await needRows(page, 'categories', 6);
    await callout(page, [
      { selector: 'a[href="?category=Ticket"]', n: 1 },
      { selector: 'button[data-modal-url*="category_add"]', n: 2, side: 'tr' },
      { selector: 'table tbody tr.table-secondary:first-child td:first-child', n: 3 },
    ]);
    await shotEl(page, `${G}/29-ticket-categories`, '.card >> nth=0');
    await clearCallouts(page);
    await page.setViewportSize(VIEW);
  }

  // 31 - Tags (Ticket tab)
  if (want('tags')) {
    await goto(page, '/admin/tag.php?type=6');
    await phpErrors(page, 'tags');
    await needRows(page, 'tags', 3);
    await callout(page, [
      { selector: 'a[href="?type=6"]', n: 1 },
      { selector: 'button[data-modal-url*="tag_add"]', n: 2, side: 'tr' },
    ]);
    await shotEl(page, `${G}/30-ticket-tags`, '.card >> nth=0');
    await clearCallouts(page);
  }

  // 32 - Custom links
  if (want('custom-links')) {
    await goto(page, '/admin/custom_link.php');
    await phpErrors(page, 'custom-links');
    await needRows(page, 'custom-links', 3);
    await callout(page, [
      { selector: 'button[data-modal-url*="custom_link_add"]', n: 1, side: 'tr' },
      { selector: 'table thead th:nth-child(3)', n: 2, side: 'tr' },
      { selector: 'table thead th:nth-child(4)', n: 3, side: 'tr' },
    ]);
    await shotEl(page, `${G}/31-custom-links`, '.card >> nth=0');
    await clearCallouts(page);
  }

  // 33 - Holidays catalog
  if (want('holidays')) {
    await goto(page, '/admin/holidays.php');
    await phpErrors(page, 'holidays');
    await needRows(page, 'holidays', 10);
    await callout(page, [
      { selector: 'button[data-modal-url*="holiday_load"]', n: 1 },
      { selector: 'button.dropdown-toggle-split', n: 2, side: 'tr' },
      { selector: 'table thead th:nth-child(5)', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/32-holidays`);
    await clearCallouts(page);
  }

  // ---------------------------------------------------------------- shots added after the admin menu rework
  // 33 - Settings directory (Administration -> Settings)
  if (want('settings-hub')) {
    await goto(page, '/admin/settings.php');
    await phpErrors(page, 'settings-hub');
    await need(page, 'settings-hub', '.admin-directory__tile');
    await callout(page, [
      { selector: 'aside a.nav-link[href="/admin/settings.php"]', n: 1, side: 'tr' },
      { selector: '.admin-directory__nav', n: 2 },
      { selector: '#general .admin-directory__grid', n: 3 },
    ]);
    await shot(page, `${G}/33-settings-hub`);
    await clearCallouts(page);
  }

  // 34 - Maintenance directory
  if (want('maintenance-hub')) {
    await goto(page, '/admin/maintenance.php');
    await phpErrors(page, 'maintenance-hub');
    await need(page, 'maintenance-hub', '.admin-directory__tile');
    await callout(page, [
      { selector: 'a.admin-directory__item[href="/admin/cron.php"]', n: 1 },
      { selector: 'a.admin-directory__item[href="/admin/backup.php"]', n: 2 },
      { selector: 'a.admin-directory__item[href="/admin/update.php"]', n: 3 },
    ]);
    await shot(page, `${G}/34-maintenance-hub`);
    await clearCallouts(page);
  }

  // 35 - Scheduled jobs. The demo server has no /etc/cron.d entry, so the page shows its empty state.
  if (want('scheduled-jobs')) {
    await goto(page, '/admin/cron.php');
    await phpErrors(page, 'scheduled-jobs');
    await need(page, 'scheduled-jobs', 'button:has-text("Run Now")');
    await callout(page, [
      { selector: 'input[name=run_cron_now] + button', n: 1, side: 'tr' },
      { selector: '.card-body .alert-info', n: 2 },
      { selector: '.card-body .alert-warning', n: 3 },
      { selector: 'table.table', n: 4 },
    ]);
    await shot(page, `${G}/35-scheduled-jobs`);
    await clearCallouts(page);

    // 36 - The schedule builder. The dialog only exists on a server where the Cron Manager helper is installed,
    // which the demo is not, so this copies the dialog markup from admin/cron.php into the page, loads the app's own
    // js/cron_schedule_builder.js against it and opens it for the installer's standard "every five minutes" entry.
    await page.evaluate(async () => {
      const days = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [0, 'Sun']]
        .map(([n, d]) => `<label class="form-check form-check-inline mb-0 me-2"><input class="form-check-input" type="checkbox" name="cron_weekday" value="${n}"><span class="form-check-label">${d}</span></label>`).join('');
      const html = `
<div class="modal fade" id="cronScheduleModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="cronScheduleForm" onsubmit="return false">
<input type="hidden" id="cronScheduleFile"><input type="hidden" id="cronScheduleLine"><input type="hidden" id="cronScheduleHash">
<div class="modal-header"><h2 class="modal-title h5" id="cronScheduleTitle">Edit schedule</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body">
<p class="text-muted mb-3">Job: <code id="cronScheduleJob"></code><br>Current: <code id="cronScheduleCurrent"></code></p>
<div class="mb-3"><label for="cronScheduleType" class="form-label">Repeat</label><select id="cronScheduleType" class="form-select">
<option value="minutes">Every few minutes</option><option value="hourly">Hourly</option><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="custom">Custom cron expression</option></select></div>
<div class="mb-3" data-cron-control="minutes"><label for="cronEveryMinutes" class="form-label">Run every</label><div class="input-group" style="max-width:180px"><input type="number" id="cronEveryMinutes" class="form-control" min="1" max="59" step="1" value="5"><span class="input-group-text">minutes</span></div><div class="form-text">Intervals start again at the top of each hour.</div></div>
<div class="mb-3 d-none" data-cron-control="hourly"><label for="cronMinute" class="form-label">Minute of each hour</label><input type="number" id="cronMinute" class="form-control" min="0" max="59" step="1" value="0" style="max-width:120px"></div>
<div class="mb-3 d-none" data-cron-control="time"><label for="cronTime" class="form-label">Time of day</label><input type="time" id="cronTime" class="form-control" value="09:00" style="max-width:180px"><div class="form-text">Uses the server's cron time zone.</div></div>
<fieldset class="mb-3 d-none" data-cron-control="weekly"><legend class="form-label fs-6 mb-2">Days of the week</legend><div class="d-flex flex-wrap gap-2">${days}</div></fieldset>
<div class="mb-3 d-none" data-cron-control="monthly"><label for="cronMonthDay" class="form-label">Day of the month</label><input type="number" id="cronMonthDay" class="form-control" min="1" max="31" step="1" value="1" style="max-width:120px"><div class="form-text">Days 29–31 are skipped in shorter months.</div></div>
<div class="mb-2"><label for="cronScheduleExpression" class="form-label">Cron expression</label><input type="text" id="cronScheduleExpression" class="form-control font-monospace" maxlength="100" spellcheck="false" readonly><div class="form-text">For custom schedules: minute, hour, day of month, month, day of week. Use <code>*</code> for every value.</div></div>
<div id="cronScheduleSummary" class="text-muted" aria-live="polite"></div><div id="cronScheduleError" class="text-danger d-none" role="alert"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="cronScheduleSave" disabled>Save schedule</button></div>
</form></div></div></div>
<button type="button" id="ugOpenCron" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#cronScheduleModal"
 data-cron-file="rivetit-demo" data-cron-line="3" data-cron-hash="x" data-cron-script="cron.php" data-cron-current="*/5 * * * *" style="position:fixed;left:0;top:0;opacity:.01">open</button>`;
      document.body.insertAdjacentHTML('beforeend', html);
      const orig = document.addEventListener;
      document.addEventListener = (t, f, o) => (t === 'DOMContentLoaded' ? f() : orig.call(document, t, f, o));
      await new Promise((resolve, reject) => {
        const sc = document.createElement('script');
        sc.src = '/js/cron_schedule_builder.js';
        sc.onload = resolve;
        sc.onerror = reject;
        document.head.appendChild(sc);
      });
      document.addEventListener = orig;
    });
    await page.setViewportSize({ width: VIEW.width, height: 1000 });
    await page.evaluate(() => document.getElementById('ugOpenCron').click());
    await page.waitForSelector('.modal.show .modal-content', { timeout: 15000 });
    await settle(page, 900);
    await page.selectOption('.modal.show #cronScheduleType', 'weekly');
    await page.fill('.modal.show #cronTime', '02:30');
    await page.evaluate(() => {
      document.querySelectorAll('.modal.show [name=cron_weekday]').forEach((i) => { i.checked = ['1', '3', '5'].includes(i.value); });
      document.getElementById('cronTime').dispatchEvent(new Event('input', { bubbles: true }));
    });
    await callout(page, [
      { selector: '.modal.show #cronScheduleType', n: 1, side: 'tr' },
      { selector: '.modal.show [data-cron-control="weekly"]', n: 2 },
      { selector: '.modal.show #cronScheduleExpression', n: 3, side: 'tr' },
      { selector: '.modal.show #cronScheduleSave', n: 4, side: 'tr' },
    ]);
    await shotEl(page, `${G}/36-scheduled-jobs-edit`, '.modal.show .modal-content', { modal: true, pad: 6 });
    await clearCallouts(page);
    await closeModal(page);
  }

  // 37 - Update page (the demo has no git remote, so the update check is unavailable and no update is pending)
  if (want('update')) {
    await goto(page, '/admin/update.php');
    await phpErrors(page, 'update');
    await need(page, 'update', '.upd-tiles');
    await callout(page, [
      { selector: '.upd-hero', n: 1 },
      { selector: '.upd-tiles', n: 2 },
    ]);
    await shot(page, `${G}/37-update`);
    await clearCallouts(page);
  }

  // 38 - Integrations -> Odoo tab (no Odoo server in the demo, so the connection form is empty)
  if (want('odoo')) {
    await goto(page, '/admin/settings_integrations.php?tab=odoo');
    await phpErrors(page, 'odoo');
    await need(page, 'odoo', '#tab-odoo.show');
    await callout(page, [
      { selector: 'a[data-tabkey="odoo"]', n: 1 },
      { selector: '#tab-odoo .row.g-3', n: 2 },
      { selector: '#tab-odoo .card:has(button[name=save_odoo_integration])', n: 3 },
      { selector: '#tab-odoo .card:has(button[name=save_odoo_sso])', n: 4 },
      { selector: '#tab-odoo .card:has(button[name=save_field_mapping])', n: 5 },
      { selector: '#tab-odoo .card:has(button[name=training_odoo_link_check])', n: 6 },
      { selector: '#tab-odoo #odoo-sync .card', n: 7 },
    ]);
    await shot(page, `${G}/38-integrations-odoo`, { fullPage: true });
    await clearCallouts(page);
  }

  // 39 - Integrations -> Directory Sync tab (Microsoft 365 / Entra ID and Google Workspace)
  if (want('directory-sync')) {
    await goto(page, '/admin/settings_integrations.php?tab=directorysync');
    await phpErrors(page, 'directory-sync');
    await need(page, 'directory-sync', '#tab-directorysync.show');
    await callout(page, [
      { selector: 'a[data-tabkey="directorysync"]', n: 1 },
      { selector: '#tab-directorysync .card:has(button[name=save_microsoft_integration])', n: 2 },
      { selector: '#tab-directorysync .card:has(h3 .fa-google)', n: 3 },
    ]);
    await shot(page, `${G}/39-integrations-directory-sync`, { fullPage: true });
    await clearCallouts(page);
  }

  // 40 - New rule with the "Requester returns from vacation" trigger
  if (want('automation-vacation')) {
    await goto(page, '/admin/ticket_automation.php');
    await openModal(page, 'button[data-modal-url*="add_rule"]');
    await page.fill('.modal.show input[name=rule_name]', 'Example: reopen after vacation');
    await page.selectOption('.modal.show #ruleTrigger', 'vacation_return');
    await page.waitForTimeout(300);
    await page.selectOption('.modal.show #ruleAction0', 'reopen_ticket');
    await callout(page, [
      { selector: '.modal.show #ruleTrigger', n: 1, side: 'tr' },
      { selector: '.modal.show #ruleAction0', n: 2, side: 'tr' },
    ]);
    await shotEl(page, `${G}/40-automation-vacation-rule`, '.modal.show .modal-content', { modal: true });
    await clearCallouts(page);
    await closeModal(page);
  }

  await browser.close();
  if (problems.length) {
    console.error('\nPROBLEMS:\n - ' + problems.join('\n - '));
    process.exit(1);
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
