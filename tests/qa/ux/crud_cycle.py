"""Create / edit / delete (or archive) cycles on QA-UI- records: software licence, project, recurring ticket, calendar event."""
from ux_common import *
import re
exec(open(os.path.join(HERE,'admin_forms.py')).read().split('FILL="""')[1].join(['FILL="""','']).split('for path in paths')[0]) if False else None
src=open(os.path.join(HERE,'admin_forms.py')).read(); FILL=src.split('FILL="""')[1].split('"""')[0]
p,b,ctx,pg=start(who='admin')
posts=[]; pg.on('response', lambda r: posts.append(r.status) if r.request.method=='POST' else None)
errs=[]; pg.on('pageerror', lambda e: errs.append(str(e)[:90]))
def flash(): return [t.strip()[:90] for t in pg.locator('.toast, .alert:visible').all_text_contents() if t.strip()][:1]
def rowcount(name): return pg.locator(f"main tbody tr:has-text('{name}')").count()
def add(page, btn, name):
    pg.goto(BASE+page); pg.wait_for_load_state('load'); time.sleep(.6)
    pg.locator(f"main .ajax-modal:has-text('{btn}')").first.click(); time.sleep(1.3)
    pg.evaluate(FILL)
    pg.evaluate("(n)=>{const m=document.querySelector('.modal.show'); const e=m.querySelector('input[name=name],input[name=title],input[name=subject]'); if(e) e.value=n}", name)
    posts.clear(); pg.locator('.modal.show button[type=submit]').first.click(); time.sleep(2.2)
    return posts[:2], flash(), 'modal-open' if pg.locator('.modal.show').count() else 'closed'
def edit(page, name, newname):
    pg.goto(BASE+page); pg.wait_for_load_state('load'); time.sleep(.6)
    row=pg.locator(f"main tbody tr:has-text('{name}')").first
    row.locator("a.dropdown-item:has-text('Edit')").first.evaluate("e=>e.click()"); time.sleep(1.3)
    pg.evaluate("(n)=>{const m=document.querySelector('.modal.show'); const e=m.querySelector('input[name=name],input[name=title],input[name=subject]'); e.value=n}", newname)
    posts.clear(); pg.locator('.modal.show button[type=submit]').first.click(); time.sleep(2.2)
    return posts[:2], flash()
def remove(page, name, word):
    pg.goto(BASE+page); pg.wait_for_load_state('load'); time.sleep(.6)
    row=pg.locator(f"main tbody tr:has-text('{name}')").first
    row.locator(f"a.dropdown-item:has-text('{word}')").first.evaluate("e=>e.click()"); time.sleep(1)
    c=pg.locator('.modal.show button:has-text("Yes"), .modal.show button.btn-danger, .modal.show button:has-text("Confirm"), .modal.show button:has-text("Archive"), .modal.show button:has-text("Delete"), button:has-text("Yes"):visible').last
    if c.count() and c.is_visible(): c.click()
    time.sleep(2.2); return flash()
def cycle(label, page, btn, base, rm):
    try:
        n='QA-UI- '+base
        a=add(page,btn,n); print(label,'create',a,'row visible:',rowcount(n))
        e=edit(page,n,n+' edited'); print(label,'edit',e,'row visible:',rowcount(n+' edited'))
        if rm: d=remove(page,n+' edited',rm); print(label,'remove',d,'row still listed:',rowcount(n+' edited'))
    except Exception as ex: print(label,'ERROR',str(ex)[:160])
cycle('software','/agent/software.php','New License','licence','Archive')
cycle('project','/agent/projects.php','New Project','project',None)
cycle('recurring ticket','/agent/recurring_tickets.php','New Recurring Ticket','recurring','Delete')
b.close()
