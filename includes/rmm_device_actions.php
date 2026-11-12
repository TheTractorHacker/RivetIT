<?php

declare(strict_types=1);

/*
 * RivetCore 1.0.0-rc.60 edition glue (rc.59 features): "Move to Department", the bulk asset transfer's device path, the "Managed by another RMM" installer option
 * and the bulk "managed by another RMM" switch on the device list.
 *
 * Nothing here decides anything about security. Every decision (rmm.device.manage on the old and on the new department, the 500 device cap, which devices exist)
 * is RivetCore\Rmm\Technician\TechnicianActions; this file only maps the page's ids to device ids, asks the same question first so a hand-written request is refused
 * with a clear 403 (an administrator-only ability must never answer "0 changed" with a 200), and shapes the answer. No superglobals and no SQL that writes: the
 * functions take the connection, the module and the principal as arguments so a test can call them against a scratch database.
 */

require_once __DIR__ . '/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\RmmModule;

/** DeviceService::BULK_MAX: the most devices one bulk call changes. */
const RIVET_RMM_BULK_DEVICE_MAX = 500;

/** The label and help text of the installer option (RivetCore docs/modules/rmm.md, "Installer option: managed by another RMM"). */
const RIVET_RMM_MANAGED_EXTERNALLY_LABEL = 'Managed by another RMM';
const RIVET_RMM_MANAGED_EXTERNALLY_HELP = 'The computers this installer enrolls are already managed by another RMM or patch tool. RivetIT will monitor them and collect inventory, but will not run scheduled scripts, install patches or deploy software on them. You can change this per computer later.';

/** The plain-words confirmation of a move (the prompt's exact text). */
const RIVET_RMM_MOVE_CONFIRM_TEXT = 'The computer\'s agent and its credential stay the same. It keeps reporting without being reinstalled. Its open alerts, queued jobs, patch and software state and history move to the new department, and technicians of the old department will no longer see it.';

/** The confirmation of the bulk switch (turning it on). */
const RIVET_RMM_BULK_MANAGED_CONFIRM_ON = 'This stops scheduled scripts, patch installs and software deployment on those computers and cancels their queued automated jobs. Monitoring and inventory continue.';
const RIVET_RMM_BULK_MANAGED_CONFIRM_OFF = 'RivetIT automation (scheduled scripts, patch installs and software deployment) is switched back on for those computers.';

/**
 * Did the form tick "Managed by another RMM"? Only the exact value "1" counts, so a missing, empty or odd value is "off" (the default).
 *
 * @param array<string,mixed> $post the request body (passed in; this function reads no superglobal)
 */
function rivetRmmManagedExternallyPosted(array $post): bool
{
    return ($post['managed_externally'] ?? '') === '1';
}

/** The check box of the installer / token forms (label and help text escaped). $id is a fixed id prefix chosen by the caller. */
function rivetRmmManagedExternallyField(string $id): string
{
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? 'managed';

    return '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="' . $id . '" name="managed_externally" value="1" aria-describedby="' . $id . '-help">'
        . '<label class="form-check-label" for="' . $id . '">' . htmlspecialchars(RIVET_RMM_MANAGED_EXTERNALLY_LABEL, ENT_QUOTES, 'UTF-8') . '</label></div>'
        . '<div class="form-text" id="' . $id . '-help">' . htmlspecialchars(RIVET_RMM_MANAGED_EXTERNALLY_HELP, ENT_QUOTES, 'UTF-8') . '</div></div>';
}

/** May this user manage devices (rmm.device.manage) in the department? $clientId 0 asks "anywhere" (used only to decide whether to SHOW a control). */
function rivetRmmCanManageDevices(RmmModule $rmm, int $userId, int $clientId = 0): bool
{
    return $userId > 0 && $rmm->authorizer()->allowed($userId, RmmAbility::DEVICE_MANAGE, $clientId);
}

/**
 * The endpoint device behind an asset, or null (an asset with no endpoint device keeps the ordinary asset paths).
 *
 * @return array{device_id:int,client_id:int,asset_id:int}|null
 */
function rivetRmmDeviceOfAsset(\mysqli $mysqli, int $assetId): ?array
{
    if ($assetId <= 0) {
        return null;
    }
    $stmt = mysqli_prepare($mysqli, 'SELECT device_id, client_id, asset_id FROM endpoint_agent_devices WHERE asset_id = ? ORDER BY device_id LIMIT 1');
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $assetId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
    mysqli_stmt_close($stmt);

    return $row === null ? null : ['device_id' => (int) $row['device_id'], 'client_id' => (int) $row['client_id'], 'asset_id' => (int) $row['asset_id']];
}

