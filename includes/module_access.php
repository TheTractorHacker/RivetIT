<?php

/*
 * Module access: who may reach which part of the app (roles audit 2026-09-26, P0 / P1 / P4).
 *
 * WHAT A "LIMITED" LOGIN IS
 *   A non-admin role that holds none of Departments (module_client), Tickets/assets/docs (module_support)
 *   and Assets (module_assets). Such a login is a module-only login - a Training Manager, a learner, a KB
 *   editor - and it gets the app through an ALLOW-LIST (itflow_limited_access_decision): its own module
 *   pages, its account pages and notifications, nothing else. Everything outside that list answers
 *   403 (a page with a "Go to <home>" button, or JSON for pop-ups and ajax).
 *
 *   Admin roles are never limited (lookupUserPermission() resolves every module to 3 for them), and the
 *   Technician role (Departments 2, Tickets/assets/docs 2, ...) is never limited, so neither is affected by
 *   anything in the limited-user paths below. Every helper here that could change what a non-limited user
 *   sees returns the pre-audit answer for them.
 *
 * WHO USES THIS FILE
 *   functions.php requires it (so cron, API and portal code can call the per-user helpers without a
 *   session). includes/check_login.php runs the allow-list for every agent-side request. The sidebar,
 *   top bar, dashboard, search and notification code call the session helpers. Other lanes (modals,
 *   Training settings) may CALL these helpers; the file is owned by the core lane.
 *
 * NOTHING HERE HAS A SIDE EFFECT ON INCLUDE.
 */

/** Modules that make a login a full IT agent (not limited). module_assets exists from DB 2.6.95. */
const ITFLOW_FULL_AGENT_MODULES = ['module_client', 'module_support', 'module_assets'];

/** Plain-language names used in denial messages (never shown as raw keys). */
function itflow_module_label(string $module): string {
    $labels = [
        'module_client'             => 'Departments',
        'module_support'            => 'Tickets, assets & docs',
        'module_assets'             => 'Assets',
        'module_credential'         => 'Credentials',
        'module_sales'              => 'Sales',
        'module_financial'          => 'Finance',
        'module_reporting'          => 'Reports',
        'module_kb'                 => 'Knowledge base',
        'module_rmm'                => 'RMM',
        'module_rmm_alerts'         => 'RMM alerts',
        'module_rmm_alerts_ack'     => 'RMM alert acknowledgement',
        'module_rmm_scripts'        => 'RMM scripts',
        'module_rmm_sync'           => 'RMM sync',
        'module_rmm_remote_connect' => 'RMM remote connect',
        'module_training'           => 'Training',
        'module_training_kiosk'     => 'Training kiosk',
    ];
    return $labels[$module] ?? ucwords(str_replace('_', ' ', preg_replace('/^module_/', '', $module)));
}

function itflow_level_label(int $level): string {
    return [1 => 'view', 2 => 'edit', 3 => 'full'][$level] ?? 'view';
}

/* ------------------------------------------------------------------------------------------------
 * SESSION helpers (agent web requests; they read the globals load_user_session.php sets)
 * ---------------------------------------------------------------------------------------------- */

/** The signed-in role's level for $module (admin = 3), as an int (0 = none). */
function itflow_level(string $module): int {
    return intval(lookupUserPermission($module));
}

/**
 * True for a non-admin whose role holds none of Departments, Tickets/assets/docs and Assets at level 1+.
 * Same rule agent/dashboard.php used for its Training redirect, extended with module_assets (P4).
 */
function itflow_is_limited_user(): bool {
    global $session_is_admin, $session_user_id, $session_user_role;
    if (!empty($session_is_admin)) {
        return false;
    }
    if (empty($session_user_id) || !isset($session_user_role)) {
        return false;   // no agent web session: nothing to limit (API/cron callers use the per-user helpers)
    }
    foreach (ITFLOW_FULL_AGENT_MODULES as $m) {
        if (itflow_level($m) >= 1) {
            return false;
        }
    }
    return true;
}

/** Assets: the Assets module OR Tickets/assets/docs at $level or above (P4: either grants asset pages). */
function itflow_can_assets(int $level = 1): bool {
    return itflow_level('module_assets') >= $level || itflow_level('module_support') >= $level;
}

/**
 * The page this login lands on: the start-page setting for everyone who is not limited (unchanged), and
 * the first module the role holds for a limited login.
 */
