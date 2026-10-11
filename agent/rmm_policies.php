<?php
/*
 * Agent policies: what a group of devices is told (check-in and collection intervals, three feature toggles, the update ring and check templates),
 * who it is assigned to, its versions, and an "explain a device" box. Everything is read and written through RivetCore's technician API as the
 * signed-in user (includes/rmm_pol_ui.php): administrators edit, everyone who can view devices reads.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_pol_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmAutoPage($mysqli, 'policies', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    return;
}
$vm = rivetRmmPolPoliciesView($ctx, $_GET);
echo rivetRmmPolPoliciesPage($vm, $ctx);

require_once "../includes/footer.php";
