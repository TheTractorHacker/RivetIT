"""Portal KB article: no CSP violations from article inline styles, formatting survives as classes, article still readable. Exit 0 = pass."""
from common import *
# make the check self-contained: article 1 gets styled content once (QA-UI- marker), the way an editor would produce it
if not mysql("select 1 from kb_articles where kb_article_id=1 and kb_article_content like '%QA-UI- centered%'"):
    mysql("update kb_articles set kb_article_client_visible=1, kb_article_archived_at=NULL, kb_article_client_id=0, kb_article_content=concat('<p style=\"text-align:center;color:red\">QA-UI- centered <strong style=\"font-weight:bold\">bold</strong></p><table style=\"width:100%;border:1px solid #000\"><tr><td style=\"padding-left:30px\">cell</td></tr></table>', kb_article_content) where kb_article_id=1")
p = sync_playwright().start()
b, ctx, pg = login(p, 'portal')
msgs = []
pg.on('console', lambda m: msgs.append(m.text) if 'Content Security Policy' in m.text or 'Refused to apply inline style' in m.text else None)
r = pg.goto(BASE + '/client/kb_article.php?id=1', wait_until='networkidle')
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
