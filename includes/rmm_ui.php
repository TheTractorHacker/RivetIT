<?php

/*
 * RMM user interface: the view-models and small renderers behind the asset page's RMM panel (agent/includes/asset/rmm_panel.php) and the
 * agent fleet page (agent/rmm_fleet.php).
 *
 * Everything here only READS. Actions go through agent/post/rmm_agent.php -> RivetCore\Rmm\Technician\TechnicianActions, so the page and the REST
 * API share one authorization path; hiding a button is cosmetic. The data comes from RivetCore\Rmm\Read\RmmReadModel (deviceView, listDevices,
 * fleetCounts, pendingApprovals, currentBinaries) and Capacity\CapacityReport; the two things Core does not offer are read here and are listed as
 * follow-ups: the fleet-wide list of failed jobs (metadata only, never output or script text) and the current job's output (RmmReadModel::jobs).
 *
 * THE MODULE SWITCH. The first thing every entry point does is rivetRmmEnabled() (the module's state file: no query). Off means the builders
 * return null having asked the database NOTHING, and the callers render nothing.
 *
 * MISSING IS NOT ZERO. A reading the agent could not take is null here and "no data" on the page, never 0.
 *
 * Escaping is the renderer's job: every device-supplied string (hostname, check detail, inventory, job text) is passed through
 * nullable_htmlentities() at the point it is printed; JavaScript consumers use textContent.
 */

require_once __DIR__ . '/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmAbility;

// ------------------------------------------------------------------ constants and tiny formatters

/**
 * Display bands, warn/crit percent. One array so the gauges, the fleet pills and the device table agree (design 5.2); the disk pair matches
 * the shipped disk check. Phase 3 replaces them with the device's resolved thresholds.
 *
 * @return array<string,array{0:int,1:int}>
 */
function rivetRmmUiBands(): array
{
    return ['cpu' => [80, 95], 'mem' => [80, 95], 'disk' => [80, 90], 'battery' => [20, 10]];
}

/** ok | warn | crit | off for a percent reading and a [warn, crit] pair. Battery is inverted by the caller (low is bad). */
function rivetRmmUiBand(?float $v, array $b): string
{
    if ($v === null) {
        return 'off';
    }

    return $v >= $b[1] ? 'crit' : ($v >= $b[0] ? 'warn' : 'ok');
}

/** @return array{0:string,1:string} Bootstrap colour name and Font Awesome icon for a state kind. */
function rivetRmmUiKind(string $kind): array
{
    return ['ok' => ['success', 'fa-check-circle'], 'warn' => ['warning', 'fa-exclamation-triangle'], 'crit' => ['danger', 'fa-times-circle'],
        'off' => ['secondary', 'fa-minus-circle'], 'info' => ['info', 'fa-info-circle']][$kind] ?? ['secondary', 'fa-minus-circle'];
}

/** A status pill: always an icon AND a word, never colour alone. */
function rivetRmmUiPill(string $kind, string $text, string $extraClass = ''): string
{
    [$col, $icon] = rivetRmmUiKind($kind);

    return '<span class="badge text-bg-' . $col . ($extraClass !== '' ? ' ' . nullable_htmlentities($extraClass) : '') . '"><i class="fas ' . $icon . ' me-1" aria-hidden="true"></i>'
        . nullable_htmlentities($text) . '</span>';
}

/** "42 s", "5 min", "3 h 10 m", "2 d 4 h". */
function rivetRmmUiDuration(?int $s): string
{
    if ($s === null) {
        return 'unknown';
    }
    $s = max(0, $s);
    $d = intdiv($s, 86400);
    $h = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);

    return $d > 0 ? "$d d $h h" : ($h > 0 ? "$h h $m m" : ($m > 0 ? "$m min" : "$s s"));
}

function rivetRmmUiAgo(?int $s): string
{
    return $s === null ? 'never' : ($s < 90 ? max(0, $s) . ' s ago' : rivetRmmUiDuration($s) . ' ago');
}

