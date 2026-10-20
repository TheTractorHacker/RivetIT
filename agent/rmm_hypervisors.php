<?php
/*
 * Hypervisor visibility (RivetCore 1.0.0-rc.29): the hosts list, one host with its storage and VM table, and one VM's facts/history/alerts.
 * Mode is picked by query params: ?host_id&vm_id -> the VM page, ?host_id alone -> the host page, else the hosts list. Every read goes
 * through RivetCore's technician API as the signed-in user; see includes/rmm_hv_ui.php. Not verified against a real Proxmox or Hyper-V host.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once dirname(__DIR__) . '/includes/rmm_hv_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

// rivetRmmAutoPage() already checked the module, the sub-switch and device-view; rmm.hypervisor.view is checked again by every REST call below
// (a role granted the module but not the hypervisor ability sees the module-level pages but gets a clean "not allowed" here instead of data).
$ctx = rivetRmmAutoPage($mysqli, 'hypervisor', (int) $session_user_id, (string) $session_name);
if ($ctx === null) {
    return;
}

$hostId = (int) ($_GET['host_id'] ?? 0);
$vmId = (int) ($_GET['vm_id'] ?? 0);

if ($hostId > 0 && $vmId > 0) {
    echo rivetRmmHvVmPage(rivetRmmHvVmView($ctx, $vmId), $ctx);
} elseif ($hostId > 0) {
    echo rivetRmmHvHostPage(rivetRmmHvHostView($ctx, $hostId, $_GET), $ctx);
} else {
    echo rivetRmmHvHostsPage(rivetRmmHvHostsView($ctx, $_GET), $ctx);
}

require_once "../includes/footer.php";
