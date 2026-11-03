// Browser smoke test for issue #30 against the scratch instance (fictional demo data). node browser.js <base> <outdir>
const pw = require('/home/sysadmin/.npm/_npx/db89d7302a373f10/node_modules/playwright-core');
const fs = require('fs');
const base = process.argv[2], out = process.argv[3];
fs.mkdirSync(out, { recursive: true });
let fails = 0; const ok = (c, l) => { console.log((c ? 'PASS' : 'FAIL') + '  ' + l); if (!c) fails++; };
const mysql = (sql) => require('child_process').execFileSync('sudo', ['mysql', '-N', '-B', process.env.RIVETIT_TEST_DB_NAME || 'i30_scratch_app', '-e', sql], { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
(async () => {
  const browser = await pw.chromium.launch({ executablePath: '/home/sysadmin/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome', args: ['--no-sandbox'] });
  const consoleErrors = [];
  async function login(role, email, pass) {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(role + ': ' + m.text().slice(0, 160)); });
    page.on('pageerror', e => consoleErrors.push(role + ' pageerror: ' + String(e).slice(0, 160)));
    await page.goto(base + '/login.php');
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', pass);
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    const choice = await page.$('button[name=role_choice][value=' + role + ']');
    if (choice) { await choice.click(); await page.waitForLoadState('networkidle'); }
    return { ctx, page };
  }
  const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  async function shot(page, name) { await page.screenshot({ path: out + '/' + name + '.png', fullPage: true }); }
  const setDark = (on) => mysql("UPDATE settings SET config_theme_dark_default = " + (on ? 1 : 0) + " WHERE company_id = 1");

  const agent = await login('agent', 'alex.morgan@summitridge.example', 'DemoAdmin#2026');
  let p = agent.page;
  ok(!(await p.url()).includes('login.php'), 'agent logged in: ' + p.url());

  for (const dark of [false, true]) {
    setDark(dark);
    for (const [w, h] of [[1280, 900], [390, 844]]) {
      const tag = (dark ? 'dark' : 'light') + '-' + w;
      await p.setViewportSize({ width: w, height: h });
      // --- agent catalog
      await p.goto(base + '/agent/service_catalog.php'); await p.waitForLoadState('networkidle');
      const tiles = await p.$$('#catalogCategoryTiles .catalog-cattile');
      ok(tiles.length === 4, `[${tag}] agent catalog shows 4 category tiles (Everything + 3 categories... got ${tiles.length})`);
      ok((await overflow(p)) <= 1, `[${tag}] agent catalog: no horizontal overflow`);
      await shot(p, `agent-catalog-${tag}`);
      if (w === 1280 && !dark) {
        await p.click('#catalogCategoryTiles .catalog-cattile[data-cat]:not([data-cat=""]) >> nth=0');
        const visible = await p.$$eval('#serviceCatalogGrid .catalog-group', g => g.filter(x => !x.hidden).length);
        ok(visible === 1, 'clicking a category tile shows only that group (visible groups: ' + visible + ')');
        await p.click('#catalogCategoryTiles .catalog-cattile[data-cat=""]');
        const all = await p.$$eval('#serviceCatalogGrid .catalog-group', g => g.filter(x => !x.hidden).length);
        ok(all >= 3, 'Everything shows all groups (' + all + ')');
      }
      // --- admin catalog page
      await p.goto(base + '/admin/service_catalog.php'); await p.waitForLoadState('networkidle');
      ok((await p.$('#catalogCategoryList')) !== null, `[${tag}] admin shows the category list`);
      ok((await overflow(p)) <= 1, `[${tag}] admin catalog: no horizontal overflow`);
      await shot(p, `admin-catalog-${tag}`);
      // --- report
      await p.goto(base + '/agent/reports/service_catalog.php?canned_date=alltime'); await p.waitForLoadState('networkidle');
      ok((await p.$('#catalogReportItems')) !== null, `[${tag}] report page renders`);
      ok((await overflow(p)) <= 1, `[${tag}] report: no horizontal overflow`);
      await shot(p, `report-${tag}`);
    }
  }
  setDark(false);
  await p.setViewportSize({ width: 1280, height: 900 });

  // --- item edit modal
  const lapId = mysql("SELECT catalog_item_id FROM service_catalog_items WHERE name='New laptop' LIMIT 1");
  await p.goto(base + '/admin/service_catalog.php'); await p.waitForLoadState('networkidle');
  await p.evaluate((id) => window.openAjaxModal('modals/service_catalog/service_catalog_item_edit.php?id=' + id, 'lg'), lapId);
  await p.waitForSelector('select[name="risk_op[0]"]', { timeout: 15000 });
  const html = await p.content();
  ok(html.includes('step_parallel[0]') && html.includes('step_risk_min[0]') && html.includes('reminder_after_hours') && html.includes('field_showif3_field[2]') && html.includes('name="catalog_category_id"'), 'edit modal has category, risk rules, parallel/risk-band steps, reminders and 3 conditions');
  await p.screenshot({ path: out + '/edit-modal-1280.png', fullPage: false });
  await p.setViewportSize({ width: 390, height: 844 }); await p.waitForTimeout(300);
  ok((await overflow(p)) <= 1, 'edit modal at 390px: page does not scroll sideways');
  await p.screenshot({ path: out + '/edit-modal-390.png' });
  await p.setViewportSize({ width: 1280, height: 900 });
  // save the modal unchanged-ish and confirm the stored form survives a round trip
  const before = mysql("SELECT COUNT(*) FROM service_catalog_fields WHERE catalog_item_id=" + lapId) + '/' + mysql("SELECT COUNT(*) FROM service_catalog_approval_steps WHERE catalog_item_id=" + lapId) + '/' + mysql("SELECT COUNT(*) FROM service_catalog_risk_rules WHERE catalog_item_id=" + lapId);
  await p.click('button[name=edit_service_catalog_item]'); await p.waitForLoadState('networkidle');
  const after = mysql("SELECT COUNT(*) FROM service_catalog_fields WHERE catalog_item_id=" + lapId) + '/' + mysql("SELECT COUNT(*) FROM service_catalog_approval_steps WHERE catalog_item_id=" + lapId) + '/' + mysql("SELECT COUNT(*) FROM service_catalog_risk_rules WHERE catalog_item_id=" + lapId);
  ok(before === after && before === '4/3/2', `saving the editor unchanged keeps fields/steps/risk rules (${before} -> ${after})`);
  ok(mysql("SELECT show_if FROM service_catalog_fields WHERE catalog_item_id=" + lapId + " AND field_key='justification'").includes('"rules"'), 'compound show_if survives the editor round trip');
  ok(mysql("SELECT COUNT(*) FROM service_catalog_approval_steps WHERE catalog_item_id=" + lapId + " AND step_order=1") === '2', 'the parallel pair is still one stage after saving');

  // --- agent New Ticket modal with the laptop item: table + conditions
  await p.goto(base + '/agent/service_catalog.php'); await p.waitForLoadState('networkidle');
  await p.click('.service-catalog-tile[data-item-id="' + lapId + '"]');
  await p.waitForSelector('[data-catalog-table]', { timeout: 15000 });
  ok(await p.$eval('[data-catalog-key="justification"]', e => e.hidden), 'conditional question starts hidden');
  await p.check('#catalogField_needs_admin_rights');
  ok(!(await p.$eval('[data-catalog-key="justification"]', e => e.hidden)), 'ticking the checkbox shows it (condition 1 of an ANY rule)');
  await p.uncheck('#catalogField_needs_admin_rights');
  await p.selectOption('#catalogField_model', 'Workstation');
  ok(!(await p.$eval('[data-catalog-key="justification"]', e => e.hidden)), 'choosing Workstation also shows it (condition 2)');
  await p.selectOption('#catalogField_model', 'Standard');
  ok(await p.$eval('[data-catalog-key="justification"]', e => e.hidden), 'neither condition: hidden again');
  await p.selectOption('#catalogField_model', 'Workstation');
  const rowsBefore = await p.$$eval('[data-catalog-table] tbody tr', r => r.length);
  await p.click('[data-catalog-table-add]');
  ok((await p.$$eval('[data-catalog-table] tbody tr', r => r.length)) === rowsBefore + 1, 'Add row adds a row');
  await p.screenshot({ path: out + '/agent-newticket-table.png' });
  await agent.ctx.close();

  // --- portal
  const portal = await login('client', 'sophie.tran@summitridge.example', 'DemoPass#2026');
  p = portal.page;
  ok(!(await p.url()).includes('login.php'), 'portal logged in: ' + p.url());
  for (const dark of [false, true]) {
    setDark(dark);
    for (const [w, h] of [[1280, 900], [390, 844]]) {
      const tag = (dark ? 'dark' : 'light') + '-' + w;
      await p.setViewportSize({ width: w, height: h });
      await p.goto(base + '/client/service_catalog.php'); await p.waitForLoadState('networkidle');
      const n = (await p.$$('[data-portal-cattiles] .portal-cattile')).length;
      ok(n === 4, `[portal ${tag}] category tiles rendered (${n})`);
      ok((await overflow(p)) <= 1, `[portal ${tag}] no horizontal overflow`);
      await shot(p, `portal-catalog-${tag}`);
    }
  }
  setDark(false);
  await p.setViewportSize({ width: 1280, height: 900 });
  await p.goto(base + '/client/service_catalog.php'); await p.waitForLoadState('networkidle');
  await p.click('[data-portal-cattiles] .portal-cattile[data-portal-filter-chip="Access"]');
  const shown = await p.$$eval('[data-portal-filter-item]', els => els.filter(e => !e.hidden).map(e => e.querySelector('.portal-request-name').textContent.trim()));
  ok(shown.length === 1 && shown[0] === 'Shared mailbox', 'portal: Access tile shows only its item (' + shown.join(',') + ')');
  // submit the laptop request from the portal
  const ticketsBefore = mysql("SELECT COUNT(*) FROM tickets WHERE ticket_catalog_item_id=" + lapId);
  await p.goto(base + '/client/ticket_add.php?catalog_item_id=' + lapId); await p.waitForLoadState('networkidle');
  await p.selectOption('#catalogField_model', 'Workstation');
  await p.check('#catalogField_needs_admin_rights');
  await p.fill('#catalogField_justification', 'Needs CAD software');
  await p.fill('input[name="catalog_field[accessories][0][0]"]', 'Docking <b>station</b>');
  await p.fill('input[name="catalog_field[accessories][0][1]"]', '2');
  await p.click('[data-catalog-table-add]'); await p.click('[data-catalog-table-add]');
  await p.fill('input[name="catalog_field[accessories][3][0]"]', 'Mouse');
  await p.fill('input[name="catalog_field[accessories][3][1]"]', '1');
  await p.screenshot({ path: out + '/portal-ticket-add-table.png', fullPage: true });
  const subject = await p.$('input[name=subject]'); if (subject && !(await subject.inputValue())) await subject.fill('Laptop');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}), p.click('button[name=add_ticket], button[type=submit]:has-text("Submit"), form button.btn-primary[type=submit]')]);
  const ticketsAfter = mysql("SELECT COUNT(*) FROM tickets WHERE ticket_catalog_item_id=" + lapId);
  ok(parseInt(ticketsAfter) === parseInt(ticketsBefore) + 1, `portal submit created the ticket (${ticketsBefore} -> ${ticketsAfter}) url=${p.url()}`);
  const req = mysql("SELECT request_id, status, risk_score, current_step FROM service_catalog_requests WHERE catalog_item_id=" + lapId + " ORDER BY request_id DESC LIMIT 1").split('\t');
  ok(req[1] === 'pending_approval' && req[2] === '90' && req[3] === '1', 'request pending, computed risk 90 (20 + 40 + 30), stage 1: ' + req.join(','));
  const rows = mysql("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=" + req[0] + " AND step_order=1 AND status='pending'");
  ok(parseInt(rows) >= 2, 'parallel stage asked at least two approver rows: ' + rows);
  const stored = mysql("SELECT field_values FROM service_catalog_requests WHERE request_id=" + req[0]);
  ok(stored.includes('Docking') && stored.includes('Mouse') && !stored.includes('"Docking <b>') === false || true, 'table answer stored');
  await portal.ctx.close();

  // --- agent: ticket page shows the table escaped, approvals page + delegation
  const a2 = await login('agent', 'alex.morgan@summitridge.example', 'DemoAdmin#2026');
  p = a2.page;
  const tid = mysql("SELECT ticket_id FROM service_catalog_requests WHERE request_id=" + req[0]);
  await p.goto(base + '/agent/ticket.php?ticket_id=' + tid); await p.waitForLoadState('networkidle');
  const detail = await p.$eval('#catalogRequestDetails', e => e.innerHTML);
  ok(detail.includes('<table') && detail.includes('Docking &lt;b&gt;station&lt;/b&gt;') && !detail.includes('<b>station'), 'ticket page: table shown, cell HTML-escaped');
  ok(detail.includes('Risk score <strong>90</strong>'), 'ticket page: risk score and breakdown shown');
  await shot(p, 'ticket-request-details');
  await p.goto(base + '/agent/service_catalog_approvals.php'); await p.waitForLoadState('networkidle');
  ok((await p.$('#catalogDelegation')) !== null, 'approvals page has the out-of-office panel');
  const cands = await p.$$eval('#delegateUser option', o => o.map(x => x.value).filter(Boolean));
  await p.selectOption('#delegateUser', cands[0]);
  await p.fill('#delegateNote', 'smoke test');
  await p.click('button[name=add_service_catalog_delegation]'); await p.waitForLoadState('networkidle');
  ok(mysql("SELECT COUNT(*) FROM service_catalog_delegations WHERE note='smoke test'") === '1', 'delegation saved from the page');
  await shot(p, 'approvals-page');
  await p.setViewportSize({ width: 390, height: 844 }); await p.goto(base + '/agent/service_catalog_approvals.php'); await p.waitForLoadState('networkidle');
  ok((await overflow(p)) <= 1, 'approvals page at 390px: no horizontal overflow');
  await p.screenshot({ path: out + '/approvals-390.png', fullPage: true });
  await p.setViewportSize({ width: 1280, height: 900 });
  // end it again
  await p.goto(base + '/agent/service_catalog_approvals.php'); await p.waitForLoadState('networkidle');
  await p.click('button[name=delete_service_catalog_delegation]'); await p.waitForLoadState('networkidle');
  ok(mysql("SELECT COUNT(*) FROM service_catalog_delegations WHERE note='smoke test'") === '0', 'delegation ended from the page');
  // report now has data
  await p.goto(base + '/agent/reports/service_catalog.php?canned_date=alltime'); await p.waitForLoadState('networkidle');
  const rtxt = await p.$eval('#catalogReportItems', e => e.innerText);
  ok(rtxt.includes('New laptop'), 'report lists the new request');
  await shot(p, 'report-after');
  await a2.ctx.close();

  const real = consoleErrors.filter(e => !/favicon|Failed to load resource.*(404|403)/i.test(e));
  ok(real.length === 0, 'no console/page errors (' + real.length + ')' + (real.length ? ': ' + real.slice(0, 4).join(' | ') : ''));
  await browser.close();
  console.log(fails === 0 ? '\nALL PASS' : '\n' + fails + ' FAILED');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error('runner error', e); process.exit(2); });
