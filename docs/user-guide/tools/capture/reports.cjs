// Screenshots for docs/user-guide/10-dashboard-and-reports.md (group "reports").
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/reports.cjs
//
// READ-ONLY: it only opens pages and changes the on-page filters through the address bar. It never submits a
// form that saves, never clicks Add / Delete / Pause on the schedules page, and never changes the dashboard's
// per-user chart-type preference (the Bar/Line select is shown, not changed).
//
// Needs the data from seed/90-reports.sql (a year of tickets with time entries and ratings, open queue,
// report schedules) plus whatever the other chapters seed. It fails loudly on a PHP error or an empty state.

const path = require('path');
const { launch, login, goto, shot, callout, clearCallouts, settle, BASE, OUT } = require('../lib.cjs');

const problems = [];
function fail(msg) { problems.push(msg); console.error('PROBLEM:', msg); }

// The app runs in the company time zone (America/Chicago in the demo), so build dates the same way.
function appDate(offsetDays = 0) {
  const d = new Date(Date.now() + offsetDays * 86400000);
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Chicago', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(d);
  const g = (t) => parts.find((p) => p.type === t).value;
  return { ymd: `${g('year')}-${g('month')}-${g('day')}`, year: Number(g('year')), month: Number(g('month')), day: Number(g('day')) };
}

