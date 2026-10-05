<?php
require_once "includes/inc_all_admin.php";

// Keep the settings directory in one place instead of expanding every destination
// in the global sidebar. Feature switches mirror the links they replace.
$settings_groups = [
    'general' => [
        'title' => 'General', 'icon' => 'fa-sliders-h',
        'description' => 'Set up the organization, appearance, and everyday defaults.',
        'items' => [
            ['Company details', 'Business identity, address, and contact details.', 'settings_company.php', 'fa-briefcase'],
            ['Language & region', 'Time zone, currency, and date formats.', 'settings_localization.php', 'fa-globe'],
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
            ['Ticketing', 'Ticket defaults and customer satisfaction.', 'settings_ticket.php', 'fa-life-ring', (bool) $config_module_enable_ticketing],
            ['Projects', 'Project defaults and options.', 'settings_project.php', 'fa-project-diagram', (bool) $config_module_enable_ticketing],
            ['Invoices', 'Invoice numbering and payment terms.', 'settings_invoice.php', 'fa-file-invoice', (bool) $config_module_enable_accounting],
            ['Quotes', 'Quote defaults and numbering.', 'settings_quote.php', 'fa-comment-dollar', (bool) $config_module_enable_accounting],
            ['Payroll', 'Pay periods, deductions, and payroll options.', 'payroll_settings.php', 'fa-money-check', (bool) $config_module_enable_payroll],
            ['Training', 'Courses, compliance, kiosks, and automation.', 'settings_training.php', 'fa-hard-hat'],
            ['Knowledge Base', 'Article access and Knowledge Base status.', 'settings_kb.php', 'fa-book', lookupUserPermission('module_kb') >= 1],
            ['Custom fields', 'Extra fields for your records.', 'settings_custom_fields.php', 'fa-list-alt'],
        ],
    ],
    'access' => [
        'title' => 'Access & communication', 'icon' => 'fa-shield-alt',
        'description' => 'Control sign-in, email, and alerts.',
        'items' => [
            ['Security', 'Sign-in and security options.', 'settings_security.php', 'fa-shield-alt'],
            ['Compliance', 'Retention presets and audit-trail retention.', 'settings_compliance.php', 'fa-clipboard-check'],
            ['Compliance status', 'Checks, manual checklist and auditor export for ISO 27001, SOC 2, PCI DSS and HIPAA.', 'compliance_status.php', 'fa-clipboard-list'],
            ['Mail', 'Sending and receiving email.', 'settings_mail.php', 'fa-envelope'],
            ['Notifications', 'Choose which events send alerts.', 'settings_notification.php', 'fa-bell'],
            ['Identity provider', 'Set up portal single sign-on.', 'identity_provider.php', 'fa-fingerprint', (bool) $config_client_portal_enable],
            ['Portal preview', 'See the department portal as a user.', 'portal_preview.php', 'fa-eye', (bool) $config_client_portal_enable],
            ['Remote MCP', 'Let AI tools read RivetIT through your sign-in provider.', 'settings_mcp.php', 'fa-plug-circle-bolt'],
        ],
    ],
    'connections' => [
        'title' => 'Connections & data', 'icon' => 'fa-plug',
        'description' => 'Connect external services and manage data sharing.',
        'items' => [
            ['Integrations', 'RMM, backups, UniFi, and directory sync.', 'settings_integrations.php', 'fa-plug'],
            ['Calendar sync', 'Connect Outlook calendars.', 'settings_calendar_sync.php', 'fa-calendar-alt'],
            ['Webhooks', 'Send events to other systems.', 'settings_webhooks.php', 'fa-satellite-dish'],
            ['AI', 'Configure AI features.', 'settings_ai.php', 'fa-robot'],
            ['Telemetry', 'Manage usage data sharing.', 'settings_telemetry.php', 'fa-chart-line'],
        ],
    ],
];
require_once "includes/admin_directory.php";
renderAdminDirectory('Settings', 'Choose what you want to configure.', 'fa-cog', $settings_groups);
require_once "../includes/footer.php";
