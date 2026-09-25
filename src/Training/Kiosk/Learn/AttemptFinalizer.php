<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * The lazy finalizer (P3 spec §3.4, §0.4): grades timed attempts that were abandoned - deadline
 * more than GRACE_S ago and no result - through AttemptService's grading path, in one Db::tx per
 * attempt (run row, then attempt row, FOR UPDATE), with finalized_by 'finalizer' and
 * timed_out 1. Idempotent (a result that appeared meanwhile is skipped) and system-derived, so it
 * may run on GET (Learning Center, course, sign), at exam_start and from cron.
 */
final class AttemptFinalizer
{
    /**
     * @param array $actor ledger actor fields (request: KioskCtx::eventBase(); cron: [] => system)
     * @return int attempts finalized
     */
    public static function finalizeExpired(\mysqli $db, ?int $runId = null, int $limit = 50, array $actor = []): int
    {
        if (Db::depth() !== 0) {
            return 0;
        }
        if ($actor === []) {
            $actor = AttemptService::SYSTEM_ACTOR;
        }
        $before = KTime::plus(-AttemptService::GRACE_S);
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT a.tattempt_id, a.tattempt_run_id, a.tattempt_contact_id FROM training_attempts a
            WHERE a.tattempt_deadline_utc IS NOT NULL AND a.tattempt_deadline_utc < ?
              AND NOT EXISTS (SELECT 1 FROM training_attempt_results r WHERE r.tresult_attempt_id = a.tattempt_id)'
            . ($runId !== null ? ' AND a.tattempt_run_id = ?' : '') . ' ORDER BY a.tattempt_id LIMIT ' . $limit;
        try {
            $rows = $runId !== null ? Db::all($db, $sql, 'si', [$before, $runId]) : Db::all($db, $sql, 's', [$before]);
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1146) {
                return 0;   // before 2.6.93
            }
            throw $e;
        }
        $n = 0;
        foreach ($rows as $r) {
            $attemptId = (int) $r['tattempt_id'];
            $rid = (int) $r['tattempt_run_id'];
            try {
                $facts = Db::tx($db, static function () use ($db, $attemptId, $rid, $actor): ?array {
                    $run = RunRepo::load($db, $rid, true);
                    $att = AttemptService::load($db, $attemptId, true);
                    if ($run === null || $att === null) {
                        return null;
                    }
                    if (Db::one($db, 'SELECT tresult_attempt_id FROM training_attempt_results WHERE tresult_attempt_id = ?', 'i', [$attemptId]) !== null) {
                        return null;
                    }
                    $g = AttemptService::gradeInTx($db, $run, $att, null, 'finalizer', $actor);
                    foreach ($g['events'] as $e) {
                        Ledger::append($db, $e);
                    }
                    return ['facts' => $g['facts']];
                });
            } catch (\Throwable $e) {
                error_log('Kiosk AttemptFinalizer attempt #' . $attemptId . ': ' . get_class($e));
                continue;
            }
            if ($facts === null) {
                continue;
            }
            $n++;
            if ($facts['facts'] !== null) {
                AttemptService::examAwards($db, $actor, (int) $r['tattempt_contact_id'], $facts['facts']);
            }
        }
        return $n;
    }
}
