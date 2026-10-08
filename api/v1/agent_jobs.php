<?php
// GET  /api/v1/agent_jobs[?wait=1..5]  (device auth) -> {"jobs":[ signed job objects ]}   (long-poll up to 5 s)
// POST /api/v1/agent_jobs              (device auth) {job_id, attempt, state, exit_code, output, started_at, finished_at} -> {"ok":true}
// A device only ever sees and reports its OWN jobs: the device id comes from the credential, never from the request.
// Handled by rivet/rivet-core (RivetCore\Rmm\Http\DeviceApi::jobs).
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

ea_dispatch('agent_jobs');
