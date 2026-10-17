// Browser smoke for the RMM log pages (RivetCore rc.11, Phase 8): the Logs page, the Logs tab of the asset page and the Log rules page.
//
//   /tmp/claude-1000/rc11b_browser.sh 8672 tests/browser/rmm_p68_logs_smoke.mjs      (seeds the fleet with tests/browser/rmm_seed.php, starts php -S, runs this)
//
// Same driver, harness and safety rules as rmm_p23_c_smoke.mjs: loopback only, zero npm dependencies, signs in through the real login form, and every check also fails on
// any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx while it ran. The forms are filled and submitted like a person would. Behind the scenes only
// the fixtures of tests/browser/rmm_p68_logs_extra.php run: the logs switch on (what an administrator does in Administration), two devices and their log lines sent through
// the real device endpoint, and a login for the Dept B technician.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke creates and deletes rules; it is meant for a throwaway install.`);
  process.exit(2);
}
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;

async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const hOverflow = () => p.eval(`(()=>{const d=document.documentElement;const vw=d.clientWidth;const over=[];
  const clipped=(e)=>{for(let n=e.parentElement;n&&n!==document.body&&n!==d;n=n.parentElement){const o=getComputedStyle(n).overflowX;if(o==='auto'||o==='scroll'||o==='hidden'||o==='clip')return true}return false};
  for(const e of document.querySelectorAll('body *')){const r=e.getBoundingClientRect();if(r.width<=0||r.right<=vw+1)continue;
    const cs=getComputedStyle(e);if(cs.position==='fixed'||cs.visibility==='hidden'||cs.display==='none')continue;
    if(e.closest('[hidden],.modal,.offcanvas,.dropdown-menu,.navbar-vertical,.main-sidebar')||clipped(e))continue;
    over.push(e.tagName+'.'+String(e.className).slice(0,50)+' right='+Math.round(r.right));if(over.length>5)break}
  return {sw:d.scrollWidth,vw,over}})()`);
const noOverflow = async (label) => {
  const o = await hOverflow();
  assert(o.sw <= o.vw + 1 && o.over.length === 0, `${label}: horizontal scroll at ${o.vw}px (scrollWidth ${o.sw}): ${o.over.join(', ')}`);
};
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.call((a0)=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')===''+a0+'');r.closest('label').click();}, value);
  await p.clickNav('button[name=edit_your_user_preferences]');
};
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);
const setVal = (sel, v) => p.call((a0,a1)=>{const e=document.querySelector(a0);if(!e)throw new Error('no '+a0+'');e.value=a1;e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return e.value}, sel, v);
const unlabeled = () => p.eval(`[...document.querySelectorAll('main input, main select, main textarea, .page-body input, .page-body select, .page-body textarea')].filter(e=>{
  if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;
  const lab=(e.labels&&e.labels.length)||e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title');return !lab}).map(e=>e.name||e.id||e.tagName)`);
