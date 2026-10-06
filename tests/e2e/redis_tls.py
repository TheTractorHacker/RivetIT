"""
End-to-end check of Redis authentication and TLS settings (Administration > Redis), and of the REST API rate limit that
rides on the same connection. Drives the real admin page and post handler, throwaway Redis servers, and the real API.

Needs a THROWAWAY copy of the app (never a real site) installed with scripts/setup_cli.php against a scratch database, with
$config_https_only = FALSE, served twice with `php -S`:
  A  http://127.0.0.1:<port>    with NO RIVETIT_REDIS_* variables (settings come from the database)
  B  TEST_BASE_ENV=http://127.0.0.1:<port2>  same app, with RIVETIT_REDIS_HOST=127.0.0.1 RIVETIT_REDIS_PORT=<plain port> RIVETIT_REDIS_TLS=0
and throwaway Redis servers:
  TEST_REDIS_PLAIN  (default 6395)  no password
  TEST_REDIS_AUTH   (default 6396)  requirepass Srv-Pass-9, plus ACL user rivet / Acl-Pass-7
  TEST_REDIS_TLS    (default 6397)  TLS only; CA at TEST_TLS_DIR/ca.crt (server cert for localhost/127.0.0.1), TEST_TLS_DIR/other.crt is an unrelated CA
The TLS cases are skipped (and say so) if TEST_REDIS_TLS is 0.

  TEST_DB_USER=... TEST_DB_PASS=... TEST_TLS_DIR=... TEST_BASE_ENV=... python3 tests/e2e/redis_tls.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>

Optional upgrade check: UPGRADE_DIR / UPGRADE_DB / UPGRADE_USER / UPGRADE_PASS name a scratch install of the PREVIOUS release (v26.10.25,
installed from `git archive`) whose files this script overlays with the current tree and then runs scripts/update_cli.php --update_db ONCE.
"""
import re, sys, os, subprocess, http.cookiejar, urllib.request, urllib.parse, urllib.error, json, shutil
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5]
BASE_ENV = os.environ.get('TEST_BASE_ENV')
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
PLAIN = int(os.environ.get('TEST_REDIS_PLAIN', 6395)); AUTH = int(os.environ.get('TEST_REDIS_AUTH', 6396)); TLS = int(os.environ.get('TEST_REDIS_TLS', 6397))
TLSDIR = os.environ.get('TEST_TLS_DIR', '')
CA = TLSDIR + '/ca.crt'; OTHER = TLSDIR + '/other.crt'
PASS = 'Srv-Pass-9'; ACLPASS = 'Acl-Pass-7'
SECRETS = [PASS, ACLPASS]

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Site:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, path, data=None, referer=None, headers=None, json_body=None):
        h = dict(headers or {})
        if referer: h['Referer'] = self.base + referer
        body = None
        if json_body is not None:
            body = json.dumps(json_body).encode(); h['Content-Type'] = 'application/json'
        elif data is not None:
            body = urllib.parse.urlencode(data, doseq=True).encode()
        r = urllib.request.Request(self.base + path, data=body, headers=h)
        try:
            resp = self.opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.headers
    def login(self):
        s, html, h = self.req('/login.php'); t = csrf(html)
        d = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
        if t: d['csrf_token'] = t
        return self.req('/login.php', d)[0] in (302, 303)

def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None

def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    return out.stdout.strip()

results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:300] + ']') if detail and not ok else ''))

def rcli(port, *a, tls=False, auth=None):
    cmd = ['redis-cli', '-p', str(port)] + (['--tls', '--cacert', CA] if tls else []) + (['-a', auth, '--no-auth-warning'] if auth else []) + list(a)
    return subprocess.run(cmd, capture_output=True, text=True).stdout.strip()

REF = '/admin/settings_redis.php'
A = Site(BASE)
check('sign in as the scratch administrator', A.login())

def page(site=A):
    s, html, h = site.req(REF); return s, html

def post(fields, site=A):
    """Post the Redis form like the browser does; return the text of the page the redirect lands on (where the flash shows)."""
    s, html = page(site); t = csrf(html)
    d = {'csrf_token': t}; d.update(fields)
    s2, body, h = site.req('/admin/post.php', d, referer=REF)
    s3, html3 = page(site)
    return s2, html3

