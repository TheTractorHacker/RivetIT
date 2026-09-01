<!-- Navbar (AdminLTE 4 app-header). data-bs-theme="dark" keeps the chrome dark in both app themes. -->
<nav class="app-header navbar navbar-expand" data-bs-theme="dark">
    <div class="container-fluid">

    <!-- Left navbar links -->
    <ul class="navbar-nav align-items-center">
        <li class="nav-item">
            <a class="nav-link" data-lte-toggle="sidebar" data-enable-remember="TRUE" href="#" role="button"><i class="fas fa-bars"></i></a>
        </li>
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
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ms-auto align-items-center">

        <!-- Mobile search shortcut (inline form is hidden below md) -->
        <li class="nav-item d-md-none">
            <a class="nav-link" href="/agent/global_search.php" aria-label="Search everywhere">
                <i class="fas fa-search"></i>
            </a>
        </li>

        <!--Custom Nav Link -->
        <?php
        $sql_custom_links = mysqli_query($mysqli, "SELECT * FROM custom_links WHERE custom_link_location = 2 AND custom_link_archived_at IS NULL
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

        <li class="nav-item" title="<?php echo $custom_link_name; ?>">
            <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link">
                <i class="fas fa-<?php echo $custom_link_icon; ?> nav-icon"></i>
            </a>
        </li>

        <?php } ?>
        <!-- End Custom Nav Links -->

        <!-- New Notifications Dropdown -->
        <?php
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('notification_id') AS num FROM notifications WHERE notification_user_id = $session_user_id AND notification_dismissed_at IS NULL"));
        $num_notifications = $row['num'];

        ?>

        <li class="nav-item">
            <a class="nav-link ajax-modal" href="#" data-modal-url="/modals/notifications.php">
                <i class="fas fa-bell"></i>
                <?php if ($num_notifications) { ?>
                <span class="badge text-bg-light rounded-pill navbar-badge position-absolute" style="top: 1px; right: 3px;">
                    <?php echo $num_notifications; ?>
                </span>
                <?php } ?>
            </a>
        </li>

        <li class="nav-item dropdown user-menu">
            <a href="#" class="nav-link" data-bs-toggle="dropdown">
                <?php if (empty($session_avatar)) { ?>
                <i class="fas fa-user-circle me-1"></i>
                <?php }else{ ?>
                <img src="<?php echo "/uploads/users/$session_user_id/$session_avatar"; ?>"
                    class="user-image img-circle">
                <?php } ?>
                <span
                    class="d-none d-md-inline dropdown-toggle"><?php echo stripslashes(nullable_htmlentities($session_name)); ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                <!-- User image -->
                <li class="user-header bg-gray-dark text-center">
                    <?php if (empty($session_avatar)) { ?>
                    <i class="fas fa-user-circle fa-6x"></i>
                    <?php }else{ ?>

                    <img src="<?php echo "/uploads/users/$session_user_id/$session_avatar"; ?>" class="img-circle">
                    <?php } ?>
                    <p>
                        <?php echo stripslashes(nullable_htmlentities($session_name)); ?>
                        <small><?php echo nullable_htmlentities($session_user_role_display); ?></small>
                    </p>
                </li>
                <!-- Menu Footer-->
                <li class="user-footer">
                    <?php if ($session_is_admin) { ?>
                        <a href="/admin/" class="btn btn-default btn-block btn-flat"><i class="fas fa-user-shield me-2"></i>Administration</a>
                    <?php } ?>
                    <div class="d-flex gap-2">
                        <a href="/agent/user/user_details.php" class="btn btn-default btn-flat flex-fill"><i class="fas fa-user-cog me-2"></i>Account</a>
                        <a href="/agent/post.php?logout" class="btn btn-default btn-flat flex-fill"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
                    </div>
                </li>
            </ul>
        </li>

    </ul>
    </div><!-- /.container-fluid -->
</nav>
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
        clients:  { label: 'Clients',  icon: 'fa-users' },
        contacts: { label: 'Contacts', icon: 'fa-address-book' },
        tickets:  { label: 'Tickets',  icon: 'fa-life-ring' },
        quotes:   { label: 'Quotes',   icon: 'fa-file-invoice' },
        invoices: { label: 'Invoices', icon: 'fa-file-invoice-dollar' },
        assets:   { label: 'Assets',   icon: 'fa-desktop' }
    };
    var GROUP_ORDER = ['clients', 'contacts', 'tickets', 'quotes', 'invoices', 'assets'];

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
