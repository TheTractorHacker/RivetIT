<?php

namespace ITFlow\Training\Core;

/**
 * Compliance and records settings (the 2.6.92 `config_training_*` columns), as one immutable
 * value (Phase 2 spec §3.1).
 *
 * fromDb() reads the settings row with an explicit column list. Before the 2.6.92 migration
 * has run (columns missing) it returns the column defaults with schemaReady = false and
 * raises no notice, so nothing that merely asks for a threshold can 500 an unmigrated
 * install (spec §10.2 step 5). fromRow() builds one from a (possibly partial) row; pure unit
 * tests (PairRules and friends) use it with just the keys they care about.
 *
 * Integers are clamped to the column ranges; the evidence cap is also held under the
 * 95 MB Cloudflare request ceiling that caps every other upload (TrainingSettings::UPLOAD_CAP_MB).
 */
final class RecordsSettings
{
    public const COLUMNS = [
        'config_training_due_soon_days', 'config_training_reissue_days', 'config_training_reopen_window_days',
        'config_training_evidence_max_mb', 'config_training_compliance_target_pct', 'config_training_reconciled_at_utc',
        'config_training_snapshot_last_on', 'config_training_odoo_sync_enabled', 'config_training_odoo_sync_last_on',
        'config_training_odoo_sync_last_result', 'config_training_odoo_link_checked_at_utc', 'config_training_odoo_target_sha',
        'config_training_hire_fill_since',
    ];

    /** The db.sql / migration column defaults. */
    public const DEFAULTS = [
        'config_training_due_soon_days' => 30,
        'config_training_reissue_days' => 14,
        'config_training_reopen_window_days' => 90,
        'config_training_evidence_max_mb' => 20,
        'config_training_compliance_target_pct' => 95,
        'config_training_reconciled_at_utc' => null,
        'config_training_snapshot_last_on' => null,
        'config_training_odoo_sync_enabled' => 0,
        'config_training_odoo_sync_last_on' => null,
        'config_training_odoo_sync_last_result' => null,
        'config_training_odoo_link_checked_at_utc' => null,
        'config_training_odoo_target_sha' => null,
        'config_training_hire_fill_since' => null,
    ];

    private function __construct(
        public readonly int $dueSoonDays,
        public readonly int $reissueDays,
        public readonly int $reopenWindowDays,
        public readonly int $evidenceMaxBytes,
        public readonly int $targetPct,
        public readonly bool $odooSyncEnabled,
        public readonly bool $schemaReady,
        public readonly ?string $reconciledAtUtc,
        public readonly ?string $snapshotLastOn,
        public readonly ?string $odooSyncLastOn,
        public readonly ?string $odooSyncLastResult,
        public readonly ?string $linkCheckedAtUtc,
        public readonly ?string $odooTargetSha,
        public readonly ?string $hireFillSince,
    ) {
    }

    public static function fromDb(\mysqli $db): self
    {
        try {
            $res = $db->query('SELECT ' . implode(', ', self::COLUMNS) . ' FROM settings WHERE company_id = 1');
            if ($res instanceof \mysqli_result) {
                $row = $res->fetch_assoc();
                $res->free();
                if (is_array($row)) {
                    return self::fromRow($row, true);
                }
            }
        } catch (\mysqli_sql_exception) {
            // 1054 unknown column: 2.6.92 has not run yet - fall through to the defaults.
        }
        return self::fromRow([], false);
    }

    /** Settings from a (possibly partial) settings row; missing keys take the column defaults. */
    public static function fromRow(array $row, bool $schemaReady = true): self
    {
        $v = static fn(string $k) => array_key_exists($k, $row) ? $row[$k] : self::DEFAULTS[$k];
        $int = static fn(string $k, int $min, int $max) => max($min, min($max, (int) $v($k)));
        $str = static function (string $k) use ($v): ?string {
            $x = $v($k);
            return ($x === null || $x === '') ? null : (string) $x;
        };
        $date = static function (string $k) use ($str): ?string {
            $x = $str($k);
            return ($x !== null && Clock::isYmd($x)) ? $x : null;
        };
        $sha = $str('config_training_odoo_target_sha');

        return new self(
            $int('config_training_due_soon_days', 0, 65535),
            $int('config_training_reissue_days', 0, 65535),
            $int('config_training_reopen_window_days', 0, 65535),
            $int('config_training_evidence_max_mb', 1, TrainingSettings::UPLOAD_CAP_MB) * TrainingSettings::MB,
            $int('config_training_compliance_target_pct', 1, 100),
            (int) $v('config_training_odoo_sync_enabled') === 1,
            $schemaReady,
            $str('config_training_reconciled_at_utc'),
            $date('config_training_snapshot_last_on'),
            $date('config_training_odoo_sync_last_on'),
            $str('config_training_odoo_sync_last_result'),
            $str('config_training_odoo_link_checked_at_utc'),
            ($sha !== null && preg_match('/^[0-9a-f]{64}$/', $sha) === 1) ? $sha : null,
            $date('config_training_hire_fill_since'),
        );
    }

    /** The column defaults (schemaReady = true): for tests that need no settings row at all. */
    public static function defaults(): self
    {
        return self::fromRow([], true);
    }
}
