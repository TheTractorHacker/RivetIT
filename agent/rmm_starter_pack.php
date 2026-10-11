<?php
/*
 * Starter pack (RivetCore 1.0.0-rc.13): browse the ready-made scripts, check template sets and policy drafts, plan, install and uninstall them. Administrators only.
 * Needs the RMM module and the `scripts` sub-switch (Administration > Endpoint agent), like the script library it fills. Every read and write goes through RivetCore's
 * technician API as the signed-in user (endpoint_devices/starter/*, rmm.admin); see includes/rmm_starter_ui.php and the handler agent/post/rmm_starter.php.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_starter_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmAutoPage($mysqli, 'scripts', (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    return;
}
if (!$ctx['perm']['admin']) {
    itflow_render_denied('The starter pack puts scripts into the library and policy drafts into the policy list, which only an RMM administrator may do.', 'Administrator access required');
    return;
}
$rmm_auto_extra_js = ['rmm_starter.js'];
echo rivetRmmStarterPage(rivetRmmStarterView($ctx, $_GET), $ctx);

require_once "../includes/footer.php";
