// Regression test for IT-WEB-1 (js/ajax_modal.js): URL allow-list, content-type gate, script token. Run: node tests/it_sec_xss_modal_js.js
const fs = require('fs'), vm = require('vm'), path = require('path');
const code = fs.readFileSync(path.join(__dirname, '..', 'js', 'ajax_modal.js'), 'utf8');
let pass = 0, fail = 0;
const ok = (c, l) => { if (c) { pass++; console.log('  ok   ' + l); } else { fail++; console.log('  FAIL ' + l); } };

function mkEnv(fetchImpl) {
  const fetched = [];
  const spinner = { remove() {}, id: '', className: '', innerHTML: '' };
  const host = { appendChild() {} };
  const doc = {
    addEventListener() {}, querySelector: () => host, body: host, getElementById: () => null,
    createElement: () => Object.assign({ remove() {}, setAttribute() {}, attributes: [] }, spinner),
  };
  const win = { location: { href: 'https://itflow.example/agent/tickets.php', origin: 'https://itflow.example' }, CSP_NONCE: 'N' };
  const alerts = [];
  const ctx = vm.createContext({ window: win, document: doc, URL, console: { warn() {}, error() {}, log() {} }, Date, Promise, Array,
    RivetDialog: { alert: (m) => alerts.push(m) }, fetch: (u, o) => { fetched.push(u); return fetchImpl(u, o); }, bootstrap: {} });
  vm.runInContext(code, ctx);
  return { win, fetched, alerts, ctx };
}
const resp = (ct, body, token) => Promise.resolve({ ok: true, status: 200, url: 'https://itflow.example/agent/modals/a/b.php',
  headers: { get: (h) => (h.toLowerCase() === 'content-type' ? ct : (h.toLowerCase() === 'x-rivet-modal-token' ? token : null)) }, json: () => Promise.resolve(body) });

console.log('== URL allow-list');
{
  const { win } = mkEnv(() => resp('application/json', {}));
  const a = win.isAllowedModalUrl;
  ok(a('modals/ticket/ticket_add_v2.php?client_id=1'), 'relative modals/ path (from /agent/) allowed');
  ok(a('/agent/modals/contract/contract_add.php?client_id=3'), 'absolute /agent/modals/... allowed');
  ok(a('/admin/modals/api/api_key_edit.php?id=2'), '/admin/modals allowed');
  ok(a('/modals/event_catalog.php'), 'root /modals allowed');
  ok(!a('/uploads/tickets/1/abc.json'), '/uploads/... json refused');
  ok(!a('/uploads/tickets/1/abc.txt'), '/uploads/... txt refused');
  ok(!a('/agent/ajax.php?search_contacts&q=x'), 'other same-origin endpoint refused');
  ok(!a('https://evil.example/agent/modals/x.php'), 'cross-origin refused');
  ok(!a('//evil.example/agent/modals/x.php'), 'protocol-relative cross-origin refused');
  ok(!a('/agent/modals/../../uploads/x.json'), 'traversal into uploads refused');
  ok(!a('/agent/modals/x.php%2f..%2f..%2fuploads%2fa.json'), 'encoded traversal refused');
  ok(!a('/uploads/x/modals/y.php'), 'uploads path that looks like a modal refused');
  ok(!a('javascript:alert(1)'), 'javascript: refused');
  ok(!a('/agent/modals/x.json'), 'non-php under modals refused');
}
console.log('\n== openAjaxModal never fetches a refused URL');
{
  const { win, fetched } = mkEnv(() => resp('application/json', {}));
  let err = null; win.openAjaxModal('/uploads/tickets/1/abc.json', 'md', { onError: (e) => { err = e; } });
  ok(fetched.length === 0 && err, 'no fetch for /uploads URL, onError called');
}
console.log('\n== content-type gate');
(async () => {
  for (const ct of ['text/plain', 'text/html', 'application/octet-stream', '']) {
    const { win, fetched } = mkEnv(() => resp(ct, { content: '<script>1</script>' }, 'tok'));
    let err = null; win.openAjaxModal('modals/a/b.php', 'md', { onError: (e) => { err = e; } });
    await new Promise((r) => setTimeout(r, 20));
    ok(fetched.length === 1 && err, 'fetched but refused for content-type "' + ct + '"');
  }
  // executed scripts: reuse executeInjectedScripts through a fake container
  console.log('\n== script token');
  const made = [];
  const mkScript = (attrs, text) => ({ attributes: Object.entries(attrs).map(([name, value]) => ({ name, value })), textContent: text, removed: false,
    getAttribute(n) { return attrs[n] === undefined ? null : attrs[n]; }, remove() { this.removed = true; }, replaceWith(n) { this.replacedBy = n; } });
  const run = (token, scripts) => {
    const { ctx } = mkEnv(() => resp('application/json', {}));
    ctx.document.createElement = () => { const e = { attrs: {}, setAttribute(n, v) { this.attrs[n] = v; }, addEventListener() {} }; made.push(e); return e; };
    const container = { querySelectorAll: () => scripts };
    vm.runInContext('executeInjectedScripts', ctx)(container, token);
  };
  const good = mkScript({ 'data-modal-script': 'tok' }, 'a()');
  const noTok = mkScript({}, 'b()');
  const wrong = mkScript({ 'data-modal-script': 'other' }, 'c()');
  const xorigin = mkScript({ 'data-modal-script': 'tok', src: 'https://evil.example/p.js' }, '');
  run('tok', [good, noTok, wrong, xorigin]);
  ok(good.replacedBy && !good.removed, 'script with the response token runs');
  ok(noTok.removed && !noTok.replacedBy, 'script without a token is removed, not run');
  ok(wrong.removed && !wrong.replacedBy, 'script with another token is removed, not run');
  ok(xorigin.removed && !xorigin.replacedBy, 'cross-origin script src is never run');
  const s2 = mkScript({ 'data-modal-script': 'tok' }, 'x()');
  run('', [s2]);
  ok(s2.removed && !s2.replacedBy, 'no response token (header missing): nothing runs');
  console.log('\n' + pass + ' passed, ' + fail + ' failed');
  console.log(fail ? 'FAILED' : 'ALL PASSED');
  process.exit(fail ? 1 : 0);
})();
