<?php
/*
 * STATUS: UNRELEASED. Not routed in api/v1/index.php, no UI for enrollment/device tokens, and the
 * device_metric_tokens table it needs is not part of the shipped schema. Requests to /api/v1/metrics-ingest
 * are NOT served today. Decision recorded in docs/FINDINGS-DECISIONS.md (finding "metrics-ingest"): keep it
 * unreleased and unwired until the built-in endpoint agent defines how it reports. Do not document it as an
 * available endpoint, and do not touch the metric tables from here.
 *
 * Device metrics — endpoint push (ingest) path.
 *
 * Naming note: this subsystem is "Metrics", never "telemetry". `config_telemetry`
 * already exists in this codebase and means anonymous phone-home usage reporting
 * (admin/settings_telemetry.php, includes/load_global_settings.php), so the two
 * must never share URL space, table space or setting space.
 *
 * ROUTES
 *   POST /api/v1/metrics-ingest
 *        Push one batch of samples for the asset the presented device token is
 *        bound to. See scripts/collector/README.md for the payload contract.
 *
 *   POST /api/v1/metrics-ingest/enroll
 *        Exchange a shared, revocable enrollment token for a per-device token
 *        bound to a single asset. Lets one Tactical script-check definition be
 *        deployed to the whole fleet with one shared secret in its arguments,
 *        while each device still ends up holding a credential that can only
 *        write its own series.
 *
 * ============================================================================
 * INTEGRATOR: WHERE THIS ROUTE MUST BE INSERTED IN api/v1/index.php
 * ============================================================================
 * This handler MUST be dispatched ABOVE index.php's pre-auth request-body read.
 *
 * At api/v1/index.php lines ~152-165 there is this block:
 *
 *     } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
 *         // Some clients send the api_key in the JSON body instead of the query string
 *         $json_body = json_decode(file_get_contents('php://input'), true);
 *
 * That runs for EVERY POST that did not match a Bearer token in `api_tokens`.
 * A device token is deliberately not an `api_tokens` row, so it never matches,
 * so every collector push would hit that unbounded `file_get_contents()` +
 * `json_decode()` before routing — reading and decoding the whole body twice
 * with no length ceiling at all. The Content-Length ceiling enforced in this
 * file is worthless if that block runs first.
 *
 * Insert the dispatch immediately AFTER the public `openapi`/`docs` block
 * (which currently ends with `exit;` and `}` around line 110) and BEFORE the
 * comment `// All other endpoints require Bearer token` (currently line 112):
 *
 *     // Device-token endpoint: metrics ingest. Routed here, above the Bearer
 *     // parsing and above the pre-auth JSON body read below, because a device
 *     // token is not an api_tokens row and would otherwise fall through to the
 *     // unbounded file_get_contents('php://input') in the legacy api_key block.
 *     // metrics_ingest.php does its own auth, its own Content-Length ceiling
 *     // and its own per-device rate limiting.
 *     if ($resource === 'metrics-ingest' || $resource === 'metrics_ingest') {
 *         require __DIR__ . '/metrics_ingest.php';
 *         exit;
 *     }
 *
 * Do NOT add a `case 'metrics-ingest':` to the switch at the bottom — that is
 * below the 401 gate and below the body read, and would defeat both points.
 * ============================================================================
 *
 * SECURITY POSTURE
 *  - Credential: a per-asset device token, hashed at rest in
 *    `device_metric_tokens` (DDL in the integration notes). Never an api_tokens
 *    row, never a user token: a device holds a credential that can write only
 *    its own asset's series and can read nothing at all.
 *  - The payload cannot choose its asset. asset_id comes from the token row and
 *    from nowhere else; an `asset_id` field in the body is ignored outright.
 *  - Content-Length is required and capped BEFORE the body is read, and the read
 *    itself is bounded to that length rather than slurping php://input.
 *  - Compressed request bodies are refused. nginx has no request-body gunzip, so
 *    a gzip body would be inflated inside PHP where a few KB can become hundreds
 *    of MB. 26 devices posting a few KB every five minutes do not need it.
 *  - Every sample is validated against MetricRegistry (via MetricSample::of())
 *    before anything touches the database; a malformed envelope rejects the
 *    whole batch.
 *  - Per-device, per-IP and per-enrollment-token rate limits (Redis-backed, and
 *    fails open when Redis is down, exactly like the rest of the API).
 *  - Enrollments and rejected batches are both written to the audit log.
 *
 * TIME: `device_metric_samples.sampled_at` is UTC — a deliberate divergence from
 * the app's local-time convention (see ITFlow\Metrics\MetricIngestService). Every
 * timestamp accepted here is parsed as UTC unless it carries an explicit offset,
 * and every timestamp emitted here is UTC with a trailing `Z`. Token bookkeeping
 * columns (`token_last_used_at`, `token_created_at`) follow the APP convention
 * (local time, NOW()) because they are shown next to other admin timestamps.
 *
 * OWNERSHIP: this file writes `device_metric_tokens`, `logs` and `app_logs`, and
 * writes samples/instances/collection-state only through MetricIngestService. It
 * never creates, updates or deletes an `assets` row or an `asset_rmm_links` row —
 * those belong exclusively to includes/class_rmm_asset_mapper.php.
 */

