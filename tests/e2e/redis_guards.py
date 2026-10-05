"""
End-to-end check of Redis guards: sign-in throttle, cron guard, update lock (through RivetCore) through the real web stack: sign in, load the page, hammer the sign-in, run a guarded cron script while its lock is held, and check everything fails open when Redis is gone.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/redis_guards.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
jar = http.cookiejar.CookieJar()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)
def req(path, data=None, referer=None):
    headers = {}
    if referer: headers['Referer'] = BASE + referer
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    r = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        resp = opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', "SET SESSION time_zone = '+00:00'; " + q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
import time
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))
RPORT = os.environ['TEST_REDIS_PORT']
def rcli(*a):
    return subprocess.run(['redis-cli', '-p', RPORT] + list(a), capture_output=True, text=True).stdout.strip()
def login(email, password):
    s, html, h = req('/login.php'); t = csrf(html)
    d = {'email': email, 'password': password, 'login': ''}
    if t: d['csrf_token'] = t
    return req('/login.php', d)
sql("delete from logs where log_type='Login'")
rcli('flushall')
env = dict(os.environ, RIVETIT_REDIS_PORT=RPORT, RIVETIT_REDIS_HOST='127.0.0.1')
APP = sys.argv[5]

# ---- sign-in throttle
sql("delete from logs where log_type='Login'")
codes = [login('victim@example.test', 'wrong-%d' % i)[0] for i in range(14)]
check('the first sign-in failures answer 401 and then the account is throttled with 429', codes[:10] == [401] * 10 and 429 in codes[10:], codes)
s, html, h = login('victim@example.test', 'wrong-again')
check('a throttled attempt says so and carries Retry-After', s == 429 and 'Too many sign-in attempts' in html and int(h.get('Retry-After', 0)) > 0, (s, h.get('Retry-After')))
check('the throttle is recorded in the activity log', int(sql("select count(*) from logs where log_description like 'Too many sign-in attempts%'")) >= 1)
check('another account is not affected by that account being throttled', login('someone.else@example.test', 'x')[0] == 401)
rcli('flushall'); sql("delete from logs where log_type='Login'")
s, html, h = req('/login.php'); t = csrf(html); d = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
if t: d['csrf_token'] = t
check('after the window resets, a real sign-in works', req('/login.php', d)[0] in (302, 303))
rcli('flushall'); sql("delete from logs where log_type='Login'")
ips = [login('u%d@example.test' % i, 'x')[0] for i in range(34)]
check('many different accounts from one address are stopped (the older per-address lockout at 15 failures, or the new 30-per-5-minutes limit)', 429 in ips and ips[:14].count(401) >= 14, ips)

# ---- cron guard
rcli('flushall')
rcli('set', 'rivetit:lock:cron:metrics_rollup', 'someone-else', 'EX', '60')
r = subprocess.run(['php', 'metrics_rollup.php'], cwd=APP + '/cron', capture_output=True, text=True, env=env)
check('a cron script exits quietly when another copy holds its lock', 'already running' in r.stdout, r.stdout + r.stderr)
rcli('del', 'rivetit:lock:cron:metrics_rollup')
r = subprocess.run(['php', 'metrics_rollup.php'], cwd=APP + '/cron', capture_output=True, text=True, env=env)
check('and runs normally when the lock is free, releasing it when done', 'already running' not in r.stdout and rcli('exists', 'rivetit:lock:cron:metrics_rollup') == '0', r.stdout + r.stderr)

# ---- update lock
s, h2, hd = req('/login.php'); t = csrf(h2); dd = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
if t: dd['csrf_token'] = t
req('/login.php', dd)
sql("delete from logs where log_type='Login'")
rcli('set', 'rivetit:lock:update_db', 'x', 'EX', '60')
tok = csrf(req('/admin/event_rules.php')[1]) or ''
req('/admin/post.php?update_db&no_backup=1&csrf_token=' + tok, referer='/admin/update.php')
s, page, h = req('/admin/update.php')
check('a database update refuses to start while another holds the update lock', 'database update is already running' in page, s)
rcli('del', 'rivetit:lock:update_db')

# ---- fail open when Redis is gone
sql("delete from logs where log_type='Login'")
rcli('shutdown', 'nosave'); time.sleep(0.5)
s, html, h = req('/login.php'); t = csrf(html); d = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
if t: d['csrf_token'] = t
check('with Redis down, sign-in still works (guards fail open)', req('/login.php', d)[0] in (302, 303))
r = subprocess.run(['php', 'metrics_rollup.php'], cwd=APP + '/cron', capture_output=True, text=True, env=env)
check('with Redis down, cron scripts still run', 'already running' not in r.stdout and r.returncode == 0, r.stdout + r.stderr)
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
