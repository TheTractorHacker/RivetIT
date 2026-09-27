<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Core\RevisionCache;

/**
 * An agent "Reset progress" on an assignment (Assign\AssignmentReset), as the kiosk engine sees it.
 *
 *   describe()     what the pair's open run holds, for the agent dialog ("exactly what will be cleared");
 *   abandonInTx()  closes the run: status abandoned, trun_open_guard NULL, ended now. Its lesson completions,
 *                  attempts, answer logs and signatures stay as the history of THAT run and no longer count:
 *                  the next run_start opens a fresh run (lesson 1, a fresh try count, no lock, the course's
 *                  current version). A run waiting for a trainer session or practical evaluation leaves the
 *                  trainer's lists with it (they read open runs). Returns the run.reset ledger event (actor =
 *                  the agent, with the reason) for the caller to append LAST;
 *   guard()        KioskRouter, before a learner handler: a request naming a run an agent reset (run_id, or an
 *                  attempt_id of it) answers 409 run_reset, and the kiosk runtime says "Your progress on this
 *                  course was reset by your supervisor..." and goes back to the course list - never an error screen.
 *
 * Kept apart from RunService so the kiosk engine files stay untouched. Lock order (P3 §0.8): the run row is
 * the caller's FIRST lock; the ledger head is its last.
 */
final class RunReset
{
    public const ERR = 'run_reset';
    public const EVENT = 'run.reset';

    /** Learner actions whose run id (run_id, or attempt_id -> its run) the guard checks. */
    private const RUN_KEYS = ['run_id'];

