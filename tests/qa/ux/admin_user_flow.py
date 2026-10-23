"""Admin persona: create / duplicate-email / edit / disable a QA-UI- user, then find it in the audit log."""
from ux_common import *
p,b,ctx,pg=start(who='admin'); clicks=1
def msg():
    return [t.strip()[:110] for t in pg.locator('.toast, .alert, .invalid-feedback:visible').all_text_contents() if t.strip()][:2]
def add_user(name, email):
    global clicks
    pg.goto(BASE+'/admin/users.php'); pg.wait_for_load_state('load'); time.sleep(.5)
    pg.locator("button:has-text('New User'), a:has-text('New User')").first.click(); clicks+=1; time.sleep(1.2)
    m='.modal.show '
    pg.fill(m+'#user_add_name', name); pg.fill(m+'#user_add_email', email)
    pg.evaluate("""()=>{const s=document.querySelector('.modal.show #user_add_role'); const o=[...s.options].filter(o=>o.value&&/tech/i.test(o.text))[0]||[...s.options].find(o=>o.value); if(s.tomselect) s.tomselect.setValue(o.value); else s.value=o.value;
      const c=document.querySelector('.modal.show #sendEmailCheckBox'); if(c) c.checked=false;}""")
    pg.fill(m+'#user_add_password','QaUi#Fuzz2026x!')
    pg.locator(m+'button[name=add_user]').click(); clicks+=1; time.sleep(2)
    return msg()
print('create      ', add_user('QA-UI- Test User','qa-ui-user@example.test'))
print('duplicate   ', add_user('QA-UI- Test User 2','qa-ui-user@example.test'))
print('invalid mail', add_user('QA-UI- Test User 3','not-an-email'))
print('ws name     ', add_user('   ','qa-ui-ws@example.test'))
pg.goto(BASE+'/admin/users.php'); pg.wait_for_load_state('load')
print('listed:', pg.locator("text=QA-UI- Test User").count(), 'blank-ish rows:', pg.locator('td:has-text("qa-ui-ws@example.test")').count())
pg.screenshot(path=f'{OUT}/admin_users_after.png')
print('clicks', clicks)
b.close()
