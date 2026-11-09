#!/usr/bin/env python3
"""Panel API extras: revoke timing, audit rows, response headers/CORS, archived/closed tickets, contact/client lifecycle. Scratch only."""
import sys, re, json, time, uuid
sys.path.insert(0, __import__('os').path.dirname(__file__))
from common import *
S = login(ADMIN)
def fresh(client, contact, host):
    t = mint_token(S, client, contact, 'QA-UI- extras ' + host)
    j = jb(enroll(t, host))
    return j.get('device_token'), j.get('device_id')

tok, did = fresh(16, 1, 'QA-UI-EX1')
# --- revoke timing (same second)
h = S.get(BASE + '/agent/panel_deploy.php?client_id=16').text
m = re.search(r'post/panel_deploy\.php\?revoke_device=' + str(did) + r'(?:&amp;|&)client_id=\d+(?:&amp;|&)csrf_token=([A-Za-z0-9_\-]+)', h)
before = api('GET', 'panel_me', token=tok).status_code
t0 = time.time()
S.get(BASE + f'/agent/post/panel_deploy.php?revoke_device={did}&client_id=16&csrf_token={m.group(1)}', headers={'Referer': BASE + '/agent/panel_deploy.php?client_id=16'})
after = api('GET', 'panel_me', token=tok)
record('revoke', 'revoke device from Deploy page: next API call is 401 revoked immediately', before == 200 and after.status_code == 401 and jb(after).get('code') == 'revoked', f'before={before} after={after.status_code} code={jb(after).get("code")} dt={time.time()-t0:.2f}s')
r = enroll(mint_token(S, 16, 1, 'QA-UI- re-enroll revoked'), 'QA-UI-EX1')   # new install_id: a different device is fine
tok2, did2 = jb(r).get('device_token'), jb(r).get('device_id')
iid = sqlv(f"select install_id from endpoint_agent_devices where device_id={did}")
r = api('POST', 'panel_enroll', body={'enrollment_token': mint_token(S, 16, 1, 'QA-UI- reenroll revoked'), 'device': {'hostname': 'QA-UI-EX1', 'os': 'windows', 'install_id': iid}})
record('revoke', 'revoked device cannot re-enroll with its install_id (403, admin must allow it)', r.status_code == 403, f'status={r.status_code} code={jb(r).get("code")} msg={jb(r).get("error")}')
# revoke an already-revoked / unknown id via crafted request, wrong client
hh = S.get(BASE + '/agent/panel_deploy.php?client_id=16').text
rv = S.get(BASE + f'/agent/post/panel_deploy.php?revoke_device=999999&client_id=16&csrf_token=x', headers={'Referer': BASE + '/agent/panel_deploy.php'}, allow_redirects=False)
record('revoke', 'revoke with bad CSRF token is refused (no state change, no 500)', rv.status_code in (302, 403) , f'status={rv.status_code} loc={rv.headers.get("Location")}')
# technician with view-only? permission matrix
T = login(TECH)
page = T.get(BASE + '/agent/panel_deploy.php?client_id=16')
tcsrf = csrf(T, '/agent/panel_deploy.php?client_id=16')
r = T.post(BASE + '/agent/post/panel_deploy.php', data={'create_token': '1', 'csrf_token': tcsrf, 'client_id': 16, 'label': 'QA-UI- tech mint', 'ttl_hours': 24}, headers={'Referer': BASE + '/agent/panel_deploy.php?client_id=16'}, allow_redirects=True)
minted = re.search(r'rvtp1\.[0-9a-f]{12}\.[0-9a-f]{40}', r.text) is not None
record('perm', 'technician (module_support level?) minting a token', True, f'tech page status={page.status_code}, token minted by technician={minted} (level 2 on module_support required; technician role has it in the demo, so minting is expected)')
# unauthenticated access to the Deploy page and handler
U = requests.Session()
r = U.get(BASE + '/agent/panel_deploy.php?client_id=16', allow_redirects=False)
record('perm', 'Deploy page requires login (redirect)', r.status_code in (302, 303), f'status={r.status_code}')
r = U.post(BASE + '/agent/post/panel_deploy.php', data={'create_token': '1', 'client_id': 16}, allow_redirects=False)
record('perm', 'Deploy handler requires login', r.status_code in (302, 303, 401, 403), f'status={r.status_code}')
# portal user
P = login(('sophie.tran@summitridge.example', 'DemoPass#2026'))
r = P.get(BASE + '/agent/panel_deploy.php?client_id=16', allow_redirects=False)
record('perm', 'client-portal login cannot open the agent Deploy page', r.status_code in (302, 303, 401, 403) , f'status={r.status_code}')

