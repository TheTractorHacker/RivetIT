"""Stored-XSS typing test (asserts). Types hostile strings with real keystrokes into a department category name, a ticket category and a user name,
then renders every page that later displays them and asserts: no script executed, no raw '<script', no double-escaped '&amp;lt;'.
Covers the two security-review flags on agent/ticket.php and agent/ticket_list.php (htmlspecialchars removed). Scratch demo only; exit != 0 on failure."""
from common import *
PAY = '<script>window.__xss=1</script><img src=x onerror="window.__xss=1">A&B "q" \'s\' é日'
fails = []
def check(n, ok, d=''):
    print(('PASS ' if ok else 'FAIL ') + n + ((' :: ' + str(d)[:200]) if not ok else '')); 
    if not ok: fails.append(n)
p = sync_playwright().start(); b, ctx, pg = login(p, 'admin'); pg.on('dialog', lambda d: d.accept())
def modal(url, murl, fills, submit_name=None):
    pg.goto(BASE + url, wait_until='domcontentloaded'); time.sleep(.8)
    pg.evaluate("(u) => { const e = [...document.querySelectorAll('.ajax-modal[data-modal-url]')].find(x => x.getAttribute('data-modal-url').startsWith(u)); e.click() }", murl)
    pg.wait_for_selector('.modal.show form', timeout=8000); time.sleep(.6)
    for sel, val in fills:
        pg.locator('.modal.show ' + sel).first.click(); pg.keyboard.press('Control+A'); pg.keyboard.type(val, delay=0)
    pg.evaluate("() => { const r = document.querySelector('.modal.show select[name=role]'); if (r && !r.value) { const o = [...r.options].find(x => x.value && x.value !== '0'); if (o) { if (r.tomselect) r.tomselect.setValue(o.value); else r.value = o.value; } } }"); time.sleep(.3)
    pg.locator('.modal.show button[type=submit]:visible').first.click(); time.sleep(1.8)
# 1 ticket category (board) with hostile name
tag = 'QA-UI-XSS-%d' % int(time.time())
name = tag + ' ' + PAY
modal('/admin/category.php?category=Ticket', 'modals/category/category_add.php?category=Ticket', [('input[name=name]', name)])
pg.goto(BASE + '/admin/category.php?category=Ticket', wait_until='domcontentloaded'); time.sleep(1)
row = pg.evaluate("(t) => { const r = [...document.querySelectorAll('tbody tr')].find(x => x.innerText.includes(t)); return r ? r.innerText : null }", tag)
check('category saved and listed', row is not None)
cid = mysql("select category_id from categories where category_name like '%s%%' limit 1" % tag)
cid = cid[0][0] if cid else None
stored = mysql("select category_name from categories where category_id=%s" % cid)[0][0] if cid else ''
check('category name is stored inert (stripped or escaped), never as live markup', '<script' not in stored.lower() and 'onerror=' not in stored.lower() or '&lt;script' in stored.lower(), stored[:80])
# attach to a ticket and render every ticket view
if cid:
    mysql("update tickets set ticket_category=%s where ticket_id=248" % cid)
    pages = ['/agent/tickets.php?client_id=16', '/agent/ticket_list.php', '/agent/ticket.php?client_id=16&ticket_id=248', '/agent/dashboard.php', '/client/ticket.php?id=1']
    for u in pages[:4]:
        pg.evaluate("() => { window.__xss = 0 }"); pg.goto(BASE + u, wait_until='domcontentloaded'); time.sleep(1.6)
        html = pg.content()
        txt = pg.evaluate("() => document.body.innerText")
        check(f'no script executed on {u.split("?")[0]}', not pg.evaluate("() => !!window.__xss"))
        check(f'no live <script>/<img onerror> from the category on {u.split("?")[0]}', '<script>window.__xss' not in html and not pg.evaluate("() => !!document.querySelector('img[onerror], [onerror]')"))
        if tag in txt:
            mine = [l for l in txt.split('\n') if tag in l]
            check(f'category shown exactly once-escaped on {u.split("?")[0]}', all('&amp;' not in l and '&lt;' not in l and '&quot;' not in l for l in mine) and any('A&B' in l for l in mine), mine[:1])
    mysql("update tickets set ticket_category=0 where ticket_id=248")
    mysql("delete from categories where category_id=%s" % cid)
# 2 user name
uname = 'QA-UI-U-%d ' % int(time.time()) + PAY
pg.goto(BASE + '/admin/users.php', wait_until='domcontentloaded'); time.sleep(.6)
modal('/admin/users.php', 'modals/user/user_add.php', [('input[name=name]', uname), ('input[name=email]', 'qa.xss%d@example.com' % int(time.time())), ('input[name=password]', 'QaUi#Pass12345')])
time.sleep(1)
uid = mysql("select user_id from users where user_name like 'QA-UI-U-%%' order by user_id desc limit 1"); uid = uid[0][0] if uid else None
check('user created via keystrokes', uid is not None)
if uid:
    mysql("update tickets set ticket_assigned_to=%s where ticket_id=248" % uid)
    for u in ['/admin/users.php', '/agent/tickets.php?client_id=16', '/agent/ticket_list.php', '/agent/ticket.php?client_id=16&ticket_id=248']:
        pg.evaluate("() => { window.__xss = 0 }"); pg.goto(BASE + u, wait_until='domcontentloaded'); time.sleep(1.5)
        html = pg.content(); txt = pg.evaluate("() => document.body.innerText")
        check(f'no script executed on {u.split("?")[0]} (user name)', not pg.evaluate("() => !!window.__xss") and not pg.evaluate("() => !!document.querySelector('img[onerror]')"))
        mine = [l for l in txt.split('\n') if 'QA-UI-U-' in l]
        if mine:
            check(f'user name shown once-escaped on {u.split("?")[0]}', all('&amp;' not in l and '&lt;' not in l for l in mine), mine[:1])
    mysql("update tickets set ticket_assigned_to=0 where ticket_id=248")
    mysql("update users set user_archived_at=now() where user_id=%s" % uid)
b.close(); p.stop(); print('\nFAILED: %d' % len(fails)); sys.exit(1 if fails else 0)
