<?php

/*
 * Changes tab of the device page (RivetCore 1.0.0-rc.22 change monitoring, RivetIT 26.10.65): a MINIMAL read-only list of the recent change records of one
 * device (what, old value, new value, when in the company time zone). Shown only while the `change_monitor` sub-switch is on and the viewer holds
 * rmm.change.view for the device's department. Full management (presets, custom watches, rules, the timeline) is a later task.
 *
 * Every text here came from a device and is attacker-controlled: it is escaped, never linked, never interpreted. Redacted values are shown as RivetCore
 * stored them (already masked); nothing is un-redacted.
 */

require_once __DIR__ . '/rmm_bootstrap.php';
require_once __DIR__ . '/rivet_time.php';

use RivetCore\Rmm\Authz\RmmAbility;

const RIVET_CHG_ROWS = 50;

/**
 * The Administration > Endpoint agent card for change monitoring: the on/off switch (default off, with the storage acknowledgement Core requires) and the list of
 * presets in force. Choosing presets, custom watches and rules are a later task; the card says so.
 *
 * @param array<string,mixed> $view ChangeActions::settingsRead() data ({enabled, presets[], storage_warning, ...})
 */
function rivetRmmChgAdminCard(array $view, string $csrf): string
{
    $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $on = !empty($view['enabled']);
    $o = '<div class="card mb-3" id="change-monitoring"><div class="card-header"><h4 class="card-title mb-0">Change monitoring</h4></div><div class="card-body">'
        . '<p class="small text-muted">Know what changed on a machine and when: registry values, configuration files, local group members and scheduled tasks. Off by default. Nothing is collected until this switch is on and an agent that supports it (RivetCore 1.0.0-rc.22 or later) has the new settings.</p>'
        . '<form action="post.php" method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="' . $h($csrf) . '"><input type="hidden" name="ea_tab" value="inventory">'
        . '<div class="form-check form-switch mb-2"><input type="checkbox" class="form-check-input" id="ea_chg_sw" name="change_monitor" value="1"' . ($on ? ' checked' : '') . ' aria-describedby="ea_chg_h"><label class="form-check-label fw-semibold" for="ea_chg_sw">Monitor changes on devices (<code>change_monitor</code>)</label>'
        . '<div class="form-text" id="ea_chg_h">Takes effect only while the RMM module itself is on.</div></div>';
    if (!$on) {
        $o .= '<div class="alert alert-warning small"><strong>Before you turn it on.</strong> ' . $h($view['storage_warning'] ?? '') . '<div class="form-check mt-2"><input type="checkbox" class="form-check-input" id="ea_chg_ack" name="accept_change_storage" value="1"><label class="form-check-label" for="ea_chg_ack">I understand what is stored and where.</label></div></div>';
    }
    $o .= '<button type="submit" name="save_change_monitor" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>Save change monitoring</button></form>'
        . '<h6 class="mt-4">What it can watch (presets)</h6><p class="small text-muted mb-2">Choosing presets, adding your own watches and alert rules is not built into this screen yet; the recommended presets are shown here so you know what turning it on covers. This is a later task.</p>';
    $rows = '';
    foreach ((array) ($view['presets'] ?? []) as $p) {
        $rows .= '<tr><td>' . $h($p['title'] ?? '') . (!empty($p['recommended']) ? ' <span class="badge text-bg-info">recommended</span>' : '') . '<div class="small text-muted">' . $h($p['description'] ?? '') . '</div></td>'
            . '<td class="text-nowrap">' . $h(ucfirst((string) ($p['platform'] ?? ''))) . '</td><td class="text-nowrap">' . (!empty($p['enabled']) ? 'In use' : 'Not in use') . '</td></tr>';
    }

    return $o . '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Change monitoring presets</caption><thead><tr><th>Preset</th><th>Platform</th><th>State</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div>';
}

/**
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmChgPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (!rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('change_monitor', $mysqli)) {
        return '';
    }
    $uid = (int) $vm['user_id'];
    $rmm = rivetRmmModule($mysqli);
    $dev = $rmm->technician()->visibleDevice($uid, (int) $vm['device_id']);
    if ($dev === null || !$rmm->authorizer()->allowed($uid, RmmAbility::CHANGE_VIEW, (int) $dev['client_id'])) {
        return '';
    }
    $res = $rmm->readModel()->changeSearch(['device_id' => (int) $vm['device_id']], $rmm->authorizer()->visibleClientIds($uid), RIVET_CHG_ROWS, 0);

    return rivetRmmChgHtml($res);
}

/** @param array{items:list<array<string,mixed>>,total:int} $res */
function rivetRmmChgHtml(array $res): string
{
    $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $o = '<div id="rmm-changes"><p class="small text-muted mb-2">Recent changes the agent noticed on this device (registry values, configuration files, local groups, scheduled tasks). Times are in the company time zone. '
        . 'Values that looked secret are hidden by the agent and shown as it sent them. This is a read-only list; setting up what to watch is not on this screen yet.</p>';
    if ($res['items'] === []) {
        return $o . '<div class="text-muted">No changes recorded for this device in the last 7 days.</div></div>';
    }
    $o .= '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><caption class="visually-hidden">Recent changes on this device, newest first</caption><thead><tr><th>When</th><th>What</th><th>Change</th><th>Was</th><th>Now</th></tr></thead><tbody>';
    foreach ($res['items'] as $r) {
        $when = rivetFormatTime((string) $r['occurred_at'], 'utc', true, 'Y-m-d H:i:s');
        $what = trim((string) $r['kind'] . ' ' . (string) $r['target'] . ((string) $r['item'] !== '' ? ' / ' . (string) $r['item'] : ''));
        $o .= '<tr><td class="text-nowrap">' . $h($when !== '' ? $when : $r['occurred_at']) . '</td><td class="text-break">' . $h($what) . ((string) $r['actor'] !== '' ? '<div class="small text-muted">by ' . $h($r['actor']) . '</div>' : '') . '</td>'
            . '<td>' . $h($r['change']) . '</td><td class="text-break rmm-mono">' . ($r['old_value'] === '' ? '<span class="text-muted">-</span>' : $h($r['old_value'])) . '</td>'
            . '<td class="text-break rmm-mono">' . ($r['new_value'] === '' ? '<span class="text-muted">-</span>' : $h($r['new_value'])) . (!empty($r['redacted']) ? ' <span class="badge text-bg-secondary">redacted</span>' : '') . '</td></tr>';
    }
    $o .= '</tbody></table></div>';
    if ($res['total'] > count($res['items'])) {
        $o .= '<div class="small text-muted mt-2">Showing the newest ' . count($res['items']) . ' of ' . (int) $res['total'] . '.</div>';
    }

    return $o . '</div>';
}
