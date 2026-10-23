"""Typed whitespace into required text fields, then reads the DATABASE: nothing blank may be stored and no existing value may be blanked.
Replaces the matrix heuristic ('toast has no error word') for the fields it could not judge. Exit 0 = pass. Scratch demo only."""
from common import *
fails = []
def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + (' :: ' + str(detail)[:200] if detail and not ok else '')); 
    if not ok: fails.append(name)
def q(sql):
    r = mysql(sql); return r[0][0] if r and r[0] else ''
p = sync_playwright().start(); b, ctx, pg = login(p, 'admin'); pg.set_viewport_size({'width': 1366, 'height': 900})
def go(u): pg.goto(BASE + u, wait_until='domcontentloaded'); time.sleep(1.4)
def open_modal(page, url):
    go(page); pg.evaluate("(u) => openAjaxModal(u, 'lg')", url); pg.wait_for_selector('.modal.show form', timeout=8000); time.sleep(.8)
def fill_required(target, scope='.modal.show'):
    """fill every other required text/url/textarea with a valid value, the target with spaces"""
    for el in pg.query_selector_all(f'{scope} input[required], {scope} textarea[required]'):
        nm = el.get_attribute('name'); ty = el.get_attribute('type') or 'text'
        if ty in ('hidden', 'checkbox', 'radio', 'file') or not el.is_visible(): continue
        el.fill('   ' if nm == target else ('https://example.com' if ty == 'url' else 'QA-UI-ws-ok'))
    for s in pg.query_selector_all(f'{scope} select[required]'):
        pg.evaluate("(s) => { if (!s.value) { s.selectedIndex = 1; s.dispatchEvent(new Event('change')) } }", s)
def submit(scope='.modal.show'):
    pg.locator(f'{scope} button[type=submit]:visible').first.click(); time.sleep(2)

mysql("delete from custom_links where trim(custom_link_uri)='' and custom_link_name like 'QA-UI-%'")   # leftover from the earlier matrix run
# 1 custom link
open_modal('/admin/custom_link.php', 'modals/custom_link/custom_link_add.php'); fill_required('uri'); submit()
check('custom link: whitespace URL is not stored', q("select count(*) from custom_links where trim(custom_link_uri)=''") == '0')
# 2 ai provider add / edit
open_modal('/admin/ai_provider.php', 'modals/ai/ai_provider_add.php'); fill_required('provider'); submit()
check('AI provider add: whitespace name is not stored', q("select count(*) from ai_providers where trim(ai_provider_name)=''") == '0')
pid = q("select ai_provider_id from ai_providers order by ai_provider_id limit 1"); before = q(f"select ai_provider_name from ai_providers where ai_provider_id={pid}")
open_modal('/admin/ai_provider.php', f'modals/ai/ai_provider_edit.php?id={pid}'); pg.fill('.modal.show [name=provider]', '   '); submit()
check('AI provider edit: whitespace name does not blank the provider', q(f"select ai_provider_name from ai_providers where ai_provider_id={pid}") == before)
# 3 contract template description
open_modal('/admin/contract_template.php', 'modals/contract_template/contract_template_add.php'); fill_required('description'); submit()
check('contract template: whitespace description is not stored', q("select count(*) from contract_templates where trim(contract_template_description)=''") == '0')
# 4 client edit name
cname = q("select client_name from clients where client_id=16")
go('/agent/asset_details.php?client_id=16&asset_id=92'); pg.evaluate("() => openAjaxModal('modals/client/client_edit.php?id=16', 'lg')"); pg.wait_for_selector('.modal.show form', timeout=8000); time.sleep(.8)
pg.fill('.modal.show [name=name]', '   '); submit()
check('department edit: whitespace name does not blank the department', q("select client_name from clients where client_id=16") == cname)
mysql(f"update clients set client_name='{cname}' where client_id=16 and trim(client_name)=''")
# 5 company name, 6 project prefix (page forms)
comp = q("select company_name from companies where company_id=1")
go('/admin/settings_company.php'); pg.fill('form [name=name]', '   '); pg.locator('form button[name=edit_company]').first.click(); time.sleep(2)
check('company settings: whitespace name does not blank the company', q("select company_name from companies where company_id=1") == comp)
mysql(f"update companies set company_name='{comp}' where company_id=1 and trim(company_name)=''")
# (a blank project/ticket number prefix is a valid setting, so it is not asserted)
# 7 ticket template task
go('/admin/ticket_template_details.php?ticket_template_id=1'); pg.fill('form [name=task_name]', '   '); pg.locator('form button[name=add_ticket_template_task]').first.click(); time.sleep(2)
check('ticket template: whitespace task name is not stored', q("select count(*) from task_templates where trim(task_template_name)=''") == '0')
# service edit, onboarding checklist task, worksheet template name
sid = q("select service_id from services order by service_id limit 1"); sdesc = q(f"select service_description from services where service_id={sid}")
go('/agent/services.php?client_id=16'); pg.evaluate("(i) => openAjaxModal('modals/service/service_edit.php?id=' + i, 'lg')", sid); pg.wait_for_selector('.modal.show form', timeout=8000); time.sleep(.8)
pg.fill('.modal.show [name=description]', '   '); submit()
check('service edit: whitespace description does not blank the service', q(f"select service_description from services where service_id={sid}") == sdesc)
go('/admin/onboarding_template_details.php?project_template_id=1'); pg.fill('form [name=task_name]', '   '); pg.locator('form button[name=add_checklist_task]').first.click(); time.sleep(2)
check('onboarding template: whitespace task name is not stored', q("select count(*) from task_templates where trim(task_template_name)=''") == '0')
wid = q("select worksheet_template_id from worksheet_templates order by 1 limit 1"); wname = q(f"select worksheet_template_name from worksheet_templates where worksheet_template_id={wid}")
go('/admin/worksheet_template_details.php?id=%s' % wid); pg.fill('form [name=worksheet_template_name]', '   '); pg.locator('form button[name=edit_worksheet_template]').first.click(); time.sleep(2)
check('worksheet template: whitespace name does not blank the template', q(f"select worksheet_template_name from worksheet_templates where worksheet_template_id={wid}") == wname)
# 8 campaign name/subject
go('/agent/campaigns.php')
pg.fill('form:has([name=add_campaign]) [name=name]', 'QA-UI-ws-camp'); pg.fill('form:has([name=add_campaign]) [name=subject]', '   ')
pg.locator('form:has([name=add_campaign]) button[type=submit]:visible').first.click(); time.sleep(2)
check('campaign: whitespace subject / name is not stored blank', q("select count(*) from crm_campaigns where trim(campaign_name)='' or trim(campaign_subject)=''") == '0')
b.close(); print('\nFAILS:', fails or 'none'); sys.exit(1 if fails else 0)
