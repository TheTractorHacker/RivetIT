<?php
/*
 * Custom fields: typed facts per department, site or device that scripts can use as {{field.name}}. Definitions are administrators'; values follow
 * RivetCore's rules (device management for the department, administrators for secrets). Device values are edited on the asset page.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_pol_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmAutoPage($mysqli, 'scripts', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    return;
}
$vm = rivetRmmPolFieldsView($ctx, $_GET);
echo rivetRmmPolFieldsPage($vm, $ctx);

require_once "../includes/footer.php";
