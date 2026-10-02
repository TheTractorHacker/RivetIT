// Screenshots for docs/user-guide/12-administration-users-and-security.md
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/admin-accounts.cjs
//
// READ-ONLY by design: this script opens pages, pop-ups and menus and types illustrative values into
// forms, but it never submits anything. A guard aborts (and fails the run) if any request to a
// post.php handler is attempted. Needs the demo data from seed/80-admin-accounts.sql and .php.
// Output: docs/user-guide/images/admin-accounts/NN-slug.png

const { launch, login, goto, shot, callout, clearCallouts, settle } = require('../lib.cjs');

const G = 'admin-accounts';
const VIEW_W = 1440;
const VIEW_H = 900;

// ---------------------------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------------------------
const PHP_ERROR = /(<b>(Warning|Notice|Fatal error|Parse error|Deprecated)<\/b>:|Fatal error:|Uncaught [A-Za-z\\]+(Exception|Error)|Stack trace:)/;

async function check(page, label, { min = 0, selector = null, text = null } = {}) {
  const html = await page.content();
  if (PHP_ERROR.test(html)) throw new Error(`${label}: the page shows a PHP error`);
  if (text && !html.includes(text)) throw new Error(`${label}: expected text not found: ${text}`);
  if (selector) {
    const n = await page.locator(selector).count();
    if (n < min) throw new Error(`${label}: expected at least ${min} of "${selector}", found ${n} (empty state? re-apply the seed)`);
  }
}

// Mark the table row that contains `text` so callouts can address it with plain CSS.
async function tagRow(page, text, attr) {
  const ok = await page.evaluate(({ text, attr }) => {
    const row = [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent.includes(text));
    if (row) row.setAttribute(attr, '1');
    return !!row;
  }, { text, attr });
  if (!ok) throw new Error(`table row containing "${text}" not found`);
}

async function openModal(page, clickTarget) {
  await clickTarget.click();
  await page.waitForSelector('.modal.show .modal-content', { timeout: 15000 });
  await page.waitForSelector('.modal.show .modal-body, .modal.show .modal-footer', { timeout: 15000 });
  await page.waitForTimeout(900);
}

