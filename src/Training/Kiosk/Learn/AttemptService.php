<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Achievements\AwardEngine;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\Hashed;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Verify\KioskLedgerChecks;
use ITFlow\Training\Preview\PayloadGuard;
use ITFlow\Training\Quiz\FeedbackBuilder;
use ITFlow\Training\Quiz\Grader;
use ITFlow\Training\Quiz\QuizDraw;

/**
 * Graded quiz and exam attempts on the kiosk (P3 spec §3.4, plan A4, §8 "Answer key").
 *
 *  - An attempt is COUNTED FROM THE DRAW: exam_start inserts the hashed attempt row (number =
 *    COUNT+1, frozen language, pass mark and deadline) before the learner sees a question.
 *  - An unresulted attempt whose deadline has not passed is RESUMED with the same draw and the
 *    answers saved so far; it is never re-drawn.
 *  - Answers are saved as chosen (answer_save -> training_attempt_answer_log). Grading reads the
 *    LOG: the last row per question saved by deadline + GRACE_S (untimed: the last row).
 *  - The attempt row lock is shared by answer_save and submit, so a save cannot slip past a submit;
 *    the result's log digest covers talog_id <= tresult_log_max_id only.
 *  - One GRACE_S for save, submit and grading. A late submit is graded from the log (timed_out=1).
 *  - The result row's primary key (the attempt id) makes submit idempotent.
 *  - AttemptFinalizer grades abandoned timed attempts through the same path (gradeInTx).
 *  - Quick checks (quiz role 'check' on a content lesson; kind 'check') use the same engine: draw,
 *    answer log, server grading, hashes, tries and the attempts-exhausted lock. A check starts only
 *    once the lesson's content is credited (409 check_before_content), may also be taken while the
 *    run waits for its sign-off (its result never changes the record), and writes no lesson
 *    completion of its own: the content's completion is the lesson's. A MUST-PASS check's pass is
 *    what makes the lesson done (RunRepo::done) - progress, order and sign-off move on then.
 */
final class AttemptService
{
    public const GRACE_S = 5;
    public const SYSTEM_ACTOR = ['actor_type' => 'system', 'user_agent' => KioskCtx::SYSTEM_UA];
    private const ATTEMPT_COLUMNS = 'tattempt_id, tattempt_run_id, tattempt_contact_id, tattempt_course_id, tattempt_revision_id, tattempt_lesson_uid,
        tattempt_quiz_uid, tattempt_kind, tattempt_number, tattempt_language, tattempt_draw_json, tattempt_pass_mark_pct, tattempt_time_limit_s,
        tattempt_started_at_utc, tattempt_deadline_utc, tattempt_ksess_id, tattempt_kiosk_id';

    public function __construct(private readonly KioskCtx $k)
    {
    }

    // =========================================================================================
    // exam_start
    // =========================================================================================

