<?php

/*
 * RMM patch management pages (RivetCore 1.0.0-rc.20, Phase 4, sub-switch `patching`, OFF by default): the Patches tab of the asset page (rivetRmmPatPanelSection()), the fleet
 * compliance page (agent/rmm_patches.php), the approval queue (agent/rmm_patch_updates.php: updates nobody has decided on yet, and requests that wait for a second person) and the
 * policy / ring / approval-rule page (agent/rmm_patch_policies.php). Form posts go to agent/post/rmm_patching.php.
 *
 * ONE AUTHORIZATION PATH. Every read and every write is a call into RivetCore's technician API (`endpoint_devices/{id}/patches...`, `endpoint_devices/patches...`,
 * `endpoint_devices/patch_policies...`, `endpoint_devices/approvals...`) as the signed-in user, through rivetRmmAutoApi(). Core's PatchActions decides who may read
 * (rmm.device.view + rmm.patch.view), who may approve / decline / scan / dry-run / change rules, rings and policies (rmm.patch.manage; rings and policies also need access to every
 * department), who may run a REAL installation (rmm.patch.install, plus rmm.job.reboot for a restart afterwards), which departments each call spans, and answers the same 404 for
 * another department's device as for a missing one. Nothing here builds a client filter or reads a patch table of its own; hiding a button is cosmetic. The only direct reads are lookups
 * limited to ids Core just returned: the asset id of the devices on the page being shown, department and site names for the labels, and the user names on an approval.
 *
 * The view-model functions (rivetRmmPat*View) return arrays and are what the tests call; the renderers (rivetRmmPat*Page / *Html) return HTML strings.
 */

require_once __DIR__ . '/rmm_automation.php';

use RivetCore\Rmm\Authz\RmmAbility;

const RIVET_RMM_PAT_HANDLER = 'rmm_patching';
const RIVET_RMM_PAT_PAGE = 50;
const RIVET_RMM_PAT_KINDS = ['critical' => 'Critical', 'security' => 'Security', 'important' => 'Important', 'optional' => 'Optional', 'driver' => 'Driver', 'other' => 'Other'];
const RIVET_RMM_PAT_STATUSES = ['available' => 'Waiting for a decision', 'approved' => 'Approved', 'declined' => 'Declined', 'installed' => 'Installed', 'failed' => 'Failed'];
const RIVET_RMM_PAT_SEVERITIES = ['critical' => 'Critical', 'important' => 'Important', 'moderate' => 'Moderate', 'low' => 'Low', 'unspecified' => 'Unspecified'];
const RIVET_RMM_PAT_DAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];

// ------------------------------------------------------------------ the gate

/**
 * Role-level abilities for the patch pages (a department of 0 asks "anywhere"; Core decides per department on every call).
 *
 * @return array{view:bool,manage:bool,install:bool,reboot:bool,approve:bool,admin:bool}
 */
function rivetRmmPatPerm(\mysqli $mysqli, int $userId): array
{
    $authz = rivetRmmModule($mysqli)->authorizer();
    $dv = $authz->allowed($userId, RmmAbility::DEVICE_VIEW, 0);
    $view = $dv && $authz->allowed($userId, RmmAbility::PATCH_VIEW, 0);

    return [
        'view' => $view,
        'manage' => $view && $authz->allowed($userId, RmmAbility::PATCH_MANAGE, 0),
        'install' => $view && $authz->allowed($userId, RmmAbility::PATCH_INSTALL, 0),
        'reboot' => $view && $authz->allowed($userId, RmmAbility::JOB_REBOOT, 0),
        'approve' => $view && $authz->allowed($userId, RmmAbility::JOB_APPROVE, 0),
        'admin' => $authz->allowed($userId, RmmAbility::ADMIN, 0),
    ];
}

/**
 * The first thing a patch page does (after inc_all.php and enforceUserPermission('module_rmm')): module on, `patching` switch on, and the ability to read patch state. Returns the page
 * context, or null after rendering the denial (the caller then stops: `return;`). With the module or the switch off nothing asks the database anything.
 *
 * @return array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli}|null
 */
function rivetRmmPatPage(\mysqli $mysqli, int $userId, string $userName): ?array
{
    if (!rivetRmmEnabled($mysqli)) {
        itflow_render_denied('The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.', 'RMM module off');

        return null;
    }
    if (!rivetRmmFeatureOn('patching', $mysqli)) {
        itflow_render_denied('Patch management is switched off. An administrator can turn it on under Administration > Endpoint agent > Patch management.', 'Switched off');

        return null;
    }
    $perm = rivetRmmPatPerm($mysqli, $userId);
    if (!$perm['view']) {
        itflow_render_denied('Your role cannot view patch status.', 'No access');

        return null;
    }
    $GLOBALS['rmm_auto_scripts'] = true;   // the confirm dialog and conditional fields of js/rmm_automation.js
    $GLOBALS['rmm_pat_scripts'] = true;    // js/rmm_patches.js

    return ['uid' => $userId, 'name' => $userName, 'csrf' => (string) ($_SESSION['csrf_token'] ?? ''), 'perm' => $perm, 'mysqli' => $mysqli];
}

/** The internal call into the technician API as the signed-in user. @return array{status:int,ok:bool,data:array<string,mixed>,error:string,code:string} */
function rivetRmmPatApi(array $ctx, string $method, array $segments, array $query = [], ?array $body = null): array
{
    return rivetRmmAutoApi($ctx['mysqli'], $ctx['uid'], $ctx['name'], $method, $segments, $query, $body);
}

// ------------------------------------------------------------------ small words

function rivetRmmPatKindPill(string $kind): string
{
    $label = RIVET_RMM_PAT_KINDS[$kind] ?? ucfirst($kind);

    return match ($kind) {
        'critical' => rivetRmmUiPill('crit', $label),
        'security' => rivetRmmUiPill('warn', $label),
        'important' => rivetRmmUiPill('info', $label),
        default => rivetRmmUiPill('off', $label),
    };
}

function rivetRmmPatStatusPill(string $status, int $failCount = 0): string
{
    return match ($status) {
        'available' => rivetRmmUiPill('warn', 'Waiting for a decision'),
        'approved' => rivetRmmUiPill('info', 'Approved'),
        'declined' => rivetRmmUiPill('off', 'Declined'),
        'installed' => rivetRmmUiPill('ok', 'Installed'),
        'failed' => rivetRmmUiPill('crit', 'Failed' . ($failCount > 1 ? ' (' . $failCount . ' times)' : '')),
        default => rivetRmmUiPill('off', ucfirst($status)),
    };
}

function rivetRmmPatResultPill(string $result): string
{
    return match ($result) {
        'installed' => rivetRmmUiPill('ok', 'Installed'),
        'needs_reboot' => rivetRmmUiPill('warn', 'Installed, needs a restart'),
        'would_install' => rivetRmmUiPill('info', 'Would install'),
        'skipped', 'not_applicable' => rivetRmmUiPill('off', $result === 'skipped' ? 'Skipped' : 'No longer applicable'),
        'eula_required' => rivetRmmUiPill('crit', 'Needs a license acceptance'),
        'locked' => rivetRmmUiPill('crit', 'Package manager busy'),
        'not_found' => rivetRmmUiPill('crit', 'Not found'),
        default => rivetRmmUiPill('crit', ucfirst(str_replace('_', ' ', $result))),
    };
}

/** @param array<string,mixed> $scan the `scan` part of a patch summary */
function rivetRmmPatScanPill(array $scan): string
{
    return match ((string) ($scan['state'] ?? 'never')) {
        'ok' => rivetRmmUiPill('ok', 'Scanned'),
        'partial' => rivetRmmUiPill('warn', 'Scanned, partly'),
        'failed' => rivetRmmUiPill('crit', 'Scan failed'),
        default => rivetRmmUiPill('off', 'Never scanned'),
    };
}

function rivetRmmPatManagerName(string $m): string
{
    return ['wua' => 'Windows Update', 'apt' => 'apt', 'dnf' => 'dnf', 'yum' => 'yum', 'zypper' => 'zypper'][$m] ?? ($m !== '' ? $m : 'unknown');
}

function rivetRmmPatSize(int $bytes): string
{
    return $bytes > 0 ? rivetRmmUiBytes((float) $bytes) : '<span class="text-muted">unknown</span>';
}

/** A short sentence for a policy's stored settings. @param array<string,mixed> $s */
function rivetRmmPatSettingsText(array $s): string
{
    if ($s === []) {
        return 'Nothing set: manual approval, nothing installed by the scheduler, no restart.';
    }
    $parts = [];
    if (isset($s['ring'])) {
        $parts[] = 'ring ' . $s['ring'];
    }
    if (isset($s['approval_mode'])) {
        $auto = [];
        foreach (array_keys(RIVET_RMM_PAT_KINDS) as $k) {
            if (isset($s['auto_days_' . $k])) {
                $auto[] = $k . ' after ' . (int) $s['auto_days_' . $k] . ' d';
            }
        }
        $parts[] = $s['approval_mode'] === 'auto' ? 'approves by itself' . ($auto !== [] ? ' (' . implode(', ', $auto) . ')' : ' (no kind has a number of days, so nothing is approved)') : 'manual approval';
    }
    if (isset($s['install_enabled'])) {
        $parts[] = !empty($s['install_enabled']) ? 'installs approved updates' : 'does not install by itself';
    }
    if (isset($s['window_mode'])) {
        $parts[] = match ((string) $s['window_mode']) {
            'daily' => 'window ' . ($s['window_days'] ?? '') . ' ' . ($s['window_start'] ?? '') . ' for ' . (int) ($s['window_minutes'] ?? 0) . ' min (' . ($s['window_tz'] ?? 'UTC') . ')',
            'maintenance' => 'only in maintenance window #' . (int) ($s['window_maintenance_id'] ?? 0),
            default => 'any time',
        };
    }
    if (isset($s['reboot_policy'])) {
        $parts[] = ['never' => 'never restarts', 'if_required_defer' => 'reports a needed restart', 'if_required_now' => 'restarts when needed'][(string) $s['reboot_policy']] ?? (string) $s['reboot_policy'];
    }
    if (!empty($s['two_person'])) {
        $parts[] = 'manual approval needs a second person';
    }
    if (isset($s['max_minutes'])) {
        $parts[] = 'at most ' . (int) $s['max_minutes'] . ' min per install';
    }

    return $parts === [] ? 'Nothing set.' : ucfirst(implode('; ', $parts)) . '.';
}

/** A previous / next pager for list pages. @param callable(int):string $url */
function rivetRmmPatPager(int $page, int $total, int $per, callable $url): string
{
    $pages = max(1, (int) ceil($total / max(1, $per)));
    if ($pages <= 1) {
        return '';
    }
    $o = '<nav class="d-flex align-items-center justify-content-between mt-2 small" aria-label="Pages"><span class="text-muted">Page ' . $page . ' of ' . $pages . ' (' . (int) $total . ' in all)</span><span>';
    $o .= $page > 1 ? '<a class="btn btn-sm btn-outline-secondary me-1" href="' . rmmH($url($page - 1)) . '">Previous</a>' : '';
    $o .= $page < $pages ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($page + 1)) . '">Next</a>' : '';

    return $o . '</span></nav>';
}

/** device_id => asset_id for the devices Core just returned (lookup limited to those ids). @param list<array<string,mixed>> $items @return array<int,int> */
function rivetRmmPatAssetIds(\mysqli $mysqli, array $items): array
{
    $ids = array_values(array_unique(array_map(static fn (array $i): int => (int) ($i['device_id'] ?? 0), $items)));
    $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
    $out = [];
    if ($ids === []) {
        return $out;
    }
    $r = mysqli_query($mysqli, 'SELECT device_id, asset_id FROM endpoint_agent_devices WHERE asset_id IS NOT NULL AND device_id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['device_id']] = (int) $row['asset_id'];
    }

    return $out;
}

/** user_id => name for the ids on a page. @param list<int> $ids @return array<int,string> */
function rivetRmmPatUserNames(\mysqli $mysqli, array $ids): array
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

// ------------------------------------------------------------------ the Patches tab of the asset page

