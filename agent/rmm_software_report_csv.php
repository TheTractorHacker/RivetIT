<?php
/*
 * CSV of the software deployment report (RMM Phase 5): the same filters as agent/rmm_software_report.php, answered by RivetCore's technician API as the signed-in user
 * (`endpoint_devices/software_report/csv`), so the abilities, the department scope and the audit entry are Core's. A read-only GET on the session, like agent/rmm_job_output.php.
 *
 *   GET /agent/rmm_software_report_csv.php?group_by=package|client|ring|device[&client_id=ID][&package_id=ID][&ring=NAME][&state=STATE]
 *
 * The file is RFC 4180 with CRLF; a cell that starts with = + - @ tab or CR carries a leading apostrophe (Core's DeployReader), because host names and package names come from devices
 * and administrators. It is sent as an attachment with nosniff and never cached.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_sw_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$fail = static function (int $code, string $text): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    $fail(405, 'GET only.');
}
if (!rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('software_deploy', $mysqli)) {
    $fail(404, 'Not found.');
}
$f = rivetRmmSwReportFilters($_GET);
$r = rivetRmmSwRaw($mysqli, (int) $session_user_id, (string) ($session_name ?? ''), ['software_report', 'csv'], rivetRmmSwReportQuery($f, false));
if ($r['status'] !== 200) {
    $j = json_decode($r['body'], true);
    $fail(in_array($r['status'], [403, 404], true) ? $r['status'] : 502, is_array($j) && isset($j['error']) ? (string) $j['error'] : 'The report could not be exported.');
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="software-deployment-' . $f['group_by'] . '.csv"');
echo $r['body'] === '' ? "No rows\r\n" : $r['body'];
