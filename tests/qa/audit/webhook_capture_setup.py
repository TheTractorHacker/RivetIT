"""Create a loopback webhook (QA-UI- capture) subscribed to the change events, through the real wizard (generic JSON)."""
import sys, os, glob, time
PY = glob.glob('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/*/scratchpad/pylibs')[0]
sys.path.insert(0, PY); sys.path.insert(1, '/home/sysadmin/.local/lib/python3.12/site-packages'); sys.path.insert(2, os.path.dirname(__file__))
from playwright.sync_api import sync_playwright
from common import *
with sync_playwright() as p:
    b = p.chromium.launch(); ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
    pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', 'qa.admin2@summitridge.example'); pg.fill('input[name=password]', ADMIN[1]); pg.locator('button[type=submit]').first.click(); pg.wait_for_url(lambda u: 'login' not in u)
    pg.goto(BASE + '/admin/webhook_new.php?dest=generic-json&step=connect', wait_until='networkidle')
    st = lambda: pg.evaluate("[...document.querySelectorAll('[data-wz-step-panel]')].filter(e=>!e.hidden).map(e=>e.dataset.wzStepPanel)")
    pg.fill('[data-wz-step-panel="connect"] input[name=webhook_name]', 'QA-UI- capture')
    pg.locator('[data-wz-step-panel="connect"] input[type=url], [data-wz-step-panel="connect"] input[name*=url]').first.fill('http://127.0.0.1:8997/hook')
    pg.locator('[data-wz-next]').first.click(); time.sleep(.6); print('step', st())
    n = pg.evaluate("""()=>{let n=0;for(const ev of ['rmm.change.detected','rmm.change.rule_matched']){document.querySelectorAll('input[value="'+ev+'"]').forEach(e=>{e.checked=true;e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));n++})}return n}""")
    print('event inputs ticked', n)
    pg.locator('[data-wz-next]').first.click(); time.sleep(.6); print('step', st())
    pg.screenshot(path=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'evidence/wz_review_light_1280.jpg'), type='jpeg', quality=55)
    sv = pg.locator('[data-wz-step-panel="review"] button[type=submit], button:has-text("Save webhook"), button:has-text("Create webhook"), button:has-text("Save")').first
    print('save button:', sv.count() and sv.inner_text()[:30]); sv.click(); time.sleep(1.8); print('after', pg.url.split('8196')[-1])
    b.close()
print(sql("select webhook_id, webhook_name, webhook_enabled from webhooks"))
