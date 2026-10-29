<?php
define('FROM_API', true);

// Module switch: while the RMM module is off, device and technician endpoints are answered here, before config.php and any database work.
require __DIR__ . '/rmm_gate.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Biometric, X-Api-Key, Idempotency-Key, X-Correlation-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$DOCUMENT_ROOT = realpath(__DIR__ . '/../../');
$_SERVER['DOCUMENT_ROOT'] = $DOCUMENT_ROOT;

require_once $DOCUMENT_ROOT . '/config.php';
require_once $DOCUMENT_ROOT . '/includes/db.php';
require_once $DOCUMENT_ROOT . '/functions.php';
require_once $DOCUMENT_ROOT . '/includes/load_company_settings.php';
require_once $DOCUMENT_ROOT . '/includes/load_global_settings.php';
require_once $DOCUMENT_ROOT . '/includes/redis_functions.php';
require_once __DIR__ . '/includes/api_db.php';
require_once __DIR__ . '/includes/api_ratelimit.php';

function api_response(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function api_error(int $code, string $message): void {
    api_response($code, ['error' => $message]);
}

// Parse route
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base     = '/api/v1';
$path     = preg_replace('#^' . preg_quote($base, '#') . '#', '', $uri);
$segments = array_values(array_filter(explode('/', $path)));
$method   = $_SERVER['REQUEST_METHOD'];
$resource = $segments[0] ?? '';
if (isset($segments[1]) && is_numeric($segments[1])) {
    // resource/{id} or resource/{id}/{sub}, e.g. tickets/5/chat
    $id  = intval($segments[1]);
    $sub = $segments[2] ?? null;
} elseif (isset($segments[1]) && isset($segments[2]) && is_numeric($segments[2])) {
    // resource/{sub}/{id}, e.g. kb/articles/57
    $id  = intval($segments[2]);
    $sub = $segments[1];
} else {
    // resource or resource/{sub}, e.g. kb/categories
    $id  = null;
    $sub = $segments[1] ?? null;
}

// Normalize legacy-style URLs (e.g. /clients/read.php → resource=clients, sub=null)
$resource = preg_replace('/\.php$/', '', $resource);
if (is_string($sub)) {
    $sub = preg_replace('/\.php$/', '', $sub);
    if ($sub === '') {
        $sub = null;
    } elseif ($sub === 'read' && $id === null) {
        // Only the legacy resource/read.php shape (no numeric id) means "no
        // sub-resource" — resource/{id}/read is a distinct action route (e.g.
        // notifications/{id}/read) and must keep 'read' as a real sub-value,
        // or that route becomes permanently unreachable.
        $sub = null;
    }
}

// Billing resources are no longer part of the RivetIT API. Reject both the
// REST paths and removed legacy PHP paths before authentication.
if (in_array($resource, ['quotes', 'invoices', 'invoice_items', 'expenses', 'products'], true)
    || ($resource === 'reports' && in_array(str_replace('_', '-', (string) $sub),
        ['mrr', 'clients-with-balance', 'income-summary', 'income-by-client', 'recurring-by-client',
         'expense-summary', 'expense-by-vendor', 'tax-summary', 'profit-loss', 'budget', 'unbilled-tickets'], true))) {
    api_error(404, 'Not found');
}

// Public company identity: the same name/logo already displayed on the login page. Same
// contract as the RivetMSP edition so one mobile app can brand itself from either server.
if ($resource === 'branding') {
    if ($method !== 'GET') api_error(405, 'Method not allowed');
    if (!api_rate_limit('branding_ip:' . getIP(), 60, 60)) {
        header('Retry-After: 60');
        api_error(429, 'Rate limit exceeded');
    }
    $logo = !empty($session_company_logo) ? basename($session_company_logo) : null;
    header('Cache-Control: public, max-age=300');
    api_response(200, [
        'name' => $session_company_name ?: APP_NAME,
        'logo_path' => $logo ? '/uploads/settings/' . rawurlencode($logo) : null,
    ]);
}

// Public endpoint: OAuth2 client-credentials token for integration clients (POST /api/v1/oauth/token). Rate limits, throttling and
// audit are inside oauth.php; the access token it issues is accepted only on /api/v1/lms, /employees and /departments (see the integration block below).
if ($resource === 'oauth') {
    require __DIR__ . '/oauth.php';
    exit;
}

// Public endpoint: auth
if ($resource === 'auth') {
    // Tight per-IP limit on the unauthenticated login path. FAILS CLOSED if Redis is down (logout stays available)
    // so the throttle cannot be lifted by taking Redis away. auth.php also enforces a per-user failed-login lockout.
    $auth_ip = getIP();
    if (!api_rate_limit('auth_ip:' . $auth_ip, 30, 60, ($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE')) {
        header('Retry-After: ' . api_rate_limit_retry_after());
        api_error(429, 'Rate limit exceeded');
    }
    require __DIR__ . '/auth.php';
    exit;
}

// Public endpoints: API documentation (OpenAPI spec + human-readable page).
// Read-only and contain no secrets — just the shape of the API — so they are
// served without a token, the same way `auth` is. This keeps GET /api/v1/docs
// browsable in a plain browser (which sends no Bearer token) and the spec
// fetchable by tooling (Swagger/Postman/CI). Light per-IP rate limit that
// fails open when Redis is down. The docs page loads only local assets and
// fetches the same public OpenAPI spec used by integrations.
if ($resource === 'openapi' || $resource === 'docs') {
    if (!api_rate_limit('docs_ip:' . getIP(), 60, 60)) {
        header('Retry-After: ' . api_rate_limit_retry_after());
        api_error(429, 'Rate limit exceeded');
    }
    if ($method !== 'GET') api_error(405, 'Method not allowed');

    if ($resource === 'openapi') {
        $spec_path = __DIR__ . '/openapi.yaml';
        if (!is_readable($spec_path)) api_error(404, 'Spec not found');
        header('Content-Type: application/yaml; charset=utf-8');
        readfile($spec_path);
        exit;
    }

    require __DIR__ . '/docs.php';
    exit;
}

// Device-credential endpoints: the built-in endpoint agent (enroll / check-in / jobs / update download, file transfer chunks, log batches, plus the token-gated installer download). Routed here, above the Bearer parsing and
// above the pre-auth JSON body read below, because a device credential is not an api_tokens row and that body read has no size
// ceiling. Each handler authenticates its caller, bounds its own body read and rate-limits itself (agent_device_api.php).
if ($resource === 'agent_enroll' || $resource === 'agent_checkin' || $resource === 'agent_jobs' || $resource === 'agent_installer' || $resource === 'agent_update' || $resource === 'agent_transfer' || $resource === 'agent_logs' || $resource === 'agent_linux_file' || $resource === 'agent_changes' || $resource === 'agent_software') {
    require __DIR__ . '/' . $resource . '.php';
    exit;
}

// RivetPanel device-credential endpoints (the desktop ticket/chat helper's per-device bearer token - never the legacy X-Api-Key or a
// user session; see vendor/rivet/rivet-core/docs/panel/PANEL_API.md). Twin of the agent_* block above and routed here for the same
// reason: a panel device credential is not an api_tokens row, and PanelApi bounds and rate-limits its own body read.
if ($resource === 'panel_enroll' || $resource === 'panel_me' || $resource === 'panel_categories' || $resource === 'panel_tickets' || $resource === 'panel_ticket_chat') {
    require __DIR__ . '/' . $resource . '.php';
    exit;
}

// Build host endpoints (Agent updates > Local build): authenticated ONLY by a build host token, never by an API key. Routed above the Bearer parsing and the pre-auth body read.
if ($resource === 'build_host') {
    require __DIR__ . '/build_host.php';
    exit;
}

// All other endpoints require Bearer token
$api_token_row  = null;
$api_user_id    = null;

// Apache often strips Authorization header — try multiple sources
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';
if (empty($authHeader) && function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $authHeader = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
}

// ONE machine-authentication path (Administration > Integration clients). A machine caller is an integration client, whichever way it
// presents itself:
//   1. "Authorization: Bearer <access token>" - the signed 10 minute token from POST /api/v1/oauth/token (Client ID + secret). A value
//      shaped like a JWT is decided HERE, before the api_tokens lookup and the user-session setup: invalid / expired / revoked / wrong
//      audience -> 401, and it never falls through to another auth method;
//   2. "X-Api-Key: <key>" - DEPRECATED. A legacy API key that was converted to an integration client (DB 2.6.204) resolves to that client and
//      from here on is indistinguishable from case 1. It can be switched off (Administration > Integration clients).
// Both produce the same context ($integration_client, $integration_scopes, department scope) and both go through the same checks below:
// the route's scope, the department scope, the IP allow-list, the per-client rate limit and the audit trail. There is no second rule set.
// User-session Bearer tokens (api_tokens: a signed-in PERSON in the mobile app) are a different thing and are handled further down, unchanged.
$integration_client = null;
$integration_scopes = [];
$integration_via = null;       // 'bearer' | 'legacy_key'
$legacy_api_key_id = 0;
if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $im) && \ITFlow\Integration\IntegrationAuth::looksLikeToken($im[1])) {
    $int_ip = (string) getIP();
    try {
        $int_auth = \ITFlow\Integration\IntegrationAuth::validateBearer($mysqli, $im[1], $int_ip);
    } catch (\Throwable $e) {
        error_log('Integration auth: ' . get_class($e) . ': ' . $e->getMessage());
        \ITFlow\Integration\ApiResponse::error(401, 'invalid_token', 'The access token is missing, invalid or expired.');
    }
    if (!$int_auth['ok']) {
        // Bad tokens are audited, but a flood of them must not become a flood of audit rows: 60 per IP per minute, then 429.
        if (!api_rate_limit('int_bad_ip:' . $int_ip, 60, 60)) {
            header('Retry-After: ' . api_rate_limit_retry_after());
            \ITFlow\Integration\ApiResponse::error(429, 'rate_limited', 'Too many rejected requests.');
        }
        \ITFlow\Integration\IntegrationAuth::audit($mysqli, 'Integration Token', 'Rejected', 'Access token rejected: ' . $int_auth['reason'], 0, $int_ip);
        \ITFlow\Integration\ApiResponse::error(401, 'invalid_token', 'The access token is missing, invalid or expired.');
    }
    $integration_client = $int_auth['client'];
    $integration_scopes = $int_auth['scopes'];
    $integration_via = 'bearer';
    $int_directory = ($resource === 'employees' || $resource === 'departments');
    if ($int_directory || $resource === 'lms') {
        // /lms, /employees and /departments are served by lms.php / directory.php, which apply their own lms:* / directory:read scopes and
        // department scope. They use no $api_user_id, no session user and no user permissions.
        if (!api_rate_limit('int:' . (int) $integration_client['client_id'], 600, 60)) {
            header('Retry-After: ' . api_rate_limit_retry_after());
            \ITFlow\Integration\ApiResponse::error(429, 'rate_limited', 'Rate limit exceeded.');
        }
        if ($int_directory) {
            // The directory routes use $segments from the resource on ($segments[0] = employees|departments).
            require __DIR__ . '/directory.php';
            exit;
        }
        require __DIR__ . '/lms.php';
        exit;
    }
}

if ($integration_client === null && preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
    $raw_token  = $m[1];
    $token_hash = hash('sha256', $raw_token);
    $esc        = mysqli_real_escape_string($mysqli, $token_hash);
    // The user filters are as important as the token hash itself. Every other
    // auth path already refuses a disabled/archived/non-agent account
    // (includes/auth_check.php, login.php, api/v1/auth.php's login query,
    // includes/load_user_session.php which destroys the session per request) -
    // this one did not, so a disabled or archived employee kept full API access
    // forever. admin/post/users.php now also deletes the token rows on
    // disable/archive; this is the belt to that braces.
    $sql        = mysqli_query($mysqli,
        "SELECT t.*, u.user_id, u.user_name, u.user_email, u.user_type
         FROM api_tokens t
         JOIN users u ON t.token_user_id = u.user_id
         WHERE t.token_hash = '$esc'
           AND u.user_status = 1
           AND u.user_archived_at IS NULL
           AND u.user_type = 1
         LIMIT 1"
    );
    $api_token_row = mysqli_fetch_assoc($sql);
    if ($api_token_row) {
        // Expire tokens inactive for more than 90 days
        $last_used = $api_token_row['token_last_used_at'] ?? $api_token_row['token_created_at'];
        if ($last_used && (time() - strtotime($last_used)) > 90 * 86400) {
            mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_hash = '$esc'");
            api_error(401, 'Session expired. Please log in again.');
        }
        $api_user_id        = intval($api_token_row['user_id']);
        $session_user_id    = $api_user_id;
        $session_name       = $api_token_row['user_name'];
        $session_company_id = 1;
        mysqli_query($mysqli, "UPDATE api_tokens SET token_last_used_at = NOW() WHERE token_hash = '$esc'");
    }
}

if ($integration_client === null && !$api_user_id && !empty($_SERVER['HTTP_X_API_KEY'])) {
    // Deprecated compat input. Only the header is read (never the query string or body, where a secret lands in logs and proxies), and only
    // for the general API: /lms, /employees and /departments need an access token.
    $legacy_key_raw = (string) $_SERVER['HTTP_X_API_KEY'];
    $int_ip = (string) getIP();
    if ($resource === 'lms' || $resource === 'employees' || $resource === 'departments') {
        \ITFlow\Integration\ApiResponse::error(401, 'unauthorized', 'A valid integration access token is required.');
    }
    // Failed-attempt throttle, same policy as validate_api_key.php: 15 failures per IP in 10 minutes -> 429 (pentest F-07)
    $rl_ip = mysqli_real_escape_string($mysqli, $int_ip);
    $rl_failed = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(log_id) AS c FROM logs
         WHERE log_type = 'API' AND log_action = 'Failed' AND log_ip = '$rl_ip'
           AND log_created_at > (NOW() - INTERVAL 10 MINUTE)"
    ));
    if (intval($rl_failed['c'] ?? 0) >= 15) {
        api_error(429, 'Too many failed attempts. Please try again later.');
    }
    $legacy = \ITFlow\Integration\LegacyKeys::resolve($mysqli, $legacy_key_raw, $int_ip);
    if (!$legacy['ok']) {
        if (str_starts_with($legacy['reason'], 'header_')) {
            // The header has been switched off: say so clearly, and do not count it as a failed guess.
            if (api_rate_limit('legacy_off_ip:' . $int_ip, 60, 60)) {
                \ITFlow\Integration\IntegrationAuth::audit($mysqli, 'Integration API', 'Legacy Key Refused', 'X-Api-Key refused: the deprecated header is switched off (' . $legacy['reason'] . ')', 0, $int_ip);
            }
            header('WWW-Authenticate: Bearer');
            api_error(401, $legacy['message']);
        }
        if ($legacy['status'] === 403) {
            // A valid key from an address its allow-list does not permit: audited, but not counted as a guessed key.
            \ITFlow\Integration\IntegrationAuth::audit($mysqli, 'Integration API', 'Legacy Key Refused', 'X-Api-Key refused: ' . $legacy['reason'], 0, $int_ip);
            api_error(403, $legacy['message']);
        }
        $rl_ua = mysqli_real_escape_string($mysqli, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250));
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'API', log_action = 'Failed', log_description = 'Incorrect or expired key (router)', log_ip = '$rl_ip', log_user_agent = '$rl_ua'");
        // fall through: no machine context -> the 401 gate below answers (a user token, if one was also sent, still works).
    } else {
        $integration_client = $legacy['client'];
        $integration_scopes = $legacy['scopes'];
        $integration_via = 'legacy_key';
        $legacy_api_key_id = (int) $legacy['key_id'];
    }
}
if ($integration_client === null && ($resource === 'lms' || $resource === 'employees' || $resource === 'departments')) {
    // /lms, /employees and /departments accept integration access tokens only; no user token, session or legacy key is ever honoured here.
    \ITFlow\Integration\ApiResponse::error(401, 'unauthorized', 'A valid integration access token is required.');
}

