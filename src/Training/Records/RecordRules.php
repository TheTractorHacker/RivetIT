<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Validation shared by completions, external/paper records, sessions and evaluations
 * (Phase 2 spec §3.5, §1.4 #14, critique #28/#30).
 */
final class RecordRules
{
    public const EARLIEST = '1990-01-01';

    /** Characters an L3 user's note must have to record for their own contact. */
    public const OWN_NOTE_MIN = 10;

    /**
     * 422 date_out_of_range unless:
     *   held_on, completed_on, trained_on, evaluated_on each within [1990-01-01, today];
     *   trained_on <= completed_on; evaluated_on <= completed_on;
     *   an expiry override (expires_on or expires_on_override) > completed_on.
     * Keys that are absent or null are skipped; a present value must be a strict Y-m-d.
     */
    public static function dates(array $in, string $today): void
    {
        foreach (['held_on', 'completed_on', 'trained_on', 'evaluated_on'] as $k) {
            $v = $in[$k] ?? null;
            if ($v === null) {
                continue;
            }
            if (!is_string($v) || !Clock::isYmd($v)) {
                throw self::range($k, 'Must be a date (YYYY-MM-DD).');
            }
            if ($v < self::EARLIEST) {
                throw self::range($k, 'That date is too far in the past.');
            }
            if ($v > $today) {
                throw self::range($k, 'That date is in the future.');
            }
        }
        $completed = $in['completed_on'] ?? null;
        if (is_string($completed)) {
            if (isset($in['trained_on']) && $in['trained_on'] > $completed) {
                throw self::range('trained_on', 'Training cannot be after the completion date.');
            }
            if (isset($in['evaluated_on']) && $in['evaluated_on'] > $completed) {
                throw self::range('evaluated_on', 'The evaluation cannot be after the completion date.');
            }
        }
        foreach (['expires_on', 'expires_on_override'] as $k) {
            $v = $in[$k] ?? null;
            if ($v === null) {
                continue;
            }
            if (!is_string($v) || !Clock::isYmd($v)) {
                throw self::range($k, 'Must be a date (YYYY-MM-DD).');
            }
            if (is_string($completed) && $v <= $completed) {
                throw self::range($k, 'The expiry must be after the completion date.');
            }
        }
    }

    /**
     * Own records (§1.4 #14): when $contactId is the caller's own contact (contact_user_id =
     * the user), a level-2 user is refused and a level-3 user needs a note of at least 10
     * characters. 422 own_record either way.
     */
    public static function notOwn(Ctx $c, \mysqli $db, int $contactId, ?string $note): void
    {
        if ($c->userId < 1) {
            return;   // system and kiosk contexts record for people, never "for themselves"
        }
        $row = Db::one($db, 'SELECT contact_user_id FROM contacts WHERE contact_id = ?', 'i', [$contactId]);
        if ($row === null || (int) ($row['contact_user_id'] ?? 0) !== $c->userId) {
            return;
        }
        $level = $c->isAdmin ? 3 : $c->level;
        if ($level < 3) {
            throw new ApiException(422, 'own_record', 'You cannot record training for yourself. Ask someone else to record it.', ['contact_id' => 'This is your own record.']);
        }
        if (mb_strlen(trim((string) $note), 'UTF-8') < self::OWN_NOTE_MIN) {
            throw new ApiException(422, 'own_record', 'This is your own record. Add a note of at least ' . self::OWN_NOTE_MIN . ' characters explaining it.',
                ['notes' => 'At least ' . self::OWN_NOTE_MIN . ' characters.']);
        }
    }

    private static function range(string $field, string $message): ApiException
    {
        return new ApiException(422, 'date_out_of_range', $message, [$field => $message]);
    }
}
