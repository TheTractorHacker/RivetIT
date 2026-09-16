<?php
// Current page, used below for active-item detection. Same basename() test the
// AdminLTE-generation markup used, hoisted into one variable so every link reads
// the same way as the migrated agent/admin sidebars.
$current_page = basename($_SERVER["PHP_SELF"]);
?>
<!-- Client-context Sidebar (Tabler vertical navbar).
     data-bs-theme="dark" keeps the sidebar dark in both app themes, exactly as the
     AdminLTE shell did.

     This file used to emit AdminLTE 3's aside.main-sidebar > div.sidebar >
     ul.nav-sidebar with data-widget="treeview". AdminLTE 4 styled none of that; its
     whole appearance came from hand-written rules in css/itflow_bs5_bridge.css, and
     the treeview widget had no handler at all (no submenus here, so nothing was
     lost). It now uses the same Tabler shape as includes/../agent/includes/side_nav.php
     and admin/includes/side_nav.php:

       aside.navbar.navbar-vertical.navbar-expand-lg > .container-fluid
         > button.navbar-toggler + .navbar-brand + .collapse.navbar-collapse#sidebar-menu
           > ul.navbar-nav

     The element is internally balanced - it opens and closes exactly one <aside> and
     adds ZERO structural depth, so includes/footer.php's four-level close is untouched.

     There are no collapsible groups in this sidebar, so it carries no
     data-if-toggle="submenu" hooks; the only JS it needs is Bootstrap's own collapse
     data-api on the mobile toggler (bootstrap.bundle.min.js, already loaded).

     Every permission/module gate, every badge count and the .client-nav-* identity
     block below are byte-for-byte the same tests as before - this is markup only. -->
