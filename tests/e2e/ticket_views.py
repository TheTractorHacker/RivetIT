"""
End-to-end check of Saved ticket views (customisable filters) through the real web stack: sign in, load the page, create and edit saved views through the filter form and verify what is stored and shown.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/ticket_views.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
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
import json
from urllib.parse import parse_qs
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))
sql("delete from ticket_saved_views where ticket_saved_view_name like 'E2E %'; delete from ticket_statuses where ticket_status_name like 'E2E %'; delete from tags where tag_name like 'E2E %'")
sql("set session sql_mode=''; insert into ticket_statuses (ticket_status_name, ticket_status_color) values ('E2E Waiting', '#111111')")
S = sql("select ticket_status_id from ticket_statuses where ticket_status_name='E2E Waiting'")
sql("set session sql_mode=''; insert into tags (tag_name, tag_type, tag_color, tag_icon) values ('E2E Urgent', 6, '#ff0000', 'fa-fire')")
TG = sql("select tag_id from tags where tag_name='E2E Urgent'")
UID = sql("select user_id from users where user_email='" + EMAIL + "'")
s, html, h = req('/login.php'); data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
check('sign in', req('/login.php', data)[0] in (302, 303))
def token():
    s, page, h = req('/agent/tickets.php')
    t = csrf(page)
    if t: return t
    return csrf(json.loads(req('/agent/modals/ticket/ticket_saved_view_add.php?status=Open')[1]).get('content', ''))
def post(d):
    d = dict(d); d['csrf_token'] = token()
    return req('/agent/post.php', d, referer='/agent/tickets.php')
def stored(name):
    return sql("select ticket_saved_view_query from ticket_saved_views where ticket_saved_view_name='%s'" % name)
def vid(name):
    return sql("select ticket_saved_view_id from ticket_saved_views where ticket_saved_view_name='%s'" % name)

s, m, h = req('/agent/modals/ticket/ticket_saved_view_add.php?status=Open&priority=High&assigned=me')
m = json.loads(m).get('content', '') if s == 200 else ''
check('the Save View dialog shows the filter form, prefilled from the current filters', 'name="f_status_mode"' in m and 'name="f_priority"' in m and 'E2E Waiting' in m and 'value="High" selected' in m and 'value="me" selected' in m, s)

post({'name': 'E2E Mine High', 'icon': 'fa-fire', 'filters_present': '1', 'f_status_mode': 'specific', 'f_status[]': [S, '999999'], 'f_assigned': 'me', 'f_priority': 'High', 'f_onsite': '1', 'f_due': 'overdue', 'f_tags[]': [TG, '999999'], 'add_ticket_saved_view': '1'})
q = parse_qs(stored('E2E Mine High'))
check('the view stores the chosen filters (valid ids only, junk ids dropped)', q.get('status[0]') == [S] or q.get('status[]') == [S] or stored('E2E Mine High').count('status') == 1, stored('E2E Mine High'))
check('assigned, priority, on-site, overdue and tag are stored', q.get('assigned') == ['me'] and q.get('priority') == ['High'] and q.get('onsite') == ['1'] and q.get('overdue') == ['1'] and any(v == [TG] for k, v in q.items() if k.startswith('tags')), stored('E2E Mine High'))
check('an unknown status id was not stored', '999999' not in stored('E2E Mine High'))

post({'name': 'E2E Hostile', 'icon': 'fa-filter', 'filters_present': '1', 'f_status_mode': "open' OR 1=1 --", 'f_assigned': "1; DROP TABLE users", 'f_priority': '<script>', 'f_onsite': '9', 'f_board': "1 OR 1=1", 'f_category': '999999', 'f_due': 'x', 'add_ticket_saved_view': '1'})
check('hostile or invalid filter values are dropped, leaving a plain Open view', stored('E2E Hostile') == 'status=Open', stored('E2E Hostile'))
check('the users table is intact', int(sql("select count(*) from users")) >= 1)

n = sql("select count(*) from ticket_saved_views")
req('/agent/post.php', {'csrf_token': 'wrong', 'name': 'E2E Csrf', 'filters_present': '1', 'f_status_mode': 'all', 'add_ticket_saved_view': '1'}, referer='/agent/tickets.php')
check('a wrong CSRF token creates nothing', sql("select count(*) from ticket_saved_views") == n)

V = vid('E2E Mine High')
s, m, h = req('/agent/modals/ticket/ticket_saved_view_edit.php?id=' + V)
m = json.loads(m).get('content', '') if s == 200 else ''
check('the Edit dialog shows this view\'s filters preselected and a plain-language summary', 'value="specific"' in m and 'E2E Waiting' in m and 'value="High" selected' in m and 'Assigned to me' in m and 'Overdue' in m, s)

post({'ticket_saved_view_id': V, 'name': 'E2E Mine High', 'icon': 'fa-fire', 'filters_present': '1', 'f_status_mode': 'closed', 'f_assigned': 'unassigned', 'f_priority': '', 'edit_ticket_saved_view': '1'})
check('editing the filters replaces them', stored('E2E Mine High') == 'status=Closed&assigned=unassigned', stored('E2E Mine High'))
post({'ticket_saved_view_id': V, 'name': 'E2E Renamed', 'icon': 'fa-star', 'edit_ticket_saved_view': '1'})
check('a plain rename (no filter form) keeps the filters', stored('E2E Renamed') == 'status=Closed&assigned=unassigned', stored('E2E Renamed'))

# the view works as a link and is highlighted when active
s, page, h = req('/agent/tickets.php')
check('the view appears in the sidebar with its link', s == 200 and 'E2E Renamed' in page and 'status=Closed' in page, s)
s, active, h = req('/agent/tickets.php?status=Closed&assigned=unassigned')
check('the page for that link renders and highlights the view', s == 200 and re.search(r'nav-link flex-grow-1 active[^>]*>[^<]*<[^>]*>[^<]*</i>\s*E2E Renamed|active"[^>]*href="\?status=Closed[^"]*assigned=unassigned', active.replace("\n", " ")) is not None, s)
post({'name': 'E2E Several', 'icon': 'fa-filter', 'filters_present': '1', 'f_status_mode': 'specific', 'f_status[]': ['2', S], 'add_ticket_saved_view': '1'})
sev = stored('E2E Several')
s, page, h = req('/agent/tickets.php?' + sev)
check('a view with several statuses loads without errors', s == 200 and 'Array to string' not in page, s)
tk = sql("select count(*) from ticket_saved_views where ticket_saved_view_name like 'E2E %'")
req('/agent/post.php', {'csrf_token': token(), 'ticket_saved_view_id': '1', 'name': 'x', 'filters_present': '1', 'f_status_mode': 'all', 'edit_ticket_saved_view': '1'}, referer='/agent/tickets.php')
check('another view that is not yours (and not shared with edit rights) is untouched by an edit attempt', sql("select ticket_saved_view_query from ticket_saved_views where ticket_saved_view_id=1") != 'status=All' or sql("select ticket_saved_view_user_id from ticket_saved_views where ticket_saved_view_id=1") in ('0', UID))
sql("delete from ticket_saved_views where ticket_saved_view_name like 'E2E %'; delete from ticket_statuses where ticket_status_name like 'E2E %'; delete from tags where tag_name like 'E2E %'")
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
