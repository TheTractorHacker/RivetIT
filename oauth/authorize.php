<?php
/*
 * Remote MCP built-in authorization server: authorization endpoint and consent screen (RFC 6749 4.1, OAuth 2.1, PKCE).
 *
 * The request is validated against the client's registration first. Only then is the person sent through the normal
 * RivetIT agent sign-in (password, 2FA, passkey, MFA policy) via includes/check_login.php, which returns here. Approval is a
 * CSRF-protected POST; the browser only ever goes back to a redirect URI that matched the registration exactly.
 * The page cannot be framed (X-Frame-Options and CSP frame-ancestors).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../functions.php';

use ITFlow\Mcp\OAuth\OAuthConfig;
use ITFlow\Mcp\OAuth\OAuthHttp;
use ITFlow\Mcp\OAuth\OAuthService;

header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'");

$oauth = OAuthConfig::load($mysqli);
if (!$oauth['active']) {
    http_response_code(404);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$app_name = defined('APP_NAME') ? APP_NAME : 'RivetIT';

/** A page the browser stays on (never a redirect): used while the client or its return address cannot be trusted. */
$render_error = static function (string $message, int $status = 400) use ($h, $app_name): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $h($app_name) . ' - cannot connect</title>' . ($GLOBALS['oauth_css'] ?? '') . '</head><body><main class="card">'
        . '<h1>This connection request cannot be completed</h1><p>' . $h($message) . '</p>'
        . '<p class="muted">Nothing was shared. Go back to the application and start the connection again, or ask your RivetIT administrator.</p></main></body></html>';
    exit;
};

