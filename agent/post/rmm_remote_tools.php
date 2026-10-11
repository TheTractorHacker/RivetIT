<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * The Remote tab of the asset page (RMM Phase 6, RivetCore 1.0.0-rc.11): services, processes, files and the MeshCentral terminal / files / desktop launch.
 * A direct JSON endpoint like agent/post/rmm_agent.php: one action per POST with the session CSRF token, answers {success, code, message|error, ...}.
 *
 * ONE AUTHORIZATION PATH. Every action is a call into RivetCore\Rmm\Remote\RemoteActions (the code behind the REST API) as the signed-in user: it checks the role,
 * the device's client scope (another client's device answers exactly like a missing one), the ability, the sub-switch and then validates against strict allow-lists.
 * This file never takes a client id from the request: the device is loaded with TechnicianActions::visibleDevice() and Core re-checks everything again. Reads that
 * Core does not tie to a switch (the latest list, a job result, the transfer ledger) are refused here with Core's own words while the switch is off.
 *
 * The one GET is `?action=download&device_id=&transfer_id=`: it streams a verified file the server already holds (audited by Core), changes nothing else, is
 * session-authenticated and ability-checked like everything else, and refuses a cross-site navigation.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_rem_ui.php';

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Remote\RemoteProtocol;
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
        if (isset($r->data[$k])) {
            $extra[$k] = $r->data[$k];
        }
    }
    $reply($r->ok ? 200 : $r->http, $r->ok, $r->code, $r->message, $extra);
};

$uid = (int) $session_user_id;
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

// ---- a POST whose body PHP threw away because it is larger than post_max_size arrives empty: say so politely instead of "invalid CSRF token"
if ($method === 'POST' && $_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $reply(413, false, 'too_large', 'That is larger than this server accepts in one request (post_max_size is ' . (string) ini_get('post_max_size') . '). Ask an administrator to raise it, or send a smaller file.');
}
if ($method === 'POST') {
    $tok = $_POST['csrf_token'] ?? null;
    $own = $_SESSION['csrf_token'] ?? null;
    if (!is_string($tok) || !is_string($own) || $own === '' || !hash_equals($own, $tok)) {
        $reply(403, false, 'csrf', 'Invalid CSRF token');
    }
    $in = $_POST;
} elseif ($method === 'GET' && (string) ($_GET['action'] ?? '') === 'download') {
    $in = $_GET;
    if (strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
        $reply(403, false, 'forbidden', 'This download can only be started from the device page.');
    }
} else {
    header('Allow: POST, GET');
    $reply(405, false, 'method', 'Use a POST with the CSRF token.');
}

$action = (string) ($in['action'] ?? '');
$deviceId = (int) ($in['device_id'] ?? 0);

if (!rivetRmmEnabled($mysqli)) {
    $reply(404, false, 'module_disabled', 'The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.');
}
$rmm = rivetRmmModule($mysqli);
$who = rivetRmmPrincipal($uid, (string) $session_name);
$actions = $rmm->remoteActions();

// ---- the device: visible to this user, or the same answer as a missing one
$dev = $deviceId > 0 ? $rmm->technician()->visibleDevice($uid, $deviceId) : null;
if ($dev === null) {
    $reply(404, false, 'not_found', 'Device not found.');
}
$client = (int) $dev['client_id'];
if ($dev['link_state'] !== 'linked' || $dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
    $reply(409, false, 'conflict', 'Only a device that is approved and not retired or revoked can be managed.');
}
$os = rivetRmmUiPlatform($dev) === 'windows' ? 'windows' : 'linux';

/** A read that Core does not tie to a switch: refuse with Core's words while the switch is off. */
$needFeature = static function (string $feature) use ($mysqli, $reply): void {
    if (!rivetRmmFeatureOn($feature, $mysqli)) {
        $reply(409, false, 'feature_disabled', rivetRmmRemOffText($feature));
    }
};
$uuid = static fn (mixed $v): string => is_string($v) && preg_match('/^[0-9a-fA-F-]{8,40}$/', $v) === 1 ? strtolower($v) : '';
$str = static fn (string $k, int $max = 1000): string => isset($in[$k]) && is_string($in[$k]) ? mb_substr($in[$k], 0, $max) : '';
$flag = static fn (string $k): bool => isset($in[$k]) && is_string($in[$k]) && in_array($in[$k], ['1', 'true', 'on'], true);

