<?php
/*
 * GET|POST /oidc/userinfo (included by oidc/index.php only).
 *
 * Accepts only an SSO access token (opaque, "rvo_" + 43 characters) in the Authorization header. A token in the query string or the body is
 * never read, and an LMS API token or a legacy API key has the wrong shape and is refused the same way as an unknown token. The person is
 * re-checked against QualityAccessPolicy on every call, so disabling the account or the provider takes effect immediately.
 */
defined('OIDC_FRONT') || exit;

use ITFlow\Mcp\OAuth\OAuthHttp;
use ITFlow\Oidc\OidcAudit;

OAuthHttp::commonHeaders();

if (!OidcAudit::allow($mysqli, 'userinfo', 60)) {
    OAuthHttp::json(429, ['error' => 'temporarily_unavailable'], ['Retry-After' => '60']);
}
$authz = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$bearer = preg_match('/^Bearer ([A-Za-z0-9._~+\/=-]{1,200})$/', $authz, $m) === 1 ? $m[1] : '';
$invalid = static function (string $header): never {
    OAuthHttp::json(401, ['error' => 'invalid_token'], ['WWW-Authenticate' => $header]);
};
if ($bearer === '') {
    $invalid('Bearer realm="oidc"');
}
if (!$cfg['enabled']) {
    $invalid('Bearer realm="oidc", error="invalid_token"');
}
$res = $provider->userinfo($bearer);
if (!$res['ok']) {
    // Reason codes go to the audit log only; the caller always gets the same answer. Probing with unknown tokens is not logged one by one
    // (the rate limit above bounds it); a token that WAS valid and is no longer is worth a line.
    if (!in_array($res['reason'], ['token_malformed', 'token_unknown'], true)) {
        OidcAudit::record($mysqli, 'userinfo', 'refused', $res['reason']);
    }
    $invalid('Bearer realm="oidc", error="invalid_token"');
}
OAuthHttp::json(200, $res['claims']);
