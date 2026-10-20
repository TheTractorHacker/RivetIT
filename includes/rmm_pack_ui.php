<?php

/*
 * RMM policy starter pack (RivetCore 1.0.0-rc.27, no migration, built in Core): the "Starter policies" card on the Policies page
 * (agent/rmm_policies.php, administrators only) and the small "Starter" badge the Policies and Maintenance windows lists show for an
 * entity the pack installed. Its POST handler is agent/post/rmm_policy_pack.php.
 *
 * Everything goes through RivetCore's technician API as the signed-in user (rivetRmmAutoApi -> endpoint_devices/starter/policies[...]):
 * `rmm.admin` is checked there, and the pack's own safety rules (every entity created off and unassigned, an edited entity is never
 * overwritten, a live entity needs include_live to update, an instance-wide item needs confirm_instance_wide) are Core's, not ours.
 *
 * Marker identity needs no table of our own: RivetCore\Rmm\Policy\StarterPack\PolicyPack already owns the ` [pp|<item id>|<rev>|<hash>]`
 * marker (parseMarker()/stripMarker()); this file only calls those, it never re-implements the pattern.
 *
 * View-model functions return arrays (testable); renderers return strings. Everything printed is escaped. No inline script or handler
 * (js/rmm_pack.js does the ticking and the instance-wide guard).
 */

require_once __DIR__ . '/rmm_automation.php';
require_once __DIR__ . '/rmm_alr_ui.php';   // rivetRmmAlrZoneSelect() for the maintenance window time zone parameter

use RivetCore\Rmm\Policy\StarterPack\PolicyPack;

const RIVET_RMM_PACK_HANDLER = 'rmm_policy_pack';

/**
 * Items an edition considers a sane starting point for "Install recommended". Core's manifest carries no such flag (every item is equally
 * installable, off and unassigned); this is this edition's own editorial choice, not Core's. Role add-ons, the laptop add-on, the
 * low-noise/quiet/critical-only alternatives and the instance-wide change-monitoring item are left out on purpose: an administrator picks
 * those deliberately.
 */
const RIVET_RMM_PACK_RECOMMENDED = [
    'windows-workstation', 'windows-server', 'linux-server', 'alert-workstation', 'alert-server',
    'patch-pilot', 'patch-standard', 'patch-servers', 'mw-nightly', 'mw-sunday-patching', 'mw-server-patching-sat',
];

// ------------------------------------------------------------------ the marker (shared by the Policies and Maintenance windows lists)

/**
 * Strips the pack's ` [pp|<item id>|<rev>|<hash>]` marker from a description or note and returns the badge to show next to it, or ['' , $text]
 * unchanged when there is no marker (an ordinary administrator-written entity). Call this everywhere a policy description, a patch policy
 * description or a maintenance window note is displayed to a person; never show the raw marker.
 *
 * @return array{text:string,badge:string}
 */
function rivetRmmPackStrip(string $text): array
{
    if (PolicyPack::parseMarker($text) === null) {
        return ['text' => $text, 'badge' => ''];
    }

    return ['text' => PolicyPack::stripMarker($text), 'badge' => '<span class="badge text-bg-primary rmm-pack-badge" title="Installed from the policy starter pack"><i class="fas fa-box-open me-1" aria-hidden="true"></i>Starter</span> '];
}

// ------------------------------------------------------------------ pills

function rivetRmmPackStatePill(string $state): string
{
    $map = ['not_installed' => ['off', 'Not installed'], 'installed' => ['ok', 'Installed'], 'update_available' => ['info', 'Update available'], 'edited' => ['warn', 'Edited']];
    [$kind, $text] = $map[$state] ?? ['off', ucfirst(str_replace('_', ' ', $state))];

    return rivetRmmUiPill($kind, $text);
}

function rivetRmmPackPlatformChip(string $platform): string
{
    $map = ['windows' => ['fa-brands fa-windows', 'Windows'], 'linux' => ['fa-brands fa-linux', 'Linux'], 'any' => ['fas fa-desktop', 'Windows and Linux']];
    [$icon, $text] = $map[$platform] ?? ['fas fa-desktop', ucfirst($platform)];

    return '<span class="badge text-bg-light border"><i class="' . rmmH($icon) . ' me-1" aria-hidden="true"></i>' . rmmH($text) . '</span>';
}