// Machine context for the general API. A client acts as the fixed, disabled, role-less service identity (ServiceIdentity), never as an
// administrator: its only authority is its scopes and its department scope, enforced below and in api_permissions.php.
// $legacy_api_key_auth stays true for every machine caller: it is the flag the endpoints already use for "not a person" (credentials, me,
// approvals refuse it; the module-only profile check is skipped).
//
// Department scope (all / one / several / none) is carried in the variables the endpoints already read: $api_key_client_ids is the list
// (null = all departments, [] = none) and $api_key_client_id is the single-id / -1 sentinel form, so any code that reads only the latter
// still denies (-1 matches no real department) instead of allowing.
$legacy_api_key_auth = false;
$api_key_client_id = null;
$api_key_client_ids = null;
$api_scope_route = null;
if ($integration_client !== null && !$api_user_id) {
    $int_actor = \ITFlow\Integration\ServiceIdentity::userId($mysqli);
    if ($int_actor < 1) {
        error_log('Integration auth: the service identity could not be created');
        api_error(503, 'Service unavailable');
    }
    $api_user_id        = $int_actor;
    $session_user_id    = $int_actor;
    $session_name       = 'Integration client ' . $integration_client['client_name'];
    $session_company_id = 1;
    $legacy_api_key_auth = true;
    $api_key_client_ids = \ITFlow\Integration\IntegrationScope::allowedDepartmentIds($mysqli, $integration_client);
    $api_key_client_id  = $api_key_client_ids === null ? null : (count($api_key_client_ids) === 1 ? $api_key_client_ids[0] : -1);
    if ($integration_via === 'legacy_key') {
        \ITFlow\Integration\LegacyKeys::recordUse($mysqli, $legacy_api_key_id, $integration_client, (string) getIP(), $method, (string) $uri);
        $lk_policy = \ITFlow\Integration\LegacyKeys::policy($mysqli);
        header('Deprecation: true');
        if (!empty($lk_policy['sunset'])) {
            header('Sunset: ' . gmdate('D, d M Y 00:00:00 \G\M\T', strtotime($lk_policy['sunset'] . ' 00:00:00 UTC')));
        }
        header('X-Api-Key-Notice: ' . \ITFlow\Integration\LegacyKeys::MSG_NOTICE);
    }
}

