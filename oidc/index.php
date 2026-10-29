<?php
/*
 * RivetIT as an OpenID Connect identity provider (issue #55): one front controller for
 *
 *   GET  /.well-known/openid-configuration   discovery
 *   GET  /oidc/jwks                          signing keys (public)
 *   GET  /oidc/authorize                     authorization endpoint (uses the person's department-portal session)
 *   POST /oidc/authorize                     only the "sign in again" button
 *   POST /oidc/token                         code exchange (confidential client, PKCE)
 *   GET|POST /oidc/userinfo                  claims for an access token
 *
 * nginx maps these paths to this file (deploy/templates/rivetit-locations.conf); the path is read from REQUEST_URI here. The provider is off
 * until an administrator switches it on (Administration > Single sign-on provider): every endpoint then answers as if it did not exist
 * (discovery, JWKS) or refuses plainly (authorize, token, userinfo). See docs/integrations/qms-sso.md.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../functions.php';
if (is_file(__DIR__ . '/../includes/redis_guards.php')) {
    require_once __DIR__ . '/../includes/redis_guards.php';
}

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Mcp\OAuth\OAuthHttp;
use ITFlow\Mcp\OAuth\OAuthService;
use ITFlow\Oidc\OidcAudit;
use ITFlow\Oidc\OidcConfig;
use ITFlow\Oidc\OidcPages;
use ITFlow\Oidc\OidcProvider;
use ITFlow\Oidc\KeyStore;
use ITFlow\Oidc\QualityAccessPolicy;

const OIDC_PENDING_COOKIE = 'rivet_oidc_pending';
define('OIDC_FRONT', true);   // the included endpoint files refuse to run without it

// Same clock as the rest of the application: stored timestamps (NOW()) are company-local time, so audit rows line up with the admin pages.
try {
    $tzRow = $mysqli->query('SELECT config_timezone FROM settings WHERE company_id = 1')->fetch_assoc();
    $tz = new DateTimeZone((string) ($tzRow['config_timezone'] ?? 'UTC'));
    date_default_timezone_set($tz->getName());
    $mysqli->query("SET time_zone = '" . (new DateTime('now', $tz))->format('P') . "'");
} catch (\Throwable) {
    // keep the server default
}

$path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$cfg = OidcConfig::load($mysqli);
$provider = new OidcProvider($mysqli, $cfg);
$ip = OidcAudit::ip();

/** Plain 404 with the no-store headers (the provider is off, the path is unknown, or the schema is not there yet). */
$notFound = static function (): never {
    OAuthHttp::commonHeaders();
    http_response_code(404);
    exit;
};
$allowMethods = static function (array $allowed) use ($method): void {
    if (!in_array($method, $allowed, true)) {
        OAuthHttp::json(405, ['error' => 'invalid_request', 'error_description' => 'Method not allowed.'], ['Allow' => implode(', ', $allowed)]);
    }
};

if (!$cfg['table_ready']) {
    $notFound();
}

try {
    switch ($path) {
        // ---------------------------------------------------------------------------------------------------- discovery / JWKS
        case '/.well-known/openid-configuration':
        case '/oidc/jwks':
            $allowMethods(['GET', 'HEAD']);
            if (!$cfg['enabled']) {
                $notFound();
            }
            if (!OidcAudit::allow($mysqli, 'public', 120)) {
                OAuthHttp::json(429, ['error' => 'temporarily_unavailable']);
            }
            $doc = $path === '/oidc/jwks' ? KeyStore::jwks($mysqli) : OidcProvider::discovery();
            header('Content-Type: application/json');
            header('Cache-Control: public, max-age=300');   // public metadata and keys; a new key is picked up when a relying party sees an unknown kid
            header('X-Content-Type-Options: nosniff');
            echo json_encode($doc, JSON_UNESCAPED_SLASHES);
            exit;

        // ---------------------------------------------------------------------------------------------------------- authorize
        case '/oidc/authorize':
            $allowMethods(['GET', 'POST']);
            require __DIR__ . '/authorize.php';
            exit;

        // -------------------------------------------------------------------------------------------------------------- token
        case '/oidc/token':
            $allowMethods(['POST']);
            require __DIR__ . '/token.php';
            exit;

        // ----------------------------------------------------------------------------------------------------------- userinfo
        case '/oidc/userinfo':
            $allowMethods(['GET', 'POST']);
            require __DIR__ . '/userinfo.php';
            exit;

        default:
            $notFound();
    }
} catch (\Throwable $e) {
    error_log('OIDC provider error: ' . $e::class);   // the class only: a driver message can carry SQL, and no request value is ever logged
    if ($path === '/oidc/authorize') {
        OidcPages::refusal(500, 'Something went wrong', 'The sign-in could not be completed. Try again shortly.');
    }
    OAuthHttp::json(500, ['error' => 'server_error', 'error_description' => 'The server could not complete the request.']);
}
