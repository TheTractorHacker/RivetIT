<?php
/* Remote MCP built-in authorization server: token endpoint (RFC 6749 section 3.2). authorization_code and refresh_token grants. */

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
    if (!OAuthHttp::allow('oauth:token:ip:' . OAuthHttp::clientIp(), 120, 60)) {
        OAuthHttp::tooMany();
    }
    if (OAuthHttp::mediaType() !== 'application/x-www-form-urlencoded') {
        OAuthHttp::json(400, ['error' => 'invalid_request', 'error_description' => 'Send the request as application/x-www-form-urlencoded.']);
    }
    // Public clients authenticate by client_id only; a credential in the Authorization header is refused, not ignored.
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
    $clientId = $params['values']['client_id'] ?? '';
    if ($clientId !== '' && !OAuthHttp::allow('oauth:token:client:' . hash('sha256', $clientId), 60, 60)) {
        OAuthHttp::tooMany();
    }
    $service = new OAuthService($mysqli, OAuthConfig::issuer($config_base_url), OAuthConfig::resource($config_base_url));
    [$status, $body] = $service->token($params['values']);
    OAuthHttp::json($status, $body, $status === 401 ? ['WWW-Authenticate' => 'Basic realm="RivetIT MCP"'] : []);
} catch (\Throwable $e) {
    OAuthHttp::serverError($e);
}