const flash = async (re, label) => p.waitFor((a0)=>(new RegExp(a0,'i').test(document.body.innerText)), { args: [re.source], label: label || String(re) });
const pad = (n) => String(n).padStart(2, '0');
const utcInput = (offsetMin) => { const d = new Date(Date.now() + offsetMin * 60000); return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`; };
const extra = (mode) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_p68_logs_extra.php', process.env.SEED_JSON, mode], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
const login = async (email, password) => {
  await p.clearCookies();
  await go('/login.php');
  await p.type('input[name=email]', email);
  await p.type('input[name=password]', password);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page for ' + email);
};
const focused = () => p.eval(`(()=>{const a=document.activeElement;return a?((a.getAttribute('aria-label')||a.getAttribute('title')||a.innerText||a.id||a.tagName)+'').trim().slice(0,60):''})()`);
let fx = null;

// ------------------------------------------------------------------------------------------------ sign in and fixtures
await T('login: real form signs in', async () => {
  await login(EMAIL, PASSWORD);
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('setup: logs switched on, two devices send lines through the real endpoint, the log pages are in the menu', async () => {
  fx = extra('setup');
  assert(fx.sent_a === 123 && fx.sent_b === 2, 'lines not accepted: ' + JSON.stringify(fx));
  await go('/agent/rmm_fleet.php');
  assert(await p.exists('a[href="/agent/rmm_logs.php"]') && await p.exists('a[href="/agent/rmm_log_rules.php"]'), 'Logs / Log rules menu entries missing');
});

// ------------------------------------------------------------------------------------------------ the Logs page
await T('logs page: header, filters, table with level pills, relative and absolute time (desktop, light)', async () => {
  await go('/agent/rmm_logs.php');
  await trapDialogs();
  await p.waitSel('table.rmm-log-table');
  const t = await bodyText();
  assert(/Logs/.test(t) && /entries stored/.test(t) && /oldest entry/.test(t) && /worst-case storage/.test(t), 'storage header missing: ' + t.slice(0, 400));
  assert(/Showing 1-50 of 125 \(newest first\)/.test(t), 'count line: ' + (t.match(/Showing[^\n]*/) || ['none'])[0]);
  assert(/at most 7 days/.test(t) && /pick one device to go back up to 90 days/.test(t), 'cap in words missing');
  assert(await p.exists('a[href*="/agent/asset_details.php?asset_id="][href$="#rmm-logs"]'), 'device link to the Logs tab missing');
  assert((await p.eval(`document.querySelectorAll('table caption.visually-hidden').length`)) === 1 && (await p.eval(`document.querySelectorAll('th[scope=col]').length`)) === 6, 'table is not accessible');
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  assert(await p.eval(`!!document.querySelector('time[datetime]') && /UTC/.test(document.querySelector('.rmm-log-abs').textContent)`), 'times missing');
  assert(await p.eval(`!document.querySelector('.rmm-log-custom:not(.d-none)')`), 'the custom range inputs should be hidden until chosen');
  await shot('logs-desktop-light');
});
await T('logs page: hostile text is shown as text, the long message expands in place', async () => {
  const hostile = await p.eval(`document.querySelectorAll('main script, .page-body script, table script, table img').length`);
  assert(hostile === 0, 'hostile markup became an element');
  assert(/<script>alert\(1\)<\/script>/.test(await bodyText()), 'the escaped script text is not shown as text');
  await go('/agent/rmm_logs.php?q=LONGTAIL-MARKER');
  await p.waitSel('details.rmm-log-msg summary');
  assert(!(await p.eval(`document.querySelector('details.rmm-log-msg').open`)), 'the full text should be closed at first');
  assert(!/LONGTAIL-MARKER/.test(await p.eval(`document.querySelector('.rmm-log-short').innerText`)), 'the preview should be the beginning of the text');
  await p.click('details.rmm-log-msg summary');
  await p.waitSel('.rmm-log-full');
  assert(/LONGTAIL-MARKER/.test(await p.eval(`document.querySelector('.rmm-log-full').innerText`)) && /show less/.test(await p.eval(`document.querySelector('details.rmm-log-msg summary').innerText`)), 'full text not shown');
  await shot('logs-expanded-desktop-light');
  await p.click('details.rmm-log-msg summary');
  assert(!(await p.eval(`document.querySelector('details.rmm-log-msg').open`)), 'did not close again');
  // keyboard: summary is focusable and Enter opens it
  await p.focus('details.rmm-log-msg summary');
  await p.press('Enter');
  await p.waitSel('.rmm-log-full');
  await p.press('Enter');
  assert(!(await p.eval(`document.querySelector('details.rmm-log-msg').open`)), 'Enter did not close the message');
});
await T('logs page: the real filter form (device, level, text, custom range), then clear', async () => {
  await go('/agent/rmm_logs.php');
  await setVal('#lg-device_id', String(fx.dev['LGSMOKE-A']));
  await setVal('#lg-level', 'warning');
  await setVal('#lg-q', 'BULK-00');
  await setVal('#lg-range', 'custom');
  await p.waitSel('#lg-from');
  assert(await p.visible('#lg-to'), 'the custom range inputs did not appear');
  await setVal('#lg-from', utcInput(-60));
  await setVal('#lg-to', utcInput(5));
  await p.clickNav('form[data-rmm-log-filter] button[type=submit]');
  const t = await bodyText();
  assert(/LGSMOKE-A/.test(t) && !/DEPTB-ONLY-LINE/.test(t), 'the device filter did not narrow the table');
  const n = Number((t.match(/of (\d+) \(newest first\)/) || [])[1]);
  assert(n === 11, 'BULK-00xx at warning or worse should be 11 lines (every ninth entry of 1-99), got ' + n + ': ' + (t.match(/Showing[^\n]*/) || ['none'])[0]);
  assert(!(await p.exists('.rmm-log-table tbody .badge.text-bg-info')) && await p.exists('.rmm-log-table tbody .badge.text-bg-warning'), 'a level below warning is listed');
  assert((await p.eval(`document.querySelector('#lg-level').value`)) === 'warning' && (await p.eval(`document.querySelector('#lg-q').value`)) === 'BULK-00' && (await p.eval(`document.querySelector('#lg-range').value`)) === 'custom', 'the form lost its values');
  assert(/Range searched: /.test(t), 'the searched range is not stated');
  await shot('logs-filtered-desktop-light');
  await p.clickNav('form[data-rmm-log-filter] a.btn-outline-secondary');
  assert(/of 125 \(newest first\)/.test(await bodyText()), 'Clear did not reset');
});
await T('logs page: pagination with Older / Newer, page size, empty page past the end', async () => {
  await go('/agent/rmm_logs.php');
  await p.clickNav('a[rel=next]');
  let t = await bodyText();
  assert(/Showing 51-100 of 125/.test(t) && /Page 2/.test(t), 'page 2: ' + (t.match(/Showing[^\n]*/) || ['none'])[0]);
  await p.clickNav('a[rel=next]');
  t = await bodyText();
  assert(/Showing 101-125 of 125/.test(t), 'page 3');
  assert(await p.eval(`document.querySelector('a[rel=next]').closest('li').classList.contains('disabled')`), 'Older should be disabled on the last page');
  await p.clickNav('a[rel=prev]');
  await p.clickNav('a[rel=prev]');
  assert(/Showing 1-50 of 125/.test(await bodyText()), 'back to page 1');
  await setVal('#lg-size', '100');
  await p.clickNav('form[data-rmm-log-filter] button[type=submit]');
  assert(/Showing 1-100 of 125/.test(await bodyText()), 'page size 100');
  await go('/agent/rmm_logs.php?page=40');
  assert(/No entries on this page/.test(await bodyText()) && (await p.exists('a[href^="/agent/rmm_logs.php"]')), 'empty page past the end');
  await shot('logs-empty-page-desktop-light');
});
await T('logs page: "show all messages" toggles every long message', async () => {
  await go('/agent/rmm_logs.php?q=fox');
  await p.waitSel('[data-rmm-log-toggle-all]');
  await p.click('[data-rmm-log-toggle-all]');
  await p.waitFor(`document.querySelector('[data-rmm-log-toggle-all]').getAttribute('aria-pressed')==='true' && document.querySelectorAll('details.rmm-log-msg[open]').length>=1`, { label: 'all open' });
  await p.click('[data-rmm-log-toggle-all]');
  await p.waitFor(`document.querySelectorAll('details.rmm-log-msg[open]').length===0`, { label: 'all closed' });
});

// ------------------------------------------------------------------------------------------------ the Logs tab
await T('asset page: #rmm-logs opens the Logs tab; its own filters keep the tab and page', async () => {
  await go(`/agent/asset_details.php?asset_id=${fx.asset['LGSMOKE-A']}#rmm-logs`);
  await p.waitFor(`document.getElementById('rmm-tab-logs') && document.getElementById('rmm-tab-logs').getAttribute('aria-selected') === 'true'`, { label: 'Logs tab from the hash' });
  assert(await p.visible('#rmm-pane-logs'), 'pane hidden');
  let t = await p.eval(`document.getElementById('rmm-pane-logs').innerText`);
  assert(/Showing 1-50 of 123/.test(t) && !/DEPTB-ONLY-LINE/.test(t) && /One device can be searched up to 90 days back/.test(t), 'tab content: ' + t.slice(0, 300));
  assert(!/has not announced|No log source/.test(t), 'no explanatory note expected for a device that announced logs:collect');
  await shot('asset-logs-desktop-light');
  await setVal('#lg-a-level', 'error');
  await p.clickNav('#rmm-pane-logs form button[type=submit]');
  await p.waitFor(`document.getElementById('rmm-tab-logs').getAttribute('aria-selected') === 'true'`, { label: 'still on the Logs tab after the filter' });
  t = await p.eval(`document.getElementById('rmm-pane-logs').innerText`);
  assert(/Showing 1-2 of 2 /.test(t) && /DEPTA-SECRET-LINE/.test(t) && /Kernel/.test(t) && !/BULK-/.test(t), 'error filter inside the tab: ' + t.slice(0, 300));
  await go(`/agent/asset_details.php?asset_id=${fx.asset['LGSMOKE-A']}&lg_page=2#rmm-logs`);
  await p.waitFor(`document.getElementById('rmm-tab-logs').getAttribute('aria-selected') === 'true'`, { label: 'tab on page 2' });
  assert(/Showing 51-100 of 123/.test(await p.eval(`document.getElementById('rmm-pane-logs').innerText`)), 'page 2 inside the tab');
});

