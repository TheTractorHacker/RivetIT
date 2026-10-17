<?php
/*
 * Software assignments (RMM Phase 5): which package should be on which devices, rings, and requests that wait for a second person.
 * Needs the RMM module and the `software_deploy` sub-switch (Administration > Endpoint agent > Software deployment). Every read and write goes through RivetCore's technician API as the
 * signed-in user (includes/rmm_sw_ui.php), which applies the abilities and the department scope.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_sw_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmSwPage($mysqli, (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    return;
}
echo rivetRmmSwAssignmentsPage(rivetRmmSwAssignmentsView($ctx, $_GET));

require_once "../includes/footer.php";
