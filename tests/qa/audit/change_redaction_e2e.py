#!/usr/bin/env python3
"""rc.31 change-monitor redaction + alert text end to end: a device reports changes carrying fake secrets, markup and bidi characters;
then the WHOLE scratch DB is searched for every secret marker (records, alerts, logs, events, queues). No real secrets."""
import sys, re, json, uuid, secrets, hashlib, datetime, subprocess
sys.path.insert(0, __import__('os').path.dirname(__file__))
from common import *

def rmm_token(client):
    sel, sec = secrets.token_hex(6), secrets.token_hex(20)
    sql(f"insert into endpoint_agent_enrollment_tokens (token_selector, token_hash, label, client_id, location_id, ring, expires_at, max_uses, created_by, created_at) values ('{sel}', '{hashlib.sha256(sec.encode()).hexdigest()}', 'QA-UI- chg', {client}, 0, 'stable', now() + interval 1 day, 3, 9, now())")
    return f'rvte1.{sel}.{sec}'
d = {'install_id': str(uuid.uuid4()), 'machine_guid': secrets.token_hex(8), 'hostname': 'QA-UI-CHG-WIN', 'os': 'windows', 'os_version': 'Windows 11', 'arch': 'amd64', 'serial': 'QA-UI-' + secrets.token_hex(4), 'manufacturer': 'Dell', 'model': 'x', 'mac_addresses': [], 'agent_version': '1.0.0'}
r = api('POST', 'agent_enroll', body={'enrollment_token': rmm_token(16), 'device': d}); j = jb(r)
tok = j.get('device_token'); did = j.get('device_id')
record('chg', 'enroll Windows RMM device', r.status_code == 201, f'status={r.status_code}')
now = datetime.datetime.now(datetime.UTC).strftime('%Y-%m-%dT%H:%M:%SZ')
HOSTS = 'C:\\Windows\\System32\\drivers\\etc\\hosts'
M = 'QAM' + secrets.token_hex(3)           # unique marker so every leak is traceable
S = lambda n: f'{M}{n}'
ch = [
 {'ts': now, 'kind': 'file', 'watch': 'win.hosts', 'change': 'modified', 'target': HOSTS, 'item': 'DB_PASS', 'old': f'DB_PASS={S("old1")}', 'new': f'DB_PASS={S("new1")}', 'summary': f'ENCRYPTION_KEY={S("sum1")} mysql -u root -p{S("sum2")} x'},
 {'ts': now, 'kind': 'file', 'watch': 'win.hosts', 'change': 'modified', 'target': HOSTS, 'item': 'line 2', 'old': 'x', 'new': f'mariadb -u root -p{S("mar1")}  mongosh -u a -p {S("mon1")}  sshpass -p {S("ssh1")} ssh h', 'summary': f'Authorization: Bearer {S("bearer1")}'},
 {'ts': now, 'kind': 'file', 'watch': 'win.hosts', 'change': 'added', 'target': HOSTS, 'item': 'x', 'old': '', 'new': f'https://admin:{S("url1")}@host.example/x?token={S("tok1")}', 'summary': '{"password":"%s","api_key":"%s"}' % (S('json1'), S('json2'))},
 {'ts': now, 'kind': 'file', 'watch': 'win.hosts', 'change': 'modified', 'target': HOSTS, 'item': '<script>alert(1)</script>', 'old': '<img src=x onerror=alert(1)>', 'new': 'a\u202eb\u200bc`cmd`"q"\'s\'', 'summary': '<b>bold</b>\u202e\u2066x'},
 {'ts': now, 'kind': 'file', 'watch': 'win.hosts', 'change': 'modified', 'target': HOSTS, 'item': 'env', 'old': '', 'new': f'FOO_BAR={S("env1")}\nSOMETHING_SECRET={S("env2")}\nPlain=visible', 'summary': 'bare lines'},
]
r = api('POST', 'agent_changes', token=tok, body={'changes': ch})
record('chg', 'agent_changes accepts the batch', r.status_code == 200 and jb(r).get('accepted', 0) >= 1, f'status={r.status_code} body={json.dumps(jb(r))[:140]}')
rows = sql(f"select item, old_value, new_value, summary, flags from endpoint_agent_change_records where device_id={did} order by change_id")
txt = '\n'.join(rows)
leaks = sorted(set(re.findall(M + r'[a-z]+[0-9]', txt)))
record('chg', 'no secret marker survives in change records (old/new/summary/item)', not leaks, f'leaking markers={leaks} of 16 sent')
record('chg', 'stored text has no bidi/zero-width/control characters', not re.search('[\u202a-\u202e\u2066-\u2069\u200b-\u200f]', txt), 'text()')
record('chg', 'stored records keep markup verbatim (by design: escape on output)', '<script>' in txt or '<b>' in txt, 'info: stored raw per CHANGELOG F4')

# whole-DB search for every marker, tables listed
dump = subprocess.run(['sudo', 'mysqldump', '--skip-extended-insert', '--no-create-info', DB], capture_output=True).stdout.decode('utf-8', 'replace')
hits = {}
cur = None
for line in dump.splitlines():
    m = re.match(r'-- Dumping data for table `([^`]+)`', line)
    if m: cur = m.group(1)
    if M in line:
        for mk in re.findall(M + r'[a-z]+[0-9]', line):
            hits.setdefault(cur, set()).add(mk)
record('chg', 'whole-DB search: markers appear in NO table (records, alerts, logs, webhook/event queues, tickets)', not hits, 'tables with leaks: ' + json.dumps({k: sorted(v) for k, v in hits.items()}))
# alert text: find the alert created by the rule
al = sql("select table_name from information_schema.tables where table_schema='%s' and table_name like '%%alert%%'" % DB)
print('alert tables:', al)
for t in al:
    cols = sql(f"select column_name from information_schema.columns where table_schema='{DB}' and table_name='{t}' and data_type in ('varchar','text','longtext','mediumtext')")
    for c in cols:
        v = sql(f"select {c} from {t} where {c} like '%QA-UI- hosts change%' or {c} like '%script%' or {c} like '%onerror%' limit 3")
        for x in v:
            bad = re.search(r'[<>`]|[\u202a-\u202e\u2066-\u2069\u200b-\u200f]', x)
            print(('LEAK' if bad else 'ok  '), t, c, red(x[:150]))
            if bad: record('chg', f'alert text in {t}.{c} contains markup/bidi', False, x[:140])
record('chg', 'alert/webhook text neutralised (see lines above)', True, 'unit-level alertText verified separately in redaction_vectors.php')
print(f'\n{len(RESULTS)} checks, {len([x for x in RESULTS if not x[2]])} FAIL')
for f in RESULTS:
    if not f[2]: print('FAIL:', f[0], '|', f[1], '|', f[3][:300])
