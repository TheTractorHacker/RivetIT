<?php
// GET|HEAD /api/v1/agent_linux_file?name=install-linux.sh|rivetit-agent-linux-amd64|rivetit-agent-linux-arm64|rivetit-agent-linux-<arch>.tar.gz&version=<x.y.z>
//   X-Install-Key: rvdl1.<selector>.<expiry>.<hmac>      (or Authorization: Bearer rvdl1....)   NEVER the enrollment token, never in the URL
//   -> 200 application/octet-stream with Content-Length, X-Content-SHA256, ETag, nosniff, no-store: a Linux file this server hosts (includes/rmm_linux_store.php)
//   -> 401 {"code":"invalid_key"}   one answer for a missing, malformed, wrong, expired or revoked credential (the audit log says which)
//   -> 404 not_found (credential fine, file not hosted) / 429 rate limited / 503 module off
// The credential is derived from a still-valid enrollment token for ONE file at ONE version; it does not use up an enrollment. Dispatched from index.php before any
// authentication or body read (a device is not an api_tokens row).
defined('FROM_API') || die();
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_linux_store.php';

function lx_reply(int $code, array $data, array $headers = []): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($data);
    exit;
}

global $session_user_id, $session_ip, $session_user_agent, $mysqli, $method;
$session_user_id = 0;
$session_ip = getIP();
$session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
mysqli_report(MYSQLI_REPORT_OFF);

if ($method !== 'GET' && $method !== 'HEAD') {
    lx_reply(405, ['error' => 'GET only.', 'code' => 'method'], ['Allow: GET, HEAD']);
}
// per address first, before any database work
if (!api_rate_limit('lx_ip:' . $session_ip, 60, 60)) {
    lx_reply(429, ['error' => 'Rate limit exceeded.', 'code' => 'rate_limited'], ['Retry-After: ' . api_rate_limit_retry_after()]);
}
try {
    if (!rivetRmmModule()->enabled()) {
        lx_reply(503, ['error' => 'The RMM service is disabled on this server.', 'code' => 'module_disabled'], ['Retry-After: 3600']);
    }
} catch (\Throwable $e) {
    error_log('agent_linux_file: ' . get_class($e));
    lx_reply(500, ['error' => 'Internal error.', 'code' => 'internal']);
}

$lx_name = $_GET['name'] ?? '';
$lx_version = $_GET['version'] ?? '';
$lx_cred = (string) ($_SERVER['HTTP_X_INSTALL_KEY'] ?? '');
if ($lx_cred === '' && preg_match('/^Bearer\s+(rvdl1\.\S+)$/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $lx_m) === 1) {
    $lx_cred = $lx_m[1];
}
$lx_cred = trim($lx_cred);
if (!is_string($lx_name) || !is_string($lx_version) || rivetLxParseName($lx_name) === null || preg_match(RIVET_LX_VERSION_RE, $lx_version) !== 1 || strlen($lx_cred) > 200) {
    // a malformed request is counted like a bad key so the answer is the same and guessing is slow
    api_rate_limit('lx_fail:' . $session_ip, 10, 600);
    lx_reply(401, ['error' => 'A valid install key is required.', 'code' => 'invalid_key']);
}
if (!rivetLxReady($mysqli)) {
    lx_reply(503, ['error' => 'Not available.', 'code' => 'unavailable']);
}
$lx_v = rivetLxVerifyCredential($mysqli, $lx_cred, $lx_name, $lx_version);
if (!$lx_v['ok']) {
    $lx_ok = api_rate_limit('lx_fail:' . $session_ip, 10, 600);
    if ($lx_v['reason'] !== 'malformed' || $lx_cred !== '') {
        logAction('Endpoint Agent', 'Linux File Download Refused', 'Download of ' . $lx_name . ' ' . $lx_version . ' refused (' . $lx_v['reason'] . ') from ' . $session_ip . ($lx_v['selector'] !== '' ? ' using token ' . $lx_v['selector'] : ''));
    }
    if (!$lx_ok) {
        lx_reply(429, ['error' => 'Too many failed attempts. Try again later.', 'code' => 'rate_limited'], ['Retry-After: ' . api_rate_limit_retry_after()]);
    }
    lx_reply(401, ['error' => 'A valid install key is required.', 'code' => 'invalid_key']);
}
// a valid credential may fetch a file a handful of times (two files, retries) per window
if (!api_rate_limit('lx_sel:' . $lx_v['selector'], 30, 600)) {
    lx_reply(429, ['error' => 'Rate limit exceeded.', 'code' => 'rate_limited'], ['Retry-After: ' . api_rate_limit_retry_after()]);
}
$lx_row = rivetLxFind($mysqli, $lx_name, $lx_version);
$lx_path = $lx_row !== null ? rivetLxPath($lx_row) : null;
if ($lx_row === null || $lx_path === null || !is_file($lx_path) || is_link($lx_path)) {
    lx_reply(404, ['error' => 'That file is not hosted on this server.', 'code' => 'not_found']);
}
if ((int) filesize($lx_path) !== (int) $lx_row['size_bytes'] || !hash_equals((string) $lx_row['sha256'], (string) hash_file('sha256', $lx_path))) {
    logAction('Endpoint Agent', 'Linux File Damaged', 'Hosted ' . $lx_name . ' ' . $lx_version . ' no longer matches its recorded size or SHA-256; not served');
    lx_reply(500, ['error' => 'The hosted file is damaged.', 'code' => 'damaged']);
}
logAction('Endpoint Agent', 'Linux File Downloaded', 'Served ' . $lx_name . ' ' . $lx_version . ' (' . (int) $lx_row['size_bytes'] . ' bytes, sha256 ' . substr((string) $lx_row['sha256'], 0, 16) . ') to ' . $session_ip . ' with the key of token ' . $lx_v['selector']);
while (ob_get_level() > 0) {
    ob_end_clean();
}
http_response_code(200);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $lx_name . '"');
header('Content-Length: ' . (int) $lx_row['size_bytes']);
header('X-Content-SHA256: ' . $lx_row['sha256']);
header('ETag: "' . $lx_row['sha256'] . '"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($method !== 'HEAD') {
    $lx_h = fopen($lx_path, 'rb');
    if ($lx_h) {
        while (!feof($lx_h)) {
            echo fread($lx_h, 262144);
            flush();
        }
        fclose($lx_h);
    }
}
exit;
