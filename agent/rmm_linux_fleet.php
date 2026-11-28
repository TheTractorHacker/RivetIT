<?php
/*
 * Linux fleet: read-only cluster health for Linux servers, one card per RMM group (up or down, patch state, failing checks, PostgreSQL, restart pending).
 * Needs the RMM module and the `patching` sub-switch. Reads go through RivetCore's technician API as the signed-in user (includes/rmm_linux_fleet.php), which applies the abilities and the department scope.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_pat_ui.php';
require_once dirname(__DIR__) . '/includes/rmm_linux_fleet.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmPatPage($mysqli, (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    return;
}
echo rivetLinuxFleetPage(rivetLinuxFleetView($ctx, $_GET));

require_once "../includes/footer.php";
