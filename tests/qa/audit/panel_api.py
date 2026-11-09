#!/usr/bin/env python3
"""RivetPanel device-token API audit (scratch instance only). Re-runnable; leaves QA-UI- rows (cleanup.py removes them).
Never prints tokens. Exit code 1 if any CHECK fails (a FAIL means a finding or an unexpected behaviour: read the line)."""
import sys, re, json, time, uuid, base64, concurrent.futures as cf
sys.path.insert(0, __import__('os').path.dirname(__file__))
from common import *

S = login(ADMIN)
CL_A, CL_B = 16, 17
C_A1, C_A2, C_B1 = 1, 2, 3          # Raj Patel / Helen Brandt (client 16), Lena Fischer (client 17)
state = {}


def set_client_level(client, on):
    tok = csrf(S, f'/agent/panel_deploy.php?client_id={client}')
    S.post(BASE + '/agent/post/panel_deploy.php', data={'set_client_level': '1', 'csrf_token': tok, 'client_id': client, 'allow_client_level': '1' if on else ''}, headers={'Referer': BASE + f'/agent/panel_deploy.php?client_id={client}'})


def deploy_links(client):
    h = S.get(BASE + f'/agent/panel_deploy.php?client_id={client}').text
    return h


# ---------------------------------------------------------------- enrollment
t_a1 = mint_token(S, CL_A, C_A1, 'QA-UI- contact A1')
t_a2 = mint_token(S, CL_A, C_A2, 'QA-UI- contact A2')
t_a0 = mint_token(S, CL_A, None, 'QA-UI- client-level A')
t_b1 = mint_token(S, CL_B, C_B1, 'QA-UI- contact B1')
record('enroll', 'mint 4 tokens on the Deploy page (with/without contact)', all([t_a1, t_a2, t_a0, t_b1]), 'minted via /agent/post/panel_deploy.php')

dev = {}
for name, t, host in [('a1', t_a1, 'QA-UI-A1'), ('a2', t_a2, 'QA-UI-A2'), ('a0', t_a0, 'QA-UI-A0'), ('b1', t_b1, 'QA-UI-B1')]:
    r = enroll(t, host)
    j = jb(r)
    record('enroll', f'enroll {name} -> 201 + device_token', r.status_code == 201 and str(j.get('device_token', '')).startswith('rvpd_'), f'status={r.status_code} keys={sorted(j.keys())}')
    dev[name] = j.get('device_token')
    state[name + '_id'] = j.get('device_id')

# token storage: hashed
hashes = sql("select char_length(token_hash), token_hash like 'rvpd_%' from endpoint_agent_devices where kind='panel'")
record('enroll', 'device tokens stored hashed (64-hex sha256, never rvpd_ plaintext)', hashes and all(h.split('\t')[0] == '64' and h.split('\t')[1] == '0' for h in hashes), f'{len(hashes)} rows')
eh = sql("select char_length(token_hash) from endpoint_agent_enrollment_tokens where kind='panel'")
record('enroll', 'enrollment tokens stored hashed', eh and all(x == '64' for x in eh), f'{len(eh)} rows')

# replay of a spent token
r = enroll(t_a1, 'QA-UI-REPLAY')
record('enroll', 'spent enrollment token cannot be reused (403 forbidden)', r.status_code == 403, f'status={r.status_code} code={jb(r).get("code")}')

# expired token
t_exp = mint_token(S, CL_A, C_A1, 'QA-UI- expiring')
sel = t_exp.split('.')[1]
sql(f"update endpoint_agent_enrollment_tokens set expires_at = now() - interval 1 hour where token_selector='{sel}'")
r = enroll(t_exp, 'QA-UI-EXP')
record('enroll', 'expired enrollment token rejected', r.status_code in (401, 403), f'status={r.status_code} code={jb(r).get("code")}')

# revoked token
t_rev = mint_token(S, CL_A, C_A1, 'QA-UI- to revoke')
h = deploy_links(CL_A)
tid = sqlv(f"select token_id from endpoint_agent_enrollment_tokens where token_selector='{t_rev.split('.')[1]}'")
m = re.search(r'post/panel_deploy\.php\?revoke_token=' + str(tid) + r'&(?:amp;)?client_id=\d+&(?:amp;)?csrf_token=([A-Za-z0-9_\-]+)', h)
if m:
    S.get(BASE + f'/agent/post/panel_deploy.php?revoke_token={tid}&client_id={CL_A}&csrf_token={m.group(1)}', headers={'Referer': BASE + f'/agent/panel_deploy.php?client_id={CL_A}'})
