"""SQL-injection / escaping assertions for the unified Alerts page (search, source, status, severity, department, asset, group, page): hostile payloads must
give no SQL error text, no timing delay, no change of scope; % and _ in the search are literal. Scratch RMM install. Exit 0 = pass."""
from common import *
import urllib.parse as up
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:240] if detail and not ok else ''))
    if not ok: fails.append(name)
p = sync_playwright().start(); b, ctx, pg = login(p, 'admin')
ERR = re.compile(r'(You have an error in your SQL|mysqli_sql_exception|Warning:|Fatal error|Uncaught|SQLSTATE|near \'|Unknown column)', re.I)
def get(params):
    t0 = time.time(); r = ctx.request.get(BASE + '/agent/alerts.php?' + params, max_redirects=0); dt = time.time() - t0
    body = r.text(); m = re.search(r'(\d+) alerts?', re.sub(r'<[^>]+>', ' ', body[body.find('rmmx-result-count'):body.find('rmmx-result-count') + 300])) if 'rmmx-result-count' in body else None
    return r.status, body, dt, (int(m.group(1)) if m else None)
base_status, base_body, base_dt, base_n = get('status=all')
check('baseline page loads', base_status == 200 and base_n is not None, (base_status, base_n))
PAY = ["'", "\\'", "\\\\'", "%27", "' OR 1=1 -- ", "');SELECT SLEEP(3)-- ", "\\');SELECT SLEEP(3)-- ", "' UNION SELECT user(),2,3-- ", "%", "_", "%%", "\\", "a" * 6000, "\x00", "\"", "';DROP TABLE rmm_alerts;-- "]
tables_before = int(mysql("select count(*) from rmm_alerts")[0][0])
for pay in PAY:
    for field in ('q', 'source', 'status', 'severity', 'client_id', 'asset_id', 'group', 'page', 'sort', 'order', 'per_page'):
        st, body, dt, n = get(f'status=all&{field}=' + up.quote(pay, safe=''))
        label = f'{field}={pay[:18]!r}'
        ok = st == 200 and not ERR.search(re.sub(r'<script.*?</script>', '', body, flags=re.S)) and dt < 2.6
        if not ok: check(f'payload {label}: 200, no SQL error text, no delay', False, (st, round(dt, 2), (ERR.search(body) or [''])[0] if st == 200 else ''))
check('every hostile payload in every parameter: 200, no SQL error text, no delay (<2.6s)', not [f for f in fails if f.startswith('payload')])
check('a table was not dropped by a payload', int(mysql("select count(*) from rmm_alerts")[0][0]) == tables_before)
# scope: filter values are whitelisted / cast, so injected text cannot widen or break the result
_, _, _, n16 = get('status=all&client_id=16'); _, _, _, nx = get('status=all&client_id=' + up.quote('16 OR 1=1', safe=''))
check('client_id="16 OR 1=1" is read as 16 (no widened scope)', nx == n16 and nx < base_n, (n16, nx, base_n))
_, _, _, ns = get('status=all&severity=' + up.quote("critical' OR '1'='1", safe=''))
check('severity with an injected clause falls back to "any" (whitelist), it does not match everything through the clause', ns == base_n, (ns, base_n))
# % and _ are literal
msgs_pct = int(mysql("select count(*) from rmm_alerts where message like '%\\%%'")[0][0]) + int(mysql("select count(*) from comet_backup_alerts where alert_message like '%\\%%'")[0][0])
_, _, _, npct = get('status=all&q=' + up.quote('%', safe=''))
check('search "%" matches only text that really contains a percent sign (not everything)', (npct or 0) <= msgs_pct + 3 and (npct or 0) < base_n, (npct, msgs_pct, base_n))
_, _, _, nus = get('status=all&q=' + up.quote('_', safe=''))
check('search "_" is a literal underscore (not "any one character")', (nus or 0) < base_n, (nus, base_n))
_, _, _, nq = get('status=all&q=' + up.quote("O'Brien", safe=''))
check("a quote in the search is just a quote (0 results, no error)", nq == 0, nq)
# a real search still works and is case-insensitive
_, _, _, nh = get('status=all&q=' + up.quote('orphan', safe=''))
check('a normal search still finds the orphan agent alert', (nh or 0) >= 1, nh)
b.close(); print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