defined('FROM_API') || die();

// ---------------------------------------------------------------------------
// Autoloader. index.php pulls in includes/redis_functions.php, which is the only
// file in the tree that requires vendor/autoload.php today, so the classes below
// are normally already available. Ensure it explicitly rather than fataling if
// that incidental ordering ever changes.
// ---------------------------------------------------------------------------
if (!class_exists('ITFlow\\Metrics\\MetricRegistry')) {
    $mi_autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_readable($mi_autoload)) {
        require_once $mi_autoload;
    }
}
if (!class_exists('ITFlow\\Metrics\\MetricIngestService')
    || !class_exists('ITFlow\\Metrics\\MetricSample')
    || !class_exists('ITFlow\\Metrics\\MetricRegistry')) {
    api_error(500, 'Metrics subsystem is not installed');
}

// ---------------------------------------------------------------------------
// Limits
// ---------------------------------------------------------------------------

/** Hard ceiling on a sample-push body. ~2,000 samples of JSON is well under this. */
const MI_MAX_BODY_BYTES = 524288;      // 512 KiB

/** Hard ceiling on an enrollment body — a handful of identity strings. */
const MI_MAX_ENROLL_BODY_BYTES = 4096; // 4 KiB

/** Max samples in one batch. The whole fleet is ~1,000 active series total. */
const MI_MAX_SAMPLES = 2000;

/** Envelope schema tag the collector must send. Bump on a breaking change. Keeps its pre-RivetIT
 *  name: deployed collectors send exactly this string. */
const MI_SCHEMA = 'itflow.metrics.v1';

/** Wire prefix for a device/enrollment token: itfm1.<selector>.<verifier> */
const MI_TOKEN_PREFIX = 'itfm1';

/** Sample pushes allowed per token per 5 minutes (cadence is one per 300s). */
const MI_RATE_PUSH_LIMIT  = 20;
const MI_RATE_PUSH_WINDOW = 300;

/** Enrollments allowed per source IP per hour. */
const MI_RATE_ENROLL_IP_LIMIT  = 10;
const MI_RATE_ENROLL_IP_WINDOW = 3600;

/** Enrollments allowed per enrollment token per hour (fleet-wide roll-out burst). */
const MI_RATE_ENROLL_TOKEN_LIMIT  = 60;
const MI_RATE_ENROLL_TOKEN_WINDOW = 3600;

/** Failed authentication attempts allowed per source IP per 5 minutes. */
const MI_RATE_AUTHFAIL_LIMIT  = 30;
const MI_RATE_AUTHFAIL_WINDOW = 300;

// ---------------------------------------------------------------------------
// logAction()/logApp() read $session_* globals. This handler runs above the
// front controller's auth block, so none of them are populated. Set them
// explicitly rather than letting PHP 8.4 emit "Undefined variable" warnings into
// the middle of a JSON response body.
// ---------------------------------------------------------------------------
$session_user_id    = 0;
$session_ip         = getIP();
$session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Audit one ingest/enrollment event.
 *
 * Both sinks are written on purpose: `logs` is the human-facing audit trail an
 * admin reads (and is what "an audit trail of enrollment and rejected batches"
 * means), `app_logs` is the operator/debug stream that can carry the longer
 * machine detail. $selector is the token's non-secret lookup handle, so an audit
 * row names exactly which credential acted without ever storing the secret.
 */
function mi_audit(string $action, string $description, int $client_id = 0, int $asset_id = 0, string $level = 'info'): void
{
    logAction('Device Metrics', $action, $description, $client_id, $asset_id);
    logApp('Metrics-Ingest', $level, $action . ': ' . $description);
}

/** End the request with a JSON error and an audit row. */
function mi_reject(int $code, string $message, string $action, string $detail, int $client_id = 0, int $asset_id = 0): void
{
    mi_audit($action, $detail, $client_id, $asset_id, 'error');
    api_error($code, $message);
}

/**
 * Pull the presented device/enrollment token off the request.
 *
 * X-Device-Token is preferred and is what the collector sends. Authorization:
 * Bearer is accepted as a convenience for curl/testing. Both are read here
 * rather than relying on index.php, because this route is dispatched above
 * index.php's Bearer parsing (see the header comment).
 */