/** What Core says an item would do / did, as a pill (the same vocabulary as the script/policy pack result tables). */
function rivetRmmPackOutcomePill(string $outcome): string
{
    $map = [
        'added' => ['ok', 'Added'], 'updated' => ['info', 'Updated'], 'reset' => ['info', 'Reset'], 'removed' => ['ok', 'Removed'],
        'unchanged' => ['off', 'Unchanged'], 'skipped' => ['off', 'Skipped'], 'blocked' => ['crit', 'Blocked'], 'failed' => ['crit', 'Failed'],
    ];
    [$kind, $text] = $map[$outcome] ?? ['off', ucfirst($outcome)];

    return rivetRmmUiPill($kind, $text);
}

// ------------------------------------------------------------------ the view-model

/** A technician API call under endpoint_devices/starter/policies/... as the page's user. */
function rivetRmmPackCall(array $ctx, string $method, array $segments = [], ?array $body = null): array
{
    return rivetRmmAutoApi($ctx['mysqli'], (int) $ctx['uid'], (string) $ctx['name'], $method, array_merge(['starter', 'policies'], $segments), [], $body);
}

/**
 * @return array{error:string,ready:bool,pack:array{id:string,name:string,version:string},counts:array<string,int>,items:list<array<string,mixed>>,
 *     groups:array<string,list<array<string,mixed>>>,result:?array<string,mixed>}
 */
function rivetRmmPackView(array $ctx): array
{
    $r = rivetRmmPackCall($ctx, 'GET');
    $vm = ['error' => $r['ok'] ? '' : ($r['error'] !== '' ? $r['error'] : 'Could not read the starter pack.'), 'ready' => $r['ok'], 'pack' => ['id' => '', 'name' => '', 'version' => ''],
        'counts' => ['not_installed' => 0, 'installed' => 0, 'update_available' => 0, 'edited' => 0], 'items' => [], 'groups' => [], 'result' => null];
    if ($r['ok']) {
        $vm['pack'] = ['id' => (string) ($r['data']['pack_id'] ?? ''), 'name' => (string) ($r['data']['name'] ?? ''), 'version' => (string) ($r['data']['version'] ?? '')];
        $vm['counts'] = (array) ($r['data']['counts'] ?? $vm['counts']);
        foreach ((array) ($r['data']['items'] ?? []) as $it) {
            $it = (array) $it;
            $vm['items'][] = $it;
            $vm['groups'][(string) ($it['group'] ?? '')][] = $it;
        }
    }
    if (isset($_SESSION['rmm_pack_result']) && is_array($_SESSION['rmm_pack_result'])) {   // shown once, after the redirect that followed a plan or a real op
        $vm['result'] = $_SESSION['rmm_pack_result'];
        unset($_SESSION['rmm_pack_result']);
    }

    return $vm;
}

// ------------------------------------------------------------------ renderers

/** @param array<string,mixed> $res {kind, op, data, ids, params} */
function rivetRmmPackResultCard(array $res): string
{
    $kind = (string) ($res['kind'] ?? '');
    $data = (array) ($res['data'] ?? []);
    $items = (array) ($data['items'] ?? []);
    $titles = ['plan' => ['Plan: nothing was changed', 'clipboard-list'], 'install' => ['Install result', 'check-double'], 'update' => ['Update result', 'arrow-up'], 'reset' => ['Reset result', 'undo'], 'uninstall' => ['Uninstall result', 'trash-alt']];
    [$title, $icon] = $titles[$kind] ?? ['Result', 'info-circle'];
    $rows = '';
    foreach ($items as $i) {
        $i = (array) $i;
        $rows .= '<tr><td class="ps-3">' . rmmH($i['name'] ?? $i['id'] ?? '') . '</td><td>' . rivetRmmPackOutcomePill((string) ($i['outcome'] ?? '')) . '</td><td class="small">' . rmmH((string) ($i['reason'] ?? '')) . '</td></tr>';
    }
    $table = $items === [] ? '<p class="text-muted mb-0">Nothing matched.</p>'
        : '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><caption class="visually-hidden">What the starter pack ' . ($kind === 'plan' ? 'would do' : 'did') . '</caption>'
            . rivetRmmUiThead(['Item', $kind === 'plan' ? 'Would do' : 'Outcome', 'Why']) . '<tbody>' . $rows . '</tbody></table></div>';
    $counts = '';
    foreach ((array) ($data['counts'] ?? []) as $k => $n) {
        $counts .= '<span class="me-1">' . rivetRmmPackOutcomePill((string) $k) . ' <span class="small">' . (int) $n . '</span></span>';
    }

    return rivetRmmUiCard($title, $icon, '<div class="mb-2" role="status">' . $counts . '</div>' . $table, $kind === 'plan' ? 'info' : 'success', '', false, 'pack-result');
}

