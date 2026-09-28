<?php
/*
 * Device Performance ("Metrics") tab.
 *
 * NAMING: this subsystem is Metrics, never "telemetry". `config_telemetry` already
 * exists in this codebase and means anonymous phone-home usage reporting
 * (admin/settings_telemetry.php), so the two never share vocabulary or URL space.
 *
 * WHAT THIS FILE IS
 * -----------------
 * A partial, required by agent/asset_details.php from inside the existing RMM
 * `.tab-content` block. It renders the whole Performance pane: a summary card row,
 * a range selector, and a stack of chart cards. js/asset_metrics.js fills in the
 * numbers and draws the charts; this file decides *what exists*.
 *
 * CAPABILITY AWARENESS IS THE POINT
 * ---------------------------------
 * Three device states are rendered as three visually distinct things and are never
 * conflated:
 *
 *   1. "this device cannot produce that metric"  -> the card and the chart are
 *      absent from the DOM entirely. A Level.io box that only reports disk and
 *      uptime shows two cards and two charts and looks deliberate, not broken. A
 *      Sophos firewall entry shows whatever it actually has and nothing else.
 *   2. "supported, but nothing collected yet"    -> an explicit empty state that
 *      says why (collection disabled / never collected / last collector error).
 *   3. "the device is offline"                   -> an explicit banner above the
 *      charts, with the last-seen time. History still renders below it.
 *
 * The capability set is range-independent (it is derived from the whole retained
 * history plus the day rollups), so it is computed once here, server-side, and the
 * layout never reflows when the user changes range. A metric that exists but has no
 * points *inside the selected range* is a per-chart overlay, handled in JS.
 *
 * DUAL MODE
 * ---------
 * Required by asset_details.php -> renders HTML.
 * Requested directly over HTTP  -> answers JSON, for js/asset_metrics.js.
 *
 * The read API at /api/v1/metrics/... is Bearer-token authenticated and there is no
 * session-cookie path into api/v1/index.php, so a browser page inside a RivetIT
 * session cannot call it. Rather than mint an API token for a logged-in web session
 * (a real credential, for a read a session is already entitled to), this file serves
 * its own JSON off the existing session: check_login.php, module_rmm >= read, the
 * user's client scope, and the session CSRF token. It reuses
 * ITFlow\Metrics\MetricQueryService - the same service api/v1/metrics.php uses - so
 * there is exactly one query implementation, and it emits the same
 * {"data":...,"total":...} envelope the API does.
 *
 * The JS reads its endpoint from a data attribute, so an integrator who would rather
 * proxy through agent/post/ or api/v1 sets $metrics_tab_endpoint before requiring
 * this file and changes nothing else.
 *
 * TIME: every timestamp in the JSON is UTC with an explicit `Z`, because
 * device_metric_samples.sampled_at is stored in UTC - a deliberate divergence from
 * the rest of RivetIT, which stores local time. The browser converts for display.
 * Nothing in this file converts.
 *
 * Integration variables (all optional, set before the require):
 *   $metrics_tab_asset_id    int     defaults to $asset_id from asset_details.php
 *   $metrics_tab_endpoint    string  JSON endpoint URL, defaults to this file
 *   $metrics_tab_render_pane bool    wrap output in <div class="tab-pane" id="rdt-metrics">
 *   $metrics_tab_pane_active bool    add `active` to that pane
 */

// --------------------------------------------------------------------- bootstrap

$metrics_tab_file  = realpath(__FILE__);
$metrics_tab_root  = dirname(__FILE__, 4);
$metrics_tab_entry = isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string) $_SERVER['SCRIPT_FILENAME']) : false;
$metrics_tab_direct = ($metrics_tab_file !== false && $metrics_tab_entry !== false && $metrics_tab_entry === $metrics_tab_file);

if ($metrics_tab_direct) {
    if (empty($_SERVER['DOCUMENT_ROOT'])) {
        $_SERVER['DOCUMENT_ROOT'] = $metrics_tab_root;
    }
    require_once $metrics_tab_root . '/config.php';
    require_once $metrics_tab_root . '/functions.php';
    // check_login.php pulls in session_init, auth_check, load_user_session,
    // load_company_settings, load_global_settings and inc_set_timezone.
    require_once $metrics_tab_root . '/includes/check_login.php';
}

// Only includes/redis_functions.php pulls in the Composer autoloader today, so it is
// ensured explicitly rather than assumed - in either mode.
if (!class_exists('ITFlow\\Metrics\\MetricQueryService')) {
    $metrics_tab_autoload = $metrics_tab_root . '/vendor/autoload.php';
    if (is_readable($metrics_tab_autoload)) {
        require_once $metrics_tab_autoload;
    }
}

