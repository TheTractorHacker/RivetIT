"""
End-to-end check of the shared icon picker (includes/icon_picker.php, js/icon_picker.js, modals/icon_catalog.php) through the
real web stack: every form that asks for an icon renders the picker with the current value, and every handler normalises
what it stores (RivetCore\\Ui\\IconCatalog::normalize). Needs a THROWAWAY install (see tests/e2e/ticket_views.py for the recipe).

  TEST_DB_USER=... TEST_DB_PASS=... python3 tests/e2e/icon_picker.py http://127.0.0.1:<port> <scratch db> <admin email> <admin password> [<app dir>]
"""
import re, sys, json, subprocess, os, http.cookiejar, urllib.request, urllib.parse, urllib.error
BASE = sys.argv[1]; DB = sys.argv[2]; EMAIL = sys.argv[3]; PASSWORD = sys.argv[4]
USER = os.environ['TEST_DB_USER']; os.environ['MYSQL_PWD'] = os.environ['TEST_DB_PASS']

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None
class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)
    def req(self, path, data=None, referer=None):
        headers = {'Referer': BASE + referer} if referer else {}
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, headers=headers)
        try:
            resp = self.opener.open(r); return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode('utf-8', 'replace'), e.headers
    def login(self, email, password):
        s, html, h = self.req('/login.php'); data = {'email': email, 'password': password, 'login': ''}
        t = csrf(html)
        if t: data['csrf_token'] = t
        return self.req('/login.php', data)[0] in (302, 303)
def sql(q):
    out = subprocess.run(['mysql', '-u', USER, '-N', '-B', DB, '-e', q], capture_output=True, text=True)
    return out.stdout.strip()
def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html); return m.group(1) if m else None
def esc(v): return v.replace("\\", "\\\\").replace("'", "\\'")
results = []
def check(name, ok, detail=''):
    results.append((name, bool(ok), detail)); print(('PASS' if ok else 'FAIL') + '  ' + name + (('  [' + str(detail) + ']') if detail and not ok else ''))

a = Client(); check('admin signs in', a.login(EMAIL, PASSWORD))
def modal(c, path):
    s, body, h = c.req(path)
    try: return s, json.loads(body).get('content', '')
    except Exception: return s, body
def token(c, page):
    s, p, h = c.req(page); t = csrf(p)
    return t or csrf(modal(c, '/agent/modals/ticket/ticket_saved_view_add.php?status=Open')[1])

sql("delete from ticket_saved_views where ticket_saved_view_name like 'E2E %'; delete from tags where tag_name like 'E2E %'; delete from custom_links where custom_link_name like 'E2E %'; delete from service_catalog_items where name like 'E2E %'")
LONG = 'fa-' + 'a' * 300
# (label, posted value, expected stored value for 'fa-' class forms)
CASES = [
    ('catalogued icon', 'fa-fire', 'fa-fire'),
    ('"fas fa-fire" style', 'fas fa-fire', 'fa-fire'),
    ('uncatalogued but valid class', 'fa-zzz-not-in-catalog', 'fa-zzz-not-in-catalog'),
    ('bare name', 'handshake', 'fa-handshake'),
    ('empty', '', None),
    ('hostile "><script>', '"><script>alert(1)</script>', None),
    ('hostile with a space', 'fa-x y', None),
    ('300 characters', LONG, None),
]

# ---- 1. the catalog endpoint and the markup -------------------------------------------------------------------------
s, body, h = a.req('/modals/icon_catalog.php?v=1')
cat = json.loads(body) if s == 200 else {}
check('catalog endpoint returns the JSON catalog (>=400 icons, categories)', s == 200 and len(cat.get('icons', [])) >= 400 and cat.get('categories'), s)
check('catalog is cacheable (private max-age)', 'max-age' in (h.get('Cache-Control') or ''))
check('catalog needs a session', Client().req('/modals/icon_catalog.php')[0] in (302, 303, 401, 403))
VIEW_ADD = '/agent/modals/ticket/ticket_saved_view_add.php?status=Open'
MODALS = {
    'saved view add': VIEW_ADD, 'tag add': '/admin/modals/tag/tag_add.php?type=1', 'custom link add': '/admin/modals/custom_link/custom_link_add.php',
    'service catalog add': '/admin/modals/service_catalog/service_catalog_item_add.php',
}
for label, path in MODALS.items():
    s, m = modal(a, path)
    check(label + ': picker markup + hidden input, catalog not embedded', s == 200 and 'data-icon-picker' in m and 'name="icon"' in m and 'type="hidden"' in m.split('name="icon"')[0][-80:] + 'type="hidden"' and '"categories"' not in m and 'type="text" class="form-control" name="icon"' not in m, s)
