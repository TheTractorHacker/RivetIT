<?php

/*
 * RMM Phase 6 (RivetCore 1.0.0-rc.11): the Remote tab of the asset page. Services, processes, files and the MeshCentral terminal / files / desktop buttons.
 *
 * ONE AUTHORIZATION PATH. Nothing here decides anything. Every read and every write is a call into RivetCore\Rmm\Remote\RemoteActions as the signed-in user
 * (the code behind the REST API's `endpoint_devices/<id>/remote/...` routes), which checks the role, the device's client scope (another client's device is the same
 * 404 as a missing one), the ability (`rmm.remote.manage`; `rmm.admin` for sending a file to a device), the sub-switch and then validates against strict
 * allow-lists. Hiding a button is cosmetic; agent/post/rmm_remote_tools.php re-checks everything for every request.
 *
 * WHEN THE TAB EXISTS. rivetRmmRemPanelSection() returns '' (so neither the tab nor the pane is drawn) unless the module is on, the `remote_tools` sub-switch is on,
 * the device is approved and not retired or revoked, and the viewer holds `rmm.remote.manage` for the device's client. Inside the tab the Files card follows the
 * `filetransfer` switch (off: an explanation with the verbatim risk text, nothing to click) and each part follows what the agent announced
 * (`remote:services`, `remote:processes`, `remote:files`; Core answers `unsupported` for the rest).
 *
 * Every renderer returns a string and escapes device-supplied text at the point it is printed (rmmH()). The same renderers produce the first paint and the
 * fragments the handler returns to js/rmm_remote.js, so there is one place that knows the markup. No secret ever appears here: the transfer token and the Mesh login
 * token live in jobs and URLs this file never prints (the Mesh launch URL is returned to the browser by the handler once and opened, never rendered into a page).
 */

require_once __DIR__ . '/rmm_ui_render.php';
require_once __DIR__ . '/rmm_mesh_push.php';   // rc.16: the Install / Remove MeshCentral agent buttons and a mesh job's result

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Device\DeviceState;
use RivetCore\Rmm\Remote\RemoteActions;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\Remote\RemoteSettings;

const RIVET_RMM_REM_HANDLER = '/agent/post/rmm_remote_tools.php';
/** Rows drawn per list (Core already caps a process list at 500; a service list has no cap of its own). */
const RIVET_RMM_REM_MAX_ROWS = 1500;
/** Job and transfer states that are final. */
const RIVET_RMM_REM_JOB_FINAL = ['succeeded', 'failed', 'timed_out', 'cancelled', 'expired'];
const RIVET_RMM_REM_XFER_PENDING = ['awaiting_content', 'queued', 'receiving', 'delivering'];

// ------------------------------------------------------------------ small helpers

/** The refusal text of a switched-off part (the same words Core uses when it answers `feature_disabled`). */
function rivetRmmRemOffText(string $feature): string
{
    return match ($feature) {
        RemoteProtocol::FEATURE_FILE_TRANSFER => 'File transfer is switched off. An administrator can turn it on in the RMM remote-tools settings after reading what it allows.',
        default => 'The remote tools (service and process managers, Mesh terminal and files) are switched off. An administrator can turn them on in the RMM remote-tools settings.',
    };
}

/** A php.ini size ("2M", "512K", "1G", "0" or "-1" for no limit) in bytes; null for unlimited. */
function rivetRmmRemIniBytes(string $key): ?int
{
    $v = trim((string) ini_get($key));
    if ($v === '' || $v === '-1' || $v === '0') {
        return null;
    }
    $n = (float) $v;
    $u = strtolower(substr($v, -1));
    $n *= match ($u) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };

    return (int) $n;
}

/**
 * The largest file an upload can be right now: the administrator's `transfer_max_bytes`, PHP's `upload_max_filesize` and `post_max_size` (less a little for the
 * other form fields). Returns the figure and the setting that limits it.
 *
 * @return array{bytes:int,by:string}
 */
function rivetRmmRemUploadCap(int $settingBytes): array
{
    $best = ['bytes' => $settingBytes, 'by' => 'the transfer size limit set by an administrator'];
    $up = rivetRmmRemIniBytes('upload_max_filesize');
    if ($up !== null && $up < $best['bytes']) {
        $best = ['bytes' => $up, 'by' => 'the server\'s upload_max_filesize'];
    }
    $post = rivetRmmRemIniBytes('post_max_size');
    if ($post !== null && $post - 65536 < $best['bytes']) {
        $best = ['bytes' => max(0, $post - 65536), 'by' => 'the server\'s post_max_size'];
    }

    return $best;
}

/** @return array<int,string> user_id => name */
function rivetRmmRemUserNames(\mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    $out = [];
    if ($ids === []) {
        return $out;
    }
    $r = mysqli_query($mysqli, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['user_id']] = (string) $row['user_name'];
    }

    return $out;
}

/** What the agent announced: services, processes, files (each true only when the flag is in its last check-in). */
function rivetRmmRemCaps(\RivetCore\Rmm\RmmModule $rmm, int $deviceId): array
{
    $caps = DeviceState::capabilitiesOf($rmm->deviceState()->get($deviceId));

    return ['services' => in_array(RemoteProtocol::CAP_SERVICES, $caps, true), 'processes' => in_array(RemoteProtocol::CAP_PROCESSES, $caps, true),
        'files' => in_array(RemoteProtocol::CAP_FILES, $caps, true), 'any' => $caps !== []];
}

/** Whether a job state is final. */
function rivetRmmRemJobFinal(string $state): bool
{
    return in_array($state, RIVET_RMM_REM_JOB_FINAL, true);
}

