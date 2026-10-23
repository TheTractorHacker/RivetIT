<?php
defined('FROM_API') || die();

/**
 * Bridge for RivetPanel's device-facing endpoints (panel_enroll, panel_me, panel_categories, panel_tickets, panel_ticket_chat).
 *
 * Twin of agent_device_api.php: dispatched from api/v1/index.php ABOVE the Bearer/legacy-key parsing and above its pre-auth
 * request-body read, because a panel device credential (rvpd_...) is not an api_tokens row either, and the handler needs a body
 * read sized for base64 attachments (PanelLimits::postBodyMax(), about 14 MiB) rather than the generic API's unbounded pre-auth read.
 * The handler itself lives in rivet/rivet-core (RivetCore\Panel\Http\PanelApi, wire protocol: vendor/rivet/rivet-core/docs/panel/PANEL_API.md);
 * this file only builds the request from the superglobals (reusing rivetRmmRequest() - same TLS/proxy-trust rule, same RmmRequest shape
 * PanelApi is built over), supplies RivetIT's rate limiter, and emits the response.
 *
 * Errors are {"error": "...", "code": "...", "server_time": "..."}. The URL space, request and response formats follow PANEL_API.md.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/includes/panel_bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/rmm_bootstrap.php';   // rivetRmmRequest() builds the shared RmmRequest shape PanelApi takes too

function panel_dispatch(string $endpoint): void
{
    global $session_user_id, $session_ip, $session_user_agent;
    // Audit entries made on behalf of a panel device have no session user.
    $session_user_id = 0;
    $session_ip = getIP();
    $session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    mysqli_report(MYSQLI_REPORT_OFF);

    try {
        // PanelApi reads the ticket id from pathSegments[0] or the `ticket_id` query value (PANEL_API.md allows either); RivetIT
        // routes panel_tickets/{id}/chat as ?ticket_id= (no path segment), and rivetRmmRequest() already carries every $_GET key
        // into RmmRequest::$query, so nothing extra is needed here.
        $api = rivetPanelModule()->api(static fn (string $bucket, int $limit, int $window): bool => api_rate_limit($bucket, $limit, $window));
        $response = $api->handle(rivetRmmRequest($endpoint));
    } catch (\Throwable $e) {
        error_log('rivetpanel: ' . get_class($e) . ': ' . $e->getMessage());
        $response = \RivetCore\Rmm\Http\RmmResponse::json(500, ['error' => 'Internal error.', 'code' => 'internal']);
    }
    (new \RivetCore\Rmm\Http\SapiEmitter())->emit($response);
    exit;
}
