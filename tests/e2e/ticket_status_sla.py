"""
End-to-end check of Administration > Ticket Statuses ("Pauses the SLA clock") through the real web stack: sign in, load the page, create and edit statuses and verify the flag, the open tickets and the pages.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/ticket_status_sla.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
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
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))
sql("delete from tickets where ticket_subject='slae2e'; delete from ticket_statuses where ticket_status_name like 'E2E %'")
s, html, h = req('/login.php'); data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
check('sign in', req('/login.php', data)[0] in (302, 303))
check('migration flagged On Hold and left Resolved/Closed unflagged', sql("select ticket_status_pauses_sla from ticket_statuses where ticket_status_name='On Hold'") == '1' and sql("select count(*) from ticket_statuses where ticket_status_name in ('Resolved','Closed','Open','New') and ticket_status_pauses_sla=1") == '0')
s, page, h = req('/admin/ticket_status.php')
check('statuses page loads with an SLA clock column', s == 200 and 'SLA clock' in page, s)
check('On Hold shows as Paused', 'Paused' in page)
tok = csrf(page)
def token():
    import json
    t = csrf(req('/admin/ticket_status.php')[1])
    if t: return t
    return csrf(json.loads(req('/admin/modals/ticket_status/ticket_status_add.php')[1]).get('content', ''))   # this page keeps its token inside the dialogs
def post(d, ref='/admin/ticket_status.php'):
    d = dict(d); d['csrf_token'] = token()
    return req('/admin/post.php', d, referer=ref)
post({'name': 'E2E Waiting on Legal', 'color': '#123456', 'pauses_sla': '1', 'add_ticket_status': '1'})
post({'name': 'E2E Busy', 'color': '#654321', 'add_ticket_status': '1'})
check('a new status created WITH the box ticked pauses the SLA', sql("select ticket_status_pauses_sla from ticket_statuses where ticket_status_name='E2E Waiting on Legal'") == '1')
check('a new status created without it does not', sql("select ticket_status_pauses_sla from ticket_statuses where ticket_status_name='E2E Busy'") == '0')
W = sql("select ticket_status_id from ticket_statuses where ticket_status_name='E2E Waiting on Legal'"); B = sql("select ticket_status_id from ticket_statuses where ticket_status_name='E2E Busy'")
import json
s, modal, h = req('/admin/modals/ticket_status/ticket_status_edit.php?id=' + W)
modal = json.loads(modal).get('content', '') if s == 200 else ''
check('the edit dialog shows the box ticked for a pausing status', 'name="pauses_sla"' in modal and 'checked' in modal.split('pauses_sla_edit')[1][:120], s)
s, modal, h = req('/admin/modals/ticket_status/ticket_status_add.php')
modal = json.loads(modal).get('content', '') if s == 200 else ''
check('the create dialog offers the box', 'name="pauses_sla"' in modal and 'Waiting on Customer' in modal)

# an open ticket already sitting in B (not paused); flagging B must pause it, unflagging W must release one
sql("set session sql_mode=''; insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_created_at, ticket_sla_resolution_due) values ('T', 910001, 'slae2e', %s, NOW() - INTERVAL 5 HOUR, NOW() - INTERVAL 1 HOUR)" % B)
sql("set session sql_mode=''; insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_created_at, ticket_sla_resolution_due, ticket_sla_paused_at) values ('T', 910002, 'slae2e', %s, NOW() - INTERVAL 5 HOUR, NOW() + INTERVAL 1 HOUR, NOW() - INTERVAL 2 HOUR)" % W)
post({'ticket_status_id': B, 'name': 'E2E Busy', 'color': '#654321', 'order': '0', 'status': '1', 'pauses_sla': '1', 'edit_ticket_status': '1'})
check('flagging a status pauses the open tickets already in it', sql("select ticket_sla_paused_at is not null from tickets where ticket_number=910001") == '1')
post({'ticket_status_id': W, 'name': 'E2E Waiting on Legal', 'color': '#123456', 'order': '0', 'status': '1', 'edit_ticket_status': '1'})
check('unflagging a status releases the tickets in it and accrues their paused time', sql("select (ticket_sla_paused_at is null and ticket_sla_paused_seconds >= 7000) from tickets where ticket_number=910002") == '1', sql("select ticket_sla_paused_at, ticket_sla_paused_seconds, ticket_status from tickets where ticket_number=910002") + ' / flagged=' + sql("select group_concat(ticket_status_id, ':', ticket_status_pauses_sla) from ticket_statuses"))
check('the flag is stored as unticked', sql("select ticket_status_pauses_sla from ticket_statuses where ticket_status_id=" + W) == '0')
s, page, h = req('/admin/ticket_status.php')
check('the list reflects both changes', s == 200 and page.count('>Paused<') >= 2)
post({'name': 'E2E Bad', 'color': '#000000', 'pauses_sla': "1' OR 1=1 --", 'add_ticket_status': '1'})
check('a hostile value cannot break the insert', sql("select ticket_status_pauses_sla from ticket_statuses where ticket_status_name='E2E Bad'") in ('1', ''), sql("select count(*) from ticket_statuses"))
req('/admin/post.php', {'csrf_token': 'wrong', 'name': 'E2E Csrf', 'color': '#000000', 'pauses_sla': '1', 'add_ticket_status': '1'}, referer='/admin/ticket_status.php')
check('a wrong CSRF token creates nothing', sql("select count(*) from ticket_statuses where ticket_status_name='E2E Csrf'") == '0')
# the ticket list and dashboard render with the new badge code
# Tickets in the lists: one waiting (On Hold, flagged) and one running, both past their due date.
sql("set session sql_mode=''; insert into clients (client_name, client_currency_code) values ('SLA E2E Client', 'USD')")
CL = sql("select max(client_id) from clients")
OH = sql("select ticket_status_id from ticket_statuses where ticket_status_name='On Hold'")
sql("set session sql_mode=''; insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_client_id, ticket_created_at, ticket_sla_resolution_due, ticket_sla_paused_at) values ('T', 910003, 'slae2e', %s, %s, NOW() - INTERVAL 5 HOUR, NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 3 HOUR)" % (OH, CL))
sql("set session sql_mode=''; insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_client_id, ticket_created_at, ticket_sla_resolution_due) values ('T', 910004, 'slae2e', 2, %s, NOW() - INTERVAL 5 HOUR, NOW() - INTERVAL 1 HOUR)" % CL)
T3 = sql("select ticket_id from tickets where ticket_number=910003"); T4 = sql("select ticket_id from tickets where ticket_number=910004")
s, tp3, h = req('/agent/ticket.php?ticket_id=' + T3); s4, tp4, h = req('/agent/ticket.php?ticket_id=' + T4)
check('ticket page: the ticket waiting (flagged On Hold) shows Paused, not Breached', s == 200 and 'data-sla-state="paused"' in tp3 and 'data-sla-state="breached"' not in tp3, s)
check('ticket page: the same dates in a running status still show Breached', s4 == 200 and 'data-sla-state="breached"' in tp4, s4)
sql("set session sql_mode=''; insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_status, ticket_client_id, ticket_created_at, ticket_sla_resolution_due) values ('T', 910005, 'slae2e', %s, %s, NOW() - INTERVAL 5 HOUR, NOW() + INTERVAL 2 HOUR)" % (OH, CL))
T5 = sql("select ticket_id from tickets where ticket_number=910005")
s5, tp5, h = req('/agent/ticket.php?ticket_id=' + T5)
check('viewing a waiting ticket whose status was changed by a path that never told the SLA code stamps its pause and shows Paused', s5 == 200 and 'data-sla-state="paused"' in tp5 and sql("select ticket_sla_paused_at is not null from tickets where ticket_number=910005") == '1', s5)
s, tl, h = req('/agent/tickets.php?client_id=' + CL); check('ticket list renders', s == 200, s)
s, db, h = req('/agent/dashboard.php'); check('dashboard renders', s == 200, s)
sql("delete from clients where client_name='SLA E2E Client'")
sql("delete from tickets where ticket_subject='slae2e'; delete from ticket_statuses where ticket_status_name like 'E2E %'")
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
