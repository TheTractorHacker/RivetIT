<?php
/* Linux Fleet: pure model for the read-only cluster-health page. No DB, no includes. */

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

function rivetLinuxFleetPostgres(array $checks): array
{
    $state = 'none';
    $detail = '';
    foreach ($checks as $c) {
        $key = strtolower((string) ($c['key'] ?? ''));
        if (!str_contains($key, 'postgres') && !str_contains($key, 'pg_')) {
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
        if (rivetLinuxFleetCheckFails($c)) {
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
    $cache = [];      // device_id => node|false (false = skipped or not Linux)
    $fetched = 0;
    $skipped = 0;
    $capped = false;
    $load = function (array $s) use (&$cache, &$fetched, &$skipped, &$capped, $fetch, $now) {
        $id = (int) ($s['device_id'] ?? 0);
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        if ($fetched >= RIVET_LINUX_FLEET_CAP) {
            $capped = true;
            return $cache[$id] = false;
        }
        $fetched++;
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
    $named = $groups;
    $named[] = ['group_id' => 0, 'name' => 'Ungrouped'];
    $clusters = [];
    foreach ($named as $g) {
        $gid = (int) $g['group_id'];
        if ($gid === 0 && ($membersByGroup[0] ?? []) === []) {
            continue;
        }
        $nodes = [];
        foreach ($membersByGroup[$gid] ?? [] as $s) {
            $n = $load($s);
            if ($n !== false) {
                $nodes[] = $n;
            }
        }
        if ($gid === 0 && $nodes === []) {
            continue;
        }
        $clusters[] = rivetLinuxFleetCluster((string) $g['name'], $nodes);
    }
    $totals = ['nodes' => 0, 'healthy' => 0, 'attention' => 0, 'down' => 0, 'security_pending' => 0];
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
            if ($c['nodes'] !== []) {
                $kept[] = $c;
            }
        }
        $clusters = $kept;
    }
    return ['clusters' => $clusters, 'totals' => $totals, 'capped' => $capped, 'skipped' => $skipped];
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
    $group = ctype_digit((string) ($get['group'] ?? '')) ? (int) $get['group'] : 0;
    $show = ($get['show'] ?? '') === 'attention' ? 'attention' : 'all';
    $vm = ['error' => '', 'clusters' => [], 'totals' => ['nodes' => 0, 'healthy' => 0, 'attention' => 0, 'down' => 0, 'security_pending' => 0],
        'capped' => false, 'skipped' => 0, 'filters' => ['group' => $group, 'show' => $show]];
    try {
        $rmm = rivetRmmModule($ctx['mysqli']);
        $visible = $rmm->authorizer()->visibleClientIds($ctx['uid']);
        $read = $rmm->readModel();
        $groups = [];
        foreach ($read->groups($visible) as $g) {
            // A group none of whose devices the user may see is not listed: its name and size must not leak.
            if ((int) ($g['device_count'] ?? 0) > 0 && ($group === 0 || (int) $g['group_id'] === $group)) {
                $groups[] = ['group_id' => (int) $g['group_id'], 'name' => (string) $g['name']];
            }
        }
        $members = [];
        $inGroup = [];
        foreach ($groups as $g) {
            $l = $read->listDevices(['group' => $g['group_id'], 'retired' => 'hide'], $visible, RIVET_LINUX_FLEET_CAP + 1, 0);
            $members[$g['group_id']] = rivetRmmExcludePanelDevices($l['items']);
            foreach ($members[$g['group_id']] as $d) {
                $inGroup[(int) $d['device_id']] = true;
            }
        }
        $members[0] = [];
        if ($group === 0) {
            // Devices in no group. A device is "ungrouped" only if it appeared in no listed group.
            $all = $read->listDevices(['retired' => 'hide'], $visible, 500, 0);
            if ((int) ($all['total'] ?? 0) > 500) {
                $vm['capped'] = true;
            }
            foreach (rivetRmmExcludePanelDevices($all['items']) as $d) {
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
        $vm['capped'] = $built['capped'] || $vm['capped'];
    } catch (\Throwable $e) {
        $vm['error'] = 'The Linux fleet could not be read.';
    }
    return $vm;
}
