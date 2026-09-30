// Screenshots for docs/user-guide/04-projects-and-calendar.md  (group: work)
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/work.cjs
//
// Needs the demo data from tools/seed/40-work.sql. The script is READ-ONLY: it opens pages, menus and
// pop-up forms, may type illustrative values into them, takes the picture and cancels. Nothing is saved.
// It exits non-zero if a page shows a PHP error, an access-denied page or an unexpectedly empty state.

const { launch, login, goto, shot, callout, clearCallouts, settle } = require('../lib.cjs');

const G = 'work';
const P_WIFI = 'Plant Floor Wi-Fi Upgrade';       // open, milestones, project tasks, linked tickets
const P_LAPTOP = 'Sales Team Laptop Refresh';      // open, created from the Device Refresh template
const P_FINANCE = 'Finance Laptop Disk Encryption'; // closed
const DEPT = 'Production';                          // department workspace used for the department shots

function fail(msg) {
  throw new Error(msg);
}

// ---- small helpers --------------------------------------------------------------------------------------

// Fail loudly on PHP errors, permission pages and "not found" pages.
async function checkPage(page, label) {
  const html = await page.content();
  if (/<b>(Fatal error|Warning|Notice|Parse error|Deprecated)<\/b>|Fatal error:|Uncaught (Error|Exception|mysqli)|Stack trace:/.test(html)) {
    fail(`${label}: PHP error on the page`);
  }
  const text = await page.evaluate(() => document.body.innerText);
  if (/You don't have access to this page|Nothing to see here|Page not found/.test(text)) {
    fail(`${label}: access denied or not found`);
  }
}

// Tag the first element that matches `css` (and contains `text`, if given) so callout() can target it.
async function mark(page, tag, css, text = null, within = null) {
  const ok = await page.evaluate(({ tag, css, text, within }) => {
    document.querySelectorAll(`[data-ug="${tag}"]`).forEach((e) => e.removeAttribute('data-ug'));
    const root = within ? document.querySelector(within) : document;
    if (!root) return false;
    const els = Array.from(root.querySelectorAll(css)).filter(
      (e) => !text || e.textContent.replace(/\s+/g, ' ').includes(text)
    );
    if (!els.length) return false;
    els[0].setAttribute('data-ug', tag);
    return true;
  }, { tag, css, text, within });
  if (!ok) fail(`mark(${tag}): nothing matches ${css}${text ? ` with text "${text}"` : ''}`);
  return `[data-ug="${tag}"]`;
}

async function hrefOf(page, css, text) {
  const href = await page.evaluate(({ css, text }) => {
    const a = Array.from(document.querySelectorAll(css)).find((x) => x.textContent.replace(/\s+/g, ' ').includes(text));
    return a ? a.getAttribute('href') : null;
  }, { css, text });
  if (!href) fail(`no link "${text}" (${css})`);
  return href.startsWith('/') ? href : `/agent/${href}`;
}

