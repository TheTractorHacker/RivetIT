"""Keyboard / focus / interaction assertions on a representative set of modals and pages (scratch demo). Exit != 0 on any failure."""
from common import *
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + ((' :: ' + str(detail)[:200]) if not ok and detail != '' else ''))
    if not ok: fails.append(name)
MODALS = [('/agent/clients.php', 'modals/client/client_add.php'), ('/agent/tickets.php?client_id=16', 'modals/ticket/ticket_add_v2.php'),
          ('/agent/contacts.php?client_id=16', 'modals/contact/contact_add.php'), ('/agent/assets.php?client_id=16', 'modals/asset/asset_add.php'),
          ('/admin/tag.php', 'modals/tag/tag_add.php'), ('/admin/canned_responses.php', 'modals/canned_response/canned_response_add.php'),
          ('/admin/custom_link.php', 'modals/custom_link/custom_link_add.php'), ('/agent/vendors.php?client_id=16', 'modals/vendor/vendor_add.php'),
          ('/agent/locations.php?client_id=16', 'modals/location/location_add.php'), ('/agent/contracts.php?client_id=16', 'modals/contract/contract_add.php')]
p = sync_playwright().start(); b, ctx, pg = login(p, 'admin')
pg.on('dialog', lambda d: d.accept())
js_errs = []; pg.on('pageerror', lambda e: js_errs.append(str(e)[:120]))
FOCUS_JS = "() => { const a = document.activeElement; if (!a || a === document.body) return null; const s = getComputedStyle(a); const ow = parseFloat(s.outlineWidth) || 0; const w = a.closest('.ts-wrapper'); const ts = w ? getComputedStyle(w.querySelector('.ts-control')) : null; const tsRing = ts && ((ts.boxShadow && ts.boxShadow !== 'none') || (ts.outlineStyle !== 'none' && parseFloat(ts.outlineWidth) > 0) || ts.borderTopColor !== getComputedStyle(w).borderTopColor); return {tag: a.tagName, inModal: !!a.closest('.modal.show'), ring: (s.outlineStyle !== 'none' && ow > 0) || (s.boxShadow && s.boxShadow !== 'none') || !!tsRing, name: (a.getAttribute('name') || a.id || a.innerText || '').slice(0, 24)} }"
for page_url, murl in MODALS:
    tag = murl.split('/')[-1][:-4]
    try:
        pg.goto(BASE + page_url, wait_until='domcontentloaded'); time.sleep(.8)
        trig = pg.locator(f'.ajax-modal[data-modal-url^="{murl}"]:visible').first
        if not trig.count(): check(f'{tag}: trigger exists', False, page_url); continue
        trig.evaluate("e => { e.setAttribute('data-qa-trigger', '1'); }")
        trig.click(); pg.wait_for_selector('.modal.show .modal-content', timeout=8000); time.sleep(.7)
        a = pg.evaluate(FOCUS_JS)
        check(f'{tag}: focus moves into the modal on open', bool(a and a['inModal']), a)
        seq = []
        for i in range(30):
            pg.keyboard.press('Tab'); time.sleep(.03); seq.append(pg.evaluate(FOCUS_JS))
        check(f'{tag}: focus trap (30 Tabs never land on the page behind; reaching browser chrome = null is allowed)', all((s is None) or s['inModal'] for s in seq), [s for s in seq if s and not s['inModal']][:2])
        noring = [s['name'] for s in seq if s and s['inModal'] and not s['ring'] and s['tag'] in ('BUTTON', 'A', 'INPUT', 'SELECT', 'TEXTAREA')]
        check(f'{tag}: every focused control shows a visible focus indicator', len(noring) == 0, noring[:4])
        for _ in range(3):
            pg.keyboard.press('Escape'); time.sleep(.5)
            if pg.evaluate("() => !document.querySelector('.modal.show')"): break
        closed = pg.evaluate("() => !document.querySelector('.modal.show')")
        check(f'{tag}: Esc closes the modal (within 3 presses: first may close a dropdown/editor popup)', closed)
        if not closed:
            pg.evaluate("() => document.querySelectorAll('.modal.show').forEach(m => bootstrap.Modal.getInstance(m)?.hide())"); time.sleep(.6)
        ret = pg.evaluate("() => { const a = document.activeElement; const t = document.querySelector('[data-qa-trigger]'); return !!(a && t && (a === t || t.contains(a) || a.contains(t))) }")
        check(f'{tag}: focus returns to the trigger after Esc', ret, pg.evaluate("() => document.activeElement && (document.activeElement.tagName + '.' + document.activeElement.className).slice(0, 60)"))
        # resize + zoom while open
        trig.click(); pg.wait_for_selector('.modal.show .modal-content', timeout=8000); time.sleep(.6)
        for (w, h) in ((900, 700), (400, 700)):
            pg.set_viewport_size({'width': w, 'height': h}); time.sleep(.4)
            r = pg.evaluate("() => { const d = document.querySelector('.modal.show .modal-dialog'); const r = d.getBoundingClientRect(); return {l: r.left, r: r.right, w: innerWidth, hs: document.documentElement.scrollWidth - innerWidth} }")
            check(f'{tag}: modal fits {w}px viewport while open', r['l'] >= -1 and r['r'] <= r['w'] + 1, r)
        pg.set_viewport_size({'width': 1280, 'height': 800}); pg.evaluate("() => { document.documentElement.style.fontSize = '200%' }"); time.sleep(.4)
        r = pg.evaluate("() => { const d = document.querySelector('.modal.show .modal-dialog'); const f = d.querySelector('button[type=submit], .modal-footer .btn'); const m = document.querySelector('.modal.show'); const fr = f ? f.getBoundingClientRect() : null; return {dw: d.getBoundingClientRect().width, w: innerWidth, btnReach: !!fr && (fr.bottom <= innerHeight + 2 || m.scrollHeight > m.clientHeight || getComputedStyle(d.querySelector('.modal-body')).overflowY !== 'visible')} }")
        check(f'{tag}: at 200% text size the dialog still fits and the submit button is reachable', r['dw'] <= r['w'] + 1 and r['btnReach'], r)
        pg.evaluate("() => { document.documentElement.style.fontSize = '' }"); pg.set_viewport_size({'width': 1920, 'height': 1080})
        pg.keyboard.press('Escape'); time.sleep(.5)
    except Exception as ex:
        check(f'{tag}: ran without exception', False, ex)
