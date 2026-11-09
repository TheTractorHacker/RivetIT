"""Server-side oversize name + archive of QA clients by id. usage: client_oversize_archive.py <it|msp> <id> [<id>...]"""
import sys, json
from common import *
ed = sys.argv[1]; ids = sys.argv[2:]; r = {}
p, b, ctx, pg, base = session(ed)
pg.goto(base + '/agent/clients.php', wait_until='domcontentloaded'); pg.get_by_text('New Department' if ed == 'it' else 'New Client', exact=True).first.click(); pg.wait_for_selector('.modal.show [name=name]')
pg.evaluate("()=>{const q=n=>document.querySelector('.modal.show [name='+n+']');q('name').removeAttribute('maxlength');q('name').value='QA-BIG-'+'X'.repeat(400);q('contact').value='C'}")
pg.locator('.modal.show button[name=add_client]').click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(900)
txt = pg.inner_text('body'); r['oversize_result'] = {'url': pg.url[len(base):], 'fatal': bool(__import__('re').search('Fatal|mysqli|Stack trace', txt)), 'alerts': pg.locator('.alert').all_inner_texts()[:2]}
pg.goto(base + '/agent/clients.php?q=QA-BIG', wait_until='domcontentloaded'); r['big_stored_len'] = [len(x) for x in pg.locator('table a').all_inner_texts() if 'QA-BIG' in x][:1]
for k in ids:
    pg.goto(base + f'/agent/client_overview.php?client_id={k}', wait_until='domcontentloaded')
    a = pg.locator('a[href*="archive_client="]')
    r[f'archive_link_{k}'] = a.count()
    if a.count():
        a.first.evaluate('e=>e.click()'); pg.wait_for_timeout(900)
        c = pg.locator('.modal.show :text-is("Yes"), .modal.show .btn-danger'); r[f'archive_confirm_{k}'] = c.count() > 0
        if c.count(): c.first.click()
        pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(700)
b.close(); p.stop(); print(json.dumps(r, indent=1))
