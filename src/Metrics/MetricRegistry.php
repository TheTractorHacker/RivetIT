<?php

namespace ITFlow\Metrics;

/**
 * The canonical metric vocabulary every provider normalises into.
 *
 * Naming note: this subsystem is "Metrics", never "telemetry". `config_telemetry`
 * already exists in this codebase and means anonymous phone-home usage reporting
 * (admin/settings_telemetry.php); reusing the word would make "telemetry disabled"
 * permanently ambiguous in settings, filenames and URL space.
 *
 * Tiers reflect what is actually obtainable, verified against vendor APIs and the
 * Tactical RMM agent source rather than assumed:
 *
 *   TIER_VENDOR    - available today from an existing provider field.
 *   TIER_COLLECTOR - needs the Tactical script-check collector.
 *   TIER_OPTIONAL  - hardware dependent; absent on most of the fleet.
 *
 * Two metric families the original plan asked for are deliberately ABSENT:
 *   cpu.temperature - MSAcpi_ThermalZoneTemperature reports "Not Supported" on
 *     most hardware and always in VMs, windows_exporter deprecated its thermalzone
 *     collector, and real die temperature needs a WinRing0-class driver that
 *     Microsoft Defender flags as HackTool:Win32/Winring0.
 *   cross-vendor GPU - only NVIDIA discrete exposes utilisation/VRAM/temperature
 *     via NVML. Intel iGPU and AMD have no free CLI equivalent, so the gpu.* keys
 *     below are TIER_OPTIONAL and must render as "not supported", never as zero.
 */
class MetricRegistry
{
    public const TIER_VENDOR    = 'vendor';
    public const TIER_COLLECTOR = 'collector';
    public const TIER_OPTIONAL  = 'optional';

    public const KIND_GAUGE   = 'gauge';
    public const KIND_COUNTER = 'counter';

    /** Dimension keys. instance_id 0 means host-level (no dimension). */
    public const DIM_VOLUME = 'volume';
    public const DIM_CORE   = 'core';
    public const DIM_NIC    = 'nic';
    public const DIM_DISK   = 'disk';
    public const DIM_GPU    = 'gpu';

    /**
     * key => [display, unit, kind, min, max, dim|null, precision, tier]
     */
    private const METRICS = [
        // ---- host-level, available from vendor APIs today ----
        'cpu.utilization'        => ['CPU',                'percent', self::KIND_GAUGE, 0,    100,  null,             1, self::TIER_VENDOR],
        'memory.utilization'     => ['Memory',             'percent', self::KIND_GAUGE, 0,    100,  null,             1, self::TIER_VENDOR],
        'disk.utilization'       => ['Disk used',          'percent', self::KIND_GAUGE, 0,    100,  self::DIM_VOLUME, 1, self::TIER_VENDOR],
        'system.uptime_seconds'  => ['Uptime',             'seconds', self::KIND_GAUGE, 0,    null, null,             0, self::TIER_VENDOR],
        'system.pending_reboot'  => ['Pending reboot',     'bool',    self::KIND_GAUGE, 0,    1,    null,             0, self::TIER_VENDOR],

        // ---- needs the collector script ----
        'cpu.core.utilization'   => ['CPU core',           'percent', self::KIND_GAUGE, 0,    100,  self::DIM_CORE,   1, self::TIER_COLLECTOR],
        'memory.used_bytes'      => ['Memory used',        'bytes',   self::KIND_GAUGE, 0,    null, null,             0, self::TIER_COLLECTOR],
        'memory.available_bytes' => ['Memory available',   'bytes',   self::KIND_GAUGE, 0,    null, null,             0, self::TIER_COLLECTOR],
        'memory.total_bytes'     => ['Memory total',       'bytes',   self::KIND_GAUGE, 0,    null, null,             0, self::TIER_COLLECTOR],
        'disk.free_bytes'        => ['Disk free',          'bytes',   self::KIND_GAUGE, 0,    null, self::DIM_VOLUME, 0, self::TIER_COLLECTOR],
        'disk.total_bytes'       => ['Disk total',         'bytes',   self::KIND_GAUGE, 0,    null, self::DIM_VOLUME, 0, self::TIER_COLLECTOR],
        'disk.read_iops'         => ['Disk read IOPS',     'iops',    self::KIND_GAUGE, 0,    null, self::DIM_DISK,   0, self::TIER_COLLECTOR],
        'disk.write_iops'        => ['Disk write IOPS',    'iops',    self::KIND_GAUGE, 0,    null, self::DIM_DISK,   0, self::TIER_COLLECTOR],
        'disk.queue_length'      => ['Disk queue',         'count',   self::KIND_GAUGE, 0,    null, self::DIM_DISK,   2, self::TIER_COLLECTOR],
        'disk.latency_ms'        => ['Disk latency',       'ms',      self::KIND_GAUGE, 0,    null, self::DIM_DISK,   1, self::TIER_COLLECTOR],
        'network.rx_bytes_per_s' => ['Network in',         'bytes/s', self::KIND_GAUGE, 0,    null, self::DIM_NIC,    0, self::TIER_COLLECTOR],
        'network.tx_bytes_per_s' => ['Network out',        'bytes/s', self::KIND_GAUGE, 0,    null, self::DIM_NIC,    0, self::TIER_COLLECTOR],
        'network.errors'         => ['Network errors',     'count',   self::KIND_COUNTER, 0,  null, self::DIM_NIC,    0, self::TIER_COLLECTOR],
        'battery.charge_percent' => ['Battery charge',     'percent', self::KIND_GAUGE, 0,    100,  null,             0, self::TIER_COLLECTOR],
        'battery.health_percent' => ['Battery health',     'percent', self::KIND_GAUGE, 0,    100,  null,             0, self::TIER_COLLECTOR],

        // ---- NVIDIA discrete only; hide the card entirely when unsupported ----
        'gpu.utilization'        => ['GPU',                'percent', self::KIND_GAUGE, 0,    100,  self::DIM_GPU,    1, self::TIER_OPTIONAL],
        'gpu.memory_used_bytes'  => ['GPU memory used',    'bytes',   self::KIND_GAUGE, 0,    null, self::DIM_GPU,    0, self::TIER_OPTIONAL],
        'gpu.memory_total_bytes' => ['GPU memory total',   'bytes',   self::KIND_GAUGE, 0,    null, self::DIM_GPU,    0, self::TIER_OPTIONAL],
        'gpu.temperature'        => ['GPU temperature',    'celsius', self::KIND_GAUGE, -20,  130,  self::DIM_GPU,    0, self::TIER_OPTIONAL],
    ];

