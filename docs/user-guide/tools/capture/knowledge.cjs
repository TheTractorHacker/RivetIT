// Screenshots for the Knowledge guide pages:
//   05-knowledge-base.md                        -> images/knowledge/01..15
//   05b-credentials-printers-network-drives.md  -> images/knowledge/16..31
//
// Run:  cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/knowledge.cjs
//
// READ-ONLY: it opens pages, dialogs and menus and never submits a form that saves. The only requests
// it makes beyond page loads are the password generator (a GET that returns a random phrase), the
// one-time-code lookup, and the admin "Department Portal Preview" link (a GET that starts a read-only
// preview for this browser session; the script leaves the preview again at the end).
//
// Data comes from seed/20-knowledge.php. Everything is found by NAME (article titles, credential
// names), never by numeric id, so the script keeps working on a rebuilt database.

const { launch, login, goto, shot, callout, clearCallouts, settle, BASE } = require('../lib.cjs');

const G = 'knowledge';
let relationShotFile = null;
let n = 0;
const next = (slug) => `${G}/${String(++n).padStart(2, '0')}-${slug}`;

// ---- guards ---------------------------------------------------------------------------------------------

async function assertClean(page, label) {
  const text = await page.locator('body').innerText();
  const bad = text.match(/(Fatal error|Uncaught|Warning:|Notice:|Deprecated:|Parse error|Undefined (variable|array key|index))/);
  if (bad) throw new Error(`${label}: page shows a PHP problem (${bad[0]})`);
}

async function open(page, url, { expect } = {}) {
  await goto(page, url);
  await assertClean(page, url);
  if (expect) {
    const text = await page.locator('body').innerText();
    for (const s of [].concat(expect)) {
      if (!text.includes(s)) throw new Error(`${url}: expected to find "${s}"`);
    }
  }
}

/** Tag an element so callout() (plain CSS selectors) can find it. */
async function mark(locator, name) {
  if ((await locator.count()) === 0) throw new Error(`mark(${name}): element not found`);
  await locator.first().evaluate((el, v) => el.setAttribute('data-ug', v), name);
  return `[data-ug="${name}"]`;
}

async function openArticle(page, title) {
  await open(page, `/agent/kb_articles.php?q=${encodeURIComponent(title)}`);
  const link = page.locator(`a.text-dark:text-is("${title}")`).first();
  if ((await link.count()) === 0) throw new Error(`article "${title}" not found - was seed/20-knowledge.php applied?`);
  const href = await link.getAttribute('href');
  await open(page, `/agent/${href}`, { expect: title });
}

async function tall(page, fn) {
  await page.setViewportSize({ width: 1440, height: 1300 });
  try { await fn(); } finally { await page.setViewportSize({ width: 1440, height: 900 }); }
}

const MODAL = '.modal.show .modal-content';
async function waitModal(page, extraMs = 900) {
  await page.waitForSelector(MODAL, { timeout: 15000 });
  await page.waitForTimeout(extraMs);
}
async function closeModal(page) {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);
  await page.mouse.move(2, 2);
}

function fsRenameRelation(rel) {
  const fs = require('fs');
  const path = require('path');
  const { OUT } = require('../lib.cjs');
  if (!relationShotFile) throw new Error('relation shot missing');
  const dest = path.join(OUT, `${rel}.png`);
  fs.renameSync(relationShotFile, dest);
  console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..'), dest));
}

// ---- main -----------------------------------------------------------------------------------------------