function mi_presented_token(): string
{
    $raw = (string) ($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');

    if ($raw === '') {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($auth === '' && function_exists('getallheaders')) {
            $hdrs = getallheaders();
            if (is_array($hdrs)) {
                foreach ($hdrs as $name => $value) {
                    $lname = strtolower((string) $name);
                    if ($lname === 'x-device-token') {
                        $raw = (string) $value;
                        break;
                    }
                    if ($lname === 'authorization' && $auth === '') {
                        $auth = (string) $value;
                    }
                }
            }
        }
        if ($raw === '' && $auth !== '' && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            $raw = $m[1];
        }
    }

    return trim($raw);
}

/**
 * Split a wire token into [selector, verifier], or null if it is not even
 * shaped like one. Cheap structural rejection before any database access.
 *
 * @return array{0:string,1:string}|null
 */
function mi_split_token(string $raw): ?array
{
    if ($raw === '' || strlen($raw) > 128) {
        return null;
    }
    $parts = explode('.', $raw);
    if (count($parts) !== 3 || $parts[0] !== MI_TOKEN_PREFIX) {
        return null;
    }
    $selector = $parts[1];
    $verifier = $parts[2];
    if (!preg_match('/^[0-9a-f]{16}$/', $selector)) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $verifier)) {
        return null;
    }
    return [$selector, $verifier];
}

/** Mint a fresh token. @return array{raw:string,selector:string,hash:string} */
function mi_mint_token(): array
{
    $selector = bin2hex(random_bytes(8));                                        // 16 hex chars
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');  // 43 url-safe chars
    return [
        'raw'      => MI_TOKEN_PREFIX . '.' . $selector . '.' . $verifier,
        'selector' => $selector,
        'hash'     => hash('sha256', $verifier),
    ];
}

/**
 * Look a token up by selector and constant-time compare the verifier.
 *
 * Returns the row, or null when the selector is unknown, the verifier does not
 * match, the token is revoked, or the token has expired. Deliberately does not
 * distinguish between those cases to the caller — the endpoint answers 401 for
 * all of them so a prober cannot enumerate live selectors.
 *
 * @return array<string,mixed>|null
 */
function mi_lookup_token(mysqli $mysqli, string $selector, string $verifier, string $kind): ?array
{
    $stmt = mysqli_prepare($mysqli,
        "SELECT token_id, token_kind, token_asset_id, token_integration_id, token_selector,
                token_hash, token_label, token_expires_at, token_revoked_at
         FROM device_metric_tokens
         WHERE token_selector = ? AND token_kind = ?
         LIMIT 1"
    );
    if ($stmt === false) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $selector, $kind);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        // Still burn a comparison so a hit and a miss cost roughly the same.
        hash_equals(str_repeat('0', 64), hash('sha256', $verifier));
        return null;
    }
    if (!hash_equals((string) $row['token_hash'], hash('sha256', $verifier))) {
        return null;
    }
    if (!empty($row['token_revoked_at'])) {
        return null;
    }
    if (!empty($row['token_expires_at']) && strtotime((string) $row['token_expires_at']) < time()) {
        return null;
    }

    return $row;
}

/**
 * Enforce the Content-Length ceiling and read exactly that many bytes.
 *
 * Order matters: the header is checked first so an oversized or compressed body
 * is refused without ever being read, and the read itself is bounded by the
 * declared length rather than by file_get_contents() slurping whatever arrives.
 */
function mi_read_body(int $max_bytes): string
{
    // A compressed body is refused outright. nginx does not gunzip request
    // bodies, so PHP would be the one inflating it — and a few KB of gzip can
    // expand to hundreds of MB in memory. The fleet is 26 devices posting a few
    // KB every five minutes; compression buys nothing and costs a decompression
    // bomb.
    $encoding = strtolower(trim((string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? '')));
    if ($encoding !== '' && $encoding !== 'identity') {
        api_error(415, 'Compressed request bodies are not accepted. Send uncompressed JSON.');
    }

    // Chunked transfer means no Content-Length, so there is no ceiling to
    // enforce before reading. Refuse rather than read an unbounded stream.
    $transfer = strtolower(trim((string) ($_SERVER['HTTP_TRANSFER_ENCODING'] ?? '')));
    if ($transfer !== '' && $transfer !== 'identity') {
        api_error(411, 'A Content-Length header is required; chunked bodies are not accepted.');
    }

    $ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if ($ctype === '' || strpos($ctype, 'application/json') === false) {
        api_error(415, 'Content-Type must be application/json');
    }

    $len_raw = (string) ($_SERVER['CONTENT_LENGTH'] ?? '');
    if ($len_raw === '' || !ctype_digit($len_raw)) {
        api_error(411, 'A numeric Content-Length header is required');
    }
    $len = (int) $len_raw;
    if ($len <= 0) {
        api_error(400, 'Empty request body');
    }
    if ($len > $max_bytes) {
        api_error(413, 'Request body too large (limit ' . $max_bytes . ' bytes)');
    }

    $fh = fopen('php://input', 'rb');
    if ($fh === false) {
        api_error(400, 'Could not read request body');
    }
    // Bounded read: at most the declared length, never "everything available".
    $body = stream_get_contents($fh, $len);
    fclose($fh);

    if ($body === false || strlen($body) !== $len) {
        api_error(400, 'Request body length did not match Content-Length');
    }

    return $body;
}

