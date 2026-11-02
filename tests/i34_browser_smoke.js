// Browser smoke test (issue #34) for the reports hub, my dashboard, the new reports, filters, PDF download and schedules, at 1280px and 390px.
// Scratch instance only (php -S on a throwaway copy of the demo data). Run once per theme:
//   BASE=http://127.0.0.1:18934 OUT=/some/dir THEME=light|dark node tests/i34_browser_smoke.js   (OUT/shots gets screenshots; dark = settings.config_theme_dark_default=1)
// The light run also exercises the Filters panel, PDF/CSV downloads and the schedule + recipient group forms (it creates one group and one schedule: delete them before re-running).
// Needs playwright-core and a Chromium; the paths below are this machine's.
const { chromium } = require('/home/sysadmin/.npm/_npx/db89d7302a373f10/node_modules/playwright-core');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:18934';
const OUT = process.env.OUT;
const THEME = process.env.THEME || 'light';
const results = [];
const ok = (c, l) => { results.push((c ? 'PASS ' : 'FAIL ') + l); };
(async () => {
  const browser = await chromium.launch({ executablePath: '/home/sysadmin/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome', args: ['--no-sandbox'] });
  const stateFile = OUT + '/state.json';
  let ctx = await browser.newContext(fs.existsSync(stateFile) ? { storageState: stateFile, viewport: { width: 1280, height: 900 } } : { viewport: { width: 1280, height: 900 } });
  let page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) errors.push('console ' + m.text()); });
  if (!fs.existsSync(stateFile)) {
    await page.goto(BASE + '/login.php');
    await page.fill('input[name=email]', 'alex.morgan@summitridge.example');
    await page.fill('input[name=password]', 'DemoAdmin#2026');
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}), page.click('button[type=submit], input[type=submit]')]);
    await ctx.storageState({ path: stateFile });
  }
  const pages = [
    ['hub', '/agent/reports/index.php'],
    ['sla', '/agent/reports/sla_breach_aging.php?canned_date=last90'],
    ['backlog', '/agent/reports/ticket_backlog.php'],
    ['assets', '/agent/reports/asset_lifecycle.php'],
    ['servicedesk_filtered', '/agent/reports/service_desk.php?canned_date=last90&f_priority=High'],
    ['schedules', '/agent/reports/schedules.php'],
    ['my_dashboard', '/agent/my_dashboard.php'],
  ];
  for (const [w, h, tag] of [[1280, 900, 'desktop'], [390, 844, 'mobile']]) {
    await page.setViewportSize({ width: w, height: h });
    for (const [name, url] of pages) {
      errors.length = 0;
      const resp = await page.goto(BASE + url, { waitUntil: 'load' });
      const status = resp.status();
      const info = await page.evaluate(() => ({
        title: document.title, h: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
        text: document.body.innerText.slice(0, 4000), tables: document.querySelectorAll('table').length,
        fatal: /Fatal error|Warning:|Notice:|Deprecated:|Uncaught/.test(document.body.innerText),
      }));
      ok(status === 200 && !info.fatal, `${tag}/${THEME} ${name}: HTTP ${status}, no PHP error text`);
      ok(info.h <= info.cw + 1, `${tag}/${THEME} ${name}: no horizontal page scroll (${info.h} <= ${info.cw})`);
      ok(errors.length === 0, `${tag}/${THEME} ${name}: no JS errors ${errors.join('|').slice(0, 200)}`);
      await page.screenshot({ path: `${OUT}/shots/${THEME}_${tag}_${name}.png`, fullPage: false });
      if (tag === 'desktop' && THEME === 'light') {
        if (name === 'hub') {
          for (const t of ['Open Tickets', 'Mean Resolution Time', 'SLA Compliance', 'Tickets This Month', 'SLA Breach and Aging', 'Ticket Backlog', 'Asset Lifecycle']) ok(info.text.includes(t), `hub shows "${t}"`);
        }
        if (name === 'sla') { ok(info.tables >= 5 && info.text.includes('SLA Compliance'), 'SLA report renders tables and compliance'); }
        if (name === 'backlog') { ok(info.tables >= 6 && info.text.includes('Weekly backlog trend'), 'backlog report renders its tables'); }
        if (name === 'assets') { ok(info.text.includes('Warranty expired') && info.tables >= 3, 'asset report renders its tables'); }
        if (name === 'schedules') { ok(info.text.includes('Quarterly') && info.text.includes('PDF attached'), 'schedules offers Quarterly and PDF'); }
      }
    }
  }
  if (THEME === 'light') {
    await page.setViewportSize({ width: 1280, height: 900 });
    // Filters panel + apply
    await page.goto(BASE + '/agent/reports/service_desk.php?canned_date=last90');
    await page.click('#rpt-filters-toggle');
    await page.waitForSelector('#rpt-filter-form', { state: 'visible' });
    await page.selectOption('#rpt-f_priority', 'High');
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#rpt-filter-form button[type=submit]')]);
    ok(/f_priority=High/.test(page.url()) && /canned_date=last90/.test(page.url()), 'filter form applies priority and keeps the date range in the URL');
    const badge = await page.evaluate(() => document.querySelector('#rpt-filters-toggle .badge')?.textContent);
    ok(badge === '1', 'Filters button shows 1 active filter');
    await page.screenshot({ path: `${OUT}/shots/light_desktop_filters_open.png` });
    // PDF + CSV download through the browser session
    const res = await page.evaluate(async () => {
      const out = {};
      for (const k of ['pdf', 'csv']) {
        const r = await fetch(location.pathname + location.search + '&export=' + k, { credentials: 'same-origin' });
        const b = new Uint8Array(await r.arrayBuffer());
        out[k] = { status: r.status, type: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), head: String.fromCharCode(...b.slice(0, 8)), len: b.length };
      }
      return out;
    });
    ok(res.pdf.status === 200 && /application\/pdf/.test(res.pdf.type) && res.pdf.head.startsWith('%PDF-') && /\.pdf/.test(res.pdf.disp) && res.pdf.len > 1000, `PDF download: ${JSON.stringify(res.pdf)}`);
    ok(res.csv.status === 200 && /text\/csv/.test(res.csv.type), 'CSV download still works');
    // PDF for each new report
    for (const k of ['sla_breach_aging', 'ticket_backlog', 'asset_lifecycle', 'ticket_summary']) {
      const r = await page.evaluate(async (k) => { const r = await fetch('/agent/reports/' + k + '.php?export=pdf', { credentials: 'same-origin' }); const b = new Uint8Array(await r.arrayBuffer()); return { s: r.status, h: String.fromCharCode(...b.slice(0, 5)), n: b.length }; }, k);
      ok(r.s === 200 && r.h === '%PDF-', `PDF export of ${k}: ${r.s} ${r.n} bytes`);
    }
    // Saved view with a filter, then it appears; schedules add form with groups
    await page.goto(BASE + '/agent/reports/schedules.php');
    await page.click('button[data-bs-target="#rpt-groups"]');
    await page.fill('#grp-name', 'SmokeGroup');
    await page.fill('#grp-rcpt', 'alex.morgan@summitridge.example');
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#rpt-groups form button[type=submit]')]);
    const t2 = await page.evaluate(() => document.body.innerText);
    ok(/Recipient group added/.test(t2) && t2.includes('SmokeGroup'), 'recipient group can be created from the UI');
    await page.selectOption('select[name=schedule_delivery]', 'pdf');
    await page.selectOption('select[name=schedule_frequency]', 'quarterly');
    await page.check('input[name^="group_target"]');
    await page.selectOption('select[name=schedule_source]', 'r:sla_breach_aging');
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('form:has(input[name=add_schedule]) button[type=submit]')]);
    const t3 = await page.evaluate(() => document.body.innerText);
    ok(/Report schedule added/.test(t3) && t3.includes('PDF attached') && t3.includes('Quarterly') && t3.includes('SmokeGroup'), 'a quarterly PDF schedule targeting the group can be added from the UI');
    await page.screenshot({ path: `${OUT}/shots/light_desktop_schedules_after.png`, fullPage: true });
  }
  await browser.close();
  console.log(results.join('\n'));
  console.log(results.some(r => r.startsWith('FAIL')) ? 'SMOKE: FAILURES' : 'SMOKE: ALL PASSED');
})().catch(e => { console.log(results.join('\n')); console.log('RUNNER ERROR', e.message); process.exit(1); });