/** Seconds between an ISO or SQL UTC timestamp (or, with $local, an application-local one) and now, or null. */
function rivetRmmUiAgeOf(?string $utc, ?int $now = null, bool $local = false): ?int
{
    if ($utc === null || $utc === '') {
        return null;
    }
    // $local: the edition's own tables (rmm_alerts, rmm_remote_sessions) store the application's local time; the endpoint_agent_* tables store UTC.
    $t = strtotime($local || str_contains($utc, 'T') || str_ends_with($utc, 'Z') ? $utc : $utc . ' UTC');

    return $t === false ? null : max(0, ($now ?? time()) - $t);
}

/** A <time> element: relative text, the absolute UTC time on hover and for assistive technology. */
function rivetRmmUiTime(?string $iso, ?int $now = null): string
{
    if ($iso === null || $iso === '') {
        return '<span class="text-muted">never</span>';
    }
    $abs = str_replace('T', ' ', rtrim($iso, 'Z')) . ' UTC';

    return '<time datetime="' . nullable_htmlentities($iso) . '" title="' . nullable_htmlentities($abs) . '">' . nullable_htmlentities(rivetRmmUiAgo(rivetRmmUiAgeOf($iso, $now))) . '</time>';
}

function rivetRmmUiBytes(?float $b): string
{
    if ($b === null) {
        return 'no data';
    }
    $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($b >= 1024 && $i < 5) {
        $b /= 1024;
        $i++;
    }

    return ($i === 0 ? (string) (int) $b : rtrim(rtrim(number_format($b, 1, '.', ''), '0'), '.')) . ' ' . $u[$i];
}

/** Bits per second as Mbit/s, or "no data". */
function rivetRmmUiMbit($bps): string
{
    return is_numeric($bps) ? rtrim(rtrim(number_format((float) $bps / 1000000, 2, '.', ''), '0'), '.') . ' Mbit/s' : 'no data';
}

function rivetRmmUiNum($v): ?float
{
    return is_numeric($v) ? (float) $v : null;
}

/** A thin progress bar with the band colour. */
function rivetRmmUiBar(?float $pct, string $kind, int $h = 6, string $label = ''): string
{
    $col = rivetRmmUiKind($kind)[0];
    $p = $pct === null ? 0 : max(1, min(100, $pct));

    return '<div class="progress" style="height:' . $h . 'px" role="progressbar" aria-valuenow="' . (int) round($pct ?? 0) . '" aria-valuemin="0" aria-valuemax="100"'
        . ($label !== '' ? ' aria-label="' . nullable_htmlentities($label) . '"' : '') . '><div class="progress-bar bg-' . $col . '" style="width:' . $p . '%"></div></div>';
}