/**
 * Run a prepared write and return the number of rows it actually changed.
 *
 * api_exec() in includes/api_db.php returns mysqli_insert_id() when it is
 * non-zero, and insert_id is CONNECTION level — it keeps reporting the id of the
 * last INSERT on this connection, so an UPDATE run after any INSERT (a logAction
 * audit row, say) would report that stale id as if it were an affected-row
 * count. Token bookkeeping needs the honest number, so it uses this instead.
 */
function mi_exec_affected(mysqli $mysqli, string $sql, string $types, array $params): int
{
    $stmt = mysqli_prepare($mysqli, $sql);
    if ($stmt === false) {
        return 0;
    }
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return 0;
    }
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    return max(0, (int) $affected);
}

/** Is device-metrics collection switched on for this instance? */
function mi_collection_enabled(mysqli $mysqli): bool
{
    $res = mysqli_query($mysqli, "SELECT config_enable_device_metrics FROM settings WHERE company_id = 1 LIMIT 1");
    if ($res === false) {
        // Column/table not present — the migration has not been applied yet.
        return false;
    }
    $row = mysqli_fetch_assoc($res);
    return isset($row['config_enable_device_metrics']) && intval($row['config_enable_device_metrics']) === 1;
}

/**
 * Read one asset, refusing archived ones.
 *
 * READ ONLY. Metrics code never creates, updates or deletes an assets row.
 *
 * @return array<string,mixed>|null
 */
function mi_load_asset(mysqli $mysqli, int $asset_id): ?array
{
    if ($asset_id < 1) {
        return null;
    }
    $stmt = mysqli_prepare($mysqli,
        "SELECT asset_id, asset_name, asset_client_id
         FROM assets
         WHERE asset_id = ? AND asset_archived_at IS NULL
         LIMIT 1"
    );
    if ($stmt === false) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $asset_id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return null;
    }
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

// ---------------------------------------------------------------------------
// Common request gating
// ---------------------------------------------------------------------------

if ($method !== 'POST') {
    api_error(405, 'Method not allowed');
}

// index.php's route parser collapses /metrics-ingest/enroll into
// ($resource='metrics-ingest', $sub='enroll'). Read $segments directly so this
// file behaves the same if it is ever required from a different dispatcher.
$mi_segments = (isset($segments) && is_array($segments)) ? array_values($segments) : [];
$mi_action   = isset($mi_segments[1]) ? strtolower((string) $mi_segments[1]) : '';
if ($mi_action !== '' && $mi_action !== 'enroll' && $mi_action !== 'samples') {
    api_error(404, 'Not found');
}

$mi_ip = getIP();

// Structural token check before any database access, and a per-IP failure budget
// so a prober cannot grind selectors. Fails open when Redis is down, like the
// rest of the API's limiters.
$mi_raw_token = mi_presented_token();
$mi_parts     = mi_split_token($mi_raw_token);
if ($mi_parts === null) {
    api_rate_limit('dm_authfail:' . $mi_ip, MI_RATE_AUTHFAIL_LIMIT, MI_RATE_AUTHFAIL_WINDOW);
    api_error(401, 'A device token is required in the X-Device-Token header');
}
[$mi_selector, $mi_verifier] = $mi_parts;

// ===========================================================================
// POST /api/v1/metrics-ingest/enroll
// ===========================================================================

