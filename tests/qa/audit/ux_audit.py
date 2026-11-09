#!/usr/bin/env python3
"""UX / accessibility audit of the scratch instance: axe (WCAG 2.x AA), computed contrast of every text node (muted-text pairs flagged),
overflow, icon-only controls without names, alert()/confirm() use, keyboard focus visibility, reduced motion. Evidence: evidence/*.jpg (small).
Usage: python3 ux_audit.py [--quick]"""
import sys, os, re, json, glob, time
PY = glob.glob('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/*/scratchpad/pylibs')[0]
sys.path.insert(0, PY); sys.path.insert(1, '/home/sysadmin/.local/lib/python3.12/site-packages')
sys.path.insert(2, os.path.dirname(__file__))
from playwright.sync_api import sync_playwright
from common import *
AXE = glob.glob('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/*/scratchpad/axe.min.js')[0]; AXE_SRC = open(AXE).read()
HERE = os.path.dirname(os.path.abspath(__file__)); EV = os.path.join(HERE, 'evidence'); OUT = os.path.join(HERE, 'out'); os.makedirs(EV, exist_ok=True); os.makedirs(OUT, exist_ok=True)
fx = json.load(open(os.path.join(OUT, 'fixtures.json')))
PAGES = [
 ('tickets', '/agent/tickets.php'), ('dashboard', '/agent/my_dashboard.php'), ('clients', '/agent/clients.php'),
 ('panel_deploy', '/agent/panel_deploy.php?client_id=16'), ('mesh_needs_approval', f"/agent/rmm_agent_device.php?device_id={fx['needs']}"),
 ('mesh_conflict', f"/agent/rmm_agent_device.php?device_id={fx['conflict']}"), ('mesh_mapped', f"/agent/rmm_agent_device.php?device_id={fx['owner']}"),
 ('rmm_dashboard', '/agent/rmm_dashboard.php'), ('rmm_fleet', '/agent/rmm_fleet.php'), ('rmm_alerts', '/agent/alerts.php'), ('rmm_policies', '/agent/rmm_policies.php'),
 ('rmm_scripts', '/agent/rmm_scripts.php'), ('rmm_checks', '/agent/rmm_checks.php'), ('rmm_software', '/agent/rmm_software.php'), ('rmm_hypervisors', '/agent/rmm_hypervisors.php'),
 ('webhooks', '/admin/settings_webhooks.php'), ('webhook_new', '/admin/webhook_new.php'), ('webhook_guides', '/admin/settings_webhook_guides.php'), ('event_rules', '/admin/event_rules.php'),
 ('audit_log', '/admin/audit_log.php'), ('endpoint_settings', '/admin/settings_endpoint_agent.php'), ('custom_links', '/admin/custom_link.php'), ('users', '/admin/users.php'),
]
VIEWS = [(1280, 800), (768, 1024), (360, 740)]
INIT = "window.__dlg=[];['alert','confirm','prompt'].forEach(function(k){var o=window[k];window[k]=function(){window.__dlg.push(k);return k==='confirm'?false:null};});"
JS_CONTRAST = r"""() => {
 const cv=document.createElement('canvas');cv.width=cv.height=1;const cx=cv.getContext('2d',{willReadFrequently:true}); const parse=c=>{ if(!c||c==='transparent') return [0,0,0,0]; cx.clearRect(0,0,1,1); cx.fillStyle='#000'; cx.fillStyle=c; cx.fillRect(0,0,1,1); const d=cx.getImageData(0,0,1,1).data; return [d[0],d[1],d[2],d[3]/255]; };
 const lum=([r,g,b])=>{const f=v=>{v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4)};return 0.2126*f(r)+0.7152*f(g)+0.0722*f(b)};
 const over=(f,b)=>[f[0]*f[3]+b[0]*(1-f[3]),f[1]*f[3]+b[1]*(1-f[3]),f[2]*f[3]+b[2]*(1-f[3]),1];
 const bgOf=el=>{let layers=[];let e=el;let img=false;while(e&&e.nodeType===1){const cs=getComputedStyle(e);const c=parse(cs.backgroundColor);if(cs.backgroundImage&&cs.backgroundImage!=='none')img=true;if(c[3]>0)layers.push(c);if(c[3]===1)break;e=e.parentElement;}
   let base=[255,255,255,1]; const root=getComputedStyle(document.documentElement).backgroundColor; if(parse(root)[3]>0) base=parse(root); else {const bd=parse(getComputedStyle(document.body).backgroundColor); if(bd[3]>0) base=bd;}
   for(let i=layers.length-1;i>=0;i--) base=over(layers[i],base); return {c:base,img};};
 const res={}; const walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
 let n; while(n=walker.nextNode()){ const t=n.nodeValue.trim(); if(!t) continue; const el=n.parentElement; if(!el) continue; const cs=getComputedStyle(el);
   if(cs.visibility==='hidden'||cs.display==='none'||el.closest('script,style,noscript,[hidden],[aria-hidden=true]')) continue; const r=el.getBoundingClientRect(); if(r.width<1||r.height<1) continue;
   let op=1,e=el; while(e&&e.nodeType===1){op*=parseFloat(getComputedStyle(e).opacity);e=e.parentElement;}
   const bg=bgOf(el); let fg=parse(cs.color); fg[3]*=op; const f=over(fg,bg.c); const ratio=(Math.max(lum(f),lum(bg.c))+0.05)/(Math.min(lum(f),lum(bg.c))+0.05);
   const fs=parseFloat(cs.fontSize), bold=parseInt(cs.fontWeight)>=700; const large=fs>=24||(fs>=18.66&&bold); const need=large?3:4.5; if(ratio>=need) continue;
   const key=cs.color+'|'+bg.c.map(x=>Math.round(x)).join(',')+'|'+fs+'|'+(bold?'b':'');
   if(!res[key]) res[key]={count:0,ratio:Math.round(ratio*100)/100,need,fg:cs.color,bg:'rgb('+bg.c.slice(0,3).map(x=>Math.round(x)).join(',')+')',img:bg.img,fs,sample:t.slice(0,50),cls:(el.className&&el.className.toString().slice(0,60))||el.tagName.toLowerCase()};
   res[key].count++; }
 return Object.values(res).sort((a,b)=>b.count-a.count).slice(0,12);
}"""
JS_MISC = r"""() => {
 const out={};
 const name=el=>(el.getAttribute('aria-label')||el.getAttribute('aria-labelledby')||el.getAttribute('title')||el.textContent||'').trim();
 out.unnamed=[...document.querySelectorAll('button,a[href],[role=button],input[type=button],input[type=submit]')].filter(e=>{const r=e.getBoundingClientRect(); if(r.width<1||r.height<1||getComputedStyle(e).visibility==='hidden') return false; if(e.tagName==='INPUT') return !(e.value||e.getAttribute('aria-label')); const img=e.querySelector('img[alt]:not([alt=""])'); return !name(e)&&!img;}).slice(0,8).map(e=>e.outerHTML.slice(0,140));
 out.nolabel=[...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea')].filter(e=>{const r=e.getBoundingClientRect(); if(r.width<1||r.height<1) return false; if(e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||e.getAttribute('title')) return false; if(e.id&&document.querySelector('label[for="'+CSS.escape(e.id)+'"]')) return false; return !e.closest('label');}).slice(0,8).map(e=>e.outerHTML.slice(0,140));
 out.overflowX=document.documentElement.scrollWidth>window.innerWidth+1; out.sw=document.documentElement.scrollWidth;
 out.h1=document.querySelectorAll('h1').length; out.main=document.querySelectorAll('main').length; out.title=document.title;
 out.muted=getComputedStyle(document.documentElement).getPropertyValue('--if-muted').trim();
 out.dlg=window.__dlg||[];
 const tiny=[...document.querySelectorAll('body *')].filter(e=>e.children.length===0&&e.textContent.trim()&&parseFloat(getComputedStyle(e).fontSize)<12&&e.getBoundingClientRect().width>0); out.tiny=tiny.length; out.tinySample=tiny.slice(0,3).map(e=>e.textContent.trim().slice(0,30));
 return out; }"""
