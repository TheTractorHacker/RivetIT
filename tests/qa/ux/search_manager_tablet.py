from ux_common import *
p,b,ctx,pg=start(who='admin'); clicks=1
# global search
pg.goto(BASE+'/agent/dashboard.php'); pg.wait_for_load_state('load')
pg.fill("input[placeholder='Search everywhere']", 'printer'); clicks+=1; pg.keyboard.press('Enter'); clicks+=1; pg.wait_for_load_state('load'); time.sleep(1)
print('search url', pg.url[-60:], '| h1', pg.evaluate("document.querySelector('h1,[role=heading][aria-level=\"1\"]')?.textContent.trim()"))
groups=pg.evaluate("[...document.querySelectorAll('main .card-title, main h3, main h4, main h5')].map(e=>e.textContent.trim().slice(0,40))")
print('result groups', groups[:12]); pg.screenshot(path=f'{OUT}/search_printer.png', full_page=True)
links=pg.locator("main a[href]").count(); print('result links', links)
if links:
    h=pg.locator("main table a[href], main .list-group a[href], main .card a[href]").first; print('first result', h.inner_text()[:50].strip(), h.get_attribute('href')[:60])
pg.fill("input[placeholder='Search everywhere']", 'zzzznomatch123'); pg.keyboard.press('Enter'); pg.wait_for_load_state('load'); time.sleep(.8)
print('no-match text:', pg.evaluate("document.querySelector('main').innerText.slice(0,200)").replace('\n',' | ')); pg.screenshot(path=f'{OUT}/search_nomatch.png')
# IT manager: reports
for name,url in [('sla','/agent/reports/sla.php'),('tech','/agent/reports/technician_performance.php'),('bydept','/agent/reports/tickets_by_department.php')]:
    r=ctx.request.get(BASE+url, max_redirects=0); print('report', name, r.status)
pg.goto(BASE+'/agent/reports/'); pg.wait_for_load_state('load')
for t in ['Service Desk & SLA','Technician Performance']:
    pg.locator(f"main a:has-text('{t}')").first.click(); clicks+=1; pg.wait_for_load_state('load'); time.sleep(1.2)
    print(t,'->',pg.url[-50:], '| controls', [x.strip()[:22] for x in pg.locator('main a.btn, main button').all_text_contents() if x.strip()][:10])
    pg.screenshot(path=f"{OUT}/report_{t.split()[0].lower()}.png", full_page=True); pg.go_back(); pg.wait_for_load_state('load')
print('clicks', clicks)
b.close()
