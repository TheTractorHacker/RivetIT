// Browser smoke for the asset page's "Run script" dialog (library scripts, saved scripts, free-form text; Windows and Linux) and the single Scripts area (tabs, import).
//
//   php tests/browser/rmm_seed.php /tmp/rmm-seed.json            # on the throwaway install (see that file's header)
//   BASE_URL=http://127.0.0.1:8650 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=/tmp/rmm-seed.json \
//     SMOKE_OUT=/tmp/rmm-shots node tests/browser/rmm_runscript_smoke.mjs
//
//   php tests/browser/rmm_runscript_extra.php seed /tmp/rmm-seed.json > /tmp/rmm-rs.json   # after rmm_seed.php, before this smoke
//   RS_JSON=/tmp/rmm-rs.json ... (the ids of the library scripts it created)
//
// Same driver, harness and safety rules as rmm_smoke.mjs (loopback only, zero npm dependencies). It signs in through the real login form, opens the dialog on a Windows
// and a Linux device, filters and picks library scripts, runs them (jobs are decoded through rmm_runscript_extra.php), checks the dry-run default, the approval route and
// the destructive confirm, then the Scripts area, at desktop and phone width in light and dark, writing full-page screenshots to SMOKE_OUT/shots.
// Every check also fails on any uncaught error, console.error, failed first-party request or HTTP 4xx/5xx that occurred while it ran.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/rmm_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke switches the signed-in user's theme and queues no jobs, but it is meant for a throwaway install.`);
  process.exit(2);
}
const SEED = JSON.parse(readFileSync(process.env.SEED_JSON, 'utf8'));
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
// a hash-only change is not a navigation (no load event): leave the page first so the deep link is really loaded
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const bodyText = () => p.eval('document.body.innerText');
const MOBILE_W = 390;
const asset = (n) => `/agent/asset_details.php?asset_id=${SEED.asset[n]}`;

// full-page screenshot: grow the capture to the document height
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
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await p.clickNav('button[name=edit_your_user_preferences]');
};
// count window.confirm / alert calls (the panel must use in-page dialogs only)
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);