// render_stat_card()/render_empty_state() come from functions.php via
// includes/ui/components.php; guard in case this partial is reached first.
if (!function_exists('render_stat_card') || !function_exists('render_empty_state')) {
    $metrics_tab_components = $metrics_tab_root . '/includes/ui/components.php';
    if (is_readable($metrics_tab_components)) {
        require_once $metrics_tab_components;
    }
}

// ------------------------------------------------------------- shared definitions

if (!function_exists('metrics_tab_ranges')) {
    /**
     * The range selector. Seconds are what the JSON endpoint turns into a window;
     * MetricQueryService::chooseResolution() then picks raw / hour / day so that no
     * series exceeds its point cap (1h..24h land on raw 5-minute samples, 7d and 30d
     * on hour rollups, 90d on day rollups).
     *
     * @return array<string,array{label:string,seconds:int}>
     */
    function metrics_tab_ranges(): array
    {
        return [
            '1h'  => ['label' => '1h',  'seconds' => 3600],
            '6h'  => ['label' => '6h',  'seconds' => 21600],
            '24h' => ['label' => '24h', 'seconds' => 86400],
            '7d'  => ['label' => '7d',  'seconds' => 604800],
            '30d' => ['label' => '30d', 'seconds' => 2592000],
            '90d' => ['label' => '90d', 'seconds' => 7776000],
        ];
    }
}

if (!function_exists('metrics_tab_default_range')) {
    function metrics_tab_default_range(): string
    {
        return '24h';
    }
}

if (!function_exists('metrics_tab_groups')) {
    /**
     * Chart groups. One group is one canvas. A group may draw more than one metric
     * key when the keys share a unit and belong together (used/available/total
     * memory, rx/tx throughput); each key still contributes one dataset per
     * instance, so a four-volume machine gets four disk lines.
     *
     * 'headline' names the key the current/average/peak readout is computed from
     * when a group draws several keys - a "peak" over {used, available, total} bytes
     * would silently report the total and mean nothing. null means "across every
     * series in the group", which is the honest answer for read+write IOPS.
     *
     * 'axis': percent = pinned 0-100, bool = 0/1 with No/Yes ticks, auto = zero-based.
     *
     * @return array<int,array<string,mixed>>
     */
    function metrics_tab_groups(): array
    {
        return [
            ['id' => 'cpu',        'family' => 'CPU',     'title' => 'CPU utilization',      'keys' => ['cpu.utilization'],                                            'headline' => 'cpu.utilization',         'axis' => 'percent'],
            ['id' => 'cpu-cores',  'family' => 'CPU',     'title' => 'Per-core utilization', 'keys' => ['cpu.core.utilization'],                                       'headline' => 'cpu.core.utilization',    'axis' => 'percent'],
            ['id' => 'mem',        'family' => 'Memory',  'title' => 'Memory utilization',   'keys' => ['memory.utilization'],                                         'headline' => 'memory.utilization',      'axis' => 'percent'],
            ['id' => 'mem-bytes',  'family' => 'Memory',  'title' => 'Memory footprint',     'keys' => ['memory.used_bytes', 'memory.available_bytes', 'memory.total_bytes'], 'headline' => 'memory.used_bytes', 'axis' => 'auto'],
            ['id' => 'disk',       'family' => 'Storage', 'title' => 'Disk used',            'keys' => ['disk.utilization'],                                           'headline' => 'disk.utilization',        'axis' => 'percent'],
            ['id' => 'disk-bytes', 'family' => 'Storage', 'title' => 'Disk space',           'keys' => ['disk.free_bytes', 'disk.total_bytes'],                         'headline' => 'disk.free_bytes',         'axis' => 'auto'],
            ['id' => 'disk-iops',  'family' => 'Storage', 'title' => 'Disk IOPS',            'keys' => ['disk.read_iops', 'disk.write_iops'],                           'headline' => null,                      'axis' => 'auto'],
            ['id' => 'disk-queue', 'family' => 'Storage', 'title' => 'Disk queue length',    'keys' => ['disk.queue_length'],                                          'headline' => 'disk.queue_length',       'axis' => 'auto'],
            ['id' => 'disk-lat',   'family' => 'Storage', 'title' => 'Disk latency',         'keys' => ['disk.latency_ms'],                                            'headline' => 'disk.latency_ms',         'axis' => 'auto'],
            ['id' => 'net',        'family' => 'Network', 'title' => 'Network throughput',   'keys' => ['network.rx_bytes_per_s', 'network.tx_bytes_per_s'],            'headline' => null,                      'axis' => 'auto'],
            ['id' => 'net-err',    'family' => 'Network', 'title' => 'Network errors',       'keys' => ['network.errors'],                                             'headline' => 'network.errors',          'axis' => 'auto'],
            ['id' => 'gpu',        'family' => 'GPU',     'title' => 'GPU utilization',      'keys' => ['gpu.utilization'],                                            'headline' => 'gpu.utilization',         'axis' => 'percent'],
            ['id' => 'gpu-mem',    'family' => 'GPU',     'title' => 'GPU memory',           'keys' => ['gpu.memory_used_bytes', 'gpu.memory_total_bytes'],             'headline' => 'gpu.memory_used_bytes',   'axis' => 'auto'],
            ['id' => 'gpu-temp',   'family' => 'GPU',     'title' => 'GPU temperature',      'keys' => ['gpu.temperature'],                                            'headline' => 'gpu.temperature',         'axis' => 'auto'],
            ['id' => 'battery',    'family' => 'Battery', 'title' => 'Battery',              'keys' => ['battery.charge_percent', 'battery.health_percent'],            'headline' => 'battery.charge_percent',  'axis' => 'percent'],
            ['id' => 'uptime',     'family' => 'System',  'title' => 'Uptime',               'keys' => ['system.uptime_seconds'],                                       'headline' => 'system.uptime_seconds',   'axis' => 'auto'],
            ['id' => 'reboot',     'family' => 'System',  'title' => 'Pending reboot',       'keys' => ['system.pending_reboot'],                                       'headline' => 'system.pending_reboot',   'axis' => 'bool'],
        ];
    }
}

