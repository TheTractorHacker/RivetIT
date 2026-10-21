"""End-user persona (portal): raise ticket -> see it -> try attachment -> reply -> KB article."""
from ux_common import *
p,b,ctx,pg=start(who='portal',portal=True); clicks=1
def toast(): return [t.strip()[:110] for t in pg.locator('.toast, .alert').all_text_contents() if t.strip()][:2]
pg.locator("a:has-text('New ticket')").first.click(); clicks+=1; pg.wait_for_load_state('domcontentloaded'); time.sleep(1)
pg.fill('input[name=subject]','QA-UI- Portal request: monitor flickers')
pg.frame_locator('iframe').first.locator('body').click(); pg.frame_locator('iframe').first.locator('body').type('QA-UI- portal ticket body.'); clicks+=1
pg.locator("button:has-text('Raise ticket')").click(); clicks+=1; pg.wait_for_load_state('domcontentloaded'); time.sleep(2)
print('after submit', pg.url[-60:], toast()); pg.screenshot(path=f'{OUT}/portal_ticket_created.png', full_page=True)
print('file inputs on ticket view:', pg.locator('input[type=file]').count(), '| upload words:', pg.locator("text=/attach|upload/i").count())
pg.locator("textarea, iframe").first
# reply
fr=pg.frame_locator('iframe')
if pg.locator('iframe').count():
    fr.first.locator('body').click(); fr.first.locator('body').type('QA-UI- portal reply'); clicks+=1
    pg.locator("button:has-text('Reply'), button:has-text('Send'), button:has-text('Submit')").first.click(); clicks+=1; time.sleep(2); print('reply', toast())
# KB
pg.goto(BASE+'/client/index.php'); pg.locator("a:has-text('Knowledge Base')").first.click(); clicks+=1; pg.wait_for_load_state('domcontentloaded'); time.sleep(.8)
pg.locator("a[href*='kb_article.php']").first.click(); clicks+=1; pg.wait_for_load_state('domcontentloaded'); time.sleep(.8)
print('kb', pg.url[-45:], pg.title()[:50]); pg.screenshot(path=f'{OUT}/portal_kb_article.png')
print('clicks', clicks); b.close()
