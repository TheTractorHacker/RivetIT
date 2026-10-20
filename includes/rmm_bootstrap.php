<?php

/*
 * RMM module bootstrap: builds RivetCore\Rmm\RmmModule from RivetIT's adapters (src/Core/Adapter/Endpoint).
 *
 * The endpoint agent's PHP lives in the rivet/rivet-core package since RivetCore 1.0.0-rc.4; this file is the only place RivetIT wires it.
 * Everything here is lazy: nothing touches the database until a function that needs the module is called, and a request that only asks
 * "is the module on" (the menus, the cron block) is answered from the module's state file when there is a valid one.
 *
 * Constants an install may define in config.php (all optional): EA_ALLOW_INSECURE_HTTP (loopback test servers only), EA_BINARY_DIR, EA_TRANSFER_DIR
 * (where file transfers are stored; default backups/endpoint-transfer, inside the denied backups/ area), EA_BINARY_MAX_BYTES, EA_ALLOW_NON_WINDOWS (the agent's Linux test build in the integration harness), RMM_STATE_DIR (see EndpointModuleState).
 */

require_once __DIR__ . '/event_bus.php';   // rivetCoreDb(), rivetWebhookUrlPolicy()

use ITFlow\Core\Adapter\Endpoint\EndpointAccessPolicy;
use ITFlow\Core\Adapter\Endpoint\EndpointAssets;
use ITFlow\Core\Adapter\Endpoint\EndpointAudit;
use ITFlow\Core\Adapter\Endpoint\EndpointBridge;
use ITFlow\Core\Adapter\Endpoint\EndpointEscalation;
use ITFlow\Core\Adapter\Endpoint\EndpointEvents;
use ITFlow\Core\Adapter\Endpoint\EndpointMetricSink;
use ITFlow\Core\Adapter\Endpoint\EndpointModuleState;
use ITFlow\Core\Adapter\Endpoint\EndpointSecretBox;
use ITFlow\Core\Adapter\Endpoint\EndpointTenancy;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Metrics\CompositeMetricSink;
use RivetCore\Rmm\Metrics\ExternalMetricReader;
use RivetCore\Rmm\Metrics\ExternalMetricSink;
use RivetCore\Rmm\Metrics\VictoriaMetricsStore;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\RmmModule;
use RivetCore\Rmm\RmmStateFile;

/** Directory of the module's zero-database state file, or null when the fast path is switched off. */
function rivetRmmStateDir(): ?string
{
    return EndpointModuleState::directory();
}

/**
 * The module for this request's database connection (built once per connection).
 *
 * @param \mysqli|null $mysqli defaults to the global connection
 */
