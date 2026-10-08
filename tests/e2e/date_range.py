"""
End-to-end check of the shared date-range picker / RivetCore DateRange wiring through the real web stack: every preset on the
ticket list (Created and other date fields), custom ranges (swapped, open-ended), invalid input, Saved views staying rolling,
the kanban board, the Service Desk reports, the picker on every converted page and legacy ?canned_date= / ?dtf= URLs.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database (timezone America/Chicago), set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.
Optional: PHP_LOG=<path of the php -S log> to assert that no PHP warnings/notices were written.

  TEST_DB_USER=... TEST_DB_PASS=... [PHP_LOG=...] python3 tests/e2e/date_range.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
"""
import re, sys, json, subprocess, os, datetime as dt, http.cookiejar, urllib.request, urllib.parse, urllib.error
from zoneinfo import ZoneInfo
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
PHP_LOG = os.environ.get('PHP_LOG')
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
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))

log_start = os.path.getsize(PHP_LOG) if PHP_LOG and os.path.exists(PHP_LOG) else 0

# ---------------------------------------------------------------- the independent expectation (Python, Monday weeks, America/Chicago)
TZ = ZoneInfo('America/Chicago')
TODAY = dt.datetime.now(TZ).date()
D = dt.date
def add_months(d, n):
    m = d.month - 1 + n; y = d.year + m // 12; m = m % 12 + 1
    return D(y, m, 1)
