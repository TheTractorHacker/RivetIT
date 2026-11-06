"""Portal IDOR: a portal user must only see their own tickets / files. usage: portal_idor.py"""
import json
from common import *
from playwright.sync_api import sync_playwright
base, _ = EDITIONS['it']; r = {}
with sync_playwright() as pw:
    b = pw.chromium.launch(); c = b.new_context(ignore_https_errors=True); pg = c.new_page()
    pg.goto(base + '/login.php'); pg.fill('input[name=email]', 'sophie.tran@summitridge.example'); pg.fill('input[name=password]', 'DemoPass#2026'); pg.locator('button[type=submit]').first.click(); pg.wait_for_load_state('domcontentloaded')
    own = {'149', '191', '192', '193', '380'}; seen = {}
    for tid in list(range(140, 152)) + [191, 192, 193, 374, 377, 380, 455, 1]:
        resp = pg.goto(base + f'/client/ticket.php?id={tid}', wait_until='domcontentloaded')
        txt = pg.inner_text('body'); shows = ('TCK-' in txt and 'Login' not in pg.title())
        seen[tid] = 'SHOWN' if shows else 'denied(' + pg.url[len(base):][:30] + ')'
        if 'login' in pg.url: pg.goto(base + '/login.php'); pg.fill('input[name=email]', 'sophie.tran@summitridge.example'); pg.fill('input[name=password]', 'DemoPass#2026'); pg.locator('button[type=submit]').first.click(); pg.wait_for_load_state('domcontentloaded')
    r['shown_not_own'] = [t for t, v in seen.items() if v == 'SHOWN' and str(t) not in own]
    r['own_not_shown'] = [t for t in own if seen.get(int(t)) not in (None, 'SHOWN')]
    r['detail'] = seen
    b.close()
print(json.dumps(r, indent=1))
