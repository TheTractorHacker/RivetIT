"""Asset CRUD + validation. usage: asset_crud.py <it|msp>"""
import sys, json, time, re
from common import *
ed = sys.argv[1]; r = {}; n = int(time.time()) % 100000; nm = f'QA-{ed.upper()}-ASSET-{n}'
p, b, ctx, pg, base = session(ed); errs = []
pg.on('pageerror', lambda e: errs.append(str(e)[:100]))
def openmodal():
    pg.goto(base + '/agent/assets.php', wait_until='domcontentloaded'); pg.get_by_text('New Asset', exact=True).first.click(); pg.wait_for_selector('.modal.show [name=name]')
def setv(name, v): pg.evaluate("([n,v])=>{const e=document.querySelector('.modal.show [name=\"'+n+'\"]');if(e.tomselect){e.tomselect.setValue(v)}else{e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}))}}", [name, v])
def pickfirst(name): pg.evaluate("n=>{const s=document.querySelector('.modal.show select[name=\"'+n+'\"]');const o=[...s.options].filter(o=>o.value&&o.value!='0');s.tomselect?s.tomselect.setValue(o[0].value):s.value=o[0].value}", name)
def save(): pg.locator('.modal.show button[name=add_asset]').click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(900)
openmodal(); save(); r['empty_blocked'] = pg.locator('.modal.show').count() == 1
openmodal(); pickfirst('client_id'); pickfirst('type'); setv('name', nm); setv('ip', '999.1.1.1'); setv('mac', 'ZZ:ZZ'); save()
r['bad_ip_mac'] = 'blocked' if pg.locator('.modal.show').count() else 'accepted'
if pg.locator('.modal.show').count() == 0: pass
else: setv('ip', '10.9.8.7'); setv('mac', '00:11:22:33:44:55'); save()
r['after_save_url'] = pg.url[len(base):]
pg.goto(base + '/agent/assets.php?q=' + nm, wait_until='domcontentloaded'); r['listed'] = nm in pg.inner_text('body')
link = pg.locator(f'a:has-text("{nm}")').first
if link.count():
    link.click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(800); r['details_url'] = pg.url[len(base):]; r['details_shows_name'] = nm in pg.inner_text('body'); r['details_shows_ip'] = bool(re.search(r'10\.9\.8\.7|999\.1\.1\.1', pg.inner_text('body')))
    a = pg.locator('a[href*="archive_asset"]').first
    r['archive_link'] = a.count()
    if a.count():
        a.evaluate('e=>e.click()'); pg.wait_for_timeout(800)
        c = pg.locator('.modal.show :text-is("Yes"), .modal.show .btn-danger')
        if c.count(): c.first.click()
        pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(600)
    pg.goto(base + '/agent/assets.php?q=' + nm, wait_until='domcontentloaded'); r['hidden_after_archive'] = nm not in pg.inner_text('body')
r['errs'] = errs[:4]; b.close(); p.stop(); print(json.dumps(r, indent=1))
