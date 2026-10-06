<?php

namespace ITFlow\Workflow;

/**
 * Resolves a template task's due date when a run starts. The offset (in days, negative = before) counts from:
 *   run   when the run started
 *   start the person's start date (contacts.contact_start_date)
 *   end   the person's end date (contacts.contact_expected_end_date)
 * A date anchor with no date on the person falls back to the run start, so a task is never left without a due date it asked for.
 * A due date that comes from a calendar date is due at the end of that working day (17:00); a run-start due date keeps the time of day.
 */
final class DueDateResolver
{
    public const ANCHORS = ['run', 'start', 'end'];

    /** @return string|null "Y-m-d H:i:s" or null when the task has no due date */
    public static function resolve(?int $offsetDays, string $anchor, ?string $startDate, ?string $endDate, string $runStartedAt): ?string
    {
        if ($offsetDays === null) {
            return null;
        }
        $offsetDays = max(-3650, min(3650, $offsetDays));
        $run = new \DateTimeImmutable($runStartedAt);
        $date = null;
        if ($anchor === 'start' && self::isDate($startDate)) {
            $date = new \DateTimeImmutable($startDate . ' 17:00:00');
        } elseif ($anchor === 'end' && self::isDate($endDate)) {
            $date = new \DateTimeImmutable($endDate . ' 17:00:00');
        }
        $base = $date ?? $run;

        return $base->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days')->format('Y-m-d H:i:s');
    }

    private static function isDate(?string $v): bool
    {
        return $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && $v !== '0000-00-00';
    }

    /** "overdue" (past due), "due_soon" (within 24 hours), "later" or null (no due date); only meaningful for an unfinished task. */
    public static function state(?string $dueAt, int $nowTs): ?string
    {
        if ($dueAt === null || $dueAt === '') {
            return null;
        }
        $due = strtotime($dueAt);
        if ($due === false) {
            return null;
        }
        if ($due < $nowTs) {
            return 'overdue';
        }

        return $due <= $nowTs + 86400 ? 'due_soon' : 'later';
    }
}
