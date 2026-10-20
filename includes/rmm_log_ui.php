<?php

/*
 * RMM log collection pages (RivetCore 1.0.0-rc.11, Phase 8, sub-switch `logs`, OFF by default): the Logs search page (agent/rmm_logs.php), the Log rules page
 * (agent/rmm_log_rules.php) and the Logs tab of the asset page (rivetRmmLogPanelSection()).
 *
 * ONE AUTHORIZATION PATH. Every search and every rule write is a call into RivetCore\Rmm\Logs\LogActions as the signed-in user ($rmm->logActions() with
 * rivetRmmPrincipal()). Core decides who may read (rmm.device.view + rmm.job.run_saved), who may manage rules (rmm.alert.manage), which clients a search spans and
 * answers the same 404 for another client's device as for a missing one. Nothing here builds a client filter of its own; hiding a control is cosmetic.
 *
 * The only direct reads are lookups limited to ids Core just returned or to the user's own departments: the asset id of the devices on the page being shown, the
 * oldest stored entry (users with access to every department only), a grouped count of the failing log checks, and the departments for the rule form.
 *
 * The view-model functions (rivetRmmLog*View) return arrays and are what the tests call; the renderers (rivetRmmLog*Page / *Html) return HTML strings.
 */

require_once __DIR__ . '/rivet_time.php';
require_once __DIR__ . '/rmm_automation.php';

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\Technician\ActionResult;

const RIVET_RMM_LOG_HANDLER = 'rmm_logs';
const RIVET_RMM_LOG_SIZES = [50, 100, 200];
const RIVET_RMM_LOG_PREVIEW = 160;
/** Core stores about this many bytes per entry (RemoteSettings::summary()). */
const RIVET_RMM_LOG_BYTES_PER_ENTRY = 600;
const RIVET_RMM_LOG_RANGES = ['1h' => ['Last hour', 3600], '24h' => ['Last 24 hours', 86400], '7d' => ['Last 7 days', 604800], '30d' => ['Last 30 days', 2592000], '90d' => ['Last 90 days', 7776000]];

// ------------------------------------------------------------------ the gate

/**
 * Role-level abilities for the log pages (a department of 0 asks "anywhere"; Core decides per department on every call).
 *
 * @return array{view:bool,read:bool,rules:bool,admin:bool}
 */
function rivetRmmLogPerm(\mysqli $mysqli, int $userId): array
{
    $authz = rivetRmmModule($mysqli)->authorizer();
    $view = $authz->allowed($userId, RmmAbility::DEVICE_VIEW, 0);

    return [
        'view' => $view,
        'read' => $view && $authz->allowed($userId, RmmAbility::JOB_RUN_SAVED, 0),
        'rules' => $view && $authz->allowed($userId, RmmAbility::ALERT_MANAGE, 0),
        'admin' => $authz->allowed($userId, RmmAbility::ADMIN, 0),
    ];
}

/**
 * The first thing a log page does (after inc_all.php and enforceUserPermission('module_rmm')): module on, `logs` switch on, and the ability for the page ('read' for
 * the search, 'rules' for the rules). Returns the page context, or null after rendering the denial (the caller then stops: `return;`). With the module or the switch
 * off nothing asks the database anything.
 *
 * @return array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli}|null
 */
function rivetRmmLogPage(\mysqli $mysqli, string $need, int $userId, string $userName): ?array
{
    if (!rivetRmmEnabled($mysqli)) {
        itflow_render_denied('The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.', 'RMM module off');

        return null;
    }
    if (!rivetRmmFeatureOn('logs', $mysqli)) {
        itflow_render_denied('Log collection is switched off. An administrator can turn it on under Administration > Endpoint agent, where the storage warning is shown first.', 'Switched off');

        return null;
    }
    $perm = rivetRmmLogPerm($mysqli, $userId);
    if (!$perm[$need === 'rules' ? 'rules' : 'read']) {
        itflow_render_denied($need === 'rules' ? 'Your role cannot manage log rules.' : 'Your role cannot read collected logs.', 'No access');

        return null;
    }
    $GLOBALS['rmm_auto_scripts'] = true;   // the confirm dialog of js/rmm_automation.js
    $GLOBALS['rmm_log_scripts'] = true;    // js/rmm_logs.js

    return ['uid' => $userId, 'name' => $userName, 'csrf' => (string) ($_SESSION['csrf_token'] ?? ''), 'perm' => $perm, 'mysqli' => $mysqli];
}

/** A technician-API style result array from an ActionResult (what rivetRmmAutoDone() takes). @return array{ok:bool,status:int,error:string,code:string,data:array<string,mixed>} */
function rivetRmmLogResult(ActionResult $r): array
{
    return ['ok' => $r->ok, 'status' => $r->http, 'error' => $r->ok ? '' : $r->message, 'code' => $r->code, 'data' => $r->data];
}

// ------------------------------------------------------------------ small words

function rivetRmmLogLevelPill(string $level): string
{
    return match ($level) {
        'critical' => rivetRmmUiPill('crit', 'Critical'),
        'error' => rivetRmmUiPill('crit', 'Error'),
        'warning' => rivetRmmUiPill('warn', 'Warning'),
        'info' => rivetRmmUiPill('info', 'Info'),
        default => rivetRmmUiPill('off', ucfirst($level)),
    };
}

/** @return array<string,string> */
function rivetRmmLogLevelNames(): array
{
    return ['critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Information', 'debug' => 'Debug'];
}

/** "3 min ago" with the absolute UTC time (with seconds) under it. */
function rivetRmmLogWhen(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '<span class="text-muted">never</span>';
    }

    return rivetRmmUiTime($iso) . '<div class="small text-muted rmm-log-abs">' . rmmH(str_replace('T', ' ', rtrim($iso, 'Z')) . ' UTC') . '</div>';
}

function rivetRmmLogCount(int $n, bool $capped = false): string
{
    return ($capped ? 'more than ' : '') . number_format($n);
}

// ------------------------------------------------------------------ filters

