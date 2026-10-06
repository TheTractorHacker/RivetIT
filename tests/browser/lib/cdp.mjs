// Minimal zero-dependency Chrome DevTools Protocol driver (Node >= 22: global WebSocket + fetch).
// Just enough surface for the smoke suite: launch Chrome, navigate, evaluate, click/type/press via real
// input events, screenshots, viewport emulation and console / network / exception capture.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, existsSync, readdirSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join } from 'node:path';

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export function findChrome() {
  if (process.env.CHROME_BIN) return process.env.CHROME_BIN;
  const roots = [join(homedir(), '.cache/puppeteer/chrome'), join(homedir(), '.cache/ms-playwright')];
  for (const root of roots) {
    if (!existsSync(root)) continue;
    for (const d of readdirSync(root).sort().reverse()) {
      for (const rel of ['chrome-linux64/chrome', 'chrome-linux/chrome']) {
        const p = join(root, d, rel);
        if (existsSync(p)) return p;
      }
    }
  }
  for (const p of ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/snap/bin/chromium']) {
    if (existsSync(p)) return p;
  }
  throw new Error('No Chrome found. Set CHROME_BIN=/path/to/chrome (see tests/browser/README.md).');
}

export class Browser {
  static async launch({ headless = true } = {}) {
    const b = new Browser();
    b.dir = mkdtempSync(join(tmpdir(), 'rivet-browser-'));
    const args = [
      '--remote-debugging-port=0', `--user-data-dir=${b.dir}`, '--no-first-run', '--no-default-browser-check',
      '--disable-extensions', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars=false',
      '--window-size=1366,900', 'about:blank',
    ];
    if (headless) args.unshift('--headless=new');
    b.proc = spawn(findChrome(), args, { stdio: ['ignore', 'ignore', 'pipe'] });
    b.wsUrl = await new Promise((resolve, reject) => {
      let buf = '';
      const t = setTimeout(() => reject(new Error("Chrome did not start: " + buf.slice(-400))), 60000);
      b.proc.stderr.on('data', (d) => {
        buf += d;
        const m = buf.match(/DevTools listening on (ws:\/\/\S+)/);
        if (m) { clearTimeout(t); resolve(m[1]); }
      });
      b.proc.on('exit', (c) => reject(new Error('Chrome exited ' + c + ': ' + buf.slice(-400))));
    });
    b.port = new URL(b.wsUrl).port;
    return b;
  }
  async newPage() {
    const t = await (await fetch(`http://127.0.0.1:${this.port}/json/new?about:blank`, { method: 'PUT' })).json();
    const p = new Page(t.webSocketDebuggerUrl);
    await p.init();
    return p;
  }
  async close() {
    try { this.proc.kill('SIGTERM'); } catch {}
    await sleep(300);
    try { this.proc.kill('SIGKILL'); } catch {}
    try { rmSync(this.dir, { recursive: true, force: true }); } catch {}
  }
}

