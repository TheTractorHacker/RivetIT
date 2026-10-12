<?php

/*
 * RivetCore 1.0.0-rc.16: push the MeshCentral agent to a device that is already enrolled.
 *
 *   - one device: "Install MeshCentral agent" and "Remove MeshCentral agent" on the device page (Remote access card), on the Remote tab and on the legacy device page;
 *   - many devices: "Install MeshCentral agent" on the Agent Fleet page, for a department, a tag, a group or everything the user may see.
 *
 * Nothing here decides anything. Whether the buttons are SHOWN follows what Core says about the device (RmmReadModel::deviceView()['mesh_install']: available,
 * agent_supports, installed) plus the module, the `remote_tools` switch and `rmm.remote.manage`; every request is checked again, from scratch, by RemoteActions
 * (agent/post/rmm_mesh_push.php): the device scope, the ability, the switch, the Mesh settings, the agent's capability and the idempotence ("already installed",
 * "already queued"). The caller sends nothing but `force`: the address, the device group id and the pin are read from the stored Mesh settings when the job is created
 * and are never printed here. Every string that comes from a device (the job result) is escaped where it is printed.
 *
 * The dialogs are rendered once per page by rivetRmmUiDialogs() (device page) or by the legacy page itself; the buttons carry data-mesh-push-action and are driven by
 * js/rmm_mesh_push.js, so any number of button groups on one page share one dialog.
 */

require_once __DIR__ . '/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmAbility;

const RIVET_RMM_MESHPUSH_HANDLER = '/agent/post/rmm_mesh_push.php';
const RIVET_RMM_MESHPUSH_TYPES = ['mesh_install', 'mesh_uninstall'];

