<?php

namespace ITFlow\Training\Kiosk\Core;

/**
 * Kiosk thresholds from the settings row (P3 spec §2.3 / §3.1), read with an explicit column
 * list. Before 2.6.93 has run (unknown column 1054) every value is its column default and
 * schemaReady is false - the bootstrap then answers 404, exactly like the module being off.
 * The same holds until 2.6.94 has added training_kiosks.kiosk_expires_at_utc (temporary and
 * unlisted devices), so a code deploy that runs ahead of Update Database never half-works.
 *
 * Values are clamped to RANGES on read (and by admin/post/settings_training_kiosk.php on write),
 * so a hand-edited row can never produce a zero idle limit or a lock of 0 minutes.
 */
final class KioskSettings
{
    public const ASSET_TYPES = ['Tablet', 'Phone', 'Mobile Phone', 'Laptop', 'Desktop'];

    /** column => [property, default, min, max] for every integer setting. */
    public const RANGES = [
        'config_training_kiosk_idle_s'           => ['idleS', 180, 30, 3600],
        'config_training_trainer_idle_s'         => ['trainerIdleS', 300, 60, 3600],
        'config_training_checkin_idle_s'         => ['checkinIdleS', 1200, 60, 7200],
        'config_training_learner_max_minutes'    => ['learnerMaxMin', 60, 2, 480],
        'config_training_trainer_max_minutes'    => ['trainerMaxMin', 240, 5, 720],
        'config_training_pin_soft_failures'      => ['pinSoft', 5, 3, 20],
        'config_training_pin_lock_minutes'       => ['pinLockMin', 15, 1, 1440],
        'config_training_pin_hard_failures'      => ['pinHard', 10, 4, 100],
        'config_training_kiosk_fail_cap'         => ['kioskFailCap10m', 15, 5, 1000],
        'config_training_global_fail_cap'        => ['globalFailCap10m', 40, 10, 5000],
        'config_training_kiosk_fail_cap_24h'     => ['kioskFailCap24h', 60, 10, 10000],
        'config_training_global_fail_cap_24h'    => ['globalFailCap24h', 200, 20, 50000],
        'config_training_kiosk_distinct_cap_24h' => ['kioskDistinctCap24h', 20, 5, 1000],
        'config_training_kiosk_search_per_min'   => ['searchPerMin', 60, 10, 600],
        'config_training_setup_code_days'        => ['setupCodeDays', 7, 1, 30],
        'config_training_odoo_breaker_errors'    => ['breakerErrors', 0, 0, 255],
    ];

    /** Nullable UTC timestamps: column => property. */
    public const TIMES = [
        'config_training_pin_pause_until_utc'       => 'pinPauseUntilUtc',
        'config_training_enroll_pause_until_utc'    => 'enrollPauseUntilUtc',
        'config_training_odoo_breaker_until_utc'    => 'breakerUntilUtc',
        'config_training_pin_sources_synced_at_utc' => 'pinSourcesSyncedAtUtc',
    ];

    public const SWITCH = 'config_training_odoo_pin_enabled';

    public readonly bool $schemaReady;
    public readonly bool $moduleEnabled;
    public readonly bool $odooPinEnabled;
    public readonly int $idleS;
    public readonly int $trainerIdleS;
    public readonly int $checkinIdleS;
    public readonly int $learnerMaxMin;
    public readonly int $trainerMaxMin;
    public readonly int $pinSoft;
    public readonly int $pinLockMin;
    public readonly int $pinHard;
    public readonly int $kioskFailCap10m;
    public readonly int $globalFailCap10m;
    public readonly int $kioskFailCap24h;
    public readonly int $globalFailCap24h;
    public readonly int $kioskDistinctCap24h;
    public readonly int $searchPerMin;
    public readonly int $setupCodeDays;
    public readonly int $breakerErrors;
    public readonly ?string $pinPauseUntilUtc;
    public readonly ?string $enrollPauseUntilUtc;
    public readonly ?string $breakerUntilUtc;
    public readonly ?string $pinSourcesSyncedAtUtc;
    public readonly string $companyName;

