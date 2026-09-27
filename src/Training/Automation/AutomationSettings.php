<?php

namespace ITFlow\Training\Automation;

use ITFlow\Training\Core\Db;

/**
 * Phase 5 settings: the singleton training_automation row (tauto_id = 1; spec §1.4 #2, §2.1, §3.2).
 * Only Phase 5 code reads it, through narrow prepared selects. Before the 2.6.96 migration every
 * loader answers the column defaults (and ready = false) and never throws.
 *
 * Versioning: tauto_version is the optimistic lock of the settings forms. save() bumps it; stamp()
 * (the worker's last-run lines, Odoo discovery results) never does, so a form never sees a false
 * conflict because the worker ran.
 *
 * Who may change what (the one-page Training settings; Settings\SettingsPolicy):
 *   odoo       admin only (Admin > Training)
 *   cert       signer name/title/signature: Training 3; tauto_verify_enabled: admin only
 *   reminders  Training 3
 *   video      Training 3
 */
final class AutomationSettings
{
    public const DEFAULTS = [
        'tauto_id' => 1,
        'tauto_odoo_push_enabled' => 0,
        'tauto_odoo_mode' => 'resume',
        'tauto_odoo_resume_type_id' => null,
        'tauto_odoo_award_type_id' => null,
        'tauto_odoo_skill_type_id' => null,
        'tauto_odoo_skill_level_id' => null,
        'tauto_odoo_push_awards' => 0,
        'tauto_odoo_push_since' => null,
        'tauto_odoo_target_key' => null,
        'tauto_odoo_target_confirmed_at_utc' => null,
        'tauto_odoo_discovery_json' => null,
        'tauto_odoo_discovered_at_utc' => null,
        'tauto_odoo_key_expires_on' => null,
        'tauto_odoo_paused_reason' => null,
        'tauto_odoo_last_run_at_utc' => null,
        'tauto_odoo_last_result' => null,
        'tauto_reminders_enabled' => 0,
        'tauto_reminder_weekdays' => '1,2,3,4,5',
        'tauto_escalate_after_days' => 14,
        'tauto_video_recheck_enabled' => 1,
        'tauto_verify_enabled' => 1,
        'tauto_cert_signer_name' => null,
        'tauto_cert_signer_title' => null,
        'tauto_cert_signer_png' => null,
        'tauto_daily_last_run_on' => null,
        'tauto_daily_last_result' => null,
        'tauto_version' => 0,
        'tauto_updated_by' => null,
        'tauto_updated_at' => null,
    ];

    /** Form-editable, versioned columns by group. */
    public const GROUPS = [
        'odoo'      => ['tauto_odoo_push_enabled', 'tauto_odoo_mode', 'tauto_odoo_resume_type_id', 'tauto_odoo_award_type_id',
                        'tauto_odoo_skill_type_id', 'tauto_odoo_skill_level_id', 'tauto_odoo_push_awards', 'tauto_odoo_push_since',
                        'tauto_odoo_target_key', 'tauto_odoo_target_confirmed_at_utc', 'tauto_odoo_key_expires_on'],
        'cert'      => ['tauto_cert_signer_name', 'tauto_cert_signer_title', 'tauto_cert_signer_png', 'tauto_verify_enabled'],
        'reminders' => ['tauto_reminders_enabled', 'tauto_reminder_weekdays', 'tauto_escalate_after_days'],
        'video'     => ['tauto_video_recheck_enabled'],
    ];

    /** Worker/discovery stamps: single UPDATE, no version bump. */
    public const STAMPS = ['tauto_odoo_discovery_json', 'tauto_odoo_discovered_at_utc',
                           'tauto_odoo_paused_reason', 'tauto_odoo_last_run_at_utc', 'tauto_odoo_last_result',
                           'tauto_daily_last_run_on', 'tauto_daily_last_result'];

