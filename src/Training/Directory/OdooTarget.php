<?php

namespace ITFlow\Training\Directory;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Core\Db;

/**
 * The accepted Odoo target (Phase 2 spec §1.4 #12, M15): sha256 of the normalised
 * base_url|database the links were confirmed against, in settings.config_training_odoo_target_sha.
 * A directory sync (the admin button, Block 0, and DirectorySyncRunner) is refused while the
 * configured integration points somewhere else, until the links are checked or an admin accepts
 * the new target.
 */
final class OdooTarget
{
    public const CHANGED_MESSAGE = 'The Odoo connection now points at a different database. Open Admin > Training compliance and run Check now first.';

    /** Frozen: identical to the 2.6.92 migration's $tr_sha expression. */
    public static function sha(?string $baseUrl, ?string $database): string
    {
        return hash('sha256', strtolower(rtrim(trim((string) $baseUrl), '/')) . '|' . trim((string) $database));
    }

    /**
     * {ok, current_sha, accepted_sha, message}. ok = true when the 2.6.92 schema is absent, when no
     * target has been accepted yet (first use), or when the row's target equals the accepted one.
     */
    public static function guard(\mysqli $db, array $integrationRow): array
    {
        $current = self::sha($integrationRow['base_url'] ?? null, $integrationRow['database_name'] ?? null);
        $accepted = self::accepted($db);
        if ($accepted === false || $accepted === null || hash_equals($accepted, $current)) {
            return ['ok' => true, 'current_sha' => $current, 'accepted_sha' => $accepted === false ? null : $accepted, 'message' => ''];
        }
        return ['ok' => false, 'current_sha' => $current, 'accepted_sha' => $accepted, 'message' => self::CHANGED_MESSAGE];
    }

    /** Stores the row's target when none is accepted yet (a fresh install's first clean sync). */
    public static function acceptIfUnset(\mysqli $db, array $integrationRow): void
    {
        if (self::accepted($db) !== null) {
            return;   // set already, or schema absent (false)
        }
        $current = self::sha($integrationRow['base_url'] ?? null, $integrationRow['database_name'] ?? null);
        Db::exec($db, 'UPDATE settings SET config_training_odoo_target_sha = ? WHERE company_id = 1 AND config_training_odoo_target_sha IS NULL', 's', [$current]);
    }

    /** Admin override ("Accept new Odoo target"), audited. */
    public static function accept(\mysqli $db, array $integrationRow, int $userId, string $reason): void
    {
        $current = self::sha($integrationRow['base_url'] ?? null, $integrationRow['database_name'] ?? null);
        $from = self::accepted($db);
        if ($from === false) {
            throw new \RuntimeException('schema_missing');
        }
        Db::exec($db, 'UPDATE settings SET config_training_odoo_target_sha = ? WHERE company_id = 1', 's', [$current]);
        try {
            (new AuditService($db))->log('training.odoo_target_accepted', $userId, 'odoo_integration', (int) ($integrationRow['odoo_integration_id'] ?? 0),
                'accept', mb_substr('Accepted Odoo target ' . self::describe($integrationRow) . ': ' . $reason, 0, 500),
                ['from' => $from, 'to' => $current, 'reason' => $reason]);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . $e->getMessage());
        }
    }

    /** The accepted sha, null when none is set, false when the 2.6.92 column does not exist. */
    public static function accepted(\mysqli $db): string|false|null
    {
        try {
            $res = $db->query('SELECT config_training_odoo_target_sha FROM settings WHERE company_id = 1');
            $row = $res->fetch_assoc();
            $res->free();
        } catch (\mysqli_sql_exception) {
            return false;
        }
        $v = $row['config_training_odoo_target_sha'] ?? null;
        return ($v === null || $v === '') ? null : (string) $v;
    }

    /** "host / database" for messages (never the key). */
    public static function describe(array $row): string
    {
        $host = (string) (parse_url(trim((string) ($row['base_url'] ?? '')), PHP_URL_HOST) ?: trim((string) ($row['base_url'] ?? '')));
        return $host . ' / ' . trim((string) ($row['database_name'] ?? ''));
    }
}