function rivetRmmRemStatePill(string $state): string
{
    $map = ['queued' => ['info', 'Queued, waiting for the device'], 'running' => ['info', 'Running on the device'], 'succeeded' => ['ok', 'Done'], 'failed' => ['crit', 'Failed'],
        'timed_out' => ['crit', 'Timed out'], 'cancelled' => ['off', 'Cancelled'], 'expired' => ['off', 'Expired before it ran'], 'none' => ['off', 'Not requested yet']];
    [$kind, $text] = $map[$state] ?? ['off', ucfirst(str_replace('_', ' ', $state))];

    return rivetRmmUiPill($kind, $text);
}

/** A short, plain-language explanation of the stable `detail` codes an agent reports (anything else is printed as reported, escaped). */
function rivetRmmRemDetailText(string $detail): string
{
    return match ($detail) {
        'refused_self' => 'The agent refused: this is the agent itself (or its parent), and ending it would cut the device off from management.',
        'refused_protected' => 'The agent refused: this is a protected system service or process.',
        'name_mismatch' => 'Nothing was ended: the process with that id has a different name now (the id was reused). Refresh the list and try again.',
        'not_found' => 'The device did not find it (it may have just stopped).',
        default => $detail,
    };
}

/** The reason of an ended job, in words. */
function rivetRmmRemReasonText(?string $reason): string
{
    return match ((string) $reason) {
        'result_lost' => 'The result never arrived. The action may or may not have happened: refresh the list to see the device\'s real state.',
        'never_started' => 'The device never started it.',
        'no_result_by_deadline' => 'The device did not report a result in time.',
        'expired_before_run' => 'The device did not check in before the job expired, so it never ran.',
        'device_retired' => 'The device was retired.',
        'cancelled_by_user' => 'It was cancelled.',
        '' => '',
        default => (string) $reason,
    };
}

// ------------------------------------------------------------------ the view

/**
 * Everything the Remote tab needs, or null when there is nothing to show (module or switch off, device not usable, viewer without the ability, tables missing).
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 * @return array<string,mixed>|null
 */
function rivetRmmRemPanelView(array $vm, \mysqli $mysqli): ?array
{
    if (!rivetRmmFeatureOn(RemoteProtocol::FEATURE_REMOTE_TOOLS, $mysqli) || empty($vm['usable']) || !empty($vm['revoked']) || !empty($vm['retired'])) {
        return null;
    }
    $uid = (int) $vm['user_id'];
    $client = (int) $vm['client_id'];
    $devId = (int) $vm['device_id'];
    try {
        $rmm = rivetRmmModule($mysqli);
        $authz = $rmm->authorizer();
        if (!$authz->allowed($uid, RmmAbility::REMOTE_MANAGE, $client)) {
            return null;
        }
        $who = rivetRmmPrincipal($uid, (string) ($GLOBALS['session_name'] ?? ''));
        $actions = $rmm->remoteActions();
        $caps = rivetRmmRemCaps($rmm, $devId);
        $snap = static function (string $kind) use ($actions, $who, $devId): array {
            $r = $actions->snapshot($who, $devId, $kind);

            return $r->ok ? $r->data : ['job_id' => null, 'state' => 'none', 'data' => null, 'error' => $r->message];
        };
        $mesh = $actions->meshInfo($who, $devId);
        $filesOn = rivetRmmFeatureOn(RemoteProtocol::FEATURE_FILE_TRANSFER, $mysqli);
        $os = $vm['platform'] === 'windows' ? 'windows' : 'linux';
        $limits = $rmm->remoteSettings()->limits();
        $view = [
            'device_id' => $devId, 'asset_id' => (int) $vm['asset_id'], 'hostname' => (string) $vm['hostname'], 'platform' => (string) $vm['platform'], 'os' => $os, 'offline' => (bool) $vm['offline'],
            'state' => (string) $vm['state'], 'caps' => $caps, 'mesh_push' => rivetRmmMeshPushState($vm), 'mesh_agent' => $vm['mesh_agent'] ?? null, 'mesh_agent_conflict_url' => $vm['mesh_agent_conflict_url'] ?? null, 'admin' => $authz->allowed($uid, RmmAbility::ADMIN, $client), 'mesh' => $mesh->ok ? $mesh->data : ['configured' => false, 'mapped' => false, 'views' => []],
            'retention_days' => (int) ($rmm->settings()->get()['job_retention_days'] ?? 30), 'services' => $snap('services'), 'processes' => $snap('processes'),
            'files_on' => $filesOn, 'roots' => $filesOn ? $rmm->remoteSettings()->allowRootsFor($os) : [], 'limits' => $limits, 'transfers' => [], 'transfer_error' => null, 'names' => [],
            'upload_cap' => rivetRmmRemUploadCap((int) $limits['transfer_max_bytes']),
        ];
        if ($filesOn) {
            $t = $actions->listTransfers($who, $devId);
            $view['transfers'] = $t->ok ? (array) ($t->data['data'] ?? []) : [];
            $view['transfer_error'] = $rmm->transfers()->unavailable();
            $view['names'] = rivetRmmRemUserNames($mysqli, array_column($view['transfers'], 'created_by'));
        }

        return $view;
    } catch (\Throwable) {
        return null;   // the Phase 6 tables are not there yet (migration not applied): no tab
    }
}

/** The hook rivetRmmUiTabs() calls: '' when there is nothing to show. */
function rivetRmmRemPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    $v = rivetRmmRemPanelView($vm, $mysqli);
    if ($v === null) {
        return '';
    }
    $v['csrf'] = $csrf;
    $GLOBALS['rmm_rem_scripts'] = true;   // includes/footer.php links js/rmm_remote.js only when this is set

    return rivetRmmRemPanelHtml($v);
}

// ------------------------------------------------------------------ service list

