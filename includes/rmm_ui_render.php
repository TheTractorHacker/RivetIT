<?php

/*
 * RMM user interface: HTML renderers for the view-models of includes/rmm_ui.php. Every function returns a string (so the tests can inspect it and
 * the pages can echo it); nothing here queries the database. Device-supplied text is escaped at the point it is printed.
 *
 * Markup follows docs/rmm/mockups/asset-page-app-style.html in rivet-core: the app's own cards, .ifm-* summary tiles and chart chrome from
 * css/itflow_metrics.css, .it-empty-state, Tabler badges and progress bars; the few additions live in css/itflow_rmm.css under .rmm-*.
 */

require_once __DIR__ . '/rmm_ui.php';
require_once __DIR__ . '/rmm_installer.php';

function rmmH($v): string
{
    return nullable_htmlentities((string) $v);
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
    return '<section class="card card-dark mb-3"' . ($edge !== '' ? ' style="border-top:3px solid var(--tblr-' . rmmH($edge) . ')"' : '') . ($id !== '' ? ' id="' . rmmH($id) . '"' : '') . '>'
        . '<div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px"><h6 class="mb-0 me-auto"><i class="fas fa-' . rmmH($icon) . ' me-2" aria-hidden="true"></i>' . $title . '</h6>'
        . $headerExtra . '</div><div class="card-body' . ($flush ? ' p-0' : '') . '">' . $body . '</div></section>';
}