/**
 * The query-string parameters of the Patches tab (prefix `pt_`, so the other tabs' parameters are left alone), clamped to what the tab offers.
 *
 * @param array<string,mixed> $get
 * @return array{status:string,kind:string,q:string,page:int}
 */
function rivetRmmPatTabFilters(array $get): array
{
    $status = is_string($get['pt_status'] ?? null) ? $get['pt_status'] : 'missing';
    $kind = is_string($get['pt_kind'] ?? null) ? $get['pt_kind'] : '';
    $q = is_string($get['pt_q'] ?? null) ? mb_substr(trim($get['pt_q']), 0, 80) : '';

    return ['status' => $status === 'missing' || $status === 'all' || isset(RIVET_RMM_PAT_STATUSES[$status]) ? $status : 'missing', 'kind' => isset(RIVET_RMM_PAT_KINDS[$kind]) ? $kind : '', 'q' => $q,
        'page' => max(1, min(1000, (int) ($get['pt_page'] ?? 1)))];
}

/**
 * The view-model of the tab: Core's summary, one page of the device's updates and the latest history. Null when Core will not show this device to the viewer.
 *
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>|null
 */
function rivetRmmPatDeviceView(array $ctx, int $deviceId, array $get): ?array
{
    $f = rivetRmmPatTabFilters($get);
    $q = ['limit' => (string) RIVET_RMM_PAT_PAGE, 'offset' => (string) (($f['page'] - 1) * RIVET_RMM_PAT_PAGE)];
    if ($f['status'] !== 'all') {
        $q['status'] = $f['status'];
    }
    if ($f['kind'] !== '') {
        $q['kind'] = $f['kind'];
    }
    if ($f['q'] !== '') {
        $q['q'] = $f['q'];
    }
    $r = rivetRmmPatApi($ctx, 'GET', [$deviceId, 'patches'], $q);
    if (!$r['ok']) {
        return in_array($r['status'], [403, 404], true) ? null : ['error' => $r['error'] !== '' ? $r['error'] : 'The patch state could not be read.', 'filters' => $f];
    }
    $h = rivetRmmPatApi($ctx, 'GET', [$deviceId, 'patches', 'history'], ['limit' => '20']);

    return ['error' => '', 'filters' => $f, 'device_id' => $deviceId, 'summary' => (array) ($r['data']['summary'] ?? []), 'items' => (array) ($r['data']['items'] ?? []), 'total' => (int) ($r['data']['total'] ?? 0),
        'history' => $h['ok'] ? (array) ($h['data']['items'] ?? []) : [], 'history_total' => $h['ok'] ? (int) ($h['data']['total'] ?? 0) : 0, 'perm' => $ctx['perm'], 'csrf' => $ctx['csrf'],
        'names' => rivetRmmPatUserNames($ctx['mysqli'], array_merge(array_map(static fn (array $u): int => (int) ($u['status_by'] ?? 0), (array) ($r['data']['items'] ?? [])),
            $h['ok'] ? array_map(static fn (array $u): int => (int) ($u['user_id'] ?? 0), (array) ($h['data']['items'] ?? [])) : []))];
}

/** Why the tab has nothing to act on, in words (the device must be linked and announce the capability). */
function rivetRmmPatTabNotes(array $vm, array $dv, \mysqli $mysqli): array
{
    $notes = [];
    if (empty($vm['usable'])) {
        $notes[] = 'This device is not approved, or is revoked or retired, so no patch job can be queued for it.';
    }
    if (($dv['summary']['scan']['state'] ?? 'never') === 'never' && !empty($vm['usable'])) {
        $caps = null;
        $res = mysqli_query($mysqli, 'SELECT capabilities_json FROM rmm_device_state WHERE device_id = ' . (int) $vm['device_id']);
        $row = $res ? mysqli_fetch_row($res) : null;
        $caps = $row && $row[0] !== null ? json_decode((string) $row[0], true) : null;
        if (!is_array($caps) || !in_array(\RivetCore\Rmm\Patch\PatchProtocol::CAP_SCAN, $caps, true)) {
            $notes[] = 'This device has not announced that it can scan for updates (<code>' . rmmH(\RivetCore\Rmm\Patch\PatchProtocol::CAP_SCAN) . '</code>). Its agent is older than 0.8.0 or patching is withdrawn in its <code>config.json</code>; update the agent and the first scan follows within a day.';
        }
    }

    return $notes;
}

/** The URL of the tab with some filter values replaced. @param array{status:string,kind:string,q:string,page:int} $f @param array<string,mixed> $over */
function rivetRmmPatTabUrl(int $assetId, array $f, array $over = []): string
{
    $f = array_merge($f, $over);
    $q = ['asset_id' => $assetId];
    foreach (['status' => 'missing', 'kind' => '', 'q' => '', 'page' => 1] as $k => $def) {
        if ($f[$k] !== $def) {
            $q['pt_' . $k] = $f[$k];
        }
    }

    return '/agent/asset_details.php?' . http_build_query($q) . '#rmm-patches';
}

