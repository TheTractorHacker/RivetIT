<?php
/* Remote MCP built-in authorization server: token revocation (RFC 7009). */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../functions.php';

use ITFlow\Mcp\OAuth\OAuthConfig;
use ITFlow\Mcp\OAuth\OAuthHttp;
use ITFlow\Mcp\OAuth\OAuthService;

OAuthHttp::commonHeaders();
$oauth = OAuthConfig::load($mysqli);
if (!$oauth['active']) {
    OAuthHttp::notFound();
}
OAuthHttp::method(['POST']);

try {
    if (!OAuthHttp::allow('oauth:revoke:ip:' . OAuthHttp::clientIp(), 120, 60)) {
        OAuthHttp::tooMany();
    }
    if (OAuthHttp::mediaType() !== 'application/x-www-form-urlencoded') {
        OAuthHttp::json(400, ['error' => 'invalid_request', 'error_description' => 'Send the request as application/x-www-form-urlencoded.']);
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        OAuthHttp::json(401, ['error' => 'invalid_client', 'error_description' => 'This server only has public clients: send client_id in the body and no credentials.']);
    }
    $raw = OAuthHttp::body(16384);
    if ($raw === null) {
        OAuthHttp::json(413, ['error' => 'invalid_request', 'error_description' => 'The request is too large.']);
    }
    $params = OAuthService::parseParams($raw);
    if ($params['duplicates'] !== []) {
        OAuthHttp::json(400, ['error' => 'invalid_request', 'error_description' => 'A parameter was sent more than once.']);
    }
    $service = new OAuthService($mysqli, OAuthConfig::issuer($config_base_url), OAuthConfig::resource($config_base_url));
    [$status, $body] = $service->revoke($params['values']);
    OAuthHttp::json($status, $body);
} catch (\Throwable $e) {
    OAuthHttp::serverError($e);
}