/** One row of a policy draft, a patch policy, a window or the change-monitoring preset. */
function rivetRmmPackItemRow(array $it, string $csrf, string $returnTo, array $ticked, bool $confirmInstanceWide): string
{
    $id = (string) $it['id'];
    $state = (string) ($it['state'] ?? 'not_installed');
    $live = (bool) ($it['enabled'] ?? false) || (int) ($it['assignments'] ?? 0) > 0;
    $instanceWide = (bool) ($it['instance_wide'] ?? false);
    $name = '<strong>' . rmmH($it['name']) . '</strong>';
    $badges = rivetRmmPackStatePill($state) . ' ' . rivetRmmPackPlatformChip((string) $it['platform']) . ($live ? ' ' . rivetRmmUiPill('warn', 'In use') : '') . ($instanceWide ? ' ' . rivetRmmUiPill('info', 'Applies to all devices') : '');
    $why = '<details><summary class="small">Why</summary><p class="small mb-1">' . rmmH((string) $it['why']) . '</p>';
    $ws = (array) ($it['why_settings'] ?? []);
    if ($ws !== []) {
        $why .= '<table class="table table-sm mb-0"><tbody>';
        foreach ($ws as $k => $v) {
            $why .= '<tr><td class="ps-3 rmm-mono small">' . rmmH((string) $k) . '</td><td class="small">' . rmmH((string) $v) . '</td></tr>';
        }
        $why .= '</tbody></table>';
    }
    $why .= '</details>';

    $actions = '';
    if (!$instanceWide) {
        if ($state === 'update_available') {
            $actions .= $live
                ? rivetRmmAutoActionForm(RIVET_RMM_PACK_HANDLER, 'update', ['ids[]' => $id, 'include_live' => 1], $csrf, $returnTo,
                    '<i class="fas fa-arrow-up me-1" aria-hidden="true"></i>Update anyway (in use)', 'btn btn-sm btn-outline-warning',
                    'This item is enabled or assigned to devices. Updating it changes every device it reaches without a review. Continue?')
                : rivetRmmAutoActionForm(RIVET_RMM_PACK_HANDLER, 'update', ['ids[]' => $id], $csrf, $returnTo, '<i class="fas fa-arrow-up me-1" aria-hidden="true"></i>Update', 'btn btn-sm btn-outline-primary');
        }
        if (in_array($state, ['update_available', 'edited'], true)) {
            $actions .= ' ' . rivetRmmAutoActionForm(RIVET_RMM_PACK_HANDLER, 'reset', ['ids[]' => $id], $csrf, $returnTo, '<i class="fas fa-undo me-1" aria-hidden="true"></i>Reset to pack version', 'btn btn-sm btn-outline-secondary',
                'This replaces your changes to this policy with the pack version.');
        }
        if ($state !== 'not_installed') {
            $actions .= ' ' . rivetRmmAutoActionForm(RIVET_RMM_PACK_HANDLER, 'uninstall', ['ids[]' => $id], $csrf, $returnTo, '<i class="fas fa-trash-alt me-1" aria-hidden="true"></i>Uninstall', 'btn btn-sm btn-outline-danger',
                'Uninstall this item? Core only removes it while it is untouched, switched off and unassigned; anything you edited or assigned is left alone.');
        }
    }
    // The instance-wide row gets the same selection checkbox as every other item (so "Install selected" can install it), but "select
    // recommended" and "select all" never tick it (data-recommended/-all are both left off): an administrator must choose it by hand, and
    // must also tick the separate confirmation below before Core will accept it (confirm_instance_wide). Install is its only real op.
    $checkbox = '<input class="form-check-input rmm-pack-pick" type="checkbox" name="ids[]" id="pack_pick_' . rmmH($id) . '" value="' . rmmH($id) . '" data-recommended="'
        . (!$instanceWide && in_array($id, RIVET_RMM_PACK_RECOMMENDED, true) ? '1' : '0') . '" data-select-all="' . ($instanceWide ? '0' : '1') . '"' . (isset($ticked[$id]) ? ' checked' : '') . ' aria-label="Select ' . rmmH($it['name']) . '">';
    $pickCell = $instanceWide
        ? '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="pack_confirm_iw" name="confirm_instance_wide" value="1"' . ($confirmInstanceWide ? ' checked' : '') . '><label class="form-check-label small" for="pack_confirm_iw">Yes, apply to all devices</label></div>'
        : '';

    return '<tr><td class="ps-3">' . $checkbox . '</td><td>' . $name . '<div class="small text-muted">' . rmmH((string) $it['summary']) . '</div>' . $why . $pickCell . '</td><td>' . $badges . '</td>'
        . '<td class="rmm-table-actions">' . ($actions === '' ? '<span class="text-muted small">&mdash;</span>' : $actions) . '</td></tr>';
}

