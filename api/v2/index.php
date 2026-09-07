<?php
/*
 * Minimal proof-of-pattern front controller for API v2 (master plan Section
 * 36). Only one real endpoint exists so far - GET /api/v2/workflow-runs -
 * meant to prove the routing/auth pattern works, not to be a comprehensive
 * v2 API. Everything else in api/v1 stays v1; nothing here replaces it.
 *
 * Auth reuses the same Bearer api_tokens mechanism as v1 (api/v1/index.php).
 * Deliberately does NOT carry over v1's legacy X-Api-Key fallback - that
 * exists only for pre-token integrations v2 has no reason to inherit.
 */
define('FROM_API_V2', true);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

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

function api_v2_response(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function api_v2_error(int $code, string $message): void {
    api_v2_response($code, ['error' => $message]);
}

$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base     = '/api/v2';
$path     = preg_replace('#^' . preg_quote($base, '#') . '#', '', $uri);
$segments = array_values(array_filter(explode('/', $path)));
$method   = $_SERVER['REQUEST_METHOD'];
$resource = $segments[0] ?? '';

$api_user_id = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader) && function_exists('getallheaders')) {
    $hdrs = getallheaders();
    $authHeader = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
}

if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
    $token_hash = hash('sha256', $m[1]);
    $esc = mysqli_real_escape_string($mysqli, $token_hash);
    // Same account-state filter as api/v1/index.php - a token alone must not
    // outlive the account behind it. Without these a disabled or archived
    // employee keeps API access indefinitely, which no other auth path allows.
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT t.token_id, u.user_id FROM api_tokens t
         JOIN users u ON t.token_user_id = u.user_id
         WHERE t.token_hash = '$esc'
           AND u.user_status = 1
           AND u.user_archived_at IS NULL
           AND u.user_type = 1
         LIMIT 1"
    ));
    if ($row) {
        $api_user_id = intval($row['user_id']);
        mysqli_query($mysqli, "UPDATE api_tokens SET token_last_used_at = NOW() WHERE token_hash = '$esc'");
    }
}

if (!$api_user_id) {
    api_v2_error(401, 'Unauthorized');
}

switch ($resource) {
    case 'workflow-runs':
        if ($method !== 'GET') api_v2_error(405, 'Method not allowed');
        require __DIR__ . '/workflow_runs.php';
        break;
    default:
        api_v2_error(404, 'Not found');
}
