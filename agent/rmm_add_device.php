<?php
/*
 * Add device / Build installer (RivetIT 26.10.52): one page for the Windows .exe, the Windows .msi, the silent-install commands and the Linux command and packages.
 * Needs the RMM module and rmm.token.manage (or rmm.admin) like the Add device dialog; the page, the endpoint it calls (agent/post/rmm_installer.php) and RivetCore check it again.
 * Markup: includes/rmm_add_device_ui.php. Script: js/rmm_add_device.js.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_add_device_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!rivetRmmEnabled($mysqli)) {
    itflow_render_denied('The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.', 'RMM module off');
    return;
}
$ad_ctx = rivetRmmInstallerContext($mysqli, (int) $session_user_id, null, (int) ($_GET['client_id'] ?? 0));
if ($ad_ctx === null) {
    itflow_render_denied('Your role cannot create agent installers: it does not have the "RMM token manage" permission (rmm.token.manage). Ask an administrator to add it to your role.', 'No access');
    return;
}
echo rivetRmmAddDevicePage($ad_ctx, rivetAgentFeedLinuxAssets($mysqli));
echo '<script src="/js/rmm_add_device.js?v=' . (int) @filemtime(dirname(__DIR__) . '/js/rmm_add_device.js') . '" defer></script>';

require_once "../includes/footer.php";
