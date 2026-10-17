// Browser smoke for the RMM script library, schedules, approvals and the asset page's "Run a library script" card (RMM Phase 2, group B).
//
//   php tests/browser/rmm_seed.php /tmp/rmm-seed.json            # on the throwaway install (see that file's header)
//   BASE_URL=http://127.0.0.1:8650 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=/tmp/rmm-seed.json \
//     SMOKE_OUT=/tmp/rmm-shots node tests/browser/rmm_p23_b_smoke.mjs
//
// Same driver, harness and safety rules as rmm_smoke.mjs (loopback only, zero npm dependencies). It signs in through the real login form, publishes and runs
// scripts, compares versions, runs with the in-page confirmation dialog, approves a request made by another user, creates and pauses a schedule, and runs a
// library script from the asset page, at desktop and phone width in light and dark, writing full-page screenshots to SMOKE_OUT/shots.
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
  await p.call((a0)=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')===''+a0+'');r.closest('label').click();}, value);
  await p.clickNav('button[name=edit_your_user_preferences]');
};
// count window.confirm / alert calls (the panel must use in-page dialogs only)
const trapDialogs = () => p.eval(`window.__dialogs = 0; window.confirm = window.alert = window.prompt = function () { window.__dialogs++; return true; }; true`);


const pick = (sel, val) => p.call((a0,a1)=>{const e=document.querySelector(a0);e.value=a1;e.dispatchEvent(new Event('change',{bubbles:true}));return e.value}, sel, String(val));
const optionValue = (sel, text) => p.call((a0,a1)=>{const o=[...document.querySelector(a0).options].find(x=>x.text.includes(a1));return o?o.value:null}, sel, text);
const setBody = (text) => p.call((a0)=>{const e=document.getElementById('scr_body');e.value=a0;e.dispatchEvent(new Event('input',{bubbles:true}));return e.value.length}, text);
const SECRET = 'SMOKE-SECRET-123';
const saved = {};
const waitText = (re, label) => p.waitFor((a0)=>(new RegExp(a0).test(document.body.innerText) || new RegExp(a0).test(document.documentElement.innerHTML)), { args: [re], timeout: 12000, label: label || String(re) });
const flash = () => p.eval(`(document.documentElement.innerHTML.match(/toastr\\["[a-z]+"\\]\\("([^"]*)"\\)/)||[])[1]||''`);
const submitNav = async (sel) => { await p.clickNav(sel); };
const confirmDialog = async () => { await p.waitSel('#rmmAutoConfirm.show'); await sleep(450); };
const trapDialogsOn = trapDialogs;

// ------------------------------------------------------------------------------------------------ sign in
await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

