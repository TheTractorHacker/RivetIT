// Screenshots for docs/user-guide/07-training-courses-and-content.md and 07b-training-quizzes-paths-and-badges.md.
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/training-authoring.cjs
//
// Needs the 50-training-authoring seed. READ-ONLY: it opens pages, dialogs and panels and closes them
// again; nothing is saved. Ids are looked up by course / lesson title (through the app's own JSON
// endpoints), never hard-coded. Fails (non-zero exit) on a PHP error or a missing element.
//
// The Training overview shows compliance numbers only once assignment rules exist (a later seed);
// it is not captured here.

const { launch, login, goto, shot, callout, clearCallouts, settle, BASE } = require('../lib.cjs');

const G = 'training-authoring';

(async () => {
  const { browser, page } = await launch();
  page.on('pageerror', (e) => { throw new Error('Browser error: ' + e.message); });

  // ---- helpers -------------------------------------------------------------------------------------------
  async function noPhpError(where) {
    const bad = await page.evaluate(() => /(Fatal error|Warning:|Notice:|Uncaught|Stack trace)/.test(document.body.innerText));
    if (bad) throw new Error('PHP error text on ' + where);
  }
  async function must(selector, where, timeout = 20000) {
    try {
      await page.waitForSelector(selector, { timeout });
    } catch (e) {
      throw new Error(`Expected ${selector} on ${where}`);
    }
  }
  async function api(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params }).toString();
    const r = await page.evaluate(async (u) => (await fetch(u, { credentials: 'same-origin' })).json(), `/agent/training_ajax.php?${qs}`);
    if (!r.ok) throw new Error(`API ${action} failed: ${JSON.stringify(r.error)}`);
    return r.data;
  }
  async function open(url, readySelector, extraMs = 1200) {
    await goto(page, url);
    await must(readySelector, url);
    await settle(page, extraMs);
    await noPhpError(url);
  }
  async function closeModal(sel) {
    await page.click(`${sel} [data-bs-dismiss="modal"]`);
    await page.waitForFunction((s) => !document.querySelector(s + '.show'), sel);
    await page.waitForTimeout(500);
  }

  await login(page, 'admin');

  // ---- look up ids by title -------------------------------------------------------------------------------
  await goto(page, '/agent/training_courses.php');
  const list = (await api('course_list', { status: 'all' })).courses;
  const byName = (n) => {
    const c = list.find((x) => x.name === n);
    if (!c) throw new Error('Course missing (run the 50-training-authoring seed): ' + n);
    return c.id;
  };
  const cyber = byName('Cybersecurity Awareness');
  const forklift = byName('Forklift Safety Refresher');
  const coc = byName('Code of Conduct & Ethics');
  const policy = byName('IT Acceptable Use Policy');
  const cyberDetail = await api('course_get', { course_id: cyber });
  const examLesson = cyberDetail.lessons.find((l) => l.type === 'quiz').id;

  // ---- 01 Courses list ------------------------------------------------------------------------------------------
  await open('/agent/training_courses.php', '.tr-course-card');
  if ((await page.locator('.tr-course-card').count()) < 5) throw new Error('Course list looks empty');
  await callout(page, [
    { selector: '.btn-group [data-tr-new="training"]', n: 1, side: 'tl' },
    { selector: '#tr-tpl-strip', n: 2 },
    { selector: '#tr-stats', n: 3 },
    { selector: '.tr-filters__row', n: 4 },
    { selector: '.tr-course-card', n: 5 },
  ]);
  await shot(page, `${G}/01-courses-list`);
  await clearCallouts(page);

  // ---- 02 New course window (Safety starters tab), 03 category manager ---------------------------------------------
  await page.click('button.btn-primary[data-tr-new="training"]');
  await must('#tr-new-course.show', 'new course window');
  await settle(page, 800);
  await page.click('#tr-nc-tab-safety');
  await page.waitForTimeout(700);
  await shot(page, `${G}/02-new-course`);
  await closeModal('#tr-new-course');

  await page.click('#tr-cat-manage');
  await page.waitForTimeout(1200);
  await settle(page, 600);
  await shot(page, `${G}/03-category-manager`);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(600);

  // ---- 04 Course builder (Content tab) -----------------------------------------------------------------------------
  await open(`/agent/training_course.php?course_id=${cyber}`, '#tr-outline .tr-lesson__title', 1800);
  await callout(page, [
    { selector: '#tr-preview-btn', n: 1, side: 'tr' },
    { selector: '#tr-publish-btn', n: 2, side: 'tr' },
    { selector: '#tr-tabs', n: 3 },
    { selector: '#tr-outline .tr-section', n: 4 },
  ]);
  await shot(page, `${G}/04-course-builder`);
  await clearCallouts(page);

  // ---- 05, 06 Content window: article and quick check -------------------------------------------------------------------
  await page.locator('.tr-lesson__title', { hasText: 'Spotting phishing emails' }).click();
  await must('#tr-cm.show', 'content window');
  await settle(page, 2000);
  await shot(page, `${G}/05-content-editor`);
  await page.click('#tr-cm-tab-quiz');
  await settle(page, 1500);
  await shot(page, `${G}/06-quick-check`);
  await page.click('#tr-cm-done');
  await page.waitForFunction(() => !document.querySelector('#tr-cm.show'));
  await page.waitForTimeout(600);

  // ---- 07 Settings tab -------------------------------------------------------------------------------------------------------
  await page.click('#tr-tab-settings');
  await settle(page, 1500);
  await page.setViewportSize({ width: 1440, height: 1700 });
  await page.evaluate(() => window.scrollTo(0, 0));
  await settle(page, 800);
  await shot(page, `${G}/07-course-settings`);
  await page.setViewportSize({ width: 1440, height: 900 });

  // ---- 08 Completion rules (Forklift) -----------------------------------------------------------------------------------------
  await open(`/agent/training_course.php?course_id=${forklift}#settings`, '#tr-tab-settings', 1500);
  await page.click('#tr-tab-settings');
  await settle(page, 1200);
  await page.click('details[data-tr-card="completion"] summary');
  await page.waitForTimeout(700);
  await shot(page, `${G}/08-completion-rules`, { selector: 'details[data-tr-card="completion"]' });

  // ---- 09 Required document builder ------------------------------------------------------------------------------------------------
  await page.setViewportSize({ width: 1440, height: 1150 });
  await open(`/agent/training_course.php?course_id=${policy}`, '#tr-doc-step-ack-body', 1800);
  await shot(page, `${G}/09-required-document`);
  await page.setViewportSize({ width: 1440, height: 900 });

  // ---- 10 Versions tab, 11 Publish (ready), 13 Compare ---------------------------------------------------------------------------
  await open(`/agent/training_course.php?course_id=${cyber}`, '#tr-tab-versions', 1500);
  await page.click('#tr-tab-versions');
  await must('#tr-timeline-body .tr-btn, #tr-timeline-body button', 'versions timeline');
  await settle(page, 1200);
  await page.evaluate(() => window.scrollTo(0, 0));
  await shot(page, `${G}/13-versions`);

  await page.click('#tr-publish-btn');
  await must('#tr-pub.show', 'publish window');
  await must('#tr-pub-form:not([hidden])', 'publish readiness', 40000);
  await page.fill('#tr-pub-note', 'Added a Passkeys section to the passwords lesson.');
  await settle(page, 600);
  await shot(page, `${G}/11-publish-ready`);
  await closeModal('#tr-pub');

  await page.locator('button', { hasText: 'Compare to draft' }).first().click();
  await must('#tr-compare-panel:not([hidden])', 'compare panel');
  await settle(page, 1200);
  await shot(page, `${G}/14-compare-draft`);

  // ---- 12 Publish blocked (Code of Conduct draft) -----------------------------------------------------------------------------------------
  await open(`/agent/training_course.php?course_id=${coc}`, '#tr-publish-btn', 1500);
  await page.click('#tr-publish-btn');
  await must('#tr-pub.show', 'publish window (draft)');
  await must('#tr-pub-form:not([hidden])', 'publish readiness (draft)', 40000);
  await settle(page, 600);
  await shot(page, `${G}/12-publish-blocked`);
  await closeModal('#tr-pub');

  // ---- 14 Preview as learner -------------------------------------------------------------------------------------------------------------------------
  await open(`/agent/training_preview.php?course_id=${cyber}`, '#tr-player .trp-course, #tr-player button', 2500);
  await shot(page, `${G}/10-preview-learner`);

  // ---- 15 Quiz builder (final exam) --------------------------------------------------------------------------------------------------------------------
  await page.setViewportSize({ width: 1440, height: 1750 });
  await open(`/agent/training_quiz.php?lesson_id=${examLesson}`, '#tr-quiz-builder .trq-card', 2500);
  await shot(page, `${G}/15-quiz-builder`);
  await page.setViewportSize({ width: 1440, height: 900 });

  // ---- 16 Question Library --------------------------------------------------------------------------------------------------------------------------------
  await open('/agent/training_banks.php', '#trb-tree .trb-node__row', 1800);
  await callout(page, [
    { selector: '#trb-new', n: 1, side: 'tl' },
    { selector: '#trb-tree', n: 2 },
    { selector: '#trb-head', n: 3 },
    { selector: '#trb-questions', n: 4 },
  ]);
  await shot(page, `${G}/16-question-library`);
  await clearCallouts(page);

  // ---- 17, 18 Learning paths ------------------------------------------------------------------------------------------------------------------------------------
  await open('/agent/training_paths.php', '.tr-path-card', 1500);
  await shot(page, `${G}/17-learning-paths`);
  await page.locator('.tr-path-card__title').first().click();
  await must('#tr-path-editor.show', 'path editor');
  await settle(page, 1200);
  await shot(page, `${G}/18-path-editor`);
  await page.click('#tr-path-editor [data-bs-dismiss="offcanvas"]');
  await page.waitForTimeout(800);

  // ---- 19, 20 Achievements ------------------------------------------------------------------------------------------------------------------------------------------------
  await open('/agent/training_achievements.php', '.tr-ach-card', 1500);
  await shot(page, `${G}/19-achievements`);
  await page.locator('.tr-ach-card').first().click();
  await must('#tr-ach-panel:not([hidden])', 'achievement panel');
  await page.setViewportSize({ width: 1440, height: 1350 });
  await settle(page, 1000);
  await shot(page, `${G}/20-achievement-editor`);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.click('#tr-ach-cancel');
  await page.waitForTimeout(500);

  // ---- 21 Admin > Training settings ----------------------------------------------------------------------------------------------------------------------------------------------
  await open('/admin/settings_training.php', '#trSettingsForm', 1200);
  await callout(page, [
    { selector: '#tsNav', n: 1 },
    { selector: '#trPassPct', n: 2 },
    { selector: '#trAttempts', n: 3 },
  ]);
  await shot(page, `${G}/21-admin-settings`);
  await clearCallouts(page);

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