# --- IDOR on Deploy page: another client's id with a restricted tech is out of scope here (single-client demo roles)
# --- headers / CORS
r = api('GET', 'panel_me', token=tok2)
hd = {k.lower(): v for k, v in r.headers.items()}
record('headers', 'API sends nosniff/cache-control (info)', True, json.dumps({k: hd.get(k) for k in ('content-type', 'x-content-type-options', 'cache-control', 'access-control-allow-origin', 'strict-transport-security', 'content-security-policy', 'x-frame-options', 'referrer-policy')}))
o = requests.options(BASE + '/api/v1/panel_tickets', headers={**HDR_TLS, 'Origin': 'https://evil.example', 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'authorization'})
record('headers', 'CORS preflight from an arbitrary origin is not allowed', 'access-control-allow-origin' not in {k.lower() for k in o.headers}, f'status={o.status_code} acao={o.headers.get("Access-Control-Allow-Origin")}')
g = requests.get(BASE + '/api/v1/panel_me', headers={**HDR_TLS, 'Authorization': 'Bearer ' + tok2, 'Origin': 'https://evil.example'})
record('headers', 'no ACAO echo on a real authenticated call', 'access-control-allow-origin' not in {k.lower() for k in g.headers}, f'acao={g.headers.get("Access-Control-Allow-Origin")}')

# --- lifecycle edge cases
# archived ticket: chat allowed?
t = jb(api('POST', 'panel_tickets', token=tok2, body={'subject': 'QA-UI- archived chat', 'body': 'x'})).get('ticket_id')
sql(f"update tickets set ticket_archived_at=now() where ticket_id={t}")
lst = jb(requests.get(BASE + '/api/v1/panel_tickets?limit=50', headers={**HDR_TLS, 'Authorization': 'Bearer ' + tok2}))
vis = t in [x['ticket_id'] for x in lst.get('tickets', [])]
r = requests.post(BASE + f'/api/v1/panel_ticket_chat?ticket_id={t}', json={'body': 'chat on archived'}, headers={**HDR_TLS, 'Authorization': 'Bearer ' + tok2})
record('lifecycle', 'ARCHIVED ticket still listed to the device (finding)', not vis, f'listed={vis}')
record('lifecycle', 'ARCHIVED ticket still accepts new chat messages (finding)', r.status_code != 201, f'chat status={r.status_code}')
# contact archived -> device blocked (403 contact_invalid) ?
sql("update contacts set contact_archived_at=now() where contact_id=1")
r = api('GET', 'panel_me', token=tok2)
record('lifecycle', 'archiving the contact blocks its panel (403 contact_invalid)', r.status_code == 403, f'status={r.status_code} code={jb(r).get("code")}')
sql("update contacts set contact_archived_at=NULL where contact_id=1")
# client archived
sql("update clients set client_archived_at=now() where client_id=16")
r = api('POST', 'panel_tickets', token=tok2, body={'subject': 'QA-UI- archived client', 'body': 'x'})
record('lifecycle', 'ARCHIVED client: panel can still create tickets (finding if 201)', r.status_code != 201, f'status={r.status_code}')
r = api('GET', 'panel_me', token=tok2)
sql("update clients set client_archived_at=NULL where client_id=16")
record('lifecycle', 'ARCHIVED client: panel_me still answers (info)', True, f'status={r.status_code}')
# token expiry
sql(f"update endpoint_agent_devices set token_expires_at = now() - interval 1 minute where device_id={did2}")
r = api('GET', 'panel_me', token=tok2)
record('lifecycle', 'expired device token -> 401 expired', r.status_code == 401 and jb(r).get('code') == 'expired', f'status={r.status_code} code={jb(r).get("code")}')

# --- audit rows
rows = sql("select audit_event_type from (select distinct log_type as audit_event_type from logs) x where audit_event_type like 'Panel%'") if False else []
names = [e.split('\t')[0] for e in sql("select log_action, count(*) from logs where log_type='Endpoint Agent' and log_action like 'Panel%' group by log_action")]
need = ['Panel Enrolled', 'Panel Ticket Created', 'Panel Chat Posted', 'Panel Enrollment Token Created', 'Panel Enrollment Token Revoked']
record('audit', 'audit rows exist for enroll / ticket create / chat / token created / token revoked', all(n in names for n in need), f'logs(Endpoint Agent) events={sorted(set(names))}')
dev_rev = [n for n in names if 'Device' in n or 'Revoked' in n]
record('audit', 'audit row exists for panel DEVICE revoke', any('Device' in n for n in names), f'revoke-related events={dev_rev}')
leak = sql("select count(*) from logs where log_description like '%rvpd\\_%' or log_description like '%rvtp1.%'")
record('audit', 'audit rows contain no tokens', leak and leak[0] == '0', f'rows with token text={leak}')
fails = sql("select log_action, left(log_description,110) from logs where log_type='Endpoint Agent' and log_action like 'Panel%' order by log_id desc limit 8")
print('AUDIT SAMPLE:'); [print('  ', x) for x in fails]
print(f'\n{len(RESULTS)} checks, {len([x for x in RESULTS if not x[2]])} FAIL')
for f in RESULTS:
    if not f[2]: print('FAIL:', f[0], '|', f[1], '|', f[3][:240])
