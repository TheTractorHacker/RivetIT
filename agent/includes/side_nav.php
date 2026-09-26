<?php
// Current page, used below to (a) mark individual links active (unchanged,
// pre-existing pattern) and (b) decide which collapsible section should
// start expanded, so navigating never lands you inside a collapsed group
// hiding the very page you're on.
//
// This map is the whole reason the sidebar does not bury the page you are on,
// and it is resolved SERVER-SIDE: the correct group is emitted already open
// (.show on the toggle + menu, .active on the <li>), so it is correct on the
// first paint and stays correct even if js/shell.js has not run yet.
$current_page = basename($_SERVER["PHP_SELF"]);
$in_reports_section = (strpos($_SERVER["PHP_SELF"], '/agent/reports/') !== false);

$section_pages = [
    'organization'  => ['clients.php', 'org_chart.php'],
    'crm'           => ['pipeline.php', 'opportunities.php', 'campaigns.php', 'segments.php'],
    'service_desk'  => ['tickets.php', 'ticket.php', 'recurring_tickets.php', 'service_catalog.php', 'csat.php', 'mail_requests.php', 'problems.php', 'problem_details.php', 'changes.php', 'change_details.php'],
    'work'          => ['projects.php', 'project_details.php', 'calendar.php'],
    'knowledge'     => ['kb_articles.php', 'kb_article.php', 'kb_article_versions.php', 'credentials.php', 'printers.php', 'network_drives.php'],
    'training'      => ['training.php', 'training_courses.php', 'training_course.php', 'training_quiz.php', 'training_banks.php', 'training_paths.php', 'training_achievements.php', 'training_preview.php', 'training_dashboard.php', 'training_reports.php', 'training_transcript.php', 'training_assignments.php', 'training_rule.php', 'training_records.php', 'training_record.php', 'training_session.php', 'training_people.php'],
    'infrastructure'=> ['assets.php', 'asset_details.php', 'locations.php', 'vendors.php', 'software.php', 'domains.php', 'certificates.php'],
    'billing'       => ['quotes.php', 'quote.php', 'invoices.php', 'invoice.php', 'recurring_invoices.php', 'recurring_invoice.php', 'revenues.php', 'products.php'],
    'finance'       => ['payments.php', 'expenses.php', 'recurring_expenses.php', 'accounts.php', 'transfers.php', 'trips.php'],
    'endpoint'      => ['intune_devices.php', 'rmm_dashboard.php', 'rmm_assets.php', 'rmm_asset.php', 'rmm_alerts.php', 'rmm_scripts.php', 'rmm_checks.php', 'network.php', 'firewalls.php'],
    'backups'       => ['backups.php'],
];
$section_pages['training'] = array_merge($section_pages['training'], ['training_devices.php', 'training_device_setup.php', 'training_pin_slips.php', 'training_locked.php', 'training_awards.php', 'training_settings.php']);

// Roles audit P0: a module-only (limited) login - no Departments, Tickets/assets/docs or Assets - sees only
// the modules its role holds: no Dashboard, no Work group, no custom links, and the logo goes to its own
// home. Everyone else keeps the sidebar exactly as it was.
$nav_limited = itflow_is_limited_user();
$nav_home_url = $nav_limited ? itflow_home_url() : '/agent/dashboard.php';
$section_open = [];
foreach ($section_pages as $key => $pages) {
    $section_open[$key] = in_array($current_page, $pages, true);
}
?>
<!-- Main Sidebar (Tabler vertical navbar).
     data-bs-theme="dark" keeps the sidebar dark in both app themes, exactly as the
     AdminLTE 4 shell did.

     Collapsible groups use Tabler's .nav-item.dropdown shape but deliberately carry
     NO data-bs-toggle="dropdown": bootstrap.bundle.min.js would then wire them
     itself and fight js/shell.js, which owns this toggle. The hook shell.js binds
     is a.nav-link.dropdown-toggle[data-if-toggle="submenu"]; it flips .show on the
     toggle and on its #id-matched .dropdown-menu sibling, .active on the parent
     <li>, and aria-expanded on the toggle. -->
