<?php
// Current page, used below for active-item detection (same basename() test as before).
$current_page = basename($_SERVER["PHP_SELF"]);
?>
<!-- Reports Sidebar (Tabler vertical navbar).
     data-bs-theme="dark" keeps the sidebar dark in both app themes, exactly as the
     AdminLTE shell did.

     Was AdminLTE 3's aside.main-sidebar > div.sidebar > ul.nav-sidebar with
     data-widget="treeview" (a widget with no handler - this list is flat, so nothing
     was lost). Now the same Tabler shape the agent/admin sidebars use:

       aside.navbar.navbar-vertical.navbar-expand-lg > .container-fluid
         > button.navbar-toggler + .navbar-brand + .collapse.navbar-collapse#sidebar-menu
           > ul.navbar-nav

     Internally balanced: exactly one <aside> opened and closed, ZERO structural
     depth added, so includes/footer.php's four-level close is unaffected.

     Section headings are <li class="nav-item nav-section-title">, the same hook
     admin/includes/side_nav.php now uses (formerly AdminLTE's .nav-header).

     No collapsible groups here, so no data-if-toggle="submenu" hooks; the only JS
     needed is Bootstrap's own collapse data-api on the mobile toggler. -->
<aside class="navbar navbar-vertical navbar-expand-lg d-print-none" data-bs-theme="dark">
    <div class="container-fluid">

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Brand area. The back link keeps its own .section-nav-back styling from
             css/itflow_bs5_bridge.css, so the brand box contributes no padding of its
             own (p-0) and lets the link fill it (w-100). -->
        <div class="navbar-brand p-0 w-100">
            <a class="section-nav-back" href="<?php echo nullable_htmlentities(itflow_home_url()); // start page; a module-only login's own home (roles audit P0) ?>">
                <i class="fas fa-arrow-left"></i> Reports
            </a>
        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2">

                <?php if ($config_module_enable_accounting == 1 && lookupUserPermission("module_financial") >= 1) { ?>
                    <li class="nav-item nav-section-title">FINANCIAL</li>
                    <li class="nav-item<?php if ($current_page == "income_summary.php") { echo " active"; } ?>">
                        <a href="/agent/reports/income_summary.php" class="nav-link<?php if ($current_page == "income_summary.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="far fa-circle"></i></span>
                            <span class="nav-link-title">Income</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "income_by_client.php") { echo " active"; } ?>">
                        <a href="/agent/reports/income_by_client.php" class="nav-link<?php if ($current_page == "income_by_client.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="far fa-user"></i></span>
                            <span class="nav-link-title">Income By Department</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "recurring_by_client.php") { echo " active"; } ?>">
                        <a href="/agent/reports/recurring_by_client.php" class="nav-link<?php if ($current_page == "recurring_by_client.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fa fa-sync"></i></span>
                            <span class="nav-link-title">Recurring Income By Department</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "mrr.php") { echo " active"; } ?>">
                        <a href="/agent/reports/mrr.php" class="nav-link<?php if ($current_page == "mrr.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-sync-alt"></i></span>
                            <span class="nav-link-title">MRR &amp; Forecast</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "clients_with_balance.php") { echo " active"; } ?>">
                        <a href="/agent/reports/clients_with_balance.php" class="nav-link<?php if ($current_page == "clients_with_balance.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fa fa-exclamation-triangle"></i></span>
                            <span class="nav-link-title">Departments with a Balance</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "expense_summary.php") { echo " active"; } ?>">
                        <a href="/agent/reports/expense_summary.php" class="nav-link<?php if ($current_page == "expense_summary.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="far fa-credit-card"></i></span>
                            <span class="nav-link-title">Expense</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "expense_by_vendor.php") { echo " active"; } ?>">
                        <a href="/agent/reports/expense_by_vendor.php" class="nav-link<?php if ($current_page == "expense_by_vendor.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="far fa-building"></i></span>
                            <span class="nav-link-title">Expense By Vendor</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "tax_summary.php") { echo " active"; } ?>">
                        <a href="/agent/reports/tax_summary.php" class="nav-link<?php if ($current_page == "tax_summary.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-percent"></i></span>
                            <span class="nav-link-title">Tax Summary</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "profit_loss.php") { echo " active"; } ?>">
                        <a href="/agent/reports/profit_loss.php" class="nav-link<?php if ($current_page == "profit_loss.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span class="nav-link-title">Profit &amp; Loss</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "budget.php") { echo " active"; } ?>">
                        <a href="/agent/reports/budget.php" class="nav-link<?php if ($current_page == "budget.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-calculator"></i></span>
                            <span class="nav-link-title">Annual Budget</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "tickets_unbilled.php") { echo " active"; } ?>">
                        <a href="/agent/reports/tickets_unbilled.php" class="nav-link<?php if ($current_page == "tickets_unbilled.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-file-invoice"></i></span>
                            <span class="nav-link-title">Unbilled Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "client_ticket_time_detail.php") { echo " active"; } ?>">
                        <a href="/agent/reports/client_ticket_time_detail.php" class="nav-link<?php if ($current_page == "client_ticket_time_detail.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-history"></i></span>
                            <span class="nav-link-title">Department Time Detail Audit</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "included_issues.php") { echo " active"; } ?>">
                        <a href="/agent/reports/included_issues.php" class="nav-link<?php if ($current_page == "included_issues.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-house-user"></i></span>
                            <span class="nav-link-title">Included Support Issues</span>
                        </a>
                    </li>

                <?php } // End financial reports IF statement ?>


                <?php if (($config_module_enable_ticketing && lookupUserPermission("module_support") >= 1) || lookupUserPermission("module_credential") >= 1) { ?>
                    <li class="nav-item nav-section-title">TECHNICAL</li>
                <?php } ?>
                <?php if ($config_module_enable_ticketing && lookupUserPermission("module_support") >= 1) { ?>
                    <li class="nav-item<?php if ($current_page == "service_desk.php") { echo " active"; } ?>">
                        <a href="/agent/reports/service_desk.php" class="nav-link<?php if ($current_page == "service_desk.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-headset"></i></span>
                            <span class="nav-link-title">Service Desk &amp; SLA</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "ticket_summary.php") { echo " active"; } ?>">
                        <a href="/agent/reports/ticket_summary.php" class="nav-link<?php if ($current_page == "ticket_summary.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-life-ring"></i></span>
                            <span class="nav-link-title">Tickets</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "ticket_day_breakdown.php") { echo " active"; } ?>">
                        <a href="/agent/reports/ticket_day_breakdown.php" class="nav-link<?php if ($current_page == "ticket_day_breakdown.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-calendar-day"></i></span>
                            <span class="nav-link-title">Tickets: Day by Day</span>
                        </a>
                    </li>
                    <?php if ($config_module_enable_ticket_charges) { ?>
                    <li class="nav-item<?php if ($current_page == "ticket_charges.php") { echo " active"; } ?>">
                        <a href="/agent/reports/ticket_charges.php" class="nav-link<?php if ($current_page == "ticket_charges.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-dollar-sign"></i></span>
                            <span class="nav-link-title">Ticket Charges</span>
                        </a>
                    </li>
                    <?php } ?>

                    <li class="nav-item<?php if ($current_page == "ticket_by_client.php") { echo " active"; } ?>">
                        <a href="/agent/reports/ticket_by_client.php" class="nav-link<?php if ($current_page == "ticket_by_client.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-users"></i></span>
                            <span class="nav-link-title">Tickets by Department</span>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "time_by_tech.php") { echo " active"; } ?>">
                        <a href="/agent/reports/time_by_tech.php" class="nav-link<?php if ($current_page == "time_by_tech.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-business-time"></i></span>
                            <span class="nav-link-title">Time by Technician</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "technician_performance.php") { echo " active"; } ?>">
                        <a href="/agent/reports/technician_performance.php" class="nav-link<?php if ($current_page == "technician_performance.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-user-clock"></i></span>
                            <span class="nav-link-title">Technician Performance</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "csat.php") { echo " active"; } ?>">
                        <a href="/agent/reports/csat.php" class="nav-link<?php if ($current_page == "csat.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-star"></i></span>
                            <span class="nav-link-title">Customer Satisfaction</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "rmm_health.php") { echo " active"; } ?>">
                        <a href="/agent/reports/rmm_health.php" class="nav-link<?php if ($current_page == "rmm_health.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-heartbeat"></i></span>
                            <span class="nav-link-title">RMM Health</span>
                        </a>
                    </li>
                <?php } ?>
                <?php if (lookupUserPermission("module_credential") >= 1) { ?>
                    <li class="nav-item<?php if ($current_page == "credential_rotation.php") { echo " active"; } ?>">
                        <a href="/agent/reports/credential_rotation.php" class="nav-link<?php if ($current_page == "credential_rotation.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-key"></i></span>
                            <span class="nav-link-title">Credential rotation</span>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "credential_rotation_v2.php") { echo " active"; } ?>">
                        <a href="/agent/reports/credential_rotation_v2.php" class="nav-link<?php if ($current_page == "credential_rotation_v2.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-history"></i></span>
                            <span class="nav-link-title">Credential rotation due</span>
                        </a>
                    </li>
                <?php } ?>

                <li class="nav-item nav-section-title">DELIVERY</li>
                <li class="nav-item<?php if ($current_page == "schedules.php") { echo " active"; } ?>">
                    <a href="/agent/reports/schedules.php" class="nav-link<?php if ($current_page == "schedules.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-paper-plane"></i></span>
                        <span class="nav-link-title">Scheduled Reports</span>
                    </a>
                </li>

                <?php
                $sql_custom_links = mysqli_query($mysqli, "SELECT * FROM custom_links
                    WHERE custom_link_location = 5 AND custom_link_archived_at IS NULL
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

                <li class="nav-item<?php if ($current_page == basename($custom_link_uri)) { echo " active"; } ?>">
                    <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link<?php if ($current_page == basename($custom_link_uri)) { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-<?php echo $custom_link_icon; ?>"></i></span>
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
