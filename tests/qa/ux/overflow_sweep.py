"""Horizontal-overflow sweep at a given viewport. usage: overflow_sweep.py WxH pages-file [label]"""
from ux_common import *
w,h=map(int,sys.argv[1].split('x')); paths=[l.strip() for l in open(sys.argv[2]) if l.strip()]
p,b,ctx,pg=start(w,h,'admin')
bad=[]
for path in paths:
    try:
        pg.goto(BASE+path,timeout=20000); pg.wait_for_load_state('load'); time.sleep(.5)
        r=pg.evaluate("""()=>{const de=document.documentElement; const over=de.scrollWidth-de.clientWidth; if(over<=2) return null;
          const clipped=e=>{for(let a=e.parentElement;a&&a!==document.body;a=a.parentElement){const o=getComputedStyle(a).overflowX; if(o==='auto'||o==='hidden'||o==='scroll') return true} return false}; const off=[...document.querySelectorAll('body *')].filter(e=>{const r=e.getBoundingClientRect(); return r.right>de.clientWidth+2 && r.width>0 && getComputedStyle(e).position!=='fixed' && !clipped(e)}).slice(0,3).map(e=>e.tagName+'.'+(e.className||'').toString().slice(0,40)+' right='+Math.round(e.getBoundingClientRect().right));
          return {over, off}}""")
        if r: bad.append((path,r)); print('OVERFLOW',path,r,flush=True)
    except Exception as e: print('ERR',path,str(e)[:60])
print(f'{w}x{h}: {len(paths)} pages, {len(bad)} overflow')
json.dump(bad,open(f'{OUT}/overflow_{w}x{h}.json','w'))
b.close()