    /** exam_start {run_id, lesson_uid} => kiosk_quiz_start payload. */
    public function start(int $runId, string $lessonUid): array
    {
        $db = $this->k->db();
        $cid = $this->contact();
        RunRepo::own($db, $runId, $cid);   // 404 before any work for someone else's run
        AttemptFinalizer::finalizeExpired($db, $runId, 50, $this->k->eventBase());
        $actor = $this->k->eventBase();
        $absoluteUntil = (string) ($this->k->ksess['ksess_absolute_until_utc'] ?? '');

        $out = Db::tx($db, function () use ($db, $cid, $runId, $lessonUid, $actor, $absoluteUntil): array {
            $run = RunRepo::own($db, $runId, $cid, true);
            $rev = RunRepo::revision($db, $run);
            $doc = $rev['doc'];
            $lesson = RunService::lessonOr404($doc, $lessonUid);
            $isCheck = RunRepo::check($lesson) !== null;
            if (($lesson['type'] !== 'quiz' && !$isCheck) || !is_array($lesson['quiz'] ?? null)) {
                throw ApiException::validation(['lesson_uid' => 'This lesson is not a quiz.']);
            }
            $quiz = $lesson['quiz'];
            $credited = RunRepo::credited($db, $runId);
            $done = RunRepo::done($db, $runId, $doc, $credited);
            if ($run['trun_blocked_reason'] !== null) {
                RunService::assertRunUsable($run);
            }
            if (self::passedOn($db, $runId, $lessonUid)) {
                throw new ApiException(409, 'already_passed', 'You already passed this quiz.');
            }
            // A quick check may also be taken while the run waits for the sign-off (a check that is not
            // must-pass, offered right after the last lesson's content): its result never changes the record.
            $checkWhileSigning = $isCheck && $run['trun_status'] === 'awaiting_signature' && (int) ($run['trun_open_guard'] ?? 0) === 1;
            if ($run['trun_status'] !== 'in_progress' && !$checkWhileSigning) {
                throw new ApiException(409, 'run_locked', 'This course run is closed. Open the course again.');
            }
            if ($run['trun_locked_at_utc'] !== null) {
                if ((string) $run['trun_locked_lesson_uid'] === $lessonUid) {
                    throw new ApiException(409, 'attempts_exhausted', 'No tries left. See your trainer.', [], ['lesson_uid' => $lessonUid]);
                }
                RunService::assertRunUsable($run);
            }
            if ($isCheck) {
                // The check follows the lesson: its content must be credited first (the order rules were met then).
                if (!isset($credited[$lessonUid])) {
                    throw new ApiException(409, 'check_before_content', 'Finish the lesson first, then take its quick check.', [], ['lesson_uid' => $lessonUid]);
                }
            } else {
                RunRepo::assertUnlocked($doc, $done, $lessonUid);
            }
            $kind = (string) ($quiz['role'] ?? 'standalone');
            if ($kind === 'exam' && ($u = RunRepo::examBlocker($doc, $done, $lessonUid)) !== null) {
                throw new ApiException(409, 'exam_locked', 'Finish every other lesson before the final exam.', [], ['lesson_uid' => $u]);
            }
            $now = KTime::now();

            // Resume the open attempt (same draw), or grade one whose time ran out.
            $open = Db::one($db, 'SELECT ' . self::ATTEMPT_COLUMNS . ' FROM training_attempts a
                WHERE a.tattempt_run_id = ? AND a.tattempt_lesson_uid = ?
                  AND NOT EXISTS (SELECT 1 FROM training_attempt_results r WHERE r.tresult_attempt_id = a.tattempt_id)
                ORDER BY a.tattempt_number DESC LIMIT 1 FOR UPDATE', 'is', [$runId, $lessonUid]);
            $events = [];
            $finalized = null;
            if ($open !== null) {
                if ($open['tattempt_deadline_utc'] === null || KTime::epoch((string) $open['tattempt_deadline_utc']) > KTime::epoch($now)) {
                    return ['resume' => $open, 'run' => $run, 'rev' => $rev, 'lesson' => $lesson];
                }
                $g = self::gradeInTx($db, $run, $open, null, 'finalizer', $actor);
                $events = $g['events'];
                $finalized = $g;
                $run = $g['run'];
                if ($g['facts'] !== null && $g['facts']['passed']) {
                    foreach ($events as $e) {
                        Ledger::append($db, $e);
                    }
                    return ['error' => new ApiException(409, 'already_passed', 'You already passed this quiz.'), 'facts' => $g['facts']];
                }
            }

            $used = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_attempts WHERE tattempt_run_id = ? AND tattempt_lesson_uid = ?',
                'is', [$runId, $lessonUid])['n'] ?? 0);
            $max = (int) ($quiz['max_attempts'] ?? 0);
            if ($max > 0 && $used >= $max + RunRepo::extraFor(RunRepo::extraTries($db, $run), $lessonUid)) {
                if ($run['trun_locked_at_utc'] === null && !empty($quiz['must_pass']) && $run['trun_status'] === 'in_progress') {
                    Db::exec($db, 'UPDATE training_runs SET trun_locked_at_utc = ?, trun_locked_lesson_uid = ? WHERE trun_id = ? AND trun_locked_at_utc IS NULL',
                        'ssi', [$now, $lessonUid, $runId]);
                    if ($finalized === null || !self::hasEvent($events, 'run.failed')) {
                        $events[] = RunRepo::event($actor, 'run.failed', $run, 'run', $runId, null, ['lesson_uid' => $lessonUid, 'attempts_used' => $used]);
                    }
                }
                foreach ($events as $e) {
                    Ledger::append($db, $e);
                }
                return ['error' => new ApiException(409, 'attempts_exhausted', 'No tries left. See your trainer.', [], ['lesson_uid' => $lessonUid]),
                    'facts' => $finalized['facts'] ?? null];
            }
            $limit = isset($quiz['time_limit_s']) && $quiz['time_limit_s'] !== null ? (int) $quiz['time_limit_s'] : null;
            if ($limit !== null && $limit > 0 && $absoluteUntil !== '') {
                $left = KTime::epoch($absoluteUntil) - KTime::epoch($now);
                if ($left < $limit + 60) {
                    foreach ($events as $e) {
                        Ledger::append($db, $e);
                    }
                    return ['error' => new ApiException(409, 'session_too_short', 'Not enough time left in this sign-in for the timed quiz. Tap Done, sign in again, then start it.'),
                        'facts' => $finalized['facts'] ?? null];
                }
            }
            $draw = QuizDraw::draw($quiz, is_array($doc['questions'] ?? null) ? $doc['questions'] : []);
            if ($draw === []) {
                throw new ApiException(409, 'quiz_empty', 'This quiz has no questions. Tell your trainer.');
            }
            $lang = QuizPresenter::language($doc, $draw, RunRepo::lang($run, $doc));
            $deadline = ($limit !== null && $limit > 0) ? KTime::plus($limit, $now) : null;
            $ins = Hashed::insert($db, 'training_attempts', [
                'tattempt_run_id' => (string) $runId,
                'tattempt_contact_id' => (string) $cid,
                'tattempt_course_id' => (string) (int) $run['trun_course_id'],
                'tattempt_revision_id' => (string) (int) $run['trun_revision_id'],
                'tattempt_lesson_uid' => $lessonUid,
                'tattempt_quiz_uid' => (string) ($quiz['uid'] ?? ''),
                'tattempt_kind' => in_array($kind, ['standalone', 'exam', 'check'], true) ? $kind : 'standalone',
                'tattempt_number' => (string) ($used + 1),
                'tattempt_language' => $lang,
                'tattempt_draw_json' => Canonical::doc($draw),
                'tattempt_pass_mark_pct' => (string) (int) ($quiz['pass_pct'] ?? 80),
                'tattempt_time_limit_s' => $deadline === null ? null : (string) $limit,
                'tattempt_started_at_utc' => $now,
                'tattempt_deadline_utc' => $deadline,
                'tattempt_ksess_id' => $actor['ksess_id'] === null ? null : (string) (int) $actor['ksess_id'],
                'tattempt_kiosk_id' => $actor['kiosk_id'] === null ? null : (string) (int) $actor['kiosk_id'],
            ]);
            Db::exec($db, 'UPDATE training_runs SET trun_last_activity_at_utc = ? WHERE trun_id = ?', 'si', [$now, $runId]);
            $events[] = RunRepo::event($actor, 'attempt.start', $run, 'attempt', $ins['id'], $ins['sha'], [
                'run_id' => $runId, 'lesson_uid' => $lessonUid, 'number' => $used + 1, 'language' => $lang,
                'question_count' => count($draw), 'deadline_utc' => $deadline,
            ]);
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
            $att = self::load($db, $ins['id']);
            return ['resume' => $att, 'run' => $run, 'rev' => $rev, 'lesson' => $lesson, 'facts' => $finalized['facts'] ?? null];
        });
        if (!empty($out['facts'])) {
            self::examAwards($db, $actor, $cid, $out['facts']);
        }
        if (isset($out['error'])) {
            throw $out['error'];
        }
        return $this->payload($out['run'], $out['rev'], $out['lesson'], $out['resume']);
    }

    // =========================================================================================
    // answer_save
    // =========================================================================================

    /** answer_save {attempt_id, question_uid, option_uids:[]} => {saved_at}. */
    public function saveAnswer(int $attemptId, string $qUid, array $optionUids): array
    {
        $db = $this->k->db();
        $cid = $this->contact();
        $ksess = $this->k->ksessId();
        return Db::tx($db, static function () use ($db, $cid, $attemptId, $qUid, $optionUids, $ksess): array {
            $att = self::load($db, $attemptId, true);   // the FIRST statement: submit holds the same lock
            if ($att === null || (int) $att['tattempt_contact_id'] !== $cid) {
                throw ApiException::notFound('That quiz attempt was not found.');
            }
            if (Db::one($db, 'SELECT tresult_attempt_id FROM training_attempt_results WHERE tresult_attempt_id = ?', 'i', [$attemptId]) !== null) {
                throw new ApiException(409, 'attempt_closed', 'This attempt is already finished.');
            }
            $now = KTime::now();
            if ($att['tattempt_deadline_utc'] !== null && KTime::epoch($now) > KTime::epoch((string) $att['tattempt_deadline_utc']) + self::GRACE_S) {
                throw new ApiException(409, 'time_up', 'Time is up for this quiz.');
            }
            $draw = self::draw($att);
            $types = self::questionTypes($db, $att);
            $sel = self::validSelection($draw, $types, $qUid, $optionUids);
            Db::insert($db, 'INSERT INTO training_attempt_answer_log (talog_attempt_id, talog_question_uid, talog_selected, talog_saved_at_utc, talog_ksess_id)
                VALUES (?, ?, ?, ?, ?)', 'isssi', [$attemptId, $qUid, implode(',', $sel), $now, $ksess]);
            return ['saved_at' => $now];
        });
    }

    // =========================================================================================
    // exam_submit
    // =========================================================================================

    /** exam_submit {attempt_id, answers:{qUid:[oUid]}} => kiosk_quiz_submit payload. Idempotent. */
    public function submit(int $attemptId, array $answers): array
    {
        $db = $this->k->db();
        $cid = $this->contact();
        $peek = self::load($db, $attemptId);
        if ($peek === null || (int) $peek['tattempt_contact_id'] !== $cid) {
            throw ApiException::notFound('That quiz attempt was not found.');
        }
        $runId = (int) $peek['tattempt_run_id'];
        $actor = $this->k->eventBase();
        $out = Db::tx($db, static function () use ($db, $cid, $runId, $attemptId, $answers, $actor): array {
            $run = RunRepo::own($db, $runId, $cid, true);
            $att = self::load($db, $attemptId, true);
            if ($att === null || (int) $att['tattempt_run_id'] !== $runId) {
                throw ApiException::notFound('That quiz attempt was not found.');
            }
            if (self::resultRow($db, $attemptId) !== null) {
                return ['replay' => true, 'run' => $run, 'att' => $att];
            }
            $g = self::gradeInTx($db, $run, $att, $answers, 'learner', $actor);
            foreach ($g['events'] as $e) {
                Ledger::append($db, $e);
            }
            return ['replay' => false, 'g' => $g, 'att' => $att];
        });
        if ($out['replay']) {
            return self::storedPayload($db, $out['run'], $out['att']);
        }
        $g = $out['g'];
        $payload = $g['payload'];
        if ($g['facts'] !== null) {
            $payload['achievements'] = self::examAwards($db, $actor, $cid, $g['facts']);
        }
        PayloadGuard::assert($payload, 'kiosk_quiz_submit');
        return $payload;
    }

    // =========================================================================================
    // shared grading path (submit + finalizer)
    // =========================================================================================

    /**
     * INSIDE Db::tx with the run row and then the attempt row locked. Grades the attempt from its
     * answer log (after logging $answers when they arrive in time), inserts the answer rows and
     * the hashed result, the quiz lesson's completion when earned, the exam pointer, the
     * awaiting_signature move and the attempts-exhausted lock. Returns the events for the caller
     * to append LAST.
     *
     * @param array|null $answers submitted answers (null: finalizer / late start)
     * @return array{payload:array, events:list<array>, run:array, facts:?array}
     */
    public static function gradeInTx(\mysqli $db, array $run, array $att, ?array $answers, string $by, array $actor): array
    {
        $attemptId = (int) $att['tattempt_id'];
        $runId = (int) $run['trun_id'];
        $rev = RunRepo::revision($db, $run);
        $doc = $rev['doc'];
        $lessonUid = (string) $att['tattempt_lesson_uid'];
        $lesson = RunRepo::lesson($doc, $lessonUid);
        if ($lesson === null || !is_array($lesson['quiz'] ?? null)) {
            throw new \RuntimeException('Kiosk attempt #' . $attemptId . ' lesson is not a quiz of its revision');
        }
        $quiz = $lesson['quiz'];
        $isCheck = RunRepo::check($lesson) !== null;
        $doneBefore = RunRepo::done($db, $runId, $doc);   // before this attempt's result exists
        $draw = self::draw($att);
        $lang = (string) $att['tattempt_language'];
        $pres = QuizPresenter::present($doc, $lesson, $draw, $lang, KioskLearnerView::mediaUrl((int) $run['trun_revision_id']));
        $key = $pres['key'];
        $now = KTime::now();
        $nowE = KTime::epoch($now);
        $deadline = $att['tattempt_deadline_utc'] === null ? null : (string) $att['tattempt_deadline_utc'];
        $graceEnd = $deadline === null ? null : KTime::epoch($deadline) + self::GRACE_S;
        $inTime = $graceEnd === null || $nowE <= $graceEnd;
        $timedOut = !$inTime || $by === 'finalizer';
        $ksessId = isset($actor['ksess_id']) && $actor['ksess_id'] !== null ? (int) $actor['ksess_id'] : null;

        $log = self::logRows($db, $attemptId, null);
        if ($answers !== null && $inTime && $by === 'learner') {
            try {
                Grader::grade($draw, $key, $answers, (int) $att['tattempt_pass_mark_pct']);   // the same validation rules as answer_save
            } catch (\DomainException $e) {
                throw new ApiException(400, 'validation', $e->getMessage(), ['answers' => $e->getMessage()]);
            }
            $last = [];
            foreach ($log as $l) {
                $last[(string) $l['talog_question_uid']] = (string) $l['talog_selected'];
            }
            foreach ($draw as $d) {
                $q = (string) $d['q'];
                if (!array_key_exists($q, $answers)) {
                    continue;   // not sent: the saved answer stands
                }
                $sel = array_values(array_map('strval', is_array($answers[$q]) ? $answers[$q] : []));
                sort($sel, SORT_STRING);
                $joined = implode(',', $sel);
                if (($last[$q] ?? null) !== $joined && !($joined === '' && !isset($last[$q]))) {
                    Db::insert($db, 'INSERT INTO training_attempt_answer_log (talog_attempt_id, talog_question_uid, talog_selected, talog_saved_at_utc, talog_ksess_id)
                        VALUES (?, ?, ?, ?, ?)', 'isssi', [$attemptId, $q, $joined, $now, $ksessId]);
                }
            }
            $log = self::logRows($db, $attemptId, null);
        }
        $maxLogId = 0;
        $chosen = [];
        foreach ($log as $l) {
            $maxLogId = max($maxLogId, (int) $l['talog_id']);
            if ($graceEnd !== null && KTime::epoch((string) $l['talog_saved_at_utc']) > $graceEnd) {
                continue;
            }
            $s = (string) $l['talog_selected'];
            $chosen[(string) $l['talog_question_uid']] = $s === '' ? [] : explode(',', $s);
        }
        try {
            $graded = Grader::grade($draw, $key, $chosen, (int) $att['tattempt_pass_mark_pct']);
        } catch (\DomainException) {
            // A log row that no longer validates (cannot happen: saves are validated) grades as unanswered.
            $graded = Grader::grade($draw, $key, [], (int) $att['tattempt_pass_mark_pct']);
        }

        // Answer rows, in draw order.
        $byUid = [];
        foreach ($graded['questions'] as $gq) {
            $byUid[$gq['uid']] = $gq;
        }
        foreach ($draw as $i => $d) {
            $q = (string) $d['q'];
            $gq = $byUid[$q];
            $sel = $gq['selected'];
            sort($sel, SORT_STRING);
            $type = (string) $key[$q]['type'];
            Db::exec($db, 'INSERT INTO training_attempt_answers (tanswer_attempt_id, tanswer_question_uid, tanswer_position, tanswer_type, tanswer_presented,
                    tanswer_selected, tanswer_is_correct, tanswer_points_awarded, tanswer_points_possible, tanswer_critical)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', 'isisssiiii', [
                $attemptId, $q, $i + 1, in_array($type, ['single', 'multi', 'truefalse'], true) ? $type : 'single',
                implode(',', array_map('strval', $d['o'])), implode(',', $sel), $gq['correct'] ? 1 : 0,
                min(255, (int) $gq['earned']), min(255, (int) $gq['points']), $gq['critical'] ? 1 : 0,
            ]);
        }
        $answersSha = KioskLedgerChecks::digest(self::textRows($db, 'SELECT ' . implode(', ', KioskLedgerChecks::ANSWER_COLUMNS)
            . ' FROM training_attempt_answers WHERE tanswer_attempt_id = ' . $attemptId . ' ORDER BY tanswer_position, tanswer_question_uid'),
            KioskLedgerChecks::ANSWER_COLUMNS);
        $logSha = KioskLedgerChecks::digest(self::textRows($db, 'SELECT ' . implode(', ', KioskLedgerChecks::LOG_COLUMNS)
            . ' FROM training_attempt_answer_log WHERE talog_attempt_id = ' . $attemptId . ' AND talog_id <= ' . $maxLogId . ' ORDER BY talog_id'),
            KioskLedgerChecks::LOG_COLUMNS);

        $end = $graceEnd === null ? $nowE : min($nowE, $graceEnd);
        $duration = max(0, (int) floor($end - (float) KTime::epoch((string) $att['tattempt_started_at_utc'])));
        $rapid = $duration < 3 * count($draw);
        $passed = (bool) $graded['passed'];
        $res = Hashed::insert($db, 'training_attempt_results', [
            'tresult_attempt_id' => (string) $attemptId,
            'tresult_submitted_at_utc' => $now,
            'tresult_points_earned' => (string) (int) $graded['points_earned'],
            'tresult_points_possible' => (string) (int) $graded['points_possible'],
            'tresult_score_pct' => (string) $graded['score_pct'],
            'tresult_pass_mark_pct' => (string) (int) $att['tattempt_pass_mark_pct'],
            'tresult_critical_missed' => (string) min(255, (int) $graded['critical_missed']),
            'tresult_passed' => $passed ? '1' : '0',
            'tresult_timed_out' => $timedOut ? '1' : '0',
            'tresult_duration_seconds' => (string) $duration,
            'tresult_rapid_flag' => $rapid ? '1' : '0',
            'tresult_finalized_by' => $by === 'finalizer' ? 'finalizer' : 'learner',
            'tresult_answers_sha256' => $answersSha,
            'tresult_log_sha256' => $logSha,
            'tresult_log_max_id' => (string) $maxLogId,
        ]);
        $events = [RunRepo::event($actor, 'attempt.submit', $run, 'attempt_result', $attemptId, $res['sha'], [
            'score_pct' => (string) $graded['score_pct'], 'passed' => $passed, 'critical_missed' => (int) $graded['critical_missed'],
            'timed_out' => $timedOut, 'finalized_by' => $by === 'finalizer' ? 'finalizer' : 'learner', 'rapid' => $rapid,
        ])];

        $mustPass = !empty($quiz['must_pass']);
        $kind = (string) $att['tattempt_kind'];
        $open = $run['trun_status'] === 'in_progress' && (int) ($run['trun_open_guard'] ?? 0) === 1;
        if ($isCheck) {
            // The lesson's completion row was written when its content was credited. Passing a MUST-PASS
            // check is what makes the lesson done: progress, the order and the sign-off move on here. A
            // check that is not must-pass changes nothing on the run (its result is the record of it).
            if ($open && $passed && $mustPass && !isset($doneBefore[$lessonUid])) {
                $done = RunRepo::done($db, $runId, $doc);   // now sees this attempt's result
                if (isset($done[$lessonUid])) {
                    $run = RunService::afterLessonDone($db, $run, $doc, $done, $now);
                }
            }
        } elseif ($open && !isset($doneBefore[$lessonUid]) && ($passed || !$mustPass)) {
            $done = $doneBefore;
            $ins = RunService::insertLcomp($db, $run, $doc, $lesson, [
                'opened_at' => (string) $att['tattempt_started_at_utc'],
                'completed_at' => $now,
                'server_seconds' => $duration,
                'required_seconds' => 0,
                'max_position' => null,
                'coverage' => ['attempt_id' => $attemptId, 'score_pct' => (string) $graded['score_pct']],
                'attempt_id' => $attemptId,
            ], null, ['ksess_id' => $att['tattempt_ksess_id'], 'kiosk_id' => $att['tattempt_kiosk_id']]);
            if ($ins !== null) {
                $done[$lessonUid] = true;
                $events[] = RunRepo::event($actor, 'run.lesson_complete', $run, 'lesson_completion', $ins['id'], $ins['sha'],
                    ['run_id' => $runId, 'lesson_uid' => $lessonUid, 'type' => 'quiz', 'server_seconds' => $duration]);
                $run = RunService::afterLessonDone($db, $run, $doc, $done, $now);
            }
        }
        if ($open && $kind === 'exam' && $passed) {
            Db::exec($db, 'UPDATE training_runs SET trun_passed_attempt_id = ? WHERE trun_id = ?', 'ii', [$attemptId, $runId]);
            $run['trun_passed_attempt_id'] = $attemptId;
        }
        $used = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_attempts WHERE tattempt_run_id = ? AND tattempt_lesson_uid = ?', 'is', [$runId, $lessonUid])['n'] ?? 0);
        $max = (int) ($quiz['max_attempts'] ?? 0);
        $eff = $max > 0 ? $max + RunRepo::extraFor(RunRepo::extraTries($db, $run), $lessonUid) : 0;   // a trainer's extra tries for THIS quiz
        $exhausted = $eff > 0 && $used >= $eff;
        $locked = false;
        if ($open && $mustPass && !$passed && $exhausted) {
            if ($run['trun_locked_at_utc'] === null) {
                Db::exec($db, 'UPDATE training_runs SET trun_locked_at_utc = ?, trun_locked_lesson_uid = ? WHERE trun_id = ?', 'ssi', [$now, $lessonUid, $runId]);
                $run['trun_locked_at_utc'] = $now;
                $run['trun_locked_lesson_uid'] = $lessonUid;
                $events[] = RunRepo::event($actor, 'run.failed', $run, 'run', $runId, null, ['lesson_uid' => $lessonUid, 'attempts_used' => $used]);
            }
            $locked = true;
        }
        if ($open) {
            Db::exec($db, 'UPDATE training_runs SET trun_last_activity_at_utc = ? WHERE trun_id = ?', 'si', [$now, $runId]);
        }
        $run = RunRepo::load($db, $runId) ?? $run;

        $payload = self::resultPayload($graded, $key, $quiz, $lang, (int) $att['tattempt_number'], $eff, $used, $locked, $run, $passed, $mustPass);
        $facts = $kind === 'exam' ? [
            'attempt_id' => $attemptId, 'course_id' => (int) $run['trun_course_id'], 'kind' => 'exam', 'passed' => $passed,
            'score_pct' => (string) $graded['score_pct'], 'attempt_number' => (int) $att['tattempt_number'],
        ] : null;
        return ['payload' => $payload, 'events' => $events, 'run' => $run, 'facts' => $facts];
    }

    // =========================================================================================
    // helpers
    // =========================================================================================

    public static function load(\mysqli $db, int $attemptId, bool $forUpdate = false): ?array
    {
        if ($attemptId < 1) {
            return null;
        }
        return Db::one($db, 'SELECT ' . self::ATTEMPT_COLUMNS . ' FROM training_attempts WHERE tattempt_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$attemptId]);
    }

    /** K6's exam award rules, after COMMIT (§3.4 submit step 13); never throws. @return list<array> AwardPublic rows */
    public static function examAwards(\mysqli $db, array $actor, int $cid, array $facts): array
    {
        if (Db::depth() !== 0) {
            return [];
        }
        try {
            return array_values(AwardEngine::onExamSubmitted($db, $actor, $cid, $facts));
        } catch (\Throwable $e) {
            error_log('Kiosk AttemptService awards: ' . get_class($e));
            return [];
        }
    }

    private function payload(array $run, array $rev, array $lesson, array $att): array
    {
        $db = $this->k->db();
        $doc = $rev['doc'];
        $quiz = $lesson['quiz'];
        $lang = (string) $att['tattempt_language'];
        $default = (string) $doc['course']['default_language'];
        $draw = self::draw($att);
        $pres = QuizPresenter::present($doc, $lesson, $draw, $lang, KioskLearnerView::mediaUrl((int) $rev['id']));
        $saved = [];
        foreach (self::logRows($db, (int) $att['tattempt_id'], null) as $l) {
            $s = (string) $l['talog_selected'];
            $saved[(string) $l['talog_question_uid']] = $s === '' ? [] : explode(',', $s);
        }
        $remaining = null;
        $limit = $att['tattempt_time_limit_s'] === null ? null : (int) $att['tattempt_time_limit_s'];
        if ($att['tattempt_deadline_utc'] !== null) {
            $remaining = max(0, (int) ceil((float) KTime::secondsUntil((string) $att['tattempt_deadline_utc'])));
        }
        $v = RunRepo::variant($lesson, $lang, $default);
        $max = (int) ($quiz['max_attempts'] ?? 0);
        $payload = [
            'graded' => true,
            'attempt_token' => 'a' . (int) $att['tattempt_id'],
            'attempt_id' => (int) $att['tattempt_id'],
            'attempt_number' => (int) $att['tattempt_number'],
            'attempts_max' => $max > 0 ? $max + RunRepo::extraFor(RunRepo::extraTries($db, $run), (string) $att['tattempt_lesson_uid']) : 0,
            'quiz' => [
                'title' => (string) ($v['title'] ?? ''),
                'intro' => ($quiz['intro'] ?? null) === null ? null : ($quiz['intro'][$lang] ?? $quiz['intro'][$default] ?? null),
                'time_limit_s' => $limit,
                'deadline_remaining_s' => $remaining,
                'show_review' => (bool) ($quiz['show_review'] ?? false),
                'question_count' => count($draw),
            ],
            'questions' => $pres['presented'],
            'saved' => $saved === [] ? new \stdClass() : $saved,
        ];
        PayloadGuard::assert($payload, 'kiosk_quiz_start');
        return $payload;
    }

    /** The stored result of an attempt as a submit payload (a repeated submit). */
    private static function storedPayload(\mysqli $db, array $run, array $att): array
    {
        $attemptId = (int) $att['tattempt_id'];
        $rev = RunRepo::revision($db, $run);
        $doc = $rev['doc'];
        $lesson = RunRepo::lesson($doc, (string) $att['tattempt_lesson_uid']) ?? [];
        $quiz = $lesson['quiz'] ?? [];
        $lang = (string) $att['tattempt_language'];
        $pres = QuizPresenter::present($doc, $lesson, self::draw($att), $lang, KioskLearnerView::mediaUrl((int) $run['trun_revision_id']));
        $r = self::resultRow($db, $attemptId);
        $questions = [];
        foreach (Db::all($db, 'SELECT tanswer_question_uid, tanswer_selected, tanswer_is_correct, tanswer_points_awarded, tanswer_points_possible, tanswer_critical
                FROM training_attempt_answers WHERE tanswer_attempt_id = ? ORDER BY tanswer_position', 'i', [$attemptId]) as $a) {
            $sel = (string) $a['tanswer_selected'];
            $questions[] = ['uid' => (string) $a['tanswer_question_uid'], 'correct' => (int) $a['tanswer_is_correct'] === 1,
                'points' => (int) $a['tanswer_points_possible'], 'earned' => (int) $a['tanswer_points_awarded'],
                'critical' => (int) $a['tanswer_critical'] === 1, 'selected' => $sel === '' ? [] : explode(',', $sel)];
        }
        $graded = ['points_earned' => (int) $r['tresult_points_earned'], 'points_possible' => (int) $r['tresult_points_possible'],
            'score_pct' => (string) $r['tresult_score_pct'], 'passed' => (int) $r['tresult_passed'] === 1, 'pass_pct' => (int) $r['tresult_pass_mark_pct'],
            'critical_missed' => (int) $r['tresult_critical_missed'], 'questions' => $questions];
        $runId = (int) $run['trun_id'];
        $used = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_attempts WHERE tattempt_run_id = ? AND tattempt_lesson_uid = ?', 'is',
            [$runId, (string) $att['tattempt_lesson_uid']])['n'] ?? 0);
        $max = (int) ($quiz['max_attempts'] ?? 0);
        $eff = $max > 0 ? $max + RunRepo::extraFor(RunRepo::extraTries($db, $run), (string) $att['tattempt_lesson_uid']) : 0;
        $locked = $run['trun_locked_at_utc'] !== null && (string) $run['trun_locked_lesson_uid'] === (string) $att['tattempt_lesson_uid'];
        $payload = self::resultPayload($graded, $pres['key'], $quiz, $lang, (int) $att['tattempt_number'], $eff, $used, $locked, $run,
            $graded['passed'], !empty($quiz['must_pass']));
        PayloadGuard::assert($payload, 'kiosk_quiz_submit');
        return $payload;
    }

    private static function resultPayload(array $graded, array $key, array $quiz, string $lang, int $number, int $eff, int $used, bool $locked,
        array $run, bool $passed, bool $mustPass): array
    {
        $mode = (string) ($quiz['feedback_mode'] ?? 'score_only');
        $status = (string) $run['trun_status'];
        $left = $eff > 0 ? max(0, $eff - $used) : null;
        $next = $locked ? 'locked'
            : ($status === 'awaiting_signature' ? 'sign'
            : ((!$passed && $mustPass && ($left === null || $left > 0)) ? 'retry' : 'continue'));
        return [
            'score_pct' => (string) $graded['score_pct'],
            'points_earned' => (int) $graded['points_earned'],
            'points_possible' => (int) $graded['points_possible'],
            'passed' => $passed,
            'pass_pct' => (int) $graded['pass_pct'],
            'critical_missed' => (int) $graded['critical_missed'],
            'topics_missed' => FeedbackBuilder::topicsMissed($mode, $graded, $key, $lang),
            'feedback' => FeedbackBuilder::build($mode, $passed, $graded, $key, $lang),
            'attempt_number' => $number,
            'attempts_max' => $eff,
            'attempts_left' => $left,
            'locked' => $locked,
            'run_status' => $status,
            'next' => $next,
            'achievements' => [],
        ];
    }

    private static function resultRow(\mysqli $db, int $attemptId): ?array
    {
        return Db::one($db, 'SELECT tresult_attempt_id, tresult_points_earned, tresult_points_possible, tresult_score_pct, tresult_pass_mark_pct,
                tresult_critical_missed, tresult_passed, tresult_timed_out FROM training_attempt_results WHERE tresult_attempt_id = ?', 'i', [$attemptId]);
    }

    private static function passedOn(\mysqli $db, int $runId, string $lessonUid): bool
    {
        return Db::one($db, 'SELECT 1 AS ok FROM training_attempts a JOIN training_attempt_results r ON r.tresult_attempt_id = a.tattempt_id
            WHERE a.tattempt_run_id = ? AND a.tattempt_lesson_uid = ? AND r.tresult_passed = 1 LIMIT 1', 'is', [$runId, $lessonUid]) !== null;
    }

    /** @return list<array{q:string, o:list<string>}> */
    private static function draw(array $att): array
    {
        $d = json_decode((string) $att['tattempt_draw_json'], true);
        if (!is_array($d) || !array_is_list($d)) {
            throw new \RuntimeException('Kiosk attempt #' . (int) $att['tattempt_id'] . ' has an unreadable draw');
        }
        return $d;
    }

    /** @return array<string, string> question uid => type, from the attempt's revision */
    private static function questionTypes(\mysqli $db, array $att): array
    {
        $doc = \ITFlow\Training\Kiosk\Core\RevisionCache::get($db, (int) $att['tattempt_revision_id'])['doc'];
        $out = [];
        foreach (is_array($doc['questions'] ?? null) ? $doc['questions'] : [] as $uid => $q) {
            $out[(string) $uid] = (string) ($q['type'] ?? 'single');
        }
        return $out;
    }

    /** @return list<string> the selection sorted; 422 validation when it breaks the save rules */
    private static function validSelection(array $draw, array $types, string $qUid, array $optionUids): array
    {
        $presented = null;
        foreach ($draw as $d) {
            if ((string) $d['q'] === $qUid) {
                $presented = array_fill_keys(array_map('strval', $d['o']), true);
                break;
            }
        }
        if ($presented === null) {
            throw ApiException::validation(['question_uid' => 'That question is not part of this attempt.']);
        }
        if (!array_is_list($optionUids)) {
            throw ApiException::validation(['option_uids' => 'Send a list of choices.']);
        }
        $seen = [];
        foreach ($optionUids as $o) {
            if (!is_string($o) || !isset($presented[$o]) || isset($seen[$o])) {
                throw ApiException::validation(['option_uids' => 'A choice was not one of the answers shown.']);
            }
            $seen[$o] = true;
        }
        if (in_array($types[$qUid] ?? 'single', ['single', 'truefalse'], true) && count($seen) > 1) {
            throw ApiException::validation(['option_uids' => 'Choose one answer.']);
        }
        $sel = array_keys($seen);
        sort($sel, SORT_STRING);
        return array_map('strval', $sel);
    }

    /** @return list<array{talog_id:string, talog_question_uid:string, talog_selected:string, talog_saved_at_utc:string}> in id order */
    private static function logRows(\mysqli $db, int $attemptId, ?int $maxId): array
    {
        return self::textRows($db, 'SELECT talog_id, talog_question_uid, talog_selected, talog_saved_at_utc FROM training_attempt_answer_log
            WHERE talog_attempt_id = ' . $attemptId . ($maxId === null ? '' : ' AND talog_id <= ' . $maxId) . ' ORDER BY talog_id');
    }

    /** Plain text-protocol read (the strings the verifier re-derives digests from). Integer-only interpolation. */
    private static function textRows(\mysqli $db, string $sql): array
    {
        $res = $db->query($sql);
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
        return $rows;
    }

    private static function hasEvent(array $events, string $type): bool
    {
        foreach ($events as $e) {
            if (($e['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    private function contact(): int
    {
        $cid = $this->k->contactId();
        if ($cid < 1 || ($this->k->role() ?? '') !== 'learner') {
            throw new ApiException(403, 'wrong_role', 'That is not available in this mode.');
        }
        return $cid;
    }
}
