"""Create a ticket from the client portal. usage: portal_ticket.py <it|msp>"""
import sys, json, time
import common
from common import *
from playwright.sync_api import sync_playwright
ed = sys.argv[1]; base, _ = EDITIONS[ed]; cred = {'it': ('sophie.tran@summitridge.example', 'DemoPass#2026'), 'msp': ('qa251@qa.example', 'QaPortal#2026x')}[ed]
r = {}; n = int(time.time()) % 100000
with sync_playwright() as pw:
    b = pw.chromium.launch(); c = b.new_context(ignore_https_errors=True); t = c.new_page(); errs = []
    t.on('console', lambda m: errs.append(m.text[:90]) if m.type == 'error' else None)
    t.goto(base + '/login.php'); t.fill('input[name=email]', cred[0]); t.fill('input[name=password]', cred[1]); t.locator('button[type=submit]').first.click(); t.wait_for_load_state('domcontentloaded')
    t.goto(base + '/client/ticket_add.php', wait_until='domcontentloaded'); t.wait_for_timeout(1500)
    r['fields'] = t.evaluate("[...document.querySelectorAll('main input,main select,main textarea,form input,form select,form textarea')].filter(e=>e.type!='hidden').map(e=>e.tagName[0]+':'+e.name+(e.required?'*':''))")
    r['rich_editor_present'] = t.locator('.tox-tinymce').count() > 0
    subj = f'QA-{ed.upper()}-PORTAL-{n}'
    t.fill('[name=subject]', subj)
    t.evaluate("()=>{const d=document.querySelector('[name=details]');if(d){d.value='portal details';if(window.tinymce&&tinymce.activeEditor)tinymce.activeEditor.setContent('portal details')}}")
    t.locator('button[name=add_ticket]').click(); t.wait_for_load_state('domcontentloaded'); t.wait_for_timeout(900)
    r['after_submit_url'] = t.url[len(base):]
    t.goto(base + '/client/tickets.php', wait_until='domcontentloaded'); r['listed_in_portal'] = subj in t.inner_text('body')
    r['errs'] = errs[:3]; b.close()
print(json.dumps(r, indent=1))
