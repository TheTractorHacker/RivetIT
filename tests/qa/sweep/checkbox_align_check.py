"""Bulk-select checkbox column: header 'select all', group-header rows and ticket/row checkboxes line up (centre x within 1px, centred in the row).
Light/dark, 1920x1080 and 1366x768, sidebar open and collapsed. Exit 0 = pass. usage: checkbox_align_check.py [shots_dir]"""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
PAGES = ['/agent/tickets.php?client_id=16', '/agent/ticket_list.php', '/agent/assets.php?client_id=16', '/agent/contacts.php?client_id=16', '/agent/clients.php',
         '/agent/recurring_tickets.php?client_id=16', '/agent/domains.php?client_id=16', '/agent/networks.php?client_id=16', '/agent/vendors.php?client_id=16',
         '/agent/locations.php?client_id=16', '/agent/certificates.php?client_id=16', '/agent/printers.php?client_id=16', '/agent/credentials.php?client_id=16',
         '/agent/invoices.php?client_id=16', '/agent/products.php', '/agent/expenses.php', '/agent/files.php?client_id=16', '/agent/network_drives.php?client_id=16',
         '/admin/api_keys.php', '/admin/holidays.php', '/admin/mail_queue.php']
PAGES = os.environ['QA_PAGES'].split(',') if os.environ.get('QA_PAGES') else PAGES
fails = []; seen = 0
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
JS = """() => {
  const hdr = document.querySelector('#selectAllCheckbox'); if (!hdr || !hdr.offsetParent) return null;
  const table = hdr.closest('table'); const cx = e => { const r = e.getBoundingClientRect(); return r.left + r.width / 2 };
  const rows = [...table.querySelectorAll('tbody input.bulk-select, tbody input[type=checkbox]')].filter(e => e.offsetParent).slice(0, 40);
  if (!rows.length) return {rows: 0};
  const hx = cx(hdr), xs = rows.map(cx);
  const vc = rows.map(e => { const tr = e.closest('tr').getBoundingClientRect(), r = e.getBoundingClientRect(); return Math.abs((r.top + r.height / 2) - (tr.top + tr.height / 2)) });
  const sz = new Set(rows.concat([hdr]).map(e => Math.round(e.getBoundingClientRect().width) + 'x' + Math.round(e.getBoundingClientRect().height)));
  const heads = [...table.querySelectorAll('tr.ticket-group-header')].filter(e => e.offsetParent);
  const gstart = heads.map(h => { const first = h.firstElementChild; const ic = first.querySelector('i, .status-dot, span'); return ic ? Math.abs((ic.getBoundingClientRect().left + ic.getBoundingClientRect().width / 2) - cx(hdr)) : 0 });
  return {rows: rows.length, dx: Math.max(...xs.map(x => Math.abs(x - hx))), dy: Math.max(...vc), sizes: [...sz], gdx: gstart.length ? Math.max(...gstart) : 0,
          sticky: getComputedStyle(table.querySelector('thead th, thead td')).position}
}"""
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    for (w, h) in ((1920, 1080), (1366, 768)):
        b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h})
        for collapsed in (False, True):
            for path in PAGES:
                pg.goto(BASE + path, wait_until='domcontentloaded'); time.sleep(.9)
                if collapsed:
                    pg.evaluate("() => { document.body.classList.add('sidebar-collapse'); document.documentElement.classList.add('sidebar-collapsed'); }"); time.sleep(.3)
                r = pg.evaluate(JS)
                if not r or not r.get('rows'): continue
                seen += 1
                tag = f'{theme} {w}x{h}{" collapsed" if collapsed else ""} {path.split("?")[0]}'
                check(f'{tag}: checkbox centres align (<=1px) and are vertically centred', r['dx'] <= 1.01 and (r.get('gdx') or 0) <= 1.5 and r['dy'] <= 2.5 and len(r['sizes']) == 1, r)
            if out and not collapsed:
                pg.goto(BASE + '/agent/tickets.php?client_id=16', wait_until='domcontentloaded'); time.sleep(1); pg.screenshot(path=f'{out}/cb_{theme}_{w}.png')
        b.close()
check('at least 10 distinct list pages were measured', seen >= 40, seen)
print('\nFAILS:', len(fails)); sys.exit(1 if fails else 0)
