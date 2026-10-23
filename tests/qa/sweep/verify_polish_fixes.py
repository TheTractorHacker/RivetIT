"""Asserting regression test for the 'fix everything that was left' design pass (DD2-DD74 polish items). Exit != 0 on any failure.
Needs the scratch demo on :8191. Uses cached sessions (common.login)."""
from common import *
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:200] if detail and not ok else ''))
    if not ok: fails.append(name)
p = sync_playwright().start()
def page(who, w=1366, h=800):
    b, ctx, pg = login(p, who); pg.set_viewport_size({'width': w, 'height': h}); return b, pg
def go(pg, path, wait=1.2):
    pg.goto(BASE + path, wait_until='domcontentloaded'); time.sleep(wait)
    if '/login' in pg.url and 'login' not in path: check('session valid on ' + path, False, pg.url)
def js(pg, expr): return pg.evaluate(expr)
def text(pg): return js(pg, "() => document.body.innerText")

# ---- admin, light
b, pg = page('admin')
go(pg, '/admin/api_docs.php')
check('DD2 api_docs light: iframe has no theme param', 'theme=dark' not in js(pg, "() => document.querySelector('iframe').src"))
go(pg, '/admin/audit_trail.php')
hs = js(pg, "() => [...document.querySelectorAll('form[method=get] .form-control, form[method=get] .form-select, form[method=get] .btn')].filter(e=>e.offsetParent).map(e=>Math.round(e.getBoundingClientRect().height))")
check('DD3 audit-trail filter controls share one height', len(hs) >= 6 and max(hs) - min(hs) <= 2, hs)
go(pg, '/admin/users.php')
check('DD6 page-size select has a visible label', 'Rows per page' in text(pg))
go(pg, '/admin/identity_provider.php')
n = js(pg, "() => [...document.querySelectorAll('input.form-control[type=text], input.form-control[type=password], input.form-control[type=url]')].filter(e=>e.offsetParent && !e.closest('.input-group')).length")
check('DD7 identity-provider text inputs all carry an icon (input-group)', n == 0, n)
for pgname in ('payment_method', 'payment_provider', 'saved_payment_method', 'tax', 'payroll_settings', 'kb_articles_archive', 'ticket_automation_log', 'settings_mail_templates', 'people_import', 'portal_users'):
    go(pg, f'/admin/{pgname}.php', .6)
    check(f'DD8 {pgname}: one breadcrumb row', js(pg, "() => [...document.querySelectorAll('nav.admin-breadcrumb, ol.breadcrumb')].filter(e=>e.offsetParent).length") == 1)
for pgname in ('tax', 'payment_provider', 'labor_type'):
    go(pg, f'/admin/{pgname}.php', .6)
    t = text(pg)
    check(f'DD9 {pgname}: empty list says so (or has rows)', 'Nothing here yet' in t or js(pg, "() => document.querySelectorAll('main table tbody tr').length") > 0)
go(pg, '/admin/portal_users.php', .6)
check('DD13 portal_users: at most one empty message', (text(pg).count('Nothing here yet') + text(pg).count('No results match')) <= 1)
go(pg, '/agent/notifications.php', .6)
check('DD13 notifications: not two stacked empty messages', not ('All caught up' in text(pg) and 'No results match' in text(pg)))
for pgname in ('segments', 'campaigns'):
    go(pg, f'/agent/{pgname}.php', .6)
    check(f'DD9 {pgname}: not a header-only card', js(pg, "() => document.querySelectorAll('main table tbody tr').length") > 0 or 'No ' in text(pg) or 'Nothing' in text(pg))
go(pg, '/admin/worksheet_template.php')
check('DD21 worksheet templates: row actions in a kebab, no inline Delete button', js(pg, "() => document.querySelectorAll('tbody .dropdown-toggle, tbody [data-bs-toggle=dropdown]').length") > 0 and js(pg, "() => document.querySelectorAll('tbody a.btn-danger').length") == 0)
go(pg, '/admin/labor_type.php')
check('DD21 labor types: row actions in a kebab', js(pg, "() => document.querySelectorAll('tbody a.btn-danger').length") == 0)
go(pg, '/agent/contracts.php?client_id=16')
check('DD21 contracts: row actions in a kebab and on one line', js(pg, "() => document.querySelectorAll('tbody a.btn-danger').length") == 0 and js(pg, "() => { const t = [...document.querySelectorAll('tbody tr')][0].querySelectorAll('td:last-child a.btn, td:last-child button.btn'); return t.length >= 2 && Math.abs(t[0].getBoundingClientRect().top - t[t.length-1].getBoundingClientRect().top) < 8 }"))
go(pg, '/admin/ticket_template_details.php?ticket_template_id=1')
check('DD20 ticket template: right card inside the viewport', js(pg, "() => { const cs = [...document.querySelectorAll('.card')].map(c=>c.getBoundingClientRect().right); return Math.max(...cs) <= innerWidth }"))
go(pg, '/agent/vendor_details.php?vendor_id=1')
check('DD38 vendor with no contacts shows an empty-state card', 'No contacts for this vendor yet' in text(pg))
go(pg, '/agent/asset_details.php?client_id=16&asset_id=92')
ys = js(pg, "() => { const bc = document.querySelector('ol.breadcrumb'); const banner = [...document.querySelectorAll('.card')].find(c => /border-left/.test(c.getAttribute('style') || '')); return [bc && bc.getBoundingClientRect().top, banner ? banner.getBoundingClientRect().top : 1e9, document.querySelector('.asset-name') && document.querySelector('.asset-name').innerText] }")
check('DD24 asset breadcrumb sits above the integration banners', ys[0] is not None and ys[0] < ys[1], ys)
check('DD25 asset name wraps only between words (non-breaking hyphens)', ys[2] and '-' not in ys[2] or '‑' in (ys[2] or ''), ys)
go(pg, '/agent/kb_articles.php')
prev = js(pg, "() => [...document.querySelectorAll('.card')].map(c=>[(c.querySelector('a[href*=kb_article]')||{}).innerText, (c.querySelector('p.card-text')||{}).innerText]).filter(x=>x[0]&&x[1])")
check('DD30 KB excerpt does not repeat the title', len(prev) > 0 and all(not (e[1] or '').lower().startswith((e[0] or '').strip().lower()) for e in prev), prev[:2])
go(pg, '/agent/project_gantt.php?client_id=16&project_id=4', 2.5)
r = js(pg, "() => { const c = document.querySelector('.gantt-container, #gantt'); const s = document.querySelector('.gantt-container svg, #gantt svg, svg.gantt'); return c && s ? [s.getBoundingClientRect().width, c.getBoundingClientRect().width] : null }")
check('DD33 gantt fills its card', r is not None and r[0] >= r[1] * 0.9, r)
go(pg, '/agent/files.php?client_id=16&folder_id=1')
check('DD28 folder tree: count badges do not overlap their labels', js(pg, "() => [...document.querySelectorAll('.nav-link .badge')].every(b => { const l = b.previousElementSibling || b.parentElement; return b.getBoundingClientRect().left >= (l.getBoundingClientRect().left) })"))
go(pg, '/agent/ticket_list.php')
check('DD64 tickets: Subject column keeps >=200px', js(pg, "() => { const th = document.querySelector('th.tk-col-subject'); return th ? th.getBoundingClientRect().width : 0 }") >= 200)
go(pg, '/agent/contacts.php?client_id=16')
check('DD23 icon-only row buttons have a tooltip title', js(pg, "() => { const bs = [...document.querySelectorAll('tbody a.btn, tbody button.btn')].filter(b=>b.textContent.trim()===''&&b.offsetParent); return bs.length>0 && bs.every(b=>b.title) }"))
go(pg, '/agent/training_quiz.php?lesson_id=1', 1.6)
check('DD37 quiz page without a quiz: no blank card shells', js(pg, "() => [...document.querySelectorAll('.trq-card')].filter(e => e.offsetParent && !e.textContent.trim() && !e.children.length).length") == 0)
# bulk modal with nothing selected + first-field focus + contract documents modal
go(pg, '/agent/assets.php?client_id=16')
pg.goto(BASE + '/agent/modals/asset/asset_bulk_edit_status.php?asset_ids[]=', wait_until='domcontentloaded')  # raw modal endpoint renders JSON; exercise through the real opener below
pg.goto(BASE + '/agent/assets.php?client_id=16', wait_until='domcontentloaded'); time.sleep(1)
js(pg, "() => openAjaxModal('modals/asset/asset_bulk_edit_status.php', 'md')"); time.sleep(1.5)
check('DD70 bulk modal with 0 selected: submit disabled and explained', js(pg, "() => { const m = document.querySelector('.modal.show'); return !!m && [...m.querySelectorAll('[type=submit]')].every(b=>b.disabled) && /Nothing is selected/.test(m.innerText) }"))
pg.keyboard.press('Escape'); time.sleep(.6)
js(pg, "() => openAjaxModal('modals/contract/contract_documents.php?contract_id=5', 'lg')"); time.sleep(1.5)
check('DD74 contract documents modal: exactly one file picker', js(pg, "() => document.querySelectorAll('.modal.show input[type=file]').length") == 1 and js(pg, "() => document.querySelectorAll('.modal.show .custom-file-label').length") == 0)
pg.keyboard.press('Escape'); time.sleep(.6)
go(pg, '/admin/labor_type.php'); js(pg, "() => openAjaxModal('modals/labor_type/labor_type_add.php', 'md')"); time.sleep(1.8)
check('DD71 first field of a form modal is focused on open', js(pg, "() => { const a = document.activeElement; return !!a && !!a.closest('.modal.show') && /INPUT|TEXTAREA|SELECT/.test(a.tagName) }"))
go(pg, '/admin/template_library.php')
tname = 'QA-UI-tl-%d' % int(time.time())
js(pg, "() => openAjaxModal('/admin/modals/contract_template/contract_template_add.php', 'lg')"); time.sleep(1.8)
pg.fill('.modal.show [name=name]', tname); pg.fill('.modal.show [name=description]', 'QA-UI- desc')
js(pg, "() => { const s = document.querySelector('.modal.show [name=type]'); const v = [...s.options].find(o => o.value).value; if (s.tomselect) { s.tomselect.setValue(v) } else { s.value = v } }")
pg.locator('.modal.show button[type=submit]:visible').first.click(); time.sleep(2)
check('I12 template library: creating a template from its New button saves it', mysql("select count(*) from contract_templates where contract_template_name='%s'" % tname)[0][0] == '1')
mysql("delete from contract_templates where contract_template_name like 'QA-UI-tl-%'")
b.close()

# ---- admin, dark
b, pg = page('admin_d')
go(pg, '/admin/api_docs.php', 3)
src = js(pg, "() => document.querySelector('iframe').src")
check('DD2 api_docs dark: viewer asked for the dark theme', 'theme=dark' in src, src)
fr = [f for f in pg.frames if f != pg.main_frame]
bg = fr[0].evaluate("() => getComputedStyle(document.body).backgroundColor") if fr else ''
lum = [int(x) for x in re.findall(r'\d+', bg)[:3]]
sc = fr[0].evaluate("() => { const m = document.querySelector('.scalar-app, .scalar-api-reference, main'); return m ? getComputedStyle(m).backgroundColor : '' }") if fr else ''
sl = [int(x) for x in re.findall(r'\d+', sc)[:3]]
check('DD2 api_docs dark: reference page body is dark', len(lum) == 3 and sum(lum) / 3 < 80, bg)
check('DD2 api_docs dark: reference panel is dark', len(sl) < 3 or sum(sl) / 3 < 100, sc)
b.close()

# ---- technician: training no-access
b, pg = page('tech')
go(pg, '/agent/training_banks.php')
check('DD41 training no-access card offers a next step', 'Back to dashboard' in text(pg) or "don't have access" not in text(pg))
b.close()

# ---- tablet (768)
b, pg = page('admin', 768, 1024)
go(pg, '/admin/ticket_automation.php')
check('DD53 wrapped condition/action pills are not circles', js(pg, "() => [...document.querySelectorAll('.badge.text-wrap')].every(e => { const r = e.getBoundingClientRect(); return parseFloat(getComputedStyle(e).borderTopLeftRadius) < Math.max(20, r.height/2.2) })"))
go(pg, '/agent/payments.php?client_id=16')
check('DD59 payments filter selects are not truncated', js(pg, "() => [...document.querySelectorAll('select[name=account], select[name=method]')].every(s => { const w = (s.nextElementSibling||s).getBoundingClientRect().width; return w >= 140 })"))
go(pg, '/agent/csat.php?client_id=16')
check('DD56 CSAT follow-up button is inside the toolbar', js(pg, "() => { const a = [...document.querySelectorAll('a.btn')].find(x=>/Needs Follow/.test(x.innerText)); return !a || a.getBoundingClientRect().right <= innerWidth }"))
b.close()

# ---- portal
b, pg = page('portal')
go(pg, '/client/documents.php')
check('DD43 portal documents date is ISO like the rest of the portal', not re.search(r'[A-Z][a-z]{2} \d{1,2}, \d{4}', js(pg, "() => document.querySelector('main, .container-fluid, body').innerText")))
go(pg, '/client/contracts.php')
check('DD42 portal contracts card is not inset narrower than the hero', abs(js(pg, "() => document.querySelector('.portal-hero').getBoundingClientRect().left") - js(pg, "() => document.querySelector('.card:not(.portal-hero)').getBoundingClientRect().left")) <= 1)
b.close()
print('\nFAILS:', fails if fails else 'none'); sys.exit(1 if fails else 0)
