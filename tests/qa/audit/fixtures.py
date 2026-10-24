"""Scratch-DB fixtures for the UX audit: RMM devices in Mesh states, a panel token page, webhook. Names start with QA-UI-."""
import sys, os, secrets, hashlib, uuid, datetime, json
sys.path.insert(0, os.path.dirname(__file__))
from common import *

def rmm_token(client, uses=5):
    sel, sec = secrets.token_hex(6), secrets.token_hex(20)
    sql(f"insert into endpoint_agent_enrollment_tokens (token_selector, token_hash, label, client_id, location_id, ring, expires_at, max_uses, created_by, created_at) values ('{sel}', '{hashlib.sha256(sec.encode()).hexdigest()}', 'QA-UI- fx', {client}, 0, 'stable', now() + interval 1 day, {uses}, 9, now())")
    return f'rvte1.{sel}.{sec}'

def new_dev(name, client=16, os_='windows'):
    d = {'install_id': str(uuid.uuid4()), 'machine_guid': secrets.token_hex(8), 'hostname': name, 'os': os_, 'os_version': 'Windows 11', 'arch': 'amd64', 'serial': 'QA-UI-' + secrets.token_hex(4), 'manufacturer': 'Dell', 'model': 'Latitude', 'mac_addresses': [], 'agent_version': '1.0.0'}
    r = api('POST', 'agent_enroll', body={'enrollment_token': rmm_token(client), 'device': d}); j = jb(r)
    return j.get('device_id'), j.get('device_token')

_s = [0]
def checkin(tok, node=None):
    _s[0] += 1
    b = {'seq': int(datetime.datetime.now().timestamp()) * 10 + _s[0], 'collected_at': datetime.datetime.now(datetime.UTC).strftime('%Y-%m-%dT%H:%M:%SZ'), 'agent_version': '1.0.0', 'inventory': None,
         'metrics': {'cpu_pct': 10.5, 'mem_pct': 40.0, 'disk': [{'mount': 'C:', 'used_pct': 55.0}]}, 'checks': [], 'buffered': []}
    if node: b['mesh_agent'] = {'installed': True, 'version': '1.1.0', 'node_id': node}
    return api('POST', 'agent_checkin', token=tok, body=b)

def build():
    fx = {}
    sql("update endpoint_agent_settings set mesh_enabled=1, mesh_url='https://mesh.example.test' where id=1")
    a, ta = new_dev('QA-UI-FX-OWNER'); n1 = 'node//' + secrets.token_hex(20)
    checkin(ta, n1)
    S = login(ADMIN)
    tk = csrf(S, f'/agent/rmm_agent_device.php?device_id={a}')
    S.post(BASE + '/agent/post/rmm_agent.php', data={'action': 'set_mesh_node', 'device_id': a, 'mesh_node_id': n1, 'csrf_token': tk}, headers={'Referer': BASE + f'/agent/rmm_agent_device.php?device_id={a}'})
    b, tb = new_dev('QA-UI-FX-NEEDS-APPROVAL'); checkin(tb, 'node//' + secrets.token_hex(20))
    c, tc = new_dev('QA-UI-FX-CONFLICT'); checkin(tc, n1)
    fx.update(owner=a, needs=b, conflict=c)
    return fx

if __name__ == '__main__':
    print(json.dumps(build()))
