#!/usr/bin/env python3
"""Mesh node approval audit (RivetCore rc.31 F1) on the scratch instance: no real MeshCentral is contacted."""
import sys, re, json, time, uuid, hashlib, secrets, datetime
sys.path.insert(0, __import__('os').path.dirname(__file__))
from common import *

sql("update endpoint_agent_settings set mesh_enabled=1, mesh_url='https://mesh.example.test', mesh_domain='' where id=1")

def rmm_token(client, uses=5):
    sel, sec = secrets.token_hex(6), secrets.token_hex(20)
    sql(f"insert into endpoint_agent_enrollment_tokens (token_selector, token_hash, label, client_id, location_id, ring, expires_at, max_uses, created_by, created_at) values ('{sel}', '{hashlib.sha256(sec.encode()).hexdigest()}', 'QA-UI- mesh', {client}, 0, 'stable', now() + interval 1 day, {uses}, 9, now())")
    return f'rvte1.{sel}.{sec}'

def dev(name, **o):
    d = {'install_id': str(uuid.uuid4()), 'machine_guid': secrets.token_hex(8), 'hostname': name, 'os': 'windows', 'os_version': 'Windows 11', 'arch': 'amd64', 'serial': 'QA-UI-' + secrets.token_hex(4), 'manufacturer': 'Dell', 'model': 'Latitude', 'mac_addresses': [], 'agent_version': '1.0.0'}
    d.update(o); return d

def enroll_rmm(tok, d):
    r = api('POST', 'agent_enroll', body={'enrollment_token': tok, 'device': d})
    j = jb(r)
    return r.status_code, j

_seq = [0]
def checkin(dtok, node=None, installed=True, **extra):
    _seq[0] += 1
    body = {'seq': _seq[0] + int(time.time()) % 100000 * 10, 'collected_at': datetime.datetime.now(datetime.UTC).strftime('%Y-%m-%dT%H:%M:%SZ'), 'agent_version': '1.0.0', 'inventory': None,
            'metrics': {'cpu_pct': 10.5, 'mem_pct': 40.0, 'disk': [{'mount': 'C:', 'used_pct': 55.0}]}, 'checks': [], 'buffered': []}
    if node is not None:
        body['mesh_agent'] = {'installed': installed, 'version': '1.1.0', 'node_id': node}
    body.update(extra)
    return api('POST', 'agent_checkin', token=dtok, body=body)

def mstate(did):
    r = sql(f"select installed, node_id, map_state, ifnull(conflict_device_id,'') from endpoint_agent_mesh_agents where device_id={did}")
    return r[0].split('\t') if r else None
def mapped(did):
    r = sql(f"select mesh_node_id, source from endpoint_agent_mesh_nodes where device_id={did}")
    return r[0].split('\t') if r else None

S = login(ADMIN); T = login(TECH); P = login(('sophie.tran@summitridge.example', 'DemoPass#2026'))
t16 = rmm_token(16); t17 = rmm_token(17)
dA = dev('QA-UI-MESH-A'); c1, j1 = enroll_rmm(t16, dA); c2, j2 = enroll_rmm(t16, dev('QA-UI-MESH-B')); c3, j3 = enroll_rmm(t17, dev('QA-UI-MESH-C'))
record('mesh', 'RMM devices enrol (3 devices, 2 clients)', c1 == 201 and c2 == 201 and c3 == 201, f'{c1}/{c2}/{c3} keys={sorted(j1.keys())[:6]}')
A, B, C = j1.get('device_id'), j2.get('device_id'), j3.get('device_id')
tA, tB, tC = j1.get('device_token'), j2.get('device_token'), j3.get('device_token')
NODE1 = 'node//' + secrets.token_hex(20)
r = checkin(tA, NODE1)
record('mesh', 'check-in with mesh_agent accepted (200)', r.status_code == 200, f'status={r.status_code} code={jb(r).get("code")}')
st = mstate(A)
record('mesh', 'new device node is NOT mapped by itself: map_state=needs_approval, no node row', st and st[2] == 'needs_approval' and mapped(A) is None, f'state={st} mapped={mapped(A)}')
ev = sql(f"select count(*) from logs where log_action='Mesh Node Needs Approval' and log_description like '%device {A} %'")
record('mesh', 'audit "Mesh Node Needs Approval" written once for the device', ev and ev[0] == '1', f'rows={ev}')
checkin(tA, NODE1); checkin(tA, NODE1); ev2 = sql(f"select count(*) from logs where log_action='Mesh Node Needs Approval' and log_description like '%device {A} %'")
record('mesh', 'repeat check-ins do not spam the audit log', ev2 and ev2[0] == '1', f'rows={ev2}')