def flash(html):
    """The toast the page shows (inc_alert_feedback.php emits toastr[type](json message))."""
    m = re.search(r'toastr\[("[a-z]+")\]\((".*?")\)\s*\n', html)
    return re.sub(r'\s+', ' ', json.loads(m.group(2))) if m else ''

def leaks(html):
    return [s for s in SECRETS if s in html]

def conn(port, **kw):
    d = {'redis_host': '127.0.0.1', 'redis_port': str(port), 'redis_db': '0', 'test_redis_connection': '1'}
    d.update({k: v for k, v in kw.items()}); return d

def reset():
    sql("update settings set config_redis_host='', config_redis_port=0, config_redis_db=0, config_redis_password='', config_redis_username='', config_redis_tls=0, config_redis_tls_verify=1, config_redis_tls_ca_file=NULL, config_redis_tls_cert_file=NULL, config_redis_tls_key_file=NULL where company_id=1")

reset()
for p in (PLAIN, AUTH): pass
for port, kw in ((PLAIN, {}), (AUTH, {'auth': PASS})):
    rcli(port, 'flushall', **kw)

# ---- the form carries the new fields
s, html = page()
check('the Redis page shows username, TLS, CA file, verify and client certificate fields',
      all(('name="%s"' % n) in html for n in ('redis_username', 'redis_tls', 'redis_tls_ca_file', 'redis_tls_verify', 'redis_tls_cert_file', 'redis_tls_key_file', 'api_rate_limit')), s)

# ---- test connection: distinct reasons, friendly, never the password
s, h = post(conn(PLAIN)); f = flash(h)
check('plain server, no password: connects', 'Connected to Redis' in f, f)
s, h = post(conn(AUTH)); f = flash(h)
check('server with a password, none given: "Sign-in problem" with a fix', 'Sign-in problem' in f and 'password' in f, f)
s, h = post(conn(AUTH, redis_password='wrong-pass')); f = flash(h)
check('wrong password: "Sign-in problem", and the typed password is not echoed', 'Sign-in problem' in f and 'wrong-pass' not in h, f)
s, h = post(conn(AUTH, redis_password=PASS)); f = flash(h)
check('right password: connects, and the page never contains it', 'Connected to Redis' in f and not leaks(h), f)
s, h = post(conn(AUTH, redis_username='rivet', redis_password=ACLPASS)); f = flash(h)
check('ACL username + password: connects', 'Connected to Redis' in f and not leaks(h), f)
s, h = post(conn(AUTH, redis_username='nobody', redis_password=ACLPASS)); f = flash(h)
check('unknown ACL user: "Sign-in problem" naming the username and password', 'Sign-in problem' in f and 'username' in f and not leaks(h), f)
s, h = post(conn(AUTH, redis_username='rivet')); f = flash(h)
check('a username without a password is refused as invalid settings', 'needs a password' in f, f)
s, h = post(conn(1)); f = flash(h)
check('nothing listening: "Cannot reach Redis"', 'Cannot reach Redis' in f, f)
s, h = post(conn(PLAIN, redis_host='bad host;rm')); f = flash(h)
check('an invalid host is refused before connecting', 'host name or IP' in f, f)
s, h = post(conn(PLAIN, redis_tls='1', redis_tls_ca_file='/nonexistent/ca.crt')); f = flash(h)
check('a CA file that cannot be read is reported as such', 'cannot be read' in f, f)
s, h = post(conn(PLAIN, redis_tls_ca_file=CA)); f = flash(h)
check('certificate files without TLS turned on are refused', 'only apply when TLS is turned on' in f, f)

if TLS:
    s, h = post(conn(TLS, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=CA)); f = flash(h)
    check('TLS with the right CA and verification: connects', 'Connected to Redis' in f, f)
    s, h = post(conn(TLS, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=OTHER)); f = flash(h)
    check('TLS with the wrong CA: "TLS problem" (certificate does not verify)', 'TLS problem' in f, f)
    s, h = post(conn(TLS, redis_tls='1', redis_tls_verify='1')); f = flash(h)
    check('TLS with a private CA and no CA file: "TLS problem"', 'TLS problem' in f, f)
    s, h = post(conn(TLS, redis_tls='1')); f = flash(h)      # verify box unticked
    check('TLS with verification off connects (encrypted, server not checked)', 'Connected to Redis' in f, f)
    s, h = post(conn(TLS)); f = flash(h)
    check('plain connection to a TLS-only port fails with a clear reason', 'Connected to Redis' not in f and ('Cannot reach Redis' in f or 'TLS problem' in f or 'Sign-in problem' in f), f)
    s, h = post(conn(PLAIN, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=CA)); f = flash(h)
    check('TLS against a plain port fails with a clear reason', 'Connected to Redis' not in f and ('TLS problem' in f or 'Cannot reach Redis' in f), f)
    s, h = post(conn(TLS, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=CA, redis_tls_key_file=CA)); f = flash(h)
    check('a client key without a client certificate is refused', 'needs a client certificate' in f, f)