// Set a <select> in the open modal through its Tom Select instance (the app turns select.select2 into Tom Select).
async function pick(page, name, text, scope = '.modal.show') {
  const res = await page.evaluate(({ name, text, scope }) => {
    const sel = document.querySelector(`${scope} select[name="${name}"]`);
    if (!sel) return 'no such select';
    const opt = Array.from(sel.options).find((o) => o.textContent.trim().toLowerCase().includes(text.toLowerCase()));
    if (!opt) return 'no such option';
    if (sel.tomselect) sel.tomselect.setValue(opt.value);
    else {
      sel.value = opt.value;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    return 'ok';
  }, { name, text, scope });
  if (res !== 'ok') fail(`pick(${name}, ${text}): ${res}`);
}

const iso = (days, time = null) => {
  const d = new Date(Date.now() + days * 864e5);
  const day = d.toISOString().slice(0, 10);
  return time ? `${day}T${time}` : day;
};

async function openModal(page, clickSelector, label) {
  await page.click(clickSelector);
  await page.waitForSelector('.modal.show form', { timeout: 10000 }).catch(() => fail(`${label}: pop-up did not open`));
  await page.waitForTimeout(500);
}

async function closeModal(page) {
  await page.evaluate(() => document.activeElement && document.activeElement.blur());
  const cancel = page.locator('.modal.show button:has-text("Cancel")');
  if (await cancel.count()) await cancel.first().click();
  else await page.keyboard.press('Escape');
  await page.waitForSelector('.modal.show', { state: 'detached', timeout: 8000 }).catch(() => {});
  await page.waitForTimeout(300);
}

async function rowCount(page, selector = 'tbody tr') {
  return page.locator(selector).count();
}

// ---- the run --------------------------------------------------------------------------------------------

(async () => {
  const { browser, page } = await launch();
  try {
    await login(page, 'admin');

    // ================= Projects list =================
    await goto(page, '/agent/projects.php');
    await checkPage(page, 'projects list');
    if ((await rowCount(page)) < 3) fail('projects list: expected the seeded open projects');
    const wifiUrl = await hrefOf(page, 'tbody a[href*="project_details.php"]', P_WIFI);
    const laptopUrl = await hrefOf(page, 'tbody a[href*="project_details.php"]', P_LAPTOP);
    const deptUrl = await hrefOf(page, 'tbody a[href*="projects.php?client_id="]', DEPT); // Department column link
    const deptId = new URL(deptUrl, 'http://x').searchParams.get('client_id');

    await callout(page, [
      { selector: await mark(page, 'nav-projects', '#nav-group-work a.dropdown-item', 'Projects'), n: 1, side: 'tr' },
      { selector: await mark(page, 'new-project', '.card-tools button', 'New Project'), n: 2 },
      { selector: await mark(page, 'search', '.card-body > form .input-group'), n: 3 },
      { selector: await mark(page, 'status', '.btn-toolbar'), n: 4 },
      { selector: await mark(page, 'row-action', 'tbody tr td .dropdown > button'), n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/01-projects-list`);
    await clearCallouts(page);

    // ================= New Project pop-up (template chosen) =================
    await openModal(page, '.card-tools button:has-text("New Project")', 'New Project');
    await pick(page, 'client_id', 'Warehouse');
    await page.fill('.modal.show input[name=name]', 'Warehouse Laptop Refresh');
    await pick(page, 'project_template_id', 'Device Refresh');
    await page.fill('.modal.show input[name=description]', 'Replace the oldest laptops in Warehouse & Logistics.');
    await page.fill('.modal.show input[name=start_date]', iso(7));
    await page.fill('.modal.show input[name=due_date]', iso(45));
    await page.fill('.modal.show input[name=estimated_hours]', '40');
    await pick(page, 'project_manager', 'Priya');
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await shot(page, `${G}/02-new-project`, { selector: '.modal.show .modal-content' });
    await closeModal(page);

    // ================= Project page =================
    await goto(page, wifiUrl);
    await checkPage(page, 'project page');
    if (!(await page.locator('text=Site survey and design').count())) fail('project page: milestones missing');
    const kanbanUrl = await hrefOf(page, '.nav-pills a', 'Kanban');
    const ganttUrl = await hrefOf(page, '.nav-pills a', 'Gantt');
    await page.evaluate(() => { const ul = document.querySelector('ul.nav-pills'); if (ul) ul.style.width = 'fit-content'; });
    await callout(page, [
      { selector: await mark(page, 'tabs', 'ul.nav-pills'), n: 1 },
      { selector: await mark(page, 'btn-new', '.card-tools button', 'New'), n: 2 },
      { selector: await mark(page, 'btn-link', '.card-tools button', 'Link'), n: 3, side: 'tr' },
      { selector: await mark(page, 'btn-more', '.card-tools .dropdown.dropleft > button'), n: 4, side: 'tr' },
      { selector: await mark(page, 'progress', '.card-group > .card:nth-child(3)'), n: 5 },
    ]);
    await shot(page, `${G}/03-project-page`);
    await clearCallouts(page);

    // ---- milestones and tasks (element shot of the Milestones card)
    const msCard = await mark(page, 'ms-card', '.card', 'Milestones');
    // the second milestone holds an overdue task, a task at 60% and one at 25%
    const secondMs = await page.evaluate(() => {
      const blocks = Array.from(document.querySelectorAll('[data-ug="ms-card"] .card-body > .border-bottom'));
      const b = blocks.find((x) => x.textContent.includes('Install access points'));
      if (!b) return null;
      b.setAttribute('data-ug', 'ms2');
      return true;
    });
    if (!secondMs) fail('milestones card: "Install access points" block missing');
    await callout(page, [
      { selector: await mark(page, 'ms-head', '.d-flex.justify-content-between', null, '[data-ug="ms2"]'), n: 1, side: 'tr' },
      { selector: await mark(page, 'task-check', 'a[title="Mark complete"]', null, '[data-ug="ms2"] table'), n: 2 },
      { selector: await mark(page, 'task-due', '.badge.text-bg-light.border', null, '[data-ug="ms2"] table'), n: 3, side: 'br' },
      { selector: await mark(page, 'task-progress', '.badge.text-bg-info', null, '[data-ug="ms2"] table'), n: 4, side: 'br' },
      { selector: await mark(page, 'task-menu', '.dropdown > a', null, '[data-ug="ms2"] table'), n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/04-milestones-tasks`, { selector: msCard });
    await clearCallouts(page);

    // ---- New Task pop-up
    await page.locator('.card-tools button', { hasText: 'New' }).first().click();
    await page.locator('.dropdown-menu.show a', { hasText: 'Task' }).first().click();
    await page.waitForSelector('.modal.show form', { timeout: 10000 }).catch(() => fail('New Task pop-up did not open'));
    await page.waitForTimeout(500);
    await page.fill('.modal.show input[name=name]', 'Label every access point with its location');
    await pick(page, 'milestone_id', 'Install access points');
    await pick(page, 'assigned_to', 'Marcus');
    await page.fill('.modal.show input[name=start]', iso(3));
    await page.fill('.modal.show input[name=due]', iso(6));
    await page.fill('.modal.show input[name=completion_estimate]', '90');
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await shot(page, `${G}/05-new-task`, { selector: '.modal.show .modal-content' });
    await closeModal(page);

    // ================= Kanban =================
    await goto(page, kanbanUrl);
    await checkPage(page, 'kanban');
    for (const lane of ['To Do', 'In Progress', 'Blocked', 'Done']) {
      if (!(await page.locator(`.kanban-column[data-status="${lane}"] .task`).count())) fail(`kanban: lane "${lane}" is empty`);
    }
    await callout(page, [
      { selector: await mark(page, 'kb-tabs', 'ul.nav-pills'), n: 1 },
      { selector: await mark(page, 'kb-lane', '.kanban-column[data-status="In Progress"] .panel-title'), n: 2 },
      { selector: await mark(page, 'kb-card', '.kanban-column[data-status="Blocked"] .task'), n: 3 },
      { selector: await mark(page, 'kb-new', 'a.btn-primary', 'New Task'), n: 4 },
    ]);
    await shot(page, `${G}/06-project-kanban`);
    await clearCallouts(page);

    // ================= Gantt =================
    await goto(page, ganttUrl);
    await checkPage(page, 'gantt');
    await page.waitForSelector('#project-gantt svg .bar-wrapper', { timeout: 10000 }).catch(() => fail('gantt: no task bars'));
    await page.waitForTimeout(500);
    await callout(page, [
      { selector: await mark(page, 'g-modes', '.gantt-toolbar'), n: 1 },
      { selector: await mark(page, 'g-bar', '#project-gantt .bar-wrapper'), n: 2, side: 'tr' },
      { selector: await mark(page, 'g-ms', '#project-gantt .bar-milestone'), n: 3, side: 'tr' },
      { selector: await mark(page, 'g-today', '#project-gantt .today-line'), n: 4, side: 'tr' },
    ]);
    await shot(page, `${G}/07-project-gantt`);
    await clearCallouts(page);

    // ================= Project created from a template =================
    await goto(page, laptopUrl);
    await checkPage(page, 'template project');
    await callout(page, [
      { selector: await mark(page, 'tp-tickets', '.card', 'Project Tickets'), n: 1 },
      { selector: await mark(page, 'tp-tasks', '.card', 'Tasks', '.col-md-3'), n: 2 },
      { selector: await mark(page, 'tp-progress', '.card-group > .card:nth-child(3)'), n: 3 },
    ]);
    await shot(page, `${G}/08-template-project`, { selector: '.page-body .container-xl' });
    await clearCallouts(page);

    // ================= Administration: the project template =================
    await goto(page, '/admin/project_template.php');
    await checkPage(page, 'project templates');
    const tplUrl = await hrefOf(page, 'tbody a[href*="project_template_details.php"]', 'Device Refresh');
    await goto(page, tplUrl.replace('/agent/', '/admin/'));
    await checkPage(page, 'project template details');
    if ((await rowCount(page, '#ticket_templates tbody tr')) < 4) fail('project template: expected four ticket templates');
    await callout(page, [
      { selector: await mark(page, 'pt-add', 'button', 'Add Ticket Template'), n: 1, side: 'tr' },
      { selector: await mark(page, 'pt-tickets', '.card', 'Project Ticket Templates'), n: 2, side: 'tr' },
      { selector: await mark(page, 'pt-tasks', '.card', 'Project Task Templates'), n: 3 },
    ]);
    await shot(page, `${G}/09-project-template`, { selector: '.page-body .container-xl' });
    await clearCallouts(page);

    // ================= Close / archive =================
    await goto(page, '/agent/projects.php?status=1');
    await checkPage(page, 'closed projects');
    if (!(await page.locator('tbody tr', { hasText: P_FINANCE }).count())) fail('closed list: seeded closed project missing');
    await page.locator('tbody tr', { hasText: P_FINANCE }).locator('button[data-bs-toggle=dropdown]').click();
    await page.waitForSelector('.dropdown-menu.show');
    await callout(page, [
      { selector: await mark(page, 'cl-closed', '.btn-toolbar .btn-group:first-child a', 'Closed'), n: 1 },
      { selector: await mark(page, 'cl-action', 'tbody tr button[aria-expanded="true"]'), n: 2, side: 'tr' },
      { selector: await mark(page, 'cl-archive', '.dropdown-menu.show a', 'Archive'), n: 3, side: 'tr' },
    ]);
    await shot(page, `${G}/10-close-archive`);
    await clearCallouts(page);

    // ================= Department workspace =================
    await goto(page, `/agent/projects.php?client_id=${deptId}`);
    await checkPage(page, 'department projects');
    if ((await rowCount(page)) < 1) fail('department projects: none listed');
    await callout(page, [
      { selector: await mark(page, 'dp-nav', '.sidebar a.nav-link, aside a.nav-link', 'Projects'), n: 1, side: 'tr' },
      { selector: await mark(page, 'dp-head', '.content-wrapper .card, .page-wrapper .card', DEPT), n: 2 },
      { selector: await mark(page, 'dp-new', '.card-tools button', 'New Project'), n: 3 },
    ]);
    await shot(page, `${G}/11-department-projects`);
    await clearCallouts(page);

    // ================= Calendar: month (application level) =================
    await goto(page, '/agent/calendar.php');
    await checkPage(page, 'calendar');
    await page.waitForSelector('.fc-daygrid-day .fc-event', { timeout: 10000 }).catch(() => fail('calendar: no events drawn'));
    if (!(await page.locator('.fc-event', { hasText: 'IT Team Standup' }).count())) fail('calendar: seeded events missing');
    await callout(page, [
      { selector: await mark(page, 'c-cals', '.card', 'Calendars', '.col-md-3'), n: 1 },
      { selector: await mark(page, 'c-builtin', '.card', 'Built-in', '.col-md-3'), n: 2 },
      { selector: await mark(page, 'c-nav', '.fc-toolbar-chunk:first-child'), n: 3 },
      { selector: await mark(page, 'c-views', '.fc-toolbar-chunk:last-child .fc-button-group'), n: 4 },
      { selector: await mark(page, 'c-new', '.fc-newEvent-button'), n: 5, side: 'tr' },
    ]);
    await shot(page, `${G}/12-calendar-month`);
    await clearCallouts(page);

    // ================= Calendar: week view inside a department workspace =================
    await goto(page, `/agent/calendar.php?client_id=${deptId}`);
    await checkPage(page, 'department calendar');
    await page.waitForSelector('.fc-daygrid-day .fc-event', { timeout: 10000 }).catch(() => fail('department calendar: no events drawn'));
    await page.click('.fc-timeGridWeek-button');
    await page.waitForSelector('.fc-timegrid-event', { timeout: 10000 }).catch(() => fail('department week view: nothing this week'));
    await page.waitForTimeout(600);
    const tagged = await page.evaluate(() => {
      const evs = Array.from(document.querySelectorAll('.fc-timegrid-event'));
      const a = evs.find((e) => e.textContent.includes('scheduled'));
      const b = evs.find((e) => !e.textContent.includes('scheduled') && !e.textContent.includes('created'));
      if (a) a.setAttribute('data-ug', 'w-sched');
      if (b) b.setAttribute('data-ug', 'w-event');
      return { sched: !!a, event: !!b };
    });
    if (!tagged.event) fail('department week view: no seeded event this week');
    const weekCallouts = [
      { selector: await mark(page, 'dc-nav', 'aside a.nav-link, .navbar-vertical a.nav-link', 'Calendar'), n: 1, side: 'tr' },
      { selector: '[data-ug="w-event"]', n: 2, side: 'tr' },
    ];
    if (tagged.sched) weekCallouts.push({ selector: '[data-ug="w-sched"]', n: 3, side: 'tr' });
    await callout(page, weekCallouts);
    await shot(page, `${G}/13-department-calendar-week`);
    await clearCallouts(page);

    // ================= New Event pop-up =================
    await goto(page, '/agent/calendar.php');
    await page.waitForSelector('.fc-newEvent-button');
    await page.click('.fc-newEvent-button');
    await page.waitForSelector('#addCalendarEventModal.show', { timeout: 8000 }).catch(() => fail('New Event pop-up did not open'));
    await page.waitForTimeout(500);
    await pick(page, 'calendar', 'Maintenance Windows', '#addCalendarEventModal');
    await page.fill('#addCalendarEventModal input[name=title]', 'Core switch firmware upgrade');
    await page.fill('#addCalendarEventModal input[name=start]', iso(9, '19:00'));
    await page.fill('#addCalendarEventModal input[name=end]', iso(9, '21:00'));
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await callout(page, [
      { selector: await mark(page, 'ev-cal', '.form-group', 'Calendar', '#addCalendarEventModal'), n: 1 },
      { selector: await mark(page, 'ev-repeat', '.form-group', 'Repeat', '#addCalendarEventModal'), n: 2 },
      { selector: await mark(page, 'ev-tabs', 'ul.nav-pills', null, '#addCalendarEventModal'), n: 3 },
    ]);
    await shot(page, `${G}/14-new-event`, { selector: '#addCalendarEventModal .modal-content' });
    await clearCallouts(page);
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await page.locator('#addCalendarEventModal button:has-text("Cancel")').click();
    await page.waitForSelector('#addCalendarEventModal.show', { state: 'detached', timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(300);

    // ================= Calendar Sync card =================
    await goto(page, '/agent/calendar.php');
    await checkPage(page, 'calendar (sync card)');
    const syncCard = await mark(page, 'sync', '.card', 'Calendar Sync', '.col-md-3');
    await shot(page, `${G}/15-calendar-sync`, { selector: syncCard });
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