# --- Approve via the same call the button makes (admin session, CSRF)
def approve(sess, did, node, token=None):
    tk = token or csrf(sess, f'/agent/rmm_agent_device.php?device_id={did}')
    return sess.post(BASE + '/agent/post/rmm_agent.php', data={'action': 'set_mesh_node', 'device_id': did, 'mesh_node_id': node, 'csrf_token': tk}, headers={'Referer': BASE + f'/agent/rmm_agent_device.php?device_id={did}'})
pg = S.get(BASE + f'/agent/rmm_agent_device.php?device_id={A}').text
record('mesh', 'device page shows the needs-approval alert and the Approve button for an admin', 'data-mesh-hint="needs_approval"' in pg and 'rmm-mesh-approve-btn' in pg, 'page markup')
pgt = T.get(BASE + f'/agent/rmm_agent_device.php?device_id={A}').text
tstat = T.get(BASE + f'/agent/rmm_agent_device.php?device_id={A}', allow_redirects=False).status_code
record('mesh', 'technician (demo role) cannot open the RMM device page at all (403) so cannot see or press Approve', tstat == 403 or ('rmm-mesh-approve-btn' not in pgt), f'status={tstat} (a non-admin WITH RMM access was not available in the demo roles; the button is rendered only when can_approve is set server-side)')
rt = approve(T, A, NODE1)
record('mesh', 'technician cannot approve via a crafted POST (403, node not mapped)', rt.status_code in (403,) or (jb(rt).get('success') is False), f'status={rt.status_code} body={rt.text[:120]}')
record('mesh', 'technician attempt left the device unmapped', mapped(A) is None, f'mapped={mapped(A)}')
rp = P.post(BASE + '/agent/post/rmm_agent.php', data={'action': 'set_mesh_node', 'device_id': A, 'mesh_node_id': NODE1, 'csrf_token': 'x'}, allow_redirects=False)
record('mesh', 'portal user cannot reach the approve handler', rp.status_code in (302, 401, 403), f'status={rp.status_code}')
rb0 = S.post(BASE + '/agent/post/rmm_agent.php', data={'action': 'set_mesh_node', 'device_id': A, 'mesh_node_id': NODE1, 'csrf_token': 'definitely-wrong'}, headers={'Referer': BASE + f'/agent/rmm_agent_device.php?device_id={A}'}, allow_redirects=False)
record('mesh', 'admin POST with a bad CSRF token is refused', rb0.status_code in (302, 403), f'status={rb0.status_code} location={rb0.headers.get("Location")}')
rb = S.post(BASE + '/agent/post/rmm_agent.php', data={'action': 'set_mesh_node', 'device_id': A, 'mesh_node_id': NODE1, 'csrf_token': 'definitely-wrong'}, headers={'Referer': BASE + f'/agent/rmm_agent_device.php?device_id={A}'}, allow_redirects=True)
record('mesh', 'FINDING-CHECK (PASS = defect confirmed): a bad CSRF token on this JSON endpoint ends on an HTML 404 page, so the button shows nothing', rb.status_code == 404 and 'json' not in rb.headers.get('Content-Type', ''), f'final status={rb.status_code} url={rb.url.split("127.0.0.1:8196")[-1]}')
record('mesh', '...and nothing was mapped', mapped(A) is None, f'mapped={mapped(A)}')
ra = approve(S, A, NODE1)
record('mesh', 'admin Approve maps the node (success true)', jb(ra).get('success') is True, f'status={ra.status_code} body={ra.text[:140]}')
record('mesh', 'node row exists with source manual after approve', mapped(A) and mapped(A)[0] == NODE1 and mapped(A)[1] != 'agent', f'mapped={mapped(A)}')
ra2 = approve(S, A, NODE1)
record('mesh', 'double-click / second Approve is idempotent (no error, still one mapping)', jb(ra2).get('success') is True and sql(f"select count(*) from endpoint_agent_mesh_nodes where mesh_node_id='{NODE1}'")[0] == '1', f'body={ra2.text[:100]}')
checkin(tA, NODE1)
record('mesh', 'subsequent check-in keeps the mapping (state mapped/same)', mapped(A) and mapped(A)[0] == NODE1, f'state={mstate(A)}')
# remote launch: uses MeshCentral; the mock isn't running, so assert only the pre-flight (maps ok -> reaches MeshCentral call, fails on connectivity, not "unmapped")
rr = S.post(BASE + '/agent/post/rmm_agent.php', data={'action': 'remote', 'device_id': A, 'csrf_token': csrf(S, f'/agent/rmm_agent_device.php?device_id={A}')}, headers={'Referer': BASE + f'/agent/rmm_agent_device.php?device_id={A}'})
record('mesh', 'Remote after Approve gets past the "not mapped" check (reaches the MeshCentral call; mock unreachable here)', 'not mapped' not in rr.text.lower() and 'no meshcentral node' not in rr.text.lower(), f'status={rr.status_code} body={rr.text[:200]}')