else:
    print('SKIP  TLS cases (TEST_REDIS_TLS=0)')

# ---- test only never saves
reset()
post(conn(PLAIN, redis_username='', redis_password=''))
check('"Test only" saves nothing', sql("select concat(config_redis_host,'|',config_redis_port,'|',config_redis_tls) from settings where company_id=1") == '|0|0')

# ---- save refused when it cannot connect, forced save allowed
save = lambda port, **kw: dict({'redis_host': '127.0.0.1', 'redis_port': str(port), 'redis_db': '0', 'save_redis_settings': '1'}, **kw)
s, h = post(save(AUTH)); f = flash(h)
check('save is refused when the test fails (nothing stored)', 'Nothing was saved' in f and sql("select config_redis_port from settings where company_id=1") == '0', f)

# ---- settings round trip: password + username
s, h = post(save(AUTH, redis_username='rivet', redis_password=ACLPASS)); f = flash(h)
row = sql("select config_redis_host, config_redis_port, config_redis_username, config_redis_password from settings where company_id=1").split('\t')
check('save with an ACL username and password stores them', 'saved. Connected' in f and row[:3] == ['127.0.0.1', str(AUTH), 'rivet'], (f, row))
check('the password is stored encrypted, not in plain text', len(row) == 4 and row[3] != '' and ACLPASS not in row[3], row)
s, h = page()
check('the page shows the saved username, says a password is saved, and never echoes it',
      'value="rivet"' in h and 'Saved. Leave blank to keep' in h and not leaks(h) and ACLPASS not in h)
check('the page shows Connected using the saved username and password', 'Connected</span>' in h, '')
s, h = post(save(AUTH, redis_username='rivet')); f = flash(h)
check('saving again with the password left blank keeps it', 'saved. Connected' in f and sql("select config_redis_password from settings where company_id=1") == row[3], f)
s, h = post(save(AUTH, redis_username='rivet', redis_password='bad-new-pass')); f = flash(h)
check('a wrong new password is refused and the saved one is untouched', 'Sign-in problem' in f and sql("select config_redis_password from settings where company_id=1") == row[3], f)
s, h = post(save(PLAIN, redis_clear_password='1')); f = flash(h)
check('"Remove the saved password" clears it', sql("select config_redis_password from settings where company_id=1") == '' and sql("select config_redis_username from settings where company_id=1") == '', f)

# ---- settings round trip: TLS
if TLS:
    s, h = post(save(TLS, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=CA)); f = flash(h)
    row = sql("select config_redis_port, config_redis_tls, config_redis_tls_verify, config_redis_tls_ca_file from settings where company_id=1").split('\t')
    check('TLS settings save and round trip', 'saved. Connected' in f and row == [str(TLS), '1', '1', CA], (f, row))
    s, h = page()
    check('the page shows TLS on, the CA path, verification on; and the app connects over TLS using them',
          'id="redis_tls" name="redis_tls" value="1" checked' in h and CA in h and 'Connected</span>' in h and 'redis_tls_verify" name="redis_tls_verify" value="1" checked' in h)
    s, h = post(save(TLS, redis_tls='1', redis_tls_ca_file=CA)); f = flash(h)       # verify unticked
    check('"verify the certificate" can be turned off and is stored as 0', sql("select config_redis_tls_verify from settings where company_id=1") == '0', f)
    s, h = post(save(TLS, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=CA))
    check('and back on', sql("select config_redis_tls_verify from settings where company_id=1") == '1')