/**
 * The search filters from a query string, clamped to what the page offers. `$prefix` is '' on the Logs page and 'lg_' on the asset page (so the Software tab's own
 * parameters are left alone). Nothing here is trusted further than being a string of bounded length: Core validates again.
 *
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmLogFilters(array $get, string $prefix = '', bool $deviceScope = false, ?int $now = null): array
{
    $now ??= time();
    $str = static function (string $k, int $max = 200) use ($get, $prefix): string {
        $v = $get[$prefix . $k] ?? '';

        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    };
    $levels = array_keys(rivetRmmLogLevelNames());
    $ranges = $deviceScope ? array_keys(RIVET_RMM_LOG_RANGES) : ['1h', '24h', '7d'];
    $range = $str('range', 10);
    $range = in_array($range, $ranges, true) || $range === 'custom' ? $range : '24h';
    $fromRaw = $str('from', 16);
    $toRaw = $str('to', 16);
    $time = static function (string $v): ?int {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $v) !== 1) {
            return null;
        }
        $t = strtotime(str_replace(' ', 'T', $v) . ':00 UTC');

        return $t === false ? null : $t;
    };
    $notice = '';
    if ($range === 'custom') {
        $to = $time($toRaw) ?? $now;
        $from = $time($fromRaw) ?? ($to - 86400);
        if ($from >= $to) {
            $notice = 'The start must be before the end, so the last 24 hours are shown instead.';
            $range = '24h';
        }
    }
    if ($range !== 'custom') {
        $to = $now;
        $from = $now - (RIVET_RMM_LOG_RANGES[$range][1] ?? 86400);
    }
    $size = (int) $str('size', 4);
    $size = in_array($size, RIVET_RMM_LOG_SIZES, true) ? $size : 50;
    $code = $str('code', 12);
    $level = $str('level', 12);

    return [
        'prefix' => $prefix,
        'range' => $range,
        'from_in' => $range === 'custom' ? gmdate('Y-m-d\TH:i', $from) : '',
        'to_in' => $range === 'custom' ? gmdate('Y-m-d\TH:i', $to) : '',
        'from' => $from,
        'to' => $to,
        'device_id' => $deviceScope ? 0 : max(0, (int) ($get['device_id'] ?? 0)),
        'client_id' => $deviceScope ? 0 : max(0, (int) ($get['client_id'] ?? 0)),
        'level' => in_array($level, $levels, true) ? $level : '',
        'source' => $str('source', 100),
        'code' => preg_match('/^\d{1,10}$/', $code) === 1 ? $code : '',
        'q' => $str('q', 200),
        'size' => $size,
        'page' => max(1, min((int) (100000 / $size) + 1, (int) $str('page', 6))),
        'notice' => $notice,
        'code_bad' => $code !== '' && preg_match('/^\d{1,10}$/', $code) !== 1,
    ];
}

/** The filters in the shape LogActions takes. @param array<string,mixed> $f @return array<string,mixed> */
function rivetRmmLogCoreFilters(array $f): array
{
    $out = ['from' => gmdate('Y-m-d\TH:i:s\Z', (int) $f['from']), 'to' => gmdate('Y-m-d\TH:i:s\Z', (int) $f['to'])];
    foreach (['device_id', 'client_id'] as $k) {
        if ((int) $f[$k] > 0) {
            $out[$k] = (int) $f[$k];
        }
    }
    foreach (['level', 'source', 'code', 'q'] as $k) {
        if ($f[$k] !== '') {
            $out[$k] = $f[$k];
        }
    }

    return $out;
}

/**
 * A link to the search with some filters changed.
 *
 * @param array<string,mixed> $f rivetRmmLogFilters()
 * @param array<string,mixed> $over replacement values (same keys as $f)
 */
function rivetRmmLogUrl(array $f, array $over = [], string $base = '/agent/rmm_logs.php', string $anchor = ''): string
{
    $f = $over + $f;
    $p = (string) $f['prefix'];
    $q = [];
    if ($f['range'] !== '24h') {
        $q[$p . 'range'] = $f['range'];
    }
    if ($f['range'] === 'custom') {
        $q[$p . 'from'] = $f['from_in'];
        $q[$p . 'to'] = $f['to_in'];
    }
    foreach (['device_id', 'client_id'] as $k) {
        if ((int) $f[$k] > 0 && $p === '') {
            $q[$k] = (int) $f[$k];
        }
    }
    foreach (['level', 'source', 'code', 'q'] as $k) {
        if ($f[$k] !== '') {
            $q[$p . $k] = $f[$k];
        }
    }
    if ((int) $f['size'] !== 50) {
        $q[$p . 'size'] = (int) $f['size'];
    }
    if ((int) $f['page'] > 1) {
        $q[$p . 'page'] = (int) $f['page'];
    }
    $glue = str_contains($base, '?') ? '&' : '?';

    return $base . ($q === [] ? '' : $glue . http_build_query($q)) . $anchor;
}

// ------------------------------------------------------------------ search: view-model

/**
 * The devices a user may search in the picker (at most 500; a larger fleet types the name into the free-text box and uses the department filter).
 *
 * @return array<int,string> device_id => hostname
 */
function rivetRmmLogDevices(\mysqli $mysqli, int $userId): array
{
    $rmm = rivetRmmModule($mysqli);
    $authz = $rmm->authorizer();
    $visible = $authz->visibleClientIds($userId);
    if ($visible !== null) {
        // the same narrowing Core applies to the search: log text only where the grant that reads job output holds for that department
        $visible = array_values(array_filter($visible, static fn (int $c): bool => $authz->check($userId, RmmAbility::JOB_RUN_SAVED, $c) === null));
        if ($visible === []) {
            return [];
        }
    }
    $out = [];
    foreach ($rmm->readModel()->listDevices([], $visible, 500, 0, false)['items'] as $d) {
        $out[(int) $d['device_id']] = (string) $d['hostname'];
    }

    return $out;
}

/** @return array<int,string> client_id => name, the user's own departments */
function rivetRmmLogClients(\mysqli $mysqli, int $userId): array
{
    $visible = rivetRmmModule($mysqli)->authorizer()->visibleClientIds($userId);
    $scope = $visible === null ? '' : ($visible === [] ? ' AND 1 = 0' : ' AND client_id IN (' . implode(',', array_map('intval', $visible)) . ')');
    $out = [];
    $r = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL' . $scope . ' ORDER BY client_name LIMIT 500');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['client_id']] = (string) $row['client_name'];
    }

    return $out;
}

/**
 * asset id by device id for the devices on a page of results (one query, limited to the ids Core returned).
 *
 * @param list<array<string,mixed>> $items
 * @return array<int,int>
 */
function rivetRmmLogAssetIds(\mysqli $mysqli, array $items): array
{
    $ids = array_values(array_unique(array_map(static fn (array $i): int => (int) $i['device_id'], $items)));
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

/**
 * Storage figures for the header. Fleet-wide row counts are shown only to a user with access to every department (a restricted user would learn the size of other
 * departments' data from them); a restricted user sees the limits, and the figures of any one device Core lets them open.
 *
 * @return array<string,mixed>
 */
function rivetRmmLogStatsView(array $ctx, ?int $deviceId): array
{
    $m = $ctx['mysqli'];
    $rmm = rivetRmmModule($m);
    $who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
    $r = $rmm->logActions()->stats($who, $deviceId);
    if (!$r->ok) {
        return ['ok' => false, 'error' => $r->message];
    }
    $s = $r->data;
    $unrestricted = $rmm->authorizer()->visibleClientIds($ctx['uid']) === null;
    $out = ['ok' => true, 'unrestricted' => $unrestricted, 'retention_days' => (int) $s['retention_days'], 'rate_per_min' => (int) $s['rate_per_min'], 'device_max_rows' => (int) $s['device_max_rows'],
        'fleet_max_rows' => (int) $s['fleet_max_rows'], 'device_worst_bytes' => (int) $s['device_max_rows'] * RIVET_RMM_LOG_BYTES_PER_ENTRY, 'fleet_worst_bytes' => (int) $s['fleet_max_rows'] * RIVET_RMM_LOG_BYTES_PER_ENTRY,
        'fleet_rows' => $unrestricted ? (int) $s['fleet_rows'] : null, 'oldest' => null, 'device' => $s['device'] ?? null];
    if ($unrestricted || $deviceId !== null) {
        try {
            $res = mysqli_query($m, 'SELECT MIN(occurred_at) FROM endpoint_agent_log_events' . ($deviceId !== null ? ' WHERE device_id = ' . (int) $deviceId : ''));
            $v = $res ? mysqli_fetch_row($res)[0] ?? null : null;
            $out['oldest'] = $v === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($v . ' UTC'));
        } catch (\Throwable) {
            $out['oldest'] = null;
        }
    }

    return $out;
}

