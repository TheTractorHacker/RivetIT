"""Enumerate admin/agent/client pages and resolve required id params to real scratch-DB rows. Writes out/targets.json"""
from common import *
pk = {}
for t, c in mysql("select table_name, column_name from information_schema.columns where table_schema='%s' and column_key='PRI' and column_name like '%%\\_id'" % DB):
    pk.setdefault(c, []).append(t)
first = {}
def val(param):
    if param in first: return first[param]
    v = None
    base = param[:-3]
    cands = sorted(pk.get(param, []), key=lambda t: (t not in (base, base + 's', base[:-1] + 'ies'), len(t)))
    for t in cands:
        r = mysql("select `%s` from `%s` order by `%s` limit 1" % (param, t, param))
        if r: v = r[0][0]; break
    first[param] = v; return v
# tickets/clients that have rows are better 'first' choices; prefer rows with most children
r = mysql("select ticket_id from tickets order by ticket_id desc limit 1"); first['ticket_id'] = r[0][0] if r else None
targets = []
for mod in ('admin', 'agent', 'client'):
    for f in sorted(glob.glob(f'{APP}/{mod}/*.php')):
        name = os.path.basename(f); rel = f'{mod}/{name}'
        src = open(f, errors='ignore').read()
        params = []
        for m in re.finditer(r"\$_GET\[\s*['\"]([a-z_]+)['\"]\s*\]", src):
            if m.group(1) not in params: params.append(m.group(1))
        q = {p_: val(p_) for p_ in params if p_.endswith('_id') and val(p_)}
        if mod == 'agent' and 'client_id' in params and 'client_id' not in q: pass
        url = '/' + rel + ('?' + '&'.join(f'{k}={v}' for k, v in q.items()) if q else '')
        targets.append({'module': mod, 'file': rel, 'url': url, 'params': params, 'ids': q,
                        'skip': SKIP.get(rel), 'ajax': rel in AJAX, 'sse': rel in SSE})
# tabs: gather ?tab=xxx values referenced anywhere for settings pages + same-file
tabs = {}
allsrc = ''
for f in glob.glob(f'{APP}/admin/*.php') + glob.glob(f'{APP}/agent/*.php') + glob.glob(f'{APP}/includes/*.php') + glob.glob(f'{APP}/modals/*/*.php'):
    allsrc += open(f, errors='ignore').read()
for m in re.finditer(r'((?:admin|agent)?/?[a-z_]+\.php)\?tab=([a-z_\-]+)', allsrc):
    tabs.setdefault(m.group(1).split('/')[-1], set()).add(m.group(2))
for t in targets:
    t['tabs'] = sorted(tabs.get(os.path.basename(t['file']), []))
json.dump(targets, open(os.path.join(OUT, 'targets.json'), 'w'), indent=1)
from collections import Counter
print(Counter(t['module'] for t in targets), 'skip', sum(1 for t in targets if t['skip']), 'ajax', sum(1 for t in targets if t['ajax']),
      'with ids', sum(1 for t in targets if t['ids']), 'with tab variants', sum(1 for t in targets if t['tabs']))
print({k: v for k, v in list(first.items())[:40]})