if (!function_exists('metrics_tab_cards')) {
    /**
     * Summary tiles, one per metric family. 'keys' is a preference order: the first
     * key the device actually reports wins, so a machine with no host-level
     * memory.utilization but a collector-supplied memory.used_bytes still gets a
     * Memory tile. A family with no reported key gets no tile at all.
     *
     * @return array<int,array<string,mixed>>
     */
    function metrics_tab_cards(): array
    {
        return [
            ['id' => 'cpu',     'label' => 'CPU',       'icon' => 'fas fa-microchip',     'tint' => 'primary', 'keys' => ['cpu.utilization', 'cpu.core.utilization']],
            ['id' => 'memory',  'label' => 'Memory',    'icon' => 'fas fa-memory',        'tint' => 'info',    'keys' => ['memory.utilization', 'memory.used_bytes']],
            ['id' => 'disk',    'label' => 'Disk used', 'icon' => 'fas fa-hdd',           'tint' => 'warning', 'keys' => ['disk.utilization']],
            ['id' => 'network', 'label' => 'Network in','icon' => 'fas fa-network-wired', 'tint' => 'violet',  'keys' => ['network.rx_bytes_per_s']],
            ['id' => 'gpu',     'label' => 'GPU',       'icon' => 'fas fa-desktop',       'tint' => 'success', 'keys' => ['gpu.utilization']],
            ['id' => 'battery', 'label' => 'Battery',   'icon' => 'fas fa-battery-half',  'tint' => 'success', 'keys' => ['battery.charge_percent']],
            ['id' => 'uptime',  'label' => 'Uptime',    'icon' => 'fas fa-clock',         'tint' => 'slate',   'keys' => ['system.uptime_seconds']],
        ];
    }
}

if (!function_exists('metrics_tab_asset_in_scope')) {
    /**
     * Existence + the caller's client scope in one query, mirroring the agent
     * portal's $access_permission_query (which is written against `clients`).
     * An out-of-scope asset is indistinguishable from a missing one.
     */
    function metrics_tab_asset_in_scope(\mysqli $mysqli, int $asset_id): bool
    {
        global $access_permission_query;
        $asset_id = (int) $asset_id;
        if ($asset_id < 1) {
            return false;
        }
        $scope = isset($access_permission_query) ? (string) $access_permission_query : '';
        $res = mysqli_query($mysqli,
            "SELECT assets.asset_id
             FROM assets
             LEFT JOIN clients ON clients.client_id = assets.asset_client_id
             WHERE assets.asset_id = $asset_id
               AND assets.asset_archived_at IS NULL
               $scope
             LIMIT 1"
        );
        return $res !== false && mysqli_num_rows($res) === 1;
    }
}

