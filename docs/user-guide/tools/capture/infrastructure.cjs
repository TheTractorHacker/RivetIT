// Screenshots for docs/user-guide/06-assets.md, 06b-locations-vendors-licenses-domains-certificates.md
// and 06c-networks-racks-services-contracts-files.md (group "infrastructure").
// Needs seed/00-core.sql and seed/10-infrastructure.sql applied.
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/infrastructure.cjs
//
// Read-only with respect to app data: it opens pop-ups and menus and may type search text, but it never
// saves, archives, links or deletes anything.

const fs = require('fs');
const path = require('path');
const { launch, login, goto, shot, callout, clearCallouts, settle, OUT } = require('../lib.cjs');

const G = 'infrastructure';
const W = 1440;
const H = 900;
const TALL = 1500;   // tall viewport for long pop-up forms

// ---------------------------------------------------------------- helpers ----
function fail(msg) { throw new Error('CAPTURE FAILED: ' + msg); }

async function check(page, { rows = 0, text = [] } = {}) {
  const body = await page.evaluate(() => document.body.innerText);
  if (/(Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:)/.test(body)) fail(`PHP error text on ${page.url()}`);
  if (/Nothing to see here|You don't have access|Access Denied/.test(body)) fail(`Unexpected page on ${page.url()}`);
  if (rows) {
    const n = await page.locator('table tbody tr').count();
    if (n < rows) fail(`expected at least ${rows} table rows on ${page.url()}, found ${n}`);
  }
  for (const t of text) if (!body.includes(t)) fail(`"${t}" not found on ${page.url()}`);
}

async function mark(locator, id) {
  const l = locator.first();
  if (!(await l.count())) fail(`cannot mark ${id}: element not found`);
  await l.evaluate((el, v) => el.setAttribute('data-ug', v), id);
  return `[data-ug="${id}"]`;
}

async function shotClip(page, name, clip) {
  const file = path.join(OUT, G, `${name}.png`);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  await page.mouse.move(2, 2);
  await settle(page, 400);
  await page.screenshot({ path: file, clip, fullPage: true });
  console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..'), file));
}

// Union of the bounding boxes of some elements, padded, in page coordinates.
async function boxOf(page, selectors, pad = 8) {
  return page.evaluate(({ selectors, pad }) => {
    let x1 = 1e9, y1 = 1e9, x2 = 0, y2 = 0;
    for (const s of selectors) {
      const el = document.querySelector(s);
      if (!el) continue;
      const r = el.getBoundingClientRect();
      x1 = Math.min(x1, r.left + scrollX); y1 = Math.min(y1, r.top + scrollY);
      x2 = Math.max(x2, r.right + scrollX); y2 = Math.max(y2, r.bottom + scrollY);
    }
    return { x: Math.max(0, x1 - pad), y: Math.max(0, y1 - pad), width: x2 - x1 + 2 * pad, height: y2 - y1 + 2 * pad };
  }, { selectors, pad });
}

async function openModal(page, trigger, { tall = false } = {}) {
  if (tall) await page.setViewportSize({ width: W, height: TALL });
  await page.locator(trigger).first().click();
  await page.waitForSelector('.modal.show .modal-content');
  await settle(page, 800);
}
async function closeModal(page) {
  await page.keyboard.press('Escape');
  await page.waitForSelector('.modal.show', { state: 'detached', timeout: 5000 }).catch(() => {});
  await page.setViewportSize({ width: W, height: H });
  await settle(page, 300);
}
async function modalTab(page, name) {
  await page.click(`.modal.show .nav-link:has-text("${name}")`);
  await settle(page, 500);
}
const modalShot = (page, name) => shot(page, `${G}/${name}`, { selector: '.modal.show .modal-content' });

// Read ids through the UI so the script never hard-codes them.
async function deptId(page, name) {
  await goto(page, '/agent/assets.php');
  const v = await page.evaluate((n) => {
    const o = [...document.querySelectorAll('select[name=client] option')].find((x) => x.textContent.trim() === n);
    return o ? o.value : null;
  }, name);
  if (v) return v;
  await goto(page, '/agent/locations.php');
  const v2 = await page.evaluate((n) => {
    const o = [...document.querySelectorAll('select[name=client] option')].find((x) => x.textContent.trim() === n);
    return o ? o.value : null;
  }, name);
  if (!v2) fail(`department ${name} not found`);
  return v2;
}
async function assetId(page, name) {
  await goto(page, `/agent/assets.php?q=${encodeURIComponent(name)}`);
  const id = await page.evaluate((n) => {
    const tr = [...document.querySelectorAll('tr[data-asset-id]')].find((r) => r.innerText.includes(n));
    return tr ? tr.getAttribute('data-asset-id') : null;
  }, name);
  if (!id) {
    await goto(page, `/agent/assets.php?q=${encodeURIComponent(name)}&archived=1`);
    const id2 = await page.evaluate((n) => {
      const tr = [...document.querySelectorAll('tr[data-asset-id]')].find((r) => r.innerText.includes(n));
      return tr ? tr.getAttribute('data-asset-id') : null;
    }, name);
    if (!id2) fail(`asset ${name} not found`);
    return id2;
  }
  return id;
}
async function rowLinkId(page, url, text, param) {
  await goto(page, url);
  const href = await page.evaluate((t) => {
    const a = [...document.querySelectorAll('tbody tr a')].find((x) => x.textContent.includes(t) && /details\.php/.test(x.getAttribute('href') || ''));
    return a ? a.getAttribute('href') : null;
  }, text);
  if (!href) fail(`row link for ${text} not found on ${url}`);
  return new RegExp(param + '=(\\d+)').exec(href)[1];
}

// ------------------------------------------------------------------- main ----
(async () => {
  const { browser, page } = await launch({ width: W, height: H });
  await login(page, 'admin');

  const exec = await deptId(page, 'Executive Office');
  const prod = await deptId(page, 'Production');
  const fin = await deptId(page, 'Finance & Accounting');
  const eng = await deptId(page, 'Engineering');
  const srvId = await assetId(page, 'SRV-FILE-01');
  const finLaptopId = await assetId(page, 'LT-FIN-02');

  // =================================================== 06-assets.md ===========
  // 01 - the list, with numbered call-outs
  await goto(page, '/agent/assets.php?type=workstation');
  await check(page, { rows: 5, text: ['LT-EXEC-01', 'Workstations'] });
  const cTabs = await mark(page.locator('.btn-toolbar .btn-group'), 'tabs');
  const cSearch = await mark(page.locator('input[name=q]').locator('xpath=..'), 'search');
  const cNew = await mark(page.locator('.card-tools .btn-group').first(), 'new');
  const cCell = await mark(page.locator('tr[data-asset-id] td[data-field=status]'), 'cell');
  const cArch = await mark(page.locator('a:has-text("Archived")').first(), 'arch');
  await callout(page, [
    { selector: cTabs, n: 1 }, { selector: cSearch, n: 2 }, { selector: cNew, n: 3, side: 'tr' },
    { selector: cCell, n: 4, side: 'tr' }, { selector: cArch, n: 5, side: 'tr' },
  ]);
  await shot(page, `${G}/01-assets-list`);
  await clearCallouts(page);

  // 02 - optional columns: purchase date and warranty expiry
  await goto(page, '/agent/assets.php?type=workstation&show_column[]=Purchase_Date&show_column[]=Warranty_Expire');
  await check(page, { rows: 5, text: ['Purchase Date', 'Warranty Expire'] });
  await shot(page, `${G}/02-assets-columns`);

  // 03 - inline status change (open only)
  await goto(page, '/agent/assets.php?type=workstation');
  await page.locator('tr[data-asset-id] td[data-field=status] .asset-inline-trigger').first().click();
  await page.waitForSelector('.ts-dropdown', { state: 'visible' });
  await settle(page, 500);
  const stRow = page.locator('tr[data-asset-id]').first();
  let bb = await stRow.boundingBox();
  await shotClip(page, '03-inline-status', { x: 280, y: Math.max(0, bb.y - 60), width: 1140, height: 330 });
  await page.keyboard.press('Escape');

  // 04 - inline "Assigned To" search (typed text only, never saved)
  await goto(page, '/agent/assets.php?type=workstation');
  await page.locator('tr[data-asset-id] td[data-field=contact] .asset-inline-trigger').first().click();
  await page.waitForSelector('.ts-control input', { state: 'visible' });
  await page.keyboard.type('Ma', { delay: 80 });
  await page.waitForTimeout(1200);
  bb = await page.locator('tr[data-asset-id]').first().boundingBox();
  await shotClip(page, '04-inline-assign', { x: 280, y: Math.max(0, bb.y - 60), width: 1140, height: 360 });
  await page.keyboard.press('Escape');

  // 05 - bulk actions (department workspace shows the full menu)
  await goto(page, `/agent/assets.php?client_id=${exec}&type=workstation`);
  await page.locator('input.bulk-select').nth(0).check();
  await page.locator('input.bulk-select').nth(1).check();
  await settle(page, 300);
  await page.locator('#bulkActionButton > button').click();
  await settle(page, 400);
  await shot(page, `${G}/05-bulk-actions`);

  // 06-10 - New Asset pop-up
  await goto(page, `/agent/assets.php?client_id=${exec}`);
  await openModal(page, 'button.ajax-modal:has-text("New Asset")', { tall: true });
  await page.selectOption('.modal.show select[name=type]', 'Laptop').catch(() => {});
  await page.fill('.modal.show input[name=name]', 'LT-EXEC-09');
  await page.fill('.modal.show input[name=asset_tag]', 'SRM-0120');
  await page.fill('.modal.show input[name=make]', 'Lenovo');
  await page.fill('.modal.show input[name=model]', 'ThinkPad T14 Gen 4');
  await modalShot(page, '06-new-asset-details');
  await modalTab(page, 'Assignment');
  await page.fill('.modal.show input[name=physical_location]', 'Executive suite, office 3');
  await modalShot(page, '07-new-asset-assignment');
  await modalTab(page, 'Network');
  await modalShot(page, '08-new-asset-network');
  await modalTab(page, 'Purchase');
  await modalShot(page, '09-new-asset-purchase');
  await closeModal(page);

  await goto(page, `/agent/assets.php?client_id=${exec}`);
  await page.locator('.card-tools .dropdown-toggle-split').first().click();
  await page.locator('a.dropdown-item:has-text("Import")').first().click();
  await page.waitForSelector('.modal.show .modal-content');
  await settle(page, 600);
  await modalShot(page, '10-import-assets');
  await closeModal(page);

  // 11 - asset page tour
  await goto(page, `/agent/asset_details.php?client_id=${exec}&asset_id=${srvId}`);
  await check(page, { text: ['SRV-FILE-01', 'Interfaces'] });
  const aEdit = await mark(page.locator('#asset-details-content .card-header button.ajax-modal').first(), 'aedit');
  const aNew = await mark(page.locator('#asset-details-content .btn-group.mb-3 .btn-primary').first(), 'anew');
  const aLink = await mark(page.locator('#asset-details-content .btn-group.mb-3 .btn-outline-primary').first(), 'alink');
  const aIf = await mark(page.locator('#asset-details-content .card:has(h3:has-text("Interfaces"))'), 'aif');
  const aAssign = await mark(page.locator('#asset-details-content .card:has(h5:has-text("Assignment"))'), 'aassign');
  await callout(page, [
    { selector: aEdit, n: 1, side: 'tr' }, { selector: aNew, n: 2 }, { selector: aLink, n: 3 },
    { selector: aIf, n: 4 }, { selector: aAssign, n: 5 },
  ]);
  await shot(page, `${G}/11-asset-page`);
  await clearCallouts(page);

  // 12 - linked items further down the same page
  const y1 = await page.evaluate(() => {
    const h = [...document.querySelectorAll('#asset-details-content h3.card-title')].find((e) => /Licenses/.test(e.textContent));
    return h ? h.closest('.card').getBoundingClientRect().top + scrollY : null;
  });
  const y2 = await page.evaluate(() => {
    const h = [...document.querySelectorAll('#asset-details-content h3.card-title')].find((e) => /Linked Services/.test(e.textContent));
    return h ? h.closest('.card').getBoundingClientRect().bottom + scrollY : null;
  });
  if (y1 === null || y2 === null) fail('linked cards not found on asset page');
  await shotClip(page, '12-asset-linked-items', { x: 560, y: y1 - 10, width: 870, height: Math.min(y2 - y1 + 20, 1300) });

  // 13 - the Link menu
  await goto(page, `/agent/asset_details.php?client_id=${exec}&asset_id=${srvId}`);
  await page.locator('#asset-details-content .btn-group.mb-3 .btn-outline-primary').first().click();
  await settle(page, 400);
  bb = await page.locator('#asset-details-content .btn-group.mb-3').first().boundingBox();
  await shotClip(page, '13-asset-link-menu', { x: 560, y: bb.y - 10, width: 560, height: 300 });
  await page.keyboard.press('Escape');

  // 14 - assignment history (laptop that changed hands)
  await goto(page, `/agent/asset_details.php?client_id=${fin}&asset_id=${finLaptopId}`);
  await check(page, { text: ['Assignment History'] });
  await shot(page, `${G}/14-asset-assignment-history`);

  // 15 - Edit Asset pop-up, History tab
  await goto(page, `/agent/asset_details.php?client_id=${exec}&asset_id=${srvId}`);
  await openModal(page, '#asset-details-content .card-header button.ajax-modal', { tall: false });
  await modalTab(page, 'History');
  await modalShot(page, '15-edit-asset-history');
  await closeModal(page);

  // 16 - archived assets with the row menu open
  await goto(page, `/agent/assets.php?client_id=${fin}&archived=1`);
  await check(page, { rows: 1, text: ['LT-OLD-03'] });
  await page.locator('tbody tr').first().locator('button:has(.fa-ellipsis-h)').click();
  await settle(page, 400);
  await shot(page, `${G}/16-archived-assets`);
  await page.keyboard.press('Escape');

  // ============================================ 06b-locations-... .md ========
  // 17 - Infrastructure group in the sidebar
  await goto(page, '/agent/locations.php');
  const navBox = await page.locator('#nav-group-infrastructure').locator('xpath=..').boundingBox();
  await shotClip(page, '17-sidebar-infrastructure', { x: 0, y: navBox.y - 6, width: 256, height: navBox.height + 12 });

  // 18 - locations list
  await goto(page, '/agent/locations.php');
  await check(page, { rows: 3, text: ['Headquarters - Madison'] });
  const lMap = await mark(page.locator('table tbody tr').first().locator('td').nth(5), 'ldept');
  const lNew = await mark(page.locator('.card-tools .btn-group').first(), 'lnew');
  const lRow = await mark(page.locator('table tbody tr').first().locator('td').nth(1), 'lname');
  const lHours = await mark(page.locator('table tbody tr').first().locator('td').nth(4), 'lhours');
  await callout(page, [{ selector: lNew, n: 1, side: 'tr' }, { selector: lRow, n: 2 }, { selector: lHours, n: 3 }, { selector: lMap, n: 4 }]);
  await shot(page, `${G}/18-locations-list`);
  await clearCallouts(page);

  // 19 - location pop-up, Departments tab
  await goto(page, '/agent/locations.php');
  await openModal(page, 'button.ajax-modal:has-text("New Location")');
  await modalTab(page, 'Departments');
  await page.locator('.modal.show input[name="departments[]"]').nth(1).check();
  await page.locator('.modal.show input[name="departments[]"]').nth(2).check();
  await modalShot(page, '19-location-departments');
  await closeModal(page);

  // 20 - vendors list
  await goto(page, '/agent/vendors.php');
  await check(page, { rows: 4, text: ['Lakeshore Technology Partners'] });
  await shot(page, `${G}/20-vendors-list`);

  // 21 - vendor details pop-up
  await page.locator('tbody tr a.ajax-modal', { hasText: 'Lakeshore' }).first().click();
  await page.waitForSelector('.modal.show .modal-content');
  await settle(page, 600);
  await modalShot(page, '21-vendor-details');
  await closeModal(page);

  // 22 - New Vendor pop-up (Details tab)
  await openModal(page, 'button.ajax-modal:has-text("New Vendor")', { tall: true });
  await page.fill('.modal.show input[name=name]', 'Northgate Cabling');
  await page.fill('.modal.show input[name=description]', 'Structured cabling contractor');
  await page.fill('.modal.show input[name=account_number]', 'NGC-2210');
  await modalShot(page, '22-new-vendor');
  await closeModal(page);

  // 23 - licenses list
  await goto(page, '/agent/software.php');
  await check(page, { rows: 5, text: ['Microsoft 365 Business Premium', 'Adobe Acrobat Pro'] });
  const sSeat = await mark(page.locator('tbody tr', { hasText: 'Microsoft 365' }).locator('td').nth(3), 'sseat');
  const sSoon = await mark(page.locator('tbody tr.table-warning'), 'ssoon');
  const sGone = await mark(page.locator('tbody tr.table-secondary').first(), 'sgone');
  await callout(page, [{ selector: sSeat, n: 1, side: 'tr' }, { selector: sSoon, n: 2, side: 'tl' }, { selector: sGone, n: 3, side: 'tl' }]);
  await shot(page, `${G}/23-licenses-list`);
  await clearCallouts(page);

  // 24, 25 - New License pop-up (department workspace has the Devices and Users tabs)
  await goto(page, `/agent/software.php?client_id=${eng}`);
  await openModal(page, 'button.ajax-modal:has-text("New License")', { tall: true });
  await modalTab(page, 'Licensing');
  await page.selectOption('.modal.show select[name=license_type]', 'Device');
  await page.fill('.modal.show input[name=seats]', '4');
  await page.fill('.modal.show input[name=key]', 'DEMO-XXXXX-XXXXX');
  await modalShot(page, '24-new-license-licensing');
  await modalTab(page, 'Devices');
  await page.locator('.modal.show input.asset-checkbox').first().check();
  await modalShot(page, '25-new-license-devices');
  await closeModal(page);

  // 26 - domains list
  await goto(page, '/agent/domains.php');
  await check(page, { rows: 4, text: ['summitridge.example'] });
  const dExp = await mark(page.locator('tbody tr.table-warning').first().locator('td').nth(6), 'dexp');
  const dReg = await mark(page.locator('tbody tr', { hasText: 'summitridge.example' }).filter({ hasText: 'NameHarbor' }).first().locator('td').nth(2), 'dreg');
  await callout(page, [{ selector: dReg, n: 1 }, { selector: dExp, n: 2, side: 'tr' }]);
  await shot(page, `${G}/26-domains-list`);
  await clearCallouts(page);

  // 27 - domain details
  const domId = await rowLinkId(page, '/agent/domains.php?q=summitridge.example', 'summitridge.example', 'id');
  await goto(page, `/agent/domain_details.php?client_id=${exec}&id=${domId}`);
  await check(page, { text: ['Who', 'WHOIS & DNS Records'] });
  await shot(page, `${G}/27-domain-details`, { fullPage: true });

  // 28 - New Domain pop-up
  await goto(page, `/agent/domains.php?client_id=${exec}`);
  await openModal(page, 'button.ajax-modal:has-text("New Domain")', { tall: true });
  await page.fill('.modal.show input[name=name]', 'summitridge-events.example');
  await page.fill('.modal.show input[name=description]', 'Trade show microsite');
  await modalShot(page, '28-new-domain');
  await closeModal(page);

  // 29 - certificates list
  await goto(page, '/agent/certificates.php');
  await check(page, { rows: 4, text: ['Employee portal'] });
  await shot(page, `${G}/29-certificates-list`);

  // 30 - certificate details
  const certId = await rowLinkId(page, '/agent/certificates.php?q=Employee', 'Employee portal', 'id');
  await goto(page, `/agent/certificate_details.php?client_id=${exec}&id=${certId}`);
  await check(page, { text: ['Renew soon', 'Public Key'] });
  await shot(page, `${G}/30-certificate-details`);

  // 31 - New Certificate pop-up, Certificate tab
  await goto(page, `/agent/certificates.php?client_id=${exec}`);
  await openModal(page, 'button.ajax-modal:has-text("New Certificate")', { tall: true });
  await page.fill('.modal.show input[name=name]', 'Intranet wiki');
  await modalTab(page, 'Certificate');
  await page.fill('.modal.show input[name=domain]', 'wiki.summitridge.example');
  await page.fill('.modal.show input[name=issued_by]', 'Harbor Trust CA');
  await modalShot(page, '31-new-certificate');
  await closeModal(page);

  // ============================================ 06c-networks-... .md =========
  // 32 - department workspace sidebar, Documentation group
  await goto(page, `/agent/networks.php?client_id=${exec}`);
  const dnav = await page.evaluate(() => {
    const items = [...document.querySelectorAll('aside .nav-section-title')].find((e) => /DOCUMENTATION/.test(e.textContent));
    const a = document.querySelector('aside');
    const r = items.getBoundingClientRect();
    return { top: r.top + scrollY, right: a.getBoundingClientRect().right };
  });
  await page.setViewportSize({ width: W, height: 1200 });
  await goto(page, `/agent/networks.php?client_id=${exec}`);
  const cNets = await mark(page.locator('aside a:has-text("Networks")').first(), 'snets');
  const cRacks = await mark(page.locator('aside a:has-text("Racks")').first(), 'sracks');
  const cSvc = await mark(page.locator('aside a:has-text("Services")').first(), 'ssvc');
  const cCon = await mark(page.locator('aside a:has-text("Contracts")').first(), 'scon');
  const cFiles = await mark(page.locator('aside a:has-text("Files")').first(), 'sfiles');
  await callout(page, [
    { selector: cNets, n: 1, side: 'tr' }, { selector: cRacks, n: 2, side: 'tr' }, { selector: cSvc, n: 3, side: 'tr' },
    { selector: cCon, n: 4, side: 'tr' }, { selector: cFiles, n: 5, side: 'tr' },
  ]);
  await shotClip(page, '32-department-sidebar', { x: 0, y: 70, width: 256, height: 1130 });
  await clearCallouts(page);
  await page.setViewportSize({ width: W, height: H });

  // 33 - networks list
  await goto(page, `/agent/networks.php?client_id=${exec}`);
  await check(page, { rows: 2, text: ['HQ Offices'] });
  await shot(page, `${G}/33-networks-list`);

  // 34 - New Network pop-up, Network tab
  await openModal(page, 'button.ajax-modal:has-text("New Network")', { tall: true });
  await page.fill('.modal.show input[name=name]', 'HQ Guest Wi-Fi');
  await modalTab(page, 'Network');
  await page.fill('.modal.show input[name=vlan]', '30');
  await page.fill('.modal.show input[name=network]', '10.10.30.0/24');
  await page.fill('.modal.show input[name=gateway]', '10.10.30.1');
  await page.fill('.modal.show input[name=dhcp_range]', '10.10.30.100-10.10.30.250');
  await modalShot(page, '34-new-network');
  await closeModal(page);

  // 35 - rack
  await goto(page, `/agent/racks.php?client_id=${exec}`);
  await check(page, { text: ['HQ Server Room - Rack A'] });
  const rackCard = page.locator('.card.card-dark .card.card-dark').first();
  const rmark = await mark(rackCard, 'rack');
  await shot(page, `${G}/35-rack`, { selector: rmark });

  // 36 - Add Device to rack
  await rackCard.locator('button[data-bs-toggle=dropdown]').first().click();
  await page.locator('a.dropdown-item:has-text("Add Device")').first().click();
  await page.waitForSelector('.modal.show .modal-content');
  await settle(page, 700);
  await page.fill('.modal.show input[name=unit_start]', '4').catch(() => {});
  await modalShot(page, '36-rack-add-device');
  await closeModal(page);

  // 37 - services list
  await goto(page, `/agent/services.php?client_id=${exec}`);
  await check(page, { rows: 3, text: ['Company File Shares'] });
  await shot(page, `${G}/37-services-list`);

  // 38 - service details pop-up
  await page.locator('tbody tr a.ajax-modal', { hasText: 'Company File Shares' }).first().click();
  await page.waitForSelector('.modal.show .modal-content');
  await settle(page, 800);
  await modalShot(page, '38-service-details');
  await closeModal(page);

  // 39 - contracts list (Production has a contract that is due for renewal)
  await goto(page, `/agent/contracts.php?client_id=${prod}`);
  await check(page, { rows: 1, text: ['Production Floor Support SLA', 'Due Soon'] });
  await shot(page, `${G}/39-contracts-list`);

  // 40 - New Contract pop-up
  await openModal(page, 'button.ajax-modal:has-text("New Contract")', { tall: true });
  await page.fill('.modal.show input[name=contract_name]', 'Quality Lab Support SLA');
  await modalShot(page, '40-new-contract');
  await closeModal(page);

  // 41 - files page
  await goto(page, `/agent/files.php?client_id=${exec}`);
  await check(page, { text: ['Network Diagrams', 'Runbooks'] });
  await shot(page, `${G}/41-files-page`);

  // 42 - the New menu on the Files page, inside a folder
  const rb = page.locator('.nav-pills a:has-text("Runbooks")').first();
  await rb.click();
  await settle(page, 800);
  await page.locator('.card-tools .dropdown-toggle').first().click();
  await settle(page, 400);
  await shot(page, `${G}/42-files-new-menu`);
  await page.keyboard.press('Escape');

  await browser.close();
  console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });
