<?php

/*
 * RMM user interface: HTML renderers for the view-models of includes/rmm_ui.php. Every function returns a string (so the tests can inspect it and
 * the pages can echo it); nothing here queries the database. Device-supplied text is escaped at the point it is printed.
 *
 * Markup follows docs/rmm/mockups/asset-page-app-style.html in rivet-core: the app's own cards, .ifm-* summary tiles and chart chrome from
 * css/itflow_metrics.css, .it-empty-state, Tabler badges and progress bars; the few additions live in css/itflow_rmm.css under .rmm-*.
 */

require_once __DIR__ . '/rivet_time.php';
require_once __DIR__ . '/rmm_ui.php';
require_once __DIR__ . '/rmm_installer.php';
require_once __DIR__ . '/rmm_mesh_wizard.php';   // 26.10.54: RIVET_RMM_MESH_WIZARD_URL (also loads rmm_mesh_cards.php)
require_once __DIR__ . '/rmm_mesh_cards.php';   // rc.12: the device's Mesh agent state (installed / version / node id / error, conflict and kept-by-hand hints)
// RMM Phase 2 and 3 sections of the asset page (RivetCore 1.0.0-rc.10). Each file defines the section hooks used by rivetRmmUiTabs() below.
foreach (['rmm_pol_ui.php', 'rmm_scr_ui.php', 'rmm_alr_ui.php', 'rmm_rem_ui.php', 'rmm_log_ui.php', 'rmm_pat_ui.php', 'rmm_sw_ui.php'] as $__rmm_p23) {
    if (is_file(__DIR__ . '/' . $__rmm_p23)) {
        require_once __DIR__ . '/' . $__rmm_p23;
    }
}

function rmmH($v): string
{
    // A list value (the starter pack's "cert_paths" example is an array) used to raise "Array to string conversion" and print the word Array.
    return nullable_htmlentities(is_array($v) ? implode("\n", array_map('strval', $v)) : (string) $v);
}

/** The app's empty state (includes/ui/empty_state.php) as a string. */
function rivetRmmUiEmpty(string $icon, string $title, string $subtitle = '', string $actions = ''): string
{
    return '<div class="it-empty-state"><div class="it-empty-icon"><i class="' . rmmH($icon) . '" aria-hidden="true"></i></div><p class="it-empty-title">' . rmmH($title)
        . '</p><p class="it-empty-subtitle">' . rmmH($subtitle) . '</p>' . ($actions !== '' ? '<div class="it-empty-actions">' . $actions . '</div>' : '') . '</div>';
}

/** A card shell with the coloured top edge used across the panel. */
function rivetRmmUiCard(string $title, string $icon, string $body, string $edge = '', string $headerExtra = '', bool $flush = false, string $id = ''): string
{
    return '<section class="card card-dark mb-3 rmmx-uicard"' . ($edge !== '' ? ' style="border-top:3px solid var(--tblr-' . rmmH($edge) . ')"' : '') . ($id !== '' ? ' id="' . rmmH($id) . '"' : '') . '>'
        . '<div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px"><h6 class="mb-0 me-auto"><i class="fas fa-' . rmmH($icon) . ' me-2" aria-hidden="true"></i>' . $title . '</h6>'
        . $headerExtra . '</div><div class="card-body' . ($flush ? ' p-0' : '') . '">' . $body . '</div></section>';
}

function rivetRmmUiThead(array $cols): string
{
    $o = '<thead class="rmm-thead rmmx-thead"><tr>';
    foreach ($cols as $i => $c) {
        $o .= '<th scope="col"' . ($i === 0 ? ' class="ps-3"' : '') . '>' . rmmH($c) . '</th>';
    }

    return $o . '</tr></thead>';
}

function rivetRmmUiDl(array $rows): string
{
    $o = '<table class="table table-sm table-borderless mb-0"><tbody>';
    foreach ($rows as [$k, $v]) {
        $o .= '<tr><th scope="row" class="text-muted fw-normal pe-3 rmm-dl-key">' . rmmH($k) . '</th><td>' . $v . '</td></tr>';   // $v is already HTML
    }

    return $o . '</tbody></table>';
}

/** Text for a value that may be missing. */
function rivetRmmUiOrNoData($v, string $none = 'not reported'): string
{
    return $v === null || $v === '' ? '<span class="text-muted">' . rmmH($none) . '</span>' : rmmH($v);
}

/** A tag as a chip: the name is the content, the colour only a swatch on the edge. $removable adds the remove button (a role that may manage tags). */
function rivetRmmUiTagChip(array $t, bool $removable): string
{
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($t['color'] ?? '')) === 1 ? (string) $t['color'] : '';

    return '<span class="rmm-tag badge"' . ($color !== '' ? ' style="border-left:4px solid ' . rmmH($color) . ' !important"' : '') . '>' . rmmH($t['name'])
        . ($removable ? '<button type="button" class="rmm-tag-x" data-tag-id="' . (int) $t['tag_id'] . '" aria-label="Remove tag ' . rmmH($t['name']) . '" title="Remove tag"><i class="fas fa-times" aria-hidden="true"></i></button>' : '') . '</span>';
}

/**
 * The tags and groups line of the device strip. Tags are editable for a role with rmm.device.manage (add with autocomplete from the tags that exist,
 * remove with the x); everyone who may see the device sees them. Group membership is shown, not edited here (groups are managed in the API).
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmUiTagsRow(array $vm): string
{
    $manage = (bool) $vm['perm']['manage'];
    if ($vm['tags'] === [] && $vm['groups'] === [] && !$manage) {
        return '';
    }
    $o = '<div class="d-flex flex-wrap align-items-center gap-2 mt-2 small rmm-tags" id="rmm-tags"><span class="text-muted"><i class="fas fa-tags me-1" aria-hidden="true"></i>Device tags</span>';
    foreach ($vm['tags'] as $t) {
        $o .= rivetRmmUiTagChip($t, $manage);
    }
    if ($vm['tags'] === []) {
        $o .= '<span class="text-muted" id="rmm-no-tags">none</span>';
    }
    if ($manage) {
        $o .= '<form id="rmm-tag-form" class="d-inline-flex align-items-center gap-1 mb-0" autocomplete="off"><label class="visually-hidden" for="rmm-tag-input">Add a tag to this device</label>'
            . '<input class="form-control form-control-sm rmm-tag-input" id="rmm-tag-input" name="tag" list="rmm-tag-list" maxlength="60" placeholder="Add a tag">'
            . '<datalist id="rmm-tag-list">';
        foreach ($vm['tag_suggestions'] as $name) {
            $o .= '<option value="' . rmmH($name) . '"></option>';
        }
        $o .= '</datalist><button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add</button></form>';
    }
    if ($vm['groups'] !== []) {
        $o .= '<span class="text-muted ms-2"><i class="fas fa-layer-group me-1" aria-hidden="true"></i>Groups</span>';
        foreach ($vm['groups'] as $g) {
            $o .= '<span class="badge rmm-group">' . rmmH($g['name']) . '</span>';
        }
    }

    return $o . '</div>';
}

/**
 * The 24 hour trend of one check: a strip of status segments (the height also tells the status: low = passing, middle = warning, full = failing, so it is not
 * colour alone), the availability in words, and the recorded points as a table behind a disclosure.
 *
 * @param array<string,mixed>|null $t rivetRmmUiTrend()
 */
function rivetRmmUiTrendCell(string $key, ?array $t, bool $offline): string
{
    if ($t === null || $t['segments'] === []) {
        return '<span class="text-muted small">no history yet</span>';
    }
    $h = ['ok' => 4, 'warn' => 9, 'fail' => 14, 'unknown' => 2];
    $fill = ['ok' => 'var(--tblr-success)', 'warn' => 'var(--tblr-warning)', 'fail' => 'var(--tblr-danger)', 'unknown' => 'var(--if-muted)'];
    $w = 120;
    $svg = '';
    foreach ($t['segments'] as $seg) {
        $x = round($seg['from'] * $w, 1);
        $sw = max(1.0, round(($seg['to'] - $seg['from']) * $w, 1));
        $svg .= '<rect x="' . $x . '" y="' . (16 - $h[$seg['status']]) . '" width="' . $sw . '" height="' . $h[$seg['status']] . '" fill="' . $fill[$seg['status']] . '"/>';
    }
    $avail = $t['availability'] === null ? 'availability not known' : rtrim(rtrim(number_format((float) $t['availability'], 2, '.', ''), '0'), '.') . '% passing';
    $label = 'Last ' . (int) $t['hours'] . ' hours of ' . $key . ': ' . $avail . ', ' . (int) $t['changes'] . ' status change' . ($t['changes'] === 1 ? '' : 's');
    $rows = '';
    foreach ($t['points'] as $p) {
        $k = ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'crit'][$p['status']] ?? 'off';
        $rows .= '<tr><td class="text-nowrap">' . rivetRmmUiTime($p['at']) . '</td><td>' . rivetRmmUiPill($k, ['ok' => 'Passing', 'warn' => 'Warning', 'fail' => 'Failing'][$p['status']] ?? 'Unknown') . '</td><td class="rmm-wrap">' . rmmH($p['detail']) . '</td></tr>';
    }

    return '<div class="rmm-trend' . ($offline ? ' rmm-dim' : '') . '"><svg class="rmm-spark" viewBox="0 0 ' . $w . ' 16" width="' . $w . '" height="16" role="img" aria-label="' . rmmH($label) . '" preserveAspectRatio="none">'
        . '<rect x="0" y="15" width="' . $w . '" height="1" fill="var(--if-border-strong)"/>' . $svg . '</svg>'
        . '<div class="small text-muted">' . rmmH($avail) . ', ' . (int) $t['changes'] . ' change' . ($t['changes'] === 1 ? '' : 's') . '</div>'
        . '<details class="small"><summary>History<span class="visually-hidden"> of ' . rmmH($key) . '</span></summary><div class="table-responsive"><table class="table table-sm mb-0">'
        . '<caption class="visually-hidden">Recorded results of ' . rmmH($key) . ', newest first</caption>' . rivetRmmUiThead(['When', 'Status', 'Detail']) . '<tbody>' . $rows . '</tbody></table></div></details></div>';
}

// ------------------------------------------------------------------ strip

/**
 * The header's one disk chip (of the 4-slot CPU / RAM / Disk / Uptime header): the busiest volume, i.e. the highest used%, since that is the single
 * reading most likely to need attention and is the most actionable "disk" fact to put in a fixed slot. Ties (including two volumes that both read
 * "no data") break toward the OS/boot volume ('/' on Linux, a 'C:' drive on Windows) so the chip stays on the same volume across page loads instead
 * of jumping between equally-full disks. Every other volume is one click away behind "+N more" (rivetRmmUiOtherDisksHtml()), never dropped.
 *
 * @param list<array<string,mixed>> $gauges $vm['gauges']
 * @return array<string,mixed>|null
 */
function rivetRmmUiMainDiskGauge(array $gauges): ?array
{
    $disks = array_values(array_filter($gauges, static fn (array $g): bool => str_starts_with((string) $g['id'], 'disk:')));
    if ($disks === []) {
        return null;
    }
    $isBoot = static fn (array $g): bool => in_array(substr((string) $g['id'], 5), ['/', 'C:', 'C:\\'], true);
    usort($disks, static function (array $a, array $b) use ($isBoot): int {
        $av = $a['value'] ?? -1.0;
        $bv = $b['value'] ?? -1.0;

        return $av !== $bv ? (float) $bv <=> (float) $av : ($isBoot($a) ? 0 : 1) <=> ($isBoot($b) ? 0 : 1);
    });

    return $disks[0];
}

/**
 * The "+N more" modal body: every disk gauge other than the header's chosen main one. A ZFS dataset (inventory `fs` === 'zfs', mount of the form
 * `pool/dataset`) is grouped with every other dataset sharing the same pool root under one expandable heading, so a pool of a dozen datasets is
 * one disclosure, not a dozen rows; every other filesystem lists its mount flat, one row each, exactly like the old per-volume chips used to.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmUiOtherDisksHtml(array $vm, ?array $mainGauge): string
{
    $invByMount = [];
    foreach ((array) ($vm['inventory']['disks'] ?? []) as $d) {
        if (is_array($d) && isset($d['mount'])) {
            $invByMount[(string) $d['mount']] = $d;
        }
    }
    $groups = [];
    $flat = [];
    foreach ($vm['gauges'] as $g) {
        if (!str_starts_with((string) $g['id'], 'disk:') || ($mainGauge !== null && $g['id'] === $mainGauge['id'])) {
            continue;
        }
        $mount = substr((string) $g['id'], 5);
        $fs = strtolower((string) ($invByMount[$mount]['fs'] ?? ''));
        if ($fs === 'zfs' && str_contains($mount, '/')) {
            $groups[substr($mount, 0, strpos($mount, '/'))][] = $g;
        } else {
            $flat[] = $g;
        }
    }
    $row = static function (array $g): string {
        $bk = rivetRmmUiKind($g['band'])[0];

        return '<tr><td class="ifm-mono">' . rmmH($g['label']) . '</td><td class="text-muted small">' . rmmH($g['caption']) . '</td><td>'
            . '<span class="badge text-bg-' . $bk . '">' . ($g['value'] === null ? 'no data' : (int) round($g['value']) . '%') . '</span></td></tr>';
    };
    $o = '';
    foreach ($groups as $pool => $items) {
        $o .= '<details class="mb-2" open><summary class="fw-semibold"><i class="fas fa-layer-group me-1" aria-hidden="true"></i>Pool ' . rmmH($pool)
            . ' <span class="text-muted small">(' . count($items) . ' dataset' . (count($items) === 1 ? '' : 's') . ')</span></summary>'
            . '<div class="table-responsive mt-1"><table class="table table-sm mb-0"><tbody>' . implode('', array_map($row, $items)) . '</tbody></table></div></details>';
    }
    if ($flat !== []) {
        $o .= '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>' . implode('', array_map($row, $flat)) . '</tbody></table></div>';
    }

    return $o === '' ? '<p class="text-muted small mb-0">No other volumes reported.</p>' : $o;
}

/** @param array<string,mixed> $vm rivetRmmUiPanel() */
function rivetRmmUiDisksModal(array $vm): string
{
    $main = rivetRmmUiMainDiskGauge($vm['gauges']);

    return '<div class="modal fade" id="rmmDisksModal" tabindex="-1" aria-labelledby="rmmDisksTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmDisksTitle"><i class="fas fa-hdd me-2" aria-hidden="true"></i>Other volumes on ' . rmmH($vm['hostname']) . '</h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">' . rivetRmmUiOtherDisksHtml($vm, $main) . '</div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>';
}