/** Install-time parameters for the items that have any, one small block per item, grouped under one card. */
function rivetRmmPackParamsBlock(array $items, array $paramVals): string
{
    $o = '';
    foreach ($items as $it) {
        $defs = (array) ($it['params'] ?? []);
        if ($defs === []) {
            continue;
        }
        $id = (string) $it['id'];
        $fields = '';
        foreach ($defs as $name => $def) {
            $def = (array) $def;
            $fid = 'pack_param_' . $id . '_' . $name;
            $fname = 'param[' . rmmH($id) . '][' . rmmH((string) $name) . ']';
            $val = (string) ($paramVals[$id][$name] ?? '');
            $label = rmmH((string) ($def['label'] ?? $name)) . ' <span class="text-muted">(default <code>' . rmmH((string) ($def['default'] ?? '')) . '</code>)</span>';
            if ($name === 'timezone') {
                $fields .= '<div class="col-md-6"><label class="form-label" for="' . $fid . '">' . $label . '</label>' . rivetRmmAlrZoneSelect($fid, $fname, $val !== '' ? $val : 'UTC', 'UTC') . '</div>';
            } else {
                $fields .= '<div class="col-md-6"><label class="form-label" for="' . $fid . '">' . $label . '</label><input class="form-control" id="' . $fid . '" name="' . $fname . '" type="text" maxlength="64" autocomplete="off" value="' . rmmH($val) . '"></div>';
            }
        }
        $o .= '<div class="mb-3"><div class="fw-semibold small mb-1">' . rmmH((string) $it['name']) . '</div><div class="row g-2">' . $fields . '</div></div>';
    }

    return $o;
}