# ---- environment precedence (server B has RIVETIT_REDIS_HOST/PORT/TLS set)
if BASE_ENV:
    B = Site(BASE_ENV); B.login()
    s, h = page(B)
    check('with environment variables set the page marks those fields as set by the server',
          'RIVETIT_REDIS_PORT' in h and 'RIVETIT_REDIS_TLS' in h and 'RIVETIT_REDIS_HOST' in h)
    check('the environment wins: it connects to the plain port although TLS settings are stored', 'Connected</span>' in h and re.search(r'id="redis_tls"[^>]*disabled', h) is not None, '')
    stored_before = sql("select concat(config_redis_port,'|',config_redis_tls,'|',config_redis_tls_ca_file) from settings where company_id=1")
    s, h = post({'redis_host': '10.9.9.9', 'redis_port': '1', 'redis_tls_ca_file': '/nonexistent/ca', 'save_redis_settings': '1'}, site=B); f = flash(h)
    check('posting different host/port/TLS to the environment-controlled page changes nothing that the environment controls',
          'Redis settings saved' in f and sql("select concat(config_redis_port,'|',config_redis_tls,'|',config_redis_tls_ca_file) from settings where company_id=1") == stored_before, (f, stored_before))

# ---- the server-wide file /etc/rivetit/redis.env (path overridden here): read by CLI scripts and the web alike, env still wins
import tempfile
envfile = tempfile.NamedTemporaryFile('w', suffix='.env', delete=False); envfile.write('RIVETIT_REDIS_HOST=file.example\nRIVETIT_REDIS_PORT=6401\nRIVETIT_REDIS_USERNAME=fileuser\nRIVETIT_REDIS_PASSWORD=file-secret\nRIVETIT_REDIS_TLS=1\n'); envfile.close()
probe = "require 'vendor/autoload.php'; $r = ITFlow\\Redis\\RedisSettings::resolve(null); echo json_encode([$r['host'], $r['port'], $r['username'], $r['tls'], $r['from_env']['host']]);"
def resolve_cli(extra):
    r = subprocess.run(['php', '-r', probe], cwd=APP, capture_output=True, text=True, env=dict(os.environ, RIVETIT_REDIS_ENV_FILE=envfile.name, **extra))
    return r.stdout.strip()
check('a CLI script reads the server-wide redis.env file', resolve_cli({}) == '["file.example",6401,"fileuser",true,true]', resolve_cli({}))
check('a real environment variable beats the file', resolve_cli({'RIVETIT_REDIS_PORT': '6500'}) == '["file.example",6500,"fileuser",true,true]', resolve_cli({'RIVETIT_REDIS_PORT': '6500'}))
os.unlink(envfile.name)

# ---- REST API rate limit: Redis-backed, 429 + Retry-After, fail open, configurable
reset()
if TLS: post(save(TLS, redis_tls='1', redis_tls_verify='1', redis_tls_ca_file=CA))   # the API then talks to Redis over TLS
else: post(save(PLAIN))
RL = lambda *a: rcli(TLS, *a, tls=True) if TLS else rcli(PLAIN, *a)
RL('flushall')
s, h = post({'api_rate_limit': '5', 'save_api_rate_limit': '1'}); f = flash(h)
check('an API limit below the minimum is refused', 'between 10 and 100000' in f and sql("select config_api_rate_limit from settings where company_id=1") == '300', f)
s, h = post({'api_rate_limit': '10', 'save_api_rate_limit': '1'}); f = flash(h)
check('the per-minute API limit saves', sql("select config_api_rate_limit from settings where company_id=1") == '10', f)
api = Site(BASE)
s, body, hd = api.req('/api/v1/auth', json_body={'username': EMAIL, 'password': PASSWORD})
tok = (json.loads(body) if s == 200 else {}).get('token') or ''
check('sign in to the REST API', bool(tok), (s, body[:120]))
auth = {'Authorization': 'Bearer ' + tok}
codes = []; last = None
for i in range(14):
    s, body, hd = api.req('/api/v1/me', headers=auth); codes.append(s)
    if s == 429: last = (body, hd)
check('requests over the limit get 429 (first 10 allowed)', codes[:10].count(429) == 0 and 429 in codes[10:], codes)
check('the 429 carries Retry-After between 1 and 60 seconds and a JSON error',
      last is not None and 1 <= int(last[1].get('Retry-After', 0)) <= 60 and 'Rate limit' in last[0], last and (last[0], last[1].get('Retry-After')))
