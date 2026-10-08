<?php
// GET /api/v1/agent_update?arch=amd64|arm64&version=1.2.3   (device auth)
//   -> 200 application/octet-stream: the UNSTAMPED agent executable of the hosted release this device is currently offered.
// The device id comes from the credential. Only the release the update manifest offers THIS device right now is served, only for its
// own architecture, and only when the stored file still matches the manifest's SHA-256. Anything else is a generic 404.
// Handled by rivet/rivet-core (RivetCore\Rmm\Http\DeviceApi::update).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch('agent_update');
