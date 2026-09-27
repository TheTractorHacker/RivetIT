<?php

namespace ITFlow\Training\Assign;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsMutex;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Kiosk\Learn\RunRepo;
use ITFlow\Training\Kiosk\Learn\RunReset;
use ITFlow\Training\People\Directory;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\CertSecret;
use ITFlow\Training\Records\CompletionService;

/**
 * Agent actions that undo an assignment's state (owner asks 2026-09-26; no schema change):
 *
 *   RESET PROGRESS (open assignment, level 2): the pair's open kiosk run is abandoned (Kiosk\Learn\RunReset:
 *     run.reset, actor = the agent + reason). Lesson completions, attempts, answer logs and signatures stay as that
 *     run's history and no longer count; the next kiosk sign-in starts at lesson 1 with a fresh try count and no
 *     lock. Optionally moves the due date (same rules as Extend; assignment.due_changed). The assignment itself
 *     stays open (assignment.progress_reset). The client names the run it saw (preview) so a second submit or a
 *     stale page is a friendly 409, never a second event.
 *
 *   RESET, TAKE AGAIN (completed assignment, level 3 - voiding revokes the certificate): only while the row's
 *     record is still the pair's latest non-voided one and voiding it would assign the course again. Reuses
 *     CompletionService::void (completion.voided, audit, listeners, one-contact reconcile that opens
 *     'reissue:c<id>'), under the named reconcile lock so that reconcile is this request's; then the chosen due
 *     date goes on the new row (assignment.due_changed when it differs from reconcile's default - the created
 *     event keeps the default, the move is logged), any leftover open kiosk run is abandoned, and the old row
 *     gets assignment.retake.
 *
 *   UN-WAIVE (waived assignment whose waiver is still active, level 2): under the records mutex, like a reconcile
 *     chunk: the row is locked and re-checked; PairRules::want for the pair with this waiver ignored decides -
 *       same anchor  -> THIS row reopens (open, guard 1, closed_* and waived_until cleared, due = the chosen date,
 *                       original_due_on kept, reopened_count + 1);
 *       other anchor -> this row is ended (cancelled, close_reason 'unwaived', no longer a waiver for RecordFacts
 *                       and never reopened by reconcile), and the wanted anchor opens the way reconcile would (a
 *                       reopenable cancelled row of that anchor, else a new row) with the chosen due date;
 *       nothing      -> this row is ended and the answer says why ("nothing to take right now").
 *     Ledger assignment.unwaived {course_id, prior_until, prior_reason, reason, due_on, outcome, open_assignment_id}.
 *
 * Reconcile afterwards finds an open row with the anchor it wants (unchanged) or nothing to do, so neither the
 * nightly cron nor Recalculate now flips any of this back or opens a second row. Authorization happens at the
 * edge (AssignResetActions): route level + People scope (404). Services never authorize.
 */
final class AssignmentReset
{
    public const REASON_MIN = 5;
    public const REASON_MAX = 500;
    /** Un-waive default: the later of the row's due date and today + this many days. */
    public const UNWAIVE_DEFAULT_DAYS = 14;
    public const VOID_PREFIX = 'Reset to take again: ';

    private ?bool $runsReady = null;

    public function __construct(private readonly Ctx $c)
    {
    }

    // =========================================================================================
    // Preview (the dialogs)
    // =========================================================================================

    /**
     * What Reset / Un-waive would do to this assignment right now: {assignment, today, kind, progress?|retake?|unwaive?, why?}.
     *   kind 'progress' (open):     progress {run: RunReset::describe()|null, nothing:?string}
     *   kind 'retake' (completed):  retake {allowed, why, completion, default_due_on, open_run, level_ok}
     *   kind 'unwaive' (waived):    unwaive {allowed, why, outcome:'reopen'|'replace'|'end'|'open_exists', end_why, prior_until,
     *                               prior_reason, default_due_on, due_on, original_due_on}
     *   kind 'none' (cancelled):    why
     */
    public function preview(int $id): array
    {
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $s = RecordsSettings::fromDb($db);
        $a = $this->row($id);
        $out = ['assignment' => (new AssignmentService($this->c))->shapeMany([$id])[0], 'today' => $today, 'kind' => 'none',
                'reason_min' => self::REASON_MIN, 'reason_max' => self::REASON_MAX];
        switch ($a['status']) {
            case 'open':
                $run = $this->runsReady() ? RunRepo::open($db, $a['contact_id'], $a['course_id']) : null;
                $out['kind'] = 'progress';
                $out['progress'] = ['run' => $run === null ? null : RunReset::describe($db, $run),
                                    'nothing' => $run === null ? "They haven't started this course." : null];
                break;
            case 'completed':
                $out['kind'] = 'retake';
                $out['retake'] = $this->retakePlan($a, $today, $s) + ['level_ok' => $this->c->isAdmin || $this->c->level >= 3];
                break;
            case 'waived':
                $out['kind'] = 'unwaive';
                $out['unwaive'] = $this->unwaivePreview($a, $today, $s);
                break;
            default:
                $out['why'] = 'A cancelled assignment has nothing to reset.';
        }
        return $out;
    }

