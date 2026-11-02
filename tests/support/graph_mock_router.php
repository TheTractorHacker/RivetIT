<?php
/*
 * A tiny Microsoft Graph + token endpoint mock for the lifecycle tests (php -S 127.0.0.1:PORT tests/support/graph_mock_router.php).
 * State: the JSON file named by GRAPH_MOCK_STORE (users) and the request log GRAPH_MOCK_LOG (one JSON object per line).
 * Never talks to the network; never sees a real credential.
 */
$store = getenv('GRAPH_MOCK_STORE');
$log = getenv('GRAPH_MOCK_LOG');
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '';
$body = file_get_contents('php://input');
header('Content-Type: application/json');
$b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

if (preg_match('#/oauth2/v2\.0/token$#', $uri)) {
    $jwt = $b64('{"alg":"none"}') . '.' . $b64(json_encode(['roles' => ['User.Read.All', 'User.ReadWrite.All', 'Group.ReadWrite.All', 'MailboxSettings.ReadWrite']])) . '.sig';
    echo json_encode(['access_token' => $jwt, 'expires_in' => 3600]);
    return;
}
file_put_contents($log, json_encode(['method' => $method, 'path' => $uri, 'query' => $query, 'body' => $body]) . "\n", FILE_APPEND);
$users = json_decode((string) @file_get_contents($store), true) ?: [];
$find = static function (string $key) use ($users) {
    foreach ($users as $u) {
        if (strcasecmp($u['id'], $key) === 0 || strcasecmp($u['userPrincipalName'], $key) === 0) {
            return $u;
        }
    }
    return null;
};
if ($method === 'GET' && preg_match('#/v1\.0/users/([^/]+)$#', $uri, $m)) {
    $u = $find(rawurldecode($m[1]));
    if (!$u) { http_response_code(404); echo '{"error":{"code":"Request_ResourceNotFound","message":"nf"}}'; return; }
    echo json_encode($u);
    return;
}
if ($method === 'GET' && preg_match('#/v1\.0/users$#', $uri)) {
    $hits = [];
    parse_str($query, $q);
    foreach ($users as $u) {
        if (isset($q['$filter']) && stripos($q['$filter'], "'" . ($u['mail'] ?? '~') . "'") !== false) { $hits[] = $u; }
    }
    echo json_encode(['value' => $hits]);
    return;
}
if ($method === 'POST' && preg_match('#/v1\.0/users/([^/]+)/assignLicense$#', $uri)) {
    $d = json_decode($body, true);
    if (!is_array($d) || !isset($d['addLicenses'], $d['removeLicenses'])) { http_response_code(400); echo '{"error":{"code":"BadRequest","message":"shape"}}'; return; }
    echo '{}';
    return;
}
if ($method === 'PATCH' && preg_match('#/v1\.0/users/([^/]+)/mailboxSettings$#', $uri)) {
    echo '{}';
    return;
}
http_response_code(404);
echo '{"error":{"code":"NotMocked","message":"' . $method . ' ' . $uri . '"}}';