/**
 * The service table (or the reason there is none). `$snap` is RemoteActions::snapshot()'s data.
 *
 * @param array<string,mixed> $snap
 */
function rivetRmmRemServicesBody(array $snap, string $os, bool $supported): string
{
    $state = (string) ($snap['state'] ?? 'none');
    if (!$supported) {
        return '<p class="text-muted mb-0">This device\'s agent did not announce the service manager, so it cannot list or control services. An agent update adds it.</p>';
    }
    if ($state === 'none') {
        return rivetRmmUiEmpty('fas fa-cogs', 'No list yet', 'Press "Refresh list". The device sends its services the next time it checks in.');
    }
    if (!rivetRmmRemJobFinal($state)) {
        return '<p class="mb-0" role="status">' . rivetRmmRemStatePill($state) . ' <span class="text-muted ms-1">Asked the device for its services. The list appears here when it answers.</span></p>';
    }
    $data = $snap['data'] ?? null;
    if ($state !== 'succeeded' || !is_array($data)) {
        return '<div class="alert alert-warning mb-0" role="status">' . rmmH((string) ($snap['error'] ?? '') !== '' ? $snap['error'] : 'The list did not arrive.') . ' Press "Refresh list" to try again.</div>';
    }
    $items = array_values(array_filter((array) ($data['services'] ?? []), 'is_array'));
    if ($items === []) {
        return rivetRmmUiEmpty('fas fa-cogs', 'The device reported no services', 'The list is empty.');
    }
    $stateKind = ['running' => 'ok', 'stopped' => 'off', 'failed' => 'crit', 'starting' => 'warn', 'stopping' => 'warn', 'paused' => 'warn', 'unknown' => 'off'];
    $rows = '';
    $n = 0;
    foreach ($items as $s) {
        if (++$n > RIVET_RMM_REM_MAX_ROWS) {
            break;
        }
        $name = (string) ($s['name'] ?? '');
        $display = (string) ($s['display'] ?? '');
        $st = (string) ($s['state'] ?? 'unknown');
        $start = (string) ($s['startup'] ?? 'unknown');
        $valid = RemoteProtocol::serviceName($name, $os) !== null;
        $rows .= '<tr data-rem-filter="' . rmmH(strtolower($name . ' ' . $display)) . '"><td class="ps-3"><div class="rmm-mono rmm-wrap">' . rmmH($name) . '</div>'
            . ($display !== '' && $display !== $name ? '<div class="text-muted small rmm-wrap">' . rmmH($display) . '</div>' : '') . '</td>'
            . '<td>' . rivetRmmUiPill($stateKind[$st] ?? 'off', ucfirst($st)) . '</td><td class="text-nowrap">' . rmmH(ucfirst($start)) . '</td>'
            . '<td class="rmm-table-actions">' . ($valid ? rivetRmmRemServiceButtons($name, $display, $st, $start, $os)
                : '<span class="text-muted small">This name has characters the safe list does not allow, so it cannot be controlled from here.</span>') . '</td></tr>';
    }
    $note = ($data['truncated'] ?? false) ? '<p class="small text-muted px-3 pt-2 mb-0">The device shortened the list. Use the filter, or refresh and look again.</p>' : '';
    $note .= count($items) > RIVET_RMM_REM_MAX_ROWS ? '<p class="small text-muted px-3 pt-2 mb-0">Showing the first ' . RIVET_RMM_REM_MAX_ROWS . ' of ' . count($items) . ' services. Use the filter.</p>' : '';

    return $note . '<div class="table-responsive rmm-rem-scroll" tabindex="0" role="region" aria-label="Services of the device"><table class="table table-hover table-sm mb-0" id="rem-svc-table"><caption class="visually-hidden">Services on the device, with state and startup type</caption>'
        . rivetRmmUiThead(['Service', 'State', 'Startup', 'Actions']) . '<tbody>' . $rows . '</tbody></table></div>';
}

function rivetRmmRemServiceButtons(string $name, string $display, string $state, string $startup, string $os): string
{
    $btn = static function (string $action, string $label, string $icon, string $class) use ($name, $display): string {
        return '<button type="button" class="btn btn-sm ' . $class . '" data-rem-svc="' . rmmH($action) . '" data-name="' . rmmH($name) . '" data-display="' . rmmH($display !== '' ? $display : $name)
            . '"><i class="fas fa-' . $icon . ' me-1" aria-hidden="true"></i>' . rmmH($label) . '<span class="visually-hidden"> service ' . rmmH($name) . '</span></button>';
    };
    $o = '<div class="d-flex flex-wrap gap-1">';
    if ($state !== 'running') {
        $o .= $btn('start', 'Start', 'play', 'btn-outline-success');
    }
    if ($state === 'running' || $state === 'unknown' || $state === 'paused' || $state === 'failed') {
        $o .= $btn('restart', 'Restart', 'redo', 'btn-outline-warning') . $btn('stop', 'Stop', 'stop', 'btn-outline-danger');
    }
    if ($startup !== 'auto') {
        $o .= $btn('enable', 'Enable', 'toggle-on', 'btn-outline-secondary');
    }
    if ($os === 'windows' && $startup !== 'manual') {
        $o .= $btn('manual', 'Manual', 'hand-paper', 'btn-outline-secondary');
    }
    if ($startup !== 'disabled') {
        $o .= $btn('disable', 'Disable', 'ban', 'btn-outline-danger');
    }

    return $o . '</div>';
}

// ------------------------------------------------------------------ process list