    private const INT_COLS = ['tauto_id', 'tauto_odoo_push_enabled', 'tauto_odoo_resume_type_id', 'tauto_odoo_award_type_id',
        'tauto_odoo_skill_type_id', 'tauto_odoo_skill_level_id', 'tauto_odoo_push_awards', 'tauto_reminders_enabled',
        'tauto_escalate_after_days', 'tauto_video_recheck_enabled', 'tauto_verify_enabled', 'tauto_version', 'tauto_updated_by'];

    /** Every column but the signature PNG (the worker never needs it). */
    private const WORKER_COLS = ['tauto_id', 'tauto_odoo_push_enabled', 'tauto_odoo_mode', 'tauto_odoo_resume_type_id', 'tauto_odoo_award_type_id',
        'tauto_odoo_skill_type_id', 'tauto_odoo_skill_level_id', 'tauto_odoo_push_awards', 'tauto_odoo_push_since', 'tauto_odoo_target_key',
        'tauto_odoo_target_confirmed_at_utc', 'tauto_odoo_discovery_json', 'tauto_odoo_discovered_at_utc', 'tauto_odoo_key_expires_on',
        'tauto_odoo_paused_reason', 'tauto_odoo_last_run_at_utc', 'tauto_odoo_last_result', 'tauto_reminders_enabled', 'tauto_reminder_weekdays',
        'tauto_escalate_after_days', 'tauto_video_recheck_enabled', 'tauto_verify_enabled', 'tauto_cert_signer_name', 'tauto_cert_signer_title',
        'tauto_daily_last_run_on', 'tauto_daily_last_result', 'tauto_version', 'tauto_updated_by', 'tauto_updated_at'];

    /**
     * The full typed row plus 'ready' => bool. Missing table (before 2.6.96) or missing row: DEFAULTS
     * with ready = false. Never throws.
     */
    public static function load(\mysqli $db): array
    {
        return self::read($db, array_keys(self::DEFAULTS));
    }

    /** SELECT tauto_verify_enabled only (the public verify page); any error => true (the default). */
    public static function loadVerify(\mysqli $db): bool
    {
        try {
            $res = $db->query('SELECT tauto_verify_enabled FROM training_automation WHERE tauto_id = 1');
            $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            if ($res instanceof \mysqli_result) {
                $res->free();
            }
            return !is_array($row) || (int) $row['tauto_verify_enabled'] === 1;
        } catch (\Throwable) {
            return true;
        }
    }

    /** Every column except tauto_cert_signer_png, plus 'ready'. */
    public static function loadWorker(\mysqli $db): array
    {
        return self::read($db, self::WORKER_COLS);
    }

    /** Signer name/title/png and the verify flag, plus 'ready'. */
    public static function loadCert(\mysqli $db): array
    {
        return self::read($db, ['tauto_cert_signer_name', 'tauto_cert_signer_title', 'tauto_cert_signer_png', 'tauto_verify_enabled', 'tauto_version']);
    }

    /**
     * Db::tx { SELECT ... FOR UPDATE; tauto_version !== $expectVersion => \RuntimeException('conflict');
     * UPDATE only $group's columns + tauto_version = tauto_version + 1, tauto_updated_by } -> the new row.
     * An unknown group or a key outside the group => \InvalidArgumentException. A missing table or row
     * => \RuntimeException('not_ready'). Values are stored as given (the lane handlers clamp and
     * allowlist them first); ints are bound as ints, null as NULL.
     */
    public static function save(\mysqli $db, string $group, array $values, int $expectVersion, int $userId): array
    {
        if (!isset(self::GROUPS[$group])) {
            throw new \InvalidArgumentException("AutomationSettings::save: unknown group '$group'");
        }
        if ($values === []) {
            throw new \InvalidArgumentException('AutomationSettings::save: nothing to save');
        }
        foreach (array_keys($values) as $k) {
            if (!in_array($k, self::GROUPS[$group], true)) {
                throw new \InvalidArgumentException("AutomationSettings::save: '$k' is not in group '$group'");
            }
        }
        Db::tx($db, static function () use ($db, $values, $expectVersion, $userId): void {
            $cur = Db::one($db, 'SELECT tauto_version FROM training_automation WHERE tauto_id = 1 FOR UPDATE');
            if ($cur === null) {
                throw new \RuntimeException('not_ready');
            }
            if ((int) $cur['tauto_version'] !== $expectVersion) {
                throw new \RuntimeException('conflict');
            }
            $set = [];
            $types = '';
            $params = [];
            foreach ($values as $col => $v) {
                $set[] = "`$col` = ?";
                [$t, $p] = self::bind($col, $v);
                $types .= $t;
                $params[] = $p;
            }
            $set[] = '`tauto_version` = `tauto_version` + 1';
            $set[] = '`tauto_updated_by` = ?';
            $types .= 'i';
            $params[] = $userId;
            Db::exec($db, 'UPDATE training_automation SET ' . implode(', ', $set) . ' WHERE tauto_id = 1', $types, $params);
        });
        return self::load($db);
    }

