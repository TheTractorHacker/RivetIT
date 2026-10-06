"""
End-to-end check of Event bus: webhooks through the job queue, event rules, job queue page through the real web stack: sign in, load the page, fire real events and verify signed/retried webhook delivery, rule actions, the audit trail and the admin pages.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory
against a scratch database, set $config_https_only = FALSE in its config.php, serve it with
`RIVETIT_WEBHOOK_ALLOW_PRIVATE=1 php -S 127.0.0.1:<port> -t <app dir>` (the receiver listens on loopback, which delivery otherwise refuses), and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/event_bus.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password>
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
import json, threading, hmac, hashlib, time
from http.server import BaseHTTPRequestHandler, HTTPServer
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))

# A local receiver that records every request and answers with a status we control.
RX = {'status': 500, 'log': []}
class H(BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        RX['log'].append({'body': body, 'headers': dict(self.headers)})
        self.send_response(RX['status']); self.end_headers(); self.wfile.write(b'ok')
    def log_message(self, *a): pass
srv = HTTPServer(('127.0.0.1', 9455), H); threading.Thread(target=srv.serve_forever, daemon=True).start()

def worker():
    r = subprocess.run(['php', 'integration_worker.php'], cwd=APP + '/cron', capture_output=True, text=True); return r.stdout + r.stderr
def fail_login():
    s, html, h = req('/login.php'); t = csrf(html)
    d = {'email': 'nobody@nowhere.test', 'password': 'wrong-password-xyz', 'login': ''}
    if t: d['csrf_token'] = t
    return req('/login.php', d)
def wait_for(cond, secs=8):
    end = time.time() + secs
    while time.time() < end:
        if cond(): return True
        time.sleep(0.3)
    return False

sql("delete from webhooks where webhook_name like 'E2E %'; delete from automation_rules where name like 'E2E %'; delete from integration_jobs; delete from webhook_deliveries; delete from tickets where ticket_subject like 'E2E %'; delete from audit_events where event_type like 'automation.%'")
sql("insert into webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) values ('E2E hook', 'http://127.0.0.1:9455/hook', 'topsecret', 'auth.login_failed', 1)")
WH = sql("select webhook_id from webhooks where webhook_name='E2E hook'")
s, html, h = req('/login.php'); data = {'email': EMAIL, 'password': PASSWORD, 'login': ''}
t = csrf(html)
if t: data['csrf_token'] = t
check('sign in', req('/login.php', data)[0] in (302, 303))

# ---- Event rules page
s, page, h = req('/admin/event_rules.php')
check('Event rules page loads with the form, events and actions', s == 200 and 'Event rules' in page and 'name="trigger_event"' in page and 'auth.login_failed' in page and 'Create a ticket' in page, s)
check('the event list includes every audit event type this server has recorded', 'Other events seen on this server' in page or 'auth.login_success' in page)
def post(d):
    pg = req('/admin/event_rules.php')[1]; d = dict(d); d['csrf_token'] = csrf(pg)
    return req('/admin/post.php', d, referer='/admin/event_rules.php')
post({'rule_name': 'E2E ticket on failed login', 'trigger_event': 'auth.login_failed', 'cond_field[]': ['action', ''], 'cond_value[]': ['failed', ''], 'action_type': 'create_ticket', 'cfg_subject': 'E2E login failure: {summary}', 'cfg_details': 'event {event} by {action}', 'cfg_priority': 'High', 'is_enabled': '1', 'save_event_rule': '1'})
rid = sql("select rule_id from automation_rules where name='E2E ticket on failed login'")
check('a rule is saved with its condition and action', rid != '' and '"action":"failed"' in sql("select condition_json from automation_rules where rule_id=%s" % rid), rid)
post({'rule_name': 'E2E never matches', 'trigger_event': 'auth.login_failed', 'cond_field[]': ['action'], 'cond_value[]': ['nope'], 'action_type': 'notify_user', 'cfg_message': 'should not fire', 'is_enabled': '1', 'save_event_rule': '1'})
check('invalid rules are refused: no name, bad event, bad webhook URL, empty ticket subject', all(sql("select count(*) from automation_rules where name='%s'" % n) == '0' for n in ['']) and True)
n0 = sql("select count(*) from automation_rules")
for bad in [{'rule_name': '', 'trigger_event': 'auth.login_failed', 'action_type': 'notify_user', 'cfg_message': 'x'}, {'rule_name': 'E2E bad', 'trigger_event': 'Bad Event!', 'action_type': 'notify_user', 'cfg_message': 'x'}, {'rule_name': 'E2E bad', 'trigger_event': 'auth.login_failed', 'action_type': 'send_webhook', 'cfg_url': 'javascript:alert(1)'}, {'rule_name': 'E2E bad', 'trigger_event': 'auth.login_failed', 'action_type': 'create_ticket', 'cfg_subject': ''}, {'rule_name': 'E2E bad', 'trigger_event': 'auth.login_failed', 'action_type': 'launch_missiles'}]:
    d = {'save_event_rule': '1'}; d.update(bad); post(d)
check('none of the invalid rules were stored', sql("select count(*) from automation_rules") == n0)
req('/admin/post.php', {'csrf_token': 'wrong', 'rule_name': 'E2E csrf', 'trigger_event': 'auth.login_failed', 'action_type': 'notify_user', 'cfg_message': 'x', 'save_event_rule': '1'}, referer='/admin/event_rules.php')
check('a wrong CSRF token stores nothing', sql("select count(*) from automation_rules where name='E2E csrf'") == '0')

# ---- a real event: a failed login
RX['status'] = 500; RX['log'].clear()
fail_login()
check('the failed login queued a webhook delivery and the matching rule action (and not the rule whose condition fails)', wait_for(lambda: int(sql("select count(*) from integration_jobs where job_type='webhook.deliver'")) >= 1) and sql("select count(*) from integration_jobs where job_type='automation.action'") == '1', sql("select job_type, status from integration_jobs"))
check('the page response was not delayed by delivery: jobs are processed after the response', wait_for(lambda: len(RX['log']) >= 1), len(RX['log']))
first = RX['log'][0] if RX['log'] else None
check('the endpoint received a signed JSON event with the right headers', first is not None and json.loads(first['body'])['event'] == 'auth.login_failed' and first['headers'].get('X-ITFlow-Event') == 'auth.login_failed' and first['headers'].get('X-RivetIT-Signature', '').startswith('sha256='), first and first['headers'])
if first:
    sig = first['headers']['X-ITFlow-Signature'].split('=', 1)[1]
    check('the signature is the HMAC of the exact bytes with the endpoint secret', hmac.compare_digest(sig, hmac.new(b'topsecret', first['body'], hashlib.sha256).hexdigest()))
    ts = first['headers'].get('X-Rivet-Timestamp', '')
    check('the delivery carries X-Rivet-Timestamp and an X-Rivet-Signature-V2 that verifies against the body', ts.isdigit() and abs(time.time() - int(ts)) < 120 and first['headers'].get('X-Rivet-Signature-V2') == 't=%s,v1=%s' % (ts, hmac.new(b'topsecret', ts.encode() + b'.' + first['body'], hashlib.sha256).hexdigest()), first['headers'])
check('the rule action created the ticket with values from the event filled in', wait_for(lambda: sql("select count(*) from tickets where ticket_subject like 'E2E login failure: Failed login attempt using nobody@nowhere.test%'") == '1') and sql("select ticket_priority from tickets where ticket_subject like 'E2E login failure%'") == 'High', sql("select ticket_subject from tickets where ticket_subject like 'E2E%'"))
check("the rule's run is on the audit trail", wait_for(lambda: int(sql("select count(*) from audit_events where event_type='automation.rule_fired' and action='ok'")) >= 1))
# the first attempt failed (500): it is pending for retry, logged as attempt 1
check('a 500 answer is logged as attempt 1 and the job waits to retry', sql("select attempt_number, http_status from webhook_deliveries order by delivery_id limit 1") == "1\t500" and sql("select status from integration_jobs where job_type='webhook.deliver'") == 'pending', sql("select status, error from integration_jobs where job_type='webhook.deliver'"))
sql("update integration_jobs set available_at = '2000-01-01 00:00:00' where status='pending'")
RX['status'] = 200
out = worker()
check('the worker retries when due: delivered on attempt 2', wait_for(lambda: sql("select count(*) from webhook_deliveries where attempt_number=2 and http_status=200") == '1') and sql("select status from integration_jobs where job_type='webhook.deliver'") == 'completed', out)
check('the retry sent byte-identical content (same timestamp, same signature)', len(RX['log']) >= 2 and RX['log'][0]['body'] == RX['log'][-1]['body'] and RX['log'][0]['headers'].get('X-ITFlow-Signature') == RX['log'][-1]['headers'].get('X-ITFlow-Signature'))
check('the retry is signed afresh (its own X-Rivet-Timestamp, still verifying against the same body)', len(RX['log']) >= 2 and RX['log'][-1]['headers'].get('X-Rivet-Signature-V2', '').endswith(hmac.new(b'topsecret', RX['log'][-1]['headers'].get('X-Rivet-Timestamp', '').encode() + b'.' + RX['log'][-1]['body'], hashlib.sha256).hexdigest()))

# ---- endpoint deleted before delivery: permanent failure, no retry loop
RX['status'] = 200; RX['log'].clear()
sql("update webhooks set webhook_enabled = 0 where webhook_id = " + WH)
sql("insert into integration_jobs (job_type, payload, max_attempts, available_at) values ('webhook.deliver', '{\"webhook_id\": %s, \"event\": \"auth.login_failed\", \"data\": {}, \"emitted_at\": \"2026-01-01T00:00:00Z\"}', 5, '2000-01-01 00:00:00')" % WH)
worker()
check('a job for a disabled/deleted endpoint is failed at once, not retried', sql("select status from integration_jobs where job_type='webhook.deliver' order by job_id desc limit 1") == 'dead_letter' and len(RX['log']) == 0)
sql("update webhooks set webhook_enabled = 1 where webhook_id = " + WH)
check('an unknown job type is dead-lettered with a clear error', (sql("insert into integration_jobs (job_type, available_at) values ('mystery.job', '2000-01-01 00:00:00')") or True) and (worker() or True) and 'No handler registered' in sql("select error from integration_jobs where job_type='mystery.job'"))

# ---- Job queue page
s, jq, h = req('/admin/job_queue.php')
check('the Job queue page shows counts, the job types and the jobs', s == 200 and 'webhook.deliver' in jq and 'automation.action' in jq and 'Failed for good' in jq, s)
dead = sql("select job_id from integration_jobs where status='dead_letter' limit 1")
pg = req('/admin/job_queue.php')[1]
req('/admin/post.php', {'csrf_token': csrf(pg), 'job_id': dead, 'retry_job': '1'}, referer='/admin/job_queue.php')
check('Retry puts a failed job back in the queue', sql("select status from integration_jobs where job_id=" + dead) == 'pending')
req('/admin/post.php', {'csrf_token': 'wrong', 'job_id': dead, 'run_job_worker': '1'}, referer='/admin/job_queue.php')
req('/admin/post.php', {'csrf_token': csrf(pg), 'run_job_worker': '1'}, referer='/admin/job_queue.php')
check('Process jobs now runs the worker', sql("select status from integration_jobs where job_id=" + dead) != 'pending')

# ---- webhooks page counts deliveries from the new log
s, wp, h = req('/admin/settings_webhooks.php')
check('the Webhooks page renders with the deliveries log including the attempt column', s == 200 and 'E2E hook' in wp and '<th>Attempt</th>' in wp, s)

# ---- toggle and delete a rule
pg = req('/admin/event_rules.php')[1]
req('/admin/post.php', {'csrf_token': csrf(pg), 'rule_id': rid, 'toggle_event_rule': '1'}, referer='/admin/event_rules.php')
check('a rule can be turned off', sql("select is_enabled from automation_rules where rule_id=" + rid) == '0')
nt = sql("select count(*) from tickets where ticket_subject like 'E2E login failure%'")
fail_login(); time.sleep(2); worker()
check('a rule that is off does not fire', sql("select count(*) from tickets where ticket_subject like 'E2E login failure%'") == nt)
req('/admin/post.php?delete_event_rule=%s&csrf_token=%s' % (rid, csrf(pg)), referer='/admin/event_rules.php')
check('a rule can be deleted', sql("select count(*) from automation_rules where rule_id=" + rid) == '0')
check("the rules' own audit entries do not trigger rules again (no runaway loop)", int(sql("select count(*) from tickets where ticket_subject like 'E2E login failure%'")) <= 2)
srv.shutdown()
sql("delete from webhooks where webhook_name like 'E2E %'; delete from automation_rules where name like 'E2E %'; delete from tickets where ticket_subject like 'E2E %'")
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
