#!/usr/bin/env python3
"""Targeted widget / flow checks (scratch instance, user QA-UI- Admin 2): wizard, guides, event rules, date range picker, icon picker, Deploy page, Mesh states.
Records findings via record(); screenshots go to evidence/ (small JPG)."""
import sys, os, re, json, glob, time
PY = glob.glob('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/*/scratchpad/pylibs')[0]
sys.path.insert(0, PY); sys.path.insert(1, '/home/sysadmin/.local/lib/python3.12/site-packages'); sys.path.insert(2, os.path.dirname(__file__))
from playwright.sync_api import sync_playwright
from common import *
EV = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'evidence'); os.makedirs(EV, exist_ok=True)
AXE_SRC = open(glob.glob('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/*/scratchpad/axe.min.js')[0]).read()
fx = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out/fixtures.json')))
INIT = "window.__dlg=[];['alert','confirm','prompt'].forEach(function(k){var o=window[k];window[k]=function(){window.__dlg.push(k);return k==='confirm'?false:null};});"
FOCUS = r"""() => { const e=document.activeElement; if(!e||e===document.body) return null; const cs=getComputedStyle(e); const r=e.getBoundingClientRect();
 const has=(cs.outlineStyle!=='none'&&parseFloat(cs.outlineWidth)>0)||(cs.boxShadow&&cs.boxShadow!=='none');
 return {tag:e.tagName.toLowerCase(),role:e.getAttribute('role'),name:(e.getAttribute('aria-label')||e.getAttribute('title')||e.textContent||e.value||e.placeholder||'').trim().replace(/\s+/g,' ').slice(0,40),has:!!has,vis:r.width>0&&r.height>0,cls:(e.className||'').toString().slice(0,40)}; }"""
def shot(pg, name):
    pg.screenshot(path=os.path.join(EV, name + '.jpg'), type='jpeg', quality=55, full_page=False)
def axe(pg, scope=None):
    pg.evaluate(AXE_SRC)
    return pg.evaluate("async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}});return r.violations.map(v=>({id:v.id,n:v.nodes.length,html:v.nodes[0].html.slice(0,120)}))}")
def tabs(pg, n):
    seq = []; pg.evaluate("document.activeElement&&document.activeElement.blur()")
    for _ in range(n):
        pg.keyboard.press('Tab'); r = pg.evaluate(FOCUS)
        if r: seq.append(r)
    return seq