function rivetRmmMeshPushH($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rivetRmmMeshPushIsJob(string $type): bool
{
    return in_array($type, RIVET_RMM_MESHPUSH_TYPES, true);
}

function rivetRmmMeshPushLabel(string $type): string
{
    return ['mesh_install' => 'Install MeshCentral agent', 'mesh_uninstall' => 'Remove MeshCentral agent'][$type] ?? $type;
}

/**
 * What the buttons need for one device, or null when nothing is to be offered: the remote-tools switch is off, the viewer lacks `rmm.remote.manage`, the device is not
 * an approved active one, or there is neither an install to offer (Core adds `mesh_install` only while Mesh is enabled with an https address and a device group)
 * nor an installed Mesh agent to remove.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel() (features.remote_tools, perm.remote_manage, usable, revoked, retired, mesh_install, mesh_agent, offline)
 * @return array{available:bool,supports:bool,installed:bool,offline:bool,device_id:int,hostname:string}|null
 */
function rivetRmmMeshPushState(array $vm): ?array
{
    if (empty($vm['features']['remote_tools']) || empty($vm['perm']['remote_manage']) || empty($vm['usable']) || !empty($vm['revoked']) || !empty($vm['retired'])) {
        return null;
    }
    $mi = is_array($vm['mesh_install'] ?? null) ? $vm['mesh_install'] : null;
    $ma = is_array($vm['mesh_agent'] ?? null) ? $vm['mesh_agent'] : null;
    $installed = ($ma['installed'] ?? false) === true;
    $available = $mi !== null && ($mi['available'] ?? false) === true;
    if (!$available && !$installed) {
        return null;
    }

    return ['available' => $available, 'supports' => $available && ($mi['agent_supports'] ?? false) === true, 'installed' => $installed || ($mi['installed'] ?? false) === true,
        'offline' => !empty($vm['offline']), 'device_id' => (int) $vm['device_id'], 'hostname' => (string) ($vm['hostname'] ?? '')];
}

/**
 * The buttons and the status line of one device. May appear more than once on a page (Overview card, Remote tab): no id is used, the script finds the groups by attribute.
 *
 * @param array{available:bool,supports:bool,installed:bool,offline:bool,device_id:int,hostname:string}|null $s rivetRmmMeshPushState()
 */
function rivetRmmMeshPushControls(?array $s): string
{
    if ($s === null) {
        return '';
    }
    $GLOBALS['rmm_mesh_push_scripts'] = true;   // includes/footer.php links js/rmm_mesh_push.js only on a page that drew a control
    $h = 'rivetRmmMeshPushH';
    $o = '<div class="rmm-mesh-push mt-2" data-mesh-push data-device-id="' . (int) $s['device_id'] . '" data-hostname="' . $h($s['hostname']) . '" data-installed="' . ($s['installed'] ? '1' : '0') . '">'
        . '<div class="d-flex flex-wrap gap-2">';
    if ($s['available']) {
        $o .= '<button type="button" class="btn btn-sm btn-primary" data-mesh-push-action="install"' . ($s['supports'] ? '' : ' disabled aria-disabled="true"') . '>'
            . '<i class="fas fa-download me-1" aria-hidden="true"></i>Install MeshCentral agent</button>';
    }
    if ($s['installed']) {
        $o .= '<button type="button" class="btn btn-sm btn-outline-danger" data-mesh-push-action="uninstall"><i class="fas fa-trash-alt me-1" aria-hidden="true"></i>Remove MeshCentral agent</button>';
    }
    $o .= '</div>';
    if ($s['available'] && !$s['supports']) {
        $o .= '<p class="small text-muted mt-2 mb-0" data-mesh-push-hint="unsupported">This device\'s agent is too old to install the MeshCentral agent by push (it did not announce it). Update the agent first.</p>';
    } elseif ($s['available']) {
        $o .= '<p class="small text-muted mt-2 mb-0" data-mesh-push-hint="info">Sends a signed job: the device downloads the MeshCentral agent from your own Mesh server and installs it. The RivetIT agent is not touched.'
            . ($s['installed'] ? ' The device already reports the Mesh agent as installed, so nothing is queued unless you force it.' : '')
            . ($s['offline'] ? ' The device is offline now: the job waits until it checks in, until the job expires.' : '') . '</p>';
    }
    $o .= '<div class="small mt-2" data-mesh-push-status role="status" aria-live="polite"></div></div>';

    return $o;
}

/** The confirm dialog shared by every button group of the page. js/rmm_mesh_push.js moves it to the end of the body. */
function rivetRmmMeshPushDialog(string $csrf): string
{
    $h = 'rivetRmmMeshPushH';

    return '<div class="modal fade" id="rmmMeshPushConfirm" tabindex="-1" aria-labelledby="rmmMeshPushTitle" aria-hidden="true" data-post-url="' . RIVET_RMM_MESHPUSH_HANDLER . '" data-csrf="' . $h($csrf) . '">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmMeshPushTitle"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i><span id="rmmMeshPushTitleText">Please confirm</span></h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><p id="rmmMeshPushText"></p><p class="small text-muted" id="rmmMeshPushTarget"></p>'
        . '<div class="form-check mb-2 d-none" id="rmmMeshPushForceWrap"><input class="form-check-input" type="checkbox" id="rmmMeshPushForce"><label class="form-check-label" for="rmmMeshPushForce">Run the installer again even though the device reports the Mesh agent as installed.</label></div>'
        . '<div class="form-check d-none" id="rmmMeshPushCheckWrap"><input class="form-check-input" type="checkbox" id="rmmMeshPushCheck"><label class="form-check-label" for="rmmMeshPushCheck" id="rmmMeshPushCheckLabel">I understand and want to do this.</label></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rmmMeshPushGo">Confirm</button></div>'
        . '</div></div></div>';
}

/**
 * The result of one mesh_install / mesh_uninstall job, in words (`$r` is RemoteActions::jobResult() data). The device-reported text is escaped.
 *
 * @param array<string,mixed> $r
 */
function rivetRmmMeshPushResultHtml(array $r): string
{
    $h = 'rivetRmmMeshPushH';
    $state = (string) ($r['state'] ?? '');
    $type = (string) ($r['type'] ?? '');
    $res = is_array($r['result'] ?? null) ? $r['result'] : null;
    $map = ['queued' => ['info', 'Queued, waiting for the device'], 'running' => ['info', 'Running on the device'], 'succeeded' => ['ok', 'Done'], 'failed' => ['crit', 'Failed'],
        'timed_out' => ['crit', 'Timed out'], 'cancelled' => ['off', 'Cancelled'], 'expired' => ['off', 'Expired before it ran']];
    [$kind, $text] = $map[$state] ?? ['off', ucfirst(str_replace('_', ' ', $state))];
    $pill = function_exists('rivetRmmUiPill') ? rivetRmmUiPill($kind, $text) : '<span class="badge bg-secondary">' . $h($text) . '</span>';
    $o = '<div class="rmm-mesh-result d-flex flex-wrap align-items-start gap-2" data-state="' . $h($state) . '"><span>' . $pill . '</span><div class="min-w-0" style="overflow-wrap:anywhere">';
    $o .= '<div><strong>' . $h(rivetRmmMeshPushLabel($type)) . '.</strong>';
    if ($res !== null) {
        $detail = (string) ($res['detail'] ?? '');
        $words = ['installed' => 'The Mesh agent was installed.', 'already_installed' => 'The Mesh agent was already installed, so nothing was done.', 'uninstalled' => 'The Mesh agent was removed.',
            'not_installed' => 'There was no Mesh agent to remove.', 'uninstall_failed' => 'The Mesh agent\'s uninstaller did not run or exited with an error.'];
        $o .= ' ' . $h($words[$detail] ?? $detail);
    } elseif ($state === 'queued' || $state === 'running') {
        $o .= ' The device carries it out at its next check-in. This page keeps checking.';
    }
    $o .= '</div>';
    if ($res !== null && (string) ($res['node_id'] ?? '') !== '') {
        $o .= '<div class="small">Node id <span class="font-monospace">' . $h($res['node_id']) . '</span>. The server maps it to this device when the device next checks in.</div>';
    }
    if ($res !== null && (string) ($res['error'] ?? '') !== '') {
        $o .= '<div class="small text-danger">' . $h($res['error']) . '</div>';
    }
    if ($res !== null && isset($res['exit_code']) && $res['exit_code'] !== null && (int) $res['exit_code'] !== 0) {
        $o .= '<div class="small text-muted">Installer exit code ' . (int) $res['exit_code'] . '.</div>';
    }
    $reasons = ['result_lost' => 'The result never arrived. The action may or may not have happened: check the Mesh agent state on this page.', 'never_started' => 'The device never started it.',
        'no_result_by_deadline' => 'The device did not report a result in time.', 'expired_before_run' => 'The device did not check in before the job expired, so it never ran.',
        'device_retired' => 'The device was retired.', 'cancelled_by_user' => 'It was cancelled.'];
    $reason = (string) ($r['reason'] ?? '');
    if ($reason !== '' && !in_array($state, ['succeeded', 'queued', 'running'], true)) {
        $o .= '<div class="small">' . $h($reasons[$reason] ?? $reason) . '</div>';
    }
    if ($res !== null && (string) ($res['log'] ?? '') !== '') {
        $o .= '<details class="small mt-1"><summary>Installer log (last lines)</summary><pre class="rmm-pre mb-0" tabindex="0">' . $h(mb_substr((string) $res['log'], -4000)) . '</pre></details>';
    }

    return $o . '<div class="text-muted small">Job ' . $h(substr((string) ($r['job_id'] ?? ''), 0, 8)) . '</div></div></div>';
}

// ------------------------------------------------------------------ Agent Fleet: many devices

/**
 * The bulk install on the Agent Fleet page, or null when it is not to be offered: the remote-tools switch is off, the viewer lacks `rmm.device.view` or
 * `rmm.remote.manage`, or Core says the install is not available (Mesh off, no https address, no device group). `$tags` / `$groups` are the fleet's own lists.
 *
 * @param list<array<string,mixed>> $tags    RmmReadModel::tags()
 * @param list<array<string,mixed>> $groups  RmmReadModel::groups()
 * @param array<string,mixed>       $filters the fleet page's current filters (a tag name, a group id, a department id preselect the target)
 * @param list<int>|null            $visible the viewer's department ids (null = every department)
 * @return array{targets:list<array{type:string,id:int,label:string}>,preselect:string,narrowed:bool,csrf:string}|null
 */
function rivetRmmMeshBulkContext(\mysqli $mysqli, \RivetCore\Rmm\RmmModule $rmm, int $userId, ?array $visible, array $tags, array $groups, array $filters): ?array
{
    try {
        if (!$rmm->featureOn(\RivetCore\Rmm\Remote\RemoteProtocol::FEATURE_REMOTE_TOOLS)) {
            return null;
        }
        $authz = $rmm->authorizer();
        if (!$authz->allowed($userId, RmmAbility::DEVICE_VIEW, 0) || !$authz->allowed($userId, RmmAbility::REMOTE_MANAGE, 0)) {
            return null;
        }
        if ($rmm->meshAgents()->installRefusal() !== null) {
            return null;
        }
    } catch (\Throwable) {
        return null;   // the Mesh tables are not there yet (migration not applied)
    }
    $targets = [['type' => 'all', 'id' => 0, 'label' => 'Every device I can see']];
    $where = 'client_archived_at IS NULL';
    if ($visible !== null) {
        if ($visible === []) {
            return null;
        }
        $where .= ' AND client_id IN (' . implode(',', array_map('intval', $visible)) . ')';
    }
    $r = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE $where ORDER BY client_name LIMIT 500");
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $targets[] = ['type' => 'client', 'id' => (int) $row['client_id'], 'label' => 'Department: ' . $row['client_name']];
    }
    foreach ($tags as $t) {
        if ((int) ($t['tag_id'] ?? 0) > 0) {
            $targets[] = ['type' => 'tag', 'id' => (int) $t['tag_id'], 'label' => 'Tag: ' . $t['name']];
        }
    }
    foreach ($groups as $g) {
        if ((int) ($g['group_id'] ?? 0) > 0) {
            $targets[] = ['type' => 'group', 'id' => (int) $g['group_id'], 'label' => 'Group: ' . $g['name']];
        }
    }
    // The page's filter selects the target when it can be said in Core's terms (a group, a tag, a department); status, ring and text search cannot narrow a bulk install.
    $pre = 'all:0';
    if ((int) ($filters['group'] ?? 0) > 0) {
        $pre = 'group:' . (int) $filters['group'];
    } elseif ((string) ($filters['tag'] ?? '') !== '') {
        foreach ($tags as $t) {
            if ((string) $t['name'] === (string) $filters['tag']) {
                $pre = 'tag:' . (int) $t['tag_id'];
            }
        }
    } elseif ((int) ($filters['client_id'] ?? 0) > 0) {
        $pre = 'client:' . (int) $filters['client_id'];
    }
    $has = false;
    foreach ($targets as $t) {
        $has = $has || $t['type'] . ':' . $t['id'] === $pre;
    }
    $narrowed = false;
    foreach (['status', 'ring', 'q', 'software'] as $k) {
        $narrowed = $narrowed || (isset($filters[$k]) && is_string($filters[$k]) && trim($filters[$k]) !== '');
    }

    return ['targets' => $targets, 'preselect' => $has ? $pre : 'all:0', 'narrowed' => $narrowed, 'csrf' => (string) ($_SESSION['csrf_token'] ?? '')];
}

