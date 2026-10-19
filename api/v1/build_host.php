<?php
// POST /api/v1/build_host/{poll|status|upload|complete}    Authorization: Bearer rbh_<id>_<secret>   (a BUILD HOST token, not an API key)
// Server half of rivet-core docs/rmm/BUILD_HOST_PROTOCOL.md. JSON in, JSON out. Rules and limits: includes/rmm_build_host.php.
defined('FROM_API') || die();
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_build_host.php';

function bh_reply(int $code, array $data): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit;
}

global $session_user_id, $session_ip, $session_user_agent, $mysqli, $sub, $method;
$session_user_id = 0;
$session_ip = getIP();
$session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
mysqli_report(MYSQLI_REPORT_OFF);

$bh_action = is_string($sub) ? $sub : '';
if (!in_array($bh_action, ['poll', 'status', 'upload', 'complete'], true)) {
    bh_reply(404, ['error' => 'Not found.', 'code' => 'not_found']);
}
if ($method !== 'POST') {
    header('Allow: POST');
    bh_reply(405, ['error' => 'POST only.', 'code' => 'method']);
}
// per address first (cheap, before any database work), so guessing tokens is slow
if (!api_rate_limit('bh_ip:' . $session_ip, 600, 60)) {
    header('Retry-After: ' . api_rate_limit_retry_after());
    bh_reply(429, ['error' => 'Rate limit exceeded.', 'code' => 'rate_limited']);
}
if (!rivetBuildHostReady($mysqli)) {
    bh_reply(503, ['error' => 'Not available.', 'code' => 'unavailable']);
}
$bh_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$bh_host = rivetBuildHostAuth($mysqli, (string) $bh_auth, $bh_why);
if ($bh_host === null) {
    if (!api_rate_limit('bh_fail:' . $session_ip, 20, 60, true)) {
        header('Retry-After: ' . api_rate_limit_retry_after());
        bh_reply(429, ['error' => 'Rate limit exceeded.', 'code' => 'rate_limited']);
    }
    if ($bh_why !== 'malformed') {
        logAction('Endpoint Agent', 'Agent Build Host Auth Refused', 'A build host request was refused (' . $bh_why . ') from ' . $session_ip);
    }
    bh_reply(401, ['error' => 'Invalid or revoked token.', 'code' => 'invalid_token']);
}
if (!api_rate_limit('bh_host:' . (int) $bh_host['host_id'], 300, 60)) {
    header('Retry-After: ' . api_rate_limit_retry_after());
    bh_reply(429, ['error' => 'Rate limit exceeded.', 'code' => 'rate_limited']);
}
// body: bounded read, JSON object
$bh_raw = '';
$h = fopen('php://input', 'rb');
if ($h) {
    $bh_raw = (string) stream_get_contents($h, RIVET_BH_BODY_MAX + 1);
    fclose($h);
}
if (strlen($bh_raw) > RIVET_BH_BODY_MAX) {
    bh_reply(413, ['error' => 'Request too large.', 'code' => 'too_large']);
}
$bh_body = json_decode($bh_raw, true, 8);
if (!is_array($bh_body)) {
    bh_reply(400, ['error' => 'A JSON object is required.', 'code' => 'invalid']);
}
try {
    [$bh_code, $bh_out] = match ($bh_action) {
        'poll' => rivetBuildHostPoll($mysqli, $bh_host, $bh_body, $session_ip),
        'status' => rivetBuildHostStatusCall($mysqli, $bh_host, $bh_body, $session_ip),
        'upload' => rivetBuildHostUpload($mysqli, $bh_host, $bh_body, $session_ip),
        'complete' => rivetBuildHostComplete($mysqli, $bh_host, $bh_body, $session_ip),
    };
} catch (\Throwable $e) {
    error_log('build host: ' . get_class($e) . ': ' . $e->getMessage());
    [$bh_code, $bh_out] = [500, ['error' => 'Internal error.', 'code' => 'internal']];
}
bh_reply($bh_code, $bh_out);
