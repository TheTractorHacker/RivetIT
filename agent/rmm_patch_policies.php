<?php
/*
 * Patch policies (RMM Phase 4): patch policies with their assignments, rings and approval rules.
 * Needs the RMM module and the `patching` sub-switch (Administration > Endpoint agent > Patch management). Every read and write goes through RivetCore's technician API as the
 * signed-in user (includes/rmm_pat_ui.php), which applies the abilities and the department scope.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_pat_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmPatPage($mysqli, (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    return;
}
echo rivetRmmPatPoliciesPage(rivetRmmPatPoliciesView($ctx, $_GET));

require_once "../includes/footer.php";