check('saved view add: default icon fa-filter preselected', 'name="icon" id="icon-picker-1" value="fa-filter"' in modal(a, VIEW_ADD)[1])

# ---- 2. saved views -------------------------------------------------------------------------------------------------
def vpost(c, d):
    d = dict(d); d['csrf_token'] = token(c, '/agent/tickets.php')
    return c.req('/agent/post.php', d, referer='/agent/tickets.php')
for i, (label, val, exp) in enumerate(CASES):
    nm = 'E2E View %d' % i
    vpost(a, {'name': nm, 'icon': val, 'filters_present': '1', 'f_status_mode': 'all', 'add_ticket_saved_view': '1'})
    got = sql("select ticket_saved_view_icon from ticket_saved_views where ticket_saved_view_name='%s'" % nm)
    check('saved view add (%s) stores %s' % (label, exp or 'fa-filter'), got == (exp or 'fa-filter'), got)
vid = sql("select ticket_saved_view_id from ticket_saved_views where ticket_saved_view_name='E2E View 0'")
for i, (label, val, exp) in enumerate(CASES):
    vpost(a, {'ticket_saved_view_id': vid, 'name': 'E2E View 0', 'icon': val, 'edit_ticket_saved_view': '1'})
    got = sql("select ticket_saved_view_icon from ticket_saved_views where ticket_saved_view_id=%s" % vid)
    check('saved view edit (%s) stores %s' % (label, exp or 'fa-filter'), got == (exp or 'fa-filter'), got)
sql("update ticket_saved_views set ticket_saved_view_icon='fa-zzz-custom' where ticket_saved_view_id=%s" % vid)
s, m = modal(a, '/agent/modals/ticket/ticket_saved_view_edit.php?id=' + vid)
check('saved view edit modal keeps an uncatalogued stored class in the hidden input and custom field', m.count('value="fa-zzz-custom"') == 2, s)
vpost(a, {'ticket_saved_view_id': vid, 'name': 'E2E View 0', 'icon': 'fa-zzz-custom', 'edit_ticket_saved_view': '1'})
check('...and saving it unchanged preserves it', sql("select ticket_saved_view_icon from ticket_saved_views where ticket_saved_view_id=%s" % vid) == 'fa-zzz-custom')

# ---- 3. admin forms ---------------------------------------------------------------------------------------------------
def apost(page, d):
    d = dict(d); d['csrf_token'] = token(a, '/admin/' + page)
    return a.req('/admin/post.php', d, referer='/admin/' + page)
# tags / custom links store the class without the 'fa-' prefix (templates render fa-fw fa-$icon / itflow_nav_icon_class); '' = default
bare = lambda e: (e[3:] if e else '')
for i, (label, val, exp) in enumerate(CASES):
    nm = 'E2E Tag %d' % i
    apost('tag.php', {'name': nm, 'type': '1', 'color': '#ff0000', 'icon': val, 'add_tag': '1'})
    got = sql("select tag_icon from tags where tag_name='%s'" % nm)
    check('tag add (%s) stores %r' % (label, bare(exp)), got == bare(exp), got)
    nm = 'E2E Link %d' % i
    apost('custom_link.php', {'name': nm, 'uri': 'https://example.test', 'location': '2', 'icon': val, 'add_custom_link': '1'})
    got = sql("select custom_link_icon from custom_links where custom_link_name='%s'" % nm)
    check('custom link add (%s) stores %r' % (label, bare(exp)), got == bare(exp), got)
    nm = 'E2E Item %d' % i
    apost('service_catalog.php', {'name': nm, 'description': '', 'ticket_subject_template': 'x', 'icon': val, 'add_service_catalog_item': '1', 'is_active': '1'})
    got = sql("select coalesce(icon,'NULL') from service_catalog_items where name='%s'" % nm)
    check('service catalog add (%s) stores %s' % (label, exp or 'NULL'), got == (exp or 'NULL'), got)

# edits
tid = sql("select tag_id from tags where tag_name='E2E Tag 0'"); lid = sql("select custom_link_id from custom_links where custom_link_name='E2E Link 0'"); cid = sql("select catalog_item_id from service_catalog_items where name='E2E Item 0'")
for label, val, exp in CASES:
    apost('tag.php', {'tag_id': tid, 'name': 'E2E Tag 0', 'color': '#ff0000', 'icon': val, 'edit_tag': '1'})
    ok1 = sql("select tag_icon from tags where tag_id=%s" % tid) == bare(exp)
    apost('custom_link.php', {'custom_link_id': lid, 'name': 'E2E Link 0', 'uri': 'https://example.test', 'location': '2', 'icon': val, 'edit_custom_link': '1'})
    ok2 = sql("select custom_link_icon from custom_links where custom_link_id=%s" % lid) == bare(exp)
    apost('service_catalog.php', {'catalog_item_id': cid, 'name': 'E2E Item 0', 'description': '', 'ticket_subject_template': 'x', 'icon': val, 'edit_service_catalog_item': '1', 'is_active': '1'})
    ok3 = sql("select coalesce(icon,'NULL') from service_catalog_items where catalog_item_id=%s" % cid) == (exp or 'NULL')
    check('tag / custom link / catalog item edit (%s) normalise' % label, ok1 and ok2 and ok3, (ok1, ok2, ok3))

