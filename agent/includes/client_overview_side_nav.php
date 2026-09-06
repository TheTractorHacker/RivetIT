<?php
// Badge Counts
// UNCHANGED from the AdminLTE-generation version of this file: same queries, same
// $access_permission_query scoping, same variable names.

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('contact_id') AS num FROM contacts LEFT JOIN clients ON contact_client_id = client_id WHERE contact_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query"));
$num_contacts = $row['num'];

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('location_id') AS num FROM locations LEFT JOIN clients ON location_client_id = client_id WHERE location_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query"));
$num_locations = $row['num'];

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('asset_id') AS num FROM assets LEFT JOIN clients ON asset_client_id = client_id WHERE asset_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query"));
$num_assets = $row['num'];

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('service_id') AS num FROM services LEFT JOIN clients ON service_client_id = client_id WHERE client_archived_at IS NULL $access_permission_query"));
$num_services = $row['num'];

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('credential_id') AS num FROM credentials LEFT JOIN clients ON credential_client_id = client_id WHERE credential_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query"));
$num_credentials = $row['num'];

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('network_id') AS num FROM networks LEFT JOIN clients ON network_client_id = client_id WHERE network_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query"));
$num_networks = $row['num'];

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('domain_id') AS num FROM domains LEFT JOIN clients ON domain_client_id = client_id WHERE domain_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query"));
$num_domains = $row['num'];

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('certificate_id') AS num FROM certificates LEFT JOIN clients ON certificate_client_id = client_id WHERE certificate_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query"));
$num_certificates = $row['num'];

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('software_id') AS num FROM software LEFT JOIN clients ON software_client_id = client_id WHERE software_archived_at IS NULL AND client_archived_at IS NULL $access_permission_query"));
$num_software = $row['num'];

// Current page, used below for active-item detection (same basename() test as before).
$current_page = basename($_SERVER["PHP_SELF"]);

?>
<!-- Department-Overview Sidebar (Tabler vertical navbar).
     data-bs-theme="dark" keeps the sidebar dark in both app themes, exactly as the
     AdminLTE shell did.

     Was AdminLTE 3's aside.main-sidebar > div.sidebar > ul.nav-sidebar with
     data-widget="treeview" (a widget with no handler - there are no submenus here,
     so nothing was lost). Now the same Tabler shape the agent/admin sidebars use:

       aside.navbar.navbar-vertical.navbar-expand-lg > .container-fluid
         > button.navbar-toggler + .navbar-brand + .collapse.navbar-collapse#sidebar-menu
           > ul.navbar-nav

     Internally balanced: exactly one <aside> opened and closed, ZERO structural
     depth added, so includes/footer.php's four-level close is unaffected.

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
            <a class="section-nav-back" href="clients.php">
                <i class="fas fa-arrow-left"></i> Department Overview
            </a>
        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2">

                <?php  if (lookupUserPermission("module_support") >= 1) { ?>
                    <li class="nav-item<?php if ($current_page == "contacts.php" || $current_page == "contact_details.php") { echo " active"; } ?>">
                        <a href="contacts.php" class="nav-link<?php if ($current_page == "contacts.php" || $current_page == "contact_details.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-address-book"></i></span>
                            <span class="nav-link-title">Contacts</span>
                            <?php
                            if ($num_contacts > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_contacts; ?></span>
                            <?php } ?>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "locations.php") { echo " active"; } ?>">
                        <a href="locations.php" class="nav-link<?php if ($current_page == "locations.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-map-marker-alt"></i></span>
                            <span class="nav-link-title">Locations</span>
                            <?php
                            if ($num_locations > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_locations; ?></span>
                            <?php } ?>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "assets.php") { echo " active"; } ?>">
                        <a href="assets.php" class="nav-link<?php if ($current_page == "assets.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-desktop"></i></span>
                            <span class="nav-link-title">Assets</span>
                            <?php
                            if ($num_assets > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_assets; ?></span>
                            <?php } ?>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "software.php") { echo " active"; } ?>">
                        <a href="software.php" class="nav-link<?php if ($current_page == "software.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-cube"></i></span>
                            <span class="nav-link-title">Licenses</span>
                            <?php
                            if ($num_software > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_software; ?></span>
                            <?php } ?>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "credentials.php") { echo " active"; } ?>">
                        <a href="credentials.php" class="nav-link<?php if ($current_page == "credentials.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-key"></i></span>
                            <span class="nav-link-title">Credentials</span>
                            <?php
                            if ($num_credentials > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_credentials; ?></span>
                            <?php } ?>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "networks.php") { echo " active"; } ?>">
                        <a href="networks.php" class="nav-link<?php if ($current_page == "networks.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-network-wired"></i></span>
                            <span class="nav-link-title">Networks</span>
                            <?php
                            if ($num_networks > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_networks; ?></span>
                            <?php } ?>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "certificates.php") { echo " active"; } ?>">
                        <a href="certificates.php" class="nav-link<?php if ($current_page == "certificates.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-lock"></i></span>
                            <span class="nav-link-title">Certificates</span>
                            <?php
                            if ($num_certificates > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_certificates; ?></span>
                            <?php } ?>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "domains.php") { echo " active"; } ?>">
                        <a href="domains.php" class="nav-link<?php if ($current_page == "domains.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-globe"></i></span>
                            <span class="nav-link-title">Domains</span>
                            <?php
                            if ($num_domains > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_domains; ?></span>
                            <?php } ?>
                        </a>
                    </li>
                    <li class="nav-item<?php if ($current_page == "services.php") { echo " active"; } ?>">
                        <a href="services.php" class="nav-link<?php if ($current_page == "services.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-stream"></i></span>
                            <span class="nav-link-title">Services</span>
                            <?php
                            if ($num_services > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_services; ?></span>
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
