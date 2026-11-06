#!/usr/bin/env python3
"""Full-page shots + interaction text for the new screens: Mesh states, Panel Deploy (token alert, revoke confirmation)."""
import sys, os, re, json, glob, time
PY = glob.glob('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/*/scratchpad/pylibs')[0]
sys.path.insert(0, PY); sys.path.insert(1, '/home/sysadmin/.local/lib/python3.12/site-packages'); sys.path.insert(2, os.path.dirname(__file__))
from playwright.sync_api import sync_playwright
from common import *
EV = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'evidence')
fx = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'out/fixtures.json')))
sql("update user_settings set user_config_theme_dark=2 where user_id=16")
with sync_playwright() as p:
    b = p.chromium.launch()
    ctx = b.new_context(viewport={'width': 1280, 'height': 800}, ignore_https_errors=True); ctx.add_init_script("window.__dlg=[];['alert','confirm','prompt'].forEach(function(k){window[k]=function(){window.__dlg.push(k);return k==='confirm'?false:null};});")
    pg = ctx.new_page(); pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', 'qa.admin2@summitridge.example'); pg.fill('input[name=password]', ADMIN[1]); pg.locator('button[type=submit]').first.click(); pg.wait_for_url(lambda u: 'login' not in u)
    # Panel deploy: mint token via UI, screenshot the one-time alert
    pg.goto(BASE + '/agent/panel_deploy.php?client_id=16', wait_until='networkidle')
    F='form[action="post/panel_deploy.php"]'; pg.select_option(F+' select[name=contact_id]', index=1); pg.fill(F+' input[name=label]', 'QA-UI- shot token'); pg.click(F+' button[name=create_token]'); pg.wait_for_load_state('networkidle'); time.sleep(.4)
    pg.screenshot(path=os.path.join(EV, 'panel_token_full.jpg'), type='jpeg', quality=50, full_page=True)
    alert = pg.evaluate("(()=>{const e=document.querySelector('.alert-success code'); return e?{len:e.textContent.length,hasCopy:!!document.querySelector('.alert-success button,.alert-success [data-clipboard],.alert-success .copy')}:null})()"); print('token alert:', alert)
    pg.reload(wait_until='networkidle'); print('token shown again after reload:', pg.locator('.alert-success code').count() > 0)
    # revoke confirmation text
    rv = pg.locator('a.confirm-link:has-text("Revoke")').first
    if rv.count():
        rv.click(); time.sleep(.6); txt = pg.evaluate("(()=>{const m=document.querySelector('#confirmationModal .modal-body'); return m?m.textContent.trim().replace(/\\s+/g,' '):null})()"); print('revoke confirm text:', repr(txt), '| dialogs:', pg.evaluate('window.__dlg'))
        pg.screenshot(path=os.path.join(EV, 'panel_revoke_confirm.jpg'), type='jpeg', quality=50)
    print('times on page:', pg.evaluate("[...document.querySelectorAll('table td')].map(e=>e.textContent.trim()).filter(t=>/^\\d{4}-\\d\\d-\\d\\d/.test(t)).slice(0,4)"))
    b.close()
