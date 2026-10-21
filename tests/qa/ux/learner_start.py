from ux_common import *
p,b,ctx,pg=start(who='portal',portal=True)
pg.goto(BASE+'/client/training.php'); pg.wait_for_load_state('domcontentloaded'); time.sleep(.8)
pg.locator("main >> text=Forklift Safety Refresher").first.click(); time.sleep(3)
c=ctx.pages[-1]
def onr(r):
    if 'api.php' in r.url:
        try: body=r.text()[:300]
        except Exception as e: body='?'
        print('RESP', r.request.method, r.status, r.url[-50:], body, dict((k,v) for k,v in r.request.headers.items() if k.lower() in ('x-kiosk-token','origin','referer','cookie')))
c.on('response', onr)
print('cookies', [(k['name'], k['path']) for k in ctx.cookies()])
c.locator("text=Start course").first.click(); time.sleep(3)
print(c.url)
b.close()
