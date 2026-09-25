<?php

namespace ITFlow\Training\Kiosk;

use ITFlow\Training\Core\Db;

/**
 * Hard-delete protection for kiosk evidence (P3 spec §7.9, C-P2-9). Phase 2's
 * Records\RecordGuard ORs this in, so the three contact/department delete paths refuse to delete
 * a person (or a department of people) who has kiosk runs, lesson completions, attempts,
 * signatures or achievement awards - rows that are insert-only and hashed, and that name the
 * contact by id (contacts have no FKs, plan A8).
 *
 * Before 2.6.93 (the tables are missing) nothing is protected; any other database error fails
 * CLOSED (true), so a delete is refused rather than orphaning hashed evidence.
 */
final class KioskRecordGuard
{
    public static function has(\mysqli $db, int $cid): bool
    {
        if ($cid < 1) {
            return false;
        }
        return self::exists($db, 'SELECT (
                EXISTS (SELECT 1 FROM training_runs WHERE trun_contact_id = ?)
             OR EXISTS (SELECT 1 FROM training_lesson_completions WHERE lcomp_contact_id = ?)
             OR EXISTS (SELECT 1 FROM training_attempts WHERE tattempt_contact_id = ?)
             OR EXISTS (SELECT 1 FROM training_signatures WHERE tsig_contact_id = ?)
             OR EXISTS (SELECT 1 FROM training_achievement_awards WHERE taward_contact_id = ?)
            ) AS x', 'iiiii', [$cid, $cid, $cid, $cid, $cid]);
    }

    public static function hasForClient(\mysqli $db, int $clientId): bool
    {
        if ($clientId < 1) {
            return false;
        }
        return self::exists($db, 'SELECT (
                EXISTS (SELECT 1 FROM contacts c JOIN training_runs r ON r.trun_contact_id = c.contact_id WHERE c.contact_client_id = ?)
             OR EXISTS (SELECT 1 FROM contacts c JOIN training_lesson_completions l ON l.lcomp_contact_id = c.contact_id WHERE c.contact_client_id = ?)
             OR EXISTS (SELECT 1 FROM contacts c JOIN training_attempts a ON a.tattempt_contact_id = c.contact_id WHERE c.contact_client_id = ?)
             OR EXISTS (SELECT 1 FROM contacts c JOIN training_signatures s ON s.tsig_contact_id = c.contact_id WHERE c.contact_client_id = ?)
             OR EXISTS (SELECT 1 FROM contacts c JOIN training_achievement_awards w ON w.taward_contact_id = c.contact_id WHERE c.contact_client_id = ?)
            ) AS x', 'iiiii', [$clientId, $clientId, $clientId, $clientId, $clientId]);
    }

    private static function exists(\mysqli $db, string $sql, string $types, array $params): bool
    {
        try {
            $row = Db::one($db, $sql, $types, $params);
            return (int) ($row['x'] ?? 0) === 1;
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1146) {
                return false;   // 2.6.93 not applied yet: there can be no kiosk evidence
            }
            error_log('Kiosk KioskRecordGuard: ' . get_class($e));
            return true;
        }
    }
}