// ------------------------------------------------------------------------------------------------ rules
await T('rules: the page explains how an alert is made; a refused form comes back filled in', async () => {
  await go('/agent/rmm_log_rules.php');
  await trapDialogs();
  const t = await bodyText();
  assert(/No log rules yet/.test(t) && /How an alert is made/.test(t), 'empty page: ' + t.slice(0, 300));
  await p.click('#lr-explain summary');
  await p.waitFor(`/never a regular expression/.test(document.getElementById('lr-explain').innerText)`, { label: 'explanation opened' });
  assert(/Alerting switch is on/.test(await p.eval(`document.getElementById('lr-explain').innerText`)), 'alerting sentence');
  await shot('rules-empty-desktop-light');
  await p.clickNav('a[href*="new=1"]');
  await p.waitSel('#lr-name');
  await p.type('#lr-contains', 'disk [sda] (error) a.c*');
  await p.clickNav('#lr-form button[type=submit]');
  await flash(/needs a name of 1 to 100 characters|Name/, 'name refusal');
  assert(/A rule needs a name of 1 to 100 characters/.test(await bodyText()), 'Core\'s message not shown');
  assert((await p.eval(`document.querySelector('#lr-contains').value`)) === 'disk [sda] (error) a.c*', 'the phrase typed was lost');
  assert((await unlabeled()).length === 0, 'unlabeled controls: ' + (await unlabeled()).join(','));
  await shot('rules-form-error-desktop-light');
});
await T('rules: create with the form, see it listed with its check key and alert link', async () => {
  await go('/agent/rmm_log_rules.php?new=1');
  await p.waitSel('#lr-name');
  await p.type('#lr-name', 'Smoke disk failing');
  await setVal('#lr-min_level', 'error');
  await p.type('#lr-contains', 'smoke disk failing');
  await setVal('#lr-threshold', '1');
  await setVal('#lr-severity', 'fail');
  await setVal('#lr-resolve_after_min', '20');
  await p.clickNav('#lr-form button[type=submit]');
  await flash(/Rule created/, 'created flash');
  const t = await bodyText();
  assert(/Smoke disk failing/.test(t) && /log\.rule_\d+/.test(t) && /Error or worse/.test(t) && /text contains smoke disk failing/.test(t) && /on every match, critical/.test(t) && /resolves after 20 min quiet/.test(t), 'row: ' + t.slice(0, 600));
  assert(/0 devices matching/.test(t) && /0 open alerts/.test(t), 'state: ' + t.slice(0, 600));
  assert(await p.exists('a[href^="/agent/rmm_agent_alerts.php?state=active&check_key=log.rule_"]'), 'link to the Agent alerts page missing');
  await shot('rules-list-desktop-light');
});
await T('rules: edit the rule, turn it off and on', async () => {
  await p.clickText(/Edit/, 'table');
  await p.waitSel('#lr-name');
  assert((await p.eval(`document.querySelector('#lr-name').value`)) === 'Smoke disk failing', 'form not filled');
  await p.type('#lr-name', 'Smoke disk failing (edited)');
  await p.clickNav('#lr-form button[type=submit]');
  await flash(/Rule saved/, 'saved flash');
  assert(/Smoke disk failing \(edited\)/.test(await bodyText()), 'rename not shown');
  await p.clickNav('form:has(input[value="toggle_rule"]) button[type=submit]');
  await flash(/Rule turned off/, 'off flash');
  assert(/Off/.test(await p.eval(`document.querySelector('table tbody tr').innerText`)), 'state pill');
  await shot('rules-off-desktop-light');
  await p.clickNav('form:has(input[value="toggle_rule"]) button[type=submit]');
  await flash(/Rule turned on/, 'on flash');
});
await T('rules: a matching line raises the alert; the rule row and the Agent alerts page show it', async () => {
  const r = extra('alert');
  assert(r.accepted === 1, 'line not accepted');
  await go('/agent/rmm_log_rules.php');
  const t = await bodyText();
  assert(/1 device matching/.test(t) && /1 open alert/.test(t), 'state after the match: ' + t.slice(0, 600));
  await p.clickNav('a[href^="/agent/rmm_agent_alerts.php?state=active&check_key=log.rule_"]');
  const a = await bodyText();
  assert(/LGSMOKE-A/.test(a) && /log\.rule_\d+/.test(a), 'the alert is not listed: ' + a.slice(0, 400));
  await shot('rules-alert-desktop-light');
});
await T('rules: delete asks in the in-page dialog; Escape cancels and returns focus; Enter confirms', async () => {
  await go('/agent/rmm_log_rules.php');
  await trapDialogs();
  await p.focus('form:has(input[value="delete_rule"]) button[type=submit]');
  await p.press('Enter');
  await p.waitSel('#rmmAutoConfirm.show');
  assert(/Delete the rule Smoke disk failing \(edited\)\?/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'confirm text');
  await shot('rules-delete-confirm-desktop-light');
  await sleep(700);   // Bootstrap moves focus into the dialog when its fade has finished
  assert(await p.eval(`document.getElementById('rmmAutoConfirm').contains(document.activeElement)`), 'focus is not inside the dialog');
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmAutoConfirm.show')`, { label: 'dialog closed' });
  await p.waitFor(`/^Delete /.test(document.activeElement.getAttribute('title') || '')`, { label: 'focus back on the Delete button', timeout: 4000 });
  assert(/Smoke disk failing/.test(await bodyText()), 'cancel must not delete');
  await p.press('Enter');
  await p.waitSel('#rmmAutoConfirm.show');
  await p.clickNav('#rmmAutoConfirmGo');
  await flash(/Rule deleted/, 'deleted flash');
  assert(/No log rules yet/.test(await bodyText()), 'empty state after delete');
  assert((await p.eval('window.__dialogs')) === undefined || (await p.eval('window.__dialogs')) === 0, 'window.confirm was used');
});