// ------------------------------------------------------------------------------------------------ the library
await T('library: empty state with a New script button; the menu has the three pages', async () => {
  await go('/agent/rmm_script_library.php');
  await p.waitSel('.it-empty-state');
  assert(/No scripts yet/.test(await bodyText()), 'empty state text');
  for (const href of ['/agent/rmm_script_library.php', '/agent/rmm_schedules.php', '/agent/rmm_approvals.php']) assert(await p.exists(`a[href="${href}"]`), 'menu entry ' + href);
  await shot('library-empty-desktop-light');
});
await T('publish: the editor (counter, parameter rows, secret hides the default) and the first version', async () => {
  await p.clickNav('a[href*="new=1"]');
  await p.waitSel('#scr-editor');
  await p.type('#scr_name', 'Smoke hello');
  await p.type('#scr_desc', 'Says hello <b>loudly</b>');
  await pick('#scr_language', 'bash');
  await setBody('#!/bin/bash\necho "hello $RIVETIT_PARAM_greeting"\nexit 0\n');
  assert(/characters, \d+ of 102,400 bytes/.test(await p.eval(`document.getElementById('scr_counter').textContent`)), 'counter text');
  await pick('#scr_language', 'powershell');
  assert(/PowerShell limit 10,000/.test(await p.eval(`document.getElementById('scr_counter').textContent`)), 'PowerShell limit in the counter');
  await pick('#scr_language', 'bash');
  await p.type('#scr_tags', 'smoke, demo');
  await p.click('[data-rmm-add="#scr-params"]');
  await p.waitSel('#pd_0_name');
  await p.type('#pd_0_name', 'greeting');
  await p.click('#pd_0_req');
  await p.click('[data-rmm-add="#scr-params"]');
  await p.waitSel('#pd_1_name');
  await p.type('#pd_1_name', 'token');
  await pick('#pd_1_type', 'secret');
  assert(!(await p.visible('#pd_1_def')), 'a secret has no default field');
  assert(await p.visible('#pd_1_max'), 'a secret has a length limit');
  await pick('#pd_1_type', 'choice');
  assert(await p.visible('#pd_1_choices'), 'a choice has a choices box');
  await pick('#pd_1_type', 'secret');
  await shot('script-new-desktop-light');
  await submitNav('#scr-editor button[type=submit]');
  await waitText('published as version 1', 'flash: published');
  saved.url = new URL(await p.url()).pathname + new URL(await p.url()).search;
  assert(/script_id=\d+/.test(saved.url), 'redirected to the new script');
  assert(!(await p.eval(`document.documentElement.outerHTML.includes('<b>loudly</b>')`)), 'hostile description rendered as markup');
});
await T('edit: a changed text makes version 2; the diff marks the added line (sign and background)', async () => {
  await p.waitSel('#scr-editor');
  const cur = await p.eval(`document.getElementById('scr_body').value`);
  await setBody(cur.trimEnd() + '\necho "bye"\n');
  await p.type('#scr_note', 'say bye');
  await submitNav('#scr-editor button[type=submit]');
  await waitText('new version \\(2\\)', 'flash: version 2');
  await p.waitSel('#scr-versions form');
  await pick('#scr_from', '1'); await pick('#scr_to', '2');
  await p.clickNav('#scr-versions form button[type=submit]');
  await p.waitSel('#scr-diff .rmm-diff-add');
  assert(/1 line added, 0 lines removed/.test(await p.eval(`document.getElementById('scr-diff').innerText`)), 'diff summary');
  const bg = await p.eval(`getComputedStyle(document.querySelector('#scr-diff .rmm-diff-add td.rmm-diff-text')).backgroundColor`);
  assert(bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent', 'added line has no background: ' + bg);
  assert((await p.eval(`document.querySelector('#scr-diff .rmm-diff-add .rmm-diff-sign').innerText`)).includes('+'), 'added line has no + sign');
  assert(/echo "bye"/.test(await p.eval(`document.querySelector('#scr-diff .rmm-diff-add').innerText`)), 'the added text is in the row');
  await shot('script-diff-desktop-light');
});

// ------------------------------------------------------------------------------------------------ running
await T('run panel: confirmation dialog names script, version and target; the job is queued; the secret never shows', async () => {
  await go(saved.url + '#scr-run');
  await trapDialogsOn();
  await p.waitSel('#scr-run-form');
  await pick('#tgt_type', 'client');
  await pick('#tgt_id_client', await optionValue('#tgt_id_client', 'Dept B'));
  await p.type('#scr_run_v2_greeting', 'smoke');
  await p.type('#scr_run_v2_token', SECRET);
  assert(await p.eval(`document.getElementById('scr_run_v2_token').type`) === 'password', 'secret input is a password field');
  await p.click('#scr-run-form [data-scr-go]');
  await confirmDialog();
  const t = await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`);
  assert(/Smoke hello/.test(t) && /version 2/.test(t) && /department "Dept B"/.test(t), 'sentence: ' + t);
  await shot('run-confirm-dialog-desktop-light');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
  await p.click('#rmmAutoConfirmGo');
  await waitText('1 job queued for 1 device', 'flash: job queued');
  assert(!(await p.call((a0)=>(document.documentElement.outerHTML.includes(a0)), SECRET)), 'the secret is in the page');
  await waitText('LNX1', 'the run is listed');
});
await T('run panel: a single device needs no dialog', async () => {
  await go(saved.url + '#scr-run');
  await p.waitSel('#scr-run-form');
  await pick('#tgt_type', 'device');
  await pick('#tgt_id_device', await optionValue('#tgt_id_device', 'LNX1'));
  await sleep(200);
  assert(!(await p.eval(`document.querySelector('#scr-run-form [data-scr-go]').hasAttribute('data-rmm-confirm')`)), 'the dialog is still wanted for a single device');
  await p.type('#scr_run_v2_greeting', 'one');
  await submitNav('#scr-run-form [data-scr-go]');
  await waitText('1 job queued for 1 device', 'flash: job queued');
});
await T('destructive script: the dialog has the extra checkbox and the run goes through only once it is ticked', async () => {
  await go('/agent/rmm_script_library.php?new=1');
  await p.waitSel('#scr-editor');
  await p.type('#scr_name', 'Smoke wipe');
  await setBody('rm -rf /tmp/smoke-demo\n');
  await p.click('#scr_destr');
  await submitNav('#scr-editor button[type=submit]');
  await waitText('published as version 1');
  await p.waitSel('#scr-run-form');
  await pick('#tgt_type', 'client');
  await pick('#tgt_id_client', await optionValue('#tgt_id_client', 'Dept B'));
  await p.click('#scr-run-form [data-scr-go]');
  await confirmDialog();
  assert(await p.eval(`document.getElementById('rmmAutoConfirmGo').disabled`), 'Confirm is enabled before the checkbox is ticked');
  assert(/destructive/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'sentence does not say destructive');
  await shot('run-confirm-destructive-desktop-light');
  await p.click('#rmmAutoConfirmCheck');
  assert(!(await p.eval(`document.getElementById('rmmAutoConfirmGo').disabled`)), 'Confirm stays disabled after ticking');
  await p.click('#rmmAutoConfirmGo');
  await waitText('1 job queued for 1 device', 'flash: destructive job queued');
});

// ------------------------------------------------------------------------------------------------ approvals
await T('approvals: a request made by someone else is approved through the dialog; the job is queued', async () => {
  const out = execFileSync('php', ['tests/browser/rmm_p23_b_extra.php', process.env.SEED_JSON], { encoding: 'utf8', env: process.env, timeout: 60000 });
  saved.extra = JSON.parse(out.split('\n').find((l) => l.startsWith('{')));
  assert(saved.extra.status === 202 && saved.extra.approval_id > 0, 'the fixture request was not made: ' + out);
  await go('/agent/rmm_approvals.php');
  await trapDialogsOn();
  await p.waitSel('#approvals-pending-count');
  assert(/Needs sign-off/.test(await bodyText()), 'request not listed');
  await p.click(`#approval-${saved.extra.approval_id} + tr summary`);
  await p.waitSel(`#approval-${saved.extra.approval_id} + tr pre`);
  assert(/preview-line <not-html>/.test(await p.call((a0)=>(document.querySelector('#approval-'+a0+' + tr pre').textContent), saved.extra.approval_id)), 'script preview');
  await shot('approvals-desktop-light');
  await p.type(`#apr_note_${saved.extra.approval_id}`, 'ok to go');
  await p.click(`#approval-${saved.extra.approval_id} + tr button[value=approve]`);
  await confirmDialog();
  assert(/Needs sign-off/.test(await p.eval(`document.getElementById('rmmAutoConfirmText').textContent`)), 'the dialog repeats the summary');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
  await p.click('#rmmAutoConfirmGo');
  await waitText('Approved. 1 job queued for 1 device', 'flash: approved');
  await go('/agent/rmm_approvals.php?state=approved');
  await p.waitSel('table');
  assert(/ok to go/.test(await bodyText()), 'note on the approved list');
});
await T('approvals: your own request cannot be approved (disabled with the words); cancel works', async () => {
  await go(saved.url.split('?')[0] + '?script_id=' + saved.extra.script_id);
  await p.waitSel('#scr-run-form');
  await pick('#tgt_type', 'device');
  await pick('#tgt_id_device', await optionValue('#tgt_id_device', 'LNX1'));
  await submitNav('#scr-run-form [data-scr-go]');
  await waitText('needs a second person to approve', 'flash: waits for approval');
  assert(/\/agent\/rmm_approvals\.php/.test(await p.url()), 'not taken to the approvals page');
  await p.waitSel('details summary');
  await p.click('details summary');
  await sleep(300);
  assert(/You cannot approve your own request/.test(await bodyText()), 'own request text');
  assert(!(await p.exists('button[name=decision]')), 'approve buttons drawn for an own request');
  await shot('approvals-own-request-desktop-light');
  await p.click('details button[data-rmm-confirm="Cancel this request? Nothing is run."]');
  await confirmDialog();
  await p.click('#rmmAutoConfirmGo');
  await waitText('Request cancelled', 'flash: cancelled');
});

// ------------------------------------------------------------------------------------------------ schedules
await T('schedules: create (form, cron toggle, validation), list, detail', async () => {
  await go('/agent/rmm_schedules.php');
  await p.waitSel('.it-empty-state');
  await p.clickNav('a[href*="new=1"]');
  await p.waitSel('#sch-form');
  await p.type('#sch_name', 'Smoke hourly');
  await pick('#sch_script', await optionValue('#sch_script', 'Smoke hello'));
  await pick('#tgt_type', 'client');
  await pick('#tgt_id_client', await optionValue('#tgt_id_client', 'Dept B'));
  await pick('#sch_kind', 'cron');
  assert(await p.visible('#sch_cron') && !(await p.visible('#sch_iv')), 'cron field shown, interval hidden');
  assert(/\*\/15 \* \* \* \*/.test(await p.eval(`document.getElementById('sch-form').innerText`)), 'cron examples');
  await p.type('#sch_cron', '99 * * * *');
  const sidv = await p.eval(`document.getElementById('sch_script').value`);
  await p.type(`#sch_p${sidv}_greeting`, 'scheduled');
  await shot('schedule-form-desktop-light');
  await submitNav('#sch-form button[type=submit]');
  assert(/Cron minute/.test(await flash()), 'flash after a bad cron: ' + (await flash()));
  await p.waitSel('#sch-form');
  assert((await p.eval(`document.getElementById('sch_name').value`)) === 'Smoke hourly' && (await p.eval(`document.getElementById('sch_cron').value`)) === '99 * * * *' && (await p.eval(`document.getElementById('sch_kind').value`)) === 'cron'
    && (await p.eval(`document.getElementById('sch_script').value`)) === sidv && (await p.eval(`document.querySelector('#tgt_type').value`)) === 'client', 'the form lost what was typed after a refusal');
  assert((await p.call((a0)=>(document.getElementById('sch_p'+a0+'_greeting').value), sidv)) === 'scheduled', 'the parameter value was lost');
  await pick('#sch_kind', 'interval');
  await p.type('#sch_iv', '1');
  await pick('#sch_ivu', 'hour');
  await p.type('#sch_start', '30');
  await submitNav('#sch-form button[type=submit]');
  await waitText('Schedule saved', 'flash: saved');
  await p.waitSel('#scr-nothing, h4');
  const t = await bodyText();
  assert(/Every 1 hour/.test(t) && /Smoke hello/.test(t) && /Result history per device/.test(t) && /Department Dept B/.test(t), 'detail page: ' + t.slice(0, 300));
  saved.sch = new URL(await p.url()).pathname + new URL(await p.url()).search;
  await shot('schedule-detail-desktop-light');
});
await T('schedules: list shows cadence and next run; pause and resume; delete asks first', async () => {
  await go('/agent/rmm_schedules.php');
  await trapDialogsOn();
  await p.waitSel('table');
  let t = await bodyText();
  assert(/Smoke hourly/.test(t) && /Every 1 hour/.test(t) && /Active/.test(t) && /Department Dept B/.test(t), 'list row');
  await shot('schedules-desktop-light');
  await p.click('form button.btn-outline-secondary:not([data-rmm-confirm])');
  await waitText('Schedule paused', 'flash: paused');
  assert(/Paused/.test(await bodyText()) && /not while paused/.test(await bodyText()), 'paused state');
  await p.click('form button.btn-outline-secondary:not([data-rmm-confirm])');
  await waitText('Schedule resumed', 'flash: resumed');
  await trapDialogsOn();
  await p.click('button[data-rmm-confirm^="Delete the schedule"]');
  await confirmDialog();
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmAutoConfirm.show')`, { label: 'dialog closed' });
  assert(/Smoke hourly/.test(await bodyText()), 'cancelling the dialog deleted it');
  assert((await p.eval('window.__dialogs')) === 0, 'window.confirm/alert was used');
});

// ------------------------------------------------------------------------------------------------ asset page card
await T('asset page: the card lists the scripts of this platform and runs one on the device', async () => {
  await go(asset('LNX1') + '#rmm-jobs');
  await p.waitSel('#rmm-library-run');
  const opts = await p.eval(`[...document.querySelectorAll('#rmm-ds-script option')].map(o=>o.text)`);
  assert(opts.some((o) => /Smoke hello/.test(o)) && opts.length >= 2, 'scripts for a Linux device: ' + opts.join(' | '));
  await pick('#rmm-ds-script', await optionValue('#rmm-ds-script', 'Smoke hello'));
  await p.waitSel('#rmm-library-run input[name="param[greeting]"]');
  await p.type('#rmm-library-run input[name="param[greeting]"]', 'card');
  await shot('asset-run-card-desktop-light');
  await submitNav('#rmm-ds-go');
  await waitText('Job queued', 'flash: job queued');
  assert(/asset_details\.php/.test(await p.url()), 'back on the asset page');
  await go(asset('WIN1') + '#rmm-jobs');
  await p.waitSel('#rmm-library-run');
  assert(!(await p.exists('#rmm-ds-script')) && /No library script runs on a Windows device/.test(await p.eval(`document.getElementById('rmm-library-run').innerText`)), 'a bash script is offered for a Windows device');
});
await T('asset page: a destructive script asks for the dialog', async () => {
  await go(asset('LNX1') + '#rmm-jobs');
  await p.waitSel('#rmm-library-run');
  await pick('#rmm-ds-script', await optionValue('#rmm-ds-script', 'Smoke wipe'));
  await sleep(200);
  await p.click('#rmm-ds-go');
  await confirmDialog();
  assert(await p.eval(`document.getElementById('rmmAutoConfirmGo').disabled`), 'Confirm enabled before the checkbox');
  await p.press('Escape');
  await p.waitFor(`!document.querySelector('#rmmAutoConfirm.show')`, { label: 'closed' });
});

// ------------------------------------------------------------------------------------------------ looks: phone, dark
const PAGES = [
  ['library', () => '/agent/rmm_script_library.php', 'table'],
  ['script', () => saved.url.split('#')[0], '#scr-editor'],
  ['diff', () => saved.url.split('?')[0] + '?script_id=' + new URL(saved.url, BASE).searchParams.get('script_id') + '&from=1&to=2', '#scr-diff'],
  ['schedules', () => '/agent/rmm_schedules.php', 'table'],
  ['schedule', () => saved.sch, '#sch-nothing, h4'],
  ['approvals', () => '/agent/rmm_approvals.php?state=all', 'table'],
  ['asset-card', () => asset('LNX1') + '#rmm-jobs', '#rmm-library-run'],
];
await T('phone width, light: no horizontal scroll on every page', async () => {
  await p.setViewport(MOBILE_W, 800, true);
  for (const [name, path, sel] of PAGES) { await go(path()); await p.waitSel(sel); await sleep(350); await noOverflow(name + ' phone light'); await shot(name + '-phone-light'); }
  await p.setViewport(1366, 900);
});
await T('dark theme: desktop and phone, readable diff and tables', async () => {
  await setTheme(1);
  for (const [name, path, sel] of PAGES) { await go(path()); await p.waitSel(sel); await sleep(350); await shot(name + '-desktop-dark'); }
  const bg = await p.eval(`(()=>{const e=document.querySelector('#scr-diff .rmm-diff-add td.rmm-diff-text');return e?getComputedStyle(e).backgroundColor:'none'})()`);
  await go(PAGES[2][1]()); await p.waitSel('#scr-diff .rmm-diff-add');
  const dbg = await p.eval(`getComputedStyle(document.querySelector('#scr-diff .rmm-diff-add td.rmm-diff-text')).backgroundColor`);
  const dfg = await p.eval(`getComputedStyle(document.querySelector('#scr-diff .rmm-diff-add td.rmm-diff-text')).color`);
  const [fr, fg, fb] = dfg.match(/\d+/g).map(Number);
  assert(fr + fg + fb > 3 * 110, 'diff text is not light in dark mode: ' + dfg + ' on ' + dbg);
  await p.setViewport(MOBILE_W, 800, true);
  for (const [name, path, sel] of PAGES) { await go(path()); await p.waitSel(sel); await sleep(350); await noOverflow(name + ' phone dark'); await shot(name + '-phone-dark'); }
  await p.setViewport(1366, 900);
  await setTheme(0);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
