<?php
require_once __DIR__ . '/admin_nav_areas.php';
$admin_nav_areas = itflowAdminNavAreas();
$admin_current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Admin Sidebar (Tabler vertical navbar).
     data-bs-theme="dark" keeps the sidebar dark in both app themes, exactly as the
     AdminLTE 4 shell did.

     Collapsible groups use Tabler's .nav-item.dropdown shape but deliberately carry
     NO data-bs-toggle="dropdown": bootstrap.bundle.min.js would then wire them
     itself and fight js/shell.js, which owns this toggle. The hook shell.js binds
     is a.nav-link.dropdown-toggle[data-if-toggle="submenu"]; it flips .show on the
     toggle and on its #id-matched .dropdown-menu sibling, .active on the parent
     <li>, and aria-expanded on the toggle.

     Which group starts open is still decided SERVER-SIDE by the same in_array()
     page maps as before, so the group containing the current page is expanded on
     first paint, with or without JS. -->
<aside class="navbar navbar-vertical navbar-expand-lg d-print-none" data-bs-theme="dark" aria-label="Administration">
    <div class="container-fluid">

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Brand area. The back-to-app link keeps its own .section-nav-back styling
             from css/itflow_bs5_bridge.css, so the brand box contributes no padding
             of its own (p-0) and lets the link fill it (w-100). -->
        <div class="navbar-brand p-0 w-100">
            <a class="section-nav-back" href="<?php echo nullable_htmlentities(itflow_home_url()); // start page; a module-only login's own home (roles audit P0) ?>">
                <i class="fas fa-arrow-left"></i> Administration
            </a>
        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2">

                <li class="nav-item nav-section-title">ACCESS</li>
                <li class="nav-item<?php if (in_array(basename($_SERVER["PHP_SELF"]), ["users.php", "portal_users.php"], true)) {echo " active";} ?>">
                    <a href="/admin/users.php" class="nav-link <?php if (in_array(basename($_SERVER["PHP_SELF"]), ["users.php", "portal_users.php"], true)) {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-users"></i></span>
                        <span class="nav-link-title">Users</span>
                    </a>
                </li>
                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == "roles.php") {echo " active";} ?>">
                    <a href="/admin/roles.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "roles.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-user-shield"></i></span>
                        <span class="nav-link-title">Roles</span>
                    </a>
                </li>
                <!-- 2025-12-05 JQ - Hide Permission Modules currently just shows modules
                <li class="nav-item">
                    <a href="/admin/modules.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "modules.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-puzzle-piece"></i></span>
                        <span class="nav-link-title">Modules</span>
                    </a>
                </li>
                -->
                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == "api_keys.php") {echo " active";} ?>">
                    <a href="/admin/api_keys.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "api_keys.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-key"></i></span>
                        <span class="nav-link-title">API Keys</span>
                    </a>
                </li>
                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == "api_docs.php") {echo " active";} ?>">
                    <a href="/admin/api_docs.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "api_docs.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-code"></i></span>
                        <span class="nav-link-title">API Docs</span>
                    </a>
                </li>

                <li class="nav-item nav-section-title">CONFIGURATION</li>

                <?php $nav_open_tags = in_array($admin_current_page, $admin_nav_areas['catalog_setup']['pages'], true); ?>
                <li class="nav-item<?php echo $nav_open_tags ? ' active' : ''; ?>">
                    <a href="/admin/catalog_setup.php" class="nav-link<?php echo $nav_open_tags ? ' active' : ''; ?>"<?php echo $admin_current_page === 'catalog_setup.php' ? ' aria-current="page"' : ''; ?>>
                        <span class="nav-link-icon"><i class="fas fa-sliders-h"></i></span>
                        <span class="nav-link-title">Tags &amp; Categories</span>
                    </a>
                </li>

                <?php if ($config_module_enable_accounting) { ?>
                <!-- BILLING Section -->
                <?php $nav_open_billing = in_array(basename($_SERVER['PHP_SELF']), ['tax.php', 'payment_method.php', 'payment_provider.php', 'saved_payment_method.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_billing ? ' active' : ''); ?>">
                    <a href="#nav-group-billing" class="nav-link dropdown-toggle<?php echo ($nav_open_billing ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-billing" aria-expanded="<?php echo ($nav_open_billing ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-hand-holding-usd"></i></span>
                        <span class="nav-link-title">Billing</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_billing ? ' show' : ''); ?>" id="nav-group-billing">
                        <a href="/admin/tax.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'tax.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-balance-scale"></i></span>
                            <span class="text-truncate">Taxes</span>
                        </a>
                        <a href="/admin/payment_method.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payment_method.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-money-check-alt"></i></span>
                            <span class="text-truncate">Payment Methods</span>
                        </a>
                        <a href="/admin/payment_provider.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payment_provider.php', 'saved_payment_method.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="far fa-credit-card"></i></span>
                            <span class="text-truncate">Payment Providers</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_payroll) { ?>
                <!-- PAYROLL Section -->
                <?php $nav_open_payroll = in_array(basename($_SERVER['PHP_SELF']), ['payroll_employees.php', 'payroll_employee.php', 'payroll_deductions.php', 'payroll_periods.php', 'payroll_period.php', 'payroll_runs.php', 'payroll_run.php', 'payroll_settings.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_payroll ? ' active' : ''); ?>">
                    <a href="#nav-group-payroll" class="nav-link dropdown-toggle<?php echo ($nav_open_payroll ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-payroll" aria-expanded="<?php echo ($nav_open_payroll ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-money-check"></i></span>
                        <span class="nav-link-title">Payroll</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_payroll ? ' show' : ''); ?>" id="nav-group-payroll">
                        <a href="/admin/payroll_employees.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_employees.php', 'payroll_employee.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-user-tag"></i></span>
                            <span class="text-truncate">Employees</span>
                        </a>
                        <a href="/admin/payroll_deductions.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payroll_deductions.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-minus-circle"></i></span>
                            <span class="text-truncate">Deductions</span>
                        </a>
                        <a href="/admin/payroll_periods.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_periods.php', 'payroll_period.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-calendar-alt"></i></span>
                            <span class="text-truncate">Periods</span>
                        </a>
                        <a href="/admin/payroll_runs.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_runs.php', 'payroll_run.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-play-circle"></i></span>
                            <span class="text-truncate">Runs</span>
                        </a>
                        <a href="/admin/payroll_settings.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payroll_settings.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-sliders-h"></i></span>
                            <span class="text-truncate">Settings</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_ticketing) { ?>
                <?php $nav_open_ticketing = in_array($admin_current_page, $admin_nav_areas['ticketing_setup']['pages'], true); ?>
                <li class="nav-item<?php echo $nav_open_ticketing ? ' active' : ''; ?>">
                    <a href="/admin/ticketing_setup.php" class="nav-link<?php echo $nav_open_ticketing ? ' active' : ''; ?>"<?php echo $admin_current_page === 'ticketing_setup.php' ? ' aria-current="page"' : ''; ?>>
                        <span class="nav-link-icon"><i class="fas fa-life-ring"></i></span>
                        <span class="nav-link-title">Ticketing</span>
                    </a>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_itdoc) { ?>
                <?php $nav_open_templates = in_array($admin_current_page, $admin_nav_areas['template_library']['pages'], true); ?>
                <li class="nav-item<?php echo $nav_open_templates ? ' active' : ''; ?>">
                    <a href="/admin/template_library.php" class="nav-link<?php echo $nav_open_templates ? ' active' : ''; ?>"<?php echo $admin_current_page === 'template_library.php' ? ' aria-current="page"' : ''; ?>>
                        <span class="nav-link-icon"><i class="fas fa-copy"></i></span>
                        <span class="nav-link-title">Templates</span>
                    </a>
                </li>
                <?php } ?>

                <?php $nav_open_maintenance = in_array($admin_current_page, $admin_nav_areas['maintenance']['pages'], true); ?>
                <li class="nav-item<?php echo $nav_open_maintenance ? ' active' : ''; ?>">
                    <a href="/admin/maintenance.php" class="nav-link<?php echo $nav_open_maintenance ? ' active' : ''; ?>"<?php echo $admin_current_page === 'maintenance.php' ? ' aria-current="page"' : ''; ?>>
                        <span class="nav-link-icon"><i class="fas fa-tools"></i></span>
                        <span class="nav-link-title">Maintenance</span>
                    </a>
                </li>

                <!-- Keep the global menu short; the settings directory groups all destinations. -->
                <?php $nav_open_settings = in_array(basename($_SERVER['PHP_SELF']), ['settings.php', 'settings_company.php', 'settings_localization.php', 'settings_theme.php', 'settings_appearance.php', 'settings_compliance.php', 'compliance_status.php', 'automation.php', 'automation_test.php', 'event_rules.php', 'job_queue.php', 'settings_security.php', 'settings_mail.php', 'settings_notification.php', 'settings_default.php', 'settings_invoice.php', 'settings_quote.php', 'settings_project.php', 'settings_ticket.php', 'settings_ai.php', 'settings_custom_fields.php', 'identity_provider.php', 'settings_mcp.php', 'settings_redis.php', 'server_tasks.php', 'settings_telemetry.php', 'settings_module.php', 'portal_preview.php', 'settings_calendar_sync.php', 'settings_webhooks.php', 'settings_integrations.php', 'settings_comet.php', 'comet_status.php', 'settings_rmm.php', 'settings_unifi.php', 'settings_kb.php', 'settings_training.php'], true); ?>
                <li class="nav-item<?php echo ($nav_open_settings ? ' active' : ''); ?>">
                    <a href="/admin/settings.php" class="nav-link<?php echo ($nav_open_settings ? ' active' : ''); ?>"<?php echo (basename($_SERVER['PHP_SELF']) === 'settings.php' ? ' aria-current="page"' : ''); ?>>
                        <span class="nav-link-icon"><i class="fas fa-cog"></i></span>
                        <span class="nav-link-title">Settings</span>
                    </a>
                </li>

                <?php
                $sql_custom_links = mysqli_query($mysqli, "SELECT * FROM custom_links
                    WHERE custom_link_location = 4 AND custom_link_archived_at IS NULL
                    ORDER BY custom_link_order ASC, custom_link_name ASC"
                );

                while ($row = mysqli_fetch_assoc($sql_custom_links)) {
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

                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == basename($custom_link_uri)) { echo " active"; } ?>">
                    <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == basename($custom_link_uri)) { echo "active"; } ?>">
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
