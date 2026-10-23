"""Per-page check as a non-primary portal contact (Sophie) or primary (Grace): does opening the page sign the user out?
Reuses one login while the session lives; re-logs in (waiting out login throttling) only after a sign-out."""
from common import *
who = sys.argv[1]; pages = sys.argv[2:]; out = {}
p = sync_playwright().start(); b, ctx, pg = login(p, who)
def relogin():
    global b, ctx, pg
    for attempt in range(20):
        b.close(); b, ctx, pg = login(p, who)
        if 'login' not in pg.url: return True
        time.sleep(30)
    return False
for f in pages:
    if 'login' in pg.url and not relogin(): out[f] = 'COULD-NOT-LOGIN'; continue
    pg.goto(BASE + '/client/' + f); time.sleep(.6)
    out[f] = pg.url.replace(BASE, '')
json.dump(out, open(os.path.join(OUT, f'signout_{who}.json'), 'w'), indent=1)
for k, v in out.items(): print(k.ljust(28), '-> ' + v)
p.stop()