    // =========================================================================================
    // Reset progress (open)
    // =========================================================================================

    /**
     * @param int $runId the open run the agent saw in the preview (a different or missing one is a 409)
     * @return array{assignment:array, run_id:int, due_changed:bool}
     */
    public function resetProgress(int $id, int $runId, string $reason, ?string $dueOn): array
    {
        $reason = self::reason($reason);
        $today = Clock::todayLocal();
        if ($dueOn !== null) {
            self::assertDue($dueOn, $today);
        }
        $db = $this->c->db;
        $pair = $this->row($id);
        if (!$this->runsReady()) {
            throw new ApiException(409, 'already_done', "Nothing to reset: they haven't started this course.");
        }
        $actor = $this->actor();
        $r = Db::tx($db, function () use ($db, $id, $pair, $runId, $reason, $dueOn, $actor): array {
            // Kiosk lock order: the run row first, then the assignment row, the ledger head last.
            $run = RunRepo::open($db, $pair['contact_id'], $pair['course_id'], true);
            $a = $this->lockRow($id);
            if ($a['status'] !== 'open') {
                throw new ApiException(409, 'conflict', 'This assignment is no longer open. Reload to see where it stands.');
            }
            if ($run === null) {
                throw new ApiException(409, 'already_done', 'Nothing to reset: there is no course progress to clear (it may just have been reset).');
            }
            if ((int) $run['trun_id'] !== $runId) {
                throw new ApiException(409, 'already_done', 'Their progress on this course changed since you opened this. Reload and try again.');
            }
            $facts = RunReset::describe($db, $run);
            $events = [RunReset::abandonInTx($db, $run, $actor, $reason, $id, $facts)];
            $events[] = AssignmentStore::event('assignment.progress_reset', $a, [
                'course_id' => $a['course_id'],
                'run_id' => $runId,
                'reason' => $reason,
                'run_status' => (string) $run['trun_status'],
                'progress_pct' => (int) $run['trun_progress_pct'],
                'lessons_done' => (int) $facts['lessons_done'],
                'failed_tries' => (int) $facts['tries']['failed'],
                'locked' => $facts['locked'] !== null,
                'blocked' => $facts['blocked']['reason'] ?? null,
                'signed' => $facts['signed_at'] !== null,
            ], $this->c->userId, ['user_agent' => $this->c->userAgent]);
            $moved = false;
            if ($dueOn !== null && $dueOn !== $a['due_on']) {
                Db::exec($db, 'UPDATE training_assignments SET tassign_due_on = ? WHERE tassign_id = ?', 'si', [$dueOn, $id]);
                $events[] = AssignmentStore::event('assignment.due_changed', $a,
                    ['course_id' => $a['course_id'], 'from' => $a['due_on'], 'to' => $dueOn, 'reason' => $reason, 'trigger' => 'reset'],
                    $this->c->userId, ['user_agent' => $this->c->userAgent]);
                $moved = true;
            }
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
            return ['facts' => $facts, 'moved' => $moved, 'contact_id' => $a['contact_id'], 'course_id' => $a['course_id']];
        });
        return ['assignment' => $this->shape($id), 'run_id' => $runId, 'due_changed' => $r['moved'], 'cleared' => $r['facts'],
                'contact_id' => $r['contact_id'], 'course_id' => $r['course_id']];
    }

    // =========================================================================================
    // Reset, take again (completed)
    // =========================================================================================

