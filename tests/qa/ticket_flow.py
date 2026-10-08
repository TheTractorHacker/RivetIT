import sys, json, time
from common import *
ed = sys.argv[1]; tag = f'QA-{ed.upper()}-TICKET-{int(time.time())%100000}'
r = {}
p, b, ctx, pg, base = session(ed)
errs = []; pg.on('pageerror', lambda e: errs.append(str(e)[:120]))
pg.on('console', lambda m: errs.append(m.text[:120]) if m.type == 'error' else None)
pg.goto(base + '/agent/tickets.php', wait_until='domcontentloaded')
# 1. empty submit must not create anything
pg.get_by_text('New Ticket', exact=True).first.click(); pg.wait_for_selector('.modal.show [name=subject]')
before = pg.evaluate("location.href")
pg.locator('.modal.show button[name=add_ticket]').click(); pg.wait_for_timeout(800)
r['empty_blocked'] = pg.locator('.modal.show').count() == 1
# 2. valid create with unicode + markup in subject
subject = f'{tag} Ünïcödé 日本語 <b>bold</b> & "quotes"'
pg.fill('.modal.show [name=subject]', subject)
pg.fill('.modal.show [name=details]', 'QA details') if pg.locator('.modal.show textarea[name=details]').is_visible() else None
pick(pg, 'client_id', index=1); pg.wait_for_timeout(1200)
pg.locator('.modal.show button[name=add_ticket]').click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(800)
r['after_create_url'] = pg.url[len(base):]
r['title'] = pg.title()[:80]
body = pg.inner_text('body')
r['subject_rendered_literally'] = '<b>bold</b>' in body      # escaped = literal tags visible
r['bold_injected'] = pg.locator('b:text-is("bold")').count() > 0
# 3. persists in list after refresh
pg.goto(base + '/agent/tickets.php?q=' + tag, wait_until='domcontentloaded')
r['in_list'] = tag in pg.inner_text('body')
r['errs'] = errs[:5]
print(json.dumps(r, indent=1))
pg.screenshot(path=f'{ed}_ticket_after.png'); b.close(); p.stop()