/**
 * The hook rivetRmmUiTabs() calls: the Patches tab. '' (no tab, no pane) when the module or the `patching` switch is off, the viewer cannot read patch state, or Core will not show this
 * device to the viewer.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmPatPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (!rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('patching', $mysqli)) {
        return '';
    }
    $uid = (int) $vm['user_id'];
    $perm = rivetRmmPatPerm($mysqli, $uid);
    if (!$perm['view']) {
        return '';
    }
    $ctx = ['uid' => $uid, 'name' => (string) ($GLOBALS['session_name'] ?? ''), 'csrf' => $csrf, 'perm' => $perm, 'mysqli' => $mysqli];
    $dv = rivetRmmPatDeviceView($ctx, (int) $vm['device_id'], $_GET);
    if ($dv === null) {
        return '';
    }
    $GLOBALS['rmm_pat_scripts'] = true;
    $GLOBALS['rmm_auto_scripts'] = true;

    return rivetRmmPatDeviceHtml($vm, $dv);
}

/** @param array<string,mixed> $vm rivetRmmUiPanel() @param array<string,mixed> $dv rivetRmmPatDeviceView() */
function rivetRmmPatDeviceHtml(array $vm, array $dv): string
{
    $assetId = (int) $vm['asset_id'];
    $o = '<div id="rmm-patches">';
    if ($dv['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($dv['error']) . '</div></div>';
    }
    $perm = $dv['perm'];
    $s = $dv['summary'];
    $scan = (array) ($s['scan'] ?? []);
    $counts = (array) ($s['counts'] ?? []);
    $by = (array) ($counts['by_status'] ?? []);
    $pol = (array) ($s['policy'] ?? []);
    $f = $dv['filters'];
    $self = '/agent/asset_details.php?asset_id=' . $assetId . '#rmm-patches';
    foreach (rivetRmmPatTabNotes($vm, $dv, $GLOBALS['mysqli']) as $n) {
        $o .= '<div class="alert alert-info py-2"><i class="fas fa-info-circle me-2" aria-hidden="true"></i>' . $n . '</div>';
    }
    // ---- status
    $missing = (int) ($counts['missing'] ?? 0);
    $overdue = (int) ($s['overdue']['critical_or_security'] ?? 0);
    $verdict = ($scan['state'] ?? 'never') === 'never' ? rivetRmmUiPill('off', 'Never scanned') : (!empty($s['compliant']) ? rivetRmmUiPill('ok', 'Compliant') : rivetRmmUiPill('crit', 'Not compliant'));
    $rows = [
        ['Status', $verdict . ' ' . rivetRmmPatScanPill($scan)],
        ['Last scan', ($scan['scanned_at'] ?? null) !== null ? rivetRmmUiTime((string) $scan['scanned_at']) . ' <span class="text-muted small">with ' . rmmH(rivetRmmPatManagerName((string) ($scan['manager'] ?? ''))) . '</span>' : '<span class="text-muted">never</span>'],
        ['Missing updates', (int) $missing . ($missing > 0 ? ' <span class="text-muted small">(' . implode(', ', array_filter(array_map(static fn (string $k): string => (int) ($counts['missing_by_kind'][$k] ?? 0) > 0 ? (int) $counts['missing_by_kind'][$k] . ' ' . strtolower(RIVET_RMM_PAT_KINDS[$k]) : '', array_keys(RIVET_RMM_PAT_KINDS)))) . ')</span>' : '')],
        ['Overdue', $overdue > 0 ? rivetRmmUiPill('crit', $overdue . ' critical or security, missing more than ' . (int) ($s['overdue']['days'] ?? 14) . ' days') : '<span class="text-muted">none (limit ' . (int) ($s['overdue']['days'] ?? 14) . ' days)</span>'],
        ['Waiting for a decision / approved / failed', (int) ($by['available'] ?? 0) . ' / ' . (int) ($by['approved'] ?? 0) . ' / ' . (int) ($by['failed'] ?? 0)],
        ['Restart', !empty($scan['reboot_pending']) ? rivetRmmUiPill('warn', 'A restart is pending') : '<span class="text-muted">none pending</span>'],
        ['Last installation', ($scan['last_install_at'] ?? null) !== null ? rivetRmmUiTime((string) $scan['last_install_at']) : '<span class="text-muted">not reported</span>'],
    ];
    if (($scan['state'] ?? '') === 'failed' && (string) ($scan['error'] ?? '') !== '') {
        $rows[] = ['Scan error', '<span class="rmm-wrap">' . rmmH($scan['error']) . '</span>'];
    }
    if (!empty($scan['truncated'])) {
        $rows[] = ['List', '<span class="text-warning-emphasis">The device reported more updates than are kept; the list is cut.</span>'];
    }
    if (($scan['wu_state'] ?? 'n/a') !== 'n/a' && ($scan['wu_state'] ?? '') !== '') {
        $rows[] = ['Windows Update service', rmmH($scan['wu_state'])];
    }
    $o .= '<div class="row g-3 mb-3"><div class="col-lg-7">' . rivetRmmUiDl($rows) . '</div><div class="col-lg-5">' . rivetRmmPatPolicyBox($pol) . '</div></div>';

    // ---- actions
    $csrf = (string) $dv['csrf'];
    $dev = (int) $vm['device_id'];
    $acts = '';
    if ($perm['manage']) {
        $acts .= rivetRmmAutoActionForm(RIVET_RMM_PAT_HANDLER, 'scan', ['device_id' => $dev], $csrf, $self, '<i class="fas fa-search me-1" aria-hidden="true"></i>Scan now', 'btn btn-sm btn-outline-primary', '', 'Ask the device for its list of missing updates (read only, nothing is downloaded)')
            . ' ' . rivetRmmAutoActionForm(RIVET_RMM_PAT_HANDLER, 'dry_run', ['device_id' => $dev], $csrf, $self, '<i class="fas fa-vial me-1" aria-hidden="true"></i>Dry run', 'btn btn-sm btn-outline-secondary', '', 'List what an installation of the approved updates would do; nothing changes');
    }
    $acts = $acts === '' ? '<span class="text-muted small">Your role can read patch status but not change it.</span>' : $acts;
    $o .= '<div class="d-flex flex-wrap align-items-center mb-3" style="gap:6px">' . $acts . '</div>';

    // ---- the updates (one form: tick rows, then approve / decline / install the ticked ones)
    $assetBase = ['asset_id' => $assetId];
    $filter = '<form method="get" action="/agent/asset_details.php#rmm-patches" class="row g-2 align-items-end mb-2 rmm-pat-filter">';
    foreach ($assetBase as $k => $v) {
        $filter .= '<input type="hidden" name="' . rmmH($k) . '" value="' . (int) $v . '">';
    }
    $filter .= '<div class="col-sm-4 col-md-3"><label class="form-label small mb-0" for="pt_status">Show</label><select class="form-select form-select-sm" id="pt_status" name="pt_status">';
    $st = ['missing' => 'Everything still missing'] + RIVET_RMM_PAT_STATUSES + ['all' => 'All'];
    foreach ($st as $k => $w) {
        $filter .= '<option value="' . rmmH($k) . '"' . ($f['status'] === $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }
    $filter .= '</select></div><div class="col-sm-4 col-md-3"><label class="form-label small mb-0" for="pt_kind">Kind</label><select class="form-select form-select-sm" id="pt_kind" name="pt_kind"><option value="">Any kind</option>';
    foreach (RIVET_RMM_PAT_KINDS as $k => $w) {
        $filter .= '<option value="' . rmmH($k) . '"' . ($f['kind'] === $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }
    $filter .= '</select></div><div class="col-sm-4 col-md-4"><label class="form-label small mb-0" for="pt_q">Search title, KB or package</label><input class="form-control form-control-sm" id="pt_q" name="pt_q" maxlength="80" value="' . rmmH($f['q']) . '"></div>'
        . '<div class="col-auto"><button class="btn btn-sm btn-outline-primary" type="submit">Filter</button></div></form>';

    if ($dv['items'] === []) {
        $table = rivetRmmUiEmpty('fas fa-shield-alt', ($scan['state'] ?? 'never') === 'never' ? 'No scan yet' : 'No updates match', ($scan['state'] ?? 'never') === 'never' ? 'The list appears after the first scan.' : 'Nothing is listed for this filter.');
    } else {
        $selectable = $perm['manage'];
        $head = ($selectable ? '<th scope="col" class="ps-3"><input class="form-check-input" type="checkbox" data-pat-all="#pat-dev-form" aria-label="Select all updates on this page"></th>' : '')
            . '<th scope="col"' . ($selectable ? '' : ' class="ps-3"') . '>Update</th><th scope="col">Kind</th><th scope="col">Status</th><th scope="col">Size</th><th scope="col">Restart</th><th scope="col">First seen</th>';
        $body = '';
        foreach ($dv['items'] as $u) {
            $body .= '<tr>' . ($selectable ? '<td class="ps-3"><input class="form-check-input" type="checkbox" name="update_ids[]" value="' . rmmH($u['update_id']) . '" aria-label="Select ' . rmmH($u['title'] !== '' ? $u['title'] : $u['update_id']) . '"></td>' : '')
                . '<td' . ($selectable ? '' : ' class="ps-3"') . '><div class="fw-semibold rmm-wrap">' . rmmH($u['title'] !== '' ? $u['title'] : $u['update_id']) . '</div><div class="small text-muted rmm-wrap"><code class="rmm-mono">' . rmmH($u['update_id']) . '</code>'
                . ($u['kb'] !== '' ? ' &middot; ' . rmmH($u['kb']) : '') . ($u['version'] !== '' ? ' &middot; ' . ($u['current_version'] !== '' ? rmmH($u['current_version']) . ' &rarr; ' : '') . rmmH($u['version']) : '') . '</div>'
                . (($vm['platform'] ?? '') === 'windows' && empty($u['eula_accepted']) && $u['status'] !== 'installed' ? '<div class="small text-warning-emphasis">Needs a license acceptance on the device: the agent never accepts one, so this update is not installed from here.</div>' : '')
                . ($u['status'] === 'declined' && $u['decline_reason'] !== '' ? '<div class="small text-muted">Declined: ' . rmmH($u['decline_reason']) . '</div>' : '') . '</td>'
                . '<td>' . rivetRmmPatKindPill((string) $u['kind']) . ($u['severity'] !== 'unspecified' ? '<div class="small text-muted">' . rmmH(RIVET_RMM_PAT_SEVERITIES[$u['severity']] ?? $u['severity']) . '</div>' : '') . '</td>'
                . '<td>' . rivetRmmPatStatusPill((string) $u['status'], (int) $u['fail_count']) . ($u['status_at'] !== null && $u['status'] !== 'available' ? '<div class="small text-muted">' . rmmH(rivetRmmPatWho($u, $dv['names'])) . ' ' . rivetRmmUiTime((string) $u['status_at']) . '</div>' : '') . '</td>'
                . '<td>' . rivetRmmPatSize((int) $u['size_bytes']) . '</td><td>' . (!empty($u['reboot_required']) ? 'may restart' : '<span class="text-muted">no</span>') . '</td><td>' . rivetRmmUiTime((string) $u['first_seen_at']) . '</td></tr>';
        }
        $table = '<div class="table-responsive"><table class="table table-sm mb-0 rmm-pat-table"><caption class="visually-hidden">Updates of this device</caption><thead class="rmm-thead"><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
        if ($selectable) {
            $table .= '<div class="p-3 border-top d-flex flex-wrap align-items-center" style="gap:6px">' . rivetRmmPatDecisionButtons($perm, $vm, $self) . '</div>';
        }
    }
    $formOpen = $perm['manage'] ? '<form method="post" action="/agent/post/' . RIVET_RMM_PAT_HANDLER . '.php" id="pat-dev-form">' . rivetRmmAutoHidden($csrf, $self) . '<input type="hidden" name="device_id" value="' . $dev . '">' : '<div id="pat-dev-form">';
    $formClose = $perm['manage'] ? '</form>' : '</div>';
    $pager = rivetRmmPatPager($f['page'], (int) $dv['total'], RIVET_RMM_PAT_PAGE, static fn (int $p): string => rivetRmmPatTabUrl($assetId, $f, ['page' => $p]));
    $o .= $filter . '<div class="card card-dark mb-3">' . $formOpen . $table . $formClose . '</div>' . $pager;

    // ---- history
    if ($dv['history'] !== []) {
        $hr = '';
        foreach ($dv['history'] as $h) {
            $hr .= '<tr><td class="ps-3">' . rivetRmmUiTime((string) $h['at']) . '</td><td>' . ($h['action'] === 'dry_run' ? 'Dry run' : 'Install') . '</td><td class="rmm-wrap">' . rmmH($h['title'] !== '' ? $h['title'] : $h['update_id'])
                . '<div class="small text-muted"><code class="rmm-mono">' . rmmH($h['update_id']) . '</code></div></td><td>' . rivetRmmPatResultPill((string) $h['result']) . '</td><td class="small rmm-wrap">' . rmmH($h['detail'])
                . '</td><td class="small">' . rmmH($h['user_id'] !== null ? ($dv['names'][(int) $h['user_id']] ?? ('user #' . (int) $h['user_id'])) : 'schedule') . '</td></tr>';
        }
        $o .= '<details class="mb-2"' . ($f['status'] === 'failed' ? ' open' : '') . '><summary class="fw-semibold mb-2">Install history (latest ' . count($dv['history']) . ' of ' . (int) $dv['history_total'] . ')</summary><div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Install history</caption>'
            . rivetRmmUiThead(['When', 'Kind', 'Update', 'Result', 'Detail', 'By']) . '<tbody>' . $hr . '</tbody></table></div></details>';
    }

    return $o . '</div>';
}

/** Who decided about an update, in words. @param array<string,mixed> $u @param array<int,string> $names */
function rivetRmmPatWho(array $u, array $names): string
{
    $src = (string) ($u['status_source'] ?? '');
    $by = $u['status_by'] !== null ? ($names[(int) $u['status_by']] ?? ('user #' . (int) $u['status_by'])) : '';

    return match ($src) {
        'rule' => 'by a rule',
        'policy' => 'by a policy',
        'manual' => $by !== '' ? 'by ' . $by : 'by a person',
        'system' => 'by the system',
        default => $by !== '' ? 'by ' . $by : '',
    };
}

/**
 * Approve / Decline / Install buttons under the update table of one device. The install button asks for explicit confirmation in a dialog.
 *
 * @param array<string,bool> $perm
 * @param array<string,mixed> $vm
 */
function rivetRmmPatDecisionButtons(array $perm, array $vm, string $self): string
{
    $o = '<button type="submit" name="action" value="approve" class="btn btn-sm btn-success"><i class="fas fa-check me-1" aria-hidden="true"></i>Approve ticked</button>'
        . '<button type="submit" name="action" value="decline" class="btn btn-sm btn-outline-danger"><i class="fas fa-ban me-1" aria-hidden="true"></i>Decline ticked</button>'
        . '<button type="submit" name="action" value="dry_run" class="btn btn-sm btn-outline-secondary" title="List what installing the ticked updates would do; nothing changes"><i class="fas fa-vial me-1" aria-hidden="true"></i>Dry run ticked</button>';
    if ($perm['install']) {
        $o .= '<details class="w-100 mt-2"><summary class="small fw-semibold">Install for real</summary><div class="pt-2 d-flex flex-wrap align-items-end" style="gap:10px">'
            . '<div><label class="form-label small mb-0" for="pat_rb">After a clean install</label><select class="form-select form-select-sm" id="pat_rb" name="reboot_policy"><option value="never">Never restart the device</option><option value="if_required_defer">Report that a restart is needed</option>'
            . ($perm['reboot'] ? '<option value="if_required_now">Restart the device if needed (5 minutes later)</option>' : '') . '</select></div>'
            . ($perm['reboot'] ? '<div class="form-check mb-1" data-rmm-when="#pat_rb=if_required_now"><input class="form-check-input" type="checkbox" id="pat_rbc" name="confirm_reboot" value="1"><label class="form-check-label small" for="pat_rbc">I confirm the device may restart by itself</label></div>' : '')
            . '<div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="pat_conf" name="confirm" value="1"><label class="form-check-label small" for="pat_conf">I confirm these updates may be installed on ' . rmmH($vm['hostname']) . ' now</label></div>'
            . '<button type="submit" name="action" value="install" class="btn btn-sm btn-warning" data-rmm-confirm="Install the ticked updates (or every approved update when nothing is ticked) on ' . rmmH($vm['hostname'])
            . ' now? This changes software on the machine. Only approved updates are installed, and a restart only if you chose it. The Windows install path has not been tested on a real machine: try a dry run first." data-rmm-confirm-label="Install"><i class="fas fa-download me-1" aria-hidden="true"></i>Install approved</button></div></details>';
    }
    $o .= '<span class="small text-muted ms-2">Tick updates first. A decline keeps an update from being approved by any rule or policy.</span>';

    return $o;
}

/** The effective policy of the device in words. @param array<string,mixed> $p the `policy` part of a patch summary */
function rivetRmmPatPolicyBox(array $p): string
{
    if ($p === []) {
        return '';
    }
    $auto = [];
    foreach ((array) ($p['auto_days'] ?? []) as $k => $d) {
        if ($d !== null) {
            $auto[] = rmmH($k) . ' after ' . (int) $d . ' d';
        }
    }
    $win = (array) ($p['window'] ?? []);
    $winText = match ((string) ($win['mode'] ?? 'none')) {
        'daily' => rmmH((is_array($win['days'] ?? null) ? implode(',', $win['days']) : (string) ($win['days'] ?? '')) . ' at ' . ($win['start'] ?? '') . ' for ' . (int) ($win['minutes'] ?? 0) . ' min (' . ($win['tz'] ?? 'UTC') . ')'),
        'maintenance' => 'only while a chosen maintenance window is open',
        default => 'any time',
    };
    $reboot = ['never' => 'never restarts the device', 'if_required_defer' => 'reports a needed restart, does not restart', 'if_required_now' => 'restarts after a clean install'][(string) ($p['reboot_policy'] ?? 'never')] ?? rmmH($p['reboot_policy'] ?? '');
    $rows = [
        ['Ring', ($p['ring'] ?? null) !== null ? rmmH($p['ring']) . ' <span class="text-muted small">(+' . (int) ($p['ring_delay_days'] ?? 0) . ' days)</span>' : '<span class="text-muted">none</span>'],
        ['Approval', (($p['approval_mode'] ?? 'manual') === 'auto' ? 'by itself' . ($auto !== [] ? ': ' . implode(', ', $auto) : ' (no kind has a number of days, so nothing is approved)') : 'by a person') . (!empty($p['two_person']) ? ', a second person for manual approvals' : '')],
        ['Installs by schedule', !empty($p['install_enabled']) ? (!empty($p['live_install']) ? rivetRmmUiPill('warn', 'on, for real') : rivetRmmUiPill('info', 'dry runs only (live installs are off)')) : '<span class="text-muted">off</span>'],
        ['Install window', $winText],
        ['Restart', $reboot],
    ];

    return '<div class="rmm-pat-policy border rounded p-2"><div class="small fw-semibold mb-1"><i class="fas fa-calendar-check me-1" aria-hidden="true"></i>Patch policy for this device</div>' . rivetRmmUiDl($rows)
        . (empty($p['sources']) ? '<div class="small text-muted mt-1">No patch policy applies: the safe defaults hold (manual approval, nothing installed by itself).</div>' : '') . '</div>';
}

// ------------------------------------------------------------------ the fleet compliance page

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmPatFleetView(array $ctx, array $get): array
{
    $by = is_string($get['group_by'] ?? null) && in_array($get['group_by'], ['client', 'site', 'group'], true) ? $get['group_by'] : 'client';
    $os = is_string($get['os'] ?? null) && in_array($get['os'], ['windows', 'linux'], true) ? $get['os'] : '';
    $client = ctype_digit((string) ($get['client_id'] ?? '')) ? (int) $get['client_id'] : 0;
    $days = isset($get['overdue_days']) && ctype_digit((string) $get['overdue_days']) ? min(365, (int) $get['overdue_days']) : null;
    $page = max(1, min(1000, (int) ($get['page'] ?? 1)));
    $q = ['group_by' => $by, 'limit' => (string) RIVET_RMM_PAT_PAGE, 'offset' => (string) (($page - 1) * RIVET_RMM_PAT_PAGE)];
    if ($os !== '') {
        $q['os'] = $os;
    }
    if ($client > 0) {
        $q['client_id'] = (string) $client;
    }
    if ($days !== null) {
        $q['overdue_days'] = (string) $days;
    }
    $r = rivetRmmPatApi($ctx, 'GET', ['patches', 'compliance'], $q);
    $vm = ['filters' => ['group_by' => $by, 'os' => $os, 'client_id' => $client, 'overdue_days' => $days, 'page' => $page], 'error' => '', 'data' => [], 'enabled' => true, 'asset_ids' => [], 'names' => [], 'perm' => $ctx['perm'], 'csrf' => $ctx['csrf'],
        'clients' => rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid'])['clients']];
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The compliance figures could not be read.';

        return $vm;
    }
    $vm['data'] = $r['data'];
    $vm['enabled'] = !empty($r['data']['enabled']);
    $vm['asset_ids'] = rivetRmmPatAssetIds($ctx['mysqli'], (array) ($r['data']['devices'] ?? []));
    if ($by === 'site') {
        $vm['names'] = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid'])['sites'];
    } elseif ($by === 'group') {
        $vm['names'] = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid'])['groups'];
    }

    return $vm;
}

function rivetRmmPatKpi(string $label, string $value, string $kind = '', string $hint = ''): string
{
    return '<div class="col-6 col-md-4 col-xl-3"><div class="card card-dark h-100"' . ($kind !== '' ? ' style="border-top:3px solid var(--tblr-' . rmmH($kind) . ')"' : '') . '><div class="card-body py-2"><div class="rmm-pat-kpi">' . $value . '</div><div class="small text-muted">' . rmmH($label) . '</div>'
        . ($hint !== '' ? '<div class="small text-muted">' . rmmH($hint) . '</div>' : '') . '</div></div></div>';
}

/** @param array<string,mixed> $vm rivetRmmPatFleetView() */
function rivetRmmPatFleetPage(array $vm): string
{
    $links = '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patch_updates.php"><i class="fas fa-check-double me-1" aria-hidden="true"></i>Patch approvals</a> '
        . '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patch_policies.php"><i class="fas fa-calendar-check me-1" aria-hidden="true"></i>Patch policies</a>';
    $o = rivetRmmAutoHeader('Patch compliance', 'shield-alt', $links, 'Which devices miss critical or security updates, and for how long. A declined update is a decision and does not count. A device is not compliant when a critical or security update has been missing for more than the overdue limit.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $f = $vm['filters'];
    $d = $vm['data'];
    $sum = (array) $d['summary'];
    $o .= '<form method="get" action="/agent/rmm_patches.php" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end">'
        . '<div class="col-md-3"><label class="form-label" for="pf_client">Department</label><select class="form-select" id="pf_client" name="client_id"><option value="">All I can see</option>';
    foreach ($vm['clients'] as $id => $name) {
        $o .= '<option value="' . (int) $id . '"' . ($f['client_id'] === (int) $id ? ' selected' : '') . '>' . rmmH($name) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="pf_os">System</label><select class="form-select" id="pf_os" name="os"><option value="">Any</option><option value="windows"' . ($f['os'] === 'windows' ? ' selected' : '') . '>Windows</option><option value="linux"' . ($f['os'] === 'linux' ? ' selected' : '') . '>Linux</option></select></div>'
        . '<div class="col-md-2"><label class="form-label" for="pf_by">Group by</label><select class="form-select" id="pf_by" name="group_by">';
    foreach (['client' => 'Department', 'site' => 'Site', 'group' => 'Group'] as $k => $w) {
        $o .= '<option value="' . $k . '"' . ($f['group_by'] === $k ? ' selected' : '') . '>' . $w . '</option>';
    }
    $o .= '</select></div><div class="col-md-3"><label class="form-label" for="pf_days">Overdue after (days)</label><input class="form-control" type="number" min="0" max="365" id="pf_days" name="overdue_days" value="' . rmmH($f['overdue_days'] ?? '') . '" placeholder="' . (int) $d['overdue_days'] . '"></div>'
        . '<div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Apply</button></div></div></div></form>';
    $pct = $sum['compliance_pct'];
    $o .= '<div class="row g-2 mb-3">'
        . rivetRmmPatKpi('Devices', number_format((int) $sum['devices']))
        . rivetRmmPatKpi('Compliant', $pct === null ? 'no data' : rmmH((string) $pct) . ' %', $pct === null ? '' : ($pct >= 95 ? 'success' : ($pct >= 80 ? 'warning' : 'danger')), number_format((int) $sum['compliant']) . ' devices')
        . rivetRmmPatKpi('Not compliant', number_format((int) $sum['noncompliant']), (int) $sum['noncompliant'] > 0 ? 'danger' : '', 'overdue more than ' . (int) $d['overdue_days'] . ' days')
        . rivetRmmPatKpi('Missing critical updates', number_format((int) $sum['missing_critical']), (int) $sum['missing_critical'] > 0 ? 'danger' : '', 'devices')
        . rivetRmmPatKpi('Never scanned', number_format((int) $sum['never_scanned']), (int) $sum['never_scanned'] > 0 ? 'warning' : '')
        . rivetRmmPatKpi('Scan out of date', number_format((int) $sum['stale']), (int) $sum['stale'] > 0 ? 'warning' : '', 'no good scan for three scan intervals')
        . rivetRmmPatKpi('Restart pending', number_format((int) $sum['reboot_pending']), (int) $sum['reboot_pending'] > 0 ? 'warning' : '')
        . rivetRmmPatKpi('Failed installs', number_format((int) $sum['failed_installs']), (int) $sum['failed_installs'] > 0 ? 'danger' : '', 'devices')
        . '</div>';
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning">Patch management was switched off while this page was open. The figures below are the last ones kept.</div>';
    }

    // ---- groups
    $by = $f['group_by'];
    $name = static function (array $g) use ($by, $vm): string {
        $key = (int) $g['key'];
        if ($by === 'client') {
            return (string) ($g['name'] ?? ('Department #' . $key));
        }

        return (string) ($vm['names'][$key] ?? (($by === 'site' ? 'Site #' : 'Group #') . $key));
    };
    $rows = '';
    foreach ((array) $d['groups'] as $g) {
        $nc = (int) $g['noncompliant'];
        $link = $by === 'client' ? '/agent/rmm_patches.php?' . http_build_query(array_filter(['client_id' => (int) $g['key'], 'os' => $f['os'], 'overdue_days' => $f['overdue_days']], static fn ($v): bool => $v !== '' && $v !== null && $v !== 0)) : '';
        $rows .= '<tr><td class="ps-3 fw-semibold">' . ($link !== '' ? '<a href="' . rmmH($link) . '">' . rmmH($name($g)) . '</a>' : rmmH($name($g))) . '</td><td>' . (int) $g['devices'] . '</td><td>' . ($nc > 0 ? rivetRmmUiPill('crit', (string) $nc) : rivetRmmUiPill('ok', '0'))
            . '</td><td>' . (int) $g['compliant'] . '</td><td>' . (int) $g['never_scanned'] . '</td><td>' . (int) $g['stale'] . '</td><td>' . (int) $g['missing_critical'] . '</td><td>' . (int) $g['reboot_pending'] . '</td><td>' . (int) $g['failed_installs'] . '</td><td>' . (int) $g['missing_total'] . '</td></tr>';
    }
    $byWord = ['client' => 'Department', 'site' => 'Site', 'group' => 'Group'][$by];
    $tbl = $rows === '' ? rivetRmmUiEmpty('fas fa-shield-alt', 'No devices to show', 'Devices appear here once they are enrolled and approved.')
        : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Patch compliance by ' . rmmH(strtolower($byWord)) . '</caption>'
            . rivetRmmUiThead([$byWord, 'Devices', 'Not compliant', 'Compliant', 'Never scanned', 'Scan out of date', 'Missing critical', 'Restart pending', 'Failed installs', 'Missing updates']) . '<tbody>' . $rows . '</tbody></table></div>';
    $o .= rivetRmmUiCard('By ' . strtolower($byWord), 'layer-group', $tbl, '', '', true);

    // ---- the devices to look at
    $rows = '';
    foreach ((array) $d['devices'] as $dev) {
        $aid = $vm['asset_ids'][(int) $dev['device_id']] ?? 0;
        $host = rmmH($dev['hostname']);
        $rows .= '<tr><td class="ps-3">' . ($aid > 0 ? '<a href="/agent/asset_details.php?asset_id=' . $aid . '#rmm-patches">' . $host . '</a>' : $host) . '</td><td>' . rmmH($dev['os']) . '</td><td>' . ((int) $dev['overdue'] > 0 ? rivetRmmUiPill('crit', (string) (int) $dev['overdue']) : '0')
            . '</td><td>' . ((int) $dev['failed'] > 0 ? rivetRmmUiPill('crit', (string) (int) $dev['failed']) : '0') . '</td><td>' . (int) $dev['missing'] . '</td><td>' . ($dev['reboot_pending'] ? 'yes' : '<span class="text-muted">no</span>') . '</td><td>'
            . rivetRmmUiTime($dev['scanned_at']) . '</td></tr>';
    }
    $tbl = $rows === '' ? rivetRmmUiEmpty('fas fa-check-circle', 'No device is overdue or has a failed install', 'Devices with an overdue critical or security update, or an install that failed, are listed here.')
        : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Devices that are not compliant or have a failed install</caption>'
            . rivetRmmUiThead(['Device', 'System', 'Overdue critical or security', 'Failed', 'Missing', 'Restart pending', 'Last scan']) . '<tbody>' . $rows . '</tbody></table></div>';
    $nDev = count((array) $d['devices']);
    $pager = '';
    if ($f['page'] > 1 || $nDev >= RIVET_RMM_PAT_PAGE) {
        $url = static fn (int $p): string => '/agent/rmm_patches.php?' . http_build_query(array_filter(['client_id' => $f['client_id'], 'os' => $f['os'], 'group_by' => $f['group_by'] !== 'client' ? $f['group_by'] : '', 'overdue_days' => $f['overdue_days'], 'page' => $p > 1 ? $p : ''], static fn ($v): bool => $v !== '' && $v !== null && $v !== 0));
        $pager = '<nav class="d-flex justify-content-between p-3 small" aria-label="Pages"><span>' . ($f['page'] > 1 ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($f['page'] - 1)) . '">Previous</a>' : '') . '</span>'
            . '<span>' . ($nDev >= RIVET_RMM_PAT_PAGE ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($f['page'] + 1)) . '">Next</a>' : '') . '</span></nav>';
    }
    $o .= rivetRmmUiCard('Devices to look at', 'exclamation-triangle', $tbl . $pager, '', '', true);

    // ---- rings
    $rings = (array) ($d['rings']['counts'] ?? []);
    if ($rings !== []) {
        $rr = '';
        foreach ($rings as $ring => $n) {
            $rr .= '<tr><td class="ps-3">' . rmmH((string) $ring) . '</td><td>' . (int) $n . '</td></tr>';
        }
        $o .= rivetRmmUiCard('Ring distribution', 'sitemap', '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Scanned devices by patch ring</caption>' . rivetRmmUiThead(['Ring', 'Scanned devices']) . '<tbody>' . $rr . '</tbody></table></div>'
            . (empty($d['rings']['exact']) ? '<p class="small text-muted px-3 pb-2 mb-0">A sample of the largest fleets, not every device.</p>' : ''), '', '', true);
    }

    return $o;
}

// ------------------------------------------------------------------ the approval queue

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmPatUpdatesView(array $ctx, array $get): array
{
    $status = is_string($get['status'] ?? null) && isset(RIVET_RMM_PAT_STATUSES[$get['status']]) && $get['status'] !== 'installed' ? $get['status'] : 'available';
    $client = ctype_digit((string) ($get['client_id'] ?? '')) ? (int) $get['client_id'] : 0;
    $page = max(1, min(1000, (int) ($get['page'] ?? 1)));
    $q = ['status' => $status, 'limit' => (string) RIVET_RMM_PAT_PAGE, 'offset' => (string) (($page - 1) * RIVET_RMM_PAT_PAGE)];
    if ($client > 0) {
        $q['client_id'] = (string) $client;
    }
    $vm = ['filters' => ['status' => $status, 'client_id' => $client, 'page' => $page], 'error' => '', 'items' => [], 'total' => 0, 'enabled' => true, 'perm' => $ctx['perm'], 'csrf' => $ctx['csrf'], 'uid' => $ctx['uid'],
        'clients' => rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid'])['clients'], 'pending' => [], 'pending_error' => '', 'names' => []];
    $r = rivetRmmPatApi($ctx, 'GET', ['patches'], $q);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The updates could not be read.';
    } else {
        $vm['items'] = (array) ($r['data']['items'] ?? []);
        $vm['total'] = (int) ($r['data']['total'] ?? 0);
        $vm['enabled'] = !empty($r['data']['enabled']);
    }
    // requests that wait for a second person (kind `patch` of the ordinary approvals queue, scoped by Core)
    $p = rivetRmmPatApi($ctx, 'GET', ['approvals'], ['kind' => 'patch', 'state' => 'pending_approval', 'limit' => '50']);
    if ($p['ok']) {
        $vm['pending'] = (array) ($p['data']['data'] ?? []);
        $ids = [];
        foreach ($vm['pending'] as $a) {
            $ids[] = (int) $a['requested_by'];
        }
        $vm['names'] = rivetRmmPatUserNames($ctx['mysqli'], $ids);
    } else {
        $vm['pending_error'] = $p['error'];
    }

    return $vm;
}

/** @param array<string,mixed> $vm rivetRmmPatUpdatesView() */
function rivetRmmPatUpdatesPage(array $vm): string
{
    $self = '/agent/rmm_patch_updates.php';
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $o = rivetRmmAutoHeader('Patch approvals', 'check-double', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patches.php"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i>Patch compliance</a> <a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patch_policies.php"><i class="fas fa-calendar-check me-1" aria-hidden="true"></i>Patch policies</a>',
        'Updates that devices are missing, one row per update, across the departments you can see. Approving or declining here acts on every device that lists the update (within the department filter); nothing is installed by approving, installation follows the patch policy and its window.');

    // ---- waiting for a second person
    if ($vm['pending'] !== [] || $vm['pending_error'] !== '') {
        $rows = '';
        foreach ($vm['pending'] as $a) {
            $id = (int) $a['approval_id'];
            $mine = (int) $a['requested_by'] === (int) $vm['uid'];
            $by = $vm['names'][(int) $a['requested_by']] ?? ('user #' . (int) $a['requested_by']);
            $act = '';
            if ($perm['approve'] && $perm['manage'] && !$mine) {
                $act = '<form method="post" action="/agent/post/' . RIVET_RMM_PAT_HANDLER . '.php" class="d-flex flex-wrap align-items-end" style="gap:6px" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $self) . '<input type="hidden" name="approval_id" value="' . $id . '">'
                    . '<div><label class="form-label small mb-0" for="pa_note_' . $id . '">Note (optional)</label><input class="form-control form-control-sm" id="pa_note_' . $id . '" name="note" maxlength="300"></div>'
                    . '<button type="submit" name="action" value="decide_approval" class="btn btn-sm btn-success" data-rmm-confirm="Approve this request? ' . rmmH($a['summary']) . ' (' . (int) $a['device_count'] . ' device' . ((int) $a['device_count'] === 1 ? '' : 's') . '). The updates become approved; installation still follows the patch policy." data-rmm-confirm-label="Approve"><i class="fas fa-check me-1" aria-hidden="true"></i>Approve</button>'
                    . '<button type="submit" name="action" value="reject_approval" class="btn btn-sm btn-outline-danger"><i class="fas fa-times me-1" aria-hidden="true"></i>Reject</button></form>';
            } elseif ($mine) {
                $act = '<span class="text-muted small">You cannot approve your own request.</span> ' . rivetRmmAutoActionForm(RIVET_RMM_PAT_HANDLER, 'cancel_approval', ['approval_id' => $id], $csrf, $self, 'Cancel', 'btn btn-sm btn-outline-secondary', 'Cancel this request? Nothing is approved.');
            } else {
                $act = '<span class="text-muted small">Your role cannot decide this request.</span>';
            }
            $rows .= '<tr><td class="ps-3 rmm-wrap">' . rmmH($a['summary']) . '</td><td>' . rmmH($by) . '<div class="small text-muted">' . rivetRmmUiTime((string) $a['requested_at']) . '</div></td><td>' . (int) $a['device_count'] . '</td><td>' . rivetRmmAutoUtc((string) $a['expires_at']) . '</td><td>' . $act . '</td></tr>';
        }
        $body = $vm['pending_error'] !== '' ? '<div class="alert alert-danger m-3" role="alert">' . rmmH($vm['pending_error']) . '</div>'
            : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Patch approvals waiting for a second person</caption>' . rivetRmmUiThead(['Request', 'Requested by', 'Devices', 'Expires', 'Decision']) . '<tbody>' . $rows . '</tbody></table></div>';
        $o .= rivetRmmUiCard('Waiting for a second person', 'user-check', $body, 'warning', '<span class="small text-muted">' . count($vm['pending']) . ' waiting</span>', true);
    }
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $f = $vm['filters'];
    $o .= '<form method="get" action="' . $self . '" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label" for="pu_status">Show updates that are</label><select class="form-select" id="pu_status" name="status">';
    foreach (RIVET_RMM_PAT_STATUSES as $k => $w) {
        if ($k !== 'installed') {
            $o .= '<option value="' . $k . '"' . ($f['status'] === $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
        }
    }
    $o .= '</select></div><div class="col-md-4"><label class="form-label" for="pu_client">Department</label><select class="form-select" id="pu_client" name="client_id"><option value="">All I can see</option>';
    foreach ($vm['clients'] as $id => $name) {
        $o .= '<option value="' . (int) $id . '"' . ($f['client_id'] === (int) $id ? ' selected' : '') . '>' . rmmH($name) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Filter</button></div></div></div></form>';
    if ($vm['items'] === []) {
        return $o . rivetRmmUiCard('Updates', 'check-double', rivetRmmUiEmpty('fas fa-check-circle', 'Nothing here', $f['status'] === 'available' ? 'No device has an update that is waiting for a decision.' : 'No update has this status.'), '', '', true);
    }
    $canManage = $perm['manage'];
    $rows = '';
    foreach ($vm['items'] as $u) {
        $rows .= '<tr>' . ($canManage ? '<td class="ps-3"><input class="form-check-input" type="checkbox" name="update_ids[]" value="' . rmmH($u['update_id']) . '" aria-label="Select ' . rmmH($u['title'] !== '' ? $u['title'] : $u['update_id']) . '"></td>' : '')
            . '<td' . ($canManage ? '' : ' class="ps-3"') . '><div class="fw-semibold rmm-wrap">' . rmmH($u['title'] !== '' ? $u['title'] : $u['update_id']) . '</div><div class="small text-muted rmm-wrap"><code class="rmm-mono">' . rmmH($u['update_id']) . '</code>' . ($u['kb'] !== '' ? ' &middot; ' . rmmH($u['kb']) : '') . '</div></td>'
            . '<td>' . rivetRmmPatKindPill((string) $u['kind']) . '</td><td>' . (int) $u['devices'] . '</td><td>' . (!empty($u['reboot_required']) ? 'may restart' : '<span class="text-muted">no</span>') . '</td><td>' . rivetRmmUiTime((string) $u['first_seen_at']) . '</td></tr>';
    }
    $head = ($canManage ? '<th scope="col" class="ps-3"><input class="form-check-input" type="checkbox" data-pat-all="#pat-fleet-form" aria-label="Select all updates on this page"></th>' : '')
        . '<th scope="col"' . ($canManage ? '' : ' class="ps-3"') . '>Update</th><th scope="col">Kind</th><th scope="col">Devices</th><th scope="col">Restart</th><th scope="col">First seen</th>';
    $table = '<div class="table-responsive"><table class="table table-sm mb-0 rmm-pat-table"><caption class="visually-hidden">Missing updates across devices</caption><thead class="rmm-thead"><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    $back = $self . '?' . http_build_query(array_filter(['status' => $f['status'] !== 'available' ? $f['status'] : '', 'client_id' => $f['client_id'], 'page' => $f['page'] > 1 ? $f['page'] : ''], static fn ($v): bool => $v !== '' && $v !== 0));
    if ($canManage) {
        $scopeWord = $f['client_id'] > 0 ? 'in ' . ($vm['clients'][$f['client_id']] ?? 'this department') : 'in every department you can see';
        $table = '<form method="post" action="/agent/post/' . RIVET_RMM_PAT_HANDLER . '.php" id="pat-fleet-form">' . rivetRmmAutoHidden($csrf, $back) . ($f['client_id'] > 0 ? '<input type="hidden" name="client_id" value="' . (int) $f['client_id'] . '">' : '')
            . $table . '<div class="p-3 border-top d-flex flex-wrap align-items-end" style="gap:8px"><div><label class="form-label small mb-0" for="pu_reason">Reason for a decline (optional)</label><input class="form-control form-control-sm" id="pu_reason" name="reason" maxlength="200"></div>'
            . '<button type="submit" name="action" value="approve" class="btn btn-sm btn-success" data-rmm-confirm="Approve the ticked updates on every device ' . rmmH($scopeWord) . ' that lists them? Nothing is installed by approving; installation follows each device\'s patch policy." data-rmm-confirm-label="Approve"><i class="fas fa-check me-1" aria-hidden="true"></i>Approve ticked</button>'
            . '<button type="submit" name="action" value="decline" class="btn btn-sm btn-outline-danger" data-rmm-confirm="Decline the ticked updates on every device ' . rmmH($scopeWord) . ' that lists them? A declined update is never approved by a rule or policy, and is not installed." data-rmm-confirm-label="Decline" data-rmm-confirm-class="btn-danger"><i class="fas fa-ban me-1" aria-hidden="true"></i>Decline ticked</button>'
            . '<span class="small text-muted">Applies ' . rmmH($scopeWord) . '. A policy with the second-person rule turns an approval into a request.</span></div></form>';
    } else {
        $table .= '<p class="small text-muted p-3 mb-0">Your role can read patch status but not approve or decline.</p>';
    }
    $pager = rivetRmmPatPager($f['page'], (int) $vm['total'], RIVET_RMM_PAT_PAGE, static fn (int $p): string => $self . '?' . http_build_query(array_filter(['status' => $f['status'] !== 'available' ? $f['status'] : '', 'client_id' => $f['client_id'], 'page' => $p > 1 ? $p : ''], static fn ($v): bool => $v !== '' && $v !== 0)));

    return $o . rivetRmmUiCard('Updates', 'check-double', $table, '', '<span class="small text-muted">' . (int) $vm['total'] . ' update' . ((int) $vm['total'] === 1 ? '' : 's') . '</span>', true) . $pager . rivetRmmAutoConfirmModal();
}

// ------------------------------------------------------------------ policies, rings and rules

/** Hand the form back what was typed after a refusal (kept in the session for one page view). */
function rivetRmmPatTakeDraft(string $kind, int $id): ?array
{
    $d = $_SESSION['rmm_pat_draft'][$kind] ?? null;
    unset($_SESSION['rmm_pat_draft'][$kind]);

    return is_array($d) && (int) ($d['__id'] ?? -1) === $id ? $d : null;
}

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmPatPoliciesView(array $ctx, array $get): array
{
    $r = rivetRmmPatApi($ctx, 'GET', ['patch_policies']);
    $vm = ['error' => '', 'policies' => [], 'rings' => [], 'rules' => [], 'perm' => $ctx['perm'], 'csrf' => $ctx['csrf'], 'edit' => null, 'form' => null, 'edit_ring' => null, 'edit_rule' => null, 'draft' => null,
        'scope_data' => rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']), 'windows' => [], 'unrestricted' => false];
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The patch policies could not be read.';

        return $vm;
    }
    $vm['policies'] = (array) ($r['data']['data'] ?? []);
    $vm['rings'] = (array) ($r['data']['rings'] ?? []);
    $rl = rivetRmmPatApi($ctx, 'GET', ['patches', 'rules']);
    $vm['rules'] = $rl['ok'] ? (array) ($rl['data']['rules'] ?? []) : [];
    $w = rivetRmmAutoApi($ctx['mysqli'], $ctx['uid'], $ctx['name'], 'GET', ['maintenance']);
    $vm['windows'] = $w['ok'] ? (array) ($w['data']['data'] ?? []) : [];
    $pid = ctype_digit((string) ($get['policy_id'] ?? '')) ? (int) $get['policy_id'] : 0;
    if ($pid > 0) {
        foreach ($vm['policies'] as $p) {
            if ((int) $p['policy_id'] === $pid) {
                $vm['edit'] = $p;
            }
        }
        $vm['form'] = $vm['edit'] !== null ? 'edit' : null;
        $vm['draft'] = rivetRmmPatTakeDraft('policy', $pid);
    } elseif (!empty($get['new'])) {
        $vm['form'] = 'new';
        $vm['draft'] = rivetRmmPatTakeDraft('policy', 0);
    }
    $rid = ctype_digit((string) ($get['ring_id'] ?? '')) ? (int) $get['ring_id'] : 0;
    foreach ($vm['rings'] as $g) {
        if ($rid > 0 && (int) $g['ring_id'] === $rid) {
            $vm['edit_ring'] = $g;
        }
    }
    $uid = ctype_digit((string) ($get['rule_id'] ?? '')) ? (int) $get['rule_id'] : 0;
    foreach ($vm['rules'] as $g) {
        if ($uid > 0 && (int) $g['rule_id'] === $uid) {
            $vm['edit_rule'] = $g;
        }
    }
    if (!empty($get['new_rule'])) {
        $vm['edit_rule'] = [];
    }
    $vm['rule_draft'] = $vm['edit_rule'] !== null ? rivetRmmPatTakeDraft('rule', $uid) : null;
    $vm['unrestricted'] = rivetRmmModule($ctx['mysqli'])->authorizer()->visibleClientIds($ctx['uid']) === null;

    return $vm;
}

/** One tri-state select for a setting: not set, or a value from the list. @param array<string,string> $opts */
function rivetRmmPatSelect(string $id, string $name, array $opts, ?string $cur, string $unsetLabel = 'Not set (a broader policy or the safe default decides)'): string
{
    $o = '<select class="form-select" id="' . rmmH($id) . '" name="' . rmmH($name) . '"><option value=""' . ($cur === null ? ' selected' : '') . '>' . rmmH($unsetLabel) . '</option>';
    foreach ($opts as $k => $w) {
        $o .= '<option value="' . rmmH($k) . '"' . ($cur === (string) $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }

    return $o . '</select>';
}

/** A boolean setting as true / false / not set. */
function rivetRmmPatBoolValue(mixed $v): ?string
{
    return $v === null ? null : (!empty($v) ? '1' : '0');
}

/** @param array<string,mixed> $vm rivetRmmPatPoliciesView() */
function rivetRmmPatPolicyForm(array $vm): string
{
    $edit = $vm['edit'];
    $draft = $vm['draft'];
    $id = $edit !== null ? (int) $edit['policy_id'] : 0;
    $s = $edit !== null ? (array) $edit['settings'] : [];
    $val = static fn (string $k, mixed $def = null): mixed => $draft !== null ? ($draft['s_' . $k] ?? $def) : ($s[$k] ?? $def);
    $str = static fn (string $k): ?string => ($v = $val($k)) === null || $v === '' ? null : (string) $v;
    $name = $draft !== null ? (string) ($draft['name'] ?? '') : (string) ($edit['name'] ?? '');
    $desc = $draft !== null ? (string) ($draft['description'] ?? '') : (string) ($edit['description'] ?? '');
    $enabled = $draft !== null ? !empty($draft['enabled']) : ($edit === null || !empty($edit['enabled']));
    $ringOpts = [];
    foreach ($vm['rings'] as $g) {
        $ringOpts[(string) $g['name']] = $g['name'] . ' (' . (int) $g['delay_days'] . ' days)';
    }
    $winOpts = [];
    foreach ($vm['windows'] as $w) {
        $winOpts[(string) (int) $w['window_id']] = (string) $w['name'];
    }
    $o = '<form method="post" action="/agent/post/' . RIVET_RMM_PAT_HANDLER . '.php" id="pol-form" data-rmm-once="1" class="rmm-pat-form">' . rivetRmmAutoHidden((string) $vm['csrf'], '/agent/rmm_patch_policies.php' . ($id > 0 ? '?policy_id=' . $id : '?new=1'))
        . '<input type="hidden" name="action" value="save_policy"><input type="hidden" name="policy_id" value="' . $id . '"><div class="row g-3">'
        . '<div class="col-md-6"><label class="form-label" for="pp_name">Name</label><input class="form-control" id="pp_name" name="name" maxlength="100" required value="' . rmmH($name) . '"></div>'
        . '<div class="col-md-6"><label class="form-label" for="pp_desc">Description (optional)</label><input class="form-control" id="pp_desc" name="description" maxlength="300" value="' . rmmH($desc) . '"></div>'
        . '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="pp_enabled" name="enabled" value="1"' . ($enabled ? ' checked' : '') . '><label class="form-check-label" for="pp_enabled">Policy is on</label></div></div></div>'
        . '<p class="form-text mt-3">A policy only holds what it wants to decide. A setting left as "Not set" is decided by a broader policy, or falls back to the safe default: manual approval, nothing installed by itself, no restart. The most specific assignment wins (device, tag, group, site, department, everything).</p>'
        . '<h6 class="mt-3">Approval</h6><div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label" for="pp_ring">Ring</label>' . rivetRmmPatSelect('pp_ring', 's_ring', $ringOpts, $str('ring')) . '<div class="form-text">A ring only adds days to automatic approvals.</div></div>'
        . '<div class="col-md-4"><label class="form-label" for="pp_mode">Approval mode</label>' . rivetRmmPatSelect('pp_mode', 's_approval_mode', ['manual' => 'A person approves', 'auto' => 'Approve by itself after N days'], $str('approval_mode')) . '</div>'
        . '<div class="col-md-4"><label class="form-label" for="pp_two">Second person for manual approvals</label>' . rivetRmmPatSelect('pp_two', 's_two_person', ['1' => 'Yes: a manual approval waits for a second person', '0' => 'No'], rivetRmmPatBoolValue($val('two_person'))) . '</div></div>'
        . '<fieldset class="mt-3"><legend class="fs-6">Automatic approval: days after the update was first seen (plus the ring\'s delay). Leave a kind empty and it is never approved by itself.</legend><div class="row g-2">';
    foreach (['critical', 'security', 'important', 'optional', 'driver'] as $k) {
        $v = $val('auto_days_' . $k);
        $o .= '<div class="col-6 col-md-2"><label class="form-label small" for="pp_ad_' . $k . '">' . rmmH(RIVET_RMM_PAT_KINDS[$k]) . '</label><input class="form-control" type="number" min="0" max="365" id="pp_ad_' . $k . '" name="s_auto_days_' . $k . '" value="' . rmmH($v === null ? '' : (string) $v) . '" placeholder="never"></div>';
    }
    $curMode = $str('window_mode');
    $o .= '</div></fieldset><h6 class="mt-4">Installation</h6><div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label" for="pp_inst">Let the scheduler install approved updates</label>' . rivetRmmPatSelect('pp_inst', 's_install_enabled', ['1' => 'Yes', '0' => 'No'], rivetRmmPatBoolValue($val('install_enabled'))) . '<div class="form-text">Until live installs are switched on under Administration, the scheduler only queues a dry run.</div></div>'
        . '<div class="col-md-4"><label class="form-label" for="pp_max">Longest an install may run (minutes)</label><input class="form-control" type="number" min="1" max="240" id="pp_max" name="s_max_minutes" value="' . rmmH($val('max_minutes') === null ? '' : (string) $val('max_minutes')) . '" placeholder="60"></div>'
        . '<div class="col-md-4"><label class="form-label" for="pat_window_mode">Install window</label>' . rivetRmmPatSelect('pat_window_mode', 's_window_mode', ['none' => 'Any time', 'daily' => 'A repeating daily window', 'maintenance' => 'Only while a maintenance window is open'], $curMode) . '</div></div>'
        . '<div class="row g-3 mt-1" data-rmm-when="#pat_window_mode=daily"><div class="col-12"><span class="form-label d-block" id="pp_days_h">Days</span><div role="group" aria-labelledby="pp_days_h">';
    $curDays = $val('window_days') === null ? [] : explode(',', (string) $val('window_days'));
    foreach (RIVET_RMM_PAT_DAYS as $k => $w) {
        $o .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="pp_day_' . $k . '" name="s_window_days[]" value="' . $k . '"' . (in_array($k, $curDays, true) ? ' checked' : '') . '><label class="form-check-label" for="pp_day_' . $k . '">' . $w . '</label></div>';
    }
    $o .= '</div></div><div class="col-md-3"><label class="form-label" for="pp_ws">Starts at (HH:MM)</label><input class="form-control" id="pp_ws" name="s_window_start" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" maxlength="5" value="' . rmmH((string) $val('window_start', '')) . '" placeholder="02:00"></div>'
        . '<div class="col-md-3"><label class="form-label" for="pp_wm">Length (minutes)</label><input class="form-control" type="number" min="5" max="1440" id="pp_wm" name="s_window_minutes" value="' . rmmH($val('window_minutes') === null ? '' : (string) $val('window_minutes')) . '" placeholder="120"></div>'
        . '<div class="col-md-6">' . '<label class="form-label" for="pp_wtz">Time zone</label>' . (function_exists('rivetRmmAlrZoneSelect') ? rivetRmmAlrZoneSelect('pp_wtz', 's_window_tz', (string) $val('window_tz', 'UTC'), 'UTC') : '<input class="form-control" id="pp_wtz" name="s_window_tz" value="' . rmmH((string) $val('window_tz', 'UTC')) . '">') . '</div></div>'
        . '<div class="row g-3 mt-1" data-rmm-when="#pat_window_mode=maintenance"><div class="col-md-6"><label class="form-label" for="pp_wmid">Maintenance window</label>';
    $curWin = $str('window_maintenance_id');
    if ($winOpts === [] && $curWin === null) {
        $o .= '<div class="form-text">No maintenance window exists (or none you can see). Create one under Maintenance windows first.</div>';
    } else {
        if ($curWin !== null && !isset($winOpts[$curWin])) {
            $winOpts[$curWin] = 'Window #' . $curWin;
        }
        $o .= rivetRmmPatSelect('pp_wmid', 's_window_maintenance_id', $winOpts, $curWin, 'Choose a window');
    }
    $o .= '<div class="form-text">The install happens only while that window is open: an "allow work" window is the maintenance time.</div></div></div>'
        . '<h6 class="mt-4">Restart</h6><div class="row g-3"><div class="col-md-5"><label class="form-label" for="pat_reboot">After a clean install</label>'
        . rivetRmmPatSelect('pat_reboot', 's_reboot_policy', ['never' => 'Never restart', 'if_required_defer' => 'Report that a restart is needed, do not restart', 'if_required_now' => 'Restart when needed (a separate restart job, 5 minutes later)'], $str('reboot_policy'))
        . '</div><div class="col-md-7" data-rmm-when="#pat_reboot=if_required_now"><div class="form-check mt-md-4"><input class="form-check-input" type="checkbox" id="pp_rbc" name="s_reboot_confirm" value="1"' . (!empty($val('reboot_confirm')) ? ' checked' : '') . '><label class="form-check-label" for="pp_rbc">I accept that devices of this policy restart by themselves after patching. (Needs the grant to restart devices.)</label></div></div></div>'
        . '<div class="rmm-sticky-form mt-4"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>' . ($id > 0 ? 'Save policy' : 'Create policy') . '</button> <a class="btn btn-outline-secondary" href="/agent/rmm_patch_policies.php">Cancel</a></div></form>';

    return $o;
}

/** Turn the posted policy form into the body Core takes. @param array<string,mixed> $post @return array{0:?array<string,mixed>,1:string} */
function rivetRmmPatPolicyBody(array $post): array
{
    $str = static fn (string $k, int $max = 200): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $settings = [];
    foreach (['ring', 'approval_mode', 'window_mode', 'reboot_policy'] as $k) {
        $v = $str('s_' . $k, 60);
        if ($v !== '') {
            $settings[$k] = $v;
        }
    }
    foreach (['install_enabled', 'two_person'] as $k) {
        $v = $str('s_' . $k, 1);
        if ($v === '1' || $v === '0') {
            $settings[$k] = $v === '1';
        }
    }
    foreach (['critical', 'security', 'important', 'optional', 'driver'] as $k) {
        $v = $str('s_auto_days_' . $k, 5);
        if ($v !== '') {
            if (preg_match('/^\d{1,3}$/', $v) !== 1) {
                return [null, 'The days for ' . $k . ' updates must be a whole number from 0 to 365.'];
            }
            $settings['auto_days_' . $k] = (int) $v;
        }
    }
    $max = $str('s_max_minutes', 5);
    if ($max !== '') {
        if (preg_match('/^\d{1,4}$/', $max) !== 1) {
            return [null, 'The longest install time must be a whole number of minutes.'];
        }
        $settings['max_minutes'] = (int) $max;
    }
    if (($settings['window_mode'] ?? '') === 'daily') {
        $days = is_array($post['s_window_days'] ?? null) ? array_values(array_filter($post['s_window_days'], 'is_string')) : [];
        $settings['window_days'] = implode(',', $days);
        $settings['window_start'] = $str('s_window_start', 5);
        $wm = $str('s_window_minutes', 5);
        $settings['window_minutes'] = preg_match('/^\d{1,4}$/', $wm) === 1 ? (int) $wm : 0;
        $tz = $str('s_window_tz', 64);
        if ($tz !== '') {
            $settings['window_tz'] = $tz;
        }
    }
    if (($settings['window_mode'] ?? '') === 'maintenance') {
        $mid = $str('s_window_maintenance_id', 12);
        $settings['window_maintenance_id'] = preg_match('/^\d{1,9}$/', $mid) === 1 ? (int) $mid : 0;
    }
    if (($settings['reboot_policy'] ?? '') === 'if_required_now' && $str('s_reboot_confirm', 1) === '1') {
        $settings['reboot_confirm'] = true;
    }

    return [['name' => $str('name', 100), 'description' => $str('description', 300), 'enabled' => $str('enabled', 1) === '1', 'settings' => $settings === [] ? new \stdClass() : $settings], ''];
}

/** What to keep of a refused form so the page can show it again. @param array<string,mixed> $post @return array<string,mixed> */
function rivetRmmPatDraft(array $post, int $id): array
{
    $keep = ['__id' => $id];
    foreach ($post as $k => $v) {
        if (is_string($k) && !in_array($k, ['csrf_token', 'return', 'action'], true) && (is_string($v) || (is_array($v) && array_filter($v, 'is_string') === $v))) {
            $keep[$k] = is_string($v) ? mb_substr($v, 0, 400) : array_slice($v, 0, 20);
        }
    }

    return $keep;
}

/** The policies card: one row per policy with where it applies. @param array<string,mixed> $vm */
function rivetRmmPatPoliciesTable(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_patch_policies.php';
    if ($vm['policies'] === []) {
        return rivetRmmUiEmpty('fas fa-calendar-check', 'No patch policy yet', 'Without a patch policy every device uses the safe defaults: updates are approved by a person, nothing is installed by the scheduler and nothing is restarted.',
            $perm['manage'] && $vm['unrestricted'] ? '<a class="btn btn-primary" href="' . $self . '?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New policy</a>' : '');
    }
    $rows = '';
    foreach ($vm['policies'] as $p) {
        $pid = (int) $p['policy_id'];
        $ass = '';
        foreach ((array) ($p['assignments'] ?? []) as $a) {
            $label = rivetRmmAutoScopeLabel((string) $a['scope_type'], (int) $a['scope_id'], $vm['scope_data']);
            $ass .= '<div class="d-flex align-items-center" style="gap:6px"><span class="rmm-wrap">' . rmmH($label) . '</span><span class="small text-muted">priority ' . (int) $a['priority'] . (!empty($a['enforce']) ? ', enforced' : '') . '</span>'
                . ($perm['manage'] && $vm['unrestricted'] ? rivetRmmAutoActionForm(RIVET_RMM_PAT_HANDLER, 'unassign_policy', ['policy_id' => $pid, 'assignment_id' => (int) $a['assignment_id']], $csrf, $self . '?policy_id=' . $pid,
                    '<i class="fas fa-times" aria-hidden="true"></i><span class="visually-hidden">Remove the assignment to ' . rmmH($label) . '</span>', 'btn btn-xs btn-outline-secondary', 'Remove this assignment? The policy stops applying to ' . $label . '.') : '') . '</div>';
        }
        $actions = $perm['manage'] && $vm['unrestricted'] ? '<a class="btn btn-sm btn-outline-primary" href="' . $self . '?policy_id=' . $pid . '"><i class="fas fa-edit me-1" aria-hidden="true"></i>Edit</a> '
            . rivetRmmAutoActionForm(RIVET_RMM_PAT_HANDLER, 'delete_policy', ['policy_id' => $pid], $csrf, $self, '<i class="fas fa-trash me-1" aria-hidden="true"></i>Delete', 'btn btn-sm btn-outline-danger', 'Delete the policy "' . $p['name'] . '" and its assignments? The devices it covered fall back to broader policies or the safe defaults.')
            : '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?policy_id=' . $pid . '">View</a>';
        $rows .= '<tr><td class="ps-3 fw-semibold rmm-wrap">' . rmmH($p['name']) . ($p['description'] !== '' ? '<div class="small text-muted fw-normal">' . rmmH($p['description']) . '</div>' : '') . '</td><td>' . (!empty($p['enabled']) ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off'))
            . '</td><td class="small rmm-wrap">' . rmmH(rivetRmmPatSettingsText((array) $p['settings'])) . '</td><td class="small">' . ($ass !== '' ? $ass : '<span class="text-muted">Not assigned: applies to nothing</span>') . '</td><td class="rmm-table-actions">' . $actions . '</td></tr>';
    }

    return '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Patch policies</caption>' . rivetRmmUiThead(['Policy', 'State', 'What it decides', 'Applies to', '']) . '<tbody>' . $rows . '</tbody></table></div>';
}

/** Assignment form of one policy. @param array<string,mixed> $vm */
function rivetRmmPatAssignForm(array $vm, array $policy): string
{
    $pid = (int) $policy['policy_id'];

    return '<form method="post" action="/agent/post/' . RIVET_RMM_PAT_HANDLER . '.php" data-rmm-once="1">' . rivetRmmAutoHidden((string) $vm['csrf'], '/agent/rmm_patch_policies.php?policy_id=' . $pid) . '<input type="hidden" name="action" value="assign_policy"><input type="hidden" name="policy_id" value="' . $pid . '">'
        . '<div class="row g-3 align-items-end"><div class="col-md-5">' . rivetRmmAutoScopePicker('asg', ['global', 'client', 'site', 'group', 'tag', 'device'], 'global', 0, $vm['scope_data'], 'Apply this policy to') . '</div>'
        . '<div class="col-md-2"><label class="form-label" for="asg_prio">Priority</label><input class="form-control" type="number" min="0" max="1000000" id="asg_prio" name="priority" value="100"><div class="form-text">Lower wins on the same level.</div></div>'
        . '<div class="col-md-3"><div class="form-check"><input class="form-check-input" type="checkbox" id="asg_enf" name="enforce" value="1"><label class="form-check-label" for="asg_enf">Enforce (a less specific policy cannot override it)</label></div></div>'
        . '<div class="col-md-2"><button class="btn btn-outline-primary" type="submit"><i class="fas fa-link me-1" aria-hidden="true"></i>Assign</button></div></div></form>';
}

function rivetRmmPatRingsCard(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_patch_policies.php';
    $can = $perm['manage'] && $vm['unrestricted'];
    $rows = '';
    foreach ($vm['rings'] as $g) {
        $gid = (int) $g['ring_id'];
        $rows .= '<tr><td class="ps-3 fw-semibold">' . rmmH($g['name']) . '</td><td>' . (int) $g['delay_days'] . ' days</td><td>' . (int) $g['sort_order'] . '</td><td class="small rmm-wrap">' . rmmH($g['description']) . '</td><td class="rmm-table-actions">'
            . ($can ? '<a class="btn btn-sm btn-outline-primary" href="' . $self . '?ring_id=' . $gid . '#rings">Edit</a> ' . rivetRmmAutoActionForm(RIVET_RMM_PAT_HANDLER, 'delete_ring', ['ring_id' => $gid], $csrf, $self . '#rings', 'Delete', 'btn btn-sm btn-outline-danger', 'Delete the ring "' . $g['name'] . '"? A ring a policy or rule still uses cannot be deleted.') : '') . '</td></tr>';
    }
    $e = $vm['edit_ring'];
    $form = '';
    if ($can) {
        $form = '<form method="post" action="/agent/post/' . RIVET_RMM_PAT_HANDLER . '.php" class="p-3 border-top" data-rmm-once="1" id="ring-form">' . rivetRmmAutoHidden($csrf, $self . '#rings') . '<input type="hidden" name="action" value="save_ring"><input type="hidden" name="ring_id" value="' . (int) ($e['ring_id'] ?? 0) . '">'
            . '<h6 class="mb-2">' . ($e !== null ? 'Change ring' : 'New ring') . '</h6><div class="row g-2 align-items-end">'
            . '<div class="col-md-3"><label class="form-label small" for="rg_name">Name</label><input class="form-control form-control-sm" id="rg_name" name="name" maxlength="39" pattern="[a-z][a-z0-9_\-]{0,38}" required value="' . rmmH($e['name'] ?? '') . '"><div class="form-text">Lower case, digits, - or _.</div></div>'
            . '<div class="col-md-2"><label class="form-label small" for="rg_delay">Delay (days)</label><input class="form-control form-control-sm" type="number" min="0" max="365" id="rg_delay" name="delay_days" required value="' . (int) ($e['delay_days'] ?? 0) . '"></div>'
            . '<div class="col-md-2"><label class="form-label small" for="rg_ord">Sort order</label><input class="form-control form-control-sm" type="number" min="0" max="100000" id="rg_ord" name="sort_order" value="' . (int) ($e['sort_order'] ?? 100) . '"></div>'
            . '<div class="col-md-3"><label class="form-label small" for="rg_desc">Description</label><input class="form-control form-control-sm" id="rg_desc" name="description" maxlength="200" value="' . rmmH($e['description'] ?? '') . '"></div>'
            . '<div class="col-md-2"><button class="btn btn-sm btn-primary" type="submit">Save ring</button>' . ($e !== null ? ' <a class="btn btn-sm btn-outline-secondary" href="' . $self . '#rings">Cancel</a>' : '') . '</div></div></form>';
    } else {
        $form = '<p class="small text-muted p-3 mb-0 border-top">' . ($perm['manage'] ? 'Rings and patch policies apply to every department, so they need access to every department.' : 'Your role can read rings but not change them.') . '</p>';
    }

    return rivetRmmUiCard('Rings', 'sitemap', '<p class="small text-muted px-3 pt-3 mb-2">A ring is only a delay: its days are added to every automatic approval of the devices whose policy names it. Test machines in <code>pilot</code> (0 days), most devices in <code>broad</code>, the machines that matter most in <code>last</code>.</p>'
        . '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Patch rings</caption>' . rivetRmmUiThead(['Ring', 'Delay', 'Order', 'Description', '']) . '<tbody>' . $rows . '</tbody></table></div>' . $form, '', '', true, 'rings');
}

function rivetRmmPatRulesCard(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_patch_policies.php';
    $can = $perm['manage'];
    $clients = (array) $vm['scope_data']['clients'];
    $rows = '';
    foreach ($vm['rules'] as $r) {
        $rid = (int) $r['rule_id'];
        $cl = (int) $r['client_id'] === 0 ? 'All departments' : ($clients[(int) $r['client_id']] ?? 'Department #' . (int) $r['client_id']);
        $rows .= '<tr><td class="ps-3 fw-semibold rmm-wrap">' . rmmH($r['name']) . '</td><td>' . (!empty($r['enabled']) ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off'))
            . '</td><td class="small">' . rmmH(($r['kinds'] === [] ? 'any kind' : implode(', ', $r['kinds'])) . '; ' . ($r['severities'] === [] ? 'any severity' : implode(', ', $r['severities']))) . '</td><td>' . (int) $r['after_days'] . ' days'
            . ($r['ring'] !== null ? ' + ring ' . rmmH($r['ring']) : '') . '</td><td class="small">' . rmmH($cl) . '</td><td class="rmm-table-actions">'
            . ($can ? '<a class="btn btn-sm btn-outline-primary" href="' . $self . '?rule_id=' . $rid . '#rules">Edit</a> ' . rivetRmmAutoActionForm(RIVET_RMM_PAT_HANDLER, 'delete_rule', ['rule_id' => $rid], $csrf, $self . '#rules', 'Delete', 'btn btn-sm btn-outline-danger', 'Delete the rule "' . $r['name'] . '"? Updates it already approved stay approved.') : '') . '</td></tr>';
    }
    $tbl = $rows === '' ? '<p class="small text-muted px-3 pt-3 mb-2">No rule yet. A rule approves matching updates by itself, once they have been missing long enough.</p>'
        : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Patch approval rules</caption>' . rivetRmmUiThead(['Rule', 'State', 'Matches', 'Approves after', 'Applies to', '']) . '<tbody>' . $rows . '</tbody></table></div>';
    $e = $vm['edit_rule'];
    $form = '';
    if ($can) {
        $d = $vm['rule_draft'];
        $v = static fn (string $k, mixed $def = '') => $d !== null ? ($d[$k] ?? $def) : ($e[$k] ?? $def);
        $kinds = $d !== null ? (array) ($d['kinds'] ?? []) : (array) ($e['kinds'] ?? []);
        $sevs = $d !== null ? (array) ($d['severities'] ?? []) : (array) ($e['severities'] ?? []);
        $ringOpts = [];
        foreach ($vm['rings'] as $g) {
            $ringOpts[(string) $g['name']] = $g['name'] . ' (' . (int) $g['delay_days'] . ' days)';
        }
        $kb = '';
        foreach (RIVET_RMM_PAT_KINDS as $k => $w) {
            $kb .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="ru_k_' . $k . '" name="kinds[]" value="' . $k . '"' . (in_array($k, $kinds, true) ? ' checked' : '') . '><label class="form-check-label" for="ru_k_' . $k . '">' . $w . '</label></div>';
        }
        $sb = '';
        foreach (RIVET_RMM_PAT_SEVERITIES as $k => $w) {
            $sb .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="ru_s_' . $k . '" name="severities[]" value="' . $k . '"' . (in_array($k, $sevs, true) ? ' checked' : '') . '><label class="form-check-label" for="ru_s_' . $k . '">' . $w . '</label></div>';
        }
        $co = '<option value="0"' . ((int) $v('client_id', 0) === 0 ? ' selected' : '') . '>All departments' . ($vm['unrestricted'] ? '' : ' (needs access to every department)') . '</option>';
        foreach ($clients as $cid => $cname) {
            $co .= '<option value="' . (int) $cid . '"' . ((int) $v('client_id', 0) === (int) $cid ? ' selected' : '') . '>' . rmmH($cname) . '</option>';
        }
        $form = '<form method="post" action="/agent/post/' . RIVET_RMM_PAT_HANDLER . '.php" class="p-3 border-top" data-rmm-once="1" id="rule-form">' . rivetRmmAutoHidden($csrf, $self . ($e !== null && isset($e['rule_id']) ? '?rule_id=' . (int) $e['rule_id'] : '?new_rule=1') . '#rules')
            . '<input type="hidden" name="action" value="save_rule"><input type="hidden" name="rule_id" value="' . (int) ($e['rule_id'] ?? 0) . '"><h6 class="mb-2">' . ($e !== null && isset($e['rule_id']) ? 'Change rule' : 'New rule') . '</h6><div class="row g-3">'
            . '<div class="col-md-4"><label class="form-label small" for="ru_name">Name</label><input class="form-control form-control-sm" id="ru_name" name="name" maxlength="100" required value="' . rmmH($v('name')) . '"></div>'
            . '<div class="col-md-2"><label class="form-label small" for="ru_days">Approve after (days)</label><input class="form-control form-control-sm" type="number" min="0" max="365" id="ru_days" name="after_days" required value="' . (int) $v('after_days', 3) . '"></div>'
            . '<div class="col-md-3"><label class="form-label small" for="ru_ring">Ring (adds its delay)</label>' . rivetRmmPatSelect('ru_ring', 'ring', $ringOpts, $v('ring', '') === '' || $v('ring', '') === null ? null : (string) $v('ring'), 'No ring') . '</div>'
            . '<div class="col-md-3"><label class="form-label small" for="ru_client">Applies to</label><select class="form-select form-select-sm" id="ru_client" name="client_id">' . $co . '</select></div>'
            . '<div class="col-12"><span class="form-label small d-block" id="ru_kh">Kinds (none ticked: any)</span><div role="group" aria-labelledby="ru_kh">' . $kb . '</div></div>'
            . '<div class="col-12"><span class="form-label small d-block" id="ru_sh">Severities (none ticked: any)</span><div role="group" aria-labelledby="ru_sh">' . $sb . '</div></div>'
            . '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="ru_en" name="enabled" value="1"' . ($d !== null ? (!empty($d['enabled']) ? ' checked' : '') : ($e === null || !isset($e['enabled']) || !empty($e['enabled']) ? ' checked' : '')) . '><label class="form-check-label" for="ru_en">Rule is on</label></div></div></div>'
            . '<div class="mt-3"><button class="btn btn-sm btn-primary" type="submit">Save rule</button>' . ($e !== null ? ' <a class="btn btn-sm btn-outline-secondary" href="' . $self . '#rules">Cancel</a>' : ' <a class="btn btn-sm btn-outline-secondary" href="' . $self . '?new_rule=1#rules">Start a new rule</a>') . '</div></form>';
    } else {
        $form = '<p class="small text-muted p-3 mb-0 border-top">Your role can read approval rules but not change them.</p>';
    }

    return rivetRmmUiCard('Approval rules', 'robot', '<p class="small text-muted px-3 pt-3 mb-2">A rule approves matching updates by itself: "critical and security updates, after 3 days, in ring pilot". The first rule that matches wins. A declined update is never approved by a rule.</p>' . $tbl . $form, '', '', true, 'rules');
}

/** @param array<string,mixed> $vm rivetRmmPatPoliciesView() */
function rivetRmmPatPoliciesPage(array $vm): string
{
    $perm = $vm['perm'];
    $new = $perm['manage'] && $vm['unrestricted'] && $vm['form'] === null ? '<a class="btn btn-sm btn-primary" href="/agent/rmm_patch_policies.php?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New policy</a> ' : '';
    $o = rivetRmmAutoHeader('Patch policies', 'calendar-check', $new . '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patches.php"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i>Patch compliance</a>',
        'How updates are approved, when they may be installed and whether a device restarts. Nothing is installed by approving an update; installation needs a policy that allows it, an open window, and (for scheduled installs) live installs switched on under Administration.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if (!$perm['manage']) {
        $o .= '<div class="alert alert-info py-2"><i class="fas fa-eye me-2" aria-hidden="true"></i>Your role can read patch policies but not change them.</div>';
    } elseif (!$vm['unrestricted']) {
        $o .= '<div class="alert alert-info py-2"><i class="fas fa-info-circle me-2" aria-hidden="true"></i>Patch policies and rings apply to every department, so only a user with access to every department can change them. You can still manage approval rules for your own departments.</div>';
    }
    if ($vm['form'] !== null && $perm['manage'] && $vm['unrestricted']) {
        $o .= rivetRmmUiCard($vm['form'] === 'new' ? 'New patch policy' : 'Change patch policy', 'edit', rivetRmmPatPolicyForm($vm), '', '', false, 'pol-edit');
        if ($vm['edit'] !== null) {
            $o .= rivetRmmUiCard('Where "' . rmmH($vm['edit']['name']) . '" applies', 'link', rivetRmmPatAssignForm($vm, $vm['edit']), '', '', false, 'pol-assign');
        }
    } elseif ($vm['form'] === 'edit' && $vm['edit'] !== null) {
        $o .= rivetRmmUiCard(rmmH($vm['edit']['name']), 'calendar-check', '<p class="mb-1">' . rmmH(rivetRmmPatSettingsText((array) $vm['edit']['settings'])) . '</p>', '', '', false);
    }
    $o .= rivetRmmUiCard('Policies', 'calendar-check', rivetRmmPatPoliciesTable($vm), '', '', true, 'policies') . rivetRmmPatRingsCard($vm) . rivetRmmPatRulesCard($vm);

    return $o . rivetRmmAutoConfirmModal();
}

/** Turn the rule form into the body Core takes. @param array<string,mixed> $post @return array{0:?array<string,mixed>,1:string} */
function rivetRmmPatRuleBody(array $post): array
{
    $str = static fn (string $k, int $max = 200): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $list = static fn (string $k): array => is_array($post[$k] ?? null) ? array_values(array_unique(array_filter($post[$k], 'is_string'))) : [];
    $days = $str('after_days', 5);
    if (preg_match('/^\d{1,3}$/', $days) !== 1) {
        return [null, 'Days must be a whole number from 0 to 365.'];
    }
    $client = $str('client_id', 12);
    if ($client === '') {
        $client = '0';
    }
    if (preg_match('/^\d{1,9}$/', $client) !== 1) {
        return [null, 'Choose a department or all departments.'];
    }
    $body = ['name' => $str('name', 100), 'enabled' => $str('enabled', 1) === '1', 'kinds' => $list('kinds'), 'severities' => $list('severities'), 'after_days' => (int) $days, 'client_id' => (int) $client];
    $ring = $str('ring', 40);
    if ($ring !== '') {
        $body['ring'] = $ring;
    }

    return [$body, ''];
}

/** Turn the install form into the body Core takes. @param array<string,mixed> $post @return array<string,mixed> */
function rivetRmmPatInstallBody(array $post, string $mode): array
{
    $body = ['mode' => $mode];
    $ids = is_array($post['update_ids'] ?? null) ? array_values(array_filter($post['update_ids'], 'is_string')) : [];
    if ($ids !== []) {
        $body['ids'] = array_slice($ids, 0, 40);
    }
    if ($mode === 'install') {
        $body['confirm'] = true;   // the dialog's explicit confirmation; Core refuses without it and re-checks every ability
        $rp = is_string($post['reboot_policy'] ?? null) ? $post['reboot_policy'] : 'never';
        $body['reboot_policy'] = in_array($rp, ['never', 'if_required_defer', 'if_required_now'], true) ? $rp : 'never';
        if ($body['reboot_policy'] === 'if_required_now') {
            $body['confirm_reboot'] = !empty($post['confirm_reboot']);
        }
    }

    return $body;
}
