"""Shared helpers for the QA Playwright flows (run against the demo instances only).
Needs python playwright; see tests/qa/README.md."""
import sys, os
sys.path.insert(0, os.environ.get('QA_PYLIBS', './pylibs'))
from playwright.sync_api import sync_playwright

EDITIONS = {
    'it':  ('http://127.0.0.1:8080', 'alex.morgan@summitridge.example'),
    'msp': ('https://10.1.0.45:8445', 'alex.morgan@northwind-it.example'),
}
PASSWORD = 'DemoAdmin#2026'

def session(ed, width=1366, height=900):
    base, email = EDITIONS[ed]
    p = sync_playwright().start()
    b = getattr(p, os.environ.get('QA_BROWSER', 'chromium')).launch()
    ctx = b.new_context(ignore_https_errors=True, viewport={'width': width, 'height': height})
    pg = ctx.new_page()
    pg.goto(base + '/login.php')
    pg.fill('input[name=email]', email); pg.fill('input[name=password]', PASSWORD)
    pg.locator('button[type=submit]').first.click(); pg.wait_for_load_state('networkidle')
    return p, b, ctx, pg, base

def pick(pg, select_name, text=None, index=1):
    """Set a TomSelect-backed <select> by visible text (or option index)."""
    pg.evaluate("""([n,t,i])=>{const s=document.querySelector('.modal.show select[name='+n+']');
      const o=[...s.options].filter(o=>o.value);const m=t?o.find(x=>x.text.includes(t)):o[i-1];
      if(s.tomselect){s.tomselect.setValue(m.value)}else{s.value=m.value;s.dispatchEvent(new Event('change',{bubbles:true}))}}""",
      [select_name, text, index])
