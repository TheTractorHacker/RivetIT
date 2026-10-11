<?php
// GET  /api/v1/agent_transfer?transfer_id=U&offset=N&length=M   (device auth + X-Transfer-Token) -> raw bytes of a file queued FOR the device (an upload to the device),
//                                                               with X-Transfer-Size and X-Transfer-Sha256 headers
// POST /api/v1/agent_transfer?transfer_id=U&offset=N            (device auth + X-Transfer-Token) raw body of at most 1 MiB: the next chunk of a file the device sends (a download
//                                                               from the device); X-Transfer-Last: 1 and X-Transfer-Sha256 on the final chunk -> {"ok":true,...}
// A device only ever moves the transfers of its OWN device id (the id comes from the credential, never from the request) and only with the one-time token of that
// transfer (only its SHA-256 is stored). Answers 503 feature_disabled while the filetransfer sub-switch is off. Handled by rivet/rivet-core (RivetCore\Rmm\Http\DeviceApi::transfer).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch('agent_transfer');
