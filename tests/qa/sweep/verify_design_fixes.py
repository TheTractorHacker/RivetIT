"""Asserting regression test for the design-review fixes. Exit code != 0 on any failure. Needs the scratch demo on :8191.
Each check opens the page as the right role and asserts the computed fact the fix is about (not just 'page loads')."""
from common import *
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail) if detail and not ok else ''))
    if not ok: fails.append(name)
p = sync_playwright().start()
def sess(who, w=1920, h=1080):
    b = p.chromium.launch(); ctx = b.new_context(viewport={'width': w, 'height': h}); pg = ctx.new_page()
    e, pw = USERS[who]; pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw)
    pg.locator('button[type=submit]').first.click(); pg.wait_for_url(lambda u: 'login' not in u, timeout=20000); time.sleep(.8); return b, pg
def go(pg, path):
    pg.goto(BASE + path, wait_until='domcontentloaded'); time.sleep(1.2)
    if '/login' in pg.url: check('session still valid on ' + path, False, pg.url)
for theme, who in (('light', 'admin'), ('dark', 'admin_d')):
    b, pg = sess(who)
    go(pg, '/admin/sla_policies.php')
    check(f'{theme} alert with inline markup is not a flex row', pg.evaluate("() => getComputedStyle(document.querySelector('main .alert-info')).display") == 'block')
    mysql("update tickets set ticket_category=(select category_id from categories where category_name='Access & Accounts' and category_type='Ticket' limit 1) where ticket_id=248")
    go(pg, '/agent/tickets.php?client_id=22')
    txt = pg.evaluate("() => document.querySelector('main').innerText")
    check(f'{theme} no literal &amp; in the category pill', not any('Access &amp;' in l for l in txt.split('\n')) and 'Access & Accounts' in txt, [l for l in txt.split('\n') if 'Access &' in l][:2])
    go(pg, '/agent/trips.php?client_id=16')
    bt = pg.evaluate("() => document.body.innerText")
    check(f'{theme} empty list without filters says "Nothing here yet." (or has rows)', 'Nothing here yet.' in bt or 'No results match your filters.' not in bt)
    go(pg, '/agent/invoice.php?client_id=16')
    check(f'{theme} invoice page without id explains itself', 'No invoice selected' in pg.evaluate("() => document.body.innerText"))
    go(pg, '/admin/canned_responses.php')
    bt2 = pg.evaluate("() => document.body.innerText").replace('\n', ' ')
    check(f'{theme} canned preview keeps a space between paragraphs', 'Hello,Your' not in bt2 and 'started?The' not in bt2)
    go(pg, '/agent/document_details.php?client_id=16&document_id=1')
    c = pg.evaluate("() => { const e = document.querySelector('.card-header.bg-dark .h4'); const s = getComputedStyle(e); return [s.color, getComputedStyle(e.closest('.card-header')).backgroundColor] }")
    check(f'{theme} document title is light on the dark band', c[0] in ('rgb(255, 255, 255)',), c)
    go(pg, '/agent/racks.php?client_id=16')
    check(f'{theme} rack table head text is white', pg.evaluate("() => getComputedStyle(document.querySelector('thead.thead-dark th')).color") == 'rgb(255, 255, 255)')
    go(pg, '/admin/settings_module.php')
    sw = pg.evaluate("() => { const e = [...document.querySelectorAll('.form-switch .form-check-input')].find(x => !x.checked); const s = getComputedStyle(e); return [s.backgroundColor, s.borderTopColor] }")
    check(f'{theme} unchecked switch is distinguishable from the card', sw[0] != 'rgb(255, 255, 255)' and sw[1] != 'rgb(255, 255, 255)' and sw[1] != sw[0], sw)
    go(pg, '/agent/ticket.php?client_id=16&ticket_id=248'); time.sleep(2)
    skin = pg.evaluate("() => { const e = document.querySelector('.tox-tinymce'); return e ? [getComputedStyle(e).backgroundColor, document.documentElement.getAttribute('data-bs-theme'), !!document.querySelector('link[href*=oxide-dark]') || !!document.querySelector('.tox-tinymce[class*=dark]') ] : null }")
    if skin: 
        lum = [int(x) for x in re.findall(r'\d+', skin[0])[:3]]
        check(f'{theme} TinyMCE follows the theme', (sum(lum) / 3 < 110) if theme == 'dark' else (len(lum) < 3 or skin[0] == 'rgba(0, 0, 0, 0)' or sum(lum) / 3 > 200), skin)
    go(pg, '/agent/training_records.php')
    check(f'{theme} training records person cell is not one letter wide', pg.evaluate("() => { const e = document.querySelector('#tro-rec-table .tro-person__text'); return e ? e.getBoundingClientRect().width : 999 }") > 60)
    b.close()
# tablet checks
for theme, who in (('light', 'admin'),):
    b, pg = sess(who, 768, 1024)
    go(pg, '/agent/asset_details.php?client_id=16&asset_id=92')
    w = pg.evaluate("() => { const c = [...document.querySelectorAll('.row > [class*=col-md-]')].filter(e => e.offsetWidth > 0); return Math.min(...c.map(e => e.offsetWidth)) }")
    check('tablet: detail columns are not squeezed below 250px', w >= 250, w)
    go(pg, '/agent/training_records.php')
    check('tablet: training record name is readable', pg.evaluate("() => { const e = document.querySelector('#tro-rec-table .tro-person__text'); return e ? e.getBoundingClientRect().width : 999 }") > 60)
    b.close()
b, pg = sess('portal2', 768, 1024)
go(pg, '/client/training.php')
y = pg.evaluate("() => { const h = document.querySelector('main h3'); const btn = document.querySelector('main .btn-primary'); return [h.getBoundingClientRect().bottom, btn.getBoundingClientRect().top] }")
check('portal training: title does not collide with the toolbar', y[0] <= y[1] + 1, y)
b.close(); p.stop()
print('\nFAILED: %d' % len(fails)); sys.exit(1 if fails else 0)
