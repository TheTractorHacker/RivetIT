// Screenshots for 08-training-assignments-and-records.md (figures 01-16) and
// 08b-training-kiosks-learners-certificates.md (figures 17-34).
//
//   cd <repo> && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/training-delivery.cjs
//
// Needs seed/50-training-authoring.php and seed/55-training-delivery.php applied.
//
// Agent pages are captured through BASE (default http://127.0.0.1:8080). The training KIOSK checks the browser's Origin
// against config_base_url ("localhost:8080" on the demo server), so the kiosk part opens http://localhost:8080 instead
// (override with KIOSK_URL). Nothing here saves a form: the offcanvas forms are opened, filled with illustrative values
// and closed. The kiosk part signs in with the demo training PIN (ordinary kiosk sign-in rows) and only reads course pages;
// the exam that is opened was started by the seed and is left unanswered.
//
// Keep in step with seed/55-training-delivery.php: DEVICE_TOKEN and PIN below are its fixed values. The seed also
// revokes a third device ("Old Loading Dock iPad", shown with a tick box on the Devices tab) and switches a few
// company-wide Knowledge Base articles on for the kiosk (the learner's Knowledge base page).

const { launch, login, goto, shot, callout, clearCallouts, settle, BASE } = require('../lib.cjs');

const G = 'training-delivery';
const KIOSK = (process.env.KIOSK_URL || 'http://localhost:8080').replace(/\/$/, '');
const DEVICE_TOKEN = 'ugdeliveryfabshopipadtoken00000000000001abc';
const PIN = '481516';
const KIOSK_VIEW = { width: 1180, height: 820 };

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

// Screenshot of the open side sheet, with the window sized so the sheet is not mostly empty space.
async function sheetShot(page, name, height) {
  await page.setViewportSize({ width: 1440, height });
  await page.waitForTimeout(400);
  await shot(page, name, { selector: '.offcanvas.show' });
  await page.setViewportSize({ width: 1440, height: 900 });
}

async function jsonGet(page, action, params) {
  const qs = new URLSearchParams({ action, ...params }).toString();
  return page.evaluate(async (u) => (await fetch(u, { headers: { Accept: 'application/json' } })).json(), `/agent/training_ajax.php?${qs}`);
}

async function kioskSignIn(browser, name, pattern) {
  const context = await browser.newContext({ viewport: KIOSK_VIEW, colorScheme: 'light' });
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
  if (!/me\.php/.test(page.url())) fail(`Kiosk sign-in for ${name} did not reach My training (${page.url()})`);
  return { context, page };
}

