"""Shared helpers for the read-only broken-page sweep (local scratch demo on 127.0.0.1:8191 only)."""
import sys, os, json, time, re, glob, subprocess
PYLIBS = os.environ.get('QA_PYLIBS') or (glob.glob('/tmp/claude-1000/-var-www-mw-itflow-foleyit-com/*/scratchpad/pylibs') or [''])[0]
sys.path.insert(0, PYLIBS); sys.path.insert(1, '/home/sysadmin/.local/lib/python3.12/site-packages')
from playwright.sync_api import sync_playwright
BASE = os.environ.get('QA_BASE', 'http://127.0.0.1:8191')
APP = os.environ.get('QA_APP', '/home/sysadmin/qa-sweep-app')
DB = os.environ.get('QA_DB', 'qa_sweep_demo')
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.environ.get("QA_OUT", os.path.join(HERE, "out"))
SHOTS = os.path.join(OUT, 'shots')
os.makedirs(SHOTS, exist_ok=True)
USERS = {'admin': ('alex.morgan@summitridge.example', 'DemoAdmin#2026'),
         'tech': ('priya.nair@summitridge.example', 'DemoPass#2026'),
         'l2': ('qa.l2@summitridge.example', 'DemoPass#2026'),
         'l3': ('qa.l3@summitridge.example', 'DemoPass#2026'),
         'portal': ('sophie.tran@summitridge.example', 'DemoPass#2026'),
         'portal2': ('grace.okafor@summitridge.example', 'DemoPass#2026'),
         'admin3': ('qa.admin3@summitridge.example', 'DemoAdmin#2026'),
         'admin4': ('qa.admin4@summitridge.example', 'DemoAdmin#2026'),
         'admin5': ('qa.admin5@summitridge.example', 'DemoAdmin#2026'),
         'admin_d': ('qa.admin.dark@summitridge.example', 'DemoAdmin#2026'),
         'tech_d': ('qa.tech.dark@summitridge.example', 'DemoPass#2026')}
# Never loaded: POST handlers, includes, auth/OAuth flows, update/db-update runners, secret reveal, script/workflow runners.
SKIP = {
 'admin/post.php': 'POST handler (no GET view)', 'agent/post.php': 'POST handler', 'client/post.php': 'POST handler',
 'admin/update.php': 'runs git/update logic - update feature', 'admin/database_updates.php': 'DB migration include - runs updates when loaded',
 'client/functions.php': 'include file, not a page', 'admin/oauth_microsoft_mail_callback.php': 'OAuth callback (Email sync integration)',
 'agent/outlook_calendar_callback.php': 'OAuth callback (integration)', 'agent/outlook_calendar_connect.php': 'starts OAuth to Microsoft (integration)',
 'client/login_microsoft.php': 'SSO flow (integration)', 'client/login_odoo.php': 'Odoo login flow (Odoo)', 'client/login_oidc.php': 'SSO flow (integration)',
 'client/login_reset.php': 'password reset flow (sends email)', 'agent/credential_reveal.php': 'reveals secrets',
 'agent/workflow_run.php': 'executes a workflow', 'agent/rmm_script_run.php': 'runs an RMM script', 'admin/automation_test.php': 'fires an automation test',
 'admin/cron.php': 'cron page - cron features out of scope', 'admin/backup.php': 'backup feature (view-only page skipped to be safe)',
 'admin/backup_recovery_panel.php': 'backup feature', 'admin/sync_watch_panel.php': 'sync panel (integration)',
 'admin/mailbox.php': 'Email sync / mailbox integration', 'admin/mail_queue.php': 'mail queue - send side effects if retry GET used',
 'admin/mail_requests.php': 'mail requests - Email sync area', 'agent/mail_requests.php': 'mail requests - Email sync area',
 'admin/comet_status.php': 'Comet backup integration', 'admin/settings_calendar_sync.php': 'calendar sync integration',
 'admin/settings_comet.php': 'Comet integration', 'admin/settings_mail.php': 'mail integration settings',
 'admin/settings_manageengine_mdm.php': 'MDM integration', 'admin/settings_unifi.php': 'UniFi integration',
 'admin/settings_integrations.php': 'Integrations (live side effects)', 'admin/settings_mesh_wizard.php': 'MeshCentral wizard (live connection)',
 'agent/rmm_add_device.php': 'enrolment', 'admin/job_queue.php': 'job queue (cron/sync jobs)', 'admin/server_tasks.php': 'server tasks (update/backup/cron runners)',
}
AJAX = {  # endpoints without an HTML UI: probed with a bare GET and judged on 'no PHP error / no 5xx'
 'agent/ajax.php','agent/crm_ajax.php','agent/training_ajax.php','agent/ticket_attachment_upload.php','agent/kb_article_upload.php',
 'agent/training_upload.php','agent/training_evidence_upload.php','agent/sse_ticket_stream.php','client/sse_ticket_stream.php',
 'agent/calendar_feed.php','agent/kb_media.php','client/kb_media.php','agent/kb_article_attachment.php','agent/training_video_frame.php',
 'agent/rmm_software_report_csv.php','client/portal_theme.css.php','agent/training_pdf.php','agent/training_certificate.php','agent/rmm_job_output.php',
}
SSE = {'agent/sse_ticket_stream.php', 'client/sse_ticket_stream.php'}
DANGER = re.compile(r'delete|archive|remove|restore|send|sync|check|backup|run\b|reset|purge|revoke|disable|impersonat|logout|sign.?out|test|clear|import|install|update|upgrade|void|cancel|close|pay|approve|reject|kill|reboot|shutdown|wipe', re.I)
PHP_ERR = re.compile(r'(?:<b>)?(Fatal error|Parse error|Warning|Notice|Deprecated|Uncaught \w+|Stack trace)(?:</b>)?\s*[:#]|mysqli_sql_exception|Call to (?:undefined|a member)|Undefined (?:variable|array key|index|offset|property)', re.I)

def mysql(sql):
    r = subprocess.run(['/home/sysadmin/demos/mysqlroot.sh', DB, '-N', '-B', '-e', sql], capture_output=True, text=True)
    return [l.split('\t') for l in r.stdout.splitlines() if l.strip()]

def login(p, who):
    """Log in once per account and reuse the session (the app throttles to 10 logins per account per 5 minutes and keeps one session per user)."""
    b = p.chromium.launch()
    sf = f'/tmp/claude-1000/qa_state_{BASE.rsplit(":",1)[-1]}_{who}.json'
    e, pw = USERS[who]
    if os.path.exists(sf) and time.time() - os.path.getmtime(sf) < 1500 and not os.environ.get('QA_FRESH_LOGIN'):
        ctx = b.new_context(viewport={'width': 1920, 'height': 1080}, ignore_https_errors=True, storage_state=sf)
        pg = ctx.new_page(); pg.goto(BASE + ('/client/index.php' if who.startswith('portal') else '/agent/dashboard.php'), wait_until='domcontentloaded')
        if 'login' not in pg.url: return b, ctx, pg
        ctx.close()
    ctx = b.new_context(viewport={'width': 1920, 'height': 1080}, ignore_https_errors=True)
    pg = ctx.new_page()
    pg.goto(BASE + '/login.php')
    pg.fill('input[name=email]', e); pg.fill('input[name=password]', pw)
    pg.locator('button[type=submit]').first.click()
    try: pg.wait_for_url(lambda u: 'login' not in u, timeout=15000)
    except Exception: pass
    pg.wait_for_load_state('domcontentloaded'); time.sleep(.8)
    if 'login' not in pg.url: ctx.storage_state(path=sf)
    return b, ctx, pg
