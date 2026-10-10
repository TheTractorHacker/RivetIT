<?php
/* Remote MCP built-in authorization server: dynamic client registration (RFC 7591). Public clients only. */

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
if (!$oauth['registration']) {
    OAuthHttp::json(403, ['error' => 'access_denied', 'error_description' => 'Self-registration is turned off on this server. Ask an administrator to register your client and give you its client ID.']);
}

try {
    $ip = OAuthHttp::clientIp();
    if (!OAuthHttp::allow('oauth:register:ip:' . $ip, OAuthConfig::REGISTER_REQUESTS_PER_IP_HOUR, 3600)
        || !OAuthHttp::allow('oauth:register:all', 60, 3600)) {
        OAuthHttp::tooMany();
    }
    if (OAuthHttp::mediaType() !== 'application/json') {
        OAuthHttp::json(400, ['error' => 'invalid_client_metadata', 'error_description' => 'Send the registration as application/json.']);
    }
    $raw = OAuthHttp::body(8192);
    if ($raw === null) {
        OAuthHttp::json(413, ['error' => 'invalid_client_metadata', 'error_description' => 'The registration is too large.']);
    }
    $meta = json_decode($raw, true, 8);
    if (!is_array($meta) || array_is_list($meta)) {
        OAuthHttp::json(400, ['error' => 'invalid_client_metadata', 'error_description' => 'The registration must be a JSON object.']);
    }
    $service = new OAuthService($mysqli, OAuthConfig::issuer($config_base_url), OAuthConfig::resource($config_base_url));
    [$status, $body] = $service->register($meta, $ip);
    OAuthHttp::json($status, $body);
} catch (\Throwable $e) {
    OAuthHttp::serverError($e);
}