/** @param array<string,mixed> $snap */
function rivetRmmRemProcessesBody(array $snap, bool $supported): string
{
    $state = (string) ($snap['state'] ?? 'none');
    if (!$supported) {
        return '<p class="text-muted mb-0">This device\'s agent did not announce the process manager, so it cannot list or end processes. An agent update adds it.</p>';
    }
    if ($state === 'none') {
        return rivetRmmUiEmpty('fas fa-microchip', 'No list yet', 'Press "Refresh list". The device sends its processes the next time it checks in.');
    }
    if (!rivetRmmRemJobFinal($state)) {
        return '<p class="mb-0" role="status">' . rivetRmmRemStatePill($state) . ' <span class="text-muted ms-1">Asked the device for its processes. The list appears here when it answers.</span></p>';
    }
    $data = $snap['data'] ?? null;
    if ($state !== 'succeeded' || !is_array($data)) {
        return '<div class="alert alert-warning mb-0" role="status">' . rmmH((string) ($snap['error'] ?? '') !== '' ? $snap['error'] : 'The list did not arrive.') . ' Press "Refresh list" to try again.</div>';
    }
    $items = array_values(array_filter((array) ($data['processes'] ?? []), 'is_array'));
    if ($items === []) {
        return rivetRmmUiEmpty('fas fa-microchip', 'The device reported no processes', 'The list is empty.');
    }
    $rows = '';
    $n = 0;
    foreach ($items as $p) {
        if (++$n > RIVET_RMM_REM_MAX_ROWS) {
            break;
        }
        $pid = (int) ($p['pid'] ?? 0);
        $name = (string) ($p['name'] ?? '');
        $cpu = is_numeric($p['cpu_pct'] ?? null) ? (float) $p['cpu_pct'] : null;
        $mem = is_numeric($p['mem_bytes'] ?? null) ? (float) $p['mem_bytes'] : null;
        $ok = RemoteProtocol::pid($pid) !== null && RemoteProtocol::processName($name) !== null;
        $rows .= '<tr data-rem-filter="' . rmmH(strtolower($name . ' ' . (string) ($p['user'] ?? '') . ' ' . $pid)) . '" data-pid="' . $pid . '" data-name="' . rmmH(strtolower($name)) . '" data-user="' . rmmH(strtolower((string) ($p['user'] ?? '')))
            . '" data-cpu="' . ($cpu ?? -1) . '" data-mem="' . ($mem ?? -1) . '"><td class="ps-3 rmm-mono">' . $pid . '</td><td class="rmm-wrap">' . rmmH($name) . '</td><td class="rmm-wrap">' . rivetRmmUiOrNoData($p['user'] ?? null, 'unknown')
            . '</td><td class="text-end text-nowrap">' . ($cpu === null ? '<span class="text-muted">no data</span>' : rmmH(number_format($cpu, 1)) . ' %') . '</td><td class="text-end text-nowrap">' . ($mem === null ? '<span class="text-muted">no data</span>' : rmmH(rivetRmmUiBytes($mem)))
            . '</td><td class="text-end">' . (is_numeric($p['threads'] ?? null) ? (int) $p['threads'] : '<span class="text-muted">-</span>') . '</td>'
            . '<td class="rmm-table-actions">' . ($ok ? '<button type="button" class="btn btn-sm btn-outline-danger" data-rem-kill="1" data-pid="' . $pid . '" data-name="' . rmmH($name) . '"><i class="fas fa-times-circle me-1" aria-hidden="true"></i>End process<span class="visually-hidden"> '
                . rmmH($name) . ' (id ' . $pid . ')</span></button>' : '<span class="text-muted small">Cannot be ended from here (the id or name is outside the safe list).</span>') . '</td></tr>';
    }
    $note = ($data['truncated'] ?? false) ? '<p class="small text-muted px-3 pt-2 mb-0">The device shortened the list to its busiest processes. Raise the number to see more, up to ' . RemoteProtocol::PROCESS_LIST_MAX . '.</p>' : '';
    $th = static fn (string $label, string $key, string $cls = ''): string => '<th scope="col" class="' . $cls . '" aria-sort="none"><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none rmm-rem-sort" data-rem-sort="' . $key . '">'
        . rmmH($label) . ' <i class="fas fa-sort ms-1" aria-hidden="true"></i></button></th>';

    return $note . '<div class="table-responsive rmm-rem-scroll" tabindex="0" role="region" aria-label="Processes of the device"><table class="table table-hover table-sm mb-0" id="rem-proc-table"><caption class="visually-hidden">Processes on the device. Use the column headings to sort.</caption>'
        . '<thead class="rmm-thead"><tr>' . $th('PID', 'pid', 'ps-3') . $th('Name', 'name') . $th('User', 'user') . $th('CPU', 'cpu', 'text-end') . $th('Memory', 'mem', 'text-end') . '<th scope="col" class="text-end">Threads</th><th scope="col">Action</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table></div>';
}

/** One line over a list: how many entries, how old. */
function rivetRmmRemSnapSummary(array $snap, string $noun): string
{
    $state = (string) ($snap['state'] ?? 'none');
    if ($state !== 'succeeded' || !is_array($snap['data'] ?? null)) {
        return '';
    }
    $count = (int) ($snap['data']['count'] ?? count((array) ($snap['data'][$noun] ?? [])));

    return $count . ' ' . $noun . ', listed ' . rivetRmmUiAgo(rivetRmmUiAgeOf((string) ($snap['finished_at'] ?? ''))) . '.';
}

// ------------------------------------------------------------------ job result (a control or a kill)

/**
 * The outcome of a queued control or kill, in words.
 *
 * @param array<string,mixed> $r RemoteActions::jobResult() data
 */
