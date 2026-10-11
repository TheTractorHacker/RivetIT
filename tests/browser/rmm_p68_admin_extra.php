<?php
/*
 * Helper for tests/rmm_ui_p68_admin.php and tests/browser/rmm_p68_admin_smoke.mjs (RivetCore 1.0.0-rc.11, builder C). Scratch database only (the guard of
 * tests/endpoint_agent_lib.php applies). Runs in a FRESH process, so the module is built from the settings as saved (cron does the same).
 *
 *   php tests/browser/rmm_p68_admin_extra.php housekeeping     one housekeeping run (rivetRmmHousekeeping), prints the result as one JSON line
 *   php tests/browser/rmm_p68_admin_extra.php checkin <n>      <n> check-ins of a fresh linked device through the real endpoint (fills the metric outbox when the store is on)
 *   php tests/browser/rmm_p68_admin_extra.php queue <n>        <n> pending rmm.ingest jobs (the backlog the autoscaling hint reacts to); 0 deletes them
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

$mode = $argv[1] ?? '';
if ($mode === 'housekeeping') {
    echo json_encode(rivetRmmHousekeeping($db)), "\n";
} elseif ($mode === 'checkin') {
    $n = max(1, (int) ($argv[2] ?? 1));
    $q("INSERT INTO assets SET asset_type='Laptop', asset_name='P68X', asset_make='Dell', asset_serial='SER-P68X', asset_client_id=1, asset_status='Active'");
    $tok = ea_token(1, 24, 5);
    [$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'SER-P68X', 'hostname' => 'P68X']));
    $dt = (string) ($j['device_token'] ?? '');
    $done = 0;
    for ($i = 0; $i < $n && $dt !== ''; $i++) {
        [$cc] = ea_checkin($dt);
        $done += $cc === 200 ? 1 : 0;
    }
    echo json_encode(['enroll' => $c, 'checkins' => $done]), "\n";
} elseif ($mode === 'queue') {
    $n = max(0, (int) ($argv[2] ?? 0));
    $q("DELETE FROM integration_jobs WHERE job_type='rmm.ingest'");
    for ($i = 0; $i < $n; $i += 500) {
        $vals = implode(',', array_fill(0, min(500, $n - $i), "('rmm.ingest','rmm_checkin','pending','{}',NOW() - INTERVAL 40 SECOND)"));
        $q("INSERT INTO integration_jobs (job_type, resource_type, status, payload, created_at) VALUES $vals");
    }
    echo json_encode(['queued' => $n]), "\n";
} else {
    fwrite(STDERR, "usage: housekeeping | checkin <n> | queue <n>\n");
    exit(2);
}
