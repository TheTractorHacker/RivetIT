"""Submit every admin form: (a) page-level settings forms saved with their current values, (b) every New/Add modal filled with QA-UI- data.
Skips anything that sends mail, calls an external service, deletes/resets, backs up or updates. Reports HTTP status of the POST and the flash message."""
from ux_common import *
import re
BLOCK=re.compile(r'backup|update|cron|send|test|sync|import|restore|reset|purge|delete|archive|remove|wipe|mailbox|oauth|payment_provider|ai_provider|identity|sso|webhook_test|download|export|install|db_|database|maintenance|queue|connect|authorize|revoke|logout|clear|license|encrypt|decrypt|rotate|disable|enable_mfa|passkey',re.I)
paths=[l.strip() for l in open(os.environ.get('QA_PAGES', os.path.join(HERE,'pages_admin.txt'))) if l.strip()]
if len(sys.argv)>2: paths=paths[int(sys.argv[1]):int(sys.argv[2])]
p,b,ctx,pg=start(who='admin')
res=[]; posts=[]
pg.on('response', lambda r: posts.append((r.status, r.url[-50:])) if r.request.method=='POST' else None)
errs=[]; pg.on('pageerror', lambda e: errs.append(str(e)[:90]))
def flash(): return [t.strip()[:100] for t in pg.locator('.toast, .alert:visible').all_text_contents() if t.strip()][:1]
FILL="""()=>{const m=document.querySelector('.modal.show'); if(!m) return 0; let n=0;
 m.querySelectorAll('input,textarea,select').forEach(e=>{ if(e.disabled||e.type==='hidden'||e.type==='file'||e.type==='submit'||e.type==='button') return;
  if(e.type==='checkbox'){ if(/send|email|notify|invite/i.test(e.name+e.id)) e.checked=false; else if(e.required) e.checked=true; return;}
  if(e.type==='radio') return;
  if(!e.required && e.tagName!=='SELECT') return;
  if(e.tagName==='SELECT'){ if(!e.required) return; const o=[...e.options].find(o=>o.value&&o.value!=='0'); if(o){ if(e.tomselect) e.tomselect.setValue(o.value); else e.value=o.value; } return;}
  if(e.type==='email') e.value='qa-ui-form@example.test'; else if(e.type==='url') e.value='https://qa-ui.example.test'; else if(e.type==='number') e.value=e.min||'1'; else if(e.type==='password') e.value='QaUi#Form2026xyz!'; else if(e.type==='datetime-local') e.value=(/end/i.test(e.name)?'2026-10-28T11:00':'2026-10-28T10:00'); else if(e.type==='date') e.value='2026-12-31'; else if(e.type==='time') e.value='09:00'; else if(e.type==='color') e.value='#336699'; else if(e.type==='tel') e.value='6085550100'; else e.value='QA-UI- form '+(e.name||e.id).slice(0,20);
  n++; });
 return n}"""
for path in paths:
    r={'path':path,'forms':[],'modals':[]}
    try:
        pg.goto(BASE+path,timeout=25000); pg.wait_for_load_state('load'); time.sleep(.5)
        # (a) page forms saved as-is
        nforms=pg.evaluate("[...document.querySelectorAll('main form')].filter(f=>f.method==='post' && !f.closest('.modal')).length")
        for i in range(min(nforms,6)):
            pg.goto(BASE+path); pg.wait_for_load_state('load'); time.sleep(.4)
            info=pg.evaluate("""(i)=>{const f=[...document.querySelectorAll('main form')].filter(f=>f.method==='post'&&!f.closest('.modal'))[i]; if(!f) return null; const b=[...f.querySelectorAll('button[type=submit],input[type=submit]')]; return b.map(x=>({n:x.name,t:(x.textContent||x.value||'').trim().slice(0,30),vis:!!x.offsetParent}))}""",i)
            if not info: continue
            cand=[x for x in info if x['vis'] and not BLOCK.search(x['n']+' '+x['t'])]
            if not cand: r['forms'].append((i,'skipped',[x['n'] for x in info][:2])); continue
            target=cand[0]; posts.clear(); errs.clear()
            sel=f"main form >> nth={i}"
            # invalid required (e.g. empty secret) -> report not submittable
            ok=pg.evaluate("(i)=>{const f=[...document.querySelectorAll('main form')].filter(f=>f.method==='post'&&!f.closest('.modal'))[i]; return f.checkValidity()}",i)
            if not ok: r['forms'].append((i,'invalid-as-is',target['n'])); continue
            pg.evaluate("""([i,n])=>{const f=[...document.querySelectorAll('main form')].filter(f=>f.method==='post'&&!f.closest('.modal'))[i]; const b=[...f.querySelectorAll('button[type=submit],input[type=submit]')].find(x=>x.name===n&&x.offsetParent)||f.querySelector('button[type=submit]'); b.click()}""",[i,target['n']])
            time.sleep(1.8)
            st=[s for s,_ in posts]; r['forms'].append((i,target['n'] or target['t'],st[:2],flash(),errs[:1]))
        # (b) add modals
        pg.goto(BASE+path); pg.wait_for_load_state('load'); time.sleep(.4)
        trig=pg.evaluate("""[...document.querySelectorAll('main .ajax-modal')].filter(e=>e.offsetParent && /^\\s*(new|add|create)\\b/i.test(e.textContent.trim()) ).map((e,i)=>[i,e.getAttribute('data-modal-url')||''])""")
        for idx,url in trig[:5]:
            if BLOCK.search(url): r['modals'].append((url[-45:],'skipped')); continue
            pg.goto(BASE+path); pg.wait_for_load_state('load'); time.sleep(.4)
            els=pg.locator("main .ajax-modal:visible").filter(has_text=re.compile(r'^\s*(new|add|create)',re.I))
            if els.count()<=idx: continue
            els.nth(idx).click(); time.sleep(1.3)
            if not pg.locator('.modal.show').count(): r['modals'].append((url[-45:],'NO MODAL')); continue
            filled=pg.evaluate(FILL); posts.clear(); errs.clear()
            sub=pg.locator('.modal.show button[type=submit]').first
            if not sub.count() or BLOCK.search((sub.get_attribute('name') or '')+' '+sub.inner_text()): r['modals'].append((url[-45:],'no safe submit')); pg.keyboard.press('Escape'); continue
            sub.click(); time.sleep(2.2)
            st=[s for s,_ in posts]; still=pg.locator('.modal.show').count()
            r['modals'].append((url[-45:],filled,st[:2],'modal-open' if still else 'closed',flash(),errs[:1]))
    except Exception as e: r['error']=str(e)[:120]
    res.append(r)
    bad=[x for x in r['forms']+r['modals'] if any(isinstance(v,list) and any(s>=400 for s in v if isinstance(s,int)) for v in x if isinstance(x,tuple))] or r.get('error')
    print(('FAIL ' if bad else 'ok   ')+path, json.dumps({k:v for k,v in r.items() if k!='path' and v})[:420] if bad else f"forms={len(r['forms'])} modals={len(r['modals'])}", flush=True)
    json.dump(res,open(f'{OUT}/admin_forms_{sys.argv[1] if len(sys.argv)>2 else "all"}.json','w'),indent=1)
b.close()
