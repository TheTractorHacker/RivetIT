// Screenshots for docs/user-guide/09-endpoints-and-integrations.md
//
//   cd /home/user/RivetIT && NODE_PATH=$(npm root -g) node docs/user-guide/tools/capture/endpoints.cjs
//   (DEMO_URL=http://127.0.0.1:PORT points it at another copy of the demo app)
//
// Needs seed/60-endpoints.php applied. READ-ONLY: it opens pages and pop-ups, types illustrative values
// into a few forms and closes them again; nothing is saved.
//
// There is no real Comet Backup server or Tactical RMM in the demo, so this script starts a tiny mock
// on 127.0.0.1:18060 (the URL the seed stores for both) for the duration of the run. The mock answers
// the handful of API calls the Backups page and the RMM "live" tabs make with clearly sample data.
// It is stopped again when the script ends.

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { launch, login, goto, callout, clearCallouts, settle, OUT } = require('../lib.cjs');

const MOCK_PORT = 18060;
const G = 'endpoints';

// ---------------------------------------------------------------------------------------------
// Mock Comet Backup + Tactical RMM API
// ---------------------------------------------------------------------------------------------
const sha = (algo, s) => crypto.createHash(algo).update(s).digest('hex');
const agentId = (name) => sha('sha1', 'summit-ridge-demo-agent-' + name).slice(0, 40);   // same rule as the seed
const cometDevId = (name) => sha('sha256', 'comet-' + name);
const nowS = () => Math.floor(Date.now() / 1000);
const GB = 1024 ** 3, MB = 1024 ** 2;

// Comet: username -> devices. job = [status, minutes ago, upload bytes, error] or null (no backup yet)
const COMET = {
  'summitridge-exec':    [['LT-EXEC-01', 200, [5000, 190, 1.8 * GB, '']], ['LT-OPS-12', 60 * 24, null]],
  'summitridge-fin':     [['LT-FIN-02', 200, [5000, 300, 2.6 * GB, '']]],
  'summitridge-hr':      [['DT-HR-01', 200, [5000, 26 * 60, 640 * MB, '']]],
  'summitridge-sales':   [['DT-SALES-03', 200, [5000, 74 * 60, 910 * MB, '']], ['MAC-MKT-03', 90, [5000, 240, 3.1 * GB, '']]],
  'summitridge-plant':   [['PC-PLANT-07', 200, [5000, 360, 420 * MB, '']], ['WS-QC-11', 200, [6001, 10, 85 * MB, '']]],
  'summitridge-whse':    [['DT-WHSE-05', 200, [5000, 30 * 60, 510 * MB, '']]],
  'summitridge-eng':     [['LT-ENG-04', 200, [7001, 300, 14.2 * GB, '3 files were skipped because another program had them open']]],
  'summitridge-servers': [['SRV-FILE-01', 200, [7002, 380, 0, 'VSS snapshot failed while reading D:\\Shares (access is denied)']],
                          ['SRV-APP-02', 200, [5000, 120, 22.7 * GB, '']]],
};

function cometUsers() {
  const out = {};
  for (const [user, devs] of Object.entries(COMET)) {
    out[user] = { Username: user, Devices: {} };
    for (const [name, regDays] of devs) {
      out[user].Devices[cometDevId(name)] = { FriendlyName: name, RegistrationTime: nowS() - regDays * 86400 };
    }
  }
  return out;
}
function cometJobs(user) {
  const jobs = [];
  for (const [name, , job] of COMET[user] || []) {
    if (!job) continue;
    const [status, minAgo, size, err] = job;
    const start = nowS() - minAgo * 60;
    jobs.push({ DeviceID: cometDevId(name), Username: user, Classification: 4001, Status: status, StartTime: start,
                EndTime: status === 6001 ? 0 : start + 420, UploadSize: Math.round(size), TotalSize: Math.round(size), ErrorString: err });
    // an older successful run, so "last job per device" really picks the newest
    jobs.push({ DeviceID: cometDevId(name), Username: user, Classification: 4001, Status: 5000, StartTime: start - 86400, EndTime: start - 86400 + 400,
                UploadSize: 400 * MB, TotalSize: 400 * MB, ErrorString: '' });
  }
  return jobs;
}