    /**
     * Voids the row's record through CompletionService::void and puts the chosen due date on the new 'reissue:c<id>'
     * assignment. @return array{assignment:?array, previous:array, cert_number:?string, void_id:int, pending:bool}
     */
    public function retake(int $id, string $reason, string $dueOn): array
    {
        $reason = self::reason($reason);
        $today = Clock::todayLocal();
        self::assertDue($dueOn, $today);
        $db = $this->c->db;
        if (Db::depth() !== 0) {
            throw new \LogicException('AssignmentReset::retake opens its own transactions');
        }
        // The reconcile that CompletionService::void runs afterwards is then this request's (GET_LOCK is re-entrant for
        // the same connection), so the new assignment exists before the due date is set; a running recalculation is a 409.
        if (!Db::lock($db, 'trrec', 10)) {
            throw new ApiException(409, 'busy', 'Assignments are being recalculated right now. Try again in a minute.');
        }
        try {
            $s = RecordsSettings::fromDb($db);
            $a = $this->row($id);
            if ($a['status'] !== 'completed') {
                throw new ApiException(409, 'conflict', 'This assignment is not completed any more. Reload to see where it stands.');
            }
            $plan = $this->retakePlan($a, $today, $s);
            if (!$plan['allowed']) {
                throw new ApiException(409, $plan['done'] ? 'already_done' : 'not_allowed', (string) $plan['why']);
            }
            $completionId = (int) $a['completion_id'];
            try {
                $void = $this->completions()->void($completionId, self::VOID_PREFIX . $reason);
            } catch (ApiException $e) {
                if ($e->errCode === 'already_voided') {
                    throw new ApiException(409, 'already_done', 'This record was already voided. Reload to see the new assignment.');
                }
                throw $e;
            }
            $anchor = 'reissue:c' . $completionId;
            $open = $this->openFor($a['contact_id'], $a['course_id']);
            if ($open === null || $open['anchor'] !== $anchor) {
                AssignmentService::safeReconcile($this->c, [$a['contact_id']], 'void', 1);
                $open = $this->openFor($a['contact_id'], $a['course_id']);
            }
            $newId = ($open !== null && $open['anchor'] === $anchor) ? (int) $open['id'] : null;
            $actor = $this->actor();
            $work = function () use ($db, $a, $newId, $dueOn, $reason, $completionId, $void, $actor): void {
                $events = [];
                $run = $this->runsReady() ? RunRepo::open($db, $a['contact_id'], $a['course_id'], true) : null;   // run row first
                $new = $newId !== null ? $this->lockRow($newId) : null;
                if ($run !== null) {
                    $events[] = RunReset::abandonInTx($db, $run, $actor, self::VOID_PREFIX . $reason, $newId, RunReset::describe($db, $run));
                }
                if ($new !== null && $new['status'] === 'open' && $new['due_on'] !== $dueOn) {
                    Db::exec($db, 'UPDATE training_assignments SET tassign_due_on = ? WHERE tassign_id = ?', 'si', [$dueOn, $newId]);
                    $events[] = AssignmentStore::event('assignment.due_changed', $new,
                        ['course_id' => $new['course_id'], 'from' => $new['due_on'], 'to' => $dueOn, 'reason' => $reason, 'trigger' => 'retake'],
                        $this->c->userId, ['user_agent' => $this->c->userAgent]);
                }
                $events[] = AssignmentStore::event('assignment.retake', $a, [
                    'course_id' => $a['course_id'],
                    'completion_id' => $completionId,
                    'cert_number' => $void['cert_number'],
                    'void_id' => (int) $void['void_id'],
                    'reason' => $reason,
                    'new_assignment_id' => $newId,
                    'due_on' => $new !== null ? $dueOn : null,
                ], $this->c->userId, ['user_agent' => $this->c->userAgent]);
                foreach ($events as $e) {
                    Ledger::append($db, $e);
                }
            };
            try {
                Db::tx($db, $work);
            } catch (\mysqli_sql_exception $e) {
                if (!in_array((int) $e->getCode(), [1205, 1213], true)) {
                    throw $e;
                }
                Db::tx($db, $work);   // once more after a deadlock / lock wait (the void already stands)
            }
        } finally {
            Db::unlock($db, 'trrec');
        }
        return ['assignment' => $newId !== null ? $this->shape($newId) : null, 'previous' => $this->shape($id),
                'cert_number' => $void['cert_number'], 'void_id' => (int) $void['void_id'], 'pending' => $newId === null,
                'contact_id' => $a['contact_id'], 'course_id' => $a['course_id']];
    }

    // =========================================================================================
    // Un-waive (waived, waiver still active)
    // =========================================================================================

