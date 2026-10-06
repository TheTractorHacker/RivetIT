<?php
// POST /api/v1/agent_enroll   (no Bearer: the body carries a short-lived enrollment token)
//   -> 201 {device_id, device_token (shown once), check_in_interval_s, server_time, status, matched_asset_id, signing_public_key, config}
// Dispatched from index.php before any authentication or body read. See docs/ENDPOINT_AGENT.md.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

use ITFlow\EndpointAgent\Config;
use ITFlow\EndpointAgent\Enrollment;

ea_guard(static function () {
    ea_require_tls();
    ea_audit_context();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ea_error(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
    }
    if (!Config::enabled()) {
        ea_error(403, 'forbidden', 'The endpoint agent service is disabled.');
    }
    $body = ea_body(16384);
    ea_send(201, Enrollment::enroll($body, getIP()));
});