// Tactical RMM: sample "live" data for the asset page tabs. Known device names get specific data.
const KNOWN_AGENTS = ['LT-EXEC-01', 'LT-FIN-02', 'DT-HR-01', 'DT-SALES-03', 'LT-ENG-04', 'PC-PLANT-07', 'SRV-FILE-01', 'WS-QC-11',
                      'LT-OPS-12', 'SRV-APP-02', 'DT-WHSE-05', 'MAC-MKT-03'];
const nameOfAgent = (id) => KNOWN_AGENTS.find((n) => agentId(n) === id) || null;
function tacticalAgent(id) {
  return { agent_id: id, hostname: nameOfAgent(id) || 'DEMO-HOST', make_model: 'Dell Inc. Latitude 5440',
           cpu_model: ['13th Gen Intel(R) Core(TM) i5-1345U'], total_ram: 16, graphics: 'Intel(R) Iris(R) Xe Graphics',
           local_ips: '10.0.20.12, fe80::5a1c:3ff:fe1a:44', physical_disks: ['KBG50ZNV512G KIOXIA 512.1 GB (Sample NVMe)'],
           disks: [{ device: 'C:', fstype: 'NTFS', total: '475.7 GB', used: '456.8 GB', free: '18.9 GB', percent: 96 },
                   { device: 'D:', fstype: 'NTFS', total: '1.8 TB', used: '0.4 TB', free: '1.4 TB', percent: 22 }] };
}
function tacticalChecks(id) {
  const name = nameOfAgent(id);
  const iso = (m) => new Date(Date.now() - m * 60000).toLocaleString('sv-SE', { timeZone: 'America/Chicago' }).replace(' ', 'T');
  const rows = [
    ['Disk Space Check: Drive C', name === 'LT-FIN-02' ? 'failing' : 'passing', name === 'LT-FIN-02' ? 'Total: 475.7GB, Free: 18.9GB (96% used)' : 'Total: 475.7GB, Free: 210.3GB (56% used)', 3],
    ['Memory Check', 'passing', 'Total: 16GB, Used: 12.6GB (79%)', 2],
    ['CPU Load Check', 'passing', 'Average CPU Load: 18%', 2],
    ['Service Check: Print Spooler (Spooler)', 'passing', 'Status RUNNING', 4],
    ['Ping Check: 10.0.0.1', 'passing', 'Reply from 10.0.0.1: time=1ms', 1],
  ];
  return rows.map(([desc, status, info, m]) => ({ readable_desc: desc, check_type: 'diskspace', check_result: { status, more_info: info, last_run: iso(m) } }));
}
const tacticalPatches = () => [
  { kb: 'KB5043076', title: '2024-09 Cumulative Update for Windows 11 Version 23H2 for x64-based Systems', severity: 'Critical', installed: false, downloaded: true },
  { kb: 'KB5042421', title: '2024-09 Security Update for .NET Framework 3.5 and 4.8.1', severity: 'Important', installed: false, downloaded: false },
  { kb: 'KB890830', title: 'Windows Malicious Software Removal Tool x64 - v5.129', severity: 'Moderate', installed: false, downloaded: false },
  { kb: 'KB5039302', title: '2024-06 Cumulative Update for Windows 11 Version 23H2', severity: 'Critical', installed: true, downloaded: true },
];