// Open a page and refuse to continue on PHP errors, a denied page or a missing landmark.
async function open(page, url, mustHave = []) {
  await goto(page, url);
  const text = await page.locator('body').innerText();
  if (/Fatal error|Uncaught|Parse error|Warning:|Notice:|Deprecated:/.test(text)) fail(`PHP message on ${url}`);
  if (/You don't have access to this page/.test(text)) fail(`Access denied on ${url}`);
  for (const sel of mustHave) {
    if ((await page.locator(sel).count()) === 0) fail(`Missing ${sel} on ${url}`);
  }
}

// Chart.js animates for about a second after load; give it time so the picture shows the finished chart.
async function chartsDone(page) { await page.waitForTimeout(1800); }

// Give a card a stable hook by its heading text (the app's cards have no ids).
async function tagCard(page, headingText, id) {
  const ok = await page.evaluate(({ headingText, id }) => {
    const h = [...document.querySelectorAll('.card-title, h6')].find((n) => n.textContent.replace(/\s+/g, ' ').trim().startsWith(headingText));
    const card = h && h.closest('.card');
    if (!card) return false;
    card.id = id;
    return true;
  }, { headingText, id });
  if (!ok) fail(`Card "${headingText}" not found`);
  return ok;
}

async function tallShot(page, name, height, opts = {}) {
  await page.setViewportSize({ width: 1440, height });
  await settle(page, 300);
  await shot(page, name, opts);
  await page.setViewportSize({ width: 1440, height: 900 });
}

(async () => {
  const { browser, context, page } = await launch();
  await login(page, 'admin');

  // ---------------------------------------------------------------- Dashboard
  await open(page, '/agent/dashboard.php', ['.dash-attention', '.dash-tiles', '#ticketFlowChart']);
  await chartsDone(page);
  await callout(page, [
    { selector: 'aside .nav-link[href="/agent/dashboard.php"]', n: 1, side: 'tr' },
    { selector: '.dash-attention', n: 2 },
    { selector: '.dash-tiles', n: 3 },
    { selector: 'aside a[href="/agent/reports/"]', n: 4, side: 'tr' },
  ]);
  await shot(page, 'reports/01-dashboard-top');
  await clearCallouts(page);

  // The flow chart with its Bar/Line switch.
  await tagCard(page, 'Tickets Opened vs Resolved', 'ug-flow');
  await callout(page, [{ selector: '#technical_chart_type', n: 1, side: 'bl' }]);
  await shot(page, 'reports/02-dashboard-flow-chart', { selector: '#ug-flow' });
  await clearCallouts(page);

  // The three doughnuts and the workload card.
  await page.evaluate(() => {
    const c = document.querySelector('#ticketPriorityChart');
    if (c) c.closest('.dash-charts').id = 'ug-breakdowns';
  });
  if (await page.locator('#ug-breakdowns').count() === 0) fail('breakdown cards not found');
  else await shot(page, 'reports/03-dashboard-breakdowns', { selector: '#ug-breakdowns' });

  // History tiles, resolved by technician, recently resolved.
  await tagCard(page, 'Recently Resolved', 'ug-recent');
  await page.evaluate(() => {
    const t = document.querySelector('#ug-recent');
    if (t) t.closest('.dash-charts').id = 'ug-history-cards';
    const h = document.querySelector('.dash-section-title');
    if (h) { h.id = 'ug-history-title'; }
  });
  await page.evaluate(() => {
    // wrap title + tiles + cards in one element so a single element screenshot shows the whole "Historical" band
    const title = document.querySelector('#ug-history-title');
    if (!title) return;
    const wrap = document.createElement('div');
    wrap.id = 'ug-history';
    title.parentNode.insertBefore(wrap, title);
    let n = title;
    while (n) { const next = n.nextElementSibling; wrap.appendChild(n); if (n.id === 'ug-history-cards') break; n = next; }
  });
  if (await page.locator('#ug-history').count() === 0) fail('historical band not found');
  else await shot(page, 'reports/04-dashboard-history', { selector: '#ug-history' });

  await tagCard(page, 'Your Open Tickets', 'ug-mine');
  if (await page.locator('#ug-mine').count()) await shot(page, 'reports/05-dashboard-your-tickets', { selector: '#ug-mine' });

  // ---------------------------------------------------------------- Reports hub
  await open(page, '/agent/reports/', ['.it-stat-grid', '.rc-grid']);
  await callout(page, [
    { selector: 'aside .navbar-nav', n: 1, side: 'tr' },
    { selector: '.it-stat-grid', n: 2 },
    { selector: '.rc-item[href$="service_desk.php"]', n: 3 },
    { selector: '.rc-item[href$="schedules.php"]', n: 4 },
  ]);
  await shot(page, 'reports/06-reports-hub');
  await clearCallouts(page);

  // ---------------------------------------------------------------- Service Desk & SLA
  await open(page, '/agent/reports/service_desk.php', ['#volumeTrendChart', '#agingChart', '#sdCanned']);
  await chartsDone(page);
  await callout(page, [
    { selector: '#sdCanned', n: 1 },
    { selector: '.card-tools a[href*="export=csv"]', n: 2, side: 'tr' },
    { selector: '.card-tools .js-print-page', n: 3, side: 'tr' },
    { selector: '.row.px-3', n: 4 },
  ]);
  await shot(page, 'reports/07-service-desk', { fullPage: true });
  await clearCallouts(page);

  // ---------------------------------------------------------------- Tickets (summary by month)
  await open(page, '/agent/reports/ticket_summary.php', ['#tickets', '#ticket-summary-year']);
  await chartsDone(page);
  await callout(page, [{ selector: '#ticket-summary-year', n: 1 }, { selector: '.table-responsive-sm', n: 2 }]);
  await shot(page, 'reports/08-ticket-summary');
  await clearCallouts(page);

  // ---------------------------------------------------------------- Tickets: Day by Day (last 30 days, custom range)
  const to = appDate(0), from = appDate(-29);
  await open(page, `/agent/reports/ticket_day_breakdown.php?canned_date=custom&dtf=${from.ymd}&dtt=${to.ymd}`, ['#dayBreakdownChart', '#tdbCanned']);
  await chartsDone(page);
  await callout(page, [{ selector: '#tdbCanned', n: 1 }, { selector: '.row.px-3', n: 2 }]);
  await shot(page, 'reports/09-day-by-day');
  await clearCallouts(page);

  // ---------------------------------------------------------------- Tickets by Department
  const m = to.day < 10 ? (to.month === 1 ? 12 : to.month - 1) : to.month;
  const y = to.day < 10 && to.month === 1 ? to.year - 1 : to.year;
  await open(page, `/agent/reports/ticket_by_client.php?year=${y}&month=${m}`, ['select[name=year]', 'select[name=month]']);
  if ((await page.locator('table tbody tr').count()) < 4) fail('Tickets by Department looks empty');
  await callout(page, [{ selector: 'select[name=year]', n: 1 }, { selector: 'select[name=month]', n: 2 }]);
  await shot(page, 'reports/10-tickets-by-department', { fullPage: true });
  await clearCallouts(page);

  // ---------------------------------------------------------------- Time by Technician
  await open(page, `/agent/reports/time_by_tech.php?year=${to.year}`, ['select[name=year]']);
  if ((await page.locator('table tbody tr').count()) < 3) fail('Time by Technician looks empty');
  await callout(page, [{ selector: 'select[name=year]', n: 1 }]);
  await shot(page, 'reports/11-time-by-technician');
  await clearCallouts(page);

  // ---------------------------------------------------------------- Technician Performance
  await open(page, '/agent/reports/technician_performance.php', ['#tpHoursChart', '#tpCanned']);
  await chartsDone(page);
  await callout(page, [{ selector: '#tpCanned', n: 1 }, { selector: '.it-stat-grid', n: 2 }, { selector: '.table-responsive-sm', n: 3 }]);
  await shot(page, 'reports/12-technician-performance');
  await clearCallouts(page);

  // ---------------------------------------------------------------- Customer Satisfaction (top of the page)
  await open(page, '/agent/reports/csat.php', ['#csatDistChart', '#csatTrendChart', '#csatCanned']);
  await chartsDone(page);
  await callout(page, [{ selector: '.row.px-3', n: 1 }, { selector: '#csatDistChart', n: 2 }, { selector: '#csatTrendChart', n: 3 }]);
  await tallShot(page, 'reports/13-csat-top', 1150);
  await clearCallouts(page);
  // the by-technician / by-department / feedback part: crop the full page from the "By Technician" heading
  // down to the twelfth feedback row (the page itself lists every rating in range).
  const box = await page.evaluate(() => {
    const h = [...document.querySelectorAll('h6')].find((n) => n.textContent.trim().startsWith('By Technician'));
    const rows = [...document.querySelectorAll('table')].find((t) => t.querySelector('th') && t.textContent.includes('Rated At'));
    if (!h || !rows) return null;
    const body = [...rows.querySelectorAll('tbody tr')].slice(0, 12);
    const top = h.getBoundingClientRect().top + window.scrollY - 8;
    const last = body[body.length - 1].getBoundingClientRect();
    const card = rows.closest('.card-body').getBoundingClientRect();
    return { x: Math.max(0, card.left - 6), y: top, width: card.width + 12, height: last.bottom + window.scrollY - top + 6, rows: body.length };
  });
  if (!box || box.rows < 6) fail('CSAT feedback rows not found');
  else {
    const file = path.join(OUT, 'reports/14-csat-breakdowns.png');
    await page.mouse.move(2, 2);
    await page.screenshot({ path: file, fullPage: true, clip: { x: box.x, y: box.y, width: box.width, height: box.height } });
    console.log('saved docs/user-guide/images/reports/14-csat-breakdowns.png');
  }

  // ---------------------------------------------------------------- RMM Health
  await open(page, '/agent/reports/rmm_health.php', ['#rmmCanned']);
  if (await page.locator('#rmmTrendChart').count()) {
    await chartsDone(page);
    await shot(page, 'reports/15-rmm-health', { fullPage: true });
  } else {
    fail('RMM Health has no alerts to show (the integrations chapter seeds them)');
  }

  // ---------------------------------------------------------------- Credential rotation reports
  await open(page, '/agent/reports/credential_rotation.php', ['input[name=days]']);
  await callout(page, [{ selector: 'input[name=days]', n: 1 }]);
  await shot(page, 'reports/16-credential-rotation');
  await clearCallouts(page);
  await open(page, '/agent/reports/credential_rotation_v2.php', ['input[name=days]']);
  if ((await page.locator('table tbody tr td.text-muted').count()) > 0) fail('Credential rotation due is empty');
  await callout(page, [{ selector: 'input[name=days]', n: 1 }]);
  await shot(page, 'reports/17-credential-rotation-due');
  await clearCallouts(page);

  // ---------------------------------------------------------------- Scheduled reports
  await open(page, '/agent/reports/schedules.php', ['select[name=schedule_report]', 'select[name=schedule_frequency]', 'input[name=schedule_recipients]']);
  if ((await page.locator('table tbody tr').count()) < 2) fail('No report schedules to show');
  await callout(page, [
    { selector: 'select[name=schedule_report]', n: 1 },
    { selector: 'select[name=schedule_frequency]', n: 2 },
    { selector: 'input[name=schedule_recipients]', n: 3 },
    { selector: 'button[type=submit].btn-primary', n: 4, side: 'tr' },
    { selector: 'table.table', n: 5, side: 'br' },
  ]);
  await shot(page, 'reports/18-schedules');
  await clearCallouts(page);

  // ---------------------------------------------------------------- What a technician sees (no Reporting permission)
  const tech = await launch();
  await login(tech.page, 'tech');
  await tech.page.goto(`${BASE}/agent/reports/`, { waitUntil: 'domcontentloaded' });
  await settle(tech.page);
  if (!/Your role needs/.test(await tech.page.locator('body').innerText())) fail('Technician was not denied the Reports page');
  await shot(tech.page, 'reports/19-no-access');
  await tech.browser.close();

  await browser.close();
  if (problems.length) { console.error(`\n${problems.length} problem(s):\n - ` + problems.join('\n - ')); process.exit(1); }
})().catch((e) => { console.error(e); process.exit(1); });
