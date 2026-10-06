<?php
defined('FROM_API') || die();

/**
 * Shared plumbing for the DEVICE-facing endpoints (agent_enroll, agent_checkin, agent_jobs).
 *
 * These are dispatched from api/v1/index.php ABOVE the Bearer/legacy-key parsing and above its pre-auth request-body read: a device
 * credential is not an api_tokens row, and that early body read has no size ceiling. Every handler therefore does its own
 * authentication, its own bounded body read and its own rate limiting.
 *
 * Errors are {"error": "...", "code": "..."}; 401 codes are invalid_token, revoked and expired.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use ITFlow\EndpointAgent\ApiError;

function ea_send(int $http, array $data, array $headers = []): void
{
    http_response_code($http);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function ea_error(int $http, string $code, string $message, array $headers = []): void
{
    ea_send($http, ['error' => $message, 'code' => $code], $headers);
}

/** TLS is required. Plain http is accepted only when config.php defines EA_ALLOW_INSECURE_HTTP = true (loopback test servers). */
function ea_require_tls(): void
{
    if (defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $port443 = (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    // Behind a TLS-terminating reverse proxy on the same host / private network.
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $peerIsLocal = filter_var($peer, FILTER_VALIDATE_IP) !== false
        && filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    $proxied = $peerIsLocal && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if (!$https && !$port443 && !$proxied) {
        ea_error(426, 'tls_required', 'TLS is required.');
    }
}

/** Bounded JSON object body. 413 above $max bytes, 422 when it is not a JSON object. */
function ea_body(int $max): array
{
    $len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : -1;
    if ($len > $max) {
        ea_error(413, 'too_large', 'Request body too large.');
    }
    $in = fopen('php://input', 'rb');
    $raw = stream_get_contents($in, $max + 1);
    fclose($in);
    if ($raw === false || strlen($raw) > $max) {
        ea_error(413, 'too_large', 'Request body too large.');
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || ($d && array_is_list($d))) {
        ea_error(422, 'invalid', 'A JSON object body is required.');
    }
    return $d;
}

function ea_bearer(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        $all = getallheaders();
        $h = $all['Authorization'] ?? $all['authorization'] ?? '';
    }
    return $h === '' ? null : $h;
}

/** Audit calls from a device have no session user. */
function ea_audit_context(): void
{
    global $session_user_id, $session_ip, $session_user_agent;
    $session_user_id = 0;
    $session_ip = getIP();
    $session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
}

/** Run a handler; ApiError becomes its JSON error, anything else a generic 500 (details go to the PHP log only). */
function ea_guard(callable $fn): void
{
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $fn();
    } catch (ApiError $e) {
        ea_error($e->http, $e->errCode, $e->getMessage(), $e->headers);
    } catch (\Throwable $e) {
        error_log('endpoint agent: ' . get_class($e) . ': ' . $e->getMessage());
        ea_error(500, 'internal', 'Internal error.');
    }
}

function ea_device_rate_limit(int $deviceId, string $kind, int $limit, int $window): void
{
    if (!api_rate_limit("agent_{$kind}:$deviceId", $limit, $window)) {
        ea_error(429, 'rate_limited', 'Too many requests.', ['Retry-After' => (string) $window]);
    }
}