(async () => {
  const { browser, page } = await launch();
  try {
    await login(page, 'admin');

    /* =========================== PAGE 05 - KNOWLEDGE BASE =========================== */

    // 01 - the list with numbered call-outs
    await open(page, '/agent/kb_articles.php', { expect: ['Knowledge Base', 'Needs Review Only'] });
    if ((await page.locator('.card.h-100').count()) < 3) throw new Error('KB list looks empty');
    await callout(page, [
      { selector: await mark(page.locator('button:has-text("Categories")'), 'cats'), n: 1, side: 'tl' },
      { selector: await mark(page.locator('button.dropdown-toggle:has-text("New")'), 'new'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('input[name=q]'), 'q'), n: 3 },
      { selector: await mark(page.locator('select[name=filter_category_id] + .select2'), 'cat'), n: 4 },
      { selector: await mark(page.locator('.card.h-100').first(), 'card'), n: 5, side: 'tr' },
    ]);
    await shot(page, next('kb-list'));
    await clearCallouts(page);

    // 02 - filtered to one category: scope badges and the portal-visibility icon on each card
    const netId = await page.evaluate(() => {
      const o = [...document.querySelectorAll('select[name=filter_category_id] option')].find((x) => x.textContent.trim() === 'Network & Wi-Fi');
      return o ? o.value : null;
    });
    if (!netId) throw new Error('Network & Wi-Fi category missing');
    await open(page, `/agent/kb_articles.php?filter_category_id=${netId}`, { expect: ['Connect to the staff Wi-Fi', 'Site Network Reference', 'Use the VPN from home'] });
    const wifiCard = page.locator('.card.h-100:has-text("Connect to the staff Wi-Fi")').first();
    const siteCard = page.locator('.card.h-100:has-text("Site Network Reference")').first();
    await callout(page, [
      { selector: await mark(wifiCard.locator('.fa-check'), 'i-vis'), n: 1, side: 'tl' },
      { selector: await mark(wifiCard.locator('.fa-graduation-cap'), 'i-train'), n: 2, side: 'tr' },
      { selector: await mark(siteCard.locator('.fa-eye-slash'), 'i-hid'), n: 3, side: 'tl' },
    ]);
    await shot(page, next('kb-category-filter'), { selector: '.card.card-dark' });
    await clearCallouts(page);

    // 03 - an article: content, details panel, attachments
    await openArticle(page, 'Connecting to the Office Printer');
    await callout(page, [
      { selector: await mark(page.locator('.card-sidebar p:has-text("Scope")'), 'scope'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('.card-sidebar p:has-text("Department Portal")'), 'portal'), n: 2, side: 'tr' },
      { selector: await mark(page.locator('.card-sidebar p:has-text("Training Portal")'), 'training'), n: 3, side: 'tr' },
      { selector: await mark(page.locator('.card-sidebar p:has-text("Review Schedule")'), 'review'), n: 4, side: 'tr' },
      { selector: await mark(page.locator('.card-sidebar button.btn-primary:has-text("Edit")'), 'edit'), n: 5, side: 'tr' },
      { selector: await mark(page.locator('.card-sidebar a:has-text("Version History")'), 'ver'), n: 6, side: 'tr' },
      { selector: await mark(page.locator('.card-sidebar:has-text("Attachments")'), 'att'), n: 7, side: 'tr' },
    ]);
    await shot(page, next('kb-article'));
    await clearCallouts(page);
    const printerArticleUrl = page.url();

    // 08/09 come from this article's history - collect the URL now
    const versionsHref = await page.locator('a:has-text("Version History")').first().getAttribute('href');

    // 04 - New article dialog (editor loads a moment after the dialog opens)
    await page.setViewportSize({ width: 1440, height: 1300 });
    await open(page, '/agent/kb_articles.php');
    await page.click('button.dropdown-toggle:has-text("New")');
    await page.click('.dropdown-menu.show a:has-text("Article")');
    await waitModal(page, 300);
    await page.waitForSelector(`${MODAL} .tox-toolbar-overlord`, { timeout: 15000 });
    await page.waitForTimeout(1200);
    await page.fill(`${MODAL} input[name=title]`, 'Connecting to the Guest Printer');
    await callout(page, [
      { selector: await mark(page.locator(`${MODAL} input[name=title]`), 't'), n: 1 },
      { selector: await mark(page.locator(`${MODAL} select[name=client_id] + .select2`), 'd'), n: 2 },
      { selector: await mark(page.locator(`${MODAL} select[name=category_id] + .select2`), 'c'), n: 3 },
      { selector: await mark(page.locator(`${MODAL} select[name=client_visible] + .select2`), 'v'), n: 4 },
      { selector: await mark(page.locator(`${MODAL} select[name=training_visible] + .select2`), 'tv'), n: 5 },
      { selector: await mark(page.locator(`${MODAL} .doc-builder-tabs`), 'tabs'), n: 6, side: 'tr' },
    ]);
    await shot(page, next('kb-new-article'), { selector: MODAL });
    await clearCallouts(page);
    await page.setViewportSize({ width: 1440, height: 900 });

    // 05 - the Interactive menu in the editor toolbar
    await page.locator(`${MODAL} .tox-tbtn:has-text("Interactive")`).first().click();
    await page.waitForTimeout(700);
    if ((await page.locator('.tox-menu .tox-collection__item').count()) === 0) throw new Error('Interactive menu did not open');
    await shot(page, next('kb-interactive-menu'), { keepHover: true });
    await page.keyboard.press('Escape');
    await closeModal(page);

    // 06/07 - the New menu with the importers, and the Word importer dialog
    await open(page, '/agent/kb_articles.php');
    await page.click('button.dropdown-toggle:has-text("New")');
    await page.waitForSelector('.dropdown-menu.show');
    await callout(page, [
      { selector: await mark(page.locator('.dropdown-menu.show a:has-text("Article")'), 'a1'), n: 1, side: 'tl' },
      { selector: await mark(page.locator('.dropdown-menu.show a:has-text("Word Doc")'), 'a2'), n: 2, side: 'tl' },
      { selector: await mark(page.locator('.dropdown-menu.show a:has-text("PDF")'), 'a3'), n: 3, side: 'tl' },
      { selector: await mark(page.locator('.dropdown-menu.show a:has-text("HTML")'), 'a4'), n: 4, side: 'tl' },
    ]);
    await shot(page, next('kb-import-menu'), { keepHover: true });
    await clearCallouts(page);
    await page.click('.dropdown-menu.show a:has-text("Word Doc")');
    await waitModal(page);
    await shot(page, next('kb-import-word'), { selector: MODAL });
    await closeModal(page);

    // 08 - version history (from the article), 09 - one old version
    await open(page, `/agent/${versionsHref}`, { expect: ['Version History', '#2', '#1'] });
    await callout(page, [
      { selector: await mark(page.locator('tbody tr').first().locator('button:has-text("View")'), 'vv'), n: 1, side: 'tl' },
      { selector: await mark(page.locator('tbody tr').first().locator('button:has-text("Restore")'), 'vr'), n: 2, side: 'tr' },
    ]);
    await shot(page, next('kb-version-history'), { selector: '.alga-theme .card' });
    await clearCallouts(page);
    await page.locator('tbody tr').last().locator('button:has-text("View")').click();
    await waitModal(page);
    await shot(page, next('kb-version-view'), { selector: MODAL });
    await closeModal(page);

    // 10 - an interactive checklist that remembers ticks
    await openArticle(page, 'Laptop Imaging and Deployment Runbook');
    if ((await page.locator('.ikb').count()) === 0) throw new Error('interactive block did not render');
    await shot(page, next('kb-checklist'), { selector: '.col-md-9 .card' });

    // 11 - the "Reveal linked credential" button and the Needs Review badge
    await openArticle(page, 'Firewall Change Procedure');
    await callout(page, [
      { selector: await mark(page.locator('.kb-credential-reveal'), 'reveal'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('.breadcrumb .badge'), 'nr'), n: 2, side: 'tr' },
    ]);
    await shot(page, next('kb-credential-button'));
    await clearCallouts(page);

    // 12 - manage categories
    await open(page, '/agent/kb_articles.php');
    await page.click('button:has-text("Categories")');
    await waitModal(page);
    await shot(page, next('kb-categories'), { selector: MODAL });
    await closeModal(page);

    // 13 - Admin > Knowledge Base, 14 - archived articles
    await open(page, '/admin/settings_kb.php', { expect: 'Knowledge Base Settings' });
    await shot(page, next('kb-settings'), { fullPage: true });
    await open(page, '/admin/kb_articles_archive.php', { expect: ['Archived Knowledge Base Articles', 'Plant Tablet Wi-Fi Setup (Retired)'] });
    await shot(page, next('kb-archive'), { selector: '.card' });

    // 15 - what a department sees (read-only portal preview for Warehouse & Logistics)
    await open(page, '/admin/portal_preview.php');
    const previewLink = page.locator('a[href*="view_client_portal="]', { hasText: /Warehouse/ }).first();
    let previewHref = null;
    if ((await previewLink.count()) > 0) previewHref = await previewLink.getAttribute('href');
    else {
      // fall back to the row whose department name mentions Warehouse
      const row = page.locator('tr:has-text("Warehouse") a[href*="view_client_portal="]').first();
      if ((await row.count()) === 0) throw new Error('portal preview link for Warehouse not found');
      previewHref = await row.getAttribute('href');
    }
    await open(page, previewHref);
    await open(page, '/client/kb_articles.php?q=printer', { expect: ['Knowledge Base', 'Connecting to the Office Printer', 'Label Printer Quick Fixes'] });
    await open(page, '/client/kb_articles.php', { expect: ['Knowledge Base'] });
    if ((await page.locator('body').innerText()).includes('Firewall Change Procedure')) throw new Error('an internal article leaked into the portal preview');
    await open(page, '/client/kb_articles.php?q=printer');
    await callout(page, [
      { selector: await mark(page.locator('input[name=q]'), 'pq'), n: 1 },
      { selector: await mark(page.locator('.card.h-100:has-text("Connecting to the Office Printer") .badge'), 'pc'), n: 2, side: 'tr' },
    ]);
    await shot(page, next('kb-portal'));
    await clearCallouts(page);
    const exitLink = page.locator('a:has-text("Exit preview")').first();
    if ((await exitLink.count()) > 0) { await exitLink.click(); await settle(page); }

    /* ================= PAGE 05b - CREDENTIALS, PRINTERS, NETWORK DRIVES ================= */

    // 16 - credentials list (wider window so every column is visible)
    await page.setViewportSize({ width: 1700, height: 1000 });
    await open(page, '/agent/credentials.php', { expect: ['Credentials', 'Edge Firewall (FW-EDGE-01) - Admin'] });
    if (await page.locator('.alert-warning:has-text("vault is locked")').count()) throw new Error('vault is locked for the capture login');
    const fwRow = page.locator('tr:has-text("Edge Firewall (FW-EDGE-01) - Admin")').first();
    const pwCell = fwRow.locator('td').nth(3);
    await callout(page, [
      { selector: await mark(page.locator('button:has-text("New Credential")'), 'nc'), n: 1, side: 'tl' },
      { selector: await mark(page.locator('input[name=q]'), 'cq'), n: 2 },
      { selector: await mark(fwRow.locator('a.cred-name-link'), 'cname'), n: 3, side: 'tl' },
      { selector: await mark(pwCell.locator('button[aria-label="Show password"]'), 'eye'), n: 4, side: 'tl' },
      { selector: await mark(pwCell.locator('button.clipboardjs'), 'pw'), n: 5, side: 'tr' },
      { selector: await mark(fwRow.locator('.otp-reveal-trigger'), 'otp'), n: 6, side: 'tr' },
      { selector: await mark(fwRow.locator('a[href*="credentials.php?client_id="]'), 'dept'), n: 7, side: 'tr' },
      { selector: await mark(fwRow.locator('button[data-bs-toggle=dropdown]').last(), 'act'), n: 8, side: 'tr' },
    ]);
    await shot(page, next('cred-list'));
    await clearCallouts(page);

    // 17 - the eye button opens a pop-up with the password; hovering the OTP column shows the code
    await pwCell.locator('button[aria-label="Show password"]').click();
    await page.waitForSelector('.popover.show', { timeout: 5000 });
    await fwRow.locator('.otp-reveal-trigger').hover();
    await page.waitForFunction(() => /\d{6}/.test((document.querySelector('.otp-reveal-trigger span[id^=otp_]') || {}).textContent || ''), null, { timeout: 8000 });
    const box = await fwRow.boundingBox();
    await callout(page, [
      { selector: await mark(pwCell.locator('button[aria-label="Show password"]'), 'eye2'), n: 1, side: 'tl' },
      { selector: await mark(page.locator('.popover.show'), 'pop'), n: 2, side: 'tr' },
      { selector: await mark(pwCell.locator('button.clipboardjs'), 'copy2'), n: 3, side: 'br' },
      { selector: await mark(fwRow.locator('.otp-reveal-trigger'), 'otp2'), n: 4, side: 'tl' },
    ]);
    const revealName = next('cred-reveal');
    await page.screenshot({
      path: require('path').join(require('../lib.cjs').OUT, `${revealName}.png`),
      clip: { x: 290, y: Math.max(0, box.y - 60), width: 1300, height: box.height + 80 },
    });
    console.log('saved', `docs/user-guide/images/${revealName}.png`);
    await clearCallouts(page);
    await page.mouse.move(2, 2);
    await page.setViewportSize({ width: 1440, height: 900 });

    // 18 - the credential dialog: username, password (eye to show, copy), one-time code, history
    await open(page, '/agent/credentials.php');
    await page.locator('a.ajax-modal:has-text("Edge Firewall (FW-EDGE-01) - Admin")').first().click();
    await waitModal(page);
    await page.click('.modal.show [id^=cred-pw-toggle]');
    await page.waitForTimeout(300);
    await shot(page, next('cred-view'), { selector: MODAL });
    await closeModal(page);

    // 19/20 - New Credential from a department workspace (Relation tab exists there)
    await open(page, '/agent/credentials.php');
    const deptHref = await page.locator('tr:has-text("Edge Firewall (FW-EDGE-01) - Admin") a[href*="credentials.php?client_id="]').first().getAttribute('href');
    await tall(page, async () => {
      await open(page, `/agent/${deptHref}`, { expect: 'Credentials' });
      await page.click('button:has-text("New Credential")');
      await waitModal(page);
      await page.fill(`${MODAL} input[name=name]`, 'Guest Wi-Fi Controller');
      await page.fill(`${MODAL} input[name=username]`, 'wifiadmin');
      await page.click(`${MODAL} .generatePasswordBtn`);
      await page.waitForFunction(() => (document.getElementById('password') || {}).value, null, { timeout: 8000 });
      await page.locator(`${MODAL} input[name=password]`).locator('xpath=../..').locator('.fa-eye').first().click().catch(() => {});
      await page.waitForTimeout(300);
      await callout(page, [
        { selector: await mark(page.locator(`${MODAL} select.js-credential-type + .select2`), 'ty'), n: 1 },
        { selector: await mark(page.locator(`${MODAL} label.star-toggle`), 'fav'), n: 2, side: 'tr' },
        { selector: await mark(page.locator(`${MODAL} input[name=password]`), 'pwd'), n: 3 },
        { selector: await mark(page.locator(`${MODAL} .generatePasswordBtn`), 'gen'), n: 4, side: 'tr' },
        { selector: await mark(page.locator(`${MODAL} input[name=rotation_due_at]`), 'rot'), n: 5 },
        { selector: await mark(page.locator(`${MODAL} input[name=otp_secret]`), 'otps'), n: 6 },
      ]);
      await shot(page, next('cred-new'), { selector: MODAL });
      await clearCallouts(page);

      // 20 - Type = API Key relabels the fields and hides the one-time-code field
      await page.evaluate(() => document.querySelector('.modal.show select.js-credential-type').tomselect.setValue('API Key'));
      await page.waitForTimeout(500);
      await shot(page, next('cred-new-apikey'), { selector: MODAL });
      await page.evaluate(() => document.querySelector('.modal.show select.js-credential-type').tomselect.setValue('Login'));

      // 22 (taken now, numbered later) - Relation tab
      await page.click(`${MODAL} a:has-text("Relation")`);
      await page.waitForTimeout(500);
      relationShotFile = await shot(page, `${G}/__relation`, { selector: MODAL });
      await closeModal(page);
    });

    // 21 - the department workspace list: folder tabs, department sidebar, row menu
    await open(page, `/agent/${deptHref}`, { expect: 'Credentials' });
    await page.locator('tr:has-text("Edge Firewall (FW-EDGE-01) - Admin"), tr:has-text("Microsoft 365")').first().waitFor();
    await callout(page, [
      { selector: await mark(page.locator('ul.nav-tabs').first(), 'tabs'), n: 1, side: 'tr' },
      { selector: await mark(page.locator('button:has-text("New Credential")'), 'nc2'), n: 2, side: 'tl' },
      { selector: await mark(page.locator('aside a[href*="credentials.php?client_id="]').first(), 'sb'), n: 3, side: 'tr' },
    ]);
    await shot(page, next('cred-workspace'));
    await clearCallouts(page);

    // 22 - relation tab captured above; move into place
    fsRenameRelation(next('cred-relation'));

    // 23 - vault locked: a session without the encryption cookie
    {
      const { browser: b2, context: ctx2, page: p2 } = await launch();
      await login(p2, 'admin');
      await ctx2.clearCookies({ name: 'user_encryption_session_key' });
      await open(p2, '/agent/credentials.php', { expect: 'Credential vault is locked' });
      await callout(p2, [
        { selector: await mark(p2.locator('.alert-warning'), 'lock'), n: 1, side: 'tl' },
        { selector: await mark(p2.locator('button:has-text("New Credential")'), 'nc3'), n: 2, side: 'tl' },
      ]);
      await shot(p2, next('cred-vault-locked'));
      await b2.close();
    }

    // 24/25 - administration: Vault Encryption, Encryption Key Backup, 26 - Credential Restore
    await open(page, '/admin/settings_security.php', { expect: 'Vault Encryption' });
    await shot(page, next('vault-encryption'), { selector: '.card:has(.card-title:has-text("Vault Encryption"))' });
    await open(page, '/admin/backup.php', { expect: 'Encryption Key Backup' });
    {
      // the card stretches to the height of its neighbour; keep just the part with content
      const kcard = page.locator('.card:has(h3:has-text("Encryption Key Backup"))').first();
      const kb = await kcard.boundingBox();
      const kname = next('vault-key-backup');
      await page.mouse.move(2, 2);
      await page.screenshot({ path: require('path').join(require('../lib.cjs').OUT, `${kname}.png`),
        clip: { x: kb.x, y: kb.y, width: kb.width, height: Math.min(kb.height, 150) } });
      console.log('saved', `docs/user-guide/images/${kname}.png`);
    }
    await open(page, '/admin/credential_restore.php', { expect: 'Credential Restore' });
    await shot(page, next('credential-restore'), { selector: '.card:has(.card-title:has-text("Credential Restore"))' });

    // 27 - printers (app level shows company-wide printers), 28 - New Printer, 29 - details
    await open(page, '/agent/printers.php', { expect: ['Printers', 'HQ Copy Room - Ricoh IM C3000'] });
    const prow = page.locator('tr:has-text("HQ Copy Room - Ricoh IM C3000")').first();
    await callout(page, [
      { selector: await mark(page.locator('button:has-text("New Printer")'), 'np'), n: 1, side: 'tl' },
      { selector: await mark(page.locator('input[name=q]'), 'pq2'), n: 2 },
      { selector: await mark(page.locator('a:has-text("Archived")').first(), 'parch'), n: 3, side: 'tr' },
      { selector: await mark(prow.locator('a.ajax-modal'), 'pname'), n: 4, side: 'tl' },
      { selector: await mark(prow.locator('button[data-bs-toggle=dropdown]'), 'pact'), n: 5, side: 'tr' },
    ]);
    await shot(page, next('printers-list'));
    await clearCallouts(page);
    await tall(page, async () => {
      await page.click('button:has-text("New Printer")');
      await waitModal(page);
      await page.fill(`${MODAL} input[name=name]`, 'HQ Reception - HP LaserJet Pro M404dn');
      await page.fill(`${MODAL} input[name=ip_address]`, '10.10.20.42');
      await page.fill(`${MODAL} input[name=physical_location]`, 'Front reception desk');
      await page.fill(`${MODAL} input[name=model]`, 'HP LaserJet Pro M404dn');
      await shot(page, next('printer-new'), { selector: MODAL });
      await closeModal(page);
    });
    await prow.locator('a.ajax-modal').first().click();
    await waitModal(page);
    await shot(page, next('printer-details'), { selector: MODAL });
    await closeModal(page);

    // 30 - network drives, 31 - New Network Drive
    await open(page, '/agent/network_drives.php', { expect: ['Network Drives', 'Company Share'] });
    const drow = page.locator('tr:has-text("Company Share")').first();
    await callout(page, [
      { selector: await mark(page.locator('button:has-text("New Network Drive")'), 'nd'), n: 1, side: 'tl' },
      { selector: await mark(drow.locator('a.ajax-modal'), 'dname'), n: 2, side: 'tl' },
      { selector: await mark(drow.locator('td').nth(2), 'dletter'), n: 3, side: 'tl' },
      { selector: await mark(drow.locator('code'), 'dpath'), n: 4, side: 'tl' },
    ]);
    await shot(page, next('drives-list'));
    await clearCallouts(page);
    await tall(page, async () => {
      await page.click('button:has-text("New Network Drive")');
      await waitModal(page);
      await page.fill(`${MODAL} input[name=name]`, 'HR Share');
      await page.evaluate(() => document.querySelector('.modal.show select[name=letter]').tomselect.setValue('H:'));
      await page.fill(`${MODAL} input[name=path]`, '\\\\SRV-FILE-01\\HR');
      await page.fill(`${MODAL} input[name=purpose]`, 'Personnel forms and onboarding packs');
      await shot(page, next('drive-new'), { selector: MODAL });
      await closeModal(page);
    });

    console.log('done -', n, 'screenshots');
  } finally {
    await browser.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });

