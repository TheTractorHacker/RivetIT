<?php
// POST /api/v1/oauth/token  (grant_type=client_credentials, application/x-www-form-urlencoded)
//
// Public route (no Bearer yet), like `auth`: routed from index.php above the Bearer parsing. Returns a signed 10 minute access token for an
// integration client (Administration > Integration clients). Every authentication failure is the same 401 invalid_client; each is audited
// (without the secret) and counted against a per-IP and per-client throttle read from the audit log, so the throttle cannot be lifted by
// taking Redis away. Credentials are accepted in the form body or an HTTP Basic header, never in the query string.
defined('FROM_API') || die();

use ITFlow\Integration\ApiResponse;
use ITFlow\Integration\IntegrationAuth;

if ($sub !== 'token' || count($segments) !== 2) {
    ApiResponse::error(404, 'not_found', 'Not found.');
}
if ($method !== 'POST') {
    ApiResponse::error(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
}

$oauth_ip = (string) getIP();
$oauth_ctype = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($oauth_ctype !== 'application/x-www-form-urlencoded') {
    ApiResponse::error(400, 'invalid_request', 'Send application/x-www-form-urlencoded with grant_type, client_id and client_secret.');
}

$oauth_grant = (string) ($_POST['grant_type'] ?? '');
$oauth_client = isset($_POST['client_id']) ? (string) $_POST['client_id'] : '';
$oauth_secret = isset($_POST['client_secret']) ? (string) $_POST['client_secret'] : '';
$oauth_basic = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($oauth_client === '' && preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $oauth_basic, $bm) === 1) {
    $decoded = base64_decode($bm[1], true);
    if ($decoded !== false && str_contains($decoded, ':')) {
        [$oauth_client, $oauth_secret] = explode(':', $decoded, 2);
        $oauth_client = rawurldecode($oauth_client);
        $oauth_secret = rawurldecode($oauth_secret);
    }
}
if (strlen($oauth_client) > 64 || strlen($oauth_secret) > 256) {
    $oauth_client = $oauth_secret = '';   // never feed oversized input to the hash functions
}

// Throttle first: a flood of bad guesses is refused before any password hashing.
$oauth_known = $oauth_client !== '' ? mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT client_id FROM integration_clients WHERE client_uid = '" . mysqli_real_escape_string($mysqli, $oauth_client) . "'")) : null;
$oauth_cid = $oauth_known ? (int) $oauth_known['client_id'] : 0;
if (IntegrationAuth::recentFailures($mysqli, $oauth_ip, null) >= 20
    || ($oauth_cid > 0 && IntegrationAuth::recentFailures($mysqli, $oauth_ip, $oauth_cid) >= 8)
    || !api_rate_limit('oauth_ip:' . $oauth_ip, 30, 60)
    || !api_rate_limit('oauth_client:' . substr(hash('sha256', $oauth_client . '|' . $oauth_ip), 0, 32), 10, 60)) {
    ApiResponse::error(429, 'rate_limited', 'Too many token requests. Try again later.', ['Retry-After' => '60']);
}

if ($oauth_grant !== 'client_credentials') {
    ApiResponse::error(400, 'unsupported_grant_type', 'grant_type must be client_credentials.');
}

$oauth_auth = $oauth_client !== '' && $oauth_secret !== '' ? IntegrationAuth::authenticate($mysqli, $oauth_client, $oauth_secret, $oauth_ip) : null;
if ($oauth_auth === null) {
    IntegrationAuth::audit($mysqli, 'Integration Token', 'Failed', 'Token request refused (invalid client credentials, revoked or expired client or secret, or IP not allowed)', $oauth_cid, $oauth_ip);
    ApiResponse::error(401, 'invalid_client', 'Client authentication failed.');
}
[$oauth_row, $oauth_secret_id] = $oauth_auth;

$oauth_granted = IntegrationAuth::parseScopes((string) $oauth_row['client_scopes']);
$oauth_asked = isset($_POST['scope']) && trim((string) $_POST['scope']) !== ''
    ? preg_split('/\s+/', trim((string) $_POST['scope']), -1, PREG_SPLIT_NO_EMPTY) : $oauth_granted;
foreach ($oauth_asked as $s) {
    if (!in_array($s, $oauth_granted, true)) {
        IntegrationAuth::audit($mysqli, 'Integration Token', 'Failed', 'Token request asked for a scope the client does not hold', (int) $oauth_row['client_id'], $oauth_ip);
        ApiResponse::error(400, 'invalid_scope', 'The client is not allowed one of the requested scopes.');
    }
}
if ($oauth_asked === []) {
    ApiResponse::error(400, 'invalid_scope', 'The client has no scopes.');
}

$oauth_token = IntegrationAuth::issueToken($mysqli, $oauth_row, $oauth_secret_id, array_values(array_unique($oauth_asked)), $oauth_ip);
// Issued tokens are not audited one by one (a token every 10 minutes per client would bury the log); client_last_used_at and the
// integration_tokens rows record the use, and every refusal above is audited.
header('Pragma: no-cache');
ApiResponse::json(200, $oauth_token);
