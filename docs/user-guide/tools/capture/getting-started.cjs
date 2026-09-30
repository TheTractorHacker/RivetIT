// Screenshots for docs/user-guide/01-getting-started.md
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/getting-started.cjs
//
// READ-ONLY with respect to app data: it signs in, opens pages, opens pop-ups, types illustrative text
// into a few fields and then leaves without saving. It never submits a form and never enters a wrong
// password. Needs the demo seeds 00-core.sql and 05-getting-started.sql (an archived former employee and
// a few notifications for the administrator). Exits non-zero on a PHP error or an unexpectedly empty page.

const fs = require('fs');
const path = require('path');
const { launch, login, goto, shot, clearCallouts, settle, BASE, OUT } = require('../lib.cjs');

const G = 'getting-started';
const REPO = path.resolve(__dirname, '..', '..', '..', '..');

// ---------------------------------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------------------------------
function fail(msg) {
  throw new Error(msg);
}

// Fail loudly when a page shows a PHP problem or bounced us back to the login page.
async function assertClean(page, label, { publicPage = false } = {}) {
  if (!publicPage && /\/login\.php/.test(page.url())) fail(`${label}: redirected to the login page (${page.url()})`);
  const text = await page.locator('body').innerText();
  const bad = text.match(/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Stack trace|Uncaught \w+)/);
  if (bad) fail(`${label}: page shows a PHP problem ("${bad[1]}")`);
}

async function assertCount(page, selector, min, label) {
  const n = await page.locator(selector).count();
  if (n < min) fail(`${label}: expected at least ${min} of "${selector}", found ${n}`);
  return n;
}

// Numbered call-outs, same look as lib.callout(), with a few extras the sidebar and top bar need:
//   selector | selectors[]  the element, or the union of several elements
//   side                    corner the badge sits on: tl (default) tr bl br
//   pad                     how far the red frame stands off the element (default 3)
//   inset                   draw the frame INSIDE the element and the badge on its right (full-width rows)
//   trim { l, t, r, b }     shrink (positive) or grow (negative) the frame on one side
async function mark(page, items) {
  await page.evaluate((items) => {
    document.querySelectorAll('.ug-callout').forEach((n) => n.remove());
    const sx = window.scrollX, sy = window.scrollY;
    items.forEach((it) => {
      const sels = it.selectors || [it.selector];
      let l = Infinity, t = Infinity, r = -Infinity, b = -Infinity;
      sels.forEach((s) => {
        const el = document.querySelector(s);
        if (!el) return;
        const q = el.getBoundingClientRect();
        l = Math.min(l, q.left); t = Math.min(t, q.top); r = Math.max(r, q.right); b = Math.max(b, q.bottom);
      });
      if (!isFinite(l)) return;
      const tr = Object.assign({ l: 0, t: 0, r: 0, b: 0 }, it.trim || {});
      l += tr.l; t += tr.t; r -= tr.r; b -= tr.b;
      const pad = it.inset ? -2 : (it.pad == null ? 3 : it.pad);
      const bl = l - pad, bt = t - pad, bw = r - l + 2 * pad, bh = b - t + 2 * pad;
      const box = document.createElement('div');
      box.className = 'ug-callout';
      box.style.cssText = `position:absolute;z-index:2147483646;pointer-events:none;box-sizing:border-box;border:3px solid #e03131;border-radius:6px;left:${bl + sx}px;top:${bt + sy}px;width:${bw}px;height:${bh}px;box-shadow:0 0 0 2px rgba(255,255,255,.7)`;
      const side = it.side || 'tl';
      let bx, by;
      if (it.inset) { bx = bl + bw - 34; by = bt + (bh - 26) / 2; }
      else {
        bx = side.includes('r') ? bl + bw - 10 : bl - 12;
        by = side.includes('b') ? bt + bh - 10 : bt - 12;
      }
      const badge = document.createElement('div');
      badge.className = 'ug-callout';
      badge.textContent = String(it.n);
      badge.style.cssText = `position:absolute;z-index:2147483647;pointer-events:none;left:${bx + sx}px;top:${by + sy}px;width:26px;height:26px;line-height:26px;text-align:center;border-radius:50%;background:#e03131;color:#fff;font:700 14px/26px system-ui,sans-serif;box-shadow:0 1px 4px rgba(0,0,0,.4)`;
      document.body.appendChild(box);
      document.body.appendChild(badge);
    });
  }, items);
}

