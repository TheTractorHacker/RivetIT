"""Visual proof of the UI fixes: tall modals scroll inside the dialog; template-detail, portal allowance/assets render. light+dark, 1920x1080 + 1366x768."""
from common import *
EV = os.path.join(HERE, 'evidence'); os.makedirs(EV, exist_ok=True)
def setdark(v):
    mysql("update user_settings set user_config_theme_dark=%d where user_id in (9,14)" % v)
p = sync_playwright().start(); rows = []
for dark in (0, 1):
    setdark(dark)
    for (w, h) in ((1920, 1080), (1366, 768)):
        for who, items in (('admin', [('/agent/tickets.php', 'Save current view', 'savedview'), ('/agent/contracts.php', 'New Contract', 'newcontract'),
                                      ('/agent/clients.php', None, 'contactmodal'), ('/admin/project_template_details.php?project_template_id=1', None, 'projtemplate')]),
                           ('portal2', [('/client/allowance.php', None, 'allowance'), ('/client/assets.php', None, 'assets')])):
            b = p.chromium.launch(); ctx = b.new_context(viewport={'width': w, 'height': h}); pg = ctx.new_page()
            e, pw = USERS[who]; pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw); pg.locator('button[type=submit]').first.click()
            try: pg.wait_for_url(lambda u: 'login' not in u, timeout=15000)
            except Exception: pass
            errs = []; pg.on('pageerror', lambda x: errs.append(str(x)[:100])); pg.on('console', lambda m: errs.append(m.text[:100]) if m.type == 'error' else None)
            for url, label, tag in items:
                pg.goto(BASE + url); time.sleep(.9)
                info = {'who': who, 'url': url, 'tag': tag, 'vp': f'{w}x{h}', 'theme': 'dark' if dark else 'light'}
                if label or tag == 'contactmodal':
                    sel = ('.btn-add-view' if tag == 'savedview' else f'.ajax-modal:has-text("{label}")') if label else '.ajax-modal[data-modal-url*="contact_details"]'
                    try:
                        pg.locator(sel + ':visible').first.click(timeout=3000); pg.wait_for_selector('.modal.show .modal-content', timeout=6000); time.sleep(.9)
                        info['modal'] = pg.evaluate("""() => { const d = document.querySelector('.modal.show .modal-dialog'); const r = d.getBoundingClientRect(); const bd = d.querySelector('.modal-body');
                          return {scrollable: d.classList.contains('modal-dialog-scrollable'), top: Math.round(r.top), bottom: Math.round(r.bottom), vh: innerHeight, bodyScrolls: bd ? bd.scrollHeight > bd.clientHeight + 1 : null, footerVisible: (() => { const f = d.querySelector('.modal-footer'); if (!f) return null; const fr = f.getBoundingClientRect(); return fr.bottom <= innerHeight + 1 })() } }""")
                    except Exception as ex:
                        info['modal'] = 'ERR ' + str(ex)[:80]
                name = f"{tag}_{w}_{'dark' if dark else 'light'}.png"
                if w == 1366 or dark == 0 or True: pg.screenshot(path=os.path.join(EV, name))
                info['hscroll'] = pg.evaluate("() => document.documentElement.scrollWidth - innerWidth"); info['errors'] = list(dict.fromkeys(errs))[:3]; rows.append(info); errs.clear()
                try: pg.keyboard.press('Escape')
                except Exception: pass
            b.close()
setdark(0)
json.dump(rows, open(os.path.join(OUT, 'ui_check.json'), 'w'), indent=1)
for r in rows: print(r['theme'], r['vp'], r['tag'].ljust(14), 'hscroll', r['hscroll'], r.get('modal') if r.get('modal') else '', r['errors'])
p.stop()
