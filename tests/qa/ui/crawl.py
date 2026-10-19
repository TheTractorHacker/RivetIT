"""Discover reachable pages per role by following in-app GET links (nav, admin index, tabs). Writes OUT/urls_<role>.json.
Skips logout/post/delete/archive/download style links. Dedupes by path + query-param names (max 2 id variants)."""
import sys, json, re
from urllib.parse import urlparse, parse_qsl, urljoin
from common import *

SKIP = re.compile(r'logout|post\.php|delete|archive|purge|restore|download|export|\.pdf|reset|disable|mailto:|tel:|javascript:|^#|github\.com|update_db|backup_now|oauth|connect|callback|sse_|calendar_feed|_csv|kb_media|training_media|video_frame|upload|_attachment|certificate\.php|training_certificate', re.I)
IGN = {'page', 'sort', 'order', 'canned_date', 'dtf', 'dtt', 'q', 'archived', 'tags[]', 'status', 'tab'}

def key(u):
    pu = urlparse(u)
    names = tuple(sorted(k for k, _ in parse_qsl(pu.query, keep_blank_values=True) if k not in IGN))
    return (pu.path, names)

def crawl(role, start_paths, maxpages=400):
    st = state_file(role)
    p, b, ctx, pg = start(role, storage=st)
    seen_keys = {}; queue = list(start_paths); done = []; order = []
    while queue and len(done) < maxpages:
        u = queue.pop(0)
        k = key(u)
        if seen_keys.get(k, 0) >= 2: continue
        seen_keys[k] = seen_keys.get(k, 0) + 1
        try:
            r = pg.goto(BASE + u if u.startswith('/') else u, wait_until='domcontentloaded', timeout=30000)
        except Exception as e:
            done.append({'url': u, 'status': 'ERR ' + str(e)[:60]}); continue
        st_ = r.status if r else 0
        done.append({'url': u, 'status': st_, 'title': pg.title()[:60]})
        if st_ >= 400 or '/login' in pg.url: continue
        try:
            pg.wait_for_load_state('load', timeout=15000)
            hrefs = pg.eval_on_selector_all('a[href]', 'els=>els.map(e=>e.href)')
        except Exception:
            continue
        for h in hrefs:
            pu = urlparse(h)
            if pu.netloc != urlparse(BASE).netloc: continue
            rel = pu.path + (('?' + pu.query) if pu.query else '')
            if SKIP.search(rel): continue
            if not (rel.startswith('/agent/') or rel.startswith('/admin') or rel.startswith('/client') or rel.startswith('/kiosk')): continue
            if rel not in queue and rel not in [d['url'] for d in done] and seen_keys.get(key(rel), 0) < 2:
                queue.append(rel)
    b.close(); p.stop()
    json.dump(done, open(os.path.join(OUT, 'urls_%s.json' % role), 'w'), indent=1)
    return done

if __name__ == '__main__':
    role = sys.argv[1]
    seeds = {'admin': ['/agent/dashboard.php', '/admin/', '/agent/reports/', '/agent/user/user_details.php'],
             'tech': ['/agent/dashboard.php'], 'portal': ['/client/index.php']}[role]
    d = crawl(role, seeds)
    bad = [x for x in d if not isinstance(x['status'], int) or x['status'] >= 400]
    print(role, len(d), 'pages;', len(bad), 'bad'); [print(' ', x) for x in bad]
