<?php

/*
 * RMM alerting pages (RivetCore 1.0.0-rc.10, sub-switch `alerting`): agent alerts (agent/rmm_agent_alerts.php), maintenance windows (agent/rmm_maintenance.php),
 * escalation policies (agent/rmm_escalations.php) and the Alerting tab of the asset page (rivetRmmAlrPanelSection()).
 *
 * Every read and write goes through rivetRmmAutoApi() (RivetCore's technician API as the signed-in user): Core decides who may see and do what. The only direct
 * reads are two lookups for the alerts of an API answer (the ticket of an edition alert, the flap flag of a check), limited to the ids the API just returned,
 * and the names of active users and roles for the escalation target pickers.
 *
 * The view-model functions (rivetRmmAlr*View) return arrays and are what the tests call; the renderers (rivetRmmAlr*Page) return HTML strings.
 */

require_once __DIR__ . '/rivet_time.php';
require_once __DIR__ . '/rmm_automation.php';
require_once __DIR__ . '/rmm_pack_ui.php';   // rivetRmmPackStrip(): the "Starter" badge on the maintenance windows list

use RivetCore\Rmm\Authz\RmmAbility;

const RIVET_RMM_ALR_PER_PAGE = 25;
const RIVET_RMM_ALR_HANDLER = 'rmm_automation_alr';
const RIVET_RMM_ALR_DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// ------------------------------------------------------------------ small words and pills


/** Plain words for why an alert was resolved (RivetCore stores a short code; `not_applicable` is rc.24's "the check no longer applies to the device"). */
function rivetRmmAlrResolveReasonText(string $reason): string
{
    if ($reason === 'not_applicable') {
        return 'This check no longer applies to the device';
    }

    return str_replace('_', ' ', $reason);
}

function rivetRmmAlrSevPill(string $sev): string
{
    return $sev === 'crit' ? rivetRmmUiPill('crit', 'Critical') : rivetRmmUiPill('warn', 'Warning');
}

/** "3 h ago" with the time in the company zone under it. */
function rivetRmmAlrWhen(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '<span class="text-muted">never</span>';
    }

    return rivetRmmUiTime($iso) . '<div class="small text-muted">' . rmmH(rivetFormatTime($iso, 'utc')) . '</div>';
}

/** "in 2 h 5 m" for a time ahead (or "now" when it is not ahead). */
function rivetRmmAlrIn(?string $iso, ?int $now = null): string
{
    $t = $iso === null || $iso === '' ? false : strtotime(str_ends_with($iso, 'Z') || str_contains($iso, 'T') ? $iso : $iso . ' UTC');
    if ($t === false) {
        return 'unknown';
    }
    $d = $t - ($now ?? time());

    return $d <= 0 ? 'now' : 'in ' . rivetRmmUiDuration($d);
}

function rivetRmmAlrModeWords(string $mode): string
{
    return $mode === 'suppress' ? 'Suppress (results are recorded but not evaluated)' : 'Mute (alerts are held back until the window ends)';
}

function rivetRmmAlrModeShort(string $mode): string
{
    return $mode === 'suppress' ? 'Suppress' : 'Mute';
}

/** "4 h", "90 min", "1 h 30 min". */
function rivetRmmAlrMinutes(int $m): string
{
    if ($m < 60) {
        return $m . ' min';
    }

    return intdiv($m, 60) . ' h' . ($m % 60 > 0 ? ' ' . ($m % 60) . ' min' : '');
}

/**
 * The schedule of a window in words: "Once, 2026-10-12 22:00 to 2026-10-13 02:00 CDT" (company zone) or "Every Saturday 22:00, 4 h, America/Chicago".
 *
 * @param array<string,mixed> $w a presented window
 */
function rivetRmmAlrScheduleWords(array $w): string
{
    if (($w['kind'] ?? 'once') === 'once') {
        $f = static fn (?string $iso): string => $iso === null ? '?' : (rivetFormatTime($iso, 'utc', false) ?: '?');

        return 'Once, ' . $f($w['starts_at'] ?? null) . ' to ' . $f($w['ends_at'] ?? null) . ' ' . rivetTimeZoneLabel();
    }
    $n = max(1, (int) ($w['recur_interval'] ?? 1));
    $days = array_filter(explode(',', (string) ($w['recur_days'] ?? '')), static fn ($d) => $d !== '');
    switch ((string) ($w['recur_freq'] ?? 'daily')) {
        case 'weekly':
            $names = array_map(static fn ($d) => RIVET_RMM_ALR_DAYS[(int) $d] ?? $d, $days);
            $last = array_pop($names);
            $list = $names === [] ? (string) $last : implode(', ', $names) . ' and ' . $last;
            $what = ($n === 1 ? 'Every ' : 'Every ' . $n . ' weeks on ') . $list;
            break;
        case 'monthly':
            $list = implode(', ', array_map(static fn ($d) => $d === 'last' ? 'the last day' : 'day ' . $d, $days));
            $what = 'On ' . $list . ($n === 1 ? ' of every month' : ' of every ' . $n . ' months');
            break;
        default:
            $what = $n === 1 ? 'Every day' : 'Every ' . $n . ' days';
    }
    $out = $what . ' ' . (string) ($w['local_start'] ?? '00:00') . ', ' . rivetRmmAlrMinutes((int) ($w['duration_min'] ?? 60)) . ', ' . (string) ($w['timezone'] ?? 'UTC');
    if (!empty($w['recur_from'])) {
        $out .= ', from ' . $w['recur_from'];
    }
    if (!empty($w['recur_until'])) {
        $out .= ', until ' . $w['recur_until'];
    }

    return $out;
}

/**
 * Active users and roles for the pickers and for naming targets in words (two small queries).
 *
 * @return array{users:array<int,string>,roles:array<int,string>}
 */
function rivetRmmAlrDirectory(\mysqli $mysqli): array
{
    $out = ['users' => [], 'roles' => []];
    $r = mysqli_query($mysqli, 'SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name LIMIT 500');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out['users'][(int) $row['user_id']] = (string) $row['user_name'];
    }
    $r = mysqli_query($mysqli, 'SELECT role_id, role_name FROM user_roles WHERE role_archived_at IS NULL ORDER BY role_name LIMIT 200');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out['roles'][(int) $row["role_id"]] = (string) $row["role_name"];
    }

    return $out;
}

/**
 * Escalation steps in words: "After 0 min: Maria Lopez, role Service Desk; after 30 min: noc@example.com".
 *
 * @param list<array<string,mixed>> $steps
 * @param array{users:array<int,string>,roles:array<int,string>} $dir
 */
function rivetRmmAlrStepsWords(array $steps, array $dir): string
{
    $parts = [];
    foreach ($steps as $i => $s) {
        $who = [];
        foreach ((array) ($s['targets'] ?? []) as $t) {
            $ref = (string) ($t['ref'] ?? '');
            $type = (string) ($t['type'] ?? '');
            if ($type === 'user') {
                $who[] = $dir['users'][(int) $ref] ?? ('user #' . $ref);
            } elseif ($type === 'group') {
                $who[] = 'role ' . ($dir['roles'][(int) $ref] ?? ('#' . $ref));
            } elseif ($type === 'email') {
                $who[] = $ref;
            } else {
                $who[] = $type . ' ' . $ref . ' (not delivered by RivetIT)';
            }
        }
        $parts[] = ($i === 0 ? 'After ' : 'after ') . (int) ($s['after_min'] ?? 0) . ' min: ' . implode(', ', $who);
    }

    return implode('; ', $parts);
}

/** Lookups for alerts of an API answer: their ticket (edition table rmm_alerts, by the ids the API returned) and whether their check is flapping. @return array<int,array{ticket_id:?int,flapping:bool}> */
function rivetRmmAlrEnrich(\mysqli $mysqli, array $items): array
{
    $out = [];
    $ids = [];
    $devs = [];
    foreach ($items as $a) {
        $id = (int) $a['alert_id'];
        $out[$id] = ['ticket_id' => null, 'flapping' => false];
        $ids[] = $id;
        $devs[(int) $a['device_id']] = true;
    }
    if ($ids === []) {
        return $out;
    }
    $r = mysqli_query($mysqli, 'SELECT id, ticket_id FROM rmm_alerts WHERE ticket_id IS NOT NULL AND id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['id']]['ticket_id'] = (int) $row['ticket_id'];
    }
    $flap = [];
    $r = mysqli_query($mysqli, 'SELECT device_id, check_key FROM rmm_check_eval WHERE flapping = 1 AND device_id IN (' . implode(',', array_keys($devs)) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $flap[(int) $row['device_id'] . '|' . $row['check_key']] = true;
    }
    foreach ($items as $a) {
        $out[(int) $a['alert_id']]['flapping'] = isset($flap[(int) $a['device_id'] . '|' . $a['check_key']]);
    }

    return $out;
}

function rivetRmmAlrDeviceLink(array $a): string
{
    $name = (string) ($a['hostname'] ?? '') !== '' ? (string) $a['hostname'] : 'device #' . (int) $a['device_id'];
    if (($a['asset_id'] ?? null) === null) {
        return rmmH($name);
    }

    return '<a href="/agent/asset_details.php?asset_id=' . (int) $a['asset_id'] . '#rmm-alerting">' . rmmH($name) . '</a>';
}

function rivetRmmAlrCanTicket(): bool
{
    return function_exists('lookupUserPermission') && lookupUserPermission('module_support') >= 2;
}

// ------------------------------------------------------------------ agent alerts: view-model

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmAlrAlertsView(array $ctx, array $get): array
{
    $m = $ctx['mysqli'];
    $str = static fn (string $k): string => is_string($get[$k] ?? null) ? trim($get[$k]) : '';
    $f = [
        'state' => in_array($str('state'), ['active', 'open', 'acknowledged', 'resolved'], true) ? $str('state') : 'active',
        'severity' => in_array($str('severity'), ['warn', 'crit'], true) ? $str('severity') : '',
        'client_id' => max(0, (int) ($get['client_id'] ?? 0)),
        'device_id' => max(0, (int) ($get['device_id'] ?? 0)),
        'check_key' => preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $str('check_key')) === 1 ? $str('check_key') : '',
        'group_key' => preg_match('/^[A-Za-z0-9_.:\-]{1,120}$/', $str('group_key')) === 1 ? $str('group_key') : '',
        'view' => in_array($str('view'), ['flat', 'device', 'check'], true) ? $str('view') : 'flat',
        'page' => max(1, (int) ($get['page'] ?? 1)),
    ];
    $base = ['perm' => $ctx['perm'], 'csrf' => $ctx['csrf'], 'can_ticket' => rivetRmmAlrCanTicket(), 'filters' => $f, 'error' => ''];

    // ---- one alert
    $alertId = (int) ($get['alert_id'] ?? 0);
    if ($alertId > 0) {
        return rivetRmmAlrDetailView($ctx, $alertId) + $base + ['kind' => 'detail'];
    }

    $q = array_filter(['state' => $f['state'], 'severity' => $f['severity'], 'check_key' => $f['check_key'], 'group_key' => $f['group_key'],
        'client_id' => $f['client_id'] > 0 ? (string) $f['client_id'] : '', 'device_id' => $f['device_id'] > 0 ? (string) $f['device_id'] : ''], static fn ($v) => $v !== '');

    // ---- KPI cards: the active alerts of the user's departments (one call, at most 500)
    $act = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', ['alerts'], ['state' => 'active', 'limit' => '500']);
    $kpi = ['open_crit' => 0, 'open_warn' => 0, 'acknowledged' => 0, 'flapping' => 0, 'capped' => false];
    if ($act['ok']) {
        $items = (array) ($act['data']['items'] ?? []);
        foreach ($items as $a) {
            if ($a['state'] === 'acknowledged') {
                ++$kpi['acknowledged'];
            } elseif ($a['severity'] === 'crit') {
                ++$kpi['open_crit'];
            } else {
                ++$kpi['open_warn'];
            }
        }
        $kpi['flapping'] = count(array_filter(rivetRmmAlrEnrich($m, $items), static fn ($e) => $e['flapping']));
        $kpi['capped'] = (int) ($act['data']['total'] ?? 0) > count($items);
    } else {
        $base['error'] = $act['error'] !== '' ? $act['error'] : 'The alerts could not be read.';
    }
    $base['kpi'] = $kpi;

    // ---- filter choices
    $scope = rivetRmmAutoScopeData($m, $ctx['uid']);
    $base['clients'] = $scope['clients'];
    $base['devices'] = $scope['devices'];

    // ---- the list
    $groups = [];
    $items = [];
    $total = 0;
    if ($f['view'] === 'check') {
        $g = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', ['alerts', 'groups']);
        $groups = $g['ok'] ? (array) ($g['data']['groups'] ?? []) : [];
        if ($f['client_id'] > 0) {
            $groups = array_values(array_filter($groups, static fn ($x) => (int) $x['client_id'] === $f['client_id']));
        }
        if ($f['severity'] !== '') {
            $groups = array_values(array_filter($groups, static fn ($x) => $x['severity'] === $f['severity']));
        }
    } else {
        $grouped = $f['view'] === 'device';
        $r = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', ['alerts'], $q + ['limit' => (string) ($grouped ? 500 : RIVET_RMM_ALR_PER_PAGE), 'offset' => (string) ($grouped ? 0 : ($f['page'] - 1) * RIVET_RMM_ALR_PER_PAGE)]);
        if ($r['ok']) {
            $items = (array) ($r['data']['items'] ?? []);
            $total = (int) ($r['data']['total'] ?? count($items));
        } elseif ($base['error'] === '') {
            $base['error'] = $r['error'] !== '' ? $r['error'] : 'The alerts could not be read.';
        }
    }

    return $base + ['kind' => 'list', 'items' => $items, 'total' => $total, 'groups' => $groups, 'pages' => max(1, (int) ceil($total / RIVET_RMM_ALR_PER_PAGE)),
        'extra' => rivetRmmAlrEnrich($m, $items)];
}

