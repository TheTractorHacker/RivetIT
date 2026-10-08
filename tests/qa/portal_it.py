"""Client/department portal as a portal user (RivetIT demo): crawl, authz boundaries, IDOR probes."""
import sys, json, re
from common import *
from playwright.sync_api import sync_playwright
base, _ = EDITIONS['it']; r = {}; errs = []
with sync_playwright() as pw:
    b = pw.chromium.launch(); c = b.new_context(ignore_https_errors=True, viewport={'width': 1366, 'height': 900}); pg = c.new_page()
    pg.on('console', lambda m: errs.append(m.text[:120]) if m.type == 'error' else None); pg.on('pageerror', lambda e: errs.append('EXC ' + str(e)[:100]))
    pg.goto(base + '/login.php'); pg.fill('input[name=email]', 'sophie.tran@summitridge.example'); pg.fill('input[name=password]', 'DemoPass#2026'); pg.locator('button[type=submit]').first.click(); pg.wait_for_load_state('domcontentloaded')
    r['landing'] = pg.url[len(base):]
    links = sorted({h[len(base):] for h in pg.eval_on_selector_all('a[href]', 'e=>e.map(x=>x.href)') if h.startswith(base + '/client/') and 'logout' not in h and 'post.php' not in h})
    r['nav'] = links; pages = {}
    for l in links[:40]:
        
        try: resp = pg.goto(base + l, wait_until='domcontentloaded')
        except Exception as e: pages[l] = [-1, str(e)[:40], False, False]; continue
        txt = pg.inner_text('body')
        pages[l] = [resp.status if resp else 0, pg.title()[:40], bool(re.search(r'Fatal error|Warning:|Stack trace|mysqli_sql', txt)), pg.evaluate('document.documentElement.scrollWidth>innerWidth+2')]
    r['pages_bad'] = {k: v for k, v in pages.items() if v[0] != 200 or v[2] or v[3]}; r['pages_count'] = len(pages)
    boundary = {}
    for path in ('/agent/dashboard.php', '/agent/tickets.php', '/agent/clients.php', '/admin/users.php', '/admin/', '/api/v1/tickets.php', '/agent/ticket.php?ticket_id=374&client_id=19'):
        resp = pg.goto(base + path, wait_until='domcontentloaded'); boundary[path] = [resp.status if resp else 0, pg.url[len(base):], pg.inner_text('body')[:60].replace('\n', ' ')]
    r['boundary'] = boundary
    # IDOR: client ticket view for ids 1..20 around, and a ticket of another client
    ids = {}
    for tid in (374, 142, 150, 1, 455):
        resp = pg.goto(base + f'/client/ticket.php?id={tid}', wait_until='domcontentloaded'); ids[tid] = [resp.status if resp else 0, pg.url[len(base):], pg.title()[:40]]
    r['idor_ticket_view'] = ids
    r['errs'] = errs[:6]; b.close()
print(json.dumps(r, indent=1))
