<?php
/*
 * Local stand-in for Entra (token endpoint) and Microsoft Graph (users, managedDevices, organization). Run as a router:
 *   MOCK_STATE=/path/state.json php -S 127.0.0.1:PORT tests/mock/graph_mock.php
 * Paths (the base URL given to GraphClient is http://127.0.0.1:PORT/v1.0, the authority http://127.0.0.1:PORT):
 *   POST /{tenant}/oauth2/v2.0/token   tenant "badsecret" -> 401 invalid_client; "throttletoken" -> 429; anything else -> JWT
 *                                      (its `roles` claim = state.roles, default both permissions), expires_in = state.expires_in or 3600
 *   GET  /v1.0/users, /v1.0/deviceManagement/managedDevices   100 per page, 250 users / 150 devices (state.users / state.devices)
 *   GET  /v1.0/organization
 *   POST /__state                      body = JSON merged into the state file (scenario switches below), then returns it
 *   GET  /__log                        JSON array of every request seen (method, path, query, auth token, time)
 * State switches (all optional): throttle (next N Graph calls answer 429 with Retry-After = retry_after), throttle_code (429|503),
 *   forbidden_devices / forbidden_users (403 Authorization_RequestDenied), unauthorized_once (next Graph call 401), loop (users nextLink
 *   points at itself), foreign_link (users nextLink points at another host), endless (every page has a nextLink), not_licensed
 *   (devices 400 "not applicable to target tenant"), html (devices answer 200 with HTML), page_size.
 */
$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/graph_mock_state.json';
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
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = $_GET;
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
file_put_contents($logFile, json_encode(['method' => $method, 'path' => $path, 'query' => $_SERVER['QUERY_STRING'] ?? '', 'auth' => $auth, 'time' => microtime(true)]) . "\n", FILE_APPEND | LOCK_EX);

if (preg_match('#^/([^/]+)/oauth2/v2\.0/token$#', $path, $m) && $method === 'POST') {
    $tenant = $m[1];
    if ($tenant === 'badsecret') {
        return reply(401, ['error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: abc\r\nCorrelation ID: def"]);
    }
    if ($tenant === 'throttletoken') {
        return reply(429, ['error' => 'temporarily_unavailable'], ['Retry-After: 1']);
    }
    $s = st_read($stateFile);
    $roles = $s['roles'] ?? ['User.Read.All', 'DeviceManagementManagedDevices.Read.All'];
    $n = with_state($stateFile, function (array &$st) { $st['token_n'] = ($st['token_n'] ?? 0) + 1; return $st['token_n']; });
    $jwt = b64u('{"alg":"none"}') . '.' . b64u(json_encode(['roles' => $roles, 'tid' => $tenant, 'n' => $n])) . '.sig';
    return reply(200, ['token_type' => 'Bearer', 'expires_in' => $s['expires_in'] ?? 3600, 'access_token' => $jwt]);
}

if (!str_starts_with($path, '/v1.0/')) { return reply(404, ['error' => ['code' => 'NotFound', 'message' => 'no such mock route']]); }
if (!preg_match('/^Bearer \S+\.\S+\.\S+$/', $auth)) { return reply(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token is missing or malformed.']]); }

// Per-call scenario switches (consumed under a lock).
$gate = with_state($stateFile, function (array &$s) {
    if (($s['unauthorized_once'] ?? false)) { $s['unauthorized_once'] = false; return ['401']; }
    if (($s['throttle'] ?? 0) > 0) { $s['throttle']--; return ['throttle', $s['throttle_code'] ?? 429, (array_key_exists('retry_after', $s) ? $s['retry_after'] : 1)]; }
    return null;
});
if ($gate && $gate[0] === '401') { return reply(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Lifetime validation failed, the token is expired.']]); }
if ($gate && $gate[0] === 'throttle') { return reply((int) $gate[1], ['error' => ['code' => 'TooManyRequests', 'message' => 'Throttled']], $gate[2] === null ? [] : ['Retry-After: ' . $gate[2]]); }

$s = st_read($stateFile);
$size = (int) ($s['page_size'] ?? 100);
$host = 'http://' . $_SERVER['HTTP_HOST'];

if ($path === '/v1.0/organization') {
    return reply(200, ['value' => [['id' => 'org-1', 'displayName' => 'Mock Tenant Inc']]]);
}

$collections = [
    '/v1.0/users' => ['total' => (int) ($s['users'] ?? 250), 'forbidden' => !empty($s['forbidden_users']), 'make' => fn ($i) => ['id' => sprintf('user-%04d', $i), 'displayName' => "User $i", 'mail' => "user$i@example.test", 'userPrincipalName' => "user$i@example.test", 'accountEnabled' => true, 'department' => 'Dept ' . ($i % 3)]],
    '/v1.0/deviceManagement/managedDevices' => ['total' => (int) ($s['devices'] ?? 150), 'forbidden' => !empty($s['forbidden_devices']), 'make' => fn ($i) => ['id' => sprintf('dev-%04d', $i), 'deviceName' => "MOCK-PC-$i", 'serialNumber' => "SN$i", 'operatingSystem' => 'Windows', 'osVersion' => '10.0.22631', 'manufacturer' => 'Mock', 'model' => 'Model X', 'complianceState' => 'compliant', 'managementAgent' => 'mdm', 'lastSyncDateTime' => '2026-10-01T10:00:00Z', 'azureADDeviceId' => sprintf('aad-%04d', $i), 'userPrincipalName' => "user$i@example.test", 'enrolledDateTime' => '2026-01-01T00:00:00Z', 'isEncrypted' => true]],
];
if (preg_match('#^/v1\.0/users/([^/]+)$#', $path, $um)) {
    $id = urldecode($um[1]);
    return str_starts_with($id, 'user-') ? reply(200, ['id' => $id, 'displayName' => 'One', 'mail' => 'one@example.test', 'accountEnabled' => true]) : reply(404, ['error' => ['code' => 'Request_ResourceNotFound', 'message' => 'Resource does not exist.']]);
}
if (!isset($collections[$path])) { return reply(404, ['error' => ['code' => 'NotFound', 'message' => 'no such mock route']]); }
$c = $collections[$path];
if ($c['forbidden']) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
if ($path === '/v1.0/deviceManagement/managedDevices' && !empty($s['not_licensed'])) { return reply(400, ['error' => ['code' => 'BadRequest', 'message' => 'Request not applicable to target tenant.']]); }
if ($path === '/v1.0/deviceManagement/managedDevices' && !empty($s['html'])) { return reply(200, '<html>login</html>', ['Content-Type: text/html']); }

$skip = (int) ($query['$skip'] ?? 0);
$top = min($size, max(1, (int) ($query['$top'] ?? $size)));
$out = ['value' => []];
for ($i = $skip; $i < min($c['total'], $skip + $top); $i++) { $out['value'][] = $c['make']($i); }
$next = $skip + $top;
if ($path === '/v1.0/users' && !empty($s['loop'])) { $out['@odata.nextLink'] = $host . $path . '?$skip=' . $skip . '&$top=' . $top; }
elseif ($path === '/v1.0/users' && !empty($s['foreign_link'])) { $out['@odata.nextLink'] = 'http://127.0.0.2:1/v1.0/users?$skip=' . $next; }
elseif (!empty($s['endless']) || $next < $c['total']) { $out['@odata.nextLink'] = $host . $path . '?$skip=' . $next . '&$top=' . $top; }
return reply(200, $out);
