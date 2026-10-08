<?php
/*
 * Agent fleet: the RivetIT endpoint agent's devices at a glance. Counts by status, devices that went quiet, devices waiting for approval,
 * outdated agents and rings, recent job failures, the capacity panel (administrators), and a filtered, paginated device list.
 *
 * Read-only. The data is RivetCore\Rmm\Read\RmmReadModel (fleetCounts, listDevices, pendingApprovals, currentBinaries) and Capacity\CapacityReport,
 * through includes/rmm_ui.php; the user's departments scope every number. With the RMM module off the page is the standard "module off" notice,
 * built without asking the database anything.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_ui_render.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!rivetRmmEnabled($mysqli)) {
    itflow_render_denied('The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.', 'RMM module off');
    return;
}

$rmm_filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'ring' => (string) ($_GET['ring'] ?? ''),
    'page' => (int) ($_GET['page'] ?? 1),
];
if (!in_array($rmm_filters['status'], ['online', 'offline', 'stale', 'never', 'pending_approval', 'linked', 'rejected'], true)) {
    $rmm_filters['status'] = '';
}
if (!in_array($rmm_filters['ring'], ['stable', 'pilot'], true)) {
    $rmm_filters['ring'] = '';
}
$rmm_filters['q'] = mb_substr($rmm_filters['q'], 0, 100);

$rmm_fleet = rivetRmmUiFleet($mysqli, (int) $session_user_id, $rmm_filters);
if ($rmm_fleet === null) {
    itflow_render_denied('Your role cannot view agent devices.', 'No access');
    return;
}
echo rivetRmmUiFleetPage($rmm_fleet, (string) ($_SESSION['csrf_token'] ?? ''));

require_once "../includes/footer.php";
