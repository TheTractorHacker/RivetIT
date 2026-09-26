<?php
/* ============================================================================
   HORIZONTAL TOP NAVBAR (Tabler shell)

   WHY THIS FILE BUFFERS INSTEAD OF PRINTING
   All six bootstraps (agent/includes/inc_all.php, inc_all_client.php,
   inc_client_overview_all.php, agent/reports/includes/inc_all_reports.php,
   agent/user/includes/inc_all_user.php, admin/includes/inc_all_admin.php)
   require this file in the order:

       header.php  ->  top_nav.php  ->  <family>_side_nav.php  ->  inc_wrapper.php

   so anything printed here lands as a DIRECT CHILD of .page, BEFORE the sidebar.
   Both of those are wrong under Tabler 1.5:

     1. Tabler implements its vertical/horizontal layout switch in pure CSS:
            html:not([data-bs-navbar-position=vertical])
              .page:has(> [class*=navbar-expand]:not(.navbar-vertical))
              > .navbar-vertical                                  { display:none }
            html[data-bs-navbar-position=vertical]
              .page:has(> .navbar-vertical)
              > [class*=navbar-expand]:not(.navbar-vertical)      { display:none }
        A horizontal navbar sitting next to the sidebar as a direct child of
        .page therefore DELETES one of the two navigations - silently, with no
        console error. This app needs both.

     2. Tabler offsets content past its position:fixed sidebar with
            .navbar-expand-lg.navbar-vertical ~ .navbar,
            .navbar-expand-lg.navbar-vertical ~ .page-wrapper
                { margin-inline-start: var(--tblr-sidebar-width) }
        a FOLLOWING-sibling rule. Printed here the navbar precedes the sidebar,
        so it would get no offset and the fixed sidebar would overlap its left
        15rem - clipping exactly the global search box.

   Buffering into $itflow_top_nav_html lets includes/inc_wrapper.php flush this
   markup inside .page-wrapper instead, which fixes both at once: out of reach of
   the :has() layout switch, and already offset by .page-wrapper's own margin.

   HOOK FOR js/shell.js
   The sidebar toggle below is a plain <button> carrying, deliberately unchanged
   from the AdminLTE shell:
       data-lte-toggle="sidebar"        <- the stable selector to bind
   plus, for the replacement implementation:
       id="itflowSidebarToggle"
       aria-controls="sidebar-menu"     <- the id of the sidebar's collapsible nav
       aria-expanded="false"            <- shell.js should keep this in sync
   Nothing binds it any more (adminlte.min.js is no longer loaded), so js/shell.js
   owns it. Reminder from the audit: the four AdminLTE-3-generation sidebars
   (client / client-overview / reports / user, reachable from 79 page includes)
   are positioned by css/itflow_bs5_bridge.css's
       @media (max-width:991.98px) { .sidebar-open .main-sidebar { margin-left:0 } }
   so the replacement toggle MUST set the class "sidebar-open" on <body> exactly.

   PRESERVED VERBATIM from the AdminLTE version: the global search form + its
   inline nonced live-search script, the custom_links query and loop, the
   notification count query and badge, and the user menu including its
   $session_is_admin gate.
   ============================================================================ */

// Roles audit P0: a module-only (limited) login gets no search box (search spans IT modules it does not
// hold), no custom links, and only its own modules' notification types in the bell count.
$top_nav_limited = itflow_is_limited_user();

// Everything printed from here until ob_get_clean() below is captured, not sent.
ob_start();
?>
<!-- Top navbar. Emitted by includes/inc_wrapper.php inside .page-wrapper.
     The .app-header class is retained on purpose: css/itflow_bs5_bridge.css styles
     the dark chrome and the navbar search box through it
     (.app-header.navbar, .app-header .form-control-navbar, .app-header .nav-link,
     and body:has(.main-sidebar) .app-header). data-bs-theme="dark" keeps the
     chrome dark in both app themes.
     navbar-expand (no breakpoint) = always a horizontal row; there is no
     .navbar-collapse here, so a breakpointed navbar-expand-* would stack the
     items vertically on small screens. -->