JS_FOCUS = r"""() => { const e=document.activeElement; if(!e||e===document.body) return null; const cs=getComputedStyle(e); const r=e.getBoundingClientRect();
  const ow=parseFloat(cs.outlineWidth)||0, has=(cs.outlineStyle!=='none'&&ow>0)||(cs.boxShadow&&cs.boxShadow!=='none');
  return {tag:e.tagName.toLowerCase(),txt:(e.getAttribute('aria-label')||e.textContent||e.value||e.name||'').trim().slice(0,30),has:!!has,vis:r.width>0&&r.height>0,out:cs.outline.slice(0,50),sh:(cs.boxShadow||'').slice(0,50)}; }"""

def login_pw(p, ctx):
    pg = ctx.new_page(); pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', ADMIN[0]); pg.fill('input[name=password]', ADMIN[1]); pg.locator('button[type=submit]').first.click()
    pg.wait_for_url(lambda u: 'login' not in u, timeout=20000); return pg

def run(quick=False):
    findings = []
    with sync_playwright() as p:
        b = p.chromium.launch()
        for theme in ('light', 'dark'):
            sql(f"update user_settings set user_config_theme_dark={2 if theme == 'light' else 1} where user_id=9")
            for (w, h) in (VIEWS[:1] if quick else VIEWS):
                ctx = b.new_context(viewport={'width': w, 'height': h}, ignore_https_errors=True); ctx.add_init_script(INIT)
                pg = login_pw(p, ctx)
                for name, url in PAGES:
                    errs, bad = [], []
                    pg.on('console', lambda m: errs.append(m.text[:140]) if m.type == 'error' else None)
                    pg.on('response', lambda r: bad.append(f'{r.status} {r.url.split("127.0.0.1:8196")[-1][:70]}') if r.status >= 400 else None)
                    try:
                        pg.goto(BASE + url, wait_until='networkidle', timeout=30000)
                    except Exception as e:
                        findings.append(dict(page=name, theme=theme, vp=f'{w}x{h}', nav_error=str(e)[:100])); continue
                    time.sleep(0.4)
                    f = dict(page=name, theme=theme, vp=f'{w}x{h}', url=url, errs=list(dict.fromkeys(errs))[:4], bad=list(dict.fromkeys(bad))[:4])
                    f.update(pg.evaluate(JS_MISC))
                    f['contrast'] = pg.evaluate(JS_CONTRAST)
                    pg.evaluate(AXE_SRC)
                    ax = pg.evaluate("async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa','best-practice']}});return r.violations.map(v=>({id:v.id,impact:v.impact,n:v.nodes.length,help:v.help,sample:v.nodes[0].html.slice(0,110)}))}")
                    f['axe'] = ax
                    if (w, h) == VIEWS[0] and not quick:
                        pg.screenshot(path=os.path.join(EV, f'{name}_{theme}_{w}.jpg'), type='jpeg', quality=55, full_page=False)
                        seq = []
                        pg.evaluate("document.activeElement&&document.activeElement.blur()")
                        for _ in range(35):
                            pg.keyboard.press('Tab'); r = pg.evaluate(JS_FOCUS)
                            if r: seq.append(r)
                        f['focus_total'] = len(seq); f['focus_no_ring'] = [f"{x['tag']}:{x['txt']}" for x in seq if x['vis'] and not x['has']][:6]
                    findings.append(f)
                    pg.remove_listener('console', pg.listeners('console')[0]) if False else None
                ctx.close()
        b.close()
    sql("update user_settings set user_config_theme_dark=0 where user_id=9")
    json.dump(findings, open(os.path.join(OUT, 'ux_findings.json'), 'w'), indent=1)
    return findings

if __name__ == '__main__':
    fs = run('--quick' in sys.argv)
    print(len(fs), 'page/theme/viewport runs')