// Semi-public endpoint: crash-reports. Bearer/legacy-key parsing above already ran
// and set $api_user_id opportunistically when a valid token was presented, but this
// must not be blocked by the hard 401 gate below - the crash we most need visibility
// into is exactly the one that happens before login succeeds or while a token is
// invalid/expired, when there is no way for the client to authenticate.
if ($resource === 'crash-reports') {
    if (!api_rate_limit('crash_ip:' . getIP(), 20, 300)) {
        header('Retry-After: 300');
        api_error(429, 'Rate limit exceeded');
    }
    require __DIR__ . '/crash_reports.php';
    exit;
}

// Public endpoint: csat. Aggregate rating summary only - meant to be embedded
// directly on the MSP's own public marketing site as a rating/testimonial
// widget, so no login is possible or expected. Deliberately exposes nothing
// beyond what a Google review badge already would (no client/contact names,
// no ticket subjects) - see csat.php.
if ($resource === 'csat') {
    if ($method !== 'GET') {
        api_error(405, 'Method not allowed');
    }
    if (!api_rate_limit('csat_ip:' . getIP(), 60, 300)) {
        header('Retry-After: 300');
        api_error(429, 'Rate limit exceeded');
    }
    require __DIR__ . '/csat.php';
    exit;
}

if (!$api_user_id) {
    api_error(401, 'Unauthorized');
}