function itflow_home_url(): string {
    global $config_start_page;
    if (!itflow_is_limited_user()) {
        $start = (string) ($config_start_page ?? '');
        return '/agent/' . ($start !== '' ? $start : 'dashboard.php');   // callers escape it for output
    }
    return itflow_limited_home()['url'];
}

/** Human name of the home page, for "Go to <home>" buttons. */
function itflow_home_label(): string {
    global $config_start_page;
    if (itflow_is_limited_user()) {
        return itflow_limited_home()['label'];
    }
    $map = [
        'dashboard.php' => 'Dashboard', 'clients.php' => 'Departments', 'tickets.php' => 'Tickets',
        'ticket_kanban.php' => 'Tickets', 'projects.php' => 'Projects', 'calendar.php' => 'Calendar',
        'assets.php' => 'Assets', 'kb_articles.php' => 'Knowledge Base',
    ];
    return $map[basename((string) ($config_start_page ?? 'dashboard.php'))] ?? 'Home';
}

/** First module a limited login holds, in the order Training, Knowledge base, Reports, RMM, RMM alerts. */
function itflow_limited_home(): array {
    return itflow_limited_home_for(fn(string $m) => itflow_level($m));
}

/** The on/off switches the limited home depends on: the loaded globals, or the settings row (login, index). */
function itflow_module_switches(): array {
    global $mysqli, $config_module_enable_training, $config_module_enable_kb, $config_module_enable_rmm;
    if (isset($config_module_enable_training, $config_module_enable_kb)) {
        return ['training' => intval($config_module_enable_training), 'kb' => intval($config_module_enable_kb), 'rmm' => intval($config_module_enable_rmm ?? 0)];
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT * FROM settings WHERE company_id = 1 LIMIT 1")) ?: [];
    return ['training' => intval($row['config_module_enable_training'] ?? 0), 'kb' => intval($row['config_module_enable_kb'] ?? 0), 'rmm' => intval($row['config_module_enable_rmm'] ?? 0)];
}

/** @param callable(string):int $lvl the role's level per module */
function itflow_limited_home_for(callable $lvl): array {
    $on = itflow_module_switches();
    if ($on['training'] === 1) {
        if ($lvl('module_training') >= 1) {
            return ['url' => '/agent/training_dashboard.php', 'label' => 'Training'];
        }
        if ($lvl('module_training_kiosk') >= 1) {
            return ['url' => '/agent/training_devices.php', 'label' => 'Training'];
        }
    }
    if ($on['kb'] === 1 && $lvl('module_kb') >= 1) {
        return ['url' => '/agent/kb_articles.php', 'label' => 'Knowledge Base'];
    }
    if ($lvl('module_reporting') >= 1) {
        return ['url' => '/agent/reports/', 'label' => 'Reports'];
    }
    if ($on['rmm'] === 1 && $lvl('module_rmm') >= 1) {
        return ['url' => '/agent/rmm_dashboard.php', 'label' => 'RMM'];
    }
    if ($lvl('module_rmm_alerts') >= 1) {
        return ['url' => '/agent/alerts.php', 'label' => 'Alerts'];
    }
    return ['url' => '/agent/user/user_details.php', 'label' => 'your account'];
}

/**
 * Where a user lands straight after sign-in, without a loaded session (login.php, passkey sign-in, /):
 * null for everyone who is not limited (the caller keeps using the start-page setting, unchanged), the
 * limited home for a module-only login.
 */
function itflow_limited_home_url_for_user(int $user_id): ?string {
    $profile = itflow_user_access_profile($user_id);
    if (!itflow_profile_is_limited($profile)) {
        return null;
    }
    return itflow_limited_home_for(fn(string $m) => itflow_profile_level($profile, $m))['url'];
}

/* ------------------------------------------------------------------------------------------------
 * P0: the allow-list for limited logins
 * ---------------------------------------------------------------------------------------------- */

/**
 * The requested script, as a path from the web root ("/agent/training.php", "/agent/reports/index.php").
 * SCRIPT_NAME (not REQUEST_URI) so path info and query strings cannot dress up a denied script.
 */
function itflow_request_script(): string {
    $s = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $s = '/' . ltrim(str_replace('\\', '/', $s), '/');
    return preg_replace('#/+#', '/', $s);
}

/**
 * 'allow', 'home' (send to itflow_home_url()) or 'deny', for the CURRENT request of a limited login.
 * Non-limited logins always get 'allow' - their pages keep their own checks.
 */
function itflow_limited_access_decision(?string $script = null): string {
    if (!itflow_is_limited_user()) {
        return 'allow';
    }
    $s = $script ?? itflow_request_script();

    // Landing pages: a limited login is sent to its own home (and ?home=1 no longer shows the dashboard).
    if (in_array($s, ['/agent/index.php', '/agent/dashboard.php', '/agent/it_dashboard.php', '/agent/'], true)) {
        return 'home';
    }

    // Always: account pages, notifications, the form handler (it loads only the handlers this role may use).
    if (strpos($s, '/agent/user/') === 0
        || in_array($s, ['/agent/post.php', '/agent/notifications.php', '/modals/notifications.php'], true)) {
        return 'allow';
    }

    $training = itflow_level('module_training') >= 1 || itflow_level('module_training_kiosk') >= 1;
    $kb       = itflow_level('module_kb') >= 1;
    $reports  = itflow_level('module_reporting') >= 1;
    $rmm      = itflow_level('module_rmm') >= 1;
    $alerts   = itflow_level('module_rmm_alerts') >= 1;

    $base = basename($s);
    $dir  = dirname($s);

    if ($dir === '/agent') {
        if ($training && strpos($base, 'training') === 0) {
            return 'allow';
        }
        if ($kb && preg_match('/^kb_(articles?|article_versions|article_attachment|article_upload|media|embed|progress)\.php$/', $base)) {
            return 'allow';
        }
        if ($rmm && (strpos($base, 'rmm_') === 0 || in_array($base, ['network.php', 'firewalls.php', 'backups.php'], true))) {
            return 'allow';
        }
        if ($alerts && $base === 'alerts.php') {
            return 'allow';
        }
        return 'deny';
    }
    if ($dir === '/agent/post') {
        // Direct JSON endpoints (they return early when loaded through agent/post.php).
        if ($rmm && strpos($base, 'rmm_') === 0) {
            return 'allow';
        }
        if ($alerts && $base === 'comet_alert.php') {
            return 'allow';
        }
        return 'deny';
    }
    if ($reports && strpos($s, '/agent/reports/') === 0) {
        return 'allow';
    }
    if ($kb && preg_match('#^/agent/modals/kb_(article|category)/#', $s)) {
        return 'allow';
    }
    return 'deny';
}

/**
 * agent/post.php loads every handler in agent/post/*.php. For a limited login it loads only the ones for
 * modules its role holds (each handler still checks its own level). Non-limited logins: all, as before.
 */
function itflow_post_handler_allowed(string $handler_file): bool {
    if (!itflow_is_limited_user()) {
        return true;
    }
    $b = basename($handler_file, '.php');
    if (strpos($b, 'training') === 0) {
        return itflow_level('module_training') >= 1 || itflow_level('module_training_kiosk') >= 1;
    }
    if (strpos($b, 'kb_') === 0) {
        return itflow_level('module_kb') >= 1;
    }
    if (strpos($b, 'rmm_') === 0) {
        return itflow_level('module_rmm') >= 1;
    }
    if ($b === 'comet_alert') {
        return itflow_level('module_rmm_alerts') >= 1;
    }
    return false;
}

/* ------------------------------------------------------------------------------------------------
 * Denials (P0 / P1h): HTTP 403, no leftover flash message, a proper page or JSON
 * ---------------------------------------------------------------------------------------------- */

/** JSON callers: pop-ups, ajax endpoints, fetch/XHR, and anything that already declared a JSON body. */
function itflow_request_wants_json(): bool {
    $s = itflow_request_script();
    if (strpos($s, '/modals/') !== false || preg_match('#/(ajax|crm_ajax|training_ajax)\.php$#', $s)
        || strpos($s, '/api/') === 0) {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return true;
    }
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if ($accept !== '' && strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) {
        return true;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'json') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * The one denial every agent-side check ends in. Never sets a flash message (a denial used to leave
 * "You are not permitted to do that!" behind for the NEXT page). JSON callers get
 * {"ok":false,"error":...} with 403. Page callers get a 403 page inside the normal app shell with a
 * "Go to <home>" button: if the shell is already on the page (the check ran after inc_all.php), only
 * the message and the footer are added.
 *
 * $detail is plain text (it is escaped here). $go = ['url' => ..., 'label' => ...] replaces the "Go to <home>"
 * button (itflow_training_settings_go()).
 */
function itflow_render_denied(string $detail = '', string $title = "You don't have access to this page", ?array $go = null): void {
    // A denial that an earlier code path already queued as a flash must not show up on the next page.
    if (isset($_SESSION['alert_message']) && $_SESSION['alert_message'] === WORDING_ROLECHECK_FAILED) {
        unset($_SESSION['alert_message'], $_SESSION['alert_type']);
    }

    if (itflow_request_wants_json()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }
        echo json_encode(['ok' => false, 'error' => trim($title . '. ' . $detail)]);
        exit;
    }

    if (!headers_sent()) {
        http_response_code(403);
        header('Cache-Control: no-store');
    }

    // Everything the message needs, under names no shell file uses, BEFORE the shell files run.
    $__itflow_denied = [
        'title'  => $title,
        'detail' => $detail !== '' ? $detail : "It isn't part of your role. Ask an administrator if you need it.",
        'home'   => (string) ($go['url'] ?? itflow_home_url()),
        'label'  => (string) ($go['label'] ?? itflow_home_label()),
    ];
    unset($title, $detail, $go);

    // The shell files read globals; make every global visible to them from inside this function.
    foreach (array_keys($GLOBALS) as $__k) {
        if ($__k !== 'GLOBALS' && strpos($__k, '__itflow') !== 0 && !isset($$__k)) {
            $$__k = &$GLOBALS[$__k];
        }
    }
    $__itflow_docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/');
    if (empty($GLOBALS['itflow_shell_open'])) {
        $GLOBALS['itflow_denied_page'] = true;
        require_once $__itflow_docroot . '/includes/page_title.php';
        require_once $__itflow_docroot . '/includes/header.php';
        require_once $__itflow_docroot . '/includes/top_nav.php';
        require_once $__itflow_docroot . '/agent/includes/get_side_nav_counts.php';
        require_once $__itflow_docroot . '/agent/includes/side_nav.php';
        require_once $__itflow_docroot . '/includes/inc_wrapper.php';
    }
    ?>
    <div class="card mt-4 itflow-access-denied" role="alert">
        <div class="card-body text-center py-5">
            <div class="mb-3 text-secondary"><i class="fas fa-lock fa-3x" aria-hidden="true"></i></div>
            <h2 class="h3 mb-2"><?php echo htmlspecialchars($__itflow_denied['title'], ENT_QUOTES); ?></h2>
            <p class="text-muted mb-4"><?php echo htmlspecialchars($__itflow_denied['detail'], ENT_QUOTES); ?></p>
            <a class="btn btn-primary" href="<?php echo htmlspecialchars($__itflow_denied['home'], ENT_QUOTES); ?>"><i class="fas fa-arrow-right me-2" aria-hidden="true"></i>Go to <?php echo htmlspecialchars($__itflow_denied['label'], ENT_QUOTES); ?></a>
        </div>
    </div>
    <?php
    require_once $__itflow_docroot . '/includes/app_version.php';   // footer.php prints it under /admin/
    require_once $__itflow_docroot . '/includes/footer.php';
    exit;
}

/**
 * The denial text for a non-admin Training level 3 login that opens one of the admin Training settings pages
 * (admin/settings_training*.php): its own page is Training > Training settings (agent/training_settings.php,
 * roles audit P2). '' for everyone and everything else.
 */
function itflow_training_settings_hint(): string {
    global $session_is_admin, $config_module_enable_training;
    if (!empty($session_is_admin) || intval($config_module_enable_training ?? 0) !== 1
        || strpos(itflow_request_script(), '/admin/settings_training') === false || itflow_level('module_training') < 3) {
        return '';
    }
    return 'Administration is for administrators only. Your Training settings are under Training > Training settings.';
}

/** The denial's button for that case: "Go to Training settings" instead of the home page. null otherwise. */
function itflow_training_settings_go(): ?array {
    return itflow_training_settings_hint() !== '' ? ['url' => '/agent/training_settings.php', 'label' => 'Training settings'] : null;
}

/** Deny unless the role holds Assets or Tickets/assets/docs at $level (P4). */
function enforceAssetPermission(int $level = 1): void {
    if (!itflow_can_assets($level)) {
        itflow_render_denied(
            'Your role needs ' . itflow_level_label($level) . ' access to Assets (or Tickets, assets & docs). Ask an administrator if you need it.'
        );
    }
}

/* ------------------------------------------------------------------------------------------------
 * PER-USER helpers (API tokens, notifications to other users, cron) - no session needed
 * ---------------------------------------------------------------------------------------------- */

/**
 * ['user_id', 'role_id', 'admin' => bool, 'levels' => [module_name => level]] for any agent user id.
 * Cached per request. Unknown user: not admin, no levels (fails closed).
 */
function itflow_user_access_profile(int $user_id): array {
    global $mysqli;
    static $cache = [];
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }
    $p = ['user_id' => $user_id, 'role_id' => 0, 'admin' => false, 'levels' => []];
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT u.user_role_id, r.role_is_admin FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id
         WHERE u.user_id = " . intval($user_id) . " LIMIT 1"));
    if ($row) {
        $p['role_id'] = intval($row['user_role_id']);
        $p['admin'] = intval($row['role_is_admin'] ?? 0) === 1;
        $res = mysqli_query($mysqli,
            "SELECT m.module_name, urp.user_role_permission_level FROM user_role_permissions urp
             JOIN modules m ON m.module_id = urp.module_id WHERE urp.user_role_id = " . $p['role_id']);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $p['levels'][$r['module_name']] = intval($r['user_role_permission_level']);
        }
    }
    return $cache[$user_id] = $p;
}

