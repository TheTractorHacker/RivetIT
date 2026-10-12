<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Push the MeshCentral agent to enrolled devices (RivetCore 1.0.0-rc.16). A direct JSON endpoint like agent/post/rmm_remote_tools.php: one action per POST with the
 * session CSRF token, answers {success, code, message|error, ...}.
 *
 *   install       one device: RemoteActions::installMeshAgent(who, device, force). The request carries nothing but `force`; the Mesh address, the device group id, the pin and
 *                 the tables are read from the stored settings by Core when the job is created. Idempotent: code already_installed / already_queued, nothing queued.
 *   uninstall     one device: RemoteActions::uninstallMeshAgent(who, device, confirm). Destructive; needs confirm=1.
 *   job_result    the outcome of one mesh_install / mesh_uninstall job (RemoteActions::jobResult), as an escaped HTML fragment.
 *   bulk          many devices: RemoteActions::installMeshAgentBulk(who, {target, force, confirm}). The first call (no confirm) answers 422 confirmation_required with the
 *                 device_count Core would reach; the confirmed call answers the counts of what was queued and what was skipped. Above the approval threshold Core REFUSES
 *                 (409 approval_required) and its text is shown as it is.
 *
 * ONE AUTHORIZATION PATH. Nothing here decides anything: the device is loaded with TechnicianActions::visibleDevice() (another client's device is the same 404 as a missing
 * one) and Core re-checks the role, the client scope, rmm.remote.manage, the `remote_tools` switch, the Mesh settings and the agent's capability for every request.
 * This file never takes a client id from the request.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_mesh_push.php';

use RivetCore\Rmm\Technician\ActionResult;

mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Answer and stop. `$extra` is merged into the body. */
$reply = static function (int $http, bool $ok, string $code, string $text, array $extra = []): never {
    http_response_code($http);
    echo json_encode(['success' => $ok, 'code' => $code, ($ok ? 'message' : 'error') => $text] + $extra, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
};
/** Answer with a Core ActionResult; `$pick` names the data keys that go to the browser. */
$fromCore = static function (ActionResult $r, array $pick = []) use ($reply): never {
    $extra = [];
    foreach ($pick as $k) {
        if (array_key_exists($k, $r->data)) {
            $extra[$k] = $r->data[$k];
        }
    }
    $reply($r->ok ? 200 : $r->http, $r->ok, $r->code, $r->message, $extra);
};

$uid = (int) $session_user_id;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $reply(405, false, 'method', 'Use a POST with the CSRF token.');
}
$tok = $_POST['csrf_token'] ?? null;
$own = $_SESSION['csrf_token'] ?? null;
if (!is_string($tok) || !is_string($own) || $own === '' || !hash_equals($own, $tok)) {
    $reply(403, false, 'csrf', 'Invalid CSRF token');
}
$in = $_POST;
$action = (string) ($in['action'] ?? '');
$flag = static fn (string $k): bool => isset($in[$k]) && is_string($in[$k]) && in_array($in[$k], ['1', 'true', 'on'], true);

if (!rivetRmmEnabled($mysqli)) {
    $reply(404, false, 'module_disabled', 'The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.');
}
$rmm = rivetRmmModule($mysqli);
$who = rivetRmmPrincipal($uid, (string) $session_name);
$actions = $rmm->remoteActions();

// ---- many devices: no device id, Core scopes the target to the caller's departments
if ($action === 'bulk') {
    $type = (string) ($in['target_type'] ?? '');
    if (!in_array($type, ['all', 'client', 'tag', 'group'], true)) {
        $reply(422, false, 'invalid', 'Choose a department, a tag, a group or everything.');
    }
    $target = ['type' => $type] + ($type === 'all' ? [] : ['id' => (int) ($in['target_id'] ?? 0)]);
    $r = $actions->installMeshAgentBulk($who, ['target' => $target, 'force' => $flag('force'), 'confirm' => $flag('confirm')]);
    $fromCore($r, ['device_count', 'created', 'skipped_denied', 'skipped_installed', 'skipped_queued', 'skipped_unsupported', 'skipped_error', 'errors']);
}

// ---- one device: visible to this user, or the same answer as a missing one
$deviceId = (int) ($in['device_id'] ?? 0);
$dev = $deviceId > 0 ? $rmm->technician()->visibleDevice($uid, $deviceId) : null;
if ($dev === null) {
    $reply(404, false, 'not_found', 'Device not found.');
}
if ($dev['link_state'] !== 'linked' || $dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
    $reply(409, false, 'conflict', 'Only a device that is approved and not retired or revoked can be managed.');
}

switch ($action) {
    case 'install':
        $fromCore($actions->installMeshAgent($who, $deviceId, $flag('force')), ['job_id', 'skipped', 'node_id']);
    case 'uninstall':
        $fromCore($actions->uninstallMeshAgent($who, $deviceId, $flag('confirm')), ['job_id']);
    case 'job_result':
        if (!rivetRmmFeatureOn(\RivetCore\Rmm\Remote\RemoteProtocol::FEATURE_REMOTE_TOOLS, $mysqli)) {
            $reply(409, false, 'feature_disabled', 'Remote tools are switched off. An administrator can turn them on under Administration > Endpoint agent.');
        }
        $jid = isset($in['job_id']) && is_string($in['job_id']) && preg_match('/^[0-9a-fA-F-]{8,40}$/', $in['job_id']) === 1 ? strtolower($in['job_id']) : '';
        if ($jid === '') {
            $reply(404, false, 'not_found', 'Job not found.');
        }
        $r = $actions->jobResult($who, $deviceId, $jid);
        if (!$r->ok) {
            $fromCore($r);
        }
        if (!rivetRmmMeshPushIsJob((string) $r->data['type'])) {
            $reply(404, false, 'not_found', 'Job not found.');   // this endpoint reads only the Mesh agent jobs; the Remote tab reads the rest
        }
        $state = (string) $r->data['state'];
        $reply(200, true, 'ok', 'ok', ['state' => $state, 'final' => in_array($state, ['succeeded', 'failed', 'timed_out', 'cancelled', 'expired'], true), 'html' => rivetRmmMeshPushResultHtml($r->data)]);
}
$reply(422, false, 'invalid', 'Unknown action.');
