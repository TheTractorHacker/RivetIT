"""Role-boundary probe (GET only, no browser): who can reach what. usage: boundary.py"""
from common import *
targets = [t for t in json.load(open(os.path.join(OUT, 'targets.json'))) if not t['skip'] and not t['sse']]
NOAUTH = re.compile(r'not authori[sz]ed|permission|access denied|forbidden|insufficient|don.t have access|not allowed', re.I)
matrix = [('tech', 'admin'), ('portal', 'admin'), ('portal', 'agent'), ('tech', 'client'), ('l2', 'admin'), (None, 'admin'), (None, 'agent'), (None, 'client')]
p = sync_playwright().start(); res = []
for who, mod in matrix:
    if who: b, ctx, pg = login(p, who)
    else: b = p.chromium.launch(); ctx = b.new_context()
    for t in [t for t in targets if t['module'] == mod]:
        rec = {'who': who or 'anonymous', 'file': t['file']}
        try:
            r = ctx.request.get(BASE + t['url'], max_redirects=0, timeout=15000)
            rec['status'] = r.status; rec['location'] = r.headers.get('location', '')
            if r.status == 200:
                body = r.text(); rec['bytes'] = len(body); rec['noauth_msg'] = bool(NOAUTH.search(body)); rec['php_error'] = bool(PHP_ERR.search(body))
                rec['snippet'] = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', re.sub(r'(?s)<(script|style).*?</\1>', '', body)))[:160]
        except Exception as e:
            rec['exception'] = str(e)[:120]
        res.append(rec)
    b.close()
    print(who, mod, flush=True)
json.dump(res, open(os.path.join(OUT, 'boundary.json'), 'w'), indent=1)
p.stop()