/** 270 degree gauge as inline SVG (no JavaScript). The ticks mark the warn and crit edges; the label carries name, value and band. */
function rivetRmmUiGaugeSvg(string $label, ?float $v, int $warn, int $crit, string $band): string
{
    $txt = ['ok' => 'OK', 'warn' => 'Warning', 'crit' => 'Critical', 'off' => 'No data'][$band] ?? 'No data';
    $col = ['ok' => 'var(--tblr-success)', 'warn' => 'var(--tblr-warning)', 'crit' => 'var(--tblr-danger)', 'off' => 'var(--if-muted)'][$band] ?? 'var(--if-muted)';
    $polar = static fn (float $deg, float $r): array => [56 + $r * cos(deg2rad($deg)), 52 + $r * sin(deg2rad($deg))];
    $arc = static function (float $a0, float $a1) use ($polar): string {
        [$x0, $y0] = $polar($a0, 40);
        [$x1, $y1] = $polar($a1, 40);

        return sprintf('M%.2f %.2f A40 40 0 %d 1 %.2f %.2f', $x0, $y0, ($a1 - $a0) > 180 ? 1 : 0, $x1, $y1);
    };
    $ticks = '';
    foreach (array_unique([$warn, $crit]) as $edge) {
        [$x0, $y0] = $polar(135 + 270 * $edge / 100, 33);
        [$x1, $y1] = $polar(135 + 270 * $edge / 100, 47);
        $ticks .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="var(--if-ink)" stroke-width="1.5"/>', $x0, $y0, $x1, $y1);
    }
    $aria = $label . ' ' . ($v === null ? 'no data' : (int) round($v) . ' percent, ' . $txt);
    $o = '<svg viewBox="0 0 112 96" width="112" height="96" role="img" aria-label="' . nullable_htmlentities($aria) . '" class="rmm-gauge"><path d="' . $arc(135, 405)
        . '" fill="none" stroke="var(--if-border-strong)" stroke-width="9" stroke-linecap="round"' . ($v === null ? ' stroke-dasharray="3 6"' : '') . '/>';
    if ($v !== null) {
        $o .= '<path d="' . $arc(135, max(135.5, 135 + 270 * max(0, min(100, $v)) / 100)) . '" fill="none" stroke="' . $col . '" stroke-width="9" stroke-linecap="round"/>' . $ticks;
        $o .= '<text x="56" y="54" text-anchor="middle" style="fill:var(--if-ink);font-size:24px;font-weight:600">' . (int) round($v) . '</text>'
            . '<text x="56" y="67" text-anchor="middle" style="fill:var(--if-muted);font-size:10px">percent</text>';
    } else {
        $o .= '<text x="56" y="56" text-anchor="middle" style="fill:var(--if-muted);font-size:13px">no data</text>';
    }

    return $o . '</svg>';
}

/** windows | linux | other, from the device's os column, falling back to the OS version text. */
function rivetRmmUiPlatform(array $dev): string
{
    $os = strtolower((string) ($dev['os'] ?? ''));
    $ver = strtolower((string) ($dev['os_version'] ?? ''));
    if ($os === 'windows' || ($os === '' && str_contains($ver, 'windows'))) {
        return 'windows';
    }
    if ($os === 'linux' || (!in_array($os, ['windows', 'linux'], true) && preg_match('/linux|ubuntu|debian|fedora|centos|rhel|red hat|suse|alma|rocky/', $ver) === 1)) {
        return 'linux';
    }

    return 'other';
}

/** Alert severity text to the page's three kinds. */
function rivetRmmUiSeverity(?string $sev): string
{
    $s = strtolower((string) $sev);

    return preg_match('/crit|error|high|fail|severe|alert|emerg/', $s) === 1 ? 'crit' : 'warn';
}

// ------------------------------------------------------------------ the asset page's RMM panel

/**
 * The RMM device behind an asset, as a view-model, or null. Null means "render nothing": the module is off (answered from the state file with
 * no query), the asset has no agent device, the user may not view devices, or the device is outside the user's departments (the same answer for
 * "missing" and "not yours").
 *
 * @param int|null $deviceId the device id when the caller already knows it (from the asset_rmm_links row); looked up otherwise
 * @return array<string,mixed>|null
 */
