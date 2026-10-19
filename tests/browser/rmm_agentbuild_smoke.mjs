// Browser smoke for Agent updates > Build a new version (RivetIT 26.10.55): token help and save, Test token, Build with publish-when-built, live progress, the release appearing with its Publish button.
//
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=<file written by tests/browser/rmm_seed.php> MOCK_GH_DIR=<dir> node tests/browser/rmm_agentbuild_smoke.mjs
//
// Same setup and safety rules as rmm_agentfeed_smoke.mjs (loopback only, throwaway install, mock GitHub tests/support/mock_github.php, RIVET_AGENT_FEED_TEST_ORIGIN on the web server). Drives the
// real page through Chromium's DevTools protocol; every check also fails on uncaught errors, console.error and failed first-party requests.
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
const MOCK = process.env.MOCK_GH_DIR;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON || !MOCK) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD, SEED_JSON and MOCK_GH_DIR.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') { console.error(`Refusing to run against ${BASE}.`); process.exit(2); }
const TOKEN = 'github_pat_11SMOKETEST0abcdefghij_ZYXWVUTSRQPONMLKJIHGFEDCBA9876543210zyxwvutsrqponmlkjihgfed';
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm', base: BASE });
const T = (name, fn, o = {}) => H.check(name, fn, o);
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (c, m) => { if (!c) throw new Error(m); };
async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: Math.ceil(m.cssContentSize.width), height: Math.min(Math.ceil(m.cssContentSize.height), 9000), scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const extra = (mode, ...more) => JSON.parse(execFileSync('php', ['-d', 'display_errors=0', 'tests/browser/rmm_agentbuild_extra.php', process.env.SEED_JSON, mode, ...more], { cwd: process.cwd(), env: process.env, encoding: 'utf8' }).trim().split('\n').filter((l) => l.startsWith('{')).pop());
const trapDialogs = (answer = true) => p.eval(`window.__dialogs = []; window.confirm = function (t) { window.__dialogs.push(String(t)); return ${answer}; }; true`);
const buildText = () => p.eval(`document.getElementById('af-build').innerText`);
const alertText = () => p.eval(`(document.getElementById('af-alert')||{}).innerText||''`);
const setVal = (sel, v) => p.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return true})()`);
const unlabeled = () => p.eval(`[...document.querySelectorAll('#af-build input, #af-build select')].filter(e=>{if(e.type==='hidden'||e.disabled)return false;const r=e.getBoundingClientRect();if(!(r.width>0&&r.height>0))return false;return !((e.labels&&e.labels.length)||e.getAttribute('aria-label'))}).map(e=>e.name||e.id)`);
const login = async () => { await p.clearCookies(); await go('/login.php'); await p.type('input[name=email]', EMAIL); await p.type('input[name=password]', PASSWORD); await p.clickNav('button[name=login], button[type=submit]'); assert(!/login\.php/.test(await p.url()), 'still on the login page'); };

await T('login: real form signs in', login, { allowHttp: [/\/agent\/clients\.php/] });
await T('setup: mock GitHub with agent-v0.9.1 and 0.9.0, no token, no build', async () => { assert(extra('fixtures', MOCK, TOKEN).ok, 'fixtures'); });

await T('Build a new version without a token: fallback button, 3 plain steps, guided token help', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#af-build');
  await p.click('#af-check');
  await p.waitSel('#af-table tr[data-tag="agent-v0.9.1"]');
  const href = await p.eval(`document.getElementById('af-build-fallback').href`);
  assert(href === 'https://github.com/TheTractorHacker/rivet-core/actions/workflows/endpoint-agent.yml', 'fallback link ' + href);
  const t = await buildText();
  assert(/Run workflow/.test(t) && /type the version/i.test(t) && /Check for updates/.test(t) && /0\.9\.2/.test(t) && !(await p.eval(`!!document.getElementById('af-build-go')`)), 'fallback text: ' + t.slice(0, 500));
  assert(await p.eval(`document.getElementById('af-build-fallback').rel.includes('noopener') && document.getElementById('af-build-fallback').target === '_blank'`), 'link rel/target');
  await p.eval(`document.getElementById('af-token').open = true`);
  const t2 = await buildText();
  assert(/Fine-grained tokens/.test(t2) && /Only select repositories/.test(t2) && /Actions\s+to\s+Read and write/.test(t2), 'token help: ' + t2.slice(-700));
  assert((await unlabeled()).length === 0, 'unlabeled: ' + (await unlabeled()));
  await shot('agent-build-fallback-desktop-light');
});

await T('Save token: password field, saved encrypted, never shown back, Build form appears with the next version', async () => {
  await p.eval(`document.getElementById('af-token').open = true`);
  assert((await p.eval(`document.getElementById('af-token-in').type`)) === 'password', 'field type');
  await p.type('#af-token-in', TOKEN);
  await p.click('#af-token-save');
  await p.waitSel('#af-build-go');
  const st = extra('state', MOCK);
  assert(st.token_stored && st.token_prefix === 'v3:' && st.token_len > 80, 'stored form ' + st.token_prefix);
  const html = await p.eval('document.documentElement.outerHTML');
  assert(!html.includes(TOKEN) && !html.includes(TOKEN.slice(12, 32)), 'the token is in the page');
  assert((await p.eval(`document.getElementById('af-build-version').value`)) === '0.9.2', 'prefilled version');
  assert((await p.eval(`document.getElementById('af-token-in').value`)) === '', 'token field not empty');
  assert(st.token_in_logs === 0 && Number(st.audit['Agent Build Token Saved']) === 1, 'audit ' + JSON.stringify(st.audit));
  await shot('agent-build-token-saved-desktop-light');
});

await T('Test token: says it works', async () => {
  await p.eval(`document.getElementById('af-token').open = true`);
  await p.click('#af-token-test');
  await p.waitFor(`/The token works/.test(document.getElementById('af-alert').innerText)`, { label: 'test token result' });
});

await T('Build: a version that exists or is lower is refused with a plain message; nothing is sent', async () => {
  for (const v of ['0.9.1', '0.8.0', 'banana']) {
    await setVal('#af-build-version', v);
    await p.eval(`document.getElementById('af-build-version').removeAttribute('pattern')`);   // let the SERVER judge it
    await p.click('#af-build-go');
    await p.waitFor(`/already exists|lower than|must look like/.test(document.getElementById('af-alert').innerText)`, { label: 'refusal for ' + v });
    await sleep(200);
  }
  assert(extra('state', MOCK).calls.filter((c) => c.p.endsWith('/dispatches')).length === 0, 'a dispatch was sent');
}, { allowHttp: [/post\.php/] });

await T('Build 0.9.2 with publish-when-built (pilot, 30%): GitHub is asked once; the box shows the waiting state and locks the button', async () => {
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#af-build-go');
  await p.eval(`document.querySelector('#af-build details').open = true`);
  await p.click('#af-build-auto-cb');
  await p.eval(`(()=>{const r=document.getElementById('af-build-ring');r.value='pilot';r.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await setVal('#af-build-pct', '30');
  await p.click('#af-build-go');
  await p.waitFor(`/GitHub accepted the request/.test(document.getElementById('af-alert').innerText)`, { label: 'accepted' });
  await p.waitFor(`!!document.getElementById('af-build-badge') && document.getElementById('af-build-go').disabled`, { label: 'waiting state' });
  const st = extra('state', MOCK);
  const d = st.calls.filter((c) => c.p.endsWith('/dispatches'));
  assert(d.length === 1 && d[0].m === 'POST' && d[0].auth === 'bearer' && JSON.parse(d[0].body).inputs.version === '0.9.2' && JSON.parse(d[0].body).ref === 'main', 'dispatch ' + JSON.stringify(d));
  assert(st.build.run_state === 'queued' && st.build.auto_state === 'pending' && JSON.parse(st.build.auto_opts).ring === 'pilot' && JSON.parse(st.build.auto_opts).pct === 30, 'row ' + JSON.stringify(st.build));
  assert(/Waiting in GitHub/.test(await buildText()), 'text ' + await buildText());
  await shot('agent-build-queued-desktop-light');
});