# --- conflict: device B claims A's node
r = checkin(tB, NODE1)
st = mstate(B)
record('mesh', "node already mapped to another LIVE device -> conflict and is never moved", st and st[2] == 'conflict' and mapped(A) and mapped(A)[0] == NODE1 and mapped(B) is None, f'B state={st} A mapped={mapped(A)} B mapped={mapped(B)}')
pgB = S.get(BASE + f'/agent/rmm_agent_device.php?device_id={B}').text
open('/tmp/claude-1000/pgB.html', 'w').write(pgB)
record('mesh', 'device page for B shows the conflict alert', 'data-mesh-hint="conflict"' in pgB, 'page markup')
record('mesh', 'FINDING-CHECK (PASS = defect confirmed): same-department conflict says "belongs to another department, or is not shown to you" when the other device simply has no asset link', 'belongs to another department, or is not shown to you' in pgB and sqlv(f"select client_id=(select client_id from endpoint_agent_devices where device_id={B}) from endpoint_agent_devices where device_id={A}") == '1', 'both devices are in the same department')
# forced approve of the conflicting node by admin (crafted): what happens?
rf = approve(S, B, NODE1)
record('mesh', 'ADMIN crafted POST maps a node that is already mapped to another live device (what happens?)', True, f'success={jb(rf).get("success")} err={jb(rf).get("error")} mapped_B={mapped(B)} mapped_A={mapped(A)} (info)')
# cross-client claim: C (client 17) claims A's node
r = checkin(tC, NODE1)
stC = mstate(C)
record('mesh', "cross-client claim of a mapped node -> conflict; other client's device id is not disclosed on the page", stC and stC[2] == 'conflict', f'C state={stC}')
pgC = S.get(BASE + f'/agent/rmm_agent_device.php?device_id={C}').text
record('mesh', 'conflict page for a device in another client does not link to the other client device', f'device #{A}' not in pgC, 'page markup')
# retired owner, same machine vs different machine
NODE2 = 'node//' + secrets.token_hex(20)
cX, jX = enroll_rmm(t16, dev('QA-UI-MESH-X', serial='QA-UI-SAME-' + secrets.token_hex(3))); X, tX = jX.get('device_id'), jX.get('device_token')
checkin(tX, NODE2); approve(S, X, NODE2)
sql(f"update endpoint_agent_devices set retired_at=now() where device_id={X}")
cY, jY = enroll_rmm(t16, dev('QA-UI-MESH-Y', serial='QA-UI-OTHER-' + secrets.token_hex(3))); Y, tY = jY.get('device_id'), jY.get('device_token')
checkin(tY, NODE2)
record('mesh', 'node bound to a RETIRED device is not moved to a different machine (conflict)', mstate(Y) and mstate(Y)[2] == 'conflict' and (mapped(Y) is None), f'Y state={mstate(Y)} Y mapped={mapped(Y)} X mapped={mapped(X)}')
# re-enrolled same machine (same install_id) keeps working
iid = sql(f"select install_id from endpoint_agent_devices where device_id={A}")[0]
cR, jR = enroll_rmm(rmm_token(16), dict(dA))
record('mesh', 're-enrolled machine (same install_id) gets a token and keeps its device row and mapping', cR in (200, 201) and jR.get('device_id') == A and mapped(A) and mapped(A)[0] == NODE1, f'status={cR} same_device={jR.get("device_id") == A} mapped={mapped(A)}')
# node id format fuzz
cD, jD = enroll_rmm(t16, dev('QA-UI-MESH-D')); D, tD = jD.get('device_id'), jD.get('device_token')
for bad in ['node//short', 'x' * 3000, 'node//' + 'A' * 200, "node//AAAA'; DROP TABLE x;--AAAAAAAAAAAAAAAA", '<script>alert(1)</script>', 'node//‮AAAAAAAAAAAAAAAAAAAA']:
    r = checkin(tD, bad)
    record('mesh', f'malformed node_id {bad[:20]!r} -> no 500, nothing mapped', r.status_code in (200, 422) and mapped(D) is None, f'status={r.status_code} state={mstate(D)}')
print(f'\n{len(RESULTS)} checks, {len([x for x in RESULTS if not x[2]])} FAIL')
for f in RESULTS:
    if not f[2]: print('FAIL:', f[0], '|', f[1], '|', f[3][:260])