/**
 * The "Map Mesh node" modal (administrators): the same hand-mapping field the Remote access card has always offered (rivetRmmUiRemoteCard()), as a
 * modal so it is reachable straight from the header's "Map Mesh node" button when the device has no node mapped yet. Distinct element ids from the
 * Remote card's own form (`rmm-mesh-map-*` vs `rmm-mesh-*`) so both can exist on the page without colliding; js/rmm_panel.js wires both.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmUiMeshMapModal(array $vm): string
{
    $m = $vm['mesh'];

    return '<div class="modal fade" id="rmmMeshMapModal" tabindex="-1" aria-labelledby="rmmMeshMapTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmMeshMapTitle"><i class="fas fa-link me-2" aria-hidden="true"></i>Map a MeshCentral node to ' . rmmH($vm['hostname']) . '</h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<form id="rmm-mesh-map-form"><div class="modal-body"><label class="form-label small mb-1" for="rmm-mesh-map-node">MeshCentral node id</label>'
        . '<input class="form-control font-monospace" id="rmm-mesh-map-node" name="mesh_node_id" maxlength="2048" value="' . rmmH($m['node_id']) . '" placeholder="node//... or the MeshCentral address of the device" aria-describedby="rmm-mesh-map-help">'
        . '<div class="form-text" id="rmm-mesh-map-help">In MeshCentral open the device and copy the address bar, or paste the node id. Any form is accepted and shown as <code>node//</code> plus 64 characters. Leave empty to clear the mapping.</div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save mapping</button></div></form></div></div></div>';
}

/** @param array<string,mixed> $vm rivetRmmUiPanel() */
function rivetRmmUiStrip(array $vm): string
{
    $perm = $vm['perm'];
    $never = $vm['state'] === 'never';
    $kind = $vm['strip']['kind'];
    [$col, $icon] = rivetRmmUiKind($kind);
    $osIcon = $vm['platform'] === 'linux' ? 'fab fa-linux' : ($vm['platform'] === 'windows' ? 'fab fa-windows' : 'fas fa-desktop');
    $platformName = $vm['platform'] === 'linux' ? 'Linux' : ($vm['platform'] === 'windows' ? 'Windows' : 'Other OS');

    // Facts line
    require_once __DIR__ . '/rmm_clock_ui.php';
    $clockNotice = rivetRmmClockNotice($vm['clock'] ?? null, (string) $vm['platform']);
    if ($never && $clockNotice !== null && $clockNotice['reject'] !== null) {
        // rc.23: the device HAS tried; say why the server turned it away instead of "not reported yet"
        $facts = '<span class="text-danger">Not reported yet: the server refused its check-in (' . rmmH($clockNotice['reject']) . ')</span>';
    } elseif ($never) {
        $facts = '<span class="text-muted">OS and agent not reported yet</span>';
    } else {
        $facts = '<i class="' . $osIcon . ' me-1" aria-hidden="true"></i>' . rmmH($vm['os_label']) . ' <span class="text-muted">' . rmmH($vm['arch']) . '</span>'
            . ' &bull; <i class="fas fa-clock mx-1" aria-hidden="true"></i>Last seen ' . rivetRmmUiTime($vm['last_checkin_at'])
            . ' &bull; Agent ' . rmmH($vm['agent_version']) . ' <span class="text-muted">' . rmmH($vm['ring']) . ' ring</span>';
    }

    // Live health badges (dimmed while offline): a fixed 4 slots, CPU / RAM / Disk / Uptime, instead of one chip per volume (a device with many
    // volumes, or a ZFS pool of datasets, used to push the whole row off narrow screens). Disk is the single busiest volume (rivetRmmUiMainDiskGauge());
    // every other volume is a click away behind "+N more" (rivetRmmUiDisksModal(), rivetRmmUiOtherDisksHtml()). Battery is a 5th, optional chip:
    // it was never one-per-volume, so it is kept exactly where it always was, only once the agent actually reports it.
    $live = '';
    if (!$never) {
        $live = '<div class="mt-2 small d-flex flex-wrap align-items-center rmm-live' . ($vm['offline'] ? ' rmm-dim' : '') . '">';
        $chip = static function (string $label, array $g): string {
            $bk = rivetRmmUiKind($g['band'])[0];

            return '<span class="me-' . ($label === '' ? '1' : '3') . ' text-nowrap"><span class="text-muted">' . rmmH($label !== '' ? $label : $g['label']) . '</span> <span class="badge text-bg-' . $bk . '">'
                . ($g['value'] === null ? 'no data' : (int) round($g['value']) . '%') . '</span></span>';
        };
        foreach ($vm['gauges'] as $g) {
            if ($g['id'] === 'cpu') {
                $live .= $chip('CPU', $g);
            } elseif ($g['id'] === 'mem') {
                $live .= $chip('RAM', $g);
            }
        }
        $mainDisk = rivetRmmUiMainDiskGauge($vm['gauges']);
        $otherDisks = 0;
        foreach ($vm['gauges'] as $g) {
            if (str_starts_with((string) $g['id'], 'disk:') && ($mainDisk === null || $g['id'] !== $mainDisk['id'])) {
                $otherDisks++;
            }
        }
        if ($mainDisk !== null) {
            $live .= $chip('', $mainDisk);
            $live .= $otherDisks > 0
                ? '<button type="button" class="btn btn-link btn-sm p-0 me-3" id="rmm-act-more-disks" data-bs-toggle="modal" data-bs-target="#rmmDisksModal">+' . $otherDisks . ' more</button>'
                : '<span class="me-3"></span>';
        }
        $live .= '<span class="text-muted me-3 text-nowrap"><i class="fas fa-power-off me-1" aria-hidden="true"></i>'
            . ($vm['offline'] || $vm['uptime_s'] === null ? 'uptime unknown' : 'Up ' . rmmH(rivetRmmUiDuration((int) $vm['uptime_s']))) . '</span>';
        foreach ($vm['gauges'] as $g) {
            if ($g['id'] === 'battery') {
                $live .= $chip('', $g);
            }
        }
        if ($vm['pending_reboot'] && !$vm['offline']) {
            $live .= rivetRmmUiPill('warn', 'Reboot pending');
        }
        $live .= '</div>';
    }

    // Actions. A button the role or device cannot use is disabled WITH its reason (title for the tooltip, the list below for narrow screens).
    $why = [];
    $btn = static function (string $id, string $class, string $iconCls, string $label, bool $ok, string $reason, string $extra = '') use (&$why): string {
        if (!$ok) {
            $why[] = [$label, $reason];
        }

        return '<button type="button" class="btn ' . $class . ' btn-sm" id="' . $id . '"' . $extra . ($ok ? '' : ' disabled aria-disabled="true" title="' . rmmH($reason) . '"')
            . '><i class="' . $iconCls . ' me-1" aria-hidden="true"></i>' . rmmH($label) . '</button>';
    };
    $dev = $vm['usable'];
    $runPlatform = in_array($vm['platform'], ['windows', 'linux'], true);
    $canScript = $perm['run_saved'] || $perm['run_script'];   // with nothing to pick the dialog itself explains why and where to add scripts
    $scriptReason = !$dev ? 'The device is not approved or no longer active.' : (!$runPlatform ? 'Scripts run on Windows and Linux devices.'
        : (!$canScript ? 'Your role cannot run jobs.' : 'Available after the first check-in.'));
    $actions = '';
    $actions .= $btn('rmm-act-script', 'btn-primary', 'fas fa-play', 'Run script', $dev && !$never && $runPlatform && $canScript, $scriptReason, ' data-bs-toggle="modal" data-bs-target="#rmmRunModal"');
    $actions .= $btn('rmm-act-reboot', 'btn-outline-warning', 'fas fa-power-off', 'Reboot', $dev && !$never && $perm['reboot'], !$dev ? 'The device is not approved or no longer active.'
        : ($never ? 'Available after the first check-in.' : 'Your role cannot reboot devices.'), ' data-bs-toggle="modal" data-bs-target="#rmmRebootModal"');
    // Remote access: a split button (Desktop / Terminal / Files) once a MeshCentral node is mapped. Desktop keeps the id="rmm-act-remote" the single
    // button always had and js/rmm_panel.js's existing launch handler, so it still works exactly as before; Terminal and Files are additional, only
    // once the module's remote-tools feature is on (a free read of the module's state file, no query) — that is the real Phase 6/8 capability
    // behind the Remote tab's own Desktop/Terminal/Files buttons (includes/rmm_rem_ui.php), so clicking either here switches to that tab and invokes
    // its real control rather than duplicating MeshCentral capability detection here. Terminal is listed first for a Linux SERVER (an SSH-style
    // session is the common first move there); every other platform/role combination, including Linux workstations, keeps Desktop first.
    if ($vm['mesh']['configured']) {
        if (!$vm['mesh']['mapped']) {
            // Per design: an unmapped device is not a disabled button (there is nothing to retry), it is a plain-text explanation, plus — for an
            // administrator, who is the only role that can fix it — a button to the mapping modal.
            $actions .= '<span class="text-muted small d-inline-flex align-items-center me-2" id="rmm-remote-hint"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>No MeshCentral node mapped</span>';
            if ($perm['admin']) {
                $actions .= '<button type="button" class="btn btn-outline-secondary btn-sm" id="rmm-act-map-mesh" data-bs-toggle="modal" data-bs-target="#rmmMeshMapModal">'
                    . '<i class="fas fa-link me-1" aria-hidden="true"></i>Map Mesh node</button>';
            }
        } else {
            $remoteOk = $perm['remote'] && $dev && !$never;
            $remoteReason = !$perm['remote'] ? 'Your role cannot open remote sessions.' : (!$dev ? 'The device is not approved or no longer active.' : 'Available after the first check-in.');
            if (!$remoteOk) {
                $why[] = ['Remote access', $remoteReason];
            }
            $isLinuxServer = $vm['platform'] === 'linux' && !empty($vm['is_server']);
            $remoteTools = !empty($vm['features']['remote_tools']);
            $defs = ['desktop' => 'fas fa-desktop|Desktop', 'terminal' => 'fas fa-terminal|Terminal', 'files' => 'fas fa-folder-open|Files'];
            $order = $isLinuxServer ? ['terminal', 'desktop', 'files'] : ['desktop', 'terminal', 'files'];
            if (!$remoteTools) {
                $order = ['desktop'];   // nothing to switch to on the Remote tab: Desktop alone, exactly as the single button used to behave
            }
            $actions .= '<div class="btn-group" role="group" aria-label="Remote access">';
            foreach ($order as $i => $kind) {
                [$ic, $lab] = explode('|', $defs[$kind]);
                $id = $kind === 'desktop' ? 'rmm-act-remote' : 'rmm-act-remote-' . $kind;
                $actions .= '<button type="button" class="btn btn-sm ' . ($i === 0 ? 'btn-success' : 'btn-outline-success') . '" id="' . $id . '"'
                    . ($remoteOk ? '' : ' disabled aria-disabled="true" title="' . rmmH($remoteReason) . '"') . '><i class="' . $ic . ' me-1" aria-hidden="true"></i>' . rmmH($lab) . '</button>';
            }
            $actions .= '</div>';
        }
    } elseif (!empty($perm['admin'])) {
        // 26.10.54: MeshCentral is not set up: the Remote button leads an administrator to the step-by-step page instead of vanishing
        $actions .= '<a class="btn btn-outline-success btn-sm" id="rmm-act-remote-setup" href="' . RIVET_RMM_MESH_WIZARD_URL . '"><i class="fas fa-desktop me-1" aria-hidden="true"></i>Set up remote access</a>';
    }
    $more = '';
    if ($perm['run_saved'] && $dev && !$never) {
        $more .= '<button type="button" class="dropdown-item" id="rmm-act-collect"><i class="fas fa-sync-alt fa-fw me-2" aria-hidden="true"></i>Collect inventory now</button>';
    }
    if ($perm['run_saved'] && $dev && !$never && $vm['software'] !== null) {
        $more .= '<button type="button" class="dropdown-item" id="rmm-act-sw-refresh"><i class="fas fa-cube fa-fw me-2" aria-hidden="true"></i>Refresh software list</button>';
    }
    if ($perm['admin']) {
        $more .= '<a class="dropdown-item" href="/admin/settings_endpoint_agent.php#devices"><i class="fas fa-cog fa-fw me-2" aria-hidden="true"></i>Open in RMM administration</a>';
    }
    if ($more !== '') {
        $actions .= '<div class="dropdown"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="dropdown" data-bs-boundary="window" aria-label="More device actions" title="More actions">'
            . '<i class="fas fa-ellipsis-v" aria-hidden="true"></i></button><div class="dropdown-menu dropdown-menu-end">' . $more . '</div></div>';
    }

    $tagsRow = rivetRmmUiTagsRow($vm);
    $banners = '';
    foreach ($vm['banners'] as $b) {
        $banners .= '<div class="ifm-banner ifm-banner-' . ($b['kind'] === 'warn' ? 'warn' : 'offline') . ' mt-2 mb-0" role="status"><i class="fas '
            . ($b['kind'] === 'warn' ? 'fa-info-circle' : 'fa-plug') . ' ifm-banner-icon" aria-hidden="true"></i><div>' . rmmH($b['text']) . '</div></div>';
    }
    $reasons = '';
    if ($why !== []) {
        $reasons = '<ul class="list-unstyled small text-muted mb-0 mt-2 rmm-reasons" aria-label="Why some actions are unavailable">';
        foreach ($why as [$l, $r]) {
            $reasons .= '<li><strong>' . rmmH($l) . ':</strong> ' . rmmH($r) . '</li>';
        }
        $reasons .= '</ul>';
    }

    return '<section class="card card-dark mb-2 rmm-strip rmm-strip-' . $kind . '" aria-label="RMM device status" id="rmm-strip"><div class="card-body py-2 px-3">'
        . '<div class="d-flex align-items-center flex-wrap rmm-strip-row"><div class="me-auto min-w-0"><strong class="h5 mb-0">' . rmmH($vm['hostname']) . '</strong> '
        . '<span class="badge text-bg-' . $col . ' ms-2"><i class="fas ' . $icon . ' me-1" aria-hidden="true"></i>' . rmmH($vm['strip']['label']) . '</span> '
        . '<span class="badge text-bg-light border ms-1"><i class="' . $osIcon . ' me-1" aria-hidden="true"></i>' . $platformName . '</span>'
        . ($vm['pending_approval'] ? ' ' . rivetRmmUiPill('warn', 'Waiting for approval') : '') . ($vm['revoked'] ? ' ' . rivetRmmUiPill('crit', 'Revoked') : '')
        . ($vm['retired'] ? ' ' . rivetRmmUiPill('off', 'Retired') : '')
        . '<div class="text-muted small mt-1">' . $facts . '</div>' . rivetRmmClockBanner($clockNotice, 'rivetRmmUiTime') . $live . $tagsRow . '</div>'
        . '<div class="d-flex align-items-center flex-wrap rmm-actions" role="group" aria-label="Device actions" style="gap:6px">' . $actions . '</div></div>' . $banners . $reasons . '</div></section>';
}

// ------------------------------------------------------------------ overview

function rivetRmmUiGaugeCard(array $g, bool $dim, ?string $stale): string
{
    $txt = ['ok' => 'OK', 'warn' => 'Warning', 'crit' => 'Critical', 'off' => 'No data'][$g['band']];

    return '<div class="ifm-card' . ($dim ? ' ifm-card-stale' : '') . '"><div class="d-flex align-items-center gap-2 px-2 py-2' . ($dim ? ' rmm-dim' : '') . '">'
        . rivetRmmUiGaugeSvg($g['label'], $g['value'], (int) $g['warn'], (int) $g['crit'], $g['band'])
        . '<div class="min-w-0"><div class="it-stat-label fw-semibold">' . rmmH($g['label']) . '</div><div class="text-muted small mb-1">' . rmmH($g['caption']) . '</div>'
        . rivetRmmUiPill($g['band'], $txt) . '</div></div>'
        . ($stale !== null ? '<div class="ifm-card-meta rmm-card-meta-flush"><span class="ifm-chip ifm-chip-stale">last reading ' . rmmH($stale) . '</span></div>' : '') . '</div>';
}

function rivetRmmUiStatCard(string $icon, string $tint, string $value, string $label, string $meta, string $extra = ''): string
{
    return '<div class="ifm-card"><div class="it-stat-card"><div class="it-stat-icon it-tint-' . rmmH($tint) . '"><i class="fas fa-' . rmmH($icon) . '" aria-hidden="true"></i></div>'
        . '<div class="it-stat-body"><div class="it-stat-value">' . $value . '</div><div class="it-stat-label">' . rmmH($label) . '</div></div></div>' . $extra
        . '<div class="ifm-card-meta">' . $meta . '</div></div>';
}