/**
 * The departments this user may move devices to: live, non-lead departments where the user holds rmm.device.manage, minus the current one.
 *
 * @return list<array{id:int,name:string}>
 */
function rivetRmmMoveTargets(\mysqli $mysqli, RmmModule $rmm, int $userId, int $exceptClientId): array
{
    $out = [];
    $res = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL AND client_lead = 0 ORDER BY client_name ASC');
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $cid = (int) $row['client_id'];
        if ($cid > 0 && $cid !== $exceptClientId && rivetRmmCanManageDevices($rmm, $userId, $cid)) {
            $out[] = ['id' => $cid, 'name' => (string) $row['client_name']];
        }
    }

    return $out;
}

/**
 * The sites of a department, for the dialog's second picker. Empty when the user may not manage devices in that department (nothing is revealed).
 *
 * @return list<array{id:int,name:string}>
 */
function rivetRmmMoveLocations(\mysqli $mysqli, RmmModule $rmm, int $userId, int $clientId): array
{
    if ($clientId <= 0 || !rivetRmmCanManageDevices($rmm, $userId, $clientId)) {
        return [];
    }
    $out = [];
    $stmt = mysqli_prepare($mysqli, 'SELECT location_id, location_name FROM locations WHERE location_client_id = ? AND location_archived_at IS NULL ORDER BY location_name LIMIT 500');
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'i', $clientId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[] = ['id' => (int) $row['location_id'], 'name' => (string) $row['location_name']];
    }
    mysqli_stmt_close($stmt);

    return $out;
}

/**
 * Move one device (and, through Core, its asset) to another department with TechnicianActions::transfer(). Core checks rmm.device.manage on the old AND the new
 * department; this asks first so the answer is a plain refusal. A location that does not belong to the target department is refused, never ignored.
 *
 * @return array{ok:bool,http:int,code:string,message:string,device_id:int,client_id:int,asset_id:int,from_client_id:int}
 */
function rivetRmmMoveDevice(\mysqli $mysqli, RmmModule $rmm, RmmPrincipal $who, int $deviceId, int $clientId, int $locationId = 0): array
{
    $fail = static fn (int $http, string $code, string $message, int $from = 0, int $asset = 0): array => ['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message,
        'device_id' => $deviceId, 'client_id' => $clientId, 'asset_id' => $asset, 'from_client_id' => $from];
    $dev = $rmm->technician()->visibleDevice($who->userId, $deviceId);
    if ($dev === null) {
        return $fail(404, 'not_found', 'Device not found.');
    }
    $from = (int) $dev['client_id'];
    $asset = (int) ($dev['asset_id'] ?? 0);
    if (!rivetRmmCanManageDevices($rmm, $who->userId, $from)) {
        return $fail(403, 'forbidden', 'You do not have permission to move computers out of this department.', $from, $asset);
    }
    if ($clientId <= 0 || !rivetRmmCanManageDevices($rmm, $who->userId, $clientId)) {
        return $fail(403, 'forbidden', 'You do not have permission to move computers into that department.', $from, $asset);
    }
    if ($locationId > 0 && !in_array($locationId, array_column(rivetRmmMoveLocations($mysqli, $rmm, $who->userId, $clientId), 'id'), true)) {
        return $fail(422, 'invalid', 'Choose a site that belongs to the new department.', $from, $asset);
    }
    $r = $rmm->technician()->transfer($who, $deviceId, $clientId, $locationId);

    return ['ok' => $r->ok, 'http' => $r->ok ? 200 : $r->http, 'code' => $r->code, 'message' => $r->message, 'device_id' => $deviceId, 'client_id' => $clientId, 'asset_id' => $asset, 'from_client_id' => $from];
}

/**
 * Bulk asset transfer, device path: every selected asset that has an endpoint device goes through TechnicianActions::transfer(), one at a time, each ActionResult
 * kept. Assets WITHOUT a device are returned untouched in `without_device` so the caller's existing path (copy and archive) handles them as today. An asset whose
 * device is already in the target department is "moved" by Core as a no-op and counted as moved.
 *
 * @param list<int> $assetIds
 * @return array{moved:list<array{asset_id:int,device_id:int,from_client_id:int}>,refused:list<array{asset_id:int,device_id:int,reason:string}>,without_device:list<int>}
 */