/** The toolbar button that opens the bulk dialog. */
function rivetRmmMeshBulkTrigger(?array $ctx): string
{
    if ($ctx === null) {
        return '';
    }

    return '<button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#rmmMeshBulk" data-mesh-bulk-open><i class="fas fa-download me-1" aria-hidden="true"></i>Install MeshCentral agent</button>';
}

/** The bulk dialog: a target, the optional force, then Core's count in a confirmation, then the counts of what was queued and skipped. */
function rivetRmmMeshBulkDialog(?array $ctx): string
{
    if ($ctx === null) {
        return '';
    }
    $GLOBALS['rmm_mesh_push_scripts'] = true;
    $h = 'rivetRmmMeshPushH';
    $opts = '';
    $group = '';
    $labels = ['all' => 'Everything', 'client' => 'Departments', 'tag' => 'Tags', 'group' => 'Groups'];
    foreach ($ctx['targets'] as $t) {
        if ($t['type'] !== $group) {
            $opts .= ($group !== '' ? '</optgroup>' : '') . '<optgroup label="' . $h($labels[$t['type']] ?? $t['type']) . '">';
            $group = $t['type'];
        }
        $v = $t['type'] . ':' . $t['id'];
        $opts .= '<option value="' . $h($v) . '"' . ($v === $ctx['preselect'] ? ' selected' : '') . '>' . $h($t['label']) . '</option>';
    }
    $opts .= '</optgroup>';

    return '<div class="modal fade" id="rmmMeshBulk" tabindex="-1" aria-labelledby="rmmMeshBulkTitle" aria-hidden="true" data-post-url="' . RIVET_RMM_MESHPUSH_HANDLER . '" data-csrf="' . $h($ctx['csrf']) . '">'
        . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmMeshBulkTitle"><i class="fas fa-download me-2" aria-hidden="true"></i>Install the MeshCentral agent on many devices</h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">'
        . '<p class="small text-muted">Queues one signed job per Windows or Linux device of the target that does not have the Mesh agent yet. The devices download it from your own Mesh server; the RivetIT agent is not touched. '
        . 'Devices whose agent is too old, that already have it, or that already have an install queued are skipped and counted. Status, ring and search filters cannot narrow this: pick a department, tag or group.</p>'
        . ($ctx['narrowed'] ? '<div class="alert alert-info py-2 small" role="note" data-mesh-bulk-narrowed>The status, ring or search filter on this page does not apply here: the target below decides which devices are reached.</div>' : '')
        . '<div class="mb-2"><label class="form-label" for="rmm-mb-target">Target</label><select class="form-select" id="rmm-mb-target">' . $opts . '</select></div>'
        . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="rmm-mb-force"><label class="form-check-label" for="rmm-mb-force">Run it again on devices that already report the Mesh agent (force).</label></div>'
        . '<div class="alert alert-warning d-none" id="rmm-mb-confirm" role="alert"><p class="mb-2" id="rmm-mb-confirm-text"></p>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-mb-check"><label class="form-check-label" for="rmm-mb-check">I confirm: install the MeshCentral agent on these devices.</label></div></div>'
        . '<div class="small" id="rmm-mb-result" role="status" aria-live="polite"></div>'
        . '</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>'
        . '<button type="button" class="btn btn-primary" id="rmm-mb-go">Count the devices</button></div></div></div></div>';
}