// ── Per-caller rate limit ────────────────────────────────────────────────────
// Best-effort abuse guard; fails open when Redis is unavailable. Keyed per
// token (or per integration client / per user) so one caller can't starve others.
// An integration client has ONE bucket (int:<client id>) whether it presents an access token or the deprecated X-Api-Key header.
// Long-lived SSE streams are excluded so a held-open connection
// (notifications/stream, tickets/{id}/chat?stream=1) never trips the limiter —
// this counts connections, not stream duration.
$is_sse_stream = ($resource === 'notifications' && $sub === 'stream')
              || ($resource === 'tickets' && $sub === 'chat' && isset($_GET['stream']));
if (!$is_sse_stream) {
    if ($integration_client !== null) {
        $rl_bucket = 'int:' . (int) $integration_client['client_id'];
    } elseif ($api_token_row) {
        $rl_bucket = 'tok:' . substr($token_hash, 0, 40);
    } else {
        $rl_bucket = 'usr:' . intval($api_user_id);
    }
    if (!api_rate_limit($rl_bucket, rivetApiRateLimitPerMinute($mysqli), 60)) {
        header('Retry-After: ' . api_rate_limit_retry_after());
        api_error(429, 'Rate limit exceeded');
    }
}

// Scope check for an integration client: a route is reachable only with that route's scope (deny by default, no wildcard).
// Person-bound routes (credentials, me, notifications, approvals) are refused for every client.
if ($integration_client !== null) {
    if (in_array($resource, \ITFlow\Integration\ApiScopes::DENIED_ROUTES, true)) {
        api_error(403, 'This endpoint is only available to a signed-in user (user API token), not to an integration client.');
    }
    if ($resource !== 'validate_api_key') {
        $api_scope_route = \ITFlow\Integration\ApiScopes::routeResource($resource, is_string($sub) ? $sub : null);
        if ($api_scope_route === null) {
            // No scope exists for this route (unknown path): deny by default rather than reveal which paths exist.
            api_error(403, 'No scope grants access to this route.');
        }
        $int_action = \ITFlow\Integration\ApiScopes::actionForMethod($method);
        if ($int_action === null) {
            api_error(405, 'Method not allowed');
        }
        if (!\ITFlow\Integration\ApiScopes::has($integration_scopes, $api_scope_route, $int_action)) {
            api_error(403, 'This integration client does not hold the scope ' . \ITFlow\Integration\ApiScopes::scope($api_scope_route, $int_action));
        }
    }
}

