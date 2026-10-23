"""Portal KB article: no CSP violations from article inline styles, formatting survives as classes, article still readable. Exit 0 = pass."""
from common import *
# fixture: a client-visible article whose body carries inline styles (what the portal must convert to classes)
html = '<p style="text-align:center;">QA-UI- centered <span style="font-weight:bold;">bold words</span></p><p>Plain paragraph.</p>'
mysql("delete from kb_articles where kb_article_title='QA-UI- styled article'")
mysql("insert into kb_articles (kb_article_title, kb_article_content, kb_article_content_raw, kb_article_client_visible, kb_article_created_by) values ('QA-UI- styled article', '" + html.replace("'", "''") + "', '" + html.replace("'", "''") + "', 1, 9)")
KB_ID = mysql("select kb_article_id from kb_articles where kb_article_title='QA-UI- styled article'")[0][0]
p = sync_playwright().start()
b, ctx, pg = login(p, 'portal')
msgs = []
pg.on('console', lambda m: msgs.append(m.text) if 'Content Security Policy' in m.text or 'Refused to apply inline style' in m.text else None)
r = pg.goto(BASE + '/client/kb_article.php?id=' + KB_ID, wait_until='networkidle')
ok = True
def check(name, cond):
    global ok
    print(('PASS ' if cond else 'FAIL ') + name); ok &= bool(cond)
check('article page 200', r.status == 200)
check('no style= attributes in the article body', pg.locator('.prettyContent [style]').count() == 0)
check('no CSP console violations', not msgs)
check('centered paragraph got kb-ta-center', pg.locator('.prettyContent p.kb-ta-center').count() >= 1)
check('bold span got kb-bold', pg.locator('.prettyContent .kb-bold').count() >= 1)
check('text-align applies', pg.locator('.prettyContent p.kb-ta-center').first.evaluate('e=>getComputedStyle(e).textAlign') == 'center')
check('article text still present', 'QA-UI- centered' in pg.locator('.prettyContent').inner_text())
if msgs: print('\n'.join(msgs[:5]))
b.close(); sys.exit(0 if ok else 1)