function itflow_profile_level(array $profile, string $module): int {
    return !empty($profile['admin']) ? 3 : intval($profile['levels'][$module] ?? 0);
}

function itflow_profile_is_limited(array $profile): bool {
    if (!empty($profile['admin'])) {
        return false;
    }
    foreach (ITFLOW_FULL_AGENT_MODULES as $m) {
        if (itflow_profile_level($profile, $m) >= 1) {
            return false;
        }
    }
    return true;
}

function itflow_profile_can_assets(array $profile, int $level = 1): bool {
    return itflow_profile_level($profile, 'module_assets') >= $level || itflow_profile_level($profile, 'module_support') >= $level;
}

/** Every module in the modules table with this profile's level (0 = none). For the app API's "me". */
function itflow_profile_permission_map(array $profile): array {
    global $mysqli;
    $map = [];
    $res = mysqli_query($mysqli, "SELECT module_name FROM modules ORDER BY module_id");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $map[$r['module_name']] = itflow_profile_level($profile, $r['module_name']);
    }
    return $map;
}

/* ------------------------------------------------------------------------------------------------
 * Notifications (P0): a limited login only gets notification types for modules it holds
 * ---------------------------------------------------------------------------------------------- */

/**
 * Notification types a LIMITED profile may receive, by includes/notification_categories.php category plus
 * the types that have no category. Full agents are never filtered (null = everything).
 */
