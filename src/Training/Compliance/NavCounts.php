<?php

namespace ITFlow\Training\Compliance;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\People\Roster;
use ITFlow\Training\People\Scope;

/**
 * One cheap query each, for the side-nav badge and the dashboard chips (Phase 2 spec §3.3, Blocks 3-4).
 * Scoped like everything else (fail-closed Scope on the person's current department) and limited to
 * people on the training roster.
 *
 *   overdue      open REQUIRED assignments due before today
 *   expiring_30  (only with $withExpiring) non-voided completions expiring between today and today + 30
 *                with no newer non-voided completion for the same (person, course)
 */
final class NavCounts
{
    /** @return array{overdue:int, expiring_30:?int} */
    public static function forCtx(Ctx $c, bool $withExpiring = false, ?string $today = null): array
    {
        return self::forScope($c->db, Scope::forCtx($c), $withExpiring, $today);
    }

    /** @return array{overdue:int, expiring_30:?int} */
    public static function forScope(\mysqli $db, Scope $s, bool $withExpiring = false, ?string $today = null): array
    {
        if ($s->isNone()) {
            return ['overdue' => 0, 'expiring_30' => $withExpiring ? 0 : null];
        }
        $today ??= Clock::todayLocal();
        [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
        $overdue = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_assignments a
                JOIN contacts c ON c.contact_id = a.tassign_contact_id ' . Roster::JOIN . "
            WHERE a.tassign_status = 'open' AND a.tassign_required = 1 AND a.tassign_due_on < ? AND " . Roster::ELIGIBLE . $scopeSql,
            's' . $types, array_merge([$today], $params))['n'] ?? 0);

        $expiring = null;
        if ($withExpiring) {
            $expiring = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_completions t
                    JOIN contacts c ON c.contact_id = t.completion_contact_id ' . Roster::JOIN . '
                WHERE t.completion_expires_on BETWEEN ? AND ?
                  AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = t.completion_id)
                  AND NOT EXISTS (SELECT 1 FROM training_completions n
                        WHERE n.completion_contact_id = t.completion_contact_id AND n.completion_course_id = t.completion_course_id
                          AND (n.completion_completed_on > t.completion_completed_on
                               OR (n.completion_completed_on = t.completion_completed_on AND n.completion_id > t.completion_id))
                          AND NOT EXISTS (SELECT 1 FROM training_completion_voids nv WHERE nv.cvoid_completion_id = n.completion_id))
                  AND ' . Roster::ELIGIBLE . $scopeSql,
                'ss' . $types, array_merge([$today, Clock::addDays($today, 30)], $params))['n'] ?? 0);
        }
        return ['overdue' => $overdue, 'expiring_30' => $expiring];
    }
}