    /**
     * @return array{outcome:string, why:?string, assignment:array, ended:array, prior_until:?string}
     *   outcome 'reopened' (this row is open again) | 'replaced' (a new/reopened row with the anchor the rules want now) |
     *   'ended' (nothing to take right now: why) | 'open_exists' (another open row already covers the pair)
     */
    public function unwaive(int $id, string $reason, ?string $dueOn): array
    {
        $reason = self::reason($reason);
        $today = Clock::todayLocal();
        if ($dueOn !== null) {
            self::assertDue($dueOn, $today);
        }
        $db = $this->c->db;
        $s = RecordsSettings::fromDb($db);
        $pair = $this->row($id);
        $ctx = $this->pairContext($pair['contact_id'], $pair['course_id'], $today);   // people / rules / course: read before the tx, like reconcile
        $now = Clock::nowUtc();
        $r = Db::tx($db, function () use ($db, $id, $reason, $dueOn, $today, $s, $ctx, $now): array {
            RecordsMutex::acquire($db);   // the lock every reconcile chunk and record writer takes first
            $a = $this->lockRow($id);
            if ($a['status'] !== 'waived') {
                throw new ApiException(409, 'already_done', 'This assignment is no longer waived. Reload to see where it stands.');
            }
            if ($a['waived_until'] !== null && $a['waived_until'] < $today) {
                throw new ApiException(409, 'waiver_expired', 'This waiver already ended on ' . $a['waived_until'] . '.');
            }
            $open = Db::one($db, 'SELECT ' . AssignmentStore::COLUMNS . ' FROM training_assignments
                WHERE tassign_contact_id = ? AND tassign_course_id = ? AND tassign_open_guard = 1 FOR UPDATE', 'ii', [$a['contact_id'], $a['course_id']]);
            $plan = $this->planUnwaive($a, $ctx, true, $today, $s);
            $due = $dueOn ?? self::unwaiveDefault($a, $today);
            $userId = $this->c->userId;
            $meta = ['user_agent' => $this->c->userAgent];
            $events = [];
            $openId = null;
            $outcome = $open !== null ? 'open_exists' : $plan['outcome'];
            if ($outcome === 'reopen') {
                $d = $ctx['d'];
                Db::exec($db, "UPDATE training_assignments SET tassign_status = 'open', tassign_open_guard = 1, tassign_waived_until = NULL,
                        tassign_closed_at_utc = NULL, tassign_closed_by_user_id = NULL, tassign_close_reason = NULL, tassign_close_note = NULL,
                        tassign_due_on = ?, tassign_reopened_count = LEAST(tassign_reopened_count + 1, 65535),
                        tassign_requirement_id = ?, tassign_required = ?, tassign_onboarding_from_on = ?
                    WHERE tassign_id = ? AND tassign_status = 'waived'", 'siisi',
                    [$due, $d['requirement_id'], $d['required'] ? 1 : 0, $d['onboarding_since'], $id]);
                $openId = $id;
                $outcome = 'reopened';
            } else {
                // The waiver ends without reopening this row: cancelled 'unwaived' is no waiver for RecordFacts and is
                // never reopened by reconcile (only no_longer_required / contact_ineligible are).
                Db::exec($db, "UPDATE training_assignments SET tassign_status = 'cancelled', tassign_open_guard = NULL, tassign_waived_until = NULL,
                        tassign_closed_at_utc = ?, tassign_closed_by_user_id = ?, tassign_close_reason = 'unwaived', tassign_close_note = ?
                    WHERE tassign_id = ? AND tassign_status = 'waived'", 'sisi', [$now, $userId, $reason, $id]);
                if ($outcome === 'replace') {
                    $openId = $this->openWanted($a, $ctx['d'], $plan, $due, $now, $events);
                    $outcome = 'replaced';
                } elseif ($outcome === 'open_exists') {
                    $openId = (int) $open['tassign_id'];
                } else {
                    $outcome = 'ended';
                }
            }
            array_unshift($events, AssignmentStore::event('assignment.unwaived', $a, [
                'course_id' => $a['course_id'],
                'prior_until' => $a['waived_until'],
                'prior_reason' => $a['close_note'],
                'reason' => $reason,
                'due_on' => in_array($outcome, ['reopened', 'replaced'], true) ? $due : null,
                'outcome' => $outcome,
                'open_assignment_id' => $openId,
            ], $userId, $meta));
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
            return ['outcome' => $outcome, 'open_id' => $openId, 'why' => $outcome === 'ended' ? $plan['why'] : null,
                    'prior_until' => $a['waived_until'], 'contact_id' => $a['contact_id'], 'course_id' => $a['course_id']];
        });
        return [
            'outcome' => $r['outcome'],
            'why' => $r['why'],
            'assignment' => $r['open_id'] !== null ? $this->shape($r['open_id']) : $this->shape($id),
            'ended' => $this->shape($id),
            'prior_until' => $r['prior_until'],
            'contact_id' => $r['contact_id'],
            'course_id' => $r['course_id'],
        ];
    }

    // =========================================================================================
    // internals
    // =========================================================================================

    /**
     * Opens the anchor the rules want now, as reconcile would: a same-anchor row cancelled as no longer required /
     * ineligible within the reopen window is reopened, else a new row is inserted - with the agent's due date.
     * Appends the assignment.reopened / .created event to $events. Returns the open row id.
     */
    private function openWanted(array $a, array $d, array $plan, string $due, string $now, array &$events): int
    {
        $db = $this->c->db;
        $want = $plan['want'];
        $userId = $this->c->userId;
        $meta = ['user_agent' => $this->c->userAgent];
        $floor = $d['onboarding_since'];
        foreach ($plan['facts']['recentCancelled'] as $R) {
            if ($R['anchor'] !== $want['anchor']) {
                continue;
            }
            Db::exec($db, "UPDATE training_assignments SET tassign_status = 'open', tassign_open_guard = 1, tassign_closed_at_utc = NULL,
                    tassign_closed_by_user_id = NULL, tassign_close_reason = NULL, tassign_close_note = NULL, tassign_due_on = ?,
                    tassign_reopened_count = LEAST(tassign_reopened_count + 1, 65535), tassign_requirement_id = ?, tassign_required = ?,
                    tassign_onboarding_from_on = ?
                WHERE tassign_id = ? AND tassign_status = 'cancelled'", 'siisi', [$due, $d['requirement_id'], $d['required'] ? 1 : 0, $floor, $R['id']]);
            $events[] = AssignmentStore::event('assignment.reopened', $R, ['course_id' => $a['course_id'], 'anchor' => $R['anchor'], 'reason' => $R['reason'],
                'requirement_id' => $d['requirement_id'], 'due_on' => $due, 'required' => $d['required'], 'onboarding_from_on' => $floor, 'trigger' => 'unwaive'],
                $userId, $meta);
            return (int) $R['id'];
        }
        try {
            $newId = Db::insert($db, "INSERT INTO training_assignments (tassign_contact_id, tassign_course_id, tassign_reason, tassign_anchor,
                    tassign_requirement_id, tassign_required, tassign_due_on, tassign_original_due_on, tassign_onboarding_from_on,
                    tassign_status, tassign_open_guard, tassign_created_at_utc, tassign_created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 1, ?, ?)", 'iissiissssi',
                [$a['contact_id'], $a['course_id'], $want['reason'], $want['anchor'], $d['requirement_id'], $d['required'] ? 1 : 0, $due, $due, $floor, $now, $userId]);
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                throw ApiException::busy('Someone else changed this assignment. Reload and try again.');
            }
            throw $e;
        }
        $events[] = AssignmentStore::event('assignment.created', ['id' => $newId, 'contact_id' => $a['contact_id'], 'course_id' => $a['course_id']],
            ['course_id' => $a['course_id'], 'anchor' => $want['anchor'], 'reason' => $want['reason'], 'requirement_id' => $d['requirement_id'],
             'due_on' => $due, 'required' => $d['required'], 'onboarding_from_on' => $floor, 'trigger' => 'unwaive'], $userId, $meta);
        return $newId;
    }

    /** The preview's un-waive facts (read without locks). */
    private function unwaivePreview(array $a, string $today, RecordsSettings $s): array
    {
        $active = $a['waived_until'] === null || $a['waived_until'] >= $today;
        $out = [
            'allowed' => $active,
            'why' => $active ? null : 'This waiver already ended on ' . $a['waived_until'] . '.',
            'outcome' => null,
            'end_why' => null,
            'prior_until' => $a['waived_until'],
            'prior_reason' => $a['close_note'],
            'due_on' => $a['due_on'],
            'original_due_on' => $a['original_due_on'],
            'default_due_on' => self::unwaiveDefault($a, $today),
            'new_anchor_label' => null,
        ];
        if (!$active) {
            return $out;
        }
        $open = $this->openFor($a['contact_id'], $a['course_id']);
        $plan = $this->planUnwaive($a, $this->pairContext($a['contact_id'], $a['course_id'], $today), false, $today, $s);
        $out['outcome'] = $open !== null ? 'open_exists' : $plan['outcome'];
        $out['end_why'] = $plan['outcome'] === 'end' ? $plan['why'] : null;
        if ($plan['outcome'] === 'replace') {
            $out['new_anchor_label'] = self::anchorWords((string) $plan['want']['anchor']);
        }
        return $out;
    }

    /**
     * {outcome:'reopen'|'replace'|'end', want:?array, why:?string, facts:array} for ending waiver $a now: the pair's facts
     * with THIS waiver ignored (another still-active waiver of the pair still counts).
     */
    private function planUnwaive(array $a, array $ctx, bool $locking, string $today, RecordsSettings $s): array
    {
        $db = $this->c->db;
        $d = $ctx['d'];
        $f = RecordFacts::forPair($db, $a['contact_id'], $a['course_id'], $d['onboarding_since'] ?? null, $locking, $s, $today);
        if (!empty($f['waiver']) && (int) $f['waiver']['id'] === $a['id']) {
            $other = Db::one($db, 'SELECT ' . AssignmentStore::COLUMNS . " FROM training_assignments
                WHERE tassign_contact_id = ? AND tassign_course_id = ? AND tassign_status = 'waived' AND tassign_id <> ?
                  AND (tassign_waived_until IS NULL OR tassign_waived_until >= ?) ORDER BY tassign_id DESC LIMIT 1" . ($locking ? ' LOCK IN SHARE MODE' : ''),
                'iiis', [$a['contact_id'], $a['course_id'], $a['id'], $today]);
            $f['waiver'] = $other === null ? null : AssignmentStore::normalize($other);
        }
        $course = $ctx['course'];
        $want = PairRules::want($d, $f, ['validity_months' => $course['validity_months'] ?? null, 'renewal_lead_days' => $course['renewal_lead_days'] ?? 0],
            $today, $s);
        if ($want === null) {
            return ['outcome' => 'end', 'want' => null, 'why' => $this->whyNothing($ctx, $f, $today), 'facts' => $f];
        }
        return ['outcome' => $want['anchor'] === $a['anchor'] ? 'reopen' : 'replace', 'want' => $want, 'why' => null, 'facts' => $f];
    }

    /** Why nothing would be open for the pair: plain words for "Waiver ended - nothing to take right now (<why>)". */
    private function whyNothing(array $ctx, array $f, string $today): string
    {
        $p = $ctx['person'];
        $course = $ctx['course'];
        $d = $ctx['d'];
        if ($course === null || $course['archived']) {
            return 'the course is archived';
        }
        if (!$course['published']) {
            return 'the course is not published';
        }
        if ($p === null) {
            return 'the person was deleted';
        }
        if (!empty($p['archived'])) {
            return 'they are archived';
        }
        if (empty($p['eligible'])) {
            return 'they are not on the training roster';
        }
        if ($d === null) {
            return 'no rule requires this course for them now';
        }
        if (!empty($f['waiver'])) {
            return 'another waiver still covers it' . ($f['waiver']['waived_until'] !== null ? ' until ' . $f['waiver']['waived_until'] : '');
        }
        $L = $f['latest'] ?? null;
        if ($L !== null && PairRules::isValid($L, $f['rr'] ?? null, $today)) {
            return 'they already have a valid record' . ($L['cert_number'] ? ' (' . $L['cert_number'] . ')' : '')
                . ($L['expires_on'] !== null ? ', good until ' . $L['expires_on'] : '');
        }
        if ($L !== null && !empty($d['one_time'])) {
            return 'the rule asks for it only once, and they completed it on ' . $L['completed_on'];
        }
        return 'nothing is due for them right now';
    }

    /**
     * Can this completed assignment be reset to take again?
     * {allowed, done (already voided), why, completion:{id, cert_number, completed_on, expires_on, kind}|null, default_due_on, open_run}
     */
    private function retakePlan(array $a, string $today, RecordsSettings $s): array
    {
        $db = $this->c->db;
        $out = ['allowed' => false, 'done' => false, 'why' => null, 'completion' => null,
                'default_due_on' => Clock::addDays($today, max(1, $s->reissueDays)), 'open_run' => null, 'reissue_days' => $s->reissueDays];
        $x = $a['completion_id'];
        if ($x === null) {
            $out['why'] = 'This assignment was closed without a training record, so there is nothing to void.';
            return $out;
        }
        $c = Db::one($db, 'SELECT c.completion_id, c.completion_cert_number, c.completion_completed_on, c.completion_expires_on, c.completion_course_kind,
                v.cvoid_id FROM training_completions c LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = c.completion_id
            WHERE c.completion_id = ?', 'i', [$x]);
        if ($c === null) {
            $out['why'] = 'Its training record was not found.';
            return $out;
        }
        $label = static fn(array $r): string => ($r['completion_cert_number'] ?? null) ? (string) $r['completion_cert_number']
            : 'the record of ' . $r['completion_completed_on'];
        $out['completion'] = ['id' => (int) $c['completion_id'], 'cert_number' => $c['completion_cert_number'], 'completed_on' => (string) $c['completion_completed_on'],
                              'expires_on' => $c['completion_expires_on'], 'kind' => (string) $c['completion_course_kind']];
        if ($c['cvoid_id'] !== null) {
            $out['done'] = true;
            $out['why'] = 'Its record (' . $label($c) . ') was already voided.';
            return $out;
        }
        $latest = Db::one($db, 'SELECT c.completion_id, c.completion_cert_number, c.completion_completed_on FROM training_completions c
                LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = c.completion_id
            WHERE c.completion_contact_id = ? AND c.completion_course_id = ? AND v.cvoid_id IS NULL
            ORDER BY c.completion_completed_on DESC, c.completion_id DESC LIMIT 1', 'ii', [$a['contact_id'], $a['course_id']]);
        if ($latest !== null && (int) $latest['completion_id'] !== $x) {
            $out['why'] = 'A newer record for this course exists (' . $label($latest) . '), so this one is no longer the record that counts.';
            return $out;
        }
        $ctx = $this->pairContext($a['contact_id'], $a['course_id'], $today);
        $d = $ctx['d'];
        $f = RecordFacts::forPair($db, $a['contact_id'], $a['course_id'], $d['onboarding_since'] ?? null, false, $s, $today);
        if ($d === null) {
            $out['why'] = 'Nothing would be assigned: ' . $this->whyNothing($ctx, ['latest' => null, 'waiver' => null], $today)
                . '. To have them take it again, give it to them with Assign training first.';
            return $out;
        }
        if (!empty($f['waiver'])) {
            $out['why'] = 'The course is waived for them, so nothing would be assigned. End the waiver first.';
            return $out;
        }
        // After the void: the pair's next latest record (same onboarding floor) must not be valid, or nothing reopens.
        $floor = $d['onboarding_since'];
        $next = null;
        foreach ($f['anchorRefs']['c'] as $cid => $cc) {   // ascending (completed_on, completion_id)
            if ((int) $cid === $x || $cc['voided_at_utc'] !== null || ($floor !== null && $cc['completed_on'] < $floor)) {
                continue;
            }
            $next = $cc;
        }
        if ($next !== null && PairRules::isValid($next, $f['rr'] ?? null, $today)) {
            $out['why'] = 'They also have another valid record for this course (' . ($next['cert_number'] ?: 'completed ' . $next['completed_on'])
                . '), so voiding this one would not assign it again.';
            return $out;
        }
        // The same decision reconcile makes after the void: it must want 'reissue:c<x>'.
        $sim = $f;
        $voided = $f['anchorRefs']['c'][$x] ?? null;
        if ($voided === null) {
            $out['why'] = 'Its training record is not the one the rules count for this person.';
            return $out;
        }
        $voided['voided_at_utc'] = Clock::nowUtc();
        $sim['latest'] = $next;
        $sim['latestVoided'] = $voided;
        $sim['anchorRefs']['c'][$x] = $voided;
        $course = $ctx['course'];
        $want = PairRules::want($d, $sim, ['validity_months' => $course['validity_months'] ?? null, 'renewal_lead_days' => $course['renewal_lead_days'] ?? 0], $today, $s);
        if ($want === null || $want['anchor'] !== 'reissue:c' . $x) {
            $out['why'] = 'Voiding this record would not assign the course again right now.';
            return $out;
        }
        $out['allowed'] = true;
        $run = $this->runsReady() ? RunRepo::open($db, $a['contact_id'], $a['course_id']) : null;
        $out['open_run'] = $run === null ? null : RunReset::describe($db, $run);
        return $out;
    }

    /** {person:?array (Directory row), d:?array (desired pair, null when not desired), course:?array (CourseCards row)} */
    private function pairContext(int $contactId, int $courseId, string $today): array
    {
        $db = $this->c->db;
        $p = Directory::load($db, Scope::all(), [$contactId], true)[$contactId] ?? null;
        $d = null;
        if ($p !== null && !empty($p['eligible'])) {
            $matcher = new RuleMatcher(RuleMatcher::loadRules($db, true));
            $d = $matcher->desiredFor($p, $today)[$courseId] ?? null;
        }
        return ['person' => $p, 'd' => $d, 'course' => CourseCards::load($db, [$courseId])[$courseId] ?? null];
    }

    private function openFor(int $contactId, int $courseId): ?array
    {
        $r = Db::one($this->c->db, 'SELECT ' . AssignmentStore::COLUMNS . ' FROM training_assignments WHERE tassign_contact_id = ? AND tassign_course_id = ?
            AND tassign_open_guard = 1', 'ii', [$contactId, $courseId]);
        return $r === null ? null : AssignmentStore::normalize($r);
    }

    private function row(int $id): array
    {
        $r = Db::one($this->c->db, 'SELECT ' . AssignmentStore::COLUMNS . ' FROM training_assignments WHERE tassign_id = ?', 'i', [$id]);
        if ($r === null) {
            throw ApiException::notFound('That assignment was not found.');
        }
        return AssignmentStore::normalize($r);
    }

    private function lockRow(int $id): array
    {
        $r = Db::one($this->c->db, 'SELECT ' . AssignmentStore::COLUMNS . ' FROM training_assignments WHERE tassign_id = ? FOR UPDATE', 'i', [$id]);
        if ($r === null) {
            throw ApiException::notFound('That assignment was not found.');
        }
        return AssignmentStore::normalize($r);
    }

    private function shape(int $id): ?array
    {
        return (new AssignmentService($this->c))->shapeMany([$id])[0] ?? null;
    }

    private function actor(): array
    {
        return ['actor_type' => 'user', 'actor_user_id' => $this->c->userId, 'user_agent' => $this->c->userAgent];
    }

    private function completions(): CompletionService
    {
        try {
            $key = CertSecret::fromGlobals();
        } catch (\RuntimeException $e) {
            $key = '';   // a void issues nothing; the key is only needed to issue certificates
        }
        return new CompletionService($this->c, $key);
    }

    /** Kiosk run tables (2.6.93+); without them there is no progress to reset. */
    private function runsReady(): bool
    {
        if ($this->runsReady === null) {
            $r = Db::one($this->c->db, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'training_runs'");
            $this->runsReady = (int) ($r['n'] ?? 0) === 1;
        }
        return $this->runsReady;
    }

    /** Un-waive default due date: the later of the row's due date and today + 14 days. */
    public static function unwaiveDefault(array $a, string $today): string
    {
        return max((string) $a['due_on'], Clock::addDays($today, self::UNWAIVE_DEFAULT_DAYS));
    }

    private static function anchorWords(string $anchor): string
    {
        $p = PairRules::parseAnchor($anchor);
        return match ($p['kind']) {
            'renew' => 'a renewal',
            'retrain' => 'retraining on a new version',
            'reissue' => 'a redo after a voided record',
            default => 'the course',
        };
    }

    /** Same rule as Extend: after today, at most 10 years out. */
    private static function assertDue(string $dueOn, string $today): void
    {
        if (!Clock::isYmd($dueOn)) {
            throw ApiException::validation(['due_on' => 'Must be a date (YYYY-MM-DD).']);
        }
        if ($dueOn <= $today || $dueOn > Clock::addDays($today, 3660)) {
            throw new ApiException(422, 'date_out_of_range', 'Pick a due date after today.', ['due_on' => 'Must be after today.']);
        }
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        $len = mb_strlen($reason, 'UTF-8');
        if (!mb_check_encoding($reason, 'UTF-8') || $len < self::REASON_MIN || $len > self::REASON_MAX) {
            throw ApiException::validation(['reason' => 'Say why (' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters).']);
        }
        return $reason;
    }
}
