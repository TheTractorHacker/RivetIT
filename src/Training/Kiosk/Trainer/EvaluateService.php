<?php

namespace ITFlow\Training\Kiosk\Trainer;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\Scratch;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Kiosk\Bridge\EvaluationBridge;
use ITFlow\Training\Kiosk\Bridge\TrainerBridge;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;

/**
 * Practical evaluation on the kiosk (P3 spec §3.6, §5.7; v0 §6.4 T5; lane K5).
 *
 *   1  trainer role: pick the course and the person, tick pass/fail per checklist item. The
 *      server REBUILDS the checklist from the course's current revision and takes the results BY
 *      INDEX (a different count is 422 checklist_mismatch), so the client can never add, drop
 *      or reword an item; any fail fails the evaluation.
 *   2  hand-off: the trainer ksess ends 'handoff_enter' and a restricted 'handoff' ksess starts
 *      (contact = the trainer). In that role only the evaluation screens work - no trainer
 *      tiles, no session actions - and Done needs the trainer PIN (handoff_cancel).
 *   3  the employee reads the summary, signs and enters THEIR PIN (evaluatee signature row).
 *   4  "Hand back": the trainer signs and enters THEIR PIN; one transaction ends the hand-off
 *      ('handoff_exit'), starts a new trainer ksess, inserts the evaluator signature and records
 *      the evaluation through EvaluationBridge (which issues the record when every part is done).
 *
 * The in-between state lives in Core\Scratch ('kiosk_eval', owner = the hand-off ksess id,
 * 15 minutes), never in the client.
 */
final class EvaluateService
{
    public const SCRATCH_KIND = 'kiosk_eval';
    public const SCRATCH_TTL_S = 900;
    public const RESULTS = ['pass', 'fail'];

    public function __construct(private readonly KioskCtx $k)
    {
    }

    /** The signed-in trainer with can_evaluate, or 403. */
    private function evaluator(): array
    {
        $t = (new TrainerService($this->k))->me();
        if (!$t['can_evaluate']) {
            throw new ApiException(403, 'not_trainer', 'You are not set up to run evaluations.');
        }
        if (!EvaluationBridge::available($this->k->db())) {
            throw new ApiException(503, 'records_unavailable', "Evaluations aren't available yet.");
        }
        return $t;
    }

