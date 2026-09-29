<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;
use ITFlow\Training\Kiosk\Core\Hashed;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Core\RateLimiter;
use ITFlow\Training\Kiosk\Core\RevisionCache;

/**
 * Course runs on the kiosk (P3 spec §3.4): one pass of one person through one published
 * revision, with server-credited lesson time and coverage (LessonCredit), sequential gating,
 * lesson completions (hashed, evented), video-change blocking, acknowledgment sign-off, lazy
 * settling of runs waiting for another component, and agent/trainer unlocks.
 *
 * The learner is ALWAYS the kiosk session's contact ($k->contactId()); every run id from a
 * request is checked against it (404 otherwise). Writes run in Db::tx with the run row locked
 * first and the ledger appended last (§0.8). Ticks are the one exception: a single
 * compare-and-set UPDATE, no transaction, no event (they are too frequent to ledger).
 */
final class RunService
{
    /** Provider error codes that mean the video is gone for good (§3.4 videoError). */
    public const PERMANENT_VIDEO_ERRORS = ['youtube' => ['100', '101', '150'], 'vimeo' => ['NotFoundError', 'PrivacyError']];
    public const VIDEO_PROVIDERS = ['upload', 'youtube', 'vimeo'];
    public const UNLOCK_MODES = ['extra', 'restart'];

    public function __construct(private readonly KioskCtx $k)
    {
    }

    // =========================================================================================
    // start / state
    // =========================================================================================

    /**
     * run_start {course_id, lang?}: resumes the open run or opens a new one on the course's CURRENT
     * revision (§3.4). An in-progress run that is blocked, or on a revision that requires
     * retraining, is superseded by a fresh run on the current revision.
     *
     * $lang (the learner's explicit choice, when the course has it) sets a new run's language. A run
     * keeps its language once work is recorded (L-9), but an open run with nothing done yet (no
     * lesson completion, no exam attempt) in another language is superseded by a new run in $lang,
     * so a person who tapped Start on the wrong language is not stuck with it.
     */
    public function start(int $courseId, ?string $lang = null): array
    {
        $db = $this->k->db();
        $cid = $this->contact();
        $course = Db::one($db, 'SELECT course_id, course_uid, course_name, course_current_revision_id, course_archived_at FROM training_courses WHERE course_id = ?',
            'i', [$courseId]);
        if ($course === null || $course['course_archived_at'] !== null || $course['course_current_revision_id'] === null) {
            throw ApiException::notFound('This course is not available.');
        }
        $rev = RevisionCache::get($db, (int) $course['course_current_revision_id']);
        $doc = $rev['doc'];
        if (empty($doc['course']['components']['online'])) {
            throw new ApiException(409, 'no_online_part', 'This course is done with your trainer, not on the kiosk.');
        }
        $bridge = new RecordsBridge($this->k->core);
        $assignmentId = $bridge->openAssignmentId($cid, $courseId);
        $asked = $lang !== null && in_array($lang, $doc['course']['languages'] ?? [], true) ? $lang : null;
        $lang = $asked ?? $this->preferredLanguage($cid, $doc);
        $actor = $this->k->eventBase();

        $run = Db::tx($db, function () use ($db, $cid, $courseId, $course, $rev, $doc, $bridge, $assignmentId, $lang, $asked, $actor): array {
            $open = RunRepo::open($db, $cid, $courseId, true);
            $old = null;
            if ($open !== null && $asked !== null && (string) $open['trun_language'] !== $asked && self::isFresh($db, $open)) {
                $old = $open;   // nothing done yet: start over in the language the person chose
            } elseif ($open !== null) {
                $inProgress = $open['trun_status'] === 'in_progress';
                $sameRev = (int) $open['trun_revision_id'] === $rev['id'];
                if ($inProgress && $open['trun_blocked_reason'] !== null && !$sameRev) {
                    $old = $open;
                } elseif ($sameRev || !$rev['requires_retraining'] || !$inProgress) {
                    return $open;
                } else {
                    $old = $open;
                }
            }
            $this->assertPrereqs($db, $bridge, $cid, $courseId);
            $now = KTime::now();
            $channel = ($this->k->device['kiosk_enroll_method'] ?? '') === 'portal' ? 'portal' : 'kiosk';
            if ($old !== null) {
                Db::exec($db, "UPDATE training_runs SET trun_status = 'superseded', trun_open_guard = NULL, trun_ended_at_utc = ?, trun_current_lesson_uid = NULL,
                    trun_lesson_resume_at = NULL WHERE trun_id = ?", 'si', [$now, (int) $old['trun_id']]);
            }
            try {
                $id = Db::insert($db, "INSERT INTO training_runs (trun_contact_id, trun_course_id, trun_revision_id, trun_revision_sha256, trun_assignment_id,
                        trun_language, trun_status, trun_open_guard, trun_channel, trun_started_at_utc, trun_started_kiosk_id, trun_last_activity_at_utc)
                    VALUES (?, ?, ?, ?, ?, ?, 'in_progress', 1, ?, ?, ?, ?)", 'iiisisssis',
                    [$cid, $courseId, $rev['id'], $rev['sha256'], $assignmentId, $lang, $channel, $now, $this->k->kioskId() > 0 ? $this->k->kioskId() : null, $now]);
            } catch (\mysqli_sql_exception $e) {
                if ((int) $e->getCode() !== 1062) {
                    throw $e;
                }
                $again = RunRepo::open($db, $cid, $courseId, true);
                if ($again === null) {
                    throw $e;
                }
                return $again;
            }
            $new = RunRepo::load($db, $id, true);
            if ($old !== null) {
                Db::exec($db, 'UPDATE training_runs SET trun_superseded_by_run_id = ? WHERE trun_id = ?', 'ii', [$id, (int) $old['trun_id']]);
                Ledger::append($db, RunRepo::event($actor, 'run.superseded', $old, 'run', (int) $old['trun_id'], null, ['by_run_id' => $id]));
            }
            Ledger::append($db, RunRepo::event($actor, 'run.start', $new, 'run', $id, null, [
                'course_uid' => (string) $course['course_uid'],
                'revision_id' => $rev['id'],
                'revision_sha256' => $rev['sha256'],
                'language' => $lang,
                'assignment_id' => $assignmentId,
            ]));
            return $new;
        });
        return $this->state($run);
    }

