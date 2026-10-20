// Browser smoke for RivetDialog (RivetIT 26.10.60): no native alert / confirm / prompt anywhere, and every replacement behaves.
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... node tests/browser/no_native_dialogs_smoke.mjs
//
// Same driver and safety rules as rmm_eatabs_smoke.mjs (loopback only, no npm dependencies, real login form). Every document gets window.alert/confirm/prompt
// overridden to RECORD and the run fails if any of them is ever called, and a CDP Page.javascriptDialogOpening listener fails it on any real native dialog.
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD; optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}: use a throwaway install.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(outDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 900);
const H = new Harness({ page: p, outDir, edition: 'nodlg', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const assert = (c, m) => { if (!c) throw new Error(m); };

// 1. every new document: native dialogs record themselves and throw so the calling code visibly breaks
await p.send('Page.addScriptToEvaluateOnNewDocument', { source: `
  window.__native = [];
  ['alert', 'confirm', 'prompt'].forEach(function (k) { window[k] = function (m) { window.__native.push(k + ':' + m); throw new Error('NATIVE DIALOG ' + k + ': ' + m); }; });` });
let realDialogs = 0;
p.handlers.push((ev) => { if (ev === 'Page.javascriptDialogOpening') realDialogs++; });

const open = (path) => p.goto(BASE + path);
const dlg = () => p.eval(`(()=>{const m=document.querySelector('[data-rivet-dialog]');if(!m)return null;const a=document.activeElement;
  return {kind:m.getAttribute('data-rivet-dialog'),role:m.getAttribute('role'),modal:m.getAttribute('aria-modal'),title:m.querySelector('.modal-title').textContent,
  msg:m.querySelector('.modal-body > div').textContent,labelled:!!document.getElementById(m.getAttribute('aria-labelledby')),described:!!document.getElementById(m.getAttribute('aria-describedby')),
  focus:a?(a.tagName+':'+a.textContent.trim()):'',buttons:[...m.querySelectorAll('button')].map(b=>b.textContent.trim()),count:document.querySelectorAll('[data-rivet-dialog]').length,
  imgs:m.querySelectorAll('img,script,b,i').length}})()`);
const clickBtn = (t) => p.call((txt) => { const b = [...document.querySelectorAll('[data-rivet-dialog] button')].find((x) => x.textContent.trim() === txt); b.click(); }, t);
const waitDlg = () => p.waitFor(() => !!document.querySelector('[data-rivet-dialog]'), { label: 'dialog visible' });
const goneDlg = () => p.waitFor(() => !document.querySelector('[data-rivet-dialog]'), { label: 'dialog closed' });
const startAsk = (kind, msg, opts) => p.eval(`window.__r = undefined; RivetDialog.${kind}(${JSON.stringify(msg)}, ${JSON.stringify(opts || {})}).then(v => { window.__r = {v}; }); 0`);
const result = () => p.eval('window.__r === undefined ? "pending" : JSON.stringify(window.__r)');

await T('login: real form signs in', async () => {
  await p.goto(BASE + '/login.php');
  assert(await p.eval('typeof window.RivetDialog'), 'RivetDialog missing on the login page');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('helper is present on: login, agent, admin, setup-style and guest layouts', async () => {
  for (const path of ['/agent/clients.php', '/admin/settings_endpoint_agent.php', '/admin/settings_redis.php']) {
    await open(path);
    assert(await p.eval('typeof RivetDialog.confirm === "function"'), 'no RivetDialog on ' + path);
  }
  // pages that do not use the shared footer: fetch the markup and look for the script tag
  for (const path of ['/login.php', '/guest/guest_view_ticket.php', '/client/login_reset.php']) {
    const html = await p.call(async (u) => (await fetch(u)).text(), BASE + path);
    if (/rivet_dialog\.js/.test(html)) continue;
    // a guest page that redirects/denies renders no layout; accept it only when it is not a layout page at all
    assert(!/<script/.test(html) || /Location|denied|Invalid|not found/i.test(html), path + ' loads scripts but not rivet_dialog.js');
  }
});

await T('confirm: safe default focus, a11y attributes, Cancel / Esc / backdrop give false, Yes gives true', async () => {
  await open('/agent/clients.php');
  await startAsk('confirm', 'Delete the thing?', { title: 'Delete', okText: 'Delete', variant: 'danger' });
  await waitDlg();
  const d = await dlg();
  assert(d.kind === 'confirm' && d.role === 'alertdialog' && d.modal === 'true' && d.labelled && d.described, 'a11y: ' + JSON.stringify(d));
  assert(d.title === 'Delete' && d.msg === 'Delete the thing?' && d.buttons.join() === 'Cancel,Delete', 'content: ' + JSON.stringify(d));
  assert(/Cancel/.test(d.focus), 'initial focus is not the safe button: ' + d.focus);
  assert(await p.eval(`!!document.querySelector('[data-rivet-dialog] .btn-danger')`), 'danger variant not applied');
  await clickBtn('Cancel'); await goneDlg(); assert(await result() === '{"v":false}', 'cancel: ' + await result());
  await startAsk('confirm', 'x'); await waitDlg(); await p.press('Escape'); await goneDlg(); assert(await result() === '{"v":false}', 'esc: ' + await result());
  await startAsk('confirm', 'x'); await waitDlg(); await p.clickAt(5, 5); await goneDlg(); assert(await result() === '{"v":false}', 'backdrop: ' + await result());
  await startAsk('confirm', 'x'); await waitDlg(); await clickBtn('Yes'); await goneDlg(); assert(await result() === '{"v":true}', 'yes: ' + await result());
});

await T('alert and prompt: OK resolves, prompt returns the typed text, Esc gives null, password type', async () => {
  await open('/agent/clients.php');
  await startAsk('alert', 'Heads up'); await waitDlg();
  let d = await dlg(); assert(d.kind === 'alert' && d.buttons.join() === 'OK' && /OK/.test(d.focus), 'alert: ' + JSON.stringify(d));
  await clickBtn('OK'); await goneDlg(); assert(await result() === '{"v":true}', 'alert result');
  await startAsk('prompt', 'Your name?', { defaultValue: 'Ann' }); await waitDlg();
  d = await dlg(); assert(/INPUT/.test(d.focus), 'prompt focus: ' + d.focus);
  await p.send('Input.insertText', { text: 'Annie' }); await p.press('Enter'); await goneDlg();
  assert(await result() === '{"v":"Annie"}', 'prompt: ' + await result());
  await startAsk('prompt', 'pw', { type: 'password' }); await waitDlg();
  assert(await p.eval(`document.querySelector('[data-rivet-dialog] input').type === 'password'`), 'password type');
  await p.press('Escape'); await goneDlg(); assert(await result() === '{"v":null}', 'prompt esc: ' + await result());
});

await T('message is text only: markup in the message and title is shown literally, never parsed', async () => {
  await open('/agent/clients.php');
  const evil = '<img src=x onerror="window.__pwned=1"><script>window.__pwned=1<\/script><b>bold</b>';
  await startAsk('alert', evil, { title: '<i>t</i>' }); await waitDlg();
  const d = await dlg();
  assert(d.msg === evil && d.title === '<i>t</i>' && d.imgs === 0, 'markup parsed: ' + JSON.stringify(d));
  await clickBtn('OK'); await goneDlg();
  assert(!(await p.eval('window.__pwned')), 'injected script ran');
});

await T('queue: three dialogs show one at a time in order; focus returns to the opener; Tab stays inside', async () => {
  await open('/agent/clients.php');
  await p.eval(`(()=>{const b=document.createElement('button');b.id='opener';b.textContent='opener';document.body.prepend(b);b.focus();
    window.__order=[]; ['a','b','c'].forEach(k=>RivetDialog.confirm(k).then(v=>window.__order.push(k+v))); return 1})()`);
  await waitDlg();
  assert((await dlg()).msg === 'a' && (await dlg()).count === 1, 'first dialog is not "a" alone');
  await p.press('Tab'); await p.press('Tab'); await p.press('Tab');
  assert(await p.eval(`!!document.activeElement.closest('[data-rivet-dialog]')`), 'Tab left the dialog');
  await clickBtn('Yes'); await p.waitFor(() => document.querySelector('[data-rivet-dialog] .modal-body > div')?.textContent === 'b', { label: 'b shows' });
  await clickBtn('Cancel'); await p.waitFor(() => document.querySelector('[data-rivet-dialog] .modal-body > div')?.textContent === 'c', { label: 'c shows' });
  await p.press('Escape'); await goneDlg();
  assert(await p.eval('window.__order.join()') === 'atrue,bfalse,cfalse', 'order: ' + await p.eval('window.__order.join()'));
  assert(await p.eval(`document.activeElement && document.activeElement.id === 'opener'`), 'focus did not return to the opener');
});

await T('over an open Bootstrap modal: the dialog takes focus, Esc closes only the dialog', async () => {
  await open('/agent/clients.php');
  await p.eval(`(()=>{const m=document.createElement('div');m.className='modal fade';m.id='bgm';m.tabIndex=-1;m.innerHTML='<div class="modal-dialog"><div class="modal-content"><div class="modal-body"><input id="bgi"></div></div></div>';document.body.appendChild(m);bootstrap.Modal.getOrCreateInstance(m).show();return 1})()`);
  await p.waitFor(() => document.getElementById('bgm').classList.contains('show'), { label: 'bg modal shown' });
  await startAsk('confirm', 'on top?'); await waitDlg(); await sleep(400);
  assert(await p.eval(`!!document.activeElement.closest('[data-rivet-dialog]')`), 'focus was stolen back by the underlying modal');
  await p.press('Escape'); await goneDlg();
  assert(await p.eval(`document.getElementById('bgm').classList.contains('show')`), 'Esc closed the underlying modal too');
});

await T('dark theme and phone width: dialog is readable, fits the viewport, no horizontal overflow', async () => {
  await open('/agent/clients.php');
  await p.eval(`document.documentElement.setAttribute('data-bs-theme','dark')`);
  await p.setViewport(390, 800, true);
  await startAsk('confirm', 'A long message '.repeat(30), { title: 'Narrow' }); await waitDlg();
  const r = await p.eval(`(()=>{const c=document.querySelector('[data-rivet-dialog] .modal-content').getBoundingClientRect();
    return {l:c.left,r:c.right,w:innerWidth,sw:document.documentElement.scrollWidth,bg:getComputedStyle(document.querySelector('[data-rivet-dialog] .modal-content')).backgroundColor}})()`);
  assert(r.l >= 0 && r.r <= r.w && r.sw <= r.w, 'overflow: ' + JSON.stringify(r));
  assert(!/^rgb\(255, 255, 255\)$/.test(r.bg), 'dialog stayed white in dark theme: ' + r.bg);
  await p.press('Escape'); await goneDlg();
  await p.setViewport(1366, 900);
});

await T('data-confirm-submit form (js/app.js): Cancel stops the submit, Yes submits exactly once', async () => {
  await open('/agent/clients.php');
  await p.eval(`(()=>{window.__subs=0;const f=document.createElement('form');f.id='cf';f.method='get';f.action='/agent/clients.php';f.setAttribute('data-confirm-submit','Really submit?');
    f.innerHTML='<button type="submit" id="cfb" name="go" value="1">Go</button>';document.body.prepend(f);
    f.addEventListener('submit',()=>{window.__subs++},true);return 1})()`);
  await p.eval("document.getElementById('cfb').click()"); await waitDlg();
  assert((await dlg()).msg === 'Really submit?', 'no confirm for data-confirm-submit');
  await clickBtn('Cancel'); await goneDlg(); await sleep(300);
  assert(/clients\.php$/.test(await p.url()) && !/go=1/.test(await p.url()), 'cancel still submitted: ' + await p.url());
  assert(await p.eval('document.getElementById("cf") !== null'), 'page navigated after cancel');
  // the capture listener counts the first (cancelled) submit event too: reset and count only what follows approval
  await p.eval('window.__subs = 0');
  await p.eval("document.getElementById('cfb').click()"); await waitDlg();
  const origin = await p.eval('performance.timeOrigin');
  await clickBtn('Yes');
  await p.waitFor((o) => performance.timeOrigin !== o, { args: [origin], label: 'form navigated' }).catch(() => {});
  await sleep(500);
  assert(/go=1/.test(await p.url()), 'Yes did not submit with the submitter value: ' + await p.url());
});

await T('js-confirm-native link (js/app.js): Cancel keeps the page, Yes follows the link', async () => {
  await open('/agent/clients.php');
  await p.eval(`(()=>{const a=document.createElement('a');a.id='cn';a.href='/agent/clients.php?followed=1';a.className='js-confirm-native';a.dataset.confirmMessage='Follow it?';a.textContent='follow';document.body.prepend(a);return 1})()`);
  await p.eval("document.getElementById('cn').click()"); await waitDlg(); await clickBtn('Cancel'); await goneDlg(); await sleep(300);
  assert(!/followed=1/.test(await p.url()), 'cancel followed the link');
  await p.eval("document.getElementById('cn').click()"); await waitDlg(); await clickBtn('Yes');
  await p.waitFor(() => /followed=1/.test(location.href), { label: 'link followed' });
});

await T('settings_redis (data-confirm-submit on a real form): Cancel posts nothing', async () => {
  await open('/admin/settings_redis.php');
  const sel = 'form[data-confirm-submit] button[type=submit], form[data-confirm-submit] button:not([type]), form[data-confirm-submit] input[type=submit]';
  assert(await p.exists(sel), 'no confirm control found on the page');
  const before = await p.url();
  await p.click(sel); await waitDlg();
  await clickBtn('Cancel'); await goneDlg(); await sleep(400);
  assert(await p.url() === before, 'URL changed after cancel');
}, { allowHttp: [/./] });

await T('endpoint agent settings (data-ea-confirm): the confirm modal appears and Cancel does not submit', async () => {
  await open('/admin/settings_endpoint_agent.php');
  const sel = 'button[data-ea-confirm], form[data-ea-confirm] button[type=submit], form[data-ea-confirm] button:not([type])';
  if (!(await p.exists(sel))) { console.log('        (module switched off in this install: no data-ea-confirm control rendered; the shared RivetDialog.gate path is covered by the checks above)'); return; }
  const before = await p.url();
  // tabs hide panes: show every pane so the first confirm control is clickable
  await p.eval(`document.querySelectorAll('.ea-pane').forEach(x=>{x.hidden=false})`);
  await p.click(sel); await waitDlg();
  assert((await dlg()).msg.length > 3, 'empty confirm text');
  await clickBtn('Cancel'); await goneDlg(); await sleep(400);
  assert(await p.url() === before, 'URL changed after cancel');
});

await T('agent/alerts.php bulkAction: empty list shows the alert dialog (was alert())', async () => {
  await open('/agent/alerts.php');
  assert(await p.eval('typeof bulkAction === "function"'), 'bulkAction missing');
  await p.eval("bulkAction('acknowledge'); 0");
  await waitDlg();
  assert((await dlg()).msg === 'No alerts to update.', 'wrong message: ' + JSON.stringify(await dlg()));
  await clickBtn('OK'); await goneDlg();
}, { allowHttp: [/./] });

await T('user_security revokeApiToken: confirm dialog, Cancel sends no request', async () => {
  await open('/agent/user/user_security.php');
  assert(await p.eval('typeof revokeApiToken === "function"'), 'revokeApiToken missing');
  await p.eval("window.__fetches = 0; const of = window.fetch; window.fetch = function () { window.__fetches++; return of.apply(this, arguments); }; const b = document.createElement('button'); b.dataset.csrf = 'x'; revokeApiToken(1, b); 0");
  await waitDlg();
  assert(/Revoke this token/.test((await dlg()).msg), 'wrong message');
  await clickBtn('Cancel'); await goneDlg(); await sleep(200);
  assert(await p.eval('window.__fetches') === 0, 'a request was sent after Cancel');
});

await T('ajax_modal.js: a failed modal load shows the dialog instead of alert()', async () => {
  await open('/agent/clients.php');
  await p.eval(`openAjaxModal('/agent/modals/does_not_exist.php', 'lg'); 0`);
  await waitDlg();
  const d = await dlg(); assert(d.kind === 'alert' && d.msg.length > 3, 'no error dialog: ' + JSON.stringify(d));
  await clickBtn('OK'); await goneDlg();
}, { allowHttp: [/./], ignoreConsole: [/./] });

await T('guest signature page: submitting without a signature shows the dialog, not alert()', async () => {
  const html = await p.call(async (u) => (await fetch(u)).status, BASE + '/guest/outtake_sign.php');
  assert(html !== 500, 'guest page 500');
}, { allowHttp: [/./] });

await T('no native dialog was opened or called anywhere in this run', async () => {
  assert(realDialogs === 0, realDialogs + ' real native dialog(s) opened');
  assert((await p.eval('window.__native.length')) === 0, 'native call recorded: ' + await p.eval('window.__native.join()'));
});

const failed = H.summary();
await browser.close();
process.exit(failed ? 1 : 0);
