"""Screenshot a list of (url, w, h, crop) for quick before/after review. usage: shoot_list.py <outdir> ; items in ITEMS env json"""
from common import *
items = json.loads(os.environ['ITEMS']); out = sys.argv[1]; os.makedirs(out, exist_ok=True)
p = sync_playwright().start()
cache = {}
for i, it in enumerate(items):
    who = it.get('who', 'admin'); w, h = it.get('w', 1920), it.get('h', 1080)
    b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h})
    pg.goto(BASE + it['url'], wait_until='domcontentloaded'); time.sleep(1.8)
    if it.get('dark'): pg.evaluate("() => document.documentElement.setAttribute('data-bs-theme','dark')")
    pg.screenshot(path=f"{out}/{i:02d}_{it['name']}.png", full_page=bool(it.get('full')), clip=None)
    b.close()
p.stop()