keys = RL('keys', 'rivetit:rl:api:*')
check('the counter lives in Redis (over TLS when available) under the shared rate-limit key layout', 'rivetit:rl:api:' in keys, keys)
check('one throttled token does not throttle a different caller (a second sign-in has its own bucket)', Site(BASE).req('/api/v1/auth', json_body={'username': EMAIL, 'password': PASSWORD})[0] == 200)
RL('flushall')
s, body, hd = api.req('/api/v1/me', headers=auth)
check('after the window is cleared the token works again', s == 200, s)
post({'api_rate_limit': '300', 'save_api_rate_limit': '1'})
# fail open: Redis unreachable
sql("update settings set config_redis_port=1 where company_id=1")
post({'api_rate_limit': '10', 'save_api_rate_limit': '1'})
codes = [api.req('/api/v1/me', headers=auth)[0] for _ in range(14)]
check('with Redis down the API fails open (no 429, requests are served)', 429 not in codes and codes.count(200) == 14, codes)
sql("update settings set config_api_rate_limit=300 where company_id=1")
reset()

# ---- upgrade path from the previous release
UP = os.environ.get('UPGRADE_DIR')
if UP:
    uu, ud = os.environ['UPGRADE_USER'], os.environ['UPGRADE_DB']
    env = dict(os.environ, MYSQL_PWD=os.environ['UPGRADE_PASS'])
    q = lambda x: subprocess.run(['mysql', '-u', uu, '-N', '-B', ud, '-e', x], capture_output=True, text=True, env=env).stdout.strip()
    v0 = q("select config_current_database_version from settings")
    cols0 = q("select count(*) from information_schema.columns where table_schema='%s' and table_name='settings' and column_name in ('config_redis_username','config_redis_tls','config_redis_tls_verify','config_redis_tls_ca_file','config_redis_tls_cert_file','config_redis_tls_key_file','config_api_rate_limit')" % ud)
    check('the previous release is installed (DB 2.6.142) without the new columns', v0 == '2.6.142' and cols0 == '0', (v0, cols0))
    q("update settings set config_redis_host='redis.example', config_redis_port=6400, config_redis_password='ENC-OLD'")
    for sub in ('admin', 'api', 'includes', 'src', 'agent', 'scripts', 'cron', 'mcp_server', 'client', 'modals', 'post', 'setup', 'js', 'css'):
        if os.path.isdir(APP + '/' + sub): shutil.copytree(APP + '/' + sub, UP + '/' + sub, dirs_exist_ok=True)
    for f in ('db.sql', 'functions.php', 'composer.json'):
        if os.path.isfile(APP + '/' + f): shutil.copy(APP + '/' + f, UP + '/' + f)
    r = subprocess.run(['php', 'update_cli.php', '--update_db'], cwd=UP + '/scripts', capture_output=True, text=True, stdin=subprocess.DEVNULL, env=dict(os.environ, RIVETIT_DB_PASSWORD=os.environ['UPGRADE_PASS']))
    v1 = q("select config_current_database_version from settings")
    latest = re.search(r'LATEST_DATABASE_VERSION", "([^"]+)"', open(APP + '/includes/database_version.php').read()).group(1)
    cols1 = q("select count(*) from information_schema.columns where table_schema='%s' and table_name='settings' and column_name in ('config_redis_username','config_redis_tls','config_redis_tls_verify','config_redis_tls_ca_file','config_redis_tls_cert_file','config_redis_tls_key_file','config_api_rate_limit')" % ud)
    check('ONE update_cli run takes the previous release to the latest version', v1 == latest and r.returncode == 0, (v1, latest, (r.stdout + r.stderr)[-300:]))
    check('all seven new columns exist after the upgrade', cols1 == '7', cols1)
    d = q("select concat(config_redis_username,'|',config_redis_tls,'|',config_redis_tls_verify,'|',ifnull(config_redis_tls_ca_file,'NULL'),'|',config_api_rate_limit,'|',config_redis_host,'|',config_redis_port,'|',config_redis_password) from settings")
    check('defaults are safe (no username, TLS off, verify on, limit 300) and the old host/port/password are untouched', d == '|0|1|NULL|300|redis.example|6400|ENC-OLD', d)

bad = [r for r in results if not r[1]]
print('\n%d checks, %d passed, %d failed' % (len(results), len(results) - len(bad), len(bad)))
sys.exit(1 if bad else 0)
