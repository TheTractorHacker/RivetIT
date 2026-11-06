"""Passkey enroll + sign-in + delete using a Chromium CDP virtual authenticator. usage: passkey.py <it|msp>
Uses http://localhost:<port> because WebAuthn rpId cannot be an IP address."""
import sys, json
import common
from playwright.sync_api import sync_playwright
ed = sys.argv[1]
base, em = {'it': ('http://localhost:8080', 'alex.morgan@summitridge.example'), 'msp': ('http://localhost:8081', 'alex.morgan@northwind-it.example')}[ed]
r = {}; errs = []
with sync_playwright() as pw:
    b = pw.chromium.launch(); ctx = b.new_context(); pg = ctx.new_page()
    pg.on('console', lambda m: errs.append(m.text[:140]) if m.type == 'error' else None); pg.on('pageerror', lambda e: errs.append('EXC ' + str(e)[:120]))
    cdp = ctx.new_cdp_session(pg); cdp.send('WebAuthn.enable')
    auth = cdp.send('WebAuthn.addVirtualAuthenticator', {'options': {'protocol': 'ctap2', 'transport': 'internal', 'hasResidentKey': True, 'hasUserVerification': True, 'isUserVerified': True, 'automaticPresenceSimulation': True}})['authenticatorId']
    dialogs = []; pg.on('dialog', lambda d: (dialogs.append(d.message[:60]), d.accept('QA virtual key')))
    pg.goto(base + '/login.php'); pg.fill('input[name=email]', em); pg.fill('input[name=password]', 'DemoAdmin#2026'); pg.locator('button[type=submit]').first.click(); pg.wait_for_load_state('domcontentloaded')
    pg.goto(base + '/agent/user/user_security.php', wait_until='domcontentloaded')
    pg.get_by_text('Add Passkey').first.click(); pg.wait_for_timeout(1500)
    inp = pg.locator('.modal.show input:not([type=hidden]):not([type=checkbox])')
    r['name_prompt'] = inp.count() > 0
    if inp.count(): inp.first.fill('QA virtual key'); pg.locator('.modal.show button:has-text("Register")').first.click(); pg.wait_for_timeout(2500)
    pg.wait_for_timeout(1500); pg.goto(base + '/agent/user/user_security.php', wait_until='domcontentloaded')
    r['dialogs'] = dialogs
    r['credentials_in_authenticator'] = len(cdp.send('WebAuthn.getCredentials', {'authenticatorId': auth})['credentials'])
    r['listed_in_ui'] = 'No passkeys yet' not in pg.inner_text('body')
    # logout, then sign in with the passkey
    pg.goto(base + '/login.php?logout', wait_until='domcontentloaded'); pg.goto(base + '/agent/post.php?logout', wait_until='domcontentloaded') if False else None
    ctx.clear_cookies(); pg.goto(base + '/login.php', wait_until='domcontentloaded')
    btn = pg.locator('button:has-text("passkey"), a:has-text("passkey"), button:has-text("Passkey")')
    r['passkey_button_on_login'] = btn.count()
    if btn.count():
        btn.first.click(); pg.wait_for_timeout(3500); r['after_passkey_login'] = pg.url[len(base):]
    r['signed_in_with_passkey'] = '/agent/' in pg.url
    if r['signed_in_with_passkey']:
        pg.goto(base + '/agent/user/user_security.php', wait_until='domcontentloaded')
        r['last_used_set'] = bool(pg.evaluate("[...document.querySelectorAll('table tr')].some(tr=>/QA virtual/.test(tr.innerText)&&/20\\d\\d-/.test(tr.innerText.replace(/ADDED[\\s\\S]*/,'')+tr.innerText))"))
        row = pg.locator('tr', has_text='QA virtual key').first
        dl = row.locator('.js-delete-passkey'); r['delete_button_accessible_name'] = (dl.first.get_attribute('aria-label') or dl.first.get_attribute('title') or dl.first.inner_text().strip()) if dl.count() else None
        r['delete_control'] = dl.count()
        if dl.count():
            dl.first.click(); pg.wait_for_timeout(800)
            c = pg.locator('.modal.show :text-is("Yes"), .modal.show .btn-danger')
            if c.count(): c.first.click()
            pg.wait_for_load_state('domcontentloaded'); pg.wait_for_timeout(800); pg.goto(base + '/agent/user/user_security.php', wait_until='domcontentloaded')
            r['deleted'] = 'QA virtual key' not in pg.inner_text('body')
    r['errs'] = errs[:6]; b.close()
print(json.dumps(r, indent=1))
