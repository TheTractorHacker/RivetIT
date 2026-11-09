"""Shared helpers for the RivetIT UI audit scripts (run against a LOCAL scratch instance only).
Env: UI_BASE (default http://127.0.0.1:8190), UI_OUT (screenshots/logs dir), QA_PYLIBS (extra sys.path)."""
import sys, os, json, re, time
for p in (os.environ.get('QA_PYLIBS'), '/home/sysadmin/.local/lib/python3.12/site-packages',
          '/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/40aafcf6-a69d-47e9-96bc-1785ea89a678/scratchpad/pylibs'):
    if p and p not in sys.path:
        sys.path.insert(0, p)
from playwright.sync_api import sync_playwright

BASE = os.environ.get('UI_BASE', 'http://127.0.0.1:8190')
OUT = os.environ.get('UI_OUT', '/tmp/ui_audit')
os.makedirs(OUT, exist_ok=True)
USERS = {
    'admin':  ('alex.morgan@summitridge.example', 'DemoAdmin#2026'),
    'tech':   ('priya.nair@summitridge.example', 'DemoPass#2026'),
    'portal': ('sophie.tran@summitridge.example', 'DemoPass#2026'),
}
VIEWPORTS = [(2560, 1440), (1920, 1080), (1680, 1050), (1536, 864), (1440, 900), (1366, 768)]

def _assert_local():
    assert '127.0.0.1' in BASE or 'localhost' in BASE, 'refusing to run against non-local host ' + BASE

def start(role='admin', width=1920, height=1080, browser='chromium', storage=None):
    """Return (playwright, browser, context, page) logged in as role."""
    _assert_local()
    p = sync_playwright().start()
    b = getattr(p, browser).launch()
    ctx = b.new_context(viewport={'width': width, 'height': height}, storage_state=storage)
    pg = ctx.new_page()
    if not storage:
        login(pg, role)
    return p, b, ctx, pg

def login(pg, role='admin'):
    email, pw = USERS[role]
    path = '/client/login.php' if role == 'portal' else '/login.php'
    pg.goto(BASE + path)
    pg.fill('input[name=email]', email)
    pg.fill('input[name=password]', pw)
    pg.locator('button[type=submit]').first.click()
    pg.wait_for_load_state('networkidle')

def state_file(role):
    f = os.path.join(OUT, 'state_%s.json' % role)
    if not os.path.exists(f) or time.time() - os.path.getmtime(f) > 1500:
        p, b, ctx, pg = start(role)
        ctx.storage_state(path=f)
        b.close(); p.stop()
    return f

# Layout checks, run in the page. Returns a list of {kind, detail}
LAYOUT_JS = r"""() => {
  const out = []; const vw = document.documentElement.clientWidth;
  const sw = document.documentElement.scrollWidth;
  if (sw > vw + 1) out.push({kind:'hscroll', detail:`scrollWidth ${sw} > viewport ${vw}`});
  const vis = e => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e);
    return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const desc = e => (e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0,3).join('.') : '')).slice(0,90);
  // elements poking out past the right edge of the viewport (excluding those inside scroll containers)
  let n = 0;
  for (const e of document.querySelectorAll('body *')) {
    if (n > 8) break;
    if (!vis(e)) continue;
    const r = e.getBoundingClientRect();
    if (r.right > vw + 2 && r.width < vw * 3) {
      let p = e.parentElement, scrolled = false;
      while (p && p !== document.body) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') { scrolled = true; break; } p = p.parentElement; }
      if (!scrolled && getComputedStyle(e).position !== 'fixed') { out.push({kind:'overflow-right', detail: desc(e) + ' right=' + Math.round(r.right)}); n++; }
    }
  }
  // clipped text: overflow hidden without ellipsis and no title
  n = 0;
  for (const e of document.querySelectorAll('a,button,.btn,th,td,.badge,label,.nav-link')) {
    if (n > 6) break;
    if (!vis(e)) continue;
    const cs = getComputedStyle(e);
    if (e.scrollWidth > e.clientWidth + 2 && (cs.overflowX === 'hidden' || cs.overflowX === 'clip') && cs.textOverflow !== 'ellipsis' && !e.title && e.clientWidth > 0) {
      out.push({kind:'text-clipped', detail: desc(e) + ' "' + (e.textContent||'').trim().slice(0,40) + '"'}); n++; }
  }
  // overlapping buttons
  const btns = [...document.querySelectorAll('button,a.btn,.btn,input[type=submit]')].filter(vis).filter(e => !e.closest('.modal:not(.show)') && !e.closest('.dropdown-menu:not(.show)'));
  n = 0;
  outer: for (let i = 0; i < btns.length && i < 250; i++) {
    const a = btns[i].getBoundingClientRect();
    for (let j = i + 1; j < btns.length && j < 250; j++) {
      if (btns[i].contains(btns[j]) || btns[j].contains(btns[i])) continue;
      const b = btns[j].getBoundingClientRect();
      const ix = Math.min(a.right, b.right) - Math.max(a.left, b.left), iy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
      if (ix > 3 && iy > 3 && ix * iy > 0.25 * Math.min(a.width * a.height, b.width * b.height)) { out.push({kind:'buttons-overlap', detail: desc(btns[i]) + ' x ' + desc(btns[j])}); if (++n > 4) break outer; }
    }
  }
  // sticky/fixed headers covering the first content
  const main = document.querySelector('main, .page-body, .content-wrapper, .content');
  if (main) { const mr = main.getBoundingClientRect(); const hdr = [...document.querySelectorAll('header,.navbar')].find(h => ['fixed','sticky'].includes(getComputedStyle(h).position) && vis(h) && h.getBoundingClientRect().width > vw * 0.6 && h.getBoundingClientRect().height < 200);
    if (hdr && mr.top < hdr.getBoundingClientRect().bottom - 2 && getComputedStyle(hdr).position === 'fixed') out.push({kind:'header-covers-content', detail: `main top ${Math.round(mr.top)} < header bottom ${Math.round(hdr.getBoundingClientRect().bottom)}`}); }
  // page-level a11y basics
  if (!document.querySelector('main, [role=main]')) out.push({kind:'no-main-landmark', detail:''});
  if (!document.querySelector('h1')) out.push({kind:'no-h1', detail:''});
  const unl = [...document.querySelectorAll('button,a')].filter(vis).filter(e => !(e.textContent||'').trim() && !e.getAttribute('aria-label') && !e.title && !e.getAttribute('aria-labelledby') && !e.querySelector('img[alt]:not([alt=""])'));
  if (unl.length) out.push({kind:'icon-only-unnamed', detail: unl.length + ' e.g. ' + unl.slice(0,3).map(desc).join(' | ')});
  const ctl = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]),select,textarea')].filter(vis).filter(e => !e.labels?.length && !e.getAttribute('aria-label') && !e.getAttribute('aria-labelledby') && !e.title && !e.closest('.ts-wrapper') && !e.closest('.ts-dropdown') && !e.classList.contains('ts-hidden-accessible') && e.type !== 'search' || false);
  if (ctl.length) out.push({kind:'control-no-label', detail: ctl.length + ' e.g. ' + ctl.slice(0,3).map(desc).join(' | ')});
  return out;
}"""
