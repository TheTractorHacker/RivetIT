<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use ITFlow\Metrics\MetricIngestService;
use ITFlow\Metrics\MetricSample;
use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * Feeds check-in samples into RivetIT's metrics subsystem (device_metric_samples) exactly as the old check-in code did: each sample
 * becomes a MetricSample (out-of-range values are rejected there, never clamped) and goes through MetricIngestService.
 *
 * It is also the module's metric READER (RivetCore 1.0.0-rc.9, RmmMetricReaderInterface): latest(), peak() and series() answer from the
 * same tables, so the device page can show the network bar against the real 24 hour peak. Windows are hour granular, as the contract
 * says. The last RAW_WINDOW_S seconds come from the raw samples (always current, whatever the rollup cron has done); anything older comes
 * from the hour rollups (device_metric_rollups, bucket = 'hour'), which keep the minimum, maximum, sum and count of each hour. Times are
 * UTC in both tables.
 */
final class EndpointMetricSink implements RmmMetricSinkInterface, RmmMetricReaderInterface
{
    /** Up to this age the raw samples answer; older hours come from the rollups. */
    private const RAW_WINDOW_S = 172800;
    /** Rows one latest() call reads at most (a device has about fifteen series). */
    private const LATEST_ROWS = 200;
    /** Points one series() call returns at most. */
    private const SERIES_POINTS = 2000;

    public function __construct(private \mysqli $mysqli)
    {
    }

    public function ingest(array $samples, int $integrationId): void
    {
        $out = [];
        foreach ($samples as $s) {
            $m = MetricSample::tryOf((int) $s['asset_id'], (string) $s['key'], $s['instance'], $s['value'], $s['at'], $s['label']);
            if ($m !== null) {
                $out[] = $m;
            }
        }
        if ($out !== []) {
            (new MetricIngestService($this->mysqli))->ingest($out, $integrationId);
        }
    }

    // ------------------------------------------------------------------ reader

    public function latest(int $assetId, ?array $keys = null): array
    {
        if ($assetId <= 0 || $keys === []) {
            return [];
        }
        $from = gmdate('Y-m-d H:i:s', time() - self::RAW_WINDOW_S);
        $metricFilter = '';
        $types = 'is';
        $params = [$assetId, $from];
        if ($keys !== null) {
            $keys = array_values(array_unique(array_map('strval', $keys)));
            $metricFilter = ' AND metric_id IN (SELECT metric_id FROM device_metric_defs WHERE metric_key IN (' . implode(',', array_fill(0, count($keys), '?')) . '))';
            $types .= str_repeat('s', count($keys));
            array_push($params, ...$keys);
        }
        // The newest sample of each (metric, instance): the primary key ends in sampled_at, so the group maximum is an index read.
        $sql = 'SELECT d.metric_key, s.instance_id, i.instance_key, i.instance_label, s.metric_value, s.sampled_at
                FROM (SELECT metric_id, instance_id, MAX(sampled_at) AS newest FROM device_metric_samples WHERE asset_id = ? AND sampled_at >= ?' . $metricFilter . '
                      GROUP BY metric_id, instance_id LIMIT ' . self::LATEST_ROWS . ') n
                JOIN device_metric_samples s ON s.asset_id = ' . $assetId . ' AND s.metric_id = n.metric_id AND s.instance_id = n.instance_id AND s.sampled_at = n.newest
                JOIN device_metric_defs d ON d.metric_id = s.metric_id
                LEFT JOIN device_metric_instances i ON i.instance_id = s.instance_id AND s.instance_id > 0 AND i.asset_id = ' . $assetId . '
                ORDER BY d.metric_key, s.instance_id';
        $out = [];
        foreach ($this->rows($sql, $types, $params) as $r) {
            $out[] = ['key' => (string) $r['metric_key'], 'instance' => (int) $r['instance_id'] === 0 ? null : (string) $r['instance_key'],
                'value' => self::number($r['metric_value']), 'at' => self::at((string) $r['sampled_at']),
                'label' => $r['instance_label'] === null ? null : (string) $r['instance_label']];
        }

        return $out;
    }

    public function peak(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since): ?array
    {
        $ids = $this->ids($assetId, $key, $instance);
        if ($ids === null) {
            return null;
        }
        $from = self::floorHour($since->getTimestamp());
        $parts = $this->hourly($assetId, $ids, $from, null);
        $n = 0;
        $sum = 0.0;
        $lo = null;
        $hi = null;
        $hiAt = $from;
        foreach ($parts as $hour => $p) {
            $n += $p['samples'];
            $sum += $p['sum'];
            $lo = $lo === null ? $p['min'] : min($lo, $p['min']);
            if ($hi === null || $p['max'] > $hi || ($p['max'] == $hi && $hour > $hiAt)) {
                $hi = $p['max'];
                $hiAt = $hour;
            }
        }
        if ($n === 0 || $lo === null || $hi === null) {
            return null;
        }

        return ['min' => $lo, 'max' => $hi, 'avg' => $sum / $n, 'samples' => $n, 'peak_at' => self::at(gmdate('Y-m-d H:i:s', $hiAt))];
    }

