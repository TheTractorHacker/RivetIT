<?php
/*
 * POST /oidc/token (included by oidc/index.php only): authorization_code grant for confidential clients, PKCE S256.
 *
 * Client authentication is the integration client's own credential (client_secret_basic or client_secret_post), checked by
 * IntegrationAuth::authenticate() - the same hashing, expiry, revocation and IP allow-list rules as POST /api/v1/oauth/token, and the same
 * timing for unknown ids and wrong secrets. There are no public clients, no refresh tokens, and no other grant: password, client_credentials,
 * implicit and refresh_token are all "unsupported_grant_type". Every response is uncacheable (OAuthHttp::json). Errors follow RFC 6749 5.2
 * with fixed descriptions; a code, token, secret or request value is never echoed or logged.
 */
defined('OIDC_FRONT') || exit;

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Mcp\OAuth\OAuthHttp;
use ITFlow\Mcp\OAuth\OAuthService;
use ITFlow\Oidc\OidcAudit;
use ITFlow\Oidc\OidcConfig;

OAuthHttp::commonHeaders();

$tokErr = static function (int $status, string $error, string $description, array $headers = []): never {
    OAuthHttp::json($status, ['error' => $error, 'error_description' => $description], $headers);
};

// Client id first (cheap lookup) so the per-client throttle applies before any password hashing.
$basic = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$rawBody = OAuthHttp::body(8192);
if ($rawBody === null) {
    $tokErr(413, 'invalid_request', 'The request is too large.');
}
if (OAuthHttp::mediaType() !== 'application/x-www-form-urlencoded') {
    $tokErr(400, 'invalid_request', 'Send the request as application/x-www-form-urlencoded.');
}
$parsed = OAuthService::parseParams($rawBody);
$f = $parsed['values'];

$clientUid = '';
$secret = '';
$usedBasic = false;
if ($basic !== '' && preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $basic, $bm) === 1) {
    $usedBasic = true;
    $decoded = base64_decode($bm[1], true);
    if ($decoded !== false && str_contains($decoded, ':')) {
        [$clientUid, $secret] = explode(':', $decoded, 2);
        $clientUid = urldecode($clientUid);
        $secret = urldecode($secret);
    }
}
$bodyUid = (string) ($f['client_id'] ?? '');
$bodySecret = (string) ($f['client_secret'] ?? '');
if ($usedBasic && $bodySecret !== '') {
    $tokErr(400, 'invalid_request', 'Use one client authentication method.');   // RFC 6749 2.3: not more than one
}
if (!$usedBasic) {
    $clientUid = $bodyUid;
    $secret = $bodySecret;
} elseif ($bodyUid !== '' && !hash_equals($clientUid, $bodyUid)) {
    $tokErr(400, 'invalid_request', 'The client_id does not match the credentials.');
}
if (strlen($clientUid) > 64 || strlen($secret) > 256) {
    $clientUid = $secret = '';   // never feed oversized input to the hash functions
}

$known = null;
if ($clientUid !== '' && preg_match('/^ic_[0-9a-f]{20}$/D', $clientUid) === 1) {
    $st = $mysqli->prepare('SELECT client_id FROM integration_clients WHERE client_uid = ?');
    $st->bind_param('s', $clientUid);
    $st->execute();
    $known = $st->get_result()->fetch_assoc();
    $st->close();
}
$knownId = $known ? (int) $known['client_id'] : 0;

if (!OidcAudit::allow($mysqli, 'token', 300, $knownId > 0 ? $knownId : null)) {
    $tokErr(429, 'temporarily_unavailable', 'Too many token requests. Try again later.', ['Retry-After' => '60']);
}
if (!$cfg['enabled']) {
    OidcAudit::record($mysqli, 'token', 'refused', 'provider_disabled', $knownId);
    $tokErr(503, 'temporarily_unavailable', 'Single sign-on is not available right now.');
}
if ($parsed['duplicates'] !== []) {
    $tokErr(400, 'invalid_request', 'A parameter was sent more than once.');
}

$invalidClient = static function (string $reason) use ($mysqli, $knownId, $usedBasic, $tokErr): never {
    OidcAudit::record($mysqli, 'token', 'refused', $reason, $knownId);
    $tokErr(401, 'invalid_client', 'Client authentication failed.', $usedBasic ? ['WWW-Authenticate' => 'Basic realm="oidc", charset="UTF-8"'] : []);
};
$auth = $clientUid !== '' && $secret !== '' ? IntegrationAuth::authenticate($mysqli, $clientUid, $secret, OidcAudit::ip()) : null;
if ($auth === null) {
    $invalidClient('client_auth_failed');
}
$client = OidcConfig::ssoClient($mysqli, $clientUid);
if ($client === null) {
    $invalidClient('client_not_sso');   // a valid integration credential without the 'sso' capability and a registration is not a sign-in client
}
$clientId = (int) $client['client_id'];

if (($f['grant_type'] ?? '') !== 'authorization_code') {
    OidcAudit::record($mysqli, 'token', 'refused', 'grant_type', $clientId);
    $tokErr(400, 'unsupported_grant_type', 'Only the authorization_code grant is supported.');
}
$result = $provider->exchangeCode($client, (string) ($f['code'] ?? ''), (string) ($f['redirect_uri'] ?? ''), (string) ($f['code_verifier'] ?? ''));
if (!$result['ok']) {
    $isReplay = str_starts_with($result['reason'], 'code_replay');
    OidcAudit::record($mysqli, $isReplay ? 'code_replay' : 'token', 'refused', $result['reason'], $clientId, $result['contact_id'],
        $isReplay ? 'A used authorization code was presented again; the tokens issued from it were revoked.' : '');
    $tokErr(400, $result['error'], $result['error'] === 'invalid_request' ? 'The request is missing or has an invalid parameter.' : 'The authorization code is invalid, expired or already used.');
}
OidcAudit::record($mysqli, 'token', 'ok', 'tokens_issued', $clientId, $result['contact_id']);
OAuthHttp::json(200, $result['response']);