$oauth_css = <<<'CSS'
<style>
:root{--bg:#f3f5f8;--fg:#1c2330;--muted:#5d6879;--card:#fff;--line:#d9dee7;--accent:#0d9488;--accent-fg:#fff;--warn:#8a5a00;--warn-bg:#fff6e0}
@media (prefers-color-scheme:dark){:root{--bg:#10141b;--fg:#e6eaf1;--muted:#9aa5b6;--card:#181e28;--line:#2a3342;--accent:#2dd4bf;--accent-fg:#06201d;--warn:#f1c76b;--warn-bg:#2a2210}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:16px}
.card{max-width:34rem;margin:6vh auto;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:24px}
h1{font-size:1.3rem;margin:0 0 .75rem}h2{font-size:1rem;margin:1.25rem 0 .4rem}p{margin:.4rem 0}ul{margin:.3rem 0;padding-left:1.2rem}li{margin:.2rem 0}
.muted{color:var(--muted);font-size:.9rem}.who{font-weight:600}.warn{background:var(--warn-bg);color:var(--warn);border-radius:8px;padding:10px 12px;margin:.9rem 0;font-size:.92rem}
code{font-family:ui-monospace,Consolas,monospace;font-size:.88rem;word-break:break-all}.row{display:flex;gap:10px;margin-top:1.4rem;flex-wrap:wrap}
button{font:inherit;cursor:pointer;border-radius:8px;padding:10px 18px;border:1px solid var(--line);background:transparent;color:var(--fg)}
button.go{background:var(--accent);color:var(--accent-fg);border-color:var(--accent);font-weight:600}.no{opacity:.7}
</style>
CSS;

if (!OAuthHttp::allow('oauth:authorize:ip:' . OAuthHttp::clientIp(), 120, 60)) {
    $render_error('Too many requests. Wait a minute and try again.', 429);
}

// Parameters: the query string on GET; the form fields on the consent POST (re-validated exactly like the GET).
$raw = $method === 'POST' ? OAuthHttp::body(16384) : (string) ($_SERVER['QUERY_STRING'] ?? '');
if ($raw === null || strlen($raw) > 16384) {
    $render_error('The request is too large.', 413);
}
$params = OAuthService::parseParams($raw);
$service = new OAuthService($mysqli, OAuthConfig::issuer($config_base_url), OAuthConfig::resource($config_base_url));
$request = $service->validateAuthorizationRequest($params);

if (!$request['ok']) {
    if (isset($request['fatal'])) {
        $render_error($request['fatal']);
    }
    // The client and redirect URI are verified: report the error to the client (RFC 6749 4.1.2.1), with state and iss.
    header('Location: ' . $service->redirectUrl($request['redirect_uri'], ['error' => $request['error'], 'error_description' => $request['description']], $request['state']));
    http_response_code(302);
    exit;
}

// The normal agent sign-in. Not signed in: this sends the browser to /login.php and back here (2FA included).
require_once __DIR__ . '/../includes/check_login.php';

if (($session_user_type ?? 0) !== 1) {
    $render_error('Only RivetIT agents can connect an AI tool. Sign in with an agent account.', 403);
}
$user_id = (int) $session_user_id;

if ($method === 'POST') {
    $csrf = (string) ($params['values']['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        $render_error('This page expired. Go back to the application and start the connection again.', 403);
    }
    $decision = $params['values']['decision'] ?? '';
    if ($decision === 'approve') {
        $url = $service->approve($request, $user_id);
    } elseif ($decision === 'deny') {
        $url = $service->deny($request, $user_id);
    } else {
        $render_error('Choose Allow or Deny.');
    }
    session_write_close();
    http_response_code(303);
    header('Location: ' . $url);
    exit;
}

/* ---- consent screen (GET) ---- */
$profile = itflow_user_access_profile($user_id);
$access = [
    ['Tickets: recent and searchable, with their replies', itflow_profile_level($profile, 'module_support') >= 1],
    ['Assets: name, type, make, model and serial number (never PINs or credentials)', function_exists('itflow_profile_can_assets') && itflow_profile_can_assets($profile)],
    ['Departments and contacts (never contact PINs)', itflow_profile_level($profile, 'module_client') >= 1],
    ['Knowledge base articles', itflow_profile_level($profile, 'module_kb') >= 1],
];
$client = $request['client'];
$host = OAuthService::redirectHost($request['redirect_uri']);
$loopback = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
$csrf_token = (string) ($_SESSION['csrf_token'] ?? '');
$user_label = trim((string) ($session_name ?? '')) !== '' ? (string) $session_name : (string) ($session_email ?? 'you');
session_write_close();

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($app_name) ?> - allow access?</title>
<?= $oauth_css ?>
</head>
<body>
<main class="card">
    <h1>Allow &ldquo;<?= $h($client['client_name']) ?>&rdquo; to read <?= $h($app_name) ?>?</h1>
    <p>Signed in as <span class="who"><?= $h($user_label) ?></span>.</p>
    <p class="muted">The application calls itself &ldquo;<?= $h($client['client_name']) ?>&rdquo;; <?= $h($app_name) ?> cannot verify that name.
        After you decide, your browser goes back to <strong><?= $h($host) ?></strong>.</p>
    <?php if ($loopback) { ?>
        <div class="warn">That address is on <strong>this computer</strong> (a desktop or command-line tool). Only continue if you just started that tool yourself.</div>
    <?php } ?>

    <h2>It will be able to read, as you</h2>
    <ul>
        <?php foreach ($access as [$label, $allowed]) { ?>
            <li><?= $h($label) ?><?= $allowed ? '' : ' <span class="muted">(your role has no access, so it will get nothing here)</span>' ?></li>
        <?php } ?>
    </ul>
    <h2>It will not be able to</h2>
    <ul>
        <li>Create, change or delete anything: access is read-only (<code><?= $h($request['scope']) ?></code>)</li>
        <li>See more than your own account can see: it uses your role and department limits on every request</li>
        <li>Read passwords, vault entries, PINs or API keys</li>
    </ul>
    <p class="muted">Access lasts until you remove it under Account &rarr; Security &rarr; Connected AI tools (or an administrator does), and
        stops by itself after <?= (int) OAuthConfig::GRANT_DAYS ?> days. If your account is disabled it stops at once.</p>

    <form method="post" action="authorize.php">
        <?php foreach (['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'resource'] as $field) {
            if (isset($params['values'][$field])) { ?>
        <input type="hidden" name="<?= $h($field) ?>" value="<?= $h($params['values'][$field]) ?>">
        <?php } } ?>
        <input type="hidden" name="csrf_token" value="<?= $h($csrf_token) ?>">
        <div class="row">
            <button type="submit" name="decision" value="approve" class="go">Allow read-only access</button>
            <button type="submit" name="decision" value="deny" class="no">Deny</button>
        </div>
    </form>
</main>
</body>
</html>
