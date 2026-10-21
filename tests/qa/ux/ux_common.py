"""Shared helpers for the RivetIT UX sweep (demo server on 127.0.0.1:8190 only)."""
import sys, os, json, time
sys.path.insert(0, os.environ.get('QA_PYLIBS', '/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/pylibs'))
from playwright.sync_api import sync_playwright
BASE = os.environ.get('QA_BASE', 'http://127.0.0.1:8190')
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.environ.get('QA_OUT', os.path.join(HERE, 'out'))
os.makedirs(OUT, exist_ok=True)
AXE = open(os.path.join(HERE, 'axe.min.js')).read()
USERS = {'admin': ('alex.morgan@summitridge.example', 'DemoAdmin#2026'),
         'tech': ('priya.nair@summitridge.example', 'DemoPass#2026'),
         'tech2': ('marcus.lee@summitridge.example', 'DemoPass#2026'),
         'portal': ('sophie.tran@summitridge.example', 'DemoPass#2026')}

def start(w=1920, h=1080, who='admin', portal=False):
    p = sync_playwright().start(); b = p.chromium.launch()
    ctx = b.new_context(viewport={'width': w, 'height': h}); pg = ctx.new_page()
    e, pw = USERS[who]
    pg.goto(BASE + ('/client/login.php' if portal else '/login.php'))
    pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw)
    pg.locator('button[type=submit]').first.click(); pg.wait_for_load_state('load'); time.sleep(.8)
    return p, b, ctx, pg

def axe(pg, rules=None):
    pg.evaluate(AXE)
    opts = {'runOnly': {'type': 'rule', 'values': rules}} if rules else {}
    return pg.evaluate("async (o) => { const r = await axe.run(document, o); return r.violations.map(v => ({id: v.id, impact: v.impact, n: v.nodes.length, sample: v.nodes.slice(0,3).map(n => n.target.join(' ') + ' :: ' + (n.html||'').slice(0,110))})); }", opts)
