"""MSP: enable portal login for a contact, then test the client portal as that contact (crawl + cross-client IDOR)."""
import sys, json, re
from common import *
r = {}; pw = 'QaPortal#2026x'
p, b, ctx, pg, base = session('msp')
pg.goto(base + '/agent/contacts.php', wait_until='domcontentloaded')
email = 'qa251@qa.example'; r['contact_email'] = email
r['edit_skipped'] = 'portal login already enabled by earlier run (contact 10, user 5)'
c = b.new_context(ignore_https_errors=True); t = c.new_page(); errs = []
t.on('pageerror', lambda e: errs.append(str(e)[:100])); t.on('console', lambda m: errs.append(m.text[:100]) if m.type == 'error' else None)
t.goto(base + '/login.php'); t.fill('input[name=email]', email); t.fill('input[name=password]', pw); t.locator('button[type=submit]').first.click(); t.wait_for_load_state('domcontentloaded'); t.wait_for_timeout(700)
r['portal_login_url'] = t.url[len(base):]
if '/client/' in t.url:
    links = sorted({h[len(base):] for h in t.eval_on_selector_all('a[href]', 'e=>e.map(x=>x.href)') if h.startswith(base + '/client/') and 'logout' not in h and 'post.php' not in h and '#' not in h})
    pages = {}
    for l in links[:30]:
        try: resp = t.goto(base + l, wait_until='domcontentloaded')
        except Exception as e: pages[l] = -1; continue
        txt = t.inner_text('body'); pages[l] = [resp.status if resp else 0, bool(re.search(r'Fatal error|Warning:|Stack trace|mysqli', txt))]
    r['portal_pages'] = {k: v for k, v in pages.items()}
    own = set(re.findall(r'ticket\.php\?id=(\d+)', ' '.join(links)))
    seen = {}
    for tid in range(1, 40):
        try: t.goto(base + f'/client/ticket.php?id={tid}', wait_until='domcontentloaded')
        except Exception: continue
        if 'login' in t.url:
            t.fill('input[name=email]', email); t.fill('input[name=password]', pw); t.locator('button[type=submit]').first.click(); t.wait_for_load_state('domcontentloaded'); continue
        if 'TCK-' in t.inner_text('body') and 'Login' not in t.title(): seen[tid] = 1
    r['own_listed'] = sorted(own); r['tickets_viewable_1_39'] = sorted(seen); r['viewable_not_own'] = sorted(set(map(str, seen)) - own)
    r['errs'] = errs[:4]
b.close(); p.stop(); print(json.dumps(r, indent=1))