/**
 * The network tile: receive and send now, each as a bar against the highest rate of the last 24 hours (RmmReadModel::networkPeak(), fed by the metrics
 * subsystem). The agent reports no link speed, so the peak is the only honest "full". Without history (no samples yet, no asset) the bars are replaced by a
 * sentence, never by an empty or full bar.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmUiNetworkTile(array $vm): string
{
    $np = $vm['net_peak'];
    $rx = $vm['net']['rx_bps'];
    $tx = $vm['net']['tx_bps'];
    $row = static function (string $label, ?float $bps, ?array $side, bool $history, string $id): string {
        $peakBytes = is_array($side) ? $side['peak'] : null;
        $now = $bps === null ? '<span class="text-muted">no data</span>' : rmmH(rivetRmmUiMbit($bps));
        if (!$history || $peakBytes === null || $peakBytes <= 0) {
            return '<div class="px-3 pb-2"><div class="d-flex justify-content-between small"><span>' . rmmH($label) . '</span><b>' . $now . '</b></div>'
                . '<div class="small text-muted" id="' . $id . '-peak">' . ($history && $peakBytes !== null ? 'no traffic recorded in the last 24 hours' : 'no 24 hour history yet') . '</div></div>';
        }
        $peakBps = $peakBytes * 8;
        $pct = $bps === null ? null : min(100.0, $bps / $peakBps * 100);
        $at = is_array($side) && !empty($side['peak_at']) ? ' at ' . rivetFormatTime((string) $side['peak_at'], 'utc', true, 'H:i') : '';

        return '<div class="px-3 pb-2"><div class="d-flex justify-content-between small"><span>' . rmmH($label) . '</span><b>' . $now . '</b></div>'
            . rivetRmmUiBar($pct, 'info', 6, $label . ' against the 24 hour peak') . '<div class="small text-muted" id="' . $id . '-peak">'
            . ($pct === null ? 'no current reading; ' : (int) round($pct) . '% of ') . '24 h peak ' . rmmH(rivetRmmUiMbit($peakBps)) . rmmH($at) . '</div></div>';
    };
    $history = is_array($np) && $np['history'];
    $body = $row('Receive', $rx, is_array($np) ? $np['rx'] : null, $history, 'rmm-net-rx') . $row('Send', $tx, is_array($np) ? $np['tx'] : null, $history, 'rmm-net-tx');

    return '<div class="ifm-card' . ($vm['offline'] ? ' rmm-dim' : '') . '" id="rmm-net"><div class="it-stat-card"><div class="it-stat-icon it-tint-info"><i class="fas fa-network-wired" aria-hidden="true"></i></div>'
        . '<div class="it-stat-body"><div class="it-stat-label fw-semibold">Network</div></div></div>' . $body
        . '<div class="ifm-card-meta"><span>link speed is not reported, so the bar shows the share of the 24 hour peak</span></div></div>';
}

function rivetRmmUiLiveHealth(array $vm): string
{
    if ($vm['state'] === 'never') {
        return rivetRmmUiEmpty('fas fa-hourglass-half', 'Waiting for the first check-in', 'The agent is enrolled but has not reported yet. Gauges, graphs and checks appear after its first report, usually within a few minutes.');
    }
    $stale = $vm['offline'] ? rivetRmmUiAgo($vm['age_s']) : null;
    $tiles = '';
    foreach ($vm['gauges'] as $g) {
        $tiles .= rivetRmmUiGaugeCard($g, $vm['offline'], $stale);
    }
    $tiles .= rivetRmmUiNetworkTile($vm);
    $boot = '';
    if (!$vm['offline'] && $vm['uptime_s'] !== null && $vm['last_checkin_at'] !== null) {
        $bootTs = strtotime($vm['last_checkin_at']) - (int) $vm['uptime_s'];
        $boot = '<span>last boot <b title="' . rmmH(rivetFormatTime($bootTs, 'epoch')) . '">' . rmmH(rivetRmmUiAgo(max(0, time() - $bootTs))) . '</b></span>';
    }
    $tiles .= rivetRmmUiStatCard('clock', $vm['pending_reboot'] ? 'warning' : 'success', $vm['offline'] || $vm['uptime_s'] === null ? '<span class="text-muted">unknown</span>' : rmmH(rivetRmmUiDuration((int) $vm['uptime_s'])),
        'Uptime', $boot . ($vm['pending_reboot'] ? rivetRmmUiPill('warn', 'Reboot pending') : '<span>' . ($vm['pending_reboot'] === null ? 'reboot state not reported' : 'no reboot pending') . '</span>'));
    $age = (int) ($vm['age_s'] ?? 0);
    $seg = $age > $vm['offline_after_s'] ? 3 : ($age > (int) round($vm['expected_interval_s'] * 1.1) ? 2 : 1);
    $sk = [1 => 'ok', 2 => 'warn', 3 => 'crit'][$seg];
    $bars = '';
    for ($i = 1; $i <= 3; $i++) {
        $bars .= '<div class="progress flex-fill" style="height:5px"><div class="progress-bar bg-' . rivetRmmUiKind($sk)[0] . '" style="width:' . ($seg >= $i ? 100 : 0) . '%"></div></div>';
    }
    $tiles .= rivetRmmUiStatCard('heartbeat', ['ok' => 'success', 'warn' => 'warning', 'crit' => 'danger'][$sk], rmmH(rivetRmmUiAgo($age)), 'Agent contact',
        '<span>expected every ' . rmmH(rivetRmmUiDuration($vm['expected_interval_s'])) . '; offline after ' . rmmH(rivetRmmUiDuration($vm['offline_after_s'])) . '</span>',
        '<div class="px-3 pb-1 d-flex gap-1" role="img" aria-label="Agent check-in freshness: ' . [1 => 'on time', 2 => 'late', 3 => 'overdue'][$seg] . '">' . $bars . '</div>');

    return '<h6 class="ifm-family">Live health</h6><p class="small text-muted mb-2">' . ($vm['offline'] ? 'Last reading before the device went offline.' : 'Latest agent sample, as of this page load.')
        . ' A reading the agent could not take shows as "no data", never as zero.</p><div class="ifm-cards">' . $tiles . '</div>';
}

function rivetRmmUiAlertsCard(array $vm): string
{
    $item = static function (array $a, bool $open): string {
        $k = $a['kind'];
        $meta = $open ? 'Open for ' . rmmH(rivetRmmUiDuration(rivetRmmUiAgeOf($a['created_at'], null, true))) . ($a['ticket_id'] ? ' &middot; <a href="/agent/ticket.php?ticket_id=' . (int) $a['ticket_id'] . '">ticket #' . (int) $a['ticket_id'] . '</a>' : ' &middot; no ticket')
            : 'Resolved ' . rmmH(rivetRmmUiAgo(rivetRmmUiAgeOf($a['resolved_at'], null, true)));

        return '<li class="list-group-item d-flex align-items-start gap-2"><i class="fas ' . ($k === 'crit' ? 'fa-times-circle text-danger' : 'fa-exclamation-triangle text-warning') . ' mt-1" aria-hidden="true"></i>'
            . '<div class="me-auto min-w-0">' . rivetRmmUiPill($k, $k === 'crit' ? 'Critical' : 'Warning') . ' <span class="rmm-wrap">' . rmmH(mb_strimwidth((string) $a['message'], 0, 300, '...')) . '</span>'
            . '<div class="text-muted small">' . $meta . '</div></div></li>';
    };
    $open = $vm['alerts']['open'];
    $o = '<ul class="list-group list-group-flush">';
    $o .= $open === [] ? '<li class="list-group-item text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No open alerts.</li>' : implode('', array_map(static fn ($a) => $item($a, true), $open));
    $o .= '</ul>';
    if ($vm['alerts']['recent'] !== []) {
        $o .= '<div class="card-header py-1 border-top"><span class="ifm-toolbar-label">Recently resolved</span></div><ul class="list-group list-group-flush">'
            . implode('', array_map(static fn ($a) => $item($a, false), $vm['alerts']['recent'])) . '</ul>';
    }
    $head = ($open !== [] ? rivetRmmUiPill('crit', count($open) . ' open') : rivetRmmUiPill('ok', 'None open'))
        . '<a class="small ms-2" href="/agent/alerts.php?status=all&amp;asset_id=' . (int) $vm['asset_id'] . '">All alerts for this device</a>';

    return '<div class="col-lg-6">' . '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-' . ($open !== [] ? 'danger' : 'success') . ')" id="rmm-alerts"><div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px">'
        . '<h6 class="mb-0 me-auto"><i class="fas fa-bell me-2 text-warning" aria-hidden="true"></i>Alerts</h6>' . $head . '</div>' . $o . '</section></div>';
}

function rivetRmmUiRemoteCard(array $vm): string
{
    $perm = $vm['perm'];
    $m = $vm['mesh'];
    $push = rivetRmmMeshPushControls(rivetRmmMeshPushState($vm));   // rc.16: install / remove the MeshCentral agent on this device ('' unless Core offers it and the viewer may)
    if (!$m['configured'] && !$perm['admin'] && $push === '') {
        return '';   // Mesh is not set up: nothing to offer or explain to a technician
    }
    $body = '<div class="card-body py-2">';
    if ($m['configured']) {
        $ok = $perm['remote'] && $m['mapped'] && $vm['usable'] && $vm['state'] !== 'never';
        $reason = !$perm['remote'] ? 'Your role cannot open remote sessions.' : (!$m['mapped'] ? 'Map a MeshCentral node first (administrator).' : 'Available once the device is approved and has checked in.');
        $body .= '<div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-success btn-sm" id="rmm-remote-btn"' . ($ok ? '' : ' disabled aria-disabled="true" title="' . rmmH($reason) . '"')
            . '><i class="fas fa-desktop me-1" aria-hidden="true"></i>Remote desktop</button></div>';
        if (!$ok) {
            $body .= '<p class="text-muted small mt-2 mb-0">' . rmmH($reason) . '</p>';
        } elseif ($vm['offline']) {
            $body .= '<p class="text-muted small mt-2 mb-0">The device is offline. A session can be forced but will likely fail.</p>';
        }
    } else {
        $body .= '<p class="text-muted small mb-0">MeshCentral is not configured. An administrator can set it up under Administration, Endpoint agent.</p>';
    }
    $body .= '</div>';
    // RivetCore rc.12: what the device reported about its MeshCentral agent (present only once it reported one). An administrator also sees the hint that nothing was reported yet.
    if (!empty($vm['mesh_agent']) || isset($vm['mesh_after_approval']) || ($m['configured'] && $perm['admin']) || $push !== '') {
        $body .= '<div class="card-body border-top py-2"><div class="ifm-toolbar-label mb-1">MeshCentral agent</div>' . rivetRmmMeshAgentHtml($vm['mesh_agent'] ?? null, ['conflict_url' => $vm['mesh_agent_conflict_url'] ?? null, 'after_approval' => $vm['mesh_after_approval'] ?? null, 'can_approve' => $perm['admin']]) . $push . '</div>';
    }
    if ($perm['admin']) {
        $body .= '<div class="card-body border-top py-2"><form id="rmm-mesh-form" class="row g-2 align-items-end"><div class="col-12"><label class="form-label small mb-1" for="rmm-mesh-node">MeshCentral node id (administrators)</label>'
            . '<input class="form-control form-control-sm font-monospace" id="rmm-mesh-node" name="mesh_node_id" maxlength="2048" value="' . rmmH($m['node_id']) . '" placeholder="node//... or the MeshCentral address of the device" aria-describedby="rmm-mesh-node-help">'
            . '<div class="form-text" id="rmm-mesh-node-help">In MeshCentral open the device and copy the address bar, or paste the node id. Any form is accepted and shown as <code>node//</code> plus 64 characters.</div></div><div class="col-12"><button class="btn btn-sm btn-outline-primary" type="submit">Save mapping</button> <span class="small text-muted">Leave empty to clear. A node set here is kept when the Mesh agent reports a different one, unless <em>Let the agent\'s node replace a node I mapped by hand</em> is on in the MeshCentral settings.</span></div></form></div>';
    }
    if ($perm['remote'] && $m['configured']) {
        $body .= '<div class="card-header py-1 border-top"><span class="ifm-toolbar-label">Recent sessions</span></div><ul class="list-group list-group-flush">';
        if ($vm['remote_sessions'] === []) {
            $body .= '<li class="list-group-item text-muted small">No sessions recorded.</li>';
        }
        foreach ($vm['remote_sessions'] as $s) {
            $body .= '<li class="list-group-item d-flex align-items-start gap-2"><i class="fas fa-user mt-1 text-secondary" aria-hidden="true"></i><div class="me-auto">' . rmmH($s['user_name'] ?? 'A user')
                . ' opened a remote session<div class="text-muted small">' . ($s['source_ip'] ? 'from ' . rmmH($s['source_ip']) : '') . '</div></div><span class="text-muted small text-nowrap">'
                . rmmH(rivetRmmUiAgo(rivetRmmUiAgeOf($s['created_at'], null, true))) . '</span></li>';
        }
        $body .= '</ul>';
    }

    return '<div class="col-lg-6"><section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-info)" id="rmm-remote"><div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px">'
        . '<h6 class="mb-0 me-auto"><i class="fas fa-desktop me-2 text-info" aria-hidden="true"></i>Remote access</h6>'
        . ($m['configured'] ? ($m['mapped'] ? rivetRmmUiPill('ok', 'MeshCentral node mapped') : rivetRmmUiPill('off', 'No node mapped')) : rivetRmmUiPill('off', 'Not configured')) . '</div>' . $body . '</section></div>';
}

/**
 * "Another tool already manages this device" (administrators only, RivetCore 1.0.0-rc.32): turns off scheduled
 * scripts and scheduled patch installs for a device a different RMM/patch agent already handles. Monitoring,
 * inventory, patch scanning/compliance and a deliberate manual run are unaffected. Empty string for a non-admin
 * (nothing to show or explain).
 */
