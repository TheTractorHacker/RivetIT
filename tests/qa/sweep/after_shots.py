"""Capture 'after' screenshots of the fixed pages into the scratch dir (before shots come from design_shoot.py output)."""
from common import *
ITEMS_ALL = [('admin', 'light', '1920x1080', '/admin/sla_policies.php'), ('admin', 'light', '1920x1080', '/admin/settings_module.php'),
         ('admin', 'dark', '1920x1080', '/admin/settings_module.php'), ('admin', 'light', '1920x1080', '/agent/document_details.php?client_id=16&document_id=1'),
         ('admin', 'light', '1920x1080', '/agent/racks.php?client_id=16'), ('admin', 'dark', '1920x1080', '/agent/ticket.php?client_id=16&ticket_id=248'),
         ('admin', 'light', '1920x1080', '/agent/tickets.php?client_id=16'), ('admin', 'light', '768x1024', '/agent/asset_details.php?client_id=16&asset_id=92'),
         ('admin', 'light', '768x1024', '/agent/training_records.php'), ('portal', 'light', '768x1024', '/client/training.php'),
         ('admin', 'light', '1920x1080', '/admin/contract_template.php'), ('admin', 'light', '1920x1080', '/admin/ticket_template_details.php?ticket_template_id=1'),
         ('admin', 'light', '768x1024', '/agent/vendor_details.php?client_id=16&vendor_id=1'), ('admin', 'light', '1366x768', '/agent/ticket_list.php')]
ITEMS = [i for i in ITEMS_ALL if (not os.environ.get('ONLY') or os.environ['ONLY'] in i[3])]
out = '/home/sysadmin/qa-design-scratch/after'; os.makedirs(out, exist_ok=True)
p = sync_playwright().start()
for role, theme, vp, url in ITEMS:
    who = {('admin', 'light'): 'admin', ('admin', 'dark'): 'admin_d', ('portal', 'light'): 'portal2'}[(role, theme)]
    W, H = map(int, vp.split('x')); b = p.chromium.launch(); ctx = b.new_context(viewport={'width': W, 'height': H}); pg = ctx.new_page()
    e, pw = USERS[who]; pg.goto(BASE + '/login.php'); pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw); pg.locator('button[type=submit]').first.click(); pg.wait_for_url(lambda u: 'login' not in u, timeout=20000); time.sleep(.8)
    pg.goto(BASE + url, wait_until='domcontentloaded'); time.sleep(2)
    slug = re.sub(r'[^a-z0-9]+', '_', url.lower()).strip('_')[:100]
    pg.screenshot(path=f'{out}/{role}_{theme}_{vp}_{slug}.jpg', full_page=True, type='jpeg', quality=65); b.close()
p.stop(); print(len(ITEMS), 'done')