def qstart(d): return D(d.year, (d.month - 1) // 3 * 3 + 1, 1)
def expected_range(p, t=TODAY):
    wk = t - dt.timedelta(days=t.weekday())
    first = D(t.year, t.month, 1)
    qs = qstart(t)
    return {
        'today': (t, t), 'yesterday': (t - dt.timedelta(1), t - dt.timedelta(1)),
        'last7': (t - dt.timedelta(6), t), 'last14': (t - dt.timedelta(13), t), 'last30': (t - dt.timedelta(29), t), 'last90': (t - dt.timedelta(89), t),
        'thisweek': (wk, t), 'lastweek': (wk - dt.timedelta(7), wk - dt.timedelta(1)),
        'thismonth': (first, t), 'lastmonth': (add_months(first, -1), first - dt.timedelta(1)),
        'last12months': (add_months(first, -11), t),
        'thisquarter': (qs, t), 'lastquarter': (add_months(qs, -3), qs - dt.timedelta(1)),
        'thisyear': (D(t.year, 1, 1), t), 'lastyear': (D(t.year - 1, 1, 1), D(t.year - 1, 12, 31)),
        'next7': (t, t + dt.timedelta(6)), 'next30': (t, t + dt.timedelta(29)),
        'alltime': (D(1970, 1, 1), D(2099, 12, 31)),
    }[p]
PRESETS = ['today', 'yesterday', 'last7', 'last14', 'last30', 'last90', 'thisweek', 'lastweek', 'thismonth', 'lastmonth', 'last12months',
           'thisquarter', 'lastquarter', 'thisyear', 'lastyear', 'next7', 'next30', 'alltime']

# ---------------------------------------------------------------- seed tickets
def at(d, hms='12:00:00'): return dt.datetime.combine(d, dt.time.fromisoformat(hms))
first_this_month = D(TODAY.year, TODAY.month, 1)
ANCHORS = [
    at(TODAY), at(TODAY, '00:00:00'), at(TODAY, '23:59:59'), at(TODAY - dt.timedelta(1)), at(TODAY - dt.timedelta(1), '23:59:59'),
    at(TODAY - dt.timedelta(3)), at(TODAY - dt.timedelta(10)), at(TODAY - dt.timedelta(40)), at(add_months(first_this_month, -1) + dt.timedelta(14)),
    at(add_months(qstart(TODAY), -3) + dt.timedelta(40)), at(D(TODAY.year - 1, 6, 15)), at(TODAY + dt.timedelta(3)), at(TODAY + dt.timedelta(20)),
    at(TODAY - dt.timedelta(95)), at(first_this_month, '00:00:00'), at(D(TODAY.year, 1, 1), '00:00:00'),
]
N = len(ANCHORS)
F = lambda x: "'%s'" % x.strftime('%Y-%m-%d %H:%M:%S') if x else 'NULL'
sql("delete from tickets where ticket_subject like 'DRT%'; delete from revenues where revenue_reference like 'DRREV-%'")
sql("update user_settings set user_config_records_per_page = 100")
tickets = []   # dicts: num + datetimes
for k in range(N):
    t = {'num': 920001 + k, 'created': ANCHORS[k], 'updated': ANCHORS[(k + 3) % N],
         'resolved': ANCHORS[(k + 5) % N] if k % 2 == 0 else None, 'closed': ANCHORS[(k + 2) % N] if k % 3 == 0 else None,
         'due': ANCHORS[(k + 1) % N], 'sla_due': ANCHORS[(k + 7) % N]}
    tickets.append(t)
    sql("set session sql_mode=''; insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at, ticket_due_at, ticket_sla_resolution_due) values ('T', %d, 'DRT%d', 2, %s, %s, %s, %s, %s, %s)"
        % (t['num'], t['num'], F(t['created']), F(t['updated']), F(t['resolved']), F(t['closed']), F(t['due']), F(t['sla_due'])))
check('seeded %d tickets' % N, sql("select count(*) from tickets where ticket_subject like 'DRT%'") == str(N))
FIELD = {'created': 'created', 'updated': 'updated', 'resolved': 'resolved', 'closed': 'closed', 'due': 'due', 'sla_due': 'sla_due'}
def expect(field, lo, hi, open_only=False):
    out = set()
    for t in tickets:
        v = t[FIELD[field]]
        if v is None: continue
        if open_only and t['resolved'] is not None: continue
        if lo <= v.date() <= hi: out.add(t['num'])
    return out
def got(html): return set(int(x) for x in re.findall(r'DRT(\d{6})', html))

# ---------------------------------------------------------------- sign in
s, html, h = req('/login.php'); data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
check('sign in', req('/login.php', data)[0] in (302, 303))

def list_(qs):
    s, page, h = req('/agent/tickets.php?' + qs)
    return s, got(page), page

# ---------------------------------------------------------------- every preset, Created and others
bad = []
for p in PRESETS:
    lo, hi = expected_range(p)
    for field in ('created', 'updated', 'due'):
        s, g, page = list_('status=All&canned_date=%s&datefield=%s' % (p, field))
        exp = expect(field, lo, hi) if p != 'alltime' else set(x['num'] for x in tickets)
        if s != 200 or g != exp: bad.append((p, field, s, sorted(exp ^ g)))
check('every preset returns exactly the expected tickets for created / updated / due (%d combos)' % (len(PRESETS) * 3), not bad, bad[:3])
bad = []
for p in ('today', 'last30', 'lastmonth', 'thisyear', 'lastyear', 'next30'):
    lo, hi = expected_range(p)
    for field in ('resolved', 'closed', 'sla_due'):
        s, g, page = list_('status=All&canned_date=%s&datefield=%s' % (p, field))
        if s != 200 or g != expect(field, lo, hi): bad.append((p, field, s, sorted(expect(field, lo, hi) ^ g)))
check('resolved / closed / SLA-due date fields filter on their own column', not bad, bad[:3])
s, g, page = list_('canned_date=last90&datefield=resolved')
check('choosing the Resolved date field with no status shows resolved tickets (not hidden by the open-only default)', g == expect('resolved', *expected_range('last90')) and len(g) > 0, sorted(g))
s, g, page = list_('status=Open&canned_date=last30&datefield=created')
check('status=Open still means unresolved with a range applied', g == expect('created', *expected_range('last30'), open_only=True), sorted(g))
s, g, page = list_('canned_date=alltime&datefield=bogus')
check('an unknown datefield falls back to Created', s == 200 and g == set(x['num'] for x in tickets if x['resolved'] is None), s)

# boundaries: today 00:00:00 and 23:59:59 are in today, yesterday 23:59:59 is not
s, g, page = list_('status=All&canned_date=today')
check('half-open bounds include today 00:00:00 and 23:59:59 and exclude yesterday 23:59:59', {920002, 920003} <= g and 920005 not in g, sorted(g))

# ---------------------------------------------------------------- custom ranges
a = TODAY - dt.timedelta(12); b = TODAY - dt.timedelta(2)
exp = expect('created', a, b)
for name, qs in [('custom range', 'canned_date=custom&dtf=%s&dtt=%s' % (a, b)),
                 ('swapped from/to', 'canned_date=custom&dtf=%s&dtt=%s' % (b, a)),
                 ('legacy dtf/dtt without canned_date', 'dtf=%s&dtt=%s' % (a, b))]:
    s, g, page = list_('status=All&' + qs)
    check('custom: ' + name, s == 200 and g == exp, sorted(g ^ exp))
s, g, page = list_('status=All&canned_date=custom&dtf=%s' % a)
check('custom: only From means on or after', g == expect('created', a, D(2099, 12, 31)), sorted(g))
s, g, page = list_('status=All&canned_date=custom&dtt=%s' % b)
check('custom: only To means up to and including', g == expect('created', D(1970, 1, 1), b), sorted(g))
s, g, page = list_('status=All&canned_date=custom&dtf=%s&dtt=%s&datefield=due' % (a, b))
check('custom range on another date field', g == expect('due', a, b), sorted(g))

# ---------------------------------------------------------------- invalid input never warns or breaks
n_all = set(x['num'] for x in tickets)
bad = []
for qs in ['canned_date=nonsense', 'canned_date=CUSTOM&dtf=garbage&dtt=2026-02-31', 'canned_date=custom&dtf=2026-13-45', "canned_date=custom&dtf=2026-01-01'%20OR%201=1--&dtt=x",
           'canned_date[]=today', 'dtf[]=1&dtt[]=2', 'canned_date=custom&dtf=0001-01-01&dtt=9999-12-31', 'datefield[]=due', "canned_date=today'%3B%20DROP%20TABLE%20users", 'canned_date=custom&dtf=%00']:
    s, g, page = list_('status=All&' + qs)
    if s != 200 or ('Warning' in page and 'Undefined' in page): bad.append((qs, s))
check('invalid canned_date / dtf / dtt / datefield values answer 200 with no PHP warning text', not bad, bad)
s, g, page = list_('status=All&canned_date=nonsense')
check('an unknown preset behaves as all time', g == n_all, sorted(g ^ n_all))
check('users table intact after hostile input', int(sql("select count(*) from users")) >= 1)

# ---------------------------------------------------------------- picker markup on the ticket list
s, g, page = list_('status=All&canned_date=last7&datefield=updated')
check('ticket list: picker in the main filter row (not in the collapsed More Filters)', 'data-drp' in page and page.index('data-drp') < page.index('id="advancedFilter"'), s)
check('ticket list: hidden canned_date = last7, dtf/dtt present but disabled (preset stays rolling)', re.search(r'name="canned_date" value="last7" class="drp-canned"', page) and re.search(r'name="dtf" value="" class="drp-dtf" disabled', page) is not None)
check('ticket list: button shows the label and the resolved dates', 'Last 7 days' in page and dt.date.strftime(TODAY - dt.timedelta(6), '%b ').strip() in page)
check('ticket list: Date field select present with Updated selected', re.search(r'<option value="updated" selected>Updated</option>', page) is not None and 'name="datefield"' in page)
s, g, page = list_('status=All&canned_date=custom&dtf=2026-01-05&dtt=2026-02-10')
check('ticket list: custom range writes enabled dtf/dtt', 'name="dtf" value="2026-01-05" class="drp-dtf" >' in page.replace('  ', ' ') or re.search(r'name="dtf" value="2026-01-05" class="drp-dtf"\s*>', page) is not None)

# ---------------------------------------------------------------- saved view stays rolling
def token():
    s, page, h = req('/agent/tickets.php'); return csrf(page)
sql("delete from ticket_saved_views where ticket_saved_view_name like 'DR %'")
s, m, h = req('/agent/modals/ticket/ticket_saved_view_add.php?status=Open&canned_date=last7&datefield=updated')
m = json.loads(m).get('content', '') if s == 200 else ''
check('Save View dialog preselects the rolling range and the date field', 'value="last7" selected' in m and 'Last 7 days (rolling)' in m and 'value="updated" selected' in m, s)
req('/agent/post.php', {'csrf_token': token(), 'name': 'DR Rolling', 'icon': 'fa-clock', 'filters_present': '1', 'f_status_mode': 'open', 'f_date': 'last7', 'f_datefield': 'updated', 'add_ticket_saved_view': '1'}, referer='/agent/tickets.php')
stq = sql("select ticket_saved_view_query from ticket_saved_views where ticket_saved_view_name='DR Rolling'")
check('stored query holds canned_date + datefield and NO absolute dates', 'canned_date=last7' in stq and 'datefield=updated' in stq and 'dtf' not in stq and 'dtt' not in stq, stq)
s, g, page = list_(stq)
lo, hi = expected_range('last7')
check('the view returns the rolling window', g == expect('updated', lo, hi, open_only=True) and len(g) > 0, sorted(g))
check('the view is highlighted as active in the sidebar', re.search(r'active[^>]*href="\?[^"]*canned_date=last7', page) is not None or re.search(r'href="\?[^"]*canned_date=last7[^"]*"[^>]*active', page.replace("\n", " ")) is not None or 'DR Rolling' in page)
# time passes: an old ticket becomes recently updated, a recent one goes stale; the SAME stored query now follows
old = [x for x in tickets if x['resolved'] is None and x['updated'].date() < lo][0]
sql("update tickets set ticket_updated_at = %s where ticket_number = %d" % (F(at(TODAY - dt.timedelta(1))), old['num'])); old['updated'] = at(TODAY - dt.timedelta(1))
s, g2, page = list_(stq)
check('after dates move, the same stored view now includes the newly-updated ticket (rolling)', old['num'] in g2 and g2 == expect('updated', lo, hi, open_only=True), sorted(g2 ^ g))
# an old absolute view keeps working
sql("insert into ticket_saved_views (ticket_saved_view_name, ticket_saved_view_icon, ticket_saved_view_query, ticket_saved_view_user_id, ticket_saved_view_order) values ('DR Absolute', 'fa-filter', 'status=All&canned_date=custom&dtf=%s&dtt=%s&dtf2=x', 0, 99)" % (a, b))
s, g, page = list_('status=All&canned_date=custom&dtf=%s&dtt=%s' % (a, b))
check('existing saved views with absolute dtf/dtt still resolve', g == expect('created', a, b))
s, m, h = req('/agent/modals/ticket/ticket_saved_view_edit.php?id=' + sql("select ticket_saved_view_id from ticket_saved_views where ticket_saved_view_name='DR Absolute'"))
m = json.loads(m).get('content', '') if s == 200 else ''
check('editing an absolute view shows it as fixed dates and keeps them on save', 'Fixed:' in m and 'name="f_dtf"' in m, s)
vid = sql("select ticket_saved_view_id from ticket_saved_views where ticket_saved_view_name='DR Absolute'")
req('/agent/post.php', {'csrf_token': token(), 'ticket_saved_view_id': vid, 'name': 'DR Absolute', 'icon': 'fa-filter', 'filters_present': '1', 'f_status_mode': 'all', 'f_date': 'custom', 'f_dtf': str(a), 'f_dtt': str(b), 'edit_ticket_saved_view': '1'}, referer='/agent/tickets.php')
stq2 = sql("select ticket_saved_view_query from ticket_saved_views where ticket_saved_view_id=" + vid)
check('a fixed range is stored with its dates', ('dtf=%s' % a) in stq2 and ('dtt=%s' % b) in stq2 and 'canned_date=custom' in stq2, stq2)
req('/agent/post.php', {'csrf_token': token(), 'name': 'DR Hostile', 'icon': 'fa-filter', 'filters_present': '1', 'f_status_mode': 'open', 'f_date': "last7' OR 1=1", 'f_datefield': "due; DROP", 'add_ticket_saved_view': '1'}, referer='/agent/tickets.php')
check('hostile date choices are dropped from a stored view', sql("select ticket_saved_view_query from ticket_saved_views where ticket_saved_view_name='DR Hostile'") == 'status=Open')
# API still lists views and does not choke on the new params
s, body, h = req('/api/v1/ticket_views.php')
check('api/v1/ticket-views route is untouched (no 5xx)', s < 500, s)
sql("delete from ticket_saved_views where ticket_saved_view_name like 'DR %'")

# ---------------------------------------------------------------- kanban respects range + field
s, page, h = req('/agent/tickets.php?view=kanban&status=All&canned_date=last30&datefield=created')
lo, hi = expected_range('last30')
check('kanban board honours the range', s == 200 and got(page) == expect('created', lo, hi), sorted(got(page) ^ expect('created', lo, hi)))
s, page, h = req('/agent/tickets.php?view=kanban&status=All&canned_date=lastmonth&datefield=due')
lo, hi = expected_range('lastmonth')
check('kanban board honours the date field', got(page) == expect('due', lo, hi), sorted(got(page) ^ expect('due', lo, hi)))

# ---------------------------------------------------------------- Service Desk reports
def csv_rows(path):
    s, body, h = req(path)
    return s, [l.split(',') for l in body.strip().splitlines()[1:]] if s == 200 else []
lo, hi = expected_range('last30')
s, rows = csv_rows('/agent/reports/ticket_day_breakdown.php?export=csv&canned_date=last30')
created_total = sum(int(r[1]) for r in rows if len(r) > 2)
exp_created = sum(1 for x in tickets if lo <= x['created'].date() <= hi)
check('Tickets: Day by Day report counts only the chosen range (created total %d)' % exp_created, s == 200 and created_total == exp_created and len(rows) == 30, (s, created_total, len(rows)))
s, rows = csv_rows('/agent/reports/ticket_day_breakdown.php?export=csv')
check('Tickets: Day by Day keeps its own default (this month)', s == 200 and len(rows) == (TODAY - first_this_month).days + 1, (s, len(rows)))
s, rows = csv_rows('/agent/reports/service_desk.php?export=csv&canned_date=last30')
opened = sum(int(r[1]) for r in rows if len(r) > 2)
check('Service Desk report volume covers only the chosen range', s == 200 and opened == exp_created, (s, opened, exp_created))
for rp in ('technician_performance', 'csat', 'rmm_health'):
    s, body, h = req('/agent/reports/%s.php?canned_date=last90' % rp)
    check('report %s renders with a preset and shows the picker' % rp, s == 200 and 'name="canned_date" value="last90"' in body and 'Last 90 days' in body, s)

# ---------------------------------------------------------------- every converted page carries the picker
PAGES = ['agent/trips.php', 'agent/transfers.php', 'agent/projects.php', 'agent/expenses.php', 'agent/revenues.php', 'agent/clients.php', 'agent/quotes.php',
         'agent/payments.php', 'agent/invoices.php', 'agent/recurring_invoices.php', 'agent/recurring_expenses.php', 'agent/notifications.php', 'agent/tickets.php',
         'agent/reports/service_desk.php', 'agent/reports/csat.php', 'agent/reports/technician_performance.php', 'agent/reports/ticket_day_breakdown.php',
         'agent/reports/rmm_health.php', 'admin/email_log.php', 'admin/audit_log.php', 'admin/mail_queue.php', 'admin/app_log.php']
bad = []
for pg in PAGES:
    s, body, h = req('/' + pg)
    ok = s == 200 and 'data-drp' in body and 'class="drp-canned"' in body and 'class="drp-dtf"' in body and 'class="drp-dtt"' in body and 'id="dateFilter"' not in body and 'js-canned-date-input' not in body
    if not ok: bad.append((pg, s))
check('picker markup present (and no old #dateFilter / select+date inputs) on all %d converted pages' % len(PAGES), not bad, bad)
s, body, h = req('/agent/tickets.php')
check('the shared script is loaded', 'date_range_picker.js' in body)

# ---------------------------------------------------------------- legacy URLs on non-ticket pages
sql("set session sql_mode=''; set @c = (select min(category_id) from categories); insert into revenues (revenue_date, revenue_amount, revenue_currency_code, revenue_reference, revenue_account_id, revenue_category_id) values (%s, 1, 'USD', 'DRREV-THISMONTH', 1, @c), (%s, 1, 'USD', 'DRREV-LASTYEAR', 1, @c), (%s, 1, 'USD', 'DRREV-JAN', 1, @c)"
    % ("'%s'" % first_this_month, "'%s'" % D(TODAY.year - 1, 6, 1), "'%s'" % D(TODAY.year, 1, 15)))
def rev(qs):
    s, body, h = req('/agent/revenues.php?' + qs); return s, set(re.findall(r'DRREV-[A-Z]+', body))
s, r = rev('canned_date=thismonth'); check('legacy ?canned_date=thismonth on revenues', s == 200 and 'DRREV-THISMONTH' in r and 'DRREV-LASTYEAR' not in r, r)
s, r = rev('canned_date=lastyear'); check('legacy ?canned_date=lastyear on revenues', s == 200 and r == {'DRREV-LASTYEAR'}, r)
s, r = rev('canned_date=custom&dtf=%s&dtt=%s' % (D(TODAY.year, 1, 1), D(TODAY.year, 1, 31))); check('legacy custom dtf/dtt on revenues', s == 200 and r == {'DRREV-JAN'}, r)
s, r = rev('dtf=%s&dtt=%s' % (D(TODAY.year, 1, 1), D(TODAY.year, 1, 31))); check('legacy dtf/dtt (no canned_date) on revenues', s == 200 and r == {'DRREV-JAN'}, r)
s, r = rev('canned_date=last12months'); check('new preset last12months on a plain list page', s == 200 and 'DRREV-THISMONTH' in r and ('DRREV-LASTYEAR' in r) == (D(TODAY.year - 1, 6, 1) >= add_months(first_this_month, -11)), r)
s, r = rev(''); check('no filter shows everything', s == 200 and len(r) == 3, r)
for pg in ('payments', 'invoices'):
    s, body, h = req('/agent/%s.php?canned_date=thismonth' % pg)
    check('legacy ?canned_date=thismonth on %s renders the picker with this month' % pg, s == 200 and 'name="canned_date" value="thismonth"' in body and 'This month' in body, s)
    s, body, h = req('/agent/%s.php?dtf=2026-01-01&dtt=2026-01-31&canned_date=custom' % pg)
    check('legacy custom dtf/dtt on %s renders the picker with those dates' % pg, s == 200 and re.search(r'name="dtf" value="2026-01-01" class="drp-dtf"\s*>', body) is not None and 'Jan 1 – Jan 31' in body.replace('Jan 1, 2026 – Jan 31, 2026', 'Jan 1 – Jan 31'), s)
sql("delete from revenues where revenue_reference like 'DRREV-%'")

# ---------------------------------------------------------------- EXPLAIN (report only)
lo_s, hi_s = '%s 00:00:00' % (TODAY - dt.timedelta(6)), '%s 00:00:00' % (TODAY + dt.timedelta(1))
ex = sql("explain select ticket_id from tickets where ticket_created_at >= '%s' and ticket_created_at < '%s'" % (lo_s, hi_s))
print('INFO  EXPLAIN created-range query (type/possible_keys/key): ' + '\t'.join(ex.split('\t')[3:7]))
print('INFO  indexes covering ticket_created_at: ' + (sql("select index_name, group_concat(column_name order by seq_in_index) from information_schema.statistics where table_schema=database() and table_name='tickets' and column_name='ticket_created_at' group by index_name") or 'none (the range scans the table)'))

# ---------------------------------------------------------------- php log
if PHP_LOG and os.path.exists(PHP_LOG):
    with open(PHP_LOG, errors='replace') as fh:
        fh.seek(log_start); new = fh.read()
    bad = [l for l in new.splitlines() if re.search(r'PHP (Warning|Notice|Deprecated|Fatal|Parse)|Uncaught', l)]
    check('no PHP warnings/notices/fatals written to the server log during the run', not bad, bad[:3])

sql("delete from tickets where ticket_subject like 'DRT%'")
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
