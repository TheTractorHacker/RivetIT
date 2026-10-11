<?php
// POST /api/v1/agent_logs  (device auth) {entries:[{ts, source, level, event_id, message, ...}]}  (at most 200 entries and 256 KiB) -> {"ok":true,"accepted":N,"dropped":N,"duplicates":N}
// Optional event-log and syslog collection (RMM Phase 8). Answers 503 feature_disabled while the logs sub-switch is off and 503 unavailable under load (the first optional work
// given up). The device id comes from the credential, never from the request. Handled by rivet/rivet-core (RivetCore\Rmm\Http\DeviceApi::logs).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch('agent_logs');