function rivetRmmModule($mysqli = null): RmmModule
{
    static $built = [];
    $mysqli ??= $GLOBALS['mysqli'] ?? null;
    if (!$mysqli instanceof \mysqli) {
        throw new \RuntimeException('The RMM module needs the database connection.');
    }
    $key = spl_object_id($mysqli);
    if (isset($built[$key]) && $built[$key][0] === $mysqli) {
        return $built[$key][1];
    }
    $root = dirname(__DIR__);
    $db = rivetCoreDb($mysqli);
    $options = [
        'binary_dir' => defined('EA_BINARY_DIR') ? (string) EA_BINARY_DIR : $root . '/backups/endpoint-agent',
        'allow_insecure_http' => defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true,
        // Linux devices may enroll: the administrator's switch (Administration > Endpoint agent > Overview, on by default); the config.php constant stays as an override that forces it on.
        'allow_linux' => (defined('EA_ALLOW_NON_WINDOWS') && EA_ALLOW_NON_WINDOWS === true) || \ITFlow\Platform\PlatformSettings::bool($mysqli, 'rmm_allow_linux'),
        'host_fallback' => (string) ($GLOBALS['config_base_url'] ?? ''),
        'client_label' => 'department',
        // RivetCore 1.0.0-rc.10 (RMM Phase 3): who delivers escalation notices (in-app and email to people, a ticket for a critical alert). Housekeeping, run by cron,
        // calls it; without it a due step would stay due and never be recorded as sent.
        'escalation' => new EndpointEscalation($mysqli),
    ];
    if (defined('EA_BINARY_MAX_BYTES')) {
        $options['max_upload_bytes'] = (int) EA_BINARY_MAX_BYTES;
    }
    // RivetCore 1.0.0-rc.11 (RMM Phase 6): file transfers are stored here (a folder private to the web user, outside the web root: backups/ is denied by every web server
    // template). Without a folder transfers answer 409 not_configured. The folder is created on first use (mode 0700) when it does not exist.
    $options['transfer_dir'] = defined('EA_TRANSFER_DIR') ? (string) EA_TRANSFER_DIR : $root . '/backups/endpoint-transfer';
    // RivetCore 1.0.0-rc.11 (scale S8): when Redis answers, the per-client token buckets (off unless a limit is set) and the read-path cache (fleet counters, the capacity panel, the
    // active releases) live in Redis, shared by every web worker and by the cron; both fail open when Redis goes away. Without Redis Core uses its own tables. The decision is made
    // per process from one connection attempt that the rate limiter makes anyway (EndpointRedisProvider loads redis_functions.php itself, so a CLI process agrees with the web).
    $provider = new \ITFlow\Core\Adapter\Endpoint\EndpointRedisProvider();
    if ($provider->client() !== null) {
        $options['redis'] = $provider;
        $options['cache'] = new \RivetCore\Mcp\RedisMetadataCache($provider, 'rivetit:rmm_cache:');
    }
    $metrics = new EndpointMetricSink($mysqli);
    $sink = $metrics;
    $ext = rivetRmmExternalStoreConfig($mysqli);
    if ($ext !== null) {
        try {
            // Scale S4, OFF by default: history also goes to a time-series database, through a bounded buffer that never blocks a check-in; reads of history prefer the store and fall
            // back to RivetIT's own metric tables. The store's URLs are vetted by the same UrlPolicy as webhooks (public addresses, https, no redirects, the connection pinned).
            $store = new VictoriaMetricsStore($ext['write_url'], rivetWebhookUrlPolicy($mysqli), null, $ext['read_url'] !== '' ? $ext['read_url'] : null, $ext['token'] !== '' ? $ext['token'] : null, null, 10, rivetRmmExtStoreAllowHttp());
            $sink = new CompositeMetricSink([[$metrics, true], [new ExternalMetricSink($db, new \RivetCore\Support\SystemClock(), $store), false]]);
            $options['metric_reader'] = new ExternalMetricReader($store, $metrics);
        } catch (\Throwable $e) {
            error_log('RMM external metric store not used: ' . get_class($e));
            $sink = $metrics;
        }
    }
    $module = new RmmModule(
        $db,
        new \RivetCore\Support\SystemClock(),
        new EndpointTenancy($db),
        new EndpointAssets($db),
        new EndpointBridge($db, $mysqli),
        new EndpointSecretBox(),
        new EndpointAudit(),
        $sink,   // sink; $metrics is also the reader (RmmMetricReaderInterface): the network bar reads the 24 hour peak from here (or from the external store, option metric_reader)
        new EndpointModuleState(),
        $options,
        null,
        new EndpointAccessPolicy($db),
        rivetWebhookUrlPolicy($mysqli),
        new EndpointEvents($mysqli),   // RivetCore 1.0.0-rc.9: rmm.* events onto the event bus (webhooks, event rules)
    );
    $built[$key] = [$mysqli, $module];

    return $module;
}

/**
 * Plain http to the external metric store is for the automated tests only: it needs BOTH the install constant that already exists for loopback test servers
 * (EA_ALLOW_INSECURE_HTTP, set by the scratch config.php and never in production) AND the webhook policy's private-address switch in the environment
 * (RIVETIT_WEBHOOK_ALLOW_PRIVATE=1). Nothing else enables it; a production install has neither, so the store is https only.
 */
function rivetRmmExtStoreAllowHttp(): bool
{
    return defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true && getenv('RIVETIT_WEBHOOK_ALLOW_PRIVATE') === '1';
}

/**
 * The external metric store's settings (RMM scale S4), or null while it is off, unconfigured or unreadable. Read from the settings row the request already loaded
 * (includes/load_global_settings.php), or with one query when nothing loaded it (cron, tests). The values are encrypted with encryptSetting(); a value that cannot be
 * decrypted counts as not configured.
 *
 * @return array{write_url:string,read_url:string,token:string}|null
 */