await T('live progress: the open page follows queued, building (with the run link) and finished without a reload', async () => {
  extra('runs', MOCK, TOKEN, 'in_progress', '-');
  await p.waitFor(`/Building/.test((document.getElementById('af-build-badge')||{}).innerText||'')`, { timeout: 40000, label: 'building' });
  const link = await p.eval(`document.getElementById('af-build-link').href`);
  assert(link === 'https://github.com/TheTractorHacker/rivet-core/actions/runs/4711', 'run link ' + link);
  await shot('agent-build-building-desktop-light');
  extra('runs', MOCK, TOKEN, 'completed', 'success');
  extra('appear', MOCK);
  await p.waitFor(`/Built/.test((document.getElementById('af-build-badge')||{}).innerText||'') && !!document.querySelector('#af-table tr[data-tag="agent-v0.9.2"]')`, { timeout: 60000, label: 'finished and listed' });
  await p.waitFor(`/Publish automatically: done/.test(document.getElementById('af-build').innerText)`, { timeout: 90000, label: 'published automatically' });
  const st = extra('state', MOCK);
  assert(st.binaries.some((b) => b.version === '0.9.2' && b.arch === 'amd64') && st.releases.some((r) => r.version === '0.9.2' && r.ring === 'pilot' && Number(r.rollout_pct) === 30), 'published: ' + JSON.stringify([st.binaries, st.releases]));
  assert(/Published/.test(await p.eval(`document.querySelector('#af-table tr[data-tag="agent-v0.9.2"]').innerText`)), 'row not Published');
  assert(Number(st.audit['Agent Build Started']) === 1 && Number(st.audit['Agent Build Auto Publish']) === 1 && st.token_in_logs === 0, 'audit ' + JSON.stringify(st.audit));
  await shot('agent-build-finished-desktop-light');
});

