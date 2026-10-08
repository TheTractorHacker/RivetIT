<?php
/*
 * Output of one job, on demand (the asset page's Jobs tab loads it when "View output" is clicked, so the page itself never carries up to
 * 15 x 64 KiB of text). A read-only JSON endpoint on the session, like agent/post/rmm_agent.php.
 *
 *   GET /agent/rmm_job_output.php?device_id=ID&job_id=UUID
 *
 * Authorization is RivetCore's, the same as the REST API: the device must be visible to the user (outside their departments looks exactly like
 * missing: 404) and the role needs rmm.job.run_saved to read output (a view-only role sees state and exit code on the page, never output). The
 * module being off answers 404 without a query. The output was redacted and size-capped when it was stored; it is returned as stored and is
 * only ever shown as text.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
mysqli_report(MYSQLI_REPORT_OFF);

$fail = static function (int $code, string $error): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
};

if (!rivetRmmEnabled($mysqli)) {
    $fail(404, 'Not found.');
}
$uid = (int) $session_user_id;
$deviceId = intval($_GET['device_id'] ?? 0);
$jobId = (string) ($_GET['job_id'] ?? '');
if ($deviceId <= 0 || preg_match('/^[0-9a-fA-F-]{36}$/', $jobId) !== 1) {
    $fail(404, 'Not found.');
}
$res = rivetRmmModule($mysqli)->readModel()->job($deviceId, $jobId, rivetRmmPrincipal($uid, (string) ($session_name ?? '')));
if (!$res->ok) {
    $fail($res->http, $res->http === 404 ? 'Not found.' : $res->message);
}
$job = $res->data;
echo json_encode(['success' => true, 'job_id' => $job['job_id'], 'state' => $job['state'], 'exit_code' => $job['exit_code'],
    'output' => $job['output'], 'truncated' => (bool) $job['output_truncated']], JSON_INVALID_UTF8_SUBSTITUTE);
