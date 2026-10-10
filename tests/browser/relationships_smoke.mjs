// Browser smoke for the Relationships card (RivetIT): link two records through the real "Link item" pop-up, see the card update, check "Referenced by"
// and "Impact", unlink, and check the card at phone width and in dark mode.
//
//   php tests/browser/relationships_seed.php /tmp/rel-seed.json      # on the throwaway install (see that file's header)
//   BASE_URL=http://127.0.0.1:8650 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... SEED_JSON=/tmp/rel-seed.json \
//     SMOKE_OUT=/tmp/rel-shots node tests/browser/relationships_smoke.mjs
//
// Same driver, harness and safety rules as rmm_smoke.mjs (loopback only, zero npm dependencies, README.md has the install recipe).
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { Browser, sleep } from './lib/cdp.mjs';
import { Harness } from './lib/harness.mjs';

const BASE = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EMAIL = process.env.ADMIN_EMAIL, PASSWORD = process.env.ADMIN_PASSWORD;
if (!BASE || !EMAIL || !PASSWORD || !process.env.SEED_JSON) {
  console.error('Set BASE_URL, ADMIN_EMAIL, ADMIN_PASSWORD and SEED_JSON (the file written by tests/browser/relationships_seed.php); optionally SMOKE_OUT, ONLY, HEADED=1.');
  process.exit(2);
}
if (!/^https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?$/.test(BASE) && process.env.ALLOW_NON_LOCAL !== '1') {
  console.error(`Refusing to run against ${BASE}: the smoke creates and removes links; it is meant for a throwaway install.`);
  process.exit(2);
}
const SEED = JSON.parse(readFileSync(process.env.SEED_JSON, 'utf8'));
const outDir = resolve(process.env.SMOKE_OUT || 'tests/browser/out');
const shotDir = join(outDir, 'shots');
mkdirSync(shotDir, { recursive: true });

const browser = await Browser.launch({ headless: process.env.HEADED !== '1' });
const page = await browser.newPage();
await page.setViewport(1366, 900);
const H = new Harness({ page, outDir, edition: 'relationships', base: BASE });
const only = process.env.ONLY ? new RegExp(process.env.ONLY, 'i') : null;
const T = (name, fn, o = {}) => (only && !only.test(name) ? Promise.resolve() : H.check(name, fn, o));
const p = page;
const go = async (path) => { if (path.includes('#')) await p.goto('about:blank'); await p.goto(BASE + path); };
const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
const asset = (id) => `/agent/asset_details.php?client_id=1&asset_id=${id}`;
const cardText = () => p.eval(`document.getElementById('relationships-card').innerText`);

async function shot(name) {
  const m = await p.send('Page.getLayoutMetrics');
  const w = Math.ceil(m.cssContentSize.width), h = Math.min(Math.ceil(m.cssContentSize.height), 9000);
  const { data } = await p.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: w, height: h, scale: 1 } });
  writeFileSync(join(shotDir, name + '.png'), Buffer.from(data, 'base64'));
}
const hOverflow = () => p.eval(`(()=>{const d=document.documentElement;const vw=d.clientWidth;const c=document.getElementById('relationships-card');const over=[];
  if(c){for(const e of c.querySelectorAll('*')){const r=e.getBoundingClientRect();if(r.width>0&&r.right>vw+1){let clipped=false;for(let n=e.parentElement;n&&n!==c;n=n.parentElement){const o=getComputedStyle(n).overflowX;if(o==='auto'||o==='scroll'||o==='hidden'){clipped=true;break}}if(!clipped)over.push(e.tagName+'.'+String(e.className).slice(0,40)+' right='+Math.round(r.right))}if(over.length>5)break}}
  return {sw:d.scrollWidth,vw,over}})()`);
const setTheme = async (value) => {
  await go('/agent/user/user_preferences.php');
  await p.eval(`(()=>{const rs=[...document.querySelectorAll('input[name=dark_mode]')];const r=rs.find(x=>(x.getAttribute('value')||'0')==='${value}');r.closest('label').click();})()`);
  await p.clickNav('button[name=edit_your_user_preferences]');
};

