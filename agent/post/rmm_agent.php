<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Endpoint agent actions from the device page: submit / cancel a job, launch a remote session, map the MeshCentral node.
 * A direct JSON endpoint (like rmm_remote.php). Every action is authorized server-side by ITFlow\EndpointAgent\Actions /
 * Authz, the same code the REST API uses; hiding a button is only cosmetic.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

use ITFlow\EndpointAgent\Actions;
use ITFlow\EndpointAgent\Authz;
use ITFlow\EndpointAgent\Devices;

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
$out = static function (array $r): void {
    http_response_code($r['ok'] ? 200 : $r['http']);
    $body = ['success' => $r['ok'], 'code' => $r['code']];
    $body[$r['ok'] ? 'message' : 'error'] = $r['message'];
    foreach (['job_id', 'url', 'session_id'] as $k) { if (isset($r[$k])) { $body[$k] = $r[$k]; } }
    echo json_encode($body);
    exit;
};

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
        $out(Actions::submitJob($uid, (string) $session_name, $device_id, $in));
        // no break: exit above
    case 'cancel_job':
        $out(Actions::cancelJob($uid, (string) $session_name, $device_id, (string) ($_POST['job_id'] ?? '')));
    case 'remote':
        $out(Actions::launchRemote($uid, (string) $session_name, $device_id, !empty($_POST['force'])));
    case 'set_mesh_node':
        $dev = Devices::find($device_id);
        if (!$dev || Authz::check($uid, Authz::ADMIN, (int) $dev['client_id']) !== null) {
            $out(['ok' => false, 'http' => 403, 'code' => 'forbidden', 'message' => 'Administrator access is required.']);
        }
        $node = trim((string) ($_POST['mesh_node_id'] ?? ''));
        if (!Devices::setMeshNode($device_id, $node, $uid)) {
            $out(['ok' => false, 'http' => 422, 'code' => 'invalid', 'message' => 'That does not look like a MeshCentral node id (node//...).']);
        }
        logAction('Endpoint Agent', 'Mesh Node Mapped', "$session_name " . ($node === '' ? 'cleared' : 'set') . " the MeshCentral node for device $device_id", (int) $dev['client_id'], (int) $dev['asset_id']);
        $out(['ok' => true, 'http' => 200, 'code' => 'ok', 'message' => 'Saved.']);
}
$out(['ok' => false, 'http' => 422, 'code' => 'invalid', 'message' => 'Unknown action.']);
