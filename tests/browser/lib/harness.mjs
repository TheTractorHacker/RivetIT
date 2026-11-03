// Tiny check runner: records PASS/FAIL per check, folds in browser-side errors (uncaught exceptions, console.error,
// failed first-party requests, any 4xx/5xx the check did not expect), takes a screenshot on failure and prints a table.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export class Harness {
  constructor({ page, outDir, edition, base, known = [] }) {
    this.known = known; this.knownHits = new Map();
    this.page = page; this.outDir = outDir; this.edition = edition; this.base = new URL(base);
    this.results = []; this.n = 0;
    mkdirSync(outDir, { recursive: true });
  }
  firstParty(url) { try { return new URL(url).host === this.base.host; } catch { return false; } }

  // opts.allowHttp: array of RegExp for first-party URLs that may legitimately answer >= 400 inside this check.
  // opts.ignoreConsole: array of RegExp for console/log errors to tolerate.
  // opts.skipIf: string reason; records SKIP without running.
  async check(name, fn, opts = {}) {
    const p = this.page; const id = String(++this.n).padStart(2, '0');
    if (opts.skipIf) { this.results.push({ id, name, status: 'SKIP', detail: opts.skipIf, errors: [] }); console.log(`SKIP  ${id} ${name}  (${opts.skipIf})`); return; }
    p.events.length = 0;
    const t0 = Date.now(); let failure = null;
    try { await fn(); } catch (e) { failure = e.message || String(e); }
    await new Promise((r) => setTimeout(r, 150));
    const errs = [];
    for (const ev of p.events.splice(0)) {
      if (ev.type === 'http') {
        if (!this.firstParty(ev.url)) continue;
        if ((opts.allowHttp || []).some((re) => re.test(ev.url))) continue;
        errs.push(`HTTP ${ev.status} ${ev.method || ''} ${ev.url.replace(this.base.origin, '')}`);
      } else if (ev.type === 'netfail') {
        if (this.firstParty(ev.url)) errs.push(`request failed ${ev.url.replace(this.base.origin, '')} (${ev.text})`);
      } else if (ev.type === 'dialog') {
        // alert/confirm dialogs are auto-accepted; not an error by themselves
      } else {
        const t = `${ev.type}: ${String(ev.text).slice(0, 300)}`;
        if ((opts.ignoreConsole || []).some((re) => re.test(t))) continue;
        // third-party resources (fonts/CDNs blocked in a sandbox) surface as console errors without a first-party URL
        if (/Failed to load resource/.test(t) && !(ev.url || '').includes(this.base.host) && !t.includes(this.base.host)) continue;
        errs.push(t);
      }
    }
    // Documented, already-reported product bugs: counted and listed in the summary instead of failing every run.
    for (let i = errs.length - 1; i >= 0; i--) {
      const k = this.known.find((x) => x.re.test(errs[i]));
      if (k) { this.knownHits.set(k.note, (this.knownHits.get(k.note) || 0) + 1); errs.splice(i, 1); }
    }
    const status = failure || errs.length ? 'FAIL' : 'PASS';
    let shot = '';
    if (status === 'FAIL') {
      shot = join(this.outDir, `${this.edition}-${id}-${name.replace(/[^a-z0-9]+/gi, '_').slice(0, 50)}.png`);
      try { await p.screenshot(shot); } catch { shot = ''; }
      try { writeFileSync(shot.replace(/\.png$/, '.txt'), `url: ${await p.url().catch(() => '?')}\n\n${failure || ''}\n\n${errs.join('\n')}\n`); } catch {}
    }
    const detail = [failure, ...errs].filter(Boolean).join(' | ');
    this.results.push({ id, name, status, detail, errors: errs, shot, ms: Date.now() - t0 });
    console.log(`${status}  ${id} ${name}  (${Date.now() - t0} ms)${detail ? '\n        ' + detail.slice(0, 600) : ''}${shot ? '\n        screenshot: ' + shot : ''}`);
  }

  summary() {
    const c = (s) => this.results.filter((r) => r.status === s).length;
    const w = Math.max(...this.results.map((r) => r.name.length), 10);
    console.log('\n' + '='.repeat(w + 22));
    console.log(`Edition: ${this.edition}   ${this.base.origin}`);
    for (const r of this.results) console.log(`${r.status.padEnd(5)} ${r.id}  ${r.name}`);
    console.log('-'.repeat(w + 22));
    if (this.knownHits.size) {
      console.log('Known issues observed (tolerated, see editions.mjs):');
      for (const [n, c] of this.knownHits) console.log(`  x${c}  ${n}`);
    }
    console.log(`TOTAL ${this.results.length}   PASS ${c('PASS')}   FAIL ${c('FAIL')}   SKIP ${c('SKIP')}`);
    console.log('screenshots/log: ' + this.outDir);
    writeFileSync(join(this.outDir, `${this.edition}-results.json`), JSON.stringify(this.results, null, 2));
    return c('FAIL');
  }
}
