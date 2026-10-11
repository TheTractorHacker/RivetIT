<?php
/*
 * Logs (RMM Phase 8): search the event-log and system-log entries the RivetIT endpoint agent collected, across the devices the signed-in user may see (up to 7 days) or on
 * one device (up to 90 days). Shown only while the module and the `logs` sub-switch are on and the user may read logs; the search is a call into RivetCore's LogActions as the
 * signed-in user, which applies the department scope (includes/rmm_log_ui.php).
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_log_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmLogPage($mysqli, 'read', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    return;
}
echo rivetRmmLogPageHtml(rivetRmmLogPageView($ctx, $_GET));

require_once "../includes/footer.php";
