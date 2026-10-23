"""Every sortable list header shows a sort icon and aria-sort, and ascending is an up arrow (it used to be inverted). Exit 0 = pass."""
from common import *
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
PAGES = ['assets.php', 'contacts.php', 'vendors.php', 'locations.php', 'domains.php', 'certificates.php', 'software.php', 'products.php', 'tickets.php?view=all', 'recurring_tickets.php',
         'credentials.php', 'documents.php', 'networks.php', 'racks.php', 'expenses.php', 'invoices.php', 'quotes.php', 'projects.php', 'trips.php', 'payments.php', 'changes.php', 'logs.php', 'clients.php', 'users.php']
p = sync_playwright().start()
b, ctx, pg = login(p, 'admin'); pg.set_viewport_size({'width': 1920, 'height': 1080})
total = 0; pages_with = 0
for pth in PAGES:
    r = pg.goto(BASE + '/agent/' + pth, wait_until='domcontentloaded'); time.sleep(.8)
    if r.status != 200: continue
    st = pg.evaluate("() => { const links = [...document.querySelectorAll('table thead th a[href*=\"sort=\"]')]; return {n: links.length, bad: links.filter(a => !a.classList.contains('sort-link') || !a.querySelector('.sort-icon') || !a.closest('th').hasAttribute('aria-sort')).map(a => a.textContent.trim()), active: links.filter(a => a.classList.contains('is-active')).map(a => ({t: a.textContent.trim(), up: !!a.querySelector('.fa-sort-up'), down: !!a.querySelector('.fa-sort-down'), aria: a.closest('th').getAttribute('aria-sort')}))} }")
    if st['n'] == 0: continue
    pages_with += 1; total += st['n']
    check(f'{pth}: all {st["n"]} sortable headers have an icon and aria-sort', not st['bad'], st['bad'])
    for a in st['active']:
        check(f'{pth}: active column "{a["t"]}" shows the right arrow for its aria-sort', (a['aria'] == 'ascending' and a['up']) or (a['aria'] == 'descending' and a['down']), a)
check(f'sortable lists found and checked ({pages_with} pages, {total} headers)', pages_with >= 8 and total >= 40, (pages_with, total))
# direction: clicking a header toggles ASC (up) then DESC (down)
pg.goto(BASE + '/agent/contacts.php?sort=contact_name&order=ASC', wait_until='domcontentloaded'); time.sleep(.6)
check('ascending sort shows an up arrow', pg.locator('table thead th[aria-sort=ascending] .fa-sort-up').count() == 1)
pg.goto(BASE + '/agent/contacts.php?sort=contact_name&order=DESC', wait_until='domcontentloaded'); time.sleep(.6)
check('descending sort shows a down arrow', pg.locator('table thead th[aria-sort=descending] .fa-sort-down').count() == 1)
b.close()
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
