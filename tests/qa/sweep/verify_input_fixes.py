"""Asserting regression test for the input-typing fixes. Exit != 0 on failure. Scratch demo only."""
from common import *
fails = []
def check(n, ok, d=''):
    print(('PASS ' if ok else 'FAIL ') + n + ((' :: ' + str(d)[:160]) if not ok else ''))
    if not ok: fails.append(n)
p = sync_playwright().start(); b, ctx, pg = login(p, 'admin'); pg.on('dialog', lambda d: d.accept())
st = []
pg.on('response', lambda r: st.append((r.status, r.request.method)) if r.request.method == 'POST' else None)
pg.goto(BASE + '/admin/ticket_status.php'); time.sleep(.8)
pg.evaluate("() => [...document.querySelectorAll('.ajax-modal[data-modal-url]')].find(x=>x.getAttribute('data-modal-url').includes('ticket_status_edit')).click()"); pg.wait_for_selector('.modal.show form'); time.sleep(.6)
pg.locator('.modal.show [name=order]').click(); pg.keyboard.press('Control+A'); pg.keyboard.type('99999999999999999999')
st.clear(); pg.locator('.modal.show button[type=submit]:visible').first.click(); time.sleep(2)
check('huge number in an int field: no HTTP 500', all(s[0] < 500 for s in st), st)
check('huge number: readable message shown', 'too large' in pg.evaluate("() => document.body.innerText").lower() or 'not valid' in pg.evaluate("() => document.body.innerText").lower())
def modal_submit(page_url, murl, fields, extra=None):
    pg.goto(BASE + page_url); time.sleep(.8)
    pg.evaluate("(u) => [...document.querySelectorAll('.ajax-modal[data-modal-url]')].find(x=>x.getAttribute('data-modal-url').startsWith(u)).click()", murl); pg.wait_for_selector('.modal.show form'); time.sleep(.6)
    for sel, v in fields:
        pg.locator('.modal.show ' + sel).first.click(); pg.keyboard.press('Control+A'); pg.keyboard.type(v)
    st.clear(); pg.locator('.modal.show button[type=submit]:visible').first.click(); time.sleep(2)
modal_submit('/agent/problems.php', 'modals/problem/problem_add.php', [('[name=title]', 'x' * 5000)])
check('5000-char title: no HTTP 500', all(s[0] < 500 for s in st), st)
before = mysql("select count(*) from services where trim(service_description)=''")[0][0]
modal_submit('/agent/services.php?client_id=16', 'modals/service/service_add.php', [('[name=name]', 'QA-UI-ws'), ('[name=description]', '    ')])
after = mysql("select count(*) from services where trim(service_description)=''")[0][0]
check('whitespace-only required description is rejected (service)', after == before, (before, after))
b.close(); p.stop(); print('\nFAILED: %d' % len(fails)); sys.exit(1 if fails else 0)
