"""
End-to-end check of Release channel (Administration > Update: Production / Beta) through the real web stack: sign in, load the page, switch channel, run Update App and the CLI updater against a throwaway git origin, and verify the checkout follows the channel.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/release_channel.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <origin.git> <origin work clone> <app checkout>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; ORIGIN = sys.argv[5]; SEED = sys.argv[6]; APP = sys.argv[7]
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
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))
G = ['git', '-c', 'safe.directory=*', '-c', 'user.name=t', '-c', 'user.email=t@t']
def git(cwd, *a):
    r = subprocess.run(G + list(a), cwd=cwd, capture_output=True, text=True); return r.returncode, (r.stdout + r.stderr).strip()
def branch(): return git(APP, 'rev-parse', '--abbrev-ref', 'HEAD')[1]
def channel(): return sql("select config_release_channel from settings")
def commit_to(br, fname, text):
    git(SEED, 'checkout', '-q', br); open(SEED + '/' + fname, 'w').write(text)
    git(SEED, 'add', '-A'); git(SEED, 'commit', '-q', '-m', 'add ' + fname); return git(SEED, 'push', '-q', ORIGIN, br)
def promote():
    git(SEED, 'checkout', '-q', 'main'); git(SEED, 'merge', '-q', '--ff-only', 'beta'); return git(SEED, 'push', '-q', ORIGIN, 'main')

git(SEED, 'checkout', '-q', '-B', 'beta', 'main')   # the work clone needs a local beta branch to commit to
s, html, h = req('/login.php'); data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
check('sign in', req('/login.php', data)[0] in (302, 303))
def page(): return req('/admin/update.php')
def save(value, token=None):
    s, p, h = page()
    return req('/admin/post.php', {'csrf_token': token or csrf(p), 'release_channel': value, 'save_release_channel': '1'}, referer='/admin/update.php')
def update_app():
    s, p, h = page()
    return req('/admin/post.php?update&no_backup=1&csrf_token=' + csrf(p), referer='/admin/update.php')

s, p, h = page()
check('the Update page shows the Release channel card with both channels', s == 200 and 'Release channel' in p and 'value="production"' in p and 'value="beta"' in p, s)
check('this server is on Production (checked out main) and the card says so', channel() == 'production' and branch() == 'main' and 'This server' in p)
check('each channel shows the branch it follows', 'origin/main' in p and 'origin/beta' in p)

# beta gets a new commit (ahead of main)
commit_to('beta', 'beta_only.txt', 'beta feature')
s, p, h = page()
save('beta')
check('switching to Beta is allowed (Beta is ahead) and stored', channel() == 'beta')
s, p, h = page()
check('the page now says Update App will switch this server to beta', 'will switch this server to' in p and 'beta' in p, s)
check('nothing moves until Update App is run', branch() == 'main' and not os.path.exists(APP + '/beta_only.txt'))

update_app()
check('Update App moves the server onto the beta branch and installs the beta code', branch() == 'beta' and os.path.exists(APP + '/beta_only.txt'), (branch(), os.path.exists(APP + '/beta_only.txt')))
s, p, h = page()
check('the page now reports the running branch as beta, up to date', s == 200 and '<code>beta</code>' in p)

# beta -> production while beta has unreleased work: refused
save('production')
check('switching back to Production while Beta is ahead is REFUSED (would install older code)', channel() == 'beta')
s, p, h = page()
check('the refusal explains why', 'older code' in p, s)
update_app()
check('and Update App keeps the server on beta', branch() == 'beta' and os.path.exists(APP + '/beta_only.txt'))

# release: promote beta -> main, then production is allowed
promote()
save('production')
check('after the release (main caught up), switching to Production is allowed', channel() == 'production')
update_app()
check('Update App returns the server to the production branch, keeping the released code', branch() == 'main' and os.path.exists(APP + '/beta_only.txt'), (branch(),))

# bad input, wrong token, anonymous
save("beta' OR 1=1 --")
check('a junk channel value is rejected and nothing changes', channel() == 'production')
save('beta', token='wrong')
check('a wrong CSRF token changes nothing', channel() == 'production')
try:
    urllib.request.urlopen(urllib.request.Request(BASE + '/admin/post.php', data=urllib.parse.urlencode({'csrf_token': 'x', 'release_channel': 'beta', 'save_release_channel': '1'}).encode(), headers={'Referer': BASE + '/admin/update.php'}), timeout=30)
except Exception:
    pass
check('an anonymous visitor cannot change it', channel() == 'production')
check('the change was written to the activity log', int(sql("select count(*) from logs where log_description like '%release channel%'")) >= 2)

# the command-line updater follows the channel too
commit_to('beta', 'beta_two.txt', 'second beta feature')
sql("update settings set config_release_channel='beta'")
r = subprocess.run(['php', 'update_cli.php', '--update'], cwd=APP + '/scripts', capture_output=True, text=True)
check('update_cli --update on a Beta server switches to beta and pulls the latest beta code', r.returncode == 0 and branch() == 'beta' and os.path.exists(APP + '/beta_two.txt'), (r.returncode, r.stdout[-200:], r.stderr[-200:]))
sql("update settings set config_release_channel='production'")
r = subprocess.run(['php', 'update_cli.php', '--update'], cwd=APP + '/scripts', capture_output=True, text=True)
check('update_cli --update refuses to go from beta back to production while beta is ahead', r.returncode != 0 and branch() == 'beta' and 'older code' in (r.stderr + r.stdout), (r.returncode, r.stdout[-200:], r.stderr[-200:]))
promote()
r = subprocess.run(['php', 'update_cli.php', '--update'], cwd=APP + '/scripts', capture_output=True, text=True)
check('after the release the CLI moves a Production server back to main', r.returncode == 0 and branch() == 'main', (r.returncode, r.stdout[-200:], r.stderr[-200:]))

# a hand-edited file in the way stops the switch and is preserved
sql("update settings set config_release_channel='beta'")
commit_to('beta', 'hand.txt', 'v1'); git(APP, 'fetch', '-q', 'origin')
commit_to('main', 'x.txt', 'x') if False else None
open(APP + '/README.md', 'a').write('\nlocal hand edit\n')
before = open(APP + '/README.md').read()
git(SEED, 'checkout', '-q', 'beta'); open(SEED + '/README.md', 'a').write('\nbeta readme edit\n'); git(SEED, 'add', '-A'); git(SEED, 'commit', '-q', '-m', 'beta readme'); git(SEED, 'push', '-q', ORIGIN, 'beta')
update_app()
check('a hand-edited file that the switch would overwrite stops it and is left untouched', branch() == 'main' and open(APP + '/README.md').read() == before, branch())
git(APP, 'checkout', '-q', '--', 'README.md'); sql("update settings set config_release_channel='production'")
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