    /**
     * The facts the agent dialog lists for an open run: {run_id, status, started_at, last_activity_at, progress_pct,
     * lessons_done, lessons_required, current_lesson, locked:{lesson,at}|null, blocked:{reason,lesson}|null,
     * tries:{total, failed, open}, signed_at, revision_number, newer_version:bool, open_sessions:[held_on]}.
     */
    public static function describe(\mysqli $db, array $run): array
    {
        $runId = (int) $run['trun_id'];
        $doc = null;
        try {
            $doc = RevisionCache::get($db, (int) $run['trun_revision_id'])['doc'];
        } catch (\Throwable $e) {
            error_log('Training run reset describe #' . $runId . ': ' . get_class($e));
        }
        $title = static function (?string $uid) use ($doc, $run): ?string {
            if ($uid === null || $uid === '' || !is_array($doc)) {
                return null;
            }
            $l = RunRepo::lesson($doc, $uid);
            if ($l === null) {
                return null;
            }
            $v = RunRepo::variant($l, RunRepo::lang($run, $doc), (string) ($doc['course']['default_language'] ?? 'en'));
            $t = trim((string) ($v['title'] ?? ''));
            return $t === '' ? null : $t;
        };
        $required = is_array($doc) ? count(RunRepo::requiredUids($doc)) : 0;
        $doneRow = Db::one($db, 'SELECT COUNT(DISTINCT lcomp_lesson_uid) AS n FROM training_lesson_completions WHERE lcomp_run_id = ?', 'i', [$runId]);
        $tries = Db::one($db, 'SELECT COUNT(*) AS n, COALESCE(SUM(r.tresult_passed = 0), 0) AS failed, COALESCE(SUM(r.tresult_attempt_id IS NULL), 0) AS open_n
            FROM training_attempts a LEFT JOIN training_attempt_results r ON r.tresult_attempt_id = a.tattempt_id WHERE a.tattempt_run_id = ?', 'i', [$runId]);
        $course = Db::one($db, 'SELECT c.course_current_revision_id, r.revision_number
            FROM training_courses c LEFT JOIN training_revisions r ON r.revision_id = ? WHERE c.course_id = ?', 'ii',
            [(int) $run['trun_revision_id'], (int) $run['trun_course_id']]);
        $sessions = [];
        if ((string) $run['trun_status'] === 'awaiting_session') {
            foreach (Db::all($db, "SELECT s.tsession_held_on FROM training_sessions s
                    JOIN training_session_attendees a ON a.tattendee_tsession_id = s.tsession_id
                    WHERE s.tsession_course_id = ? AND s.tsession_status = 'open' AND a.tattendee_contact_id = ? AND a.tattendee_removed_at_utc IS NULL
                    ORDER BY s.tsession_held_on", 'ii', [(int) $run['trun_course_id'], (int) $run['trun_contact_id']]) as $s) {
                $sessions[] = (string) $s['tsession_held_on'];
            }
        }
        $current = $run['trun_current_lesson_uid'] === null ? null : (string) $run['trun_current_lesson_uid'];
        return [
            'run_id' => $runId,
            'status' => (string) $run['trun_status'],
            'started_at' => Clock::toIso((string) $run['trun_started_at_utc'], true),
            'last_activity_at' => Clock::toIso((string) $run['trun_last_activity_at_utc'], true),
            'progress_pct' => (int) $run['trun_progress_pct'],
            'lessons_done' => (int) ($doneRow['n'] ?? 0),
            'lessons_required' => $required,
            'current_lesson' => $title($current),
            'locked' => $run['trun_locked_at_utc'] === null ? null
                : ['lesson' => $title($run['trun_locked_lesson_uid'] === null ? null : (string) $run['trun_locked_lesson_uid']),
                   'at' => Clock::toIso((string) $run['trun_locked_at_utc'], true)],
            'blocked' => $run['trun_blocked_reason'] === null ? null
                : ['reason' => (string) $run['trun_blocked_reason'],
                   'lesson' => $title($run['trun_blocked_lesson_uid'] === null ? null : (string) $run['trun_blocked_lesson_uid'])],
            'tries' => ['total' => (int) ($tries['n'] ?? 0), 'failed' => (int) ($tries['failed'] ?? 0), 'open' => (int) ($tries['open_n'] ?? 0)],
            'signed_at' => $run['trun_attested_at_utc'] === null ? null : Clock::toIso((string) $run['trun_attested_at_utc'], true),
            'revision_number' => isset($course['revision_number']) ? (int) $course['revision_number'] : null,
            'newer_version' => $course !== null && $course['course_current_revision_id'] !== null
                && (int) $course['course_current_revision_id'] !== (int) $run['trun_revision_id'],
            'open_sessions' => $sessions,
        ];
    }

    /**
     * INSIDE the caller's Db::tx, with $run locked FOR UPDATE as the transaction's first lock: closes the open run
     * and returns its run.reset event (append it last). $actor = ledger actor fields (actor_type, actor_user_id,
     * user_agent). The payload keeps what the run held when it was reset.
     */
    public static function abandonInTx(\mysqli $db, array $run, array $actor, string $reason, ?int $assignmentId, array $facts = []): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('RunReset::abandonInTx must run inside Db::tx');
        }
        $runId = (int) $run['trun_id'];
        $n = Db::exec($db, "UPDATE training_runs SET trun_status = 'abandoned', trun_open_guard = NULL, trun_ended_at_utc = ?, trun_current_lesson_uid = NULL
            WHERE trun_id = ? AND trun_open_guard = 1", 'si', [KTime::now(), $runId]);
        if ($n !== 1) {
            throw new \LogicException("RunReset: run #$runId is not open (lock it first)");
        }
        return RunRepo::event($actor, self::EVENT, $run, 'run', $runId, null, [
            'assignment_id' => $assignmentId,
            'reason' => $reason,
            'status' => (string) $run['trun_status'],
            'progress_pct' => (int) $run['trun_progress_pct'],
            'locked' => $run['trun_locked_at_utc'] !== null,
            'blocked' => $run['trun_blocked_reason'] === null ? null : (string) $run['trun_blocked_reason'],
            'signed' => $run['trun_attested_at_utc'] !== null,
            'failed_tries' => (int) ($facts['tries']['failed'] ?? 0),
        ]);
    }

    /** Was this (closed) run ended by an agent reset? */
    public static function wasReset(\mysqli $db, int $runId): bool
    {
        return Db::one($db, "SELECT tevent_seq FROM training_events WHERE tevent_entity_type = 'run' AND tevent_entity_id = ? AND tevent_type = ? LIMIT 1",
            'is', [$runId, self::EVENT]) !== null;
    }

    /**
     * KioskRouter, after auth and CSRF, before the handler: a learner request for one of their own runs that an agent
     * reset gets 409 run_reset instead of the handler's "closed" error (and an exam_submit is not graded into it).
     * Anything else passes through untouched (a run that is not theirs gets the handler's own 404).
     */
    public static function guard(KioskCtx $k, array $input): void
    {
        $cid = $k->contactId();
        if ($cid < 1 || ($k->role() ?? '') !== 'learner') {
            return;
        }
        $db = $k->db();
        $runId = 0;
        foreach (self::RUN_KEYS as $key) {
            $runId = self::id($input[$key] ?? null);
            if ($runId > 0) {
                break;
            }
        }
        if ($runId < 1 && ($attemptId = self::id($input['attempt_id'] ?? null)) > 0) {
            $a = Db::one($db, 'SELECT tattempt_run_id FROM training_attempts WHERE tattempt_id = ? AND tattempt_contact_id = ?', 'ii', [$attemptId, $cid]);
            $runId = $a === null ? 0 : (int) $a['tattempt_run_id'];
        }
        if ($runId < 1) {
            return;
        }
        $run = Db::one($db, 'SELECT trun_contact_id, trun_course_id, trun_status FROM training_runs WHERE trun_id = ?', 'i', [$runId]);
        if ($run === null || (int) $run['trun_contact_id'] !== $cid || (string) $run['trun_status'] !== 'abandoned' || !self::wasReset($db, $runId)) {
            return;
        }
        throw new ApiException(409, self::ERR, "Your progress on this course was reset by your supervisor. Start again when you're ready.", [],
            ['course_id' => (int) $run['trun_course_id']]);
    }

    private static function id(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        return is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1 ? (int) $v : 0;
    }
}
