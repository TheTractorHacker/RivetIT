"""Auth edge cases + error-state handling. usage: auth_errors.py <it|msp>"""
import sys, json, re
from common import *
ed = sys.argv[1]; r = {}
p, b, ctx, pg, base = session(ed)
BAD = re.compile(r'Fatal error|Parse error|Warning:|Notice:|Stack trace|mysqli_sql_exception|Uncaught|thrown in')
# invalid routes / ids
errs = {}
for path in ['/nope.php', '/agent/nope.php', '/agent/ticket.php?ticket_id=99999999&client_id=1', '/agent/ticket.php?ticket_id=abc', '/agent/ticket.php', '/agent/client_overview.php?client_id=0', "/agent/client_overview.php?client_id=1'", '/agent/asset_details.php?asset_id=99999999', '/agent/clients.php?page=-5', '/agent/clients.php?page=abc&sort=zzz&order=sideways', '/agent/tickets.php?status=%27%22']:
    resp = pg.goto(base + path, wait_until='domcontentloaded'); t = pg.inner_text('body')
    errs[path] = [resp.status if resp else 0, 'LEAK' if BAD.search(t) else '', t[:50].replace('\n', ' ')]
r['routes'] = errs
# logout then back button / direct nav
pg.goto(base + '/agent/dashboard.php', wait_until='domcontentloaded')
lo = pg.locator('a[href*="logout"]').first; r['logout_link'] = lo.get_attribute('href')
pg.goto(base + '/' + r['logout_link'].lstrip('/') if not r['logout_link'].startswith('http') else r['logout_link'], wait_until='domcontentloaded')
r['after_logout_url'] = pg.url[len(base):]
pg.go_back(); pg.wait_for_timeout(600); r['back_after_logout_shows_app'] = ('/agent/' in pg.url) and 'Login' not in pg.title()
pg.goto(base + '/agent/clients.php', wait_until='domcontentloaded'); r['direct_nav_after_logout'] = pg.url[len(base):][:40]
# login edge cases
def attempt(em, pw):
    pg.goto(base + '/login.php', wait_until='domcontentloaded'); pg.fill('input[name=email]', em); pg.fill('input[name=password]', pw)
    pg.locator('button[type=submit]').first.click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(500)
    return [pg.url[len(base):][:30], ' | '.join(pg.locator('.alert,.text-danger,.invalid-feedback').all_inner_texts())[:80]]
r['login_unknown_user'] = attempt('nobody@nowhere.example', 'x')
r['login_sqli_like'] = attempt("admin'--@x.example", "' OR '1'='1")
r['login_unicode'] = attempt('ünï@日本.example', 'пароль')
r['login_long'] = attempt('a' * 300 + '@x.example', 'p' * 5000)
# rate limiting / lockout: 8 bad attempts then a good one
for _ in range(8): attempt(EDITIONS[ed][1], 'bad-pass')
r['good_login_after_8_bad'] = attempt(EDITIONS[ed][1], PASSWORD)
# session expiry: drop cookies mid-session
ctx.clear_cookies(); pg.goto(base + '/agent/tickets.php', wait_until='domcontentloaded'); r['expired_session_redirect'] = pg.url[len(base):][:50]
# security headers on login
resp = pg.request.get(base + '/login.php'); h = resp.headers
r['headers'] = {k: h.get(k, 'MISSING')[:70] for k in ('content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'strict-transport-security', 'set-cookie')}
b.close(); p.stop(); print(json.dumps(r, indent=1))