// ---- the stream of a finished download
if ($action === 'download') {
    $needFeature(RemoteProtocol::FEATURE_FILE_TRANSFER);
    $tid = $uuid($in['transfer_id'] ?? '');
    if ($tid === '') {
        $reply(404, false, 'not_found', 'Transfer not found.');
    }
    $r = $actions->downloadContent($who, $deviceId, $tid);
    if (!$r->ok) {
        $fromCore($r);
    }
    $d = $r->data;
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $d['name']) ?? '';
    $name = trim($name, '.') === '' ? 'download.bin' : $name;
    $fh = @fopen((string) $d['path'], 'rb');
    if ($fh === false) {
        $reply(409, false, 'conflict', 'This file is not available: it has not arrived, failed its checksum, was deleted or expired.');
    }
    session_write_close();
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . (int) $d['length']);
    header('X-Content-Type-Options: nosniff');
    header('X-Transfer-SHA256: ' . (string) $d['sha256']);
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    set_time_limit(0);
    fpassthru($fh);
    fclose($fh);
    exit;
}

switch ($action) {
    // ---------------------------------------------------------------- services
    case 'svc_refresh':
        $fromCore($actions->listServices($who, $deviceId), ['job_id']);
    case 'svc_snapshot':
    case 'proc_snapshot':
        $needFeature(RemoteProtocol::FEATURE_REMOTE_TOOLS);
        $svc = $action === 'svc_snapshot';
        $r = $actions->snapshot($who, $deviceId, $svc ? 'services' : 'processes');
        if (!$r->ok) {
            $fromCore($r);
        }
        $caps = rivetRmmRemCaps($rmm, $deviceId);
        $snap = $r->data;
        $state = (string) $snap['state'];
        $reply(200, true, 'ok', 'ok', ['state' => $state, 'job_id' => $snap['job_id'], 'final' => $state === 'none' || rivetRmmRemJobFinal($state),
            'summary' => rivetRmmRemSnapSummary($snap, $svc ? 'services' : 'processes'),
            'html' => $svc ? rivetRmmRemServicesBody($snap, $os, $caps['services']) : rivetRmmRemProcessesBody($snap, $caps['processes'])]);
    case 'svc_control':
        $fromCore($actions->controlService($who, $deviceId, $str('name', 300), $str('verb', 20), $flag('confirm')), ['job_id']);

    // ---------------------------------------------------------------- processes
    case 'proc_refresh':
        $limit = $str('limit', 6);
        $sort = $str('sort', 6);
        $fromCore($actions->listProcesses($who, $deviceId, $limit !== '' ? $limit : null, $sort !== '' ? $sort : null), ['job_id']);
    case 'proc_kill':
        $fromCore($actions->killProcess($who, $deviceId, $str('pid', 12), $str('name', 300), $flag('force'), $flag('confirm')), ['job_id']);

    // ---------------------------------------------------------------- the result of any queued remote job
    case 'job_result':
        $needFeature(RemoteProtocol::FEATURE_REMOTE_TOOLS);
        $jid = $uuid($in['job_id'] ?? '');
        if ($jid === '') {
            $reply(404, false, 'not_found', 'Job not found.');
        }
        $r = $actions->jobResult($who, $deviceId, $jid);
        if (!$r->ok) {
            $fromCore($r);
        }
        $reply(200, true, 'ok', 'ok', ['state' => (string) $r->data['state'], 'final' => rivetRmmRemJobFinal((string) $r->data['state']), 'html' => rivetRmmRemJobResultHtml($r->data)]);

    // ---------------------------------------------------------------- files
    case 'xfer_download':
        $fromCore($actions->requestDownload($who, $deviceId, $str('path', RemoteProtocol::TRANSFER_PATH_MAX + 50)), ['transfer_id', 'job_id']);
    case 'xfer_list':
        $needFeature(RemoteProtocol::FEATURE_FILE_TRANSFER);
        $r = $actions->listTransfers($who, $deviceId);
        if (!$r->ok) {
            $fromCore($r);
        }
        $items = (array) ($r->data['data'] ?? []);
        $names = rivetRmmRemUserNames($mysqli, array_column($items, 'created_by'));
        $brief = [];
        foreach ($items as $t) {
            $brief[] = ['transfer_id' => $t['transfer_id'], 'state' => $t['state'], 'direction' => $t['direction'], 'file_name' => $t['file_name'], 'size_bytes' => $t['size_bytes'], 'sha256' => $t['sha256'], 'error' => $t['error']];
        }
        $reply(200, true, 'ok', 'ok', ['pending' => rivetRmmRemXferPending($items), 'items' => $brief, 'html' => rivetRmmRemXferTable($items, $names, $deviceId)]);
    case 'xfer_delete':
        $needFeature(RemoteProtocol::FEATURE_FILE_TRANSFER);
        $tid = $uuid($in['transfer_id'] ?? '');
        if ($tid === '') {
            $reply(404, false, 'not_found', 'Transfer not found.');
        }
        $fromCore($actions->deleteTransfer($who, $deviceId, $tid));
    case 'xfer_upload':
        $needFeature(RemoteProtocol::FEATURE_FILE_TRANSFER);
        $path = $str('path', RemoteProtocol::TRANSFER_PATH_MAX + 50);
        // An administrator-only action: a viewer or technician gets Core's refusal (and Core's audit entry) before a byte of the file is looked at.
        if ($rmm->authorizer()->check($uid, RmmAbility::ADMIN, $client) !== null) {
            $fromCore($actions->createUpload($who, $deviceId, $path, 0, str_repeat('0', 64), false, false));
        }
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || !isset($f['error'], $f['tmp_name']) || is_array($f['error'])) {
            $reply(422, false, 'invalid', 'Choose the file to send.');
        }
        $why = [UPLOAD_ERR_INI_SIZE => 'The file is larger than this server\'s upload_max_filesize (' . (string) ini_get('upload_max_filesize') . '). Ask an administrator to raise it, or send a smaller file.',
            UPLOAD_ERR_FORM_SIZE => 'The file is too large.', UPLOAD_ERR_PARTIAL => 'The file arrived only in part. Try again.', UPLOAD_ERR_NO_FILE => 'Choose the file to send.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.', UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload.'];
        if ((int) $f['error'] !== UPLOAD_ERR_OK) {
            $reply((int) $f['error'] === UPLOAD_ERR_INI_SIZE ? 413 : 422, false, (int) $f['error'] === UPLOAD_ERR_INI_SIZE ? 'too_large' : 'invalid', $why[(int) $f['error']] ?? 'The upload failed.');
        }
        $tmp = (string) $f['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            $reply(422, false, 'invalid', 'Choose the file to send.');
        }
        $size = (int) filesize($tmp);
        $sha = (string) hash_file('sha256', $tmp);
        $r = $actions->createUpload($who, $deviceId, $path, $size, $sha, $flag('overwrite'), $flag('confirm'));
        if (!$r->ok) {
            $fromCore($r);
        }
        $tid = (string) $r->data['transfer_id'];
        $fh = fopen($tmp, 'rb');
        $r2 = $fh === false ? ActionResult::fail(500, 'error', 'The uploaded file could not be read.') : $actions->uploadContent($who, $deviceId, $tid, $fh);
        if (is_resource($fh)) {
            fclose($fh);
        }
        if (!$r2->ok) {
            $actions->deleteTransfer($who, $deviceId, $tid);   // do not leave a declared-but-empty transfer behind
            $fromCore($r2);
        }
        $reply(200, true, 'queued', $r2->message, ['transfer_id' => $tid, 'job_id' => $r2->data['job_id'] ?? null, 'sha256' => $sha, 'size_bytes' => $size]);

    // ---------------------------------------------------------------- MeshCentral
    case 'mesh':
        $view = $str('view', 12);
        $fromCore($rmm->technician()->launchRemote($who, $deviceId, $flag('force'), (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $view !== '' ? $view : 'desktop'), ['url', 'session_id']);
}
$reply(422, false, 'invalid', 'Unknown action.');