function rivetRmmUiThead(array $cols): string
{
    $o = '<thead class="rmm-thead"><tr>';
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
    if ($never) {
        $facts = '<span class="text-muted">OS and agent not reported yet</span>';
    } else {
        $facts = '<i class="' . $osIcon . ' me-1" aria-hidden="true"></i>' . rmmH($vm['os_label']) . ' <span class="text-muted">' . rmmH($vm['arch']) . '</span>'
            . ' &bull; <i class="fas fa-clock mx-1" aria-hidden="true"></i>Last seen ' . rivetRmmUiTime($vm['last_checkin_at'])
            . ' &bull; Agent ' . rmmH($vm['agent_version']) . ' <span class="text-muted">' . rmmH($vm['ring']) . ' ring</span>';
    }

    // Live health badges (dimmed while offline)
    $live = '';
    if (!$never) {
        $live = '<div class="mt-2 small d-flex flex-wrap align-items-center rmm-live' . ($vm['offline'] ? ' rmm-dim' : '') . '">';
        foreach ($vm['gauges'] as $g) {
            if ($g['id'] === 'cpu' || $g['id'] === 'mem' || str_starts_with($g['id'], 'disk:') || $g['id'] === 'battery') {
                $bk = rivetRmmUiKind($g['band'])[0];
                $short = $g['id'] === 'cpu' ? 'CPU' : ($g['id'] === 'mem' ? 'RAM' : $g['label']);
                $live .= '<span class="me-3 text-nowrap"><span class="text-muted">' . rmmH($short) . '</span> <span class="badge text-bg-' . $bk . '">'
                    . ($g['value'] === null ? 'no data' : (int) round($g['value']) . '%') . '</span></span>';
            }
        }
        $live .= '<span class="text-muted me-3 text-nowrap"><i class="fas fa-power-off me-1" aria-hidden="true"></i>'
            . ($vm['offline'] || $vm['uptime_s'] === null ? 'uptime unknown' : 'Up ' . rmmH(rivetRmmUiDuration((int) $vm['uptime_s']))) . '</span>';
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
    $canScript = ($perm['run_saved'] && $vm['saved_scripts'] !== []) || $perm['run_script'];
    $scriptReason = !$dev ? 'The device is not approved or no longer active.' : ($vm['platform'] !== 'windows' ? 'Script jobs run on Windows devices in this release. Linux devices can collect inventory and reboot.'
        : (!($perm['run_saved'] || $perm['run_script']) ? 'Your role cannot run jobs.' : ($never ? 'Available after the first check-in.' : 'No saved script is available and your role cannot run free-form scripts.')));
    $actions = '';
    $actions .= $btn('rmm-act-script', 'btn-primary', 'fas fa-play', 'Run script', $dev && !$never && $vm['platform'] === 'windows' && $canScript, $scriptReason, ' data-bs-toggle="modal" data-bs-target="#rmmRunModal"');
    $actions .= $btn('rmm-act-reboot', 'btn-outline-warning', 'fas fa-power-off', 'Reboot', $dev && !$never && $perm['reboot'], !$dev ? 'The device is not approved or no longer active.'
        : ($never ? 'Available after the first check-in.' : 'Your role cannot reboot devices.'), ' data-bs-toggle="modal" data-bs-target="#rmmRebootModal"');
    if ($vm['mesh']['configured']) {
        $remoteReason = !$perm['remote'] ? 'Your role cannot open remote sessions.' : (!$vm['mesh']['mapped'] ? 'An administrator maps this device to a MeshCentral node first.'
            : (!$dev ? 'The device is not approved or no longer active.' : 'Available after the first check-in.'));
        $actions .= $btn('rmm-act-remote', 'btn-success', 'fas fa-desktop', 'Remote access', $perm['remote'] && $vm['mesh']['mapped'] && $dev && !$never, $remoteReason);
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
        . '<div class="text-muted small mt-1">' . $facts . '</div>' . $live . $tagsRow . '</div>'
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
        $at = is_array($side) && !empty($side['peak_at']) ? ' at ' . gmdate('H:i', strtotime((string) $side['peak_at']) ?: 0) . ' UTC' : '';

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
        $boot = '<span>last boot <b title="' . rmmH(gmdate('Y-m-d H:i', $bootTs)) . ' UTC">' . rmmH(rivetRmmUiAgo(max(0, time() - $bootTs))) . '</b></span>';
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
        . '<a class="small ms-2" href="/agent/rmm_alerts.php?status=all&amp;asset_id=' . (int) $vm['asset_id'] . '">All alerts for this device</a>';

    return '<div class="col-lg-6">' . '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-' . ($open !== [] ? 'danger' : 'success') . ')" id="rmm-alerts"><div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px">'
        . '<h6 class="mb-0 me-auto"><i class="fas fa-bell me-2 text-warning" aria-hidden="true"></i>Alerts</h6>' . $head . '</div>' . $o . '</section></div>';
}

function rivetRmmUiRemoteCard(array $vm): string
{
    $perm = $vm['perm'];
    $m = $vm['mesh'];
    if (!$m['configured'] && !$perm['admin']) {
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
    if ($perm['admin']) {
        $body .= '<div class="card-body border-top py-2"><form id="rmm-mesh-form" class="row g-2 align-items-end"><div class="col-12"><label class="form-label small mb-1" for="rmm-mesh-node">MeshCentral node id (administrators)</label>'
            . '<input class="form-control form-control-sm font-monospace" id="rmm-mesh-node" name="mesh_node_id" maxlength="200" value="' . rmmH($m['node_id']) . '" placeholder="node//AbCd...">'
            . '</div><div class="col-12"><button class="btn btn-sm btn-outline-primary" type="submit">Save mapping</button> <span class="small text-muted">Leave empty to clear.</span></div></form></div>';
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
            . '<td>' . ($ch['alert_id'] ? '<a class="badge text-bg-light border" href="/agent/rmm_alerts.php?status=all&amp;asset_id=' . (int) $vm['asset_id'] . '">alert #' . (int) $ch['alert_id'] . '</a>' : '<span class="text-muted">none</span>') . '</td></tr>';
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
    $o .= '<div class="row mt-2">' . rivetRmmUiAlertsCard($vm) . rivetRmmUiRemoteCard($vm) . '</div>' . rivetRmmUiChecksCard($vm);

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
    return ['powershell' => 'PowerShell script', 'reboot' => 'Reboot', 'collect' => 'Collect inventory'][$type] ?? $type;
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
        if ($perm['run_saved'] && $done) {
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
        if ($perm['run_saved'] && $done) {
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

function rivetRmmUiDialogs(array $vm): string
{
    $perm = $vm['perm'];
    $o = '';
    // Reboot
    $o .= '<div class="modal fade" id="rmmRebootModal" tabindex="-1" aria-labelledby="rmmRebootTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmRebootTitle"><i class="fas fa-power-off me-2" aria-hidden="true"></i>Reboot ' . rmmH($vm['hostname']) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><p>The device restarts the next time it picks the job up' . ($vm['offline'] ? ' (it is offline now, so this waits until it returns, until the job expires)' : '') . '. Anyone signed in loses unsaved work. The job is sent at most once.</p>'
        . '<div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-reboot-confirm"><label class="form-check-label" for="rmm-reboot-confirm">I confirm this device may restart.</label></div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-warning" id="rmm-reboot-go" disabled>Reboot device</button></div></div></div></div>';
    // Run script
    if ($vm['platform'] === 'windows' && ($perm['run_saved'] || $perm['run_script'])) {
        $opts = '';
        if ($perm['run_saved'] && $vm['saved_scripts'] !== []) {
            $opts .= '<option value="saved">A saved script</option>';
        }
        if ($perm['run_script']) {
            $opts .= '<option value="powershell">PowerShell text</option>';
        }
        $saved = '';
        foreach ($vm['saved_scripts'] as $s) {
            $saved .= '<option value="' . (int) $s['id'] . '">' . rmmH($s['name']) . '</option>';
        }
        $o .= '<div class="modal fade" id="rmmRunModal" tabindex="-1" aria-labelledby="rmmRunTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><form id="rmm-run-form">'
            . '<div class="modal-header"><h5 class="modal-title" id="rmmRunTitle"><i class="fas fa-play me-2" aria-hidden="true"></i>Run a script on ' . rmmH($vm['hostname']) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            . '<div class="modal-body">' . ($opts === '' ? '<p class="text-muted mb-0">No saved script is available and your role cannot run free-form text.</p>' : '<div class="row g-2">'
            . '<div class="col-md-6"><label class="form-label" for="rmm-run-type">What to run</label><select class="form-select" id="rmm-run-type" name="type">' . $opts . '</select></div>'
            . '<div class="col-md-6"><label class="form-label" for="rmm-run-timeout">Timeout, seconds</label><input class="form-control" type="number" min="5" max="3600" value="300" id="rmm-run-timeout" name="timeout_s"></div>'
            . '<div class="col-12" id="rmm-run-saved-wrap"><label class="form-label" for="rmm-run-saved">Saved script</label><select class="form-select" id="rmm-run-saved" name="script_id">' . $saved . '</select></div>'
            . '<div class="col-12 d-none" id="rmm-run-text-wrap"><label class="form-label" for="rmm-run-text">PowerShell</label><textarea class="form-control font-monospace" rows="6" id="rmm-run-text" name="script" maxlength="100000"></textarea></div>'
            . '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-run-destructive"><label class="form-check-label" for="rmm-run-destructive">This script changes or removes things (destructive)</label></div></div>'
            . '<div class="col-12 d-none" id="rmm-run-confirm-wrap"><div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-run-confirm"><label class="form-check-label" for="rmm-run-confirm">I confirm it may run now as SYSTEM.</label></div></div></div>')
            . '</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>' . ($opts === '' ? '' : '<button type="submit" class="btn btn-primary" id="rmm-run-go">Queue job</button>')
            . '</div></form></div></div></div>';
    }

    return $o;
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

    return '<div class="card card-dark mb-3" id="rmm-panel" data-device-id="' . (int) $vm['device_id'] . '" data-csrf="' . rmmH($csrf) . '" data-post-url="/agent/post/rmm_agent.php" data-output-url="/agent/rmm_job_output.php" '
        . 'data-platform="' . rmmH($vm['platform']) . '" data-offline="' . ($vm['offline'] ? '1' : '0') . '">'
        . '<div class="card-header p-0 border-bottom-0"><ul class="nav nav-tabs px-3 pt-2 rmm-tabbar" role="tablist" aria-label="RMM device sections">'
        . $tab('overview', 'tachometer-alt', 'Overview', true) . $tab('inventory', 'microchip', 'Inventory', false) . ($vm['software'] !== null ? $tab('software', 'cube', 'Software', false) : '') . $tab('jobs', 'tasks', 'Jobs', false) . '</ul></div>'
        . '<div class="px-3 pt-3"><div id="rmm-msg" class="alert d-none mb-0" role="status" aria-live="polite"></div></div>'
        . '<div class="tab-content"><div class="tab-pane active p-3" id="rmm-pane-overview" role="tabpanel" aria-labelledby="rmm-tab-overview" tabindex="0">' . rivetRmmUiOverview($vm, $performanceHtml, $performanceNote) . '</div>'
        . '<div class="tab-pane p-3" id="rmm-pane-inventory" role="tabpanel" aria-labelledby="rmm-tab-inventory" tabindex="0">' . rivetRmmUiInventory($vm) . '</div>'
        . ($vm['software'] !== null ? '<div class="tab-pane p-3" id="rmm-pane-software" role="tabpanel" aria-labelledby="rmm-tab-software" tabindex="0">' . rivetRmmUiSoftware($vm) . '</div>' : '')
        . '<div class="tab-pane p-3" id="rmm-pane-jobs" role="tabpanel" aria-labelledby="rmm-tab-jobs" tabindex="0">' . rivetRmmUiJobs($vm, $userNames) . '</div></div></div>'
        . rivetRmmUiDialogs($vm);
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

    return $aid > 0 ? '<a class="fw-bold" href="/agent/asset_details.php?asset_id=' . $aid . '">' . $label . '</a>' : '<span class="fw-bold">' . $label . '</span>';
}

/**
 * @param array<string,mixed>|null $installer rivetRmmInstallerContext(): when set the page gets the "Add device" button, the empty-state button and the dialog
 */
function rivetRmmUiFleetPage(array $f, string $csrf, ?array $installer = null): string
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
        . rivetRmmUiInfoBox($f['open_alerts'] > 0 ? 'danger' : 'secondary', 'bell', 'Open alerts', $f['open_alerts'], 'agent alerts', '/agent/rmm_alerts.php?status=new');

    $legend = static fn (string $col, string $l, int $n): string => '<li class="d-flex align-items-center gap-2"><span class="ifm-legend-swatch" style="background:' . $col . '"></span>' . rmmH($l) . ' <b class="ms-auto">' . $n . '</b></li>';
    $donut = rivetRmmUiDonut([['n' => $c['online'], 'l' => 'online', 'col' => 'var(--tblr-success)'], ['n' => $c['offline'], 'l' => 'offline', 'col' => 'var(--tblr-danger)'],
        ['n' => $c['stale'], 'l' => 'stale', 'col' => 'var(--if-muted)'], ['n' => $c['never'], 'l' => 'never seen', 'col' => 'var(--tblr-blue)']]);
    $health = '<div class="card-body d-flex align-items-center flex-wrap gap-4 justify-content-center">' . $donut . '<ul class="list-unstyled mb-0 rmm-legend">'
        . $legend('var(--tblr-success)', 'Online', $c['online']) . $legend('var(--tblr-danger)', 'Offline', $c['offline']) . $legend('var(--if-muted)', 'Stale (quiet 7+ days)', $c['stale'])
        . $legend('var(--tblr-blue)', 'Never checked in', $c['never']) . '</ul></div>';
    $healthCard = '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-orange)"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-heartbeat me-2 text-warning" aria-hidden="true"></i>Fleet health</h6></div>' . $health . '</section>';

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
    $versionsBody = '<div class="card-body pb-2"><div class="row"><div class="col-6"><div class="ifm-toolbar-label mb-1">Rings</div><ul class="list-unstyled small mb-0">' . ($ringRows ?: '<li class="text-muted">none</li>') . '</ul></div>'
        . '<div class="col-6"><div class="ifm-toolbar-label mb-1">Versions</div><ul class="list-unstyled small mb-0">' . ($verRows ?: '<li class="text-muted">none</li>') . '</ul></div></div>'
        . '<p class="small text-muted mt-2 mb-0">' . ($cur ? 'Current hosted version: ' . rmmH(implode(' / ', $cur)) . '.' : 'No agent binary is hosted yet, so nothing counts as outdated.')
        . ($f['scan_partial'] ? ' Counts cover the first ' . (int) $f['scanned'] . ' devices.' : '') . '</p></div>'
        . '<ul class="list-group list-group-flush">' . ($outRows ?: '<li class="list-group-item text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No outdated agents.</li>') . '</ul>';
    $versionsCard = '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-azure)"><div class="card-header py-2 d-flex align-items-center" style="gap:4px"><h6 class="mb-0 me-auto"><i class="fas fa-code-branch me-2 text-info" aria-hidden="true"></i>Agent versions and rings</h6>'
        . ($f['outdated_total'] > 0 ? rivetRmmUiPill('warn', $f['outdated_total'] . ' outdated') : rivetRmmUiPill('ok', 'Up to date')) . '</div>' . $versionsBody . '</section>';

    // Offline and stale
    $li = static function (array $d, string $right) use ($cn): string {
        return '<li class="list-group-item d-flex align-items-center gap-2"><div class="me-auto min-w-0">' . rivetRmmUiDeviceLink($d) . '<div class="text-muted small">' . rmmH($cn((int) $d['client_id'])) . '</div></div>' . $right . '</li>';
    };
    $offRows = '';
    foreach (array_merge($f['offline'], $f['stale']) as $d) {
        $offRows .= $li($d, rivetRmmUiStatusPill($d['status']) . '<span class="text-muted small text-nowrap">' . rivetRmmUiTime($d['last_checkin_at'], $now) . '</span>');
    }
    $offCard = '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-danger)"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-times-circle me-2 text-danger" aria-hidden="true"></i>Offline and stale</h6></div><ul class="list-group list-group-flush">'
        . ($offRows ?: '<li class="list-group-item text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>Every managed device has checked in recently.</li>') . '</ul></section>';

    // Needs approval
    $apRows = '';
    foreach ($f['approvals'] as $a) {
        $apRows .= '<li class="list-group-item"><div class="d-flex align-items-center gap-2"><div class="me-auto min-w-0"><span class="fw-bold">' . rmmH($a['hostname']) . '</span><div class="text-muted small">' . rmmH($cn((int) $a['client_id']))
            . ' &middot; first seen ' . rivetRmmUiTime($a['first_seen_at'], $now) . '</div></div>' . rivetRmmUiPill('warn', 'Waiting') . '</div><div class="small mt-1">' . rmmH($a['match_reason_text']) . '</div></li>';
    }
    $apHead = $f['perm']['manage'] ? '<a class="small" href="/admin/settings_endpoint_agent.php#devices">Review in administration</a>' : '<span class="small text-muted">An administrator approves devices.</span>';
    $apCard = '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-warning)" id="rmm-approvals"><div class="card-header py-2 d-flex align-items-center" style="gap:4px"><h6 class="mb-0 me-auto"><i class="fas fa-user-clock me-2 text-warning" aria-hidden="true"></i>Devices needing approval</h6>'
        . $apHead . '</div><ul class="list-group list-group-flush">' . ($apRows ?: '<li class="list-group-item text-muted small"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No device is waiting for approval.</li>') . '</ul></section>';

    // Recent job failures
    $fRows = '';
    foreach ($f['failures'] as $j) {
        $fRows .= '<li class="list-group-item d-flex align-items-center gap-2"><div class="me-auto min-w-0">'
            . ($j['asset_id'] ? '<a class="fw-bold" href="/agent/asset_details.php?asset_id=' . (int) $j['asset_id'] . '#rmm-jobs">' . rmmH($j['hostname']) . '</a>' : '<span class="fw-bold">' . rmmH($j['hostname']) . '</span>')
            . '<div class="text-muted small">' . rmmH(rivetRmmUiJobLabel($j['type'])) . ($j['exit_code'] !== null ? ' &middot; exit ' . (int) $j['exit_code'] : '') . ($j['reason'] ? ' &middot; ' . rmmH($j['reason']) : '') . '</div></div>'
            . rivetRmmUiPill($j['state'] === 'timed_out' ? 'warn' : 'crit', $j['state'] === 'timed_out' ? 'Timed out' : 'Failed') . '<span class="text-muted small text-nowrap">' . rivetRmmUiTime($j['at'], $now) . '</span></li>';
    }
    $fCard = '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-red)"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-tasks me-2 text-danger" aria-hidden="true"></i>Recent job failures</h6></div><ul class="list-group list-group-flush">'
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
    $table = '<section class="card card-dark mb-3" id="rmm-devices"><div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-desktop me-2" aria-hidden="true"></i>Devices</h6></div>' . $filter
        . '<div class="table-responsive"><table class="table table-hover table-sm mb-0"><caption class="visually-hidden">Agent devices</caption>' . rivetRmmUiThead(['Status', 'Device', 'Agent', 'Last check-in']) . '<tbody>' . $rows . '</tbody></table></div>' . $pager . '</section>';

    $capacity = $f['capacity'] !== null ? rivetRmmUiCapacity($f['capacity']) : '';
    $swCard = $f['outdated_sw'] !== null ? rivetRmmUiOutdatedSoftware($f['outdated_sw'], $cn, $f) : '';

    return '<div class="d-flex align-items-center flex-wrap mb-3" style="gap:6px"><h4 class="mb-0 me-auto"><i class="fas fa-satellite me-2" aria-hidden="true"></i>Agent fleet</h4>'
        . ($installer !== null ? rivetRmmInstallerTrigger('button', 0, 'Add device') : '')
        . '<a href="/agent/rmm_assets.php" class="btn btn-info btn-sm"><i class="fas fa-desktop me-1" aria-hidden="true"></i>All RMM assets</a>'
        . '<a href="/agent/rmm_alerts.php" class="btn btn-warning btn-sm"><i class="fas fa-bell me-1" aria-hidden="true"></i>Alerts</a>'
        . ($f['perm']['admin'] ? '<a href="/admin/settings_endpoint_agent.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-cog me-1" aria-hidden="true"></i>Administration</a>' : '') . '</div>'
        . ($c['total'] === 0 && $f['filters'] === [] ? '<section class="card card-dark mb-3"><div class="card-body">' . rivetRmmUiEmpty('fas fa-satellite-dish', 'No agent is enrolled yet',
            $installer !== null ? 'Click Add device, download the installer and run it on a PC. It enrolls itself.' : ($f['perm']['admin'] ? 'Create an enrollment token under Administration, Endpoint agent, then install the agent on a device.' : 'An administrator creates an enrollment token and installs the agent.'),
            $installer !== null ? rivetRmmInstallerTrigger('button', 0, 'Add device') : '') . '</div></section>' : '')
        . '<div class="row mb-3">' . $kpi . '</div>'
        . '<div class="row"><div class="col-lg-7">' . $healthCard . '</div><div class="col-lg-5">' . $apCard . '</div></div>'
        . '<div class="row"><div class="col-lg-6">' . $offCard . '</div><div class="col-lg-6">' . $fCard . '</div></div>'
        . $versionsCard . $swCard . $capacity . $table
        . ($installer !== null ? rivetRmmInstallerModal($installer) : '');
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

function rivetRmmUiCapacity(array $cap): string
{
    $d = $cap['devices'];
    $set = $cap['settings'];
    $shed = (int) $cap['shed']['level'];
    $max = (int) $set['max_devices'];
    $pct = $max > 0 ? min(100, round($d['active'] / $max * 100, 1)) : null;
    $tile = static fn (string $icon, string $tint, string $value, string $label, string $meta, string $extra = ''): string => '<div class="col-md-6 col-xl-4 mb-2">' . rivetRmmUiStatCard($icon, $tint, $value, $label, $meta, $extra) . '</div>';
    $q = $cap['queue'];
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

    return '<section class="card card-dark mb-3" style="border-top:3px solid var(--tblr-info)" id="rmm-capacity"><div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px"><h6 class="mb-0 me-auto">'
        . '<i class="fas fa-server me-2 text-info" aria-hidden="true"></i>Performance and capacity <span class="small text-muted fw-normal ms-2">Administrators only</span></h6>'
        . ($shed > 0 ? rivetRmmUiPill('warn', 'Shed level ' . $shed) : rivetRmmUiPill('ok', 'Shed level 0: normal')) . '</div><div class="card-body pb-1"><div class="row">' . $tiles . '</div>'
        . ($warn !== '' ? '<ul class="list-group list-group-flush mb-2">' . $warn . '</ul>' : '<p class="small text-muted"><i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>No capacity warnings.</p>')
        . ($tables !== '' ? '<div class="table-responsive"><table class="table table-sm mb-2">' . rivetRmmUiThead(['Table', 'Rows (estimate)', 'Size']) . '<tbody>' . $tables . '</tbody></table></div>' : '')
        . '</div></section>';
}