function rivetRmmRemJobResultHtml(array $r): string
{
    $state = (string) ($r['state'] ?? '');
    $res = is_array($r['result'] ?? null) ? $r['result'] : null;
    $type = (string) ($r['type'] ?? '');
    if (rivetRmmMeshPushIsJob($type)) {
        return rivetRmmMeshPushResultHtml($r);   // rc.16: a pushed Mesh agent install / removal
    }
    $what = '';
    if ($res !== null && $type === RemoteProtocol::JOB_SERVICE_CONTROL) {
        $what = 'Service <span class="rmm-mono">' . rmmH((string) ($res['name'] ?? '')) . '</span>, ' . rmmH((string) ($res['action'] ?? '')) . '.';
        if (($res['before'] ?? '') !== '' || ($res['after'] ?? '') !== '') {
            $what .= ' State: ' . rmmH((string) ($res['before'] ?? '?')) . ' to ' . rmmH((string) ($res['after'] ?? '?')) . '.';
        }
    } elseif ($res !== null && $type === RemoteProtocol::JOB_PROCESS_KILL) {
        $what = 'Process <span class="rmm-mono">' . (int) ($res['pid'] ?? 0) . '</span> (' . rmmH((string) ($res['name'] ?? '')) . ').';
    }
    $detail = $res !== null && (string) ($res['detail'] ?? '') !== '' ? rmmH(rivetRmmRemDetailText((string) $res['detail'])) : '';
    $reason = rivetRmmRemReasonText($r['reason'] ?? null);
    $o = '<div class="rmm-rem-result d-flex flex-wrap align-items-start gap-2"><span>' . rivetRmmRemStatePill($state) . '</span><div class="min-w-0 rmm-wrap">';
    $o .= $what !== '' ? '<div>' . $what . '</div>' : '';
    if ($state === 'succeeded') {
        $o .= '<div>' . ($res !== null && array_key_exists('ok', $res) && $res['ok'] === false ? 'The device ran it but reports it did not work. ' : 'The device reports it done. ') . $detail . '</div>';
    } elseif (rivetRmmRemJobFinal($state)) {
        $o .= '<div>' . ($detail !== '' ? $detail . ' ' : '') . rmmH($reason) . '</div>';
    } else {
        $o .= '<div class="text-muted">The device carries it out at its next check-in. This page keeps checking.</div>';
    }

    return $o . '<div class="text-muted small">Job ' . rmmH(substr((string) ($r['job_id'] ?? ''), 0, 8)) . '</div></div></div>';
}

// ------------------------------------------------------------------ files

/** @param array<string,mixed> $t TransferService::present() */
function rivetRmmRemXferPill(string $state): string
{
    $map = ['awaiting_content' => ['info', 'Waiting for the file'], 'queued' => ['info', 'Waiting for the device'], 'receiving' => ['info', 'Transferring'], 'delivering' => ['info', 'Delivering to the device'],
        'complete' => ['ok', 'Complete'], 'failed' => ['crit', 'Failed'], 'expired' => ['off', 'Expired'], 'deleted' => ['off', 'Deleted']];
    [$kind, $text] = $map[$state] ?? ['off', ucfirst($state)];

    return rivetRmmUiPill($kind, $text);
}

/**
 * The transfer ledger of the device.
 *
 * @param list<array<string,mixed>> $items TransferService::present() rows
 * @param array<int,string> $names user_id => name
 */
function rivetRmmRemXferTable(array $items, array $names, int $deviceId): string
{
    if ($items === []) {
        return rivetRmmUiEmpty('fas fa-exchange-alt', 'No transfers yet', 'Files you ask for, and files an administrator sends, are listed here until they expire.');
    }
    $rows = '';
    foreach ($items as $t) {
        $state = (string) $t['state'];
        $down = (string) $t['direction'] === 'download';
        $id = (string) $t['transfer_id'];
        $size = (int) $t['size_bytes'];
        $act = '';
        if ($down && $state === 'complete') {
            $act .= '<a class="btn btn-sm btn-outline-primary" href="' . RIVET_RMM_REM_HANDLER . '?action=download&amp;device_id=' . $deviceId . '&amp;transfer_id=' . rmmH($id)
                . '"><i class="fas fa-download me-1" aria-hidden="true"></i>Download<span class="visually-hidden"> ' . rmmH((string) $t['file_name']) . '</span></a> ';
        }
        if ($state !== 'deleted') {
            $act .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-rem-xfer-delete="' . rmmH($id) . '" data-name="' . rmmH((string) $t['file_name']) . '"><i class="fas fa-trash me-1" aria-hidden="true"></i>Delete<span class="visually-hidden"> the stored copy of '
                . rmmH((string) $t['file_name']) . '</span></button>';
        }
        $rows .= '<tr data-xfer="' . rmmH($id) . '" data-state="' . rmmH($state) . '"><td class="ps-3"><div class="rmm-wrap fw-semibold">' . rmmH((string) $t['file_name']) . '</div><div class="rmm-mono rmm-wrap text-muted">' . rmmH((string) $t['path']) . '</div>'
            . ((string) $t['error'] !== '' ? '<div class="small text-danger rmm-wrap">' . rmmH((string) $t['error']) . '</div>' : '') . '</td>'
            . '<td class="text-nowrap">' . ($down ? '<i class="fas fa-arrow-down me-1" aria-hidden="true"></i>From the device' : '<i class="fas fa-arrow-up me-1" aria-hidden="true"></i>To the device') . '</td>'
            . '<td>' . rivetRmmRemXferPill($state) . '</td><td class="text-end text-nowrap">' . ($size > 0 || $state === 'complete' ? rmmH(rivetRmmUiBytes((float) $size)) : '<span class="text-muted">-</span>') . '</td>'
            . '<td>' . ((string) $t['sha256'] !== '' && $t['sha256'] !== null ? '<span class="rmm-mono rmm-wrap rmm-rem-hash">' . rmmH((string) $t['sha256']) . '</span>' : '<span class="text-muted">not yet</span>') . '</td>'
            . '<td class="small text-nowrap">' . rivetRmmUiTime((string) $t['created_at']) . '</td><td class="small rmm-wrap">' . rmmH($names[(int) $t['created_by']] ?? ('user #' . (int) $t['created_by'])) . '</td><td class="rmm-table-actions">' . $act . '</td></tr>';
    }

    return '<div class="table-responsive rmm-rem-scroll" tabindex="0" role="region" aria-label="File transfers of the device"><table class="table table-hover table-sm mb-0" id="rem-xfer-table"><caption class="visually-hidden">File transfers with state, size and the SHA-256 the server verified</caption>'
        . rivetRmmUiThead(['File', 'Direction', 'State', 'Size', 'SHA-256 verified', 'When', 'Who', 'Actions']) . '<tbody>' . $rows . '</tbody></table></div>';
}

