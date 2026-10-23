from ux_common import *
src=open(os.path.join(HERE,'admin_forms.py')).read(); FILL=src.split('FILL="""')[1].split('"""')[0]
p,b,ctx,pg=start(who='admin')
posts=[]; pg.on('response', lambda r: posts.append(r.status) if r.request.method=='POST' else None)
def flash(): return [t.strip()[:90] for t in pg.locator('.toast, .alert:visible').all_text_contents() if t.strip()][:1]
pg.goto(BASE+'/agent/calendar.php'); pg.wait_for_load_state('load'); time.sleep(1.5)
pg.screenshot(path=f'{OUT}/calendar.png')
print('add controls:', [t.strip()[:25] for t in pg.locator('main button:visible, main a.btn:visible').all_text_contents() if t.strip()][:14])
pg.locator('.fc-daygrid-day').nth(20).click(); time.sleep(1.4)
print('modal after day click:', pg.locator('.modal.show').count(), pg.locator('.modal.show .modal-title').all_text_contents()[:1])
if pg.locator('.modal.show').count()==0:
    x=pg.locator("button:has-text('New Event'), .fc-addEvent-button, button:has-text('Add event'), button:has-text('New event')").first
    if x.count(): x.click(); time.sleep(1.3)
print('modal:', pg.locator('.modal.show').count())
if pg.locator('.modal.show').count():
    print('filled', pg.evaluate(FILL))
  
    print('invalid:', pg.evaluate("[...document.querySelectorAll('.modal.show :invalid')].map(e=>e.name+':'+e.type+':'+e.validationMessage)"))
    pg.screenshot(path=f'{OUT}/calendar_modal.png')
    posts.clear(); pg.locator('.modal.show button[type=submit]').first.click(); time.sleep(2.5); print('create', posts[:2], flash())
    pg.goto(BASE+'/agent/calendar.php'); pg.wait_for_load_state('load'); time.sleep(1.5)
    ev=pg.locator(".fc-event:has-text('QA-UI- form title')").first; print('event visible', ev.count())
    if ev.count():
        ev.click(); time.sleep(1.3); pg.screenshot(path=f'{OUT}/calendar_event.png')
        pg.evaluate("()=>{const e=document.querySelector('.modal.show input[name=name]'); if(e) e.value='QA-UI- form title edited'}")
        posts.clear(); pg.locator('.modal.show button[type=submit]').first.click(); time.sleep(2.2); print('edit', posts[:2], flash())
        pg.goto(BASE+'/agent/calendar.php'); pg.wait_for_load_state('load'); time.sleep(1.5)
        pg.locator(".fc-event:has-text('QA-UI- form title edited')").first.click(); time.sleep(1.3)
        print('delete controls', [t.strip()[:20] for t in pg.locator('.modal.show a:visible, .modal.show button:visible').all_text_contents() if t.strip()][:8])
b.close()