// Screenshot of a rectangle given in VIEWPORT coordinates (nothing may scroll while it runs).
async function shotClip(page, rel, clip, { delay = 400 } = {}) {
  const file = path.join(OUT, rel.endsWith('.png') ? rel : `${rel}.png`);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.mouse.move(2, 2);
  await settle(page, delay);
  const vp = page.viewportSize();
  const x = Math.max(0, Math.floor(clip.x));
  const y = Math.max(0, Math.floor(clip.y));
  const width = Math.min(vp.width - x, Math.ceil(clip.width));
  const height = Math.min(vp.height - y, Math.ceil(clip.height));
  await page.screenshot({ path: file, clip: { x, y, width, height } });
  console.log('saved', path.relative(REPO, file));
  return file;
}

// Bounding box (viewport coordinates) of the union of several elements, grown by a margin.
async function boxOf(page, selectors, margin = {}) {
  const m = Object.assign({ l: 0, t: 0, r: 0, b: 0 }, margin);
  const box = await page.evaluate((sels) => {
    let l = Infinity, t = Infinity, r = -Infinity, b = -Infinity;
    for (const s of sels) {
      const el = document.querySelector(s);
      if (!el) continue;
      const q = el.getBoundingClientRect();
      l = Math.min(l, q.left); t = Math.min(t, q.top); r = Math.max(r, q.right); b = Math.max(b, q.bottom);
    }
    return { l, t, r, b };
  }, selectors);
  if (!isFinite(box.l)) fail(`boxOf: none of ${selectors.join(', ')} found`);
  return { x: box.l - m.l, y: box.t - m.t, width: box.r - box.l + m.l + m.r, height: box.b - box.t + m.t + m.b };
}

async function newSession(browser, who, viewport) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1, colorScheme: 'light' });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  // A page that says "leave site?" must never stop the script from moving on.
  page.on('dialog', (d) => d.accept().catch(() => {}));
  await login(page, who);
  await goto(page, '/agent/clients.php'); // the first page after sign-in carries a one-off toast; skip past it
  await assertClean(page, `${who} start page`);
  return { context, page };
}

// A dropdown menu inside a table is moved to <body> while it is open (js/app.js), so look for it there.
async function openRowMenu(page, row) {
  await row.locator('button[data-bs-toggle=dropdown]').click();
  await page.waitForSelector('.dropdown-menu.show');
  await page.waitForTimeout(250);
}

async function closeMenus(page) {
  await page.keyboard.press('Escape');
  await page.mouse.click(5, 5);
  await page.waitForTimeout(250);
}

// Rail of the sidebar as a PNG buffer, cut to the height of its content.
async function railPng(page) {
  await page.mouse.move(2, 2);
  await settle(page, 300);
  const h = await page.evaluate(() => {
    const aside = document.querySelector('aside.navbar-vertical');
    const a = aside.getBoundingClientRect();
    let bottom = 0;
    aside.querySelectorAll('#sidebar-menu .navbar-nav li').forEach((li) => {
      const q = li.getBoundingClientRect();
      if (q.height > 0) bottom = Math.max(bottom, q.bottom);
    });
    return Math.min(a.height, Math.ceil(bottom - a.top + 24));
  });
  return page.screenshot({ clip: { x: 0, y: 0, width: 256, height: h } });
}

// Side-by-side figure built from real screenshots: [{ label, note, png }].
async function composite(browser, rel, panels) {
  const context = await browser.newContext({ viewport: { width: 1200, height: 800 }, deviceScaleFactor: 1 });
  const page = await context.newPage();
  const cols = panels
    .map(
      (p) => `<div class="col"><div class="cap">${p.label}</div><div class="note">${p.note || ''}</div>` +
        `<img src="data:image/png;base64,${p.png.toString('base64')}"></div>`
    )
    .join('');
  await page.setContent(`<!doctype html><meta charset="utf-8"><style>
    body{margin:0;background:#f1f5f9;font:14px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1f2937}
    .wrap{display:inline-flex;gap:32px;padding:22px 26px 26px;background:#f1f5f9;align-items:flex-start}
    .col{display:flex;flex-direction:column;gap:6px}
    .cap{font-weight:700;font-size:16px}
    .note{font-size:12.5px;color:#475569;width:256px;height:54px;overflow:hidden}
    img{display:block;box-shadow:0 1px 6px rgba(15,23,42,.35)}
  </style><div class="wrap">${cols}</div>`);
  await page.waitForLoadState('load');
  const file = path.join(OUT, rel.endsWith('.png') ? rel : `${rel}.png`);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.locator('.wrap').screenshot({ path: file });
  console.log('saved', path.relative(REPO, file));
  await context.close();
}

