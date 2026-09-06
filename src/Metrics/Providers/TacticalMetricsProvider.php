<?php

namespace ITFlow\Metrics\Providers;

use ITFlow\Metrics\MetricSample;
use ITFlow\Metrics\MetricsProviderInterface;
use ITFlow\Metrics\MetricRegistry;
use ITFlow\Metrics\ProviderCapabilities;

/**
 * Tactical RMM metrics adapter.
 *
 * WHAT TACTICAL ACTUALLY EXPOSES (verified against amidaware/tacticalrmm and
 * rmmagent, releases v0.9.0 - v1.5.2 — do not re-derive this from the docs):
 *
 *  1. checks.CheckHistory is the ONLY genuine time series. One integer per
 *     sample, only for the cpuload / memory / diskspace check types, retrieved
 *     one HTTP call per check per agent, with a timeFilter denominated in DAYS
 *     and no sub-day option, server-pruned at 30 days.
 *
 *  2. The Agent model has NO cpu_load and NO mem field in any of those
 *     releases. The percentages the UI shows come from the WMI fallback blob,
 *     which refreshes only every 3000-4000s (~50-67 min), and the disks array
 *     every 1000-2000s (~17-33 min).
 *
 * Two consequences drive the whole design of this class:
 *
 *  A. THE DAY-GRANULARITY TRAP. Asking for timeFilter=1 on a 5-minute cadence
 *     returns a full day of points every cycle — roughly 60,000 across the
 *     fleet to discover the ~150 that are new. We therefore filter every
 *     returned point against $since before building a sample, and we cache the
 *     per-agent check list in device_metric_collection_state.vendor_cursor_json
 *     instead of calling getAgentChecks() on every cycle. Checks are created by
 *     humans; re-enumerating them every five minutes is pure waste.
 *
 *  B. NO MANUFACTURED FRESHNESS. Uptime and pending-reboot come from the stale
 *     WMI-backed agent detail, so they are stamped with the vendor's own
 *     observation time — the WMI capture instant if the blob carries one,
 *     otherwise the agent's last check-in — never with now(). Recording a
 *     50-minute-old reading at the current timestamp is precisely the bug this
 *     subsystem exists to fix. Because the composite primary key of
 *     device_metric_samples is (asset_id, metric_id, instance_id, sampled_at),
 *     re-emitting the same observation on the next cycle dedupes to one row
 *     instead of drawing a flat line of fake samples.
 *
 * READ-ONLY over assets: this class never creates, updates or deletes an
 * `assets` or `asset_rmm_links` row. RmmAssetMapper owns those.
 *
 * All times handled here are UTC. device_metric_samples.sampled_at is UTC by
 * deliberate divergence from the app's local-time convention.
 */
final class TacticalMetricsProvider implements MetricsProviderInterface
{
    public const PROVIDER_TYPE = 'tactical_rmm';

    /** Namespaced key inside vendor_cursor_json. Other subsystems' keys survive untouched. */
    public const CURSOR_KEY = 'metrics_checks';

    /** How long a cached per-agent check list stays usable, in seconds. */
    public const CHECK_LIST_TTL = 3600;

    /** Tactical prunes CheckHistory at 30 days; asking for more is wasted work. */
    public const MAX_HISTORY_DAYS = 30;

    /**
     * Hard ceiling on points kept from a single check in one cycle. A cold start
     * on a 60-second check yields ~1440 points/day; this bounds a pathological
     * response without truncating any realistic backfill.
     */
    public const MAX_POINTS_PER_CHECK = 5000;

    /**
     * Tactical check_type => registry metric key.
     *
     * These three are the entire genuine time series available. Nothing else in
     * the check catalogue (ping, script, winsvc, eventlog) produces a numeric
     * series that maps onto a registry metric.
     */
    private const CHECK_TYPE_METRIC = [
        'cpuload'   => 'cpu.utilization',
        'memory'    => 'memory.utilization',
        'diskspace' => 'disk.utilization',
    ];

    /**
     * Does the diskspace check history record percent USED or percent FREE?
     *
     * Tactical stores percent used, which is what disk.utilization means, so
     * this is false. It is a named constant rather than an inline assumption
     * because getting it backwards silently inverts every disk chart in the
     * product, and a future Tactical release changing it would need exactly one
     * edit here. Validate it once against a live server on first collection:
     * a nearly-full volume must produce a high number.
     */
    private const DISK_CHECK_REPORTS_FREE_SPACE = false;

