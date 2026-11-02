// Browser smoke for the employee self-service portal additions (issue #32): announcements (admin page and portal widget), the simplified employee
// home, help articles, richer device cards, the notification feed, and the same pages at phone width and in dark mode.
//
//   php tests/browser/employee_portal_i32_seed.php /tmp/ep-seed.json          # on the throwaway install (WIPES the scratch tables it fills)
//   php -S 127.0.0.1:19032 tests/browser/employee_portal_i32_router.php        # from the install's root, config.php -> the scratch database
//   BASE_URL=http://127.0.0.1:19032 SEED_JSON=/tmp/ep-seed.json SMOKE_OUT=/tmp/ep-shots node tests/browser/employee_portal_i32_smoke.mjs
//
// Same driver, harness and safety rules as the rest of tests/browser (loopback only, zero npm dependencies; ./lib/cdp.mjs and ./lib/harness.mjs
// come with the browser suite and are not part of this change). It signs in through the real login forms.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
if (!BASE || !process.env.SEED_JSON) { console.error('Set BASE_URL and SEED_JSON (written by employee_portal_i32_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: it creates and removes data; use a throwaway install.`); process.exit(2); }
const SEED = JSON.parse(readFileSync(process.env.SEED_JSON, 'utf8'));
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1280, 900);
const H = new Harness({ page, outDir, edition: 'employee-portal', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const text = () => p.eval('document.body.innerText');

async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const hOverflow = () => p.eval(`(()=>{const d=document.documentElement;return {sw:d.scrollWidth,vw:d.clientWidth}})()`);
const portalLogin = async (email) => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', SEED.employee_password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(/\/client\//.test(await p.url()), 'did not land in the portal: ' + (await p.url()));
};
const setPortalTheme = async (dark) => {
  const cur = await p.eval(`document.documentElement.getAttribute('data-bs-theme')`);
  if ((cur === 'dark') !== dark) { await p.click('[data-portal-theme-toggle]'); await sleep(300); }
};
const lum = (c) => c.match(/\d+/g).slice(0, 3).map(Number).reduce((a, b) => a + b, 0);

await T('employee (Alice) signs in through the real portal form', async () => { await portalLogin(SEED.alice.email); });

await T('home: announcements for all departments and her own, scoped, as plain text', async () => {
  await go('/client/index.php');
  await p.waitSel('#announcements');
  const t = await p.eval(`document.getElementById('announcements').innerText`);
  assert(/Network maintenance tonight/.test(t) && /Dept A lunch on Friday/.test(t), 'missing announcements: ' + t);
  assert(!/Dept B only notice/.test(await text()), 'Dept B announcement leaked');
  assert(!/Expired notice|Scheduled for next week|Removed notice/.test(await text()), 'expired / scheduled / removed notice shown');
  assert(/<script>alert\(1\)<\/script>/.test(t), 'markup in the text was not shown as plain text');
  assert((await p.eval(`document.getElementById('announcements').querySelectorAll('script').length`)) === 0, 'a script element was injected');
  assert((await p.eval(`document.getElementById('announcements').querySelectorAll('.alert').length`)) === 2, 'expected two announcement boxes');
  await shot('home-desktop-light');
});

await T('home: simplified for an employee (no stat tiles; request, devices, help articles)', async () => {
  const b = await text();
  assert(!(await p.exists('.portal-stats')), 'stat tiles still shown to an employee');
  assert(/What do you need\?/.test(b) && /My open requests/.test(b) && /My devices/.test(b) && /Help articles/.test(b), 'a section is missing');
  assert(/Alice printer broken/.test(b) && !/Bob VPN|Carl secret/.test(b), 'requests not scoped to Alice');
  assert(/ALICE-LAPTOP/.test(b) && /Windows 11 Pro/.test(b) && /Online now|Seen today/.test(b) && !/BOB-LAPTOP|CARL-LAPTOP|SPARE-LAPTOP/.test(b), 'devices not scoped / missing OS or last seen');
  assert(!/SECRET NOTE|10\.9\.9\.9/.test(b), 'internal device data leaked');
});

await T('home: help articles show popular and recent, only what she may read', async () => {
  const t = await p.eval(`document.getElementById('help-articles').innerText`);
  assert(/Popular this month/.test(t) && /New and updated/.test(t), 'headings missing');
  assert(/Reset your password/.test(t) && /Dept A parking guide/.test(t), 'visible articles missing');
  assert(!/Dept B secret recipe|Internal runbook|Retired article/.test(t), 'article she may not read is listed');
  await p.clickText('Reset your password', '#help-articles');
  await sleep(1200);
  assert(/kb_article\.php\?id=41/.test(await p.url()), 'article did not open: ' + (await p.url()));
});

await T('another department\'s article is not readable', async () => {
  await go('/client/kb_article.php?id=43');
  assert(!/Dept B secret recipe/.test(await text()), 'article of another department opened');
});

await T('notifications: nav badge, feed content and mark all as read', async () => {
  await go('/client/index.php');
  const badge = await p.eval(`(document.querySelector('a[href="/client/notifications.php"] .badge')||{}).innerText||''`);
  assert(/^\d+$/.test(badge.trim()) && Number(badge) >= 5, 'no unread badge on the Notifications link: "' + badge + '"');
  await go('/client/notifications.php');
  await p.waitSel('#portal-feed');
  const t = await p.eval(`document.getElementById('portal-feed').innerText`);
  assert(/Network maintenance tonight/.test(t) && /New reply on #T5001/.test(t) && /was closed/.test(t) && /Due soon: Sign the handbook/.test(t) && /ALICE-LAPTOP: warranty ends in 20 days/.test(t), 'feed items missing: ' + t.slice(0, 500));
  assert(!/Dept B only notice|Bob VPN|Carl secret|internal only/.test(t), 'feed leaked other people\'s data');
  await shot('notifications-desktop-light');
  await p.clickNav('button[name=mark_feed_read]');
  const after = await p.eval(`document.querySelectorAll('#portal-feed .portal-feed-unread').length`);
  assert(after === 3, 'expected the 3 sticky items to stay unread, got ' + after);
});

await T('my devices: cards with OS, last seen, warranty and restart pending', async () => {
  await go('/client/assets.php');
  await p.waitSel('#my-devices');
  const t = await p.eval(`document.getElementById('my-devices').innerText`);
  assert((await p.eval(`document.querySelectorAll('#my-devices .portal-device').length`)) === 2, 'expected two device cards');
  assert(/Windows 11 Pro/.test(t) && /Online now|Seen today/.test(t) && /Restart needed/.test(t) && /Warranty ends in 20 days/.test(t) && /Latitude 7440/.test(t), 'laptop facts missing: ' + t.slice(0, 400));
  assert(/macOS 14\.4/.test(t) && /Last seen 5 days ago/.test(t) && /Warranty ended/.test(t), 'Mac facts missing');
  assert(!/BOB-LAPTOP|CARL-LAPTOP|SPARE-LAPTOP|SECRET NOTE|10\.9\.9\.9/.test(t), 'someone else\'s or internal data shown');
  assert((await p.eval(`[...document.querySelectorAll('#my-devices a')].every(a=>/ticket_add\\.php\\?asset_id=(31|35)$/.test(a.getAttribute('href')))`)), 'report-a-problem links point at other assets');
  await shot('devices-desktop-light');
});

await T('phone width (390px): home, notifications and devices do not scroll sideways', async () => {
  await p.setViewport(390, 800, true);
  for (const path of ['/client/index.php', '/client/notifications.php', '/client/assets.php']) {
    await go(path);
    await sleep(400);
    const o = await hOverflow();
    assert(o.sw <= o.vw + 1, `${path} overflows at 390px (scrollWidth ${o.sw} > ${o.vw})`);
    await shot('phone-light-' + path.split('/').pop().replace('.php', ''));
  }
  await p.setViewport(1280, 900);
});

await T('dark mode: home, feed and devices are readable', async () => {
  await go('/client/index.php');
  await setPortalTheme(true);
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'not dark');
  for (const [path, sel] of [['/client/index.php', '#announcements .alert'], ['/client/notifications.php', '#portal-feed .list-group-item'], ['/client/assets.php', '#my-devices .card-body']]) {
    await go(path);
    await p.waitSel(sel);
    const ink = await p.eval(`getComputedStyle(document.querySelector(${JSON.stringify(sel)})).color`);
    const bg = await p.eval(`getComputedStyle(document.querySelector(${JSON.stringify(sel)})).backgroundColor`);
    const body = await p.eval(`getComputedStyle(document.body).backgroundColor`);
    assert(lum(body) < 300, `${path}: page background is not dark (${body})`);
    if (!/rgba\(0, 0, 0, 0\)/.test(bg)) assert(lum(ink) !== lum(bg), `${path}: text and background identical (${ink} on ${bg})`);
    await shot('dark-' + path.split('/').pop().replace('.php', ''));
  }
  await p.setViewport(390, 800, true);
  await go('/client/index.php'); await sleep(300);
  const o = await hOverflow();
  assert(o.sw <= o.vw + 1, 'dark home overflows at 390px');
  await shot('phone-dark-index');
  await p.setViewport(1280, 900);
  await go('/client/index.php');
  await setPortalTheme(false);
});

await T('department administrator (Dee) keeps the original home page and still gets announcements', async () => {
  await portalLogin(SEED.dee.email);
  await go('/client/index.php');
  const b = await text();
  assert(await p.exists('.portal-stats'), 'stat tiles missing for the department administrator');
  assert(/Recent tickets/.test(b) && /Network maintenance tonight/.test(b), 'original home or announcements missing');
});

await T('employee in another department (Carl) sees his own announcements and nothing of Dept A', async () => {
  await portalLogin(SEED.carl.email);
  await go('/client/index.php');
  const b = await text();
  assert(/Dept B only notice/.test(b) && /Network maintenance tonight/.test(b) && !/Dept A lunch/.test(b), 'announcement scoping wrong for Dept B');
  assert(/Dept B secret recipe/.test(await p.eval(`document.getElementById('help-articles').innerText`)), 'Dept B article missing from his help articles');
  assert(!/Dept A parking guide/.test(b) && !/ALICE-/.test(b), 'Dept A data visible to Dept B');
});

let adminOk = true;
await T('admin signs in and opens Portal announcements', async () => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', SEED.admin.email);
  await p.type('input[name=password]', SEED.admin.password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'admin still on the login page');
  await go('/admin/portal_announcements.php');
  await p.waitSel('#announcement_form');
  const t = await text();
  assert(/Network maintenance tonight/.test(t) && /Scheduled for next week/.test(t) && /Expired notice/.test(t) && !/Removed notice/.test(t), 'admin list wrong');
  await shot('admin-announcements-desktop-light');
}, { allowHttp: [/\/agent\//] });

await T('admin: invalid form is refused with a message and saves nothing', async () => {
  await go('/admin/portal_announcements.php');
  await p.type('#ann_title', 'Bad dates');
  await p.type('#ann_body', 'x');
  await p.eval(`(()=>{document.getElementById('ann_pub').value='2026-12-10T10:00';document.getElementById('ann_exp').value='2026-12-09T10:00'})()`);
  await p.clickNav('button[name=add_announcement]');
  const t = await text();
  assert(/expiry must be after the publish time/i.test(t) && !/Bad dates/.test(await p.eval(`document.querySelector('table').innerText`)), 'validation message missing or row saved');
});

await T('admin: publish a Dept B notice, edit it, and remove it', async () => {
  await go('/admin/portal_announcements.php');
  await p.type('#ann_title', 'Smoke notice');
  await p.type('#ann_body', 'Line one\nLine two <b>bold?</b>');
  await p.eval(`(()=>{const s=document.getElementById('ann_dept');s.value=[...s.options].find(o=>/Dept B/.test(o.text)).value;const v=document.getElementById('ann_sev');v.value='important'})()`);
  await p.clickNav('button[name=add_announcement]');
  assert(/Announcement published/.test(await text()), 'no success message');
  assert(/Smoke notice/.test(await p.eval(`document.querySelector('table').innerText`)), 'not in the list');
  // edit
  const editHref = await p.eval(`[...document.querySelectorAll('table tbody tr')].find(r=>/Smoke notice/.test(r.innerText)).querySelector('a[href*="edit="]').getAttribute('href')`);
  await go('/admin/portal_announcements.php' + editHref);
  await p.waitSel('#ann_title');
  assert(await p.exists('input[name=announcement_id]'), 'not in edit mode');
  assert((await p.eval(`document.getElementById('ann_title').value`)) === 'Smoke notice', 'edit form not filled');
  await p.type('#ann_title', 'Smoke notice (edited)');
  await p.clickNav('button[name=edit_announcement]');
  assert(/Announcement updated/.test(await text()) && /Smoke notice \(edited\)/.test(await p.eval(`document.querySelector('table').innerText`)), 'edit not saved');
});

await T('the new Dept B notice reaches Carl (and not Alice), then removing it takes it away', async () => {
  await portalLogin(SEED.carl.email);
  await go('/client/index.php');
  let t = await p.eval(`document.getElementById('announcements').innerText`);
  assert(/Smoke notice \(edited\)/.test(t) && /Line two <b>bold\?<\/b>/.test(t), 'Carl does not see it as plain text: ' + t);
  assert((await p.eval(`document.getElementById('announcements').querySelectorAll('b').length`)) === 0, 'markup was interpreted');
  await portalLogin(SEED.alice.email);
  await go('/client/index.php');
  assert(!/Smoke notice/.test(await text()), 'Alice sees a Dept B notice');
  // remove as admin
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', SEED.admin.email);
  await p.type('input[name=password]', SEED.admin.password);
  await p.clickNav('button[name=login], button[type=submit]');
  await go('/admin/portal_announcements.php');
  await p.eval(`(()=>{const tr=[...document.querySelectorAll('table tbody tr')].find(r=>/Smoke notice/.test(r.innerText));tr.querySelector('button[name=archive_announcement]').click()})()`);
  await sleep(500);
  await p.eval(`(()=>{const m=document.querySelector('[data-rivet-dialog]');const b=[...m.querySelectorAll('.btn')].find(x=>!x.classList.contains('btn-secondary'));b.click()})()`);
  await sleep(1500);
  assert(/Announcement removed/.test(await text()) && !/Smoke notice/.test(await p.eval(`document.querySelector('table').innerText`)), 'not removed');
  await portalLogin(SEED.carl.email);
  await go('/client/index.php');
  assert(!/Smoke notice/.test(await text()), 'removed notice still shown to Carl');
});

await T('admin page at phone width and in dark mode', async () => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', SEED.admin.email);
  await p.type('input[name=password]', SEED.admin.password);
  await p.clickNav('button[name=login], button[type=submit]');
  await p.setViewport(390, 800, true);
  await go('/admin/portal_announcements.php');
  await p.waitSel('#announcement_form');
  await sleep(300);
  const o = await hOverflow();
  assert(o.sw <= o.vw + 1, `admin page overflows at 390px (${o.sw} > ${o.vw})`);
  await shot('admin-announcements-phone');
  await p.setViewport(1280, 900);
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','dark')`);
  await shot('admin-announcements-dark');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
