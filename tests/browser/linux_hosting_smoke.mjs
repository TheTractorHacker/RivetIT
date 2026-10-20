// Browser smoke for the hosted Linux files (RivetIT 26.10.64): the Add device page says "Build or publish the agent first" while nothing is hosted, then gives the self-contained
// command once files are hosted; the Agent binaries card lists them. Loopback only, scratch install only (same harness as rmm_agentfeed_smoke.mjs).
//   BASE_URL=http://127.0.0.1:<port> ADMIN_EMAIL=... ADMIN_PASSWORD=... SEED_DIR=<dir with install-linux.sh + rivetit-agent-linux-amd64> RIVETIT_TEST_DB* (scratch) node tests/browser/linux_hosting_smoke.mjs
import { mkdirSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD, SEED = process.env.SEED_DIR;
if (!BASE || !EMAIL || !PASSWORD || !SEED) { console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD, SEED_DIR.'); process.exit(2); }
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE)) { console.error('Refusing a non-loopback address.'); process.exit(2); }
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out'); mkdirSync(join(outDir, 'shots'), { recursive: true });
const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'rmm', base: BASE });
const T = (n, f, o = {}) => H.check(n, f, o);
const p = page; const assert = (c, m) => { if (!c) throw new Error(m); };
const sql = (q) => execFileSync('mysql', ['-u', process.env.RIVETIT_TEST_DB_USER, '-p' + process.env.RIVETIT_TEST_DB_PASS, process.env.RIVETIT_TEST_DB_NAME, '-Nse', q]).toString();
sql('DELETE FROM endpoint_agent_linux_files');

await T('login', async () => {
  await p.clearCookies(); await p.goto(BASE + '/login.php');
  await p.type('input[name=email]', EMAIL); await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('nothing hosted: the Linux tab says what to do and offers no command', async () => {
  await p.goto(BASE + '/agent/rmm_add_device.php');
  await p.click('label[for=ad-p-lx64]');
  await p.waitFor(`!document.getElementById('ad-linux').classList.contains('d-none')`, { label: 'linux section' });
  const s = await p.eval(`(()=>({miss:!document.getElementById('ad-l-missing').classList.contains('d-none'),txt:document.getElementById('ad-l-missing').innerText,dis:document.getElementById('ad-l-go').disabled}))()`);
  assert(s.miss && s.dis && /Build or publish the agent first/.test(s.txt), JSON.stringify(s));
  await p.screenshot(join(outDir, 'shots', 'linux-hosting-missing.png'));
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('after hosting the files the command is self-contained and the table lists them', async () => {
  execFileSync('php', ['tests/support/linux_command_issue.php', SEED, '/tmp/lx_smoke_cmd.sh', '0.9.9', BASE], { stdio: 'ignore' });
  await p.goto(BASE + '/agent/rmm_add_device.php');
  await p.click('label[for=ad-p-lx64]');
  await p.waitFor(`!document.getElementById('ad-l-go').disabled`, { label: 'button enabled' });
  await p.eval(`(()=>{const s=document.getElementById('ad-client');if(s&&s.options&&!s.value){const o=[...s.options].find(x=>x.value&&!x.disabled);if(o){s.value=o.value;s.dispatchEvent(new Event('change',{bubbles:true}));}}})()`);
  await p.click('#ad-l-go');
  await p.waitFor(`!document.getElementById('ad-l-out').classList.contains('d-none')`, { timeout: 20000, label: 'command' });
  const c = await p.eval(`document.getElementById('ad-l-cmd').value`);
  assert(/agent_linux_file\?name=install-linux\.sh/.test(c) && /sha256sum -c/.test(c) && !/unpacked|github\.com/.test(c) && /rvte1\./.test(c), c.slice(0, 300));
  const t = await p.eval(`document.getElementById('ad-linux').innerText`);
  assert(/Hosted on this server/.test(t) && /install-linux\.sh/.test(t), 'hosted table missing');
  await p.screenshot(join(outDir, 'shots', 'linux-hosting-command.png'));
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('390 px: no horizontal scroll', async () => {
  await p.setViewport(390, 800); await p.goto(BASE + '/agent/rmm_add_device.php'); await p.click('label[for=ad-p-lx64]'); await sleep(300);
  assert(await p.eval(`document.documentElement.scrollWidth <= window.innerWidth + 1`), 'horizontal scroll');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('Agent binaries card lists the hosted files with the upload form', async () => {
  await p.setViewport(1366, 900); await p.goto(BASE + '/admin/settings_endpoint_agent.php');
  const t = await p.eval(`(()=>{const e=document.getElementById('linux-files');return e?e.parentElement.innerText:''})()`);
  assert(/Linux files hosted on this server/.test(t) && /rivetit-agent-linux-amd64/.test(t) && await p.exists('input[name="linux_files[]"]'), t.slice(0, 200));
}, { allowHttp: [/\/agent\/clients\.php/] });

sql('DELETE FROM endpoint_agent_linux_files');
const fails = H.summary(); await browser.close(); process.exit(fails ? 1 : 0);
