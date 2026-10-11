<?php
/*
 * Script library: the list, and one script (editor, versions, differences, run panel, recent runs).
 * Needs the RMM module and the `scripts` sub-switch (Administration > Endpoint agent). Every read and write goes through RivetCore's technician API as the
 * signed-in user; see includes/rmm_scr_ui.php.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_scr_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$ctx = rivetRmmAutoPage($mysqli, 'scripts', (int) $session_user_id, (string) $session_name);   // renders the module-off / switch-off / no-access notice and returns null
if ($ctx === null) {
    return;
}
$rmm_auto_extra_js = ['rmm_scr.js'];
if (isset($_GET['script_id']) || isset($_GET['new'])) {
    echo rivetRmmScrScriptPage(rivetRmmScrScriptView($ctx, $_GET), $ctx);
} else {
    echo rivetRmmScrLibraryPage(rivetRmmScrLibraryView($ctx, $_GET), $ctx);
}

require_once "../includes/footer.php";