<header class="app-header navbar navbar-expand d-print-none" data-bs-theme="dark">
    <div class="container-fluid">

    <!-- Left navbar links -->
    <ul class="navbar-nav align-items-center">
        <li class="nav-item">
            <button type="button" class="nav-link border-0 bg-transparent shadow-none px-2"
                    id="itflowSidebarToggle" data-lte-toggle="sidebar"
                    aria-controls="sidebar-menu" aria-expanded="false"
                    aria-label="Toggle navigation">
                <i class="fas fa-bars"></i>
            </button>
        </li>
        <?php if (!$top_nav_limited) { ?>
        <li class="nav-item d-none d-md-block">
            <!-- SEARCH FORM -->
            <form class="app-header-search" action="/agent/global_search.php" role="search">
                <div class="input-group input-group-sm">
                    <input class="form-control form-control-navbar" type="search" placeholder="Search everywhere" name="query"
                        id="globalSearchInput" autocomplete="off"
                        value="<?php if (isset($_GET['query'])) { echo nullable_htmlentities($_GET['query']); } ?>">
                    <button class="btn btn-navbar" type="submit" aria-label="Search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="app-header-search-results d-none" id="globalSearchResults" role="listbox" aria-label="Search results"></div>
            </form>
        </li>
        <?php } ?>
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ms-auto align-items-center">

        <!-- Mobile search shortcut (inline form is hidden below md) -->
        <?php if (!$top_nav_limited) { ?>
        <li class="nav-item d-md-none">
            <a class="nav-link" href="/agent/global_search.php" aria-label="Search everywhere">
                <i class="fas fa-search"></i>
            </a>
        </li>
        <?php } ?>

        <!--Custom Nav Link -->
        <?php
        $sql_custom_links = $top_nav_limited ? false : mysqli_query($mysqli, "SELECT * FROM custom_links WHERE custom_link_location = 2 AND custom_link_archived_at IS NULL
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

        <li class="nav-item" title="<?php echo $custom_link_name; ?>">
            <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link">
                <i class="fas <?php echo $custom_link_icon_class; ?> nav-icon"></i>
            </a>
        </li>

        <?php } ?>
        <!-- End Custom Nav Links -->

        <!-- New Notifications Dropdown -->
        <?php
        $top_nav_notif_types = itflow_notification_type_sql(intval($session_user_id));   // '' for full agents
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('notification_id') AS num FROM notifications WHERE notification_user_id = $session_user_id AND notification_dismissed_at IS NULL $top_nav_notif_types"));
        $num_notifications = $row['num'];

        ?>

        <li class="nav-item">
            <!-- position-relative added explicitly: AdminLTE supplied the positioning
                 context for .navbar-badge's position-absolute, Tabler does not. -->
            <a class="nav-link position-relative ajax-modal" href="#" data-modal-url="/modals/notifications.php">
                <i class="fas fa-bell"></i>
                <?php if ($num_notifications) { ?>
                <span class="badge text-bg-light rounded-pill navbar-badge position-absolute" style="top: 1px; right: 3px;">
                    <?php echo $num_notifications; ?>
                </span>
                <?php } ?>
            </a>
        </li>

        <!-- User menu. Rebuilt on plain Bootstrap/Tabler dropdown primitives:
             AdminLTE's .user-menu / .user-header / .user-footer / .dropdown-menu-lg /
             .img-circle / .btn-flat had no first-party definitions and died with
             adminlte.min.css. The .user-menu class is kept only as a stable hook.
             Links, ordering and the $session_is_admin gate are unchanged. -->
        <li class="nav-item dropdown user-menu">
            <a href="#" class="nav-link d-flex align-items-center" data-bs-toggle="dropdown" role="button" aria-expanded="false">
                <?php if (empty($session_avatar)) { ?>
                <i class="fas fa-user-circle me-1"></i>
                <?php }else{ ?>
                <img src="<?php echo "/uploads/users/$session_user_id/$session_avatar"; ?>"
                    class="rounded-circle me-1" width="28" height="28" alt="">
                <?php } ?>
                <span
                    class="d-none d-md-inline dropdown-toggle"><?php echo stripslashes(nullable_htmlentities($session_name)); ?></span>
            </a>
            <div class="dropdown-menu dropdown-menu-end p-0" style="min-width: 17rem;">
                <!-- User identity -->
                <div class="text-center px-3 py-3 border-bottom">
                    <?php if (empty($session_avatar)) { ?>
                    <i class="fas fa-user-circle fa-4x text-muted"></i>
                    <?php }else{ ?>

                    <img src="<?php echo "/uploads/users/$session_user_id/$session_avatar"; ?>" class="rounded-circle" width="72" height="72" alt="">
                    <?php } ?>
                    <div class="mt-2 fw-semibold"><?php echo stripslashes(nullable_htmlentities($session_name)); ?></div>
                    <div class="small text-muted"><?php echo nullable_htmlentities($session_user_role_display); ?></div>
                </div>
                <!-- Menu Footer-->
                <div class="py-1">
                    <?php if ($session_is_admin) { ?>
                        <a href="/admin/" class="dropdown-item"><i class="fas fa-fw fa-user-shield me-2"></i>Administration</a>
                    <?php } ?>
                    <a href="/agent/user/user_details.php" class="dropdown-item"><i class="fas fa-fw fa-user-cog me-2"></i>Account</a>
                    <div class="dropdown-divider"></div>
                    <a href="/agent/post.php?logout" class="dropdown-item"><i class="fas fa-fw fa-sign-out-alt me-2"></i>Logout</a>
                </div>
            </div>
        </li>

    </ul>
    </div><!-- /.container-fluid -->