    /**
     * RunState (§4.2): {run_id, course_id, revision_id, language, status, locked, locked_lesson_uid|null,
     * blocked:{reason,lesson_uid}|null, progress_pct, done:{uid:true}, credited:{uid:true}, current_uid, current_gate|null,
     * quizzes:{uid:{used,max,left,locked,passed,open,check,must_pass}}, attested, completion_id, awaiting, reopened}.
     * `max`/`left` are null for unlimited attempts. `quizzes` also lists every content lesson's quick
     * check (check:true); `credited` names lessons whose own work is recorded, which for a lesson
     * with a must-pass quick check that is not passed yet is not the same as `done`.
     */
    public function state(array $run): array
    {
        $db = $this->k->db();
        $rev = RunRepo::revision($db, $run);
        $doc = $rev['doc'];
        $credited = RunRepo::credited($db, (int) $run['trun_id']);
        $done = RunRepo::done($db, (int) $run['trun_id'], $doc, $credited);
        $quizzes = self::quizzes($db, $run, $doc);
        $current = $run['trun_current_lesson_uid'] === null ? null : (string) $run['trun_current_lesson_uid'];
        $currentLesson = $current === null ? null : RunRepo::lesson($doc, $current);
        $status = (string) $run['trun_status'];
        return [
            'run_id' => (int) $run['trun_id'],
            'course_id' => (int) $run['trun_course_id'],
            'revision_id' => (int) $run['trun_revision_id'],
            'language' => RunRepo::lang($run, $doc),
            'status' => $status,
            'locked' => $run['trun_locked_at_utc'] !== null,
            // the quiz or quick check that ran out of tries (the course page names it)
            'locked_lesson_uid' => $run['trun_locked_at_utc'] !== null && $run['trun_locked_lesson_uid'] !== null ? (string) $run['trun_locked_lesson_uid'] : null,
            'blocked' => $run['trun_blocked_reason'] === null ? null
                : ['reason' => (string) $run['trun_blocked_reason'], 'lesson_uid' => $run['trun_blocked_lesson_uid'] === null ? null : (string) $run['trun_blocked_lesson_uid']],
            'progress_pct' => (int) $run['trun_progress_pct'],
            'done' => (object) $done,
            'credited' => (object) $credited,
            'current_uid' => $currentLesson === null ? null : $current,
            'current_gate' => $currentLesson === null ? null : self::gateFor($run, $doc, $currentLesson, $done, $credited),
            'quizzes' => (object) $quizzes,
            'attested' => $run['trun_attested_at_utc'] !== null,
            // nothing recorded yet: the course page may still switch the run to the screen's language
            'fresh' => $status === 'in_progress' && $credited === [] && $run['trun_locked_at_utc'] === null && $run['trun_blocked_reason'] === null
                && array_sum(array_map(static fn($q) => (int) ($q['used'] ?? 0), $quizzes)) === 0,
            'completion_id' => $run['trun_completion_id'] === null ? null : (int) $run['trun_completion_id'],
            'awaiting' => in_array($status, RunRepo::AWAITING, true) ? $status : null,
            // A run from before kiosk quick checks that was moved back from "Sign to finish" (run.reopened) and
            // still has a must-pass quick check to pass: the course page says why (once it is passed, the run
            // waits for the sign-off again and this is false).
            'reopened' => $status === 'in_progress' && RunRepo::pendingChecks($doc, $credited, $done) !== []
                && RunRepo::reopened($db, (int) $run['trun_id']),
        ];
    }

    /**
     * set_language (learner): open runs with nothing done yet follow the person's new language when
     * their course has it (each is superseded by a new run via start()). Best-effort: a course that
     * can't start now is left as it is.
     */
    public function followLanguage(string $lang): void
    {
        $db = $this->k->db();
        $cid = $this->contact();
        foreach (RunRepo::openRuns($db, $cid) as $r) {
            if ((string) $r['trun_language'] === $lang || !self::isFresh($db, $r)) {
                continue;
            }
            try {
                $this->start((int) $r['trun_course_id'], $lang);
            } catch (\Throwable $e) {
                error_log('Kiosk followLanguage: ' . get_class($e));
            }
        }
    }

    /** An open in-progress run with nothing recorded yet: no lesson completion, no exam attempt, not locked, blocked or signed. */
    public static function isFresh(\mysqli $db, array $run): bool
    {
        if ((string) $run['trun_status'] !== 'in_progress' || $run['trun_locked_at_utc'] !== null || $run['trun_blocked_reason'] !== null
            || $run['trun_attested_at_utc'] !== null) {
            return false;
        }
        $id = (int) $run['trun_id'];
        return Db::one($db, 'SELECT 1 AS x FROM training_lesson_completions WHERE lcomp_run_id = ? LIMIT 1', 'i', [$id]) === null
            && Db::one($db, 'SELECT 1 AS x FROM training_attempts WHERE tattempt_run_id = ? LIMIT 1', 'i', [$id]) === null;
    }

    /** The state of the caller's own run by id (course.php, sign.php). */
    public function stateById(int $runId): array
    {
        return $this->state(RunRepo::own($this->k->db(), $runId, $this->contact()));
    }

    // =========================================================================================
    // lessons
    // =========================================================================================

