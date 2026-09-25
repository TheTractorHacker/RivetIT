<?php

namespace ITFlow\Training\People;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;

/**
 * "Set hire date" / "Rehired" (S11, Phase 2 spec §1.4 #6). contacts.contact_start_date is the
 * authoritative hire date; HR can override it here. A later hire date re-opens onboarding pairs
 * (new-hires-only rules count only completions on or after the hire date). The `rehired` flag is
 * informational (ledger).
 */
final class HireDateService
{
    public const MAX_FUTURE_DAYS = 60;

    public function __construct(private readonly Ctx $c)
    {
    }

    /** @return array{person:?array, old:?string, new:string, reconcile:?array} */
    public function set(int $contactId, string $date, bool $rehired, string $reason): array
    {
        if (!Clock::isYmd($date) || $date < '1950-01-01') {
            throw ApiException::validation(['hire_date' => 'Must be a date (YYYY-MM-DD).']);
        }
        $today = Clock::todayLocal();
        if ($date > Clock::addDays($today, self::MAX_FUTURE_DAYS)) {
            throw new ApiException(422, 'date_out_of_range', 'The hire date can be at most ' . self::MAX_FUTURE_DAYS . ' days ahead.',
                ['hire_date' => 'At most ' . self::MAX_FUTURE_DAYS . ' days ahead.']);
        }
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 3 || mb_strlen($reason, 'UTF-8') > 255 || !mb_check_encoding($reason, 'UTF-8')) {
            throw ApiException::validation(['reason' => 'Say why (3 to 255 characters).']);
        }
        $db = $this->c->db;
        $old = Db::tx($db, function () use ($db, $contactId, $date, $rehired, $reason): ?string {
            $row = Db::one($db, 'SELECT contact_start_date FROM contacts WHERE contact_id = ? FOR UPDATE', 'i', [$contactId]);
            if ($row === null) {
                throw ApiException::notFound('That person was not found.');
            }
            $old = $row['contact_start_date'] === null ? null : (string) $row['contact_start_date'];
            if ($old === $date && !$rehired) {
                return $old;
            }
            Db::exec($db, 'UPDATE contacts SET contact_start_date = ? WHERE contact_id = ?', 'si', [$date, $contactId]);
            Ledger::append($db, [
                'type' => 'contact.hire_date_set',
                'actor_type' => 'user',
                'actor_user_id' => $this->c->userId,
                'subject_contact_id' => $contactId,
                'entity_type' => 'contact',
                'entity_id' => $contactId,
                'payload' => ['old' => $old, 'new' => $date, 'rehired' => $rehired, 'reason' => $reason],
                'user_agent' => $this->c->userAgent,
            ]);
            return $old;
        });
        $reconcile = ($old !== $date || $rehired) ? AssignmentService::safeReconcile($this->c, [$contactId], 'hire_date') : null;
        $person = Directory::load($db, Scope::all(), [$contactId], true)[$contactId] ?? null;
        return ['person' => $person === null ? null : Roster::row($person), 'old' => $old, 'new' => $date, 'reconcile' => $reconcile];
    }
}