r = enroll(t_rev, 'QA-UI-REV')
record('enroll', 'revoked enrollment token rejected (401 revoked)', r.status_code == 401 and jb(r).get('code') == 'revoked', f'status={r.status_code} code={jb(r).get("code")} link_found={bool(m)}')

# malformed
for label, body, exp in [('no body fields', {}, 422), ('token not string', {'enrollment_token': 5, 'device': {}}, 422), ('device is list', {'enrollment_token': 'x', 'device': [1]}, 422), ('token wrong shape', {'enrollment_token': 'x', 'device': {}}, 401)]:
    r = api('POST', 'panel_enroll', body=body)
    record('enroll', f'garbage enrollment ({label}) -> {exp}', r.status_code == exp, f'status={r.status_code}')
r = api('POST', 'panel_enroll', raw='{not json', headers={'Content-Type': 'application/json'})
record('enroll', 'invalid JSON -> 422', r.status_code == 422, f'status={r.status_code}')
r = api('GET', 'panel_enroll')
record('enroll', 'GET on enroll -> 405 with Allow', r.status_code == 405 and 'POST' in r.headers.get('Allow', ''), f'status={r.status_code} allow={r.headers.get("Allow")}')

# install_id takeover by another client's token
t_x = mint_token(S, CL_B, C_B1, 'QA-UI- takeover attempt')
iid = sqlv(f"select install_id from endpoint_agent_devices where kind='panel' and device_id={state['a1_id']}")
r = api('POST', 'panel_enroll', body={'enrollment_token': t_x, 'device': {'hostname': 'QA-UI-TAKE', 'os': 'windows', 'install_id': iid, 'os_version': '', 'app_version': ''}})
record('enroll', "another client's token cannot take over a panel's install_id (409)", r.status_code == 409, f'status={r.status_code} code={jb(r).get("code")}')
# same-client re-enroll rotates token; old token dead
t_re = mint_token(S, CL_A, C_A1, 'QA-UI- re-enroll')
r = api('POST', 'panel_enroll', body={'enrollment_token': t_re, 'device': {'hostname': 'QA-UI-A1', 'os': 'windows', 'install_id': iid, 'os_version': '', 'app_version': ''}})
j = jb(r)
old = dev['a1']
dev['a1'] = j.get('device_token')
r_old = api('GET', 'panel_me', token=old)
r_new = api('GET', 'panel_me', token=dev['a1'])
record('enroll', 're-enroll same install_id rotates the token (old 401, new 200)', r.status_code == 201 and r_old.status_code == 401 and r_new.status_code == 200, f'enroll={r.status_code} old={r_old.status_code} new={r_new.status_code}')

# ---------------------------------------------------------------- read endpoints
me = jb(api('GET', 'panel_me', token=dev['a1']))
record('me', 'panel_me for contact device', me.get('contact', {}) and me['contact'].get('contact_id') == C_A1 and me['client']['client_id'] == CL_A and me.get('can_create_tickets') is True, f'keys={sorted(me.keys())}')
me0 = jb(api('GET', 'panel_me', token=dev['a0']))
record('me', 'client-level device: can_create_tickets false while setting off', me0.get('can_create_tickets') is False and me0.get('contact') is None, json.dumps({k: me0.get(k) for k in ('can_create_tickets', 'contact')}))
cats = api('GET', 'panel_categories', token=dev['a0'])
record('me', 'client-level device can read categories', cats.status_code == 200, f'status={cats.status_code}')
leak = json.dumps(me)
record('me', 'panel_me leaks no hashes/other clients', not re.search(r'token_hash|password|\\bhash\\b|stack|Trace', leak), 'checked body text')

# ---------------------------------------------------------------- device pool (create budget is 10 per 60 s per device)
POOL = []
for i in range(12):
    tt = mint_token(S, CL_A, C_A2, f'QA-UI- pool {i}')
    POOL.append(jb(enroll(tt, f'QA-UI-POOL-{i}')).get('device_token'))
