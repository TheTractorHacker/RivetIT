<?php
// Badge Counts
// UNCHANGED from the AdminLTE-generation version of this file: same queries, same
// $access_permission_query scoping, same variable names.
//
// THESE COUNTS ARE COMPANY-WIDE ON PURPOSE. Every query below joins clients and
// is scoped only by $access_permission_query (what this user may see), never by a
// single client_id - this sidebar is only ever included when the URL carries NO
// client_id (see agent/includes/inc_client_overview_all.php and the else-branch of
// agent/{contacts,contact_details,assets,asset_details,networks,services}.php).
// Do not "fix" them into per-department numbers: the per-department rail is
// agent/includes/client_side_nav.php, which gets its counts from
// agent/includes/inc_all_client.php and scopes every one of them to $client_id.

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
<!-- Company-wide Sidebar (Tabler vertical navbar).
     data-bs-theme="dark" keeps the sidebar dark in both app themes, exactly as the
     AdminLTE shell did.

     WHAT THIS RAIL IS, AND WHY THERE ARE TWO OF THEM
     ------------------------------------------------
     This app has two secondary rails over the same record types, and they are two
     SCOPES, not two designs:

       agent/includes/client_side_nav.php   ONE DEPARTMENT. Included from
           inc_all_client.php, i.e. only when the URL carries a client_id. Every
           href carries that client_id, every count is scoped to it, and the brand
           names the department.

       agent/includes/client_overview_side_nav.php  (this file)  COMPANY-WIDE.
           Included from inc_client_overview_all.php, i.e. only when the URL has NO
           client_id. No href carries a client_id and every count spans every
           department the user may see.

     Until now this rail called itself "Department Overview" and back-linked to the
     departments list, so walking contacts.php -> contact_details.php?client_id=20
     looked like the SAME department redrawing itself with a different, shorter set
     of items - the "two different maps of one department" the owner reported. It
     was never a department map at all; it was the company-wide map wearing a
     department's label. The brand below now states the scope ("Company-wide"), and
     the item order/grouping matches client_side_nav.php exactly, so the two rails
     read as one system seen at two scopes.

     Nothing was removed: all nine destinations are still here, in the same order
     client_side_nav.php lists them, and the three that no other nav in the app
     reaches (global Assets, Networks, Services) are still reachable only from here.

     WHY FIVE OF THE NINE HREFS CARRY ?scope=company
     -----------------------------------------------
     The nine destinations do not all resolve their scope the same way.

       contacts.php, assets.php, networks.php, services.php
           have no app-level branch at all: with no client_id they ALWAYS include
           inc_client_overview_all.php, so a bare href already lands on this rail.

       locations.php, software.php (Licenses), credentials.php, certificates.php,
       domains.php
           are also destinations of the APP-LEVEL rail (includes/side_nav.php links
           to all five, under Infrastructure and Knowledge), so with no client_id
           they used to fall through to inc_all.php and draw side_nav.php instead.
           Clicking any of those five from here therefore replaced this rail with
           the twelve-item app rail: the sidebar swapped under the user and the
           company-wide view was gone after one click. Measured before the fix, all
           five landed on side_nav.php.

     ?scope=company is that missing statement of intent. It is a marker, not a
     filter - the rows those pages list are identical either way - so the app-level
     rail keeps linking to them bare and keeps its own rail, and nothing is hidden
     from anyone: every item, and every user who could reach it, is untouched. The
     other four hrefs stay bare because for them the marker would be inert.

     Markup shape is the one every migrated sidebar uses:

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

        <!-- Brand area: the same stacked shape client_side_nav.php uses - the same
             "All Departments" back link over an identity block - so the ONLY thing
             that differs between the two rails is the value in the identity slot: a
             department's name and type there, the "Company-wide" scope here. Both
             keep their existing .client-nav-back / .client-nav-header /
             .client-nav-avatar styling from css/itflow_bs5_bridge.css, so the brand
             box contributes no padding of its own (p-0) and lets the two children
             fill it (w-100 + flex-column + align-items-stretch).

             The identity block is a <div>, not an <a>: it is a scope label, not a
             destination. There is no per-department page to send it to. -->
        <div class="navbar-brand p-0 w-100 flex-column align-items-stretch">

            <a class="client-nav-back" href="/agent/clients.php">
                <i class="fas fa-arrow-left"></i> All Departments
            </a>

            <div class="client-nav-header" title="These lists span every department you can see">
                <span class="client-nav-avatar"><i class="fas fa-sitemap"></i></span>
                <span class="client-nav-identity">
                    <span class="client-nav-name">Company-wide</span>
                </span>
            </div>

        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2">

                <?php  if (lookupUserPermission("module_support") >= 1) { ?>

                    <!-- Contacts and Locations sit above the DOCUMENTATION heading,
                         same as client_side_nav.php. Hrefs are root-absolute for the
                         same reason that file's are: the rail renders on pages in
                         /agent/ only, but an absolute path cannot be broken by a
                         future include from a subdirectory. -->
                    <li class="nav-item<?php if ($current_page == "contacts.php" || $current_page == "contact_details.php") { echo " active"; } ?>">
                        <a href="/agent/contacts.php" class="nav-link<?php if ($current_page == "contacts.php" || $current_page == "contact_details.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-address-book"></i></span>
                            <span class="nav-link-title">Contacts</span>
                            <?php
                            if ($num_contacts > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_contacts; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "locations.php") { echo " active"; } ?>">
                        <a href="/agent/locations.php?scope=company" class="nav-link<?php if ($current_page == "locations.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-map-marker-alt"></i></span>
                            <span class="nav-link-title">Locations</span>
                            <?php
                            if ($num_locations > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_locations; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item nav-section-title">DOCUMENTATION</li>

                    <li class="nav-item<?php if ($current_page == "assets.php" || $current_page == "asset_details.php") { echo " active"; } ?>">
                        <a href="/agent/assets.php" class="nav-link<?php if ($current_page == "assets.php" || $current_page == "asset_details.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-desktop"></i></span>
                            <span class="nav-link-title">Assets</span>
                            <?php
                            if ($num_assets > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_assets; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "software.php") { echo " active"; } ?>">
                        <a href="/agent/software.php?scope=company" class="nav-link<?php if ($current_page == "software.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-cube"></i></span>
                            <span class="nav-link-title">Licenses</span>
                            <?php
                            if ($num_software > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_software; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "credentials.php") { echo " active"; } ?>">
                        <a href="/agent/credentials.php?scope=company" class="nav-link<?php if ($current_page == "credentials.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-key"></i></span>
                            <span class="nav-link-title">Credentials</span>
                            <?php
                            if ($num_credentials > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_credentials; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "networks.php") { echo " active"; } ?>">
                        <a href="/agent/networks.php" class="nav-link<?php if ($current_page == "networks.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-network-wired"></i></span>
                            <span class="nav-link-title">Networks</span>
                            <?php
                            if ($num_networks > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_networks; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "certificates.php") { echo " active"; } ?>">
                        <a href="/agent/certificates.php?scope=company" class="nav-link<?php if ($current_page == "certificates.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-lock"></i></span>
                            <span class="nav-link-title">Certificates</span>
                            <?php
                            if ($num_certificates > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_certificates; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "domains.php") { echo " active"; } ?>">
                        <a href="/agent/domains.php?scope=company" class="nav-link<?php if ($current_page == "domains.php") { echo " active"; } ?>">
                            <span class="nav-link-icon"><i class="fas fa-globe"></i></span>
                            <span class="nav-link-title">Domains</span>
                            <?php
                            if ($num_domains > 0) { ?>
                                <span class="ms-auto badge text-light"><?php echo $num_domains; ?></span>
                            <?php } ?>
                        </a>
                    </li>

                    <li class="nav-item<?php if ($current_page == "services.php") { echo " active"; } ?>">
                        <a href="/agent/services.php" class="nav-link<?php if ($current_page == "services.php") { echo " active"; } ?>">
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
