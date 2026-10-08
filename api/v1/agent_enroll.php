<?php
// POST /api/v1/agent_enroll   (no Bearer: the body carries a short-lived enrollment token)
//   -> 201 {device_id, device_token (shown once), check_in_interval_s, server_time, status, matched_asset_id, signing_public_key, config}
// Dispatched from index.php before any authentication or body read. Handled by rivet/rivet-core (RivetCore\Rmm\Http\DeviceApi::enroll).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch('agent_enroll');
