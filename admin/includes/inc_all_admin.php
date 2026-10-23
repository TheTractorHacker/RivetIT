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
    'webhook_new.php' => 'Webhooks',
    'webhook_edit.php' => 'Webhooks',
    'settings_ai.php' => 'AI',
    'settings_telemetry.php' => 'Telemetry',
    'settings_comet.php' => 'Integrations',
    'comet_status.php' => 'Integrations',
    'settings_rmm.php' => 'Integrations',
    'settings_endpoint_agent.php' => 'Endpoint agent',
    'settings_mesh_wizard.php' => 'Remote access (MeshCentral) setup',
    'settings_unifi.php' => 'Integrations',
    'settings_manageengine_mdm.php' => 'Integrations',
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
        <?php if (!empty($admin_breadcrumb_trail) && is_array($admin_breadcrumb_trail)) {
            // A page may add deeper crumbs (e.g. Event rules / New rule): [[label, url|null], ...]; the last one is the current page.
            foreach ($admin_breadcrumb_trail as $admin_crumb_i => $admin_crumb) { ?>
                <span class="text-muted mx-2" aria-hidden="true">/</span>
                <?php if (!empty($admin_crumb[1]) && $admin_crumb_i < count($admin_breadcrumb_trail) - 1) { ?>
                    <a href="<?php echo nullable_htmlentities($admin_crumb[1]); ?>"><?php echo nullable_htmlentities($admin_crumb[0]); ?></a>
                <?php } else { ?>
                    <span aria-current="page"><?php echo nullable_htmlentities($admin_crumb[0]); ?></span>
                <?php } ?>
            <?php }
        } else { ?>
            <span class="text-muted mx-2" aria-hidden="true">/</span>
            <span aria-current="page"><?php echo nullable_htmlentities($admin_settings_labels[$admin_settings_page]); ?></span>
        <?php } ?>
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
    $admin_area_crumb_done = true;
    break;
}

// Admin pages that sit in no settings list and no admin area (payroll, payments, tax, archives...) had no way back at all.
// Same back-crumb as everywhere else: [parent label, parent url, current page label].
$admin_loose_crumbs = [
    'payroll_employees.php'    => ['Payroll', '/admin/payroll_settings.php', 'Employees'],
    'payroll_employee.php'     => ['Payroll', '/admin/payroll_settings.php', 'Employee'],
    'payroll_periods.php'      => ['Payroll', '/admin/payroll_settings.php', 'Pay periods'],
    'payroll_period.php'       => ['Payroll', '/admin/payroll_settings.php', 'Pay period'],
    'payroll_runs.php'         => ['Payroll', '/admin/payroll_settings.php', 'Payroll runs'],
    'payroll_run.php'          => ['Payroll', '/admin/payroll_settings.php', 'Payroll run'],
    'payroll_deductions.php'   => ['Payroll', '/admin/payroll_settings.php', 'Deduction categories'],
    'kb_articles_archive.php'  => ['Knowledge Base', '/admin/settings_kb.php', 'Archived articles'],
    'ticket_automation_log.php' => ['Ticket automation', '/admin/ticket_automation.php', 'Run log'],
    'settings_mail_templates.php' => ['Ticketing', '/admin/settings_ticket.php', 'Email templates'],
    'people_import.php'        => ['Tags & categories', '/admin/catalog_setup.php', 'People import'],
    'portal_users.php'         => ['All settings', '/admin/settings.php', 'Department logins'],
];
if (empty($admin_area_crumb_done) && !isset($admin_settings_labels[$admin_settings_page]) && isset($admin_loose_crumbs[$admin_settings_page])) {
    [$loose_parent, $loose_url, $loose_label] = $admin_loose_crumbs[$admin_settings_page]; ?>
    <nav aria-label="Breadcrumb" class="mb-3 admin-breadcrumb">
        <a href="<?php echo nullable_htmlentities($loose_url); ?>"><i class="fas fa-fw fa-arrow-left me-1" aria-hidden="true"></i><?php echo nullable_htmlentities($loose_parent); ?></a>
        <span class="text-muted mx-2" aria-hidden="true">/</span>
        <span aria-current="page"><?php echo nullable_htmlentities($loose_label); ?></span>
    </nav>
<?php }
