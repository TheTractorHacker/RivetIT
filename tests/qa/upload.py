"""Ticket attachment upload matrix. usage: upload.py <it|msp> <ticket url path>"""
import sys, json, re, os
from common import *
ed, url = sys.argv[1], sys.argv[2]; r = {}
p, b, ctx, pg, base = session(ed)
tid = re.search(r'ticket_id=(\d+)', url).group(1)
pg.goto(base + url, wait_until='domcontentloaded'); csrf = pg.evaluate("document.querySelector('[name=csrf_token]').value")
cases = {
 'ok.txt': b'hello', 'my file ünï 日本.txt': b'unicode name', 'spaces and (parens).txt': b'x', 'real.pdf': b'%PDF-1.4\n%%EOF', 'fake.pdf': b'<html><script>alert(1)</script>',
 'evil.php': b'<?php echo 1;', 'shell.PHP': b'<?php echo 1;', 'a.php.txt': b'<?php echo 1;', 'noext': b'abc', 'empty.txt': b'', 'x.html': b'<script>1</script>', 'x.svg': b'<svg onload=alert(1)>', 'big25mb.zip': os.urandom(25 * 1024 * 1024),
 '..%2f..%2fpwn.txt': b'traversal', 'a\x00.txt': b'nul', 'x.exe': b'MZ',
}
res = {}
for name, data in cases.items():
    try:
        resp = pg.request.post(base + '/agent/post.php', multipart={'csrf_token': csrf, 'ticket_id': tid, 'upload_ticket_attachment': '1', 'attachment_file[]': {'name': name, 'mimeType': 'application/octet-stream', 'buffer': data}}, headers={'Referer': base + url}, timeout=60000)
        res[name] = resp.status
    except Exception as e: res[name] = 'ERR ' + str(e)[:50]
r['post_status'] = res
pg.goto(base + url, wait_until='domcontentloaded'); pg.wait_for_timeout(800)
hrefs = pg.eval_on_selector_all('a[href*="attachment"], a[href*="uploads/tickets"]', 'e=>e.map(x=>x.getAttribute("href")+"|"+x.innerText.trim().slice(0,50))')
r['attachments_listed'] = hrefs[:25]
r['flash'] = pg.locator('.alert').all_inner_texts()[:3]
# stored files on disk (demo only)
d = {'it': '/home/sysadmin/demos/rivetit/uploads/tickets', 'msp': '/home/sysadmin/demos/rivetmsp/uploads/tickets'}[ed]
r['on_disk'] = sorted(os.listdir(os.path.join(d, tid)) if os.path.isdir(os.path.join(d, tid)) else os.listdir(d))[:25]
b.close(); p.stop(); print(json.dumps(r, indent=1, ensure_ascii=False))
