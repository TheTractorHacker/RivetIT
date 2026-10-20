<?php
// POST /api/v1/agent_changes  (device auth) {changes:[{...}]}  (JSON up to 256 KiB, device bearer token) -> {"ok":true,...}
// Change monitoring (RivetCore 1.0.0-rc.22): registry values, files, local groups, scheduled tasks. Answers 503 feature_disabled while the change_monitor sub-switch is off
// (the default) and 503 unavailable under load. The device id comes from the credential, never from the request. Handled by rivet/rivet-core (RivetCore\Rmm\Http\DeviceApi::changes).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch('agent_changes');