if ($mi_action === 'enroll') {

    if (!api_rate_limit('dm_enroll_ip:' . $mi_ip, MI_RATE_ENROLL_IP_LIMIT, MI_RATE_ENROLL_IP_WINDOW)) {
        header('Retry-After: ' . MI_RATE_ENROLL_IP_WINDOW);
        api_error(429, 'Rate limit exceeded');
    }

    $mi_enroll_token = mi_lookup_token($mysqli, $mi_selector, $mi_verifier, 'enrollment');
    if ($mi_enroll_token === null) {
        api_rate_limit('dm_authfail:' . $mi_ip, MI_RATE_AUTHFAIL_LIMIT, MI_RATE_AUTHFAIL_WINDOW);
        mi_audit('Enroll Denied', "Unknown, revoked or expired enrollment token (selector $mi_selector) from $mi_ip", 0, 0, 'warning');
        api_error(401, 'Invalid enrollment token');
    }

    if (!api_rate_limit('dm_enroll_tok:' . $mi_selector, MI_RATE_ENROLL_TOKEN_LIMIT, MI_RATE_ENROLL_TOKEN_WINDOW)) {
        header('Retry-After: ' . MI_RATE_ENROLL_TOKEN_WINDOW);
        api_error(429, 'Rate limit exceeded');
    }

    $mi_body = mi_read_body(MI_MAX_ENROLL_BODY_BYTES);
    $mi_json = json_decode($mi_body, true);
    if (!is_array($mi_json)) {
        mi_reject(400, 'Malformed enrollment body', 'Enroll Denied',
            "Enrollment body was not a JSON object (selector $mi_selector, ip $mi_ip)");
    }

    $mi_hostname     = trim((string) ($mi_json['hostname'] ?? ''));
    $mi_agent_id     = trim((string) ($mi_json['agent_id'] ?? ''));
    $mi_machine_guid = trim((string) ($mi_json['machine_guid'] ?? ''));

    if (strlen($mi_hostname) > 200)     { $mi_hostname = substr($mi_hostname, 0, 200); }
    if (strlen($mi_agent_id) > 200)     { $mi_agent_id = substr($mi_agent_id, 0, 200); }
    if (strlen($mi_machine_guid) > 100) { $mi_machine_guid = substr($mi_machine_guid, 0, 100); }

    if ($mi_hostname === '' && $mi_agent_id === '') {
        mi_reject(400, 'Either hostname or agent_id is required', 'Enroll Denied',
            "Enrollment supplied neither hostname nor agent_id (selector $mi_selector, ip $mi_ip)");
    }

    /*
     * Resolve the asset, in descending order of confidence. asset_rmm_links is
     * read only here — it is owned exclusively by class_rmm_asset_mapper.php and
     * this code must never write it.
     *
     * A hostname that matches more than one live asset is ambiguous and is
     * refused rather than guessed at: binding a device token to the wrong asset
     * silently poisons another machine's history, and there is no way to tell
     * afterwards which readings were wrong.
     */
    $mi_matches = [];

    if ($mi_agent_id !== '') {
        $mi_matches = api_q(
            "SELECT a.asset_id, a.asset_name, a.asset_client_id, l.integration_id
             FROM asset_rmm_links l
             JOIN assets a ON a.asset_id = l.asset_id
             WHERE l.tactical_agent_id = ? AND a.asset_archived_at IS NULL",
            's', [$mi_agent_id]
        );
        $mi_match_by = 'tactical_agent_id';
    }

    if (!$mi_matches && $mi_hostname !== '') {
        $mi_matches = api_q(
            "SELECT a.asset_id, a.asset_name, a.asset_client_id, l.integration_id
             FROM asset_rmm_links l
             JOIN assets a ON a.asset_id = l.asset_id
             WHERE l.hostname = ? AND a.asset_archived_at IS NULL",
            's', [$mi_hostname]
        );
        $mi_match_by = 'rmm hostname';
    }

    if (!$mi_matches && $mi_hostname !== '') {
        $mi_matches = api_q(
            "SELECT a.asset_id, a.asset_name, a.asset_client_id, 0 AS integration_id
             FROM assets a
             WHERE a.asset_name = ? AND a.asset_archived_at IS NULL",
            's', [$mi_hostname]
        );
        $mi_match_by = 'asset name';
    }

    // Distinct asset ids — one asset linked to two RMM integrations is one
    // device, not an ambiguity.
    $mi_by_asset = [];
    foreach ($mi_matches as $mi_row) {
        $mi_aid = intval($mi_row['asset_id']);
        if (!isset($mi_by_asset[$mi_aid])) {
            $mi_by_asset[$mi_aid] = $mi_row;
        }
    }

    if (count($mi_by_asset) === 0) {
        mi_reject(404, 'No matching asset for this device', 'Enroll Denied',
            "No asset matched hostname '$mi_hostname' / agent '$mi_agent_id' (selector $mi_selector, ip $mi_ip)");
    }
    if (count($mi_by_asset) > 1) {
        mi_reject(409, 'This device matches more than one asset; enroll it manually', 'Enroll Denied',
            "Ambiguous match by $mi_match_by for hostname '$mi_hostname' / agent '$mi_agent_id': asset ids "
            . implode(',', array_keys($mi_by_asset)));
    }

    $mi_asset          = array_values($mi_by_asset)[0];
    $mi_asset_id       = intval($mi_asset['asset_id']);
    $mi_asset_name     = (string) $mi_asset['asset_name'];
    $mi_client_id      = intval($mi_asset['asset_client_id']);
    $mi_integration_id = intval($mi_asset['integration_id']);

    /*
     * Supersede any device token this asset already holds. A device only ever
     * needs one credential; leaving stale ones live would mean a machine that
     * was re-imaged (and re-enrolled) still has a working token lying around in
     * whatever backup its old ProgramData ended up in. Revoked rather than
     * deleted so the audit trail survives.
     */
    $mi_superseded = mi_exec_affected($mysqli,
        "UPDATE device_metric_tokens
         SET token_revoked_at = NOW()
         WHERE token_asset_id = ? AND token_kind = 'device' AND token_revoked_at IS NULL",
        'i', [$mi_asset_id]
    );

    $mi_new   = mi_mint_token();
    $mi_label = substr('Collector: ' . ($mi_hostname !== '' ? $mi_hostname : $mi_asset_name), 0, 200);

    $mi_created = api_exec(
        "INSERT INTO device_metric_tokens
            (token_kind, token_asset_id, token_integration_id, token_selector, token_hash,
             token_label, token_created_at, token_created_by, token_enrolled_from, token_machine_guid)
         VALUES ('device', ?, ?, ?, ?, ?, NOW(), 0, ?, ?)",
        'iisssss',
        [$mi_asset_id, $mi_integration_id, $mi_new['selector'], $mi_new['hash'],
         $mi_label, $mi_selector, $mi_machine_guid]
    );

    if ($mi_created < 1) {
        mi_audit('Enroll Failed', "Could not store a device token for asset $mi_asset_id: " . mysqli_error($mysqli),
            $mi_client_id, $mi_asset_id, 'error');
        api_error(500, 'Could not issue a device token');
    }

    mi_audit(
        'Enroll',
        "Issued device metrics token {$mi_new['selector']} for asset #$mi_asset_id ($mi_asset_name), "
        . "matched by $mi_match_by, hostname '$mi_hostname', agent '$mi_agent_id', from $mi_ip"
        . ($mi_superseded > 0 ? ", superseding $mi_superseded previous token(s)" : ''),
        $mi_client_id,
        $mi_asset_id
    );

    api_response(200, [
        'data' => [
            'token'        => $mi_new['raw'],
            'token_id'     => $mi_new['selector'],
            'asset_id'     => $mi_asset_id,
            'asset_name'   => $mi_asset_name,
            'matched_by'   => $mi_match_by,
            'superseded'   => $mi_superseded,
            'issued_at'    => gmdate('Y-m-d\TH:i:s\Z'),
        ],
    ]);
}

