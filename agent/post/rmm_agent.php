<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Endpoint agent actions from the device page: submit / cancel a job, launch a remote session, map the MeshCentral node.
 * A direct JSON endpoint (like rmm_remote.php). Every action is authorized server-side by RivetCore\Rmm\Technician\TechnicianActions
 * (rivet/rivet-core), the same code the REST API uses; hiding a button is only cosmetic.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_device_actions.php';

use RivetCore\Rmm\Technician\ActionResult;

header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token', 'code' => 'csrf']);
    exit;
}

$uid = (int) $session_user_id;
$device_id = intval($_POST['device_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$out = static function (ActionResult $r): void {
    http_response_code($r->ok ? 200 : $r->http);
    $body = ['success' => $r->ok, 'code' => $r->code];
    $body[$r->ok ? 'message' : 'error'] = $r->message;
    foreach (['job_id', 'url', 'session_id'] as $k) { if (isset($r->data[$k])) { $body[$k] = $r->data[$k]; } }
    if (isset($r->data['tag']) && is_array($r->data['tag'])) { $body['tag'] = ['tag_id' => (int) ($r->data['tag']['tag_id'] ?? 0), 'name' => (string) ($r->data['tag']['name'] ?? '')]; }
    echo json_encode($body);
    exit;
};

$rmm = rivetRmmModule();
$tech = $rmm->technician();
$who = rivetRmmPrincipal($uid, (string) $session_name);

// RivetCore 1.0.0-rc.60: "Move to Department" (dialog data, then the move) and the bulk "managed by another RMM" switch. Each re-checks rmm.device.manage on the server
// (rivetRmmMoveDevice() / rivetRmmBulkManagedExternally() ask first, Core asks again per device); the CSRF token was checked above. JSON only; never HTML.
$json = static function (int $http, array $body): void {
    http_response_code($http);
    echo json_encode($body);
    exit;
};
$posted_ids = static function (string $key): array {
    $raw = $_POST[$key] ?? [];
    $ids = [];
    foreach (is_array($raw) ? $raw : [] as $v) {
        if (is_scalar($v) && ctype_digit(trim((string) $v))) {
            $ids[] = (int) trim((string) $v);
        }
    }

    return $ids;
};
switch ($action) {
    case 'move_options':
        $dev = $tech->visibleDevice($uid, $device_id);
        if ($dev === null) {
            $json(404, ['success' => false, 'code' => 'not_found', 'error' => 'Device not found.']);
        }
        if (!rivetRmmCanManageDevices($rmm, $uid, (int) $dev['client_id'])) {
            $json(403, ['success' => false, 'code' => 'forbidden', 'error' => 'You do not have permission to move computers out of this department.']);
        }
        $json(200, ['success' => true, 'current_client_id' => (int) $dev['client_id'], 'departments' => rivetRmmMoveTargets($mysqli, $rmm, $uid, (int) $dev['client_id'])]);
    case 'move_locations':
        $dev = $tech->visibleDevice($uid, $device_id);
        if ($dev === null || !rivetRmmCanManageDevices($rmm, $uid, (int) $dev['client_id'])) {
            $json(403, ['success' => false, 'code' => 'forbidden', 'error' => 'Not allowed.']);
        }
        $json(200, ['success' => true, 'locations' => rivetRmmMoveLocations($mysqli, $rmm, $uid, intval($_POST['client_id'] ?? 0))]);
    case 'move_department':
        $mv = rivetRmmMoveDevice($mysqli, $rmm, $who, $device_id, intval($_POST['client_id'] ?? 0), intval($_POST['location_id'] ?? 0));
        if (!$mv['ok']) {
            $json($mv['http'], ['success' => false, 'code' => $mv['code'], 'error' => $mv['message']]);
        }
        $to = (int) $mv['client_id'];
        $asset = (int) $mv['asset_id'];
        if ($asset > 0 && $to !== (int) $mv['from_client_id']) {
            // What Core does not know about: the asset's photo folder and the asset's own log line (the same two things the asset edit modal does when it moves an asset).
            if (function_exists('moveAssetPhotoToClient')) {
                moveAssetPhotoToClient($asset, (int) $mv['from_client_id'], $to);
            }
            $mv_name = sanitizeInput(getFieldById('assets', $asset, 'asset_name'));
            $mv_to = sanitizeInput(getFieldById('clients', $to, 'client_name'));
            logAction('Asset', 'Edit', "$session_name moved asset $mv_name to department $mv_to (Move to Department)", $to, $asset);
        }
        flash_alert(nullable_htmlentities($mv['message']));   // shown in the page's normal flash area after the reload
        $json(200, ['success' => true, 'code' => $mv['code'], 'message' => $mv['message'],
            'redirect' => $asset > 0 ? '/agent/asset_details.php?client_id=' . $to . '&asset_id=' . $asset . '#rmm-overview' : '/agent/rmm_agent_device.php?device_id=' . $device_id]);
    case 'bulk_managed_externally':
        $bm = rivetRmmBulkManagedExternally($mysqli, $rmm, $who, $posted_ids('asset_ids'), !empty($_POST['disabled']));
        $json($bm['http'], ['success' => $bm['ok'], 'code' => $bm['code'], ($bm['ok'] ? 'message' : 'error') => $bm['message'], 'counts' => $bm['counts'], 'changed' => $bm['changed'], 'unchanged' => $bm['unchanged'], 'skipped' => $bm['skipped']]);
}
switch ($action) {
    case 'submit_job':
        $in = [
            'type' => (string) ($_POST['type'] ?? ''),
            'script' => isset($_POST['script']) ? (string) $_POST['script'] : null,
            'script_id' => intval($_POST['script_id'] ?? 0),
            'timeout_s' => isset($_POST['timeout_s']) && $_POST['timeout_s'] !== '' ? intval($_POST['timeout_s']) : null,
            'destructive' => !empty($_POST['destructive']),
            'confirm' => !empty($_POST['confirm']),
            'params' => [],
        ];
        $out($tech->submitJob($who, $device_id, $in));
        // no break: exit above
    case 'cancel_job':
        $out($tech->cancelJob($who, $device_id, (string) ($_POST['job_id'] ?? '')));
    case 'remote':
        $out($tech->launchRemote($who, $device_id, !empty($_POST['force']), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    // RivetCore 1.0.0-rc.9 (RMM Phase 1): tags and the software refresh. InventoryActions authorizes each one itself (rmm.device.manage for tags, rmm.job.run_saved
    // for the refresh) with the same client scope as the REST API.
    case 'tag_add':
        $out($rmm->inventory()->tagDevice($who, $device_id, trim((string) ($_POST['tag'] ?? ''))));
    case 'tag_remove':
        $out($rmm->inventory()->untagDevice($who, $device_id, intval($_POST['tag_id'] ?? 0)));
    case 'software_refresh':
        $out($rmm->inventory()->refreshSoftware($who, $device_id));
    case 'set_mesh_node':
        $out($tech->setMeshNode($who, $device_id, (string) ($_POST['mesh_node_id'] ?? '')));
    // RivetCore 1.0.0-rc.31: a device another tool (a second RMM/patch agent) already manages - RivetCore's own
    // scheduled scripts and patch installs never fire on it. Monitoring, inventory, patch scanning and a
    // deliberate "run now" are unaffected.
    case 'set_automation_disabled':
        $out($tech->setAutomationDisabled($who, $device_id, !empty($_POST['disabled'])));
}
$out(ActionResult::fail(422, 'invalid', 'Unknown action.'));
