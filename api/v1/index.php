<?php
define('FROM_API', true);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Biometric, X-Api-Key');

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

// Public endpoint: auth
if ($resource === 'auth') {
    // Tight per-IP limit on the unauthenticated login path (fails open if
    // Redis is down). auth.php also enforces a per-user failed-login lockout.
    $auth_ip = getIP();
    if (!api_rate_limit('auth_ip:' . $auth_ip, 30, 60)) {
        header('Retry-After: 60');
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
// fails open when Redis is down. The docs page is 100% self-contained (inline
// CSS, no JS, no external assets), so it renders safely under a strict
// `default-src 'self'` CSP.
if ($resource === 'openapi' || $resource === 'docs') {
    if (!api_rate_limit('docs_ip:' . getIP(), 60, 60)) {
        header('Retry-After: 60');
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

if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
    $raw_token  = $m[1];
    $token_hash = hash('sha256', $raw_token);
    $esc        = mysqli_real_escape_string($mysqli, $token_hash);
    $sql        = mysqli_query($mysqli,
        "SELECT t.*, u.user_id, u.user_name, u.user_email, u.user_type
         FROM api_tokens t
         JOIN users u ON t.token_user_id = u.user_id
         WHERE t.token_hash = '$esc'
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

// Legacy api_key auth: accept header (preferred — keeps key out of server logs) or ?api_key= query param
$legacy_api_key_auth = false;
$legacy_key_raw = null;
if (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $legacy_key_raw = $_SERVER['HTTP_X_API_KEY'];
} elseif (isset($_GET['api_key'])) {
    $legacy_key_raw = $_GET['api_key'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Some clients send the api_key in the JSON body instead of the query string
    $json_body = json_decode(file_get_contents('php://input'), true);
    if (is_array($json_body) && isset($json_body['api_key'])) {
        $legacy_key_raw = $json_body['api_key'];
    }
}
// api_key_client_id, when non-zero, is the "Client Access" restriction an admin
// picked when creating this legacy key (0/NULL = "ALL CLIENTS"). This used to be
// stored but never actually read/enforced anywhere - every legacy key granted
// full instance-wide access regardless of this setting. api_client_scope_sql()/
// api_client_scope_ok() in includes/api_permissions.php now fold this in.
$api_key_client_id = null;
if (!$api_user_id && $legacy_key_raw !== null) {
    $legacy_key = mysqli_real_escape_string($mysqli, hash('sha256', $legacy_key_raw));
    $legacy_sql = mysqli_query($mysqli,
        "SELECT * FROM api_keys WHERE api_key_secret = '$legacy_key' AND api_key_expire > NOW() LIMIT 1"
    );
    $legacy_key_row = mysqli_fetch_assoc($legacy_sql);
    if ($legacy_key_row) {
        $admin = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT user_id, user_name FROM users
             WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
             ORDER BY user_id LIMIT 1"
        ));
        if ($admin) {
            $api_user_id        = intval($admin['user_id']);
            $session_user_id    = $api_user_id;
            $session_name       = $admin['user_name'];
            $session_company_id = 1;
            $legacy_api_key_auth = true;
            $api_key_client_id  = intval($legacy_key_row['api_key_client_id'] ?? 0) ?: null;
        }
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
// token (or per legacy key / per user) so one caller can't starve others.
// Long-lived SSE streams are excluded so a held-open connection
// (notifications/stream, tickets/{id}/chat?stream=1) never trips the limiter —
// this counts connections, not stream duration.
$is_sse_stream = ($resource === 'notifications' && $sub === 'stream')
              || ($resource === 'tickets' && $sub === 'chat' && isset($_GET['stream']));
if (!$is_sse_stream) {
    if ($api_token_row) {
        $rl_bucket = 'tok:' . substr($token_hash, 0, 40);
    } elseif ($legacy_api_key_auth) {
        $rl_bucket = 'key:' . substr($legacy_key, 0, 40);
    } else {
        $rl_bucket = 'usr:' . intval($api_user_id);
    }
    if (!api_rate_limit($rl_bucket, 300, 60)) {
        header('Retry-After: 60');
        api_error(429, 'Rate limit exceeded');
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
    case 'credentials':
        if ($legacy_api_key_auth) { api_error(403, 'Credentials endpoint requires a user API token'); }
        require __DIR__ . '/credentials.php'; break;
    case 'quotes':        require __DIR__ . '/quotes.php';        break;
    case 'invoices':      require __DIR__ . '/invoices.php';      break;
    case 'expenses':      require __DIR__ . '/expenses.php';      break;
    case 'worksheets':        require __DIR__ . '/worksheets.php'; break;
    case 'outtakes':          require __DIR__ . '/outtakes.php';  break;
    case 'worksheet-templates': require __DIR__ . '/worksheets.php'; break;
    case 'products':    require __DIR__ . '/products.php'; break;
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
    case 'validate_api_key': api_response(200, ['success' => 'True', 'message' => 'API key is valid']); break;
    default:              api_error(404, 'Not found');
}