const pick = (sel, val) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.value=${JSON.stringify(String(val))};e.dispatchEvent(new Event('change',{bubbles:true}));return e.value})()`);
const optionValue = (sel, text) => p.eval(`(()=>{const o=[...document.querySelector(${JSON.stringify(sel)}).options].find(x=>x.text.includes(${JSON.stringify(text)}));return o?o.value:null})()`);
const setBody = (text) => p.eval(`(()=>{const e=document.getElementById('scr_body');e.value=${JSON.stringify(text)};e.dispatchEvent(new Event('input',{bubbles:true}));return e.value.length})()`);
const SECRET = 'SMOKE-SECRET-123';
const saved = {};
const waitText = (re, label) => p.waitFor(`/${re}/.test(document.body.innerText) || /${re}/.test(document.documentElement.innerHTML)`, { timeout: 12000, label: label || String(re) });
const flash = () => p.eval(`(document.documentElement.innerHTML.match(/toastr\\["[a-z]+"\\]\\("([^"]*)"\\)/)||[])[1]||''`);
const IDS = JSON.parse(readFileSync(process.env.RS_JSON, 'utf8')).ids;
const php = (...args) => JSON.parse(execFileSync('php', [resolve('tests/browser/rmm_runscript_extra.php'), ...args], { env: process.env, encoding: 'utf8' }).split('\n').filter((l) => l.startsWith('{')).pop());
const job = (key) => php('job', String(IDS[key]));
const openDialog = async (name) => {
  await go(asset(name) + '#rmm-overview');
  await p.waitSel('#rmm-act-script');
  await p.click('#rmm-act-script');
  await p.waitSel('#rmmRunModal.show');
  await sleep(450);
};
const choose = async (text) => {
  const v = await p.eval(`(()=>{const o=[...document.querySelectorAll('#rmm-run-lib-script option')].find(x=>x.textContent.includes(${JSON.stringify(text)}));return o?o.value:null})()`);
  assert(v, 'script not in the list: ' + text);
  await p.eval(`(()=>{const s=document.getElementById('rmm-run-lib-script');s.value=${JSON.stringify(v)};s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await sleep(150);
  return v;
};
const setVal = (sel, val) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.value=${JSON.stringify(String(val))};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return e.value})()`);
const blockVisible = (id) => p.eval(`(()=>{const b=document.querySelector('.rmm-run-lib-block[data-script-id="${id}"]');return !!b&&!b.hidden&&b.getBoundingClientRect().height>0})()`);

await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ------------------------------------------------------------------------------------------------ Windows device
await T('Windows: Run script opens ONE dialog whose first choice is "Script library (N)"; saved script and PowerShell follow', async () => {
  await openDialog('WIN1');
  const opts = await p.eval(`[...document.querySelectorAll('#rmm-run-type option')].map(o=>o.textContent)`);
  assert(/^Script library \(\d+\)$/.test(opts[0]), 'first option: ' + opts.join(' | '));
  assert(opts.includes('A saved script') && opts[opts.length - 1] === 'PowerShell (type or paste)' && !opts.some((o) => /PowerShell text/.test(o)), 'options: ' + opts.join(' | '));
  const n = Number(opts[0].match(/\d+/)[0]);
  const listed = await p.eval(`document.querySelectorAll('#rmm-run-lib-script option').length`);
  assert(n >= 5 && listed === n, `library count ${n} vs listed ${listed}`);
  const groups = await p.eval(`[...document.querySelectorAll('#rmm-run-lib-script optgroup')].map(g=>g.label)`);
  assert(/^Read only/.test(groups[0]) && /^Changes the system/.test(groups[1]) && /^Disruptive/.test(groups[2]), 'groups: ' + groups.join(' | '));
  assert(!(await p.eval(`[...document.querySelectorAll('#rmm-run-lib-script option')].some(o=>/Uptime/.test(o.textContent))`)), 'a Linux script is listed for Windows');
  assert(await p.visible('#rmm-run-lib-form') && !(await p.visible('#rmm-run-form')), 'the library pane is shown, the text pane is not');
  await shot('run-dialog-windows-desktop-light');
});

await T('search filters the list live and announces the count; the parameter form follows the selected script', async () => {
  await p.type('#rmm-run-lib-q', 'cleanup');
  await sleep(250);
  assert((await p.eval(`document.querySelectorAll('#rmm-run-lib-script option').length`)) === 1, 'filter by tag leaves one script');
  assert(/1 script matches/.test(await p.eval(`document.getElementById('rmm-run-lib-count').textContent`)), 'aria-live count');
  assert(await blockVisible(IDS.change), 'the selected script block is shown');
  await p.type('#rmm-run-lib-q', 'zzzz');
  await sleep(250);
  assert(/No script matches/.test(await p.eval(`document.getElementById('rmm-run-lib-count').textContent`)) && await p.eval(`document.getElementById('rmm-run-lib-go').disabled`), 'no match: announced, Queue job disabled');
  await p.type('#rmm-run-lib-q', '');
  await sleep(250);
  await choose('RS Disk report');
  assert(await blockVisible(IDS.read) && !(await blockVisible(IDS.change)), 'only the chosen script\'s block is visible');
  assert((await p.eval(`document.querySelector('#rmm_rl${IDS.read}_top_n').type`)) === 'number' && (await p.eval(`document.querySelector('#rmm_rl${IDS.read}_api_token').type`)) === 'password', 'typed inputs: number and password');
  assert(await p.eval(`document.querySelector('#rmm_rl${IDS.change}_dry_run').disabled`), 'the other script\'s fields are disabled (not posted)');
  assert(/Read only/.test(await p.eval(`document.querySelector('.rmm-run-lib-block[data-script-id="${IDS.read}"]').innerText`)), 'risk badge');
  await shot('run-dialog-read-only-desktop-light');
});

await T('running a read-only library script queues a job; the job decodes to the script, version and typed params', async () => {
  const before = (await job('read')).n;
  await setVal(`#rmm_rl${IDS.read}_top_n`, '9');
  await setVal(`#rmm_rl${IDS.read}_format`, 'csv');
  await p.clickNav('#rmm-run-lib-go');
  await waitText('Job queued', 'flash: job queued');
  assert(/asset_details\.php/.test(await p.url()) && /rmm-jobs/.test(await p.url()), 'back on the Jobs tab: ' + await p.url());
  const j = await job('read');
  assert(j.n === before + 1 && j.type === 'powershell' && j.state === 'queued' && j.script_version === 1 && j.params.top_n === 9 && j.params.format === 'csv', 'job: ' + JSON.stringify(j));
  assert(/PowerShell script/.test(await bodyText()), 'the job is listed on the Jobs tab');
});

await T('a changing script defaults to "Dry run (nothing is changed)" ON; unticking it sends false', async () => {
  await openDialog('WIN1');
  await choose('RS Clean temp');
  const cb = `#rmm_rl${IDS.change}_dry_run`;
  assert(await p.eval(`document.querySelector('${cb}').checked`), 'dry run starts ticked');
  assert(/Dry run \(nothing is changed\)/.test(await p.eval(`document.querySelector('label[for="rmm_rl${IDS.change}_dry_run"]').textContent`)), 'label wording');
  await shot('run-dialog-dry-run-desktop-light');
  const before = (await job('change')).n;
  await p.clickNav('#rmm-run-lib-go');
  await waitText('Job queued');
  let j = await job('change');
  assert(j.n === before + 1 && j.params.dry_run === true, 'default run: ' + JSON.stringify(j));
  await sleep(1100);
  await openDialog('WIN1');
  await choose('RS Clean temp');
  await p.click(cb);
  await p.clickNav('#rmm-run-lib-go');
  await waitText('Job queued');
  j = await job('change');
  assert(j.n === before + 2 && j.params.dry_run === false, 'unticked run: ' + JSON.stringify(j));
});

await T('a script that needs approval goes to the approvals queue and the dialog says so', async () => {
  await openDialog('WIN1');
  await choose('RS Needs sign-off');
  assert(/approvals queue/.test(await p.eval(`document.querySelector('.rmm-run-lib-block[data-script-id="${IDS.approval}"]').innerText`)), 'approval notice in the dialog');
  const before = (await job('approval')).n, ap = (await php('approvals')).pending;
  await p.clickNav('#rmm-run-lib-go');
  await waitText('waiting under Approvals');
  assert(/rmm_approvals\.php/.test(await p.url()), 'taken to the approvals page: ' + await p.url());
  assert((await job('approval')).n === before && (await php('approvals')).pending === ap + 1, 'no job, one pending approval');
});

await T('a destructive script needs its confirm box (native validation), then runs', async () => {
  await openDialog('WIN1');
  await choose('RS Wipe cache');
  const cb = `#rmm_rl${IDS.destructive}_confirm`;
  assert(await p.visible(cb) && !(await p.eval(`document.getElementById('rmm-run-lib-form').checkValidity()`)), 'unconfirmed: the form is invalid');
  const before = (await job('destructive')).n;
  await p.click(cb);
  assert(await p.eval(`document.getElementById('rmm-run-lib-form').checkValidity()`), 'confirmed: valid');
  await p.clickNav('#rmm-run-lib-go');
  await waitText('Job queued');
  assert((await job('destructive')).n === before + 1, 'destructive job queued');
});

await T('saved script and PowerShell text panes switch in the same dialog; Esc closes and returns focus', async () => {
  await openDialog('WIN1');
  await trapDialogs();
  await pick('#rmm-run-type', 'saved');
  await sleep(150);
  assert(await p.visible('#rmm-run-saved') && !(await p.visible('#rmm-run-lib-form')) && !(await p.visible('#rmm-run-text')), 'saved pane');
  await pick('#rmm-run-type', 'text');
  await sleep(150);
  assert(await p.visible('#rmm-run-text') && /PowerShell \(type or paste\)/.test(await p.eval(`document.querySelector('label[for=rmm-run-text]').textContent`)), 'text pane');
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmRunModal.show')`, { label: 'dialog closed' });
  await sleep(600);
  assert(await p.eval(`document.activeElement && document.activeElement.id`) === 'rmm-act-script', 'focus returned to the button');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
});

// ------------------------------------------------------------------------------------------------ Linux device
await T('Linux: the Run script button is enabled; the dialog offers Linux library scripts and Shell (type or paste)', async () => {
  await openDialog('LNX1');
  const opts = await p.eval(`[...document.querySelectorAll('#rmm-run-type option')].map(o=>o.textContent)`);
  assert(/^Script library \(\d+\)$/.test(opts[0]) && opts[opts.length - 1] === 'Shell (type or paste)' && !opts.some((o) => /PowerShell|saved/.test(o)), 'options: ' + opts.join(' | '));
  const names = await p.eval(`[...document.querySelectorAll('#rmm-run-lib-script option')].map(o=>o.textContent)`);
  assert(names.some((n) => /RS Uptime/.test(n)) && !names.some((n) => /\(Windows\)/.test(n)), 'only Linux scripts: ' + names.join(' | '));
  await shot('run-dialog-linux-desktop-light');
  const before = (await job('linux')).n;
  await p.clickNav('#rmm-run-lib-go');
  await waitText('Job queued');
  const j = await job('linux');
  assert(j.n === before + 1 && j.type === 'shell' && j.device_id === SEED.dev.LNX1, 'linux library job: ' + JSON.stringify(j));
});
await T('Linux: free-form shell text queues a shell job', async () => {
  await openDialog('LNX1');
  await pick('#rmm-run-type', 'text');
  await sleep(150);
  assert(/Runs with bash/.test(await p.eval(`document.getElementById('rmm-run-text-wrap').innerText`)), 'bash note');
  const before = (await php('shell', String(SEED.dev.LNX1))).n;
  await setVal('#rmm-run-text', 'echo smoke');
  await p.click('#rmm-run-go');
  await waitText('Script job queued', 'toast: queued');
  await sleep(1200);
  const s = await php('shell', String(SEED.dev.LNX1));
  assert(s.n === before + 1 && s.type === 'shell' && s.has_script, 'free-form job: ' + JSON.stringify(s));
});

// ------------------------------------------------------------------------------------------------ the one Scripts area
await T('Scripts area: one menu entry; Library and "Synced from" tabs; old URL; import into the library is idempotent', async () => {
  await go('/agent/rmm_script_library.php');
  await p.waitSel('h4');
  assert((await p.eval(`[...document.querySelectorAll('.dropdown-item .text-truncate')].filter(e=>e.textContent.trim()==='Scripts').length`)) === 1, 'exactly one Scripts entry');
  assert(!/Script library/.test(await p.eval(`document.querySelector('#nav-group-endpoints').innerText`)), 'no "Script library" label left in the menu');
  assert(await p.exists('#rmm-scripts-tabs') && /Library/.test(await p.eval(`document.querySelector('#rmm-scripts-tabs .nav-link.active').textContent`)), 'Library tab active');
  await shot('scripts-library-desktop-light');
  await p.clickNav('#rmm-scripts-tabs a[href="/agent/rmm_scripts.php"]');
  assert(/Synced from/.test(await p.eval(`document.querySelector('#rmm-scripts-tabs .nav-link.active').textContent`)) && await p.exists('#legacy-import'), 'legacy tab active with the import card');
  assert((await p.eval(`document.querySelector('a.dropdown-item.active[href="/agent/rmm_script_library.php"]') !== null`)), 'the Scripts entry stays active on the second tab');
  await shot('scripts-legacy-desktop-light');
  await p.clickNav('#legacy-import button[type=submit]');
  await waitText('imported into the library');
  assert(!(await p.exists('#legacy-import')) && /Imported/.test(await bodyText()), 'after the import the card is gone and the row says Imported');
  await shot('scripts-legacy-imported-desktop-light');
});

// ------------------------------------------------------------------------------------------------ looks: phone, dark
const openAndShot = async (name, dev, pane, label) => {
  await openDialog(dev);
  if (pane) { await pick('#rmm-run-type', pane); await sleep(200); } else { await choose('RS Clean temp'); }
  const o = await p.eval(`(()=>{const m=document.querySelector('#rmmRunModal .modal-content');const r=m.getBoundingClientRect();return {l:Math.round(r.left),r:Math.round(r.right),vw:document.documentElement.clientWidth,sw:m.scrollWidth,cw:m.clientWidth}})()`);
  assert(o.l >= -1 && o.r <= o.vw + 1 && o.sw <= o.cw + 1, `${label}: dialog overflows (${JSON.stringify(o)})`);
  await shot(label);
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmRunModal.show')`, { label: 'closed' });
};
await T('phone width, light: the dialog fits (library and text panes)', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  await openAndShot('x', 'WIN1', null, 'run-dialog-windows-phone-light');
  await openAndShot('x', 'LNX1', 'text', 'run-dialog-linux-text-phone-light');
  await go('/agent/rmm_scripts.php'); await p.waitSel('#rmm-scripts-tabs, h4'); await sleep(300); await noOverflow('legacy tab phone'); await shot('scripts-legacy-phone-light');
  await go('/agent/rmm_script_library.php'); await p.waitSel('table'); await sleep(300); await noOverflow('library phone'); await shot('scripts-library-phone-light');
  await p.setViewport(1366, 900);
});
await T('dark theme: desktop and phone dialog is readable and fits', async () => {
  await setTheme(1);
  await openAndShot('x', 'WIN1', null, 'run-dialog-windows-desktop-dark');
  await p.setViewport(MOBILE_W, 800, true);
  await openAndShot('x', 'WIN1', null, 'run-dialog-windows-phone-dark');
  await go('/agent/rmm_script_library.php'); await p.waitSel('table'); await sleep(300); await noOverflow('library phone dark'); await shot('scripts-library-phone-dark');
  await p.setViewport(1366, 900);
  await setTheme(0);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