    private function __construct(array $row, bool $schemaReady, bool $moduleEnabled, string $companyName)
    {
        $this->schemaReady = $schemaReady;
        $this->moduleEnabled = $moduleEnabled;
        $this->companyName = $companyName;
        $this->odooPinEnabled = $schemaReady && (int) ($row[self::SWITCH] ?? 0) === 1;
        $vals = [];
        foreach (self::RANGES as $col => [$prop, $def, $min, $max]) {
            $v = array_key_exists($col, $row) && $row[$col] !== null && $row[$col] !== '' ? (int) $row[$col] : $def;
            $vals[$prop] = max($min, min($max, $v));
        }
        if ($vals['pinHard'] <= $vals['pinSoft']) {
            // A hard lock at or below the soft lock would skip the soft lock entirely; keep it strictly above.
            $vals['pinHard'] = $vals['pinSoft'] + 1;
        }
        foreach ($vals as $prop => $v) {
            $this->{$prop} = $v;
        }
        foreach (self::TIMES as $col => $prop) {
            $v = $row[$col] ?? null;
            $this->{$prop} = (is_string($v) && KTime::epoch($v) !== null) ? $v : null;
        }
    }

    public static function fromDb(\mysqli $db): self
    {
        $moduleEnabled = false;
        $company = '';
        try {
            $res = $db->query('SELECT config_module_enable_training FROM settings WHERE company_id = 1');
            $r = $res->fetch_assoc();
            $res->free();
            $moduleEnabled = is_array($r) && (int) ($r['config_module_enable_training'] ?? 0) === 1;
            $res = $db->query('SELECT company_name FROM companies WHERE company_id = 1');
            $c = $res->fetch_assoc();
            $res->free();
            $company = is_array($c) ? trim((string) ($c['company_name'] ?? '')) : '';
        } catch (\mysqli_sql_exception) {
            // settings/companies unreadable: treated as module off.
        }
        $cols = array_merge(array_keys(self::RANGES), array_keys(self::TIMES), [self::SWITCH]);
        try {
            $res = $db->query('SELECT ' . implode(', ', $cols) . ' FROM settings WHERE company_id = 1');
            $row = $res->fetch_assoc();
            $res->free();
            if (is_array($row)) {
                // 2.6.94: the device columns the kiosk code reads (a zero-row probe; 1054 before the migration).
                $probe = $db->query('SELECT kiosk_expires_at_utc FROM training_kiosks LIMIT 0');
                if ($probe instanceof \mysqli_result) {
                    $probe->free();
                    return new self($row, true, $moduleEnabled, $company);
                }
            }
        } catch (\mysqli_sql_exception) {
            // 1054 unknown column / 1146 missing table: 2.6.93 or 2.6.94 has not run yet.
        }
        return new self([], false, $moduleEnabled, $company);
    }

    /** From a (possibly partial) settings row - tests and the admin page preview. */
    public static function fromRow(array $row, bool $schemaReady = true, bool $moduleEnabled = true, string $companyName = ''): self
    {
        return new self($row, $schemaReady, $moduleEnabled, $companyName);
    }

    /** Clamp one integer setting (admin post handler). */
    public static function clamp(string $column, mixed $value): int
    {
        if (!isset(self::RANGES[$column])) {
            throw new \InvalidArgumentException("KioskSettings: unknown setting $column");
        }
        [, $def, $min, $max] = self::RANGES[$column];
        $v = (is_int($value) || (is_string($value) && preg_match('/^-?[0-9]{1,9}$/D', trim($value)) === 1)) ? (int) $value : $def;
        return max($min, min($max, $v));
    }

    /** The brand shown in the kiosk header: first word of the company name + "Training" is composed by the layout. */
    public function brandWord(): string
    {
        $w = preg_split('/\s+/u', $this->companyName, 2)[0] ?? '';
        $w = trim((string) $w);
        return $w === '' ? 'Company' : $w;
    }

    /** Idle limit and absolute cap for a session role: ['idle_s'=>int, 'max_min'=>int]. */
    public function limitsFor(string $role): array
    {
        return match ($role) {
            'learner' => ['idle_s' => $this->idleS, 'max_min' => $this->learnerMaxMin],
            'trainer', 'handoff' => ['idle_s' => $this->trainerIdleS, 'max_min' => $this->trainerMaxMin],
            'checkin' => ['idle_s' => $this->checkinIdleS, 'max_min' => $this->trainerMaxMin],
            default => throw new \InvalidArgumentException("KioskSettings: unknown role $role"),
        };
    }
}
