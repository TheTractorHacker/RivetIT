<?php
/* Linux Fleet: read-only cluster-health page. The top of this file is the pure model (no DB, loadable alone); rivetLinuxFleetView() reads through RivetCore and the renderer builds the HTML, both loading rmm_pat_ui.php lazily. */

/* Free text from a device is untrusted: drop control bytes (ASCII only, so safe on any byte string), drop invalid UTF-8, truncate. */
function rivetLinuxFleetClean(string $s, int $max): string
{
    $s = preg_replace('/[\x00-\x1f\x7f]/', '', $s) ?? '';
    if (!mb_check_encoding($s, 'UTF-8')) {
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        $s = $fixed !== false ? $fixed : (string) preg_replace('/[^\x20-\x7e]/', '', $s);
    }
    return mb_substr($s, 0, $max);
}

function rivetLinuxFleetIsLinux(array $detail, array $patch, string $osVersion = ''): bool
{
    $mgr = strtolower((string) ($patch['scan']['manager'] ?? ''));
    if (in_array($mgr, ['apt', 'dnf', 'yum', 'zypper'], true)) {
        return true;
    }
    $os = (string) ($detail['os_version'] ?? '');
    if ($os === '') {
        $os = $osVersion;
    }
    return preg_match('/linux|ubuntu|debian|rhel|red hat|centos|rocky|alma|fedora|suse|sles|arch|raspbian/i', $os) === 1;
}

/* A check is failing when it has reported and its status is anything but "ok". "unknown"/empty = never reported. */
function rivetLinuxFleetCheckFails(array $c): bool
{
    $s = strtolower(trim((string) ($c['status'] ?? '')));
    return $s !== '' && $s !== 'ok' && $s !== 'unknown';
}

/* The one place that decides a check key is a PostgreSQL check. */
function rivetLinuxFleetIsPgKey(string $key): bool
{
    $key = strtolower($key);
    return str_contains($key, 'postgres') || str_contains($key, 'pg_');
}

/* Query string to filters; anything that is not a plain digit string / the literal "attention" falls back to the default. */
function rivetLinuxFleetFilters(array $get): array
{
    $g = $get['group'] ?? null;
    return ['group' => is_string($g) && ctype_digit($g) ? (int) $g : 0, 'show' => ($get['show'] ?? null) === 'attention' ? 'attention' : 'all'];
}

/**
 * Page through a device list, 500 per page, up to $max rows.
 *
 * @param callable(int):array{items:list<array>,total:int} $page rows from an offset
 * @return array{items:list<array>,complete:bool}
 */
function rivetLinuxFleetListAll(callable $page, int $max = 5000): array
{
    $items = [];
    $off = 0;
    while (true) {
        $l = $page($off);
        $rows = (array) ($l['items'] ?? []);
        $total = (int) ($l['total'] ?? 0);
        foreach ($rows as $r) {
            $items[] = $r;
        }
        $off += 500;
        if ($rows === [] || $off >= $total) {
            return ['items' => array_slice($items, 0, $max), 'complete' => $total <= $max];
        }
        if ($off >= $max) {
            return ['items' => array_slice($items, 0, $max), 'complete' => false];
        }
    }
}

function rivetLinuxFleetPostgres(array $checks): array
{
    $state = 'none';
    $detail = '';
    foreach ($checks as $c) {
        if (!rivetLinuxFleetIsPgKey((string) ($c['key'] ?? ''))) {
            continue;
        }
        if (rivetLinuxFleetCheckFails($c)) {
            if ($state !== 'fail') {
                $detail = rivetLinuxFleetClean((string) ($c['detail'] ?? ''), 120);
            }
            $state = 'fail';
        } elseif ($state === 'none' && strtolower(trim((string) ($c['status'] ?? ''))) === 'ok') {
            $state = 'ok';
        }
    }
    return ['state' => $state, 'detail' => $detail];
}