await T('a failed run is shown as failed with the run link; the Build button works again', async () => {
  extra('fixtures', MOCK, TOKEN);
  // keep the token: fixtures cleared it, save it again through the page
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#af-token');
  await p.eval(`document.getElementById('af-token').open = true`);
  await p.type('#af-token-in', TOKEN);
  await p.click('#af-token-save');
  await p.waitSel('#af-build-go');
  await p.click('#af-check'); await p.waitSel('#af-table tr[data-tag="agent-v0.9.1"]');
  await p.click('#af-build-go');
  await p.waitFor(`/GitHub accepted/.test(document.getElementById('af-alert').innerText)`, { label: 'accepted' });
  extra('runs', MOCK, TOKEN, 'completed', 'failure');
  await p.waitFor(`/Failed/.test((document.getElementById('af-build-badge')||{}).innerText||'') && !document.getElementById('af-build-go').disabled`, { timeout: 40000, label: 'failed shown' });
  assert(/did not succeed/.test(await buildText()), await buildText());
  await shot('agent-build-failed-desktop-light');
});

await T('Remove token asks first and removes it; the fallback returns', async () => {
  await trapDialogs(false);
  await p.eval(`document.getElementById('af-token').open = true`);
  await p.click('#af-token-clear');
  await sleep(500);
  assert(extra('state', MOCK).token_stored, 'removed after Cancel');
  await trapDialogs(true);
  await p.click('#af-token-clear');
  await p.waitSel('#af-build-fallback');
  assert(!extra('state', MOCK).token_stored, 'still stored');
});

await T('phone width: no horizontal scroll in the build box', async () => {
  await p.setViewport(390, 800);
  await go('/admin/settings_endpoint_agent.php');
  await p.waitSel('#af-build');
  const o = await p.eval(`(()=>{const d=document.documentElement;return {sw:d.scrollWidth,vw:d.clientWidth}})()`);
  assert(o.sw <= o.vw + 1, 'horizontal scroll ' + JSON.stringify(o));
  await shot('agent-build-fallback-mobile-light');
  await p.setViewport(1366, 900);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
