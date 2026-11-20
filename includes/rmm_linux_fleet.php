<?php
/* Linux Fleet: pure model for the read-only cluster-health page. No DB, no includes. */

function rivetLinuxFleetIsLinux(array $detail, array $patch): bool
{
    $mgr = strtolower((string) ($patch['scan']['manager'] ?? ''));
    if ($mgr === 'wua') {
        return false;
    }
    if (in_array($mgr, ['apt', 'dnf', 'yum', 'zypper', 'pacman', 'apk'], true)) {
        return true;
    }
    $os = strtolower((string) ($detail['os_version'] ?? $detail['os'] ?? ''));
    return $os !== '' && !str_contains($os, 'windows') && !str_contains($os, 'macos') && !str_contains($os, 'darwin');
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
                $detail = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) ($c['detail'] ?? '')) ?? '', 0, 120);
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
            $failing[] = (string) ($c['key'] ?? '');
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
        'reboot_pending' => ($detail['pending_reboot'] ?? false) === true || ($scan['reboot_pending'] ?? false) === true,
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