// ===========================================================================
// POST /api/v1/metrics-ingest  — sample push
// ===========================================================================

$mi_token = mi_lookup_token($mysqli, $mi_selector, $mi_verifier, 'device');
if ($mi_token === null) {
    api_rate_limit('dm_authfail:' . $mi_ip, MI_RATE_AUTHFAIL_LIMIT, MI_RATE_AUTHFAIL_WINDOW);
    mi_audit('Push Denied', "Unknown, revoked or expired device token (selector $mi_selector) from $mi_ip", 0, 0, 'warning');
    api_error(401, 'Invalid device token');
}

// Per-device rate limit, keyed on the token's selector so one noisy or looping
// collector cannot drown the others.
if (!api_rate_limit('dm_push:' . $mi_selector, MI_RATE_PUSH_LIMIT, MI_RATE_PUSH_WINDOW)) {
    header('Retry-After: ' . MI_RATE_PUSH_WINDOW);
    api_error(429, 'Rate limit exceeded');
}

/*
 * The asset comes from the TOKEN, never from the payload. An `asset_id` field in
 * the body is ignored outright — a device credential must not be able to write
 * another device's series, and accepting a payload-supplied asset id would make
 * that a one-line request away.
 */
$mi_asset_id = intval($mi_token['token_asset_id']);
$mi_asset    = mi_load_asset($mysqli, $mi_asset_id);
if ($mi_asset === null) {
    mi_audit('Push Denied', "Device token $mi_selector is bound to asset $mi_asset_id, which is missing or archived",
        0, $mi_asset_id, 'warning');
    api_error(403, 'The asset this token is bound to is no longer active');
}
$mi_client_id      = intval($mi_asset['asset_client_id']);
$mi_asset_name     = (string) $mi_asset['asset_name'];
$mi_integration_id = intval($mi_token['token_integration_id']);

// config_enable_device_metrics gates collection instance-wide. Answered as 503
// (not 403) so the collector can treat it as "intentionally off, do not alert"
// and pass its check rather than paging someone about a deliberate setting.
if (!mi_collection_enabled($mysqli)) {
    header('Retry-After: 3600');
    api_response(503, [
        'error'   => 'Device metrics collection is disabled',
        'code'    => 'metrics_disabled',
        'retry_after' => 3600,
    ]);
}

$mi_body = mi_read_body(MI_MAX_BODY_BYTES);
$mi_json = json_decode($mi_body, true);

// -------------------------- envelope validation ----------------------------
// A malformed envelope rejects the WHOLE batch. Unlike a single unusable
// reading, a bad envelope means the producer is broken, and quietly accepting
// whatever happened to parse out of it would write half a truth into a history
// nobody can audit afterwards.

