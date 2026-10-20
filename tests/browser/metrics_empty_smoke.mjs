// Browser smoke for the asset Metrics tab with UNEVEN data (RivetIT 26.10.63):
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... ASSET_ID=<id> node tests/browser/metrics_empty_smoke.mjs     (seeded by tests/browser/metrics_empty_seed.php)
//
// Dense CPU / memory / network charts draw; disk space, uptime and pending reboot have 2-4 samples and must show point markers plus an "N samples so far" note;
// memory totals exist only 10 days back (no samples in range -> last-sample text + 7-day link); GPU / battery / IOPS / per-core never reported (no canvas, one muted line).
// Same harness rules as rmm_eatabs_smoke.mjs: loopback only, any console error / failed first-party request fails the check.
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD, ASSET = process.env.ASSET_ID;
if (!BASE || !EMAIL || !PASSWORD || !ASSET) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD, ASSET_ID; optionally SMOKE_OUT, ONLY, HEADED=1.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}.`); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
mkdirSync(outDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const p = await browser.newPage();
await p.setViewport(1366, 1400);
const H = new Harness({ page: p, outDir, edition: 'rmm', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const assert = (c, m) => { if (!c) throw new Error(m); };

// per chart block: drawn?, markers on every real sample?, note, overlay
const probe = (id) => p.eval(`(()=>{const b=document.querySelector('[data-chart-id="${id}"]');if(!b)return null;
  const cv=b.querySelector('canvas');const ch=window.Chart&&Chart.getChart(cv);const note=b.querySelector('[data-role=note]');const ov=b.querySelector('[data-role=overlay]');
  let real=0,marked=0;if(ch){ch.data.datasets.forEach((d,di)=>{const m=ch.getDatasetMeta(di);d.data.forEach((v,i)=>{if(v!==null){real++;const el=m.data[i];if(el&&el.options&&el.options.radius>0)marked++;}})});}
  return {drawn:!!ch,datasets:ch?ch.data.datasets.length:0,real,marked,note:note&&!note.hidden?note.textContent:'',overlay:ov&&!ov.hidden?ov.textContent:'',visible:getComputedStyle(cv).visibility}})()`);
const ids = () => p.eval(`[...document.querySelectorAll('[data-metric-chart]')].map(b=>b.dataset.chartId)`);
const settle = async () => { await p.waitFor(`(()=>{const s=document.querySelector('#ifm-${ASSET} [data-role=status]');return s&&/updated/.test(s.textContent)})()`, { timeout: 15000, label: 'metrics loaded' }); await sleep(300); };

await T('login: real form signs in', async () => {
  await p.goto(BASE + '/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('open the asset Metrics tab: it loads without console errors', async () => {
  await p.goto('about:blank');
  await p.goto(`${BASE}/agent/asset_details.php?asset_id=${ASSET}`);
  await p.waitSel('a[href="#rdt-metrics"]');
  await p.click('a[href="#rdt-metrics"]');
  await settle();
});

await T('dense charts draw (CPU, memory %, network)', async () => {
  for (const id of ['cpu', 'mem', 'net']) {
    const r = await probe(id);
    assert(r && r.drawn && r.real >= 60, `${id}: ${JSON.stringify(r)}`);
    assert(r.note === '', `${id}: dense chart must not carry a sparse note: ${r.note}`);
  }
});

await T('sparse charts (disk space 4, uptime 2, reboot 2) show markers on every sample and an "N samples so far" note', async () => {
  const want = { 'disk-bytes': /^4 samples so far/, uptime: /^2 samples so far/, reboot: /^2 samples so far/ };
  for (const [id, re] of Object.entries(want)) {
    const r = await probe(id);
    assert(r && r.drawn && r.real > 0 && r.marked === r.real, `${id}: markers ${JSON.stringify(r)}`);
    assert(re.test(r.note), `${id}: note "${r.note}"`);
  }
});

await T('pending reboot renders as a stepped Yes/No strip', async () => {
  const r = await p.eval(`(()=>{const ch=Chart.getChart(document.querySelector('[data-chart-id=reboot] canvas'));const d=ch.data.datasets[0];return {stepped:d.stepped,min:ch.scales.y.min,max:ch.scales.y.max,vals:d.data.filter(v=>v!==null)}})()`);
  assert(r.stepped === 'after' && r.min === 0 && r.max === 1 && r.vals.join() === '0,1', JSON.stringify(r));
});

await T('memory totals only 10 days back: no samples in range, last sample time, one-click 30-day link that draws it', async () => {
  let r = await probe('mem-bytes');
  assert(r && !r.drawn && /No samples in this range; last sample .+\d/.test(r.overlay), JSON.stringify(r));
  assert(await p.exists('[data-chart-id="mem-bytes"] [data-role=range-link][data-range-target="30d"]'), 'no 30d link');
  assert(!(await p.exists('[data-chart-id="mem-bytes"] [data-role=range-link][data-range-target="7d"]')), '7d link offered although the sample is 10 days old');
  await p.click('[data-chart-id="mem-bytes"] [data-role=range-link]');
  await settle();
  r = await probe('mem-bytes');
  assert(r.drawn && r.marked === r.real && r.real === 1 && /^1 sample so far: 15 GB at /.test(r.note), JSON.stringify(r));
  await p.click('[data-role=range][data-range="24h"]');
  await settle();
});

await T('never-reported groups (GPU, battery, IOPS, per-core, errors) have no canvas and are summarised in one muted line', async () => {
  const have = await ids();
  for (const id of ['gpu', 'gpu-mem', 'gpu-temp', 'battery', 'disk-iops', 'disk-queue', 'disk-lat', 'cpu-cores', 'net-err']) assert(!have.includes(id), `${id} rendered an empty canvas`);
  const line = await p.eval(`(document.querySelector('[data-role=not-reported]')||{}).textContent||''`);
  assert(/^Not reported by this device:/.test(line) && /GPU/.test(line) && /Battery/.test(line) && /Disk IOPS/.test(line), 'line: ' + line);
});

await T('every other range (1h, 6h, 7d, 30d, 90d) loads without errors and the sparse markers survive', async () => {
  for (const r of ['1h', '6h', '7d', '30d', '90d']) {
    await p.click(`[data-role=range][data-range="${r}"]`);
    await settle();
    const e = await p.eval(`(document.querySelector('[data-role=error]')||{}).hidden`);
    assert(e !== false, `${r}: error banner visible`);
    const c = await probe('cpu');
    assert(c && (c.drawn || /No samples/.test(c.overlay)), `${r}: cpu ${JSON.stringify(c)}`);
  }
});

await T('tz helper never throws or prints NaN for a missing, bad or valid zone', async () => {
  const r = await p.eval(`(()=>{const pane=document.querySelector('[data-asset-metrics]');const orig=pane.getAttribute('data-company-tz');const out=[];
    for(const z of [null,'','Not/AZone','America/Chicago','UTC']){ if(z===null)pane.removeAttribute('data-company-tz');else pane.setAttribute('data-company-tz',z);
      pane.querySelector('[data-role=refresh]').click(); out.push(z); }
    if(orig!==null)pane.setAttribute('data-company-tz',orig);return out.length})()`);
  await settle();
  const bad = await p.eval(`[...document.querySelectorAll('.ifm-chart-note,.ifm-chart-overlay,.ifm-wrap')].some(e=>/NaN|undefined|Invalid/.test(e.textContent))`);
  assert(r === 5 && !bad, 'NaN/undefined text on the page');
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