function rivetRmmBulkTransferAssets(\mysqli $mysqli, RmmModule $rmm, RmmPrincipal $who, array $assetIds, int $clientId): array
{
    $out = ['moved' => [], 'refused' => [], 'without_device' => []];
    foreach (array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn (int $i): bool => $i > 0))) as $assetId) {
        $dev = rivetRmmDeviceOfAsset($mysqli, $assetId);
        if ($dev === null) {
            $out['without_device'][] = $assetId;
            continue;
        }
        $r = rivetRmmMoveDevice($mysqli, $rmm, $who, $dev['device_id'], $clientId, 0);
        if ($r['ok']) {
            $out['moved'][] = ['asset_id' => $assetId, 'device_id' => $dev['device_id'], 'from_client_id' => $r['from_client_id']];
        } else {
            $out['refused'][] = ['asset_id' => $assetId, 'device_id' => $dev['device_id'], 'reason' => $r['message']];
        }
    }

    return $out;
}

/**
 * The bulk "Mark / Clear managed by another RMM" on the device list, for the assets (rows) the page lists. rmm.device.manage is asked first (403 without it), then Core
 * decides per device. Assets with no endpoint device, assets that do not exist and devices the caller may not manage are all reported as `skipped`, in the same words,
 * so the answer never says which of those it was. More than 500 selected is refused (422), nothing changed.
 *
 * @param list<int> $assetIds
 * @return array{ok:bool,http:int,code:string,message:string,counts:array{changed:int,unchanged:int,skipped:int},changed:list<int>,unchanged:list<int>,skipped:list<int>}
 */
function rivetRmmBulkManagedExternally(\mysqli $mysqli, RmmModule $rmm, RmmPrincipal $who, array $assetIds, bool $disabled): array
{
    $none = ['changed' => 0, 'unchanged' => 0, 'skipped' => 0];
    $fail = static fn (int $http, string $code, string $message): array => ['ok' => false, 'http' => $http, 'code' => $code, 'message' => $message, 'counts' => $none, 'changed' => [], 'unchanged' => [], 'skipped' => []];
    if (!rivetRmmCanManageDevices($rmm, $who->userId, 0)) {
        return $fail(403, 'forbidden', 'You do not have permission to change this setting on computers.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn (int $i): bool => $i > 0)));
    if ($ids === []) {
        return $fail(422, 'invalid', 'Select at least one computer.');
    }
    if (count($ids) > RIVET_RMM_BULK_DEVICE_MAX) {
        return $fail(422, 'too_many', 'Change at most ' . RIVET_RMM_BULK_DEVICE_MAX . ' computers at a time.');
    }
    $deviceOfAsset = [];
    $skipped = [];
    foreach ($ids as $assetId) {
        $dev = rivetRmmDeviceOfAsset($mysqli, $assetId);
        if ($dev === null) {
            $skipped[] = $assetId;
        } else {
            $deviceOfAsset[$assetId] = $dev['device_id'];
        }
    }
    $r = $rmm->technician()->setAutomationDisabledBulk($who, array_values($deviceOfAsset), $disabled);
    if (!$r->ok) {
        return $fail($r->http, $r->code, $r->message);
    }
    $assetOfDevice = array_flip($deviceOfAsset);
    $toAssets = static fn (array $deviceIds): array => array_values(array_map(static fn ($d): int => $assetOfDevice[(int) $d] ?? 0, $deviceIds));
    $changed = $toAssets((array) ($r->data['changed'] ?? []));
    $unchanged = $toAssets((array) ($r->data['unchanged'] ?? []));
    $skipped = array_merge($skipped, $toAssets((array) ($r->data['skipped'] ?? [])));
    sort($skipped);
    $counts = ['changed' => count($changed), 'unchanged' => count($unchanged), 'skipped' => count($skipped)];

    return ['ok' => true, 'http' => 200, 'code' => 'ok',
        'message' => $counts['changed'] . ' changed, ' . $counts['unchanged'] . ' already set, ' . $counts['skipped'] . ' skipped.',
        'counts' => $counts, 'changed' => $changed, 'unchanged' => $unchanged, 'skipped' => $skipped];
}

/**
 * The "Move to Department" button (opens js/rmm_device_move.js's dialog). Cosmetic: the button is only drawn for a user who holds rmm.device.manage, and the server
 * re-checks on every call. $csrf may be '' when the surrounding element (the RMM panel, a form) already carries the token.
 */
function rivetRmmMoveButton(int $deviceId, string $deviceName, string $csrf = '', string $class = 'btn btn-outline-primary btn-sm'): string
{
    $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    return '<button type="button" class="' . $e($class) . '" data-rmm-move="1" data-device-id="' . $deviceId . '" data-device-name="' . $e($deviceName) . '"'
        . ($csrf !== '' ? ' data-csrf="' . $e($csrf) . '"' : '') . ' data-post-url="/agent/post/rmm_agent.php" data-confirm-text="' . $e(RIVET_RMM_MOVE_CONFIRM_TEXT) . '">'
        . '<i class="fas fa-fw fa-exchange-alt me-1" aria-hidden="true"></i>Move to Department</button>';
}
