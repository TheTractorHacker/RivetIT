"""Second target list: pages that take a bare ?id= (not *_id) - resolve a real row by file-stem guess. Writes out/targets_id.json"""
from common import *
T = json.load(open(os.path.join(OUT, 'targets.json')))
tables = {r[0] for r in mysql("show tables")}
out = []
for t in T:
    if t['skip'] or 'id' not in t['params'] or t['ids'].get('id'): continue
    stem = os.path.basename(t['file'])[:-4]
    stem = re.sub(r'(_details|_edit|_view|_add)$', '', stem)
    cand = [stem + 's', stem + 'es', stem[:-1] + 'ies', stem, 'kb_articles' if stem.startswith('kb') else '', 'ticket' + 's' if 'ticket' in stem else '']
    for c in cand:
        if c and c in tables:
            pkcol = c[:-1] + '_id' if c.endswith('s') else c + '_id'
            if c.endswith('ies'): pkcol = c[:-3] + 'y_id'
            if c == 'kb_articles': pkcol = 'kb_article_id'
            r = mysql("select `%s` from `%s` order by 1 limit 1" % (pkcol, c)) if mysql("show columns from `%s` like '%s'" % (c, pkcol)) else []
            if r:
                n = dict(t); n['url'] = '/' + t['file'] + '?id=' + r[0][0]; n['ids'] = {'id': r[0][0]}; out.append(n); break
json.dump(out, open(os.path.join(OUT, 'targets_id.json'), 'w'), indent=1)
print(len(out), [o['url'] for o in out])
