"""User management + role isolation. usage: user_roles.py <it|msp>"""
import sys, json, time
from common import *
ed = sys.argv[1]; r = {}; n = int(time.time()) % 100000
email = f'qa-{ed}-user-{n}@qa.example'; pw = 'QaPass#2026xyz'
p, b, ctx, pg, base = session(ed)
def add_user(name, em, pw_, role_text):
    pg.goto(base + '/admin/users.php', wait_until='domcontentloaded'); pg.get_by_text('New User', exact=True).first.click()
    pg.wait_for_selector('.modal.show [name=name]'); m = '.modal.show '
    pg.fill(m+'[name=name]', name); pg.fill(m+'[name=email]', em); pg.fill(m+'[name=password]', pw_)
    r_opts = pg.evaluate("()=>[...document.querySelector('.modal.show select[name=role]').options].map(o=>o.text.trim())")
    pg.evaluate("t=>{const s=document.querySelector('.modal.show select[name=role]');const o=[...s.options].find(o=>o.text.includes(t));if(s.tomselect)s.tomselect.setValue(o.value);else s.value=o.value}", role_text)
    pg.locator(m+'button[name=add_user]').click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(800)
    return r_opts
r['role_options'] = add_user(f'QA-{ed.upper()}-USER-{n}', email, pw, 'Technician')
r['created_listed'] = email in (pg.goto(base + '/admin/users.php', wait_until='domcontentloaded') and pg.inner_text('body'))
# duplicate email
add_user('Dup', email, pw, 'Technician'); body = pg.inner_text('body')
r['dup_rejected_msg'] = [l for l in body.split('\n') if 'already' in l.lower() or 'exist' in l.lower()][:2]
pg.goto(base + '/admin/users.php', wait_until='domcontentloaded'); r['dup_count'] = pg.inner_text('body').count(email)
# login as the new tech in a clean context
base2, _ = EDITIONS[ed]
b2 = b.new_context(ignore_https_errors=True); t = b2.new_page()
t.goto(base + '/login.php'); t.fill('input[name=email]', email); t.fill('input[name=password]', pw); t.locator('button[type=submit]').first.click(); t.wait_for_load_state('domcontentloaded'); t.wait_for_timeout(800)
r['tech_login_url'] = t.url[len(base):]
res = {}
for path in ('/agent/tickets.php', '/agent/dashboard.php', '/admin/users.php', '/admin/settings_company.php', '/admin/post.php'):
    resp = t.goto(base + path, wait_until='domcontentloaded'); txt = t.inner_text('body')[:200].replace('\n', ' ')
    res[path] = [resp.status, t.url[len(base):], txt[:80]]
r['tech_access'] = res
# admin archives the tech -> login must fail
pg.goto(base + '/admin/users.php', wait_until='domcontentloaded')
row = pg.locator('tr', has_text=email).first
r['row_actions'] = row.locator('a,button').evaluate_all('e=>e.map(x=>(x.innerText||x.title||x.getAttribute("data-bs-target")||"").trim().slice(0,20))')
b.close(); p.stop()
print(json.dumps(r, indent=1, ensure_ascii=False))