/** True while some transfer in the list is still moving (the page keeps polling). */
function rivetRmmRemXferPending(array $items): bool
{
    foreach ($items as $t) {
        if (in_array((string) $t['state'], RIVET_RMM_REM_XFER_PENDING, true)) {
            return true;
        }
    }

    return false;
}

// ------------------------------------------------------------------ the tab

/** @param array<string,mixed> $v rivetRmmRemPanelView() plus 'csrf' */
function rivetRmmRemPanelHtml(array $v): string
{
    $caps = $v['caps'];
    $os = $v['os'];
    $o = '<div id="rmm-remote-tools" class="rmm-rem" data-post-url="' . RIVET_RMM_REM_HANDLER . '" data-csrf="' . rmmH($v['csrf']) . '" data-device-id="' . (int) $v['device_id'] . '" data-hostname="' . rmmH($v['hostname'])
        . '" data-platform="' . rmmH($v['platform']) . '" data-offline="' . ($v['offline'] ? '1' : '0') . '" data-upload-max="' . (int) $v['upload_cap']['bytes'] . '">';

    // ---- context
    $pill = static fn (bool $on, string $label): string => $on ? rivetRmmUiPill('ok', $label . ': announced') : rivetRmmUiPill('off', $label . ': not announced');
    $o .= '<div class="rmm-rem-context mb-3"><div class="d-flex flex-wrap align-items-center gap-2 mb-1"><strong>' . rmmH($v['hostname']) . '</strong><span class="text-muted">' . rmmH(['windows' => 'Windows', 'linux' => 'Linux'][$v['platform']] ?? 'Other platform') . '</span>'
        . $pill($caps['services'], 'Services') . $pill($caps['processes'], 'Processes') . $pill($caps['files'], 'Files') . '</div>'
        . '<p class="small text-muted mb-0">' . ($caps['any'] ? 'Every action here is queued as a signed job the agent verifies, and it is written to the audit log with your name. Job results are kept for ' . (int) $v['retention_days'] . ' days.'
            : 'This device has not announced any remote capability yet. It does after its next check-in with a current agent. Every action is audited and job results are kept for ' . (int) $v['retention_days'] . ' days.')
        . ($v['offline'] ? ' <strong>The device is offline now</strong>: what you queue waits until it checks in again, until the job expires.' : '') . '</p></div>';

    // ---- Mesh
    $o .= rivetRmmUiCard('Remote access (MeshCentral)', 'desktop', rivetRmmRemMeshHtml($v), 'info', '', false, 'rem-mesh');

    // ---- services
    $svcActions = $caps['services'] ? '<div class="d-flex flex-wrap align-items-end gap-2 mb-2"><button type="button" class="btn btn-sm btn-primary" id="rem-svc-refresh"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Refresh list</button>'
        . '<div><label class="form-label small mb-0" for="rem-svc-filter">Filter services</label><input class="form-control form-control-sm" id="rem-svc-filter" type="search" maxlength="100" autocomplete="off" placeholder="name or display name"></div>'
        . '<span class="small text-muted ms-auto" id="rem-svc-summary">' . rmmH(rivetRmmRemSnapSummary($v['services'], 'services')) . '</span></div>' : '';
    $o .= rivetRmmUiCard('Services', 'cogs', $svcActions . '<div id="rem-svc-status" class="small mb-2" role="status" aria-live="polite"></div><div id="rem-svc-result" class="mb-2" role="status" aria-live="polite"></div>'
        . '<div id="rem-svc-body" data-state="' . rmmH((string) $v['services']['state']) . '" data-job-id="' . rmmH((string) ($v['services']['job_id'] ?? '')) . '">' . rivetRmmRemServicesBody($v['services'], $os, $caps['services']) . '</div>',
        'primary', '', false, 'rem-services');

    // ---- processes
    $procActions = $caps['processes'] ? '<div class="d-flex flex-wrap align-items-end gap-2 mb-2"><button type="button" class="btn btn-sm btn-primary" id="rem-proc-refresh"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Refresh list</button>'
        . '<div><label class="form-label small mb-0" for="rem-proc-sort">Busiest by</label><select class="form-select form-select-sm" id="rem-proc-sort"><option value="cpu">CPU</option><option value="mem">Memory</option></select></div>'
        . '<div><label class="form-label small mb-0" for="rem-proc-limit">How many</label><select class="form-select form-select-sm" id="rem-proc-limit"><option value="50">50</option><option value="100">100</option><option value="200" selected>200</option><option value="500">500</option></select></div>'
        . '<div><label class="form-label small mb-0" for="rem-proc-filter">Filter processes</label><input class="form-control form-control-sm" id="rem-proc-filter" type="search" maxlength="100" autocomplete="off" placeholder="name, user or id"></div>'
        . '<span class="small text-muted ms-auto" id="rem-proc-summary">' . rmmH(rivetRmmRemSnapSummary($v['processes'], 'processes')) . '</span></div>' : '';
    $o .= rivetRmmUiCard('Processes', 'microchip', $procActions . '<div id="rem-proc-status" class="small mb-2" role="status" aria-live="polite"></div><div id="rem-proc-result" class="mb-2" role="status" aria-live="polite"></div>'
        . '<div id="rem-proc-body" data-state="' . rmmH((string) $v['processes']['state']) . '" data-job-id="' . rmmH((string) ($v['processes']['job_id'] ?? '')) . '">' . rivetRmmRemProcessesBody($v['processes'], $caps['processes']) . '</div>',
        'primary', '', false, 'rem-processes');

    // ---- files
    $o .= rivetRmmUiCard('Files', 'folder-open', rivetRmmRemFilesHtml($v), 'warning', '', false, 'rem-files');

    return $o . rivetRmmRemDialogs($v) . '</div>';
}