# Enter submits a form, double-click creates once, back/forward does not resubmit
try:
    pg.goto(BASE + '/admin/canned_responses.php', wait_until='domcontentloaded'); time.sleep(.8)
    pg.locator('.ajax-modal[data-modal-url^="modals/canned_response/canned_response_add.php"]').first.click(); pg.wait_for_selector('.modal.show form', timeout=8000); time.sleep(.6)
    name = 'QA-UI-dbl-%d' % int(time.time()); pg.fill('.modal.show [name=name]', name)
    try: pg.fill('.modal.show textarea[name=message]', 'QA-UI- body')
    except Exception: pg.evaluate("(n) => { const t = document.querySelector('.modal.show [name=message]'); if (t) t.value = n }", 'QA-UI- body')
    posts = []; pg.on('request', lambda r: posts.append(r.url) if r.method == 'POST' else None)
    pg.locator('.modal.show button[type=submit]:visible').first.dblclick(); time.sleep(2.5)
    n = int(mysql("select count(*) from canned_responses where canned_response_name='%s'" % name)[0][0])   # database, not the page: the list is paginated
    check('double-click submit creates exactly one record', n == 1, f'rows with name: {n}, POSTs: {len(posts)}')
    pg.go_back(); time.sleep(1); posts.clear(); pg.go_forward(); time.sleep(1.2)
    check('back/forward after submit does not resubmit the form', len(posts) == 0, posts)
except Exception as ex: check('double-submit / history test ran', False, ex)
# arrow keys on tabs
try:
    pg.goto(BASE + '/admin/settings_endpoint_agent.php', wait_until='domcontentloaded'); time.sleep(1)
    tabs = pg.locator('[data-bs-toggle="tab"]:visible'); 
    if tabs.count() >= 2:
        tabs.first.focus(); first = pg.evaluate("() => document.activeElement.getAttribute('href') || document.activeElement.getAttribute('data-bs-target')")
        pg.keyboard.press('ArrowRight'); time.sleep(.4)
        second = pg.evaluate("() => document.activeElement.getAttribute('href') || document.activeElement.getAttribute('data-bs-target')")
        check('ArrowRight moves between tabs (roving tabindex)', second != first, [first, second])
        role = pg.evaluate("() => !!document.querySelector('[role=tablist]')")
        check('tab strip has role=tablist', role)
except Exception as ex: check('tab keyboard test ran', False, ex)
check('no uncaught JS errors during keyboard tests', len(js_errs) == 0, js_errs[:3])
b.close(); p.stop(); print('\nFAILED: %d' % len(fails)); sys.exit(1 if fails else 0)
