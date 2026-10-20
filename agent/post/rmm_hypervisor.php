<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the hypervisor pages (RivetCore 1.0.0-rc.29): ignore_host / unignore_host, ignore_vm / unignore_vm. The prologue
 * (rivetRmmAutoPostBegin) checks POST, CSRF, the module and the `hypervisor` sub-switch; every action then calls RivetCore's technician API
 * as the signed-in user (endpoint_devices/hypervisor...), which needs rmm.hypervisor.manage and decides everything else.
 *
 * The on/off switch and the thresholds are NOT here: they live with every other RMM sub-switch, under Administration > Endpoint agent >
 * Hypervisors (admin/settings_endpoint_agent.php, save_hypervisor_settings in admin/post/settings_endpoint_agent.php), so an administrator
 * can always reach the switch even while it is off — a form gated behind rivetRmmAutoPostBegin('hypervisor', ...) never could be, since that
 * prologue itself refuses while the feature is off.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_hv_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'hypervisor', (int) $session_user_id, (string) $session_name);
$ret = $ctx['return'];
$action = rivetRmmAutoStr('action', 30);

if ($action === 'ignore_host' || $action === 'unignore_host') {
    $deviceId = (int) rivetRmmAutoStr('device_id', 20);
    if ($deviceId <= 0) {
        rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing device.'], '', $ret);
    }
    $r = rivetRmmAutoApi($mysqli, $ctx['uid'], $ctx['name'], 'PUT', [$deviceId, 'hypervisor'], [], ['ignored' => $action === 'ignore_host']);
    rivetRmmAutoDone($r, $action === 'ignore_host' ? 'This host is no longer monitored.' : 'This host is monitored again.', $ret);
} elseif ($action === 'ignore_vm' || $action === 'unignore_vm') {
    $vmId = (int) rivetRmmAutoStr('vm_id', 20);
    if ($vmId <= 0) {
        rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing VM.'], '', $ret);
    }
    $body = ['ignored' => $action === 'ignore_vm'];
    if ($action === 'ignore_vm') {
        $body['reason'] = rivetRmmAutoStr('reason', 200);
    }
    $r = rivetRmmAutoApi($mysqli, $ctx['uid'], $ctx['name'], 'PUT', ['hypervisor', 'vms', $vmId], [], $body);
    rivetRmmAutoDone($r, $action === 'ignore_vm' ? 'This VM is ignored.' : 'This VM is watched again.', $ret);
} else {
    rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ret);
}