function rivetRmmUiPanel(\mysqli $mysqli, int $assetId, int $userId, ?int $deviceId = null, ?int $now = null): ?array
{
    if ($userId <= 0 || $assetId <= 0 || !rivetRmmEnabled($mysqli)) {
        return null;
    }
    $now ??= time();
    $rmm = rivetRmmModule($mysqli);
    if ($deviceId === null || $deviceId <= 0) {
        $deviceId = rivetRmmUiDeviceIdForAsset($mysqli, $assetId);
        if ($deviceId === null) {
            return null;
        }
    }
    $dev = $rmm->technician()->visibleDevice($userId, $deviceId);
    if ($dev === null || ((int) ($dev['asset_id'] ?? 0) !== $assetId && (int) ($dev['asset_id'] ?? 0) !== 0)) {
        return null;   // a link row that points at another asset's device is not shown
    }
    $client = (int) $dev['client_id'];
    $authz = $rmm->authorizer();
    $perm = [
        'view' => true,
        'run_saved' => $authz->allowed($userId, RmmAbility::JOB_RUN_SAVED, $client),
        'reboot' => $authz->allowed($userId, RmmAbility::JOB_REBOOT, $client),
        'run_script' => $authz->allowed($userId, RmmAbility::JOB_RUN_SCRIPT, $client),
        'remote' => $authz->allowed($userId, RmmAbility::REMOTE_LAUNCH, $client),
        'admin' => $authz->allowed($userId, RmmAbility::ADMIN, $client),
    ];
    $view = $rmm->readModel()->deviceView($deviceId, false, 15);
    if ($view === null) {
        return null;
    }
    $view['jobs'] = array_slice((array) ($view['jobs'] ?? []), 0, 15);
    $cfg = $rmm->settings()->get();
    $platform = rivetRmmUiPlatform($dev);
    $st = $view['status_info'];
    $state = (string) $st['state'];
    $offline = $state === 'offline' || $state === 'stale';
    $met = is_array($view['metrics'] ?? null) ? $view['metrics'] : [];
    $inv = is_array($view['inventory'] ?? null) ? $view['inventory'] : [];
    $bands = rivetRmmUiBands();

    // ---- alerts (the edition's rmm_alerts rows of this device)
    $integrationId = (int) ($cfg['integration_id'] ?? 0);
    $alerts = ['open' => [], 'recent' => []];
    $a = mysqli_query($mysqli, 'SELECT id, severity, status, message, created_at, resolved_at, acknowledged_at, ticket_id FROM rmm_alerts WHERE asset_id = ' . $assetId
        . ' AND integration_id = ' . $integrationId . " AND status <> 'resolved' ORDER BY created_at DESC LIMIT 20");
    while ($a && ($r = mysqli_fetch_assoc($a))) {
        $r['kind'] = rivetRmmUiSeverity($r['severity']);
        $alerts['open'][] = $r;
    }
    $a = mysqli_query($mysqli, 'SELECT id, severity, status, message, created_at, resolved_at, ticket_id FROM rmm_alerts WHERE asset_id = ' . $assetId
        . ' AND integration_id = ' . $integrationId . " AND status = 'resolved' ORDER BY resolved_at DESC LIMIT 5");
    while ($a && ($r = mysqli_fetch_assoc($a))) {
        $r['kind'] = rivetRmmUiSeverity($r['severity']);
        $alerts['recent'][] = $r;
    }
    $openCrit = false;
    foreach ($alerts['open'] as $r) {
        $openCrit = $openCrit || $r['kind'] === 'crit';
    }

    // ---- remote sessions
    $sessions = [];
    $meshConfigured = (int) ($cfg['mesh_enabled'] ?? 0) === 1 && (string) ($cfg['mesh_url'] ?? '') !== '' && !empty($cfg['mesh_login_key_enc']);
    if ($perm['remote']) {
        $s = mysqli_query($mysqli, 'SELECT s.created_at, s.source_ip, s.connection_type, u.user_name FROM rmm_remote_sessions s LEFT JOIN users u ON u.user_id = s.user_id WHERE s.asset_id = '
            . $assetId . ' ORDER BY s.created_at DESC LIMIT 5');
        while ($s && ($r = mysqli_fetch_assoc($s))) {
            $sessions[] = $r;
        }
    }

    // ---- saved scripts for the run dialog (PowerShell only: Linux has no script jobs in Phase 0)
    $saved = [];
    if ($perm['run_saved'] && $platform === 'windows') {
        $s = mysqli_query($mysqli, "SELECT id, name FROM rmm_scripts WHERE enabled = 1 AND script_type = 'powershell' AND script_body IS NOT NULL AND script_body <> '' ORDER BY name LIMIT 300");
        while ($s && ($r = mysqli_fetch_assoc($s))) {
            $saved[] = $r;
        }
    }

    // ---- gauges (a reading the agent could not take stays null)
    $gauges = [];
    $cpu = rivetRmmUiNum($met['cpu_pct'] ?? null);
    $gauges[] = ['id' => 'cpu', 'label' => 'CPU', 'value' => $cpu, 'band' => rivetRmmUiBand($cpu, $bands['cpu']), 'warn' => $bands['cpu'][0], 'crit' => $bands['cpu'][1],
        'caption' => trim((string) ($inv['cpu']['model'] ?? '')) !== '' ? (string) $inv['cpu']['model'] . (!empty($inv['cpu']['cores']) ? ' (' . (int) $inv['cpu']['cores'] . ' cores)' : '') : 'processor load'];
    $mem = rivetRmmUiNum($met['mem_pct'] ?? null);
    $gauges[] = ['id' => 'mem', 'label' => 'Memory', 'value' => $mem, 'band' => rivetRmmUiBand($mem, $bands['mem']), 'warn' => $bands['mem'][0], 'crit' => $bands['mem'][1],
        'caption' => isset($inv['memory_total_bytes']) && is_numeric($inv['memory_total_bytes']) ? rivetRmmUiBytes((float) $inv['memory_total_bytes']) . ' installed' : 'memory in use'];
    $volSizes = [];
    foreach ((array) ($inv['disks'] ?? []) as $d) {
        if (is_array($d) && isset($d['mount'])) {
            $volSizes[(string) $d['mount']] = $d;
        }
    }
    $disks = [];
    foreach ((array) ($met['disk'] ?? []) as $d) {
        if (!is_array($d) || !isset($d['mount'])) {
            continue;
        }
        $u = rivetRmmUiNum($d['used_pct'] ?? null);
        $size = $volSizes[(string) $d['mount']] ?? [];
        $cap = isset($size['total_bytes'], $size['free_bytes']) && is_numeric($size['total_bytes']) && is_numeric($size['free_bytes'])
            ? rivetRmmUiBytes((float) $size['free_bytes']) . ' free of ' . rivetRmmUiBytes((float) $size['total_bytes']) : 'used space';
        $disks[] = ['id' => 'disk:' . $d['mount'], 'label' => 'Disk ' . $d['mount'], 'value' => $u, 'band' => rivetRmmUiBand($u, $bands['disk']), 'warn' => $bands['disk'][0],
            'crit' => $bands['disk'][1], 'caption' => $cap];
    }
    // Battery appears only when the agent reports it (Phase 0 agents do not; the key is read so a later agent shows it without a page change).
    $battery = rivetRmmUiNum($met['battery_pct'] ?? null);
    $batteryGauge = null;
    if ($battery !== null) {
        $b = $bands['battery'];
        $bk = $battery <= $b[1] ? 'crit' : ($battery <= $b[0] ? 'warn' : 'ok');
        $batteryGauge = ['id' => 'battery', 'label' => 'Battery', 'value' => $battery, 'band' => $bk, 'warn' => 100 - $b[0], 'crit' => 100 - $b[1], 'caption' => 'charge remaining', 'inverted' => true];
    }

    // ---- checks
    $checks = [];
    $counts = ['fail' => 0, 'warn' => 0, 'ok' => 0, 'unknown' => 0];
    $checkTypes = [];
    foreach ($rmm->settings()->checks() as $def) {
        if (isset($def['key'], $def['type'])) {
            $checkTypes[(string) $def['key']] = (string) $def['type'];
        }
    }
    foreach ((array) ($view['checks'] ?? []) as $c) {
        $status = in_array($c['status'], ['ok', 'warn', 'fail', 'unknown'], true) ? $c['status'] : 'unknown';
        $c['shown_status'] = $offline ? 'unknown' : $status;
        $c['type'] = str_replace('_', ' ', $checkTypes[(string) $c['key']] ?? 'check');
        $counts[$c['shown_status']]++;
        $checks[] = $c;
    }

    // ---- state of the strip
    $pending = $dev['link_state'] === 'pending_approval';
    $kind = $state === 'online' ? ($openCrit ? 'crit' : 'ok') : ($state === 'offline' ? 'crit' : 'off');
    $label = $state === 'never' ? 'Waiting for first check-in' : ($state === 'online' ? ($openCrit ? 'Online with critical alerts' : 'Online')
        : ($state === 'offline' ? 'Offline' : 'Not seen for days'));

    $banners = [];
    if ($dev['revoked_at'] !== null) {
        $banners[] = ['kind' => 'crit', 'text' => 'This device was revoked. It can no longer check in or receive jobs.' . ($dev['revoked_reason'] ? ' Reason: ' . $dev['revoked_reason'] : '')];
    } elseif ($dev['retired_at'] !== null) {
        $banners[] = ['kind' => 'off', 'text' => 'This device was retired. It no longer checks in or receives jobs.'];
    }
    if ($pending) {
        $banners[] = ['kind' => 'warn', 'text' => 'This device is waiting for approval. ' . ($view['match_reason_text'] ?? '') . ' Jobs and remote sessions stay off until an administrator approves it.'];
    }
    if ($state === 'offline') {
        $banners[] = ['kind' => 'off', 'text' => 'This device is offline. Last check-in ' . rivetRmmUiAgo($st['age_s']) . '. Jobs you queue now are delivered when it returns, until they expire. Gauges show the last reading, dimmed.'];
    } elseif ($state === 'stale') {
        $banners[] = ['kind' => 'off', 'text' => 'This device has been quiet for ' . rivetRmmUiDuration($st['age_s']) . '. Consider retiring it if it is gone.'];
    }
    $usable = $dev['revoked_at'] === null && $dev['retired_at'] === null && $dev['link_state'] === 'linked';

    return [
        'device_id' => $deviceId,
        'asset_id' => $assetId,
        'client_id' => $client,
        'hostname' => (string) $dev['hostname'],
        'platform' => $platform,
        'os_label' => (string) ($dev['os_version'] ?? ''),
        'arch' => (string) ($dev['arch'] ?? ''),
        'agent_version' => (string) ($dev['agent_version'] ?? ''),
        'ring' => (string) ($dev['ring'] ?? ''),
        'link_state' => (string) $dev['link_state'],
        'pending_approval' => $pending,
        'revoked' => $dev['revoked_at'] !== null,
        'retired' => $dev['retired_at'] !== null,
        'usable' => $usable,
        'state' => $state,
        'offline' => $offline,
        'strip' => ['kind' => $kind, 'label' => $label],
        'age_s' => $st['age_s'],
        'last_checkin_at' => $st['last_checkin_at'],
        'last_collected_at' => $view['last_collected_at'] ?? null,
        'last_inventory_at' => $view['last_inventory_at'] ?? null,
        'offline_since' => $st['offline_since'],
        'uptime_s' => $view['uptime_s'] ?? null,
        'pending_reboot' => $view['pending_reboot'] ?? null,
        'logged_in_user' => (string) ($view['logged_in_user'] ?? ''),
        'expected_interval_s' => (int) ($cfg['check_in_interval_s'] ?? 300),
        'offline_after_s' => (int) ($cfg['offline_after_s'] ?? 900),
        'perm' => $perm,
        'gauges' => array_merge([$gauges[0], $gauges[1]], $disks, $batteryGauge === null ? [] : [$batteryGauge]),
        'net' => ['rx_bps' => rivetRmmUiNum($met['net_rx_bps'] ?? null), 'tx_bps' => rivetRmmUiNum($met['net_tx_bps'] ?? null)],
        'checks' => $checks,
        'check_counts' => $counts,
        'alerts' => $alerts,
        'inventory' => $inv,
        'jobs' => (array) ($view['jobs'] ?? []),
        'mesh' => ['mapped' => !empty($view['mesh']['mapped']), 'source' => $view['mesh']['source'] ?? null, 'node_id' => $perm['admin'] ? ($view['mesh']['node_id'] ?? '') : '',
            'configured' => $meshConfigured],
        'remote_sessions' => $sessions,
        'saved_scripts' => $saved,
        'banners' => $banners,
        'match_reason_text' => (string) ($view['match_reason_text'] ?? ''),
        'update_state' => $view['update_state'] ?? null,
        'offered_release' => $view['offered_release'] ?? null,
        'coexistence_policy' => (string) ($cfg['coexistence_policy'] ?? ''),
    ];
}