if (!function_exists('metrics_tab_device_row')) {
    /**
     * Read-only peek at the RMM link, purely so the UI can say "offline" out loud
     * instead of drawing a flat line and letting the technician guess.
     *
     * includes/class_rmm_asset_mapper.php owns assets and asset_rmm_links
     * exclusively - nothing here writes to either table.
     *
     * @return array<string,mixed>
     */
    function metrics_tab_device_row(\mysqli $mysqli, int $asset_id): array
    {
        $asset_id = (int) $asset_id;
        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT arl.hostname, arl.rmm_status, arl.last_seen,
                    i.name AS integration_name, i.type AS integration_type
             FROM asset_rmm_links arl
             LEFT JOIN rmm_integrations i ON i.id = arl.integration_id
             WHERE arl.asset_id = $asset_id
             ORDER BY arl.id
             LIMIT 1"
        ));
        if (!is_array($row)) {
            return ['linked' => false, 'status' => 'unknown', 'hostname' => null,
                    'last_seen' => null, 'integration_name' => null, 'integration_type' => null];
        }
        $status = strtolower((string) ($row['rmm_status'] ?? ''));
        if ($status !== 'online' && $status !== 'offline') {
            $status = 'unknown';
        }
        return [
            'linked'           => true,
            'status'           => $status,
            'hostname'         => $row['hostname'],
            // asset_rmm_links.last_seen follows the app's local-time convention,
            // unlike every sample timestamp in this payload. Flagged, not converted.
            'last_seen'        => $row['last_seen'],
            'integration_name' => $row['integration_name'],
            'integration_type' => $row['integration_type'],
        ];
    }
}

if (!function_exists('metrics_tab_capabilities')) {
    /**
     * MetricQueryService::capabilities() plus the two things the chrome needs that
     * the service does not own: whether collection is switched on at all, and
     * whether the device is currently reachable.
     *
     * @return array<string,mixed>
     */
    function metrics_tab_capabilities(\mysqli $mysqli, int $asset_id): array
    {
        global $config_enable_device_metrics;

        $service = new \ITFlow\Metrics\MetricQueryService($mysqli);
        $caps = $service->capabilities($asset_id);

        // config_enable_device_metrics gates COLLECTION, not reading history, so a
        // disabled instance still shows whatever was gathered before it was turned
        // off - but says so. null when the settings loader does not expose it yet.
        $caps['collection_enabled'] = isset($config_enable_device_metrics)
            ? (intval($config_enable_device_metrics) === 1)
            : null;
        $caps['device'] = metrics_tab_device_row($mysqli, $asset_id);

        return $caps;
    }
}

if (!function_exists('metrics_tab_status_of')) {
    /**
     * metric_key => 'available' | 'stale' | 'unavailable', from a capabilities blob.
     *
     * @param array<string,mixed> $caps
     * @return array<string,string>
     */
    function metrics_tab_status_of(array $caps): array
    {
        $out = [];
        foreach ((array) ($caps['metrics'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['key'])) {
                continue;
            }
            $out[(string) $entry['key']] = (string) ($entry['status'] ?? 'unavailable');
        }
        return $out;
    }
}