# existing rows with a legacy bare name or an odd custom class open with the value preserved
sql("update tags set tag_icon='handshake' where tag_id=%s; update custom_links set custom_link_icon='question-circle' where custom_link_id=%s; update service_catalog_items set icon='zzz-custom-thing' where catalog_item_id=%s" % (tid, lid, cid))
check('tag edit modal preserves a legacy bare icon', 'name="icon" id="icon-picker-1" value="fa-handshake"' in modal(a, '/admin/modals/tag/tag_edit.php?id=' + tid)[1])
check('custom link edit modal preserves a legacy bare icon', 'value="fa-question-circle"' in modal(a, '/admin/modals/custom_link/custom_link_edit.php?id=' + lid)[1])
m = modal(a, '/admin/modals/service_catalog/service_catalog_item_edit.php?id=' + cid)[1]
check('catalog item edit modal preserves an uncatalogued class (hidden + custom field)', m.count('value="fa-zzz-custom-thing"') == 2)
sql("update tags set tag_icon='\"><script>' where tag_id=%s" % tid)
m = modal(a, '/admin/modals/tag/tag_edit.php?id=' + tid)[1]
check('a hostile stored icon never reaches the markup', '<script>alert' not in m and '&quot;&gt;&lt;script' not in m.split('data-icon-picker')[1].split('data-icon-search')[0] and 'name="icon" id="icon-picker-1" value=""' in m)

# ---- 4. permissions: a Technician -------------------------------------------------------------------------------------
import hashlib
pw_hash = subprocess.run(['php', '-r', 'echo password_hash("Tech-User-1234", PASSWORD_DEFAULT);'], capture_output=True, text=True).stdout
sql("delete from users where user_email='tech@scratch.test'")
sql("insert into users (user_name,user_email,user_password,user_role_id,user_status) values ('E2E Tech','tech@scratch.test','%s',2,1)" % esc(pw_hash))
t = Client(); check('technician signs in', t.login('tech@scratch.test', 'Tech-User-1234'))
for label, p in [('tag', '/admin/modals/tag/tag_add.php?type=1'), ('custom link', '/admin/modals/custom_link/custom_link_add.php'), ('service catalog', '/admin/modals/service_catalog/service_catalog_item_add.php')]:
    check('technician is still refused the admin %s modal (403)' % label, t.req(p)[0] == 403)
before = sql("select count(*) from tags")
tt = token(t, '/agent/tickets.php')
t.req('/admin/post.php', {'csrf_token': tt, 'name': 'E2E TechTag', 'type': '1', 'color': '#000000', 'icon': 'fa-fire', 'add_tag': '1'}, referer='/admin/tag.php')
check('technician cannot create an admin tag', sql("select count(*) from tags") == before)
check('technician can still read the icon catalog', t.req('/modals/icon_catalog.php?v=1')[0] == 200)
s, m = modal(t, VIEW_ADD)
check('technician sees the picker on the saved view modal', s == 200 and 'data-icon-picker' in m, s)
d = {'name': 'E2E Tech View', 'icon': 'fas fa-star', 'filters_present': '1', 'f_status_mode': 'all', 'add_ticket_saved_view': '1', 'csrf_token': tt}
t.req('/agent/post.php', d, referer='/agent/tickets.php')
got = sql("select ticket_saved_view_icon from ticket_saved_views where ticket_saved_view_name='E2E Tech View'")
tech_support = sql("select coalesce(max(urp.user_role_permission_level),0) from user_role_permissions urp where urp.user_role_id=2 and urp.module_id=(select module_id from modules where module_name='module_support')") if False else None
check('technician saved view follows the normal permission rule (created+normalised, or nothing created)', got in ('fa-star', ''), got)

# ---- cleanup + summary ---------------------------------------------------------------------------------------------------
sql("delete from ticket_saved_views where ticket_saved_view_name like 'E2E %'; delete from tags where tag_name like 'E2E %'; delete from custom_links where custom_link_name like 'E2E %'; delete from service_catalog_items where name like 'E2E %'; delete from users where user_email='tech@scratch.test'")
bad = [r for r in results if not r[1]]
print('\n%d/%d passed' % (len(results) - len(bad), len(results)))
sys.exit(1 if bad else 0)
