<?php
/*
 * Agent alerts (RMM Phase 3): the alerts of the RivetIT endpoint agent with state, severity, escalation and ticket, a grouped view, and acknowledge / resolve /
 * create-ticket for people allowed to manage alerts. Reads and writes go through RivetCore's technician API as the signed-in user (includes/rmm_alr_ui.php).
 * The list now lives in agent/alerts.php (this file redirects plain list requests there and keeps the detail / device / check drill-downs).
 */

// A plain list request (state / severity / department / view / page only) now lives in the unified Alerts page; drill-downs (one alert, a device, a check, a group)
// keep this page, which reads RivetCore's alert API directly. Query is carried over: state active|open -> new, acknowledged, resolved; crit/warn -> critical/warning; view -> group.
$drill = array_intersect(array_keys($_GET), ['alert_id', 'device_id', 'check_key', 'group_key']);
if (!$drill && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $st = ['active' => 'active', 'open' => 'new', 'acknowledged' => 'acknowledged', 'resolved' => 'resolved'][(string) ($_GET['state'] ?? 'active')] ?? 'active';
    $q = ['source' => 'agent', 'status' => $st];
    if (isset($_GET['severity']) && in_array($_GET['severity'], ['crit', 'warn'], true)) { $q['severity'] = $_GET['severity'] === 'crit' ? 'critical' : 'warning'; }
    if ((int) ($_GET['client_id'] ?? 0) > 0) { $q['client_id'] = (int) $_GET['client_id']; }
    if (in_array($_GET['view'] ?? '', ['device', 'check'], true)) { $q['group'] = $_GET['view']; }
    if ((int) ($_GET['page'] ?? 0) > 1) { $q['page'] = (int) $_GET['page']; }
    header('Location: /agent/alerts.php?' . http_build_query($q), true, 302);
    exit;
}

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_alr_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmAutoPage($mysqli, 'alerting', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    return;
}
$rmm_auto_extra_js = ['rmm_alr.js'];
$vm = rivetRmmAlrAlertsView($ctx, $_GET);
echo rivetRmmAlrAlertsPage($vm);

require_once "../includes/footer.php";
