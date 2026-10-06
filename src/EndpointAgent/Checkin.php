<?php

namespace ITFlow\EndpointAgent;

use ITFlow\Metrics\MetricIngestService;
use ITFlow\Metrics\MetricSample;

/**
 * POST /api/v1/agent_checkin processing: validation, idempotency by (device_id, seq), inventory, metrics (through the existing
 * MetricIngestService, so the existing charts/rollups/retention apply), checks (debounced alerts) and the response.
 *
 * A missing metric (null or absent) produces NO sample row. It is never stored as 0.
 */
final class Checkin
{
    public const MAX_BODY_BYTES = 1048576;
    public const MAX_CHECKS = 100;
    public const MAX_BUFFERED = 100;
    public const MAX_DISKS = 32;
    public const MAX_INVENTORY_BYTES = 65536;
    private const FUTURE_SKEW_S = 300;

    /** @return array<string,mixed> */
    public static function handle(array $dev, array $body): array
    {
        global $mysqli;
        $cfg = Config::get();

        $seq = $body['seq'] ?? null;
        if (!is_int($seq) || $seq < 0 || $seq > 9007199254740991) {
            throw new ApiError(422, 'invalid', 'seq must be a non-negative integer');
        }
        $collected = self::parseTime($body['collected_at'] ?? null, 'collected_at', $cfg);
        $ver = $body['agent_version'] ?? null;
        if (!is_string($ver) || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?$/', $ver)) {
            throw new ApiError(422, 'invalid', 'agent_version is invalid');
        }
        $inventory = $body['inventory'] ?? null;
        if ($inventory !== null) {
            if (!is_array($inventory) || array_is_list($inventory) || strlen((string) json_encode($inventory)) > self::MAX_INVENTORY_BYTES) {
                throw new ApiError(422, 'invalid', 'inventory must be an object under ' . self::MAX_INVENTORY_BYTES . ' bytes');
            }
        }
        $metrics = $body['metrics'] ?? null;
        if ($metrics !== null && (!is_array($metrics) || (array_is_list($metrics) && $metrics))) {
            throw new ApiError(422, 'invalid', 'metrics must be an object');
        }
        $checks = $body['checks'] ?? [];
        if (!is_array($checks) || !array_is_list($checks) || count($checks) > self::MAX_CHECKS) {
            throw new ApiError(422, 'invalid', 'checks must be a list of at most ' . self::MAX_CHECKS . ' items');
        }
        $buffered = $body['buffered'] ?? [];
        if (!is_array($buffered) || !array_is_list($buffered) || count($buffered) > self::MAX_BUFFERED) {
            throw new ApiError(422, 'invalid', 'buffered must be a list of at most ' . self::MAX_BUFFERED . ' samples');
        }
        $primaryChecks = self::cleanChecks($checks, true);

        $mysqli->begin_transaction();
        try {
            // Serialise check-ins of ONE device; different devices never contend.
            $cur = Db::one('SELECT * FROM endpoint_agent_devices WHERE device_id = ? FOR UPDATE', [$dev['device_id']]);
            if (!$cur || $cur['revoked_at'] !== null || $cur['retired_at'] !== null || $cur['token_hash'] !== $dev['token_hash']) {
                $mysqli->rollback();
                throw new ApiError(401, 'revoked', 'This device credential was revoked.');
            }
            $now = Db::utcNow();
            $fresh = Db::run('INSERT IGNORE INTO endpoint_agent_checkins (device_id, seq, received_at, collected_at) VALUES (?, ?, ?, ?)',
                [$cur['device_id'], $seq, $now, $collected->format('Y-m-d H:i:s')]);
            // Liveness is real even for a duplicate delivery.
            Db::run('UPDATE endpoint_agent_devices SET last_checkin_at = ?, last_ip = ? WHERE device_id = ?', [$now, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $cur['device_id']]);
            if ($fresh === 1) {
                self::process($cur, $seq, $collected, $ver, $inventory, $metrics, $primaryChecks, $buffered, $cfg, $body['update_result'] ?? null);
            }
            $mysqli->commit();
        } catch (\Throwable $e) {
            @$mysqli->rollback();
            throw $e;
        }

        $dev = Devices::find((int) $dev['device_id']);
        return [
            'ok' => true,
            'next_check_in_s' => (int) $cfg['check_in_interval_s'],
            'jobs_pending' => Jobs::pendingCount((int) $dev['device_id']),
            'config' => ['checks' => Config::signedChecks(), 'collect_interval_s' => (int) $cfg['collect_interval_s']],
            'update' => Updates::manifestFor($dev),
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            'signing_key_id' => Config::get()['signing_key_id'],
        ];
    }

