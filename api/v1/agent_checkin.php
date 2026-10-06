<?php
// POST /api/v1/agent_checkin   (Authorization: Bearer <device token>)
//   -> 200 {ok, next_check_in_s, jobs_pending, config, update, server_time}
// Idempotent by (device_id, seq). Dispatched from index.php before the user-token/legacy-key parsing.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

use ITFlow\EndpointAgent\Checkin;
use ITFlow\EndpointAgent\Devices;

ea_guard(static function () {
    ea_require_tls();
    ea_audit_context();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        ea_error(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
    }
    $dev = Devices::authenticate(ea_bearer());
    ea_device_rate_limit((int) $dev['device_id'], 'checkin', 40, 60);
    $body = ea_body(Checkin::MAX_BODY_BYTES);
    ea_send(200, Checkin::handle($dev, $body));
});
