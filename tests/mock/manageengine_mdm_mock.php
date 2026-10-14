<?php
/*
 * Local stand-in for the ManageEngine MDM Cloud API and the Zoho Accounts token endpoint, built from the published docs
 * (docs/MANAGEENGINE_MDM.md). Never contacts ManageEngine or Zoho. Run as a router:
 *   MOCK_STATE=/path/state.json php -S 127.0.0.1:PORT tests/mock/manageengine_mdm_mock.php
 * The same server answers both hosts (MdmClient gets mdm_base = accounts_base = http://127.0.0.1:PORT in tests).
 *
 *   POST /oauth/v2/token              form: refresh_token, client_id, client_secret, grant_type=refresh_token.
 *                                      refresh_token "good-refresh" + client_secret "good-secret" -> {"access_token":"at-N","expires_in":3600,...}
 *                                      anything else -> HTTP 200 {"error":"invalid_code"} (Zoho reports token errors with status 200).
 *                                      state.token_throttle_text -> HTTP 200 {"error":"Access Denied","error_description":"You have made too many requests continuously. ..."}
 *                                      state.token_429 (count) -> HTTP 429 + Retry-After: 1 first
 *   GET  /api/v1/mdm/devices           ?limit&offset -> {"metadata":{"total_record_count":N},"devices":[...],"paging":{"next":..,"previous":..}}
 *   GET  /api/v1/mdm/devices/{id}      state.details[id] (404 {"error_code":"COM0008",...} when absent)
 *   GET  /api/v1/mdm/devices/{id}/summary   state.summaries[id]
 *   POST /__state  body JSON merged into the state (__reset clears), GET /__log every request seen (method, path, query, auth, form keys)
 * On-premises edition: state.onprem=true makes the Authorization header value be compared with state.api_key (default onprem-good-key); there is no token endpoint.
 * MSP edition: state.customers (list) answers GET /api/v1/mdm/customers; state.customer_devices maps an X-CUSTOMER header value to that customer's device list.
 *   state.malformed (truncated JSON) and state.oversize (bytes of padding) test the client's response checks.
 * State switches: max_limit (the server caps `limit`, like a default page of 50), devices (list rows), details, summaries, ignore_offset (server ignores offset: always page 1), foreign_next (paging.next
 *   points at another host), no_paging (no metadata/paging object), fail_status (every API call answers it), fail_once (list of statuses
 *   consumed one per API call), retry_after, summary_forbidden (summary calls 403 COM0013-style), unauthorized_once (next API call 401),
 *   html (API answers 200 with HTML), revoke_tokens (every issued token becomes invalid -> 401), slow (sleep seconds before answering).
 */
$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/mdm_mock_state.json';
$logFile = $stateFile . '.log';

