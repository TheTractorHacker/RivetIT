"""
End-to-end check of the Event rules page (list, editor, recipes, test and history drawers' JSON endpoint) through the real web stack:
the rule list (summary sentences, badges, search, filters, sort, statistics), the live on/off toggle, duplicate, reorder, delete, the editor
(every action type, nested condition groups round trip, kept input after a failed save), recipes, the dry-run Test endpoint with proof that it
has no side effects (no ticket, no webhook, no notification, no queued job), "run for real" for notify rules only, the history endpoint,
permissions (a technician is refused), CSRF and hostile input.

Needs a THROWAWAY copy of the app (never a real site): install it with scripts/setup_cli.php run from its scripts/ directory against a scratch
database, migrate it to the latest version, set $config_https_only = FALSE in its config.php, serve it with
`RIVETIT_WEBHOOK_ALLOW_PRIVATE=1 php -S 127.0.0.1:<port> -t <app dir>`, and give this script the scratch database credentials in TEST_DB_USER / TEST_DB_PASS.
A tech@scratch.test technician is created by the script.

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/event_rules_ui.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> <app dir>
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error, threading, time
from http.server import BaseHTTPRequestHandler, HTTPServer
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]; APP = sys.argv[5]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']
PAGE = '/admin/event_rules.php'; API = '/admin/modals/event_rules_api.php'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
def session():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)
def req(op, path, data=None, referer=None):
    if data is not None:  # PHP wants name[] for lists
        data = {(k + '[]' if isinstance(v, list) and not k.endswith(']') else k): v for k, v in data.items()}
    headers = {}
    if referer: headers['Referer'] = BASE + referer
    body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    r = urllib.request.Request(BASE + path, data=body, headers=headers)
    try:
        resp = op.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', "SET SESSION time_zone = '+00:00'; " + q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail)[:400] + ']') if detail and not ok else ''))
def login(op, email, pw):
    s, html, h = req(op, '/login.php'); d = {'email': email, 'password': pw, 'login': ''}
    t = csrf(html)
    if t: d['csrf_token'] = t
    return req(op, '/login.php', d)[0] in (302, 303)

admin = session()
check('sign in', login(admin, EMAIL, PASSWORD))
TOK = csrf(req(admin, PAGE)[1])
def post(d, op=None, page=PAGE):
    d = dict(d); d['csrf_token'] = TOK if op is None else csrf(req(op, page)[1])
    return req(op or admin, '/admin/post.php', d, referer=page)
def api_post(a, d=None, op=None, token=None):
    d = dict(d or {}); d['a'] = a; d['csrf_token'] = TOK if token is None else token
    s, body, h = req(op or admin, API, d)
    try: return s, json.loads(body)
    except Exception: return s, {'raw': body[:200]}
def api_get(a, q=None, op=None):
    s, body, h = req(op or admin, API + '?' + urllib.parse.urlencode(dict(q or {}, a=a)))
    try: return s, json.loads(body)
    except Exception: return s, {'raw': body[:200]}
def state_of(html):
    m = re.search(r'<script type="application/json" id="er-data">(.*?)</script>', html, re.S)
    return json.loads(m.group(1)) if m else None
def rid(name): return sql("select rule_id from automation_rules where name='%s'" % name.replace("'", "''"))
def count(table, where='1'): return int(sql("select count(*) from %s where %s" % (table, where)))
def base_rule(name, **kw):
    d = {'rule_name': name, 'trigger_event': 'ticket.created', 'action_type': 'notify_user', 'cfg_message': 'hello', 'is_enabled': '1', 'save_event_rule': '1'}; d.update(kw); return d

# A local receiver that records every request (a dry run must never reach it).
RX = {'log': []}
class H(BaseHTTPRequestHandler):
    def do_POST(self):
        RX['log'].append(self.rfile.read(int(self.headers.get('Content-Length', 0))))
        self.send_response(200); self.end_headers(); self.wfile.write(b'ok')
    def log_message(self, *a): pass
srv = HTTPServer(('127.0.0.1', 9457), H); threading.Thread(target=srv.serve_forever, daemon=True).start()

assert 'scratch' in DB, 'this script empties the automation rules: scratch databases only'
sql("delete from automation_rule_runs; delete from automation_rules")  # the list assertions need a known set of rules
sql("delete from tickets where ticket_subject like 'UI %'; delete from users where user_email='tech@scratch.test'")
AGENT = sql("select user_id from users where user_type=1 and user_status=1 order by user_id limit 1")
AGENT_NAME = sql("select user_name from users where user_id=%s" % AGENT)
hashed = subprocess.run(['php', '-r', 'echo password_hash(getenv("P"), PASSWORD_DEFAULT);'], capture_output=True, text=True, env=dict(os.environ, P='Tech-Pass-12345!')).stdout
sql("insert into users (user_name, user_email, user_password, user_type, user_status, user_role_id) values ('Scratch Tech', 'tech@scratch.test', '%s', 1, 1, 2)" % hashed)
def mk_ticket(subject, priority):
    n = int(sql("select coalesce(max(ticket_number),0)+1 from tickets"))
    sql("insert into tickets (ticket_prefix, ticket_number, ticket_subject, ticket_details, ticket_priority, ticket_status, ticket_created_by, ticket_url_key, ticket_client_id, ticket_contact_id) values ('T-', %d, '%s', 'd', '%s', 1, %s, 'k%d', 0, 0)" % (n, subject, priority, AGENT, n))
    return sql("select ticket_id from tickets where ticket_subject='%s'" % subject)
T_HIGH = mk_ticket('UI VPN is down', 'High'); T_LOW = mk_ticket('UI Printer jam', 'Low')

# ================================================================== page states and structure
s, new_page, h = req(admin, PAGE + '?new=1')
d = state_of(new_page)
check('the editor (?new) has the four cards, the single event picker, the action grid and the live summary panel', s == 200 and all(x in new_page for x in ['id="er-sec-trigger"', 'id="er-sec-conditions"', 'id="er-sec-action"', 'id="er-sec-settings"', 'data-event-picker', 'data-mode="single"', 'name="trigger_event"', 'id="er-summary"', 'id="er-builder"', 'Rule summary']), s)
check('the editor offers every action of the registry with its description', all(l in new_page for l in ['Create a ticket', 'Send a webhook', 'Notify a user', 'Add an internal note to the ticket', 'Assign the ticket', 'Send an email', 'Add a task to the ticket', 'Set ticket fields']) and 'name="action_type" value="start_workflow"' in new_page)
check('the page hands its data to the script as JSON (smart value selects: priorities, statuses, people)', d and d['view'] == 'editor' and d['priorities'] == ['Low', 'Medium', 'High', 'Critical'] and 'Open' in d['statuses'] and str(AGENT) in d['agents'] and 'maxConditions' in d and d['operators'].get('contains'), str(d)[:200])
check('the script is loaded only on this page', '/js/event_rules.js' in new_page and '/js/event_rules.js' not in req(admin, '/admin/automation.php')[1])
js = req(admin, '/js/event_rules.js')[1]
check('the script contains the builder, the placeholder chips, the drawers and the drag-and-drop ordering', all(x in js for x in ['Add condition', 'Insert a value', 'openTest', 'openHistory', 'dragstart', 'ticket_priority']))
css = req(admin, '/css/itflow_custom.css')[1]
check('the styles are appended to itflow_custom.css (after the event picker styles) and use theme tokens', css.rfind('.er-rule') > css.rfind('.event-picker') and 'var(--tblr-border-color' in css[css.rfind('Event rules (admin'):])
s, empty, h = req(admin, PAGE)
check('with no rules the list shows the empty state with the recipe gallery', s == 200 and 'No rules yet' in empty and 'class="er-recipe"' in empty and 'Tell me when a High or Critical ticket arrives' in empty and 'name="csrf_token"' in empty, s)
check('the picker still offers events (no-JS list)', 'auth.login_failed' in new_page and '<noscript>' in new_page)

# ================================================================== recipes prefill, nothing saved
before = count('automation_rules')
s, rp, h = req(admin, PAGE + '?new=1&recipe=critical-ticket')
st = state_of(rp)['state']
check('a recipe prefills the editor (event, condition, action, message) and saves nothing', s == 200 and st['trigger'] == 'ticket.created' and st['rows'][0]['op'] == 'in' and st['rows'][0]['value'] == 'High,Critical' and st['action'] == 'notify_user' and 'Urgent ticket' in rp and count('automation_rules') == before and 'name="trigger_event" value="ticket.created"' in rp, str(st)[:200])
recipes = re.findall(r'href="event_rules.php\?new=1&amp;recipe=([a-z-]+)"', empty)
check('the gallery lists 8 to 12 recipes', 8 <= len(recipes) <= 12, recipes)
bad = []
for k in recipes:
    s, rp, h = req(admin, PAGE + '?new=1&recipe=' + k); stt = state_of(rp)
    if s != 200 or not stt or stt['state']['recipe'] != k or not stt['state']['trigger'] or not stt['state']['action']: bad.append(k)
check('every recipe opens as a valid prefilled editor', not bad, bad)
check('an unknown recipe is ignored (blank editor)', state_of(req(admin, PAGE + '?new=1&recipe=nope')[1])['state']['trigger'] == '')
# save from a recipe exactly as the form would post it
s, _, h = post(base_rule('RCP critical', cond_field=['ticket_priority'], cond_op=['in'], cond_value=['High,Critical'], cond_group=[''], cond_mode='all', cfg_message='Urgent ticket {ticket_number}: {ticket_subject} ({client_name})'))
check('a recipe is saved like any rule when the administrator presses Save', rid('RCP critical') != '' and 'High,Critical' in sql("select condition_json from automation_rules where name='RCP critical'") and s in (302, 303), s)

# ================================================================== create via the editor: every action type
cases = {
    'create_ticket': dict(cfg_subject='UI made: {summary}', cfg_details='d {event}', cfg_priority='High'),
    'send_webhook': dict(cfg_url='http://127.0.0.1:9457/hook', cfg_secret='s3'),
    'notify_user': dict(cfg_message='UI note {ticket_number}'),
    'start_workflow': dict(),
    'set_ticket_field': dict(cfg_sf_status='Open', cfg_sf_priority='High', cfg_sf_category='0', cfg_sf_assignee=AGENT),
    'add_ticket_note': dict(cfg_note='UI internal note'),
    'assign_ticket': dict(cfg_as_mode='user', cfg_as_user=AGENT),
    'send_mail': dict(cfg_mail_to='address', cfg_mail_address='ops@example.test', cfg_mail_subject='UI mail', cfg_mail_body='body {ticket_number}'),
    'create_task': dict(cfg_task_name='UI task {ticket_number}', cfg_task_assignee=AGENT, cfg_task_due_days='2'),
}
tpl = sql("select workflow_template_id from workflow_templates where archived_at is null and is_active=1 limit 1")
if not tpl:
    sql("insert into workflow_templates (name, type, is_active) values ('UI Offboarding', 'offboarding', 1)")
    tpl = sql("select workflow_template_id from workflow_templates where name='UI Offboarding'")
if tpl:
    cases['start_workflow'] = dict(cfg_template_id=tpl)
else:
    del cases['start_workflow']
for act, cfg in cases.items():
    trig = 'employee.terminated' if act == 'start_workflow' else 'ticket.created'
    post(base_rule('UI act ' + act, trigger_event=trig, action_type=act, **cfg))
    r = rid('UI act ' + act)
    stored = sql("select action_type, action_config_json from automation_rules where rule_id=%s" % r) if r else ''
    check('the editor saves a "%s" rule with its config' % act, r != '' and stored.startswith(act + '\t{'), stored)
    if r:
        s, ep, h = req(admin, PAGE + '?edit=' + r); ed = state_of(ep)
        check('editing the "%s" rule shows its stored config in the form' % act, s == 200 and ed['state']['action'] == act and ed['state']['rule_id'] == int(r) and all(('value="%s"' % v in ep) or (str(v) in ep) for v in cfg.values()), ed['state']['form'] if ed else s)

# ================================================================== nested conditions: what is saved is what the form shows
nested = base_rule('UI nested', cond_mode='all', cond_field=['ticket_priority', 'client_id', 'ticket_subject', 'ticket_status', ''], cond_op=['in', 'eq', 'contains', 'ne', 'eq'], cond_value=['High,Critical', '4', 'VPN', 'Closed', ''], cond_group=['', 'A', 'A', 'B', ''], **{'group_mode[A]': 'any', 'group_mode[B]': 'all'})
post(nested)
nid = rid('UI nested')
stored = json.loads(sql("select condition_json from automation_rules where rule_id=%s" % nid))
check('nested groups are stored in the engine model (all of: [in], any of [eq, contains], all of [ne])', stored.get('version') == 2 and stored['mode'] == 'all' and len(stored['conditions']) == 3 and stored['conditions'][1]['mode'] == 'any' and stored['conditions'][2]['mode'] == 'all', stored)
s, ep, h = req(admin, PAGE + '?edit=' + nid); ed = state_of(ep)['state']
check('the editor shows the same structure that was saved (rows, groups, group modes)', ed['mode'] == 'all' and [(r['field'], r['op'], r['value'], r['group']) for r in ed['rows']] == [('ticket_priority', 'in', 'High,Critical', ''), ('client_id', 'eq', '4', 'A'), ('ticket_subject', 'contains', 'VPN', 'A'), ('ticket_status', 'ne', 'Closed', 'B')] and ed['groupModes'] == {'A': 'any', 'B': 'all'}, ed)
# round trip: post the rows the editor shows, the stored JSON must not change
rows = ed['rows']
post(base_rule('UI nested', rule_id=nid, cond_mode=ed['mode'], cond_field=[r['field'] for r in rows], cond_op=[r['op'] for r in rows], cond_value=[r['value'] for r in rows], cond_group=[r['group'] for r in rows], **{'group_mode[%s]' % g: m for g, m in ed['groupModes'].items()}))
check('saving what the editor shows leaves the stored conditions unchanged (round trip)', json.loads(sql("select condition_json from automation_rules where rule_id=%s" % nid)) == stored)
# many groups and the 20 condition limit
many = base_rule('UI many groups', cond_mode='any', cond_field=['a', 'b', 'c', 'd', 'e'], cond_op=['eq'] * 5, cond_value=['1'] * 5, cond_group=['A', 'B', 'C', 'D', 'E'], **{'group_mode[%s]' % g: 'all' for g in 'ABCDE'})
post(many)
check('up to ten groups are supported', rid('UI many groups') != '' and len(json.loads(sql("select condition_json from automation_rules where name='UI many groups'"))['conditions']) == 5)
n0 = count('automation_rules')
post(base_rule('UI too many', cond_field=['f%d' % i for i in range(21)], cond_op=['eq'] * 21, cond_value=['v'] * 21, cond_group=[''] * 21))
check('more than 20 conditions are refused', count('automation_rules') == n0)

# ================================================================== failed save keeps the input and lists the problems
s, _, h = post(base_rule('UI keep me', trigger_event='ticket.created', action_type='send_webhook', cfg_url='javascript:alert(1)', cond_field=['ticket_priority'], cond_op=['eq'], cond_value=['High'], cond_group=['']))
loc = h.get('Location', '')
check('a refused save sends the administrator back to the editor (not a blank page)', 'event_rules.php?new=1' in loc and 'draft=1' in loc, loc)
s, dp, h = req(admin, '/admin/' + loc.split('/')[-1] if not loc.startswith('/') else loc)
dst = state_of(dp)
check('the editor shows an error summary and keeps everything that was typed', s == 200 and 'The rule was not saved' in dp and 'id="er-error-summary"' in dp and dst['state']['name'] == 'UI keep me' and dst['state']['rows'][0]['value'] == 'High' and dst['state']['action'] == 'send_webhook' and 'javascript:alert(1)' in dp and dst['touched'] is True, dst['state'] if dst else s)
check('the kept input is shown once (a reload gives a blank editor)', state_of(req(admin, PAGE + '?new=1&draft=1')[1])['state']['name'] == '')
check('nothing was stored for the refused rule', rid('UI keep me') == '')
s, _, h = post(base_rule('UI draft edit', cond_field=['ticket_priority'], cond_op=['eq'], cond_value=['High'], cond_group=['']))
eid = rid('UI draft edit')
s, _, h = post(base_rule('UI draft edit', rule_id=eid, cfg_message='', action_type='notify_user'))
check('a refused edit goes back to that rule\'s editor', 'event_rules.php?edit=%s' % eid in h.get('Location', '') and 'draft=1' in h.get('Location', ''), h.get('Location'))

# ================================================================== list: summaries, badges, statistics
for n, kw in [('UI list high', dict(cond_field=['ticket_priority'], cond_op=['in'], cond_value=['High,Critical'], cond_group=[''])), ('UI list webhook', dict(trigger_event='ticket.sla_breached', action_type='send_webhook', cfg_url='http://127.0.0.1:9457/x')),
              ('UI list login', dict(trigger_event='auth.login_failed', cfg_message='bad login'))]:
    post(base_rule(n, **kw))
R_HIGH, R_HOOK, R_LOGIN = rid('UI list high'), rid('UI list webhook'), rid('UI list login')
sql("insert into automation_rule_runs (rule_id, event_type, matched, status, message, duration_ms, created_at) values (%s,'ticket.created',1,'ok','done',5,now()),(%s,'ticket.created',1,'ok','done',5,now()),(%s,'ticket.created',1,'failed','boom: nope',9,now()),(%s,'ticket.sla_breached',1,'ok','x',3, now() - interval 2 day),(%s,'ticket.sla_breached',1,'throttled','throttled: more than 30 runs in the last minute',0, now() - interval 1 hour)" % (R_HIGH, R_HIGH, R_HIGH, R_HOOK, R_HOOK))
s, lp, h = req(admin, PAGE)
check('the list shows a plain-English sentence per rule', 'When a ticket is created, and priority is High or Critical, then notify all technicians: &quot;hello&quot;.' in lp and 'When a ticket breaches its SLA, then send a webhook to 127.0.0.1.' in lp and 'When a sign-in fails, then notify all technicians' in lp, [m for m in re.findall(r'<p class="er-sentence">(.*?)</p>', lp)][:4])
check('the list shows trigger group badges with the event id, action badges and the order number', 'class="er-badge"' in lp and 'er-badge-action' in lp and '<code>ticket.created</code>' in lp and 'fa-ticket-alt' in lp and 'fa-paper-plane' in lp and '#100' in lp)
check('the list shows the last run (status badge, age), a 7 day sparkline with counts and the total', re.search(r'badge text-bg-danger">failed</span>', lp) and 'class="er-spark"' in lp and 'Last 7 days: 2 ok, 1 failed' in lp and '3 runs in total' in lp, '')
check('the summary strip counts rules, rules on and failed runs in 24 h', re.search(r'id="er-n-total">(\d+)<', lp) and int(re.search(r'id="er-n-total">(\d+)<', lp).group(1)) == count('automation_rules') and int(re.search(r'id="er-n-on">(\d+)<', lp).group(1)) == count('automation_rules', 'is_enabled=1') and re.search(r'er-bad">1</span><span class="er-tile-l">failed runs in the last 24 h', lp), re.findall(r'er-tile-n[^>]*>(\d+)', lp))
def names(html): return re.findall(r'<a class="er-name" href="event_rules.php\?edit=\d+">(.*?)</a>', html)
def lst(q): return names(req(admin, PAGE + '?' + urllib.parse.urlencode(q))[1])
check('search finds rules by name, by event id and by what they do', lst({'q': 'list high'}) == ['UI list high'] and 'UI list login' in lst({'q': 'auth.login'}) and 'UI list webhook' in lst({'q': 'webhook'}) and lst({'q': 'zzz-nothing'}) == [])
check('search matches the summary text (a value inside a condition)', 'UI list high' in lst({'q': 'critical'}))
check('the group filter keeps only rules of that event group', set(lst({'group': 'security'})) >= {'UI list login'} and 'UI list high' not in lst({'group': 'security'}) and 'UI list webhook' in lst({'group': 'tickets'}))
check('the action filter keeps only that action', 'UI list webhook' in lst({'action': 'send_webhook'}) and 'UI list high' not in lst({'action': 'send_webhook'}))
check('the failing filter keeps rules whose last run failed', lst({'state': 'failing'}) == ['UI list high'])
r_off = rid('UI act add_ticket_note')
sql("update automation_rules set is_enabled=0 where rule_id=%s" % r_off)
check('the on / off filters split enabled and disabled rules', 'UI act add_ticket_note' in lst({'state': 'off'}) and 'UI act add_ticket_note' not in lst({'state': 'on'}) and 'UI list high' in lst({'state': 'on'}))
check('sort by name (ascending and descending)', lst({'sort': 'name'}) == sorted(lst({'sort': 'name'}), key=str.lower) and lst({'sort': 'name', 'dir': 'desc'}) == lst({'sort': 'name'})[::-1])
check('the sort menu accepts "name_desc" style values (sort and direction in one control)', lst({'sort': 'name_desc'}) == lst({'sort': 'name'})[::-1] and 'value="name_desc" selected' in req(admin, PAGE + '?sort=name_desc')[1])
check('sort by event groups rules of one event together', (lambda l: [x for x in l] == l)(lst({'sort': 'trigger'})) and len(lst({'sort': 'trigger'})) == len(lst({})))
check('sort by last run puts rules that never ran first and the newest run last', lst({'sort': 'last_run'})[-1] in ('UI list high',) and lst({'sort': 'last_run'})[0] != 'UI list high')
check('sort by run count puts the busiest rule first when descending', lst({'sort': 'runs', 'dir': 'desc'})[0] == 'UI list high')
check('an unknown sort or filter value falls back safely (no error)', req(admin, PAGE + '?sort=%27%3Bdrop&dir=x&state=zz&action=nope&group=%3Cb%3E')[0] == 200)
s, empty_f, h = req(admin, PAGE + '?q=zzz-nothing')
check('a search with no result says so and offers to reset', 'No rule matches these filters' in empty_f)
check('drag handles and move buttons appear in the default (order) sort only', 'data-er-move="1"' in lp and 'draggable="true"' in lp and 'data-er-move' not in req(admin, PAGE + '?sort=name')[1] and 'data-er-move' not in req(admin, PAGE + '?q=UI')[1] and 'draggable="true"' not in req(admin, PAGE + '?state=on')[1])
check('the list has Edit, Test, History, Duplicate and Delete (with a CSRF-protected link) for each rule', all(x in lp for x in ['data-er-test', 'data-er-history', 'data-er-duplicate', 'post.php?delete_event_rule=%s&amp;csrf_token=' % R_HIGH, 'event_rules.php?edit=%s' % R_HIGH]))

# ================================================================== toggle, duplicate, reorder, delete
s, r = api_post('toggle', {'rule_id': R_LOGIN, 'enabled': '0'})
check('the toggle turns a rule off at once and the change persists', s == 200 and r.get('enabled') is False and sql("select is_enabled from automation_rules where rule_id=%s" % R_LOGIN) == '0', r)
check('the toggle leaves an audit row', count('audit_events', "event_type='automation.rule_toggled' and entity_id='%s'" % R_LOGIN) >= 1)
s, r = api_post('toggle', {'rule_id': R_LOGIN})
check('without a value the toggle flips the rule back on', r.get('enabled') is True and sql("select is_enabled from automation_rules where rule_id=%s" % R_LOGIN) == '1')
s, r = api_post('toggle', {'rule_id': 99999999})
check('toggling a rule that does not exist answers 404', s == 404 and r.get('ok') is False)
s, r = api_post('duplicate', {'rule_id': R_HIGH})
cid = str(r.get('id'))
row = sql("select name, is_enabled, trigger_event, condition_json = (select condition_json from automation_rules where rule_id=%s), action_config_json = (select action_config_json from automation_rules where rule_id=%s) from automation_rules where rule_id=%s" % (R_HIGH, R_HIGH, cid))
check('duplicate copies the rule as a disabled "(copy)" with the same conditions and action', s == 200 and row == 'UI list high (copy)\t0\tticket.created\t1\t1', row)
req(admin, '/admin/post.php?delete_event_rule=%s&csrf_token=%s' % (cid, TOK), referer=PAGE)
check('delete removes the rule (with the CSRF token) and the copy is gone', rid('UI list high (copy)') == '')
req(admin, '/admin/post.php?delete_event_rule=%s&csrf_token=wrong' % R_LOGIN, referer=PAGE)
check('delete with a wrong CSRF token deletes nothing', rid('UI list login') != '')
# reorder
a, b, c = [str(x) for x in (rid('UI act create_ticket'), rid('UI nested'), R_HIGH)]
s, r = api_post('reorder', {'ids[]': [c, a, b]})
check('reorder gives rules of one event the priorities 10, 20, 30 in the order given', s == 200 and [sql("select priority from automation_rules where rule_id=%s" % x) for x in (c, a, b)] == ['10', '20', '30'], r)
ordered = sql("select group_concat(rule_id order by priority, rule_id) from automation_rules where trigger_event='ticket.created' and rule_id in (%s,%s,%s)" % (a, b, c))
check('the engine order (priority) follows the new order', ordered == ','.join([c, a, b]), ordered)
s, r = api_post('reorder', {'ids[]': [a, R_LOGIN]})
check('reorder across different events is refused with an explanation', s == 400 and 'same event' in r.get('error', ''), r)
s, r = api_post('reorder', {'ids[]': [a]})
check('reorder with a single rule is refused', s == 400)

# ================================================================== API: security
tech = session()
check('technician sign in', login(tech, 'tech@scratch.test', 'Tech-Pass-12345!'))
s, body, h = req(tech, PAGE)
check('a technician cannot open the Event rules page', s == 403 or 'only for administrators' in body or 'access to this page' in body, s)
for a in ['event', 'recent', 'history']:
    s, j = api_get(a, {'event': 'ticket.created', 'rule_id': R_HIGH}, op=tech)
    check('a technician is refused by the "%s" endpoint (403)' % a, s == 403, (s, j))
tok_t = csrf(req(tech, '/agent/tickets.php')[1]) or 'x'
for a, d in [('toggle', {'rule_id': R_HIGH}), ('duplicate', {'rule_id': R_HIGH}), ('reorder', {'ids[]': [a, b]}), ('test', {'rule_id': R_HIGH}), ('run_now', {'rule_id': R_HIGH}), ('preview', {}), ('check', {})]:
    s, j = api_post(a, d, op=tech, token=tok_t)
    check('a technician is refused by the "%s" endpoint (403)' % a, s == 403, (s, j))
req(tech, '/admin/post.php', dict(base_rule('UI tech rule'), csrf_token=tok_t), referer=PAGE)
check('a technician cannot save rules through post.php', rid('UI tech rule') == '')
sql("update automation_rules set is_enabled=1 where rule_id=%s" % R_HIGH)
req(tech, '/admin/post.php', {'rule_id': R_HIGH, 'toggle_event_rule': '1', 'csrf_token': tok_t}, referer=PAGE)
check('a technician cannot toggle through post.php', sql("select is_enabled from automation_rules where rule_id=%s" % R_HIGH) == '1')
for a in ['toggle', 'duplicate', 'reorder', 'test', 'run_now', 'preview', 'check']:
    s, j = api_post(a, {'rule_id': R_HIGH, 'ids[]': [a, b]}, token='wrong-token')
    check('the "%s" endpoint refuses a wrong CSRF token (403) and changes nothing' % a, s == 403 and j.get('ok') is False, (s, j))
s, body, h = req(admin, API + '?a=toggle&rule_id=%s' % R_HIGH)
check('mutating endpoints do not work over GET', s == 403)
s, j = api_get('nope')
check('an unknown endpoint action answers 404', s == 404)
s, j = api_get('event', {'event': 'Bad Event!'})
check('a malformed event id is refused', s == 400)

# ================================================================== API: event info, recent samples, preview, check
s, j = api_get('event', {'event': 'ticket.created'})
paths = [f['path'] for f in j.get('fields', [])]
check('the event endpoint returns its label, description and the payload fields (with types and sample values)', s == 200 and j['label'] == 'Ticket created' and j['description'] and 'ticket_priority' in paths and 'ticket_subject' in paths and j['ticket'] is True and all('type' in f for f in j['fields']), j)
s, j = api_get('event', {'event': 'ticket.sla_breached'})
check('extra fields of the event (SLA clock) are included', 'sla_clock' in [f['path'] for f in j['fields']] and j['known'] is True)
s, j = api_get('event', {'event': 'made.up_event'})
check('an event the catalog does not know still answers (audit fields)', s == 200 and j['known'] is False and 'summary' in [f['path'] for f in j['fields']])
s, j = api_get('recent', {'event': 'ticket.created'})
check('recent samples list tickets for a ticket event, and a sample JSON', s == 200 and len(j['tickets']) >= 2 and any('UI VPN is down' in t['label'] for t in j['tickets']) and 'ticket_subject' in j['sample'])
s, j = api_post('preview', {'trigger_event': 'ticket.created', 'cond_field[]': ['ticket_priority'], 'cond_op[]': ['in'], 'cond_value[]': ['High,Critical'], 'cond_group[]': [''], 'action_type': 'assign_ticket', 'cfg_as_mode': 'user', 'cfg_as_user': AGENT, 'rule_name': 'x'})
check('preview returns the live sentence with the technician\'s name and no problems', s == 200 and j['valid'] is True and j['summary'] == 'When a ticket is created, and priority is High or Critical, then assign the ticket to %s.' % AGENT_NAME and j['problems'] == {}, j)
s, j = api_post('preview', {'trigger_event': '', 'action_type': 'send_webhook', 'cfg_url': 'ftp://10.9.9.9/x', 'rule_priority': '0', 'cond_field[]': ['n'], 'cond_op[]': ['gt'], 'cond_value[]': ['x'], 'cond_group[]': ['']})
check('preview reports problems per section: name, event, conditions, action (with the allowed-network message), settings', j['valid'] is False and set(j['problems']) == {'name', 'trigger', 'conditions', 'action', 'settings'} and 'allowed:' in j['problems']['action'], j)
s, j = api_post('preview', {'trigger_event': 'auth.login_failed', 'action_type': 'add_ticket_note', 'cfg_note': 'x', 'rule_name': 'n'})
check('preview explains a ticket action on a non-ticket event', 'about a ticket' in j['problems'].get('action', ''), j)
s, j = api_post('check', {'trigger_event': 'ticket.created', 'cond_field[]': ['ticket_priority'], 'cond_op[]': ['eq'], 'cond_value[]': ['High'], 'cond_group[]': [''], 'cond_mode': 'all'})
mine = [i for i in j.get('items', []) if 'UI ' in i['label']]
check('"check against recent events" says which recent tickets match, read only', s == 200 and j['total'] >= 2 and any(i['matched'] and 'UI VPN' in i['label'] for i in mine) and any((not i['matched']) and 'UI Printer' in i['label'] and i['failed'] for i in mine) and j['matched'] >= 1 and 'tickets' in j['basis'], j)
s, j = api_post('check', {'trigger_event': 'auth.login_failed', 'cond_field[]': [''], 'cond_op[]': ['eq'], 'cond_value[]': [''], 'cond_group[]': ['']})
check('"check against recent events" works for audit events too (counts from the audit trail)', s == 200 and 'audit trail' in j['basis'])
s, j = api_post('check', {'trigger_event': '', 'cond_field[]': ['a']})
check('"check against recent events" needs an event', s == 400)

# ================================================================== test drawer: dry run and no side effects
def snapshot():
    return {'tickets': count('tickets'), 'jobs': count('integration_jobs'), 'notifications': count('notifications'), 'runs': count('automation_rule_runs'), 'replies': count('ticket_replies'), 'tasks': count('tasks'), 'mail': count('email_queue') if sql("show tables like 'email_queue'") else 0, 'rx': len(RX['log'])}
snap = snapshot()
s, j = api_post('test', {'rule_id': R_HIGH, 'source': 'ticket', 'sample_ticket': T_HIGH})
check('dry run, conditions matched: shows the matched conditions and "WOULD notify"', s == 200 and j['matched'] is True and any(c['ok'] for c in j['conditions']) and j['action']['ok'] and 'WOULD notify' in j['action']['message'] and j['summary'].startswith('When a ticket is created') and j['can_run'] is True, j)
s, j = api_post('test', {'rule_id': R_HIGH, 'source': 'ticket', 'sample_ticket': T_LOW})
check('dry run, conditions NOT matched: says which condition failed and that nothing would run', s == 200 and j['matched'] is False and j['action'] is None and any((not c['ok']) and 'ticket_priority' in c['text'] and 'Low' in c['text'] for c in j['conditions']), j)
s, j = api_post('test', {'rule_id': R_HIGH, 'source': 'synthetic', 'synthetic_json': json.dumps({'ticket_priority': 'Critical', 'ticket_id': int(T_HIGH)})})
check('dry run with the administrator\'s own JSON event', s == 200 and j['matched'] is True and 'your own event data' in j['sample'], j)
s, j = api_post('test', {'rule_id': R_HIGH, 'source': 'synthetic', 'synthetic_json': '{not json'})
check('invalid JSON in the sample is refused politely', s == 400 and 'JSON' in j['error'], j)
s, j = api_post('test', {'rule_id': R_HIGH, 'source': 'synthetic', 'synthetic_json': '{"a":"' + 'x' * 30000 + '"}'})
check('a huge sample is refused', s == 400)
s, j = api_post('test', {'rule_id': R_HIGH, 'source': 'ticket', 'sample_ticket': '999999'})
check('an unknown sample ticket is refused', s == 400)
s, j = api_post('test', {'rule_id': R_HIGH, 'source': 'sample'})
check('a sample event with plausible values works', s == 200 and 'sample event' in j['sample'])
s, j = api_post('test', {'rule_id': '99999'})
check('testing a rule that does not exist answers 404', s == 404)
# unsaved editor form dry runs for every action that can run on a ticket event
for act, cfg in cases.items():
    if act == 'start_workflow': continue
    s, j = api_post('test', dict({'rule_name': 'unsaved', 'trigger_event': 'ticket.created', 'action_type': act, 'source': 'ticket', 'sample_ticket': T_HIGH, 'is_enabled': '1'}, **cfg))
    check('dry run of the unsaved "%s" form reports what it would do' % act, s == 200 and j['matched'] is True and j['action'] and j['action']['ok'] and j['action']['message'].startswith('WOULD'), j)
s, j = api_post('test', {'rule_name': 'unsaved', 'trigger_event': 'ticket.created', 'action_type': 'create_ticket', 'cfg_subject': '', 'source': 'sample'})
check('dry run of an invalid unsaved form explains what is wrong (422)', s == 422 and j['ok'] is False and 'subject' in j['error'].lower() and 'action' in j['problems'], j)
check('NO side effects from any dry run: no ticket, job, notification, run row, reply, task, mail or webhook request', snapshot() == snap, (snap, snapshot()))
# the same through the saved webhook rule
hook = rid('UI act send_webhook')
s, j = api_post('test', {'rule_id': hook, 'source': 'ticket', 'sample_ticket': T_HIGH})
check('dry run of a webhook rule says it WOULD POST, and the receiver gets nothing', j['action']['ok'] and 'WOULD POST' in j['action']['message'] and len(RX['log']) == snap['rx'], j)
# run for real
s, j = api_post('run_now', {'rule_id': R_HIGH, 'source': 'ticket', 'sample_ticket': T_HIGH})
check('"Run for real" on a notify rule runs it once: a notification and a run row appear', s == 200 and j['status'] == 'ok' and count('notifications') > snap['notifications'] and count('automation_rule_runs', "rule_id=%s" % R_HIGH) == 4, j)
s, j = api_post('run_now', {'rule_id': R_HIGH, 'source': 'ticket', 'sample_ticket': T_LOW})
check('"Run for real" refuses a sample the conditions do not match', s == 400 and 'would not run' in j['error'])
s, j = api_post('run_now', {'rule_id': rid('UI act create_ticket'), 'source': 'sample'})
check('"Run for real" is refused for rules that are not harmless (create ticket)', s == 403 and count('tickets', "ticket_subject like 'UI made%'") == 0, j)
s, j = api_post('run_now', {'rule_id': hook, 'source': 'sample'})
check('"Run for real" is refused for a webhook rule and nothing is sent', s == 403 and len(RX['log']) == snap['rx'], j)
sql("update automation_rules set is_enabled=0 where rule_id=%s" % R_HIGH)
s, j = api_post('run_now', {'rule_id': R_HIGH, 'source': 'ticket', 'sample_ticket': T_HIGH})
check('"Run for real" is refused while the rule is off', s == 400 and 'on' in j['error'])
sql("update automation_rules set is_enabled=1 where rule_id=%s" % R_HIGH)

# ================================================================== history drawer endpoint
s, j = api_get('history', {'rule_id': R_HIGH})
check('history lists every run with time, event, status, message and duration, newest first', s == 200 and j['total'] == 4 and j['rows'][0]['run_id'] > j['rows'][-1]['run_id'] and all(k in j['rows'][0] for k in ['created_at', 'event_type', 'status', 'message', 'duration_ms', 'age']) and j['counts'].get('ok') == 3 and j['counts'].get('failed') == 1, j)
s, j = api_get('history', {'rule_id': R_HIGH, 'failed': 1})
check('history can show failed runs only', j['total'] == 1 and j['rows'][0]['status'] == 'failed' and 'boom' in j['rows'][0]['message'])
s, j = api_get('history', {'rule_id': R_HOOK})
check('history shows rate-limit skips (throttled) and the rule\'s limit', j['counts'].get('throttled') == 1 and any(r['status'] == 'throttled' and 'throttled' in r['message'] for r in j['rows']) and j['rule']['rate'] == 30, j)
s, j = api_get('history', {'rule_id': 99999})
check('history of a missing rule answers 404', s == 404)
s, j = api_get('history', {'rule_id': R_HIGH, 'offset': 3})
check('history pages by offset', len(j['rows']) == 1 and j['total'] == 4)

# ================================================================== hostile input
xss = '<script>alert(1)</script>'
post(base_rule(xss, cfg_message='<img src=x onerror=alert(2)>', cond_field=['ticket_subject'], cond_op=['contains'], cond_value=['"><svg onload=alert(3)>'], cond_group=['']))
xid = rid(xss)
s, lp, h = req(admin, PAGE)
body_wo_json = re.sub(r'<script type="application/json" id="er-data">.*?</script>', '', lp, flags=re.S)
check('HTML in a rule name and message is escaped on the list (no raw tag reaches the page)', xid != '' and xss not in body_wo_json and '&lt;script&gt;alert(1)&lt;/script&gt;' in lp and '<img src=x' not in body_wo_json and '<svg onload' not in body_wo_json)
s, ep, h = req(admin, PAGE + '?edit=' + xid)
body_wo_json = re.sub(r'<script type="application/json" id="er-data">.*?</script>', '', ep, flags=re.S)
check('HTML in a rule is escaped in the editor (fields and embedded JSON cannot break out of their element)', xss not in body_wo_json and '<img src=x' not in body_wo_json and '</script><' not in re.search(r'id="er-data">(.*?)</script>', ep, re.S).group(1) and state_of(ep)['state']['name'] == xss, '')
s, j = api_post('preview', {'rule_name': xss, 'trigger_event': 'ticket.created', 'action_type': 'notify_user', 'cfg_message': xss})
check('the preview sentence is JSON text (the script renders it as text, never as HTML)', s == 200 and j['summary'].startswith('When a ticket is created'))
n0 = count('automation_rules')
post(base_rule('x' * 5000))
check('a 5000 character name is refused', count('automation_rules') == n0)
post(base_rule('UI huge message', cfg_message='m' * 200000))
check('a huge message is cut to the action limit (1000) and never crashes', rid('UI huge message') == '' or len(json.loads(sql("select action_config_json from automation_rules where name='UI huge message'"))['message']) == 1000)
post(base_rule('UI regexy', cond_field=['ticket_subject', 'ticket_subject'], cond_op=['contains', 'eq'], cond_value=['(((unclosed[', '.*'], cond_group=['', '']))
check('regex metacharacters in values are plain text: stored as typed, no error', json.loads(sql("select condition_json from automation_rules where name='UI regexy'"))['conditions'][0]['value'] == '(((unclosed[')
s, j = api_post('test', {'rule_id': rid('UI regexy'), 'source': 'synthetic', 'synthetic_json': json.dumps({'ticket_subject': 'a (((unclosed[ b'})})
check('a rule with invalid-regex-looking text still tests normally (matching is not regex based)', s == 200 and j['matched'] is False and any(c['ok'] for c in j['conditions']), j)
n0 = count('automation_rules')
post(base_rule('UI bad op', cond_field=['ticket_subject'], cond_op=['matches'], cond_value=['^a'], cond_group=['']))
check('an operator the engine does not have (matches, starts with) is refused', count('automation_rules') == n0)
post(base_rule('UI array input', **{'cond_field[][]': 'x', 'rule_name[]': 'y'}))
check('array-shaped input where text is expected does not crash the handler', req(admin, PAGE)[0] == 200)
post(base_rule('UI field inj', cond_field=['a b; drop'], cond_op=['eq'], cond_value=['v'], cond_group=['']))
check('a field name with spaces or SQL is refused', rid('UI field inj') == '')
s, j = api_post('test', {'rule_id': R_HIGH, 'source': 'audit', 'sample_audit': "1 OR 1=1"})
check('a non-numeric audit id in the sample is treated as an unknown sample', s == 400)
s, bodyx, h = req(admin, PAGE + '?edit=%27%20or%201%3D1')
check('a hostile edit id shows the list with a notice, not an error', s == 200 and 'no longer exists' in bodyx)
s, bodyx, h = req(admin, PAGE + '?q=' + urllib.parse.quote('"><script>alert(9)</script>'))
check('a hostile search string is escaped in the search box', s == 200 and '"><script>alert(9)' not in bodyx and '&quot;&gt;&lt;script&gt;' in bodyx)

# ================================================================== engine still behaves (existing rules fire from the list's rules)
sql("update automation_rules set is_enabled=0 where name like 'UI %' or name like 'RCP %'")

# ================================================================== clean up
srv.shutdown()
sql("delete from automation_rule_runs where rule_id in (select rule_id from automation_rules where name like 'UI %' or name like 'RCP %' or name like '%<script>%'); delete from automation_rules where name like 'UI %' or name like 'RCP %' or name like '%<script>%'; delete from tickets where ticket_subject like 'UI %'; delete from users where user_email='tech@scratch.test'; delete from workflow_templates where name='UI Offboarding'")
print("SUMMARY %d/%d passed" % (sum(1 for r in results if r[1]), len(results)))
sys.exit(0 if all(r[1] for r in results) else 1)
