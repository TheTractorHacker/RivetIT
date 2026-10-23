"""Shared dropdown (TomSelect) behaviour in the New Ticket / Recurring Ticket modals and the portal ticket form. Exit 0 = pass.
usage: ts_dropdown_check.py [shots_dir]"""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:220] if detail and not ok else ''))
    if not ok: fails.append(name)
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    for (w, h) in ((1920, 1080), (1366, 768)):
        b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h}); tag = f'{theme} {w}x{h}'
        pg.goto(BASE + '/agent/tickets.php', wait_until='domcontentloaded'); time.sleep(1.2)
        pg.evaluate("() => openAjaxModal('modals/ticket/ticket_add_v2.php', 'lg')"); pg.wait_for_selector('.modal.show .ts-wrapper', timeout=10000); time.sleep(1.2)
        r = pg.evaluate("""() => {
          const wr = [...document.querySelectorAll('.modal.show .ts-wrapper.single')].filter(x => x.offsetParent);
          const vis = e => e && e.offsetParent && e.getBoundingClientRect().width > 4 && getComputedStyle(e).opacity !== '0';
          return { n: wr.length,
                   typedControls: wr.filter(x => vis(x.querySelector('.ts-control > input')) && x.querySelector('select').id !== 'contactSelect').length,
                   noChevron: wr.filter(x => getComputedStyle(x.querySelector('.ts-control'), '::after').content === 'none').length }
        }""")
        check(f'{tag}: closed single selects show no text caret/input and all have a chevron', r['n'] >= 4 and r['typedControls'] == 0 and r['noChevron'] == 0, r)
        pg.evaluate("() => document.querySelector('#ticket_template_select').tomselect.open()"); time.sleep(.5)
        opts = pg.evaluate("() => [...document.querySelectorAll('.ts-dropdown.single .option')].filter(o => o.offsetParent).map(o => o.innerText.trim())")
        check(f'{tag}: template list does not repeat the placeholder as a row', not any('Choose a Template' in o for o in opts) and len(opts) > 1, opts[:3])
        box = pg.evaluate("() => { const d = [...document.querySelectorAll('.ts-dropdown')].find(x => x.offsetParent); const r = d.getBoundingClientRect(); return [r.left, r.right, innerWidth] }")
        check(f'{tag}: open list stays inside the window', box[0] >= 0 and box[1] <= box[2], box)
        if out: pg.screenshot(path=f'{out}/ts_{theme}_{w}_template.png')
        pg.keyboard.press('Escape'); time.sleep(.4)
        check(f'{tag}: Esc closes the list but not the modal', pg.evaluate("() => !![...document.querySelectorAll('.modal.show')].length && ![...document.querySelectorAll('.ts-dropdown')].some(x => x.offsetParent)"))
        # keyboard on a short select (priority): focus, ArrowDown opens, Enter picks
        pg.evaluate("() => document.querySelector('.modal.show [name=priority]').tomselect.focus()"); time.sleep(.3)
        before = pg.evaluate("() => document.querySelector('.modal.show [name=priority]').value")
        pg.keyboard.press('ArrowDown'); time.sleep(.3); pg.keyboard.press('ArrowDown'); pg.keyboard.press('Enter'); time.sleep(.3)
        after = pg.evaluate("() => document.querySelector('.modal.show [name=priority]').value")
        check(f'{tag}: arrows + Enter change a short select; value persists in the form', after != before and pg.evaluate("() => new FormData(document.querySelector('.modal.show form')).get('priority')") == after, (before, after))
        fcol = pg.evaluate("() => { document.querySelector('.modal.show [name=priority]').tomselect.focus(); const c = getComputedStyle(document.querySelector('.modal.show [name=priority]').closest('.input-group').querySelector('.ts-control')); return c.borderTopColor }")
        m = [int(x) for x in re.findall(r'\d+', fcol)[:3]]
        check(f'{tag}: focus border is not alarming red', not (m[0] > 180 and m[1] < 90 and m[2] < 90), fcol)
        # dependent selects
        pg.evaluate("() => document.querySelector('a[href=\"#pills-add-relationships\"]').click()"); time.sleep(.5)
        st = pg.evaluate("() => ['assetSelect','locationSelect','vendorSelect'].map(i => { const s = document.getElementById(i); const t = s.tomselect; return [s.disabled, t ? t.getValue() : '', (t ? t.options['0'] : {}).text] })")
        check(f'{tag}: Asset/Location/Vendor are disabled with a "Select a department first" hint before a department is chosen', all(x[0] and 'department first' in (x[2] or '') for x in st), st)
        pg.evaluate("() => { const t = document.getElementById('changeClientSelect').tomselect; t.setValue(Object.keys(t.options).find(k => k && k !== '0')); }"); time.sleep(2)
        st = pg.evaluate("() => ['assetSelect','locationSelect','vendorSelect'].map(i => { const s = document.getElementById(i); return [s.disabled, Object.keys(s.tomselect.options).length] })")
        check(f'{tag}: they enable and fill when a department is chosen', all((not x[0]) and x[1] >= 1 for x in st) and st[0][1] > 1, st)
        pg.evaluate("() => { const t = document.getElementById('changeClientSelect').tomselect; t.clear(); }"); time.sleep(1)
        st = pg.evaluate("() => ['assetSelect','locationSelect','vendorSelect'].map(i => document.getElementById(i).disabled)")
        check(f'{tag}: clearing the department disables them again', all(st), st)
        if out: pg.screenshot(path=f'{out}/ts_{theme}_{w}_assign.png')
        b.close()
# recurring ticket modal + portal form: same single-select rules
b, ctx, pg = login(p, 'admin'); pg.set_viewport_size({'width': 1366, 'height': 768})
pg.goto(BASE + '/agent/recurring_tickets.php?client_id=16', wait_until='domcontentloaded'); time.sleep(1.2)
pg.evaluate("() => openAjaxModal('modals/recurring_ticket/recurring_ticket_add.php?client_id=16', 'lg')"); pg.wait_for_selector('.modal.show .ts-wrapper', timeout=10000); time.sleep(1.2)
r = pg.evaluate("() => [...document.querySelectorAll('.modal.show .ts-wrapper.single')].filter(x => x.offsetParent && x.querySelector('.ts-control > input') && x.querySelector('.ts-control > input').offsetParent && x.querySelector('.ts-control > input').getBoundingClientRect().width > 4).length")
check('recurring ticket modal: no select shows a caret in its closed control', r == 0, r)
b.close()
b, ctx, pg = login(p, 'portal'); pg.set_viewport_size({'width': 1366, 'height': 768})
pg.goto(BASE + '/client/ticket_add.php', wait_until='domcontentloaded'); time.sleep(1.5)
r = pg.evaluate("() => [...document.querySelectorAll('.ts-wrapper.single')].filter(x => x.offsetParent && x.querySelector('.ts-control > input') && x.querySelector('.ts-control > input').getBoundingClientRect().width > 4).length")
check('portal new ticket: no select shows a caret in its closed control', r == 0, r)
b.close()
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
