<?php
// Current page, used below for active-item detection (same basename() test as before).
$current_page = basename($_SERVER["PHP_SELF"]);
?>
<!-- Account (user) Sidebar (Tabler vertical navbar).
     data-bs-theme="dark" keeps the sidebar dark in both app themes, exactly as the
     AdminLTE shell did. The old aside also carried sidebar-dark-<theme>, an AdminLTE
     class nothing styles any more (AdminLTE 4 dropped it and Tabler never had it), so
     it is gone; the per-company accent still reaches this sidebar through the
     --if-*/--tblr-* token mapping in css/itflow.bind-tabler.css.

     Was AdminLTE 3's aside.main-sidebar > div.sidebar > ul.nav-sidebar with
     data-widget="treeview" (a widget with no handler - this list is flat, so nothing
     was lost). Now the same Tabler shape the agent/admin sidebars use:

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
            <a class="section-nav-back" href="/agent/<?php echo $config_start_page ?>">
                <i class="fas fa-arrow-left"></i> Account
            </a>
        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2">

                <li class="nav-item<?php if ($current_page == "user_details.php") { echo " active"; } ?>">
                    <a href="/agent/user/user_details.php" class="nav-link<?php if ($current_page == "user_details.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-user"></i></span>
                        <span class="nav-link-title">Details</span>
                    </a>
                </li>

                <li class="nav-item<?php if ($current_page == "user_security.php") { echo " active"; } ?>">
                    <a href="/agent/user/user_security.php" class="nav-link<?php if ($current_page == "user_security.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-shield-alt"></i></span>
                        <span class="nav-link-title">Security</span>
                    </a>
                </li>

                <li class="nav-item<?php if ($current_page == "user_preferences.php") { echo " active"; } ?>">
                    <a href="/agent/user/user_preferences.php" class="nav-link<?php if ($current_page == "user_preferences.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-cogs"></i></span>
                        <span class="nav-link-title">Preferences</span>
                    </a>
                </li>

                <li class="nav-item<?php if ($current_page == "user_activity.php") { echo " active"; } ?>">
                    <a href="/agent/user/user_activity.php" class="nav-link<?php if ($current_page == "user_activity.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fas fa-clock"></i></span>
                        <span class="nav-link-title">Activity</span>
                    </a>
                </li>

                <li class="nav-item<?php if ($current_page == "user_integrations.php") { echo " active"; } ?>">
                    <a href="/agent/user/user_integrations.php" class="nav-link<?php if ($current_page == "user_integrations.php") { echo " active"; } ?>">
                        <span class="nav-link-icon"><i class="fab fa-microsoft"></i></span>
                        <span class="nav-link-title">Integrations</span>
                    </a>
                </li>

            </ul>
            <div class="mb-3"></div>
        </div>
        <!-- /.navbar-collapse -->

    </div>
</aside>
