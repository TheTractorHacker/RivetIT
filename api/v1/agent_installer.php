<?php
// POST /api/v1/agent_installer   (public, token-gated: for RMM / GPO / Intune deployment scripts)
//   body (JSON or form): {"token":"rvte1....","arch":"amd64|arm64"}   (the token may instead be an Authorization: Bearer header)
//   -> 200 application/octet-stream: the stamped per-department installer (RivetIT-Agent-Setup-<department>-<arch>.exe)
//   -> 404 generic for any token that is not currently usable (unknown, wrong, revoked, expired, used up)
// The token is NEVER read from the query string, so it stays out of access logs. Dispatched from index.php before any authentication or
// body read. See docs/ENDPOINT_AGENT.md.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

use ITFlow\EndpointAgent\ApiError;
use ITFlow\EndpointAgent\Binaries;
use ITFlow\EndpointAgent\Config;
use ITFlow\EndpointAgent\Installer;
use ITFlow\EndpointAgent\InstallerStamp;
use ITFlow\EndpointAgent\Jobs;

ea_guard(static function () {
    ea_require_tls();
    ea_audit_context();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ea_error(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
    }
    if (isset($_GET['token'])) {
        ea_error(400, 'token_in_url', 'Send the token in the request body or an Authorization header, never in the URL.');
    }
    if (!Config::enabled()) {
        ea_error(403, 'forbidden', 'The endpoint agent service is disabled.');
    }
    $ip = getIP();
    Installer::checkRate($ip);

    $len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : -1;
    if ($len > 4096) {
        ea_error(413, 'too_large', 'Request body too large.');
    }
    $in = fopen('php://input', 'rb');
    $raw = (string) stream_get_contents($in, 4097);
    fclose($in);
    if (strlen($raw) > 4096) {
        ea_error(413, 'too_large', 'Request body too large.');
    }
    $ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ctype, 'json') !== false) {
        $body = json_decode($raw, true);
    } else {
        $body = [];
        parse_str($raw, $body);
    }
    if (!is_array($body)) {
        ea_error(422, 'invalid', 'A JSON object or form body with token and arch is required.');
    }
    $token = $body['token'] ?? null;
    if ($token === null && preg_match('/^Bearer\s+(\S+)$/i', (string) ea_bearer(), $m)) {
        $token = $m[1];
    }
    $arch = $body['arch'] ?? null;
    if (!is_string($token) || $token === '' || strlen($token) > 200 || !is_string($arch) || !isset(Binaries::ARCHS[$arch])) {
        ea_error(422, 'invalid', 'token and arch (amd64 or arm64) are required.');
    }
    $parts = explode('.', $token);
    Installer::checkRate($ip, count($parts) === 3 ? substr($parts[1], 0, 12) : '');

    $tok = Installer::authenticate($token, $ip);
    $refusal = Installer::preflight($arch);
    if ($refusal !== null) {
        ea_error(409, 'unavailable', $refusal);
    }
    $installerId = Jobs::uuid();
    [$payload, $err] = Installer::payloadFor($tok, $token, $installerId);
    if ($payload === null) {
        ea_error(409, 'unavailable', (string) $err);
    }
    $bin = Binaries::current($arch);
    $name = Installer::filename(Installer::departmentName((int) $tok['client_id']), $arch);
    Installer::recordSuccess($tok, $ip, $arch, $installerId);
    $e = Binaries::stream($bin, $name, InstallerStamp::trailer($payload));
    ea_error(503, 'unavailable', $e);
});