    public static function all(): array
    {
        return self::METRICS;
    }

    public static function exists(string $key): bool
    {
        return isset(self::METRICS[$key]);
    }

    public static function keys(): array
    {
        return array_keys(self::METRICS);
    }

    /** @return array{key:string,display:string,unit:string,kind:string,min:?float,max:?float,dim:?string,precision:int,tier:string}|null */
    public static function spec(string $key): ?array
    {
        if (!isset(self::METRICS[$key])) {
            return null;
        }
        [$display, $unit, $kind, $min, $max, $dim, $precision, $tier] = self::METRICS[$key];
        return [
            'key'       => $key,
            'display'   => $display,
            'unit'      => $unit,
            'kind'      => $kind,
            'min'       => $min === null ? null : (float) $min,
            'max'       => $max === null ? null : (float) $max,
            'dim'       => $dim,
            'precision' => $precision,
            'tier'      => $tier,
        ];
    }

    public static function keysForTier(string $tier): array
    {
        $out = [];
        foreach (self::METRICS as $key => $m) {
            if ($m[7] === $tier) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Reject rather than clamp when a value is outside a metric's declared range.
     * A CPU reading of 1200% is a collection bug, and silently clamping it to 100
     * would bake that bug into the history permanently.
     */
    public static function isValidValue(string $key, $value): bool
    {
        $spec = self::spec($key);
        if ($spec === null || !is_numeric($value)) {
            return false;
        }
        $v = (float) $value;
        if (is_nan($v) || is_infinite($v)) {
            return false;
        }
        if ($spec['min'] !== null && $v < $spec['min']) {
            return false;
        }
        if ($spec['max'] !== null && $v > $spec['max']) {
            return false;
        }
        return true;
    }

    /** Seed/refresh device_metric_defs so metric_id assignment is stable and DB-side. */
    public static function syncToDatabase(\mysqli $mysqli): int
    {
        $n = 0;
        foreach (self::METRICS as $key => $m) {
            [$display, $unit, $kind, $min, $max, $dim, $precision, $tier] = $m;
            $sql = sprintf(
                "INSERT INTO `device_metric_defs`
                    (`metric_key`,`display_name`,`unit`,`metric_kind`,`value_min`,`value_max`,`metric_dim`,`display_precision`)
                 VALUES ('%s','%s','%s','%s',%s,%s,%s,%d)
                 ON DUPLICATE KEY UPDATE
                    `display_name`=VALUES(`display_name`), `unit`=VALUES(`unit`),
                    `metric_kind`=VALUES(`metric_kind`), `value_min`=VALUES(`value_min`),
                    `value_max`=VALUES(`value_max`), `metric_dim`=VALUES(`metric_dim`),
                    `display_precision`=VALUES(`display_precision`)",
                $mysqli->real_escape_string($key),
                $mysqli->real_escape_string($display),
                $mysqli->real_escape_string($unit),
                $mysqli->real_escape_string($kind),
                $min === null ? 'NULL' : (float) $min,
                $max === null ? 'NULL' : (float) $max,
                $dim === null ? 'NULL' : "'" . $mysqli->real_escape_string($dim) . "'",
                (int) $precision
            );
            if ($mysqli->query($sql)) {
                $n++;
            }
        }
        return $n;
    }
}