// ------------------------------------------------------------------------------------------------ dark mode and phone width
await T('dark mode and phone width: every page fits, nothing overflows (screenshots in shots/)', async () => {
  const pages = [['logs', '/agent/rmm_logs.php'], ['logs-expanded', '/agent/rmm_logs.php?q=LONGTAIL-MARKER'], ['rules-form', '/agent/rmm_log_rules.php?new=1'],
    ['asset-logs', `/agent/asset_details.php?asset_id=${fx.asset['LGSMOKE-A']}#rmm-logs`]];
  await setTheme(1);
  for (const [name, path] of pages) {
    await p.setViewport(1366, 900);
    await go(path); await sleep(700);
    if (name === 'logs-expanded') { await p.click('details.rmm-log-msg summary'); await sleep(200); }
    const bg = await p.eval(`getComputedStyle(document.body).backgroundColor`);
    const [r, g, b] = bg.match(/\d+/g).map(Number);
    assert(r + g + b < 3 * 110, `${name}: the page is not dark in dark mode: ${bg}`);
    await shot(`${name}-desktop-dark`);
    await p.setViewport(MOBILE_W, 800, true);
    await go(path); await sleep(700);
    if (name === 'logs-expanded') { await p.click('details.rmm-log-msg summary'); await sleep(200); }
    await noOverflow(`${name} dark phone`);
    await shot(`${name}-phone-dark`);
  }
  await p.setViewport(1366, 900);
  await setTheme(0);
  await p.setViewport(MOBILE_W, 800, true);
  for (const [name, path] of pages) {
    await go(path); await sleep(700);
    if (name === 'logs-expanded') { await p.click('details.rmm-log-msg summary'); await sleep(200); }
    await noOverflow(`${name} light phone`);
    await shot(`${name}-phone-light`);
  }
  await p.setViewport(1366, 900);
});