$mi_envelope_error = null;
if (!is_array($mi_json) || array_is_list($mi_json)) {
    $mi_envelope_error = 'body is not a JSON object';
} elseif (($mi_json['schema'] ?? '') !== MI_SCHEMA) {
    $mi_envelope_error = 'schema must be "' . MI_SCHEMA . '", got "'
        . substr((string) ($mi_json['schema'] ?? ''), 0, 40) . '"';
} elseif (!isset($mi_json['samples']) || !is_array($mi_json['samples']) || !array_is_list($mi_json['samples'])) {
    $mi_envelope_error = '"samples" must be a JSON array';
} elseif (count($mi_json['samples']) === 0) {
    $mi_envelope_error = '"samples" was empty';
} elseif (count($mi_json['samples']) > MI_MAX_SAMPLES) {
    $mi_envelope_error = '"samples" has ' . count($mi_json['samples']) . ' entries, limit is ' . MI_MAX_SAMPLES;
}

if ($mi_envelope_error !== null) {
    mi_reject(400, 'Malformed batch: ' . $mi_envelope_error, 'Batch Rejected',
        "Rejected batch from token $mi_selector (asset #$mi_asset_id $mi_asset_name): $mi_envelope_error",
        $mi_client_id, $mi_asset_id);
}

$mi_collector_version = substr((string) ($mi_json['collector_version'] ?? 'unknown'), 0, 40);
$mi_device            = is_array($mi_json['device'] ?? null) ? $mi_json['device'] : [];
$mi_device_hostname   = substr((string) ($mi_device['hostname'] ?? ''), 0, 200);

// Batch-level fallback timestamp for samples that omit their own.
$mi_batch_at = (string) ($mi_json['collected_at'] ?? '');

// -------------------------- per-sample validation ---------------------------
// Every reading goes through MetricSample::of(), which enforces the registry:
// the key must exist, the value must be numeric/finite/in range, and the
// instance key must agree with the metric's declared dimension. Out-of-range is
// REJECTED, never clamped — a CPU reading of 1200% is a collection bug, and
// clamping it to 100 would bake that bug into the history permanently.

$mi_samples  = [];
$mi_reasons  = [];   // reason => count
$mi_examples = [];   // reason => first offending metric key, for the audit line

$mi_add_reason = static function (string $reason, string $context) use (&$mi_reasons, &$mi_examples): void {
    if (!isset($mi_reasons[$reason])) {
        $mi_reasons[$reason]  = 0;
        $mi_examples[$reason] = $context;
    }
    $mi_reasons[$reason]++;
};

foreach ($mi_json['samples'] as $mi_entry) {
    if (!is_array($mi_entry) || array_is_list($mi_entry)) {
        $mi_add_reason('malformed_sample', '');
        continue;
    }

    $mi_key = trim((string) ($mi_entry['metric'] ?? $mi_entry['metric_key'] ?? ''));
    if ($mi_key === '') {
        $mi_add_reason('missing_metric_key', '');
        continue;
    }
    if (!\ITFlow\Metrics\MetricRegistry::exists($mi_key)) {
        $mi_add_reason('unknown_metric', $mi_key);
        continue;
    }

    if (!array_key_exists('value', $mi_entry)) {
        $mi_add_reason('missing_value', $mi_key);
        continue;
    }
    $mi_value = $mi_entry['value'];
    if (is_bool($mi_value)) {
        // JSON true/false is a legitimate way to send system.pending_reboot.
        $mi_value = $mi_value ? 1 : 0;
    }

    $mi_instance = $mi_entry['instance'] ?? $mi_entry['instance_key'] ?? null;
    if ($mi_instance !== null && !is_scalar($mi_instance)) {
        $mi_add_reason('bad_instance_key', $mi_key);
        continue;
    }
    $mi_instance = ($mi_instance === null) ? null : trim((string) $mi_instance);
    if ($mi_instance === '') {
        $mi_instance = null;
    }

    $mi_label = $mi_entry['label'] ?? $mi_entry['instance_label'] ?? null;
    if ($mi_label !== null && !is_scalar($mi_label)) {
        $mi_label = null;
    }
    $mi_label = ($mi_label === null) ? null : trim((string) $mi_label);
    if ($mi_label === '') {
        $mi_label = null;
    }

    // sampled_at is UTC — see the header comment. A bare timestamp with no zone
    // or offset is read AS UTC, matching MetricIngestService, because every
    // producer on this path emits UTC.
    $mi_at = (string) ($mi_entry['at'] ?? $mi_entry['sampled_at'] ?? '');
    if ($mi_at === '') {
        $mi_at = $mi_batch_at;
    }
    try {
        $mi_when = ($mi_at === '')
            ? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            : new \DateTimeImmutable($mi_at, new \DateTimeZone('UTC'));
    } catch (\Exception $e) {
        $mi_add_reason('invalid_timestamp', $mi_key);
        continue;
    }

    try {
        // asset id from the token, not the payload.
        $mi_samples[] = \ITFlow\Metrics\MetricSample::of(
            $mi_asset_id,
            $mi_key,
            $mi_instance,
            $mi_value,
            $mi_when,
            $mi_label
        );
    } catch (\InvalidArgumentException $e) {
        // of() carries the precise reason: out of range, dimension mismatch,
        // non-numeric. Bucket by a short tag but keep the first full message.
        $mi_msg = $e->getMessage();
        if (strpos($mi_msg, 'is not valid for metric') !== false) {
            $mi_add_reason('invalid_value', $mi_key . ' -> ' . substr($mi_msg, 0, 120));
        } elseif (strpos($mi_msg, 'requires an instance key') !== false) {
            $mi_add_reason('missing_instance_key', $mi_key);
        } elseif (strpos($mi_msg, 'takes no instance key') !== false) {
            $mi_add_reason('unexpected_instance_key', $mi_key);
        } else {
            $mi_add_reason('rejected_sample', $mi_key . ' -> ' . substr($mi_msg, 0, 120));
        }
    }
}