function rivetLinuxFleetNode(array $summary, array $detail, array $patch, ?int $now = null): array
{
    $now ??= time();
    $scan = is_array($patch['scan'] ?? null) ? $patch['scan'] : [];
    $kinds = $patch['counts']['missing_by_kind'] ?? [];
    $sec = (int) ($kinds['critical'] ?? 0) + (int) ($kinds['security'] ?? 0);
    $missing = (int) ($patch['counts']['missing'] ?? 0);
    $scannedAt = (string) ($scan['scanned_at'] ?? '');
    $ts = $scannedAt !== '' ? strtotime($scannedAt) : false;
    $scanned = $ts !== false;
    $checks = is_array($detail['checks'] ?? null) ? $detail['checks'] : [];
    $failing = [];
    foreach ($checks as $c) {
        if (rivetLinuxFleetCheckFails($c) && !rivetLinuxFleetIsPgKey((string) ($c['key'] ?? ''))) {
            $failing[] = rivetLinuxFleetClean((string) ($c['key'] ?? ''), 60);
        }
    }
    return [
        'device_id' => (int) ($summary['device_id'] ?? 0),
        'hostname' => (string) ($summary['hostname'] ?? ''),
        'asset_id' => isset($summary['asset_id']) ? (int) $summary['asset_id'] : 0,
        'online' => ($summary['status'] ?? '') === 'online',
        'status' => (string) ($summary['status'] ?? ''),
        'os_version' => (string) ($summary['os_version'] ?? ''),
        'uptime_s' => isset($detail['uptime_s']) ? (int) $detail['uptime_s'] : null,
        'last_checkin_at' => $summary['last_checkin_at'] ?? null,
        'scanned' => $scanned,
        'scan_age_days' => $scanned ? max(0, intdiv($now - $ts, 86400)) : null,
        'sec_count' => $sec,
        'other_count' => max(0, $missing - $sec),
        'overdue' => (int) ($patch['overdue']['critical_or_security'] ?? 0),
        'reboot_pending' => filter_var($detail['pending_reboot'] ?? false, FILTER_VALIDATE_BOOLEAN) || filter_var($scan['reboot_pending'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'failing_checks' => $failing,
        'postgres' => rivetLinuxFleetPostgres($checks),
        'manager' => (string) ($scan['manager'] ?? ''),
    ];
}

function rivetLinuxFleetHealth(array $node): array
{
    if (empty($node['online'])) {
        return ['state' => 'down', 'reasons' => ['Offline']];
    }
    $r = [];
    if (empty($node['scanned'])) {
        $r[] = 'Never scanned';
    }
    if (($node['overdue'] ?? 0) > 0) {
        $r[] = $node['overdue'] . ' security update' . ($node['overdue'] === 1 ? '' : 's') . ' overdue';
    }
    if (!empty($node['reboot_pending'])) {
        $r[] = 'Restart pending';
    }
    foreach ($node['failing_checks'] ?? [] as $k) {
        if ($k === 'patch.compliance' && ($node['overdue'] ?? 0) > 0) {
            continue;   // already named above; with overdue 0 it is Core's stale-scan warning and stays
        }
        $r[] = 'Check failing: ' . $k;
    }
    if (($node['postgres']['state'] ?? '') === 'fail') {
        $r[] = 'PostgreSQL failing';
    }
    return ['state' => $r ? 'attention' : 'healthy', 'reasons' => $r];
}

function rivetLinuxFleetCluster(string $name, array $nodes): array
{
    $rank = ['down' => 0, 'attention' => 1, 'healthy' => 2];
    $counts = ['down' => 0, 'attention' => 0, 'healthy' => 0];
    $reboots = 0;
    $os = [];
    foreach ($nodes as &$n) {
        $n['health'] = rivetLinuxFleetHealth($n);
        $counts[$n['health']['state']]++;
        if (!empty($n['reboot_pending'])) {
            $reboots++;
        }
        if (($n['os_version'] ?? '') !== '') {
            $os[$n['os_version']] = true;
        }
    }
    unset($n);
    usort($nodes, fn($a, $b) => [$rank[$a['health']['state']], $a['hostname']] <=> [$rank[$b['health']['state']], $b['hostname']]);
    $flags = [];
    if ($reboots > 1) {
        $flags[] = "$reboots nodes need a restart";
    }
    if (count($os) > 1) {
        $v = array_keys($os);
        sort($v);
        $flags[] = 'OS versions differ: ' . implode(', ', $v);
    }
    return ['name' => $name, 'total' => count($nodes), 'healthy' => $counts['healthy'], 'attention' => $counts['attention'],
        'down' => $counts['down'], 'flags' => $flags, 'nodes' => $nodes];
}

const RIVET_LINUX_FLEET_CAP = 60;

/* Devices whose listed os_version says Windows or macOS never reach the per-device fetch, so they cannot use up the cap. An empty os_version is kept: the patch scan can still tell. */
function rivetLinuxFleetNotOtherOs(array $summaries): array
{
    return array_values(array_filter($summaries, fn($s) => preg_match('/windows|macos|darwin/i', (string) ($s['os_version'] ?? '')) !== 1));
}

/**
 * Which groups to list. $visible is the caller's visible client ids, null = unrestricted. A group with no visible device is dropped for a restricted caller (its name and size must not leak); an unrestricted caller sees it, empty.
 *
 * @param list<array<string,mixed>> $all
 * @return list<array{group_id:int,name:string}>
 */
function rivetLinuxFleetPickGroups(array $all, ?array $visible, int $only): array
{
    $out = [];
    foreach ($all as $g) {
        if (($visible === null || (int) ($g['device_count'] ?? 0) > 0) && ($only === 0 || (int) $g['group_id'] === $only)) {
            $out[] = ['group_id' => (int) $g['group_id'], 'name' => (string) $g['name']];
        }
    }
    return $out;
}

/**
 * Pure assembly over injected data.
 *
 * @param list<array{group_id:int,name:string}> $groups the groups to show, in order
 * @param array<int,list<array<string,mixed>>> $membersByGroup group_id => device summaries; key 0 = devices in no group (shown as "Ungrouped")
 * @param callable(int):?array{summary:array,detail:array,patch:array} $fetch one device's facts, null when it cannot be read
 * @return array{clusters:list<array>,totals:array,capped:bool,skipped:int}
 */
function rivetLinuxFleetBuild(array $groups, array $membersByGroup, callable $fetch, ?int $now = null, string $show = 'all'): array
{
    $now ??= time();
    $named = $groups;
    $named[] = ['group_id' => 0, 'name' => 'Ungrouped'];
    // Decide what to read before reading anything: unique non-revoked, non-Windows/macOS devices, not-online first, then the cap.
    $candidates = [];
    foreach ($named as $g) {
        foreach (rivetLinuxFleetNotOtherOs($membersByGroup[(int) $g['group_id']] ?? []) as $s) {
            $id = (int) ($s['device_id'] ?? 0);
            if (!empty($s['revoked']) || isset($candidates[$id])) {
                continue;
            }
            $candidates[$id] = $s;
        }
    }
    $ordered = array_values($candidates);
    usort($ordered, fn($a, $b) => (($a['status'] ?? '') !== 'online' ? 0 : 1) <=> (($b['status'] ?? '') !== 'online' ? 0 : 1));
    $loadIds = [];
    foreach (array_slice($ordered, 0, RIVET_LINUX_FLEET_CAP) as $s) {
        $loadIds[(int) $s['device_id']] = true;
    }
    $unloaded = count($candidates) - count($loadIds);

    $cache = [];      // device_id => node|false (false = skipped or not Linux)
    $skipped = 0;
    $load = function (array $s) use (&$cache, &$skipped, $loadIds, $fetch, $now) {
        $id = (int) ($s['device_id'] ?? 0);
        if (!empty($s['revoked'])) {
            return false;
        }
        if (!isset($loadIds[$id])) {
            return null;   // not loaded: over the cap
        }
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        $f = $fetch($id);
        if ($f === null) {
            $skipped++;
            return $cache[$id] = false;
        }
        $detail = (array) ($f['detail'] ?? []);
        $patch = (array) ($f['patch'] ?? []);
        $sum = $s + (array) ($f['summary'] ?? []);
        if (!rivetLinuxFleetIsLinux($detail, $patch, (string) ($sum['os_version'] ?? ''))) {
            return $cache[$id] = false;
        }
        return $cache[$id] = rivetLinuxFleetNode($sum, $detail, $patch, $now);
    };
    $clusters = [];
    foreach ($named as $g) {
        $gid = (int) $g['group_id'];
        $nodes = [];
        $notLoaded = 0;
        foreach (rivetLinuxFleetNotOtherOs($membersByGroup[$gid] ?? []) as $s) {
            $n = $load($s);
            if ($n === null) {
                $notLoaded++;
            } elseif ($n !== false) {
                $nodes[] = $n;
            }
        }
        if ($gid === 0 && $nodes === [] && $notLoaded === 0) {
            continue;
        }
        $cl = rivetLinuxFleetCluster((string) $g['name'], $nodes);
        $cl['unloaded'] = $notLoaded;
        $clusters[] = $cl;
    }
    $totals = ['nodes' => 0, 'healthy' => 0, 'attention' => 0, 'down' => 0, 'security_pending' => 0, 'unloaded' => $unloaded];
    foreach ($cache as $n) {
        if ($n === false) {
            continue;
        }
        $totals['nodes']++;
        $totals[rivetLinuxFleetHealth($n)['state']]++;
        $totals['security_pending'] += $n['sec_count'];
    }
    if ($show === 'attention') {
        $kept = [];
        foreach ($clusters as $c) {
            $c['nodes'] = array_values(array_filter($c['nodes'], fn($n) => $n['health']['state'] !== 'healthy'));
            if ($c['nodes'] !== [] || $c['unloaded'] > 0) {
                $kept[] = $c;
            }
        }
        $clusters = $kept;
    }
    return ['clusters' => $clusters, 'totals' => $totals, 'capped' => $unloaded > 0, 'skipped' => $skipped];
}

/**
 * The page's view model. Reads through RivetCore as the signed-in user: department scope applies to the group list, the member lists and each device read.
 *
 * @param array $ctx rivetRmmPatPage() context
 * @param array $get the query string (group = group id, 0 or absent = all; show = all|attention)
 * @return array{error:string,clusters:list<array>,totals:array,capped:bool,skipped:int,filters:array{group:int,show:string}}
 */
function rivetLinuxFleetView(array $ctx, array $get): array
{
    require_once __DIR__ . '/rmm_pat_ui.php';
    ['group' => $group, 'show' => $show] = rivetLinuxFleetFilters($get);
    $vm = ['error' => '', 'clusters' => [], 'totals' => ['nodes' => 0, 'healthy' => 0, 'attention' => 0, 'down' => 0, 'security_pending' => 0, 'unloaded' => 0],
        'capped' => false, 'list_incomplete' => false, 'skipped' => 0, 'groups' => [], 'filters' => ['group' => $group, 'show' => $show]];
    try {
        $rmm = rivetRmmModule($ctx['mysqli']);
        $visible = $rmm->authorizer()->visibleClientIds($ctx['uid']);
        $read = $rmm->readModel();
        $vm['groups'] = rivetLinuxFleetPickGroups($read->groups($visible), $visible, 0);   // the filter's options
        $groups = $group === 0 ? $vm['groups'] : array_values(array_filter($vm['groups'], fn($g) => $g['group_id'] === $group));
        $members = [];
        $inGroup = [];
        foreach ($groups as $g) {
            $l = rivetLinuxFleetListAll(fn(int $off) => $read->listDevices(['group' => $g['group_id'], 'retired' => 'hide'], $visible, 500, $off, false));   // wide on purpose: Windows/macOS rows are dropped in Build before the cap counts
            $vm['list_incomplete'] = $vm['list_incomplete'] || !$l['complete'];
            $members[$g['group_id']] = rivetRmmExcludePanelDevices($l['items']);
            foreach ($members[$g['group_id']] as $d) {
                $inGroup[(int) $d['device_id']] = true;
            }
        }
        $members[0] = [];
        if ($group === 0) {
            // Devices in no group. A device is "ungrouped" only if it appeared in no listed group.
            $all = rivetLinuxFleetListAll(fn(int $off) => $read->listDevices(['retired' => 'hide'], $visible, 500, $off, false));
            $vm['list_incomplete'] = $vm['list_incomplete'] || !$all['complete'];
            foreach (rivetLinuxFleetNotOtherOs(rivetRmmExcludePanelDevices($all['items'])) as $d) {
                if (!isset($inGroup[(int) $d['device_id']])) {
                    $members[0][] = $d;
                }
            }
        }
        $fetch = function (int $id) use ($ctx): ?array {
            $d = rivetRmmPatApi($ctx, 'GET', [(string) $id]);
            if (!$d['ok']) {
                return null;
            }
            $p = rivetRmmPatApi($ctx, 'GET', [(string) $id, 'patches'], ['limit' => '1']);
            if (!$p['ok']) {
                return null;
            }
            return ['summary' => $d['data'], 'detail' => $d['data'], 'patch' => (array) ($p['data']['summary'] ?? [])];
        };
        $built = rivetLinuxFleetBuild($groups, $members, $fetch, null, $show);
        $vm = $built + $vm;
    } catch (\Throwable $e) {
        $vm['error'] = 'The Linux fleet could not be read.';
    }
    return $vm;
}

/**
 * The page. Every value is escaped here with rmmH(); the markup carries no script and no inline handler.
 *
 * @param array<string,mixed> $vm rivetLinuxFleetView()
 */
function rivetLinuxFleetPage(array $vm): string
{
    require_once __DIR__ . '/rmm_pat_ui.php';
    $links = '<a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_patches.php"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i>Patch compliance</a>';
    $o = rivetRmmAutoHeader('Linux fleet', 'server', $links, 'Linux servers by group: which are up, which need attention, and what they are missing. Read-only; groups are the clusters.');
    if (($vm['error'] ?? '') !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $f = (array) ($vm['filters'] ?? []);
    $t = (array) ($vm['totals'] ?? []);

    $groups = '';
    foreach ((array) ($vm['groups'] ?? []) as $g) {
        $groups .= '<option value="' . (int) $g['group_id'] . '"' . ((int) ($f['group'] ?? 0) === (int) $g['group_id'] ? ' selected' : '') . '>' . rmmH($g['name']) . '</option>';
    }
    $o .= '<form method="get" action="/agent/rmm_linux_fleet.php" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end">'
        . '<div class="col-md-4"><label class="form-label" for="lf_group">Group</label><select class="form-select" id="lf_group" name="group"><option value="0">All groups</option>' . $groups . '</select></div>'
        . '<div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" id="lf_show" name="show" value="attention"' . (($f['show'] ?? '') === 'attention' ? ' checked' : '') . '><label class="form-check-label" for="lf_show">Needs attention only</label></div></div>'
        . '<div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Apply</button></div></div></div></form>';

    $n = static fn(string $k): int => (int) ($t[$k] ?? 0);
    $capped = !empty($vm['capped']);
    $o .= '<div class="row g-2 mb-3">'
        . rivetRmmPatKpi('Servers', number_format($n('nodes')), '', $capped ? 'first ' . RIVET_LINUX_FLEET_CAP . ' read' : '')
        . rivetRmmPatKpi('Healthy', number_format($n('healthy')), !$capped && $n('healthy') > 0 && $n('attention') + $n('down') === 0 ? 'success' : '')
        . rivetRmmPatKpi('Need attention', number_format($n('attention')), $n('attention') > 0 ? 'warning' : '')
        . rivetRmmPatKpi('Down', number_format($n('down')), $n('down') > 0 ? 'danger' : '')
        . rivetRmmPatKpi('Security updates pending', number_format($n('security_pending')), $n('security_pending') > 0 ? 'warning' : '')
        . '</div>';
    if ($capped) {
        $o .= '<div class="alert alert-warning" role="status">Read the first ' . RIVET_LINUX_FLEET_CAP . ' servers (servers that are not online first). ' . number_format($n('unloaded')) . ' more devices were not loaded and are not in the counts above.'
            . ((int) ($f['group'] ?? 0) === 0 ? ' Choose a group to see them.' : '') . '</div>';
    }
    if (!empty($vm['list_incomplete'])) {
        $o .= '<div class="alert alert-warning" role="status">More than 5,000 devices: those past the first 5,000 by hostname were not checked.</div>';
    }
    if ((int) ($vm['skipped'] ?? 0) > 0) {
        $o .= '<p class="small text-muted">' . (int) $vm['skipped'] . ' servers could not be read and are not counted.</p>';
    }

    foreach ((array) ($vm['clusters'] ?? []) as $c) {
        $kind = (int) $c['down'] > 0 ? 'crit' : ((int) $c['attention'] > 0 ? 'warn' : 'ok');
        $un = (int) ($c['unloaded'] ?? 0);
        $badge = (int) $c['total'] === 0
            ? rivetRmmUiPill('off', $un > 0 ? 'Not loaded' : 'No Linux servers')
            : rivetRmmUiPill($kind, (int) $c['healthy'] . ' of ' . (int) $c['total'] . ' healthy') . ($un > 0 ? ' ' . rivetRmmUiPill('off', $un . ' not loaded') : '');
        $body = '';
        foreach ((array) $c['flags'] as $flag) {
            $body .= '<div class="alert alert-warning mx-3 mt-3 mb-0 py-2" role="status">' . rmmH($flag) . '</div>';
        }
        if ((array) $c['nodes'] === []) {
            $body .= $un > 0
                ? rivetRmmUiEmpty('fas fa-server', $un . ' devices not loaded', 'Over the ' . RIVET_LINUX_FLEET_CAP . '-server limit for one view. Choose this group in the filter to see them.')
                : rivetRmmUiEmpty('fas fa-server', 'No Linux servers in this group');
        } else {
            $rows = '';
            foreach ((array) $c['nodes'] as $nd) {
                $host = rmmH($nd['hostname']) . (($nd['os_version'] ?? '') !== '' ? '<div class="small text-muted">' . rmmH($nd['os_version']) . '</div>' : '');
                if ((int) ($nd['asset_id'] ?? 0) > 0) {
                    $host = '<a href="/agent/rmm_asset.php?asset_id=' . (int) $nd['asset_id'] . '">' . rmmH($nd['hostname']) . '</a>'
                        . (($nd['os_version'] ?? '') !== '' ? '<div class="small text-muted">' . rmmH($nd['os_version']) . '</div>' : '');
                }
                $state = (string) ($nd['health']['state'] ?? 'healthy');
                $pill = $state === 'down' ? rivetRmmUiPill('crit', 'Down') : ($state === 'attention' ? rivetRmmUiPill('warn', 'Attention') : rivetRmmUiPill('ok', 'Healthy'));
                $upd = !empty($nd['scanned']) ? rmmH((int) $nd['sec_count'] . ' security / ' . (int) $nd['other_count'] . ' other') : '<span class="text-muted">Never scanned</span>';
                $scan = !empty($nd['scanned']) ? rmmH((int) $nd['scan_age_days'] === 0 ? 'Today' : (int) $nd['scan_age_days'] . 'd ago') : '<span class="text-muted">—</span>';
                $checks = ($nd['failing_checks'] ?? []) === [] ? 'OK' : rmmH(implode(', ', (array) $nd['failing_checks']));
                $pg = (string) ($nd['postgres']['state'] ?? 'none');
                $pgCell = $pg === 'fail' ? rmmH('Failing' . ((string) ($nd['postgres']['detail'] ?? '') !== '' ? ': ' . (string) $nd['postgres']['detail'] : '')) : ($pg === 'ok' ? 'OK' : '<span class="text-muted">—</span>');
                $why = rmmH(implode('; ', (array) ($nd['health']['reasons'] ?? [])));
                $rows .= '<tr><td class="ps-3">' . $host . '</td><td>' . $pill . '</td><td>' . $upd . '</td><td>' . (!empty($nd['reboot_pending']) ? 'Pending' : '<span class="text-muted">No</span>')
                    . '</td><td>' . $scan . '</td><td>' . $checks . '</td><td>' . $pgCell . '</td><td class="rmmx-msg">' . $why . '</td></tr>';
            }
            $body .= '<div class="table-responsive"><table class="table table-sm mb-0 rmmx-table"><caption class="visually-hidden">Linux servers in ' . rmmH($c['name']) . '</caption>'
                . rivetRmmUiThead(['Server', 'Status', 'Updates', 'Restart', 'Last scan', 'Checks', 'PostgreSQL', 'Why']) . '<tbody>' . $rows . '</tbody></table></div>';
        }
        $o .= rivetRmmUiCard(rmmH($c['name']), 'server', $body, '', $badge, true);
    }
    if ((array) ($vm['clusters'] ?? []) === []) {
        $o .= rivetRmmUiEmpty('fas fa-server', 'No Linux servers to show', 'Linux servers appear here once they are enrolled and approved.');
    }
    return $o;
}