function st_read(string $f): array { return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; }
function with_state(string $f, callable $fn) {
    $h = fopen($f . '.lock', 'c'); flock($h, LOCK_EX);
    $s = st_read($f); $r = $fn($s); file_put_contents($f, json_encode($s)); flock($h, LOCK_UN); fclose($h);
    return $r;
}
function reply(int $code, $body, array $headers = []): void {
    http_response_code($code);
    foreach ($headers as $h) header($h);
    if (is_array($body)) { header('Content-Type: application/json'); echo json_encode($body); } else { echo $body; }
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($path === '/__state' && $method === 'POST') {
    $new = json_decode(file_get_contents('php://input'), true) ?: [];
    $out = with_state($stateFile, function (array &$s) use ($new) { $s = array_replace($s, $new); if (isset($new['__reset'])) { $s = []; @unlink($GLOBALS['logFile']); } return $s; });
    return reply(200, $out);
}
if ($path === '/__log') {
    $lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : [];
    return reply(200, array_map(fn ($l) => json_decode($l, true), $lines));
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
parse_str(file_get_contents('php://input'), $form);
// The log never holds a secret value: only which form keys were present, and the (random-looking) access token used.
file_put_contents($logFile, json_encode(['method' => $method, 'path' => $path, 'query' => $_SERVER['QUERY_STRING'] ?? '', 'auth' => $auth, 'form_keys' => array_keys($form),
    'x_customer' => $_SERVER['HTTP_X_CUSTOMER'] ?? null, 'grant_type' => $form['grant_type'] ?? null, 'time' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);

$s0 = st_read($stateFile);
if (!empty($s0['slow'])) { usleep((int) ($s0['slow'] * 1000000)); }

if ($path === '/oauth/v2/token' && $method === 'POST' && empty($s0['onprem'])) {
    $gate = with_state($stateFile, function (array &$s) {
        if (($s['token_429'] ?? 0) > 0) { $s['token_429']--; return '429'; }
        return '';
    });
    if ($gate === '429') { return reply(429, ['error' => 'rate'], ['Retry-After: 1']); }
    if (!empty($s0['token_throttle_text'])) {
        return reply(200, ['error_description' => 'You have made too many requests continuously. Please try again after some time.', 'error' => 'Access Denied']);
    }
    if (($form['grant_type'] ?? '') !== 'refresh_token' || ($form['refresh_token'] ?? '') !== 'good-refresh' || ($form['client_secret'] ?? '') !== 'good-secret' || ($form['client_id'] ?? '') === '') {
        return reply(200, ['error' => 'invalid_code']);
    }
    $n = with_state($stateFile, function (array &$st) { $st['token_n'] = ($st['token_n'] ?? 0) + 1; return $st['token_n']; });
    return reply(200, ['access_token' => 'at-' . $n, 'scope' => 'MDMOnDemand.MDMInventory.READ', 'api_domain' => 'https://www.zohoapis.com', 'token_type' => 'Bearer', 'expires_in' => $s0['expires_in'] ?? 3600]);
}

if (!str_starts_with($path, '/api/v1/mdm/')) { return reply(404, ['error_code' => 'COM0008', 'error_description' => 'Unknown resource']); }
if ($method !== 'GET') { return reply(405, ['error_code' => 'COM0001', 'error_description' => 'Method not implemented']); }
$onprem = !empty($s0['onprem']);   // on-premises edition: the API key itself is the Authorization header value (state.api_key), no OAuth, no token endpoint
$authOk = $onprem ? (($s0['api_key'] ?? 'onprem-good-key') !== '' && hash_equals((string) ($s0['api_key'] ?? 'onprem-good-key'), $auth) && empty($s0['revoke_tokens']))
    : (preg_match('/^Zoho-oauthtoken at-(\d+)$/', $auth) && empty($s0['revoke_tokens']));
if (!$authOk) {
    return reply(401, ['error_code' => 'COM0013', 'error_description' => 'Not authorized or invalid auth token', 'localized_error_description' => 'x']);
}

$gate = with_state($stateFile, function (array &$s) {
    if (!empty($s['unauthorized_once'])) { $s['unauthorized_once'] = false; return [401]; }
    if (!empty($s['fail_once'])) { $c = array_shift($s['fail_once']); return [(int) $c]; }
    if (!empty($s['fail_status'])) { return [(int) $s['fail_status']]; }
    return [];
});
if ($gate) {
    $code = $gate[0];
    $hdr = ($code === 429 || $code === 503) && array_key_exists('retry_after', $s0) ? ['Retry-After: ' . $s0['retry_after']] : [];
    $err = [401 => ['COM0013', 'Not authorized or invalid auth token'], 403 => ['COM0013', 'forbidden'], 429 => ['COM0002', 'API limit exceeded'], 500 => ['COM0004', 'Internal server error']];
    return reply($code, ['error_code' => $err[$code][0] ?? 'COM0014', 'error_description' => $err[$code][1] ?? 'Bad request', 'localized_error_description' => 'x'], $hdr);
}
if (!empty($s0['html'])) { return reply(200, '<html>maintenance</html>'); }

if ($path === '/api/v1/mdm/customers') {
    // MSP edition: GET customers -> {"customers":[{customer_id,customer_name,...}]} (the path prefix is an assumption, see docs/MANAGEENGINE_MDM.md)
    return empty($s0['customers']) ? reply(403, ['error_code' => 'COM0013', 'error_description' => 'not an MSP server']) : reply(200, ['customers' => $s0['customers']]);
}
$devices = $s0['devices'] ?? [];
if (isset($s0['customer_devices'])) {   // MSP edition: the X-CUSTOMER header picks the customer
    $devices = $s0['customer_devices'][$_SERVER['HTTP_X_CUSTOMER'] ?? ''] ?? [];
}
if (!empty($s0['malformed']) && $path === '/api/v1/mdm/devices') { return reply(200, '{"devices": [ {"device_id": "1", "devi'); }
if (!empty($s0['oversize']) && $path === '/api/v1/mdm/devices') { return reply(200, '{"devices":[],"pad":"' . str_repeat('A', (int) $s0['oversize']) . '"}'); }
if ($path === '/api/v1/mdm/devices') {
    $limit = max(1, min(!empty($s0['max_limit']) ? (int) $s0['max_limit'] : 1000, (int) ($_GET['limit'] ?? 50)));
    $offset = !empty($s0['ignore_offset']) ? 0 : max(0, (int) ($_GET['offset'] ?? 0));
    $slice = array_slice($devices, $offset, $limit);
    $out = ['devices' => $slice];
    if (empty($s0['no_paging'])) {
        $out['metadata'] = ['total_record_count' => count($devices)];
        $next = ($offset + $limit) < count($devices);
        $out['paging'] = ['previous' => "https://{BASE_URL}/api/v1/mdm/devices?limit=$limit&offset=" . max(0, $offset - $limit)]
            + ($next ? ['next' => !empty($s0['foreign_next']) ? 'http://127.0.0.1:9/evil/api/v1/mdm/devices?limit=' . $limit . '&offset=' . ($offset + $limit) : "https://{BASE_URL}/api/v1/mdm/devices?limit=$limit&offset=" . ($offset + $limit)] : []);
    }
    return reply(200, $out);
}
if (preg_match('#^/api/v1/mdm/devices/(\d+)(/summary)?$#', $path, $m)) {
    if (!empty($m[2])) {
        if (!empty($s0['summary_forbidden'])) { return reply(403, ['error_code' => 'COM0013', 'error_description' => 'insufficient scope MDMDeviceMgmt']); }
        $row = ($s0['summaries'] ?? [])[$m[1]] ?? null;
    } else {
        $row = ($s0['details'] ?? [])[$m[1]] ?? null;
    }
    return $row === null ? reply(404, ['error_code' => 'COM0008', 'error_description' => 'Unknown ID']) : reply(200, $row);
}
return reply(404, ['error_code' => 'COM0008', 'error_description' => 'Unknown resource']);
