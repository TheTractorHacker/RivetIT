<!-- Main Sidebar Container (AdminLTE 4). data-bs-theme="dark" keeps the sidebar dark in both app themes. -->
<aside class="app-sidebar shadow d-print-none" data-bs-theme="dark">

    <div class="sidebar-brand">
        <a class="brand-link" href="/agent/dashboard.php">
            <?php if (!empty($session_company_logo)) { ?>
                <img src="/uploads/settings/<?php echo nullable_htmlentities($session_company_logo); ?>" class="brand-image" style="max-height:33px;width:auto;object-fit:contain;" alt="">
            <?php } else { ?>
                <div class="brand-image"><i class="fas fa-building fa-2x"></i></div>
            <?php } ?>
            <span class="brand-text" title="<?php echo nullable_htmlentities($session_company_name); ?>"><?php echo nullable_htmlentities($session_company_name); ?></span>
        </a>
    </div>

    <!-- Sidebar -->
    <div class="sidebar-wrapper">

        <?php
        // Current page, used below to (a) mark individual links active (unchanged,
        // pre-existing pattern) and (b) decide which collapsible section should
        // start expanded, so navigating never lands you inside a collapsed group
        // hiding the very page you're on.
        $current_page = basename($_SERVER["PHP_SELF"]);
        $in_reports_section = (strpos($_SERVER["PHP_SELF"], '/agent/reports/') !== false);

        $section_pages = [
            'organization'  => ['clients.php'],
            'crm'           => ['pipeline.php', 'opportunities.php', 'campaigns.php', 'segments.php'],
            'service_desk'  => ['tickets.php', 'ticket.php', 'recurring_tickets.php', 'service_catalog.php', 'csat.php', 'mail_requests.php', 'problems.php', 'problem_details.php', 'changes.php', 'change_details.php'],
            'work'          => ['projects.php', 'project_details.php', 'calendar.php'],
            'knowledge'     => ['kb_articles.php', 'kb_article.php', 'kb_article_versions.php', 'credentials.php'],
            'infrastructure'=> ['locations.php', 'software.php', 'domains.php', 'certificates.php'],
            'billing'       => ['quotes.php', 'quote.php', 'invoices.php', 'invoice.php', 'recurring_invoices.php', 'recurring_invoice.php', 'revenues.php', 'products.php'],
            'finance'       => ['payments.php', 'vendors.php', 'expenses.php', 'recurring_expenses.php', 'accounts.php', 'transfers.php', 'trips.php'],
            'endpoint'      => ['intune_devices.php', 'rmm_dashboard.php', 'rmm_assets.php', 'rmm_asset.php', 'rmm_alerts.php', 'rmm_scripts.php', 'rmm_checks.php', 'network.php', 'firewalls.php'],
            'backups'       => ['backups.php'],
        ];
        $section_open = [];
        foreach ($section_pages as $key => $pages) {
            $section_open[$key] = in_array($current_page, $pages, true);
        }
        ?>

        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false" role="menu">
                <li class="nav-item">
                    <a href="/agent/dashboard.php" class="nav-link <?php if ($current_page == "dashboard.php") { echo "active"; } ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                <?php if (lookupUserPermission("module_rmm_alerts") >= 1) { ?>
                <li class="nav-item">
                    <a href="/agent/alerts.php" class="nav-link <?php if ($current_page == "alerts.php") { echo "active"; } ?>">
                        <i class="nav-icon fas fa-bell"></i>
                        <p>
                            Alerts
                            <?php
                            $num_central_alerts = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
                                (SELECT COUNT(*) FROM rmm_alerts WHERE status='new') +
                                (SELECT COUNT(*) FROM comet_backup_alerts WHERE alert_status='new') AS c"))['c'] ?? 0);
                            if ($num_central_alerts) { ?>
                                <span class="right badge text-bg-danger" data-bs-toggle="tooltip" title="Open Alerts"><?php echo $num_central_alerts; ?></span>
                            <?php } ?>
                        </p>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_client") >= 1) { ?>
                <li class="nav-item mt-2<?php echo $section_open['organization'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-sitemap"></i>
                        <p>Organization<i class="nav-arrow fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/agent/clients.php" class="nav-link <?php if ($current_page == "clients.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-users"></i>
                                <p>
                                    Departments
                                    <?php if ($num_active_clients) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Active Departments"><?php echo $num_active_clients; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_crm == 1 && lookupUserPermission("module_sales") >= 1) { ?>
                <li class="nav-item mt-2<?php echo $section_open['crm'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-funnel-dollar"></i>
                        <p>CRM<i class="nav-arrow fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/agent/pipeline.php" class="nav-link <?php if ($current_page == "pipeline.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-stream"></i>
                                <p>Pipeline</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/opportunities.php" class="nav-link <?php if ($current_page == "opportunities.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-funnel-dollar"></i>
                                <p>Opportunities</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/campaigns.php" class="nav-link <?php if ($current_page == "campaigns.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-paper-plane"></i>
                                <p>Campaigns</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/segments.php" class="nav-link <?php if ($current_page == "segments.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-layer-group"></i>
                                <p>Segments</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_support") >= 1 && $config_module_enable_ticketing == 1) { ?>
                <li class="nav-item mt-2<?php echo $section_open['service_desk'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-life-ring"></i>
                        <p>
                            Service Desk
                            <?php if ($num_active_tickets) { ?>
                                <span class="right badge text-light" data-bs-toggle="tooltip" title="Open Tickets"><?php echo $num_active_tickets; ?></span>
                            <?php } ?>
                            <i class="nav-arrow fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/agent/tickets.php" class="nav-link <?php if ($current_page == "tickets.php" || $current_page == "ticket.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-life-ring"></i>
                                <p>
                                    Tickets
                                    <?php if ($num_active_tickets) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Open Tickets"><?php echo $num_active_tickets; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/recurring_tickets.php" class="nav-link <?php if ($current_page == "recurring_tickets.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-redo-alt"></i>
                                <p>
                                    Recurring Tickets
                                    <?php if ($num_recurring_tickets) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Active Recurring Tickets"><?php echo $num_recurring_tickets; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/service_catalog.php" class="nav-link <?php if ($current_page == "service_catalog.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-th-large"></i>
                                <p>Request Something</p>
                            </a>
                        </li>
                        <?php if (!empty($config_ticket_csat_enable)) { ?>
                        <li class="nav-item">
                            <a href="/agent/csat.php" class="nav-link <?php if ($current_page == "csat.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-star"></i>
                                <p>CSAT Ratings</p>
                            </a>
                        </li>
                        <?php } ?>
                        <li class="nav-item">
                            <a href="/agent/mail_requests.php" class="nav-link <?php if ($current_page == "mail_requests.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-envelope-open-text"></i>
                                <p>
                                    Requests
                                    <?php if ($num_mail_requests) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Unknown-sender emails awaiting review"><?php echo $num_mail_requests; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/problems.php" class="nav-link <?php if ($current_page == "problems.php" || $current_page == "problem_details.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-exclamation-circle"></i>
                                <p>Problems</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/changes.php" class="nav-link <?php if ($current_page == "changes.php" || $current_page == "change_details.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-exchange-alt"></i>
                                <p>Changes</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <li class="nav-item mt-2<?php echo $section_open['work'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-calendar-alt"></i>
                        <p>Work<i class="nav-arrow fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (lookupUserPermission("module_support") >= 1 && $config_module_enable_ticketing == 1) { ?>
                        <li class="nav-item">
                            <a href="/agent/projects.php" class="nav-link <?php if ($current_page == "projects.php" || $current_page == "project_details.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-project-diagram"></i>
                                <p>
                                    Projects
                                    <?php if ($num_active_projects) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Open Projects"><?php echo $num_active_projects; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <?php } ?>
                        <li class="nav-item">
                            <a href="/agent/calendar.php" class="nav-link <?php if ($current_page == "calendar.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-calendar-alt"></i>
                                <p>Calendar</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <?php if (($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") >= 1 && lookupUserPermission("module_credential") >= 1) || ($config_module_enable_kb == 1 && lookupUserPermission("module_kb") >= 1)) { ?>
                <li class="nav-item mt-2<?php echo $section_open['knowledge'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-book"></i>
                        <p>Knowledge<i class="nav-arrow fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if ($config_module_enable_kb == 1 && lookupUserPermission("module_kb") >= 1) { ?>
                        <li class="nav-item">
                            <a href="/agent/kb_articles.php" class="nav-link <?php if ($current_page == "kb_articles.php" || $current_page == "kb_article.php" || $current_page == "kb_article_versions.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-book"></i>
                                <p>Knowledge Base</p>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") >= 1 && lookupUserPermission("module_credential") >= 1) { ?>
                        <li class="nav-item">
                            <a href="/agent/credentials.php" class="nav-link <?php if ($current_page == "credentials.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-key"></i>
                                <p>
                                    Credentials
                                    <?php if ($num_credentials_all) { ?>
                                        <span class="right badge text-light"><?php echo $num_credentials_all; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_itdoc == 1 && lookupUserPermission("module_support") >= 1) { ?>
                <li class="nav-item mt-2<?php echo $section_open['infrastructure'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-server"></i>
                        <p>Infrastructure<i class="nav-arrow fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/agent/locations.php" class="nav-link <?php if ($current_page == "locations.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-map-marker-alt"></i>
                                <p>
                                    Locations
                                    <?php if ($num_locations_all) { ?>
                                        <span class="right badge text-light"><?php echo $num_locations_all; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/software.php" class="nav-link <?php if ($current_page == "software.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-cube"></i>
                                <p>
                                    Licenses
                                    <?php if ($num_software_all) { ?>
                                        <span class="right badge text-light"><?php echo $num_software_all; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/domains.php" class="nav-link <?php if ($current_page == "domains.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-globe"></i>
                                <p>
                                    Domains
                                    <?php if ($num_domains_all) { ?>
                                        <span class="right badge text-light"><?php echo $num_domains_all; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/certificates.php" class="nav-link <?php if ($current_page == "certificates.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-lock"></i>
                                <p>
                                    Certificates
                                    <?php if ($num_certificates_all) { ?>
                                        <span class="right badge text-light"><?php echo $num_certificates_all; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_accounting == 1 && lookupUserPermission("module_sales") >= 1) { ?>
                <li class="nav-item mt-2<?php echo $section_open['billing'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-file-invoice-dollar"></i>
                        <p>Billing<i class="nav-arrow fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/agent/quotes.php" class="nav-link <?php if ($current_page == "quotes.php" || $current_page == "quote.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-comment-dollar"></i>
                                <p>
                                    Quotes
                                    <?php if ($num_open_quotes) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Active Quotes"><?php echo $num_open_quotes; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/invoices.php" class="nav-link <?php if ($current_page == "invoices.php" || $current_page == "invoice.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-file-invoice"></i>
                                <p>
                                    Invoices
                                    <?php if ($num_open_invoices) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Open Invoices"><?php echo $num_open_invoices; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/recurring_invoices.php" class="nav-link <?php if ($current_page == "recurring_invoices.php" || $current_page == "recurring_invoice.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-redo-alt"></i>
                                <p>
                                    Recurring Invoices
                                    <?php if ($num_recurring_invoices) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Active Recurring Invoices"><?php echo $num_recurring_invoices; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/revenues.php" class="nav-link <?php if ($current_page == "revenues.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-hand-holding-usd"></i>
                                <p>Revenues</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/products.php" class="nav-link <?php if ($current_page == "products.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-box-open"></i>
                                <p>Products</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } elseif ($config_module_enable_ticket_charges == 1 && lookupUserPermission("module_sales") >= 1) { ?>
                <li class="nav-item">
                    <a href="/agent/products.php" class="nav-link <?php if ($current_page == "products.php") { echo "active"; } ?>">
                        <i class="nav-icon fas fa-box-open"></i>
                        <p>Products</p>
                    </a>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_accounting == 1) { ?>
                <li class="nav-item mt-2<?php echo $section_open['finance'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-piggy-bank"></i>
                        <p>Finance<i class="nav-arrow fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if (lookupUserPermission("module_financial") >= 1) { ?>
                        <li class="nav-item">
                            <a href="/agent/payments.php" class="nav-link <?php if ($current_page == "payments.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-credit-card"></i>
                                <p>Payments</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/vendors.php" class="nav-link <?php if ($current_page == "vendors.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-building"></i>
                                <p>Vendors</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/expenses.php" class="nav-link <?php if ($current_page == "expenses.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-shopping-cart"></i>
                                <p>Expenses</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/recurring_expenses.php" class="nav-link <?php if ($current_page == "recurring_expenses.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-redo-alt"></i>
                                <p>
                                    Recurring Expenses
                                    <?php if ($num_recurring_expenses) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Recurring Expenses"><?php echo $num_recurring_expenses; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/accounts.php" class="nav-link <?php if ($current_page == "accounts.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-piggy-bank"></i>
                                <p>Accounts</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/transfers.php" class="nav-link <?php if ($current_page == "transfers.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-exchange-alt"></i>
                                <p>Transfers</p>
                            </a>
                        </li>
                        <?php if ($config_module_enable_payroll && $session_is_admin) { ?>
                        <li class="nav-item">
                            <a href="/admin/payroll_periods.php" class="nav-link">
                                <i class="nav-icon fas fa-money-check"></i>
                                <p>Payroll</p>
                            </a>
                        </li>
                        <?php } ?>
                        <?php } ?>
                        <li class="nav-item">
                            <a href="/agent/trips.php" class="nav-link <?php if ($current_page == "trips.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-route"></i>
                                <p>Trips</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <?php if (($config_module_enable_rmm && lookupUserPermission("module_rmm") >= 1) || $config_module_enable_intune) { ?>
                <li class="nav-item mt-2<?php echo $section_open['endpoint'] ? ' menu-open' : ''; ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-desktop"></i>
                        <p>Endpoints<i class="nav-arrow fas fa-angle-left"></i></p>
                    </a>
                    <ul class="nav nav-treeview">
                        <?php if ($config_module_enable_intune) { ?>
                        <li class="nav-item">
                            <a href="/agent/intune_devices.php" class="nav-link <?php if ($current_page == "intune_devices.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-laptop"></i>
                                <p>Intune Devices</p>
                            </a>
                        </li>
                        <?php } ?>
                        <?php if ($config_module_enable_rmm && lookupUserPermission("module_rmm") >= 1) { ?>
                        <li class="nav-item">
                            <a href="/agent/rmm_dashboard.php" class="nav-link <?php if ($current_page == 'rmm_dashboard.php') { echo 'active'; } ?>">
                                <i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>RMM Dashboard</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/rmm_assets.php" class="nav-link <?php if (in_array($current_page, ['rmm_assets.php','rmm_asset.php'])) { echo 'active'; } ?>">
                                <i class="nav-icon fas fa-desktop"></i>
                                <p>Assets</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/rmm_alerts.php" class="nav-link <?php if ($current_page == 'rmm_alerts.php') { echo 'active'; } ?>">
                                <i class="nav-icon fas fa-bell"></i>
                                <p>RMM Alerts</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/rmm_scripts.php" class="nav-link <?php if ($current_page == 'rmm_scripts.php') { echo 'active'; } ?>">
                                <i class="nav-icon fas fa-code"></i>
                                <p>Scripts</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/rmm_checks.php" class="nav-link <?php if ($current_page == 'rmm_checks.php') { echo 'active'; } ?>">
                                <i class="nav-icon fas fa-heartbeat"></i>
                                <p>Check Policies</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/network.php" class="nav-link <?php if (in_array($current_page, ['network.php','firewalls.php'])) { echo 'active'; } ?>">
                                <i class="nav-icon fas fa-network-wired"></i>
                                <p>Network</p>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                </li>
                <?php } ?>

                <?php if (!empty($config_comet_enabled) && lookupUserPermission("module_rmm") >= 1) { ?>
                <li class="nav-item mt-3">
                    <a href="/agent/backups.php" class="nav-link <?php if ($current_page == 'backups.php') { echo 'active'; } ?>">
                        <i class="nav-icon fas fa-cloud-upload-alt"></i>
                        <p>Backups</p>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_client") >= 1) { ?>
                <li class="nav-item mt-3">
                    <a href="/agent/contacts.php" class="nav-link">
                        <i class="fas fa-users nav-icon"></i>
                        <p>People</p>
                        <i class="fas fa-angle-right nav-icon float-end"></i>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_reporting") >= 1) { ?>
                    <li class="nav-item<?php echo (lookupUserPermission("module_client") >= 1) ? '' : ' mt-3'; ?>">
                        <a href="/agent/reports/" class="nav-link <?php if ($in_reports_section) { echo "active"; } ?>">
                            <i class="fas fa-chart-line nav-icon"></i>
                            <p>Reports</p>
                            <i class="fas fa-angle-right nav-icon float-end"></i>
                        </a>
                    </li>
                <?php } ?>

                <?php
                $sql_custom_links = mysqli_query($mysqli, "SELECT * FROM custom_links WHERE custom_link_location = 1 AND custom_link_archived_at IS NULL
                    ORDER BY custom_link_order ASC, custom_link_name ASC"
                );

                while ($row = mysqli_fetch_assoc($sql_custom_links)) {
                    $custom_link_name = nullable_htmlentities($row['custom_link_name']);
                    $custom_link_uri = sanitize_url($row['custom_link_uri']);
                    $custom_link_icon = nullable_htmlentities($row['custom_link_icon']);
                    $custom_link_new_tab = intval($row['custom_link_new_tab']);
                    if ($custom_link_new_tab == 1) {
                        $target = "target='_blank' rel='noopener noreferrer'";
                    } else {
                        $target = "";
                    }

                    ?>

                <li class="nav-item">
                    <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link <?php if ($current_page == basename($custom_link_uri)) { echo "active"; } ?>">
                        <i class="fas fa-<?php echo $custom_link_icon; ?> nav-icon"></i>
                        <p><?php echo $custom_link_name; ?></p>
                        <i class="fas fa-angle-right nav-icon float-end"></i>
                    </a>
                </li>

                <?php } ?>

            </ul>
        </nav>
        <!-- /.sidebar-menu -->

        <div class="mb-3"></div>

    </div>
    <!-- /.sidebar-wrapper -->

</aside>