    private static function process(array $dev, int $seq, \DateTimeImmutable $collected, string $ver, ?array $inventory, ?array $metrics, array $primaryChecks, array $buffered, array $cfg, $updateResult = null): void
    {
        global $mysqli;
        $deviceId = (int) $dev['device_id'];
        $collectedSql = $collected->format('Y-m-d H:i:s');
        $newer = $dev['last_collected_at'] === null || $collectedSql >= $dev['last_collected_at'];
        Updates::recordResult($dev, $updateResult);

        // ---- inventory ----
        if ($inventory !== null && $newer) {
            self::applyInventory($dev, $inventory);
        }
        if ($newer) {
            Db::run('UPDATE endpoint_agent_devices SET agent_version = ?, last_collected_at = ?, last_seq = GREATEST(last_seq, ?),
                last_metrics_json = ? WHERE device_id = ?', [$ver, $collectedSql, $seq, $metrics === null ? null : json_encode(self::cleanMetrics($metrics)), $deviceId]);
        } else {
            Db::run('UPDATE endpoint_agent_devices SET last_seq = GREATEST(last_seq, ?) WHERE device_id = ?', [$seq, $deviceId]);
        }
        $dev = Devices::find($deviceId);

        // ---- metrics, current sample and buffered backlog ----
        $samples = [];
        $assetId = ($dev['link_state'] === 'linked' && $dev['asset_id']) ? (int) $dev['asset_id'] : 0;
        $batches = [[$collected, $metrics, $inventory]];
        $bufChecks = [];
        $dropped = 0;
        foreach ($buffered as $b) {
            if (!is_array($b)) {
                $dropped++;
                continue;
            }
            try {
                $bt = self::parseTime($b['collected_at'] ?? null, 'buffered.collected_at', $cfg);
            } catch (ApiError $e) {
                $dropped++;
                continue;
            }
            $bm = $b['metrics'] ?? null;
            $batches[] = [$bt, is_array($bm) ? $bm : null, null];
            if (isset($b['checks']) && is_array($b['checks']) && array_is_list($b['checks'])) {
                foreach (self::cleanChecks(array_slice($b['checks'], 0, self::MAX_CHECKS), false) as $c) {
                    $c['at'] = $bt->format('Y-m-d H:i:s');
                    $bufChecks[] = $c;
                }
            }
        }
        if ($assetId) {
            foreach ($batches as [$at, $m, $inv]) {
                foreach (self::samplesFor($assetId, $at, $m, $inv) as $s) {
                    $samples[] = $s;
                }
            }
            if ($samples) {
                (new MetricIngestService($mysqli))->ingest($samples, Config::integrationId());
            }
        }

        // ---- health columns on the existing RMM link ----
        $health = ['cpu' => self::num($metrics['cpu_pct'] ?? null), 'mem' => self::num($metrics['mem_pct'] ?? null), 'disk' => null];
        foreach (is_array($metrics['disk'] ?? null) ? $metrics['disk'] : [] as $dk) {
            $u = is_array($dk) ? self::num($dk['used_pct'] ?? null) : null;
            if ($u !== null && ($health['disk'] === null || $u > $health['disk'])) {
                $health['disk'] = $u;
            }
        }
        Link::applyCheckin($dev, $health);

        // ---- checks: backlog oldest first, then the current results ----
        usort($bufChecks, static fn($a, $b) => strcmp($a['at'], $b['at']));
        Checks::apply($dev, array_merge($bufChecks, $primaryChecks));
    }

    private static function applyInventory(array $dev, array $inv): void
    {
        $txt = static fn($v, int $n) => Enrollment::cleanText($v, $n);
        $deviceId = (int) $dev['device_id'];
        $macs = [];
        foreach (is_array($inv['network'] ?? null) ? array_slice($inv['network'], 0, 32) : [] as $nic) {
            $mac = is_array($nic) && is_string($nic['mac'] ?? null) ? Enrollment::normalizeMac($nic['mac']) : null;
            if ($mac !== null) {
                $macs[$mac] = $mac;
            }
        }
        $clean = [
            'hostname' => $txt($inv['hostname'] ?? null, 200),
            'os' => $txt($inv['os'] ?? null, 50),
            'os_version' => $txt($inv['os_version'] ?? null, 200),
            'manufacturer' => $txt($inv['manufacturer'] ?? null, 200),
            'model' => $txt($inv['model'] ?? null, 200),
            'serial' => $txt($inv['serial'] ?? null, 100),
            'cpu' => ['model' => $txt($inv['cpu']['model'] ?? null, 200), 'cores' => self::intOrNull($inv['cpu']['cores'] ?? null)],
            'memory_total_bytes' => self::intOrNull($inv['memory_total_bytes'] ?? null),
            'uptime_s' => self::intOrNull($inv['uptime_s'] ?? null),
            'logged_in_user' => $txt($inv['logged_in_user'] ?? null, 200),
            'pending_reboot' => isset($inv['pending_reboot']) ? (bool) $inv['pending_reboot'] : null,
            'disks' => [],
            'network' => [],
        ];
        foreach (is_array($inv['disks'] ?? null) ? array_slice($inv['disks'], 0, self::MAX_DISKS) : [] as $d) {
            if (is_array($d) && ($mount = $txt($d['mount'] ?? null, 64)) !== null) {
                $clean['disks'][] = ['mount' => $mount, 'total_bytes' => self::intOrNull($d['total_bytes'] ?? null), 'free_bytes' => self::intOrNull($d['free_bytes'] ?? null), 'fs' => $txt($d['fs'] ?? null, 20)];
            }
        }
        foreach (is_array($inv['network'] ?? null) ? array_slice($inv['network'], 0, 32) : [] as $n) {
            if (!is_array($n)) {
                continue;
            }
            $ips = [];
            foreach (is_array($n['ips'] ?? null) ? array_slice($n['ips'], 0, 16) : [] as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
            $clean['network'][] = ['name' => $txt($n['name'] ?? null, 100), 'mac' => is_string($n['mac'] ?? null) ? Enrollment::normalizeMac($n['mac']) : null, 'ips' => $ips];
        }
        $meshNode = $inv['mesh_node_id'] ?? null;
        $uptime = $clean['uptime_s'];

        Db::run('UPDATE endpoint_agent_devices SET inventory_json = ?, last_inventory_at = ?, hostname = COALESCE(?, hostname), os_version = COALESCE(?, os_version),
            manufacturer = COALESCE(?, manufacturer), model = COALESCE(?, model), serial = COALESCE(serial, ?), mac_addresses = ?, logged_in_user = ?, pending_reboot = ?, uptime_s = ?
            WHERE device_id = ?',
            [json_encode($clean), Db::utcNow(), $clean['hostname'], $clean['os_version'], $clean['manufacturer'], $clean['model'], Enrollment::cleanSerial($clean['serial']),
                $macs ? json_encode(array_values($macs)) : $dev['mac_addresses'], $clean['logged_in_user'], $clean['pending_reboot'] === null ? null : (int) $clean['pending_reboot'], $uptime, $deviceId]);

        if (is_string($meshNode) && Mesh::validNodeId($meshNode)) {
            $existing = Db::one('SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$deviceId]);
            // A node an administrator set by hand is never overwritten by what the device claims.
            if (!$existing || $existing['source'] === 'agent') {
                Devices::setMeshNode($deviceId, $meshNode, 0, 'agent');
            }
        }
    }

    /** @return list<MetricSample> */
    private static function samplesFor(int $assetId, \DateTimeImmutable $at, ?array $m, ?array $inv): array
    {
        $out = [];
        $add = static function (string $key, ?string $inst, $val, ?string $label = null) use (&$out, $assetId, $at) {
            if ($val === null) {
                return;   // a missing reading is a missing sample, never zero
            }
            $s = MetricSample::tryOf($assetId, $key, $inst, $val, $at, $label);
            if ($s !== null) {
                $out[] = $s;
            }
        };
        if ($m !== null) {
            $add('cpu.utilization', null, self::num($m['cpu_pct'] ?? null));
            $add('memory.utilization', null, self::num($m['mem_pct'] ?? null));
            foreach (is_array($m['disk'] ?? null) ? array_slice($m['disk'], 0, self::MAX_DISKS) : [] as $d) {
                if (is_array($d) && is_string($d['mount'] ?? null) && $d['mount'] !== '') {
                    $add('disk.utilization', mb_substr($d['mount'], 0, 64), self::num($d['used_pct'] ?? null), mb_substr($d['mount'], 0, 64));
                }
            }
            $add('network.rx_bytes_per_s', 'total', self::num($m['net_rx_bps'] ?? null), 'All adapters');
            $add('network.tx_bytes_per_s', 'total', self::num($m['net_tx_bps'] ?? null), 'All adapters');
        }
        if ($inv !== null) {
            $add('system.uptime_seconds', null, self::intOrNull($inv['uptime_s'] ?? null));
            if (isset($inv['pending_reboot'])) {
                $add('system.pending_reboot', null, $inv['pending_reboot'] ? 1 : 0);
            }
            $add('memory.total_bytes', null, self::intOrNull($inv['memory_total_bytes'] ?? null));
            foreach (is_array($inv['disks'] ?? null) ? array_slice($inv['disks'], 0, self::MAX_DISKS) : [] as $d) {
                if (is_array($d) && is_string($d['mount'] ?? null) && $d['mount'] !== '') {
                    $mt = mb_substr($d['mount'], 0, 64);
                    $add('disk.total_bytes', $mt, self::intOrNull($d['total_bytes'] ?? null), $mt);
                    $add('disk.free_bytes', $mt, self::intOrNull($d['free_bytes'] ?? null), $mt);
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ small validators

    public static function parseTime($raw, string $field, array $cfg): \DateTimeImmutable
    {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/', $raw)) {
            throw new ApiError(422, 'invalid', "$field must be an RFC 3339 timestamp");
        }
        try {
            $dt = (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new ApiError(422, 'invalid', "$field must be an RFC 3339 timestamp");
        }
        $ts = $dt->getTimestamp();
        if ($ts > time() + self::FUTURE_SKEW_S) {
            throw new ApiError(422, 'invalid', "$field is in the future");
        }
        if ($ts < time() - (int) $cfg['retention_days'] * 86400) {
            throw new ApiError(422, 'invalid', "$field is older than the retention window");
        }
        return $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), (int) $dt->format('s'));
    }

    /** @return list<array{key:string,status:string,detail:string,at:string}> */
    private static function cleanChecks(array $checks, bool $strict): array
    {
        $out = [];
        $seen = [];
        foreach ($checks as $c) {
            $key = is_array($c) ? ($c['key'] ?? null) : null;
            $status = is_array($c) ? ($c['status'] ?? null) : null;
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $key) || !in_array($status, ['ok', 'warn', 'fail', 'unknown'], true)) {
                if ($strict) {
                    throw new ApiError(422, 'invalid', 'each check needs a valid key and a status of ok, warn, fail or unknown');
                }
                continue;
            }
            if (isset($seen[$key])) {
                continue;   // one result per check per check-in
            }
            $seen[$key] = 1;
            $detail = Enrollment::cleanText($c['detail'] ?? '', 500) ?? '';
            $out[] = ['key' => $key, 'status' => $status, 'detail' => $detail, 'at' => Db::utcNow()];
        }
        return $out;
    }

    private static function cleanMetrics(array $m): array
    {
        $o = ['cpu_pct' => self::num($m['cpu_pct'] ?? null), 'mem_pct' => self::num($m['mem_pct'] ?? null), 'disk' => [],
            'net_rx_bps' => self::num($m['net_rx_bps'] ?? null), 'net_tx_bps' => self::num($m['net_tx_bps'] ?? null)];
        foreach (is_array($m['disk'] ?? null) ? array_slice($m['disk'], 0, self::MAX_DISKS) : [] as $d) {
            if (is_array($d) && is_string($d['mount'] ?? null)) {
                $o['disk'][] = ['mount' => mb_substr($d['mount'], 0, 64), 'used_pct' => self::num($d['used_pct'] ?? null)];
            }
        }
        return $o;
    }

    private static function num($v): ?float
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) ? (float) $v : null;
    }

    private static function intOrNull($v): ?int
    {
        return (is_int($v) && $v >= 0) ? $v : ((is_float($v) && is_finite($v) && $v >= 0 && $v < 9e15) ? (int) $v : null);
    }
}