/** @param array<string,mixed> $v */
function rivetRmmRemMeshHtml(array $v): string
{
    $m = $v['mesh'];
    $views = (array) ($m['views'] ?? []);
    $o = '<p class="small text-muted mb-2">MeshCentral opens in a new tab. The terminal is a shell on the device; the file view is MeshCentral\'s own. RivetIT does not embed it.</p>';
    if (!empty($v['mesh_agent']) || isset($v['mesh_after_approval'])) {
        $o .= rivetRmmMeshAgentHtml($v['mesh_agent'] ?? null, ['conflict_url' => $v['mesh_agent_conflict_url'] ?? null, 'after_approval' => $v['mesh_after_approval'] ?? null]);   // rc.12: installed / version / node id / error, with the conflict and kept-by-hand hints; rc.27: the "install after approval" state line
    }
    $o .= rivetRmmMeshPushControls($v['mesh_push'] ?? null);   // rc.16: Install / Remove MeshCentral agent (Core decides whether the install is offered)
    if (empty($m['configured'])) {
        return $o . '<p class="mb-0 text-muted mt-2">MeshCentral is not configured.' . ($v['admin'] ? ' <a href="/admin/settings_mesh_wizard.php" id="rmm-mesh-setup-link">Set it up step by step under Administration &gt; Remote access (MeshCentral) setup</a>.' : ' An administrator can set it up under Administration &gt; Remote access (MeshCentral) setup.') . '</p>';
    }
    if (empty($m['mapped'])) {
        return $o . '<p class="mb-0 text-muted">This device is not mapped to a MeshCentral node yet. An administrator can map it in the Remote access card on the Overview tab.</p>';
    }
    if ($views === []) {
        return $o . '<p class="mb-0 text-muted">Your role cannot open remote sessions.</p>';
    }
    $defs = ['desktop' => ['Desktop', 'desktop', 'success'], 'terminal' => ['Terminal', 'terminal', 'primary'], 'files' => ['Files', 'folder-open', 'primary']];
    $o .= '<div class="d-flex flex-wrap gap-2">';
    foreach ($defs as $view => [$label, $icon, $cls]) {
        if (in_array($view, $views, true)) {
            $o .= '<button type="button" class="btn btn-' . $cls . ' btn-sm" data-rem-mesh="' . $view . '"><i class="fas fa-' . $icon . ' me-1" aria-hidden="true"></i>' . $label . '<span class="visually-hidden"> (opens MeshCentral in a new tab)</span></button>';
        }
    }
    $o .= '</div>';
    if ($v['offline']) {
        $o .= '<p class="small text-muted mt-2 mb-0">The device is offline. A session can be forced but will likely fail.</p>';
    }

    return $o . '<div id="rem-mesh-status" class="small mt-2" role="status" aria-live="polite"></div>';
}

