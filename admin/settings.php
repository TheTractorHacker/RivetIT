<?php
require_once "includes/inc_all_admin.php";

// At-a-glance status for the tiles below. Read-only, built from settings already loaded
// (plus two cheap counts); never touches an integration or sends anything.
$st_company = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_name, company_phone, company_email, company_address FROM companies WHERE company_id = 1 LIMIT 1")) ?: [];
$st_company_missing = [];
foreach (['company_phone' => 'phone', 'company_email' => 'email', 'company_address' => 'address'] as $col => $word) {
    if (trim((string) ($st_company[$col] ?? '')) === '') { $st_company_missing[] = $word; }
}
$st_webhooks = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS total, SUM(webhook_enabled = 1) AS enabled FROM webhooks")) ?: ['total' => 0, 'enabled' => 0];
$st_smtp = trim((string) $config_smtp_host) !== '';
$st_imap = trim((string) $config_imap_host) !== '';
$st = [
    'company' => $st_company_missing ? ['warn', 'Missing ' . implode(', ', $st_company_missing)] : ['ok', 'Complete'],
    'locale' => trim((string) $config_timezone) !== '' ? ['ok', (string) $config_timezone] : ['warn', 'No time zone set'],
    'mail' => $st_smtp ? ['ok', 'Sending on' . ($st_imap ? ', receiving on' : ', receiving off')] : ['warn', 'Email sending not set up'],
    'security' => !empty($config_login_key_required) ? ['ok', 'Login key required'] : ['off', 'No login key'],
    'idp' => $config_oidc_enabled ? ['ok', 'Single sign-on on'] : ['off', 'Not configured'],
    'webhooks' => (int) $st_webhooks['enabled'] > 0 ? ['ok', (int) $st_webhooks['enabled'] . ' active'] : ['off', 'None active'],
    'ai' => $config_ai_enable ? ['ok', 'On'] : ['off', 'Off'],
    'telemetry' => $config_telemetry ? ['ok', 'Sharing on'] : ['off', 'Sharing off'],
    'mcp' => !empty($config_module_enable_mcp) ? ['ok', 'On'] : ['off', 'Off'],
    'ticketing' => $config_module_enable_ticketing ? ['ok', 'On'] : ['off', 'Off'],
];