    /** STAMPS only; one UPDATE; no version bump; autocommit. A missing table is a silent no-op (logged). */
    public static function stamp(\mysqli $db, array $values): void
    {
        if ($values === []) {
            return;
        }
        $set = [];
        $types = '';
        $params = [];
        foreach ($values as $col => $v) {
            if (!in_array($col, self::STAMPS, true)) {
                throw new \InvalidArgumentException("AutomationSettings::stamp: '$col' is not a stamp column");
            }
            $set[] = "`$col` = ?";
            [$t, $p] = self::bind($col, $v);
            $types .= $t;
            $params[] = $p;
        }
        try {
            Db::exec($db, 'UPDATE training_automation SET ' . implode(', ', $set) . ' WHERE tauto_id = 1', $types, $params);
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1146) {   // table missing: before 2.6.96
                error_log('Training AutomationSettings::stamp: training_automation is not installed yet');
                return;
            }
            throw $e;
        }
    }

    /** Integer weekday list '1,2,3' (ISO 1 = Monday .. 7 = Sunday) => [1, 2, 3]. */
    public static function weekdays(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $d) {
            $d = (int) trim($d);
            if ($d >= 1 && $d <= 7) {
                $out[$d] = $d;
            }
        }
        ksort($out);
        return array_values($out);
    }

    // ------------------------------------------------------------------------------------------

    /** @param list<string> $cols */
    private static function read(\mysqli $db, array $cols): array
    {
        $out = [];
        foreach ($cols as $c) {
            $out[$c] = self::DEFAULTS[$c];
        }
        $out['ready'] = false;
        try {
            $res = $db->query('SELECT ' . implode(', ', array_map(static fn($c) => "`$c`", $cols)) . ' FROM training_automation WHERE tauto_id = 1');
            $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            if ($res instanceof \mysqli_result) {
                $res->free();
            }
        } catch (\Throwable) {
            return $out;
        }
        if (!is_array($row)) {
            return $out;
        }
        foreach ($cols as $c) {
            $v = $row[$c] ?? null;
            $out[$c] = ($v === null) ? null : (in_array($c, self::INT_COLS, true) ? (int) $v : (string) $v);
        }
        $out['ready'] = true;
        return $out;
    }

    /** @return array{0:string, 1:int|string|null} */
    private static function bind(string $col, mixed $v): array
    {
        if ($v === null) {
            return ['s', null];
        }
        if (in_array($col, self::INT_COLS, true)) {
            if (is_bool($v)) {
                $v = $v ? 1 : 0;
            }
            if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,10}$/D', $v) === 1)) {
                throw new \InvalidArgumentException("AutomationSettings: '$col' needs an integer");
            }
            return ['i', (int) $v];
        }
        if (!is_string($v) && !is_int($v)) {
            throw new \InvalidArgumentException("AutomationSettings: '$col' needs a string");
        }
        return ['s', (string) $v];
    }
}