    private \TacticalRmmClient $client;
    private int $integrationId;
    private ?\mysqli $mysqli;

    /** @var string[] failures from the most recent collect() */
    private array $errors = [];

    /**
     * Per-run memo of check lists, keyed by asset id, so a single cycle never
     * hits the database or the vendor twice for the same agent.
     *
     * @var array<int,array<int,array{id:int,metric_key:string,instance_key:?string}>>
     */
    private array $checkListMemo = [];

    public function __construct(\TacticalRmmClient $client, int $integrationId, ?\mysqli $mysqli = null)
    {
        $this->client        = $client;
        $this->integrationId = $integrationId > 0 ? $integrationId : $client->getIntegrationId();
        $this->mysqli        = $mysqli;
    }

    public function providerType(): string
    {
        return self::PROVIDER_TYPE;
    }

    public function integrationId(): int
    {
        return $this->integrationId;
    }

    /**
     * Tactical supplies the five TIER_VENDOR metrics and nothing more without
     * the collector script. Only the three check-backed keys are declared as
     * genuine time series; uptime and pending-reboot are single stale readings
     * and must not be plotted as if they had per-minute resolution.
     */
    public function capabilities(): ProviderCapabilities
    {
        return self::declaredCapabilities();
    }

    /**
     * The same declaration, reachable without a client.
     *
     * Capabilities are static data, so a settings screen can ask what Tactical
     * will and will not chart before any credentials exist, and without opening
     * a socket.
     */
    public static function declaredCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            [
                'cpu.utilization',
                'memory.utilization',
                'disk.utilization',
                'system.uptime_seconds',
                'system.pending_reboot',
            ],
            [
                'cpu.utilization',
                'memory.utilization',
                'disk.utilization',
            ],
            null, // resolution equals each check's own run_interval; it is per-check, not per-provider
            'CPU, memory and disk history come from Tactical check history and exist only for '
            . 'agents that have a cpuload, memory or diskspace check configured. Uptime and '
            . 'pending reboot come from the WMI-backed agent record, which Tactical refreshes '
            . 'roughly every 50-67 minutes, and are stamped with the vendor observation time.'
        );
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<int,array{asset_id:int,agent_id:string,hostname:string}> $devices
     * @param \DateTimeImmutable|null $since UTC high-water mark
     * @return MetricSample[]
     */
    public function collect(array $devices, ?\DateTimeImmutable $since = null): array
    {
        $this->errors = [];
        $samples      = [];

        if ($since !== null) {
            $since = $since->setTimezone(new \DateTimeZone('UTC'));
        }
        $historyDays = $this->historyDaysFor($since);

        foreach ($devices as $device) {
            $assetId  = isset($device['asset_id']) ? (int) $device['asset_id'] : 0;
            $agentId  = isset($device['agent_id']) ? trim((string) $device['agent_id']) : '';
            $hostname = isset($device['hostname']) ? trim((string) $device['hostname']) : '';
            $label    = $hostname !== '' ? $hostname : ('asset ' . $assetId);

            if ($assetId <= 0 || $agentId === '') {
                $this->addError("Skipped $label: missing asset id or Tactical agent id");
                continue;
            }

            // One bad agent must not end the cycle for the other twenty-five.
            try {
                foreach ($this->collectCheckHistory($assetId, $agentId, $since, $historyDays) as $s) {
                    $samples[] = $s;
                }
            } catch (\Throwable $e) {
                $this->addError("Check history failed for $label: " . $e->getMessage());
            }

            try {
                foreach ($this->collectAgentState($assetId, $agentId, $since) as $s) {
                    $samples[] = $s;
                }
            } catch (\Throwable $e) {
                $this->addError("Agent state failed for $label: " . $e->getMessage());
            }
        }

        return $samples;
    }

    // ------------------------------------------------------------------
    // Source (a): check history — the only genuine time series
    // ------------------------------------------------------------------

    /**
     * @return MetricSample[]
     */
    private function collectCheckHistory(
        int $assetId,
        string $agentId,
        ?\DateTimeImmutable $since,
        int $historyDays
    ): array {
        $checks = $this->checkListFor($assetId, $agentId);
        if ($checks === []) {
            return [];
        }

        // getCheckHistory() is supplied by TacticalRmmClient. It is probed
        // rather than called blind because this provider must degrade to the
        // agent-state source on an older client build instead of fataling. See
        // the integration notes for the exact signature expected:
        //   getCheckHistory(int $check_id, int $days = 1): array
        if (!method_exists($this->client, 'getCheckHistory')) {
            $this->addError(
                'TacticalRmmClient::getCheckHistory() is unavailable; skipping CPU, memory and disk history'
            );
            return [];
        }

        $out = [];
        foreach ($checks as $check) {
            $raw = $this->client->getCheckHistory($check['id'], $historyDays);
            if (!is_array($raw)) {
                continue;
            }
            // Tactical may wrap the list; accept either a bare list or {results:[...]}.
            if (isset($raw['results']) && is_array($raw['results'])) {
                $raw = $raw['results'];
            }

            $points = $this->normalisePoints($raw, $since);
            if (count($points) > self::MAX_POINTS_PER_CHECK) {
                // Keep the newest; older points are already past their usefulness
                // and will be re-fetched on a later cycle if the cursor lags.
                $points = array_slice($points, -self::MAX_POINTS_PER_CHECK);
            }

            foreach ($points as $p) {
                $value = $this->adjustValue($check['metric_key'], $p['value']);
                $s = MetricSample::tryOf(
                    $assetId,
                    $check['metric_key'],
                    $check['instance_key'],
                    $value,
                    $p['at'],
                    $check['instance_label'] ?? null
                );
                if ($s !== null) {
                    $out[] = $s;
                }
            }
        }

        return $out;
    }

    /**
     * Turn raw history rows into ['at' => DateTimeImmutable(UTC), 'value' => float],
     * dropping anything at or before $since. THIS FILTER IS THE POINT: timeFilter
     * is denominated in days, so the vendor always hands back far more than the
     * cycle needs, and everything downstream must be spared it.
     *
     * @param array<int,mixed> $rows
     * @return array<int,array{at:\DateTimeImmutable,value:float}> ordered oldest first
     */
    private function normalisePoints(array $rows, ?\DateTimeImmutable $since): array
    {
        $points = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // CheckHistory serialises as {x: <datetime>, y: <value>}; tolerate the
            // longer field names in case a future serialiser spells them out.
            $rawAt = $row['x'] ?? $row['time'] ?? $row['timestamp'] ?? $row['sampled_at'] ?? null;
            $rawV  = $row['y'] ?? $row['value'] ?? $row['results'] ?? null;

            if ($rawAt === null || $rawV === null || !is_numeric($rawV)) {
                continue;
            }

            $at = $this->parseVendorTime($rawAt);
            if ($at === null) {
                continue;
            }
            if ($since !== null && $at <= $since) {
                continue;
            }

            $points[] = ['at' => $at, 'value' => (float) $rawV];
        }

        usort($points, static function (array $a, array $b): int {
            return $a['at']->getTimestamp() <=> $b['at']->getTimestamp();
        });

        return $points;
    }

    /** Apply per-metric vendor quirks. Currently only the diskspace direction. */
    private function adjustValue(string $metricKey, float $value): float
    {
        if ($metricKey === 'disk.utilization' && self::DISK_CHECK_REPORTS_FREE_SPACE) {
            return 100.0 - $value;
        }
        return $value;
    }

    /**
     * How many days of history to ask the vendor for.
     *
     * timeFilter has no sub-day option, so 1 is the floor even when the cursor
     * is four minutes old. Everything past $since is discarded in
     * normalisePoints(); this only bounds the request.
     */
    private function historyDaysFor(?\DateTimeImmutable $since): int
    {
        if ($since === null) {
            return self::MAX_HISTORY_DAYS;
        }
        $ageSeconds = time() - $since->getTimestamp();
        if ($ageSeconds <= 0) {
            return 1;
        }
        $days = (int) ceil($ageSeconds / 86400);
        return max(1, min(self::MAX_HISTORY_DAYS, $days));
    }

    // ------------------------------------------------------------------
    // Check-list cache (vendor_cursor_json)
    // ------------------------------------------------------------------

    /**
     * The agent's metric-bearing checks, from cache when fresh.
     *
     * Checks are created by a human and then sit unchanged for months, so
     * enumerating them every five minutes costs one HTTP round trip per agent
     * per cycle to learn nothing. The list is cached in
     * device_metric_collection_state.vendor_cursor_json under a namespaced key
     * and refreshed once an hour.
     *
     * @return array<int,array{id:int,metric_key:string,instance_key:?string,instance_label:?string}>
     */
    private function checkListFor(int $assetId, string $agentId): array
    {
        if (isset($this->checkListMemo[$assetId])) {
            return $this->checkListMemo[$assetId];
        }

        $cached = $this->readCachedCheckList($assetId);
        if ($cached !== null) {
            $this->checkListMemo[$assetId] = $cached;
            return $cached;
        }

        $checks = $this->mapChecks($this->client->getAgentChecks($agentId));
        $this->writeCachedCheckList($assetId, $checks);
        $this->checkListMemo[$assetId] = $checks;
        return $checks;
    }

    /**
     * @param array<int,mixed> $rawChecks as returned by TacticalRmmClient::getAgentChecks()
     * @return array<int,array{id:int,metric_key:string,instance_key:?string,instance_label:?string}>
     */
    private function mapChecks(array $rawChecks): array
    {
        $out = [];
        foreach ($rawChecks as $c) {
            if (!is_array($c)) {
                continue;
            }
            $type = isset($c['check_type']) ? strtolower(trim((string) $c['check_type'])) : '';
            if (!isset(self::CHECK_TYPE_METRIC[$type])) {
                continue;
            }
            $checkId = isset($c['id']) ? (int) $c['id'] : 0;
            if ($checkId <= 0) {
                continue;
            }

            $metricKey = self::CHECK_TYPE_METRIC[$type];

            // disk.utilization carries the volume dimension; the other two are host level.
            $spec = MetricRegistry::spec($metricKey);
            if ($spec === null) {
                continue;
            }
            $instanceKey = null;
            if ($spec['dim'] === MetricRegistry::DIM_VOLUME) {
                $instanceKey = $this->normaliseVolume($c['disk'] ?? null);
                if ($instanceKey === null) {
                    // A diskspace check with no resolvable volume cannot be
                    // attributed; recording it host-level would collide with
                    // every other volume on the machine.
                    continue;
                }
            }

            $out[] = [
                'id'             => $checkId,
                'metric_key'     => $metricKey,
                'instance_key'   => $instanceKey,
                // "C:" reads perfectly well as its own label; a diskspace check
                // carries no friendlier volume name to use instead.
                'instance_label' => $instanceKey,
            ];
        }
        return $out;
    }

    /** "c", "C:", "c:\\" all become "C:". Returns null when unusable. */
    private function normaliseVolume($disk): ?string
    {
        if (!is_string($disk)) {
            return null;
        }
        $d = strtoupper(trim($disk));
        $d = rtrim($d, "\\/ \t\n\r\0\x0B");
        if ($d === '') {
            return null;
        }
        if (preg_match('/^[A-Z]$/', $d)) {
            return $d . ':';
        }
        if (preg_match('/^[A-Z]:$/', $d)) {
            return $d;
        }
        // Mount points and non-Windows paths: keep as-is, capped by MetricSample.
        return $d;
    }

    /**
     * @return array<int,array{id:int,metric_key:string,instance_key:?string,instance_label:?string}>|null
     *         null means "no usable cache, go ask the vendor"
     */
    private function readCachedCheckList(int $assetId): ?array
    {
        $cursor = $this->readCursor($assetId);
        if ($cursor === null || !isset($cursor[self::CURSOR_KEY]) || !is_array($cursor[self::CURSOR_KEY])) {
            return null;
        }
        $entry     = $cursor[self::CURSOR_KEY];
        $fetchedAt = isset($entry['fetched_at']) ? (int) $entry['fetched_at'] : 0;
        if ($fetchedAt <= 0 || (time() - $fetchedAt) > self::CHECK_LIST_TTL) {
            return null;
        }
        if (!isset($entry['checks']) || !is_array($entry['checks'])) {
            return null;
        }

        $out = [];
        foreach ($entry['checks'] as $c) {
            if (!is_array($c) || !isset($c['id'], $c['metric_key'])) {
                continue;
            }
            $metricKey = (string) $c['metric_key'];
            if (!MetricRegistry::exists($metricKey)) {
                // The registry changed under the cache — throw it away and refetch.
                return null;
            }
            $instanceKey = isset($c['instance_key']) && $c['instance_key'] !== null
                ? (string) $c['instance_key']
                : null;
            $out[] = [
                'id'             => (int) $c['id'],
                'metric_key'     => $metricKey,
                'instance_key'   => $instanceKey,
                'instance_label' => isset($c['instance_label']) && $c['instance_label'] !== null
                    ? (string) $c['instance_label']
                    : $instanceKey,
            ];
        }
        return $out;
    }

    /** @param array<int,array{id:int,metric_key:string,instance_key:?string,instance_label:?string}> $checks */
    private function writeCachedCheckList(int $assetId, array $checks): void
    {
        if (!($this->mysqli instanceof \mysqli)) {
            return;
        }

        // Merge into whatever else lives in the blob; only our namespaced key is ours.
        $cursor = $this->readCursor($assetId) ?? [];
        $cursor[self::CURSOR_KEY] = [
            'fetched_at' => time(),
            'checks'     => $checks,
        ];

        $json = json_encode($cursor);
        if ($json === false) {
            return;
        }

        // Only vendor_cursor_json is touched. last_collected_at, last_sample_at,
        // last_error and consecutive_failures belong to the collector; a
        // provider must never move them.
        $sql = "INSERT INTO `device_metric_collection_state`
                    (`asset_id`, `integration_id`, `vendor_cursor_json`)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `vendor_cursor_json` = VALUES(`vendor_cursor_json`)";
        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            return;
        }
        $integrationId = $this->integrationId;
        $stmt->bind_param('iis', $assetId, $integrationId, $json);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<string,mixed>|null decoded vendor_cursor_json */
    private function readCursor(int $assetId): ?array
    {
        if (!($this->mysqli instanceof \mysqli)) {
            return null;
        }
        $stmt = $this->mysqli->prepare(
            "SELECT `vendor_cursor_json` FROM `device_metric_collection_state`
             WHERE `asset_id` = ? AND `integration_id` = ? LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $integrationId = $this->integrationId;
        $stmt->bind_param('ii', $assetId, $integrationId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $json = null;
        $stmt->bind_result($json);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || !is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ------------------------------------------------------------------
    // Source (b): agent state — uptime and pending reboot
    // ------------------------------------------------------------------

    /**
     * Uptime and pending reboot from the agent record.
     *
     * getAgentWmi() is a projection of getAgent() that keeps only the hardware
     * fields — it drops boot_time, needs_reboot, last_seen and status, the four
     * things this method needs — so getAgent() is called once and the very same
     * wmi_detail blob getAgentWmi() would have returned is read out of it. That
     * is one HTTP round trip instead of two for strictly more data.
     *
     * Everything here is stamped with the vendor's observation time, never
     * now(). Re-emitting an unchanged observation next cycle is harmless: the
     * composite primary key of device_metric_samples dedupes it to one row.
     *
     * @return MetricSample[]
     */
    private function collectAgentState(int $assetId, string $agentId, ?\DateTimeImmutable $since): array
    {
        $agent = $this->client->getAgent($agentId);
        if (!is_array($agent) || $agent === []) {
            return [];
        }

        $wmi        = (isset($agent['wmi_detail']) && is_array($agent['wmi_detail'])) ? $agent['wmi_detail'] : [];
        $observedAt = $this->resolveObservationTime($agent, $wmi);
        if ($observedAt === null) {
            // No vendor timestamp anywhere. Stamping now() would assert that a
            // blob up to 67 minutes old was measured this second, which is the
            // exact failure mode this subsystem replaces.
            return [];
        }
        if ($since !== null && $observedAt <= $since) {
            // Nothing new has been observed since the last cycle.
            return [];
        }

        $out = [];

        $bootTs = $this->resolveBootTimestamp($agent, $wmi);
        if ($bootTs !== null) {
            $uptime = $observedAt->getTimestamp() - $bootTs;
            if ($uptime >= 0) {
                $s = MetricSample::tryOf($assetId, 'system.uptime_seconds', null, (float) $uptime, $observedAt);
                if ($s !== null) {
                    $out[] = $s;
                }
            }
        }

        if (array_key_exists('needs_reboot', $agent)) {
            $pending = !empty($agent['needs_reboot']) ? 1.0 : 0.0;
            $s = MetricSample::tryOf($assetId, 'system.pending_reboot', null, $pending, $observedAt);
            if ($s !== null) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * When the vendor actually observed this agent record, best available.
     *
     * Preference order matters:
     *   1. wmi_detail os LocalDateTime — the instant WMI was captured, i.e. the
     *      true age of the reboot flag and the disks array.
     *   2. last_seen — the agent's last check-in. Later than the WMI capture,
     *      but still a real vendor event rather than an invention of ours.
     * There is no third option on purpose.
     *
     * @param array<string,mixed> $agent
     * @param array<string,mixed> $wmi
     */
    private function resolveObservationTime(array $agent, array $wmi): ?\DateTimeImmutable
    {
        $os = $this->unwrapWmiBlock($wmi['os'] ?? null);
        if ($os !== null && isset($os['LocalDateTime'])) {
            $at = $this->parseVendorTime($os['LocalDateTime']);
            if ($at !== null) {
                return $at;
            }
        }

        foreach (['last_seen', 'last_checkin'] as $field) {
            if (!empty($agent[$field])) {
                $at = $this->parseVendorTime($agent[$field]);
                if ($at !== null) {
                    return $at;
                }
            }
        }

        return null;
    }

    /**
     * Unix timestamp of last boot.
     *
     * Tactical exposes boot_time as a unix timestamp on the agent record; the
     * WMI OS block carries LastBootUpTime in CIM_DATETIME as a fallback.
     *
     * @param array<string,mixed> $agent
     * @param array<string,mixed> $wmi
     */
    private function resolveBootTimestamp(array $agent, array $wmi): ?int
    {
        $bt = $agent['boot_time'] ?? null;
        if (is_numeric($bt) && (int) $bt > 0) {
            return (int) $bt;
        }
        if (is_string($bt) && $bt !== '') {
            $at = $this->parseVendorTime($bt);
            if ($at !== null) {
                return $at->getTimestamp();
            }
        }

        $os = $this->unwrapWmiBlock($wmi['os'] ?? null);
        if ($os !== null && isset($os['LastBootUpTime'])) {
            $at = $this->parseVendorTime($os['LastBootUpTime']);
            if ($at !== null) {
                return $at->getTimestamp();
            }
        }

        return null;
    }

    /**
     * Tactical's WMI blobs nest each record in one or more single-element
     * arrays ([[{...}]]). Peel until an associative record appears.
     *
     * @param mixed $block
     * @return array<string,mixed>|null
     */
    private function unwrapWmiBlock($block): ?array
    {
        $guard = 0;
        while (is_array($block) && isset($block[0]) && $guard < 5) {
            $block = $block[0];
            $guard++;
        }
        return is_array($block) ? $block : null;
    }

    // ------------------------------------------------------------------
    // Time parsing
    // ------------------------------------------------------------------

    /**
     * Parse any timestamp shape Tactical emits into UTC.
     *
     * Handles: unix seconds (int or numeric string), WMI CIM_DATETIME
     * ("20250905083012.123456-300"), and anything DateTimeImmutable understands
     * (ISO-8601 with Z or an offset, and naive "Y-m-d H:i:s").
     *
     * A naive string with no zone is read as UTC, not as server local time.
     * Tactical's API serialises UTC, and guessing local here would shift every
     * sample by the server's offset and quietly corrupt the series.
     *
     * @param mixed $value
     */
    private function parseVendorTime($value): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');

        if (is_int($value) || is_float($value)) {
            $ts = (int) $value;
            return $ts > 0 ? (new \DateTimeImmutable('@' . $ts))->setTimezone($utc) : null;
        }

        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }

        // Bare unix seconds.
        if (preg_match('/^\d{9,11}$/', $v)) {
            return (new \DateTimeImmutable('@' . (int) $v))->setTimezone($utc);
        }

        // WMI CIM_DATETIME: yyyymmddHHMMSS.ffffffsUUU where UUU is minutes from UTC.
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\.(\d{1,6})([+-])(\d{1,4})$/', $v, $m)) {
            $offsetMinutes = (int) $m[9];
            $sign          = $m[8];
            $offset        = sprintf('%s%02d:%02d', $sign, intdiv($offsetMinutes, 60), $offsetMinutes % 60);
            $stamp         = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
            try {
                $dt = new \DateTimeImmutable($stamp, new \DateTimeZone($offset));
            } catch (\Exception $e) {
                return null;
            }
            return $dt->setTimezone($utc);
        }

        $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v);
        try {
            $dt = $hasZone ? new \DateTimeImmutable($v) : new \DateTimeImmutable($v, $utc);
        } catch (\Exception $e) {
            return null;
        }
        return $dt->setTimezone($utc);
    }

    /** Keep the error list bounded; a 26-device fleet cannot legitimately fill it. */
    private function addError(string $message): void
    {
        if (count($this->errors) >= 100) {
            return;
        }
        $this->errors[] = $message;
    }
}