/**
 * Run one search through Core.
 *
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $f rivetRmmLogFilters()
 * @param int|null $deviceId the device of a device search (asset page), null for the fleet search
 * @return array<string,mixed>
 */
function rivetRmmLogSearch(array $ctx, array $f, ?int $deviceId = null): array
{
    $m = $ctx['mysqli'];
    $actions = rivetRmmModule($m)->logActions();
    $who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
    $limit = (int) $f['size'];
    $offset = ($f['page'] - 1) * $limit;
    $core = rivetRmmLogCoreFilters($f);
    $r = $deviceId !== null ? $actions->deviceLogs($who, $deviceId, $core, $limit, $offset) : $actions->fleetLogs($who, $core, $limit, $offset);
    if (!$r->ok) {
        return ['ok' => false, 'status' => $r->http, 'error' => $r->http === 404 ? ($deviceId !== null || (int) $f['device_id'] > 0 ? 'That device does not exist, or it belongs to a department you cannot see.' : 'That department does not exist, or you cannot see it.') : $r->message,
            'items' => [], 'total' => 0, 'capped' => false, 'clamped' => '', 'assets' => []];
    }
    $d = $r->data;
    $items = (array) $d['items'];
    $total = (int) $d['total'];
    $clamped = '';
    $span = $deviceId !== null || (int) $f['device_id'] > 0 ? 90 : 7;
    $got = (int) strtotime((string) $d['from']);
    if ($got > (int) $f['from'] + 1) {
        $clamped = 'A search covers at most ' . $span . ' days' . ($span === 7 ? ' across devices (one device can go back 90 days)' : ' for one device') . '. Showing ' . gmdate('Y-m-d H:i', $got) . ' to ' . gmdate('Y-m-d H:i', (int) strtotime((string) $d['to'])) . ' UTC instead of the range you asked for.';
    }

    return ['ok' => true, 'status' => 200, 'error' => '', 'items' => $items, 'total' => $total, 'capped' => $total > 10000, 'clamped' => $clamped, 'assets' => rivetRmmLogAssetIds($m, $items),
        'from' => (string) $d['from'], 'to' => (string) $d['to']];
}

/**
 * The model of the Logs page.
 *
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmLogPageView(array $ctx, array $get): array
{
    $f = rivetRmmLogFilters($get);
    $search = rivetRmmLogSearch($ctx, $f, null);

    return ['filters' => $f, 'search' => $search, 'devices' => rivetRmmLogDevices($ctx['mysqli'], $ctx['uid']), 'clients' => rivetRmmLogClients($ctx['mysqli'], $ctx['uid']),
        'stats' => rivetRmmLogStatsView($ctx, (int) $f['device_id'] > 0 && $search['ok'] ? (int) $f['device_id'] : null), 'perm' => $ctx['perm'], 'csrf' => $ctx['csrf']];
}

// ------------------------------------------------------------------ search: renderers

/** The message of one entry: short text as it is; long text behind a <details> that works without JavaScript. */
function rivetRmmLogMessage(string $msg): string
{
    if (mb_strlen($msg) <= RIVET_RMM_LOG_PREVIEW) {
        return '<span class="rmm-wrap">' . rmmH($msg) . '</span>';
    }

    return '<details class="rmm-log-msg"><summary><span class="rmm-log-short">' . rmmH(mb_substr($msg, 0, RIVET_RMM_LOG_PREVIEW)) . '&hellip;</span>'
        . '<span class="rmm-log-more small">show more</span><span class="rmm-log-less small">show less</span></summary>'
        . '<div class="rmm-log-full rmm-code">' . rmmH($msg) . '</div></details>';
}

/** The pager: previous / next with "showing x-y of n". @param array<string,mixed> $f @param array<string,mixed> $s rivetRmmLogSearch() */
function rivetRmmLogPager(array $f, array $s, string $base, string $anchor = ''): string
{
    $n = count($s['items']);
    $first = ($f['page'] - 1) * $f['size'];
    $hasNext = $n > 0 && $first + $n < $s['total'];
    $link = static fn (int $p, string $t, bool $on, string $rel): string => '<li class="page-item' . ($on ? '' : ' disabled') . '"><a class="page-link" rel="' . $rel . '" href="' . ($on ? rmmH(rivetRmmLogUrl($f, ['page' => $p], $base, $anchor)) : '#') . '"'
        . ($on ? '' : ' tabindex="-1" aria-disabled="true"') . '>' . $t . '</a></li>';

    return '<nav aria-label="Log pages" class="mt-2"><ul class="pagination pagination-sm mb-0">' . $link($f['page'] - 1, 'Newer', $f['page'] > 1, 'prev')
        . '<li class="page-item disabled"><span class="page-link">Page ' . (int) $f['page'] . '</span></li>' . $link($f['page'] + 1, 'Older', $hasNext, 'next') . '</ul></nav>';
}

/** The "showing x-y of n" sentence. @param array<string,mixed> $f @param array<string,mixed> $s */
function rivetRmmLogShowing(array $f, array $s): string
{
    $n = count($s['items']);
    if ($n === 0) {
        return 'No entries on this page.';
    }
    $first = ($f['page'] - 1) * $f['size'];

    return 'Showing ' . number_format($first + 1) . '-' . number_format($first + $n) . ' of ' . rivetRmmLogCount((int) $s['total'], (bool) $s['capped']) . ' (newest first).' . ($s['capped'] ? ' Narrow the filters to see further back.' : '');
}

/**
 * The entries table.
 *
 * @param array<string,mixed> $s rivetRmmLogSearch()
 */
function rivetRmmLogTable(array $s, bool $withDevice, string $caption): string
{
    $cols = $withDevice ? ['Time', 'Level', 'Device', 'Source', 'Event', 'Message'] : ['Time', 'Level', 'Source', 'Event', 'Message'];
    $rows = '';
    foreach ($s['items'] as $e) {
        $dev = '';
        if ($withDevice) {
            $aid = (int) ($s['assets'][(int) $e['device_id']] ?? 0);
            $name = (string) $e['hostname'] !== '' ? (string) $e['hostname'] : 'device #' . (int) $e['device_id'];
            $dev = '<td class="text-nowrap">' . ($aid > 0 ? '<a href="/agent/asset_details.php?asset_id=' . $aid . '#rmm-logs">' . rmmH($name) . '</a>' : rmmH($name)) . '</td>';
        }
        $rows .= '<tr><td class="ps-3 text-nowrap">' . rivetRmmLogWhen($e['occurred_at']) . '</td><td>' . rivetRmmLogLevelPill((string) $e['level']) . '</td>' . $dev
            . '<td class="rmm-wrap rmm-log-src">' . ((string) $e['source'] !== '' ? rmmH($e['source']) : '<span class="text-muted">none</span>') . '</td>'
            . '<td>' . ($e['code'] === null ? '<span class="text-muted">none</span>' : '<code class="rmm-mono">' . (int) $e['code'] . '</code>') . '</td>'
            . '<td class="rmm-log-msgcell">' . rivetRmmLogMessage((string) $e['message']) . '</td></tr>';
    }

    return '<div class="table-responsive"><table class="table table-sm table-hover align-top mb-0 rmm-log-table"><caption class="visually-hidden">' . rmmH($caption) . '</caption>'
        . rivetRmmUiThead($cols) . '<tbody>' . $rows . '</tbody></table></div>';
}