// Tom Select replaces a <select> with a sibling .ts-wrapper; give the visible control a stable hook.
async function tag(page, selector, key) {
  const ok = await page.evaluate(({ selector, key }) => {
    let el = document.querySelector(selector);
    if (!el) return false;
    const sib = el.nextElementSibling;
    if (sib && sib.classList.contains('ts-wrapper')) el = sib;
    el.setAttribute('data-ug', key);
    return true;
  }, { selector, key });
  if (!ok) fail(`tag: ${selector} not found`);
  return `[data-ug="${key}"]`;
}

async function departmentOptionValue(page, name) {
  const v = await page.evaluate((n) => {
    const o = Array.from(document.querySelectorAll('select[name=client] option')).find((x) => x.textContent.trim() === n);
    return o ? o.value : null;
  }, name);
  if (!v) fail(`department "${name}" not offered by the People filter`);
  return v;
}

async function departmentId(page, name) {
  await goto(page, '/agent/clients.php');
  const href = await page.evaluate((n) => {
    const a = Array.from(document.querySelectorAll('a[href*="client_overview.php?client_id="]')).find((x) => x.innerText.includes(n));
    return a ? a.getAttribute('href') : null;
  }, name);
  if (!href) fail(`department "${name}" not found in the Departments list`);
  return href.match(/client_id=(\d+)/)[1];
}

