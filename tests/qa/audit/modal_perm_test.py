#!/usr/bin/env python3
"""Item 4: modal_permissions map vs removed quote/invoice/product modals; unmapped files default. Scratch app copy only."""
import sys, os, json
sys.path.insert(0, os.path.dirname(__file__))
from common import *
APPDIR = APP
sql("insert ignore into user_roles (role_name,role_description,role_is_admin) values ('QA-UI-SalesOnly','qa',0)")
rid = sqlv("select role_id from user_roles where role_name='QA-UI-SalesOnly'")
sql(f"delete from user_role_permissions where user_role_id={rid}") if False else None
mods = sql("select module_id, module_name from modules where module_name like 'module_%'")
sales = [m.split('\t')[0] for m in mods if m.split('\t')[1] == 'module_sales']
if sales:
    sql(f"insert ignore into user_role_permissions select {rid}, {sales[0]}, 3")
if not sqlv("select user_id from users where user_email='qa.sales@summitridge.example'"):
    sql(f"insert into users (user_name,user_email,user_password,user_status,user_role_id,user_type,user_specific_encryption_ciphertext,user_extension_key) select 'QA-UI- Sales only','qa.sales@summitridge.example',user_password,1,{rid},1,user_specific_encryption_ciphertext,user_extension_key from users where user_id=10")
    sql("insert into user_settings (user_id) select user_id from users where user_email='qa.sales@summitridge.example'")
print('modules:', [m.split('\t')[1] for m in mods][:12])
# an unmapped, newly added modal file, in the SCRATCH APP COPY only
d = os.path.join(APPDIR, 'agent/modals/zz_qaunmapped'); os.makedirs(d, exist_ok=True)
open(os.path.join(d, 'qa_unmapped.php'), 'w').write("<?php\nrequire_once '../../../includes/modal_header.php';\necho 'UNMAPPED-MODAL-BODY';\n")
users = {'admin': ADMIN, 'tech': TECH, 'sales-only': ('qa.sales@summitridge.example', 'DemoPass#2026'), 'portal': ('sophie.tran@summitridge.example', 'DemoPass#2026')}
paths = ['/agent/modals/quote/quote_add.php', '/agent/modals/invoice/invoice_add.php', '/agent/modals/recurring_invoice/recurring_invoice_add.php', '/agent/modals/product/product_add.php',
         '/agent/modals/ticket/ticket_quote_add.php', '/agent/modals/ticket/ticket_invoice_add.php', '/agent/modals/zz_qaunmapped/qa_unmapped.php', '/agent/modals/price_quote/price_quote_add.php?client_id=16', '/agent/modals/../../includes/config.php']
for who, cred in users.items():
    s = login(cred)
    for p in paths:
        r = s.get(BASE + p, headers={'X-Requested-With': 'XMLHttpRequest'}, allow_redirects=False)
        body = r.text[:90].replace('\n', ' ')
        print(f'{who:11} {r.status_code} {p:70} {body}')
os.remove(os.path.join(d, 'qa_unmapped.php')); os.rmdir(d)