def main():
    with sync_playwright() as p:
        b = p.chromium.launch()
        for theme in ('light', 'dark'):
            sql(f"update user_settings set user_config_theme_dark={2 if theme == 'light' else 1} where user_id=16")
            for (w, h) in ((1280, 800), (768, 1024), (360, 740)):
                ctx = b.new_context(viewport={'width': w, 'height': h}, ignore_https_errors=True); ctx.add_init_script(INIT)
                pg = ctx.new_page(); pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', 'qa.admin2@summitridge.example'); pg.fill('input[name=password]', ADMIN[1]); pg.locator('button[type=submit]').first.click()
                pg.wait_for_url(lambda u: 'login' not in u, timeout=20000)
                tag = f'{theme}_{w}'
                # ---------------- webhook wizard
                pg.goto(BASE + '/admin/webhook_new.php', wait_until='networkidle'); time.sleep(.3)
                shot(pg, f'wz_platform_{tag}')
                ov = pg.evaluate("document.documentElement.scrollWidth>innerWidth+1")
                record('wizard', f'step 1 no horizontal overflow @{w} {theme}', not ov, f'sw={pg.evaluate("document.documentElement.scrollWidth")}')
                if w == 1280 and theme == 'light':
                    seq = tabs(pg, 30)
                    noring = [x for x in seq if x['vis'] and not x['has']]
                    record('wizard', 'keyboard: every tab stop on step 1 shows a focus indicator', not noring, f'{len(seq)} stops; without indicator: {[x["tag"]+":"+x["name"] for x in noring][:6]}')
                    names = [x['name'] for x in seq if x['tag'] in ('a', 'button', 'input') and not x['name']]
                    record('wizard', 'keyboard: every tab stop has an accessible name', not names, f'unnamed stops={len(names)}')
                    order = [x['name'][:18] for x in seq[:12]]; print('  tab order:', order)
                ax = axe(pg); record('wizard', f'axe AA on step 1 @{w} {theme}', not ax, json.dumps(ax)[:250])
                # choose first platform by keyboard activation
                card = pg.locator('a.wz-pcard').first
                if card.count():
                    card.focus(); pg.keyboard.press('Enter'); time.sleep(.6)
                    shot(pg, f'wz_connect_{tag}')
                    st = pg.evaluate("[...document.querySelectorAll('[data-wz-step-panel]')].filter(e=>!e.hidden).map(e=>e.dataset.wzStepPanel)")
                    record('wizard', f'Enter on a platform card moves to step 2 @{w} {theme}', st == ['connect'], f'visible steps={st} url={pg.url.split("8196")[-1][:60]}')
                    ax = axe(pg); record('wizard', f'axe AA on step 2 @{w} {theme}', not ax, json.dumps(ax)[:250])
                    # try Next with empty required fields -> error state
                    nxt = pg.locator('[data-wz-next], button:has-text("Next"), button:has-text("Continue")').first
                    if nxt.count():
                        nxt.click(); time.sleep(.5); shot(pg, f'wz_connect_error_{tag}')
                        inv = pg.evaluate("[...document.querySelectorAll('[aria-invalid=true], .is-invalid')].map(e=>({id:e.id,desc:e.getAttribute('aria-describedby'),has:!!(e.getAttribute('aria-describedby')&&document.getElementById(e.getAttribute('aria-describedby').split(' ')[0]))}))")
                        live = pg.evaluate("[...document.querySelectorAll('[role=alert],[aria-live]')].filter(e=>e.textContent.trim()).map(e=>e.textContent.trim().slice(0,70))")
                        record('wizard', f'empty Next shows an error linked to the field (aria-invalid + aria-describedby) @{w} {theme}', bool(inv) and all(x['has'] for x in inv), f'invalid={inv[:3]} live={live[:2]}')
                    # event picker step
                    for _ in range(3):
                        n2 = pg.locator('[data-wz-next]').first
                        if n2.count() and n2.is_visible():
                            fl = pg.locator('[data-wz-step-panel="connect"] input[type=url], [data-wz-step-panel="connect"] input[type=text]').first
                            if fl.count() and fl.is_visible() and not fl.input_value(): fl.fill('https://example.test/hook')
                            n2.click(); time.sleep(.4)
                    st = pg.evaluate("[...document.querySelectorAll('[data-wz-step-panel]')].filter(e=>!e.hidden).map(e=>e.dataset.wzStepPanel)")
                    shot(pg, f'wz_{"_".join(st) or "x"}_{tag}')
                    if 'events' in st:
                        ax = axe(pg); record('event-picker', f'axe AA on the events step @{w} {theme}', not ax, json.dumps(ax)[:250])
                        if w == 1280 and theme == 'light':
                            seq = tabs(pg, 25); noring = [x for x in seq if x['vis'] and not x['has']]
                            record('event-picker', 'keyboard: tab stops in the event picker show a focus indicator', not noring, f'{len(seq)} stops; none: {[x["name"] for x in noring][:5]}')
                            cb = pg.locator('[data-wz-step-panel="events"] input[type=checkbox]').first
                            if cb.count():
                                cb.focus(); pg.keyboard.press('Space'); time.sleep(.2)
                                record('event-picker', 'Space toggles an event checkbox', cb.is_checked(), '')
                # ---------------- guides
                pg.goto(BASE + '/admin/settings_webhook_guides.php', wait_until='networkidle'); time.sleep(.3); shot(pg, f'wg_{tag}')
                ax = axe(pg); record('guides', f'axe AA @{w} {theme}', not ax, json.dumps(ax)[:300])
                # ---------------- event rules
                pg.goto(BASE + '/admin/event_rules.php', wait_until='networkidle'); time.sleep(.3); shot(pg, f'er_{tag}')
                ax = axe(pg); record('event-rules', f'axe AA list @{w} {theme}', not ax, json.dumps(ax)[:300])
                pg.goto(BASE + '/admin/event_rules.php?new=1&recipe=critical-ticket', wait_until='networkidle'); time.sleep(.4); shot(pg, f'er_builder_{tag}')
                ax = axe(pg); record('event-rules', f'axe AA builder @{w} {theme}', not ax, json.dumps(ax)[:300])
                if w == 1280 and theme == 'light':
                    seq = tabs(pg, 40); noring = [x for x in seq if x['vis'] and not x['has']]
                    record('event-rules', 'keyboard: builder tab stops show a focus indicator', not noring, f'{len(seq)} stops; none: {[x["tag"]+":"+x["name"] for x in noring][:6]}')
                # ---------------- date range picker (tickets)
                pg.goto(BASE + '/agent/tickets.php', wait_until='networkidle'); time.sleep(.3)
                dr = pg.locator('#ticketDateRange, [id^=ticketDateRange]').first
                if dr.count():
                    try:
                        dr.scroll_into_view_if_needed(); dr.click(); time.sleep(.4); shot(pg, f'daterange_open_{tag}')
                        ax = axe(pg); record('date-range', f'axe AA with the picker open @{w} {theme}', not ax, json.dumps(ax)[:250])
                        pg.keyboard.press('Escape'); time.sleep(.2)
                        still = pg.evaluate("!!document.querySelector('.drp-menu.show, .dropdown-menu.show, [data-drp-panel]:not([hidden])')")
                        record('date-range', f'Esc closes the picker @{w} {theme}', not still, f'panel still open={still}')
                    except Exception as e:
                        record('date-range', f'open/close @{w} {theme}', False, str(e)[:120])
                else:
                    record('date-range', f'picker present on tickets @{w}', False, 'no #ticketDateRange')
                # ---------------- icon picker (custom links add modal)
                pg.goto(BASE + '/admin/custom_link.php', wait_until='networkidle'); time.sleep(.3)
                add = pg.locator('a[data-modal-url*="custom_link_add"], button[data-modal-url*="custom_link_add"], a.ajax-modal:has-text("Add"), [data-bs-toggle=modal]:has-text("Add")').first
                if add.count():
                    add.click(); time.sleep(.9); shot(pg, f'icon_modal_{tag}')
                    ib = pg.locator('.icon-picker button, [data-icon-picker], .icon-picker__btn, button:has-text("Choose icon"), button:has-text("Icon")').first
                    if ib.count():
                        ib.click(); time.sleep(.5); shot(pg, f'icon_picker_{tag}')
                        ax = axe(pg); record('icon-picker', f'axe AA with the picker open @{w} {theme}', not ax, json.dumps(ax)[:250])
                        if w == 1280 and theme == 'light':
                            seq = tabs(pg, 15); noring = [x for x in seq if x['vis'] and not x['has']]
                            record('icon-picker', 'keyboard: tab stops show a focus indicator', not noring, f'{len(seq)} stops; none: {[x["name"] for x in noring][:5]}')
                            pg.keyboard.press('Escape'); time.sleep(.3)
                            op = pg.evaluate("!!document.querySelector('.icon-picker__panel:not([hidden]), .icon-picker.open, .icon-picker .show')")
                            record('icon-picker', 'Esc closes the picker panel (not the whole modal)', not op, f'panel open after Esc={op}')
                    else:
                        record('icon-picker', f'icon picker trigger found in the modal @{w} {theme}', False, 'selector not found')
                else:
                    record('icon-picker', f'custom link add trigger found @{w} {theme}', False, 'selector not found')
                ctx.close()
        b.close()
    sql("update user_settings set user_config_theme_dark=2 where user_id=16")
    fails = [x for x in RESULTS if not x[2]]
    json.dump(RESULTS, open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out/widgets.json'), 'w'), indent=1)
    print(f'\n{len(RESULTS)} checks, {len(fails)} FAIL')
    for f in fails: print('FAIL:', f[0], '|', f[1], '|', f[3][:300])
if __name__ == '__main__': main()
