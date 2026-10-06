<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
// Buffer the page (roles audit P1h) so a permission check that runs after the shell is printed can still
// answer HTTP 403 (itflow_render_denied); PHP flushes this buffer when the page ends.
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/page_title.php';
if (!isset($session_is_admin) || !$session_is_admin) {
    // A proper 403 page in the agent shell with a way back (roles audit P1h), not a bare text line.
    // Training level 3 has its own Training settings page (roles audit P2): the hint says where it is.
    itflow_render_denied(itflow_training_settings_hint()
        ?: 'Administration is for administrators only. Ask an administrator if you need something changed.',
        "You don't have access to this page", itflow_training_settings_go());
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/top_nav.php';
require_once 'includes/side_nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_wrapper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_alert_feedback.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/app_version.php';

// A short return path makes the settings directory useful from every detail page.
// Keep it outside forms so it never changes how an existing settings form submits.
$admin_settings_page = basename($_SERVER['PHP_SELF']);
$admin_settings_labels = [
    'settings_company.php' => 'Company details',
    'settings_localization.php' => 'Language & region',
    'settings_theme.php' => 'Theme',
    'settings_appearance.php' => 'Appearance',
    'settings_default.php' => 'Defaults',
    'settings_module.php' => 'Modules',
    'settings_ticket.php' => 'Ticketing',
    'settings_project.php' => 'Projects',
    'settings_invoice.php' => 'Invoices',
    'settings_quote.php' => 'Quotes',
    'payroll_settings.php' => 'Payroll',
    'settings_training.php' => 'Training',
    'settings_kb.php' => 'Knowledge Base',
    'settings_portal.php' => 'Employee portal',
    'settings_custom_fields.php' => 'Custom fields',
    'settings_security.php' => 'Security',
    'settings_compliance.php' => 'Compliance',
    'compliance_status.php' => 'Compliance status',
    'automation.php' => 'Automation',
    'automation_test.php' => 'Test rule',
    'event_rules.php' => 'Event rules',
    'job_queue.php' => 'Job queue',
    'audit_trail.php' => 'Audit trail',
    'settings_mail.php' => 'Mail',
    'settings_notification.php' => 'Notifications',
    'identity_provider.php' => 'Identity provider',
    'settings_mcp.php' => 'Remote MCP',
    'settings_redis.php' => 'Redis',
    'portal_preview.php' => 'Portal preview',
    'settings_integrations.php' => 'Integrations',
    'settings_calendar_sync.php' => 'Calendar sync',
    'settings_webhooks.php' => 'Webhooks',
    'settings_webhook_guides.php' => 'Webhook guides',
    'settings_ai.php' => 'AI',
    'settings_telemetry.php' => 'Telemetry',
    'settings_comet.php' => 'Integrations',
    'comet_status.php' => 'Integrations',
    'settings_rmm.php' => 'Integrations',
    'settings_unifi.php' => 'Integrations',
];
// A page that lives under an admin area (Maintenance, Ticketing setup, ...) gets that area's breadcrumb below; showing
// the Settings one as well stacked two breadcrumb rows (settings_redis.php is in both lists).
require_once __DIR__ . '/admin_nav_areas.php';
$admin_page_in_area = false;
foreach (itflowAdminNavAreas() as $admin_area_check_key => $admin_area_check) {
    if ($admin_settings_page !== $admin_area_check_key . '.php' && in_array($admin_settings_page, $admin_area_check['pages'], true)) {
        $admin_page_in_area = true;
        break;
    }
}
if (isset($admin_settings_labels[$admin_settings_page]) && !$admin_page_in_area) { ?>
    <nav aria-label="Breadcrumb" class="mb-3 admin-breadcrumb">
        <a href="/admin/settings.php"><i class="fas fa-fw fa-arrow-left me-1" aria-hidden="true"></i>All settings</a>
        <span class="text-muted mx-2" aria-hidden="true">/</span>
        <span aria-current="page"><?php echo nullable_htmlentities($admin_settings_labels[$admin_settings_page]); ?></span>
    </nav>
<?php }

require_once __DIR__ . '/admin_nav_areas.php';
$admin_area_back_labels = [
    'catalog_setup' => 'Tags & categories',
    'ticketing_setup' => 'Ticketing setup',
    'template_library' => 'All templates',
    'maintenance' => 'Maintenance',
];
foreach (itflowAdminNavAreas() as $admin_area_key => $admin_area) {
    if ($admin_settings_page === $admin_area_key . '.php' || !in_array($admin_settings_page, $admin_area['pages'], true)) {
        continue;
    }
    ?>
    <nav aria-label="Breadcrumb" class="mb-3 admin-breadcrumb">
        <a href="/admin/<?php echo nullable_htmlentities($admin_area_key); ?>.php"><i class="fas fa-fw fa-arrow-left me-1" aria-hidden="true"></i><?php echo nullable_htmlentities($admin_area_back_labels[$admin_area_key]); ?></a>
        <span class="text-muted mx-2" aria-hidden="true">/</span>
        <span aria-current="page"><?php echo isset($admin_settings_labels[$admin_settings_page]) ? nullable_htmlentities($admin_settings_labels[$admin_settings_page]) : $page_title; ?></span>
    </nav>
    <?php
    break;
}
