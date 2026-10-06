<?php
// GET  /api/v1/agent_jobs[?wait=1..5]  (device auth) -> {"jobs":[ signed job objects ]}   (long-poll up to 5 s)
// POST /api/v1/agent_jobs              (device auth) {job_id, attempt, state, exit_code, output, started_at, finished_at} -> {"ok":true}
// A device only ever sees and reports its OWN jobs: the device id comes from the credential, never from the request.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

use ITFlow\EndpointAgent\Devices;
use ITFlow\EndpointAgent\Jobs;

ea_guard(static function () {
    ea_require_tls();
    ea_audit_context();
    $dev = Devices::authenticate(ea_bearer());
    $deviceId = (int) $dev['device_id'];
    ea_device_rate_limit($deviceId, 'jobs', 120, 60);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $wait = max(0, min(5, (int) ($_GET['wait'] ?? 0)));
        $deadline = microtime(true) + $wait;
        do {
            $jobs = Jobs::offer($dev);
            if ($jobs || microtime(true) >= $deadline) {
                break;
            }
            usleep(500000);
        } while (true);
        ea_send(200, ['jobs' => $jobs]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Jobs::report($dev, ea_body(262144));
        ea_send(200, ['ok' => true]);
    }
    ea_error(405, 'method_not_allowed', 'Use GET or POST.', ['Allow' => 'GET, POST']);
});
