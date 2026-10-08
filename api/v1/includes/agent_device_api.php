<?php
defined('FROM_API') || die();

/**
 * Bridge for the DEVICE-facing endpoints (agent_enroll, agent_checkin, agent_jobs, agent_update, agent_installer).
 *
 * These are dispatched from api/v1/index.php ABOVE the Bearer/legacy-key parsing and above its pre-auth request-body read: a device
 * credential is not an api_tokens row, and that early body read has no size ceiling. The handlers themselves live in rivet/rivet-core
 * (RivetCore\Rmm\Http\DeviceApi, wire protocol: docs/rmm/PROTOCOL.md there); this file only builds the request from the superglobals,
 * supplies RivetIT's rate limiter, and emits the response. TLS and proxy trust are decided here (rivetRmmRequest()).
 *
 * Errors are {"error": "...", "code": "..."}; 401 codes are invalid_token, revoked and expired. The URL space, request and response formats
 * are frozen: agents in the field depend on them.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/includes/rmm_bootstrap.php';

function ea_dispatch(string $endpoint): void
{
    global $session_user_id, $session_ip, $session_user_agent;
    // Audit entries made on behalf of a device have no session user.
    $session_user_id = 0;
    $session_ip = getIP();
    $session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    mysqli_report(MYSQLI_REPORT_OFF);

    try {
        // Compatibility answer: a disabled service still answers 403 forbidden here, as it always did (agents in the field). The 503 module_disabled
        // answer comes from the pre-bootstrap gate (api/v1/rmm_gate.php) once the state file says the module is off.
        $api = rivetRmmModule()->deviceApi(static fn (string $bucket, int $limit, int $window): bool => api_rate_limit($bucket, $limit, $window), true, null, null, \RivetCore\Rmm\Http\DeviceApi::DISABLED_COMPAT);
        $response = $api->handle(rivetRmmRequest($endpoint));
    } catch (\Throwable $e) {
        error_log('endpoint agent: ' . get_class($e) . ': ' . $e->getMessage());
        $response = \RivetCore\Rmm\Http\RmmResponse::json(500, ['error' => 'Internal error.', 'code' => 'internal']);
    }
    (new \RivetCore\Rmm\Http\SapiEmitter())->emit($response);
    exit;
}