await T('login: real form signs in', async () => {
  await go('/login.php');
  await p.type('input[name=email]', EMAIL);
  await p.type('input[name=password]', PASSWORD);
  await p.clickNav('button[name=login], button[type=submit]');
  assert(!/login\.php/.test(await p.url()), 'still on the login page');
}, { allowHttp: [/\/agent\/clients\.php/] });

await T('asset page: the Relationships card lists links, referenced by and impact', async () => {
  await go(asset(SEED.asset.core));
  await p.waitSel('#relationships-card');
  const t = await cardText();
  assert(/relationships/i.test(t) && /Core server runbook/.test(t), 'outgoing link to the document missing: ' + t.slice(0, 300));
  assert(/referenced by/i.test(t) && /Payroll/.test(t) && /srv-app/.test(t), 'referenced-by rows missing');
  assert(/impact: what depends on this/i.test(t), 'impact heading missing');
  assert((await p.eval(`document.querySelectorAll('#relationships-card [data-rel-impact] li').length`)) >= 2, 'impact list has fewer than two entries (service and second server)');
  assert(await p.visible('#relationships-card [data-rel-open-link]'), 'Link item button not visible');
  await shot('asset-card-desktop-light');
});

await T('Link item: pick a record through the pop-up and the new link shows on the card', async () => {
  await go(asset(SEED.asset.core));
  await p.waitSel('#relationships-card [data-rel-open-link]');
  await p.click('#relationships-card [data-rel-open-link]');
  await p.waitSel('.modal.show form[data-rel-form]');
  await p.eval(`(()=>{const s=document.querySelector('.modal.show [data-rel-type-select]');s.value='asset';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.waitFor(`[...document.querySelectorAll('.modal.show [data-rel-results] option')].some(o=>/ws-front-desk/.test(o.textContent))`, { label: 'the search list shows ws-front-desk', timeout: 15000 });
  await p.type('.modal.show [data-rel-search]', 'front');
  await p.waitFor(`(()=>{const o=[...document.querySelectorAll('.modal.show [data-rel-results] option')];return o.length===1&&/ws-front-desk/.test(o[0].textContent)})()`, { label: 'search narrowed to one record' });
  await p.eval(`(()=>{const r=document.querySelector('.modal.show [data-rel-results]');r.selectedIndex=0;r.dispatchEvent(new Event('change',{bubbles:true}));const l=document.querySelector('.modal.show [data-rel-link-type-select]');l.value='runs_on'})()`);
  await p.type('.modal.show input[name=note]', 'racked in the front office');
  await shot('link-item-modal');
  await p.clickNav('.modal.show button[name=link_entities]');
  await p.waitSel('#relationships-card');
  const t = await cardText();
  assert(/ws-front-desk/.test(t) && /racked in the front office/.test(t) && /runs on/i.test(t), 'the new link is not on the card: ' + t.slice(0, 400));
  assert(/Linked\./.test(await p.eval('document.body.innerText')), 'no success message');
});

await T('the other record shows the link under "Referenced by", and impact follows it', async () => {
  await go(asset(SEED.asset.desk));
  await p.waitSel('#relationships-card');
  const t = await cardText();
  assert(/referenced by/i.test(t) && /srv-core/.test(t), 'srv-core is not listed as referring to the workstation');
  await go(asset(SEED.asset.core));
  await p.waitSel('#relationships-card');
  // ws-front-desk runs_on srv-core means srv-core is the one that is depended upon: it must not list itself, and the workstation is NOT in its impact
  const impact = await p.eval(`document.querySelector('#relationships-card [data-rel-impact]').innerText`);
  assert(!/srv-core/.test(impact), 'the record lists itself in its own impact');
});

await T('Unlink removes the link; read-only derived rows have no unlink button', async () => {
  await go(asset(SEED.asset.core));
  await p.waitSel('#relationships-card');
  const before = await p.eval(`document.querySelectorAll('#relationships-card [data-rel-table=outgoing] tr[data-rel-row=link]').length`);
  assert(before >= 2, 'expected the document and the workstation links');
  assert(!(await p.eval(`!!document.querySelector('#relationships-card tr[data-rel-row=derived] [data-rel-unlink]')`)), 'a derived row has an unlink button');
  await p.eval(`(()=>{const rows=[...document.querySelectorAll('#relationships-card [data-rel-table=outgoing] tr[data-rel-row=link]')];const r=rows.find(x=>/ws-front-desk/.test(x.innerText));r.querySelector('[data-rel-unlink]').setAttribute('data-smoke-target','1')})()`);
  await p.clickNav('[data-smoke-target="1"]');
  await p.waitSel('#relationships-card');
  assert(!/ws-front-desk/.test(await cardText()), 'the link is still on the card after unlinking');
});

await T('KB article <-> asset: link a (global) KB article to an asset from the article page, see it from the asset page, unlink', async () => {
  await go(`/agent/kb_article.php?id=${SEED.kb}`);
  await p.waitSel('#relationships-card [data-rel-open-link]');
  await p.click('#relationships-card [data-rel-open-link]');
  await p.waitSel('.modal.show form[data-rel-form]');
  await p.eval(`(()=>{const s=document.querySelector('.modal.show [data-rel-type-select]');s.value='asset';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
  await p.type('.modal.show [data-rel-search]', 'srv-core');
  await p.waitFor(`(()=>{const o=[...document.querySelectorAll('.modal.show [data-rel-results] option')];return o.length===1&&/srv-core/.test(o[0].textContent)&&/Dept A/.test(o[0].textContent)})()`, { label: 'the asset is offered with its department name', timeout: 15000 });
  await p.eval(`(()=>{const r=document.querySelector('.modal.show [data-rel-results]');r.selectedIndex=0;const l=document.querySelector('.modal.show [data-rel-link-type-select]');l.value='documented_by'})()`);
  await p.clickNav('.modal.show button[name=link_entities]');
  await p.waitSel('#relationships-card');
  assert(/srv-core/.test(await cardText()), 'the KB article card does not show the asset');
  await go(asset(SEED.asset.core));
  await p.waitSel('#relationships-card');
  assert(/Restart the core server/.test(await cardText()), 'the asset card does not show the KB article that documents it');
  await shot('asset-card-kb-link');
  await p.eval(`(()=>{const rows=[...document.querySelectorAll('#relationships-card [data-rel-table=incoming] tr[data-rel-row=link]')];const r=rows.find(x=>/Restart the core server/.test(x.innerText));r.querySelector('[data-rel-unlink]').setAttribute('data-smoke-target','1')})()`);
  await p.clickNav('[data-smoke-target="1"]');
  await p.waitSel('#relationships-card');
  assert(!/Restart the core server/.test(await cardText()), 'the KB link is still there after unlinking');
});

await T('document and service-less details: card on the document page shows the asset that points at it', async () => {
  await go(`/agent/document_details.php?client_id=1&document_id=${SEED.document}`);
  await p.waitSel('#relationships-card');
  assert(/srv-core/.test(await cardText()), 'the document page does not show the asset that is documented by it');
});

await T('phone width: the card does not scroll sideways', async () => {
  await p.setViewport(390, 800, true);
  await go(asset(SEED.asset.core));
  await p.waitSel('#relationships-card');
  await sleep(300);
  const o = await hOverflow();
  assert(o.over.length === 0, 'card overflows at 390px: ' + o.over.join(', '));
  await shot('asset-card-phone-light');
  await p.setViewport(1366, 900);
});

await T('dark mode: the card is readable', async () => {
  await setTheme(1);
  await go(asset(SEED.asset.core));
  await p.waitSel('#relationships-card');
  assert((await p.eval(`document.documentElement.getAttribute('data-bs-theme')`)) === 'dark', 'not dark');
  const ink = await p.eval(`getComputedStyle(document.querySelector('#relationships-card .card-body')).color`);
  const bg = await p.eval(`getComputedStyle(document.querySelector('#relationships-card .card-body')).backgroundColor`);
  const sum = (c) => c.match(/\d+/g).slice(0, 3).map(Number).reduce((a, b) => a + b, 0);
  assert(sum(ink) > sum(bg), `text is not lighter than its background in dark mode: ${ink} on ${bg}`);
  await shot('asset-card-desktop-dark');
  await setTheme(0);
});

const fails = H.summary();
await browser.close();
process.exit(fails ? 1 : 0);