    /** lesson_open {run_id, lesson_uid} => Gate (§3.4 "lessonOpen"). */
    public function lessonOpen(int $runId, string $uid): array
    {
        $db = $this->k->db();
        $cid = $this->contact();
        return Db::tx($db, function () use ($db, $cid, $runId, $uid): array {
            $run = RunRepo::own($db, $runId, $cid, true);
            $doc = RunRepo::revision($db, $run)['doc'];
            $lesson = self::lessonOr404($doc, $uid);
            $credited = RunRepo::credited($db, $runId);
            $done = RunRepo::done($db, $runId, $doc, $credited);
            if ($run['trun_status'] !== 'in_progress') {
                if ((int) ($run['trun_open_guard'] ?? 0) !== 1) {
                    throw new ApiException(409, 'run_locked', 'This course run is closed. Open the course again.');
                }
                return self::gateFor($run, $doc, $lesson, $done, $credited);   // review only: nothing is credited once every lesson is done
            }
            self::assertRunUsable($run);
            if ($lesson['type'] === 'quiz') {
                return self::gateFor($run, $doc, $lesson, $done, $credited);
            }
            RunRepo::assertUnlocked($doc, $done, $uid);
            if (isset($credited[$uid])) {
                // Done, or its content credited with a must-pass quick check still to pass: nothing is reset.
                return self::gateFor($run, $doc, $lesson, $done, $credited);
            }
            $now = KTime::now();
            if ($run['trun_current_lesson_uid'] === $uid) {
                Db::exec($db, 'UPDATE training_runs SET trun_lesson_last_tick_at_utc = ?, trun_last_activity_at_utc = ? WHERE trun_id = ?', 'ssi', [$now, $now, $runId]);
                $run['trun_lesson_last_tick_at_utc'] = $now;
            } else {
                $fresh = LessonCredit::fresh((string) $lesson['type'], $now);
                Db::exec($db, 'UPDATE training_runs SET trun_current_lesson_uid = ?, trun_lesson_opened_at_utc = ?, trun_lesson_last_tick_at_utc = ?,
                        trun_lesson_last_active = ?, trun_lesson_credit_s = 0, trun_lesson_max_position = 0, trun_lesson_pages_hex = NULL,
                        trun_lesson_rejected_ticks = 0, trun_lesson_resume_at = NULL, trun_last_activity_at_utc = ?
                    WHERE trun_id = ?', 'sssisi', [$uid, $now, $now, $fresh['last_active'] ? 1 : 0, $now, $runId]);
                $run = RunRepo::load($db, $runId) ?? $run;
            }
            return self::gateFor($run, $doc, $lesson, $done, $credited);
        });
    }

    /**
     * lesson_tick {run_id, lesson_uid, position_s?, pages_seen?, current_page?, playing, visible, active, video_id?} => Gate.
     * One compare-and-set UPDATE (no transaction, no ledger): a concurrent tick that moved the
     * state first wins and this one is dropped (its interval is credited by the next tick). The
     * sample's position (video) or current_page (document) is stored as the lesson's last point
     * (ResumePoint, navigation only) - also by a tick that changes no credit, and after a lost race.
     */
    public function tick(int $runId, string $uid, array $sample): array
    {
        $db = $this->k->db();
        $run = RunRepo::own($db, $runId, $this->contact());
        $doc = RunRepo::revision($db, $run)['doc'];
        $lesson = self::lessonOr404($doc, $uid);
        $credited = RunRepo::credited($db, $runId);
        $done = RunRepo::done($db, $runId, $doc, $credited);
        if ($run['trun_status'] !== 'in_progress' || isset($credited[$uid]) || $lesson['type'] === 'quiz') {
            if ((int) ($run['trun_open_guard'] ?? 0) !== 1) {
                throw new ApiException(409, 'run_locked', 'This course run is closed. Open the course again.');
            }
            return self::gateFor($run, $doc, $lesson, $done, $credited);
        }
        self::assertRunUsable($run);
        if ($run['trun_current_lesson_uid'] !== $uid) {
            // A late tick from a lesson the learner already left: nothing is credited; the gate is returned as-is.
            return self::gateFor($run, $doc, $lesson, $done, $credited);
        }
        [$type, $duration, $videoId, $pages] = self::lessonFacts($run, $doc, $lesson);
        $old = LessonCredit::fromRun($run);
        $new = LessonCredit::applyTick($old, $sample, $type, $duration, $videoId, $pages, KTime::now());
        // The last point (navigation only, never credit): the sample's position / page, kept at or below the furthest
        // point after this tick; a sample without one keeps the stored point. A tick that changes no credit column (a
        // pause right after a play, same-state chatter) still stores it.
        $oldResume = ($run[ResumePoint::COLUMN] ?? null) === null ? null : (int) $run[ResumePoint::COLUMN];
        $newResume = ResumePoint::fromSample($sample, $type, $new['max_position'], $pages, $videoId) ?? $oldResume;
        if ($new['last_tick'] === $old['last_tick'] && $new['credit'] === $old['credit'] && $new['pages_hex'] === $old['pages_hex']
            && $new['max_position'] === $old['max_position'] && $new['rejected'] === $old['rejected'] && $new['last_active'] === $old['last_active']
            && $newResume === $oldResume) {
            return self::gateFor($run, $doc, $lesson, $done, $credited);
        }
        $n = Db::exec($db, 'UPDATE training_runs SET trun_lesson_last_tick_at_utc = ?, trun_lesson_last_active = ?, trun_lesson_credit_s = ?,
                trun_lesson_max_position = ?, trun_lesson_pages_hex = ?, trun_lesson_rejected_ticks = ?, trun_lesson_resume_at = ?, trun_last_activity_at_utc = ?
            WHERE trun_id = ? AND trun_status = \'in_progress\' AND trun_current_lesson_uid = ? AND trun_lesson_last_tick_at_utc <=> ?
              AND trun_lesson_credit_s = ? AND trun_lesson_pages_hex <=> ? AND trun_lesson_rejected_ticks = ?',
            'siiisiis' . 'issisi', [$new['last_tick'], $new['last_active'] ? 1 : 0, $new['credit'], $new['max_position'], $new['pages_hex'], $new['rejected'],
                $newResume, $new['applied'] ? KTime::now() : (string) $run['trun_last_activity_at_utc'],
                $runId, $uid, $old['last_tick'], $old['credit'], $old['pages_hex'], $old['rejected']]);
        $fresh = $n > 0 ? null : RunRepo::load($db, $runId);
        if ($fresh === null) {
            foreach (LessonCredit::COLUMNS as $k => $col) {
                $run[$col] = is_bool($new[$k]) ? ($new[$k] ? 1 : 0) : $new[$k];
            }
            $run[ResumePoint::COLUMN] = $newResume;
            return self::gateFor($run, $doc, $lesson, $done, $credited);
        }
        if ($newResume !== $oldResume && $newResume !== null && $fresh['trun_status'] === 'in_progress' && $fresh['trun_current_lesson_uid'] === $uid) {
            // A concurrent tick moved the credit state first (this tick's interval is credited by the next one), but the
            // point this sample reports - often the pause, the newest - is still stored: a video point no further than
            // the furthest point now on the row.
            Db::exec($db, 'UPDATE training_runs SET trun_lesson_resume_at = ' . ($type === 'video' ? 'LEAST(?, trun_lesson_max_position)' : '?')
                . ' WHERE trun_id = ? AND trun_status = \'in_progress\' AND trun_current_lesson_uid = ?', 'iis', [$newResume, $runId, $uid]);
            $fresh = RunRepo::load($db, $runId) ?? $fresh;
        }
        $credited = RunRepo::credited($db, $runId);
        return self::gateFor($fresh, $doc, $lesson, RunRepo::done($db, $runId, $doc, $credited), $credited);
    }

    /**
     * lesson_complete {run_id, lesson_uid, evidence:{position_s?, pages_seen?, video_id?}}
     * => {progress_pct, run_status, next_uid, done, credited, check|null}. The evidence is applied as
     * a final tick; 422 gate_not_met when the server gate is not met. Idempotent: a credited lesson
     * returns the state. A lesson with a quick check says so in `check` ({must_pass, used, max, left,
     * locked, passed}): the player offers the check next. A MUST-PASS check keeps the lesson out of
     * `done` (progress, order, sign-off) until it is passed; the completion row is written now either
     * way, so the watched/read evidence is never lost.
     */
    public function lessonComplete(int $runId, string $uid, array $evidence): array
    {
        $db = $this->k->db();
        $cid = $this->contact();
        $actor = $this->k->eventBase();
        return Db::tx($db, function () use ($db, $cid, $runId, $uid, $evidence, $actor): array {
            $run = RunRepo::own($db, $runId, $cid, true);
            $doc = RunRepo::revision($db, $run)['doc'];
            $lesson = self::lessonOr404($doc, $uid);
            $credited = RunRepo::credited($db, $runId);
            $done = RunRepo::done($db, $runId, $doc, $credited);
            if (isset($credited[$uid])) {
                return self::completeResponse($db, $run, $doc, $done, $credited, $lesson);
            }
            if ($run['trun_status'] !== 'in_progress') {
                throw new ApiException(409, 'run_locked', 'This course run is closed. Open the course again.');
            }
            self::assertRunUsable($run);
            if ($lesson['type'] === 'quiz') {
                throw new ApiException(409, 'use_exam_start', 'Start the quiz to finish this lesson.');
            }
            if ($lesson['type'] === 'acknowledgment') {
                throw new ApiException(409, 'use_ack_sign', 'Sign the acknowledgment to finish this lesson.');
            }
            RunRepo::assertUnlocked($doc, $done, $uid);
            if ($run['trun_current_lesson_uid'] !== $uid) {
                throw new ApiException(409, 'lesson_locked', 'Open this lesson again to continue.');
            }
            [$type, $duration, $videoId, $pages] = self::lessonFacts($run, $doc, $lesson);
            $variant = RunRepo::variant($lesson, RunRepo::lang($run, $doc), (string) $doc['course']['default_language']);
            $required = LessonCredit::requiredSeconds($lesson, $variant);
            $st = LessonCredit::fromRun($run);
            $sample = [
                'visible' => true, 'playing' => $st['last_active'], 'active' => $st['last_active'],
                'position_s' => $evidence['position_s'] ?? null, 'pages_seen' => $evidence['pages_seen'] ?? [], 'video_id' => $evidence['video_id'] ?? null,
            ];
            $now = KTime::now();
            $st = LessonCredit::applyTick($st, array_filter($sample, static fn($v) => $v !== null), $type, $duration, $videoId, $pages, $now);
            $gate = LessonCredit::gate($st, $type, $required, $duration, (int) ($lesson['min_watch_pct'] ?? 100), $pages);
            if (!$gate['can_complete']) {
                $refused = self::gateShape($gate, $type, $duration, (int) ($lesson['min_watch_pct'] ?? 100), $pages, $videoId, false, false);
                if ($type === 'video' || $type === 'document') {
                    // the stored last point, as every other gate reports it (nothing is written here): without it the
                    // course list would fall back to the furthest point ("Continue at …")
                    $refused['resume_at'] = ResumePoint::forGate($run[ResumePoint::COLUMN] ?? null, $type, (int) $run['trun_lesson_max_position'], $pages);
                }
                throw new ApiException(422, 'gate_not_met', 'Spend a little more time on this lesson first.', [], $refused);
            }
            $coverage = match ($type) {
                'video' => ['max_position_s' => $st['max_position'], 'duration_s' => $duration,
                    'watch_pct' => $duration > 0 ? min(100, intdiv($st['max_position'] * 100, $duration)) : 0],
                'document' => ['pages_seen' => $gate['pages_seen'], 'page_count' => $pages],
                default => null,
            };
            $ins = self::insertLcomp($db, $run, $doc, $lesson, [
                'opened_at' => (string) ($run['trun_lesson_opened_at_utc'] ?? $now),
                'completed_at' => $now,
                'server_seconds' => $st['credit'],
                'required_seconds' => $required,
                'max_position' => $type === 'video' ? $st['max_position'] : null,
                'coverage' => $coverage,
            ], $this->k);
            if ($ins === null) {
                $credited = RunRepo::credited($db, $runId);
                return self::completeResponse($db, $run, $doc, RunRepo::done($db, $runId, $doc, $credited), $credited, $lesson);
            }
            $credited[$uid] = true;
            $done = RunRepo::done($db, $runId, $doc, $credited);   // a must-pass quick check keeps it out until passed
            $run = self::afterLessonDone($db, $run, $doc, $done, $now, false, $uid);
            Ledger::append($db, RunRepo::event($actor, 'run.lesson_complete', $run, 'lesson_completion', $ins['id'], $ins['sha'],
                ['run_id' => $runId, 'lesson_uid' => $uid, 'type' => $type, 'server_seconds' => $st['credit']]));
            return self::completeResponse($db, $run, $doc, $done, $credited, $lesson);
        });
    }

    /**
     * video_duration {run_id, lesson_uid, duration_s}: the player's live length. A difference of more
     * than max(2 s, 2%) from the published length blocks the run (the author changed the video):
     * run.blocked + a deduplicated lesson.video_error, then 409 video_changed.
     */
    public function videoDuration(int $runId, string $uid, int $liveS): void
    {
        $db = $this->k->db();
        $cid = $this->contact();
        $run = RunRepo::own($db, $runId, $cid);
        $doc = RunRepo::revision($db, $run)['doc'];
        $lesson = self::lessonOr404($doc, $uid);
        if ($lesson['type'] !== 'video') {
            throw ApiException::validation(['lesson_uid' => 'Not a video lesson.']);
        }
        $variant = RunRepo::variant($lesson, RunRepo::lang($run, $doc), (string) $doc['course']['default_language']);
        $frozen = LessonCredit::videoDuration($variant);
        if ($frozen <= 0 || abs($liveS - $frozen) <= max(2, (int) ceil($frozen * 0.02))) {
            return;
        }
        if (isset(RunRepo::credited($db, $runId)[$uid]) || $run['trun_status'] !== 'in_progress') {
            return;   // already watched: a later change does not take the lesson away
        }
        $provider = (string) ($variant['video']['provider'] ?? 'upload');
        $this->blockRun($runId, $uid, 'video_changed', $provider, (string) ($variant['video']['id'] ?? ''), 'video_changed', true);
        throw new ApiException(409, 'video_changed', 'This video changed since the course was published. Tell your trainer.');
    }

    /**
     * [S] lesson_error {run_id, lesson_uid, provider, code}: a player error, deduplicated per
     * session/run/lesson/code for 24 h. Permanent YouTube/Vimeo errors block the run
     * (video_unavailable); the agent restarts it on the current version.
     */
    public function videoError(int $runId, string $uid, string $provider, string $code): void
    {
        $db = $this->k->db();
        $run = RunRepo::own($db, $runId, $this->contact());
        $doc = RunRepo::revision($db, $run)['doc'];
        $lesson = self::lessonOr404($doc, $uid);
        $variant = RunRepo::variant($lesson, RunRepo::lang($run, $doc), (string) $doc['course']['default_language']);
        $permanent = in_array($code, self::PERMANENT_VIDEO_ERRORS[$provider] ?? [], true)
            && $lesson['type'] === 'video' && ($variant['video']['provider'] ?? null) === $provider;
        $block = $permanent && $run['trun_status'] === 'in_progress' && !isset(RunRepo::credited($db, $runId)[$uid]);
        $this->blockRun($runId, $uid, $block ? 'video_unavailable' : null, $provider, (string) ($variant['video']['id'] ?? ''), $code, $block);
    }

    /**
     * ack_sign {run_id, lesson_uid, signature_png?, pin?} (§3.4 "ackSign"): an acknowledgment lesson,
     * signed and/or confirmed with the PIN. The final acknowledgment of a DOCUMENT always needs the
     * PIN and completes the document (the attestation, with the P2 record).
     *
     * @return array{progress_pct:int, run_status:string, receipt:?array}
     */
    public function ackSign(int $runId, string $uid, ?string $sigDataUrl, mixed $pin): array
    {
        $db = $this->k->db();
        $cid = $this->contact();
        $run = RunRepo::own($db, $runId, $cid);
        $rev = RunRepo::revision($db, $run);
        $doc = $rev['doc'];
        $lesson = self::lessonOr404($doc, $uid);
        if ($lesson['type'] !== 'acknowledgment') {
            throw ApiException::validation(['lesson_uid' => 'Not an acknowledgment lesson.']);
        }
        $done = RunRepo::done($db, $runId, $doc);
        if (isset($done[$uid])) {
            unset($pin);
            return ['progress_pct' => (int) $run['trun_progress_pct'], 'run_status' => (string) $run['trun_status'], 'receipt' => null];
        }
        if ($run['trun_status'] !== 'in_progress') {
            throw new ApiException(409, 'run_locked', 'This course run is closed. Open the course again.');
        }
        self::assertRunUsable($run);
        RunRepo::assertUnlocked($doc, $done, $uid);
        if ($run['trun_current_lesson_uid'] !== $uid) {
            throw new ApiException(409, 'lesson_locked', 'Open this lesson again to continue.');
        }
        $isDocument = ($doc['course']['kind'] ?? 'training') === 'document';
        $needPin = $isDocument || !empty($lesson['ack']['require_pin']);
        $needSig = !empty($lesson['ack']['require_signature']);
        // Gate before anything is charged: the acknowledgment needs its few seconds of reading.
        $variant = RunRepo::variant($lesson, RunRepo::lang($run, $doc), (string) $doc['course']['default_language']);
        $required = LessonCredit::requiredSeconds($lesson, $variant);
        $pre = LessonCredit::applyTick(LessonCredit::fromRun($run), ['visible' => true, 'active' => !empty($run['trun_lesson_last_active'])], 'acknowledgment', 0, null, 0, KTime::now());
        if ($pre['credit'] < $required) {
            unset($pin);
            throw new ApiException(422, 'gate_not_met', 'Read the statement first.', [], ['credit_s' => $pre['credit'], 'required_s' => $required]);
        }
        $prep = null;
        if ($needSig) {
            try {
                $prep = SignatureService::prepare($sigDataUrl);
            } catch (SignatureException $e) {
                unset($pin);
                throw $e->toApi();
            }
        }
        $willComplete = RunRepo::allRequiredDone($doc, $done + [$uid => true]);
        $bridge = new RecordsBridge($this->k->core);
        if ($isDocument && !RecordsBridge::available($db)) {
            unset($pin);
            throw new ApiException(503, 'records_unavailable', 'Training records are not available yet. Your reading is saved - try again later.');
        }
        $pinInfo = null;
        if ($needPin) {
            $pinInfo = PinGate::stepUp($this->k, $pin, 'ack');
        }
        unset($pin);
        $startUtc = KTime::now();
        $statement = (string) ($variant['body_html'] ?? '');
        $actor = $this->k->eventBase();
        $out = Db::tx($db, function () use ($db, $cid, $runId, $uid, $rev, $doc, $lesson, $prep, $pinInfo, $isDocument, $statement, $required, $bridge, $actor): array {
            $run = RunRepo::own($db, $runId, $cid, true);
            $done = RunRepo::done($db, $runId, $doc);
            if (isset($done[$uid])) {
                return ['run' => $run, 'r' => null, 'receipt' => null];
            }
            if ($run['trun_status'] !== 'in_progress' || $run['trun_current_lesson_uid'] !== $uid) {
                throw new ApiException(409, 'run_locked', 'This course run changed. Open the course again.');
            }
            self::assertRunUsable($run);
            $now = KTime::now();
            $st = LessonCredit::applyTick(LessonCredit::fromRun($run), ['visible' => true, 'active' => !empty($run['trun_lesson_last_active'])],
                'acknowledgment', 0, null, 0, $now);
            if ($st['credit'] < $required) {
                throw new ApiException(422, 'gate_not_met', 'Read the statement first.', [], ['credit_s' => $st['credit'], 'required_s' => $required]);
            }
            $events = [];
            $sig = null;
            if ($prep !== null) {
                $sig = SignatureService::insert($db, $prep, [
                    'purpose' => 'ack', 'contact_id' => $cid, 'signer_name' => (string) ($this->k->ksess['contact_name'] ?? ''),
                    'statement_sha256' => hash('sha256', $statement), 'kiosk_id' => $this->k->kioskId() ?: null, 'ksess_id' => $this->k->ksessId(),
                    'run_id' => $runId, 'tsession_id' => null,
                ]);
                $events[] = array_merge($actor, SignatureService::event($sig, 'ack', $runId, null, $cid, (int) $run['trun_course_id']));
            }
            $ins = self::insertLcomp($db, $run, $doc, $lesson, [
                'opened_at' => (string) ($run['trun_lesson_opened_at_utc'] ?? $now),
                'completed_at' => $now,
                'server_seconds' => $st['credit'],
                'required_seconds' => $required,
                'max_position' => null,
                'coverage' => ['signed' => $sig !== null, 'pin' => $pinInfo !== null],
                'tsig_id' => $sig['id'] ?? null,
            ], $this->k);
            if ($ins === null) {
                throw new ApiException(409, 'busy', 'Busy - try again.');
            }
            $done[$uid] = true;
            $events[] = RunRepo::event($actor, 'run.lesson_complete', $run, 'lesson_completion', $ins['id'], $ins['sha'],
                ['run_id' => $runId, 'lesson_uid' => $uid, 'type' => 'acknowledgment', 'server_seconds' => $st['credit']]);
            $run = self::afterLessonDone($db, $run, $doc, $done, $now, $isDocument);
            $r = null;
            if ($isDocument && RunRepo::allRequiredDone($doc, $done)) {
                $att = AttestService::finishInTx($this->k, $bridge, $run, $rev, $sig, $sig !== null ? 'self_pin_signature' : 'self_pin', $pinInfo,
                    $statement, $now);
                $run = $att['run'];
                $r = $att['r'];
                $events = array_merge($events, $att['events']);
            }
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
            return ['run' => $run, 'r' => $r];
        });
        $receipt = null;
        if ($out['r'] !== null || ($isDocument && $out['run']['trun_attested_at_utc'] !== null)) {
            $bridge->afterCommit($out['r']);
            $receipt = AttestService::receiptFor($this->k, $out['run'], $doc, $out['r'], $startUtc);
        }
        return ['progress_pct' => (int) $out['run']['trun_progress_pct'], 'run_status' => (string) $out['run']['trun_status'], 'receipt' => $receipt];
    }

    // =========================================================================================
    // housekeeping
    // =========================================================================================

    /**
     * Lazy, idempotent, system-derived housekeeping for one person's OPEN runs (§0.4), run on the
     * Learning Center, course and sign GETs and by cron:
     *   course archived                    -> abandoned (run.abandoned {reason:'course_archived'})
     *   awaiting_signature with a required lesson whose MUST-PASS quick check was never passed
     *                                      -> in_progress (run.reopened {reason:'quick_check'}; see reopenForChecks)
     *   awaiting_session/_evaluation with a record for the run (completion_run_id, or recorded after
     *   the attestation)                   -> completed
     *   otherwise                          -> RecordsBridge::tryIssueComponents OUTSIDE any tx; a record closes the run
     */
    public function settleAwaiting(int $contactId): void
    {
        $db = $this->k->db();
        if ($contactId < 1 || Db::depth() !== 0) {
            return;
        }
        $rows = Db::all($db, 'SELECT r.trun_id, r.trun_course_id, r.trun_status, r.trun_attested_at_utc, c.course_archived_at
            FROM training_runs r LEFT JOIN training_courses c ON c.course_id = r.trun_course_id
            WHERE r.trun_contact_id = ? AND r.trun_open_guard = 1 ORDER BY r.trun_id', 'i', [$contactId]);
        if ($rows === []) {
            return;
        }
        $bridge = new RecordsBridge($this->k->core);
        $actor = $this->k->eventBase();
        foreach ($rows as $r) {
            $runId = (int) $r['trun_id'];
            if ($r['course_archived_at'] !== null) {
                Db::tx($db, function () use ($db, $runId, $actor): void {
                    $run = RunRepo::load($db, $runId, true);
                    if ($run === null || (int) ($run['trun_open_guard'] ?? 0) !== 1) {
                        return;
                    }
                    Db::exec($db, "UPDATE training_runs SET trun_status = 'abandoned', trun_open_guard = NULL, trun_ended_at_utc = ?, trun_current_lesson_uid = NULL,
                        trun_lesson_resume_at = NULL WHERE trun_id = ?", 'si', [KTime::now(), $runId]);
                    Ledger::append($db, RunRepo::event($actor, 'run.abandoned', $run, 'run', $runId, null, ['reason' => 'course_archived']));
                });
                continue;
            }
            if ($r['trun_status'] === 'awaiting_signature' && $r['trun_attested_at_utc'] === null) {
                try {
                    self::reopenForChecks($db, $runId, $actor);
                } catch (\Throwable $e) {
                    error_log('Kiosk settleAwaiting reopen run #' . $runId . ': ' . get_class($e));
                }
                continue;
            }
            if (!in_array($r['trun_status'], ['awaiting_session', 'awaiting_evaluation'], true) || !RecordsBridge::available($db)) {
                continue;
            }
            $courseId = (int) $r['trun_course_id'];
            $c = $bridge->completionForRun($contactId, $courseId, $runId, $r['trun_attested_at_utc'] === null ? null : (string) $r['trun_attested_at_utc']);
            if ($c === null) {
                try {
                    $issued = $bridge->tryIssueComponents($contactId, $courseId);
                    if ($issued !== null) {
                        $c = ['completion_id' => $issued['completion_id']];
                    }
                } catch (\Throwable $e) {
                    error_log('Kiosk settleAwaiting run #' . $runId . ': ' . get_class($e));
                }
            }
            if ($c === null) {
                continue;
            }
            $completionId = (int) $c['completion_id'];
            Db::tx($db, function () use ($db, $runId, $completionId): void {
                $run = RunRepo::load($db, $runId, true);
                if ($run === null || !in_array($run['trun_status'], ['awaiting_session', 'awaiting_evaluation'], true)) {
                    return;
                }
                Db::exec($db, "UPDATE training_runs SET trun_status = 'completed', trun_completion_id = ?, trun_open_guard = NULL, trun_ended_at_utc = ?
                    WHERE trun_id = ?", 'isi', [$completionId, KTime::now(), $runId]);
            });
        }
    }

    /**
     * A run waiting for its sign-off while a required lesson's MUST-PASS quick check was never passed
     * goes back to in_progress, so the check is taken before the person signs. Only runs from before
     * kiosk quick checks can be in that state (the kiosk used to skip the checks and credit the
     * lesson with its content); RunRepo::done() now keeps such a lesson open until its check is
     * passed, so nothing else reaches it. Idempotent; the run row is re-read under its lock; ledger
     * run.reopened {reason:'quick_check', lesson_uids}. Returns true when the run was reopened.
     */
    public static function reopenForChecks(\mysqli $db, int $runId, array $actor): bool
    {
        $peek = RunRepo::load($db, $runId);
        if ($peek === null || $peek['trun_status'] !== 'awaiting_signature' || $peek['trun_attested_at_utc'] !== null) {
            return false;
        }
        $doc = RunRepo::revision($db, $peek)['doc'];
        $any = false;
        foreach ($doc['lessons'] ?? [] as $l) {
            $any = $any || (!empty($l['required']) && RunRepo::mustPassCheck($l));
        }
        if (!$any || RunRepo::allRequiredDone($doc, RunRepo::done($db, $runId, $doc))) {
            return false;
        }
        return Db::tx($db, static function () use ($db, $runId, $doc, $actor): bool {
            $run = RunRepo::load($db, $runId, true);
            if ($run === null || $run['trun_status'] !== 'awaiting_signature' || $run['trun_attested_at_utc'] !== null
                || (int) ($run['trun_open_guard'] ?? 0) !== 1) {
                return false;
            }
            $done = RunRepo::done($db, $runId, $doc);
            $pending = array_values(array_filter(RunRepo::requiredUids($doc), static fn(string $u): bool => !isset($done[$u])));
            if ($pending === []) {
                return false;
            }
            Db::exec($db, "UPDATE training_runs SET trun_status = 'in_progress', trun_progress_pct = ?, trun_last_activity_at_utc = ? WHERE trun_id = ?",
                'isi', [RunRepo::progress($doc, $done), KTime::now(), $runId]);
            Ledger::append($db, RunRepo::event($actor, 'run.reopened', $run, 'run', $runId, null, ['reason' => 'quick_check', 'lesson_uids' => $pending]));
            return true;
        });
    }

    /**
     * Agent / trainer unlock (§3.4): 'extra' adds 1..5 attempts to the quiz or quick check the run is
     * locked on (a run that is not locked: to every quiz of the run) and clears the lock; 'restart'
     * abandons the run so the next run_start opens a fresh one on the CURRENT revision (also the
     * way out of a blocked run). $actor is the ledger actor (agent: user; trainer: contact).
     */
    public function unlock(int $runId, string $mode, int $extra, string $reason, array $actor): void
    {
        self::unlockAs($this->k->db(), $runId, $mode, $extra, $reason, $actor);
    }

    /** @return array{run_id:int, contact_id:int, course_id:int, mode:string, extra:int} */
    public static function unlockAs(\mysqli $db, int $runId, string $mode, int $extra, string $reason, array $actor): array
    {
        if (!in_array($mode, self::UNLOCK_MODES, true)) {
            throw ApiException::validation(['mode' => 'Not a valid choice.']);
        }
        $reason = trim($reason);
        $len = mb_strlen($reason, 'UTF-8');
        if ($len < 5 || $len > 255 || !mb_check_encoding($reason, 'UTF-8')) {
            throw ApiException::validation(['reason' => 'Give a reason (5 to 255 characters).']);
        }
        if ($mode === 'extra' && ($extra < 1 || $extra > 5)) {
            throw ApiException::validation(['extra' => 'Between 1 and 5.']);
        }
        return Db::tx($db, static function () use ($db, $runId, $mode, $extra, $reason, $actor): array {
            $run = RunRepo::load($db, $runId, true);
            if ($run === null) {
                throw ApiException::notFound('That course run was not found.');
            }
            if ((int) ($run['trun_open_guard'] ?? 0) !== 1) {
                throw new ApiException(409, 'run_closed', 'This course run is already closed.');
            }
            $now = KTime::now();
            if ($mode === 'extra') {
                if ($run['trun_blocked_reason'] !== null) {
                    throw new ApiException(409, 'run_blocked', 'This course needs a restart on the current version, not more tries.');
                }
                if ($run['trun_status'] !== 'in_progress') {
                    throw new ApiException(409, 'run_closed', 'This course run is not in progress.');
                }
                Db::exec($db, 'UPDATE training_runs SET trun_extra_attempts = LEAST(trun_extra_attempts + ?, 255), trun_locked_at_utc = NULL,
                    trun_locked_lesson_uid = NULL, trun_last_activity_at_utc = ? WHERE trun_id = ?', 'isi', [$extra, $now, $runId]);
            } else {
                Db::exec($db, "UPDATE training_runs SET trun_status = 'abandoned', trun_open_guard = NULL, trun_ended_at_utc = ?, trun_current_lesson_uid = NULL,
                    trun_lesson_resume_at = NULL WHERE trun_id = ?", 'si', [$now, $runId]);
            }
            // lesson_uid: the quiz or quick check that ran out of tries - the extra tries count for it only
            // (RunRepo::extraTries); null when the run was not locked on a lesson (then they count run-wide).
            Ledger::append($db, RunRepo::event($actor, 'run.unlocked', $run, 'run', $runId, null,
                ['mode' => $mode, 'extra' => $mode === 'extra' ? $extra : 0, 'reason' => $reason,
                 'lesson_uid' => $mode === 'extra' && $run['trun_locked_at_utc'] !== null && $run['trun_locked_lesson_uid'] !== null
                     ? (string) $run['trun_locked_lesson_uid'] : null]));
            return ['run_id' => $runId, 'contact_id' => (int) $run['trun_contact_id'], 'course_id' => (int) $run['trun_course_id'], 'mode' => $mode,
                'extra' => $mode === 'extra' ? $extra : 0];
        });
    }

    // =========================================================================================
    // shared helpers (AttemptService, AttestService, LearnerHome)
    // =========================================================================================

    /**
     * Gate for one lesson of a run: {done, credited, quiz?, credit_s, required_s, max_position_s, pages_seen, page_count,
     * duration_s, min_watch_pct, can_complete, reason, video_id?, resume_at?, pages_seen_list?}. resume_at (video and
     * document lessons not credited yet): the run's last point in the lesson when it is the current one (ResumePoint),
     * else null. `credited` without `done`: the lesson's content
     * is recorded and its must-pass quick check is still to pass ($credited defaults to $done).
     */
    public static function gateFor(array $run, array $doc, array $lesson, array $done, ?array $credited = null): array
    {
        $uid = (string) $lesson['uid'];
        [$type, $duration, $videoId, $pages] = self::lessonFacts($run, $doc, $lesson);
        $minPct = (int) ($lesson['min_watch_pct'] ?? 100);
        if ($type === 'quiz') {
            return ['done' => isset($done[$uid]), 'credited' => isset($done[$uid]), 'quiz' => true, 'credit_s' => 0, 'required_s' => 0, 'max_position_s' => 0,
                'pages_seen' => 0, 'page_count' => 0, 'duration_s' => $duration, 'min_watch_pct' => $minPct, 'can_complete' => false, 'reason' => null];
        }
        $variant = RunRepo::variant($lesson, RunRepo::lang($run, $doc), (string) $doc['course']['default_language']);
        $required = LessonCredit::requiredSeconds($lesson, $variant);
        if (isset($done[$uid]) || isset($credited[$uid])) {
            $g = ['credit_s' => $required, 'required_s' => $required, 'max_position_s' => $type === 'video' ? $duration : 0,
                  'pages_seen' => $pages, 'can_complete' => true, 'reason' => null];
            return self::gateShape($g, $type, $duration, $minPct, $pages, $videoId, isset($done[$uid]), true);
        }
        $st = $run['trun_current_lesson_uid'] === $uid ? LessonCredit::fromRun($run) : LessonCredit::fresh($type, KTime::now());
        if ($run['trun_current_lesson_uid'] !== $uid) {
            $st['last_active'] = false;
        }
        $g = LessonCredit::gate($st, $type, $required, $duration, $minPct, $pages);
        $out = self::gateShape($g, $type, $duration, $minPct, $pages, $videoId, false, false);
        if ($type === 'video' || $type === 'document') {
            // Where the learner last was in this lesson (navigation only): seconds (never past the furthest point) or the
            // page; null when none is recorded (an older run: the players fall back to the furthest point / first unseen page).
            $out['resume_at'] = $run['trun_current_lesson_uid'] === $uid
                ? ResumePoint::forGate($run[ResumePoint::COLUMN] ?? null, $type, (int) $g['max_position_s'], $pages) : null;
        }
        if ($type === 'document' && $run['trun_current_lesson_uid'] === $uid) {
            // "Pick up where you left off" for a PDF: the pages this lesson already credited (the run keeps a
            // bitmap, not the last page), so the player marks them viewed and opens at the first page not seen.
            $out['pages_seen_list'] = LessonCredit::pagesFromHex($st['pages_hex'] ?? null, $pages);
        }
        return $out;
    }

    /** @return array{0:string, 1:int, 2:?string, 3:int} type, duration_s, external video id (null for uploads), page count */
    public static function lessonFacts(array $run, array $doc, array $lesson): array
    {
        $type = (string) $lesson['type'];
        $variant = RunRepo::variant($lesson, RunRepo::lang($run, $doc), (string) $doc['course']['default_language']);
        $duration = $type === 'video' ? LessonCredit::videoDuration($variant) : max(0, (int) ($lesson['duration_s'] ?? 0));
        $videoId = null;
        if ($type === 'video' && in_array($variant['video']['provider'] ?? 'upload', ['youtube', 'vimeo'], true)) {
            $videoId = (string) ($variant['video']['id'] ?? '');
            $videoId = $videoId === '' ? null : $videoId;
        }
        $pages = $type === 'document' ? LessonCredit::pageCount($variant) : 0;
        return [$type, $duration, $videoId, $pages];
    }

    /**
     * INSIDE Db::tx: the hashed lesson completion. Returns null on a duplicate (the lesson was
     * completed by a concurrent request).
     *
     * @param array $f opened_at, completed_at, server_seconds, required_seconds, max_position, coverage (?array), attempt_id?, tsig_id?
     * @return array{id:int, sha:string}|null
     */
    public static function insertLcomp(\mysqli $db, array $run, array $doc, array $lesson, array $f, ?KioskCtx $k = null, ?array $ids = null): ?array
    {
        $s = static fn($v): ?string => $v === null ? null : (string) (int) $v;
        $ksess = $ids['ksess_id'] ?? ($k?->ksessId());
        $kiosk = $ids['kiosk_id'] ?? (($k !== null && $k->kioskId() > 0) ? $k->kioskId() : null);
        try {
            return Hashed::insert($db, 'training_lesson_completions', [
                'lcomp_run_id' => (string) (int) $run['trun_id'],
                'lcomp_contact_id' => (string) (int) $run['trun_contact_id'],
                'lcomp_course_id' => (string) (int) $run['trun_course_id'],
                'lcomp_revision_id' => (string) (int) $run['trun_revision_id'],
                'lcomp_lesson_uid' => (string) $lesson['uid'],
                'lcomp_lesson_type' => (string) $lesson['type'],
                'lcomp_language' => RunRepo::lang($run, $doc),
                'lcomp_opened_at_utc' => (string) $f['opened_at'],
                'lcomp_completed_at_utc' => (string) $f['completed_at'],
                'lcomp_server_seconds' => (string) max(0, (int) $f['server_seconds']),
                'lcomp_required_seconds' => (string) max(0, (int) $f['required_seconds']),
                'lcomp_max_position' => $s($f['max_position'] ?? null),
                'lcomp_coverage_json' => ($f['coverage'] ?? null) === null || $f['coverage'] === [] ? null : Canonical::doc($f['coverage']),
                'lcomp_attempt_id' => $s($f['attempt_id'] ?? null),
                'lcomp_tsig_id' => $s($f['tsig_id'] ?? null),
                'lcomp_ksess_id' => $s($ksess),
                'lcomp_kiosk_id' => $s($kiosk),
            ]);
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * INSIDE Db::tx, after a lesson completion: progress, current lesson cleared, and
     * awaiting_signature once every required lesson is done (a document's final acknowledgment
     * attests right away instead: $attestsNow). $done is RunRepo::done() (must-pass quick checks
     * applied). $creditedUid is the lesson just credited: it stops being the current lesson even
     * when a must-pass quick check keeps it out of $done. Returns the updated run row.
     */
    public static function afterLessonDone(\mysqli $db, array $run, array $doc, array $done, string $now, bool $attestsNow = false,
        ?string $creditedUid = null): array
    {
        $runId = (int) $run['trun_id'];
        $pct = RunRepo::progress($doc, $done);
        $status = (string) $run['trun_status'];
        if ($status === 'in_progress' && RunRepo::allRequiredDone($doc, $done) && !$attestsNow) {
            $status = 'awaiting_signature';
        }
        $cur = $run['trun_current_lesson_uid'] === null ? null : (string) $run['trun_current_lesson_uid'];
        $clearCurrent = $cur !== null && (isset($done[$cur]) || $cur === $creditedUid);
        Db::exec($db, 'UPDATE training_runs SET trun_progress_pct = ?, trun_status = ?, trun_last_activity_at_utc = ?'
            . ($clearCurrent ? ', trun_current_lesson_uid = NULL, trun_lesson_credit_s = 0, trun_lesson_max_position = 0, trun_lesson_pages_hex = NULL,
                  trun_lesson_rejected_ticks = 0, trun_lesson_last_active = 0, trun_lesson_resume_at = NULL' : '') . ' WHERE trun_id = ?', 'issi', [$pct, $status, $now, $runId]);
        return RunRepo::load($db, $runId) ?? $run;
    }

    /**
     * Every quiz of the run's revision: Quiz lessons (quiz, exam) and content lessons' quick checks
     * (check:true), keyed by lesson uid. `used` counts drawn attempts (an attempt counts from its
     * draw); `open` = an unfinished attempt that exam_start resumes (same draw, saved answers), so the
     * last try that was left mid-way is not shown as locked or out of tries.
     *
     * @return array<string, array{used:int, max:?int, left:?int, locked:bool, passed:bool, open:bool, check:bool, must_pass:bool}>
     */
    public static function quizzes(\mysqli $db, array $run, array $doc): array
    {
        $stats = [];
        foreach (Db::all($db, 'SELECT a.tattempt_lesson_uid, COUNT(*) AS n, MAX(COALESCE(r.tresult_passed, 0)) AS passed,
                    SUM(r.tresult_attempt_id IS NULL AND (a.tattempt_deadline_utc IS NULL OR a.tattempt_deadline_utc > ?)) AS open
                FROM training_attempts a LEFT JOIN training_attempt_results r ON r.tresult_attempt_id = a.tattempt_id
                WHERE a.tattempt_run_id = ? GROUP BY a.tattempt_lesson_uid', 'si', [KTime::now(), (int) $run['trun_id']]) as $r) {
            $stats[(string) $r['tattempt_lesson_uid']] = ['n' => (int) $r['n'], 'passed' => (int) $r['passed'] === 1, 'open' => (int) $r['open'] > 0];
        }
        $out = [];
        $extras = RunRepo::extraTries($db, $run);
        foreach ($doc['lessons'] ?? [] as $l) {
            $isCheck = RunRepo::check($l) !== null;
            if (!$isCheck && (($l['type'] ?? '') !== 'quiz' || !is_array($l['quiz'] ?? null))) {
                continue;
            }
            $uid = (string) $l['uid'];
            $used = $stats[$uid]['n'] ?? 0;
            $passed = $stats[$uid]['passed'] ?? false;
            $open = !$passed && ($stats[$uid]['open'] ?? false);
            $max = (int) ($l['quiz']['max_attempts'] ?? 0);
            $eff = $max > 0 ? $max + RunRepo::extraFor($extras, $uid) : null;
            $exhausted = $eff !== null && $used >= $eff && !$open;
            $out[$uid] = [
                'used' => $used,
                'max' => $eff,
                'left' => $eff === null ? null : max(0, $eff - $used),
                'locked' => !$passed && (($run['trun_locked_at_utc'] !== null && (string) $run['trun_locked_lesson_uid'] === $uid)
                    || ($exhausted && !empty($l['quiz']['must_pass']))),
                'passed' => $passed,
                'open' => $open,
                'check' => $isCheck,
                'must_pass' => !empty($l['quiz']['must_pass']),
            ];
        }
        return $out;
    }

    /** 409 run_locked / run_blocked for a run that cannot be worked on. */
    public static function assertRunUsable(array $run): void
    {
        if ($run['trun_blocked_reason'] !== null) {
            throw new ApiException(409, 'run_blocked', 'This course needs attention. See your trainer.', [],
                ['reason' => (string) $run['trun_blocked_reason'], 'lesson_uid' => $run['trun_blocked_lesson_uid']]);
        }
        if ($run['trun_locked_at_utc'] !== null) {
            throw new ApiException(409, 'run_locked', 'This course is locked. See your trainer.', [], ['lesson_uid' => $run['trun_locked_lesson_uid']]);
        }
    }

    public static function lessonOr404(array $doc, string $uid): array
    {
        $l = preg_match('/^[a-z][0-9a-z]{11}$/D', $uid) === 1 ? RunRepo::lesson($doc, $uid) : null;
        if ($l === null) {
            throw ApiException::notFound('That lesson is not part of this course.');
        }
        return $l;
    }

    // =========================================================================================
    // internals
    // =========================================================================================

    private function contact(): int
    {
        $cid = $this->k->contactId();
        if ($cid < 1 || ($this->k->role() ?? '') !== 'learner') {
            throw new ApiException(403, 'wrong_role', 'That is not available in this mode.');
        }
        return $cid;
    }

    /** The learner's saved language when this revision has it, else the course default (A3). */
    private function preferredLanguage(int $cid, array $doc): string
    {
        $pref = Db::one($this->k->db(), 'SELECT tpref_language FROM training_learner_prefs WHERE tpref_contact_id = ?', 'i', [$cid]);
        $lang = $pref !== null ? (string) $pref['tpref_language'] : (string) ($this->k->ksess['ksess_language'] ?? $this->k->lang);
        return in_array($lang, $doc['course']['languages'] ?? [], true) ? $lang : (string) $doc['course']['default_language'];
    }

    /** [S] L-13: every prerequisite course needs a valid record (skipped while records are unavailable). */
    private function assertPrereqs(\mysqli $db, RecordsBridge $bridge, int $cid, int $courseId): void
    {
        if (!RecordsBridge::available($db)) {
            return;
        }
        $missing = [];
        foreach (Db::all($db, 'SELECT p.prereq_requires_course_id, c.course_name FROM training_course_prereqs p
                JOIN training_courses c ON c.course_id = p.prereq_requires_course_id
                WHERE p.prereq_course_id = ? AND c.course_archived_at IS NULL ORDER BY c.course_name', 'i', [$courseId]) as $p) {
            if ($bridge->validCompletion($cid, (int) $p['prereq_requires_course_id']) === null) {
                $missing[] = (string) $p['course_name'];
            }
        }
        if ($missing !== []) {
            throw new ApiException(409, 'prereq_missing', 'Finish the courses this one builds on first.', [], ['names' => $missing]);
        }
    }

    /**
     * Records a player error (deduplicated per session/run/lesson/code for 24 h) and, when
     * $blockReason is given, blocks the in-progress run (run.blocked, once).
     */
    private function blockRun(int $runId, string $uid, ?string $blockReason, string $provider, string $videoId, string $code, bool $block): void
    {
        $db = $this->k->db();
        $cid = $this->contact();
        $actor = $this->k->eventBase();
        $dedupe = 'verr:' . substr(hash('sha256', ($this->k->ksessId() ?? 0) . "|$runId|$uid|$code"), 0, 40);
        Db::tx($db, function () use ($db, $cid, $runId, $uid, $blockReason, $provider, $videoId, $code, $block, $actor, $dedupe): void {
            $run = RunRepo::own($db, $runId, $cid, true);
            $events = [];
            if ($block && $blockReason !== null && $run['trun_status'] === 'in_progress' && $run['trun_blocked_reason'] === null) {
                Db::exec($db, 'UPDATE training_runs SET trun_blocked_reason = ?, trun_blocked_lesson_uid = ?, trun_last_activity_at_utc = ? WHERE trun_id = ?',
                    'sssi', [$blockReason, $uid, KTime::now(), $runId]);
                $events[] = RunRepo::event($actor, 'run.blocked', $run, 'run', $runId, null, ['reason' => $blockReason, 'lesson_uid' => $uid]);
            }
            if (RateLimiter::hit($db, $dedupe, 86400, 1)) {
                $events[] = RunRepo::event($actor, 'lesson.video_error', $run, 'run', $runId, null,
                    ['lesson_uid' => $uid, 'provider' => $provider, 'video_id' => $videoId === '' ? null : $videoId, 'code' => $code]);
            }
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
        });
    }

    private static function completeResponse(\mysqli $db, array $run, array $doc, array $done, array $credited, array $lesson): array
    {
        $uid = (string) $lesson['uid'];
        return [
            'progress_pct' => (int) $run['trun_progress_pct'],
            'run_status' => (string) $run['trun_status'],
            'next_uid' => RunRepo::nextUid($doc, $done, $uid),
            'done' => (object) $done,
            'credited' => (object) $credited,
            'check' => RunRepo::check($lesson) === null ? null : (self::quizzes($db, $run, $doc)[$uid] ?? null),
        ];
    }

    private static function gateShape(array $g, string $type, int $duration, int $minPct, int $pages, ?string $videoId, bool $done, bool $credited): array
    {
        $out = [
            'done' => $done,
            'credited' => $credited,
            'credit_s' => (int) $g['credit_s'],
            'required_s' => (int) $g['required_s'],
            'max_position_s' => (int) $g['max_position_s'],
            'pages_seen' => (int) $g['pages_seen'],
            'page_count' => $pages,
            'duration_s' => $duration,
            'min_watch_pct' => $minPct,
            'can_complete' => (bool) $g['can_complete'],
            'reason' => $g['reason'] ?? null,
        ];
        if ($videoId !== null) {
            $out['video_id'] = $videoId;
        }
        return $out;
    }
}
