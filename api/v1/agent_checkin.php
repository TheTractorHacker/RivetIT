<?php
// POST /api/v1/agent_checkin   (Authorization: Bearer <device token>)
//   -> 200 {ok, next_check_in_s, jobs_pending, config, update, server_time}
// Idempotent by (device_id, seq). Dispatched from index.php before the user-token/legacy-key parsing.
// Handled by rivet/rivet-core (RivetCore\Rmm\Http\DeviceApi::checkin).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch('agent_checkin');