</header>
<!-- /.navbar -->

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var input = document.getElementById('globalSearchInput');
    var panel = document.getElementById('globalSearchResults');
    if (!input || !panel) { return; }

    var MIN_CHARS = 2;
    var DEBOUNCE_MS = 250;
    var debounceTimer = null;
    var requestSeq = 0; // guards against out-of-order responses

    var GROUP_META = {
        clients:  { label: 'Departments',  icon: 'fa-users' },
        contacts: { label: 'Contacts', icon: 'fa-address-book' },
        tickets:  { label: 'Tickets',  icon: 'fa-life-ring' },
        quotes:   { label: 'Quotes',   icon: 'fa-file-invoice' },
        invoices: { label: 'Invoices', icon: 'fa-file-invoice-dollar' },
        assets:   { label: 'Assets',   icon: 'fa-desktop' },
        kb_articles: { label: 'Knowledge Base', icon: 'fa-book' },
        credentials: { label: 'Credentials', icon: 'fa-key' },
        vendors: { label: 'Vendors', icon: 'fa-building' },
        domains: { label: 'Domains', icon: 'fa-globe' },
        documents: { label: 'Documents', icon: 'fa-file-alt' },
        recurring_tickets: { label: 'Recurring Tickets', icon: 'fa-sync' },
        products: { label: 'Products', icon: 'fa-box' },
        settings: { label: 'Settings', icon: 'fa-cog' }
    };
    var GROUP_ORDER = ['clients', 'contacts', 'tickets', 'quotes', 'invoices', 'assets',
        'kb_articles', 'credentials', 'vendors', 'domains', 'documents', 'recurring_tickets',
        'products', 'settings'];

    function closePanel() {
        panel.classList.add('d-none');
        panel.textContent = '';
    }

    function renderResults(groups, queryText) {
        panel.textContent = '';
        var hasAny = false;

        GROUP_ORDER.forEach(function (key) {
            var items = groups[key];
            if (!items || !items.length) { return; }
            hasAny = true;

            var section = document.createElement('div');
            section.className = 'app-header-search-group';

            var heading = document.createElement('div');
            heading.className = 'app-header-search-group-label';
            var icon = document.createElement('i');
            icon.className = 'fas fa-fw ' + GROUP_META[key].icon + ' me-1';
            heading.appendChild(icon);
            heading.appendChild(document.createTextNode(GROUP_META[key].label));
            section.appendChild(heading);

            items.forEach(function (item) {
                var a = document.createElement('a');
                a.className = 'app-header-search-result';
                a.href = item.url; // server-built, static-prefix + intval id — safe as a href
                var title = document.createElement('div');
                title.className = 'app-header-search-result-title';
                title.textContent = item.title || '';
                a.appendChild(title);
                if (item.subtitle) {
                    var sub = document.createElement('div');
                    sub.className = 'app-header-search-result-subtitle';
                    sub.textContent = item.subtitle;
                    a.appendChild(sub);
                }
                section.appendChild(a);
            });

            panel.appendChild(section);
        });

        if (!hasAny) {
            var empty = document.createElement('div');
            empty.className = 'app-header-search-empty';
            empty.textContent = 'No matches.';
            panel.appendChild(empty);
        } else {
            var footer = document.createElement('a');
            footer.className = 'app-header-search-seeall';
            footer.href = '/agent/global_search.php?query=' + encodeURIComponent(queryText);
            footer.textContent = 'See all results for “' + queryText + '”';
            panel.appendChild(footer);
        }

        panel.classList.remove('d-none');
    }

    function runSearch(q) {
        var seq = ++requestSeq;
        fetch('/agent/ajax.php?global_search_live=1&q=' + encodeURIComponent(q), {
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (seq !== requestSeq) { return; } // a newer keystroke's request already landed
            if (!data || !data.ok) { closePanel(); return; }
            renderResults(data.groups || {}, q);
        })
        .catch(function () {
            if (seq !== requestSeq) { return; }
            closePanel();
        });
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        if (debounceTimer) { clearTimeout(debounceTimer); }
        if (q.length < MIN_CHARS) { closePanel(); return; }
        debounceTimer = setTimeout(function () { runSearch(q); }, DEBOUNCE_MS);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closePanel(); }
    });

    input.addEventListener('focus', function () {
        if (input.value.trim().length >= MIN_CHARS && panel.children.length) {
            panel.classList.remove('d-none');
        }
    });

    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && e.target !== input) { closePanel(); }
    });
})();
</script>
<?php
// Hand the finished markup to includes/inc_wrapper.php, which prints it inside
// .page-wrapper. If a page ever includes this file without that wrapper the
// navbar is simply not rendered - see the note in inc_wrapper.php.
$itflow_top_nav_html = ob_get_clean();
