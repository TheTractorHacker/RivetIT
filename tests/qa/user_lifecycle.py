"""Create tech -> login -> technician escalation POST -> admin Disable -> login blocked. usage: user_lifecycle.py <it|msp>"""
import sys, json, time, re
from common import *
ed = sys.argv[1]; pw = 'QaPass#2026xyz'; r = {}; n = int(time.time()) % 100000
email = f'qa-{ed}-life-{n}@qa.example'; name = f'QA-{ed.upper()}-LIFE-{n}'
p, b, ctx, pg, base = session(ed)
pg.goto(base + '/admin/users.php', wait_until='domcontentloaded'); pg.get_by_text('New User', exact=True).first.click(); pg.wait_for_selector('.modal.show [name=name]')
m = '.modal.show '; pg.fill(m+'[name=name]', name); pg.fill(m+'[name=email]', email); pg.fill(m+'[name=password]', pw)
pg.evaluate("()=>{const s=document.querySelector('.modal.show select[name=role]');const o=[...s.options].find(o=>o.text.includes('Technician'));s.tomselect?s.tomselect.setValue(o.value):s.value=o.value}")
pg.locator(m+'button[name=add_user]').click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(800)
pg.goto(base + '/admin/users.php', wait_until='domcontentloaded')
uid = pg.locator(f'tr:has-text("{name}") a[href*="disable_user="]').first.get_attribute('href'); uid = re.search(r'disable_user=(\d+)', uid).group(1); r['user_id'] = uid
def login(e=email):
    c = b.new_context(ignore_https_errors=True); t = c.new_page()
    t.goto(base + '/login.php'); t.fill('input[name=email]', e); t.fill('input[name=password]', pw)
    t.locator('button[type=submit]').first.click(); t.wait_for_load_state('domcontentloaded'); t.wait_for_timeout(600)
    return c, t
c, t = login(); r['login_ok'] = '/agent/' in t.url
t.goto(base + '/agent/tickets.php', wait_until='domcontentloaded'); csrf = t.evaluate("document.querySelector('[name=csrf_token]')?.value")
resp = t.request.post(base + '/admin/post.php', form={'add_user': '1', 'name': 'QA-ESCALATION', 'email': f'qa-esc-{ed}@qa.example', 'role': '3', 'password': 'Zz#12345678abc', 'csrf_token': csrf or ''}, headers={'Referer': base + '/admin/users.php'})
r['escalation_post'] = [resp.status, resp.text()[:80].replace('\n', ' ')]
resp = t.request.get(base + f'/admin/post.php?disable_user=1&csrf_token={csrf}', headers={'Referer': base + '/admin/users.php'}); r['escalation_disable_admin'] = [resp.status, resp.text()[:60].replace('\n',' ')]
# admin disables via UI
tr = pg.locator(f'tr:has(a[href*="disable_user={uid}&"])').first; tr.locator('[data-bs-toggle=dropdown]').first.click(); tr.locator('a.dropdown-item', has_text='Disable').first.click(); pg.wait_for_timeout(1200)
btn = pg.locator('.modal.show :text-is("Yes"), .modal.show .btn-danger'); r['confirm_dialog'] = btn.count() > 0
if btn.count(): btn.first.click()
pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(800)
# existing session of the disabled user
t.goto(base + '/agent/tickets.php', wait_until='domcontentloaded'); r['live_session_after_disable'] = t.url[len(base):]
c.close(); c2, t2 = login(); r['login_after_disable'] = [t2.url[len(base):], ' | '.join(t2.locator('.alert,.text-danger').all_inner_texts())[:80]]
b.close(); p.stop(); print(json.dumps(r, indent=1))
