<!-- Main Sidebar Container (AdminLTE 4). data-bs-theme="dark" keeps the sidebar dark in both app themes. -->
<aside class="app-sidebar shadow d-print-none" data-bs-theme="dark">

    <div class="sidebar-brand">
        <a class="brand-link" href="/agent/dashboard.php">
            <?php if (!empty($session_company_logo)) { ?>
                <img src="/uploads/settings/<?php echo nullable_htmlentities($session_company_logo); ?>" class="brand-image" style="max-height:33px;width:auto;object-fit:contain;" alt="">
            <?php } else { ?>
                <div class="brand-image"><i class="fas fa-building fa-2x"></i></div>
            <?php } ?>
            <span class="brand-text"><?php echo nullable_htmlentities($session_company_name); ?></span>
        </a>
    </div>

    <!-- Sidebar -->
    <div class="sidebar-wrapper">

        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false" role="menu">
                <li class="nav-item">
                    <a href="/agent/dashboard.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "dashboard.php") { echo "active"; } ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                <?php if (lookupUserPermission("module_rmm_alerts") >= 1) { ?>
                <li class="nav-item">
                    <a href="/agent/alerts.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "alerts.php") { echo "active"; } ?>">
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
                    <li class="nav-item">
                        <a href="/agent/clients.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "clients.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-users"></i>
                            <p>
                                Clients
                                <?php if ($num_active_clients) { ?>
                                    <span class="right badge text-light" data-bs-toggle="tooltip" title="Active Clients"><?php echo $num_active_clients; ?></span>
                                <?php } ?>
                            </p>
                        </a>
                    </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_sales") >= 1) { ?>
                    <li class="nav-header mt-3">CRM</li>
                    <li class="nav-item">
                        <a href="/agent/pipeline.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "pipeline.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-stream"></i>
                            <p>Pipeline</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="/agent/opportunities.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "opportunities.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-funnel-dollar"></i>
                            <p>Opportunities</p>
                        </a>
                    </li>
                    <?php if (lookupUserPermission("module_client") >= 1) { ?>
                    <li class="nav-item">
                        <a href="/agent/clients.php?leads=1" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "clients.php" && isset($_GET['leads']) && $_GET['leads'] == 1) { echo "active"; } ?>">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Leads</p>
                        </a>
                    </li>
                    <?php } ?>
                    <li class="nav-item">
                        <a href="/agent/campaigns.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "campaigns.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-paper-plane"></i>
                            <p>Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="/agent/segments.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "segments.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-layer-group"></i>
                            <p>Segments</p>
                        </a>
                    </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_support") >= 1) { ?>
                    <?php if ($config_module_enable_ticketing == 1) { ?>
                        <li class="nav-header mt-3">SUPPORT</li>
                        <li class="nav-item">
                            <a href="/agent/tickets.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "tickets.php" || basename($_SERVER["PHP_SELF"]) == "ticket.php") { echo "active"; } ?>">
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
                            <a href="/agent/recurring_tickets.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "recurring_tickets.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-redo-alt"></i>
                                <p>
                                    Recurring Tickets
                                    <?php if ($num_recurring_tickets) { ?>
                                        <span class="right badge text-light" data-bs-toggle="tooltip" title="Active Recurring Tickets"><?php echo $num_recurring_tickets; ?></span>
                                    <?php } ?>
                                </p>
                            </a>
                        </li>
                        <?php if (!empty($config_ticket_csat_enable)) { ?>
                        <li class="nav-item">
                            <a href="/agent/csat.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "csat.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-star"></i>
                                <p>CSAT Ratings</p>
                            </a>
                        </li>
                        <?php } ?>
                        <li class="nav-item">
                            <a href="/agent/mail_requests.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "mail_requests.php") { echo "active"; } ?>">
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
                            <a href="/agent/projects.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "projects.php" || basename($_SERVER["PHP_SELF"]) == "project_details.php") { echo "active"; } ?>">
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
                <?php } ?>

                <li class="nav-item">
                    <a href="/agent/calendar.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "calendar.php") { echo "active"; } ?>">
                        <i class="nav-icon fas fa-calendar-alt"></i>
                        <p>Calendar</p>
                    </a>
                </li>

                <?php if ($config_module_enable_kb == 1 && lookupUserPermission("module_kb") >= 1) { ?>
                    <li class="nav-item">
                        <a href="/agent/kb_articles.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "kb_articles.php" || basename($_SERVER["PHP_SELF"]) == "kb_article.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-book"></i>
                            <p>Knowledge Base</p>
                        </a>
                    </li>
                <?php } ?>

                <?php if ($config_module_enable_accounting == 1 && lookupUserPermission("module_sales") >= 1) { ?>
                    <li class="nav-header mt-3">BILLING</li>
                    <li class="nav-item">
                        <a href="/agent/quotes.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "quotes.php" || basename($_SERVER["PHP_SELF"]) == "quote.php") { echo "active"; } ?>">
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
                        <a href="/agent/invoices.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "invoices.php" || basename($_SERVER["PHP_SELF"]) == "invoice.php") { echo "active"; } ?>">
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
                        <a href="/agent/recurring_invoices.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "recurring_invoices.php" || basename($_SERVER["PHP_SELF"]) == "recurring_invoice.php") { echo "active"; } ?>">
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
                        <a href="/agent/revenues.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "revenues.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-hand-holding-usd"></i>
                            <p>Revenues</p>
                        </a>
                    </li>
                <?php } ?>

                <?php if (($config_module_enable_accounting == 1 || $config_module_enable_ticket_charges == 1) && lookupUserPermission("module_sales") >= 1) { ?>
                    <?php if ($config_module_enable_accounting != 1) { ?>
                    <li class="nav-header mt-3">BILLING</li>
                    <?php } ?>
                    <li class="nav-item">
                        <a href="/agent/products.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "products.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-box-open"></i>
                            <p>Products</p>
                        </a>
                    </li>
                <?php } ?>

                <?php if ($config_module_enable_accounting == 1) { ?>
                    <li class="nav-header mt-3">FINANCE</li>
                    <?php if (lookupUserPermission("module_financial") >= 1) { ?>
                        <li class="nav-item">
                            <a href="/agent/payments.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "payments.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-credit-card"></i>
                                <p>Payments</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/vendors.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "vendors.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-building"></i>
                                <p>Vendors</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/expenses.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "expenses.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-shopping-cart"></i>
                                <p>Expenses</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/recurring_expenses.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "recurring_expenses.php") { echo "active"; } ?>">
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
                            <a href="/agent/accounts.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "accounts.php") { echo "active"; } ?>">
                                <i class="nav-icon fas fa-piggy-bank"></i>
                                <p>Accounts</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/agent/transfers.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "transfers.php") { echo "active"; } ?>">
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
                        <a href="/agent/trips.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "trips.php") { echo "active"; } ?>">
                            <i class="nav-icon fas fa-route"></i>
                            <p>Trips</p>
                        </a>
                    </li>
                <?php } ?>

                <?php if ($config_module_enable_rmm && lookupUserPermission("module_rmm") >= 1) { ?>
                <li class="nav-header mt-3">RMM</li>
                <li class="nav-item">
                    <a href="/agent/rmm_dashboard.php" class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == 'rmm_dashboard.php') { echo 'active'; } ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>RMM Dashboard</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/agent/rmm_assets.php" class="nav-link <?php if (in_array(basename($_SERVER['PHP_SELF']), ['rmm_assets.php','rmm_asset.php'])) { echo 'active'; } ?>">
                        <i class="nav-icon fas fa-desktop"></i>
                        <p>Assets</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/agent/rmm_alerts.php" class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == 'rmm_alerts.php') { echo 'active'; } ?>">
                        <i class="nav-icon fas fa-bell"></i>
                        <p>RMM Alerts</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/agent/rmm_scripts.php" class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == 'rmm_scripts.php') { echo 'active'; } ?>">
                        <i class="nav-icon fas fa-code"></i>
                        <p>Scripts</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/agent/rmm_checks.php" class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == 'rmm_checks.php') { echo 'active'; } ?>">
                        <i class="nav-icon fas fa-heartbeat"></i>
                        <p>Check Policies</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/agent/network.php" class="nav-link <?php if (in_array(basename($_SERVER['PHP_SELF']), ['network.php','firewalls.php'])) { echo 'active'; } ?>">
                        <i class="nav-icon fas fa-network-wired"></i>
                        <p>Network</p>
                    </a>
                </li>
                <?php } ?>

                <?php if (!empty($config_comet_enabled) && lookupUserPermission("module_rmm") >= 1) { ?>
                <li class="nav-header mt-3">Backups</li>
                <li class="nav-item">
                    <a href="/agent/backups.php" class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == 'backups.php') { echo 'active'; } ?>">
                        <i class="nav-icon fas fa-cloud-upload-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_client") >= 1) { ?>
                <li class="nav-item mt-3">
                    <a href="/agent/contacts.php" class="nav-link">
                        <i class="fas fa-users nav-icon"></i>
                        <p>Client Overview</p>
                        <i class="fas fa-angle-right nav-icon float-end"></i>
                    </a>
                </li>
                <?php } ?>

                <?php if (lookupUserPermission("module_reporting") >= 1) { ?>
                    <li class="nav-item mt-3">
                        <a href="/agent/reports/" class="nav-link">
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
                    <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == basename($custom_link_uri)) { echo "active"; } ?>">
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
