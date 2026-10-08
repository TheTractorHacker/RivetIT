"""Ticket lifecycle: internal note -> public note -> On Hold -> Open -> Resolved (closes) -> Reopen.
usage: ticket_lifecycle.py <it|msp> <ticket url path>"""
import sys, json
from common import *
ed, url = sys.argv[1], sys.argv[2]
r = {}
p, b, ctx, pg, base = session(ed)
errs = []; pg.on('pageerror', lambda e: errs.append(str(e)[:120]))
pg.on('console', lambda m: errs.append(m.text[:140]) if m.type == 'error' else None)
def load():
    pg.goto(base + url, wait_until='domcontentloaded'); pg.wait_for_timeout(900)
def status():
    return pg.evaluate("()=>{const s=document.getElementById('quickStatusSelect');return s?s.selectedOptions[0].text.trim():(document.body.innerText.match(/Ticket# [^\\n]*\\n?([A-Za-z ]+)/)||[])[1]}")
def reply(text, rtype, status_label=None):
    f = pg.locator('form:has([name=add_ticket_reply])')
    f.locator(f'[name=public_reply_type][value="{rtype}"]').evaluate('e=>e.click()')
    f.locator('textarea[name=ticket_reply]').evaluate("(e,t)=>{e.value=t; if(window.tinymce&&tinymce.get(e.id))tinymce.get(e.id).setContent(t); e.dispatchEvent(new Event('input'))}", text)
    if status_label:
        f.locator('.dropdown-toggle-split').click()
        f.locator('.reply-status-submit', has=pg.locator(f'text="{status_label}"')).first.click()
    else:
        f.locator('[name=add_ticket_reply]').click()
    pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(900)
load(); r['start'] = status()
reply('QA internal note ÜñÏ 日本', '0');  r['internal_visible'] = 'QA internal note' in pg.inner_text('body')
reply('QA public note', '1');             r['public_visible'] = 'QA public note' in pg.inner_text('body')
for lbl in ('On Hold', 'Open'):
    reply(f'QA -> {lbl}', '1', lbl); r['after_'+lbl] = status()
reply('QA -> Resolved', '1', 'Resolved'); load()
r['after_Resolved'] = pg.evaluate("document.body.innerText.includes('Ticket closed.')") and 'closed (reply form removed)' if pg.locator('[name=add_ticket_reply]').count()==0 else 'reply form still present'
pg.get_by_text('Reopen', exact=True).first.click(); pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(900)
load(); r['after_Reopen'] = status(); r['reply_form_back'] = pg.locator('[name=add_ticket_reply]').count() > 0
r['errs'] = errs[:5]
print(json.dumps(r, indent=1)); b.close(); p.stop()
