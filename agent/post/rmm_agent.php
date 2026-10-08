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
    echo json_encode($body);
    exit;
};

$rmm = rivetRmmModule();
$tech = $rmm->technician();
$who = rivetRmmPrincipal($uid, (string) $session_name);

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
    case 'set_mesh_node':
        $out($tech->setMeshNode($who, $device_id, (string) ($_POST['mesh_node_id'] ?? '')));
}
$out(ActionResult::fail(422, 'invalid', 'Unknown action.'));
