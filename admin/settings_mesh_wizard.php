<?php
/*
 * Administration > Remote access (MeshCentral) setup (RivetIT 26.10.54): the step-by-step page. Markup and state: includes/rmm_mesh_wizard.php. Saves go through
 * admin/post/settings_mesh_wizard.php (RivetCore RmmAdmin::saveMesh). Administrators only (inc_all_admin.php), like Administration > Endpoint agent.
 */
require_once "includes/inc_all_admin.php";

require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__) . '/includes/rmm_mesh_wizard.php';

mysqli_report(MYSQLI_REPORT_OFF);

$mw_ready = false;
$mw_res = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_mesh_settings'");
if ($mw_res && mysqli_num_rows($mw_res) > 0) {
    $mw_res = mysqli_query($mysqli, "SHOW COLUMNS FROM endpoint_agent_settings LIKE 'mesh_url'");
    $mw_ready = $mw_res && mysqli_num_rows($mw_res) > 0;
}
if (!$mw_ready) {
    echo '<div class="alert alert-warning">Run the database update first (Administration &rarr; Updates), then reload this page.</div>';
    require_once "../includes/footer.php";
    return;
}

$rmm = rivetRmmModule();
$mw_trust = isset($_SESSION['mw_trust']) && is_array($_SESSION['mw_trust']) ? $_SESSION['mw_trust'] : null;
$mw_probe = isset($_SESSION['mw_pin_probe']) && is_array($_SESSION['mw_pin_probe']) ? $_SESSION['mw_pin_probe'] : null;
$mw_keep = isset($_SESSION['mw_keep']) && is_array($_SESSION['mw_keep']) ? array_map('strval', array_filter($_SESSION['mw_keep'], 'is_scalar')) : [];
unset($_SESSION['mw_pin_probe'], $_SESSION['mw_keep']);   // shown once

$mw_state = rivetRmmMeshWizardState($rmm, $mysqli, $mw_trust);
echo rivetRmmMeshWizardPage($mw_state, (string) $_SESSION['csrf_token'], $mw_probe, $mw_keep);
echo '<script src="/js/rmm_mesh_wizard.js?v=' . (int) @filemtime(dirname(__DIR__) . '/js/rmm_mesh_wizard.js') . '" defer></script>';

require_once "../includes/footer.php";
