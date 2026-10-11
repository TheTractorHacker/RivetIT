<?php
/*
 * Log rules (RMM Phase 8): phrase rules that raise an alert when collected log lines match often enough. Create, change, switch and delete are calls into RivetCore's
 * LogActions as the signed-in user (needs the alert-management ability); the rule text is plain, never a regular expression (includes/rmm_log_ui.php).
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_log_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmLogPage($mysqli, 'rules', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    return;
}
echo rivetRmmLogRulesPage(rivetRmmLogRulesView($ctx, $_GET));

require_once "../includes/footer.php";