$mi_rejected_count = array_sum($mi_reasons);

// A batch in which nothing at all survived validation is a producer bug, not a
// partial outage. Answer 422 so the collector's check goes red and somebody
// looks, instead of silently succeeding with zero rows written forever.
if (!$mi_samples) {
    $mi_summary = [];
    foreach ($mi_reasons as $mi_reason => $mi_count) {
        $mi_summary[] = "$mi_reason x$mi_count (" . $mi_examples[$mi_reason] . ')';
    }
    mi_audit(
        'Batch Rejected',
        "All " . $mi_rejected_count . " samples rejected from token $mi_selector "
        . "(asset #$mi_asset_id $mi_asset_name, collector $mi_collector_version): " . implode('; ', $mi_summary),
        $mi_client_id,
        $mi_asset_id,
        'error'
    );
    api_response(422, [
        'error'          => 'Every sample in the batch was rejected',
        'received'       => $mi_rejected_count,
        'accepted'       => 0,
        'reject_reasons' => $mi_reasons,
    ]);
}

// -------------------------------- ingest ------------------------------------

$mi_service = new \ITFlow\Metrics\MetricIngestService($mysqli);
$mi_result  = $mi_service->ingest($mi_samples, $mi_integration_id > 0 ? $mi_integration_id : null);

// Touch the token so an admin can see which devices are actually reporting.
// Local time here (NOW()), matching every other admin-facing timestamp column —
// unlike sampled_at, which is UTC.
mi_exec_affected($mysqli,
    "UPDATE device_metric_tokens
     SET token_last_used_at = NOW(), token_last_ip = ?
     WHERE token_selector = ?",
    'ss', [substr($mi_ip, 0, 45), $mi_selector]
);

// Anything the ingest service itself refused (unknown metric id, instance
// resolution failure, insert error) is folded into the same reason map so one
// response and one audit line tell the whole story.
foreach (($mi_result['reject_reasons'] ?? []) as $mi_reason => $mi_count) {
    if (!isset($mi_reasons[$mi_reason])) {
        $mi_reasons[$mi_reason] = 0;
    }
    $mi_reasons[$mi_reason] += (int) $mi_count;
}
$mi_rejected_count = array_sum($mi_reasons);

// Audit only when something was actually thrown away, or the writer errored —
// a clean 5-minute push from 21 agents must not write 6,048 audit rows a day.
if ($mi_rejected_count > 0 || !empty($mi_result['errors'])) {
    $mi_summary = [];
    foreach ($mi_reasons as $mi_reason => $mi_count) {
        $mi_summary[] = "$mi_reason x$mi_count" . (isset($mi_examples[$mi_reason]) ? ' (' . $mi_examples[$mi_reason] . ')' : '');
    }
    mi_audit(
        'Batch Partially Rejected',
        "Token $mi_selector, asset #$mi_asset_id ($mi_asset_name), collector $mi_collector_version, host '$mi_device_hostname': "
        . "inserted {$mi_result['inserted']}, duplicate {$mi_result['duplicate']}, rejected $mi_rejected_count — "
        . implode('; ', $mi_summary)
        . (!empty($mi_result['errors']) ? ' | errors: ' . implode(' | ', $mi_result['errors']) : ''),
        $mi_client_id,
        $mi_asset_id,
        'warning'
    );
}

api_response(200, [
    'data' => [
        'asset_id'       => $mi_asset_id,
        'received'       => count($mi_json['samples']),
        'accepted'       => count($mi_samples),
        'inserted'       => (int) $mi_result['inserted'],
        'duplicate'      => (int) $mi_result['duplicate'],
        'rejected'       => $mi_rejected_count,
        'reject_reasons' => (object) $mi_reasons,
        'server_time'    => gmdate('Y-m-d\TH:i:s\Z'),
    ],
]);