/** @return array<string,mixed> */
function rivetRmmAlrDetailView(array $ctx, int $alertId): array
{
    $m = $ctx['mysqli'];
    $r = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', ['alerts', $alertId]);
    if (!$r['ok']) {
        return ['alert' => null, 'error' => $r['status'] === 404 ? 'That alert does not exist, or it belongs to a department you cannot see.' : ($r['error'] ?: 'The alert could not be read.')];
    }
    $a = (array) $r['data']['alert'];
    $dev = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', [(int) $a['device_id'], 'alerting']);
    $check = null;
    if ($dev['ok']) {
        foreach ((array) ($dev['data']['checks'] ?? []) as $c) {
            if ($c['check_key'] === $a['check_key']) {
                $check = $c;
            }
        }
    }
    $policy = null;
    if ($a['policy_id'] !== null) {
        $p = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', ['escalation_policies', (int) $a['policy_id']]);
        $policy = $p['ok'] ? (array) $p['data']['policy'] : null;
    }
    $names = [];
    foreach (array_filter([(int) ($a['acknowledged_by'] ?? 0), (int) ($a['resolved_by'] ?? 0)]) as $uid) {
        $row = mysqli_fetch_assoc(mysqli_query($m, 'SELECT user_name FROM users WHERE user_id = ' . $uid));
        $names[$uid] = (string) ($row['user_name'] ?? ('user #' . $uid));
    }
    $extra = rivetRmmAlrEnrich($m, [$a])[$alertId] ?? ['ticket_id' => null, 'flapping' => false];

    return ['alert' => $a, 'check' => $check, 'device_alerting' => $dev['ok'] ? $dev['data'] : null, 'policy' => $policy, 'dir' => rivetRmmAlrDirectory($m), 'names' => $names, 'extra' => $extra, 'error' => ''];
}

// ------------------------------------------------------------------ agent alerts: renderers

function rivetRmmAlrAlertsUrl(array $f, array $over = []): string
{
    $f = $over + $f;
    $q = [];
    foreach (['state', 'severity', 'client_id', 'device_id', 'check_key', 'group_key', 'view', 'page'] as $k) {
        $v = $f[$k] ?? '';
        if ($v === '' || $v === 0 || ($k === 'state' && $v === 'active') || ($k === 'view' && $v === 'flat') || ($k === 'page' && (int) $v <= 1)) {
            continue;
        }
        $q[$k] = $v;
    }
    if (isset($over['alert_id'])) {
        $q = ['alert_id' => $over['alert_id']];
    }

    return '/agent/rmm_agent_alerts.php' . ($q === [] ? '' : '?' . http_build_query($q));
}

/** Acknowledge / Resolve / Create ticket for one alert row. @param array<string,mixed> $a */
function rivetRmmAlrAlertButtons(array $a, array $vm, string $returnTo, ?int $ticketId): string
{
    $o = '';
    if ($ticketId !== null) {
        $o .= '<a class="btn btn-sm btn-outline-secondary" href="/agent/ticket.php?ticket_id=' . $ticketId . '"><i class="fas fa-ticket-alt me-1" aria-hidden="true"></i>Ticket #' . $ticketId . '</a> ';
    }
    if (!$vm['perm']['alert_manage']) {
        return $o;
    }
    $id = (int) $a['alert_id'];
    if ($a['state'] === 'open') {
        $o .= rivetRmmAutoActionForm(RIVET_RMM_ALR_HANDLER, 'ack_alert', ['alert_id' => $id], $vm['csrf'], $returnTo, '<i class="fas fa-check me-1" aria-hidden="true"></i>Acknowledge',
            'btn btn-sm btn-outline-primary') . ' ';
    }
    if ($a['state'] !== 'resolved') {
        $o .= rivetRmmAutoActionForm(RIVET_RMM_ALR_HANDLER, 'resolve_alert', ['alert_id' => $id], $vm['csrf'], $returnTo, '<i class="fas fa-check-double me-1" aria-hidden="true"></i>Resolve',
            'btn btn-sm btn-outline-success', 'Resolve this alert by hand? This closes the alert even though the check may still be failing. If it keeps failing, a new alert opens.') . ' ';
    }
    if ($ticketId === null && $vm['can_ticket'] && $a['state'] !== 'resolved') {
        $o .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-alr-ticket="' . $id . '" data-csrf="' . rmmH($vm['csrf']) . '"><i class="fas fa-ticket-alt me-1" aria-hidden="true"></i>Create ticket</button>';
    }

    return $o;
}

function rivetRmmAlrKpiCards(array $k): string
{
    $card = static fn (string $n, string $label, string $icon, string $edge): string => '<div class="col-6 col-md-3"><div class="card card-dark h-100 rmm-alr-kpi" style="border-top:3px solid var(--tblr-' . $edge . ')"><div class="card-body py-2">'
        . '<div class="rmm-alr-kpi-n">' . rmmH($n) . '</div><div class="small text-muted"><i class="fas fa-' . $icon . ' me-1" aria-hidden="true"></i>' . rmmH($label) . '</div></div></div></div>';
    $cap = $k['capped'] ? '+' : '';

    return '<div class="row g-2 mb-3" role="group" aria-label="Alert counts">' . $card($k['open_crit'] . $cap, 'Open critical', 'exclamation-circle', 'danger') . $card($k['open_warn'] . $cap, 'Open warning', 'exclamation-triangle', 'warning')
        . $card($k['acknowledged'] . $cap, 'Acknowledged', 'check', 'info') . $card($k['flapping'] . $cap, 'Flapping', 'random', 'secondary') . '</div>';
}