export class Page {
  constructor(wsUrl) { this.wsUrl = wsUrl; this.id = 0; this.pending = new Map(); this.handlers = []; this.inflight = new Map();
    this.events = []; this.base = ''; }
  async init() {
    this.ws = new WebSocket(this.wsUrl);
    await new Promise((res, rej) => { this.ws.onopen = res; this.ws.onerror = () => rej(new Error('ws error')); });
    this.ws.onmessage = (m) => this._msg(JSON.parse(m.data));
    for (const d of ['Page', 'Runtime', 'Network', 'Log', 'DOM']) await this.send(`${d}.enable`);
    await this.send('Page.setLifecycleEventsEnabled', { enabled: true });
  }
  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((res, rej) => this.pending.set(id, { res, rej, method }));
  }
  _msg(m) {
    if (m.id) {
      const p = this.pending.get(m.id); this.pending.delete(m.id);
      if (p) m.error ? p.rej(new Error(`${p.method}: ${m.error.message}`)) : p.res(m.result);
      return;
    }
    const { method: ev, params: pr } = m;
    if (ev === 'Runtime.exceptionThrown') {
      const d = pr.exceptionDetails;
      this.events.push({ type: 'exception', text: (d.exception && (d.exception.description || d.exception.value)) || d.text, url: d.url || '' });
    } else if (ev === 'Runtime.consoleAPICalled' && pr.type === 'error') {
      this.events.push({ type: 'console.error', text: pr.args.map((a) => a.value ?? a.description ?? '').join(' ') });
    } else if (ev === 'Log.entryAdded' && pr.entry.level === 'error' && pr.entry.source !== 'network') {
      this.events.push({ type: 'log.error', text: pr.entry.text + ' ' + (pr.entry.url || '') });
    } else if (ev === 'Network.requestWillBeSent') {
      this.inflight.set(pr.requestId, { url: pr.request.url, method: pr.request.method, type: pr.type });
    } else if (ev === 'Network.responseReceived') {
      const r = this.inflight.get(pr.requestId) || {}; r.status = pr.response.status; r.url = pr.response.url;
      this.inflight.set(pr.requestId, r);
      if (pr.response.status >= 400) this.events.push({ type: 'http', status: pr.response.status, url: pr.response.url, method: r.method, rtype: pr.type });
    } else if (ev === 'Network.loadingFailed') {
      const r = this.inflight.get(pr.requestId) || {};
      if (!pr.canceled && pr.errorText !== 'net::ERR_ABORTED') this.events.push({ type: 'netfail', url: r.url || '', text: pr.errorText });
    } else if (ev === 'Page.javascriptDialogOpening') {
      this.dialog = pr; this.events.push({ type: 'dialog', text: pr.message });
      this.send('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
    }
    for (const h of this.handlers) h(ev, pr);
  }
  waitEvent(name, pred = () => true, timeout = 15000) {
    return new Promise((res, rej) => {
      const t = setTimeout(() => { this.handlers = this.handlers.filter((x) => x !== h); rej(new Error('timeout waiting ' + name)); }, timeout);
      const h = (ev, pr) => { if (ev === name && pred(pr)) { clearTimeout(t); this.handlers = this.handlers.filter((x) => x !== h); res(pr); } };
      this.handlers.push(h);
    });
  }
  async goto(url, { timeout = 30000 } = {}) {
    const w = this.waitEvent('Page.loadEventFired', () => true, timeout).catch(() => {});
    const r = await this.send('Page.navigate', { url });
    if (r.errorText) throw new Error('navigate ' + url + ': ' + r.errorText);
    await w; await sleep(250);
  }
  async waitNav(timeout = 30000) { await this.waitEvent('Page.loadEventFired', () => true, timeout).catch(() => {}); await sleep(250); }
  async eval(expr, { await: aw = true } = {}) {
    const r = await this.send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: aw, userGesture: true });
    if (r.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    return r.result.value;
  }
  url() { return this.eval('location.href'); }
  async waitFor(expr, { timeout = 8000, label } = {}) {
    const end = Date.now() + timeout; let last;
    while (Date.now() < end) {
      try { last = await this.eval(`!!(${expr})`); if (last) return true; } catch (e) { last = e.message; }
      await sleep(100);
    }
    throw new Error(`waitFor timed out: ${label || expr}`);
  }
  async waitSel(sel, o) { return this.waitFor(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)return false;const r=e.getBoundingClientRect();const s=getComputedStyle(e);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'})()`, { ...o, label: 'visible ' + sel }); }
  exists(sel) { return this.eval(`!!document.querySelector(${JSON.stringify(sel)})`); }
  visible(sel) { return this.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(!e)return false;const r=e.getBoundingClientRect();const s=getComputedStyle(e);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'})()`); }
  async centre(sel, timeout = 8000) {
    // Scroll into view, return the centre of the first VISIBLE match (selector may be a CSS list). Retries while it appears.
    const end = Date.now() + timeout;
    for (;;) {
      const r = await this.eval(`(()=>{const els=[...document.querySelectorAll(${JSON.stringify(sel)})];
        const e=els.find(x=>{const b=x.getBoundingClientRect();const s=getComputedStyle(x);return b.width>0&&b.height>0&&s.visibility!=='hidden'&&s.display!=='none'});
        if(!e)return null;e.scrollIntoView({block:'center',inline:'center',behavior:'instant'});const b=e.getBoundingClientRect();return {x:b.x+b.width/2,y:b.y+b.height/2}})()`);
      if (r) return r;
      if (Date.now() > end) throw new Error('not visible: ' + sel);
      await sleep(150);
    }
  }
  async click(sel) {
    const { x, y } = await this.centre(sel);
    await this.clickAt(x, y);
  }
  async clickAt(x, y) {
    await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
    await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
    await sleep(120);
  }
  async hover(sel) { const { x, y } = await this.centre(sel); await this.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y }); }
  // click the first visible element whose text matches (exact trimmed text or regex) inside optional scope selector
  async clickText(text, scope = 'body', tags = 'a,button,[role=button],label,li,.dropdown-item,span,div') {
    const found = await this.eval(`(()=>{const re=${text instanceof RegExp ? `new RegExp(${JSON.stringify(text.source)},${JSON.stringify(text.flags)})` : 'null'};const t=${JSON.stringify(text instanceof RegExp ? '' : text)};
      const root=document.querySelector(${JSON.stringify(scope)});if(!root)return false;
      const els=[...root.querySelectorAll(${JSON.stringify(tags)})].filter(e=>{const b=e.getBoundingClientRect();const s=getComputedStyle(e);if(!(b.width>0&&b.height>0&&s.visibility!=='hidden'))return false;
        const tx=(e.innerText||e.value||'').trim().replace(/\\s+/g,' ');return re?re.test(tx):tx===t});
      // prefer the deepest (smallest) match
      els.sort((a,b)=>a.getBoundingClientRect().width*a.getBoundingClientRect().height-b.getBoundingClientRect().width*b.getBoundingClientRect().height);
      const e=els[0];if(!e)return false;e.setAttribute('data-smoke-target','1');return true})()`);
    if (!found) throw new Error('no visible element with text ' + text + ' in ' + scope);
    try { await this.click('[data-smoke-target="1"]'); } finally { await this.eval(`document.querySelectorAll('[data-smoke-target]').forEach(e=>e.removeAttribute('data-smoke-target'))`); }
  }
  // click something that triggers a full page load (form submit, link) and wait for it
  async clickNav(sel, timeout = 90000) {
    // Poll for a new document (timeOrigin changes) that finished loading: more robust than the load event on a slow box.
    const origin = await this.eval('performance.timeOrigin');
    await this.click(sel);
    const end = Date.now() + timeout;
    for (;;) {
      await sleep(200);
      try {
        if (await this.eval(`performance.timeOrigin !== ${origin} && document.readyState !== 'loading'`)) break;
      } catch { /* execution context gone mid-navigation */ }
      if (Date.now() > end) throw new Error('page did not navigate after clicking ' + sel);
    }
    await sleep(300);
  }
  async focus(sel) { await this.eval(`document.querySelector(${JSON.stringify(sel)}).focus()`); }
  async type(sel, text, { clear = true } = {}) {
    await this.click(sel);
    if (clear) await this.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});if(e&&'value' in e){e.select&&e.select();}})()`);
    if (clear) await this.press('Backspace');
    await this.send('Input.insertText', { text });
    await sleep(80);
  }
  async press(key, { modifiers = 0 } = {}) {
    const map = { Tab: 9, Enter: 13, Escape: 27, Backspace: 8, ArrowDown: 40, ArrowUp: 38, ArrowLeft: 37, ArrowRight: 39, ' ': 32 };
    const code = key === ' ' ? 'Space' : key.length === 1 ? 'Key' + key.toUpperCase() : key;
    const base = { key, code, windowsVirtualKeyCode: map[key] || 0, modifiers };
    await this.send('Input.dispatchKeyEvent', { type: 'rawKeyDown', ...base, text: key === 'Enter' ? '\r' : undefined });
    if (key === 'Enter') await this.send('Input.dispatchKeyEvent', { type: 'char', ...base, text: '\r' });
    await this.send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
    await sleep(80);
  }
  async setViewport(width, height = 900, mobile = false) {
    await this.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
  }
  async clearViewport() { await this.send('Emulation.clearDeviceMetricsOverride'); }
  async screenshot(path) {
    const { data } = await this.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    const { writeFileSync } = await import('node:fs');
    writeFileSync(path, Buffer.from(data, 'base64'));
  }
  async clearCookies() { await this.send('Network.clearBrowserCookies'); }
  close() { try { this.ws.close(); } catch {} }
}
