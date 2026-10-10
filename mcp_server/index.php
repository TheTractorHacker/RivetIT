<?php
/* Remote MCP resource server. Disabled until a dedicated OAuth issuer is configured. */

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/../config.php';

// Fail closed: the module switch (Administration > Remote MCP) must be on and RIVETIT_MCP_ENABLED must not be 0.
require_once __DIR__ . '/../vendor/autoload.php';
$mcp_config = ITFlow\Mcp\McpConfig::load($mysqli);
if (!$mcp_config['enabled']) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/app_version.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/redis_functions.php';
require_once __DIR__ . '/McpIdentityMiddleware.php';
require_once __DIR__ . '/ReadTools.php';
require_once __DIR__ . '/RedisMetadataCache.php';
// Built-in mode (RivetIT is its own OAuth authorization server, see docs/REMOTE_MCP.md) or external-provider mode.
// The two are exclusive: with the built-in server on, only tokens it issued are accepted.
$oauth_state = ITFlow\Mcp\OAuth\OAuthConfig::load($mysqli);
$builtin = $oauth_state['active'];
$issuer = $builtin ? ITFlow\Mcp\OAuth\OAuthConfig::issuer($config_base_url) : $mcp_config['issuer'];
$audience = $builtin ? ITFlow\Mcp\OAuth\OAuthConfig::resource($config_base_url) : $mcp_config['audience'];
$host = parse_url('https://' . $config_base_url, PHP_URL_HOST);
if (!is_string($host) || !preg_match('/^[A-Za-z0-9.-]+$/D', $host)
    || (!$builtin && !$mcp_config['configured'])) {
    http_response_code(503);
    exit;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($builtin && $path === '/.well-known/oauth-authorization-server') {
    // RFC 8414 metadata; built from the configured base URL, never from the Host header.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit;
    }
    $as = new ITFlow\Mcp\OAuth\OAuthService($mysqli, $issuer, $audience);
    header('Content-Type: application/json');
    echo json_encode($as->metadata($oauth_state['registration']), JSON_UNESCAPED_SLASHES);
    exit;
}
if (!in_array($path, ['/mcp', '/.well-known/oauth-protected-resource'], true)) {
    http_response_code(404);
    exit;
}
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($headers['Authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
}
$input = fopen('php://input', 'rb');
$body = is_resource($input) ? stream_get_contents($input, 65537) : false;
if (is_resource($input)) fclose($input);
if (!is_string($body) || strlen($body) > 65536) {
    http_response_code(413);
    exit;
}
$request = new Nyholm\Psr7\ServerRequest(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'https://' . $config_base_url . $path,
    $headers,
    $body
);
$metadata = new Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata(
    authorizationServers: [$issuer],
    scopesSupported: ['mcp:read'],
    resource: 'https://' . $config_base_url . '/mcp',
    resourceName: 'RivetIT',
);
if ($builtin) {
    $validator = new ITFlow\Mcp\OAuth\BuiltinTokenValidator($mysqli, $issuer, $audience);
} else {
    $cache = new RedisMetadataCache();
    $discovery = new Mcp\Server\Transport\Http\OAuth\OidcDiscovery(
        httpClient: new GuzzleHttp\Client(['timeout' => 5, 'allow_redirects' => false]),
        cache: $cache,
    );
    $jwks = new Mcp\Server\Transport\Http\OAuth\JwksProvider(
        $discovery, new GuzzleHttp\Client(['timeout' => 5, 'allow_redirects' => false]),
        cache: $cache,
    );
    $validator = new Mcp\Server\Transport\Http\OAuth\JwtTokenValidator(
        issuer: $issuer, audience: $audience, jwksProvider: $jwks, algorithms: ['RS256'],
    );
}
$middleware = [
    new Mcp\Server\Transport\Http\Middleware\CorsMiddleware(),
    new Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware([$host]),
    new Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware($metadata),
    new Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware($validator, $metadata),
    new McpIdentityMiddleware($mysqli, $issuer, $audience, $builtin),
    new Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware(),
];
$transport = new Mcp\Server\Transport\StreamableHttpTransport($request, middleware: $middleware,
    maxBodyBytes: 65536);
// Server-generated correlation id for every MCP request; used by the tool envelope and the audit row.
// Never taken from a client header.
$request_id = 'req_' . bin2hex(random_bytes(8));
$_SERVER['RIVET_REQUEST_ID'] = $request_id; // read by ServerRequestContext; a client X-Request-ID header is never used
header('X-Request-ID: ' . $request_id);
$tools = new RivetITMcpReadTools($mysqli);
$server = $tools->register(Mcp\Server::builder()->setServerInfo('RivetIT', APP_VERSION))->build();
$response = $server->run($transport);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) header($name . ': ' . $value, false);
}
echo (string) $response->getBody();