if (!function_exists('metrics_tab_spec_of')) {
    /**
     * metric_key => the capability entry, so the renderer can read unit/display
     * without a second registry lookup.
     *
     * @param array<string,mixed> $caps
     * @return array<string,array<string,mixed>>
     */
    function metrics_tab_spec_of(array $caps): array
    {
        $out = [];
        foreach ((array) ($caps['metrics'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $out[(string) $entry['key']] = $entry;
            }
        }
        return $out;
    }
}

// ------------------------------------------------------------------- direct mode

if ($metrics_tab_direct) {

    if (!function_exists('metrics_tab_json')) {
        function metrics_tab_json(int $code, array $payload): void
        {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            echo json_encode($payload);
            exit;
        }
    }

    if (!class_exists('ITFlow\\Metrics\\MetricQueryService')) {
        metrics_tab_json(500, ['error' => 'Metrics subsystem is not installed']);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        metrics_tab_json(405, ['error' => 'Method not allowed']);
    }

    /*
     * CSRF on a read is belt-and-braces, but this endpoint is same-origin-only by
     * design and the token costs one header. validateCSRFToken() is deliberately not
     * used: it redirects to index.php on failure, which would hand a fetch() an HTML
     * login page instead of a status code.
     */
    $metrics_tab_token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? '');
    $metrics_tab_session_token = (string) ($_SESSION['csrf_token'] ?? '');
    if ($metrics_tab_session_token === '' || !hash_equals($metrics_tab_session_token, $metrics_tab_token)) {
        metrics_tab_json(403, ['error' => 'CSRF token verification failed. Reload the page.']);
    }

    // enforceUserPermission() also redirects; check the level directly.
    if (intval(lookupUserPermission('module_rmm')) < 1) {
        metrics_tab_json(403, ['error' => 'You do not have permission to read device metrics']);
    }

    $metrics_tab_asset = intval($_GET['asset_id'] ?? 0);
    if ($metrics_tab_asset < 1) {
        metrics_tab_json(400, ['error' => 'A numeric asset_id is required']);
    }
    if (!metrics_tab_asset_in_scope($mysqli, $metrics_tab_asset)) {
        metrics_tab_json(404, ['error' => 'Asset not found']);
    }

    $metrics_tab_action = (string) ($_GET['action'] ?? 'capabilities');

    if ($metrics_tab_action === 'capabilities') {
        try {
            $metrics_tab_caps = metrics_tab_capabilities($mysqli, $metrics_tab_asset);
        } catch (\Throwable $e) {
            metrics_tab_json(500, ['error' => 'Unable to read metric capabilities']);
        }
        metrics_tab_json(200, [
            'data'  => $metrics_tab_caps,
            'total' => intval($metrics_tab_caps['metric_count'] ?? 0),
        ]);
    }

    if ($metrics_tab_action !== 'batch') {
        metrics_tab_json(404, ['error' => 'Unknown action. Use capabilities or batch.']);
    }

    $metrics_tab_range_key = (string) ($_GET['range'] ?? metrics_tab_default_range());
    $metrics_tab_range_set = metrics_tab_ranges();
    if (!isset($metrics_tab_range_set[$metrics_tab_range_key])) {
        metrics_tab_json(400, ['error' => 'Unknown range. Use one of: ' . implode(', ', array_keys($metrics_tab_range_set))]);
    }
    $metrics_tab_span = (int) $metrics_tab_range_set[$metrics_tab_range_key]['seconds'];

    $metrics_tab_keys = [];
    foreach (explode(',', (string) ($_GET['metrics'] ?? '')) as $metrics_tab_k) {
        $metrics_tab_k = trim($metrics_tab_k);
        if ($metrics_tab_k !== ''
            && \ITFlow\Metrics\MetricRegistry::exists($metrics_tab_k)
            && !in_array($metrics_tab_k, $metrics_tab_keys, true)) {
            $metrics_tab_keys[] = $metrics_tab_k;
        }
    }
    if ($metrics_tab_keys === []) {
        metrics_tab_json(400, ['error' => 'The "metrics" parameter is required: a comma-separated list of known metric keys']);
    }

    // UTC, always. device_metric_samples.sampled_at is stored in UTC - a deliberate
    // divergence from the app's local-time convention (see MetricIngestService).
    $metrics_tab_to   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $metrics_tab_from = $metrics_tab_to->modify('-' . $metrics_tab_span . ' seconds');

    try {
        $metrics_tab_result = (new \ITFlow\Metrics\MetricQueryService($mysqli))->queryBatch(
            $metrics_tab_asset,
            $metrics_tab_keys,
            $metrics_tab_from,
            $metrics_tab_to,
            \ITFlow\Metrics\MetricQueryService::RES_AUTO
        );
    } catch (\InvalidArgumentException $e) {
        metrics_tab_json(400, ['error' => $e->getMessage()]);
    } catch (\OverflowException $e) {
        // A hard rejection, not a silently clipped payload: a chart drawn from
        // truncated data looks like an outage that never happened.
        metrics_tab_json(400, ['error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        metrics_tab_json(500, ['error' => 'Unable to read metrics']);
    }

    $metrics_tab_result['range']        = $metrics_tab_range_key;
    $metrics_tab_result['range_label']  = $metrics_tab_range_set[$metrics_tab_range_key]['label'];
    $metrics_tab_result['generated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $metrics_tab_result['device']       = metrics_tab_device_row($mysqli, $metrics_tab_asset);

    metrics_tab_json(200, [
        'data'  => $metrics_tab_result,
        'total' => count($metrics_tab_result['metrics']),
    ]);
}

// ------------------------------------------------------------------ render mode

if (!isset($mysqli) || !($mysqli instanceof \mysqli)) {
    return;
}
if (intval(lookupUserPermission('module_rmm')) < 1) {
    return;
}

$mt_asset_id = 0;
if (isset($metrics_tab_asset_id)) {
    $mt_asset_id = intval($metrics_tab_asset_id);
} elseif (isset($asset_id)) {
    $mt_asset_id = intval($asset_id);
}
if ($mt_asset_id < 1) {
    return;
}

$mt_render_pane = isset($metrics_tab_render_pane) ? (bool) $metrics_tab_render_pane : true;
$mt_pane_active = isset($metrics_tab_pane_active) ? (bool) $metrics_tab_pane_active : false;

// Endpoint default: this very file, addressed by its path under the web root.
if (isset($metrics_tab_endpoint) && $metrics_tab_endpoint !== '') {
    $mt_endpoint = (string) $metrics_tab_endpoint;
} else {
    $mt_web_root = realpath($metrics_tab_root);
    $mt_endpoint = ($mt_web_root !== false && $metrics_tab_file !== false && strpos($metrics_tab_file, $mt_web_root) === 0)
        ? '/' . ltrim(str_replace('\\', '/', substr($metrics_tab_file, strlen($mt_web_root))), '/')
        : '/agent/includes/asset/metrics_tab.php';
}

$mt_metrics_installed = class_exists('ITFlow\\Metrics\\MetricQueryService');
$mt_caps = null;
$mt_caps_error = '';
if ($mt_metrics_installed) {
    try {
        $mt_caps = metrics_tab_capabilities($mysqli, $mt_asset_id);
    } catch (\Throwable $mt_e) {
        $mt_caps = null;
        $mt_caps_error = 'Device metrics could not be read from the database.';
    }
}

$mt_status   = $mt_caps === null ? [] : metrics_tab_status_of($mt_caps);
$mt_specs    = $mt_caps === null ? [] : metrics_tab_spec_of($mt_caps);
$mt_device   = $mt_caps === null ? ['linked' => false, 'status' => 'unknown', 'last_seen' => null,
                                    'hostname' => null, 'integration_name' => null, 'integration_type' => null]
                                 : (array) $mt_caps['device'];
$mt_has_data = $mt_caps !== null && !empty($mt_caps['has_any_data']);

/** A key is renderable when the device has ever produced it. */
$mt_supported = static function (string $key) use ($mt_status): bool {
    return isset($mt_status[$key]) && $mt_status[$key] !== 'unavailable';
};

// Groups reduced to the keys this device actually reports.
$mt_groups = [];
$mt_request_keys = [];
foreach (metrics_tab_groups() as $mt_group) {
    $mt_live_keys = array_values(array_filter($mt_group['keys'], $mt_supported));
    if ($mt_live_keys === []) {
        continue;   // state 1: unsupported -> absent from the DOM entirely
    }
    $mt_group['keys'] = $mt_live_keys;
    if ($mt_group['headline'] !== null && !in_array($mt_group['headline'], $mt_live_keys, true)) {
        $mt_group['headline'] = null;
    }
    // Unit/precision for the axis come from the first live key in the group.
    $mt_head_spec = $mt_specs[$mt_live_keys[0]] ?? [];
    $mt_group['unit']      = (string) ($mt_head_spec['unit'] ?? '');
    $mt_group['precision'] = intval($mt_head_spec['precision'] ?? 0);
    $mt_groups[] = $mt_group;
    foreach ($mt_live_keys as $mt_live_key) {
        if (!in_array($mt_live_key, $mt_request_keys, true)) {
            $mt_request_keys[] = $mt_live_key;
        }
    }
}

// Summary tiles: first reported key per family wins.
$mt_cards = [];
foreach (metrics_tab_cards() as $mt_card) {
    foreach ($mt_card['keys'] as $mt_card_key) {
        if ($mt_supported($mt_card_key)) {
            $mt_card['key']    = $mt_card_key;
            $mt_card['status'] = $mt_status[$mt_card_key];
            $mt_cards[] = $mt_card;
            break;
        }
    }
}

$mt_reported_count = 0;
foreach ($mt_status as $mt_s) {
    if ($mt_s !== 'unavailable') {
        $mt_reported_count++;
    }
}
$mt_total_count = $mt_caps === null ? 0 : intval($mt_caps['metric_count'] ?? 0);

$mt_ranges      = metrics_tab_ranges();
$mt_range       = metrics_tab_default_range();
$mt_uid         = 'ifm-' . $mt_asset_id;
$mt_nonce       = htmlspecialchars($csp_nonce ?? '', ENT_QUOTES);
$mt_csrf        = (string) ($_SESSION['csrf_token'] ?? '');
$mt_provider    = (string) ($mt_device['integration_name'] ?? '') !== ''
    ? (string) $mt_device['integration_name']
    : (['tactical_rmm' => 'Tactical RMM', 'level' => 'Level.io', 'action1' => 'Action1',
        'sophos_central' => 'Sophos Central'][(string) ($mt_device['integration_type'] ?? '')] ?? 'RMM');

// The bootstrap blob makes the first paint correct with zero round trips; the JS
// still re-reads capabilities on an explicit refresh. JSON_HEX_TAG is what keeps a
// "</script>" inside an instance label from ending this element early.
$mt_bootstrap = json_encode(
    $mt_caps === null ? ['metrics' => [], 'has_any_data' => false] : $mt_caps,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($mt_bootstrap === false) {
    $mt_bootstrap = '{"metrics":[],"has_any_data":false}';
}

if ($mt_render_pane): ?>
<div class="tab-pane<?= $mt_pane_active ? ' active' : '' ?> p-3" id="rdt-metrics">
<?php endif; ?>

<div class="ifm-wrap"
     id="<?= htmlspecialchars($mt_uid, ENT_QUOTES) ?>"
     data-asset-metrics
     data-asset-id="<?= $mt_asset_id ?>"
     data-endpoint="<?= htmlspecialchars($mt_endpoint, ENT_QUOTES) ?>"
     data-csrf="<?= htmlspecialchars($mt_csrf, ENT_QUOTES) ?>"
     data-range="<?= htmlspecialchars($mt_range, ENT_QUOTES) ?>"
     data-metric-keys="<?= htmlspecialchars(implode(',', $mt_request_keys), ENT_QUOTES) ?>"
     data-stale-seconds="<?= intval($mt_caps['stale_after_seconds'] ?? 7200) ?>"
     data-device-status="<?= htmlspecialchars((string) $mt_device['status'], ENT_QUOTES) ?>">

    <script type="application/json" data-role="metrics-bootstrap" nonce="<?= $mt_nonce ?>"><?= $mt_bootstrap ?></script>

<?php if (!$mt_metrics_installed): ?>

    <?php render_empty_state(
        'fas fa-plug',
        'Device metrics are not installed',
        'The ITFlow\\Metrics classes could not be autoloaded. Run composer install on this instance, then reload this page.'
    ); ?>

<?php elseif ($mt_caps_error !== ''): ?>

    <?php render_empty_state('fas fa-exclamation-triangle', 'Metrics unavailable', $mt_caps_error); ?>

<?php else: ?>

    <?php /* State 3: offline. Explicit, and never conflated with "no data". */ ?>
    <?php if ($mt_device['status'] === 'offline'): ?>
    <div class="ifm-banner ifm-banner-offline" role="status">
        <i class="fas fa-plug ifm-banner-icon"></i>
        <div>
            <strong>This device is offline.</strong>
            <?php if (!empty($mt_device['last_seen'])): ?>
                <?= nullable_htmlentities($mt_provider) ?> last saw it at
                <span class="ifm-mono"><?= nullable_htmlentities(substr((string) $mt_device['last_seen'], 0, 16)) ?></span>.
            <?php else: ?>
                <?= nullable_htmlentities($mt_provider) ?> has not reported a last-seen time.
            <?php endif; ?>
            Charts below show history up to that point; they are not being extended.
        </div>
    </div>
    <?php endif; ?>

    <?php if ($mt_caps['collection_enabled'] === false): ?>
    <div class="ifm-banner ifm-banner-warn" role="status">
        <i class="fas fa-pause ifm-banner-icon"></i>
        <div>
            <strong>Metric collection is switched off instance-wide.</strong>
            Existing history is still shown. Enable <span class="ifm-mono">Device metrics</span> in
            Settings to resume collection.
        </div>
    </div>
    <?php endif; ?>

    <?php /* State 2: supported, nothing collected. Says why, rather than showing empty axes. */ ?>
    <?php if (!$mt_has_data):
        $mt_reason = 'No samples have been recorded for this device yet.';
        if ($mt_caps['collection_enabled'] === false) {
            $mt_reason = 'Metric collection is switched off instance-wide, so nothing has been recorded for this device yet.';
        } elseif (!$mt_device['linked']) {
            $mt_reason = 'This asset is not linked to an RMM integration, so there is nothing to collect from. Link it from the RMM Overview tab.';
        } else {
            foreach ((array) ($mt_caps['collection_state'] ?? []) as $mt_cs) {
                if (!empty($mt_cs['last_error'])) {
                    $mt_reason = 'The collector last failed with: ' . (string) $mt_cs['last_error'];
                    break;
                }
            }
        }
        // A collector error is pasted in verbatim and may not end in punctuation.
        if (!preg_match('/[.!?]$/', $mt_reason)) {
            $mt_reason .= '.';
        }
        render_empty_state(
            'fas fa-chart-line',
            'No performance history yet',
            $mt_reason . ' Charts appear here once the collector has run at least twice.'
        );
        ?>
    <?php else: ?>

    <div class="ifm-toolbar">
        <div class="ifm-toolbar-left">
            <span class="ifm-toolbar-label">Range</span>
            <div class="btn-group btn-group-sm ifm-ranges" role="group" aria-label="Metric time range">
                <?php foreach ($mt_ranges as $mt_rk => $mt_rv): ?>
                <button type="button"
                        class="btn btn-outline-secondary<?= $mt_rk === $mt_range ? ' active' : '' ?>"
                        data-role="range"
                        data-range="<?= htmlspecialchars($mt_rk, ENT_QUOTES) ?>"
                        aria-pressed="<?= $mt_rk === $mt_range ? 'true' : 'false' ?>"><?= nullable_htmlentities($mt_rv['label']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ifm-toolbar-right">
            <span class="ifm-meta" data-role="status" aria-live="polite"></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-role="refresh" title="Reload metrics">
                <i class="fas fa-sync-alt"></i><span class="visually-hidden">Refresh</span>
            </button>
        </div>
    </div>

    <div class="ifm-alert" data-role="error" hidden role="alert"></div>

    <?php if ($mt_cards): ?>
    <div class="ifm-cards">
        <?php foreach ($mt_cards as $mt_card):
            $mt_card_spec = $mt_specs[$mt_card['key']] ?? [];
            ?>
        <div class="ifm-card<?= $mt_card['status'] === 'stale' ? ' ifm-card-stale' : '' ?>"
             data-metric-card
             data-metric-key="<?= htmlspecialchars($mt_card['key'], ENT_QUOTES) ?>"
             data-unit="<?= htmlspecialchars((string) ($mt_card_spec['unit'] ?? ''), ENT_QUOTES) ?>"
             data-precision="<?= intval($mt_card_spec['precision'] ?? 0) ?>">
            <?php render_stat_card($mt_card['label'], '--', $mt_card['icon'], $mt_card['tint']); ?>
            <div class="ifm-card-meta">
                <span>avg <b data-role="avg">--</b></span>
                <span>peak <b data-role="peak">--</b></span>
                <?php if ($mt_card['status'] === 'stale'): ?>
                <span class="ifm-chip ifm-chip-stale" title="No sample within the staleness window">stale</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    $mt_current_family = null;
    foreach ($mt_groups as $mt_group):
        if ($mt_group['family'] !== $mt_current_family):
            $mt_current_family = $mt_group['family'];
            ?>
    <h6 class="ifm-family"><?= nullable_htmlentities($mt_current_family) ?></h6>
        <?php endif; ?>
    <div class="ifm-chart"
         data-metric-chart
         data-chart-id="<?= htmlspecialchars($mt_group['id'], ENT_QUOTES) ?>"
         data-metric-keys="<?= htmlspecialchars(implode(',', $mt_group['keys']), ENT_QUOTES) ?>"
         data-headline="<?= htmlspecialchars((string) ($mt_group['headline'] ?? ''), ENT_QUOTES) ?>"
         data-axis="<?= htmlspecialchars($mt_group['axis'], ENT_QUOTES) ?>"
         data-unit="<?= htmlspecialchars($mt_group['unit'], ENT_QUOTES) ?>"
         data-precision="<?= intval($mt_group['precision']) ?>">
        <div class="ifm-chart-head">
            <div class="ifm-chart-title"><?= nullable_htmlentities($mt_group['title']) ?></div>
            <div class="ifm-chart-stats">
                <span class="ifm-stat"><em>Current</em><b data-role="current">--</b></span>
                <span class="ifm-stat"><em>Average</em><b data-role="avg">--</b></span>
                <span class="ifm-stat"><em>Peak</em><b data-role="peak">--</b></span>
            </div>
        </div>
        <div class="ifm-chart-body">
            <canvas data-role="canvas"
                    aria-label="<?= htmlspecialchars($mt_group['title'] . ' over time', ENT_QUOTES) ?>"
                    role="img"></canvas>
            <div class="ifm-chart-overlay" data-role="overlay" hidden></div>
        </div>
        <div class="ifm-chart-legend" data-role="legend"></div>
    </div>
    <?php endforeach; ?>

    <p class="ifm-footnote">
        This device reports <b><?= $mt_reported_count ?></b> of <?= $mt_total_count ?> known metrics
        via <?= nullable_htmlentities($mt_provider) ?>. Metrics it cannot produce are not shown.
        Sample timestamps are stored in UTC and displayed in your local time zone.
    </p>

    <?php endif; /* $mt_has_data */ ?>
<?php endif; /* installed / error */ ?>
</div>

<?php if ($mt_render_pane): ?>
</div>
<?php endif; ?>