function rivetRmmExternalStoreConfig($mysqli = null): ?array
{
    $raw = $GLOBALS['config_rmm_extstore_raw'] ?? false;
    $on = $GLOBALS['config_rmm_extstore_enabled'] ?? null;
    $state = RmmStateFile::read(rivetRmmStateDir());
    if ($state !== null && !$state['enabled']) {
        return null;   // the module is off: nothing is ingested, so no store is wired and no statement is spent finding out
    }
    if ($on === null || ($on === 1 && !is_array($raw))) {
        $raw = null;
        $mysqli ??= $GLOBALS['mysqli'] ?? null;
        if ($mysqli instanceof \mysqli) {
            try {
                $res = mysqli_query($mysqli, 'SELECT config_rmm_extstore_enabled AS e, config_rmm_extstore_write_url AS w, config_rmm_extstore_read_url AS r, config_rmm_extstore_token AS t FROM settings WHERE company_id = 1');
                $row = $res ? mysqli_fetch_assoc($res) : null;
                if ($row && (int) $row['e'] === 1) {
                    $raw = ['write_url' => (string) $row['w'], 'read_url' => (string) $row['r'], 'token' => (string) $row['t']];
                }
            } catch (\Throwable) {
                $raw = null;   // the column is not there yet (new code, old schema)
            }
        }
    } elseif ($on !== 1) {
        return null;
    }
    if (!is_array($raw)) {
        return null;
    }
    $write = trim(decryptSetting($raw['write_url']));
    if ($write === '') {
        return null;
    }

    return ['write_url' => $write, 'read_url' => trim(decryptSetting($raw['read_url'])), 'token' => decryptSetting($raw['token'])];
}

/**
 * Drop the module's remembered access answers (RivetCore 1.0.0-rc.6: RmmAuthorizer memoizes policy and client-scope answers for the life of the
 * object). The module is built once per request, so a page never needs this; call it after changing a role, a role permission or a user's client
 * scope inside the same request (or in a long-lived process such as a test or a worker) when something later must see the change.
 */
function rivetRmmForgetAccess(?int $userId = null, $mysqli = null): void
{
    rivetRmmModule($mysqli)->authorizer()->forget($userId);
}

/**
 * Is the module on? Answered from the state file when it holds a valid verdict (no database), from the settings row otherwise.
 * A missing or damaged file is "unknown", never "off".
 */