/** @param array<string,mixed> $v */
function rivetRmmRemFilesHtml(array $v): string
{
    if (!$v['files_on']) {
        return '<p class="mb-2">' . rmmH(rivetRmmRemOffText(RemoteProtocol::FEATURE_FILE_TRANSFER)) . '</p>'
            . '<blockquote class="small text-muted border-start ps-3 mb-2">' . rmmH(RemoteSettings::FILE_TRANSFER_RISK) . '</blockquote>'
            . ($v['admin'] ? '<a class="btn btn-sm btn-outline-primary" href="/admin/settings_endpoint_agent.php">Open Administration &gt; Endpoint agent</a>' : '<p class="small text-muted mb-0">Only an administrator can switch it on.</p>');
    }
    $caps = $v['caps'];
    $o = '';
    if ($v['transfer_error'] !== null && $v['transfer_error'] !== '') {
        $o .= '<div class="alert alert-warning" role="alert">File transfer cannot store files right now: ' . rmmH((string) $v['transfer_error']) . ($v['admin'] ? ' <a href="/admin/settings_endpoint_agent.php">Open Administration</a>.' : '') . '</div>';
    }
    $o .= '<p class="small text-muted">Only folders an administrator allowed can be used, and a never-transfer list (password databases, private keys, system credential stores, the agent\'s own files) always wins. Every request, refusal and download is audited.</p>';
    if (!$caps['files']) {
        $o .= '<p class="text-muted">This device\'s agent did not announce file transfer, so it cannot send or receive files. An agent update adds it.</p>';
    } else {
        $roots = $v['roots'];
        $sep = $v['os'] === 'windows' ? '\\' : '/';
        $o .= '<div class="mb-2"><div class="small fw-semibold mb-1" id="rem-roots-label">Allowed folders on this ' . ($v['os'] === 'windows' ? 'Windows' : 'Linux') . ' device</div>';
        if ($roots === []) {
            $o .= '<p class="text-muted small mb-0">No folder is allowed for this platform yet, so nothing can be transferred. ' . ($v['admin'] ? '<a href="/admin/settings_endpoint_agent.php">Allow a folder in Administration &gt; Endpoint agent</a>.' : 'An administrator lists the folders.') . '</p>';
        } else {
            $o .= '<div class="d-flex flex-wrap gap-1" role="group" aria-labelledby="rem-roots-label">';
            foreach ($roots as $r) {
                $o .= '<button type="button" class="btn btn-sm btn-outline-secondary rmm-mono" data-rem-root="' . rmmH(rtrim($r, '/\\') . $sep) . '"><i class="fas fa-folder me-1" aria-hidden="true"></i>' . rmmH($r) . '</button>';
            }
            $o .= '</div><div class="form-text">Pick a folder to start the path, then type the file name.</div>';
        }
        $o .= '</div>';
        $o .= '<form id="rem-dl-form" class="row g-2 align-items-end mb-3" autocomplete="off"><div class="col-12 col-md"><label class="form-label small mb-0" for="rem-dl-path">Full path of the file on the device</label>'
            . '<input class="form-control rmm-mono" id="rem-dl-path" name="path" maxlength="' . RemoteProtocol::TRANSFER_PATH_MAX . '" required placeholder="' . rmmH($roots !== [] ? rtrim($roots[0], '/\\') . $sep . 'file.txt' : ($v['os'] === 'windows' ? 'C:\\Folder\\file.txt' : '/srv/folder/file.txt')) . '"></div>'
            . '<div class="col-auto"><button type="submit" class="btn btn-primary" id="rem-dl-go"><i class="fas fa-cloud-download-alt me-1" aria-hidden="true"></i>Request file</button></div></form>';
    }
    $o .= '<div id="rem-xfer-status" class="small mb-2" role="status" aria-live="polite"></div>';
    $o .= '<div id="rem-xfer-body" data-pending="' . (rivetRmmRemXferPending($v['transfers']) ? '1' : '0') . '">' . rivetRmmRemXferTable($v['transfers'], $v['names'], (int) $v['device_id']) . '</div>';
    if ($caps['files']) {
        $cap = $v['upload_cap'];
        $o .= '<div class="border-top pt-3 mt-3">' . ($v['admin'] ? '<button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rmmRemUpload"><i class="fas fa-cloud-upload-alt me-1" aria-hidden="true"></i>Send a file to the device</button>'
            . '<span class="small text-muted ms-2">Largest file: ' . rmmH(rivetRmmUiBytes((float) $cap['bytes'])) . ' (limited by ' . rmmH($cap['by']) . ').</span>'
            : '<span class="small text-muted">Only an administrator can send a file to a device.</span>') . '</div>';
    }

    return $o;
}

/** The two in-page dialogs. js/rmm_remote.js moves them to the end of the body (a modal inside a tab pane would sit under the page's own stacking contexts). */
function rivetRmmRemDialogs(array $v): string
{
    $o = '<div class="modal fade" id="rmmRemConfirm" tabindex="-1" aria-labelledby="rmmRemConfirmTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmRemConfirmTitle"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>Please confirm</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><p id="rmmRemConfirmText"></p><p class="small text-muted" id="rmmRemConfirmTarget"></p>'
        . '<div class="form-check mb-2 d-none" id="rmmRemConfirmForceWrap"><input class="form-check-input" type="checkbox" id="rmmRemConfirmForce"><label class="form-check-label" for="rmmRemConfirmForce">Force it: end the process immediately, with no chance to save or shut down cleanly.</label></div>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" id="rmmRemConfirmCheck"><label class="form-check-label" for="rmmRemConfirmCheck" id="rmmRemConfirmCheckLabel">I understand and want to do this.</label></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="rmmRemConfirmGo" disabled>Confirm</button></div></div></div></div>';
    if ($v['admin'] && $v['files_on'] && $v['caps']['files']) {
        $cap = $v['upload_cap'];
        $o .= '<div class="modal fade" id="rmmRemUpload" tabindex="-1" aria-labelledby="rmmRemUploadTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="rem-up-form" autocomplete="off">'
            . '<div class="modal-header"><h5 class="modal-title" id="rmmRemUploadTitle"><i class="fas fa-cloud-upload-alt me-2" aria-hidden="true"></i>Send a file to ' . rmmH($v['hostname']) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            . '<div class="modal-body"><div class="alert alert-warning small" role="note">A file sent to a device is written to its disk and <strong>can be a program that runs later</strong>. Send only files you trust, into a folder an administrator allowed.</div>'
            . '<div class="mb-2"><label class="form-label" for="rem-up-file">File</label><input class="form-control" type="file" id="rem-up-file" name="file" required><div class="form-text" id="rem-up-cap">Largest file: ' . rmmH(rivetRmmUiBytes((float) $cap['bytes'])) . ' (limited by ' . rmmH($cap['by']) . ').</div></div>'
            . '<div class="mb-2"><label class="form-label" for="rem-up-path">Full path to write on the device</label><input class="form-control rmm-mono" id="rem-up-path" name="path" maxlength="' . RemoteProtocol::TRANSFER_PATH_MAX . '" required></div>'
            . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="rem-up-overwrite" name="overwrite" value="1"><label class="form-check-label" for="rem-up-overwrite">Replace the file if one is already there</label></div>'
            . '<div class="form-check"><input class="form-check-input" type="checkbox" id="rem-up-confirm" name="confirm" value="1"><label class="form-check-label" for="rem-up-confirm">I confirm this file may be written to ' . rmmH($v['hostname']) . '.</label></div>'
            . '<div id="rem-up-msg" class="small mt-2" role="status" aria-live="polite"></div></div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger" id="rem-up-go" disabled>Send file</button></div></form></div></div></div>';
    }

    return $o;
}