function startMock() {
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      const json = (o, code = 200) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
      const url = req.url.split('?')[0];
      const form = new URLSearchParams(body);
      let m;
      // ---- Comet Backup Server ----
      if (url === '/api/v1/admin/meta/version') return json({ Version: '24.9.1' });
      if (url === '/api/v1/admin/list-users-full') return json(cometUsers());
      if (url === '/api/v1/admin/get-jobs-for-user') return json(cometJobs(form.get('TargetUser')));
      if (url === '/api/v1/admin/get-jobs-recent') return json([]);
      if (url === '/api/v1/admin/account/session-start') return json({ SessionKey: 'demo-session-key' });
      // ---- Tactical RMM ----
      if (url === '/agents/') return json(KNOWN_AGENTS.map(agentId).map(tacticalAgent));
      if ((m = url.match(/^\/agents\/([^/]+)\/checks\/$/))) return json(tacticalChecks(m[1]));
      if ((m = url.match(/^\/agents\/([^/]+)\/$/))) return json(tacticalAgent(m[1]));
      if ((m = url.match(/^\/winupdate\/([^/]+)\/$/))) return json(tacticalPatches());
      if ((m = url.match(/^\/software\/([^/]+)\/$/))) return json({ software: [{ name: '7-Zip 23.01 (x64)', version: '23.01', publisher: 'Igor Pavlov' }, { name: 'Microsoft 365 Apps for enterprise', version: '16.0.17726', publisher: 'Microsoft Corporation' }] });
      if ((m = url.match(/^\/services\/([^/]+)\/$/))) return json([{ name: 'Spooler', display_name: 'Print Spooler', status: 'running', start_type: 'Automatic' }]);
      if (url === '/alerts/') return json([]);
      json({ detail: 'Not found' }, 404);
    });
  });
  return new Promise((resolve, reject) => {
    server.once('error', (e) => reject(new Error(`Cannot start the mock on port ${MOCK_PORT}: ${e.message}`)));
    server.listen(MOCK_PORT, '127.0.0.1', () => resolve(server));
  });
}