function rivetRmmEnabled($mysqli = null): bool
{
    $state = RmmStateFile::read(rivetRmmStateDir());
    if ($state !== null) {
        return $state['enabled'];
    }
    try {
        return rivetRmmModule($mysqli)->enabled();
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Is a sub-switch (`policies`, `scripts`, `alerting`, `inventory_software`, ...) on? True only while the module itself is on. Answered from the module's state
 * file when it holds a valid verdict, so a page, a menu or the cron block asks the database NOTHING while the module is off; a missing or damaged file falls
 * back to the settings row (a missing file is "unknown", never "off").
 */
function rivetRmmFeatureOn(string $feature, $mysqli = null): bool
{
    $state = RmmStateFile::read(rivetRmmStateDir());
    if ($state !== null) {
        return $state['enabled'] && !empty($state['features'][$feature]);
    }
    try {
        return rivetRmmModule($mysqli)->featureOn($feature);
    } catch (\Throwable) {
        return false;
    }
}

/**
 * The Endpoints menu entries of RMM Phase 2 and 3 (RivetCore 1.0.0-rc.10), each shown only while the module AND its sub-switch are on. Answered from the
 * state file: no database query, and no module is built, while anything is off. The caller has already checked the RMM module grant. With a user id the Logs entries
 * (the only ones that need an ability beyond viewing) are left out for a user who lacks it; that check builds the module, and only while the `logs` switch is on.
 *
 * @return list<array{href:string,label:string,icon:string,pages:list<string>,group:string}>
 */
function rivetRmmAutoNav(?int $userId = null): array
{
    $all = [
        ['policies', '/agent/rmm_policies.php', 'Policies', 'fa-sliders-h', ['rmm_policies.php'], 'automation'],
        // one Scripts entry: the library tab, and the legacy list's tab (rmm_scripts.php, "Synced from <provider>") live under it
        ['scripts', '/agent/rmm_script_library.php', 'Scripts', 'fa-file-code', ['rmm_script_library.php', 'rmm_scripts.php'], 'automation'],
        // RivetCore 1.0.0-rc.13: the starter pack (administrators; the entry is left out for anyone else when the caller names the user, and the page checks again)
        ['scripts', '/agent/rmm_starter_pack.php', 'Starter pack', 'fa-box-open', ['rmm_starter_pack.php'], 'automation'],
        ['scripts', '/agent/rmm_schedules.php', 'Schedules', 'fa-clock', ['rmm_schedules.php'], 'automation'],
        ['scripts', '/agent/rmm_approvals.php', 'Approvals', 'fa-user-check', ['rmm_approvals.php'], 'automation'],
        ['scripts', '/agent/rmm_fields.php', 'Custom fields', 'fa-tags', ['rmm_fields.php'], 'automation'],
        ['alerting', '/agent/rmm_agent_alerts.php', 'Agent alerts', 'fa-bell', ['rmm_agent_alerts.php'], 'alerting'],
        ['alerting', '/agent/rmm_maintenance.php', 'Maintenance windows', 'fa-wrench', ['rmm_maintenance.php'], 'alerting'],
        ['alerting', '/agent/rmm_escalations.php', 'Escalation policies', 'fa-level-up-alt', ['rmm_escalations.php'], 'alerting'],
        // RMM Phase 8 (RivetCore 1.0.0-rc.11), sub-switch `logs` (off by default): the search needs the ability that reads job output, the rules the alert-management ability.
        ['logs', '/agent/rmm_logs.php', 'Logs', 'fa-scroll', ['rmm_logs.php'], 'logs'],
        ['logs', '/agent/rmm_log_rules.php', 'Log rules', 'fa-bullhorn', ['rmm_log_rules.php'], 'logs'],
        // RMM Phase 4 (RivetCore 1.0.0-rc.20), sub-switch `patching` (off by default): compliance and update approval need the patch-view ability (any RMM viewer), the policies page shows to
        // viewers read-only and lets whoever has rmm.patch.manage change them; the page and every handler check again.
        ['patching', '/agent/rmm_patches.php', 'Patch compliance', 'fa-shield-alt', ['rmm_patches.php'], 'patching'],
        ['patching', '/agent/rmm_patch_updates.php', 'Patch approvals', 'fa-check-double', ['rmm_patch_updates.php'], 'patching'],
        ['patching', '/agent/rmm_patch_policies.php', 'Patch policies', 'fa-calendar-check', ['rmm_patch_policies.php'], 'patching'],
        // RMM Phase 5 (RivetCore 1.0.0-rc.21), sub-switch `software_deploy` (off by default): the catalog, the assignments and the deployed-versus-expected report need the software-view
        // ability (any RMM viewer sees them read-only; the buttons need rmm.software.manage / deploy). The pages and every handler check again.
        ['software_deploy', '/agent/rmm_software.php', 'Software catalog', 'fa-box-open', ['rmm_software.php'], 'software'],
        ['software_deploy', '/agent/rmm_software_assignments.php', 'Software assignments', 'fa-boxes', ['rmm_software_assignments.php'], 'software'],
        ['software_deploy', '/agent/rmm_software_report.php', 'Software report', 'fa-clipboard-check', ['rmm_software_report.php'], 'software'],
        // RivetCore 1.0.0-rc.29, sub-switch `hypervisor` (off by default): Proxmox VE and Hyper-V hosts. Needs the hypervisor-view ability on top of
        // the ordinary device-view every entry here already needs; the page and every handler check again.
        ['hypervisor', '/agent/rmm_hypervisors.php', 'Hypervisors', 'fa-server', ['rmm_hypervisors.php'], 'hypervisor'],
    ];
    $abilities = ['/agent/rmm_logs.php' => \RivetCore\Rmm\Authz\RmmAbility::JOB_RUN_SAVED, '/agent/rmm_log_rules.php' => \RivetCore\Rmm\Authz\RmmAbility::ALERT_MANAGE,
        '/agent/rmm_starter_pack.php' => \RivetCore\Rmm\Authz\RmmAbility::ADMIN,
        '/agent/rmm_patches.php' => \RivetCore\Rmm\Authz\RmmAbility::PATCH_VIEW, '/agent/rmm_patch_updates.php' => \RivetCore\Rmm\Authz\RmmAbility::PATCH_VIEW, '/agent/rmm_patch_policies.php' => \RivetCore\Rmm\Authz\RmmAbility::PATCH_VIEW,
        '/agent/rmm_software.php' => \RivetCore\Rmm\Authz\RmmAbility::SOFTWARE_VIEW, '/agent/rmm_software_assignments.php' => \RivetCore\Rmm\Authz\RmmAbility::SOFTWARE_VIEW, '/agent/rmm_software_report.php' => \RivetCore\Rmm\Authz\RmmAbility::SOFTWARE_VIEW,
        '/agent/rmm_hypervisors.php' => \RivetCore\Rmm\Authz\RmmAbility::HYPERVISOR_VIEW];
    $out = [];
    foreach ($all as [$feature, $href, $label, $icon, $pages, $group]) {
        if (rivetRmmFeatureOn($feature)) {
            // an entry that needs an ability is drawn only for a user who has it (when the caller names the user); the page and the handler check again
            if ($userId !== null && isset($abilities[$href])) {
                $authz = rivetRmmModule()->authorizer();
                if (!$authz->allowed($userId, \RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW, 0) || !$authz->allowed($userId, $abilities[$href], 0)) {
                    continue;
                }
            }
            $out[] = ['href' => $href, 'label' => $label, 'icon' => $icon, 'pages' => $pages, 'group' => $group];
        }
    }

    return $out;
}

/**
 * Make the state file agree with the database (after the updater, a restore, or the switch changed outside the module). Safe to call anywhere;
 * never throws. Returns false when there is no usable state directory.
 */
function rivetRmmSyncState($mysqli = null): bool
{
    try {
        return rivetRmmModule($mysqli)->syncState();
    } catch (\Throwable $e) {
        error_log('RMM state sync skipped: ' . $e->getMessage());

        return false;
    }
}

/** The RMM job handlers (queued ingest) on a job worker. Does nothing, and loads nothing, while the state file says the module is off. */
function rivetRmmRegisterHandlers(\RivetCore\Jobs\JobWorker $worker, $mysqli): void
{
    if (!RmmStateFile::enabled(rivetRmmStateDir())) {
        return;
    }
    try {
        rivetRmmModule($mysqli)->registerHandlers($worker);
    } catch (\Throwable $e) {
        error_log('RMM job handlers not registered: ' . $e->getMessage());
    }
}

/**
 * Housekeeping from cron/cron.php. Skipped entirely, with no module load, while the state file says the module is off; a file that disagrees
 * with the settings row (a restore, a manual edit) is corrected first, so the file never stays wrong for longer than one cron tick.
 *
 * @return array<string,int>
 */
function rivetRmmHousekeeping($mysqli): array
{
    $dir = rivetRmmStateDir();
    $row = mysqli_query($mysqli, 'SELECT enabled FROM endpoint_agent_settings WHERE id = 1');
    $dbOn = $row && (int) (mysqli_fetch_row($row)[0] ?? 0) === 1;
    if (!$dbOn && !RmmStateFile::enabled($dir)) {
        return [];
    }
    if ($dir !== null && RmmStateFile::enabled($dir) !== $dbOn) {
        rivetRmmSyncState($mysqli);
    }
    if (!$dbOn) {
        return [];
    }

    return rivetRmmModule($mysqli)->housekeeping()->run();
}

/** The authenticated technician for the REST API and the web handlers. */
function rivetRmmPrincipal(int $userId, string $userName): RmmPrincipal
{
    return new RmmPrincipal($userId, $userName);
}

/**
 * Build the request object from the PHP superglobals. TLS and proxy trust stay RivetIT's decision: TLS is required unless the connection is https,
 * on port 443, or relayed by a proxy on this host / private network that says so (X-Forwarded-Proto: https).
 *
 * @param list<string> $pathSegments segments after the resource (technician API)
 */
function rivetRmmRequest(string $endpoint, array $pathSegments = []): RmmRequest
{
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (is_string($v) && strncmp($k, 'HTTP_', 5) === 0) {
            $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        $all = getallheaders();
        $auth = $all['Authorization'] ?? $all['authorization'] ?? '';
    }
    if ($auth !== '') {
        $headers['authorization'] = (string) $auth;
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $port443 = (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $peerIsLocal = filter_var($peer, FILTER_VALIDATE_IP) !== false
        && filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    $proxied = $peerIsLocal && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $query = [];
    foreach ($_GET as $k => $v) {
        $query[(string) $k] = is_string($v) ? $v : '';
    }
    $len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;

    return new RmmRequest(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $endpoint,
        $pathSegments,
        $query,
        $headers,
        getIP(),
        isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
        $https || $port443 || $proxied,
        $len,
        fopen('php://input', 'rb') ?: null,
    );
}