function rivetRmmUiAutomationCard(array $vm): string
{
    if (!$vm['perm']['admin']) {
        return '';
    }
    $on = !empty($vm['automation_disabled']);
    $body = '<div class="card-body py-2"><div class="form-check form-switch">'
        . '<input class="form-check-input" type="checkbox" role="switch" id="rmm-automation-disabled"' . ($on ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="rmm-automation-disabled">Another tool already manages this device</label></div>'
        . '<div class="form-text">Turns off this device\'s scheduled scripts and scheduled patch installs, so a different RMM or patch agent already on it and RivetCore\'s own automation never fight. Monitoring, inventory, patch scanning/compliance and running a script or installing a patch on this device right now are unaffected.</div></div>';

    return '<div class="col-lg-6"><section class="card card-dark mb-3" id="rmm-automation"><div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px">'
        . '<h6 class="mb-0 me-auto"><i class="fas fa-robot me-2 text-secondary" aria-hidden="true"></i>Automation</h6>'
        . ($on ? rivetRmmUiPill('off', 'Disabled here') : rivetRmmUiPill('ok', 'Managed by RivetCore')) . '</div>' . $body . '</section></div>';
}

function rivetRmmUiChecksCard(array $vm): string
{
    $c = $vm['check_counts'];
    $map = ['ok' => ['ok', 'Passing'], 'warn' => ['warn', 'Warning'], 'fail' => ['crit', 'Failing'], 'unknown' => ['off', 'Unknown']];
    $rows = '';
    foreach ($vm['checks'] as $ch) {
        $m = $map[$ch['shown_status']];
        $changed = $ch['last_changed_at'] !== null ? rivetRmmUiDuration(rivetRmmUiAgeOf($ch['last_changed_at'])) : null;
        $rows .= '<tr class="' . ($ch['shown_status'] === 'fail' ? 'table-danger' : ($ch['shown_status'] === 'warn' ? 'table-warning' : '')) . '"><td class="ps-3">' . rivetRmmUiPill($m[0], $m[1]) . '</td>'
            . '<td><div class="ifm-mono">' . rmmH($ch['key']) . '</div><div class="text-muted small">' . rmmH($ch['type']) . (($ch['consecutive_failures'] ?? 0) > 0 ? ' &middot; ' . (int) $ch['consecutive_failures'] . ' failures in a row' : '') . '</div></td>'
            . '<td class="rmm-wrap">' . rmmH($ch['detail']) . '<div class="text-muted small">reported ' . rivetRmmUiTime($ch['last_reported_at']) . '</div></td>'
            . '<td class="text-nowrap small">' . ($changed === null ? '<span class="text-muted">not recorded</span>' : ($ch['status'] === 'ok' ? 'steady for ' : 'since ') . rmmH($changed)) . '</td>'
            . ($vm['check_history_on'] ? '<td class="rmm-trend-cell">' . rivetRmmUiTrendCell((string) $ch['key'], $vm['trends'][(string) $ch['key']] ?? null, (bool) $vm['offline']) . '</td>' : '')
            . '<td>' . ($ch['alert_id'] ? '<a class="badge text-bg-light border" href="/agent/alerts.php?status=all&amp;asset_id=' . (int) $vm['asset_id'] . '">alert #' . (int) $ch['alert_id'] . '</a>' : '<span class="text-muted">none</span>') . '</td></tr>';
    }
    if ($vm['checks'] === []) {
        $table = rivetRmmUiEmpty('fas fa-heartbeat', 'No check result yet', $vm['state'] === 'never' ? 'The device has not checked in yet.' : 'No checks are configured, or none has reported since the agent last restarted.');
    } else {
        $table = '<div class="table-responsive"><table class="table table-hover table-sm mb-0">' . rivetRmmUiThead($vm['check_history_on'] ? ['Status', 'Check', 'Last result', 'Last change', 'Trend (' . (int) $vm['trend_hours'] . ' h)', 'Alert'] : ['Status', 'Check', 'Last result', 'Last change', 'Alert']) . '<tbody>' . $rows . '</tbody></table></div>';
    }
    $head = ($c['fail'] ? rivetRmmUiPill('crit', $c['fail'] . ' failing') : '') . ($c['warn'] ? rivetRmmUiPill('warn', $c['warn'] . ' warning') : '')
        . ($vm['checks'] !== [] ? rivetRmmUiPill($vm['offline'] ? 'off' : 'ok', $vm['offline'] ? $c['unknown'] . ' unknown while offline' : $c['ok'] . ' passing') : '');

    return rivetRmmUiCard('Checks', 'heartbeat', $table, 'success', $head, true, 'rmm-checks');
}

/** @param string $performanceHtml the Metrics partial's output, or '' */
function rivetRmmUiOverview(array $vm, string $performanceHtml, string $performanceNote): string
{
    $o = rivetRmmUiLiveHealth($vm);
    if ($vm['state'] === 'never') {
        return $o;
    }
    if ($performanceHtml !== '') {
        $o .= '<h6 class="ifm-family mt-3">Performance</h6>' . $performanceHtml;
    } elseif ($performanceNote !== '') {
        $o .= '<h6 class="ifm-family mt-3">Performance</h6><div class="card card-dark mb-3"><div class="card-body">' . $performanceNote . '</div></div>';
    }
    $o .= '<div class="row mt-2">' . rivetRmmUiAlertsCard($vm) . rivetRmmUiRemoteCard($vm) . rivetRmmUiAutomationCard($vm) . '</div>' . rivetRmmUiChecksCard($vm);

    return $o;
}

// ------------------------------------------------------------------ inventory

function rivetRmmUiInventory(array $vm): string
{
    $inv = $vm['inventory'];
    if ($inv === []) {
        $act = $vm['perm']['run_saved'] && $vm['usable'] && $vm['state'] !== 'never' ? '<button type="button" class="btn btn-sm btn-outline-secondary" id="rmm-collect-empty"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Collect inventory now</button>' : '';

        return rivetRmmUiEmpty('fas fa-microchip', 'No inventory yet', 'The agent sends its hardware and operating system summary with its first check-in and again at least daily.', $act);
    }
    $nd = static fn ($v) => rivetRmmUiOrNoData($v);
    $cpu = $inv['cpu']['model'] ?? null;
    $mem = isset($inv['memory_total_bytes']) && is_numeric($inv['memory_total_bytes']) ? rivetRmmUiBytes((float) $inv['memory_total_bytes']) : null;
    $hw = rivetRmmUiDl([
        ['Make / model', $nd(trim((string) ($inv['manufacturer'] ?? '') . ' ' . (string) ($inv['model'] ?? '')))],
        ['Serial', '<span class="ifm-mono">' . $nd($inv['serial'] ?? null) . '</span>'],
        ['CPU', $nd($cpu !== null ? $cpu . (!empty($inv['cpu']['cores']) ? ' (' . (int) $inv['cpu']['cores'] . ' cores)' : '') : null)],
        ['Memory', $nd($mem)],
    ]);
    $os = rivetRmmUiDl([
        ['Operating system', $nd($vm['os_label'])],
        ['Architecture', $nd($vm['arch'])],
        ['Logged-in user', $nd($vm['logged_in_user'])],
        ['Pending reboot', $vm['pending_reboot'] === null ? '<span class="text-muted">not reported</span>' : ($vm['pending_reboot'] ? rivetRmmUiPill('warn', 'Yes') : 'No')],
        ['Inventory collected', $vm['last_inventory_at'] ? rivetRmmUiTime($vm['last_inventory_at']) : '<span class="text-muted">not reported</span>'],
    ]);
    $disks = '';
    foreach ((array) ($inv['disks'] ?? []) as $d) {
        if (!is_array($d)) {
            continue;
        }
        $t = isset($d['total_bytes']) && is_numeric($d['total_bytes']) ? (float) $d['total_bytes'] : null;
        $f = isset($d['free_bytes']) && is_numeric($d['free_bytes']) ? (float) $d['free_bytes'] : null;
        $used = $t !== null && $f !== null && $t > 0 ? round(($t - $f) / $t * 100, 1) : null;
        $bk = rivetRmmUiBand($used, rivetRmmUiBands()['disk']);
        $disks .= '<tr><td class="ps-3 ifm-mono">' . rmmH($d['mount'] ?? '') . '</td><td>' . rmmH($d['fs'] ?? '') . '</td><td>' . rmmH(rivetRmmUiBytes($t)) . '</td><td>' . rmmH(rivetRmmUiBytes($f)) . '</td>'
            . '<td class="rmm-usedcell">' . ($used === null ? '<span class="text-muted">no data</span>' : '<div class="d-flex align-items-center gap-2"><div class="flex-fill">' . rivetRmmUiBar($used, $bk, 6, 'Used') . '</div><span class="small">' . (int) round($used) . '%</span></div>') . '</td></tr>';
    }
    $net = '';
    foreach ((array) ($inv['network'] ?? []) as $n) {
        if (is_array($n)) {
            $net .= '<tr><td class="ps-3">' . rmmH($n['name'] ?? '') . '</td><td class="ifm-mono">' . rmmH($n['mac'] ?? '') . '</td><td class="ifm-mono">' . rmmH(implode(', ', array_map('strval', array_slice((array) ($n['ips'] ?? []), 0, 12)))) . '</td></tr>';
        }
    }
    $tbl = static fn (array $cols, string $rows, string $empty): string => $rows === '' ? '<div class="p-3 text-muted small">' . rmmH($empty) . '</div>'
        : '<div class="table-responsive"><table class="table table-hover table-sm mb-0">' . rivetRmmUiThead($cols) . '<tbody>' . $rows . '</tbody></table></div>';

    $software = '';
    foreach (array_slice((array) ($inv['software'] ?? []), 0, 500) as $s) {
        if (is_array($s)) {
            $software .= '<tr><td class="ps-3">' . rmmH($s['name'] ?? '') . '</td><td class="ifm-mono">' . rmmH($s['version'] ?? '') . '</td><td>' . rmmH($s['publisher'] ?? '') . '</td></tr>';
        }
    }
    $services = '';
    foreach (array_slice((array) ($inv['services'] ?? []), 0, 500) as $s) {
        if (is_array($s)) {
            $services .= '<tr><td class="ps-3">' . rmmH($s['name'] ?? '') . '</td><td>' . rmmH($s['state'] ?? '') . '</td><td>' . rmmH($s['start_type'] ?? '') . '</td></tr>';
        }
    }
    $softCard = $software !== '' ? rivetRmmUiCard('Installed software', 'cube', $tbl(['Name', 'Version', 'Publisher'], $software, ''), '', '', true)
        : rivetRmmUiCard('Installed software', 'cube', $vm['software'] !== null
            ? rivetRmmUiEmpty('fas fa-cube', 'See the Software tab', 'The installed software list, its search and its change history are on the Software tab.')
            : rivetRmmUiEmpty('fas fa-cube', 'Software inventory is off', 'An administrator can switch it on under Administration, Endpoint agent, Software inventory and history. Agents send nothing until then.'));
    $svcCard = $services !== '' ? rivetRmmUiCard('Services', 'cogs', $tbl(['Service', 'State', 'Start type'], $services, ''), '', '', true)
        : rivetRmmUiCard('Services', 'cogs', rivetRmmUiEmpty('fas fa-cogs', 'Not collected yet', 'The agent does not report services in this release. The list arrives with the service and process manager (Phase 6).'));

    return '<div class="row"><div class="col-lg-6">' . rivetRmmUiCard('Hardware', 'microchip', $hw) . '</div><div class="col-lg-6">' . rivetRmmUiCard('Operating system', 'server', $os) . '</div></div>'
        . rivetRmmUiCard('Disks', 'hdd', $tbl(['Volume', 'Filesystem', 'Size', 'Free', 'Used'], $disks, 'No disks reported.'), '', '', true)
        . rivetRmmUiCard('Network adapters', 'network-wired', $tbl(['Adapter', 'MAC', 'Addresses'], $net, 'No network adapters reported.'), '', '', true)
        . '<div class="row"><div class="col-lg-6">' . $softCard . '</div><div class="col-lg-6">' . $svcCard . '</div></div>';
}

// ------------------------------------------------------------------ software (RivetCore 1.0.0-rc.9, behind the inventory_software switch)

/** URL of the asset page's Software tab with the given search state. */
function rivetRmmUiSoftwareUrl(array $vm, array $over = []): string
{
    $sw = $vm['software'];
    $q = array_merge(['asset_id' => (int) $vm['asset_id'], 'swq' => $sw['q'], 'swp' => $sw['page'], 'swr' => $sw['show_removed'] ? 1 : 0], $over);
    $q = array_filter($q, static fn ($v, $k) => $k === 'asset_id' || ($v !== '' && $v !== 0 && !($k === 'swp' && $v === 1)), ARRAY_FILTER_USE_BOTH);

    return '/agent/asset_details.php?' . http_build_query($q) . '#rmm-software';
}

/**
 * The Software tab: the current list (search by name, optionally with what was removed), the change log (installed, upgraded, downgraded, removed) and
 * when the device last reported. All of it is device-supplied text: escaped on output.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel() with a non-null `software`
 */
function rivetRmmUiSoftware(array $vm): string
{
    $sw = $vm['software'];
    $st = $sw['state'];
    $perm = $vm['perm'];
    $refresh = $perm['run_saved'] && $vm['usable'] && $vm['state'] !== 'never'
        ? '<button type="button" class="btn btn-sm btn-outline-secondary" id="rmm-sw-refresh"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Ask for a full list</button>' : '';
    if (!$st['reported']) {
        $why = $vm['state'] === 'never' ? 'The device has not checked in yet.' : (!$st['capable'] ? 'This agent has not announced software inventory. Agents older than RivetCore 1.0.0-rc.9 cannot report it; update the agent (Administration, Endpoint agent, Agent binaries).'
            : 'The agent announced software inventory and sends its first list at a coming check-in (at most once an hour).');

        return rivetRmmUiEmpty('fas fa-cube', 'No software list yet', $why, $refresh);
    }
    $sum = '<p class="small text-muted mb-2"><b>' . (int) $st['count'] . '</b> installed &middot; last report ' . rivetRmmUiTime($st['reported_at']) . ' &middot; last full list ' . rivetRmmUiTime($st['full_at'])
        . ($st['resync_requested'] ? ' &middot; ' . rivetRmmUiPill('info', 'full list requested') : '') . ' ' . $refresh . '</p>';
    $form = '<form class="d-flex flex-wrap align-items-center gap-2 mb-3" method="get" action="/agent/asset_details.php#rmm-software" role="search" aria-label="Search this device\'s software">'
        . '<input type="hidden" name="asset_id" value="' . (int) $vm['asset_id'] . '"><label class="visually-hidden" for="rmm-sw-q">Search software by name</label>'
        . '<input class="form-control form-control-sm w-auto" id="rmm-sw-q" name="swq" type="search" maxlength="100" placeholder="Search by name" value="' . rmmH($sw['q']) . '">'
        . '<div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="rmm-sw-removed" name="swr" value="1"' . ($sw['show_removed'] ? ' checked' : '') . '><label class="form-check-label small" for="rmm-sw-removed">Include removed</label></div>'
        . '<button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-search me-1" aria-hidden="true"></i>Search</button>'
        . ($sw['q'] !== '' || $sw['show_removed'] ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH(rivetRmmUiSoftwareUrl($vm, ['swq' => '', 'swp' => 1, 'swr' => 0])) . '">Clear</a>' : '') . '</form>';
    $rows = '';
    foreach ($sw['items'] as $r) {
        $gone = $r['removed_at'] !== null;
        $rows .= '<tr' . ($gone ? ' class="text-muted"' : '') . '><td class="ps-3 rmm-wrap">' . rmmH($r['name']) . ($gone ? ' ' . rivetRmmUiPill('off', 'Removed') : '') . '</td><td class="ifm-mono">' . rmmH($r['version'] === '' ? 'unknown' : $r['version'])
            . '</td><td class="rmm-wrap">' . rivetRmmUiOrNoData($r['publisher'], 'not reported') . '</td><td>' . rmmH($r['source']) . '</td><td class="text-nowrap">' . rivetRmmUiOrNoData($r['installed_on'], 'not reported')
            . '</td><td class="text-nowrap">' . ($gone ? 'removed ' . rivetRmmUiTime($r['removed_at']) : 'seen ' . rivetRmmUiTime($r['first_seen_at'])) . '</td></tr>';
    }
    $cur = $rows === '' ? '<div class="p-3 text-muted small">' . ($sw['q'] !== '' ? 'No software matches "' . rmmH($sw['q']) . '".' : 'Nothing is listed.') . '</div>'
        : '<div class="table-responsive"><table class="table table-hover table-sm mb-0"><caption class="visually-hidden">Software installed on this device</caption>'
            . rivetRmmUiThead(['Name', 'Version', 'Publisher', 'Source', 'Installed', 'First seen / removed']) . '<tbody>' . $rows . '</tbody></table></div>';
    $pager = '';
    if ($sw['pages'] > 1) {
        $pager = '<nav class="d-flex align-items-center justify-content-between p-3 border-top" aria-label="Software pages"><span class="small text-muted">Page ' . (int) $sw['page'] . ' of ' . (int) $sw['pages'] . ', ' . (int) $sw['total'] . ' items</span><div class="btn-group btn-group-sm">'
            . ($sw['page'] > 1 ? '<a class="btn btn-outline-secondary" href="' . rmmH(rivetRmmUiSoftwareUrl($vm, ['swp' => $sw['page'] - 1])) . '" rel="prev">Previous</a>' : '<span class="btn btn-outline-secondary disabled" aria-disabled="true">Previous</span>')
            . ($sw['page'] < $sw['pages'] ? '<a class="btn btn-outline-secondary" href="' . rmmH(rivetRmmUiSoftwareUrl($vm, ['swp' => $sw['page'] + 1])) . '" rel="next">Next</a>' : '<span class="btn btn-outline-secondary disabled" aria-disabled="true">Next</span>') . '</div></nav>';
    } else {
        $pager = '<div class="px-3 py-2 border-top small text-muted">' . (int) $sw['total'] . ' item' . ($sw['total'] === 1 ? '' : 's') . '</div>';
    }
    $kinds = ['installed' => ['info', 'Installed'], 'upgraded' => ['ok', 'Upgraded'], 'downgraded' => ['warn', 'Downgraded'], 'removed' => ['off', 'Removed']];
    $hist = '';
    foreach ($sw['history']['items'] as $h) {
        $k = $kinds[$h['change']] ?? ['off', ucfirst((string) $h['change'])];
        $ver = $h['old_version'] !== null && $h['new_version'] !== null ? rmmH($h['old_version']) . ' <i class="fas fa-arrow-right text-muted" aria-label="to"></i> ' . rmmH($h['new_version'])
            : rmmH($h['new_version'] ?? $h['old_version'] ?? '');
        $hist .= '<tr><td class="ps-3 text-nowrap">' . rivetRmmUiTime($h['at']) . '</td><td>' . rivetRmmUiPill($k[0], $k[1]) . '</td><td class="rmm-wrap">' . rmmH($h['name']) . '</td><td class="ifm-mono">' . $ver . '</td></tr>';
    }
    $histBody = $hist === '' ? '<div class="p-3 text-muted small">No change recorded' . ($sw['q'] !== '' ? ' for "' . rmmH($sw['q']) . '"' : '') . '. The first list a device sends is a baseline and is not logged as installs.</div>'
        : '<div class="table-responsive"><table class="table table-hover table-sm mb-0"><caption class="visually-hidden">Software changes, newest first</caption>' . rivetRmmUiThead(['When', 'Change', 'Software', 'Version']) . '<tbody>' . $hist . '</tbody></table></div>';

    return $sum . $form . rivetRmmUiCard('Installed software', 'cube', $cur . $pager, '', '', true, 'rmm-sw-current')
        . rivetRmmUiCard('Changes', 'history', $histBody, '', '<span class="small text-muted">latest ' . RIVET_RMM_UI_SOFTWARE_HISTORY . '</span>', true, 'rmm-sw-history');
}

// ------------------------------------------------------------------ jobs

function rivetRmmUiJobLabel(string $type): string
{
    return ['powershell' => 'PowerShell script', 'reboot' => 'Reboot', 'collect' => 'Collect inventory', 'mesh_install' => 'Install MeshCentral agent', 'mesh_uninstall' => 'Remove MeshCentral agent'][$type] ?? $type;
}

/** @param array<int,string> $userNames */
function rivetRmmUiJobs(array $vm, array $userNames): string
{
    $perm = $vm['perm'];
    $states = ['succeeded' => ['ok', 'Succeeded'], 'failed' => ['crit', 'Failed'], 'timed_out' => ['warn', 'Timed out'], 'cancelled' => ['off', 'Cancelled'], 'expired' => ['off', 'Expired'],
        'running' => ['info', 'Running'], 'queued' => ['info', 'Queued']];
    $rows = '';
    foreach ($vm['jobs'] as $j) {
        $s = $states[$j['state']] ?? ['off', ucfirst((string) $j['state'])];
        $took = $j['started_at'] && $j['finished_at'] ? rivetRmmUiDuration(max(0, strtotime($j['finished_at']) - strtotime($j['started_at']))) : '';
        $by = $j['created_by'] > 0 ? ($userNames[$j['created_by']] ?? 'user #' . $j['created_by']) : 'system';
        $done = in_array($j['state'], ['succeeded', 'failed', 'timed_out'], true);
        $acts = '';
        $meshJob = rivetRmmMeshPushIsJob((string) $j['type']);   // rc.16: a pushed Mesh install / removal; its result is read through the remote endpoint (needs rmm.remote.manage), not as script output
        if ($meshJob && !empty($perm['remote_manage'])) {
            $acts .= '<button type="button" class="btn btn-xs btn-info" data-mesh-push-result="' . rmmH($j['job_id']) . '" data-device-id="' . (int) $vm['device_id'] . '" data-post-url="' . RIVET_RMM_MESHPUSH_HANDLER . '" data-csrf="' . rmmH((string) ($_SESSION['csrf_token'] ?? '')) . '" aria-expanded="false" aria-controls="rmm-mres-' . rmmH($j['job_id']) . '">View result</button> ';
        }
        if ($perm['run_saved'] && $done && !$meshJob) {
            $acts .= '<button type="button" class="btn btn-xs btn-info rmm-output-btn" data-job="' . rmmH($j['job_id']) . '" aria-expanded="false" aria-controls="rmm-out-' . rmmH($j['job_id']) . '">View output</button> ';
        }
        if ($perm['run_saved'] && $j['state'] === 'queued') {
            $acts .= '<button type="button" class="btn btn-xs btn-outline-secondary rmm-cancel-btn" data-job="' . rmmH($j['job_id']) . '">Cancel</button>';
        }
        $rows .= '<tr><td class="ps-3">' . rmmH(rivetRmmUiJobLabel((string) $j['type'])) . ($j['destructive'] ? ' ' . rivetRmmUiPill('warn', 'Destructive') : '')
            . '<div class="ifm-mono text-muted small">' . rmmH(substr((string) $j['job_id'], 0, 8)) . '</div></td>'
            . '<td>' . rivetRmmUiPill($s[0], $s[1]) . ($j['state'] === 'queued' ? '<div class="small text-muted">waiting for the device</div>' : '') . ($j['reason'] ? '<div class="small text-muted">' . rmmH($j['reason']) . '</div>' : '') . '</td>'
            . '<td class="text-nowrap">' . rivetRmmUiTime($j['started_at'] ?: $j['created_at']) . '</td><td>' . rmmH($took) . '</td><td>' . rmmH($by) . '</td>'
            . '<td>' . ($j['exit_code'] === null ? '<span class="text-muted">none</span>' : (int) $j['exit_code']) . '</td><td class="text-end pe-2 text-nowrap">' . $acts . '</td></tr>';
        if ($meshJob && !empty($perm['remote_manage'])) {
            $rows .= '<tr id="rmm-mres-' . rmmH($j['job_id']) . '" hidden><td colspan="7" class="ps-3"><div data-mesh-push-result-body></div></td></tr>';
        }
        if ($perm['run_saved'] && $done && !$meshJob) {
            $rows .= '<tr id="rmm-out-' . rmmH($j['job_id']) . '" hidden><td colspan="7" class="ps-3"><div class="small text-muted rmm-out-meta"></div><pre class="rmm-pre mb-1" tabindex="0" aria-label="Job output"></pre>'
                . '<button type="button" class="btn btn-xs btn-outline-secondary rmm-copy-btn">Copy</button></td></tr>';
        }
    }
    if ($vm['jobs'] === []) {
        $table = '<div class="p-3 text-muted small">No jobs yet.</div>';
    } else {
        $table = '<div class="table-responsive"><table class="table table-hover table-sm mb-0">' . rivetRmmUiThead(['Job', 'State', 'Started', 'Took', 'By', 'Exit', '']) . '<tbody>' . $rows . '</tbody></table></div>';
    }
    $note = $perm['run_saved'] ? 'Last 15 jobs. Output has secrets redacted and is size-capped.' : 'Last 15 jobs. Job output is visible to roles that may run jobs.';
    $head = '<span class="small text-muted">' . rmmH($note) . '</span>';

    return rivetRmmUiCard('Jobs and scripts', 'tasks', $table, '', $head, true, 'rmm-jobs-card');
}

// ------------------------------------------------------------------ dialogs (in-page; no window.confirm)

function rivetRmmUiDialogs(array $vm, string $csrf = ''): string
{
    $perm = $vm['perm'];
    $o = '';
    // rc.16: the confirm dialog of the Install / Remove MeshCentral agent buttons (drawn only when a button group is)
    if (rivetRmmMeshPushState($vm) !== null) {
        $GLOBALS['rmm_mesh_push_scripts'] = true;   // includes/footer.php links js/rmm_mesh_push.js only when this is set
        $o .= rivetRmmMeshPushDialog($csrf !== '' ? $csrf : (string) ($_SESSION['csrf_token'] ?? ''));
    }
    // Reboot
    $o .= '<div class="modal fade" id="rmmRebootModal" tabindex="-1" aria-labelledby="rmmRebootTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmRebootTitle"><i class="fas fa-power-off me-2" aria-hidden="true"></i>Reboot ' . rmmH($vm['hostname']) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><p>The device restarts the next time it picks the job up' . ($vm['offline'] ? ' (it is offline now, so this waits until it returns, until the job expires)' : '') . '. Anyone signed in loses unsaved work. The job is sent at most once.</p>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-reboot-confirm"><label class="form-check-label" for="rmm-reboot-confirm">I confirm this device may restart.</label></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-warning" id="rmm-reboot-go" disabled>Reboot device</button></div></div></div></div>';
    // Run script: ONE dialog for everything. "What to run" offers, in this order, the Scripts library (RivetCore, rmm_scripts_v2), a saved script of the legacy
    // `rmm_scripts` table (Windows) and free-form text (PowerShell on Windows, shell on Linux; Core job types `powershell` and `shell`, ability rmm.job.run_script).
    // Library runs post to agent/post/rmm_automation_scr.php (the Jobs-tab card's handler); saved and text runs go through agent/post/rmm_agent.php (js/rmm_panel.js).
    if (in_array($vm['platform'], ['windows', 'linux'], true) && ($perm['run_saved'] || $perm['run_script'])) {
        $o .= rivetRmmUiRunDialog($vm, $csrf !== '' ? $csrf : (string) ($_SESSION['csrf_token'] ?? ''));
    }
    // The header's "+N more" disks modal, drawn whenever there is at least one disk gauge (harmless, and never opened, if the device turns out to
    // have none) so the strip's trigger button never points at a missing modal id.
    if ($vm['state'] !== 'never' && rivetRmmUiMainDiskGauge($vm['gauges']) !== null) {
        $o .= rivetRmmUiDisksModal($vm);
    }
    // The header's "Map Mesh node" modal: only for an administrator, and only while there is something to map (Mesh configured).
    if ($perm['admin'] && $vm['mesh']['configured']) {
        $o .= rivetRmmUiMeshMapModal($vm);
    }

    return $o;
}

/** The Run script dialog (see rivetRmmUiDialogs). */
function rivetRmmUiRunDialog(array $vm, string $csrf): string
{
    $perm = $vm['perm'];
    $linux = $vm['platform'] === 'linux';
    $lib = $vm['library'] ?? ['on' => false, 'scripts' => [], 'error' => '', 'truncated' => false];
    $hasLib = $lib['scripts'] !== [];
    $hasSaved = $perm['run_saved'] && $vm['saved_scripts'] !== [];
    $textWord = $linux ? 'Shell (type or paste)' : 'PowerShell (type or paste)';
    $kinds = [];   // value => label
    if ($hasLib) {
        $kinds['library'] = 'Script library (' . count($lib['scripts']) . ')';
    }
    if ($hasSaved) {
        $kinds['saved'] = 'A saved script';
    }
    if ($perm['run_script']) {
        $kinds['text'] = $textWord;
    }
    $self = '/agent/rmm_script_library.php';
    $note = '';
    if ($lib['error'] !== '') {
        $note = '<div class="alert alert-warning small" role="alert">The script library could not be read: ' . rmmH($lib['error']) . '</div>';
    } elseif ($lib['on'] && !$hasLib) {
        $note = '<div class="alert alert-info small" role="note"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>The library has no script for ' . ($linux ? 'Linux' : 'Windows') . ' devices yet. '
            . ($perm['admin'] ? 'Install the ready-made set from the <a href="/agent/rmm_starter_pack.php">Starter pack</a>, or add your own under <a href="' . $self . '">Scripts</a>.' : 'Ask an administrator to add scripts under <a href="' . $self . '">Scripts</a>.') . '</div>';
    }
    if ($kinds === []) {
        $body = '<div class="modal-body">' . $note . '<p class="text-muted mb-0">Nothing can be run from here yet: no library script, no saved script, and your role cannot run free-form text.</p></div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>';
        $legacy = '';
        $pre = '';
    } else {
        $keys = array_keys($kinds);
        if (count($kinds) > 1) {
            $sel = '';
            foreach ($kinds as $v => $l) {
                $sel .= '<option value="' . $v . '">' . rmmH($l) . '</option>';
            }
            $pickEl = '<label class="form-label" for="rmm-run-type">What to run</label><select class="form-select" id="rmm-run-type" aria-controls="' . ($hasLib ? 'rmm-run-lib-form ' : '') . 'rmm-run-form">' . $sel . '</select>';
        } else {
            $pickEl = '<span class="form-label d-block" id="rmm-run-type-l">What to run</span><input type="hidden" id="rmm-run-type" value="' . $keys[0] . '"><div class="form-control-plaintext fw-semibold" aria-labelledby="rmm-run-type-l">' . rmmH($kinds[$keys[0]]) . '</div>';
        }
        $pre = '<div class="modal-body pb-0">' . $note . '<div class="row g-2"><div class="col-md-6">' . $pickEl . '</div></div></div>';
        $body = '';
        if ($hasLib) {
            $body .= rivetRmmScrRunDialogLibrary($vm, $lib, $csrf);
        }
        // legacy `rmm_scripts` rows: the ones written here, and the ones synced from the vendor RMM (grouped under its real name; both run as text through the agent)
        $mine = $synced = '';
        foreach ($hasSaved ? $vm['saved_scripts'] : [] as $s) {
            $opt = '<option value="' . (int) $s['id'] . '">' . rmmH($s['name']) . '</option>';
            if (!empty($s['tactical_script_id'])) { $synced .= $opt; } else { $mine .= $opt; }
        }
        $provider = $synced !== '' && ($GLOBALS['mysqli'] ?? null) instanceof \mysqli && function_exists('rivetRmmScrLegacyProviderName') ? rivetRmmScrLegacyProviderName($GLOBALS['mysqli']) : 'RMM';
        $saved = $synced === '' ? $mine : ($mine !== '' ? '<optgroup label="Saved here">' . $mine . '</optgroup>' : '') . '<optgroup label="Synced from ' . rmmH($provider) . '">' . $synced . '</optgroup>';
        $legacy = '';
        if ($hasSaved || $perm['run_script']) {
            $legacy = '<form id="rmm-run-form" class="rmm-run-pane" data-rmm-run-pane="legacy"' . ($keys[0] === 'library' ? ' hidden' : '') . '><div class="modal-body"><div class="row g-2">'
                . '<div class="col-md-6"><label class="form-label" for="rmm-run-timeout">Timeout, seconds</label><input class="form-control" type="number" min="5" max="3600" value="300" id="rmm-run-timeout" name="timeout_s"></div>'
                . ($hasSaved ? '<div class="col-12' . ($keys[0] === 'saved' ? '' : ' d-none') . '" id="rmm-run-saved-wrap"><label class="form-label" for="rmm-run-saved">Saved script</label><select class="form-select" id="rmm-run-saved" name="script_id">' . $saved . '</select></div>' : '')
                . ($perm['run_script'] ? '<div class="col-12' . ($keys[0] === 'text' ? '' : ' d-none') . '" id="rmm-run-text-wrap"><label class="form-label" for="rmm-run-text">' . rmmH($textWord) . '</label><textarea class="form-control font-monospace" rows="6" id="rmm-run-text" name="script" maxlength="100000" spellcheck="false"></textarea>'
                    . '<div class="form-text">' . ($linux ? 'Runs with bash, or POSIX sh when bash is missing, as root.' : 'Runs as SYSTEM.') . ' Free-form text is not versioned or signed: prefer a library script.</div></div>' : '')
                . '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-run-destructive"><label class="form-check-label" for="rmm-run-destructive">This script changes or removes things (destructive)</label></div></div>'
                . '<div class="col-12 d-none" id="rmm-run-confirm-wrap"><div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-run-confirm"><label class="form-check-label" for="rmm-run-confirm">I confirm it may run now as ' . ($linux ? 'root' : 'SYSTEM') . '.</label></div></div></div></div>'
                . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="rmm-run-go">Queue job</button></div></form>';
        }
        $body .= $legacy;
    }

    return '<div class="modal fade" id="rmmRunModal" tabindex="-1" aria-labelledby="rmmRunTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmRunTitle"><i class="fas fa-play me-2" aria-hidden="true"></i>Run a script on ' . rmmH($vm['hostname']) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . $pre . $body . '</div></div></div>';
}

// ------------------------------------------------------------------ panel wrapper

/**
 * The tab card: Overview, Inventory, Jobs. The strip is rendered separately above the page's two columns.
 *
 * @param array<int,string> $userNames
 */
function rivetRmmUiTabs(array $vm, string $performanceHtml, string $performanceNote, array $userNames, string $csrf, string $csp = ''): string
{
    $tab = static fn (string $id, string $icon, string $label, bool $active): string => '<li class="nav-item" role="presentation"><button type="button" class="nav-link small' . ($active ? ' active' : '')
        . '" id="rmm-tab-' . $id . '" data-bs-toggle="tab" data-bs-target="#rmm-pane-' . $id . '" role="tab" aria-controls="rmm-pane-' . $id . '" aria-selected="' . ($active ? 'true' : 'false')
        . '"><i class="fas fa-' . $icon . ' me-1" aria-hidden="true"></i>' . $label . '</button></li>';

    // RMM Phase 2 and 3 tabs. Each hook returns '' when its sub-switch is off or the viewer may see nothing, and then neither the tab nor the pane is drawn.
    $mysqli = $GLOBALS['mysqli'] ?? null;
    $polHtml = $scrHtml = $alrHtml = '';
    if ($mysqli instanceof \mysqli) {
        $polHtml = function_exists('rivetRmmPolPanelSection') && ($vm['features']['policies'] || $vm['features']['scripts']) ? rivetRmmPolPanelSection($vm, $mysqli, $csrf) : '';
        $scrHtml = function_exists('rivetRmmScrDeviceSection') && $vm['features']['scripts'] ? rivetRmmScrDeviceSection($vm, $mysqli, $csrf) : '';
        $alrHtml = function_exists('rivetRmmAlrPanelSection') && $vm['features']['alerting'] ? rivetRmmAlrPanelSection($vm, $mysqli, $csrf) : '';
    }
    // RMM Phase 6 and 8 (RivetCore 1.0.0-rc.11): the Remote tab (services, processes, files, Mesh; includes/rmm_rem_ui.php) and the Logs tab (includes/rmm_log_ui.php). Each function
    // checks the module, its own sub-switch and the viewer's ability itself and returns '' when there is nothing to show, and then neither the tab nor the pane is drawn.
    $remHtml = $logHtml = $patHtml = $swHtml = $chgHtml = $autoHtml = $hvHtml = '';
    if ($mysqli instanceof \mysqli) {
        $remHtml = function_exists('rivetRmmRemPanelSection') ? rivetRmmRemPanelSection($vm, $mysqli, $csrf) : '';
        $logHtml = function_exists('rivetRmmLogPanelSection') ? rivetRmmLogPanelSection($vm, $mysqli, $csrf) : '';
        // RMM Phase 4 (RivetCore 1.0.0-rc.20): the Patches tab (includes/rmm_pat_ui.php); '' while the module or the `patching` switch is off or the viewer may not read patch state.
        $patHtml = function_exists('rivetRmmPatPanelSection') && !empty($vm['features']['patching']) ? rivetRmmPatPanelSection($vm, $mysqli, $csrf) : '';
        // RMM Phase 5 (RivetCore 1.0.0-rc.21): the deployment block at the top of the Software tab (includes/rmm_sw_ui.php); '' while the module or the `software_deploy` switch is off or the viewer may not read it.
        $swHtml = function_exists('rivetRmmSwPanelSection') && !empty($vm['features']['software_deploy']) ? rivetRmmSwPanelSection($vm, $mysqli, $csrf) : '';
        // RMM change monitoring (RivetCore 1.0.0-rc.22): the read-only Changes tab (includes/rmm_chg_ui.php); '' while the `change_monitor` switch is off or the viewer lacks rmm.change.view.
        if (!empty($vm['features']['change_monitor'])) {
            require_once __DIR__ . '/rmm_chg_ui.php';
            $chgHtml = rivetRmmChgPanelSection($vm, $mysqli, $csrf);
        }
        // Install apps when the agent installs (RivetCore 1.0.0-rc.26, migration 0033): its own tab, "Apps with agent" (includes/rmm_auto_install_ui.php); no new sub-switch,
        // same `software_deploy` feature as the Software tab. '' while the module or the switch is off or the viewer may not read software deployment.
        $autoHtml = function_exists('rivetRmmAutoInstPanelSection') && !empty($vm['features']['software_deploy']) ? rivetRmmAutoInstPanelSection($vm, $mysqli, $csrf) : '';
        // Hypervisor visibility (RivetCore 1.0.0-rc.29, migration 0036): a "Hypervisor" tab (includes/rmm_hv_ui.php), but ONLY for a device that is itself a reporting
        // hypervisor host — '' for every other device, which is most of them, so the tab usually never appears. Not a VM's own tab: a VM belongs to its host's page.
        $hvHtml = function_exists('rivetRmmHvPanelSection') && !empty($vm['features']['hypervisor']) ? rivetRmmHvPanelSection($vm, $mysqli, $csrf) : '';
    }

    return '<div class="card card-dark mb-3" id="rmm-panel" data-device-id="' . (int) $vm['device_id'] . '" data-csrf="' . rmmH($csrf) . '" data-post-url="/agent/post/rmm_agent.php" data-output-url="/agent/rmm_job_output.php" '
        . 'data-platform="' . rmmH($vm['platform']) . '" data-offline="' . ($vm['offline'] ? '1' : '0') . '">'
        . '<div class="card-header p-0 border-bottom-0"><ul class="nav nav-tabs px-3 pt-2 rmm-tabbar" role="tablist" aria-label="RMM device sections">'
        . $tab('overview', 'tachometer-alt', 'Overview', true) . $tab('inventory', 'microchip', 'Inventory', false) . ($vm['software'] !== null || $swHtml !== '' ? $tab('software', 'cube', 'Software', false) : '') . $tab('jobs', 'tasks', 'Jobs', false)
        . ($polHtml !== '' ? $tab('policy', 'sliders-h', 'Policy and fields', false) : '') . ($alrHtml !== '' ? $tab('alerting', 'bell', 'Alerting', false) : '') . ($remHtml !== '' ? $tab('remote', 'terminal', 'Remote', false) : '') . ($logHtml !== '' ? $tab('logs', 'scroll', 'Logs', false) : '') . ($patHtml !== '' ? $tab('patches', 'shield-alt', 'Patches', false) : '') . ($chgHtml !== '' ? $tab('changes', 'history', 'Changes', false) : '') . ($autoHtml !== '' ? $tab('autoinstall', 'download', 'Apps with agent', false) : '') . ($hvHtml !== '' ? $tab('hypervisor', 'server', 'Hypervisor', false) : '') . '</ul></div>'
        . '<div class="px-3 pt-3"><div id="rmm-msg" class="alert d-none mb-0" role="status" aria-live="polite"></div></div>'
        . '<div class="tab-content"><div class="tab-pane active p-3" id="rmm-pane-overview" role="tabpanel" aria-labelledby="rmm-tab-overview" tabindex="0">' . rivetRmmUiOverview($vm, $performanceHtml, $performanceNote) . '</div>'
        . '<div class="tab-pane p-3" id="rmm-pane-inventory" role="tabpanel" aria-labelledby="rmm-tab-inventory" tabindex="0">' . rivetRmmUiInventory($vm) . '</div>'
        . ($vm['software'] !== null || $swHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-software" role="tabpanel" aria-labelledby="rmm-tab-software" tabindex="0">' . $swHtml . ($vm['software'] !== null ? rivetRmmUiSoftware($vm) : '') . '</div>' : '')
        . '<div class="tab-pane p-3" id="rmm-pane-jobs" role="tabpanel" aria-labelledby="rmm-tab-jobs" tabindex="0">' . $scrHtml . rivetRmmUiJobs($vm, $userNames) . '</div>'
        . ($polHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-policy" role="tabpanel" aria-labelledby="rmm-tab-policy" tabindex="0">' . $polHtml . '</div>' : '')
        . ($alrHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-alerting" role="tabpanel" aria-labelledby="rmm-tab-alerting" tabindex="0">' . $alrHtml . '</div>' : '')
        . ($remHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-remote" role="tabpanel" aria-labelledby="rmm-tab-remote" tabindex="0">' . $remHtml . '</div>' : '')
        . ($logHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-logs" role="tabpanel" aria-labelledby="rmm-tab-logs" tabindex="0">' . $logHtml . '</div>' : '')
        . ($patHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-patches" role="tabpanel" aria-labelledby="rmm-tab-patches" tabindex="0">' . $patHtml . '</div>' : '')
        . ($chgHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-changes" role="tabpanel" aria-labelledby="rmm-tab-changes" tabindex="0">' . $chgHtml . '</div>' : '')
        . ($autoHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-autoinstall" role="tabpanel" aria-labelledby="rmm-tab-autoinstall" tabindex="0">' . $autoHtml . '</div>' : '')
        . ($hvHtml !== '' ? '<div class="tab-pane p-3" id="rmm-pane-hypervisor" role="tabpanel" aria-labelledby="rmm-tab-hypervisor" tabindex="0">' . $hvHtml . '</div>' : '') . '</div></div>'
        . rivetRmmUiDialogs($vm, $csrf)
        . ($polHtml . $scrHtml . $alrHtml . $patHtml . $swHtml . $autoHtml !== '' ? rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn() : '');
}

/** @return array<int,string> user_id => name for the job rows (one query, none when there are no jobs). */
function rivetRmmUiJobUserNames(\mysqli $mysqli, array $vm): array
{
    $ids = [];
    foreach ($vm['jobs'] as $j) {
        if ((int) $j['created_by'] > 0) {
            $ids[(int) $j['created_by']] = true;
        }
    }
    if ($ids === []) {
        return [];
    }
    $out = [];
    $r = mysqli_query($mysqli, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', array_keys($ids)) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['user_id']] = (string) $row['user_name'];
    }

    return $out;
}

// ------------------------------------------------------------------ fleet page renderers

function rivetRmmUiDonut(array $parts): string
{
    $tot = 0;
    foreach ($parts as $p) {
        $tot += $p['n'];
    }
    $r = 46;
    $c = 2 * M_PI * $r;
    $off = 0.0;
    $aria = 'Fleet status: ' . implode(', ', array_map(static fn ($p) => $p['n'] . ' ' . $p['l'], $parts));
    $o = '<svg width="140" height="140" viewBox="0 0 140 140" role="img" aria-label="' . rmmH($aria) . '" class="rmm-gauge"><circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="var(--if-border-strong)" stroke-width="18"/>';
    foreach ($parts as $p) {
        if ($p['n'] <= 0 || $tot <= 0) {
            continue;
        }
        $len = $p['n'] / $tot * $c;
        $o .= sprintf('<circle cx="70" cy="70" r="%d" fill="none" stroke="%s" stroke-width="18" stroke-dasharray="%.2f %.2f" stroke-dashoffset="%.2f" transform="rotate(-90 70 70)"/>', $r, $p['col'], max(0, $len - 2), $c - max(0, $len - 2), -$off);
        $off += $len;
    }

    return $o . '<text x="70" y="70" text-anchor="middle" style="font-size:26px;font-weight:600;fill:var(--if-ink)">' . $tot . '</text><text x="70" y="88" text-anchor="middle" style="font-size:12px;fill:var(--if-muted)">devices</text></svg>';
}

function rivetRmmUiInfoBox(string $cls, string $icon, string $label, $num, string $sub = '', string $href = ''): string
{
    $inner = '<div class="info-box bg-' . rmmH($cls) . ' mb-0"><span class="info-box-icon"><i class="fas fa-' . rmmH($icon) . '" aria-hidden="true"></i></span><div class="info-box-content"><span class="info-box-text">' . rmmH($label)
        . '</span><span class="info-box-number">' . rmmH($num) . '</span>' . ($sub !== '' ? '<span class="info-box-text rmm-ib-sub">' . rmmH($sub) . '</span>' : '') . '</div></div>';

    return '<div class="col-6 col-md-4 col-xl-2 mb-2">' . ($href !== '' ? '<a class="text-decoration-none" href="' . rmmH($href) . '">' . $inner . '</a>' : $inner) . '</div>';
}

function rivetRmmUiStatusPill(string $state): string
{
    return ['online' => rivetRmmUiPill('ok', 'Online'), 'offline' => rivetRmmUiPill('crit', 'Offline'), 'stale' => rivetRmmUiPill('off', 'Stale'), 'never' => rivetRmmUiPill('off', 'Never seen')][$state] ?? rivetRmmUiPill('off', ucfirst($state));
}

/** A device name that links to its asset page when it has one. */
function rivetRmmUiDeviceLink(array $d): string
{
    $name = $d['asset_name'] ?? null;
    $label = rmmH($d['hostname'] !== '' ? $d['hostname'] : ($name ?? 'device'));
    $aid = (int) ($d['asset_id'] ?? 0);

    require_once __DIR__ . '/rmm_clock_ui.php';
    $badge = rivetRmmClockBadge(is_array($d['clock'] ?? null) ? $d['clock'] : null);

    return ($aid > 0 ? '<a class="fw-bold" href="/agent/asset_details.php?asset_id=' . $aid . '">' . $label . '</a>' : '<span class="fw-bold">' . $label . '</span>') . $badge;
}

/**
 * @param array<string,mixed>|null $installer rivetRmmInstallerContext(): when set the page gets the "Add device" button, the empty-state button and the dialog
 */
function rivetRmmUiFleetPage(array $f, string $csrf, ?array $installer = null, ?array $installerGate = null): string
{
    $c = $f['counts'];
    $names = $f['client_names'];
    $cn = static fn (int $id): string => $id > 0 ? ($names[$id] ?? 'department #' . $id) : 'unassigned';
    $now = $f['now'];

    $kpi = rivetRmmUiInfoBox('success', 'circle', 'Online', $c['online'], 'of ' . $c['total'] . ' managed', '/agent/rmm_fleet.php?status=online')
        . rivetRmmUiInfoBox('danger', 'times-circle', 'Offline', $c['offline'], 'quiet up to 7 days', '/agent/rmm_fleet.php?status=offline')
        . rivetRmmUiInfoBox('secondary', 'moon', 'Stale', $c['stale'], 'quiet over 7 days', '/agent/rmm_fleet.php?status=stale')
        . rivetRmmUiInfoBox('primary', 'hourglass-half', 'Never seen', $c['never'], 'enrolled, no check-in', '/agent/rmm_fleet.php?status=never')
        . rivetRmmUiInfoBox($c['pending_approval'] > 0 ? 'warning' : 'secondary', 'user-clock', 'Need approval', $c['pending_approval'], 'waiting for a decision', '/agent/rmm_fleet.php?status=pending_approval')
        . rivetRmmUiInfoBox($f['open_alerts'] > 0 ? 'danger' : 'secondary', 'bell', 'Open alerts', $f['open_alerts'], 'agent alerts', '/agent/alerts.php?status=new');

    $legend = static fn (string $col, string $l, int $n): string => '<li class="d-flex align-items-center gap-2"><span class="ifm-legend-swatch" style="background:' . $col . '"></span>' . rmmH($l) . ' <b class="ms-auto">' . $n . '</b></li>';
    $donut = rivetRmmUiDonut([['n' => $c['online'], 'l' => 'online', 'col' => 'var(--tblr-success)'], ['n' => $c['offline'], 'l' => 'offline', 'col' => 'var(--tblr-danger)'],
        ['n' => $c['stale'], 'l' => 'stale', 'col' => 'var(--if-muted)'], ['n' => $c['never'], 'l' => 'never seen', 'col' => 'var(--tblr-blue)']]);
    $health = '<div class="card-body d-flex align-items-center flex-wrap gap-4 justify-content-center py-4">' . $donut . '<ul class="list-unstyled mb-0 rmm-legend">'
        . $legend('var(--tblr-success)', 'Online', $c['online']) . $legend('var(--tblr-danger)', 'Offline', $c['offline']) . $legend('var(--if-muted)', 'Stale (quiet 7+ days)', $c['stale'])
        . $legend('var(--tblr-blue)', 'Never checked in', $c['never']) . '</ul></div>';
    $healthCard = '<section class="card card-dark mb-3"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-heartbeat me-2 text-secondary" aria-hidden="true"></i>Fleet health</h6></div>' . $health . '</section>';

    // Agent versions and rings
    $ringRows = '';
    foreach ($f['rings'] as $ring => $n) {
        $ringRows .= '<li class="d-flex align-items-center gap-2"><span class="text-capitalize">' . rmmH($ring) . ' ring</span><b class="ms-auto">' . (int) $n . '</b></li>';
    }
    $verRows = '';
    foreach (array_slice($f['versions'], 0, 8, true) as $v => $n) {
        $verRows .= '<li class="d-flex align-items-center gap-2"><span class="ifm-mono">' . rmmH($v) . '</span><b class="ms-auto">' . (int) $n . '</b></li>';
    }
    $cur = array_filter($f['current_versions']);
    $outRows = '';
    foreach ($f['outdated'] as $d) {
        $outRows .= '<li class="list-group-item d-flex align-items-center gap-2"><div class="me-auto min-w-0">' . rivetRmmUiDeviceLink($d) . '<div class="text-muted small">' . rmmH($cn((int) $d['client_id'])) . '</div></div>'
            . '<span class="ifm-mono small">' . rmmH($d['agent_version']) . '</span><i class="fas fa-arrow-right text-muted" aria-label="should be"></i><span class="ifm-mono small">' . rmmH($d['target_version']) . '</span></li>';
    }
    $versionsBody = '<div class="card-body pb-2"><div class="row g-4"><div class="col-md-6 col-xl-3"><div class="ifm-toolbar-label mb-1">Rings</div><ul class="list-unstyled mb-0 rmmx-kv">' . ($ringRows ?: '<li class="text-muted">none</li>') . '</ul></div>'
        . '<div class="col-md-6 col-xl-3"><div class="ifm-toolbar-label mb-1">Versions</div><ul class="list-unstyled mb-0 rmmx-kv">' . ($verRows ?: '<li class="text-muted">none</li>') . '</ul></div></div>'
        . '<p class="small text-muted mt-2 mb-0">' . ($cur ? 'Current hosted version: ' . rmmH(implode(' / ', $cur)) . '.' : 'No agent binary is hosted yet, so nothing counts as outdated.')
        . ($f['scan_partial'] ? ' Counts cover the first ' . (int) $f['scanned'] . ' devices.' : '') . '</p></div>'
        . '<ul class="list-group list-group-flush">' . ($outRows ?: '<li class="list-group-item text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No outdated agents.</li>') . '</ul>';
    $versionsCard = '<section class="card card-dark mb-3"><div class="card-header py-2 d-flex align-items-center" style="gap:4px"><h6 class="mb-0 me-auto"><i class="fas fa-code-branch me-2 text-secondary" aria-hidden="true"></i>Agent versions and rings</h6>'
        . ($f['outdated_total'] > 0 ? rivetRmmUiPill('warn', $f['outdated_total'] . ' outdated') : rivetRmmUiPill('ok', 'Up to date')) . '</div>' . $versionsBody . '</section>';

    // Offline and stale
    $li = static function (array $d, string $right) use ($cn): string {
        return '<li class="list-group-item d-flex align-items-center gap-2"><div class="me-auto min-w-0">' . rivetRmmUiDeviceLink($d) . '<div class="text-muted small">' . rmmH($cn((int) $d['client_id'])) . '</div></div>' . $right . '</li>';
    };
    $offRows = '';
    foreach (array_merge($f['offline'], $f['stale']) as $d) {
        $offRows .= $li($d, rivetRmmUiStatusPill($d['status']) . '<span class="text-muted small text-nowrap">' . rivetRmmUiTime($d['last_checkin_at'], $now) . '</span>');
    }
    $offCard = '<section class="card card-dark mb-0 h-100"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-times-circle me-2 text-secondary" aria-hidden="true"></i>Offline and stale</h6></div><ul class="list-group list-group-flush">'
        . ($offRows ?: '<li class="list-group-item text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>Every managed device has checked in recently.</li>') . '</ul></section>';

    // Needs approval
    $apRows = '';
    foreach ($f['approvals'] as $a) {
        $apRows .= '<li class="list-group-item"><div class="d-flex align-items-center gap-2"><div class="me-auto min-w-0"><span class="fw-bold">' . rmmH($a['hostname']) . '</span><div class="text-muted small">' . rmmH($cn((int) $a['client_id']))
            . ' &middot; first seen ' . rivetRmmUiTime($a['first_seen_at'], $now) . '</div></div>' . rivetRmmUiPill('warn', 'Waiting') . '</div><div class="small mt-1">' . rmmH($a['match_reason_text']) . '</div></li>';
    }
    $apHead = $f['perm']['manage'] ? '<a class="small" href="/admin/settings_endpoint_agent.php#devices">Review in administration</a>' : '<span class="small text-muted">An administrator approves devices.</span>';
    $apCard = '<section class="card card-dark mb-3" id="rmm-approvals"><div class="card-header py-2 d-flex align-items-center" style="gap:4px"><h6 class="mb-0 me-auto"><i class="fas fa-user-clock me-2 text-secondary" aria-hidden="true"></i>Devices needing approval</h6>'
        . $apHead . '</div><ul class="list-group list-group-flush">' . ($apRows ?: '<li class="list-group-item text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No device is waiting for approval.</li>') . '</ul></section>';

    // Recent job failures
    $fRows = '';
    foreach ($f['failures'] as $j) {
        $fRows .= '<li class="list-group-item d-flex align-items-center gap-2"><div class="me-auto min-w-0">'
            . ($j['asset_id'] ? '<a class="fw-bold" href="/agent/asset_details.php?asset_id=' . (int) $j['asset_id'] . '#rmm-jobs">' . rmmH($j['hostname']) . '</a>' : '<span class="fw-bold">' . rmmH($j['hostname']) . '</span>')
            . '<div class="text-muted small">' . rmmH(rivetRmmUiJobLabel($j['type'])) . ($j['exit_code'] !== null ? ' &middot; exit ' . (int) $j['exit_code'] : '') . ($j['reason'] ? ' &middot; ' . rmmH($j['reason']) : '') . '</div></div>'
            . rivetRmmUiPill($j['state'] === 'timed_out' ? 'warn' : 'crit', $j['state'] === 'timed_out' ? 'Timed out' : 'Failed') . '<span class="text-muted small text-nowrap">' . rivetRmmUiTime($j['at'], $now) . '</span></li>';
    }
    $fCard = '<section class="card card-dark mb-0 h-100"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-tasks me-2 text-secondary" aria-hidden="true"></i>Recent job failures</h6></div><ul class="list-group list-group-flush">'
        . ($fRows ?: '<li class="list-group-item text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No failed jobs.</li>') . '</ul></section>';

    // Device table
    $rows = '';
    foreach ($f['list']['items'] as $d) {
        $chips = '';
        foreach (array_slice((array) ($d['tags'] ?? []), 0, 6) as $t) {
            $chips .= rivetRmmUiTagChip($t, false) . ' ';
        }
        $rows .= '<tr><td class="ps-3">' . rivetRmmUiStatusPill($d['status']) . ($d['link_state'] === 'pending_approval' ? ' ' . rivetRmmUiPill('warn', 'Approval') : '') . ($d['revoked'] ? ' ' . rivetRmmUiPill('crit', 'Revoked') : '') . '</td>'
            . '<td class="min-w-0">' . rivetRmmUiDeviceLink($d) . '<div class="text-muted small">' . rmmH($cn((int) $d['client_id'])) . ' &middot; ' . rmmH($d['os_version'] ?: 'OS not reported') . '</div>'
            . ($chips !== '' ? '<div class="mt-1 rmm-tags">' . $chips . (count((array) $d['tags']) > 6 ? '<span class="small text-muted">+' . (count((array) $d['tags']) - 6) . ' more</span>' : '') . '</div>' : '') . '</td>'
            . '<td class="ifm-mono">' . rmmH($d['agent_version'] ?: 'unknown') . '<div class="text-muted small">' . rmmH($d['ring']) . ' ring</div></td>'
            . '<td class="text-nowrap">' . rivetRmmUiTime($d['last_checkin_at'], $now) . '</td></tr>';
    }
    if ($f['list']['items'] === []) {
        $rows = '<tr><td colspan="4" class="p-3 text-muted">No device matches.</td></tr>';
    }
    $qs = static function (array $over) use ($f): string {
        $q = array_merge($f['filters'], $f['keep'] ?? [], ['page' => $f['page']], $over);
        $q = array_filter($q, static fn ($v) => $v !== '' && $v !== null && $v !== 1 && $v !== 0);

        return '/agent/rmm_fleet.php' . ($q ? '?' . http_build_query($q) : '');
    };
    $chip = static function (string $label, string $status) use ($f, $qs): string {
        $active = ($f['filters']['status'] ?? '') === $status;

        return '<a class="btn btn-sm ' . ($active ? 'btn-primary' : 'btn-outline-secondary') . '" href="' . rmmH($qs(['status' => $status, 'page' => 1])) . '"' . ($active ? ' aria-current="true"' : '') . '>' . rmmH($label) . '</a>';
    };
    $filter = '<form class="d-flex flex-wrap align-items-center gap-2 p-3 border-bottom" method="get" action="/agent/rmm_fleet.php" role="search" aria-label="Filter devices">'
        . '<label class="visually-hidden" for="rmm-f-q">Search by hostname or serial number</label><input class="form-control form-control-sm w-auto" id="rmm-f-q" name="q" type="search" maxlength="100" placeholder="Hostname or serial" value="' . rmmH($f['filters']['q'] ?? '') . '">'
        . '<label class="visually-hidden" for="rmm-f-status">Status</label><select class="form-select form-select-sm w-auto" id="rmm-f-status" name="status"><option value="">Any status</option>';
    foreach (['online' => 'Online', 'offline' => 'Offline', 'stale' => 'Stale', 'never' => 'Never seen', 'pending_approval' => 'Needs approval', 'linked' => 'Linked', 'rejected' => 'Rejected'] as $v => $l) {
        $filter .= '<option value="' . $v . '"' . (($f['filters']['status'] ?? '') === $v ? ' selected' : '') . '>' . $l . '</option>';
    }
    $filter .= '</select><label class="visually-hidden" for="rmm-f-ring">Ring</label><select class="form-select form-select-sm w-auto" id="rmm-f-ring" name="ring"><option value="">Any ring</option>';
    foreach (['stable', 'pilot'] as $v) {
        $filter .= '<option value="' . $v . '"' . (($f['filters']['ring'] ?? '') === $v ? ' selected' : '') . '>' . ucfirst($v) . '</option>';
    }
    $filter .= '</select>';
    if ($f['tag_list'] !== []) {
        $filter .= '<label class="visually-hidden" for="rmm-f-tag">Tag</label><select class="form-select form-select-sm w-auto" id="rmm-f-tag" name="tag"><option value="">Any tag</option>';
        foreach ($f['tag_list'] as $t) {
            $filter .= '<option value="' . rmmH($t['name']) . '"' . (($f['filters']['tag'] ?? '') === (string) $t['name'] ? ' selected' : '') . '>' . rmmH($t['name']) . ' (' . (int) ($t['device_count'] ?? 0) . ')</option>';
        }
        $filter .= '</select>';
    }
    if ($f['group_list'] !== []) {
        $filter .= '<label class="visually-hidden" for="rmm-f-group">Group</label><select class="form-select form-select-sm w-auto" id="rmm-f-group" name="group"><option value="">Any group</option>';
        foreach ($f['group_list'] as $g) {
            $filter .= '<option value="' . (int) $g['group_id'] . '"' . ((int) ($f['filters']['group'] ?? 0) === (int) $g['group_id'] ? ' selected' : '') . '>' . rmmH($g['name']) . '</option>';
        }
        $filter .= '</select>';
    }
    if ($f['inventory_on']) {
        $filter .= '<label class="visually-hidden" for="rmm-f-sw">Has software installed</label><input class="form-control form-control-sm w-auto" id="rmm-f-sw" name="software" type="search" maxlength="100" placeholder="Has software (name)" value="' . rmmH($f['filters']['software'] ?? '') . '">';
    }
    foreach ($f['keep'] ?? [] as $k => $v) {
        $filter .= '<input type="hidden" name="' . rmmH($k) . '" value="' . rmmH($v) . '">';
    }
    $filter .= '<button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-search me-1" aria-hidden="true"></i>Filter</button>'
        . ($f['filters'] ? '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_fleet.php">Clear</a>' : '') . '</form>';
    $pager = '';
    if ($f['pages'] > 1) {
        $pager = '<nav class="d-flex align-items-center justify-content-between p-3 border-top" aria-label="Device pages"><span class="small text-muted">Page ' . (int) $f['page'] . ' of ' . (int) $f['pages'] . ', ' . (int) $f['list']['total'] . ' devices</span><div class="btn-group btn-group-sm">'
            . ($f['page'] > 1 ? '<a class="btn btn-outline-secondary" href="' . rmmH($qs(['page' => $f['page'] - 1])) . '" rel="prev">Previous</a>' : '<span class="btn btn-outline-secondary disabled" aria-disabled="true">Previous</span>')
            . ($f['page'] < $f['pages'] ? '<a class="btn btn-outline-secondary" href="' . rmmH($qs(['page' => $f['page'] + 1])) . '" rel="next">Next</a>' : '<span class="btn btn-outline-secondary disabled" aria-disabled="true">Next</span>') . '</div></nav>';
    } else {
        $pager = '<div class="p-3 border-top small text-muted">' . (int) $f['list']['total'] . ' device' . ($f['list']['total'] === 1 ? '' : 's') . '</div>';
    }
    $clockLine = (int) ($f['clock_problems'] ?? 0) > 0
        ? '<div class="alert alert-warning py-2 px-3 mb-0 rounded-0 small" id="rmm-clock-fleet"><i class="fas fa-clock me-1" aria-hidden="true"></i>' . (int) $f['clock_problems'] . ' device' . ((int) $f['clock_problems'] === 1 ? ' on this page has' : 's on this page have') . ' a wrong clock or time zone (see the Clock badge). Fix the time zone and sync the time on those devices.</div>'
        : '';
    $table = '<section class="card card-dark mb-3" id="rmm-devices"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-desktop me-2" aria-hidden="true"></i>Devices</h6></div>' . $clockLine . $filter
        . '<div class="table-responsive"><table class="table table-hover table-sm mb-0"><caption class="visually-hidden">Agent devices</caption>' . rivetRmmUiThead(['Status', 'Device', 'Agent', 'Last check-in']) . '<tbody>' . $rows . '</tbody></table></div>' . $pager . '</section>';

    $capacity = $f['capacity'] !== null ? rivetRmmUiCapacity($f['capacity']) : '';
    $swCard = $f['outdated_sw'] !== null ? rivetRmmUiOutdatedSoftware($f['outdated_sw'], $cn, $f) : '';

    return '<div class="rmmx-head"><h1><i class="fas fa-satellite text-secondary" aria-hidden="true"></i>Agent fleet</h1><div class="rmmx-head-actions align-items-center">'
        . ($installerGate !== null ? preg_replace('/\bbtn-sm\b\s*/', '', rivetRmmInstallerCreateButton($installerGate)) : '')   // 26.10.54: the primary button, drawn for everyone (muted, with the reason below, without the right)
        . ($installer !== null ? rivetRmmInstallerTrigger('button', 0, 'Add device', 'btn btn-outline-secondary') : '')
        . rivetRmmMeshBulkTrigger($f['mesh_bulk'] ?? null)
        . '<a href="/agent/rmm_assets.php" class="btn btn-outline-secondary"><i class="fas fa-desktop me-1" aria-hidden="true"></i>All RMM assets</a>'
        . '<a href="/agent/alerts.php" class="btn btn-outline-secondary"><i class="fas fa-bell me-1" aria-hidden="true"></i>Alerts</a>'
        . ($f['perm']['admin'] ? '<a href="/admin/settings_endpoint_agent.php" class="btn btn-outline-secondary"><i class="fas fa-cog me-1" aria-hidden="true"></i>Administration</a>' : '') . '</div></div>'
        . ($installerGate !== null ? rivetRmmInstallerNotice($installerGate, $installer) : '')
        . ($c['total'] === 0 && $f['filters'] === [] ? '<section class="card card-dark mb-3"><div class="card-body">' . rivetRmmUiEmpty('fas fa-satellite-dish', 'No agent is enrolled yet',
            $installer !== null ? 'Click Add device, download the installer and run it on a PC. It enrolls itself.' : ($f['perm']['admin'] ? 'Create an enrollment token under Administration, Endpoint agent, then install the agent on a device.' : 'An administrator creates an enrollment token and installs the agent.'),
            $installer !== null ? rivetRmmInstallerTrigger('button', 0, 'Add device') : '') . '</div></section>' : '')
        . '<div class="row mb-3">' . $kpi . '</div>'
        . '<div class="row"><div class="col-lg-5">' . $healthCard . '</div><div class="col-lg-7">' . $apCard . '</div></div>'
        . '<div class="row"><div class="col-lg-6 mb-3">' . $offCard . '</div><div class="col-lg-6 mb-3">' . $fCard . '</div></div>'
        . $versionsCard . $swCard . $capacity . $table
        . ($installer !== null ? rivetRmmInstallerModal($installer) : '')
        . rivetRmmMeshBulkDialog($f['mesh_bulk'] ?? null);
}

/**
 * The "Outdated software" card of the fleet page: devices running a version of a product older than the one asked for (RmmReadModel::outdatedSoftware()).
 * It asks nothing until a name and a version are given; the comparison is Core's forgiving version compare, not a package manager's.
 *
 * @param array<string,mixed> $o the view-model's outdated_sw
 * @param callable(int):string $cn client name by id
 * @param array<string,mixed> $f rivetRmmUiFleet()
 */
function rivetRmmUiOutdatedSoftware(array $o, callable $cn, array $f): string
{
    $form = '<form class="d-flex flex-wrap align-items-end gap-2" method="get" action="/agent/rmm_fleet.php#rmm-outdated-sw" aria-label="Find outdated software">';
    foreach ($f['filters'] as $k => $v) {
        $form .= '<input type="hidden" name="' . rmmH($k) . '" value="' . rmmH($v) . '">';
    }
    $form .= '<div><label class="form-label small mb-0" for="rmm-osw">Software name contains</label><input class="form-control form-control-sm" id="rmm-osw" name="osw" maxlength="100" required value="' . rmmH($o['name']) . '" placeholder="e.g. Chrome"></div>'
        . '<div><label class="form-label small mb-0" for="rmm-osv">Older than version</label><input class="form-control form-control-sm" id="rmm-osv" name="osv" maxlength="64" required value="' . rmmH($o['min_version']) . '" placeholder="e.g. 126.0"></div>'
        . '<button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-search me-1" aria-hidden="true"></i>Find devices</button>'
        . ($o['asked'] ? '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_fleet.php' . ($f['filters'] ? '?' . rmmH(http_build_query($f['filters'])) : '') . '">Clear</a>' : '') . '</form>';
    $rows = '';
    foreach ($o['items'] as $r) {
        $rows .= '<tr><td class="ps-3">' . rivetRmmUiDeviceLink($r + ['asset_name' => null]) . '<div class="text-muted small">' . rmmH($cn((int) $r['client_id'])) . '</div></td><td class="rmm-wrap">' . rmmH($r['name'])
            . '</td><td class="ifm-mono">' . rmmH($r['version'] === '' ? 'unknown' : $r['version']) . '</td><td>' . rmmH($r['source']) . '</td></tr>';
    }
    if (!$o['asked']) {
        $body = '<div class="p-3 text-muted small">Enter a product name and the oldest version you accept. The devices below it are listed here.</div>';
    } elseif ($rows === '') {
        $body = '<div class="p-3 text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No device runs a version of "' . rmmH($o['name']) . '" older than ' . rmmH($o['min_version']) . '.</div>';
    } else {
        $body = '<div class="table-responsive"><table class="table table-hover table-sm mb-0"><caption class="visually-hidden">Devices with outdated software</caption>' . rivetRmmUiThead(['Device', 'Software', 'Version', 'Source']) . '<tbody>' . $rows . '</tbody></table></div>'
            . (count($o['items']) >= $o['limit'] ? '<div class="px-3 py-2 border-top small text-muted">Showing the first ' . (int) $o['limit'] . ' matches.</div>' : '');
    }
    $head = $o['asked'] ? ($o['items'] === [] ? rivetRmmUiPill('ok', 'None outdated') : rivetRmmUiPill('warn', count($o['items']) . ($o['items'] && count($o['items']) >= $o['limit'] ? '+' : '') . ' outdated')) : '';

    return '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-orange)" id="rmm-outdated-sw"><div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px"><h6 class="mb-0 me-auto">'
        . '<i class="fas fa-cube me-2 text-warning" aria-hidden="true"></i>Outdated software</h6>' . $head . '</div><div class="card-body border-bottom py-2">' . $form . '</div>' . $body . '</section>';
}

/**
 * The "Performance and capacity" (scale and capacity) panel of the Agent fleet page (administrators): RivetCore's CapacityReport::build() in plain words. Rendering only.
 * RivetCore 1.0.0-rc.11 adds the autoscaling hint, the read cache, herd control (warm start, per-department token buckets), the storage tiers and the external
 * store buffer; each block is skipped when its key is missing so an older report still renders. `limits` and `cache_shared` are added by rivetRmmUiFleet().
 *
 * @param array<string,mixed> $cap
 */
function rivetRmmUiCapacity(array $cap): string
{
    $d = $cap['devices'];
    $set = $cap['settings'];
    $shed = (int) $cap['shed']['level'];
    $max = (int) $set['max_devices'];
    $pct = $max > 0 ? min(100, round($d['active'] / $max * 100, 1)) : null;
    $tile = static fn (string $icon, string $tint, string $value, string $label, string $meta, string $extra = ''): string => '<div class="col-md-6 col-xl-4 mb-2">' . rivetRmmUiStatCard($icon, $tint, $value, $label, $meta, $extra) . '</div>';
    $q = $cap['queue'];
    $limits = (array) ($cap['limits'] ?? []);
    $tiles = $tile('laptop', 'primary', rmmH($d['active']) . ($max > 0 ? ' <span class="text-muted fs-5">of ' . $max . '</span>' : ''), 'Enrolled devices', '<span>' . ($pct === null ? 'no device limit set' : $pct . '% of the limit') . '</span>',
            $pct === null ? '' : '<div class="px-3 pb-1">' . rivetRmmUiBar((float) $pct, 'info', 5, 'Share of the device limit') . '</div>')
        . $tile('exchange-alt', 'info', rmmH($cap['checkins']['last_minute']), 'Check-ins, last minute', '<span>' . (int) $cap['checkins']['last_hour'] . ' in the last hour</span>')
        . $tile('inbox', 'slate', rmmH(ucfirst((string) $cap['ingest_mode'])), 'Ingest mode', '<span>oldest queued ' . (int) ($q['oldest_pending_age_s'] ?? 0) . ' s &middot; ' . (int) ($q['dead_letter'] ?? 0) . ' dead-lettered</span>')
        . $tile('database', 'violet', rmmH($cap['projection']['rows_per_s']) . ' <span class="text-muted fs-6">rows/s</span>', 'Projected write rate', '<span>about ' . rmmH($cap['projection']['storage_steady_gb']) . ' GB of samples at steady state</span>')
        . $tile('compress-arrows-alt', $shed > 0 ? 'warning' : 'success', 'Level ' . $shed, 'Load shedding', '<span>' . ($shed > 0 ? 'devices are asked to report less often' : 'normal') . '</span>');
    $warn = '';
    foreach ($cap['warnings'] as $w) {
        $warn .= '<li class="list-group-item"><i class="fas fa-exclamation-triangle text-warning me-2" aria-hidden="true"></i>' . rmmH($w['message']) . '</li>';
    }
    $tables = '';
    foreach (array_slice($cap['tables'], 0, 5) as $t) {
        $tables .= '<tr><td class="ps-3 ifm-mono">' . rmmH($t['table']) . '</td><td class="text-end">' . rmmH(number_format((int) $t['rows'])) . '</td><td class="text-end pe-3">' . rmmH($t['total_mb']) . ' MB</td></tr>';
    }

    // ---- queue workers: the autoscaling hint in plain words
    $workers = '';
    $a = $cap['autoscale'] ?? null;
    if (is_array($a)) {
        $n = (int) $a['workers_desired'];
        $hint = (string) $a['hint'];
        $queued = (string) $cap['ingest_mode'] === 'queued';
        $headline = 'Run ' . $n . ' queue worker' . ($n === 1 ? '' : 's') . '.';
        $verdict = ['up' => 'Add workers now: ', 'down' => 'You could run fewer: ', 'hold' => 'No change needed: '][$hint] ?? '';
        $eta = $a['drain_eta_s'] === null ? ((int) $a['pending'] === 0 ? 'nothing waiting' : 'not shrinking') : '~' . (int) $a['drain_eta_s'] . ' s to empty';
        $workers = '<div class="rmm-scale-hint rmm-hint-' . rmmH($hint) . ' mb-3" id="rmm-autoscale"><p class="mb-1"><i class="fas fa-' . ($hint === 'up' ? 'arrow-up text-warning' : ($hint === 'down' ? 'arrow-down text-success' : 'equals text-info')) . ' me-2" aria-hidden="true"></i><strong>' . rmmH($headline) . '</strong> '
            . rivetRmmUiPill($hint === 'up' ? 'warn' : 'ok', $hint === 'up' ? 'Add workers' : ($hint === 'down' ? 'Could reduce' : 'Keeping up')) . '</p>'
            . '<p class="small mb-1">' . rmmH($verdict . $a['reason']) . ($a['workers_current'] !== null ? ' About ' . (int) $a['workers_current'] . ' worker' . ((int) $a['workers_current'] === 1 ? ' is' : 's are') . ' busy right now.' : '') . '</p>'
            . '<p class="small text-muted mb-1">' . (int) $a['pending'] . ' waiting &middot; ' . (int) $a['running'] . ' running &middot; ' . rmmH($a['arrival_per_s']) . ' arriving per second &middot; ' . rmmH($a['completion_per_s']) . ' finished per second &middot; ' . rmmH($eta)
            . '. Assumes one worker finishes about ' . rmmH(rtrim(rtrim(number_format((float) $a['per_worker_jobs_per_s'], 1, '.', ''), '0'), '.')) . ' jobs a second.</p>'
            . (!$queued ? '<p class="small mb-1"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>Ingest mode is <strong>' . rmmH($cap['ingest_mode']) . '</strong>: check-ins are processed as they arrive, so no queue workers are needed. The number above only applies once ingest mode is "queued".</p>' : '')
            . '</div>';
    }

    // ---- read cache, herd control, storage, external store
    $cacheRows = [];
    if ($limits !== []) {
        $fresh = (int) ($limits['cache_fresh_s'] ?? 0);
        $cacheRows[] = ['Read cache', $fresh <= 0 ? rivetRmmUiPill('off', 'Off') . ' every page view recounts' : rivetRmmUiPill('ok', 'On') . ' fresh for ' . $fresh . ' s, then up to ' . (int) ($limits['cache_stale_s'] ?? 0) . ' s stale &middot; kept in ' . (!empty($cap['cache_shared']) ? 'Redis' : 'the database')];
    }
    $herd = $cap['herd'] ?? null;
    if (is_array($herd)) {
        $ws = $herd['warm_start'] ?? null;
        $warmLen = (int) ($limits['warm_start_s'] ?? 0);
        if (is_array($ws) && !empty($ws['active'])) {
            $cacheRows[] = ['Warm start', rivetRmmUiPill('warn', 'In progress') . ' admitting about ' . rmmH($ws['admitted_percent']) . ' % of check-ins until ' . rmmH(rivetFormatTime((int) $ws['until'], 'epoch', true, 'H:i:s'))];
        } else {
            $cacheRows[] = ['Warm start', $warmLen > 0 ? rivetRmmUiPill('ok', 'Armed') . ' not active now; starts after the module is switched back on or after a ' . (int) ($limits['warm_quiet_s'] ?? 120) . ' s quiet gap' : rivetRmmUiPill('off', 'Off')];
        }
        $b = (int) $herd['client_bucket_burst'];
        $r = (int) $herd['client_bucket_per_min'];
        $cacheRows[] = ['Check-ins per department', $b > 0 && $r > 0 ? rivetRmmUiPill('ok', 'Limited') . ' ' . $r . ' per minute, burst of ' . $b : rivetRmmUiPill('off', 'Off') . ' no per-department limit'];
    }
    $cacheRows[] = ['Load shedding', $shed > 0 ? rivetRmmUiPill('warn', 'Level ' . $shed) . ' devices are asked to report less often' : rivetRmmUiPill('ok', 'Level 0') . ' normal'];
    $cacheRows[] = ['Ingest mode', rmmH(ucfirst((string) $cap['ingest_mode'])) . ((string) $cap['ingest_mode'] === 'queued' ? ' &middot; check-ins are queued and processed by workers' : ' &middot; check-ins are processed as they arrive')];
    $st = $cap['storage'] ?? null;
    $storRows = [];
    if (is_array($st)) {
        $hist = $st['tables']['endpoint_agent_check_history'] ?? null;
        if (is_array($hist)) {
            $storRows[] = ['Check history', rmmH($hist['retention_days']) . ' days kept &middot; ' . (!empty($hist['partitioned']) ? rivetRmmUiPill('ok', 'Partitioned') . ' ' . (int) $hist['partitions'] . ' partitions' : 'plain table, expired rows deleted in batches')];
        }
        $storRows[] = ['Partitioning setting', !empty($st['partitioning_enabled']) ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off')];
        $storRows[] = ['RivetCore daily rollup', ('<span class="text-muted">not used: ' . htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' keeps its own hourly metric rollups</span>')];
    }
    $ext = $cap['external_store'] ?? null;
    if (is_array($ext)) {
        $storRows[] = ['External metric store', rivetRmmUiPill($ext['pending_batches'] > 0 ? 'warn' : 'ok', 'On') . ' ' . (int) $ext['pending_batches'] . ' batch' . ((int) $ext['pending_batches'] === 1 ? '' : 'es') . ' waiting &middot; ' . (int) $ext['dropped_points'] . ' points dropped (this request)'];
    }

    // RivetCore 1.0.0-rc.41 (issue #89 phase 4): where the 12 HOT RMM tables live (store_sizes, the active store) and the "consider PostgreSQL"
    // advice (data_store_recommendation), additive keys of CapacityReport::build(). Never acted on automatically; see Administration > Endpoint
    // agent > RMM data store to switch.
    $dsRows = [];
    $sizes = $cap['store_sizes'] ?? null;
    if (is_array($sizes)) {
        $dsRows[] = ['Active store', rivetRmmUiPill($sizes['kind'] === 'postgres' ? 'ok' : 'off', ucfirst((string) $sizes['kind']))];
        $dsRows[] = ['Hot table rows/size', $sizes['available'] ? rmmH(number_format((int) $sizes['total_rows'])) . ' rows &middot; ' . rmmH(round($sizes['total_bytes'] / 1048576, 1)) . ' MB' : '<span class="text-muted">' . rmmH($sizes['error'] ?? 'not available') . '</span>'];
    }
    $rec = $cap['data_store_recommendation'] ?? null;
    if (is_array($rec) && $rec['message'] !== null) {
        $dsRows[] = ['Recommendation', rivetRmmUiPill('warn', 'Consider PostgreSQL') . ' ' . rmmH($rec['message']) . ($rec['growth_mb_per_day'] !== null ? ' About ' . rmmH($rec['growth_mb_per_day']) . ' MB/day growth.' : '')];
    }
    $detail = '<div class="row"><div class="col-lg-4 mb-2"><h6 class="small text-uppercase text-muted">Load and cache</h6><div class="rmm-scale-dl">' . rivetRmmUiDl($cacheRows) . '</div></div>'
        . ($storRows !== [] ? '<div class="col-lg-4 mb-2"><h6 class="small text-uppercase text-muted">Storage</h6><div class="rmm-scale-dl">' . rivetRmmUiDl($storRows) . '</div></div>' : '')
        . ($dsRows !== [] ? '<div class="col-lg-4 mb-2"><h6 class="small text-uppercase text-muted">Data store</h6><div class="rmm-scale-dl">' . rivetRmmUiDl($dsRows) . '</div></div>' : '') . '</div>';

    $guidance = '<p class="small text-muted mb-2" id="rmm-workers-help"><i class="fas fa-info-circle me-1" aria-hidden="true"></i><strong>Running several queue workers.</strong> Several workers are safe: on MariaDB 10.6 or later and MySQL 8.0.1 or later each job is claimed by exactly one worker (SKIP LOCKED), and on older servers a lease does the same, '
        . 'so you can raise or lower the number at any time. To run N workers, start N copies of the queue worker (for example N systemd service instances or N cron lines for <code>cron/integration_worker.php</code>); the hint above says what N should be. '
        . 'The limits that shape them are under Administration &rarr; Endpoint agent &rarr; Scale settings; see <code>docs/ENDPOINT_AGENT.md</code> for the commands.</p>';

    return '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-info)" id="rmm-capacity"><div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px"><h6 class="mb-0 me-auto">'
        . '<i class="fas fa-server me-2 text-info" aria-hidden="true"></i>Performance and capacity <span class="small text-muted fw-normal ms-2">scale, queue workers and storage &middot; Administrators only</span></h6>'
        . ($shed > 0 ? rivetRmmUiPill('warn', 'Shed level ' . $shed) : rivetRmmUiPill('ok', 'Shed level 0: normal')) . '</div><div class="card-body pb-1">' . $workers . '<div class="row">' . $tiles . '</div>'
        . ($warn !== '' ? '<ul class="list-group list-group-flush mb-2">' . $warn . '</ul>' : '<p class="small text-muted"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No capacity warnings.</p>')
        . $detail
        . ($tables !== '' ? '<div class="table-responsive"><table class="table table-sm mb-2"><caption class="visually-hidden">Largest endpoint agent tables</caption>' . rivetRmmUiThead(['Table', 'Rows (estimate)', 'Size']) . '<tbody>' . $tables . '</tbody></table></div>' : '')
        . $guidance
        . '<p class="small mb-2"><a href="/admin/settings_endpoint_agent.php#scale"><i class="fas fa-cog me-1" aria-hidden="true"></i>Scale settings</a> &middot; <a href="/admin/settings_endpoint_agent.php#datastore"><i class="fas fa-database me-1" aria-hidden="true"></i>RMM data store</a></p>'
        . '</div></section>';
}

// The shared layer of the Phase 2 and 3 pages (required last: it requires this file first, and everything above is defined by now).
require_once __DIR__ . '/rmm_automation.php';
