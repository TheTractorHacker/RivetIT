// Screenshots for 08-training-assignments-and-records.md and 08b-training-kiosks-learners-certificates.md.
//
//   cd <repo> && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/training-delivery.cjs
//
// Needs seed/50-training-authoring.php and seed/55-training-delivery.php applied.
// Agent pages are captured through BASE (default http://127.0.0.1:8080). The training KIOSK checks the browser's Origin
// against config_base_url ("localhost:8080" on the demo server), so the kiosk part opens http://localhost:8080 instead.
// Nothing here saves a form: offcanvas forms are opened, filled with illustrative values and closed. The kiosk part signs
// in with the demo training PIN (creates ordinary kiosk sign-in rows) and only reads course pages.
//
// Keep in step with seed/55-training-delivery.php: DEVICE_TOKEN and PIN below are its fixed values.

const { launch, login, goto, shot, callout, clearCallouts, settle, BASE } = require('../lib.cjs');

const G = 'training-delivery';
const KIOSK = (process.env.KIOSK_URL || 'http://localhost:8080').replace(/\/$/, '');
const DEVICE_TOKEN = 'ugdeliveryfabshopipadtoken00000000000001abc';
const PIN = '481516';

function fail(msg) { throw new Error(msg); }

async function expectNoError(page, what) {
  const txt = await page.locator('body').innerText();
  if (/Fatal error|Warning:|Uncaught|Stack trace/.test(txt)) fail(`PHP error on ${what}`);
}

async function need(page, selector, what) {
  if (!(await page.locator(selector).count())) fail(`Missing ${what} (${selector}) on ${page.url()}`);
}

async function closeSheet(page) {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);
  await page.evaluate(() => document.querySelectorAll('.offcanvas-backdrop').forEach((n) => n.remove()));
}

async function jsonGet(page, action, params) {
  const qs = new URLSearchParams({ action, ...params }).toString();
  return page.evaluate(async (u) => (await fetch(u, { headers: { Accept: 'application/json' } })).json(), `/agent/training_ajax.php?${qs}`);
}

async function kioskSignIn(context, name, pattern) {
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  await page.goto(`${KIOSK}/kiosk/#d=${DEVICE_TOKEN}`, { waitUntil: 'domcontentloaded' });
  await settle(page, 2500);
  await page.fill('input[type=search], input[type=text]', name);
  await settle(page, 1000);
  await page.locator('button, a').filter({ hasText: pattern }).first().click();
  await settle(page, 700);
  for (const d of PIN) {
    await page.locator('button', { hasText: new RegExp('^' + d + '$') }).first().click();
    await page.waitForTimeout(120);
  }
  await page.locator('button', { hasText: /^OK$/ }).first().click();
  await settle(page, 2500);
  if (!/me\.php|trainer\.php/.test(page.url())) fail(`Kiosk sign-in for ${name} did not reach the home page (${page.url()})`);
  return page;
}