    public function series(int $assetId, string $key, ?string $instance, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $ids = $this->ids($assetId, $key, $instance);
        if ($ids === null) {
            return [];
        }
        $out = [];
        foreach ($this->hourly($assetId, $ids, self::floorHour($since->getTimestamp()), $until->getTimestamp()) as $hour => $p) {
            $out[] = ['at' => self::at(gmdate('Y-m-d H:i:s', $hour)), 'min' => $p['min'], 'max' => $p['max'], 'avg' => $p['sum'] / max(1, $p['samples']), 'samples' => $p['samples']];
            if (count($out) >= self::SERIES_POINTS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Hourly min/max/sum/count of one series from $from (an hour start) to $until (null = now), oldest hour first, keyed by the hour's UTC timestamp.
     *
     * @param array{0:int,1:int} $ids [metric_id, instance_id]
     * @return array<int,array{min:float,max:float,sum:float,samples:int}>
     */
    private function hourly(int $assetId, array $ids, int $from, ?int $until): array
    {
        [$metricId, $instanceId] = $ids;
        $rawFrom = max($from, self::floorHour(time() - self::RAW_WINDOW_S));
        $out = [];
        if ($from < $rawFrom) {
            $rows = $this->rows('SELECT period_start, min_value, max_value, sum_value, sample_count FROM device_metric_rollups
                WHERE bucket = \'hour\' AND asset_id = ? AND metric_id = ? AND instance_id = ? AND period_start >= ? AND period_start < ?' . ($until !== null ? ' AND period_start <= ?' : '') . ' ORDER BY period_start LIMIT ' . self::SERIES_POINTS,
                'iiiss' . ($until !== null ? 's' : ''), array_merge([$assetId, $metricId, $instanceId, gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $rawFrom)], $until !== null ? [gmdate('Y-m-d H:i:s', $until)] : []));
            foreach ($rows as $r) {
                $out[strtotime((string) $r['period_start'] . ' UTC')] = ['min' => (float) $r['min_value'], 'max' => (float) $r['max_value'], 'sum' => (float) $r['sum_value'], 'samples' => (int) $r['sample_count']];
            }
        }
        $rows = $this->rows('SELECT DATE_FORMAT(sampled_at, \'%Y-%m-%d %H:00:00\') AS hr, MIN(metric_value) AS lo, MAX(metric_value) AS hi, SUM(metric_value) AS total, COUNT(*) AS n
            FROM device_metric_samples WHERE asset_id = ? AND metric_id = ? AND instance_id = ? AND sampled_at >= ?' . ($until !== null ? ' AND sampled_at <= ?' : '') . ' GROUP BY hr ORDER BY hr LIMIT ' . self::SERIES_POINTS,
            'iiis' . ($until !== null ? 's' : ''), array_merge([$assetId, $metricId, $instanceId, gmdate('Y-m-d H:i:s', $rawFrom)], $until !== null ? [gmdate('Y-m-d H:i:s', $until)] : []));
        foreach ($rows as $r) {
            $out[strtotime((string) $r['hr'] . ' UTC')] = ['min' => (float) $r['lo'], 'max' => (float) $r['hi'], 'sum' => (float) $r['total'], 'samples' => (int) $r['n']];
        }
        ksort($out);

        return $out;
    }

    /**
     * The metric id and instance id of a (key, instance) pair of an asset, or null when either does not exist (the series has no data).
     *
     * @return array{0:int,1:int}|null
     */
    private function ids(int $assetId, string $key, ?string $instance): ?array
    {
        if ($assetId <= 0) {
            return null;
        }
        $def = $this->rows('SELECT metric_id, metric_dim FROM device_metric_defs WHERE metric_key = ?', 's', [$key])[0] ?? null;
        if ($def === null) {
            return null;
        }
        if ($instance === null) {
            return [(int) $def['metric_id'], 0];
        }
        $inst = $this->rows('SELECT instance_id FROM device_metric_instances WHERE asset_id = ? AND metric_dim = ? AND instance_key = ?', 'iss', [$assetId, (string) $def['metric_dim'], $instance])[0] ?? null;

        return $inst === null ? null : [(int) $def['metric_id'], (int) $inst['instance_id']];
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, string $types, array $params): array
    {
        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $out = [];
        if ($stmt->execute() && ($res = $stmt->get_result()) !== false) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
        $stmt->close();

        return $out;
    }

    private static function floorHour(int $ts): int
    {
        return $ts - $ts % 3600;
    }

    private static function number(mixed $v): int|float
    {
        $f = (float) $v;

        return $f == floor($f) && abs($f) < 9e15 ? (int) $f : $f;
    }

    private static function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }
}
