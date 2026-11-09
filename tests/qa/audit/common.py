"""Shared helpers for the read-only beta audit. Scratch instance only (127.0.0.1:8196, DB qa_beta_audit_demo).
Secrets (device tokens, enrollment tokens) are held in memory and are never printed: use red() on anything shown."""
import os, re, json, subprocess, time, uuid, base64, threading, concurrent.futures as cf
import requests

BASE = os.environ.get('AUD_BASE', 'http://127.0.0.1:8196')
DB = os.environ.get('AUD_DB', 'qa_beta_audit_demo')
APP = os.environ.get('AUD_APP', '/home/sysadmin/qa-beta-audit-app')
MYSQL = '/home/sysadmin/demos/mysqlroot.sh'
ADMIN = ('alex.morgan@summitridge.example', 'DemoAdmin#2026')
TECH = ('priya.nair@summitridge.example', 'DemoPass#2026')
HDR_TLS = {'X-Forwarded-Proto': 'https'}   # the API refuses plain HTTP (426 tls_required); the app trusts this header from a loopback/private peer

RESULTS = []


def red(s):
    s = str(s)
    s = re.sub(r'rvpd_[0-9a-f]{8,}', 'rvpd_<redacted>', s)
    s = re.sub(r'rvtp1\.[0-9a-f]{12}\.[0-9a-f]{8,}', 'rvtp1.<redacted>', s)
    return s


def sql(q):
    r = subprocess.run([MYSQL, DB, '-N', '-B', '-e', q], capture_output=True, text=True)
    out = [l for l in r.stdout.splitlines() if l.strip()]
    return out


def sqlv(q):
    o = sql(q)
    return o[0] if o else None


def record(area, name, ok, detail=''):
    RESULTS.append((area, name, bool(ok), red(detail)))
    print(('PASS' if ok else 'FAIL'), '|', area, '|', name, '|', red(detail)[:300])


def login(who=ADMIN):
    s = requests.Session()
    s.get(BASE + '/login.php')
    r = s.post(BASE + '/login.php', data={'email': who[0], 'password': who[1], 'login': ''}, allow_redirects=False)
    return s


def csrf(s, path):
    html = s.get(BASE + path).text
    m = re.search(r'name="csrf_token" value="([^"]+)"', html) or re.search(r"csrf_token', '([^']+)'", html)
    return m.group(1) if m else None


def mint_token(s, client_id, contact_id=None, label='QA-UI- panel', ttl=24):
    tok = csrf(s, f'/agent/panel_deploy.php?client_id={client_id}')
    data = {'create_token': '1', 'csrf_token': tok, 'client_id': client_id, 'label': label, 'ttl_hours': ttl}
    if contact_id:
        data['contact_id'] = contact_id
    r = s.post(BASE + '/agent/post/panel_deploy.php', data=data, headers={'Referer': BASE + f'/agent/panel_deploy.php?client_id={client_id}'}, allow_redirects=True)
    m = re.search(r'(rvtp1\.[0-9a-f]{12}\.[0-9a-f]{40})', r.text)
    return m.group(1) if m else None


def api(method, path, token=None, body=None, headers=None, raw=None, tls=True, timeout=30):
    h = {}
    if tls:
        h.update(HDR_TLS)
    if token:
        h['Authorization'] = 'Bearer ' + token
    if headers:
        h.update(headers)
    kw = {'headers': h, 'timeout': timeout, 'allow_redirects': False}
    if raw is not None:
        kw['data'] = raw
    elif body is not None:
        kw['json'] = body
    return requests.request(method, BASE + '/api/v1/' + path, **kw)


def enroll(enr_token, hostname='QA-UI-PANEL-01', **extra):
    dev = {'hostname': hostname, 'os': 'windows', 'install_id': str(uuid.uuid4()), 'os_version': '11 23H2', 'app_version': '0.1.0'}
    dev.update(extra)
    return api('POST', 'panel_enroll', body={'enrollment_token': enr_token, 'device': dev})


def jb(r):
    try:
        return r.json()
    except Exception:
        return {'_raw': r.text[:200]}