/** The agent device linked to an asset through asset_rmm_links (the link row carries "rivetit:<device id>"), or null. */
function rivetRmmUiDeviceIdForAsset(\mysqli $mysqli, int $assetId): ?int
{
    $r = mysqli_query($mysqli, "SELECT tactical_agent_id FROM asset_rmm_links WHERE asset_id = $assetId AND tactical_agent_id LIKE 'rivetit:%' ORDER BY id DESC LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if (!$row || preg_match('/^rivetit:(\d+)$/', (string) $row['tactical_agent_id'], $m) !== 1) {
        return null;
    }

    return (int) $m[1];
}

// ------------------------------------------------------------------ the fleet page

/**
 * Everything the fleet page shows, as one view-model, or null when the module is off or the user may not view devices.
 *
 * @param array{status?:string,q?:string,ring?:string,client_id?:int,page?:int,per_page?:int} $filters
 * @return array<string,mixed>|null
 */
function rivetRmmUiFleet(\mysqli $mysqli, int $userId, array $filters = [], ?int $now = null): ?array
{
    if ($userId <= 0 || !rivetRmmEnabled($mysqli)) {
        return null;
    }
    $now ??= time();
    $rmm = rivetRmmModule($mysqli);
    $authz = $rmm->authorizer();
    if (!$authz->allowed($userId, RmmAbility::DEVICE_VIEW, 0)) {
        return null;
    }
    $visible = $authz->visibleClientIds($userId);
    $read = $rmm->readModel();
    $isAdmin = $authz->allowed($userId, RmmAbility::ADMIN, 0);
    $canManage = $authz->allowed($userId, RmmAbility::DEVICE_MANAGE, 0);

    $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 25)));
    $page = max(1, (int) ($filters['page'] ?? 1));
    $listFilters = [];
    foreach (['status', 'q', 'ring'] as $k) {
        if (isset($filters[$k]) && is_string($filters[$k]) && $filters[$k] !== '') {
            $listFilters[$k] = $filters[$k];
        }
    }
    if (!empty($filters['client_id'])) {
        $listFilters['client_id'] = (int) $filters['client_id'];
    }
    $list = $read->listDevices($listFilters, $visible, $perPage, ($page - 1) * $perPage);
    $counts = $read->fleetCounts($visible);

    // Offline devices, oldest silence first within the page of ten.
    $offline = $read->listDevices(['status' => 'offline'], $visible, 10, 0)['items'];
    $stale = $read->listDevices(['status' => 'stale'], $visible, 5, 0)['items'];
    $approvals = $read->pendingApprovals($visible, 10);

    // Agent versions and rings. A scan of at most FLEET_SCAN devices; a larger fleet says so rather than pretending.
    $current = $read->currentBinaries();
    $byRing = ['pilot' => 0, 'stable' => 0];
    $byVersion = [];
    $outdated = [];
    $scanned = 0;
    $scanCap = 2000;
    for ($off = 0; $off < $scanCap; $off += 500) {
        $chunk = $read->listDevices([], $visible, 500, $off, true);
        foreach ($chunk['items'] as $d) {
            $scanned++;
            if ($d['revoked'] || $d['retired']) {
                continue;
            }
            $byRing[$d['ring']] = ($byRing[$d['ring']] ?? 0) + 1;
            $v = (string) $d['agent_version'];
            if ($v !== '') {
                $byVersion[$v] = ($byVersion[$v] ?? 0) + 1;
            }
            $target = rivetRmmUiTargetVersion($current);
            if ($target !== null && $v !== '' && version_compare(ltrim($v, 'v'), ltrim($target, 'v'), '<')) {
                $outdated[] = $d + ['target_version' => $target];
            }
        }
        if (count($chunk['items']) < 500 || $off + 500 >= $chunk['total']) {
            break;
        }
    }
    ksort($byVersion);

    // Clients (departments) by id, in one query.
    $clientIds = [];
    foreach (array_merge($list['items'], $offline, $stale, $outdated) as $d) {
        $clientIds[(int) $d['client_id']] = true;
    }
    foreach ($approvals as $d) {
        $clientIds[(int) $d['client_id']] = true;
    }
    $clientNames = rivetRmmUiClientNames($mysqli, array_keys($clientIds));

    $failures = $read->recentFailedJobs(8, rivetRmmPrincipal($userId, ''));

    $capacity = null;
    if ($isAdmin) {
        $redis = null;
        if (function_exists('redisEnabled')) {
            $redis = (bool) redisEnabled();
        }
        $capacity = $rmm->capacity()->build(['redis_available' => $redis]);
    }

    return [
        'now' => $now,
        'counts' => $counts,
        'list' => $list,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => max(1, (int) ceil($list['total'] / $perPage)),
        'filters' => $listFilters,
        'offline' => $offline,
        'stale' => $stale,
        'approvals' => $approvals,
        'outdated' => array_slice($outdated, 0, 15),
        'outdated_total' => count($outdated),
        'rings' => $byRing,
        'versions' => $byVersion,
        'scanned' => $scanned,
        'scan_partial' => $scanned >= $scanCap,
        'current_versions' => ['amd64' => $current['amd64']['version'] ?? null, 'arm64' => $current['arm64']['version'] ?? null],
        'failures' => $failures,
        'client_names' => $clientNames,
        'open_alerts' => rivetRmmUiOpenAlertCount($mysqli, $rmm, $visible),
        'capacity' => $capacity,
        'perm' => ['admin' => $isAdmin, 'manage' => $canManage],
    ];
}