(async () => {
  const { browser, context, page } = await launch();
  await login(page, 'admin');

  // ===================================================================== page 08: agent side
  // 01 Overview
  await goto(page, '/agent/training_dashboard.php');
  await expectNoError(page, 'overview');
  await need(page, '.trr-kpis', 'KPI row');
  await callout(page, [{ selector: '.trr-kpis .trr-kpi:nth-child(1)', n: 1 }, { selector: '.trr-kpis .trr-kpi:nth-child(2)', n: 2 },
    { selector: '.trr-card:nth-of-type(2)', n: 3, side: 'tr' }]);
  await shot(page, `${G}/01-overview`);
  await clearCallouts(page);

  // 02 Assignments list
  await goto(page, '/agent/training_assignments.php?status=all');
  await expectNoError(page, 'assignments');
  await need(page, '#tro-a-table tbody tr', 'assignment rows');
  await callout(page, [{ selector: '#tro-a-status', n: 1 }, { selector: '#tro-a-q', n: 2 }, { selector: '#tro-a-dept', n: 3 },
    { selector: '#tro-a-table tbody tr:first-child .dropdown-toggle', n: 4, side: 'tr' }, { selector: '#tro-assign', n: 5, side: 'tr' }]);
  await shot(page, `${G}/02-assignments-list`);
  await clearCallouts(page);

  // 03 Overdue view with the row menu open
  await goto(page, '/agent/training_assignments.php?status=overdue');
  await need(page, '#tro-a-table tbody tr', 'overdue rows');
  await page.locator('#tro-a-table tbody tr:nth-child(2) .dropdown-toggle').click();
  await page.waitForTimeout(400);
  await shot(page, `${G}/03-row-menu`);
  await page.keyboard.press('Escape');

  // 04 Assign training (offcanvas)
  await goto(page, '/agent/training_assignments.php');
  await page.locator('#tro-assign').click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(600);
  const pick = page.locator('.offcanvas.show input[type=search], .offcanvas.show input[type=text]').first();
  await pick.fill('Maya');
  await page.waitForTimeout(900);
  await page.locator('.offcanvas.show [role=option], .offcanvas.show .tro-picker__item, .offcanvas.show li, .offcanvas.show button').filter({ hasText: /Maya Singh/ }).first().click();
  await pick.fill('Zoe');
  await page.waitForTimeout(900);
  await page.locator('.offcanvas.show [role=option], .offcanvas.show .tro-picker__item, .offcanvas.show li, .offcanvas.show button').filter({ hasText: /Zoe Hartman/ }).first().click();
  await page.locator('.offcanvas.show select').first().selectOption({ label: 'Hazard Communication (HazCom)' }).catch(() => {});
  await page.waitForTimeout(400);
  await shot(page, `${G}/04-assign-training`, { selector: '.offcanvas.show' });
  await closeSheet(page);

  // 05 Extend and 06 Waive
  await goto(page, '/agent/training_assignments.php?status=overdue');
  await page.locator('#tro-a-table tbody tr:first-child .dropdown-toggle').click();
  await page.locator('.dropdown-menu.show .dropdown-item', { hasText: 'Extend' }).click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(500);
  const reason = page.locator('.offcanvas.show textarea, .offcanvas.show input[type=text]').first();
  await reason.fill('Waiting for the trainer to reopen the plant training room.').catch(() => {});
  await shot(page, `${G}/05-extend-due-date`, { selector: '.offcanvas.show' });
  await closeSheet(page);

  await page.locator('#tro-a-table tbody tr:nth-child(3) .dropdown-toggle').click();
  await page.locator('.dropdown-menu.show .dropdown-item', { hasText: 'Waive' }).click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(500);
  await page.locator('.offcanvas.show textarea, .offcanvas.show input[type=text]').first().fill('On medical leave until the end of the quarter.').catch(() => {});
  await shot(page, `${G}/06-waive`, { selector: '.offcanvas.show' });
  await closeSheet(page);

  // 07 History
  await page.locator('#tro-a-table tbody tr:first-child .dropdown-toggle').click();
  await page.locator('.dropdown-menu.show .dropdown-item', { hasText: 'History' }).click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(1200);
  await shot(page, `${G}/07-assignment-history`, { selector: '.offcanvas.show' });
  await closeSheet(page);

  // 08 Rules list, 09 rule editor
  await goto(page, '/agent/training_assignments.php?tab=rules');
  await need(page, '#tro-r-table tbody tr', 'rules');
  await shot(page, `${G}/08-rules-list`);
  const ruleId = await page.evaluate(() => {
    const a = [...document.querySelectorAll('#tro-r-table a[href*="training_rule.php?id="]')].find((x) => /Workplace Safety/.test(x.closest('tr').textContent));
    return a ? new URL(a.href).searchParams.get('id') : null;
  });
  if (!ruleId) fail('rule link not found');
  await goto(page, `/agent/training_rule.php?id=${ruleId}`);
  await page.waitForTimeout(1200);
  await expectNoError(page, 'rule editor');
  await callout(page, [{ selector: '#tro-rule-course', n: 1 }, { selector: '#tro-rule-conds', n: 2 }, { selector: '#tro-rule-preview', n: 3, side: 'tr' }]);
  await shot(page, `${G}/09-rule-editor`);
  await clearCallouts(page);

  // 10 Records log and 11 one record
  await goto(page, '/agent/training_records.php');
  await need(page, '#tro-rec-table tbody tr', 'records');
  await callout(page, [{ selector: '#tro-rec-q', n: 1 }, { selector: '#tro-rec-method', n: 2 }, { selector: '#tro-rec-strength', n: 3 }, { selector: '#tro-rec-external', n: 4, side: 'tr' }]);
  await shot(page, `${G}/10-records-log`);
  await clearCallouts(page);

  const list = await jsonGet(page, 'completion_list', { q: 'Carter' });
  const rows = (list.data && list.data.rows) || list.rows || [];
  const kioskRow = rows.find((r) => r.course && /Workplace Safety/.test(r.course.name));
  if (!kioskRow) fail('Ben Carter record not found');
  await goto(page, `/agent/training_record.php?id=${kioskRow.id}`);
  await expectNoError(page, 'record');
  await page.locator('details.tro-details summary').click();
  await page.waitForTimeout(600);
  const verifyText = await page.locator('.tro-copy').first().innerText();
  const token = (verifyText.match(/[?&]t=([A-Za-z0-9_-]{24})/) || [])[1];
  if (!token) fail('verification link not found on the record page');
  await shot(page, `${G}/11-record-detail`);

  // 12 External / paper record form
  await goto(page, '/agent/training_records.php');
  await page.locator('#tro-rec-external').click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(700);
  await shot(page, `${G}/12-record-external-form`, { selector: '.offcanvas.show' });
  await closeSheet(page);

  // 13 Sessions tab
  await goto(page, '/agent/training_records.php?tab=sessions');
  await page.waitForTimeout(800);
  await shot(page, `${G}/13-sessions-list`);

  // 14 Transcript
  const people = await jsonGet(page, 'people_search', { q: 'Aisha' });
  const pr = (people.data && (people.data.people || people.data.rows)) || people.people || people.rows || [];
  const aisha = pr.find((p) => /Aisha/.test(p.name || ''));
  if (!aisha) fail('Aisha Rahman not found');
  await goto(page, `/agent/training_transcript.php?contact_id=${aisha.contact_id || aisha.id}`);
  await expectNoError(page, 'transcript');
  await shot(page, `${G}/14-transcript`);

  // 15-17 Reports
  await goto(page, '/agent/training_reports.php');
  await expectNoError(page, 'matrix');
  await callout(page, [{ selector: '.trr-filter__select >> nth=0', n: 1 }, { selector: '.trr-cell >> nth=8', n: 2 }]);
  await shot(page, `${G}/15-report-matrix`);
  await clearCallouts(page);
  await goto(page, '/agent/training_reports.php?tab=overdue');
  await shot(page, `${G}/16-report-overdue`);
  await goto(page, '/agent/training_reports.php?tab=documents');
  await shot(page, `${G}/17-report-documents`);

  // 18 People roster
  await goto(page, '/agent/training_people.php');
  await need(page, '#tro-pr-q', 'roster search');
  await shot(page, `${G}/18-people-roster`);

  // ===================================================================== page 08b: devices, kiosk, certificates
  await goto(page, '/agent/training_devices.php');
  await expectNoError(page, 'devices');
  await need(page, '.btn', 'device buttons');
  await callout(page, [{ selector: 'a[href="/agent/training_device_setup.php"]', n: 1, side: 'tr' }, { selector: 'a[href="/agent/training_device_bulk.php"]', n: 2 }]);
  await shot(page, `${G}/20-devices`);
  await clearCallouts(page);
  await page.locator('button.nav-link', { hasText: 'People & PINs' }).click();
  await page.waitForTimeout(1200);
  await callout(page, [{ selector: '#tr-people-slips', n: 1, side: 'tr' }]);
  await shot(page, `${G}/21-people-and-pins`);
  await clearCallouts(page);

  await goto(page, '/agent/training_device_setup.php');
  await expectNoError(page, 'device setup');
  await shot(page, `${G}/22-device-setup`);

  // Kiosk: a browser that is not set up (its own context, no cookies)
  const bare = await browser.newContext({ viewport: { width: 1180, height: 820 }, colorScheme: 'light' });
  const bp = await bare.newPage();
  await bp.goto(`${KIOSK}/kiosk/`, { waitUntil: 'domcontentloaded' });
  await settle(bp, 900);
  await shot(bp, `${G}/23-kiosk-not-set-up`);
  await bare.close();

  // Kiosk: set up device, name search, PIN pad
  const dev = await browser.newContext({ viewport: { width: 1180, height: 820 }, colorScheme: 'light' });
  const kp = await dev.newPage();
  kp.setDefaultTimeout(20000);
  await kp.goto(`${KIOSK}/kiosk/#d=${DEVICE_TOKEN}`, { waitUntil: 'domcontentloaded' });
  await settle(kp, 2500);
  await kp.fill('input[type=search], input[type=text]', 'sul');
  await settle(kp, 1200);
  await callout(kp, [{ selector: 'input[type=search], input[type=text]', n: 1 }, { selector: 'button:has-text("Sullivan"), a:has-text("Sullivan")', n: 2, side: 'tr' }]);
  await shot(kp, `${G}/24-kiosk-name-search`);
  await clearCallouts(kp);
  await kp.locator('button, a').filter({ hasText: /Sullivan/ }).first().click();
  await settle(kp, 800);
  for (const d of '48151') { await kp.locator('button', { hasText: new RegExp('^' + d + '$') }).first().click(); await kp.waitForTimeout(100); }
  await shot(kp, `${G}/25-kiosk-pin`);
  await dev.close();

  // Learner: Jake (overdue, in progress) and Tara (finished one course, exam open)
  const jctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, colorScheme: 'light' });
  const jp = await kioskSignIn(jctx, 'jake', /Sullivan/);
  await need(jp, 'a[href*="course.php?c="]', 'course cards');
  await callout(jp, [{ selector: 'a[href*="course.php?c="] >> nth=0', n: 1 }]);
  await shot(jp, `${G}/26-kiosk-my-training-jake`, { fullPage: true });
  await clearCallouts(jp);
  await jctx.close();

  const tctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, colorScheme: 'light' });
  const tp = await kioskSignIn(tctx, 'tara', /Whitfield/);
  await shot(tp, `${G}/27-kiosk-my-training-tara`, { fullPage: true });
  await tp.goto(`${KIOSK}/kiosk/course.php?c=3`, { waitUntil: 'domcontentloaded' });
  await settle(tp, 2000);
  await shot(tp, `${G}/28-kiosk-course`, { fullPage: true });
  await tp.locator('button', { hasText: /What HazCom covers/ }).first().click();
  await settle(tp, 2500);
  await shot(tp, `${G}/29-kiosk-lesson`);
  await tp.goto(`${KIOSK}/kiosk/course.php?c=3`, { waitUntil: 'domcontentloaded' });
  await settle(tp, 2000);
  await tp.locator('button', { hasText: /Continue: HazCom exam/ }).first().click();
  await settle(tp, 2500);
  await shot(tp, `${G}/30-kiosk-exam-start`);
  await tp.locator('button', { hasText: /Start the exam|Continue the exam|Resume/ }).first().click();
  await settle(tp, 2500);
  await shot(tp, `${G}/31-kiosk-exam-question`);
  await tctx.close();

  // Trainer sign-in screen (name search, trainers only)
  const rctx = await browser.newContext({ viewport: { width: 1180, height: 820 }, colorScheme: 'light' });
  const rp = await rctx.newPage();
  await rp.goto(`${KIOSK}/kiosk/#d=${DEVICE_TOKEN}`, { waitUntil: 'domcontentloaded' });
  await settle(rp, 2500);
  await rp.locator('button, a').filter({ hasText: 'Trainer sign-in' }).first().click();
  await settle(rp, 800);
  await rp.fill('input[type=search], input[type=text]', 'carl');
  await settle(rp, 1200);
  await shot(rp, `${G}/32-kiosk-trainer-signin`);
  await rctx.close();

  // Certificates: the agent's printable sheet, and the public check (no login, no cookies)
  await goto(page, `/agent/training_certificate.php?id=${kioskRow.id}`);
  await shot(page, `${G}/33-certificate`);
  const pub = await browser.newContext({ viewport: { width: 900, height: 760 }, colorScheme: 'light' });
  const vp = await pub.newPage();
  await vp.goto(`${BASE}/verify/?t=${token}`, { waitUntil: 'domcontentloaded' });
  await settle(vp, 500);
  if (!(await vp.locator('.tv-card--valid').count())) fail('verify page did not show a valid certificate');
  await shot(vp, `${G}/34-verify-valid`);
  await vp.goto(`${BASE}/verify/?t=AAAAAAAAAAAAAAAAAAAAAAAA`, { waitUntil: 'domcontentloaded' });
  await settle(vp, 500);
  await shot(vp, `${G}/35-verify-not-found`);
  await pub.close();

  // Awarded badges
  await goto(page, '/agent/training_awards.php');
  await expectNoError(page, 'awards');
  await shot(page, `${G}/36-awarded-badges`);

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
