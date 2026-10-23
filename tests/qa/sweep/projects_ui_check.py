"""Projects module + shared search bar: kanban lanes equal width/top, cards padded, drag and drop still works on a QA-UI- task, New Task is a real CTA,
project header padded, search input-groups join cleanly on 12 list pages. Exit 0 = pass. usage: projects_ui_check.py [shots_dir]"""
from common import *
out = sys.argv[1] if len(sys.argv) > 1 else None
if out: os.makedirs(out, exist_ok=True)
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:220] if detail and not ok else ''))
    if not ok: fails.append(name)
LISTS = ['/agent/projects.php?client_id=16', '/agent/tickets.php?client_id=16', '/agent/assets.php?client_id=16', '/agent/contacts.php?client_id=16', '/agent/clients.php',
         '/agent/kb_articles.php', '/agent/domains.php?client_id=16', '/agent/vendors.php?client_id=16', '/agent/payments.php?client_id=16', '/agent/invoices.php?client_id=16',
         '/agent/files.php?client_id=16', '/agent/credentials.php?client_id=16', '/agent/software.php?client_id=16', '/agent/expenses.php']
# self-contained: the drag target task exists in project 4 / To Do
if not mysql("select 1 from tasks where task_name='QA-UI-200371'"):
    mysql("insert into tasks (task_name, task_status, task_project_id, task_order) values ('QA-UI-200371', 'To Do', 4, 0)")
p = sync_playwright().start()
for who, theme in (('admin', 'light'), ('admin_d', 'dark')):
    for (w, h) in ((1920, 1080), (1366, 768)):
        b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h}); tag = f'{theme} {w}x{h}'
        pg.goto(BASE + '/agent/project_kanban.php?client_id=16&project_id=4', wait_until='domcontentloaded'); time.sleep(1.5)
        r = pg.evaluate("""() => { const cols = [...document.querySelectorAll('.kanban-column')].map(c => c.getBoundingClientRect());
           const tasks = [...document.querySelectorAll('.task')]; const pad = tasks.length ? parseFloat(getComputedStyle(tasks[0]).paddingLeft) : 0;
           const btn = document.querySelector('.project-new-task').getBoundingClientRect();
           const hdr = [...document.querySelectorAll('.kanban-column .panel-title')].every(x => x.scrollWidth <= x.clientWidth + 1);
           return {n: cols.length, widths: cols.map(c => Math.round(c.width)), tops: cols.map(c => Math.round(c.top)), pad, btnH: btn.height, hdr,
                   handleOutside: [...document.querySelectorAll('.task .drag-handle-class')].filter(hh => { const t = hh.closest('.task').getBoundingClientRect(), r = hh.getBoundingClientRect(); return r.right > t.right + 1 || r.bottom > t.bottom + 1 }).length } }""")
        check(f'{tag} kanban: 4 lanes of equal width starting on one line', r['n'] == 4 and max(r['widths']) - min(r['widths']) <= 1 and len(set(r['tops'])) == 1, r)
        check(f'{tag} kanban: cards have inner padding, headers fit, handles stay inside cards', r['pad'] >= 8 and r['hdr'] and r['handleOutside'] == 0, r)
        check(f'{tag} kanban: New Task is a ~40px primary CTA', r['btnH'] >= 38, r)
        if out: pg.screenshot(path=f'{out}/kanban_{theme}_{w}.png')
        pg.goto(BASE + '/agent/project_details.php?client_id=16&project_id=4', wait_until='domcontentloaded'); time.sleep(1.2)
        r = pg.evaluate("""() => { const cs = [...document.querySelectorAll('.project-summary-group > .card.card-body')]; const bt = [...document.querySelectorAll('.project-header-card .card-tools .btn-sm')].map(x => x.getBoundingClientRect().height);
           return {pad: cs.map(c => parseFloat(getComputedStyle(c).paddingLeft)), tops: cs.map(c => Math.round(c.querySelector(':scope > *').getBoundingClientRect().top)), bt} }""")
        check(f'{tag} project header: summary cards padded, first lines share a top, buttons >= 34px', all(x >= 16 for x in r['pad']) and max(r['tops']) - min(r['tops']) <= 2 and all(x >= 34 for x in r['bt']), r)
        if out: pg.screenshot(path=f'{out}/pdetails_{theme}_{w}.png')
        if (who, w) == ('admin', 1366):
            for path in LISTS:
                pg.goto(BASE + path, wait_until='domcontentloaded'); time.sleep(.8)
                q = pg.evaluate("""() => { const g = [...document.querySelectorAll('.input-group')].find(x => x.querySelector('input[type=search], input[name=q]') && x.querySelector('.input-group-append') && x.offsetParent); if (!g) return null;
                    const bs = [...g.querySelectorAll('.input-group-append > .btn')]; const last = getComputedStyle(bs[bs.length - 1]); const inner = bs.slice(0, -1).map(x => getComputedStyle(x).borderTopRightRadius);
                    const inp = getComputedStyle(g.querySelector('.form-control'));
                    return {outer: parseFloat(last.borderTopRightRadius), inner, inputRight: inp.borderTopRightRadius, heights: [g.querySelector('.form-control'), ...bs].map(x => Math.round(x.getBoundingClientRect().height))} }""")
                if q is None: continue
                check(f'search bar {path.split("?")[0]}: outer corner rounded, joins flat, equal heights', q['outer'] >= 4 and all(float(i[:-2]) == 0 for i in q['inner']) and float(q["inputRight"][:-2]) == 0 and max(q['heights']) - min(q['heights']) <= 1, q)
        b.close()
# drag and drop a QA-UI- task between lanes
b, ctx, pg = login(p, 'admin'); pg.set_viewport_size({'width': 1920, 'height': 1080})
pg.goto(BASE + '/agent/project_kanban.php?client_id=16&project_id=4', wait_until='domcontentloaded'); time.sleep(1.5)
card = pg.locator('.kanban-column[data-status="To Do"] .task', has_text='QA-UI-200371').first
src = card.bounding_box(); dst = pg.locator('.kanban-column[data-status="Blocked"] .kanban-status').bounding_box()
pg.mouse.move(src['x'] + 40, src['y'] + 20); pg.mouse.down(); pg.mouse.move(src['x'] + 200, src['y'] + 40, steps=8); pg.mouse.move(dst['x'] + 100, dst['y'] + 40, steps=12); time.sleep(.3); pg.mouse.up(); time.sleep(1.5)
pg.reload(wait_until='domcontentloaded'); time.sleep(1.2)
moved = pg.locator('.kanban-column[data-status="Blocked"] .task', has_text='QA-UI-200371').count()
check('drag and drop moves a QA-UI- task to another lane and it persists', moved == 1)
if moved:
    card = pg.locator('.kanban-column[data-status="Blocked"] .task', has_text='QA-UI-200371').first; src = card.bounding_box(); dst = pg.locator('.kanban-column[data-status="To Do"] .kanban-status').bounding_box()
    pg.mouse.move(src['x'] + 40, src['y'] + 20); pg.mouse.down(); pg.mouse.move(src['x'] + 100, src['y'] + 60, steps=8); pg.mouse.move(dst['x'] + 100, dst['y'] + 40, steps=12); time.sleep(.3); pg.mouse.up(); time.sleep(1.5)
b.close()
print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
