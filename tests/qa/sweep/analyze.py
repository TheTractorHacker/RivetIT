from common import *
import glob
flag = []; counts = {}
for f in sorted(glob.glob(os.path.join(OUT, 'sweep_*.json'))):
    for r in json.load(open(f)):
        c = counts.setdefault(r['role'], {'pages': 0, 'variants': 0, 'skipped': 0}); 
        if r['skipped']: c['skipped'] += 1; continue
        c['pages'] += 1
        for v in r['variants']:
            c['variants'] += 1
            issues = []
            if v.get('exception'): issues.append('EXC ' + v['exception'][:100])
            if v.get('status') and v['status'] >= 400: issues.append(f"HTTP {v['status']}")
            if v.get('php_error'): issues.append('PHP ' + str(v['php_error'])[:140])
            if v.get('pageerrors'): issues.append('JSERR ' + v['pageerrors'][0][:100])
            if v.get('console'): issues.append('CONSOLE ' + ' | '.join(v['console'])[:200])
            if v.get('bad_requests'): issues.append('REQ ' + ' | '.join(v['bad_requests'])[:200])
            if v.get('failed_requests'): issues.append('FAILED ' + ' | '.join(v['failed_requests'])[:160])
            if v.get('hscroll', -99) > 2: issues.append(f"HSCROLL {v['hscroll']}")
            if 'js' in v and not v['js']['bootstrap'] and v.get('status') == 200 and not v.get('final', '').startswith('/login') : issues.append('NO-BOOTSTRAP-JS')
            if 'bodyLen' in v and v['bodyLen'] < 40 and v.get('status') == 200: issues.append(f"BLANK({v['bodyLen']})")
            if v.get('broken_hrefs'): issues.append('BROKEN-HREF ' + ','.join(v['broken_hrefs']))
            if v.get('dead_anchors'): issues.append('DEAD-ANCHOR ' + ','.join(v['dead_anchors']))
            for m in v.get('modals', []):
                if m.get('shown') is False or m.get('php_error') or m.get('offscreen') or m.get('error') or m.get('new_errors'): issues.append('MODAL ' + json.dumps(m)[:160])
            for t in v.get('tabs', []):
                if t.get('error') or t.get('activated') is False or t.get('new_errors'): issues.append('TAB ' + json.dumps(t)[:120])
            if issues: flag.append((r['role'], r['file'], v['url'], v.get('final'), issues, v.get('shot')))
print(counts)
for role, f, u, fin, iss, shot in flag:
    print(f"[{role}] {u}" + (f" -> {fin}" if fin and fin != u.split('?')[0] and fin != u else '') + ' :: ' + ' ;; '.join(iss))
print(len(flag), 'flagged variants')