function itflow_notification_types_for_profile(array $profile): ?array {
    if (!itflow_profile_is_limited($profile)) {
        return null;
    }
    $lvl = fn(string $m) => itflow_profile_level($profile, $m);
    $category_allowed = [
        'tickets'     => $lvl('module_support') >= 1,
        'invoices'    => $lvl('module_sales') >= 1 || $lvl('module_financial') >= 1,
        'quotes'      => $lvl('module_sales') >= 1,
        'expirations' => $lvl('module_support') >= 1,
        'backups'     => $lvl('module_rmm') >= 1,
        'system'      => false,   // admin-only; admins are never limited
    ];
    $types = [];
    foreach (push_notification_categories() as $key => $cat) {
        if (!empty($category_allowed[$key])) {
            $types = array_merge($types, $cat['types']);
        }
    }
    if ($lvl('module_training') >= 1 || $lvl('module_training_kiosk') >= 1) {
        $types[] = 'Training';
    }
    if ($lvl('module_sales') >= 1) {
        $types[] = 'CRM Follow-up';
    }
    return array_values(array_unique($types));
}

function itflow_notification_allowed_for_user(int $user_id, string $type): bool {
    $types = itflow_notification_types_for_profile(itflow_user_access_profile($user_id));
    return $types === null || in_array($type, $types, true);
}

/**
 * SQL to AND onto a notifications query for $user_id: '' for full agents, an IN (...) type filter for a
 * limited login (a never-true filter when it may receive nothing).
 */
function itflow_notification_type_sql(int $user_id, string $column = 'notification_type'): string {
    global $mysqli;
    $types = itflow_notification_types_for_profile(itflow_user_access_profile($user_id));
    if ($types === null) {
        return '';
    }
    if (!$types) {
        return ' AND 1 = 0';
    }
    $esc = array_map(fn($t) => "'" . mysqli_real_escape_string($mysqli, $t) . "'", $types);
    return " AND $column IN (" . implode(',', $esc) . ')';
}
