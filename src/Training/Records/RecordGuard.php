<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\KioskRecordGuard;

/**
 * Hard-delete protection for people and departments with training records (S14; plan A8:
 * contacts get no foreign keys, so the delete paths ask here first).
 *
 * A contact "has records" when it is the person on a completion, an evaluation or a session
 * attendance (removed attendances included: they are part of a finalized session's digest), or
 * has Phase 3 kiosk evidence (runs, lesson completions, attempts, signatures, awards).
 * A department has records when any of its contacts has, when a completion snapshot names it,
 * or when a session was held for it. Before the 2.6.92 tables exist nothing is protected, and
 * any other database error fails closed (true), so a delete is refused rather than orphaning
 * hashed records.
 */
final class RecordGuard
{
    public static function contactHasRecords(\mysqli $db, int $contactId): bool
    {
        if ($contactId < 1) {
            return false;
        }
        return self::exists($db, 'SELECT (
                EXISTS (SELECT 1 FROM training_completions WHERE completion_contact_id = ?)
             OR EXISTS (SELECT 1 FROM training_evaluations WHERE evaluation_contact_id = ?)
             OR EXISTS (SELECT 1 FROM training_session_attendees WHERE tattendee_contact_id = ?)
            ) AS x', 'iii', [$contactId, $contactId, $contactId])
            || KioskRecordGuard::has($db, $contactId);   // Phase 3 kiosk evidence (P3 spec §7.9)
    }

    public static function clientHasRecords(\mysqli $db, int $clientId): bool
    {
        if ($clientId < 1) {
            return false;
        }
        return self::exists($db, 'SELECT (
                EXISTS (SELECT 1 FROM training_completions WHERE completion_snap_client_id = ?)
             OR EXISTS (SELECT 1 FROM training_sessions WHERE tsession_client_id = ?)
             OR EXISTS (SELECT 1 FROM contacts c JOIN training_completions tc ON tc.completion_contact_id = c.contact_id WHERE c.contact_client_id = ?)
             OR EXISTS (SELECT 1 FROM contacts c JOIN training_evaluations te ON te.evaluation_contact_id = c.contact_id WHERE c.contact_client_id = ?)
             OR EXISTS (SELECT 1 FROM contacts c JOIN training_session_attendees ta ON ta.tattendee_contact_id = c.contact_id WHERE c.contact_client_id = ?)
            ) AS x', 'iiiii', [$clientId, $clientId, $clientId, $clientId, $clientId])
            || KioskRecordGuard::hasForClient($db, $clientId);   // Phase 3 kiosk evidence (P3 spec §7.9)
    }

    private static function exists(\mysqli $db, string $sql, string $types, array $params): bool
    {
        try {
            $row = Db::one($db, $sql, $types, $params);
            return (int) ($row['x'] ?? 0) === 1;
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1146) {
                return false;   // 2.6.92 not applied yet: there can be no records
            }
            error_log('Training RecordGuard: ' . $e->getMessage());
            return true;
        }
    }
}