<aside class="navbar navbar-vertical navbar-expand-lg d-print-none" data-bs-theme="dark">
    <div class="container-fluid">

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="navbar-brand w-100">
            <a href="<?php echo nullable_htmlentities($nav_home_url); ?>" class="d-flex align-items-center gap-2 w-100 text-reset text-decoration-none" style="min-width:0;">
                <?php if (!empty($session_company_logo)) { ?>
                    <img src="/uploads/settings/<?php echo nullable_htmlentities($session_company_logo); ?>" style="max-height:33px;width:auto;object-fit:contain;" alt="">
                <?php } else { ?>
                    <i class="fas fa-building fa-lg"></i>
                <?php } ?>
                <span class="text-truncate" title="<?php echo nullable_htmlentities($session_company_name); ?>"><?php echo nullable_htmlentities($session_company_name); ?></span>
            </a>
        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2">

                <?php if (!$nav_limited) { ?>
                <li class="nav-item<?php if ($current_page == "dashboard.php") { echo " active"; } ?>">
                    <a href="/agent/dashboard.php" class="nav-link<?php if ($current_page == "dashboard.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <span class="nav-link-title">Dashboard</span>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_rmm_alerts") >= 1) { ?>
                <li class="nav-item<?php if ($current_page == "alerts.php") { echo " active"; } ?>">
                    <a href="/agent/alerts.php" class="nav-link<?php if ($current_page == "alerts.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-bell"></i></span>
                        <span class="nav-link-title">Alerts</span>
                        <?php
                        $num_central_alerts = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
                            (SELECT COUNT(*) FROM rmm_alerts WHERE status='new') +
                            (SELECT COUNT(*) FROM comet_backup_alerts WHERE alert_status='new') AS c"))['c'] ?? 0);
                        if ($num_central_alerts) { ?>
                            <span class="ms-auto badge text-bg-danger" data-bs-toggle="tooltip" title="Open Alerts"><?php echo $num_central_alerts; ?></span>
                        <?php } ?>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_client") >= 1) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['organization'] ? ' active' : ''; ?>">
                    <a href="#nav-group-organization" class="nav-link dropdown-toggle<?php echo $section_open['organization'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-organization" aria-expanded="<?php echo $section_open['organization'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-sitemap"></i></span>
                        <span class="nav-link-title">Organization</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['organization'] ? ' show' : ''; ?>" id="nav-group-organization">
                        <a href="/agent/clients.php" class="dropdown-item<?php if ($current_page == "clients.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-users"></i></span>
                            <span class="text-truncate">Departments</span>
                            <?php if ($num_active_clients) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Active Departments"><?php echo $num_active_clients; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/org_chart.php" class="dropdown-item<?php if ($current_page == "org_chart.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-project-diagram"></i></span>
                            <span class="text-truncate">Org Chart</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if (!$nav_limited && $config_module_enable_crm == 1 && lookupUserPermission("module_sales") >= 1) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['crm'] ? ' active' : ''; ?>">
                    <a href="#nav-group-crm" class="nav-link dropdown-toggle<?php echo $section_open['crm'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-crm" aria-expanded="<?php echo $section_open['crm'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-funnel-dollar"></i></span>
                        <span class="nav-link-title">CRM</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['crm'] ? ' show' : ''; ?>" id="nav-group-crm">
                        <a href="/agent/pipeline.php" class="dropdown-item<?php if ($current_page == "pipeline.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-stream"></i></span>
                            <span class="text-truncate">Pipeline</span>
                        </a>
                        <a href="/agent/opportunities.php" class="dropdown-item<?php if ($current_page == "opportunities.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-funnel-dollar"></i></span>
                            <span class="text-truncate">Opportunities</span>
                        </a>
                        <a href="/agent/campaigns.php" class="dropdown-item<?php if ($current_page == "campaigns.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-paper-plane"></i></span>
                            <span class="text-truncate">Campaigns</span>
                        </a>
                        <a href="/agent/segments.php" class="dropdown-item<?php if ($current_page == "segments.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-layer-group"></i></span>
                            <span class="text-truncate">Segments</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_support") >= 1 && $config_module_enable_ticketing == 1) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['service_desk'] ? ' active' : ''; ?>">
                    <a href="#nav-group-service-desk" class="nav-link dropdown-toggle<?php echo $section_open['service_desk'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-service-desk" aria-expanded="<?php echo $section_open['service_desk'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-life-ring"></i></span>
                        <span class="nav-link-title">Service Desk</span>
                        <?php if ($num_active_tickets) { ?>
                            <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Open Tickets"><?php echo $num_active_tickets; ?></span>
                        <?php } ?>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['service_desk'] ? ' show' : ''; ?>" id="nav-group-service-desk">
                        <a href="/agent/tickets.php" class="dropdown-item<?php if ($current_page == "tickets.php" || $current_page == "ticket.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-life-ring"></i></span>
                            <span class="text-truncate">Tickets</span>
                            <?php if ($num_active_tickets) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Open Tickets"><?php echo $num_active_tickets; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/recurring_tickets.php" class="dropdown-item<?php if ($current_page == "recurring_tickets.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-redo-alt"></i></span>
                            <span class="text-truncate">Recurring Tickets</span>
                            <?php if ($num_recurring_tickets) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Active Recurring Tickets"><?php echo $num_recurring_tickets; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/service_catalog.php" class="dropdown-item<?php if ($current_page == "service_catalog.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-th-large"></i></span>
                            <span class="text-truncate">Request Something</span>
                        </a>
                        <?php if (!empty($config_ticket_csat_enable)) { ?>
                        <a href="/agent/csat.php" class="dropdown-item<?php if ($current_page == "csat.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-star"></i></span>
                            <span class="text-truncate">CSAT Ratings</span>
                        </a>
                        <?php } ?>
                        <a href="/agent/mail_requests.php" class="dropdown-item<?php if ($current_page == "mail_requests.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-envelope-open-text"></i></span>
                            <span class="text-truncate">Requests</span>
                            <?php if ($num_mail_requests) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Unknown-sender emails awaiting review"><?php echo $num_mail_requests; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/problems.php" class="dropdown-item<?php if ($current_page == "problems.php" || $current_page == "problem_details.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-exclamation-circle"></i></span>
                            <span class="text-truncate">Problems</span>
                        </a>
                        <a href="/agent/changes.php" class="dropdown-item<?php if ($current_page == "changes.php" || $current_page == "change_details.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-exchange-alt"></i></span>
                            <span class="text-truncate">Changes</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_support") >= 1) { // Work = Projects + Calendar, both Tickets/assets/docs (roles audit P1c) ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['work'] ? ' active' : ''; ?>">
                    <a href="#nav-group-work" class="nav-link dropdown-toggle<?php echo $section_open['work'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-work" aria-expanded="<?php echo $section_open['work'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span class="nav-link-title">Work</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['work'] ? ' show' : ''; ?>" id="nav-group-work">
                        <?php if (lookupUserPermission("module_support") >= 1 && $config_module_enable_ticketing == 1) { ?>
                        <a href="/agent/projects.php" class="dropdown-item<?php if ($current_page == "projects.php" || $current_page == "project_details.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-project-diagram"></i></span>
                            <span class="text-truncate">Projects</span>
                            <?php if ($num_active_projects) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Open Projects"><?php echo $num_active_projects; ?></span>
                            <?php } ?>
                        </a>
                        <?php } ?>
                        <a href="/agent/calendar.php" class="dropdown-item<?php if ($current_page == "calendar.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-calendar-alt"></i></span>
                            <span class="text-truncate">Calendar</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if (($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") >= 1 && lookupUserPermission("module_credential") >= 1) || ($config_module_enable_kb == 1 && lookupUserPermission("module_kb") >= 1) || ($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") >= 1)) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['knowledge'] ? ' active' : ''; ?>">
                    <a href="#nav-group-knowledge" class="nav-link dropdown-toggle<?php echo $section_open['knowledge'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-knowledge" aria-expanded="<?php echo $section_open['knowledge'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-book"></i></span>
                        <span class="nav-link-title">Knowledge</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['knowledge'] ? ' show' : ''; ?>" id="nav-group-knowledge">
                        <?php if ($config_module_enable_kb == 1 && lookupUserPermission("module_kb") >= 1) { ?>
                        <a href="/agent/kb_articles.php" class="dropdown-item<?php if ($current_page == "kb_articles.php" || $current_page == "kb_article.php" || $current_page == "kb_article_versions.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-book"></i></span>
                            <span class="text-truncate">Knowledge Base</span>
                        </a>
                        <?php } ?>
                        <?php if ($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") >= 1 && lookupUserPermission("module_credential") >= 1) { ?>
                        <a href="/agent/credentials.php" class="dropdown-item<?php if ($current_page == "credentials.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-key"></i></span>
                            <span class="text-truncate">Credentials</span>
                            <?php if ($num_credentials_all) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_credentials_all; ?></span>
                            <?php } ?>
                        </a>
                        <?php } ?>
                        <?php if ($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") >= 1) { ?>
                        <a href="/agent/printers.php" class="dropdown-item<?php if ($current_page == "printers.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-print"></i></span>
                            <span class="text-truncate">Printers</span>
                            <?php if ($num_printers_all) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_printers_all; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/network_drives.php" class="dropdown-item<?php if ($current_page == "network_drives.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-hdd"></i></span>
                            <span class="text-truncate">Network Drives</span>
                            <?php if ($num_network_drives_all) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_network_drives_all; ?></span>
                            <?php } ?>
                        </a>
                        <?php } ?>
                    </div>
                </li>
                <?php } ?>

                <?php if (($config_module_enable_training ?? 0) == 1 && lookupUserPermission("module_training") >= 1) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['training'] ? ' active' : ''; ?>">
                    <a href="#nav-group-training" class="nav-link dropdown-toggle<?php echo $section_open['training'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-training" aria-expanded="<?php echo $section_open['training'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-hard-hat"></i></span>
                        <span class="nav-link-title">Training</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['training'] ? ' show' : ''; ?>" id="nav-group-training">
                        <a href="/agent/training.php" class="dropdown-item<?php if (in_array($current_page, ['training.php', 'training_dashboard.php'], true)) { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-chart-pie"></i></span>
                            <span class="text-truncate">Overview</span>
                        </a>
                        <a href="/agent/training_courses.php" class="dropdown-item<?php if (in_array($current_page, ['training_courses.php', 'training_course.php', 'training_quiz.php', 'training_preview.php'], true)) { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-graduation-cap"></i></span>
                            <span class="text-truncate">Courses</span>
                            <?php if (!empty($num_training_unpublished)) { ?>
                                <span class="ms-auto badge text-bg-warning" title="Unpublished changes"><?php echo intval($num_training_unpublished); ?></span>
                            <?php } ?>
                        </a>
                        <?php if (lookupUserPermission("module_training") >= 2) { ?>
                        <a href="/agent/training_banks.php" class="dropdown-item<?php if ($current_page == "training_banks.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-layer-group"></i></span>
                            <span class="text-truncate">Question Library</span>
                        </a>
                        <?php } ?>
                        <a href="/agent/training_paths.php" class="dropdown-item<?php if ($current_page == "training_paths.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-route"></i></span>
                            <span class="text-truncate">Learning Paths</span>
                        </a>
                        <a href="/agent/training_assignments.php<?php if (!empty($num_training_overdue) && $num_training_overdue > 0) { echo '?status=overdue'; } ?>" class="dropdown-item<?php if (in_array($current_page, ['training_assignments.php', 'training_rule.php'], true)) { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-tasks"></i></span>
                            <span class="text-truncate">Assignments</span>
                            <?php if (!empty($num_training_overdue) && $num_training_overdue > 0) { ?>
                                <span class="ms-auto badge text-bg-danger" title="Overdue"><?php echo intval($num_training_overdue); ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/training_records.php" class="dropdown-item<?php if (in_array($current_page, ['training_records.php', 'training_record.php', 'training_session.php', 'training_transcript.php'], true)) { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-id-card"></i></span>
                            <span class="text-truncate">Records &amp; sessions</span>
                        </a>
                        <a href="/agent/training_reports.php" class="dropdown-item<?php if ($current_page == "training_reports.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-chart-bar"></i></span>
                            <span class="text-truncate">Reports</span>
                        </a>
                        <?php if (lookupUserPermission("module_training") >= 2) { ?>
                        <a href="/agent/training_achievements.php" class="dropdown-item<?php if ($current_page == "training_achievements.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-award"></i></span>
                            <span class="text-truncate">Achievements</span>
                        </a>
                        <?php } ?>
                        <a href="/agent/training_people.php" class="dropdown-item<?php if ($current_page == "training_people.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-users"></i></span>
                            <span class="text-truncate">People</span>
                        </a>
                        <?php if (lookupUserPermission("module_training_kiosk") >= 1) { ?>
                        <a href="/agent/training_devices.php" class="dropdown-item<?php if (in_array($current_page, ['training_devices.php', 'training_device_setup.php', 'training_pin_slips.php'], true)) { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-tablet-alt"></i></span>
                            <span class="text-truncate">Devices &amp; PINs</span>
                        </a>
                        <?php } ?>
                        <?php if (lookupUserPermission("module_training") >= 2) { ?>
                        <a href="/agent/training_locked.php" class="dropdown-item<?php if ($current_page == "training_locked.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-lock"></i></span>
                            <span class="text-truncate">Locked courses</span>
                        </a>
                        <a href="/agent/training_awards.php" class="dropdown-item<?php if ($current_page == "training_awards.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-award"></i></span>
                            <span class="text-truncate">Awarded badges</span>
                        </a>
                        <?php } ?>
                        <?php if (lookupUserPermission("module_training") >= 3 && empty($session_is_admin)) { // Training level 3 without admin (admins keep Admin > Training; their sidebar is unchanged) ?>
                        <a href="/agent/training_settings.php" class="dropdown-item<?php if ($current_page == "training_settings.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-sliders-h"></i></span>
                            <span class="text-truncate">Training settings</span>
                        </a>
                        <?php } ?>
                    </div>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") < 1 && itflow_can_assets(1)) { // P4: the Assets module alone ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['infrastructure'] ? ' active' : ''; ?>">
                    <a href="#nav-group-infrastructure" class="nav-link dropdown-toggle<?php echo $section_open['infrastructure'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-infrastructure" aria-expanded="<?php echo $section_open['infrastructure'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-server"></i></span>
                        <span class="nav-link-title">Infrastructure</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['infrastructure'] ? ' show' : ''; ?>" id="nav-group-infrastructure">
                        <a href="/agent/assets.php" class="dropdown-item<?php if ($current_page == "assets.php" || $current_page == "asset_details.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-desktop"></i></span>
                            <span class="text-truncate">Assets</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") >= 1) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['infrastructure'] ? ' active' : ''; ?>">
                    <a href="#nav-group-infrastructure" class="nav-link dropdown-toggle<?php echo $section_open['infrastructure'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-infrastructure" aria-expanded="<?php echo $section_open['infrastructure'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-server"></i></span>
                        <span class="nav-link-title">Infrastructure</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['infrastructure'] ? ' show' : ''; ?>" id="nav-group-infrastructure">
                        <a href="/agent/assets.php" class="dropdown-item<?php if ($current_page == "assets.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-desktop"></i></span>
                            <span class="text-truncate">Assets</span>
                        </a>
                        <a href="/agent/locations.php" class="dropdown-item<?php if ($current_page == "locations.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-map-marker-alt"></i></span>
                            <span class="text-truncate">Locations</span>
                            <?php if ($num_locations_all) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_locations_all; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/vendors.php" class="dropdown-item<?php if ($current_page == "vendors.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-building"></i></span>
                            <span class="text-truncate">Vendors</span>
                        </a>
                        <a href="/agent/software.php" class="dropdown-item<?php if ($current_page == "software.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-cube"></i></span>
                            <span class="text-truncate">Licenses</span>
                            <?php if ($num_software_all) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_software_all; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/domains.php" class="dropdown-item<?php if ($current_page == "domains.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-globe"></i></span>
                            <span class="text-truncate">Domains</span>
                            <?php if ($num_domains_all) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_domains_all; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/certificates.php" class="dropdown-item<?php if ($current_page == "certificates.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-lock"></i></span>
                            <span class="text-truncate">Certificates</span>
                            <?php if ($num_certificates_all) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_certificates_all; ?></span>
                            <?php } ?>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if (!$nav_limited && $config_module_enable_accounting == 1 && lookupUserPermission("module_sales") >= 1) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['billing'] ? ' active' : ''; ?>">
                    <a href="#nav-group-billing" class="nav-link dropdown-toggle<?php echo $section_open['billing'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-billing" aria-expanded="<?php echo $section_open['billing'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                        <span class="nav-link-title">Billing</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['billing'] ? ' show' : ''; ?>" id="nav-group-billing">
                        <a href="/agent/quotes.php" class="dropdown-item<?php if ($current_page == "quotes.php" || $current_page == "quote.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-comment-dollar"></i></span>
                            <span class="text-truncate">Quotes</span>
                            <?php if ($num_open_quotes) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Active Quotes"><?php echo $num_open_quotes; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/invoices.php" class="dropdown-item<?php if ($current_page == "invoices.php" || $current_page == "invoice.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-file-invoice"></i></span>
                            <span class="text-truncate">Invoices</span>
                            <?php if ($num_open_invoices) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Open Invoices"><?php echo $num_open_invoices; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/recurring_invoices.php" class="dropdown-item<?php if ($current_page == "recurring_invoices.php" || $current_page == "recurring_invoice.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-redo-alt"></i></span>
                            <span class="text-truncate">Recurring Invoices</span>
                            <?php if ($num_recurring_invoices) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Active Recurring Invoices"><?php echo $num_recurring_invoices; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/revenues.php" class="dropdown-item<?php if ($current_page == "revenues.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-hand-holding-usd"></i></span>
                            <span class="text-truncate">Revenues</span>
                        </a>
                        <a href="/agent/products.php" class="dropdown-item<?php if ($current_page == "products.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-box-open"></i></span>
                            <span class="text-truncate">Products</span>
                        </a>
                    </div>
                </li>
                <?php } elseif (!$nav_limited && $config_module_enable_ticket_charges == 1 && lookupUserPermission("module_sales") >= 1) { ?>
                <li class="nav-item<?php if ($current_page == "products.php") { echo " active"; } ?>">
                    <a href="/agent/products.php" class="nav-link<?php if ($current_page == "products.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-box-open"></i></span>
                        <span class="nav-link-title">Products</span>
                    </a>
                </li>
                <?php } ?>

                <?php if (!$nav_limited && $config_module_enable_accounting == 1 && lookupUserPermission("module_financial") >= 1) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['finance'] ? ' active' : ''; ?>">
                    <a href="#nav-group-finance" class="nav-link dropdown-toggle<?php echo $section_open['finance'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-finance" aria-expanded="<?php echo $section_open['finance'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-piggy-bank"></i></span>
                        <span class="nav-link-title">Finance</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['finance'] ? ' show' : ''; ?>" id="nav-group-finance">
                        <?php if (lookupUserPermission("module_financial") >= 1) { ?>
                        <a href="/agent/payments.php" class="dropdown-item<?php if ($current_page == "payments.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-credit-card"></i></span>
                            <span class="text-truncate">Payments</span>
                        </a>
                        <a href="/agent/expenses.php" class="dropdown-item<?php if ($current_page == "expenses.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-shopping-cart"></i></span>
                            <span class="text-truncate">Expenses</span>
                        </a>
                        <a href="/agent/recurring_expenses.php" class="dropdown-item<?php if ($current_page == "recurring_expenses.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-redo-alt"></i></span>
                            <span class="text-truncate">Recurring Expenses</span>
                            <?php if ($num_recurring_expenses) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Recurring Expenses"><?php echo $num_recurring_expenses; ?></span>
                            <?php } ?>
                        </a>
                        <a href="/agent/accounts.php" class="dropdown-item<?php if ($current_page == "accounts.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-piggy-bank"></i></span>
                            <span class="text-truncate">Accounts</span>
                        </a>
                        <a href="/agent/transfers.php" class="dropdown-item<?php if ($current_page == "transfers.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-exchange-alt"></i></span>
                            <span class="text-truncate">Transfers</span>
                        </a>
                        <?php if ($config_module_enable_payroll && $session_is_admin) { ?>
                        <a href="/admin/payroll_periods.php" class="dropdown-item">
                            <span class="dropdown-item-icon"><i class="fas fa-money-check"></i></span>
                            <span class="text-truncate">Payroll</span>
                        </a>
                        <?php } ?>
                        <a href="/agent/trips.php" class="dropdown-item<?php if ($current_page == "trips.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-route"></i></span>
                            <span class="text-truncate">Trips</span>
                        </a>
                        <?php } // Trips (agent/trips.php needs Finance) moved inside the Finance check - roles audit P1i ?>
                    </div>
                </li>
                <?php } ?>

                <?php $nav_intune = $config_module_enable_intune && lookupUserPermission("module_client") >= 1; // roles audit P1i ?>
                <?php if (($config_module_enable_rmm && lookupUserPermission("module_rmm") >= 1) || $nav_intune) { ?>
                <li class="nav-item dropdown mt-2<?php echo $section_open['endpoint'] ? ' active' : ''; ?>">
                    <a href="#nav-group-endpoints" class="nav-link dropdown-toggle<?php echo $section_open['endpoint'] ? ' show' : ''; ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-endpoints" aria-expanded="<?php echo $section_open['endpoint'] ? 'true' : 'false'; ?>">
                        <span class="nav-link-icon"><i class="fas fa-desktop"></i></span>
                        <span class="nav-link-title">Endpoints</span>
                    </a>
                    <div class="dropdown-menu<?php echo $section_open['endpoint'] ? ' show' : ''; ?>" id="nav-group-endpoints">
                        <?php if ($nav_intune) { ?>
                        <a href="/agent/intune_devices.php" class="dropdown-item<?php if ($current_page == "intune_devices.php") { echo " active"; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-laptop"></i></span>
                            <span class="text-truncate">Intune Devices</span>
                        </a>
                        <?php } ?>
                        <?php if ($config_module_enable_rmm && lookupUserPermission("module_rmm") >= 1) { ?>
                        <a href="/agent/rmm_dashboard.php" class="dropdown-item<?php if ($current_page == 'rmm_dashboard.php') { echo ' active'; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span class="text-truncate">RMM Dashboard</span>
                        </a>
                        <a href="/agent/rmm_assets.php" class="dropdown-item<?php if (in_array($current_page, ['rmm_assets.php','rmm_asset.php'])) { echo ' active'; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-desktop"></i></span>
                            <span class="text-truncate">Assets</span>
                        </a>
                        <a href="/agent/rmm_alerts.php" class="dropdown-item<?php if ($current_page == 'rmm_alerts.php') { echo ' active'; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-bell"></i></span>
                            <span class="text-truncate">RMM Alerts</span>
                        </a>
                        <a href="/agent/rmm_scripts.php" class="dropdown-item<?php if ($current_page == 'rmm_scripts.php') { echo ' active'; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-code"></i></span>
                            <span class="text-truncate">Scripts</span>
                        </a>
                        <a href="/agent/rmm_checks.php" class="dropdown-item<?php if ($current_page == 'rmm_checks.php') { echo ' active'; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-heartbeat"></i></span>
                            <span class="text-truncate">Check Policies</span>
                        </a>
                        <a href="/agent/network.php" class="dropdown-item<?php if (in_array($current_page, ['network.php','firewalls.php'])) { echo ' active'; } ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-network-wired"></i></span>
                            <span class="text-truncate">Network</span>
                        </a>
                        <?php } ?>
                    </div>
                </li>
                <?php } ?>

                <?php if (!empty($config_comet_enabled) && lookupUserPermission("module_rmm") >= 1) { ?>
                <li class="nav-item mt-3<?php if ($current_page == 'backups.php') { echo ' active'; } ?>">
                    <a href="/agent/backups.php" class="nav-link<?php if ($current_page == 'backups.php') { echo ' active'; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                        <span class="nav-link-title">Backups</span>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_client") >= 1) { ?>
                <li class="nav-item mt-3">
                    <a href="/agent/contacts.php" class="nav-link">
                        <span class="nav-link-icon"><i class="fas fa-users"></i></span>
                        <span class="nav-link-title">People</span>
                        <i class="fas fa-angle-right ms-auto"></i>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_reporting") >= 1) { ?>
                    <li class="nav-item<?php echo (lookupUserPermission("module_client") >= 1) ? '' : ' mt-3'; ?><?php if ($in_reports_section) { echo " active"; } ?>">
                        <a href="/agent/reports/" class="nav-link<?php if ($in_reports_section) { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-chart-line"></i></span>
                            <span class="nav-link-title">Reports</span>
                            <i class="fas fa-angle-right ms-auto"></i>
                        </a>
                    </li>
                <?php } ?>

                <?php
                // Custom links are IT links (docs sites, Odoo, ...): hidden from module-only (limited) logins,
                // shown to everyone else as before (roles audit P1i; per-link visibility would need a column).
                $sql_custom_links = $nav_limited ? false : mysqli_query($mysqli, "SELECT * FROM custom_links WHERE custom_link_location = 1 AND custom_link_archived_at IS NULL
                    ORDER BY custom_link_order ASC, custom_link_name ASC"
                );

                while ($sql_custom_links && $row = mysqli_fetch_assoc($sql_custom_links)) {
                    $custom_link_name = nullable_htmlentities($row['custom_link_name']);
                    $custom_link_uri = sanitize_url($row['custom_link_uri']);
                    $custom_link_icon_class = itflow_nav_icon_class($row['custom_link_icon']);
                    $custom_link_new_tab = intval($row['custom_link_new_tab']);
                    if ($custom_link_new_tab == 1) {
                        $target = "target='_blank' rel='noopener noreferrer'";
                    } else {
                        $target = "";
                    }

                    ?>

                <li class="nav-item<?php if ($current_page == basename($custom_link_uri)) { echo " active"; } ?>">
                    <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link<?php if ($current_page == basename($custom_link_uri)) { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas <?php echo $custom_link_icon_class; ?>"></i></span>
                        <span class="nav-link-title"><?php echo $custom_link_name; ?></span>
                        <i class="fas fa-angle-right ms-auto"></i>
                    </a>
                </li>

                <?php } ?>

            </ul>
            <div class="mb-3"></div>
        </div>
        <!-- /.navbar-collapse -->

    </div>
</aside>