// ---------------------------------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------------------------------
(async () => {
  const { browser } = await launch();
  const VIEW = { width: 1440, height: 900 };
  try {
    // ==== 01  Sign-in page =========================================================================
    {
      const context = await browser.newContext({ viewport: VIEW, deviceScaleFactor: 1, colorScheme: 'light' });
      const page = await context.newPage();
      page.setDefaultTimeout(20000);
      await page.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
      await settle(page, 500);
      await assertClean(page, 'login page', { publicPage: true });
      await page.waitForSelector('#passkeySignInBtn');
      await page.evaluate(() => document.activeElement && document.activeElement.blur());
      await mark(page, [
        { selector: '#passkeySignInBtn', n: 1, side: 'tr' },
        { selector: 'input[name=email]', n: 2, side: 'tr' },
        { selector: 'input[name=password]', n: 3, side: 'tr' },
        { selector: '#remember_me', n: 4, side: 'tr' },
        { selector: 'button[name=login]', n: 5, side: 'tr' },
      ]);
      const clip = await boxOf(page, ['.login-brand', '.page-center .card'], { l: 70, r: 70, t: 40, b: 40 });
      await shotClip(page, `${G}/01-sign-in`, clip);
      await context.close();
    }

    // ==== admin session for most of the figures =====================================================
    const admin = await newSession(browser, 'admin', VIEW);
    const page = admin.page;

    // ==== 02  The app shell: top bar and sidebar =====================================================
    {
      await goto(page, '/agent/clients.php');
      await assertClean(page, 'Departments');
      await assertCount(page, 'aside.navbar-vertical [data-if-toggle="submenu"]', 4, 'sidebar groups');
      await page.click('li.user-menu > a');
      await page.waitForSelector('li.user-menu .dropdown-menu.show');
      await page.waitForTimeout(300);
      await mark(page, [
        { selector: 'a[href="#nav-group-service-desk"]', n: 1, side: 'tr' },
        { selector: '#itflowSidebarToggle', n: 2, side: 'br' },
        { selector: 'form.app-header-search', n: 3, side: 'br' },
        { selectors: ['a[data-modal-url="/modals/notifications.php"]', 'a[data-modal-url="/modals/notifications.php"] .badge'], n: 4, side: 'bl', pad: 2 },
        { selector: 'li.user-menu > a', n: 5, side: 'br', pad: 2, trim: { l: 8 } },
      ]);
      await shot(page, `${G}/02-app-shell`);
      await clearCallouts(page);
      await closeMenus(page);
    }

    // ==== 03  Three navigation scopes (composite of real sidebar screenshots) ========================
    {
      await page.setViewportSize({ width: 1440, height: 1400 });
      await goto(page, '/agent/clients.php');
      const appRail = await railPng(page);

      const prodId = await departmentId(page, 'Production');
      await goto(page, `/agent/client_overview.php?client_id=${prodId}`);
      await assertClean(page, 'Department overview');
      await mark(page, [{ selector: '.client-nav-back', n: 1, inset: true }, { selector: '.client-nav-header', n: 2, inset: true }]);
      const deptRail = await railPng(page);

      await goto(page, '/agent/contacts.php');
      await assertClean(page, 'People');
      await mark(page, [{ selector: '.client-nav-back', n: 1, inset: true }, { selector: '.client-nav-header', n: 2, inset: true }]);
      const companyRail = await railPng(page);

      await composite(browser, `${G}/03-three-scopes`, [
        { label: 'App-level sidebar', note: 'All departments together. Shown on the start page and on most lists.', png: appRail },
        { label: 'Department workspace', note: 'One department (here: Production). Shown whenever the page is opened for a department.', png: deptRail },
        { label: 'Company-wide', note: 'One list across every department you can see. Opens from People.', png: companyRail },
      ]);
      await page.setViewportSize(VIEW);
    }

    // ==== 04  Administrator and Technician sidebars side by side ==================================
    {
      await page.setViewportSize({ width: 1440, height: 1400 });
      await goto(page, '/agent/credentials.php'); // opens the Knowledge group in both rails
      await assertClean(page, 'Credentials (admin)');
      const adminRail = await railPng(page);

      const tech = await newSession(browser, 'tech', { width: 1440, height: 1400 });
      await goto(tech.page, '/agent/credentials.php');
      await assertClean(tech.page, 'Credentials (technician)');
      const techRail = await railPng(tech.page);
      await tech.context.close();

      await composite(browser, `${G}/10-admin-vs-technician`, [
        { label: 'Administrator', note: 'Alex Morgan. Every module, plus the Administration area.', png: adminRail },
        { label: 'Technician', note: 'Priya Nair, standard Technician role. Same app, fewer entries.', png: techRail },
      ]);
      await page.setViewportSize(VIEW);
    }

    // ==== 05  Search everywhere ======================================================================
    {
      await goto(page, '/agent/clients.php');
      await page.click('#globalSearchInput');
      await page.type('#globalSearchInput', 'logistics', { delay: 60 });
      await page.waitForSelector('#globalSearchResults:not(.d-none) .app-header-search-result', { timeout: 8000 });
      await page.waitForTimeout(500);
      await assertCount(page, '#globalSearchResults .app-header-search-group', 2, 'search result groups');
      await assertCount(page, '#globalSearchResults .app-header-search-seeall', 1, 'search: See all results link');
      await mark(page, [
        { selector: 'form.app-header-search .input-group', n: 1, side: 'tr' },
        { selector: '#globalSearchResults .app-header-search-group-label', n: 2, side: 'tr', trim: { r: 60 } },
        { selector: '#globalSearchResults .app-header-search-seeall', n: 3, side: 'tr' },
      ]);
      const clip = await boxOf(page, ['form.app-header-search', '#globalSearchResults'], { l: 40, t: 24, r: 60, b: 30 });
      await shotClip(page, `${G}/09-search-everywhere`, clip);
      await clearCallouts(page);
      await page.keyboard.press('Escape');
      await page.evaluate(() => { const i = document.getElementById('globalSearchInput'); if (i) { i.value = ''; i.blur(); } });
    }

    // ==== 06  The notifications bell ================================================================
    {
      await goto(page, '/agent/clients.php');
      await page.click('a[data-modal-url="/modals/notifications.php"]');
      await page.waitForSelector('.modal.show .notification-item', { timeout: 8000 });
      await page.waitForTimeout(700);
      await assertCount(page, '.modal.show .notification-item', 3, 'notifications in the bell');
      await mark(page, [
        { selectors: ['a[data-modal-url="/modals/notifications.php"]', 'a[data-modal-url="/modals/notifications.php"] .badge'], n: 1, side: 'bl', pad: 2 },
        { selector: '.modal.show .notification-item', n: 2, side: 'tr', trim: { r: 6 } },
        { selector: '.modal.show .modal-footer a[href*="dismiss_all_notifications"]', n: 3, side: 'tr' },
        { selector: '.modal.show .modal-footer a[href="/agent/notifications.php"]', n: 4, side: 'tr' },
      ]);
      await shot(page, `${G}/08-notifications`);
      await clearCallouts(page);
      await page.click('.modal.show .modal-footer button:has-text("Close")');
      await page.waitForSelector('.modal.show', { state: 'detached' });
    }

    // ==== 07  Account > Details (profile and email signature) ========================================
    {
      await page.setViewportSize({ width: 1440, height: 1180 });
      await goto(page, '/agent/user/user_details.php');
      await assertClean(page, 'Account details');
      await page.waitForFunction(() => window.tinymce && window.tinymce.get('signature_editor') && window.tinymce.get('signature_editor').initialized, null, { timeout: 15000 });
      // Illustrative values, typed but never saved.
      await page.fill('#user_title_input', 'IT Systems Administrator');
      await page.fill('#user_phone_input', '608-555-0180');
      await page.click('#signature_template_btn');
      await page.waitForTimeout(800);
      await page.evaluate(() => document.activeElement && document.activeElement.blur());
      await mark(page, [
        { selector: 'a.section-nav-back', n: 1, inset: true },
        { selectors: ['aside.navbar-vertical .navbar-nav > li:first-child', 'aside.navbar-vertical .navbar-nav > li:last-child'], n: 2, inset: true, trim: { t: 2, b: 2, l: 4, r: 4 } },
        { selector: 'label.btn:has(input[name=avatar])', n: 3, side: 'tr' },
        { selector: '#signature_template_btn', n: 4, side: 'tr' },
        { selector: 'button[name=edit_your_user_details]', n: 5, side: 'tr' },
      ]);
      await shot(page, `${G}/04-account-details`);
      await clearCallouts(page);
      await page.setViewportSize(VIEW);
    }

    // ==== 08  Account > Security =====================================================================
    {
      await goto(page, '/agent/user/user_security.php');
      await assertClean(page, 'Account security');
      await assertCount(page, 'button[data-bs-target="#enableMFAModal"], a[href*="disable_mfa"]', 1, 'MFA control');
      await mark(page, [
        { selector: 'button[name=edit_your_user_password]', n: 1, side: 'tr' },
        { selector: 'button[data-bs-target="#enableMFAModal"]', n: 2, side: 'tl' },
        { selector: '#addPasskeyBtn', n: 3, side: 'tl' },
      ]);
      await shot(page, `${G}/05-account-security`);
      await clearCallouts(page);

      // ==== 09  Turning on MFA: the QR pop-up (nothing is saved; Cancel closes it) ==================
      await page.click('button[data-bs-target="#enableMFAModal"]');
      await page.waitForSelector('#enableMFAModal.show');
      // The secret in this pop-up is random and never enrolled; swap it (and its QR) for an obvious example anyway.
      await page.evaluate(async () => {
        const fake = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        const modal = document.getElementById('enableMFAModal');
        const img = modal.querySelector('img');
        img.src = '../../plugins/barcode/barcode.php?f=png&s=qr&d=' + encodeURIComponent('otpauth://totp/RivetIT:alex.morgan@summitridge.example?secret=' + fake);
        const p = Array.from(modal.querySelectorAll('p')).find((x) => /Secret:/.test(x.textContent));
        const walker = document.createTreeWalker(p, NodeFilter.SHOW_TEXT);
        let n;
        while ((n = walker.nextNode())) { if (n.textContent.trim() && !/Secret:/.test(n.textContent)) { n.textContent = ' ' + fake + ' '; break; } }
        const btn = p.querySelector('[data-clipboard-text]'); if (btn) btn.setAttribute('data-clipboard-text', fake);
        await new Promise((r) => { if (img.complete && img.naturalWidth) r(); else img.onload = r; setTimeout(r, 4000); });
      });
      await page.waitForTimeout(500);
      const qrOk = await page.evaluate(() => { const i = document.querySelector('#enableMFAModal img'); return i.complete && i.naturalWidth > 0; });
      if (!qrOk) fail('MFA pop-up: the QR code did not load');
      await mark(page, [
        { selector: '#enableMFAModal img', n: 1, side: 'tr' },
        { selector: '#enableMFAModal input[name=verify_code]', n: 2, side: 'tr', trim: { r: 8 } },
        { selector: '#enableMFAModal button[name=enable_mfa]', n: 3, side: 'tr' },
      ]);
      const clip = await boxOf(page, ['#enableMFAModal .modal-content'], { l: 30, r: 30, t: 20, b: 20 });
      await shotClip(page, `${G}/06-mfa-setup`, clip);
      await clearCallouts(page);
      await page.click('#enableMFAModal button:has-text("Cancel")');
      await page.waitForSelector('#enableMFAModal.show', { state: 'detached' }).catch(() => {});
      await page.waitForTimeout(400);
    }

    // ==== 10  Account > Preferences ==================================================================
    {
      await goto(page, '/agent/user/user_preferences.php');
      await assertClean(page, 'Account preferences');
      await mark(page, [
        { selector: '.js-btn-group-toggle', n: 1, side: 'tr' },
        { selector: 'select[name=calendar_first_day]', n: 2, side: 'tr' },
        { selector: 'select[name=records_per_page]', n: 3, side: 'tr' },
        { selector: 'button[name=edit_your_user_preferences]', n: 4, side: 'tr' },
      ]);
      const c = await boxOf(page, ['.card'], { l: 8, t: 8, r: 8, b: 8 });
      await shotClip(page, `${G}/07-account-preferences`, { x: c.x, y: c.y, width: 640, height: c.height });
      await clearCallouts(page);
    }

    // ==== 11  Anatomy of a list (People, filtered to one department, row menu open) ==================
    {
      await goto(page, '/agent/contacts.php');
      const prod = await departmentOptionValue(page, 'Production');
      await goto(page, `/agent/contacts.php?client=${prod}`);
      await assertClean(page, 'People (Production)');
      await assertCount(page, 'table tbody tr', 3, 'People rows');
      const rows = page.locator('table tbody tr');
      let idx = -1;
      const n = await rows.count();
      for (let i = 0; i < n; i++) { if (!/Primary Contact/.test(await rows.nth(i).innerText())) { idx = i; break; } }
      if (idx < 0) fail('People: no row without the Primary Contact label');
      await openRowMenu(page, rows.nth(idx));
      const menuText = await page.locator('.dropdown-menu.show').first().innerText();
      if (!/Archive/.test(menuText)) fail(`People row menu has no Archive entry: ${menuText}`);
      const tagsSel = await tag(page, 'select[name="tags[]"]', 'tags');
      const deptSel = await tag(page, 'select[name=client]', 'dept');
      await mark(page, [
        { selector: 'input[name=q]', n: 1, side: 'tl' },
        { selectors: [tagsSel, deptSel], n: 2, side: 'tl' },
        { selector: 'a.btn[href*="archived=1"]', n: 3, side: 'tr' },
        { selector: 'table thead th a', n: 4, side: 'bl' },
        { selector: `table tbody tr:nth-child(${idx + 1}) button[data-bs-toggle=dropdown]`, n: 5, side: 'tl' },
      ]);
      await shot(page, `${G}/11-list-anatomy`);
      await clearCallouts(page);
      await closeMenus(page);
    }

    // ==== 12  A filter panel (Departments: the funnel button) ========================================
    {
      await goto(page, '/agent/clients.php');
      await assertClean(page, 'Departments');
      await page.click('[data-bs-target="#advancedFilter"]');
      await page.waitForSelector('#advancedFilter.show');
      await page.waitForTimeout(700);
      const tagSel = await tag(page, 'select[name="tags[]"]', 'ftags');
      const indSel = await tag(page, 'select[name=industry]', 'find');
      await mark(page, [
        { selector: '[data-bs-target="#advancedFilter"]', n: 1, side: 'tl' },
        { selector: '#dateFilter', n: 2, side: 'tl' },
        { selector: tagSel, n: 3, side: 'tl' },
        { selector: indSel, n: 4, side: 'tl' },
      ]);
      const c = await boxOf(page, ['.card-header', '#advancedFilter'], { l: 10, t: 6, r: 10, b: 24 });
      await shotClip(page, `${G}/12-filter-panel`, c);
      await clearCallouts(page);
    }

    // ==== 13  Select rows, then Bulk Action ===========================================================
    {
      await goto(page, '/agent/contacts.php');
      await assertClean(page, 'People');
      const boxes = page.locator('input.bulk-select');
      await assertCount(page, 'input.bulk-select', 3, 'People checkboxes');
      await boxes.nth(0).check();
      await boxes.nth(1).check();
      await page.waitForTimeout(300);
      await page.click('#bulkActionButton > button');
      await page.waitForSelector('#bulkActionButton .dropdown-menu.show');
      await page.waitForTimeout(300);
      await mark(page, [
        { selector: '#selectAllCheckbox', n: 1, side: 'tr' },
        { selector: 'input.bulk-select', n: 2, side: 'tr' },
        { selector: '#bulkActionButton > button', n: 3, side: 'tl' },
      ]);
      const c = await boxOf(page, ['.card-header', '#bulkActionButton .dropdown-menu'], { l: 10, t: 6, r: 10, b: 30 });
      await shotClip(page, `${G}/13-bulk-actions`, { x: c.x, y: c.y, width: c.width, height: Math.max(c.height, 560) });
      await clearCallouts(page);
      await closeMenus(page);
    }

    // ==== 14  The Archived view: Restore and Delete ===================================================
    {
      await goto(page, '/agent/contacts.php?archived=1');
      await assertClean(page, 'People (archived)');
      await assertCount(page, 'input.bulk-select', 1, 'archived People rows');
      await page.locator('input.bulk-select').first().check();
      await page.waitForTimeout(300);
      await page.click('#bulkActionButton > button');
      await page.waitForSelector('#bulkActionButton .dropdown-menu.show');
      await page.waitForTimeout(300);
      await mark(page, [
        { selector: 'a.btn[href*="archived=0"]', n: 1, side: 'tr' },
        { selector: 'button[name=bulk_restore_contacts]', n: 2, side: 'tl' },
        { selector: 'button[name=bulk_delete_contacts]', n: 3, side: 'tl' },
      ]);
      const c = await boxOf(page, ['.card-header', '#bulkActionButton .dropdown-menu', 'table'], { l: 10, t: 6, r: 10, b: 24 });
      await shotClip(page, `${G}/16-archived-view`, c);
      await clearCallouts(page);
      await closeMenus(page);
    }

    // ==== 15  Paging: per-page selector, range and page links ===========================================
    {
      await goto(page, '/agent/contacts.php');
      await assertClean(page, 'People');
      await assertCount(page, '.card-footer ul.pagination .page-item', 3, 'pagination links');
      await page.locator('.card-footer').last().scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
      const perPage = await tag(page, 'select[name=change_records_per_page]', 'perpage');
      await mark(page, [
        { selector: perPage, n: 1, side: 'tl' },
        { selector: '.card-footer p.text-center', n: 2, side: 'tl' },
        { selector: '.card-footer ul.pagination', n: 3, side: 'tl' },
      ]);
      const foot = await boxOf(page, ['.card-footer'], { l: 8, t: 0, r: 8, b: 14 });
      const rows = await boxOf(page, ['table tbody tr:nth-last-child(2)'], {});
      const y = Math.max(0, rows.y - 4);
      await shotClip(page, `${G}/14-paging`, { x: foot.x, y, width: foot.width, height: foot.y + foot.height - y });
      await clearCallouts(page);
    }

    // ==== 16  A pop-up form (the small "Make Note" form opened from a row menu) ==========================
    {
      await goto(page, '/agent/contacts.php');
      const rows = page.locator('table tbody tr');
      await openRowMenu(page, rows.first());
      await page.locator('.dropdown-menu.show a:has-text("Make Note")').click();
      await page.waitForSelector('.modal.show textarea', { timeout: 8000 });
      await page.waitForTimeout(700);
      // Illustrative text, typed but never saved.
      await page.fill('.modal.show textarea', 'Asked for a second monitor at the plant-floor desk. Follow up next week.');
      const typeSel = await tag(page, '.modal.show select', 'notetype');
      await mark(page, [
        { selector: '.modal.show .modal-header', n: 1, inset: true },
        { selector: typeSel, n: 2, side: 'tr' },
        { selector: '.modal.show button[type=submit]', n: 3, side: 'tr' },
        { selector: '.modal.show .modal-footer button:not([type=submit])', n: 4, side: 'tr' },
      ]);
      const clip = await boxOf(page, ['.modal.show .modal-content'], { l: 30, r: 30, t: 20, b: 26 });
      await shotClip(page, `${G}/15-popup-form`, clip);
      await clearCallouts(page);
      await page.click('.modal.show .modal-footer button:has-text("Cancel")');
      await page.waitForSelector('.modal.show', { state: 'detached' });
    }

    console.log('all figures done');
    await admin.context.close();
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