// Keep the settings directory in one place instead of expanding every destination
// in the global sidebar. Feature switches mirror the links they replace.
$settings_groups = [
    'general' => [
        'title' => 'General', 'icon' => 'fa-sliders-h',
        'description' => 'Set up the organization, appearance, and everyday defaults.',
        'items' => [
            ['Company details', 'Business identity, address, and contact details.', 'settings_company.php', 'fa-briefcase', true, null, $st['company']],
            ['Language & region', 'Time zone, currency, and date formats.', 'settings_localization.php', 'fa-globe', true, null, $st['locale']],
            ['Theme', 'Colors, light and dark mode, and favicon.', 'settings_theme.php', 'fa-paint-brush'],
            ['Appearance', 'Branding and display options.', 'settings_appearance.php', 'fa-palette'],
            ['Defaults', 'Starting page and default accounts.', 'settings_default.php', 'fa-cogs'],
            ['Modules', 'Turn app features on or off.', 'settings_module.php', 'fa-cube'],
        ],
    ],
    'workflows' => [
        'title' => 'Workflows', 'icon' => 'fa-stream',
        'description' => 'Choose how work, billing, and learning behave.',
        'items' => [
            ['Ticketing', 'Ticket defaults and customer satisfaction.', 'settings_ticket.php', 'fa-life-ring', (bool) $config_module_enable_ticketing, null, $st['ticketing']],
            ['Projects', 'Project defaults and options.', 'settings_project.php', 'fa-project-diagram', (bool) $config_module_enable_ticketing],
            ['Payroll', 'Pay periods, deductions, and payroll options.', 'payroll_settings.php', 'fa-money-check', (bool) $config_module_enable_payroll],
            ['Training', 'Courses, compliance, kiosks, and automation.', 'settings_training.php', 'fa-hard-hat'],
            ['Knowledge Base', 'Article access and Knowledge Base status.', 'settings_kb.php', 'fa-book', lookupUserPermission('module_kb') >= 1],
            ['Custom fields', 'Stored only: not shown on records yet.', 'settings_custom_fields.php', 'fa-list-alt'],
        ],
    ],
    'access' => [
        'title' => 'Access & communication', 'icon' => 'fa-shield-alt',
        'description' => 'Control sign-in, email, and alerts.',
        'items' => [
            ['Security', 'Sign-in and security options.', 'settings_security.php', 'fa-shield-alt', true, null, $st['security']],
            ['Compliance', 'Retention presets and audit-trail retention.', 'settings_compliance.php', 'fa-clipboard-check'],
            ['Compliance status', 'Checks, manual checklist and auditor export for ISO 27001, SOC 2, PCI DSS and HIPAA.', 'compliance_status.php', 'fa-clipboard-list'],
            ['Compliance framework', 'Pick a framework profile (retention minimums, backup RPO, restore tests); import or export it.', 'compliance_framework.php', 'fa-balance-scale'],
            ['Security and trust', 'Security baseline, self-assessment and the public trust page.', 'security_trust.php', 'fa-user-shield'],
            ['Reporting / Power BI', 'Read-only reporting views and a database login for Power BI or ODBC.', 'reporting.php', 'fa-chart-line'],
            ['Automation', 'Event rules, ticket rules, lifecycle workflows, the run log and the on/off switch in one place.', 'automation.php', 'fa-cogs'],
            ['Event rules', 'Create a ticket, notify someone or call a service when something happens.', 'event_rules.php', 'fa-bolt'],
            ['Job queue', 'Background deliveries and rule actions: status, retries and failures.', 'job_queue.php', 'fa-tasks'],
            ['Mail', 'Sending and receiving email.', 'settings_mail.php', 'fa-envelope', true, null, $st['mail']],
            ['Notifications', 'Choose which events send alerts.', 'settings_notification.php', 'fa-bell'],
            ['Email Templates', 'Customize the ticket and portal emails sent to clients.', 'settings_mail_templates.php', 'fa-envelope-open-text'],
            ['Identity provider', 'Set up portal single sign-on.', 'identity_provider.php', 'fa-fingerprint', (bool) $config_client_portal_enable, null, $st['idp']],
            ['Employee portal', 'Home page sections and onboarding requests.', 'settings_portal.php', 'fa-user-circle', (bool) $config_client_portal_enable],
            ['Portal announcements', 'Notices shown on the employee portal home page.', 'portal_announcements.php', 'fa-bullhorn', (bool) $config_client_portal_enable],
            ['Portal preview', 'See the department portal as a user.', 'portal_preview.php', 'fa-eye', (bool) $config_client_portal_enable],
            ['Remote MCP', ('Let AI tools read ' . htmlspecialchars(APP_NAME) . ' through your sign-in provider.'), 'settings_mcp.php', 'fa-plug', true, null, $st['mcp']],
        ],
    ],
    'connections' => [
        'title' => 'Connections & data', 'icon' => 'fa-plug',
        'description' => 'Connect external services and manage data sharing.',
        'items' => [
            ['Integrations', 'RMM, backups, UniFi, and directory sync.', 'settings_integrations.php', 'fa-plug'],
            ['Endpoint agent', 'The built-in RMM module and Windows agent: the on/off switch, enrollment, devices, jobs, updates and MeshCentral remote access.', 'settings_endpoint_agent.php', 'fa-satellite'],
            ['Remote access (MeshCentral) setup', 'Step-by-step help to connect your MeshCentral server, create the device group, trust its certificate and test Remote on a device.', 'settings_mesh_wizard.php', 'fa-desktop'],
            ['Calendar sync', 'Connect Outlook calendars.', 'settings_calendar_sync.php', 'fa-calendar-alt'],
            ['Webhooks', 'Send events to other systems.', 'settings_webhooks.php', 'fa-satellite-dish', true, null, $st['webhooks']],
            ['Chat', 'Slack and Teams: two-way tickets, commands, actions, virtual agent.', 'settings_chat.php', 'fa-comments'],
            ['AI', 'Configure AI features.', 'settings_ai.php', 'fa-robot', true, null, $st['ai']],
            ['Telemetry', 'Manage usage data sharing.', 'settings_telemetry.php', 'fa-chart-line', true, null, $st['telemetry']],
        ],
    ],
];
require_once "includes/admin_directory.php";
renderAdminDirectory('Settings', 'Choose what you want to configure.', 'fa-cog', $settings_groups);
require_once "../includes/footer.php";
