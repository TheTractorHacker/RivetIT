<?php

/*
 * RMM module bootstrap: builds RivetCore\Rmm\RmmModule from RivetIT's adapters (src/Core/Adapter/Endpoint).
 *
 * The endpoint agent's PHP lives in the rivet/rivet-core package since RivetCore 1.0.0-rc.4; this file is the only place RivetIT wires it.
 * Everything here is lazy: nothing touches the database until a function that needs the module is called, and a request that only asks
 * "is the module on" (the menus, the cron block) is answered from the module's state file when there is a valid one.
 *
 * Constants an install may define in config.php (all optional): EA_ALLOW_INSECURE_HTTP (loopback test servers only), EA_BINARY_DIR,
 * EA_BINARY_MAX_BYTES, EA_ALLOW_NON_WINDOWS (the agent's Linux test build in the integration harness), RMM_STATE_DIR (see EndpointModuleState).
 */

require_once __DIR__ . '/event_bus.php';   // rivetCoreDb(), rivetWebhookUrlPolicy()

use ITFlow\Core\Adapter\Endpoint\EndpointAccessPolicy;
use ITFlow\Core\Adapter\Endpoint\EndpointAssets;
use ITFlow\Core\Adapter\Endpoint\EndpointAudit;
use ITFlow\Core\Adapter\Endpoint\EndpointBridge;
use ITFlow\Core\Adapter\Endpoint\EndpointMetricSink;
use ITFlow\Core\Adapter\Endpoint\EndpointModuleState;
use ITFlow\Core\Adapter\Endpoint\EndpointSecretBox;
use ITFlow\Core\Adapter\Endpoint\EndpointTenancy;
use RivetCore\Rmm\Authz\RmmPrincipal;
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
        'allow_linux' => defined('EA_ALLOW_NON_WINDOWS') && EA_ALLOW_NON_WINDOWS === true,
        'host_fallback' => (string) ($GLOBALS['config_base_url'] ?? ''),
        'client_label' => 'department',
    ];
    if (defined('EA_BINARY_MAX_BYTES')) {
        $options['max_upload_bytes'] = (int) EA_BINARY_MAX_BYTES;
    }
    $module = new RmmModule(
        $db,
        new \RivetCore\Support\SystemClock(),
        new EndpointTenancy($db),
        new EndpointAssets($db),
        new EndpointBridge($db, $mysqli),
        new EndpointSecretBox(),
        new EndpointAudit(),
        new EndpointMetricSink($mysqli),
        new EndpointModuleState(),
        $options,
        null,
        new EndpointAccessPolicy($db),
        rivetWebhookUrlPolicy($mysqli),
    );
    $built[$key] = [$mysqli, $module];

    return $module;
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