/** The "Starter policies" card for the Policies page (administrators only). */
function rivetRmmPackCard(array $vm, array $ctx): string
{
    if ($vm['error'] !== '') {
        return rivetRmmUiCard('Starter policies', 'box-open', '<div class="alert alert-danger mb-0" role="alert">' . rmmH($vm['error']) . '</div>', '', '', false, 'pack-card');
    }
    $returnTo = '/agent/rmm_policies.php#pack-card';
    $csrf = (string) $ctx['csrf'];
    $o = $vm['result'] !== null ? rivetRmmPackResultCard($vm['result']) : '';
    $notice = '<div class="alert alert-info d-flex gap-2" role="status"><i class="fas fa-info-circle mt-1" aria-hidden="true"></i><div>Installing creates the policies switched off and assigned to nothing. '
        . 'Nothing changes on your devices until you switch one on and assign it.</div></div>';
    $ticked = array_flip(array_map('strval', (array) ($vm['result']['ids'] ?? [])));
    $paramVals = (array) ($vm['result']['params'] ?? []);
    $confirmIw = !empty($vm['result']['confirm_instance_wide']);
    $rows = '';
    foreach ($vm['groups'] as $group => $items) {
        $rows .= '<tr class="rmm-thead"><th colspan="4" class="ps-3">' . rmmH($group) . '</th></tr>';
        foreach ($items as $it) {
            $rows .= rivetRmmPackItemRow($it, $csrf, $returnTo, $ticked, $confirmIw);
        }
    }
    $table = '<div class="table-responsive"><table class="table table-hover table-sm mb-0" id="pack-items"><caption class="visually-hidden">Policy starter pack items</caption>'
        . '<thead><tr class="rmm-thead"><th scope="col" class="ps-3" style="width:2.5rem"><span class="visually-hidden">Select</span></th><th scope="col">Item</th><th scope="col">State</th><th scope="col">Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    $params = rivetRmmPackParamsBlock($vm['items'], $paramVals);
    $c = $vm['counts'];
    $chips = '<div class="d-flex flex-wrap gap-2 mb-3">' . '<span class="badge text-bg-secondary">' . (int) $c['not_installed'] . ' not installed</span>'
        . '<span class="badge text-bg-success">' . (int) $c['installed'] . ' installed</span>' . '<span class="badge text-bg-info">' . (int) $c['update_available'] . ' update available</span>'
        . '<span class="badge text-bg-warning">' . (int) $c['edited'] . ' edited</span></div>';
    $actions = '<div class="rmm-sticky-form d-flex flex-wrap gap-2 align-items-center" id="pack-actions"><span class="small text-muted me-auto" id="pack-selected" role="status" aria-live="polite">Nothing selected.</span>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary" data-pack-select="recommended">Select recommended</button>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary" data-pack-select="all">Select all</button>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary" data-pack-select="none">Clear</button>'
        . '<button type="submit" name="action" value="plan" class="btn btn-outline-primary" id="pack-plan"><i class="fas fa-clipboard-list me-1" aria-hidden="true"></i>Plan</button>'
        . '<button type="submit" name="action" value="install" class="btn btn-primary" id="pack-install" data-rmm-confirm="Install the selected items? Every item is created switched off and assigned to nothing. Nothing changes on your devices until you switch one on and assign it." data-rmm-confirm-label="Install"><i class="fas fa-download me-1" aria-hidden="true"></i>Install selected</button></div>';
    $o .= rivetRmmUiCard('Starter policies', 'box-open',
        $notice . $chips
        . '<form method="post" action="/agent/post/' . RIVET_RMM_PACK_HANDLER . '.php" id="pack-form">' . rivetRmmAutoHidden($csrf, $returnTo)
        . $table
        . ($params === '' ? '' : '<div class="p-3 border-top"><div class="small text-muted mb-2">Install-time parameters. Leave a field at its default unless you need another value.</div>' . $params . '</div>')
        . '<div class="p-3 border-top">' . $actions . '</div></form>',
        '', '<span class="small text-muted">' . rmmH($vm['pack']['name']) . ' ' . rmmH($vm['pack']['version']) . '</span>', true, 'pack-card');

    return $o;
}

// ------------------------------------------------------------------ the handler's reading of the form

/**
 * @return array{ids:list<string>,params:array<string,array<string,string>>,raw_params:array<string,array<string,string>>,include_live:bool,confirm_instance_wide:bool}
 */
function rivetRmmPackSelectionFromPost(): array
{
    $ids = [];
    foreach ((array) ($_POST['ids'] ?? []) as $id) {
        if (is_string($id) && preg_match('/^[a-z0-9-]{1,60}$/', $id) === 1 && !in_array($id, $ids, true) && count($ids) < 100) {
            $ids[] = $id;
        }
    }
    $params = [];
    $raw = [];
    foreach ((array) ($_POST['param'] ?? []) as $itemId => $fields) {
        if (!is_string($itemId) || preg_match('/^[a-z0-9-]{1,60}$/', $itemId) !== 1 || !is_array($fields)) {
            continue;
        }
        foreach ($fields as $name => $val) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) !== 1 || !is_string($val)) {
                continue;
            }
            $val = mb_substr(trim($val), 0, 200);
            $raw[$itemId][$name] = $val;
            if ($val !== '') {
                $params[$itemId][$name] = $val;   // empty = keep the pack's default
            }
        }
    }

    return ['ids' => $ids, 'params' => $params, 'raw_params' => $raw, 'include_live' => !empty($_POST['include_live']), 'confirm_instance_wide' => !empty($_POST['confirm_instance_wide'])];
}