async function closeModal(page) {
  await page.keyboard.press('Escape');
  await page.waitForSelector('.modal.show', { state: 'detached', timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(500);
}

async function resize(page, height) {
  await page.setViewportSize({ width: VIEW_W, height });
  await page.waitForTimeout(250);
}

(async () => {
  const { browser, page } = await launch({ width: VIEW_W, height: VIEW_H });
  const blocked = [];
  try {
    await login(page, 'admin');

    // Safety net: nothing on these pages may be saved. Any write to a post.php handler is refused and fails the run.
    await page.route('**/*', (route) => {
      const req = route.request();
      if (!['GET', 'HEAD', 'OPTIONS'].includes(req.method()) && /post\.php/.test(req.url())) {
        blocked.push(`${req.method()} ${req.url()}`);
        return route.abort();
      }
      return route.continue();
    });

    // -------------------------------------------------------------------------------------
    // 01  Reaching Administration: the name menu, top right
    // -------------------------------------------------------------------------------------
    await goto(page, '/agent/dashboard.php');
    await check(page, 'dashboard');
    await page.click('li.user-menu > a.nav-link');
    await page.waitForSelector('li.user-menu .dropdown-menu.show');
    await page.waitForTimeout(400);
    await callout(page, [
      { selector: 'li.user-menu .dropdown-menu a[href="/admin/"]', n: 1, side: 'tl' },
      { selector: 'li.user-menu > a.nav-link', n: 2, side: 'bl' },
    ]);
    await shot(page, `${G}/01-administration-menu`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 02  The Administration layout: its own sidebar, a different one from the agent menu
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/users.php');
    await check(page, 'users (layout)', { selector: 'tbody tr', min: 3 });
    await callout(page, [
      { selector: 'a.section-nav-back', n: 1, side: 'br' },
      { selector: 'a.nav-link[href="/admin/users.php"]', n: 2, side: 'tr' },
      { selector: 'a.nav-link.dropdown-toggle[href="#nav-group-maintenance"]', n: 3, side: 'tr' },
      { selector: 'a.nav-link.dropdown-toggle[href="#nav-group-settings"]', n: 4, side: 'tr' },
      { selector: 'li.user-menu > a.nav-link', n: 5, side: 'bl' },
    ]);
    await shot(page, `${G}/02-administration-layout`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 03  Users list
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/users.php');
    await check(page, 'users', { selector: 'tbody tr', min: 3, text: 'Priya Nair' });
    await tagRow(page, 'Marcus Lee', 'data-ug-row');
    await callout(page, [
      { selector: '.card-tools .btn-group', n: 1, side: 'tl' },
      { selector: 'form.mb-4 .input-group', n: 2, side: 'tl' },
      { selector: 'a[href*="archived="]', n: 3, side: 'tl' },
      { selector: 'thead th:nth-child(5)', n: 4, side: 'tl' },
      { selector: 'tr[data-ug-row] .btn-group, tr[data-ug-row] td:last-child .d-flex', n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/03-users-list`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 04  New User pop-up (Details tab), filled in but never submitted
    // -------------------------------------------------------------------------------------
    await resize(page, 1120);
    await openModal(page, page.locator('button.ajax-modal:has-text("New User")'));
    await page.fill('.modal.show input[name="name"]', 'Jordan Ellis');
    await page.fill('.modal.show input[name="email"]', 'jordan.ellis@summitridge.example');
    await page.evaluate(() => {
      const sel = document.querySelector('.modal.show select[name="role"]');
      const opt = [...sel.options].find((o) => o.textContent.trim() === 'Help Desk Lead');
      if (!opt) throw new Error('role "Help Desk Lead" not found (apply the seed)');
      if (sel.tomselect) sel.tomselect.setValue(opt.value); else sel.value = opt.value;
    });
    await page.fill('.modal.show input[name="password"]', 'Example-Only-1234');
    await page.check('.modal.show input[name="force_mfa"]');
    await page.waitForTimeout(500);
    await callout(page, [
      { selector: '.modal.show select[name="role"] + .ts-wrapper', n: 1, side: 'tr' },
      { selector: '.modal.show input[name="password"]', n: 2, side: 'tr' },
      { selector: '.modal.show input[name="force_mfa"]', n: 3, side: 'tl' },
      { selector: '.modal.show a.nav-link[href="#pills-user-access"]', n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/04-new-user-details`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
    await resize(page, VIEW_H);

    // -------------------------------------------------------------------------------------
    // 05  Edit User pop-up, Access tab: department ticks and what they mean for the role
    // -------------------------------------------------------------------------------------
    await resize(page, 1000);
    await goto(page, '/admin/users.php');
    await openModal(page, page.locator('tr:has-text("marcus.lee") a.ajax-modal:has-text("Edit")'));
    await page.click('.modal.show a.nav-link:has-text("Access")');
    await page.waitForTimeout(600);
    for (const dept of ['Production', 'Warehouse & Logistics']) {
      await page.locator('.modal.show .list-group-item', { hasText: dept }).locator('input.client-checkbox').check();
    }
    await page.waitForTimeout(500);
    await callout(page, [
      { selector: '.modal.show .js-user-access-help', n: 1, side: 'tr' },
      { selector: '.modal.show .js-toggle-all-clients', n: 2, side: 'tl' },
      { selector: '.modal.show .border.rounded .list-group', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/05-edit-user-access`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
    await resize(page, VIEW_H);

    // -------------------------------------------------------------------------------------
    // 06  Archive User pop-up: reassign open tickets
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/users.php');
    await page.locator('tr:has-text("priya.nair") .dropdown button').click();
    await page.waitForTimeout(400);
    await openModal(page, page.locator('tr:has-text("priya.nair") a.dropdown-item:has-text("Archive")'));
    await shot(page, `${G}/06-archive-user`, { selector: '.modal.show .modal-content' });
    await closeModal(page);

    // -------------------------------------------------------------------------------------
    // 07  Roles list
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/roles.php');
    await check(page, 'roles', { selector: 'tbody tr', min: 4, text: 'Help Desk Lead' });
    await tagRow(page, 'Help Desk Lead', 'data-ug-hdl');
    await tagRow(page, 'Technician', 'data-ug-tech');
    await tagRow(page, 'Administrator', 'data-ug-admin');
    await callout(page, [
      { selector: 'button.ajax-modal[data-modal-url*="role_add"]', n: 1, side: 'tl' },
      { selector: 'tr[data-ug-hdl] small.text-muted', n: 2, side: 'tr' },
      { selector: 'tr[data-ug-tech] td:nth-child(2)', n: 3, side: 'tl' },
      { selector: 'tr[data-ug-admin] td:last-child i.fa-lock', n: 4, side: 'tr' },
      { selector: 'tr[data-ug-hdl] td:last-child button', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/07-roles-list`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 08  Role editor, Permissions tab (the two billing rows are left out of the picture)
    // -------------------------------------------------------------------------------------
    await resize(page, 1750);
    await page.locator('tr[data-ug-hdl] td:last-child button').click();
    await page.waitForTimeout(400);
    await openModal(page, page.locator('tr[data-ug-hdl] a.dropdown-item:has-text("Edit")'));
    await page.click('.modal.show a.nav-link:has-text("Permissions")');
    await page.waitForTimeout(700);
    await page.evaluate(() => {
      document.querySelectorAll('.modal.show .js-role-perm').forEach((row) => {
        const m = row.getAttribute('data-module');
        if (m === 'module_sales' || m === 'module_financial') row.style.display = 'none';
      });
    });
    await page.waitForTimeout(300);
    await callout(page, [
      { selector: '.modal.show .role-preset-box', n: 1, side: 'tr' },
      { selector: '.modal.show .js-role-perm[data-module="module_support"] .btn-group', n: 2, side: 'tr' },
      { selector: '.modal.show .js-role-perm[data-module="module_support"] .js-role-perm-help', n: 3, side: 'br' },
      { selector: '.modal.show .role-perm-more', n: 4, side: 'tr' },
      { selector: '.modal.show .role-preview', n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/08-role-permissions`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
    await resize(page, VIEW_H);

    // -------------------------------------------------------------------------------------
    // 09  Settings > Security (whole page)
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/settings_security.php');
    await check(page, 'security settings', { selector: 'input[name="config_log_retention"]', min: 1 });
    await callout(page, [
      { selector: 'button[name="establish_canonical_vault_key"]', n: 1, side: 'tr' },
      { selector: 'textarea[name="config_login_message"]', n: 2, side: 'tr' },
      { selector: 'input[name="config_login_key_required"]', n: 3, side: 'tl' },
      { selector: 'input[name="config_login_session_lifetime"]', n: 4, side: 'tr' },
      { selector: 'input[name="config_log_retention"]', n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/09-security-settings`, { fullPage: true });
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 10  Settings > Identity Provider
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/identity_provider.php');
    await check(page, 'identity provider', { selector: 'input[name="azure_client_id"]', min: 1 });
    await callout(page, [
      { selector: 'form .form-group .input-group', n: 1, side: 'tr' },
      { selector: 'input[name="azure_client_id"]', n: 2, side: 'tr' },
      { selector: 'input[name="azure_client_secret"]', n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/10-identity-provider`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 11  API Keys list
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/api_keys.php');
    await check(page, 'api keys', { selector: 'tbody tr', min: 3, text: 'Plant floor status board' });
    await tagRow(page, 'Old scanner integration', 'data-ug-old');
    await callout(page, [
      { selector: 'button.ajax-modal[data-modal-url*="api_key_add"]', n: 1, side: 'tl' },
      { selector: 'thead th:nth-child(3)', n: 2, side: 'tl' },
      { selector: 'thead th:nth-child(5)', n: 3, side: 'tl' },
      { selector: 'tr[data-ug-old] td:nth-child(7)', n: 4, side: 'tl' },
      { selector: 'tr[data-ug-old] td:last-child button', n: 5, side: 'tl' },
    ]);
    await shot(page, `${G}/11-api-keys`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 12  New API Key pop-up (Details tab); the generated key on the Keys tab is not shown
    // -------------------------------------------------------------------------------------
    await resize(page, 1000);
    await openModal(page, page.locator('button.ajax-modal:has-text("New API Key")'));
    const nextYear = new Date(Date.now() + 365 * 86400000).toISOString().slice(0, 10);
    await page.fill('.modal.show input[name="name"]', 'Warehouse dashboard feed');
    await page.selectOption('#apiKeyExpirationPreset', 'custom');
    await page.fill('.modal.show input[name="expire"]', nextYear);
    await page.selectOption('.modal.show select[name="permission"]', 'read');
    await page.locator('.modal.show .modal-title').click();   // take focus off the date field
    await page.waitForTimeout(500);
    await callout(page, [
      { selector: '.modal.show input[name="expire"]', n: 1, side: 'tr' },
      { selector: '.modal.show select[name="client"] + .ts-wrapper', n: 2, side: 'tr' },
      { selector: '.modal.show select[name="permission"]', n: 3, side: 'tr' },
      { selector: '.modal.show a.nav-link[href="#pills-api-keys"]', n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/12-new-api-key`, { selector: '.modal.show .modal-content' });
    await clearCallouts(page);
    await closeModal(page);
    await resize(page, VIEW_H);

    // -------------------------------------------------------------------------------------
    // 13  API Docs: the public Redoc reference, filtered with its search box
    // -------------------------------------------------------------------------------------
    await goto(page, '/api/v1/docs');
    await page.locator('input[aria-label="Search"]').waitFor();
    await page.fill('input[aria-label="Search"]', 'asset');
    await page.waitForTimeout(600);
    await shot(page, `${G}/13-api-docs`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 14  Audit Logs: searching for "API", date-range panel open
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/audit_log.php');
    await check(page, 'audit log', { selector: 'tbody tr', min: 8 });
    await page.fill('form input[name="q"]', 'API');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.press('form input[name="q"]', 'Enter')]);
    await settle(page);
    await check(page, 'audit log (search API)', { selector: 'tbody tr', min: 4, text: 'API Key' });
    await page.click('button[data-bs-target="#advancedFilter"]');
    await page.waitForTimeout(700);
    await callout(page, [
      { selector: 'form input[name="q"]', n: 1, side: 'tl' },
      { selector: 'select[name="client"] + .ts-wrapper', n: 2, side: 'tl' },
      { selector: 'button[data-bs-target="#advancedFilter"]', n: 3, side: 'tr' },
      { selector: '#dateFilter', n: 4, side: 'tr' },
      { selector: 'thead th:first-child a', n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/14-audit-log`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 15  App Logs
    // -------------------------------------------------------------------------------------
    await goto(page, '/admin/app_log.php');
    await check(page, 'app log', { selector: 'tbody tr', min: 5, text: 'Cron' });
    await callout(page, [
      { selector: 'form input[name="q"]', n: 1, side: 'tl' },
      { selector: 'select[name="type"] + .ts-wrapper', n: 2, side: 'tl' },
      { selector: 'select[name="category"] + .ts-wrapper', n: 3, side: 'tl' },
      { selector: 'thead th:nth-child(4)', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/15-app-log`);
    await clearCallouts(page);

    // -------------------------------------------------------------------------------------
    // 16  Email Log
    // -------------------------------------------------------------------------------------
    await resize(page, 1080);
    await goto(page, '/admin/email_log.php');
    await check(page, 'email log', { selector: 'tbody tr', min: 3, text: 'Every email the mailbox poller has touched' });
    await callout(page, [
      { selector: '.card-body > p.text-muted', n: 1, side: 'tl' },
      { selector: 'select[name="outcome"] + .ts-wrapper', n: 2, side: 'tl' },
      { selector: 'tbody tr:first-child td:nth-child(5) .badge', n: 3, side: 'tl' },
      { selector: 'tbody tr:first-child td:nth-child(6)', n: 4, side: 'tl' },
    ]);
    await shot(page, `${G}/16-email-log`);
    await clearCallouts(page);
    await resize(page, VIEW_H);

    if (blocked.length) throw new Error('A save request was attempted and blocked: ' + blocked.join(', '));
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