/**
 * The version a device should be on. The list summary carries no architecture, so this is the amd64 build's version, or the arm64 build's when
 * only that one is hosted.
 *
 * @param array{amd64:?array<string,mixed>,arm64:?array<string,mixed>} $current
 */
function rivetRmmUiTargetVersion(array $current): ?string
{
    foreach (['amd64', 'arm64'] as $arch) {
        if (is_array($current[$arch] ?? null) && !empty($current[$arch]['version'])) {
            return (string) $current[$arch]['version'];
        }
    }

    return null;
}

/**
 * @param list<int> $ids
 * @return array<int,string> client_id => name
 */
function rivetRmmUiClientNames(\mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
    if ($ids === []) {
        return [];
    }
    $out = [];
    $r = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['client_id']] = (string) $row['client_name'];
    }

    return $out;
}

/** Open (not resolved) agent alerts in the user's departments. */
function rivetRmmUiOpenAlertCount(\mysqli $mysqli, \RivetCore\Rmm\RmmModule $rmm, ?array $visible): int
{
    $integration = (int) ($rmm->settings()->get()['integration_id'] ?? 0);
    $scope = '';
    if ($visible !== null) {
        $scope = $visible === [] ? ' AND client_id IS NULL' : ' AND (client_id IS NULL OR client_id IN (' . implode(',', array_map('intval', $visible)) . '))';
    }
    $r = mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM rmm_alerts WHERE integration_id = $integration AND status <> 'resolved'$scope");
    $row = $r ? mysqli_fetch_assoc($r) : null;

    return (int) ($row['c'] ?? 0);
}
