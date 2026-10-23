"""GET robustness: bad page numbers / nonexistent ids must not 500 or leak errors."""
from ux_common import *
p,b,ctx,pg=start(who='admin')
req=ctx.request
pages=[l.strip() for l in open(os.path.join(HERE,'pages_agent.txt')) if l.strip().endswith('.php')]
bad=[]
def probe(url):
    r=req.get(BASE+url, max_redirects=0)
    t=r.text()
    flag = r.status>=500 or any(x in t[:20000] for x in ('Fatal error','Uncaught','Warning:','mysqli_sql_exception','Stack trace','Parse error'))
    return r.status, flag
for path in pages:
    for q in ['page=-5','page=abc','page=99999999999999999999','sort=nonsense&order=XX']:
        s,f=probe(f"{path}?{q}")
        if f: bad.append((f"{path}?{q}",s))
for u in ['/agent/asset_details.php?client_id=22&asset_id=999999','/agent/asset_details.php?asset_id=-1','/agent/ticket.php?ticket_id=999999','/agent/ticket.php?ticket_id=-1','/agent/client_overview.php?client_id=999999','/agent/contact_details.php?client_id=22&contact_id=999999','/agent/kb_article.php?article_id=999999','/agent/location_details.php?location_id=999999','/agent/vendor_details.php?vendor_id=999999','/agent/domain_details.php?domain_id=999999','/agent/software_details.php?software_id=999999','/agent/project_details.php?project_id=999999','/agent/training_course.php?course_id=999999','/agent/training_record.php?record_id=999999','/agent/credential_details.php?credential_id=999999']:
    s,f=probe(u)
    print(u,s,'BAD' if f else '')
    if f: bad.append((u,s))
print('BAD:',len(bad))
for x in bad[:60]: print(x)
b.close()