(async () => {
  const { browser, page } = await launch();
  await login(page, 'admin');

  // ============================================================ page 08: assignments, records, reports
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
    { selector: '#tro-a-table tbody tr:first-child .tro-actions button[data-bs-toggle=dropdown]', n: 4, side: 'tr' }, { selector: '#tro-assign', n: 5 }]);
  await shot(page, `${G}/02-assignments-list`);
  await clearCallouts(page);

  // 03 Assign training (side sheet)
  await goto(page, '/agent/training_assignments.php');
  await page.locator('#tro-assign').click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(600);
  const pick = page.locator('.offcanvas.show input[type=search], .offcanvas.show input[type=text]').first();
  const option = page.locator('.offcanvas.show [role=option], .offcanvas.show .tro-picker__item, .offcanvas.show li, .offcanvas.show button');
  await pick.fill('Maya');
  await page.waitForTimeout(900);
  await option.filter({ hasText: /Maya Singh/ }).first().click();
  await pick.fill('Zoe');
  await page.waitForTimeout(900);
  await option.filter({ hasText: /Zoe Hartman/ }).first().click();
  const hazcom = await page.locator('.offcanvas.show select option').evaluateAll((os) => (os.find((o) => /^Hazard Communication/.test(o.textContent)) || {}).value);
  if (!hazcom) fail('HazCom course option not found in Assign training');
  await page.locator('.offcanvas.show select').first().selectOption(hazcom);
  await page.waitForTimeout(500);
  await sheetShot(page, `${G}/03-assign-training`, 660);
  await closeSheet(page);

  // 04 Extend, 05 Waive, 06 History (overdue rows)
  await goto(page, '/agent/training_assignments.php?status=overdue');
  await need(page, '#tro-a-table tbody tr', 'overdue rows');
  const kebab = (n) => page.locator(`#tro-a-table tbody tr:nth-child(${n}) .tro-actions button[data-bs-toggle=dropdown]`);
  await kebab(1).click();
  await page.locator('.dropdown-menu.show .dropdown-item', { hasText: 'Extend' }).click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(500);
  await page.locator('.offcanvas.show textarea, .offcanvas.show input[type=text]').first().fill('Waiting for the trainer to reopen the plant training room.');
  await sheetShot(page, `${G}/04-extend-due-date`, 560);
  await closeSheet(page);

  await kebab(3).click();
  await page.locator('.dropdown-menu.show .dropdown-item', { hasText: 'Waive' }).click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(500);
  await page.locator('.offcanvas.show textarea, .offcanvas.show input[type=text]').first().fill('On medical leave until the end of the quarter.');
  await sheetShot(page, `${G}/05-waive`, 640);
  await closeSheet(page);

  await kebab(1).click();
  await page.locator('.dropdown-menu.show .dropdown-item', { hasText: 'History' }).click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(1200);
  await sheetShot(page, `${G}/06-assignment-history`, 620);
  await closeSheet(page);

  // 07 Rules list, 08 rule editor
  await goto(page, '/agent/training_assignments.php?tab=rules');
  await need(page, '#tro-r-table tbody tr', 'rules');
  await shot(page, `${G}/07-rules-list`);
  const ruleId = await page.evaluate(() => {
    const a = [...document.querySelectorAll('#tro-r-table a[href*="training_rule.php?id="]')].find((x) => /Workplace Safety/.test(x.closest('tr').textContent));
    return a ? new URL(a.href).searchParams.get('id') : null;
  });
  if (!ruleId) fail('rule link not found');
  await goto(page, `/agent/training_rule.php?id=${ruleId}`);
  await page.waitForTimeout(1200);
  await expectNoError(page, 'rule editor');
  await callout(page, [{ selector: '#tro-rule-course', n: 1 }, { selector: '#tro-rule-conds', n: 2 }, { selector: '#tro-rule-preview', n: 3, side: 'tr' }]);
  await shot(page, `${G}/08-rule-editor`);
  await clearCallouts(page);

  // 09 Records log, 10 one record, 11 external / paper record form
  await goto(page, '/agent/training_records.php');
  await need(page, '#tro-rec-table tbody tr', 'records');
  await callout(page, [{ selector: '#tro-rec-q', n: 1 }, { selector: '#tro-rec-method', n: 2 }, { selector: '#tro-rec-strength', n: 3 }, { selector: '#tro-rec-external', n: 4, side: 'tr' }]);
  await shot(page, `${G}/09-records-log`);
  await clearCallouts(page);

  const list = await jsonGet(page, 'completion_list', { q: 'Carter' });
  const rows = (list.data && list.data.rows) || list.rows || [];
  const kioskRow = rows.find((r) => r.course && /Workplace Safety/.test(r.course.name));
  if (!kioskRow) fail('Ben Carter record not found');
  await goto(page, `/agent/training_record.php?id=${kioskRow.id}`);
  await expectNoError(page, 'record');
  await page.locator('details.tro-details summary').click();
  await page.waitForTimeout(600);
  const verifyText = await page.locator('input[aria-label="Verification link"]').first().inputValue();
  const token = (verifyText.match(/[?&]t=([A-Za-z0-9_-]{24})/) || [])[1];
  if (!token) fail('verification link not found on the record page');
  await shot(page, `${G}/10-record-detail`);

  await goto(page, '/agent/training_records.php');
  await page.locator('#tro-rec-external').click();
  await page.waitForSelector('.offcanvas.show');
  await page.waitForTimeout(700);
  await sheetShot(page, `${G}/11-record-external-form`, 1150);
  await closeSheet(page);

  // 12 Sessions tab
  await goto(page, '/agent/training_records.php?tab=sessions');
  await page.waitForTimeout(800);
  await need(page, 'table tbody tr', 'session rows');
  await shot(page, `${G}/12-sessions-list`);

  // 13 Transcript
  const people = await jsonGet(page, 'people_search', { q: 'Aisha' });
  const pr = (people.data && (people.data.people || people.data.rows)) || people.people || people.rows || [];
  const aisha = pr.find((p) => /Aisha/.test(p.name || ''));
  if (!aisha) fail('Aisha Rahman not found');
  await goto(page, `/agent/training_transcript.php?contact_id=${aisha.contact_id || aisha.id}`);
  await expectNoError(page, 'transcript');
  await shot(page, `${G}/13-transcript`);

  // 14 Compliance matrix, 15 Overdue report
  await goto(page, '/agent/training_reports.php');
  await expectNoError(page, 'matrix');
  await need(page, '.trr-cell', 'matrix cells');
  await page.evaluate(() => { const s = document.querySelector('.trr-filter__select'); if (s && s.parentElement) s.parentElement.setAttribute('data-ug', 'filter'); });
  await callout(page, [{ selector: '[data-ug=filter]', n: 1 }, { selector: '.trr-cell', n: 2 }]);
  await shot(page, `${G}/14-report-matrix`);
  await clearCallouts(page);
  await goto(page, '/agent/training_reports.php?tab=overdue');
  await expectNoError(page, 'overdue report');
  await shot(page, `${G}/15-report-overdue`);

  // 16 People roster
  await goto(page, '/agent/training_people.php');
  await need(page, '#tro-pr-q', 'roster search');
  await shot(page, `${G}/16-people-roster`);

  // ============================================================ page 08b: devices, kiosk, certificates
  // 17 Devices, 18 People & PINs, 19 Set up this device
  await goto(page, '/agent/training_devices.php');
  await expectNoError(page, 'devices');
  await need(page, 'a[href="/agent/training_device_setup.php"]', 'Set up a device button');
  await need(page, '#tr-device-list input[type=checkbox]', 'tick box on the revoked device');
  await page.locator('#tr-device-list input[type=checkbox]').first().check();   // client-side only: nothing is removed
  await page.waitForTimeout(300);
  await page.evaluate(() => {
    const cb = document.querySelector('#tr-device-list input[type=checkbox]');
    if (cb) cb.setAttribute('data-ug', 'revoked-pick');
  });
  await callout(page, [{ selector: 'a[href="/agent/training_device_setup.php"]', n: 1 }, { selector: 'a[href="/agent/training_device_bulk.php"]', n: 2 },
    { selector: '[data-ug=revoked-pick]', n: 3 }, { selector: '#tr-device-bulk-remove', n: 4 }]);
  await shot(page, `${G}/17-devices`);
  await clearCallouts(page);
  await page.locator('button.nav-link', { hasText: 'People & PINs' }).click();
  await page.waitForTimeout(1200);
  await callout(page, [{ selector: '#tr-people-slips', n: 1, side: 'tr' }]);
  await shot(page, `${G}/18-people-and-pins`);
  await clearCallouts(page);

  await goto(page, '/agent/training_device_setup.php');
  await expectNoError(page, 'device setup');
  await shot(page, `${G}/19-device-setup`);

  // 32 Get setup codes (several devices at once); nothing is issued, the form is only looked at
  await goto(page, '/agent/training_device_bulk.php');
  await expectNoError(page, 'device setup codes');
  await need(page, '#trb-go', 'Get setup codes button');
  await callout(page, [{ selector: '#trb-names', n: 1 }, { selector: '#trb-dept', n: 2 }, { selector: '#trb-go', n: 3 }]);
  await shot(page, `${G}/32-device-bulk-codes`, { fullPage: true });
  await clearCallouts(page);

  // 20 A browser that is not set up (its own context, no cookies)
  const bare = await browser.newContext({ viewport: KIOSK_VIEW, colorScheme: 'light' });
  const bp = await bare.newPage();
  await bp.goto(`${KIOSK}/kiosk/`, { waitUntil: 'domcontentloaded' });
  await settle(bp, 900);
  await bp.evaluate(() => {
    for (const el of document.querySelectorAll('a, button')) {
      if (/Enter a setup code/.test(el.textContent)) el.setAttribute('data-ug', 'code');
      if (/Set up this device/.test(el.textContent)) el.setAttribute('data-ug', 'admin');
    }
  });
  await callout(bp, [{ selector: '[data-ug=admin]', n: 1 }, { selector: '[data-ug=code]', n: 2, side: 'tr' }]);
  await shot(bp, `${G}/20-kiosk-not-set-up`);
  await clearCallouts(bp);
  await bare.close();

  // 21 name search, 22 PIN keypad
  const dev = await browser.newContext({ viewport: KIOSK_VIEW, colorScheme: 'light' });
  const kp = await dev.newPage();
  kp.setDefaultTimeout(20000);
  await kp.goto(`${KIOSK}/kiosk/#d=${DEVICE_TOKEN}`, { waitUntil: 'domcontentloaded' });
  await settle(kp, 2500);
  await kp.fill('input[type=search], input[type=text]', 'sul');
  await settle(kp, 1200);
  await kp.evaluate(() => {
    const el = [...document.querySelectorAll('button, a')].find((e) => /Sullivan/.test(e.textContent));
    if (el) el.setAttribute('data-ug', 'hit');
    const tr = [...document.querySelectorAll('button, a')].find((e) => /Trainer sign-in/.test(e.textContent));
    if (tr) tr.setAttribute('data-ug', 'trainer');
  });
  await callout(kp, [{ selector: 'input[type=search], input[type=text]', n: 1 }, { selector: '[data-ug=hit]', n: 2, side: 'tr' }, { selector: '[data-ug=trainer]', n: 3, side: 'tr' }]);
  await shot(kp, `${G}/21-kiosk-name-search`);
  await clearCallouts(kp);
  await kp.locator('button, a').filter({ hasText: /Sullivan/ }).first().click();
  await settle(kp, 800);
  for (const d of '48151') { await kp.locator('button', { hasText: new RegExp('^' + d + '$') }).first().click(); await kp.waitForTimeout(100); }
  await shot(kp, `${G}/22-kiosk-pin`);
  await dev.close();

  // 23 My training as Jake (overdue, in progress), 24 as Tara (a finished course, a certificate, an open exam)
  const jake = await kioskSignIn(browser, 'jake', /Sullivan/);
  await need(jake.page, 'a[href*="course.php?c="]', 'course cards');
  await need(jake.page, 'a[href*="kb.php"]', 'Knowledge base tile (KB module on)');
  await callout(jake.page, [{ selector: 'a[href*="course.php?c="]', n: 1 }, { selector: 'a[href*="kb.php"]', n: 2 }]);
  await shot(jake.page, `${G}/23-kiosk-my-training-jake`, { fullPage: true });
  await clearCallouts(jake.page);
  await jake.context.close();

  const tara = await kioskSignIn(browser, 'tara', /Whitfield/);
  const tp = tara.page;
  await need(tp, 'a[href*="certificate.php"]', 'certificate link');
  await callout(tp, [{ selector: 'a[href*="certificate.php"]', n: 1 }]);
  await shot(tp, `${G}/24-kiosk-my-training-tara`, { fullPage: true });
  await clearCallouts(tp);

  // 33 the learner's Knowledge base (only articles switched on with "Show on Training Portal")
  await tp.goto(`${KIOSK}/kiosk/kb.php`, { waitUntil: 'domcontentloaded' });
  await settle(tp, 1200);
  if (!/kb\.php/.test(tp.url())) fail('Knowledge base page did not open on the kiosk');
  await shot(tp, `${G}/33-kiosk-knowledge-base`, { fullPage: true });

  // 25 course overview (taller window so the lesson list shows), 26 a lesson, 27 the exam
  await tp.setViewportSize({ width: 1180, height: 1250 });
  await tp.goto(`${KIOSK}/kiosk/course.php?c=3`, { waitUntil: 'domcontentloaded' });
  await settle(tp, 2000);
  await shot(tp, `${G}/25-kiosk-course`);
  await tp.setViewportSize(KIOSK_VIEW);
  await tp.locator('button', { hasText: /What HazCom covers/ }).first().click();
  await settle(tp, 2500);
  await shot(tp, `${G}/26-kiosk-lesson`);
  await tp.goto(`${KIOSK}/kiosk/course.php?c=3`, { waitUntil: 'domcontentloaded' });
  await settle(tp, 2000);
  await tp.locator('button', { hasText: /Continue: HazCom exam/ }).first().click();
  await settle(tp, 2500);
  await tp.locator('button', { hasText: /Start the exam|Continue the exam|Resume/ }).first().click();
  await settle(tp, 2500);
  await shot(tp, `${G}/27-kiosk-exam-question`);
  await tara.context.close();

  // 28 the printable certificate, 29 / 30 the public check (no login, no cookies)
  await goto(page, `/agent/training_certificate.php?id=${kioskRow.id}`);
  await shot(page, `${G}/28-certificate`);
  const pub = await browser.newContext({ viewport: { width: 900, height: 760 }, colorScheme: 'light' });
  const vp = await pub.newPage();
  await vp.goto(`${BASE}/verify/?t=${token}`, { waitUntil: 'domcontentloaded' });
  await settle(vp, 500);
  if (!(await vp.locator('.tv-card--valid').count())) fail('verify page did not show a valid certificate');
  await shot(vp, `${G}/29-verify-valid`);
  await vp.goto(`${BASE}/verify/?t=AAAAAAAAAAAAAAAAAAAAAAAA`, { waitUntil: 'domcontentloaded' });
  await settle(vp, 500);
  await shot(vp, `${G}/30-verify-not-found`);
  await pub.close();

  // 31 Awarded badges
  await goto(page, '/agent/training_awards.php');
  await expectNoError(page, 'awards');
  await need(page, 'table tbody tr, .tr-awards, [class*=award]', 'awards');
  await shot(page, `${G}/31-awarded-badges`);

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