function rivetRmmAlrFilterForm(array $vm): string
{
    $f = $vm['filters'];
    $sel = static function (string $id, string $label, array $opts, string $cur): string {
        $o = '<div class="col-6 ' . ($id === 'state' ? 'col-md-3' : 'col-md-2') . '"><label class="form-label small mb-1" for="' . $id . '">' . rmmH($label) . '</label><select class="form-select form-select-sm" id="' . $id . '" name="' . $id . '">';
        foreach ($opts as $v => $t) {
            $o .= '<option value="' . rmmH((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>' . rmmH($t) . '</option>';
        }

        return $o . '</select></div>';
    };
    $clients = ['0' => 'All departments'];
    foreach ($vm['clients'] as $id => $n) {
        $clients[(string) $id] = $n;
    }
    $devices = ['0' => 'All devices'];
    foreach ($vm['devices'] as $id => $n) {
        $devices[(string) $id] = $n;
    }

    return '<form method="get" action="/agent/rmm_agent_alerts.php" class="card card-dark mb-3"><div class="card-body py-2"><div class="row g-2 align-items-end">'
        . $sel('state', 'State', ['active' => 'Active (open or acknowledged)', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved'], $f['state'])
        . $sel('severity', 'Severity', ['' => 'Any severity', 'crit' => 'Critical', 'warn' => 'Warning'], $f['severity'])
        . $sel('client_id', 'Department', $clients, (string) $f['client_id'])
        . $sel('device_id', 'Device', $devices, (string) $f['device_id'])
        . '<div class="col-6 col-md-2"><label class="form-label small mb-1" for="check_key">Check key</label><input class="form-control form-control-sm" id="check_key" name="check_key" maxlength="64" value="' . rmmH($f['check_key']) . '"></div>'
        . '<input type="hidden" name="view" value="' . rmmH($f['view']) . '">'
        . '<div class="col-12 col-md-auto"><button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1" aria-hidden="true"></i>Filter</button> '
        . '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH(rivetRmmAlrAlertsUrl(['view' => $f['view']], ['state' => 'active'])) . '">Clear</a></div></div></div></form>';
}

/** @param array<string,mixed> $vm */
function rivetRmmAlrAlertRows(array $vm, array $items, string $returnTo): string
{
    $rows = '';
    foreach ($items as $a) {
        $e = $vm['extra'][(int) $a['alert_id']] ?? ['ticket_id' => null, 'flapping' => false];
        $badges = ($e['flapping'] ? ' ' . rivetRmmUiPill('warn', 'Flapping') : '');
        $rows .= '<tr><td class="ps-3">' . rivetRmmAlrSevPill($a['severity']) . '</td><td>' . rivetRmmAutoStatePill($a['state']) . $badges . '</td><td>' . rivetRmmAlrDeviceLink($a) . '</td>'
            . '<td class="text-muted small">' . rmmH($vm['clients'][(int) $a['client_id']] ?? ('#' . (int) $a['client_id'])) . '</td>'
            . '<td><code class="rmm-mono">' . rmmH($a['check_key']) . '</code></td><td class="rmm-wrap"><a href="' . rmmH(rivetRmmAlrAlertsUrl([], ['alert_id' => (int) $a['alert_id']])) . '">' . rmmH($a['message'] !== '' ? $a['message'] : 'Open the alert') . '</a></td>'
            . '<td>' . rivetRmmAlrWhen($a['opened_at']) . '</td><td class="rmm-table-actions">' . rivetRmmAlrAlertButtons($a, $vm, $returnTo, $e['ticket_id']) . '</td></tr>';
    }

    return $rows;
}

function rivetRmmAlrPager(array $vm): string
{
    $f = $vm['filters'];
    if ($vm['pages'] <= 1) {
        return '';
    }
    $link = static fn (int $p, string $t, bool $on): string => '<li class="page-item' . ($on ? '' : ' disabled') . '"><a class="page-link" href="' . ($on ? rmmH(rivetRmmAlrAlertsUrl($f, ['page' => $p])) : '#') . '"' . ($on ? '' : ' tabindex="-1" aria-disabled="true"') . '>' . $t . '</a></li>';

    return '<nav aria-label="Alert pages" class="mt-2"><ul class="pagination pagination-sm mb-0">' . $link($f['page'] - 1, 'Previous', $f['page'] > 1)
        . '<li class="page-item disabled"><span class="page-link">Page ' . (int) $f['page'] . ' of ' . (int) $vm['pages'] . '</span></li>' . $link($f['page'] + 1, 'Next', $f['page'] < $vm['pages']) . '</ul></nav>';
}

/**
 * The Agent alerts page.
 *
 * @param array<string,mixed> $vm rivetRmmAlrAlertsView()
 */
function rivetRmmAlrAlertsPage(array $vm): string
{
    $f = $vm['filters'];
    if ($vm['kind'] === 'detail') {
        return rivetRmmAlrDetailPage($vm);
    }
    $returnTo = rivetRmmAlrAlertsUrl($f);
    $o = rivetRmmAutoHeader('Agent alerts', 'bell', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_alerts.php"><i class="fas fa-list me-1" aria-hidden="true"></i>Alerts of all RMM integrations</a>',
        'Alerts raised by the RivetIT endpoint agent. A critical alert can open a ticket. Acknowledging stops the escalation; resolving closes the alert by hand.');
    $o .= '<div id="alr-msg" class="alert d-none" role="status" aria-live="polite"></div>';
    if ($vm['error'] !== '') {
        $o .= '<div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle me-2" aria-hidden="true"></i>' . rmmH($vm['error']) . '</div>';
    }
    $o .= rivetRmmAlrKpiCards($vm['kpi']) . rivetRmmAlrFilterForm($vm);

    $tab = static fn (string $view, string $label, string $icon) => '<li class="nav-item"><a class="nav-link small' . ($f['view'] === $view ? ' active' : '') . '"' . ($f['view'] === $view ? ' aria-current="page"' : '')
        . ' href="' . rmmH(rivetRmmAlrAlertsUrl($f, ['view' => $view, 'page' => 1])) . '"><i class="fas fa-' . $icon . ' me-1" aria-hidden="true"></i>' . $label . '</a></li>';
    $o .= '<ul class="nav nav-pills mb-3" aria-label="How to show the alerts">' . $tab('flat', 'List', 'list') . $tab('device', 'Grouped by device', 'desktop') . $tab('check', 'Grouped by check', 'tasks') . '</ul>';

    $thead = rivetRmmUiThead(['Severity', 'State', 'Device', 'Department', 'Check', 'Message', 'Opened', 'Actions']);
    $table = static fn (string $caption, string $head, string $rows) => '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><caption class="visually-hidden">' . rmmH($caption) . '</caption>' . $head . '<tbody>' . $rows . '</tbody></table></div>';

    if ($f['view'] === 'check') {
        if ($vm['groups'] === []) {
            return $o . rivetRmmUiCard('Active alerts by check', 'tasks', rivetRmmUiEmpty('fas fa-check-circle', 'No active alerts', 'Nothing is open or acknowledged right now.'), '', '', false) . rivetRmmAutoConfirmModal();
        }
        $rows = '';
        foreach ($vm['groups'] as $g) {
            $rows .= '<tr><td class="ps-3">' . rivetRmmAlrSevPill($g['severity']) . '</td><td><code class="rmm-mono">' . rmmH($g['check_key']) . '</code></td><td>' . rmmH($vm['clients'][(int) $g['client_id']] ?? ('#' . (int) $g['client_id'])) . '</td>'
                . '<td>' . (int) $g['alerts'] . '</td><td>' . (int) $g['devices'] . '</td><td>' . rivetRmmAlrWhen($g['last_opened_at']) . '</td>'
                . '<td><a class="btn btn-sm btn-outline-secondary" href="' . rmmH(rivetRmmAlrAlertsUrl([], ['state' => 'active', 'check_key' => $g['check_key'], 'client_id' => (int) $g['client_id']])) . '">Show the alerts</a></td></tr>';
        }

        return $o . rivetRmmUiCard('Active alerts by check', 'tasks', $table('Active alerts grouped by department and check', rivetRmmUiThead(['Worst severity', 'Check', 'Department', 'Alerts', 'Devices', 'Latest', 'Show']), $rows), '', '', true)
            . rivetRmmAutoConfirmModal();
    }
    if ($vm['items'] === []) {
        return $o . rivetRmmUiCard('Alerts', 'bell', rivetRmmUiEmpty('fas fa-check-circle', 'No alerts match', $f['state'] === 'active' ? 'Nothing is open or acknowledged right now.' : 'Try another state or clear the filters.'), '', '', false)
            . rivetRmmAutoConfirmModal();
    }
    if ($f['view'] === 'device') {
        $by = [];
        foreach ($vm['items'] as $a) {
            $by[(int) $a['device_id']][] = $a;
        }
        $o .= '<p class="small text-muted">' . count($by) . ' device' . (count($by) === 1 ? '' : 's') . ' with alerts' . ($vm['total'] > count($vm['items']) ? ' (showing the first ' . count($vm['items']) . ' alerts)' : '') . '.</p>';
        foreach ($by as $list) {
            $crit = count(array_filter($list, static fn ($a) => $a['severity'] === 'crit'));
            $o .= rivetRmmUiCard(rivetRmmAlrDeviceLink($list[0]) . ' <span class="small text-muted ms-2">' . count($list) . ' alert' . (count($list) === 1 ? '' : 's') . ($crit > 0 ? ', ' . $crit . ' critical' : '') . '</span>', 'desktop',
                $table('Alerts of ' . $list[0]['hostname'], $thead, rivetRmmAlrAlertRows($vm, $list, $returnTo)), $crit > 0 ? 'danger' : 'warning', '', true);
        }

        return $o . rivetRmmAutoConfirmModal();
    }

    return $o . rivetRmmUiCard('Alerts (' . (int) $vm['total'] . ')', 'bell', $table('Agent alerts, newest first', $thead, rivetRmmAlrAlertRows($vm, $vm['items'], $returnTo)) . '<div class="p-2">' . rivetRmmAlrPager($vm) . '</div>', '', '', true)
        . rivetRmmAutoConfirmModal();
}

/** @param array<string,mixed> $vm */
function rivetRmmAlrDetailPage(array $vm): string
{
    $o = rivetRmmAutoHeader('Agent alert', 'bell', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_agent_alerts.php"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>All agent alerts</a>');
    $o .= '<div id="alr-msg" class="alert d-none" role="status" aria-live="polite"></div>';
    $a = $vm['alert'] ?? null;
    if ($a === null) {
        return $o . '<div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>' . rmmH($vm['error']) . '</div>';
    }
    $e = $vm['extra'];
    $names = $vm['names'];
    $returnTo = rivetRmmAlrAlertsUrl([], ['alert_id' => (int) $a['alert_id']]);
    $rows = [
        ['Severity', rivetRmmAlrSevPill($a['severity'])],
        ['State', rivetRmmAutoStatePill($a['state']) . ($e['flapping'] ? ' ' . rivetRmmUiPill('warn', 'Flapping') : '')],
        ['Device', rivetRmmAlrDeviceLink($a)],
        ['Check', '<code class="rmm-mono">' . rmmH($a['check_key']) . '</code> <span class="text-muted small">episode ' . (int) $a['episode'] . ', group ' . rmmH($a['group_key']) . '</span>'],
        ['Message', rmmH($a['message'])],
        ['Opened', rivetRmmAlrWhen($a['opened_at'])],
    ];
    if ($a['acknowledged_at'] !== null) {
        $rows[] = ['Acknowledged', rivetRmmAlrWhen($a['acknowledged_at']) . ' by ' . rmmH($names[(int) $a['acknowledged_by']] ?? 'someone')];
    }
    if ($a['resolved_at'] !== null) {
        $by = $a['resolved_by'] !== null ? ' by ' . rmmH($names[(int) $a['resolved_by']] ?? 'someone') : ' automatically';
        $rows[] = ['Resolved', rivetRmmAlrWhen($a['resolved_at']) . $by . ($a['resolve_reason'] ? ' <span class="text-muted small">(' . rmmH(rivetRmmAlrResolveReasonText((string) $a['resolve_reason'])) . ')</span>' : '')];
    }
    $rows[] = ['Ticket', $e['ticket_id'] !== null ? '<a href="/agent/ticket.php?ticket_id=' . (int) $e['ticket_id'] . '">Ticket #' . (int) $e['ticket_id'] . '</a>' : '<span class="text-muted">none yet</span>'];
    $buttons = rivetRmmAlrAlertButtons($a, $vm, $returnTo, null);
    $o .= rivetRmmUiCard('Alert #' . (int) $a['alert_id'], 'bell', rivetRmmUiDl($rows) . ($buttons !== '' ? '<div class="mt-3 d-flex flex-wrap" style="gap:6px">' . $buttons . '</div>' : ''), $a['severity'] === 'crit' ? 'danger' : 'warning');

    // the check now
    $c = $vm['check'];
    if ($c === null) {
        $now = '<p class="mb-0 text-muted">The device does not report this check any more, or its state could not be read.</p>';
    } else {
        $why = rivetRmmAlrWhyNoAlert($c);
        $now = rivetRmmUiDl([
            ['Status now', rivetRmmUiPill(['ok' => 'ok', 'warn' => 'warn', 'fail' => 'crit'][$c['status']] ?? 'off', ['ok' => 'Passing', 'warn' => 'Warning', 'fail' => 'Failing'][$c['status']] ?? 'Unknown')],
            ['Threshold tier', rmmH(rivetRmmAlrTierWords($c['tier'])) . ($c['pending_tier'] !== null && $c['pending_tier'] !== $c['tier'] && $c['pending_tier'] !== 'ok' ? ' <span class="text-muted small">(moving to ' . rmmH(rivetRmmAlrTierWords($c['pending_tier'])) . ')</span>' : '')],
            ['Last value', $c['last_value'] === null ? '<span class="text-muted">none</span>' : rmmH((string) $c['last_value'])],
            ['Flapping', $c['flapping'] ? 'Yes, the check keeps changing state' : 'No'],
        ]) . ($why !== '' ? '<p class="small text-muted mb-0 mt-2">' . rmmH($why) . '</p>' : '');
    }
    $o .= rivetRmmUiCard('The check now', 'heartbeat', $now);

    // escalation
    $p = $vm['policy'];
    if ($p === null) {
        $esc = '<p class="mb-0 text-muted">No escalation policy applies to this alert. ' . ($a['policy_id'] !== null ? 'The policy it started with was removed or is out of your reach.' : 'Add one on the Escalation policies page to be told when an alert stays open.') . '</p>';
    } else {
        $esc = rivetRmmUiDl([
            ['Policy', '<a href="/agent/rmm_escalations.php">' . rmmH($p['name']) . '</a>' . ($p['enabled'] ? '' : ' ' . rivetRmmUiPill('off', 'Off'))],
            ['Steps', rmmH(rivetRmmAlrStepsWords((array) $p['steps'], $vm['dir']))],
            ['Sent so far', (int) $a['notices_sent'] . ' notice' . ((int) $a['notices_sent'] === 1 ? '' : 's') . ($a['last_notified_at'] !== null ? ', the last ' . rivetRmmUiTime($a['last_notified_at']) : '')],
            ['Next notice', $a['state'] !== 'open' ? '<span class="text-muted">none, the alert is ' . rmmH($a['state']) . '</span>' : ($a['next_escalation_at'] !== null ? rivetRmmAlrWhen($a['next_escalation_at']) : '<span class="text-muted">none due</span>')],
        ]);
    }

    return $o . rivetRmmUiCard('Escalation', 'level-up-alt', $esc) . rivetRmmAutoConfirmModal();
}

function rivetRmmAlrTierWords(?string $t): string
{
    return ['ok' => 'Normal', 'warn' => 'Warning level', 'crit' => 'Critical level'][(string) $t] ?? 'Not measured';
}

/** Plain words for why a bad check has no alert yet (from GET {id}/alerting), or ''. @param array<string,mixed> $c */
function rivetRmmAlrWhyNoAlert(array $c): string
{
    if (!in_array($c['status'], ['warn', 'fail'], true) || $c['alert_id'] !== null) {
        return '';
    }
    return match (true) {
        $c['held_by'] === 'maintenance_suppress' => 'No alert: a maintenance window in Suppress mode is open, so results are not evaluated.',
        $c['held_by'] === 'maintenance' => 'No alert yet: a maintenance window in Mute mode is open. If the check is still bad after the window, the alert opens.',
        $c['held_by'] === 'dependency' => 'No alert: the parent device is offline, so alerts of this device are held back.',
        $c['waiting_for_debounce'] === true => 'No alert yet: waiting for the check to fail a few times in a row (the debounce) before an alert opens.',
        default => 'No alert yet.',
    };
}

// ------------------------------------------------------------------ maintenance windows

/** Time zone names for a select: the company zone and common ones first, then every PHP identifier. @return array{common:list<string>,all:list<string>} */
function rivetRmmAlrZones(string $default): array
{
    $all = \DateTimeZone::listIdentifiers();
    $common = ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu', 'America/Toronto', 'Europe/London',
        'Europe/Dublin', 'Europe/Paris', 'Europe/Berlin', 'Europe/Madrid', 'Europe/Amsterdam', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Tokyo', 'Australia/Sydney', 'Pacific/Auckland'];
    if ($default !== '' && in_array($default, $all, true)) {
        array_unshift($common, $default);
    }
    $common = array_values(array_unique(array_filter($common, static fn ($z) => in_array($z, $all, true))));

    return ['common' => $common, 'all' => $all];
}

/** The company time zone (a valid name), else UTC. */
function rivetRmmAlrDefaultZone(): string
{
    $z = (string) ($GLOBALS['config_timezone'] ?? '');

    return $z !== '' && in_array($z, \DateTimeZone::listIdentifiers(), true) ? $z : 'UTC';
}

/** A UTC instant (RFC 3339 or SQL) as the wall-clock value of a datetime-local input in a zone ("2026-10-12T17:00"), or ''. */
function rivetRmmAlrToLocalInput(?string $utc, string $zone): string
{
    $t = $utc === null || $utc === '' ? false : strtotime(str_contains($utc, 'T') || str_ends_with($utc, 'Z') ? $utc : $utc . ' UTC');
    if ($t === false) {
        return '';
    }
    try {
        return (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i');
    } catch (\Throwable) {
        return '';
    }
}

/** A datetime-local value ("2026-10-12T22:00") in a zone as RFC 3339 UTC ("2026-10-13T03:00:00Z"), or null when it is not a date. */
function rivetRmmAlrLocalToUtc(string $local, string $zone): ?string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $local) !== 1) {
        return null;
    }
    try {
        $d = new \DateTimeImmutable(str_replace('T', ' ', $local) . ':00', new \DateTimeZone($zone));
    } catch (\Throwable) {
        return null;
    }

    return gmdate('Y-m-d\TH:i:s\Z', $d->getTimestamp());
}

/**
 * Which scope types a user may use for a maintenance window, with the reason for the ones they may not (Core decides again on save).
 *
 * @param array<string,bool> $perm rivetRmmAutoPerm()
 * @return array{allowed:list<string>,denied:array<string,string>}
 */
function rivetRmmAlrWindowScopes(array $perm): array
{
    $all = ['all', 'client', 'site', 'group', 'tag', 'device'];
    if ($perm['admin']) {
        return ['allowed' => $all, 'denied' => []];
    }
    $why = 'Only administrators can make a window for ';
    $denied = ['all' => $why . 'all devices.', 'site' => $why . 'a site.', 'group' => $why . 'a group.', 'tag' => $why . 'a tag.'];
    if (!$perm['alert_manage']) {
        return ['allowed' => [], 'denied' => $denied + ['client' => 'You cannot manage alerts.', 'device' => 'You cannot manage alerts.']];
    }

    return ['allowed' => ['client', 'device'], 'denied' => $denied];
}

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmAlrMaintenanceView(array $ctx, array $get): array
{
    $m = $ctx['mysqli'];
    $r = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', ['maintenance']);
    $windows = $r['ok'] ? (array) ($r['data']['data'] ?? []) : [];
    $vm = ['perm' => $ctx['perm'], 'csrf' => $ctx['csrf'], 'error' => $r['ok'] ? '' : ($r['error'] ?: 'The maintenance windows could not be read.'), 'windows' => $windows,
        'active' => array_values(array_filter($windows, static fn ($w) => $w['active_now'] && $w['enabled'])), 'scope_data' => rivetRmmAutoScopeData($m, $ctx['uid']),
        'scopes' => rivetRmmAlrWindowScopes($ctx['perm']), 'default_zone' => rivetRmmAlrDefaultZone(), 'edit' => null, 'form' => null, 'editing_missing' => false];
    $editId = (int) ($get['window_id'] ?? 0);
    if ($editId > 0) {
        foreach ($windows as $w) {
            if ((int) $w['window_id'] === $editId) {
                $vm['edit'] = $w;
            }
        }
        $vm['editing_missing'] = $vm['edit'] === null;
    }
    $vm['form'] = $vm['edit'] !== null ? 'edit' : (!empty($get['new']) ? 'new' : null);

    return $vm;
}

/** One zone select (common zones first). */
function rivetRmmAlrZoneSelect(string $id, string $name, string $selected, string $default): string
{
    $z = rivetRmmAlrZones($default);
    $opt = static fn (string $zone): string => '<option value="' . rmmH($zone) . '"' . ($zone === $selected ? ' selected' : '') . '>' . rmmH($zone) . '</option>';
    $o = '<select class="form-select" id="' . rmmH($id) . '" name="' . rmmH($name) . '"><optgroup label="Common">';
    foreach ($z['common'] as $zone) {
        $o .= $opt($zone);
    }
    $o .= '</optgroup><optgroup label="All time zones">';
    foreach ($z['all'] as $zone) {
        $o .= $opt($zone);
    }

    return $o . '</optgroup></select>';
}

/** @param array<string,mixed> $vm */
function rivetRmmAlrWindowForm(array $vm): string
{
    $w = $vm['edit'];
    $zone = $w['timezone'] ?? $vm['default_zone'];
    $kind = $w['kind'] ?? 'once';
    $sc = $vm['scopes'];
    $selType = $w['scope_type'] ?? ($sc['allowed'][0] ?? 'client');
    if (!in_array($selType, $sc['allowed'], true) && $w === null) {
        $selType = $sc['allowed'][0] ?? 'client';
    }
    $picker = rivetRmmAutoScopePicker('w', ['all', 'client', 'site', 'group', 'tag', 'device'], $selType, (int) ($w['scope_id'] ?? 0), $vm['scope_data'], 'Applies to');
    // scope types the user may not use are drawn disabled with the reason in the option text
    $picker = preg_replace_callback('~<option value="(all|client|site|group|tag|device)"( selected)?>([^<]*)</option>~', static function (array $mm) use ($sc): string {
        if (!isset($sc['denied'][$mm[1]]) || in_array($mm[1], $sc['allowed'], true)) {
            return $mm[0];
        }

        return '<option value="' . $mm[1] . '" disabled>' . $mm[3] . ' (not allowed for you)</option>';
    }, $picker) ?? $picker;
    $reasons = '';
    foreach ($sc['denied'] as $t => $why) {
        $reasons .= '<li>' . rmmH($why) . '</li>';
    }
    $weekly = array_filter(explode(',', (string) ($w['recur_days'] ?? '')), static fn ($d) => $d !== '');
    $days = '';
    foreach (RIVET_RMM_ALR_DAYS as $n => $label) {
        $days .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="w-day-' . $n . '" name="recur_days_w[]" value="' . $n . '"' . (($w['recur_freq'] ?? '') === 'weekly' && in_array((string) $n, $weekly, true) ? ' checked' : '')
            . '><label class="form-check-label" for="w-day-' . $n . '">' . $label . '</label></div>';
    }
    $csrf = $vm['csrf'];
    $self = '/agent/rmm_maintenance.php' . ($w !== null ? '?window_id=' . (int) $w['window_id'] : '?new=1');
    $once = $w !== null && $kind === 'once';
    $f = '<form method="post" action="/agent/post/' . RIVET_RMM_ALR_HANDLER . '.php" data-rmm-once="1" class="rmm-alr-form">' . rivetRmmAutoHidden($csrf, '/agent/rmm_maintenance.php')
        . '<input type="hidden" name="action" value="save_window"><input type="hidden" name="window_id" value="' . (int) ($w['window_id'] ?? 0) . '">'
        . '<div class="row g-3"><div class="col-md-6"><label class="form-label" for="w-name">Name</label><input class="form-control" id="w-name" name="name" maxlength="100" required value="' . rmmH($w['name'] ?? '') . '"></div>'
        . '<div class="col-md-6"><div class="form-label">What happens during the window</div>'
        . '<div class="form-check"><input class="form-check-input" type="radio" id="w-mode-mute" name="mode" value="mute"' . (($w['mode'] ?? 'mute') === 'mute' ? ' checked' : '') . '><label class="form-check-label" for="w-mode-mute"><strong>Mute.</strong> Alerts that would open are held back, and open after the window if the check is still bad. Escalation pauses. Scripts still run.</label></div>'
        . '<div class="form-check"><input class="form-check-input" type="radio" id="w-mode-suppress" name="mode" value="suppress"' . (($w['mode'] ?? '') === 'suppress' ? ' checked' : '') . '><label class="form-check-label" for="w-mode-suppress"><strong>Suppress.</strong> Results are recorded but not evaluated. Scheduled scripts for the device also pause until the window ends.</label></div></div>'
        . '<div class="col-md-6">' . $picker . ($reasons !== '' ? '<ul class="form-text small mb-0 ps-3">' . $reasons . '</ul>' : '') . '</div>'
        . '<div class="col-md-6"><label class="form-label" for="w-kind">Schedule</label><select class="form-select" data-company-tz="' . rmmH(rivetCompanyTimezone()) . '" id="w-kind" name="kind"><option value="once"' . ($kind === 'once' ? ' selected' : '') . '>One time</option><option value="recurring"' . ($kind === 'recurring' ? ' selected' : '') . '>Repeating</option></select></div>'
        . '<div class="col-12"><label class="form-label" for="w-tz">Time zone</label>' . rivetRmmAlrZoneSelect('w-tz', 'timezone', $zone, $vm['default_zone'])
        . '<div class="form-text">Times below are in this time zone. The company time zone is shown under each box.</div></div>'
        // one time
        . '<div class="col-12" data-rmm-when="#w-kind=once"><div class="row g-3"><div class="col-md-6"><label class="form-label" for="w-start">Starts</label><input class="form-control" type="datetime-local" id="w-start" name="starts_local" data-alr-local="start" value="'
        . rmmH($once ? rivetRmmAlrToLocalInput($w['starts_at'], $zone) : '') . '"><div class="form-text" data-alr-utc="start" aria-live="polite">' . ($once ? 'Company time: ' . rmmH(rivetFormatTime($w['starts_at'], 'utc')) : '') . '</div></div>'
        . '<div class="col-md-6"><label class="form-label" for="w-end">Ends</label><input class="form-control" type="datetime-local" id="w-end" name="ends_local" data-alr-local="end" value="'
        . rmmH($once ? rivetRmmAlrToLocalInput($w['ends_at'], $zone) : '') . '"><div class="form-text" data-alr-utc="end" aria-live="polite">' . ($once ? 'Company time: ' . rmmH(rivetFormatTime($w['ends_at'], 'utc')) : '') . '</div></div>'
        . '<div class="col-12 form-text">A one-time window can last up to 366 days.</div></div></div>'
        // repeating
        . '<div class="col-12" data-rmm-when="#w-kind=recurring"><div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label" for="recur_freq">Repeats</label><select class="form-select" id="recur_freq" name="recur_freq">'
        . implode('', array_map(static fn ($v, $t) => '<option value="' . $v . '"' . (($w['recur_freq'] ?? 'weekly') === $v ? ' selected' : '') . '>' . $t . '</option>', ['daily', 'weekly', 'monthly'], ['Every day', 'Every week', 'Every month'])) . '</select></div>'
        . '<div class="col-md-4"><label class="form-label" for="recur_interval">Every N (days, weeks or months)</label><input class="form-control" type="number" min="1" max="52" id="recur_interval" name="recur_interval" value="' . (int) ($w['recur_interval'] ?? 1) . '"></div>'
        . '<div class="col-md-2"><label class="form-label" for="local_start">Starts at</label><input class="form-control" type="time" id="local_start" name="local_start" value="' . rmmH($w['local_start'] ?? '22:00') . '"></div>'
        . '<div class="col-md-2"><label class="form-label" for="duration_min">Minutes long</label><input class="form-control" type="number" min="1" max="10080" id="duration_min" name="duration_min" value="' . (int) ($kind === 'recurring' ? $w['duration_min'] : 240) . '"></div>'
        . '<div class="col-12" data-rmm-when="#recur_freq=weekly"><div class="form-label" id="w-days-label">On these weekdays</div><div role="group" aria-labelledby="w-days-label">' . $days . '</div></div>'
        . '<div class="col-md-6" data-rmm-when="#recur_freq=monthly"><label class="form-label" for="recur_days_m">On these days of the month</label><input class="form-control" id="recur_days_m" name="recur_days_m" placeholder="1, 15, last" value="'
        . rmmH(($w['recur_freq'] ?? '') === 'monthly' ? (string) $w['recur_days'] : '') . '"><div class="form-text">Day numbers 1 to 31, or the word last. A day a month does not have uses the last day of that month.</div></div>'
        . '<div class="col-md-3"><label class="form-label" for="recur_from">Repeat from (optional)</label><input class="form-control" type="date" id="recur_from" name="recur_from" value="' . rmmH($w['recur_from'] ?? '') . '"></div>'
        . '<div class="col-md-3"><label class="form-label" for="recur_until">Repeat until (optional)</label><input class="form-control" type="date" id="recur_until" name="recur_until" value="' . rmmH($w['recur_until'] ?? '') . '"></div>'
        . '<div class="col-12 form-text">A start time is in the chosen time zone. When it is every 2 or more days, weeks or months, a start date is needed. A start time that does not exist on a clock-change day opens an hour later.</div></div></div>'
        . '<div class="col-12"><label class="form-label" for="w-note">Note (optional)</label><input class="form-control" id="w-note" name="note" maxlength="300" value="' . rmmH($w['note'] ?? '') . '"></div>'
        . '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="w-enabled" name="enabled" value="1"' . (($w['enabled'] ?? true) ? ' checked' : '') . '><label class="form-check-label" for="w-enabled">Window is on</label></div></div></div>'
        . '<div class="mt-3 d-flex flex-wrap" style="gap:6px"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>' . ($w === null ? 'Create window' : 'Save window') . '</button>'
        . '<a class="btn btn-outline-secondary" href="/agent/rmm_maintenance.php">Cancel</a></div></form>';

    return rivetRmmUiCard($w === null ? 'New maintenance window' : 'Edit ' . rmmH($w['name']), 'wrench', $f, '', '', false, 'alr-window-form');
}

/** @param array<string,mixed> $vm rivetRmmAlrMaintenanceView() */
function rivetRmmAlrMaintenancePage(array $vm): string
{
    $canCreate = $vm['scopes']['allowed'] !== [];
    $o = rivetRmmAutoHeader('Maintenance windows', 'wrench', $canCreate ? '<a class="btn btn-sm btn-primary" href="/agent/rmm_maintenance.php?new=1#alr-window-form"><i class="fas fa-plus me-1" aria-hidden="true"></i>New window</a>' : '',
        'While a window is open, alerts of the devices it covers are muted or not evaluated, so planned work does not page anyone.');
    if ($vm['error'] !== '') {
        $o .= '<div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle me-2" aria-hidden="true"></i>' . rmmH($vm['error']) . '</div>';
    }
    $names = $vm['scope_data'];
    // active now
    if ($vm['active'] !== []) {
        $li = '';
        foreach ($vm['active'] as $w) {
            $li .= '<li><strong>' . rmmH($w['name']) . '</strong>: ' . rmmH(rivetRmmAlrModeShort($w['mode'])) . ', ' . rmmH(rivetRmmAutoScopeLabel($w['scope_type'], $w['scope_id'], $names)) . ', ends ' . rmmH(rivetRmmAlrIn($w['current_ends_at'])) . '</li>';
        }
        $o .= '<div class="alert alert-info" role="status"><i class="fas fa-info-circle me-2" aria-hidden="true"></i><strong>Maintenance is open now.</strong><ul class="mb-0 mt-1">' . $li . '</ul></div>';
    } else {
        $o .= '<p class="text-muted" role="status"><i class="fas fa-check-circle me-1" aria-hidden="true"></i>No maintenance window is open.</p>';
    }
    if ($vm['editing_missing']) {
        $o .= '<div class="alert alert-warning" role="alert">That window does not exist, or it belongs to a department you cannot see.</div>';
    }
    if ($vm['form'] !== null) {
        $o .= $canCreate || $vm['form'] === 'edit' ? rivetRmmAlrWindowForm($vm) : '<div class="alert alert-warning" role="alert">Your role cannot create maintenance windows.</div>';
    }
    if ($vm['windows'] === []) {
        return $o . rivetRmmUiCard('Windows', 'wrench', rivetRmmUiEmpty('fas fa-wrench', 'No maintenance windows', $canCreate ? 'Create one before planned work so it does not raise alerts.' : 'An administrator or an alert manager can create one.'));
    }
    $rows = '';
    foreach ($vm['windows'] as $w) {
        $next = $w['active_now'] ? '<span class="badge text-bg-info"><i class="fas fa-play me-1" aria-hidden="true"></i>Open, ends ' . rmmH(rivetRmmAlrIn($w['current_ends_at'])) . '</span>'
            : ($w['next_starts_at'] !== null && $w['enabled'] ? 'Next ' . rivetRmmAlrWhen($w['next_starts_at']) : '<span class="text-muted">no upcoming start</span>');
        $edit = $canCreate ? '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_maintenance.php?window_id=' . (int) $w['window_id'] . '#alr-window-form"><i class="fas fa-edit me-1" aria-hidden="true"></i>Edit<span class="visually-hidden"> ' . rmmH($w['name']) . '</span></a> '
            . rivetRmmAutoActionForm(RIVET_RMM_ALR_HANDLER, 'delete_window', ['window_id' => (int) $w['window_id']], $vm['csrf'], '/agent/rmm_maintenance.php',
                '<i class="fas fa-trash me-1" aria-hidden="true"></i>Delete<span class="visually-hidden"> ' . rmmH($w['name']) . '</span>', 'btn btn-sm btn-outline-danger', 'Delete the maintenance window "' . $w['name'] . '"? Alerts it was holding back are no longer held.') : '';
        $note = rivetRmmPackStrip((string) $w['note']);
        $rows .= '<tr><td class="ps-3">' . rmmH($w['name']) . ($note['text'] !== '' ? '<div class="small text-muted">' . $note['badge'] . rmmH($note['text']) . '</div>' : rtrim($note['badge'])) . '</td><td>' . rmmH(rivetRmmAlrModeShort($w['mode'])) . '</td>'
            . '<td>' . rmmH(rivetRmmAutoScopeLabel($w['scope_type'], $w['scope_id'], $names)) . '</td><td class="rmm-wrap">' . rmmH(rivetRmmAlrScheduleWords($w)) . '</td><td>' . $next . '</td>'
            . '<td>' . ($w['enabled'] ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off')) . '</td><td class="rmm-table-actions">' . $edit . '</td></tr>';
    }

    return $o . rivetRmmUiCard('Windows (' . count($vm['windows']) . ')', 'wrench', '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><caption class="visually-hidden">Maintenance windows</caption>'
        . rivetRmmUiThead(['Name', 'Mode', 'Covers', 'Schedule', 'Open now / next start', 'On', 'Actions']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', true) . rivetRmmAutoConfirmModal();
}

// ------------------------------------------------------------------ escalation policies

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmAlrEscalationView(array $ctx, array $get): array
{
    $m = $ctx['mysqli'];
    $r = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', ['escalation_policies']);
    $policies = $r['ok'] ? (array) ($r['data']['data'] ?? []) : [];
    $s = rivetRmmAutoApi($m, $ctx['uid'], $ctx['name'], 'GET', ['alerting', 'settings']);
    $contact = '';
    $c = mysqli_query($m, 'SELECT config_rmm_escalation_contact AS c FROM settings WHERE company_id = 1');
    if ($c && ($row = mysqli_fetch_assoc($c))) {
        $contact = trim((string) ($row['c'] ?? ''));
    }
    $vm = ['perm' => $ctx['perm'], 'csrf' => $ctx['csrf'], 'error' => $r['ok'] ? '' : ($r['error'] ?: 'The escalation policies could not be read.'), 'policies' => $policies, 'dir' => rivetRmmAlrDirectory($m),
        'storm' => $s['ok'] ? (array) ($s['data']['storm'] ?? []) : null, 'contact' => $contact, 'edit' => null, 'form' => null, 'editing_missing' => false];
    $vm['scope_data'] = rivetRmmAutoScopeData($m, $ctx['uid']);   // the picker (administrators) and the scope labels of the table
    $editId = (int) ($get['policy_id'] ?? 0);
    if ($editId > 0) {
        foreach ($policies as $p) {
            if ((int) $p['policy_id'] === $editId) {
                $vm['edit'] = $p;
            }
        }
        $vm['editing_missing'] = $vm['edit'] === null;
    }
    $vm['form'] = $ctx['perm']['admin'] ? ($vm['edit'] !== null ? 'edit' : (!empty($get['new']) ? 'new' : null)) : null;

    return $vm;
}

/** One target row of the steps editor. @param array<string,mixed>|null $t */
function rivetRmmAlrTargetRow(array $dir, ?array $t, string $si, string $ti): string
{
    $type = $t['type'] ?? 'user';
    $ref = (string) ($t['ref'] ?? '');
    $n = 'steps[' . $si . '][targets][' . $ti . ']';
    $users = '<select class="form-select form-select-sm" name="' . $n . '[ref_user]" aria-label="Person">';
    foreach ($dir['users'] as $id => $name) {
        $users .= '<option value="' . $id . '"' . ($type === 'user' && $ref === (string) $id ? ' selected' : '') . '>' . rmmH($name) . '</option>';
    }
    if ($type === 'user' && $ref !== '' && !isset($dir['users'][(int) $ref])) {
        $users .= '<option value="' . (int) $ref . '" selected>user #' . (int) $ref . ' (inactive)</option>';
    }
    $users .= '</select>';
    $roles = '<select class="form-select form-select-sm" name="' . $n . '[ref_group]" aria-label="Role">';
    foreach ($dir['roles'] as $id => $name) {
        $roles .= '<option value="' . $id . '"' . ($type === 'group' && $ref === (string) $id ? ' selected' : '') . '>' . rmmH($name) . '</option>';
    }
    $roles .= '</select>';

    return '<div class="d-flex flex-wrap align-items-center mb-1 rmm-alr-target" data-alr-index="' . rmmH($ti) . '" style="gap:6px">'
        . '<select class="form-select form-select-sm w-auto" name="' . $n . '[type]" data-alr-type="1" aria-label="Who to notify"><option value="user"' . ($type === 'user' ? ' selected' : '') . '>A person</option>'
        . '<option value="group"' . ($type === 'group' ? ' selected' : '') . '>Everyone with a role</option><option value="email"' . ($type === 'email' ? ' selected' : '') . '>An email address</option></select>'
        . '<span data-alr-ref="user">' . $users . '</span><span data-alr-ref="group">' . $roles . '</span>'
        . '<span data-alr-ref="email"><input class="form-control form-control-sm" type="email" name="' . $n . '[ref_email]" maxlength="200" placeholder="noc@example.com" aria-label="Email address" value="' . rmmH($type === 'email' ? $ref : '') . '"></span>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary" data-alr-remove-target="1" aria-label="Remove this person or address"><i class="fas fa-times" aria-hidden="true"></i></button></div>';
}

/** One step row of the steps editor. @param array<string,mixed>|null $s */
function rivetRmmAlrStepRow(array $dir, ?array $s, string $si, bool $template = false): string
{
    $targets = '';
    foreach ((array) ($s['targets'] ?? []) as $j => $t) {
        $targets .= rivetRmmAlrTargetRow($dir, $t, $si, (string) $j);
    }
    $blank = '<template data-alr-target-row>' . str_replace('__j__', '__j__', rivetRmmAlrTargetRow($dir, null, $si, '__j__')) . '</template>';

    return '<div class="rmm-row"' . ($template ? '' : ' data-rmm-index="' . rmmH($si) . '"') . '><div class="row g-2 align-items-start"><div class="col-md-3"><label class="form-label small mb-1" for="alr-after-' . rmmH($si) . '">Notify after (minutes)</label>'
        . '<input class="form-control form-control-sm" type="number" min="0" max="10080" id="alr-after-' . rmmH($si) . '" name="steps[' . rmmH($si) . '][after_min]" value="' . (int) ($s['after_min'] ?? 0) . '" required>'
        . '<div class="form-text">Counted from when the alert opened. Never earlier than the step before.</div></div>'
        . '<div class="col-md-8"><div class="form-label small mb-1">Who is told</div><div data-alr-targets="1">' . $targets . '</div>' . $blank
        . '<button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-alr-add-target="1"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add a person, role or address</button></div>'
        . '<div class="col-md-1 text-md-end"><button type="button" class="btn btn-sm btn-outline-danger rmm-row-remove" data-rmm-remove="1" aria-label="Remove this step"><i class="fas fa-trash" aria-hidden="true"></i></button></div></div></div>';
}

/** @param array<string,mixed> $vm */
function rivetRmmAlrPolicyForm(array $vm): string
{
    $p = $vm['edit'];
    $dir = $vm['dir'];
    $steps = (array) ($p['steps'] ?? [['after_min' => 0, 'targets' => [['type' => 'user', 'ref' => (string) array_key_first($dir['users'])]]]]);
    $rows = '';
    foreach (array_values($steps) as $i => $s) {
        $rows .= rivetRmmAlrStepRow($dir, $s, (string) $i);
    }
    $picker = rivetRmmAutoScopePicker('p', ['all', 'client', 'site', 'group', 'tag', 'device'], $p['scope_type'] ?? 'all', (int) ($p['scope_id'] ?? 0), $vm['scope_data'], 'Applies to');
    $min = $p['min_severity'] ?? 'warn';

    return rivetRmmUiCard($p === null ? 'New escalation policy' : 'Edit ' . rmmH($p['name']), 'level-up-alt',
        '<p class="small text-muted">RivetIT tells people in the app and by email. A critical alert also opens a ticket. To reach a chat room or another system, use an event subscription on <code>rmm.alert.escalated</code>.</p>'
        . '<form method="post" action="/agent/post/' . RIVET_RMM_ALR_HANDLER . '.php" data-rmm-once="1">' . rivetRmmAutoHidden($vm['csrf'], '/agent/rmm_escalations.php')
        . '<input type="hidden" name="action" value="save_escalation"><input type="hidden" name="policy_id" value="' . (int) ($p['policy_id'] ?? 0) . '">'
        . '<div class="row g-3"><div class="col-md-6"><label class="form-label" for="p-name">Name</label><input class="form-control" id="p-name" name="name" maxlength="100" required value="' . rmmH($p['name'] ?? '') . '"></div>'
        . '<div class="col-md-6"><label class="form-label" for="p-min">Alerts it applies to</label><select class="form-select" id="p-min" name="min_severity"><option value="warn"' . ($min === 'warn' ? ' selected' : '') . '>Warning and critical alerts</option>'
        . '<option value="crit"' . ($min === 'crit' ? ' selected' : '') . '>Critical alerts only</option></select></div>'
        . '<div class="col-md-6">' . $picker . '<div class="form-text">When several policies fit a device, the most specific one is used (device, group, tag, site, department, all).</div></div>'
        . '<div class="col-md-3"><label class="form-label" for="p-rep">Repeat every (minutes)</label><input class="form-control" type="number" min="0" max="10080" id="p-rep" name="repeat_every_min" value="' . (int) ($p['repeat_every_min'] ?? 0) . '"><div class="form-text">0 for no repeat, otherwise 5 or more. The last step is repeated.</div></div>'
        . '<div class="col-md-3"><label class="form-label" for="p-repmax">Repeat at most</label><input class="form-control" type="number" min="0" max="1000" id="p-repmax" name="repeat_max" value="' . (int) ($p['repeat_max'] ?? 0) . '"><div class="form-text">0 repeats until someone acknowledges or the alert resolves.</div></div>'
        . '<div class="col-12"><div class="form-label">Steps</div><div id="alr-steps" data-rmm-repeat="1" data-rmm-max="10"><div data-rmm-rows="1">' . $rows . '</div><template data-rmm-row>' . rivetRmmAlrStepRow($dir, ['after_min' => 0, 'targets' => []], '__i__', true) . '</template>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary" data-rmm-add="#alr-steps"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add a step</button></div></div>'
        . '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="p-enabled" name="enabled" value="1"' . (($p['enabled'] ?? true) ? ' checked' : '') . '><label class="form-check-label" for="p-enabled">Policy is on</label></div></div></div>'
        . '<div class="mt-3 d-flex flex-wrap" style="gap:6px"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>' . ($p === null ? 'Create policy' : 'Save policy') . '</button><a class="btn btn-outline-secondary" href="/agent/rmm_escalations.php">Cancel</a></div></form>',
        '', '', false, 'alr-policy-form');
}

function rivetRmmAlrStormWords(array $s): string
{
    $part = static fn (int $max, int $win): string => $max <= 0 ? 'no limit' : $max . ' new alerts in ' . rivetRmmAlrMinutes(intdiv($win, 60) ?: 1);

    return 'All departments together: ' . $part((int) $s['storm_global_max'], (int) $s['storm_global_window_s']) . '. One department: ' . $part((int) $s['storm_client_max'], (int) $s['storm_client_window_s']) . '.';
}

/** @param array<string,mixed> $vm rivetRmmAlrEscalationView() */
function rivetRmmAlrEscalationPage(array $vm): string
{
    $admin = $vm['perm']['admin'];
    $o = rivetRmmAutoHeader('Escalation policies', 'level-up-alt', $admin ? '<a class="btn btn-sm btn-primary" href="/agent/rmm_escalations.php?new=1#alr-policy-form"><i class="fas fa-plus me-1" aria-hidden="true"></i>New policy</a>' : '',
        'An escalation policy says who is told when an alert stays open: after how many minutes, and whether it is repeated. Acknowledging or resolving an alert stops it.');
    if ($vm['error'] !== '') {
        $o .= '<div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle me-2" aria-hidden="true"></i>' . rmmH($vm['error']) . '</div>';
    }
    if ($vm['editing_missing']) {
        $o .= '<div class="alert alert-warning" role="alert">That policy does not exist, or it belongs to a department you cannot see.</div>';
    }
    if (!$admin) {
        $o .= '<p class="small text-muted"><i class="fas fa-lock me-1" aria-hidden="true"></i>Only administrators can create, change or delete escalation policies.</p>';
    }
    if ($vm['form'] !== null) {
        $o .= rivetRmmAlrPolicyForm($vm);
    }
    if ($vm['policies'] === []) {
        $o .= rivetRmmUiCard('Policies', 'level-up-alt', rivetRmmUiEmpty('fas fa-level-up-alt', 'No escalation policies', $admin ? 'Without one, alerts open but nobody is told that they stay open.' : 'An administrator can add one.'));
    } else {
        $rows = '';
        foreach ($vm['policies'] as $p) {
            $rep = (int) $p['repeat_every_min'] > 0 ? 'Every ' . rivetRmmAlrMinutes((int) $p['repeat_every_min']) . ((int) $p['repeat_max'] > 0 ? ', at most ' . (int) $p['repeat_max'] . ' times' : ', until acknowledged') : 'No repeat';
            $act = $admin ? '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_escalations.php?policy_id=' . (int) $p['policy_id'] . '#alr-policy-form"><i class="fas fa-edit me-1" aria-hidden="true"></i>Edit<span class="visually-hidden"> ' . rmmH($p['name']) . '</span></a> '
                . rivetRmmAutoActionForm(RIVET_RMM_ALR_HANDLER, 'delete_escalation', ['policy_id' => (int) $p['policy_id']], $vm['csrf'], '/agent/rmm_escalations.php', '<i class="fas fa-trash me-1" aria-hidden="true"></i>Delete<span class="visually-hidden"> ' . rmmH($p['name']) . '</span>',
                    'btn btn-sm btn-outline-danger', 'Delete the escalation policy "' . $p['name'] . '"? Alerts that are open now stop being escalated.') : '';
            $rows .= '<tr><td class="ps-3">' . rmmH($p['name']) . '</td><td>' . rmmH(rivetRmmAutoScopeLabel($p['scope_type'], $p['scope_id'], $vm['scope_data'])) . '</td>'
                . '<td>' . ($p['min_severity'] === 'crit' ? 'Critical only' : 'Warning and critical') . '</td><td class="rmm-wrap">' . rmmH(rivetRmmAlrStepsWords((array) $p['steps'], $vm['dir'])) . '</td><td>' . rmmH($rep) . '</td>'
                . '<td>' . ($p['enabled'] ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off')) . '</td><td class="rmm-table-actions">' . $act . '</td></tr>';
        }
        $o .= rivetRmmUiCard('Policies (' . count($vm['policies']) . ')', 'level-up-alt', '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><caption class="visually-hidden">Escalation policies</caption>'
            . rivetRmmUiThead(['Name', 'Applies to', 'Alerts', 'Steps', 'Repeat', 'On', 'Actions']) . '<tbody>' . $rows . '</tbody></table></div>', '', '', true);
    }
    $contact = $vm['contact'] !== '' ? '<span class="rmm-wrap">' . rmmH($vm['contact']) . '</span>' : '<span class="text-muted">not set</span>';
    $o .= rivetRmmUiCard('Fallback contact', 'user-shield', '<p class="mb-1">This contact is told when a step reaches nobody, and is copied on every critical alert so it never goes unnoticed: ' . $contact . '</p>'
        . ($admin ? '<a href="/admin/settings_endpoint_agent.php">Change it in Administration &gt; Endpoint agent</a>' : '<span class="small text-muted">Only administrators can change it.</span>'));
    if ($vm['storm'] !== null) {
        $o .= rivetRmmUiCard('Storm control', 'cloud-showers-heavy', '<p class="mb-1">' . rmmH(rivetRmmAlrStormWords($vm['storm'])) . '</p><p class="small text-muted mb-0">Beyond these limits new alerts are held back (not lost) and one summary alert says storm control is active. '
            . ($admin ? 'An administrator can change the limits through the alerting settings of the API.' : 'Only administrators can change the limits.') . '</p>');
    }

    return $o . rivetRmmAutoConfirmModal();
}

// ------------------------------------------------------------------ the Alerting tab of the asset page

const RIVET_RMM_ALR_OPS = ['gt' => 'above', 'gte' => 'at or above', 'lt' => 'below', 'lte' => 'at or below'];
const RIVET_RMM_ALR_PANEL_CHECKS = 30;

/** Thresholds in words: "Warning when above 80, critical when above 95. Needs 3 samples in a row and 10 min. Hysteresis 5." @param array<string,mixed>|null $t */
function rivetRmmAlrThresholdWords(?array $t): string
{
    if ($t === null) {
        return 'none';
    }
    $parts = [];
    foreach (['warn' => 'warning', 'crit' => 'critical'] as $k => $word) {
        if (isset($t[$k])) {
            $parts[] = $word . ' when ' . (RIVET_RMM_ALR_OPS[$t[$k]['op']] ?? $t[$k]['op']) . ' ' . $t[$k]['value'];
        }
    }
    $out = ucfirst(implode(', ', $parts));
    $out .= '. Needs ' . (int) ($t['for_samples'] ?? 1) . ' sample' . ((int) ($t['for_samples'] ?? 1) === 1 ? '' : 's') . ' in a row' . ((int) ($t['for_minutes'] ?? 0) > 0 ? ' and ' . (int) $t['for_minutes'] . ' min' : '')
        . '. Hysteresis ' . (int) ($t['hysteresis'] ?? 0) . '.';

    return $out;
}

/**
 * The model of the Alerting tab. Null when the tab must not be drawn (alerting off is decided by the caller; here: the device is not visible).
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 * @return array<string,mixed>|null
 */
function rivetRmmAlrPanelView(array $vm, \mysqli $mysqli): ?array
{
    $uid = (int) $vm['user_id'];
    $name = (string) ($GLOBALS['session_name'] ?? '');
    $dev = (int) $vm['device_id'];
    $a = rivetRmmAutoApi($mysqli, $uid, $name, 'GET', [$dev, 'alerting']);
    if (!$a['ok']) {
        return null;
    }
    $rmm = rivetRmmModule($mysqli);
    $authz = $rmm->authorizer();
    $client = (int) $vm['client_id'];
    $canAlert = $authz->allowed($uid, RmmAbility::ALERT_MANAGE, $client);
    $canDevice = $authz->allowed($uid, RmmAbility::DEVICE_MANAGE, $client);
    $parent = (array) ($a['data']['parent'] ?? []) ?: null;
    $candidates = [];
    if ($canAlert) {
        foreach ($rmm->readModel()->listDevices(['client_id' => $client], $authz->visibleClientIds($uid), 500, 0, false)['items'] as $d) {
            if ((int) $d['device_id'] !== $dev) {
                $candidates[(int) $d['device_id']] = (string) $d['hostname'];
            }
        }
    }
    $checks = (array) ($a['data']['checks'] ?? []);
    $thr = [];
    foreach (array_slice($checks, 0, RIVET_RMM_ALR_PANEL_CHECKS) as $c) {
        $t = rivetRmmAutoApi($mysqli, $uid, $name, 'GET', [$dev, 'checks', (string) $c['check_key'], 'thresholds']);
        $thr[(string) $c['check_key']] = $t['ok'] ? $t['data'] : null;
    }
    $al = rivetRmmAutoApi($mysqli, $uid, $name, 'GET', [$dev, 'alerts'], ['state' => 'active', 'limit' => '50']);

    return ['device_id' => $dev, 'asset_id' => (int) $vm['asset_id'], 'csrf' => '', 'can_alert' => $canAlert, 'can_device' => $canDevice, 'maintenance' => (array) ($a['data']['maintenance'] ?? []),
        'muted' => !empty($a['data']['muted']), 'suppressed' => !empty($a['data']['suppressed']), 'parent' => $parent, 'down_ancestor' => $a['data']['down_ancestor'] ?? null, 'candidates' => $candidates,
        'checks' => $checks, 'thresholds' => $thr, 'alerts' => $al['ok'] ? (array) ($al['data']['items'] ?? []) : [], 'can_ticket' => rivetRmmAlrCanTicket()];
}

/**
 * The hook rivetRmmUiTabs() calls: the Alerting tab of the asset page. '' (no tab) when the `alerting` switch is off or the device cannot be read.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmAlrPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (empty($vm['features']['alerting'])) {
        return '';
    }
    $pv = rivetRmmAlrPanelView($vm, $mysqli);
    if ($pv === null) {
        return '';
    }
    $pv['csrf'] = $csrf;
    $GLOBALS['rmm_auto_extra_js'] = array_values(array_unique(array_merge((array) ($GLOBALS['rmm_auto_extra_js'] ?? []), ['rmm_alr.js'])));

    return rivetRmmAlrPanelHtml($pv);
}

/** @param array<string,mixed> $pv rivetRmmAlrPanelView() */
function rivetRmmAlrPanelHtml(array $pv): string
{
    $csrf = $pv['csrf'];
    $dev = (int) $pv['device_id'];
    $ret = '/agent/asset_details.php?asset_id=' . (int) $pv['asset_id'] . '#rmm-alerting';
    $o = '<div id="rmm-alerting">';

    // (a) maintenance windows open for the device
    if ($pv['maintenance'] !== []) {
        $li = '';
        foreach ($pv['maintenance'] as $w) {
            $li .= '<li><strong>' . rmmH($w['name']) . '</strong>: ' . rmmH(rivetRmmAlrModeShort($w['mode'])) . ', ends ' . rmmH(rivetRmmAlrIn($w['current_ends_at'] ?? null)) . '</li>';
        }
        $o .= '<div class="alert alert-info" role="status"><i class="fas fa-info-circle me-2" aria-hidden="true"></i><strong>A maintenance window is open for this device.</strong> '
            . ($pv['suppressed'] ? 'Results are recorded but not evaluated.' : 'New alerts are held back until it ends.') . '<ul class="mb-0 mt-1">' . $li . '</ul></div>';
    } else {
        $o .= '<p class="text-muted small mb-3"><i class="fas fa-check-circle me-1" aria-hidden="true"></i>No maintenance window is open for this device. <a href="/agent/rmm_maintenance.php">Maintenance windows</a></p>';
    }

    // (b) parent device
    $p = $pv['parent'];
    $body = '';
    if ($p !== null) {
        $down = $pv['down_ancestor'];
        $body .= '<p class="mb-2">This device depends on <strong>' . rmmH($p['parent_hostname'] !== '' ? $p['parent_hostname'] : 'device #' . $p['parent_device_id']) . '</strong>. '
            . ($down !== null ? rivetRmmUiPill('crit', 'Parent is offline') . ' Alerts of this device do not open while the parent is offline.' : rivetRmmUiPill('ok', 'Parent is reachable') . ' Alerts of this device do not open while the parent is offline.') . '</p>';
    } else {
        $body .= '<p class="mb-2 text-muted">No parent device. Choose one when this device sits behind another (a switch, a router, a hypervisor), so its alerts do not pile up when that one is down.</p>';
    }
    if ($pv['can_alert']) {
        $opts = '';
        foreach ($pv['candidates'] as $id => $n) {
            $opts .= '<option value="' . $id . '"' . ($p !== null && (int) $p['parent_device_id'] === $id ? ' selected' : '') . '>' . rmmH($n) . '</option>';
        }
        if ($pv['candidates'] === []) {
            $body .= '<p class="small text-muted mb-0">There is no other device in this department to depend on.</p>';
        } else {
            $body .= '<form method="post" action="/agent/post/' . RIVET_RMM_ALR_HANDLER . '.php" class="row g-2 align-items-end">' . rivetRmmAutoHidden($csrf, $ret) . '<input type="hidden" name="action" value="set_parent"><input type="hidden" name="device_id" value="' . $dev . '">'
                . '<div class="col-md-6"><label class="form-label small mb-1" for="alr-parent">Depends on</label><select class="form-select form-select-sm" id="alr-parent" name="parent_device_id">' . $opts . '</select></div>'
                . '<div class="col-auto"><button type="submit" class="btn btn-sm btn-primary">Save parent</button></div></form>';
        }
        if ($p !== null) {
            $body .= '<div class="mt-2">' . rivetRmmAutoActionForm(RIVET_RMM_ALR_HANDLER, 'clear_parent', ['device_id' => $dev], $csrf, $ret, 'Remove the parent', 'btn btn-sm btn-outline-secondary') . '</div>';
        }
    }
    $o .= rivetRmmUiCard('Depends on', 'sitemap', $body);

    // (c) per check alerting state
    $rows = '';
    foreach ($pv['checks'] as $c) {
        $why = rivetRmmAlrWhyNoAlert($c);
        $rows .= '<tr><th scope="row" class="ps-3 fw-normal"><code class="rmm-mono">' . rmmH($c['check_key']) . '</code></th><td>'
            . rivetRmmUiPill(['ok' => 'ok', 'warn' => 'warn', 'fail' => 'crit'][$c['status']] ?? 'off', ['ok' => 'Passing', 'warn' => 'Warning', 'fail' => 'Failing'][$c['status']] ?? 'Unknown') . '</td>'
            . '<td>' . rmmH(rivetRmmAlrTierWords($c['tier'])) . ($c['pending_tier'] !== null && $c['pending_tier'] !== $c['tier'] && $c['pending_tier'] !== 'ok' ? '<div class="small text-muted">moving to ' . rmmH(rivetRmmAlrTierWords($c['pending_tier'])) . '</div>' : '') . '</td>'
            . '<td>' . ($c['last_value'] === null ? '<span class="text-muted">none</span>' : rmmH((string) $c['last_value'])) . '</td><td>' . ($c['flapping'] ? rivetRmmUiPill('warn', 'Flapping') : '<span class="text-muted">no</span>') . '</td>'
            . '<td class="rmm-wrap">' . ($c['alert_id'] !== null ? '<a href="/agent/rmm_agent_alerts.php?alert_id=' . (int) $c['alert_id'] . '">Alert #' . (int) $c['alert_id'] . '</a>' : ($why !== '' ? rmmH($why) : '<span class="text-muted">no alert</span>')) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Checks and alerting', 'heartbeat', $pv['checks'] === [] ? '<p class="mb-0 text-muted">This device has not reported any check yet.</p>'
        : '<div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Alerting state of each check</caption>' . rivetRmmUiThead(['Check', 'Status', 'Threshold level', 'Last value', 'Flapping', 'Alert'])
            . '<tbody>' . $rows . '</tbody></table></div>', '', '', true);

    // (d) thresholds editor
    $o .= rivetRmmUiCard('Thresholds', 'sliders-h', rivetRmmAlrThresholdsHtml($pv, $ret));

    // (e) open alerts
    $vmA = ['perm' => ['alert_manage' => $pv['can_alert']], 'csrf' => $csrf, 'can_ticket' => $pv['can_ticket']];
    $ex = rivetRmmAlrEnrichForPanel($pv['alerts']);
    $arows = '';
    foreach ($pv['alerts'] as $a) {
        $arows .= '<tr><td class="ps-3">' . rivetRmmAlrSevPill($a['severity']) . '</td><td>' . rivetRmmAutoStatePill($a['state']) . '</td><td><code class="rmm-mono">' . rmmH($a['check_key']) . '</code></td>'
            . '<td class="rmm-wrap"><a href="/agent/rmm_agent_alerts.php?alert_id=' . (int) $a['alert_id'] . '">' . rmmH($a['message'] !== '' ? $a['message'] : 'Open the alert') . '</a></td><td>' . rivetRmmAlrWhen($a['opened_at']) . '</td>'
            . '<td class="rmm-table-actions">' . rivetRmmAlrAlertButtons($a, $vmA, $ret, $ex[(int) $a['alert_id']] ?? null) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Open alerts of this device', 'bell', $pv['alerts'] === [] ? '<p class="mb-0 text-muted"><i class="fas fa-check-circle me-1" aria-hidden="true"></i>No open alerts.</p>'
        : '<div id="alr-msg" class="alert d-none" role="status" aria-live="polite"></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Open agent alerts of this device</caption>'
            . rivetRmmUiThead(['Severity', 'State', 'Check', 'Message', 'Opened', 'Actions']) . '<tbody>' . $arows . '</tbody></table></div>', '', '', true);

    return $o . '</div>';
}

/** alert id => ticket id for the alerts of the panel (the edition's rmm_alerts rows of the ids the API returned). @return array<int,int> */
function rivetRmmAlrEnrichForPanel(array $alerts): array
{
    $mysqli = $GLOBALS['mysqli'] ?? null;
    if (!$mysqli instanceof \mysqli || $alerts === []) {
        return [];
    }
    $out = [];
    foreach (rivetRmmAlrEnrich($mysqli, $alerts) as $id => $e) {
        if ($e['ticket_id'] !== null) {
            $out[$id] = $e['ticket_id'];
        }
    }

    return $out;
}

/** @param array<string,mixed> $pv */
function rivetRmmAlrThresholdsHtml(array $pv, string $ret): string
{
    $csrf = $pv['csrf'];
    $dev = (int) $pv['device_id'];
    if ($pv['checks'] === []) {
        return '<p class="mb-0 text-muted">Thresholds appear here once the device reports checks.</p>';
    }
    $o = '<p class="small text-muted">A check that measures a number (percent used, latency, days left) can turn into a warning or critical alert when the value crosses a limit. '
        . 'You can change the limits for this device only; the check definition keeps the original.</p>';
    foreach ($pv['checks'] as $c) {
        $key = (string) $c['check_key'];
        $t = $pv['thresholds'][$key] ?? null;
        $o .= '<div class="rmm-row"><div class="d-flex flex-wrap align-items-center" style="gap:6px"><strong><code class="rmm-mono">' . rmmH($key) . '</code></strong>';
        if ($t === null || ($t['defined'] ?? null) === null) {
            $o .= '</div><p class="small text-muted mb-0">This check declares no thresholds; add them to the check definition (a policy check template or the global checks list).</p></div>';
            continue;
        }
        $eff = $t['effective'] ?? null;
        $ov = $t['override'] ?? null;
        $o .= ($ov !== null ? rivetRmmUiPill('warn', 'Changed for this device') : '') . '</div>'
            . '<div class="small mb-2"><span class="text-muted">Defined:</span> ' . rmmH(rivetRmmAlrThresholdWords($t['defined'])) . '<br><span class="text-muted">In effect:</span> ' . rmmH(rivetRmmAlrThresholdWords($eff)) . '</div>';
        if (!$pv['can_device']) {
            $o .= '</div>';
            continue;
        }
        $form = $eff ?? $t['defined'];
        $opt = static function (string $name, string $cur, string $label) use ($key): string {
            $s = '<select class="form-select form-select-sm" name="' . $name . '" aria-label="' . rmmH($label) . '">';
            foreach (RIVET_RMM_ALR_OPS as $v => $w) {
                $s .= '<option value="' . $v . '"' . ($cur === $v ? ' selected' : '') . '>' . $w . '</option>';
            }

            return $s . '</select>';
        };
        $id = 'thr-' . md5($key);
        $o .= '<form method="post" action="/agent/post/' . RIVET_RMM_ALR_HANDLER . '.php">' . rivetRmmAutoHidden($csrf, $ret) . '<input type="hidden" name="action" value="save_thresholds"><input type="hidden" name="device_id" value="' . $dev . '"><input type="hidden" name="check_key" value="' . rmmH($key) . '">'
            . '<div class="row g-2 align-items-end">'
            . '<div class="col-6 col-md-3"><div class="form-label small mb-1">Warning when</div><div class="d-flex" style="gap:4px">' . $opt('warn_op', $form['warn']['op'] ?? 'gt', 'Warning comparison for ' . $key)
            . '<input class="form-control form-control-sm" type="number" step="1" name="warn_value" aria-label="Warning value for ' . rmmH($key) . '" value="' . rmmH((string) ($form['warn']['value'] ?? '')) . '"></div></div>'
            . '<div class="col-6 col-md-3"><div class="form-label small mb-1">Critical when</div><div class="d-flex" style="gap:4px">' . $opt('crit_op', $form['crit']['op'] ?? 'gt', 'Critical comparison for ' . $key)
            . '<input class="form-control form-control-sm" type="number" step="1" name="crit_value" aria-label="Critical value for ' . rmmH($key) . '" value="' . rmmH((string) ($form['crit']['value'] ?? '')) . '"></div></div>'
            . '<div class="col-4 col-md-2"><label class="form-label small mb-1" for="' . $id . '-h">Hysteresis</label><input class="form-control form-control-sm" type="number" min="0" max="1000" id="' . $id . '-h" name="hysteresis" value="' . (int) ($form['hysteresis'] ?? 0) . '"></div>'
            . '<div class="col-4 col-md-2"><label class="form-label small mb-1" for="' . $id . '-s">Samples in a row</label><input class="form-control form-control-sm" type="number" min="1" max="100" id="' . $id . '-s" name="for_samples" value="' . (int) ($form['for_samples'] ?? 1) . '"></div>'
            . '<div class="col-4 col-md-2"><label class="form-label small mb-1" for="' . $id . '-m">For minutes</label><input class="form-control form-control-sm" type="number" min="0" max="1440" id="' . $id . '-m" name="for_minutes" value="' . (int) ($form['for_minutes'] ?? 0) . '"></div></div>'
            . '<div class="form-text mb-2">Leave a value empty for no limit at that level. Hysteresis keeps a level until the value is that far back on the safe side. The check must hold a level for the samples and minutes before it counts.</div>'
            . '<button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>Save for this device<span class="visually-hidden"> (' . rmmH($key) . ')</span></button></form>';
        if ($ov !== null) {
            $o .= '<div class="mt-2">' . rivetRmmAutoActionForm(RIVET_RMM_ALR_HANDLER, 'clear_thresholds', ['device_id' => $dev, 'check_key' => $key], $csrf, $ret,
                'Remove the override<span class="visually-hidden"> (' . rmmH($key) . ')</span>', 'btn btn-sm btn-outline-secondary', 'Go back to the limits of the check definition for ' . $key . ' on this device?') . '</div>';
        }
        $o .= '</div>';
    }

    return $o;
}

// ------------------------------------------------------------------ form posts -> typed JSON bodies for Core

/** A whole number from a posted value, or null when it is empty or not a plain integer. */
function rivetRmmAlrIntOf(mixed $v): ?int
{
    return is_string($v) && preg_match('/^-?\d{1,12}$/', trim($v)) === 1 ? (int) trim($v) : null;
}

/**
 * The body of a maintenance window from the form. One-time local start and end are converted from the chosen zone to RFC 3339 UTC.
 *
 * @param array<string,mixed> $post
 * @return array{0:?array<string,mixed>,1:string} [body, error]
 */
function rivetRmmAlrWindowBody(array $post, array $allowedScopes = ['all', 'client', 'site', 'group', 'tag', 'device']): array
{
    $s = static fn (string $k, int $max = 300): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $zone = $s('timezone', 64);
    if ($zone === '' || !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
        return [null, 'Choose a time zone from the list.'];
    }
    $type = $s('w_type', 20);
    if (!in_array($type, ['all', 'client', 'site', 'group', 'tag', 'device'], true)) {
        return [null, 'Choose what the window covers.'];
    }
    $scopeId = $type === 'all' ? 0 : (rivetRmmAlrIntOf($post['w_id_' . $type] ?? null) ?? 0);
    if ($type !== 'all' && $scopeId < 1) {
        return [null, 'Choose which ' . ['client' => 'department', 'site' => 'site', 'group' => 'group', 'tag' => 'tag', 'device' => 'device'][$type] . ' the window covers.'];
    }
    $kind = $s('kind', 12) === 'recurring' ? 'recurring' : 'once';
    $body = ['name' => $s('name', 100), 'mode' => $s('mode', 10) === 'suppress' ? 'suppress' : 'mute', 'scope_type' => $type, 'scope_id' => $scopeId, 'kind' => $kind, 'timezone' => $zone,
        'note' => $s('note'), 'enabled' => !empty($post['enabled'])];
    if ($kind === 'once') {
        $a = rivetRmmAlrLocalToUtc($s('starts_local', 20), $zone);
        $b = rivetRmmAlrLocalToUtc($s('ends_local', 20), $zone);
        if ($a === null || $b === null) {
            return [null, 'Enter a start and an end date and time for a one-time window.'];
        }
        $body['starts_at'] = $a;
        $body['ends_at'] = $b;

        return [$body, ''];
    }
    $freq = $s('recur_freq', 10);
    $body['recur_freq'] = in_array($freq, ['daily', 'weekly', 'monthly'], true) ? $freq : 'daily';
    $body['recur_interval'] = rivetRmmAlrIntOf($post['recur_interval'] ?? null) ?? 1;
    $body['local_start'] = $s('local_start', 5);
    $dur = rivetRmmAlrIntOf($post['duration_min'] ?? null);
    if ($dur === null) {
        return [null, 'Enter how many minutes the window lasts.'];
    }
    $body['duration_min'] = $dur;
    if ($freq === 'weekly') {
        $days = [];
        foreach ((array) ($post['recur_days_w'] ?? []) as $d) {
            $n = rivetRmmAlrIntOf($d);
            if ($n !== null && $n >= 1 && $n <= 7) {
                $days[$n] = $n;
            }
        }
        ksort($days);
        $body['recur_days'] = implode(',', $days);
    } elseif ($freq === 'monthly') {
        $body['recur_days'] = strtolower(preg_replace('/[\s;]+/', ',', $s('recur_days_m', 100)) ?? '');
    }
    foreach (['recur_from', 'recur_until'] as $k) {
        $v = $s($k, 10);
        $body[$k] = $v === '' ? null : $v;
    }

    return [$body, ''];
}

/**
 * The body of an escalation policy from the form.
 *
 * @param array<string,mixed> $post
 * @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmAlrPolicyBody(array $post): array
{
    $s = static fn (string $k, int $max = 300): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $type = $s('p_type', 20);
    if (!in_array($type, ['all', 'client', 'site', 'group', 'tag', 'device'], true)) {
        return [null, 'Choose what the policy applies to.'];
    }
    $scopeId = $type === 'all' ? 0 : (rivetRmmAlrIntOf($post['p_id_' . $type] ?? null) ?? 0);
    if ($type !== 'all' && $scopeId < 1) {
        return [null, 'Choose which ' . ['client' => 'department', 'site' => 'site', 'group' => 'group', 'tag' => 'tag', 'device' => 'device'][$type] . ' the policy applies to.'];
    }
    $rep = rivetRmmAlrIntOf($post['repeat_every_min'] ?? null);
    $max = rivetRmmAlrIntOf($post['repeat_max'] ?? null);
    $steps = [];
    $raw = is_array($post['steps'] ?? null) ? $post['steps'] : [];
    ksort($raw);
    foreach ($raw as $st) {
        if (!is_array($st)) {
            continue;
        }
        $after = rivetRmmAlrIntOf($st['after_min'] ?? null);
        $targets = [];
        $rt = is_array($st['targets'] ?? null) ? $st['targets'] : [];
        ksort($rt);
        foreach ($rt as $t) {
            $tt = is_array($t) ? (string) ($t['type'] ?? '') : '';
            if ($tt === 'user' || $tt === 'group') {
                $id = rivetRmmAlrIntOf($t['ref_' . $tt] ?? null);
                if ($id !== null && $id > 0) {
                    $targets[] = ['type' => $tt, 'ref' => (string) $id];
                }
            } elseif ($tt === 'email') {
                $mail = is_string($t['ref_email'] ?? null) ? trim($t['ref_email']) : '';
                if ($mail !== '') {
                    $targets[] = ['type' => 'email', 'ref' => $mail];
                }
            }
        }
        if ($after === null) {
            return [null, 'Every step needs the number of minutes after which it notifies.'];
        }
        $steps[] = ['after_min' => $after, 'targets' => $targets];
    }

    return [['name' => $s('name', 100), 'enabled' => !empty($post['enabled']), 'scope_type' => $type, 'scope_id' => $scopeId, 'min_severity' => $s('min_severity', 5) === 'crit' ? 'crit' : 'warn',
        'repeat_every_min' => $rep ?? 0, 'repeat_max' => $max ?? 0, 'steps' => $steps], ''];
}

/**
 * The threshold override from the form. A limit left empty is removed only when the definition has one (null removes it in Core's merge).
 *
 * @param array<string,mixed> $post
 * @param array<string,mixed>|null $defined the check's defined thresholds
 * @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmAlrThresholdBody(array $post, ?array $defined): array
{
    $out = [];
    foreach (['warn', 'crit'] as $tier) {
        $raw = is_string($post[$tier . '_value'] ?? null) ? trim($post[$tier . '_value']) : '';
        $op = is_string($post[$tier . '_op'] ?? null) ? $post[$tier . '_op'] : '';
        if ($raw === '') {
            if ($defined !== null && isset($defined[$tier])) {
                $out[$tier] = null;
            }
            continue;
        }
        $v = rivetRmmAlrIntOf($raw);
        if ($v === null || !in_array($op, ['gt', 'gte', 'lt', 'lte'], true)) {
            return [null, 'Limits are whole numbers, for example 80.'];
        }
        $out[$tier] = ['op' => $op, 'value' => $v];
    }
    foreach (['hysteresis', 'for_samples', 'for_minutes'] as $k) {
        $v = rivetRmmAlrIntOf($post[$k] ?? null);
        if ($v === null) {
            return [null, 'Hysteresis, samples and minutes are whole numbers.'];
        }
        $out[$k] = $v;
    }
    if ((!isset($out['warn']) || $out['warn'] === null) && (!isset($out['crit']) || $out['crit'] === null)) {
        return [null, 'Set at least a warning or a critical limit.'];
    }

    return [$out, ''];
}