// Roles audit P0: a module-only (limited) login's user token - no Departments, Tickets/assets/docs or
// Assets - may use only its profile, its notifications and the resources of modules it holds (same
// families as the web allow-list). Integration clients are not users: their scopes decide (above).
if (!$legacy_api_key_auth) {
    $api_access_profile = itflow_user_access_profile(intval($api_user_id));
    if (itflow_profile_is_limited($api_access_profile)) {
        $api_limited_ok = in_array($resource, ['me', 'notifications'], true)
            || ($resource === 'kb' && itflow_profile_level($api_access_profile, 'module_kb') >= 1)
            || ($resource === 'reports' && itflow_profile_level($api_access_profile, 'module_reporting') >= 1)
            || ($resource === 'alerts' && itflow_profile_level($api_access_profile, 'module_rmm_alerts') >= 1);
        if (!$api_limited_ok) {
            api_error(403, 'Insufficient permissions');
        }
    }
}

// Route
switch ($resource) {
    case 'dashboard':     require __DIR__ . '/dashboard.php';     break;
    case 'tickets':
        if ($sub === 'charges' || $sub === 'worksheets') {
            require __DIR__ . '/worksheets.php';
        } elseif ($sub === 'outtake' || $sub === 'outtakes') {
            require __DIR__ . '/outtakes.php';
        } elseif ($sub === 'attachments') {
            require __DIR__ . '/tickets.php';
        } else {
            require __DIR__ . '/tickets.php';
        }
        break;
    case 'statuses':      require __DIR__ . '/tickets.php';      break;
    case 'alerts':        require __DIR__ . '/alerts.php';       break;
    case 'ticket-categories':
    case 'ticket_categories':
        require __DIR__ . '/ticket_categories.php';
        break;
    case 'clients':
        if ($sub !== null && !is_numeric($sub ?? '')) {
            require __DIR__ . '/client_tabs.php';
        } else {
            require __DIR__ . '/clients.php';
        }
        break;
    case 'contacts':      require __DIR__ . '/contacts.php';      break;
    case 'assets':        require __DIR__ . '/assets.php';        break;
    case 'relationships': require __DIR__ . '/relationships.php'; break;
    case 'projects':      require __DIR__ . '/projects.php';      break;
    case 'contracts':     require __DIR__ . '/contracts.php';     break;
    case 'tasks':         require __DIR__ . '/tasks.php';         break;
    case 'milestones':    require __DIR__ . '/milestones.php';    break;
    case 'credentials':
        if ($legacy_api_key_auth) { api_error(403, 'Credentials endpoint requires a user API token'); }
        require __DIR__ . '/credentials.php'; break;
    case 'worksheets':        require __DIR__ . '/worksheets.php'; break;
    case 'outtakes':          require __DIR__ . '/outtakes.php';  break;
    case 'worksheet-templates': require __DIR__ . '/worksheets.php'; break;
    case 'search':      require __DIR__ . '/search.php';   break;
    case 'reports':     require __DIR__ . '/reports.php';  break;
    case 'kb':          require __DIR__ . '/kb.php';        break;
    case 'ticket-views':
    case 'ticket_views':
        require __DIR__ . '/ticket_views.php';
        break;
    case 'me':
        if ($legacy_api_key_auth) { api_error(403, 'Profile endpoint requires a user API token'); }
        require __DIR__ . '/me.php';
        break;
    case 'appointments': require __DIR__ . '/appointments.php'; break;
    case 'notifications':
        if ($sub === 'stream') {
            require __DIR__ . '/notifications_stream.php';
        } else {
            require __DIR__ . '/notifications.php';
        }
        break;
    case 'approvals':        require __DIR__ . '/approvals.php';        break;
    case 'endpoint_devices': require __DIR__ . '/endpoint_devices.php'; break;
    case 'service_catalog':  require __DIR__ . '/service_catalog.php';  break;
    case 'workflow_tasks':   require __DIR__ . '/workflow_tasks.php';   break;
    case 'ticket_attachments': require __DIR__ . '/ticket_attachments.php'; break;
    case 'validate_api_key': api_response(200, ['success' => 'True', 'message' => 'API key is valid']); break;
    default:              api_error(404, 'Not found');
}
