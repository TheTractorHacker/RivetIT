"""New Asset with bad IP / MAC: must be rejected with a message (not stored)."""
from ux_common import *
p,b,ctx,pg=start(who='admin')
def go(ip,mac,name):
    pg.goto(BASE+'/agent/assets.php'); pg.wait_for_load_state('load'); time.sleep(.5)
    pg.locator("a:has-text('New Asset'), button:has-text('New Asset')").first.click(); time.sleep(1.3)
    pg.evaluate("""([n,ip,mac])=>{const m=document.querySelector('.modal.show');
      m.querySelectorAll('input[required],select[required]').forEach(e=>{ if(e.tagName==='SELECT'){const o=[...e.options].find(o=>o.value&&o.value!=='0'); if(e.tomselect) e.tomselect.setValue(o.value); else e.value=o.value;} });
      m.querySelector('[name=name]').value=n; const i=m.querySelector('[name=ip]'); i.value=ip; m.querySelector('[name=mac]').value=mac;
      m.querySelectorAll('[data-inputmask]').forEach(x=>{ if(x.inputmask) x.inputmask.remove(); }); m.querySelector('[name=ip]').value=ip; m.querySelector('[name=mac]').value=mac; }""",[name,ip,mac])
    pg.locator('.modal.show button[type=submit]').first.click(); time.sleep(2.2)
    return [t.strip()[:120] for t in pg.locator('.toast, .alert').all_text_contents() if t.strip()][:1]
print('bad ip ', go('999.1.1.1','','QA-UI- asset badip'))
print('bad mac', go('10.9.9.9','zz:zz','QA-UI- asset badmac'))
print('good   ', go('10.9.9.9','00:1A:2B:3C:4D:5E','QA-UI- asset good'))
b.close()
