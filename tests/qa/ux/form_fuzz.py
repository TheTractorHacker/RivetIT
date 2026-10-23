"""Open an Add modal, fill the first name-like required field with fuzz values, submit, report outcome.
usage: form_fuzz.py <page> <button text> [valueset]"""
from ux_common import *
page, btn = sys.argv[1], sys.argv[2]
VALS = {'ws': '   ', 'tabs': '\t\t', 'long': 'QA-UI-' + 'x' * 400, 'ok': 'QA-UI- fuzz ok'}
p,b,ctx,pg=start(who='admin')
for key in (sys.argv[3].split(',') if len(sys.argv)>3 else ['ws','long','ok']):
    pg.goto(BASE+page); pg.wait_for_load_state('load'); time.sleep(.6)
    posts=[]
    pg.on('response', lambda r: posts.append((r.request.method, r.status, r.url[-60:])) if r.request.method=='POST' else None)
    pg.locator(f"a:has-text('{btn}'), button:has-text('{btn}')").first.click(); time.sleep(1.2)
    modal = pg.locator('.modal.show')
    if modal.count()==0: print(key,'NO MODAL'); continue
    # fill required controls
    info = pg.evaluate("""(v) => {
      const m=document.querySelector('.modal.show'); const out=[];
      let named=false;
      m.querySelectorAll('input[required],textarea[required],select[required]').forEach(e=>{
        if(e.type==='hidden'||e.disabled) return;
        if(e.tagName==='SELECT'){ const o=[...e.options].find(o=>o.value&&o.value!=='0'); if(o){ if(e.tomselect) e.tomselect.setValue(o.value); else e.value=o.value; } return;}
        if(e.type==='email') e.value='qa-ui-fuzz@example.test'; else if(e.type==='url') e.value='https://example.test'; else if(e.type==='number') e.value='1'; else if(e.type==='password') e.value='QaUi#Fuzz2026x!';
        else if(!named && /name|title|subject/i.test(e.name+e.id)) { e.value=v; named=true; out.push(e.name); }
        else if(e.type==='text'||e.tagName==='TEXTAREA') e.value='QA-UI- x';
      });
      return out; }""", VALS[key])
    pg.locator('.modal.show button[type=submit]').first.click(); time.sleep(2.5)
    toast = pg.locator('.toast, .alert, .invalid-feedback:visible, .iziToast, #toast-container').all_text_contents()
    print(f"{key:5} fields={info} still_modal={pg.locator('.modal.show').count()} posts={posts[-2:]} msg={[t.strip()[:90] for t in toast if t.strip()][:2]} url={pg.url[-50:]}")
    pg.screenshot(path=f"{OUT}/fuzz_{page.strip('/').replace('/','_')}_{key}.png")
b.close()
