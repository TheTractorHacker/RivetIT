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
 * Account writes (state.accounts = {upn: {id, mail, enabled, displayName}} is the directory; state.groups = {gid: [userId]}):
 *   POST /v1.0/users (create; 400 ObjectConflict when the UPN exists), PATCH /v1.0/users/{id|upn} (accountEnabled), POST /v1.0/users/{id}/revokeSignInSessions,
 *   POST /v1.0/groups/{gid}/members/$ref (400 "already exist" on a repeat), DELETE /v1.0/groups/{gid}/members/{uid}/$ref, GET /v1.0/users/{upn} and
 *   GET /v1.0/users?$filter=mail eq '...' answer from state.accounts first. state.write_forbidden -> every write answers 403 Authorization_RequestDenied.
 *   state.revoked lists user ids whose sessions were revoked; state.last_password holds the last password sent (a TEST hook: the request log never
 *   contains it - bodies are logged with passwordProfile.password replaced by <redacted>).
 * Entra extras (issue #35): GET /v1.0/users/delta (baseline round + deltaLink; a round with $deltatoken returns state.delta_changed and state.delta_removed
 *   as @removed; state.delta_invalid -> 410 syncStateNotFound), GET /v1.0/groups, /groups/{id}/members/microsoft.graph.user, /subscribedSkus,
 *   /users?$select=id,assignedLicenses, /deviceManagement/managedDevices/{id}/deviceCompliancePolicyStates and /detectedApps, /applications?$filter=appId...
 *   (switches are documented at the route block: forbidden_groups, forbidden_licenses, forbidden_details, forbidden_apps, device_gone, app_secrets, no_app, ...).
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
$rawBody = file_get_contents('php://input');
$logBody = json_decode($rawBody, true);
if (is_array($logBody) && isset($logBody['passwordProfile']['password'])) { $logBody['passwordProfile']['password'] = '<redacted>'; }
file_put_contents($logFile, json_encode(['method' => $method, 'path' => $path, 'query' => $_SERVER['QUERY_STRING'] ?? '', 'auth' => $auth, 'time' => microtime(true), 'body' => is_array($logBody) ? $logBody : null]) . "\n", FILE_APPEND | LOCK_EX);

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
$jsonBody = json_decode($rawBody, true) ?: [];
$notFound = fn () => reply(404, ['error' => ['code' => 'Request_ResourceNotFound', 'message' => 'Resource does not exist.']]);
$findAccount = function (array $st, string $key): ?array {
    foreach ($st['accounts'] ?? [] as $upn => $a) { if (strcasecmp($upn, $key) === 0 || $a['id'] === $key) { return ['upn' => $upn] + $a; } }
    return null;
};
$isWrite = $method !== 'GET' && (str_starts_with($path, '/v1.0/users') || str_starts_with($path, '/v1.0/groups'));
if ($isWrite && !empty($s['write_forbidden'])) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
if ($method === 'POST' && $path === '/v1.0/users') {
    $upn = (string) ($jsonBody['userPrincipalName'] ?? '');
    if ($findAccount($s, $upn)) { return reply(400, ['error' => ['code' => 'Request_BadRequest', 'message' => 'Another object with the same value for property userPrincipalName already exists.']]); }
    $id = 'acct-' . substr(md5($upn), 0, 8);
    with_state($stateFile, function (array &$st) use ($upn, $id, $jsonBody) {
        $st['accounts'][$upn] = ['id' => $id, 'mail' => null, 'enabled' => (bool) ($jsonBody['accountEnabled'] ?? false), 'displayName' => $jsonBody['displayName'] ?? '', 'force_change' => (bool) ($jsonBody['passwordProfile']['forceChangePasswordNextSignIn'] ?? false), 'has_password' => !empty($jsonBody['passwordProfile']['password'])];
        $st['last_password'] = $jsonBody['passwordProfile']['password'] ?? null;
        $st['creates'] = ($st['creates'] ?? 0) + 1;
    });
    return reply(201, ['id' => $id, 'userPrincipalName' => $upn, 'displayName' => $jsonBody['displayName'] ?? '', 'accountEnabled' => (bool) ($jsonBody['accountEnabled'] ?? false)]);
}
if (preg_match('#^/v1\.0/users/([^/]+)/revokeSignInSessions$#', $path, $m) && $method === 'POST') {
    $a = $findAccount($s, urldecode($m[1]));
    if (!$a) { return $notFound(); }
    with_state($stateFile, function (array &$st) use ($a) { $st['revoked'][] = $a['id']; });
    return reply(200, ['value' => true]);
}
if (preg_match('#^/v1\.0/users/([^/]+)$#', $path, $m) && $method === 'PATCH') {
    $a = $findAccount($s, urldecode($m[1]));
    if (!$a) { return $notFound(); }
    with_state($stateFile, function (array &$st) use ($a, $jsonBody) { if (array_key_exists('accountEnabled', $jsonBody)) { $st['accounts'][$a['upn']]['enabled'] = (bool) $jsonBody['accountEnabled']; } });
    return reply(204, '');
}
if (preg_match('#^/v1\.0/groups/([^/]+)/members/\$ref$#', $path, $m) && $method === 'POST') {
    $gid = urldecode($m[1]);
    $uid = basename((string) ($jsonBody['@odata.id'] ?? ''));
    if (in_array($uid, $s['groups'][$gid] ?? [], true)) { return reply(400, ['error' => ['code' => 'Request_BadRequest', 'message' => 'One or more added object references already exist for the following modified properties: \'members\'.']]); }
    with_state($stateFile, function (array &$st) use ($gid, $uid) { $st['groups'][$gid][] = $uid; });
    return reply(204, '');
}
if (preg_match('#^/v1\.0/groups/([^/]+)/members/([^/]+)/\$ref$#', $path, $m) && $method === 'DELETE') {
    $gid = urldecode($m[1]); $uid = urldecode($m[2]);
    if (!in_array($uid, $s['groups'][$gid] ?? [], true)) { return $notFound(); }
    with_state($stateFile, function (array &$st) use ($gid, $uid) { $st['groups'][$gid] = array_values(array_diff($st['groups'][$gid], [$uid])); });
    return reply(204, '');
}
if ($method === 'GET' && $path === '/v1.0/users' && isset($query['$filter']) && !empty($s['accounts'])) {
    $mail = preg_match("/mail eq '(.*)'/", $query['$filter'], $fm) ? str_replace("''", "'", $fm[1]) : '';
    $out = ['value' => []];
    foreach ($s['accounts'] as $upn => $a) { if ($a['mail'] !== null && strcasecmp($a['mail'], $mail) === 0) { $out['value'][] = ['id' => $a['id'], 'displayName' => $a['displayName'], 'mail' => $a['mail'], 'userPrincipalName' => $upn, 'accountEnabled' => $a['enabled'], 'department' => $a['department'] ?? null]; } }
    return reply(200, $out);
}
if ($method === 'GET' && preg_match('#^/v1\.0/users/([^/]+)$#', $path, $m) && ($a = $findAccount($s, urldecode($m[1])))) {
    return reply(200, ['id' => $a['id'], 'displayName' => $a['displayName'], 'mail' => $a['mail'], 'userPrincipalName' => $a['upn'], 'accountEnabled' => $a['enabled'], 'department' => $a['department'] ?? null]);
}
$host = 'http://' . $_SERVER['HTTP_HOST'];