    /** Courses with a practical part this trainer may evaluate. */
    public function courses(): array
    {
        $out = [];
        foreach ((new TrainerService($this->k))->courses() as $c) {
            if ($c['needs_practical']) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * GET evaluate_candidates {course_id}: people whose run waits for this evaluation (in the
     * trainer's departments) first, and the checklist the server will use.
     *
     * @return array{course:array, candidates:list<array>, checklist:list<array{item:string, critical:bool}>}
     */
    public function candidates(int $courseId): array
    {
        $t = $this->evaluator();
        $db = $this->k->db();
        $tb = new TrainerBridge($db);
        $course = CourseInfo::current($db, $courseId, $this->k->lang);
        if ($course === null || !$tb->canCourse($t, $courseId) || !$course['needs_practical']) {
            throw ApiException::notFound('That course was not found.');
        }
        $scope = $tb->scopeClientIds($t);
        $sql = "SELECT r.trun_contact_id, MIN(r.trun_attested_at_utc) AS since, c.contact_name, c.contact_client_id, cl.client_name
            FROM training_runs r
            JOIN contacts c ON c.contact_id = r.trun_contact_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            WHERE r.trun_course_id = ? AND r.trun_status = 'awaiting_evaluation' AND c.contact_archived_at IS NULL";
        $types = 'i';
        $p = [$courseId];
        if ($scope !== null) {
            if ($scope === []) {
                return ['course' => $this->courseShape($course), 'candidates' => [], 'checklist' => $course['checklist']];
            }
            $sql .= ' AND c.contact_client_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $types .= str_repeat('i', count($scope));
            $p = array_merge($p, $scope);
        }
        $sql .= ' GROUP BY r.trun_contact_id, c.contact_name, c.contact_client_id, cl.client_name ORDER BY since, c.contact_name LIMIT 100';
        $out = [];
        foreach (Db::all($db, $sql, $types, $p) as $r) {
            $cid = (int) $r['trun_contact_id'];
            if ($cid === $this->k->contactId()) {
                continue;
            }
            $name = trim((string) $r['contact_name']);
            $out[] = [
                'contact_id' => $cid,
                'sig' => $this->k->keys->pickSig($this->k->kioskId(), $cid),
                'name' => $name,
                'first' => KioskAuth::firstName($name),
                'initials' => KioskAuth::initials($name),
                'dept' => (string) ($r['client_name'] ?? ''),
                'since' => $r['since'] === null ? null : Clock::toIso((string) $r['since'], true),
            ];
        }
        return ['course' => $this->courseShape($course), 'candidates' => $out, 'checklist' => $course['checklist']];
    }

    /**
     * POST evaluate_handoff {course_id, contact_id, sig, results[], equipment?, notes?}.
     * Returns {eval_token, result, first, next} and sets the hand-off cookie.
     */
    public function handoff(int $courseId, int $contactId, string $sig, array $results, ?string $equipment, ?string $notes): array
    {
        $t = $this->evaluator();
        $db = $this->k->db();
        $tb = new TrainerBridge($db);
        if (!hash_equals($this->k->keys->pickSig($this->k->kioskId(), $contactId), $sig)) {
            throw ApiException::notFound('That person was not found.');
        }
        $course = CourseInfo::current($db, $courseId, 'en');
        if ($course === null || !$tb->canCourse($t, $courseId) || !$course['needs_practical']) {
            throw ApiException::notFound('That course was not found.');
        }
        if ($contactId === $this->k->contactId()) {
            throw ApiException::validation(['contact_id' => 'You cannot evaluate yourself.']);
        }
        $person = (new TrainerService($this->k))->person($contactId);
        if (!$tb->inScope($t, $person['client_id'])) {
            throw ApiException::notFound('That person is not in your departments.');
        }
        $checklist = $course['checklist'];
        if ($checklist === []) {
            throw new ApiException(409, 'validation', 'This course has no evaluation checklist. Ask the office to add one.');
        }
        if (!array_is_list($results) || count($results) !== count($checklist)) {
            throw new ApiException(422, 'checklist_mismatch', 'The checklist changed. Reload and evaluate again.', ['results' => 'Mark every item.']);
        }
        $rows = [];
        $fail = false;
        foreach ($checklist as $i => $item) {
            $v = $results[$i];
            if (!is_string($v) || !in_array($v, self::RESULTS, true)) {
                throw new ApiException(422, 'checklist_mismatch', 'Mark every item pass or fail.', ['results' => 'Mark every item.']);
            }
            $fail = $fail || $v === 'fail';
            $rows[] = ['item' => $item['item'], 'critical' => $item['critical'], 'result' => $v];
        }
        $result = $fail ? 'fail' : 'pass';
        $clip = static function (?string $s, int $n): ?string {
            $s = $s === null ? '' : trim($s);
            return $s === '' ? null : Text::clip($s, $n);
        };
        $state = [
            'course_id' => $courseId,
            'revision_id' => $course['revision_id'],
            'contact_id' => $contactId,
            'contact_name' => $person['name'],
            'trainer_contact_id' => $this->k->contactId(),
            'checklist' => $rows,
            'equipment' => $clip($equipment, 200),
            'notes' => $clip($notes, 2000),
            'result' => $result,
            'evaluatee_tsig_id' => null,
        ];
        $k = $this->k;
        $sw = Db::tx($db, static function () use ($db, $k): array {
            $sw = RoleSwitch::switch($k, 'handoff_enter', 'handoff', $k->contactId(), null);
            foreach ($sw['events'] as $e) {
                Ledger::append($db, $e);
            }
            return $sw;
        });
        KioskAuth::setSessionCookie($sw['token']);
        $token = Scratch::put(self::SCRATCH_KIND, $sw['ksess_id'], $state, self::SCRATCH_TTL_S);
        return ['eval_token' => $token, 'result' => $result, 'first' => $person['first'], 'next' => '/kiosk/evaluate.php?step=evaluatee'];
    }

    /** GET evaluate_state {eval_token} (handoff role): the read-only summary the employee signs. */
    public function state(string $evalToken): array
    {
        $st = $this->load($evalToken);
        $db = $this->k->db();
        $course = CourseInfo::atRevision($db, (int) $st['course_id'], (int) $st['revision_id'], $this->k->lang);
        return [
            'course' => $course['name'] ?? '',
            'employee' => ['name' => (string) $st['contact_name'], 'first' => KioskAuth::firstName((string) $st['contact_name'])],
            'trainer' => ['name' => $this->k->ksess['contact_name'] ?? '', 'first' => $this->k->ksess['first'] ?? ''],
            'checklist' => $st['checklist'],
            'equipment' => $st['equipment'],
            'notes' => $st['notes'],
            'result' => $st['result'],
            'step' => $st['evaluatee_tsig_id'] === null ? 'evaluatee' : 'evaluator',
        ];
    }

    /** POST evaluate_evaluatee {eval_token, pin, signature_png}: the employee signs the summary and enters their PIN. */
    public function evaluatee(string $evalToken, mixed $pin, mixed $sigDataUrl): array
    {
        $st = $this->load($evalToken);
        if ($st['evaluatee_tsig_id'] !== null) {
            unset($pin);
            return ['next_step' => 'evaluator'];
        }
        $cid = (int) $st['contact_id'];
        $prep = TrainerSig::prepare($sigDataUrl, true);
        TrainerPin::require($this->k, $cid, $pin, 'evaluatee');
        unset($pin);
        $db = $this->k->db();
        $k = $this->k;
        $statement = self::statement($st);
        $actor = array_merge($k->eventBase(), ['actor_type' => 'contact', 'actor_contact_id' => $cid]);
        $sig = Db::tx($db, static function () use ($db, $k, $prep, $st, $cid, $statement, $actor): array {
            $sig = TrainerSig::insert($k, $prep, [
                'purpose' => 'evaluatee', 'contact_id' => $cid, 'signer_name' => (string) $st['contact_name'],
                'statement_sha256' => TrainerSig::statementSha($statement),
            ]);
            Ledger::append($db, array_merge($sig['event'], $actor));
            return $sig;
        });
        $st['evaluatee_tsig_id'] = $sig['id'];
        Scratch::replace(self::SCRATCH_KIND, $evalToken, (int) $this->k->ksessId(), $st);
        return ['next_step' => 'evaluator'];
    }

    /**
     * POST evaluate_submit {eval_token, pin, signature_png}: the trainer signs, enters their PIN
     * and the evaluation is recorded. Back in the trainer role afterwards (new cookie).
     */
    public function submit(string $evalToken, mixed $trainerPin, mixed $sigDataUrl): array
    {
        $st = $this->load($evalToken);
        if ($st['evaluatee_tsig_id'] === null) {
            throw new ApiException(409, 'validation', 'The employee has not signed yet.');
        }
        if ((int) $st['trainer_contact_id'] !== $this->k->contactId()) {
            throw ApiException::notFound('That evaluation was not found.');
        }
        $db = $this->k->db();
        if (!EvaluationBridge::available($db)) {
            throw new ApiException(503, 'records_unavailable', "Training records aren't available right now.");
        }
        $prep = TrainerSig::prepare($sigDataUrl, true);
        TrainerPin::require($this->k, $this->k->contactId(), $trainerPin, 'evaluator');
        unset($trainerPin);
        // The trainer must still be allowed to evaluate this course (re-read after the PIN, v0 §6.4 T1).
        $t = (new TrainerBridge($db))->trainer($this->k->contactId());
        if ($t === null || !$t['active'] || !$t['can_evaluate'] || !(new TrainerBridge($db))->canCourse($t, (int) $st['course_id'])) {
            throw new ApiException(403, 'not_trainer', 'You are not set up to run evaluations.');
        }
        $k = $this->k;
        $trainerName = (string) ($k->ksess['contact_name'] ?? '');
        $statement = self::statement($st);
        $handoffKsess = (int) $k->ksessId();
        $bridge = new EvaluationBridge($k->core, $k->eventBase());
        $runId = $bridge->awaitingRunId((int) $st['contact_id'], (int) $st['course_id']);
        $r = Db::tx($db, static function () use ($db, $k, $prep, $st, $trainerName, $statement, $handoffKsess, $bridge, $runId): array {
            $sw = RoleSwitch::switch($k, 'handoff_exit', 'trainer', $k->contactId(), null);
            $sig = TrainerSig::insert($k, $prep, [
                'purpose' => 'evaluator', 'contact_id' => $k->contactId(), 'signer_name' => $trainerName,
                'statement_sha256' => TrainerSig::statementSha($statement), 'run_id' => $runId,
            ]);
            $rec = $bridge->record([
                'source_key' => 'kev:' . $handoffKsess,
                'contact_id' => (int) $st['contact_id'],
                'course_id' => (int) $st['course_id'],
                'revision_id' => (int) $st['revision_id'],
                'run_id' => $runId,
                'evaluator_contact_id' => $k->contactId(),
                'evaluator_name' => $trainerName,
                'evaluated_on' => Clock::todayLocal(),
                'result' => (string) $st['result'],
                'equipment' => $st['equipment'],
                'checklist' => $st['checklist'],
                'notes' => $st['notes'],
                'evaluator_tsig_id' => $sig['id'],
                'evaluatee_tsig_id' => (int) $st['evaluatee_tsig_id'],
                'kiosk_id' => $k->kioskId(),
            ]);
            foreach ($sw['events'] as $e) {
                Ledger::append($db, $e);
            }
            Ledger::append($db, $sig['event']);
            foreach ($rec['events'] as $e) {
                Ledger::append($db, $e);
            }
            return ['sw' => $sw, 'rec' => $rec];
        });
        KioskAuth::setSessionCookie($r['sw']['token']);
        Scratch::take(self::SCRATCH_KIND, $evalToken, $handoffKsess);
        $bridge->afterCommit($r['rec']);
        TrainerService::settle($k, [(int) $st['contact_id']]);
        $rec = $r['rec'];
        return [
            'status' => $rec['completion'] !== null ? 'recorded' : 'pending',
            'result' => $rec['result'],
            'record_id' => $rec['completion']['completion_id'] ?? null,
            'cert_number' => $rec['completion']['cert_number'] ?? null,
            'pending' => $rec['pending'],
            'first' => KioskAuth::firstName((string) $st['contact_name']),
            'next' => '/kiosk/evaluate.php',
        ];
    }

    /** POST handoff_cancel {pin, eval_token?}: the trainer takes the iPad back without recording anything. */
    public function cancelHandoff(mixed $trainerPin, ?string $evalToken): array
    {
        TrainerPin::require($this->k, $this->k->contactId(), $trainerPin, 'handoff_cancel');
        unset($trainerPin);
        $db = $this->k->db();
        $k = $this->k;
        $handoffKsess = (int) $k->ksessId();
        $sw = Db::tx($db, static function () use ($db, $k): array {
            $sw = RoleSwitch::switch($k, 'handoff_exit', 'trainer', $k->contactId(), null);
            foreach ($sw['events'] as $e) {
                Ledger::append($db, $e);
            }
            return $sw;
        });
        KioskAuth::setSessionCookie($sw['token']);
        if ($evalToken !== null && preg_match('/^[0-9a-f]{32}$/D', $evalToken) === 1) {
            Scratch::take(self::SCRATCH_KIND, $evalToken, $handoffKsess);
        }
        return ['next' => '/kiosk/evaluate.php'];
    }

    // ---------------------------------------------------------------------------------------

    /** The hand-off state owned by THIS hand-off ksess, or 404 (expired, taken, or another device's). */
    private function load(string $evalToken): array
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $evalToken) !== 1) {
            throw ApiException::notFound('That evaluation expired. Cancel and start again.');
        }
        $st = Scratch::get(self::SCRATCH_KIND, $evalToken, (int) $this->k->ksessId());
        if (!is_array($st) || !isset($st['course_id'], $st['contact_id'], $st['checklist'], $st['result'])) {
            throw ApiException::notFound('That evaluation expired. Cancel and start again.');
        }
        return $st;
    }

    /** The exact facts both people sign: course, revision, person, every item with its result, the overall result. */
    private static function statement(array $st): string
    {
        return Canonical::doc([
            'kind' => 'kiosk_evaluation',
            'course_id' => (string) (int) $st['course_id'],
            'revision_id' => (string) (int) $st['revision_id'],
            'contact_id' => (string) (int) $st['contact_id'],
            'checklist' => array_map(static fn($r) => ['item' => (string) $r['item'], 'critical' => $r['critical'] ? '1' : '0', 'result' => (string) $r['result']],
                $st['checklist']),
            'equipment' => $st['equipment'] === null ? '' : (string) $st['equipment'],
            'result' => (string) $st['result'],
        ]);
    }

    private function courseShape(array $c): array
    {
        return ['id' => $c['id'], 'name' => $c['name'], 'needs_online' => $c['needs_online']];
    }
}