// ---------------------------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------------------------
const file = (rel) => path.join(OUT, G, rel + '.png');
async function clean(page, label) {
  const html = await page.content();
  if (/Fatal error|Parse error|<b>Warning<\/b>|<b>Notice<\/b>|Uncaught |mysqli_sql_exception|Stack trace|Could not reach Comet/.test(html)) {
    throw new Error(`Error text on page: ${label}`);
  }
}
async function need(page, selector, min, label) {
  const n = await page.locator(selector).count();
  if (n < min) throw new Error(`${label}: expected at least ${min} of "${selector}", found ${n}`);
}
async function snap(page, rel, { selector = null, fullPage = false, clip = null } = {}) {
  fs.mkdirSync(path.dirname(file(rel)), { recursive: true });
  await page.mouse.move(2, 2);
  await settle(page, 500);
  if (selector) await page.locator(selector).first().screenshot({ path: file(rel) });
  else await page.screenshot({ path: file(rel), fullPage: fullPage || !!clip, clip: clip || undefined });
  console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..'), file(rel)));
}
/** Clip from the top of one element to the bottom of another (page coordinates). */
async function regionClip(page, fromSel, toSel, pad = 8) {
  return page.evaluate(([a, b, pad]) => {
    const ra = document.querySelector(a).getBoundingClientRect();
    const rb = document.querySelector(b).getBoundingClientRect();
    const left = Math.min(ra.left, rb.left) + scrollX, top = ra.top + scrollY;
    const right = Math.max(ra.right, rb.right) + scrollX, bottom = rb.bottom + scrollY;
    return { x: Math.max(0, left - pad), y: Math.max(0, top - pad), width: right - left + 2 * pad, height: bottom - top + 2 * pad };
  }, [fromSel, toSel, pad]);
}
async function setText(page, selector, from, to) {
  await page.evaluate(([s, f, t]) => document.querySelectorAll(s).forEach((el) => { if (el.textContent.includes(f)) el.textContent = el.textContent.split(f).join(t); }), [selector, from, to]);
}
async function closeModal(page) {
  await page.keyboard.press('Escape');
  await page.waitForSelector('.modal.show', { state: 'detached', timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(300);
}
async function assetIdFor(page, hostname) {
  await goto(page, '/agent/rmm_assets.php');
  const link = page.locator(`#rmm-assets-table a.fw-bold:text-is("${hostname}")`).first();
  const target = (await link.count()) ? link : page.locator('#rmm-assets-table a.fw-bold').first();
  const href = await target.getAttribute('href');
  return { id: new URL(href, 'http://x').searchParams.get('asset_id'), name: (await target.innerText()).trim() };
}

// ---------------------------------------------------------------------------------------------
(async () => {
  const mock = await startMock();
  const { browser, page } = await launch();
  try {
    await login(page, 'admin');

    // 01 sidebar: where everything lives
    await goto(page, '/agent/rmm_dashboard.php');
    await clean(page, 'rmm dashboard');
    await need(page, '#nav-group-endpoints a.dropdown-item', 6, 'Endpoints menu');
    await callout(page, [
      { selector: 'a[href="/agent/alerts.php"].nav-link', n: 1, side: 'tr' },
      { selector: 'a[href="#nav-group-endpoints"]', n: 2, side: 'tr' },
      { selector: 'a[href="/agent/backups.php"].nav-link', n: 3, side: 'tr' },
    ]);
    await snap(page, '01-sidebar', { clip: { x: 0, y: 0, width: 276, height: 900 } });
    await clearCallouts(page);

    // 02 Alerts list
    await goto(page, '/agent/alerts.php');
    await clean(page, 'alerts');
    await need(page, '#alertsTable tbody tr', 5, 'alerts');
    await callout(page, [
      { selector: '.row.mb-3 .col-md-4:first-child .small-box', n: 1, side: 'tr' },
      { selector: 'form.d-flex > .btn-group:nth-of-type(1)', n: 2, side: 'br' },
      { selector: 'form.d-flex > .btn-group:nth-of-type(2)', n: 3, side: 'br' },
      { selector: 'form.d-flex > .btn-group:nth-of-type(3)', n: 4, side: 'br' },
      { selector: '#alertsTable tbody tr:first-child td:last-child', n: 5, side: 'tl' },
      { selector: '.js-bulk-alert-action[data-bulk-action="acknowledge"]', n: 6, side: 'tl' },
    ]);
    await snap(page, '02-alerts-list');
    await clearCallouts(page);

    // 03 RMM dashboard: top, then charts + lists
    await goto(page, '/agent/rmm_dashboard.php');
    await need(page, '.info-box', 6, 'dashboard stat cards');
    await callout(page, [
      { selector: '.row.mb-3 .col-6.col-md-2:nth-child(1) .info-box', n: 1, side: 'tr' },
      { selector: '.col-lg-7 > .card', n: 2, side: 'tr' },
      { selector: '.col-lg-5 > .card', n: 3, side: 'tr' },
      { selector: '.js-quick-sync', n: 4, side: 'tl' },
    ]);
    await snap(page, '03-rmm-dashboard');
    await clearCallouts(page);
    await snap(page, '04-rmm-dashboard-lower', { clip: await regionClip(page, '.col-lg-8 > .card', '.row > .col-md-6:last-child > .card:last-child', 4) });

    // 05 RMM Assets
    await goto(page, '/agent/rmm_assets.php');
    await clean(page, 'rmm assets');
    await need(page, '#rmm-assets-table tbody tr', 8, 'rmm assets');
    await callout(page, [
      { selector: 'select[name=integration_id]', n: 1, side: 'br' },
      { selector: 'select[name=status]', n: 2, side: 'br' },
      { selector: '#rmm-assets-table tbody tr:nth-child(4) td:nth-child(2)', n: 3, side: 'tl' },
      { selector: '#rmm-assets-table tbody tr:nth-child(4) td:last-child a', n: 4, side: 'tr' },
    ]);
    await snap(page, '05-rmm-assets');
    await clearCallouts(page);

    // 06-07 a device on its asset page (RMM card + tabs), live Monitoring tab through the mock
    const hero = await assetIdFor(page, 'LT-FIN-02');
    await goto(page, `/agent/asset_details.php?asset_id=${hero.id}`);
    await clean(page, 'asset page');
    await need(page, '#rmmDetailTabs a', 8, 'RMM tabs');
    await callout(page, [
      { selector: '.card.card-dark.mb-2 .badge', n: 1, side: 'tr' },
      { selector: '[data-rmm-action="connect"]', n: 2, side: 'tr' },
      { selector: '[data-rmm-action="reboot"]', n: 3, side: 'tr' },
      { selector: '#rmmDetailTabs', n: 4, side: 'tl' },
    ]);
    await snap(page, '06-asset-rmm-card');
    await clearCallouts(page);

    await page.click('a[href="#rdt-checks"]');
    await page.waitForSelector('#rdt-checks .rdt-data', { state: 'visible', timeout: 15000 });
    await snap(page, '07-asset-monitoring-tab', { selector: 'div.card:has(#rmmDetailTabs)' });

    await page.click('a[href="#rdt-scripts"]');
    await snap(page, '09-asset-scripts-tab', { selector: 'div.card:has(#rmmDetailTabs)' });

    // 09-11 script library, run pop-up, run detail
    await goto(page, '/agent/rmm_scripts.php');
    await clean(page, 'scripts');
    await need(page, 'table tbody tr', 10, 'scripts');
    await callout(page, [
      { selector: '#syncScriptsBtn', n: 1, side: 'bl' },
      { selector: '.js-open-new-script', n: 2, side: 'bl' },
      { selector: '.btn-group.btn-group-sm.me-2', n: 3, side: 'br' },
      { selector: 'table tbody tr:first-child .js-open-run-modal', n: 4, side: 'tl' },
      { selector: 'table tbody tr:first-child .js-preview-script', n: 5, side: 'tr' },
    ]);
    await snap(page, '08-script-library', { clip: { x: 0, y: 0, width: 1440, height: 900 } });
    await clearCallouts(page);

    await page.click('table tbody tr:first-child .js-open-run-modal');
    await page.waitForSelector('.modal.show #runAssetSelect');
    await page.locator('#runAssetSelect').selectOption({ index: 2 });
    await snap(page, '10-run-script-modal', { selector: '.modal.show .modal-content' });
    await closeModal(page);

    const runHref = await page.locator('tr:has(.badge:text-is("completed")) a[href*="rmm_script_run.php"]').first().getAttribute('href');
    await goto(page, runHref);
    await clean(page, 'script run');
    await need(page, 'pre', 1, 'run output');
    await snap(page, '11-script-run', { clip: { x: 256, y: 70, width: 1184, height: 560 } });

    // 12-13 check policies + new policy pop-up
    await goto(page, '/agent/rmm_checks.php');
    await clean(page, 'check policies');
    await need(page, '.js-push-policy', 6, 'policies');
    await callout(page, [
      { selector: '.js-open-new-policy', n: 1, side: 'bl' },
      { selector: '#platform-card-windows .js-push-all-policies', n: 2, side: 'tl' },
      { selector: '#platform-card-windows tbody tr:first-child td:nth-child(5)', n: 3, side: 'tl' },
      { selector: '#platform-card-windows tbody tr:first-child .js-push-policy', n: 4, side: 'tl' },
    ]);
    await snap(page, '12-check-policies', { clip: { x: 0, y: 0, width: 1440, height: 900 } });
    await clearCallouts(page);

    // 13 Network
    await goto(page, '/agent/network.php');
    await clean(page, 'network');
    await need(page, 'table tbody tr', 4, 'network devices');
    await callout(page, [
      { selector: '.small-box.bg-warning', n: 1, side: 'tr' },
      { selector: 'a.info-box[href*="device_type=Firewall"]', n: 2, side: 'tr' },
      { selector: '.js-trigger-net-sync', n: 3, side: 'bl' },
    ]);
    await snap(page, '13-network', { clip: { x: 0, y: 0, width: 1440, height: 900 } });
    await clearCallouts(page);

    // 14 Intune devices
    await goto(page, '/agent/intune_devices.php');
    await clean(page, 'intune devices');
    await need(page, '#intune-devices-table tbody tr', 6, 'intune devices');
    await callout(page, [
      { selector: '.row.mb-3 .col-md-4:nth-child(2) .small-box', n: 1, side: 'tr' },
      { selector: 'select[name=compliance]', n: 2, side: 'br' },
      { selector: '#intune-devices-table tbody tr:first-child td:nth-child(2)', n: 3, side: 'tl' },
    ]);
    await snap(page, '16-intune-devices');
    await clearCallouts(page);

    // 15-16 Settings -> Integrations: RMM tab and the Add Integration pop-up
    await goto(page, '/admin/settings_integrations.php?tab=rmm');
    await clean(page, 'settings rmm');
    await setText(page, '#tab-rmm td', 'http://127.0.0.1:18060', 'https://rmm-api.summitridge.example');
    await callout(page, [
      { selector: '#rmm_module_enabled', n: 1, side: 'tr' },
      { selector: '#rmm_auto_ticket_critical', n: 2, side: 'tl' },
      { selector: '#enable_device_metrics', n: 3, side: 'tr' },
      { selector: '.js-rmm-sync', n: 4, side: 'tl' },
    ]);
    await snap(page, '14-settings-rmm', { selector: '#tab-rmm' });
    await clearCallouts(page);

    await page.click('.js-rmm-reset-modal >> nth=0');
    await page.waitForSelector('.modal.show #rmm_integration_name');
    await page.fill('#rmm_integration_name', 'Summit Ridge RMM');
    await page.fill('#rmm_integration_api_url', 'https://rmm-api.summitridge.example');
    await page.fill('#rmm_integration_web_url', 'https://rmm.summitridge.example');
    await snap(page, '15-add-rmm-integration', { selector: '.modal.show .modal-content' });
    await closeModal(page);

    // 17 UniFi tab
    await goto(page, '/admin/settings_integrations.php?tab=unifi');
    await clean(page, 'settings unifi');
    await need(page, '#tab-unifi .js-unifi-sync', 2, 'unifi controllers');
    await callout(page, [
      { selector: '#unifi_module_enabled', n: 1, side: 'tr' },
      { selector: '#tab-unifi .js-unifi-reset-modal', n: 2, side: 'tl' },
      { selector: '#tab-unifi .js-unifi-sync', n: 3, side: 'tl' },
      { selector: '#tab-unifi select[name^="site_map"]', n: 4, side: 'tr' },
    ]);
    await snap(page, '18-settings-unifi', { selector: '#tab-unifi' });
    await clearCallouts(page);

    // 18 Comet settings (Backups tab). Illustrative values are typed over the demo ones; nothing is saved.
    await goto(page, '/admin/settings_integrations.php?tab=backups');
    await clean(page, 'settings backups');
    await page.fill('input[name=config_comet_server_url]', 'http://10.0.0.35:8060');
    await page.fill('input[name=config_comet_admin_user]', 'rivetit-api');
    await page.evaluate(() => { document.querySelector('input[name=config_comet_webhook_secret]').type = 'password'; });
    await setText(page, '#tab-backups code', 'localhost:8080', 'rivetit.summitridge.example');
    await setText(page, '#tab-backups code', 'localhost:18081', 'rivetit.summitridge.example');
    await callout(page, [
      { selector: '#comet_enabled', n: 1, side: 'tr' },
      { selector: 'input[name=config_comet_server_url]', n: 2, side: 'tr' },
      { selector: 'input[name=config_comet_webhook_secret]', n: 3, side: 'tr' },
      { selector: '#tab-backups select[name^="comet_map"]', n: 4, side: 'tr' },
    ]);
    await snap(page, '20-settings-comet', { selector: '#tab-backups' });
    await clearCallouts(page);

    // 19 Backup dashboard (data comes from the mock Comet server)
    await goto(page, '/agent/backups.php');
    await clean(page, 'backups');
    await need(page, 'table tbody tr', 10, 'backup devices');
    await callout(page, [
      { selector: '.row.mb-3 .col:nth-child(1) .info-box', n: 1, side: 'tr' },
      { selector: 'a[href*="alerts.php?source=backup"]', n: 2, side: 'bl' },
      { selector: 'table tbody tr:first-child a.badge', n: 3, side: 'tr' },
      { selector: 'table tbody tr:nth-child(2) td:nth-child(4) a', n: 4, side: 'tl' },
    ]);
    await snap(page, '19-backup-dashboard');
    await clearCallouts(page);

    // 20 Device Sync (Intune) tab
    await goto(page, '/admin/settings_integrations.php?tab=devicesync');
    await clean(page, 'settings device sync');
    await callout(page, [
      { selector: '#intune_module_enabled', n: 1, side: 'tr' },
      { selector: '#msIntuneSyncEnabled', n: 2, side: 'tr' },
    ]);
    await snap(page, '17-settings-device-sync', { selector: '#tab-devicesync' });
    await clearCallouts(page);
  } finally {
    await browser.close();
    mock.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