// ------------------------------------------------------------------------------------------------ a technician limited to Dept B
await T('restricted user (Dept B): signs in through the form and sees no Dept A line anywhere', async () => {
  await login('deptb@scratch.test', 'Scratch-Deptb-1234');
  await go('/agent/rmm_logs.php?size=200');
  await p.waitSel('table.rmm-log-table');
  let t = await bodyText();
  assert(/DEPTB-ONLY-LINE/.test(t) && /of 2 \(newest first\)/.test(t), 'Dept B lines expected: ' + (t.match(/Showing[^\n]*/) || ['none'])[0]);
  assert(!/DEPTA-SECRET-LINE|BULK-|LGSMOKE-A|LONGTAIL|Kernel/.test(t), 'a Dept A line or device is visible to the Dept B user');
  assert(!/Dept A/.test(await p.eval(`document.querySelector('#lg-client_id').innerText`)) && !/LGSMOKE-A/.test(await p.eval(`document.querySelector('#lg-device_id').innerText`)), 'the pickers hold Dept A');
  assert(/fleet totals need access to every department/.test(t), 'the header should not reveal fleet totals');
  await shot('logs-deptb-desktop-light');
  await go(`/agent/rmm_logs.php?device_id=${fx.dev['LGSMOKE-A']}`);
  t = await bodyText();
  assert(/does not exist, or it belongs to a department you cannot see/.test(t) && !/DEPTA-SECRET-LINE|BULK-/.test(t), 'another department\'s device must read as not found');
  await go(`/agent/asset_details.php?asset_id=${fx.asset['LGSMOKE-A']}`);
  assert(!(await p.exists('#rmm-tab-logs')) && !/DEPTA-SECRET-LINE|BULK-/.test(await bodyText()), 'no Logs tab for another department\'s asset');
  await go(`/agent/asset_details.php?asset_id=${fx.asset['LGSMOKE-B']}#rmm-logs`);
  await p.waitFor(`document.getElementById('rmm-tab-logs') && document.getElementById('rmm-tab-logs').getAttribute('aria-selected') === 'true'`, { label: 'own device Logs tab' });
  assert(/DEPTB-ONLY-LINE/.test(await p.eval(`document.getElementById('rmm-pane-logs').innerText`)), 'own device tab');
  await shot('asset-logs-deptb-desktop-light');
  await go('/agent/rmm_log_rules.php?new=1');
  await p.waitSel('#lr-client');
  assert(!/All departments/.test(await p.eval(`document.querySelector('#lr-client').innerText`)), 'a restricted user must not be offered "All departments"');
  await p.setViewport(MOBILE_W, 800, true);
  await go('/agent/rmm_logs.php?size=200'); await sleep(500);
  await noOverflow('restricted logs phone');
  await shot('logs-deptb-phone-light');
  await p.setViewport(1366, 900);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