/** The range cap in plain words. */
function rivetRmmLogCapWords(bool $deviceScope): string
{
    return $deviceScope ? 'One device can be searched up to 90 days back.' : 'A search across devices covers at most 7 days; pick one device to go back up to 90 days.';
}

/**
 * The filter form shared by the page and the asset tab.
 *
 * @param array<string,mixed> $f rivetRmmLogFilters()
 * @param array<int,string> $devices (page only)
 * @param array<int,string> $clients (page only)
 */
function rivetRmmLogFilterForm(array $f, string $action, bool $deviceScope, array $devices = [], array $clients = [], array $hidden = [], string $anchor = ''): string
{
    $p = (string) $f['prefix'];
    $id = static fn (string $k): string => 'lg-' . ($p === '' ? '' : 'a-') . $k;
    $sel = static function (string $k, string $label, array $opts, string $cur, string $col = 'col-6 col-md-2') use ($p, $id): string {
        $o = '<div class="' . $col . '"><label class="form-label small mb-1" for="' . $id($k) . '">' . rmmH($label) . '</label><select class="form-select form-select-sm" id="' . $id($k) . '" name="' . rmmH($p . $k) . '">';
        foreach ($opts as $v => $t) {
            $o .= '<option value="' . rmmH((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>' . rmmH($t) . '</option>';
        }

        return $o . '</select></div>';
    };
    $txt = static fn (string $k, string $label, string $val, int $max, string $col, string $extra = ''): string => '<div class="' . $col . '"><label class="form-label small mb-1" for="' . $id($k) . '">' . rmmH($label)
        . '</label><input class="form-control form-control-sm" id="' . $id($k) . '" name="' . rmmH($p . $k) . '" maxlength="' . $max . '" value="' . rmmH($val) . '"' . $extra . '></div>';

    $ranges = [];
    foreach (RIVET_RMM_LOG_RANGES as $k => [$label]) {
        if ($deviceScope || in_array($k, ['1h', '24h', '7d'], true)) {
            $ranges[$k] = $label;
        }
    }
    $ranges['custom'] = 'Custom range';
    $levels = ['' => 'Any level'];
    foreach (rivetRmmLogLevelNames() as $k => $t) {
        $levels[$k] = $t . ' or worse';
    }
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= '<input type="hidden" name="' . rmmH((string) $k) . '" value="' . rmmH((string) $v) . '">';
    }
    $o = '<form method="get" action="' . rmmH($action . $anchor) . '" class="card card-dark mb-3 rmm-log-filter" data-rmm-log-filter="1"><div class="card-body py-2"><div class="row g-2 align-items-end">' . $h;
    if (!$deviceScope) {
        $devOpts = ['0' => 'All devices'];
        foreach ($devices as $did => $n) {
            $devOpts[(string) $did] = $n;
        }
        if ((int) $f['device_id'] > 0 && !isset($devices[(int) $f['device_id']])) {
            $devOpts[(string) (int) $f['device_id']] = 'Device #' . (int) $f['device_id'];
        }
        $cliOpts = ['0' => 'All departments'];
        foreach ($clients as $cid => $n) {
            $cliOpts[(string) $cid] = $n;
        }
        $o .= $sel('device_id', 'Device', $devOpts, (string) $f['device_id'], 'col-12 col-md-3');
        $o = str_replace('name="' . $p . 'device_id"', 'name="device_id"', $o);
        $o .= $sel('client_id', 'Department', $cliOpts, (string) $f['client_id'], 'col-6 col-md-2');
        $o = str_replace('name="' . $p . 'client_id"', 'name="client_id"', $o);
    }
    $o .= $sel('range', 'Time range (UTC)', $ranges, (string) $f['range'], $deviceScope ? 'col-6 col-md-3' : 'col-6 col-md-2');
    $o .= '<div class="col-6 col-md-2 rmm-log-custom"><label class="form-label small mb-1" for="' . $id('from') . '">From (UTC)</label><input type="datetime-local" class="form-control form-control-sm" id="' . $id('from') . '" name="' . rmmH($p . 'from') . '" value="' . rmmH((string) $f['from_in']) . '"></div>'
        . '<div class="col-6 col-md-2 rmm-log-custom"><label class="form-label small mb-1" for="' . $id('to') . '">To (UTC)</label><input type="datetime-local" class="form-control form-control-sm" id="' . $id('to') . '" name="' . rmmH($p . 'to') . '" value="' . rmmH((string) $f['to_in']) . '"></div>';
    $o .= $sel('level', 'Level', $levels, (string) $f['level']);
    $o .= $txt('source', 'Source contains', (string) $f['source'], 100, 'col-6 col-md-2');
    $o .= $txt('code', 'Event id', (string) $f['code'], 10, 'col-6 col-md-1', ' inputmode="numeric" pattern="[0-9]*"');
    $o .= $txt('q', 'Message contains', (string) $f['q'], 200, 'col-12 col-md-3');
    $o .= $sel('size', 'Per page', array_combine(RIVET_RMM_LOG_SIZES, RIVET_RMM_LOG_SIZES), (string) $f['size'], 'col-6 col-md-1');
    $o .= '<div class="col-12 col-md-auto"><button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1" aria-hidden="true"></i>Search</button> '
        . '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($action . $anchor) . '">Clear</a></div></div>'
        . '<div class="form-text mt-1">' . rmmH(rivetRmmLogCapWords($deviceScope)) . ' Text, source and event id are plain substring matches inside the range, not regular expressions.</div>'
        . '</div></form>';

    return $o;
}

/**
 * The storage header.
 *
 * @param array<string,mixed> $st rivetRmmLogStatsView()
 */
function rivetRmmLogStatsHtml(array $st, bool $deviceScope = false): string
{
    if (!$st['ok']) {
        return '';
    }
    $cell = static fn (string $v, string $label, string $icon): string => '<div class="col-6 col-md-3"><div class="card card-dark h-100 rmm-alr-kpi"><div class="card-body py-2"><div class="rmm-alr-kpi-n rmm-log-kpi">' . $v . '</div>'
        . '<div class="small text-muted"><i class="fas fa-' . $icon . ' me-1" aria-hidden="true"></i>' . rmmH($label) . '</div></div></div></div>';
    $cells = '';
    if ($deviceScope || is_array($st['device'])) {
        $d = (array) $st['device'];
        $cells .= $cell(rmmH(number_format((int) ($d['rows'] ?? 0))), 'entries stored for this device (cap ' . number_format($st['device_max_rows']) . ')', 'database')
            . $cell($d['last_event_at'] ? rivetRmmUiTime($d['last_event_at']) : '<span class="text-muted">none yet</span>', 'newest entry', 'clock')
            . $cell(rmmH(number_format((int) ($d['dropped_total'] ?? 0))), 'entries dropped (rate or size caps)', 'filter');
    } else {
        $cells .= $cell($st['fleet_rows'] === null ? '<span class="text-muted">not shown</span>' : rmmH(number_format($st['fleet_rows'])), 'entries stored' . ($st['fleet_rows'] === null ? ' (fleet totals need access to every department)' : ' (cap ' . number_format($st['fleet_max_rows']) . ')'), 'database')
            . $cell($st['oldest'] === null ? '<span class="text-muted">' . ($st['unrestricted'] ? 'none yet' : 'not shown') . '</span>' : rivetRmmUiTime($st['oldest']), 'oldest entry', 'history');
    }
    $cells .= $cell(rmmH($st['retention_days'] . ' days'), 'retention before pruning', 'trash-alt');
    $cells .= $cell(rmmH(rivetRmmUiBytes((float) ($deviceScope || is_array($st['device']) ? $st['device_worst_bytes'] : $st['fleet_worst_bytes']))), 'worst-case storage (about ' . RIVET_RMM_LOG_BYTES_PER_ENTRY . ' bytes per entry' . ($deviceScope || is_array($st['device']) ? ', per device' : ', whole fleet') . ')', 'hdd');

    return '<div class="row g-2 mb-3" role="group" aria-label="Log storage">' . $cells . '</div>';
}

/** The results block (summary line, notices, table, pager) shared by the page and the tab. @param array<string,mixed> $f @param array<string,mixed> $s */
function rivetRmmLogResultsHtml(array $f, array $s, bool $withDevice, string $base, string $anchor = '', string $emptyHint = ''): string
{
    if ($f['notice'] !== '') {
        $notice = '<div class="alert alert-info py-2" role="status"><i class="fas fa-info-circle me-2" aria-hidden="true"></i>' . rmmH($f['notice']) . '</div>';
    } else {
        $notice = '';
    }
    if ($f['code_bad']) {
        $notice .= '<div class="alert alert-warning py-2" role="status"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>The event id must be a whole number, so it was ignored.</div>';
    }
    if (!$s['ok']) {
        return $notice . '<div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i>' . rmmH($s['error']) . '</div>';
    }
    if ($s['clamped'] !== '') {
        $notice .= '<div class="alert alert-info py-2" role="status"><i class="fas fa-info-circle me-2" aria-hidden="true"></i>' . rmmH($s['clamped']) . '</div>';
    }
    $range = 'Range searched: ' . gmdate('Y-m-d H:i', (int) strtotime($s['from'])) . ' to ' . gmdate('Y-m-d H:i', (int) strtotime($s['to'])) . ' UTC.';
    $head = '<div class="d-flex align-items-center flex-wrap mb-2" style="gap:6px"><div class="small text-muted me-auto" role="status" aria-live="polite" id="lg-count">' . rmmH(rivetRmmLogShowing($f, $s)) . ' ' . rmmH($range) . '</div>'
        . ($s['items'] !== [] ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-rmm-log-toggle-all="1" aria-pressed="false"><i class="fas fa-expand-alt me-1" aria-hidden="true"></i><span>Show all messages</span></button>' : '') . '</div>';
    if ($s['items'] === []) {
        if ($f['page'] > 1 && (int) $s['total'] > 0) {
            $empty = rivetRmmUiEmpty('fas fa-inbox', 'No entries on this page', 'There are ' . rivetRmmLogCount((int) $s['total'], (bool) $s['capped']) . ' entries in this range, but not this far back.',
                '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH(rivetRmmLogUrl($f, ['page' => 1], $base, $anchor)) . '">Back to the first page</a>');
        } else {
            $empty = rivetRmmUiEmpty('fas fa-inbox', 'No log entries match', $emptyHint !== '' ? $emptyHint : 'Nothing was collected in this range with these filters. Widen the range or clear a filter.');
        }

        return $notice . $head . $empty;
    }

    return $notice . $head . rivetRmmLogTable($s, $withDevice, 'Collected log entries, newest first') . '<div class="p-2">' . rivetRmmLogPager($f, $s, $base, $anchor) . '</div>';
}

/**
 * The Logs page.
 *
 * @param array<string,mixed> $vm rivetRmmLogPageView()
 */
function rivetRmmLogPageHtml(array $vm): string
{
    $actions = '';
    if ($vm['perm']['rules']) {
        $actions .= '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_log_rules.php"><i class="fas fa-bullhorn me-1" aria-hidden="true"></i>Log rules</a>';
    }
    $o = rivetRmmAutoHeader('Logs', 'scroll', $actions, 'Event-log and system-log entries collected from devices that were set up to send them. Everything here is read-only; line text can contain personal data.');
    $o .= rivetRmmLogStatsHtml($vm['stats'], (int) $vm['filters']['device_id'] > 0 && is_array($vm['stats']['device'] ?? null));
    $o .= rivetRmmLogFilterForm($vm['filters'], '/agent/rmm_logs.php', false, $vm['devices'], $vm['clients']);
    $card = rivetRmmLogResultsHtml($vm['filters'], $vm['search'], true, '/agent/rmm_logs.php');

    return $o . rivetRmmUiCard('Log entries', 'scroll', $card, '', '', false);
}

// ------------------------------------------------------------------ the Logs tab of the asset page

/**
 * Why a device may have no log entries, in words: what the administrator has to do, or what the device has to do.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 * @return list<array{kind:string,html:string}>
 */
function rivetRmmLogPanelNotes(array $vm, \mysqli $mysqli, bool $isAdmin): array
{
    $notes = [];
    $os = ((string) $vm['platform']) === 'linux' ? 'linux' : 'windows';
    $sources = [];
    try {
        $sources = rivetRmmModule($mysqli)->remoteSettings()->logSources($os);
    } catch (\Throwable) {
        $sources = [];
    }
    $adminLink = $isAdmin ? ' <a href="/admin/settings_endpoint_agent.php">Administration &gt; Endpoint agent</a>' : ' Ask an administrator.';
    if ($sources === []) {
        $notes[] = ['kind' => 'info', 'html' => 'No log source is configured for ' . ($os === 'linux' ? 'Linux' : 'Windows') . ' devices yet, so the agent has not been told to collect anything. Choose the sources (event-log channels or journal units) under' . ($isAdmin ? $adminLink . '.' : ' Administration &gt; Endpoint agent.' . $adminLink)];

        return $notes;
    }
    $caps = null;
    try {
        $res = mysqli_query($mysqli, 'SELECT capabilities_json FROM rmm_device_state WHERE device_id = ' . (int) $vm['device_id']);
        $row = $res ? mysqli_fetch_row($res) : null;
        $caps = $row && $row[0] !== null ? json_decode((string) $row[0], true) : null;
    } catch (\Throwable) {
        $caps = null;
    }
    if (!is_array($caps) || !in_array(RemoteProtocol::CAP_LOGS, $caps, true)) {
        $notes[] = ['kind' => 'info', 'html' => 'This device has not announced that it can collect logs (<code>' . rmmH(RemoteProtocol::CAP_LOGS) . '</code>). Its agent is probably older than the log feature; update the agent and collection starts at the next check-in. Entries collected earlier, if any, are shown below.'];
    }

    return $notes;
}

/**
 * The hook rivetRmmUiTabs() calls: the Logs tab. '' (no tab, no pane) when the module or the `logs` switch is off, the viewer cannot read logs, or Core will not show this
 * device to the viewer.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmLogPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (!rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('logs', $mysqli)) {
        return '';
    }
    $uid = (int) $vm['user_id'];
    $perm = rivetRmmLogPerm($mysqli, $uid);
    if (!$perm['read']) {
        return '';
    }
    $ctx = ['uid' => $uid, 'name' => (string) ($GLOBALS['session_name'] ?? ''), 'csrf' => $csrf, 'perm' => $perm, 'mysqli' => $mysqli];
    $deviceId = (int) $vm['device_id'];
    $f = rivetRmmLogFilters($_GET, 'lg_', true);
    $s = rivetRmmLogSearch($ctx, $f, $deviceId);
    if (!$s['ok'] && in_array($s['status'], [403, 404], true)) {
        return '';
    }
    $GLOBALS['rmm_log_scripts'] = true;
    $base = '/agent/asset_details.php?asset_id=' . (int) $vm['asset_id'];
    $o = '<div id="rmm-logs">';
    foreach (rivetRmmLogPanelNotes($vm, $mysqli, $perm['admin']) as $n) {
        $o .= '<div class="alert alert-' . rmmH($n['kind']) . ' py-2"><i class="fas fa-info-circle me-2" aria-hidden="true"></i>' . $n['html'] . '</div>';
    }
    $o .= rivetRmmLogStatsHtml(rivetRmmLogStatsView($ctx, $deviceId), true);
    $o .= rivetRmmLogFilterForm($f, '/agent/asset_details.php', true, [], [], ['asset_id' => (int) $vm['asset_id']], '#rmm-logs');
    $o .= rivetRmmLogResultsHtml($f, $s, false, $base, '#rmm-logs', 'No entries from this device in this range. If log collection was only just switched on, entries arrive within a few minutes of the next check-in.');

    return $o . '</div>';
}

// ------------------------------------------------------------------ rules: view-model

/** The matches a rule makes in words. @param array<string,mixed> $r a presented rule */
function rivetRmmLogRuleMatchWords(array $r): string
{
    $parts = [rivetRmmLogLevelNames()[(string) $r['min_level']] . ' or worse'];
    if ((string) $r['source_contains'] !== '') {
        $parts[] = 'source contains <code class="rmm-mono">' . rmmH($r['source_contains']) . '</code>';
    }
    if ($r['code'] !== null) {
        $parts[] = 'event id <code class="rmm-mono">' . (int) $r['code'] . '</code>';
    }
    if ((string) $r['contains'] !== '') {
        $parts[] = 'text contains <code class="rmm-mono">' . rmmH($r['contains']) . '</code>';
    }

    return implode(', ', $parts);
}

/** "Alert after 3 matches within 10 minutes". @param array<string,mixed> $r */
function rivetRmmLogRuleCountWords(array $r): string
{
    $n = (int) $r['threshold'];

    return $n <= 1 ? 'on every match' : $n . ' matches within ' . rivetRmmAlrMinutes((int) $r['window_min']);
}

/**
 * Failing log checks per rule (devices whose check `log.rule_<id>` is warn or fail) and the open alerts per rule, inside the user's own departments.
 *
 * @return array<int,array{devices:int,alerts:int}>
 */
function rivetRmmLogRuleStates(\mysqli $mysqli, int $userId): array
{
    $visible = rivetRmmModule($mysqli)->authorizer()->visibleClientIds($userId);
    $scope = $visible === null ? '' : ($visible === [] ? ' AND 1 = 0' : ' AND d.client_id IN (' . implode(',', array_map('intval', $visible)) . ')');
    $out = [];
    $like = "LIKE 'log.rule!_%' ESCAPE '!'";
    try {
        $r = mysqli_query($mysqli, "SELECT c.check_key, COUNT(*) AS n FROM endpoint_agent_checks c JOIN endpoint_agent_devices d ON d.device_id = c.device_id WHERE c.check_key $like AND c.status IN ('warn','fail')$scope GROUP BY c.check_key");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $id = (int) substr((string) $row['check_key'], strlen(RemoteProtocol::LOG_CHECK_PREFIX));
            $out[$id]['devices'] = (int) $row['n'];
        }
        $r = mysqli_query($mysqli, "SELECT m.check_key, COUNT(*) AS n FROM rmm_alert_meta m JOIN endpoint_agent_devices d ON d.device_id = m.device_id WHERE m.check_key $like AND m.state IN ('open','acknowledged')$scope GROUP BY m.check_key");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $id = (int) substr((string) $row['check_key'], strlen(RemoteProtocol::LOG_CHECK_PREFIX));
            $out[$id]['alerts'] = (int) $row['n'];
        }
    } catch (\Throwable) {
        return [];
    }
    foreach ($out as $id => $v) {
        $out[$id] = ['devices' => (int) ($v['devices'] ?? 0), 'alerts' => (int) ($v['alerts'] ?? 0)];
    }

    return $out;
}

/**
 * The model of the Log rules page. `?rule_id=N` opens the form for a rule, `?new=1` the empty form. A refused save leaves the values the user typed in the session for
 * one request so the form comes back filled in.
 *
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmLogRulesView(array $ctx, array $get): array
{
    $m = $ctx['mysqli'];
    $rmm = rivetRmmModule($m);
    $who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
    $list = $rmm->logActions()->listRules($who);
    $rules = $list->ok ? (array) ($list->data['data'] ?? []) : [];
    $editId = (int) ($get['rule_id'] ?? 0);
    $new = !empty($get['new']);
    $edit = null;
    $error = $list->ok ? '' : $list->message;
    if ($editId > 0) {
        foreach ($rules as $r) {
            if ((int) $r['rule_id'] === $editId) {
                $edit = $r;
            }
        }
        if ($edit === null) {
            $error = 'That rule does not exist, or it belongs to a department you cannot see.';
        }
    }
    $draft = $_SESSION['rmm_log_rule_draft'] ?? null;
    unset($_SESSION['rmm_log_rule_draft']);
    $form = null;
    if ($edit !== null || $new) {
        $form = is_array($draft) ? $draft : ($edit ?? ['rule_id' => 0, 'name' => '', 'enabled' => true, 'client_id' => 0, 'min_level' => 'error', 'source_contains' => '', 'code' => null, 'contains' => '', 'threshold' => 1,
            'window_min' => 5, 'severity' => 'warn', 'resolve_after_min' => 30]);
    }
    $alertingOn = rivetRmmFeatureOn('alerting', $m);

    return ['rules' => $rules, 'form' => $form, 'error' => $error, 'states' => rivetRmmLogRuleStates($m, $ctx['uid']), 'clients' => rivetRmmLogClients($m, $ctx['uid']), 'csrf' => $ctx['csrf'], 'alerting' => $alertingOn,
        'all_clients' => $rmm->authorizer()->visibleClientIds($ctx['uid']) === null, 'max_rules' => \RivetCore\Rmm\Logs\LogRuleService::MAX_RULES];
}

// ------------------------------------------------------------------ rules: renderers

/** How a match becomes an alert, in plain words. */
function rivetRmmLogExplainHtml(bool $alertingOn): string
{
    $off = $alertingOn
        ? 'The <strong>Alerting</strong> switch is on, so maintenance windows, dependencies, storm control and escalation policies apply to these alerts like any other agent alert.'
        : 'The <strong>Alerting</strong> switch is off, so the alert still opens (it appears under RMM Alerts and can raise a ticket) but maintenance windows, dependencies, storm control and escalation policies do not apply. Turn Alerting on under Administration &gt; Endpoint agent to use them.';

    return '<details class="rmm-log-explain mb-3" id="lr-explain"><summary class="fw-bold"><i class="fas fa-question-circle me-1" aria-hidden="true"></i>How an alert is made</summary><div class="mt-2 small">'
        . '<ol class="mb-2"><li>Each batch of log lines a device sends is checked against every enabled rule that covers the device&rsquo;s department.</li>'
        . '<li>A line matches when its level is at least as bad as the rule&rsquo;s level and the source, event id and phrase (when given) are found in it. The phrase and the source are '
        . '<strong>plain text, never a regular expression</strong>: a character such as <code>.</code> or <code>*</code> stands for itself, and the comparison ignores upper and lower case.</li>'
        . '<li>When the number of matches inside the window reaches the count, the rule reports the check <code>log.rule_&lt;id&gt;</code> for that device as a warning or a failure.</li>'
        . '<li>That check goes through the same alert path as every other check, so one rule raises at most one alert per device until it recovers.</li>'
        . '<li>When the rule has not matched on that device for the &ldquo;resolve after&rdquo; time (or the rule is switched off or deleted), the check reports OK at the next housekeeping run and the alert closes.</li></ol>'
        . '<p class="mb-0">' . $off . '</p></div></details>';
}

/**
 * The create / edit form.
 *
 * @param array<string,mixed> $vm rivetRmmLogRulesView()
 */
function rivetRmmLogRuleForm(array $vm): string
{
    $f = $vm['form'];
    $id = (int) ($f['rule_id'] ?? 0);
    $csrf = (string) $vm['csrf'];
    $levels = '';
    foreach (rivetRmmLogLevelNames() as $k => $t) {
        $levels .= '<option value="' . $k . '"' . ((string) $f['min_level'] === $k ? ' selected' : '') . '>' . rmmH($t . ' or worse') . '</option>';
    }
    $clients = '';
    if ($vm['all_clients']) {
        $clients .= '<option value="0"' . ((int) $f['client_id'] === 0 ? ' selected' : '') . '>All departments</option>';
    }
    foreach ($vm['clients'] as $cid => $n) {
        $clients .= '<option value="' . (int) $cid . '"' . ((int) $f['client_id'] === (int) $cid ? ' selected' : '') . '>' . rmmH($n) . '</option>';
    }
    if ((int) $f['client_id'] > 0 && !isset($vm['clients'][(int) $f['client_id']])) {
        $clients .= '<option value="' . (int) $f['client_id'] . '" selected>Department #' . (int) $f['client_id'] . '</option>';
    }
    $num = static fn (string $k, string $label, $val, int $min, int $max, string $hint): string => '<div class="col-6 col-md-3"><label class="form-label" for="lr-' . $k . '">' . rmmH($label) . '</label>'
        . '<input type="number" class="form-control" id="lr-' . $k . '" name="' . $k . '" min="' . $min . '" max="' . $max . '" step="1" required value="' . rmmH((string) $val) . '" aria-describedby="lr-' . $k . '-h"><div class="form-text" id="lr-' . $k . '-h">' . rmmH($hint) . '</div></div>';

    $o = '<form method="post" action="/agent/post/' . RIVET_RMM_LOG_HANDLER . '.php" class="rmm-alr-form" id="lr-form" novalidate>' . rivetRmmAutoHidden($csrf, $id > 0 ? '/agent/rmm_log_rules.php?rule_id=' . $id : '/agent/rmm_log_rules.php?new=1')
        . '<input type="hidden" name="action" value="save_rule"><input type="hidden" name="rule_id" value="' . $id . '"><div class="row g-3">'
        . '<div class="col-12 col-md-6"><label class="form-label" for="lr-name">Name</label><input class="form-control" id="lr-name" name="name" maxlength="100" required value="' . rmmH((string) $f['name']) . '"><div class="form-text">Shown in the alert, for example &ldquo;Failed logons&rdquo;.</div></div>'
        . '<div class="col-6 col-md-3"><label class="form-label" for="lr-client">Applies to</label><select class="form-select" id="lr-client" name="client_id">' . $clients . '</select></div>'
        . '<div class="col-6 col-md-3 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="lr-enabled" name="enabled" value="1"' . (!empty($f['enabled']) ? ' checked' : '') . '><label class="form-check-label" for="lr-enabled">Rule is on</label></div></div>'
        . '<div class="col-12"><h6 class="mb-0">A line matches when</h6></div>'
        . '<div class="col-6 col-md-3"><label class="form-label" for="lr-min_level">Level</label><select class="form-select" id="lr-min_level" name="min_level">' . $levels . '</select><div class="form-text">This level or worse.</div></div>'
        . '<div class="col-6 col-md-3"><label class="form-label" for="lr-source_contains">Source contains</label><input class="form-control" id="lr-source_contains" name="source_contains" maxlength="100" value="' . rmmH((string) $f['source_contains']) . '"><div class="form-text">Optional. For example <code>Security</code> or <code>sshd</code>.</div></div>'
        . '<div class="col-6 col-md-2"><label class="form-label" for="lr-code">Event id</label><input class="form-control" id="lr-code" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="10" value="' . rmmH($f['code'] === null ? '' : (string) $f['code']) . '"><div class="form-text">Optional.</div></div>'
        . '<div class="col-12 col-md-4"><label class="form-label" for="lr-contains">Phrase</label><input class="form-control" id="lr-contains" name="contains" maxlength="200" value="' . rmmH((string) $f['contains']) . '" aria-describedby="lr-contains-h"><div class="form-text" id="lr-contains-h">Optional. Plain text, <strong>not a regular expression</strong>: <code>.</code>, <code>*</code> and <code>[</code> mean themselves. Upper and lower case are the same.</div></div>'
        . '<div class="col-12"><h6 class="mb-0">Then</h6></div>'
        . $num('threshold', 'Matches needed', (int) $f['threshold'], 1, 1000, 'Alert once this many lines match.')
        . $num('window_min', 'Within (minutes)', (int) $f['window_min'], 1, 1440, 'Only used when more than 1 match is needed.')
        . '<div class="col-6 col-md-3"><label class="form-label" for="lr-severity">Alert severity</label><select class="form-select" id="lr-severity" name="severity"><option value="warn"' . ((string) $f['severity'] === 'warn' ? ' selected' : '') . '>Warning</option><option value="fail"' . ((string) $f['severity'] === 'fail' ? ' selected' : '') . '>Critical (failure)</option></select></div>'
        . $num('resolve_after_min', 'Resolve after (minutes)', (int) $f['resolve_after_min'], 1, 10080, 'Close the alert after this long without a match.')
        . '</div><div class="mt-3 d-flex flex-wrap" style="gap:6px"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>Save rule</button>'
        . '<a class="btn btn-outline-secondary" href="/agent/rmm_log_rules.php">Cancel</a></div></form>';

    return rivetRmmUiCard($id > 0 ? 'Edit rule' : 'New rule', 'bullhorn', $o, '', '', false, 'lr-form-card');
}

/**
 * The Log rules page.
 *
 * @param array<string,mixed> $vm rivetRmmLogRulesView()
 */
function rivetRmmLogRulesPage(array $vm): string
{
    $csrf = (string) $vm['csrf'];
    $btn = count($vm['rules']) >= $vm['max_rules'] ? '' : '<a class="btn btn-sm btn-primary" href="/agent/rmm_log_rules.php?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New rule</a>';
    $o = rivetRmmAutoHeader('Log rules', 'bullhorn', '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_logs.php"><i class="fas fa-scroll me-1" aria-hidden="true"></i>Logs</a>' . $btn,
        'A rule raises an alert when collected log lines match a filter often enough. At most ' . (int) $vm['max_rules'] . ' rules.');
    $o .= '<div id="lr-msg" class="alert d-none" role="status" aria-live="polite"></div>';
    if ($vm['error'] !== '') {
        $o .= '<div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle me-2" aria-hidden="true"></i>' . rmmH($vm['error']) . '</div>';
    }
    $o .= rivetRmmLogExplainHtml($vm['alerting']);
    if ($vm['form'] !== null) {
        $o .= rivetRmmLogRuleForm($vm);
    }
    if ($vm['rules'] === []) {
        return $o . rivetRmmUiCard('Rules', 'bullhorn', rivetRmmUiEmpty('fas fa-bullhorn', 'No log rules yet', 'Create a rule to be alerted when a phrase such as &ldquo;disk failure&rdquo; shows up in the logs.', $btn !== '' ? str_replace('btn-sm ', '', $btn) : ''), '', '', false)
            . rivetRmmAutoConfirmModal();
    }
    $rows = '';
    foreach ($vm['rules'] as $r) {
        $id = (int) $r['rule_id'];
        $st = $vm['states'][$id] ?? ['devices' => 0, 'alerts' => 0];
        $alertLink = $vm['alerting']
            ? '<a href="' . rmmH('/agent/rmm_agent_alerts.php?' . http_build_query(['state' => 'active', 'check_key' => $r['check_key']])) . '">' . (int) $st['alerts'] . ' open alert' . ($st['alerts'] === 1 ? '' : 's') . '</a>'
            : '<a href="/agent/rmm_alerts.php">RMM Alerts</a>';
        $scope = (int) $r['client_id'] === 0 ? 'All departments' : ($vm['clients'][(int) $r['client_id']] ?? ('Department #' . (int) $r['client_id']));
        $rows .= '<tr><td class="ps-3"><strong class="rmm-wrap">' . rmmH($r['name']) . '</strong><div class="small text-muted"><code class="rmm-mono">' . rmmH($r['check_key']) . '</code></div></td>'
            . '<td>' . ($r['enabled'] ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off')) . '</td><td class="rmm-wrap">' . rmmH($scope) . '</td>'
            . '<td class="rmm-wrap small">' . rivetRmmLogRuleMatchWords($r) . '</td>'
            . '<td class="small">' . rmmH(rivetRmmLogRuleCountWords($r)) . ', ' . ($r['severity'] === 'fail' ? 'critical' : 'warning') . '<div class="text-muted">resolves after ' . rmmH(rivetRmmAlrMinutes((int) $r['resolve_after_min'])) . ' quiet</div></td>'
            . '<td class="small">' . (int) $st['devices'] . ' device' . ($st['devices'] === 1 ? '' : 's') . ' matching<div>' . $alertLink . '</div></td>'
            . '<td class="rmm-table-actions"><a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_log_rules.php?rule_id=' . $id . '" aria-label="Edit rule ' . rmmH($r['name']) . '"><i class="fas fa-edit" aria-hidden="true"></i> Edit</a> '
            . rivetRmmAutoActionForm(RIVET_RMM_LOG_HANDLER, 'toggle_rule', ['rule_id' => $id], $csrf, '/agent/rmm_log_rules.php', $r['enabled'] ? 'Turn off' : 'Turn on', 'btn btn-sm btn-outline-secondary', '', ($r['enabled'] ? 'Turn off ' : 'Turn on ') . $r['name']) . ' '
            . rivetRmmAutoActionForm(RIVET_RMM_LOG_HANDLER, 'delete_rule', ['rule_id' => $id], $csrf, '/agent/rmm_log_rules.php', '<i class="fas fa-trash" aria-hidden="true"></i> Delete', 'btn btn-sm btn-outline-danger',
                'Delete the rule ' . $r['name'] . '? Alerts it has open close at the next housekeeping run.', 'Delete ' . $r['name']) . '</td></tr>';
    }
    $table = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><caption class="visually-hidden">Log rules</caption>'
        . rivetRmmUiThead(['Rule', 'State', 'Applies to', 'A line matches when', 'Alert when', 'Right now', 'Actions']) . '<tbody>' . $rows . '</tbody></table></div>';

    return $o . rivetRmmUiCard('Rules (' . count($vm['rules']) . ')', 'bullhorn', $table, '', '', true) . rivetRmmAutoConfirmModal();
}

// ------------------------------------------------------------------ rules: form post

/**
 * The typed body Core takes, from a posted form. Whole numbers stay ints (Core refuses strings); an empty event id is null.
 *
 * @param array<string,mixed> $post
 * @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmLogRuleBody(array $post): array
{
    $s = static fn (string $k, int $max = 200): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $int = static function (string $k) use ($s): ?int {
        $v = $s($k, 12);

        return preg_match('/^\d{1,10}$/', $v) === 1 ? (int) $v : null;
    };
    $labels = ['threshold' => 'Matches needed', 'window_min' => 'Within (minutes)', 'resolve_after_min' => 'Resolve after (minutes)'];
    $body = ['name' => $s('name', 100), 'enabled' => isset($post['enabled']) && $post['enabled'] !== '' ? 1 : 0, 'client_id' => $int('client_id') ?? 0, 'min_level' => $s('min_level', 12),
        'source_contains' => $s('source_contains', 100), 'contains' => $s('contains', 200), 'severity' => $s('severity', 8)];
    foreach ($labels as $k => $label) {
        $v = $int($k);
        if ($v === null) {
            return [null, $label . ' must be a whole number.'];
        }
        $body[$k] = $v;
    }
    $code = $s('code', 12);
    if ($code !== '' && preg_match('/^\d{1,10}$/', $code) !== 1) {
        return [null, 'The event id must be a whole number, or empty.'];
    }
    $body['code'] = $code === '' ? null : (int) $code;

    return [$body, ''];
}

/**
 * What the user typed, in the shape of a presented rule, kept for one request so a refused form comes back filled in.
 *
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function rivetRmmLogRuleDraft(array $post, int $ruleId): array
{
    $s = static fn (string $k, int $max = 200): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $i = static fn (string $k, int $d): int => preg_match('/^\d{1,10}$/', $s($k, 12)) === 1 ? (int) $s($k, 12) : $d;
    $code = $s('code', 12);

    return ['rule_id' => $ruleId, 'name' => $s('name', 100), 'enabled' => isset($post['enabled']) && $post['enabled'] !== '', 'client_id' => $i('client_id', 0), 'min_level' => $s('min_level', 12),
        'source_contains' => $s('source_contains', 100), 'code' => preg_match('/^\d{1,10}$/', $code) === 1 ? (int) $code : null, 'contains' => $s('contains', 200), 'threshold' => $i('threshold', 1),
        'window_min' => $i('window_min', 5), 'severity' => $s('severity', 8), 'resolve_after_min' => $i('resolve_after_min', 30)];
}
