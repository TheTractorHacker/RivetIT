<?php
/* Remote MCP resource server. Disabled until a dedicated OAuth issuer is configured. */

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/app_version.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/redis_functions.php';
require_once __DIR__ . '/McpIdentityMiddleware.php';
require_once __DIR__ . '/ReadTools.php';
require_once __DIR__ . '/RedisMetadataCache.php';

if (getenv('RIVETIT_MCP_ENABLED') !== '1') {
    http_response_code(404);
    exit;
}
$issuer = trim((string) getenv('RIVETIT_MCP_ISSUER'));
$audience = trim((string) getenv('RIVETIT_MCP_AUDIENCE'));
$host = parse_url('https://' . $config_base_url, PHP_URL_HOST);
if (!is_string($host) || !preg_match('/^[A-Za-z0-9.-]+$/D', $host)
    || !filter_var($issuer, FILTER_VALIDATE_URL) || parse_url($issuer, PHP_URL_SCHEME) !== 'https'
    || $audience === '' || strlen($audience) > 255) {
    http_response_code(503);
    exit;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
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
$middleware = [
    new Mcp\Server\Transport\Http\Middleware\CorsMiddleware(),
    new Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware([$host]),
    new Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware($metadata),
    new Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware($validator, $metadata),
    new McpIdentityMiddleware($mysqli, $issuer),
    new Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware(),
];
$transport = new Mcp\Server\Transport\StreamableHttpTransport($request, middleware: $middleware,
    maxBodyBytes: 65536);
$tools = new RivetITMcpReadTools($mysqli);
$server = Mcp\Server::builder()
    ->setServerInfo('RivetIT', APP_VERSION)
    ->addTool([$tools, 'myProfile'])
    ->addTool([$tools, 'recentTickets'])
    ->build();
$response = $server->run($transport);
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) header($name . ': ' . $value, false);
}
echo (string) $response->getBody();