// ---- Entra extras (issue #35): delta users, groups, subscribedSkus, per-device compliance/apps, applications (secret expiry)
// state: delta_changed (list of user dicts returned by a delta round that carries a token), delta_removed (ids), delta_invalid (410 syncStateNotFound),
//   delta_users (how many users a baseline delta round returns; default = state.users), group_defs (list of group dicts; default 3), groups (gid => [user ids],
//   shared with the write routes above), skus (list; default 2), forbidden_groups / forbidden_licenses / forbidden_details / forbidden_apps,
//   app_secrets (list of passwordCredentials; default one with hint "sec"), no_app (the app registration is not found), device_gone (ids that 404).
$mkUser = fn ($i) => ['id' => sprintf('user-%04d', $i), 'displayName' => "User $i", 'mail' => "user$i@example.test", 'userPrincipalName' => "user$i@example.test", 'accountEnabled' => true, 'department' => 'Dept ' . ($i % 3), 'assignedLicenses' => $i % 2 === 0 ? [['skuId' => 'sku-0001', 'disabledPlans' => []]] : []];
if ($method === 'GET' && $path === '/v1.0/users/delta') {
    if (!empty($s['forbidden_users'])) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
    $tok = $query['$deltatoken'] ?? null;
    if ($tok !== null) {
        if (!empty($s['delta_invalid'])) { return reply(410, ['error' => ['code' => 'syncStateNotFound', 'message' => 'The sync state generation is not found.']]); }
        $val = array_values($s['delta_changed'] ?? []);
        foreach ($s['delta_removed'] ?? [] as $rid) { $val[] = ['id' => $rid, '@removed' => ['reason' => 'deleted']]; }
        $n = with_state($stateFile, function (array &$st) { $st['delta_n'] = ($st['delta_n'] ?? 0) + 1; return $st['delta_n']; });
        return reply(200, ['value' => $val, '@odata.deltaLink' => $host . '/v1.0/users/delta?$deltatoken=t' . $n]);
    }
    $total = (int) ($s['delta_users'] ?? $s['users'] ?? 250);
    $skip = (int) ($query['$skiptoken'] ?? 0);
    $top = min($size, 100);
    $out = ['value' => []];
    for ($i = $skip; $i < min($total, $skip + $top); $i++) { $out['value'][] = $mkUser($i); }
    if ($skip + $top < $total) { $out['@odata.nextLink'] = $host . '/v1.0/users/delta?$skiptoken=' . ($skip + $top); }
    else {
        $n = with_state($stateFile, function (array &$st) { $st['delta_n'] = ($st['delta_n'] ?? 0) + 1; return $st['delta_n']; });
        $out['@odata.deltaLink'] = $host . '/v1.0/users/delta?$deltatoken=t' . $n;
    }
    return reply(200, $out);
}
if ($method === 'GET' && $path === '/v1.0/groups') {
    if (!empty($s['forbidden_groups'])) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
    $defs = $s['group_defs'] ?? [
        ['id' => 'grp-1', 'displayName' => 'All Staff', 'description' => 'Everyone', 'mail' => null, 'mailEnabled' => false, 'securityEnabled' => true, 'groupTypes' => []],
        ['id' => 'grp-2', 'displayName' => 'Sales Team', 'description' => null, 'mail' => 'sales@example.test', 'mailEnabled' => true, 'securityEnabled' => false, 'groupTypes' => ['Unified']],
        ['id' => 'grp-3', 'displayName' => 'Finance DL', 'description' => null, 'mail' => 'fin@example.test', 'mailEnabled' => true, 'securityEnabled' => false, 'groupTypes' => []],
    ];
    return reply(200, ['value' => array_values($defs)]);
}
if ($method === 'GET' && preg_match('#^/v1\.0/groups/([^/]+)/members/microsoft\.graph\.user$#', $path, $gm)) {
    if (!empty($s['forbidden_groups'])) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
    $gid = urldecode($gm[1]);
    $members = $s['groups'][$gid] ?? ($gid === 'grp-1' ? ['user-0000', 'user-0001', 'user-0002'] : ($gid === 'grp-2' ? ['user-0001'] : []));
    return reply(200, ['value' => array_map(fn ($u) => ['@odata.type' => '#microsoft.graph.user', 'id' => $u], array_values($members))]);
}
if ($method === 'GET' && $path === '/v1.0/subscribedSkus') {
    if (!empty($s['forbidden_licenses'])) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
    return reply(200, ['value' => $s['skus'] ?? [
        ['skuId' => 'sku-0001', 'skuPartNumber' => 'SPB', 'capabilityStatus' => 'Enabled', 'consumedUnits' => 5, 'prepaidUnits' => ['enabled' => 10, 'suspended' => 0, 'warning' => 0]],
        ['skuId' => 'sku-0002', 'skuPartNumber' => 'SOME_UNKNOWN_SKU', 'capabilityStatus' => 'Enabled', 'consumedUnits' => 1, 'prepaidUnits' => ['enabled' => 1, 'suspended' => 0, 'warning' => 0]],
    ]]);
}
if ($method === 'GET' && $path === '/v1.0/users' && str_contains((string) ($query['$select'] ?? ''), 'assignedLicenses') && !isset($query['$filter'])) {
    if (!empty($s['forbidden_users'])) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
    $total = (int) ($s['users'] ?? 250); $skip = (int) ($query['$skip'] ?? 0); $top = min($size, max(1, (int) ($query['$top'] ?? $size)));
    $out = ['value' => []];
    for ($i = $skip; $i < min($total, $skip + $top); $i++) { $u = $mkUser($i); $out['value'][] = ['id' => $u['id'], 'assignedLicenses' => $u['assignedLicenses']]; }
    if ($skip + $top < $total) { $out['@odata.nextLink'] = $host . $path . '?$select=id,assignedLicenses&$skip=' . ($skip + $top) . '&$top=' . $top; }
    return reply(200, $out);
}
if ($method === 'GET' && preg_match('#^/v1\.0/deviceManagement/managedDevices/([^/]+)/(deviceCompliancePolicyStates|detectedApps)$#', $path, $dm)) {
    if (!empty($s['forbidden_details'])) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
    $did = urldecode($dm[1]);
    if (in_array($did, $s['device_gone'] ?? [], true)) { return reply(404, ['error' => ['code' => 'ResourceNotFound', 'message' => 'Resource not found for the segment.']]); }
    $n = (int) preg_replace('/\D/', '', $did);
    if ($dm[2] === 'deviceCompliancePolicyStates') {
        return reply(200, ['value' => [
            ['id' => "p1-$n", 'displayName' => 'Windows baseline', 'state' => 'compliant', 'settingCount' => 12, 'userPrincipalName' => "user$n@example.test"],
            ['id' => "p2-$n", 'displayName' => 'BitLocker required', 'state' => $n % 5 === 0 ? 'nonCompliant' : 'compliant', 'settingCount' => 3, 'userPrincipalName' => "user$n@example.test"],
        ]]);
    }
    return reply(200, ['value' => [
        ['id' => "a1-$n", 'displayName' => 'Google Chrome', 'version' => '130.0.1', 'publisher' => 'Google LLC'],
        ['id' => "a2-$n", 'displayName' => '7-Zip', 'version' => '24.08', 'publisher' => 'Igor Pavlov'],
        ['id' => "a3-$n", 'displayName' => 'App ' . $n, 'version' => null, 'publisher' => null],
    ]]);
}
if ($method === 'GET' && $path === '/v1.0/applications') {
    if (!empty($s['forbidden_apps'])) { return reply(403, ['error' => ['code' => 'Authorization_RequestDenied', 'message' => 'Insufficient privileges to complete the operation.']]); }
    if (!empty($s['no_app'])) { return reply(200, ['value' => []]); }
    return reply(200, ['value' => [['id' => 'app-obj-1', 'displayName' => 'RivetIT sync', 'passwordCredentials' => $s['app_secrets'] ?? [['displayName' => 'cron', 'endDateTime' => '2027-01-15T00:00:00Z', 'hint' => 'sec']]]]]);
}

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