<aside class="navbar navbar-vertical navbar-expand-lg d-print-none" data-bs-theme="dark">
    <div class="container-fluid">

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Brand area: the "All Departments" back link stacked over the client identity
             block. Both keep their existing .client-nav-back / .client-nav-header /
             .client-nav-avatar styling from css/itflow_bs5_bridge.css, so the brand box
             contributes no padding of its own (p-0) and lets the two links fill it
             (w-100 + flex-column + align-items-stretch). -->
        <div class="navbar-brand p-0 w-100 flex-column align-items-stretch">

            <a class="client-nav-back" href="/agent/clients.php">
                <i class="fas fa-arrow-left"></i> All Departments
            </a>

            <a class="client-nav-header" href="/agent/client_overview.php?client_id=<?php echo $client_id; ?>" title="<?php echo $client_name; ?>">
                <span class="client-nav-avatar"><?php echo nullable_htmlentities(mb_strtoupper(mb_substr($client_abbreviation ?: $client_name, 0, 2))); ?></span>
                <span class="client-nav-identity">
                    <span class="client-nav-name"><?php echo $client_name; ?></span>
                    <?php if (!empty($client_type)) { ?><span class="client-nav-type"><?php echo $client_type; ?></span><?php } ?>
                </span>
            </a>

        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2">

                <li class="nav-item<?php if ($current_page == "client_overview.php") { echo " active"; } ?>">
                    <a href="/agent/client_overview.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "client_overview.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <span class="nav-link-title">Overview</span>
                    </a>
                </li>

                <li class="nav-item<?php if ($current_page == "contacts.php" || $current_page == "contact_details.php") { echo " active"; } ?>">
                    <a href="/agent/contacts.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "contacts.php" || $current_page == "contact_details.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-address-book"></i></span>
                        <span class="nav-link-title">Contacts</span>
                        <?php
                        if ($num_contacts > 0) { ?>
                            <span class="ms-auto badge text-light"><?php echo $num_contacts; ?></span>
                        <?php } ?>
                    </a>
                </li>

                <li class="nav-item<?php if ($current_page == "locations.php") { echo " active"; } ?>">
                    <a href="/agent/locations.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "locations.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-map-marker-alt"></i></span>
                        <span class="nav-link-title">Locations</span>
                        <?php
                        if ($num_locations > 0) { ?>
                            <span class="ms-auto badge text-light"><?php echo $num_locations; ?></span>
                        <?php } ?>
                    </a>
                </li>

                <?php if ($config_module_enable_ticketing == 1 && lookupUserPermission("module_support") >= 1) { ?>
                    <li class="nav-item nav-section-title">SUPPORT</li>

                    <li class="nav-item<?php if ($current_page == "tickets.php" || $current_page == "ticket.php") { echo " active"; } ?>">
                        <a href="/agent/tickets.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "tickets.php" || $current_page == "ticket.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-life-ring"></i></span>
                            <span class="nav-link-title">Tickets</span>
                            <?php
                            if ($num_active_tickets > 0) { ?>
                                <span class="ms-auto badge <?php if ($num_active_tickets > 0) { ?> text-bg-danger <?php } ?> text-light"><?php echo $num_active_tickets; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "recurring_tickets.php") { echo " active"; } ?>">
                        <a href="/agent/recurring_tickets.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "recurring_tickets.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-redo-alt"></i></span>
                            <span class="nav-link-title">Recurring Tickets</span>
                            <?php
                            if ($num_recurring_tickets) { ?>
                                <span class="ms-auto badge"><?php echo $num_recurring_tickets; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "projects.php" || $current_page == "project_details.php") { echo " active"; } ?>">
                        <a href="/agent/projects.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "projects.php" || $current_page == "project_details.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-project-diagram"></i></span>
                            <span class="nav-link-title">Projects</span>
                            <?php if ($num_active_projects) { ?>
                                <span class="ms-auto badge text-light" data-bs-toggle="tooltip" title="Open Projects"><?php echo $num_active_projects; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                <?php } ?>

                <li class="nav-item<?php if ($current_page == "vendors.php") { echo " active"; } ?>">
                    <a href="/agent/vendors.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "vendors.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-building"></i></span>
                        <span class="nav-link-title">Vendors</span>
                        <?php
                        if ($num_vendors > 0) { ?>
                            <span class="ms-auto badge text-light"><?php echo $num_vendors; ?></span>
                        <?php } ?>
                    </a>
                </li>

                <li class="nav-item<?php if ($current_page == "calendar.php") { echo " active"; } ?>">
                    <a href="/agent/calendar.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "calendar.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-calendar-alt"></i></span>
                        <span class="nav-link-title">Calendar</span>
                        <?php
                        if ($num_calendar_events > 0) { ?>
                            <span class="ms-auto badge text-light"><?php echo $num_calendar_events; ?></span>
                        <?php } ?>
                    </a>
                </li>

                <?php if ($config_module_enable_kb == 1 && lookupUserPermission("module_kb") >= 1) { ?>
                    <li class="nav-item<?php if ($current_page == "kb_articles.php" || $current_page == "kb_article.php") { echo " active"; } ?>">
                        <a href="/agent/kb_articles.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "kb_articles.php" || $current_page == "kb_article.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-book"></i></span>
                            <span class="nav-link-title">Knowledge Base</span>
                        </a>
                    </li>
                <?php } ?>

                <?php if ($config_module_enable_itdoc == 1) { ?>

                    <li class="nav-item nav-section-title">DOCUMENTATION</li>

                    <?php if (lookupUserPermission("module_support") >= 1) { ?>
                        <li class="nav-item<?php if ($current_page == "assets.php" || $current_page == "client_asset_details.php") { echo " active"; } ?>">
                            <a href="/agent/assets.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "assets.php" || $current_page == "client_asset_details.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-desktop"></i></span>
                                <span class="nav-link-title">Assets</span>
                                <?php
                                if ($num_assets > 0) { ?>
                                    <span class="ms-auto badge text-light"><?php echo $num_assets; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "software.php") { echo " active"; } ?>">
                            <a href="/agent/software.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "software.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-cube"></i></span>
                                <span class="nav-link-title">Licenses</span>
                                <?php
                                if ($num_software > 0) { ?>
                                    <span class="ms-auto badge <?php if ($num_software_expiring > 0) { ?> text-bg-warning text-dark <?php } ?> <?php if ($num_software_expired > 0) { ?> text-bg-danger <?php } ?> text-white"><?php echo $num_software; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <?php if (lookupUserPermission("module_credential") >= 1) { ?>
                            <li class="nav-item<?php if ($current_page == "credentials.php") { echo " active"; } ?>">
                                <a href="/agent/credentials.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "credentials.php") { echo " active"; } ?>">
                                    <span class="nav-link-icon"><i class="fas fa-key"></i></span>
                                    <span class="nav-link-title">Credentials</span>
                                    <?php
                                    if ($num_credentials > 0) { ?>
                                        <span class="ms-auto badge text-light"><?php echo $num_credentials; ?></span>
                                    <?php } ?>
                                </a>
                            </li>
                        <?php } ?>

                        <li class="nav-item<?php if ($current_page == "networks.php") { echo " active"; } ?>">
                            <a href="/agent/networks.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "networks.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-network-wired"></i></span>
                                <span class="nav-link-title">Networks</span>
                                <?php
                                if ($num_networks > 0) { ?>
                                    <span class="ms-auto badge text-light"><?php echo $num_networks; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "printers.php") { echo " active"; } ?>">
                            <a href="/agent/printers.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "printers.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-print"></i></span>
                                <span class="nav-link-title">Printers</span>
                                <?php
                                if ($num_printers > 0) { ?>
                                    <span class="ms-auto badge text-light"><?php echo $num_printers; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "network_drives.php") { echo " active"; } ?>">
                            <a href="/agent/network_drives.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "network_drives.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-hdd"></i></span>
                                <span class="nav-link-title">Network Drives</span>
                                <?php
                                if ($num_network_drives > 0) { ?>
                                    <span class="ms-auto badge text-light"><?php echo $num_network_drives; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "racks.php") { echo " active"; } ?>">
                            <a href="/agent/racks.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "racks.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-server"></i></span>
                                <span class="nav-link-title">Racks</span>
                                <?php
                                if ($num_racks > 0) { ?>
                                    <span class="ms-auto badge text-light"><?php echo $num_racks; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "certificates.php") { echo " active"; } ?>">
                            <a href="/agent/certificates.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "certificates.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-lock"></i></span>
                                <span class="nav-link-title">Certificates</span>
                                <?php
                                if ($num_certificates > 0) { ?>
                                    <span class="ms-auto badge <?php if ($num_certificates_expiring > 0) { ?> text-bg-warning text-dark <?php } ?> <?php if ($num_certificates_expired > 0) { ?> text-bg-danger <?php } ?> text-white"><?php echo $num_certificates; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "domains.php") { echo " active"; } ?>">
                            <a href="/agent/domains.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "domains.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-globe"></i></span>
                                <span class="nav-link-title">Domains</span>
                                <?php
                                if ($num_domains > 0) { ?>
                                    <span class="ms-auto badge <?php if (isset($num_domains_expiring)) { ?> text-bg-warning text-dark<?php } ?> <?php if (isset($num_domains_expired)) { ?> text-bg-danger <?php } ?> text-white"><?php echo $num_domains; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "services.php") { echo " active"; } ?>">
                            <a href="/agent/services.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "services.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-stream"></i></span>
                                <span class="nav-link-title">Services</span>
                                <?php
                                if ($num_services > 0) { ?>
                                    <span class="ms-auto badge text-light"><?php echo $num_services; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                    <?php } ?>

                    <!-- Allow files even without module_support for things like contracts, etc. ) -->
                    <li class="nav-item<?php if ($current_page == "contracts.php") { echo " active"; } ?>">
                        <a href="/agent/contracts.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "contracts.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-file-contract"></i></span>
                            <span class="nav-link-title">Contracts</span>
                            <?php
                            $num_contracts = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM contracts WHERE contract_archived_at IS NULL AND contract_client_id = $client_id"))[0]);
                            $num_contracts_due = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM contracts WHERE contract_archived_at IS NULL AND contract_client_id = $client_id AND contract_renewal_date IS NOT NULL AND contract_renewal_date <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)"))[0]);
                            if ($num_contracts > 0) { ?>
                                <span class="ms-auto badge <?php if ($num_contracts_due > 0) { ?> text-bg-warning text-dark <?php } else { ?> text-light <?php } ?>"><?php echo $num_contracts; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "files.php") { echo " active"; } ?>">
                        <a href="/agent/files.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "files.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-folder"></i></span>
                            <span class="nav-link-title">Files</span>
                            <?php
                            if ($num_files > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_files; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                <?php } ?>

                <?php if ($config_module_enable_accounting == 1) { ?>

                    <li class="nav-item nav-section-title">BILLING</li>

                    <?php if (lookupUserPermission("module_sales") >= 1) { ?>

                        <li class="nav-item<?php if ($current_page == "invoices.php" || $current_page == "invoice.php") { echo " active"; } ?>">
                            <a href="/agent/invoices.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "invoices.php" || $current_page == "invoice.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-file-invoice"></i></span>
                                <span class="nav-link-title">Invoices</span>
                                <?php
                                if ($num_invoices > 0) { ?>
                                    <span class="ms-auto badge <?php if ($num_invoices_open > 0) { ?> text-bg-danger <?php } ?> text-light"><?php echo $num_invoices; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "recurring_invoices.php" || $current_page == "recurring_invoice.php") { echo " active"; } ?>">
                            <a href="/agent/recurring_invoices.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "recurring_invoices.php" || $current_page == "recurring_invoice.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-redo-alt"></i></span>
                                <span class="nav-link-title">Recurring Invoices</span>
                                <?php
                                if ($num_recurring_invoices) { ?>
                                    <span class="ms-auto badge"><?php echo $num_recurring_invoices; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                        <li class="nav-item<?php if ($current_page == "quotes.php" || $current_page == "quote.php") { echo " active"; } ?>">
                            <a href="/agent/quotes.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "quotes.php" || $current_page == "quote.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-comment-dollar"></i></span>
                                <span class="nav-link-title">Quotes</span>
                                <?php
                                if ($num_quotes > 0) { ?>
                                    <span class="ms-auto badge text-light"><?php echo $num_quotes; ?></span>
                                <?php } ?>
                            </a>
                        </li>

                    <?php } ?>

                    <?php if (lookupUserPermission("module_financial") >= 1) { ?>
                        <li class="nav-item<?php if ($current_page == "payments.php") { echo " active"; } ?>">
                            <a href="/agent/payments.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "payments.php") { echo " active"; } ?>">
                                <span class="nav-link-icon"><i class="fas fa-credit-card"></i></span>
                                <span class="nav-link-title">Payments</span>
                                <?php
                                if ($num_payments > 0) { ?>
                                    <span class="ms-auto badge text-light"><?php echo $num_payments; ?></span>
                                <?php } ?>
                            </a>
                        </li>
                    <?php } ?>

                    <li class="nav-item<?php if ($current_page == "trips.php") { echo " active"; } ?>">
                        <a href="/agent/trips.php?client_id=<?php echo $client_id; ?>" class="nav-link<?php if ($current_page == "trips.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-route"></i></span>
                            <span class="nav-link-title">Trips</span>
                            <?php
                            if ($num_trips > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_trips; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                <?php } ?>

            </ul>
            <div class="mb-3"></div>
        </div>
        <!-- /.navbar-collapse -->

    </div>
</aside>
