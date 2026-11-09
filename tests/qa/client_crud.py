"""Client/department CRUD + validation. usage: client_crud.py <it|msp>"""
import sys, json, time, re
from common import *
ed = sys.argv[1]; r = {}; n = int(time.time()) % 100000
nm = f'QA-{ed.upper()}-CLIENT-{n} Ünï 日本 &amp; "Q"'
p, b, ctx, pg, base = session(ed); errs = []
pg.on('pageerror', lambda e: errs.append(str(e)[:100]))
def open_add():
    pg.goto(base + '/agent/clients.php', wait_until='domcontentloaded'); pg.get_by_text('New Department' if ed == 'it' else 'New Client', exact=True).first.click(); pg.wait_for_selector('.modal.show [name=name]')
def submit(): pg.locator('.modal.show button[name=add_client]').click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(800)
m = '.modal.show '
def setv(name, v): pg.evaluate("([n,v])=>{const e=document.querySelector('.modal.show [name="+'"'+"'+n+'"+'"'+"]');e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}))}", [name, v])
open_add(); submit(); r['empty_blocked'] = pg.locator('.modal.show').count() == 1 and '/client_overview' not in pg.url
open_add(); setv('name', '   '); setv('contact', 'QA Contact'); submit()
r['whitespace_name'] = 'blocked' if pg.locator('.modal.show').count() else ('created ' + pg.url[len(base):])
open_add(); setv('name', nm); setv('contact', 'QA Contact'); setv('contact_email', 'not-an-email'); submit()
r['invalid_email'] = 'blocked' if pg.locator('.modal.show').count() else 'accepted -> ' + pg.url[len(base):]
if pg.locator('.modal.show').count(): setv('contact_email', f'qa{n}@qa.example'); submit()
r['after_create'] = pg.url[len(base):]; cid = re.search(r'client_id=(\d+)', pg.url); cid = cid.group(1) if cid else None; r['client_id'] = cid
body = pg.inner_text('body'); r['name_shown'] = f'QA-{ed.upper()}-CLIENT-{n}' in body; r['amp_double_escaped'] = '&amp;' in body
pg.goto(base + '/agent/clients.php?q=' + f'QA-{ed.upper()}-CLIENT-{n}', wait_until='domcontentloaded'); r['search_finds'] = f'QA-{ed.upper()}-CLIENT-{n}' in pg.inner_text('body')
pg.goto(base + '/agent/clients.php?q=qa-' + ed + '-client-' + str(n), wait_until='domcontentloaded'); r['search_case_insensitive'] = f'QA-{ed.upper()}-CLIENT-{n}' in pg.inner_text('body')
pg.goto(base + '/agent/clients.php?q=%25%27%22%3Cscript%3E', wait_until='domcontentloaded'); r['search_special_chars_ok'] = 'Fatal' not in pg.inner_text('body') and 'mysqli' not in pg.inner_text('body')
if cid:
    # oversized name
    pg.goto(base + '/agent/clients.php', wait_until='domcontentloaded'); open_add(); setv('name', 'QA-BIG-' + 'X' * 400); setv('contact', 'C'); submit()
    r['oversized_name'] = ('blocked/modal' if pg.locator('.modal.show').count() else 'created ' + pg.url[len(base):]) + ' | ' + ' '.join(pg.locator('.alert').all_inner_texts())[:80]
    big = re.search(r'client_id=(\d+)', pg.url); r['big_id'] = big.group(1) if big else None
    # archive both via the GET link on the overview page (csrf-protected)
    for k in [cid] + ([r['big_id']] if r['big_id'] else []):
        pg.goto(base + f'/agent/client_overview.php?client_id={k}', wait_until='domcontentloaded')
        a = pg.locator('a[href*="archive_client="]').first
        if a.count():
            tgt = a.get_attribute('href'); pg.evaluate("h=>location.href=h", tgt) if False else None
            a.evaluate('e=>e.click()'); pg.wait_for_timeout(800)
            c = pg.locator('.modal.show :text-is("Yes"), .modal.show .btn-danger')
            if c.count(): c.first.click()
            pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(600)
    pg.goto(base + '/agent/clients.php?q=' + f'QA-{ed.upper()}-CLIENT-{n}', wait_until='domcontentloaded'); r['archived_hidden_from_list'] = f'QA-{ed.upper()}-CLIENT-{n}' not in pg.inner_text('body')
r['errs'] = errs[:4]; b.close(); p.stop(); print(json.dumps(r, indent=1, ensure_ascii=False))