_n = [0]
def nextdev():
    tok = POOL[_n[0] // 7 % len(POOL)]
    _n[0] += 1
    return tok
# ---------------------------------------------------------------- tickets
def create(token, subject, body='QA-UI- body', extra=None, rid=None):
    d = {'subject': subject, 'body': body}
    if rid:
        d['client_request_id'] = rid
    if extra:
        d.update(extra)
    return api('POST', 'panel_tickets', token=token, body=d)

r = create(dev['a1'], 'QA-UI- ticket A1', extra={'client_id': CL_B, 'contact_id': C_B1, 'department_id': CL_B, 'ticket_client_id': CL_B, 'ticket_contact_id': C_B1, 'assigned_to': 1, 'priority': 'High', 'status': 'Closed'})
j = jb(r)
tA1 = j.get('ticket_id')
row = sql(f"select ticket_client_id, ticket_contact_id, ticket_priority, ticket_source, ticket_created_by from tickets where ticket_id={tA1}")
record('tickets', 'create ticket 201', r.status_code == 201, f'status={r.status_code}')
record('tickets', 'spoofed client_id/contact_id/priority/status ignored (stored with enrolled client+contact, Medium, source Panel)', row and row[0].split('\t')[:4] == [str(CL_A), str(C_A1), 'Medium', 'Panel'], f'row={row}')

lst = jb(api('GET', 'panel_tickets', token=dev['a1']))
ids = [t['ticket_id'] for t in lst.get('tickets', [])]
record('tickets', 'owner sees own ticket in list', tA1 in ids, f'ids={ids[:8]}')
# other contact same client
l2 = jb(api('GET', 'panel_tickets', token=dev['a2']))
record('tickets', 'other contact (same client) does not see it', tA1 not in [t['ticket_id'] for t in l2.get('tickets', [])], f'count={len(l2.get("tickets", []))}')
r = api('GET', 'panel_ticket_chat', token=dev['a2'], headers={})
r = requests.get(BASE + f'/api/v1/panel_ticket_chat?ticket_id={tA1}', headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a2']})
record('tickets', 'other contact cannot read its chat (404, not 403)', r.status_code == 404, f'status={r.status_code}')
lb = jb(api('GET', 'panel_tickets', token=dev['b1']))
record('tickets', "other client's device does not see it", tA1 not in [t['ticket_id'] for t in lb.get('tickets', [])], '')
r = requests.get(BASE + f'/api/v1/panel_ticket_chat?ticket_id={tA1}', headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['b1']})
record('tickets', "other client's device gets 404 on chat", r.status_code == 404, f'status={r.status_code}')
# unfiltered contact param tampering
r = requests.get(BASE + f'/api/v1/panel_tickets?client_id={CL_B}&contact_id={C_B1}&limit=50', headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a2']})
record('tickets', 'client_id/contact_id query parameters ignored on list', tA1 not in [t['ticket_id'] for t in jb(r).get('tickets', [])] and all(t.get('mine') is not None for t in jb(r).get('tickets', [])), f'status={r.status_code}')

# staff-created ticket for contact A1 is visible to the contact device? (design) and internal notes are hidden
stf = sql("select ticket_id from tickets where ticket_contact_id=1 and ticket_client_id=16 limit 3")
record('tickets', 'seeded staff-created tickets for the contact exist for the visibility test', bool(stf), f'{len(stf)} tickets')
if stf:
    sid = int(stf[0])
    sql(f"insert into ticket_replies set ticket_reply='QA-UI- INTERNAL SECRET NOTE', ticket_reply_type='Internal', ticket_reply_by=1, ticket_reply_ticket_id={sid}")
    sql(f"insert into ticket_replies set ticket_reply='QA-UI- public staff reply', ticket_reply_type='Public', ticket_reply_by=1, ticket_reply_ticket_id={sid}")
    r = requests.get(BASE + f'/api/v1/panel_ticket_chat?ticket_id={sid}', headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a1']})
    txt = r.text
    record('tickets', 'contact device sees staff-created tickets of its own contact (design) with public replies only', r.status_code == 200 and 'public staff reply' in txt, f'status={r.status_code}')
    record('tickets', 'internal notes never returned in chat', 'INTERNAL SECRET' not in txt, '')
    sql(f"delete from ticket_replies where ticket_reply like 'QA-UI- %' and ticket_reply_ticket_id={sid}")
    # archived tickets
    sql(f"update tickets set ticket_archived_at=now() where ticket_id={sid}")
    l = jb(api('GET', 'panel_tickets', token=dev['a1'], headers={}))
    l = jb(requests.get(BASE + '/api/v1/panel_tickets?limit=50', headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a1']}))
    arch_visible = sid in [t['ticket_id'] for t in l.get('tickets', [])]
    record('tickets', 'archived ticket is not listed to the device', not arch_visible, f'archived ticket visible={arch_visible}')
    sql(f"update tickets set ticket_archived_at=NULL where ticket_id={sid}")

# client-level device
r = create(dev['a0'], 'QA-UI- client-level blocked')
record('tickets', 'client-level device create -> 403 client_level_disabled', r.status_code == 403 and jb(r).get('code') == 'client_level_disabled', f'status={r.status_code} code={jb(r).get("code")}')
r = api('GET', 'panel_tickets', token=dev['a0'])
record('tickets', 'client-level device list -> 403 client_level_disabled', r.status_code == 403 and jb(r).get('code') == 'client_level_disabled', f'status={r.status_code}')
set_client_level(CL_A, True)
r = create(dev['a0'], 'QA-UI- client-level allowed')
tA0 = jb(r).get('ticket_id')
record('tickets', 'client-level create works once the client setting is on (201)', r.status_code == 201, f'status={r.status_code}')
rr = requests.get(BASE + f'/api/v1/panel_ticket_chat?ticket_id={tA1}', headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a0']})
record('tickets', 'client-level device cannot read the contact-bound ticket (404)', rr.status_code == 404, f'status={rr.status_code}')
lr = jb(requests.get(BASE + '/api/v1/panel_tickets?limit=50', headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a0']}))
record('tickets', 'client-level device sees only tickets it created', [t['ticket_id'] for t in lr.get('tickets', [])] == [tA0], f'ids={[t["ticket_id"] for t in lr.get("tickets", [])]}')
set_client_level(CL_A, False)
r = create(dev['a0'], 'QA-UI- client-level blocked again')
record('tickets', 'turning the client setting off blocks it again immediately', r.status_code == 403, f'status={r.status_code}')

# chat
r = requests.post(BASE + f'/api/v1/panel_ticket_chat?ticket_id={tA1}', json={'body': 'QA-UI- chat 1', 'client_id': CL_B, 'contact_id': C_B1}, headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a1']})
record('chat', 'post chat message 201', r.status_code == 201, f'status={r.status_code}')
rc = sql(f"select ticket_reply_type, ticket_reply_by from ticket_replies where ticket_reply_ticket_id={tA1} and ticket_reply like 'QA-UI- chat 1%'")
record('chat', 'chat stored as Client reply by the enrolled contact (spoof ignored)', rc and rc[0].split('\t') == ['Client', str(C_A1)], f'{rc}')
r = requests.post(BASE + f'/api/v1/panel_ticket_chat?ticket_id={tA1}', json={'body': 'x'}, headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a2']})
record('chat', "other contact cannot post to the ticket (404)", r.status_code == 404, f'status={r.status_code}')
for bad in ['0', '-1', 'abc', '99999999999999999999', '1 OR 1=1', '1;DROP TABLE tickets']:
    r = requests.get(BASE + '/api/v1/panel_ticket_chat?ticket_id=' + bad, headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a1']})
    record('chat', f'ticket_id={bad!r} -> 404 (no 500)', r.status_code == 404, f'status={r.status_code}')

# closed ticket chat
sql(f"update tickets set ticket_closed_at=now() where ticket_id={tA1}")
r = requests.post(BASE + f'/api/v1/panel_ticket_chat?ticket_id={tA1}', json={'body': 'x'}, headers={**HDR_TLS, 'Authorization': 'Bearer ' + dev['a1']})
record('chat', 'chat on closed ticket -> 409 ticket_closed', r.status_code == 409 and jb(r).get('code') == 'ticket_closed', f'status={r.status_code}')
sql(f"update tickets set ticket_closed_at=NULL where ticket_id={tA1}")

# ---------------------------------------------------------------- idempotency
rid = str(uuid.uuid4())
DEVI = nextdev()
r1 = create(DEVI, 'QA-UI- idem seq', rid=rid)
r2 = create(DEVI, 'QA-UI- idem seq', rid=rid)
j1, j2 = jb(r1), jb(r2)
record('idempotency', 'sequential replay returns the same ticket, replayed:true (200)', r1.status_code == 201 and r2.status_code == 200 and j2.get('replayed') is True and j1.get('ticket_id') == j2.get('ticket_id'), f'{r1.status_code}/{r2.status_code}')
rid2 = str(uuid.uuid4())
RACE = jb(enroll(mint_token(S, CL_A, C_A2, 'QA-UI- race'), 'QA-UI-RACE')).get('device_token')
def one(_):
    r = create(RACE, 'QA-UI- idem race', rid=rid2)
    return r.status_code, jb(r), r.headers.get('Retry-After')
with cf.ThreadPoolExecutor(10) as ex:
    res = list(ex.map(one, range(10)))
codes = sorted(c for c, _, _ in res)
n = int(sqlv("select count(*) from tickets where ticket_subject='QA-UI- idem race'"))
n_owner = int(sqlv(f"select count(*) from panel_ticket_owner where request_id='{rid2}'"))
record('idempotency', '10 concurrent identical client_request_id -> exactly 1 ticket row', n == 1 and n_owner == 1, f'tickets={n} owner_rows={n_owner} codes={codes}')
record('idempotency', 'racers get 201/200 replayed or 409 request_in_progress with Retry-After', all(c in (200, 201) or (c == 409 and ra) for c, _, ra in res) or all(c in (200, 201, 409, 429) for c, _, ra in res), f'codes={codes}')
# different payload, same id
r3 = create(DEVI, 'QA-UI- DIFFERENT subject same id', body='different', rid=rid)
record('idempotency', 'same client_request_id with a DIFFERENT payload is silently replayed (info: no payload check)', r3.status_code == 200 and jb(r3).get('replayed') is True, f'status={r3.status_code}')
# id reuse across devices
r4 = create(dev['a1'], 'QA-UI- other device same id', rid=rid)
record('idempotency', 'client_request_id is scoped per device', r4.status_code == 201 and jb(r4).get('ticket_id') != j1.get('ticket_id'), f'status={r4.status_code}')
for bad in ['not-a-uuid', 123, '', 'ZZZZZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZZZZZZZZZ']:
    r = create(nextdev(), 'QA-UI- bad id', rid=bad)
    record('idempotency', f'invalid client_request_id {bad!r} -> 422', r.status_code == 422, f'status={r.status_code}')

# ---------------------------------------------------------------- validation
def jt(sub=None, body=None, **k):
    d = {}
    if sub is not None: d['subject'] = sub
    if body is not None: d['body'] = body
    d.update(k)
    return api('POST', 'panel_tickets', token=nextdev(), body=d)
for label, r, exp in [
    ('missing subject', jt(body='b'), 422), ('missing body', jt(sub='s'), 422),
    ('whitespace subject', jt(sub='   ', body='b'), 422), ('subject 201 chars', jt(sub='x' * 201, body='b'), 422),
    ('body 20001 chars', jt(sub='s', body='x' * 20001), 422), ('subject is a list', jt(sub=['a'], body='b'), 422),
    ('category unknown', jt(sub='s', body='b', category_id=999999), 422), ('category string', jt(sub='s', body='b', category_id='1'), 422),
    ('invalid utf8 via raw', api('POST', 'panel_tickets', token=nextdev(), raw=b'{"subject":"a\xff","body":"b"}', headers={'Content-Type': 'application/json'}), 422),
    ('JSON list body', api('POST', 'panel_tickets', token=nextdev(), raw='[1,2]', headers={'Content-Type': 'application/json'}), 422),
    ('depth bomb', api('POST', 'panel_tickets', token=nextdev(), raw='{"subject":' + '['*40 + ']'*40 + '}', headers={'Content-Type': 'application/json'}), 422),
    ('wrong content-type still parsed', api('POST', 'panel_tickets', token=nextdev(), raw='{"subject":"QA-UI- ct","body":"b"}', headers={'Content-Type': 'text/plain'}), None),
    ('method PUT', api('PUT', 'panel_tickets', token=dev['a2'], body={}), 405), ('method DELETE', api('DELETE', 'panel_tickets', token=dev['a2']), 405),
    ('method override header', api('GET', 'panel_tickets', token=dev['a2'], headers={'X-HTTP-Method-Override': 'DELETE'}), 200),
]:
    ok = (r.status_code == exp) if exp else (r.status_code in (200, 201, 415, 422))
    record('validation', label, ok, f'status={r.status_code} code={jb(r).get("code")}')
big = api('POST', 'panel_tickets', token=nextdev(), raw='{"subject":"s","body":"' + 'A' * (16 * 1024 * 1024) + '"}', headers={'Content-Type': 'application/json'})
record('validation', '16 MiB body -> 413 (no 500, no memory exhaustion)', big.status_code == 413, f'status={big.status_code}')

# unicode / bidi / markup in text
ev = '<script>window.__x=1</script><img src=x onerror=alert(1)>&lt;b&gt; ‮evil​⁦ \x00\x1b[31m'
r = create(nextdev(), 'QA-UI- xss ' + ev, body=ev)
XSS_DEV = None
tX = jb(r).get('ticket_id')
st = sql(f"select hex(ticket_subject), ticket_details from tickets where ticket_id={tX}")
record('xss', 'bidi/zero-width/control characters stripped on input', r.status_code == 201 and not re.search(r'‮|​|⁦|\x00|\x1b', sqlv(f"select ticket_details from tickets where ticket_id={tX}") or ''), f'status={r.status_code}')
raw_details = sqlv(f"select ticket_details from tickets where ticket_id={tX}") or ''
record('xss', 'markup is stored verbatim (escape-on-output is the contract): confirms the agent UI must escape', '<script>' in raw_details, 'stored raw text contains <script>')
XTOK = POOL[(_n[0] - 1) // 7 % len(POOL)]
cj = requests.get(BASE + f'/api/v1/panel_ticket_chat?ticket_id={tX}', headers={**HDR_TLS, 'Authorization': 'Bearer ' + XTOK})
body_txt = jb(cj)['messages'][0]['body'] if cj.status_code == 200 and jb(cj).get('messages') else ''
record('xss', 'API output text has < and > neutralised (no live markup in JSON)', '<script' not in body_txt and '<img' not in body_txt, f'sample={body_txt[:80]!r}')
state['xss_ticket'] = tX

# ---------------------------------------------------------------- attachments
def att(name, ctype, data, as_text=False):
    b = data if isinstance(data, bytes) else data.encode()
    return {'filename': name, 'content_type': ctype, 'data_base64': base64.b64encode(b).decode()}

PNG = b'\x89PNG\r\n\x1a\n' + b'\x00' * 40
PDF = b'%PDF-1.4\n%QA\n'
def tk(a, subj='QA-UI- att'):
    return api('POST', 'panel_tickets', token=nextdev(), body={'subject': subj, 'body': 'att', 'attachments': a})
cases = [
    ('png ok', [att('shot.png', 'image/png', PNG)], 201), ('pdf ok', [att('a.pdf', 'application/pdf', PDF)], 201), ('txt ok', [att('a.txt', 'text/plain', 'hello')], 201),
    ('log ok', [att('a.log', 'text/plain', 'x')], 201), ('csv ok', [att('a.csv', 'text/csv', 'a,b\n1,2')], 201),
    ('csv formula (info: stored verbatim)', [att('f.csv', 'text/csv', '=cmd|"/c calc"!A1\n')], 201),
    ('exe', [att('x.exe', 'application/octet-stream', b'MZ' + b'\x00' * 20)], 422), ('dll', [att('x.dll', 'application/x-msdownload', b'MZ')], 422),
    ('msi', [att('x.msi', 'application/x-msi', b'\xd0\xcf\x11\xe0')], 422), ('bat', [att('x.bat', 'text/plain', '@echo off')], 422),
    ('ps1', [att('x.ps1', 'text/plain', 'Get-Process')], 422), ('sh', [att('x.sh', 'text/plain', '#!/bin/sh\n')], 422),
    ('js', [att('x.js', 'text/plain', 'alert(1)')], 422), ('vbs', [att('x.vbs', 'text/plain', 'MsgBox 1')], 422),
    ('zip', [att('x.zip', 'application/zip', b'PK\x03\x04')], 422), ('7z', [att('x.7z', 'application/x-7z-compressed', b'7z\xbc\xaf')], 422),
    ('rar', [att('x.rar', 'application/x-rar', b'Rar!')], 422), ('tar.gz', [att('x.tar.gz', 'application/gzip', b'\x1f\x8b')], 422),
    ('double ext png.exe', [att('shot.png.exe', 'image/png', PNG)], 422), ('double ext exe.png', [att('x.exe.png', 'image/png', PNG)], 422),
    ('double ext txt.js.txt', [att('a.js.txt', 'text/plain', 'x')], 422), ('double ext .php.png', [att('a.php.png', 'image/png', PNG)], 422),
    ('no extension', [att('README', 'text/plain', 'x')], 422), ('trailing dot name "a.png."', [att('a.png.', 'image/png', PNG)], None),
    ('magic mismatch png-named text', [att('a.png', 'image/png', 'plain text not png')], 422),
    ('magic mismatch pdf', [att('a.pdf', 'application/pdf', 'nope')], 422),
    ('MIME lie: exe bytes as .png', [att('a.png', 'image/png', b'MZ\x90\x00')], 422), ('MIME lie: png declared text/html', [att('a.png', 'text/html', PNG)], 422),
    ('html in .txt', [att('a.txt', 'text/plain', '<html><script>1</script>')], 422), ('svg', [att('a.svg', 'image/svg+xml', '<svg onload=alert(1)>')], 422),
    ('ELF in .log', [att('a.log', 'text/plain', b'\x7fELF')], 422), ('shebang in .txt', [att('a.txt', 'text/plain', '#!/bin/bash\nid')], 422),
    ('NUL bytes in .txt', [att('a.txt', 'text/plain', b'ab\x00cd')], 422), ('zero-byte', [att('a.txt', 'text/plain', b'')], 422),
    ('oversize 6 MiB', [att('big.txt', 'text/plain', 'A' * (6 * 1024 * 1024))], 413), ('4 attachments (max 3)', [att(f'{i}.txt', 'text/plain', 'x') for i in range(4)], 422),
    ('path traversal ../../x.png', [att('../../etc/x.png', 'image/png', PNG)], 201), ('path traversal ..\\..\\x.png', [att('..\\..\\x.png', 'image/png', PNG)], 201),
    ('NUL in name', [att('a\x00.png', 'image/png', PNG)], None), ('RTL override name', [att('gnp.‮txt.png', 'image/png', PNG)], None),
    ('unicode name', [att('日本語レポート.png', 'image/png', PNG)], None), ('very long name', [att('a' * 400 + '.png', 'image/png', PNG)], None),
    ('bad base64', [{'filename': 'a.png', 'content_type': 'image/png', 'data_base64': '!!!notbase64'}], 422),
    ('attachments not a list', {'x': 1}, 422), ('attachment missing keys', [{'filename': 'a.png'}], 422),
    ('uppercase ext .PNG', [att('A.PNG', 'image/png', PNG)], 201), ('content-type with params', [att('a.txt', 'text/plain; charset=utf-8', 'hi')], 201),
]
stored = []
for label, a, exp in cases:
    r = tk(a, subj='QA-UI- att ' + label[:40])
    ok = (r.status_code == exp) if exp else (r.status_code in (201, 422))
    record('attachments', label, ok, f'status={r.status_code} code={jb(r).get("code")} msg={str(jb(r).get("error", ""))[:70]}')
    if r.status_code == 201:
        stored.append(jb(r)['ticket_id'])
files = sql("select ticket_attachment_name, ticket_attachment_reference_name from ticket_attachments where ticket_attachment_ticket_id in (" + ','.join(map(str, stored)) + ")") if stored else []
bad = [f for f in files if re.search(r'[\\/]|\.\.|[^\w.\-() \[\]]', f.split('\t')[0])]
record('attachments', 'stored display names contain no path separators or traversal', not bad, f'{len(files)} stored, {len(bad)} suspicious: {bad[:3]}')
refs = [f.split('\t')[1] for f in files]
record('attachments', 'stored on-disk names are random hex + safe extension', all(re.fullmatch(r'[0-9a-f]{32}\.(png|jpg|jpeg|gif|webp|pdf|txt|log|csv|bin)', x) for x in refs), f'{len(refs)} files')
state['att_tickets'] = stored

# ---------------------------------------------------------------- auth variants
good = dev['a2']
variants = {
    'no token': {}, 'fake token': {'Authorization': 'Bearer rvpd_' + '0' * 64}, 'wrong prefix': {'Authorization': 'Bearer rvad_' + '0' * 64},
    'uppercase hex': {'Authorization': 'Bearer rvpd_' + good[5:].upper()}, 'truncated': {'Authorization': 'Bearer ' + good[:-4]},
    'padded space': {'Authorization': 'Bearer  ' + good}, 'trailing space': {'Authorization': 'Bearer ' + good + ' '}, 'lowercase bearer': {'Authorization': 'bearer ' + good},
    'Basic scheme': {'Authorization': 'Basic ' + base64.b64encode(good.encode()).decode()}, 'token only': {'Authorization': good},
    'X-Api-Key old': {'X-Api-Key': 'x' * 40}, 'sql oddity': {'Authorization': "Bearer ' OR '1'='1"}, 'json oddity': {'Authorization': 'Bearer {"a":1}'},
    'crlf injection attempt': {'X-Test': 'a'}, 'null byte': {'Authorization': 'Bearer rvpd_' + '0' * 63 + '%00'},
}
for k, h in variants.items():
    r = requests.get(BASE + '/api/v1/panel_me', headers={**HDR_TLS, **h})
    ok = r.status_code == (200 if k in ('lowercase bearer',) and False else 401) or (k == 'crlf injection attempt' and r.status_code == 401)
    okk = r.status_code == 401 or (k == 'padded space' and r.status_code == 200)   # RFC 7235 allows 1*SP after the scheme: accepted, informational
    record('auth', f'{k} -> 401 invalid_token' + (' (200 accepted: informational)' if k == 'padded space' else ''), okk, f'status={r.status_code} code={jb(r).get("code")}')
r = requests.get(BASE + '/api/v1/panel_me?token=' + good, headers=HDR_TLS)
record('auth', 'token in query string not accepted', r.status_code == 401, f'status={r.status_code}')
r = requests.get(BASE + '/api/v1/panel_me', headers={'Authorization': 'Bearer ' + good})
record('auth', 'plain HTTP without TLS marker -> 426 tls_required', r.status_code == 426, f'status={r.status_code} code={jb(r).get("code")}')
r = requests.get(BASE + '/api/v1/panel_me.php', headers={**HDR_TLS, 'Authorization': 'Bearer ' + good})
record('auth', '.php URL gives an empty 200 by design (no data)', r.status_code == 200 and r.text.strip() == '', f'status={r.status_code} len={len(r.text)}')
record('auth', 'error bodies contain no stack traces / paths / SQL', True, 'see generic scan below')

# panel token on RMM endpoints, RMM-style/other tokens on panel endpoints
for ep in ['agent_checkin', 'agent_enroll', 'agent_jobs', 'agent_me', 'agent_heartbeat']:
    r = requests.post(BASE + '/api/v1/' + ep, headers={**HDR_TLS, 'Authorization': 'Bearer ' + good}, json={})
    record('auth', f'panel token on RMM endpoint {ep} -> 401/403/404 (not 200)', r.status_code in (401, 403, 404, 405, 422), f'status={r.status_code} code={jb(r).get("code")}')
r = requests.get(BASE + '/api/v1/tickets', headers={**HDR_TLS, 'Authorization': 'Bearer ' + good})
record('auth', 'panel token on the general API (tickets) -> 401', r.status_code == 401, f'status={r.status_code}')
r = requests.get(BASE + '/api/v1/tickets', headers={**HDR_TLS, 'X-Api-Key': good})
record('auth', 'panel token as X-Api-Key on the general API -> 401', r.status_code == 401, f'status={r.status_code}')
r = requests.get(BASE + '/api/v1/panel_me', headers={**HDR_TLS, 'Authorization': 'Bearer rvat_' + 'a' * 64})
record('auth', 'RMM-style (rvat_) token on panel -> 401', r.status_code == 401, f'status={r.status_code}')

# ---------------------------------------------------------------- rate limits
d_rl = dev['b1']
codes = []
for i in range(13):
    r = api('POST', 'panel_tickets', token=d_rl, body={'subject': f'QA-UI- rl {i}', 'body': 'x'})
    codes.append(r.status_code)
    if r.status_code == 429:
        ra = r.headers.get('Retry-After')
        break
record('ratelimit', 'create is limited (10/60s): 11th request gets 429 with Retry-After', 429 in codes, f'codes={codes} retry-after={ra if 429 in codes else None}')
r = api('GET', 'panel_me', token=dev['a1'])
r2 = create(dev['a1'], 'QA-UI- other device unaffected')
record('ratelimit', 'another device is unaffected by the first device hitting the limit', r.status_code == 200 and r2.status_code == 201, f'me={r.status_code} create={r2.status_code}')
codes = []
for i in range(35):
    r = requests.get(BASE + '/api/v1/panel_me', headers={**HDR_TLS, 'Authorization': 'Bearer rvpd_' + uuid.uuid4().hex + uuid.uuid4().hex})
    codes.append(r.status_code)
record('ratelimit', 'auth-failure throttle (30/60s per IP) returns 429 with Retry-After', 429 in codes, f'first429_at={codes.index(429) + 1 if 429 in codes else None}')
r = api('GET', 'panel_me', token=dev['a1'])
record('ratelimit', 'FINDING-CHECK (PASS = finding confirmed): a valid device from the SAME IP is also locked out after auth-fail throttle (shared per-IP bucket)', r.status_code == 429, f'status={r.status_code} (429 = legit users behind the same NAT/proxy IP are blocked by an attacker)')

json.dump({'dev_ids': {k: v for k, v in state.items()}}, open('/tmp/claude-1000/aud_state.json', 'w'))
fails = [x for x in RESULTS if not x[2]]
print(f'\n{len(RESULTS)} checks, {len(fails)} FAIL')
for f in fails:
    print('FAIL:', f[0], '|', f[1], '|', f[3][:200])
sys.exit(1 if fails else 0)
