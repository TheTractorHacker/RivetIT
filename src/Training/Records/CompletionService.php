<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Assign\AssignmentStore;
use ITFlow\Training\Assign\RecordFacts;
use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashedInsert;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsMutex;
use ITFlow\Training\Core\Text;

/**
 * The one writer of training completions (Phase 2 spec §3.5, M8). Every record - kiosk course,
 * office session, practical evaluation, external card, paper record - is issued here.
 *
 * issue() is idempotent by source key and serialized by the records mutex. Inside ONE
 * transaction, in the global lock order (spec §0 #4):
 *   records mutex -> source-key read (FOR UPDATE) -> open assignment (AssignmentStore, FOR UPDATE)
 *   -> record facts (locking reads) -> year counter (CertIssuer::nextNumber) -> inserts
 *   (HashedInsert completion, cert token, assignment close) -> ledger head (last)
 * A duplicate source key for the same person and course returns the existing record
 * (duplicate:true, no number used); for a different person or course it is 409
 * source_key_conflict and the other record is never revealed.
 *
 * Composite writers (session finalize, evaluation -> completion, the Phase 3 sign-off) call
 * issue(..., deferEvents: true) inside their own transaction, append the returned events LAST
 * and call afterCommit($result) after commit. They must not hold assignment or completion row
 * locks when they call it (entity rows and the records mutex are fine: that is the order).
 *
 * Nothing here authorizes: Actions and pages check level, Scope and RecordRules::notOwn first
 * (spec §0 #2). Completions, voids and tokens are insert-only (HashedInsert); there is no
 * UPDATE or DELETE path.
 */
final class CompletionService
{
    public const METHODS = ['online', 'session', 'blended', 'evaluation', 'external', 'legacy_paper'];
    public const PROOFS = ['self_pin_signature', 'evaluator_signed', 'self_pin', 'portal_login', 'trainer_attested', 'document', 'agent_recorded'];
    /** Methods that always name a published revision (null => the course's current one). */
    public const REVISION_METHODS = ['online', 'session', 'blended', 'evaluation'];
    public const SOURCE_KEY_RE = '/^[a-z]{2,8}:[A-Za-z0-9:_-]{1,39}$/D';
    public const ACTOR_TYPES = ['user', 'contact', 'kiosk', 'system'];
    public const VOID_REASON_MIN = 10;

    private const INT_KEYS = ['revision_id', 'pass_mark_pct', 'attempts_used', 'duration_minutes', 'run_id', 'attempt_id', 'tsession_id',
        'tattendee_id', 'evaluation_id', 'trainer_contact_id', 'trainer_user_id', 'learner_tsig_id', 'trainer_tsig_id', 'kiosk_id',
        'asset_id', 'odoo_employee_id', 'evidence_media_id', 'supersedes_id', 'actor_contact_id', 'ksess_id'];
    private const TEXT_KEYS = ['trainer_name' => 200, 'evaluator_name' => 200, 'external_issuer' => 200, 'external_ref' => 100,
        'attestation_text' => 5000, 'notes' => 5000];

    private ?string $lastPending = null;

    public function __construct(private readonly Ctx $c, private readonly string $certKey)
    {
    }

    // =========================================================================================
    // issue
    // =========================================================================================

    /**
     * @param array<string, mixed> $in spec §3.5 input keys (contact_id, course_id, method, proof, source_key,
     *   completed_on, and the optional ones)
     * @return array{completion_id:int, contact_id:int, course_id:int, kind:string, cert_number:?string, token:?string,
     *   verify_url:?string, assignment_id:?int, duplicate:bool, events?:list<array>}
     */
    public function issue(array $in, bool $deferEvents = false): array
    {
        $v = $this->normalize($in);
        $today = Clock::todayLocal();
        RecordRules::dates([
            'completed_on' => $v['completed_on'],
            'trained_on' => $v['trained_on'],
            'evaluated_on' => $v['evaluated_on'],
            'expires_on_override' => $v['expires_on_override'],
        ], $today);

        $own = Db::depth() === 0;
        if ($own && $deferEvents) {
            throw new \LogicException('CompletionService::issue: deferEvents needs the caller\'s open transaction');
        }
        $result = Db::tx($this->c->db, fn(): array => $this->issueInTx($v, $today, $deferEvents));
        if ($own) {
            $result['reconcile'] = $this->afterCommit($result);
        }
        return $result;
    }

    /**
     * Post-commit work for an issued record: activity log, audit, the Phase 3/5 listeners and a
     * one-contact reconcile (skipped when $result['suppress_reconcile'] is set: a session
     * finalize runs ONE reconcile for all its attendees). Every step is best-effort.
     *
     * @return array|null the reconcile result ({error:'busy'|'failed'} on failure), null when skipped
     */
    public function afterCommit(array $result): ?array
    {
        if (!empty($result['duplicate']) || empty($result['completion_id'])) {
            return null;
        }
        $id = (int) $result['completion_id'];
        $label = $result['cert_number'] ?? ('#' . $id);
        $summary = "Recorded training record $label for contact #" . (int) $result['contact_id'] . ' (course #' . (int) $result['course_id'] . ')';
        AfterCommit::log('Create', $summary, $id);
        AfterCommit::audit($this->c, 'training.completion_recorded', 'training_completion', $id, 'create', $summary, [
            'contact_id' => (int) $result['contact_id'],
            'course_id' => (int) $result['course_id'],
            'cert_number' => $result['cert_number'] ?? null,
        ]);
        AfterCommit::completionRecorded($this->c, $id);
        if (!empty($result['suppress_reconcile'])) {
            return null;
        }
        return self::reconcile($this->c, [(int) $result['contact_id']], 'completion');
    }

    // =========================================================================================
    // void
    // =========================================================================================

    /**
     * Voids a record (level 3 at the edge; spec §3.5). Voids are rows, never edits: a hashed
     * training_completion_voids row plus a completion.voided event. The records mutex is the
     * first lock. After commit: log, audit, listeners and a one-contact reconcile (which opens
     * the reissue assignment when no other valid record remains).
     *
     * @return array{void_id:int, completion_id:int, contact_id:int, course_id:int, cert_number:?string, reconcile:?array}
     */
    public function void(int $completionId, string $reason): array
    {
        $reason = trim($reason);
        $len = mb_strlen($reason, 'UTF-8');
        if ($len < self::VOID_REASON_MIN) {
            throw ApiException::validation(['reason' => 'Give a reason of at least ' . self::VOID_REASON_MIN . ' characters.']);
        }
        if ($len > 1000 || !mb_check_encoding($reason, 'UTF-8')) {
            throw ApiException::validation(['reason' => 'Too long (at most 1000 characters).']);
        }
        if (Db::depth() !== 0) {
            throw new \LogicException('CompletionService::void opens its own transaction');
        }
        $db = $this->c->db;
        $actor = $this->actor([]);
        $r = Db::tx($db, function () use ($db, $completionId, $reason, $actor): array {
            RecordsMutex::acquire($db);
            $row = Db::one($db, 'SELECT completion_id, completion_contact_id, completion_course_id, completion_cert_number
                FROM training_completions WHERE completion_id = ? FOR UPDATE', 'i', [$completionId]);
            if ($row === null) {
                throw ApiException::notFound('That record was not found.');
            }
            if (Db::one($db, 'SELECT cvoid_id FROM training_completion_voids WHERE cvoid_completion_id = ? FOR UPDATE', 'i', [$completionId]) !== null) {
                throw new ApiException(422, 'already_voided', 'This record is already voided.');
            }
            $ins = HashedInsert::insert($db, 'training_completion_voids', [
                'cvoid_completion_id' => (string) $completionId,
                'cvoid_reason' => $reason,
                'cvoid_by_user_id' => (string) max(0, $this->c->userId),
                'cvoid_at_utc' => Clock::nowUtc(),
                'cvoid_hash_v' => '1',
            ]);
            $contactId = (int) $row['completion_contact_id'];
            $courseId = (int) $row['completion_course_id'];
            Ledger::append($db, $actor + [
                'type' => 'completion.voided',
                'subject_contact_id' => $contactId,
                'course_id' => $courseId,
                'entity_type' => 'completion_void',
                'entity_id' => $ins['id'],
                'entity_sha256' => $ins['sha'],
                'payload' => ['completion_id' => $completionId, 'cert_number' => $row['completion_cert_number']],
            ]);
            return ['void_id' => $ins['id'], 'completion_id' => $completionId, 'contact_id' => $contactId, 'course_id' => $courseId,
                    'cert_number' => $row['completion_cert_number'] === null ? null : (string) $row['completion_cert_number']];
        });

        $label = $r['cert_number'] ?? ('#' . $completionId);
        $summary = "Voided training record $label for contact #{$r['contact_id']}";
        AfterCommit::log('Delete', $summary, $completionId);
        AfterCommit::audit($this->c, 'training.completion_voided', 'training_completion', $completionId, 'void', $summary,
            ['void_id' => $r['void_id'], 'contact_id' => $r['contact_id'], 'course_id' => $r['course_id'], 'cert_number' => $r['cert_number']]);
        AfterCommit::completionVoided($this->c, $completionId);
        $r['reconcile'] = self::reconcile($this->c, [$r['contact_id']], 'void');
        return $r;
    }

    // =========================================================================================
    // components (S1)
    // =========================================================================================

    /**
     * Issues a completion from a course's separately recorded components when every part the
     * course needs is present and they fall within the course's window (spec §3.5):
     *   session   = the latest present, not-removed attendance in a finalized session
     *   practical = the latest passed evaluation
     *   online    = Components::online()->latestAttested() (Phase 3; pending until then)
     * method blended when more than one part, else session | evaluation | online; proof = the
     * weakest part's; source key 'blend:a<attendee>-e<evaluation>-r<run>' (0 = part not used), so
     * the same combination never issues twice. Returns null when not complete; the reason is
     * then in lastPendingReason().
     */
    public function tryIssueComponents(int $contactId, int $courseId, bool $deferEvents = false): ?array
    {
        $this->lastPending = null;
        $db = $this->c->db;
        $course = CourseFacts::load($db, $courseId);
        if ($course === null || !$course['published']) {
            $this->lastPending = 'course_unpublished';
            return null;
        }
        $needs = $course['needs'];
        if (!$needs['online'] && !$needs['session'] && !$needs['practical']) {
            $this->lastPending = 'no_components';
            return null;
        }

        $session = null;
        if ($needs['session']) {
            $session = Db::one($db, "SELECT ta.tattendee_id, ta.tattendee_proof, s.tsession_id, s.tsession_held_on, s.tsession_trainer_contact_id,
                    s.tsession_trainer_user_id, s.tsession_trainer_name, s.tsession_duration_minutes, s.tsession_evidence_media_id
                FROM training_session_attendees ta
                JOIN training_sessions s ON s.tsession_id = ta.tattendee_tsession_id
                WHERE ta.tattendee_contact_id = ? AND s.tsession_course_id = ? AND s.tsession_status = 'finalized'
                  AND ta.tattendee_attendance = 'present' AND ta.tattendee_removed_at_utc IS NULL
                ORDER BY s.tsession_held_on DESC, ta.tattendee_id DESC LIMIT 1", 'ii', [$contactId, $courseId]);
            if ($session === null) {
                $this->lastPending = 'needs_session';
                return null;
            }
        }
        $eval = null;
        if ($needs['practical']) {
            $eval = Db::one($db, "SELECT evaluation_id, evaluation_evaluated_on, evaluation_proof, evaluation_evaluator_name, evaluation_evidence_media_id
                FROM training_evaluations
                WHERE evaluation_contact_id = ? AND evaluation_course_id = ? AND evaluation_result = 'pass'
                ORDER BY evaluation_evaluated_on DESC, evaluation_id DESC LIMIT 1", 'ii', [$contactId, $courseId]);
            if ($eval === null) {
                $this->lastPending = 'needs_practical';
                return null;
            }
        }
        $online = null;
        if ($needs['online']) {
            $src = Components::online($this->c);
            $anchorDates = array_filter([$session['tsession_held_on'] ?? null, $eval['evaluation_evaluated_on'] ?? null]);
            $since = Clock::addDays($anchorDates === [] ? Clock::todayLocal() : min($anchorDates), -max(0, $course['window_days']));
            $online = $src?->latestAttested($contactId, $courseId, $since);
            if ($online === null) {
                $this->lastPending = 'needs_online';
                return null;
            }
        }

        $dates = array_values(array_filter([
            $session['tsession_held_on'] ?? null,
            $eval['evaluation_evaluated_on'] ?? null,
            $online['attested_on'] ?? null,
        ]));
        $span = (int) (new \DateTimeImmutable(min($dates)))->diff(new \DateTimeImmutable(max($dates)))->format('%a');
        if ($span > $course['window_days']) {
            $this->lastPending = 'outside_window';
            return null;
        }

        $attId = $session === null ? null : (int) $session['tattendee_id'];
        $evalId = $eval === null ? null : (int) $eval['evaluation_id'];
        $runId = $online === null ? null : (int) $online['run_id'];
        // The same parts already made a record under another key (e.g. the session's own 'att:').
        $same = Db::one($db, 'SELECT completion_id FROM training_completions
            WHERE completion_contact_id = ? AND completion_course_id = ?
              AND completion_tattendee_id <=> ? AND completion_evaluation_id <=> ? AND completion_run_id <=> ?
            ORDER BY completion_id DESC LIMIT 1', 'iiiii', [$contactId, $courseId, $attId, $evalId, $runId]);
        if ($same !== null) {
            $this->lastPending = 'already_recorded';
            return null;
        }

        $parts = count($dates);
        $method = $parts > 1 ? 'blended' : ($session !== null ? 'session' : ($eval !== null ? 'evaluation' : 'online'));
        $proofs = array_values(array_filter([
            $session['tattendee_proof'] ?? null,
            $eval['evaluation_proof'] ?? null,
            $online['proof'] ?? null,
        ]));
        $trained = array_values(array_filter([$online['attested_on'] ?? null, $session['tsession_held_on'] ?? null]));

        return $this->issue([
            'contact_id' => $contactId,
            'course_id' => $courseId,
            'method' => $method,
            'proof' => EvidenceStrength::weakest(...$proofs),
            'source_key' => 'blend:a' . ($attId ?? 0) . '-e' . ($evalId ?? 0) . '-r' . ($runId ?? 0),
            'completed_on' => max($dates),
            'trained_on' => $trained === [] ? null : max($trained),
            'evaluated_on' => $eval['evaluation_evaluated_on'] ?? null,
            'revision_id' => $online['revision_id'] ?? null,
            'language' => $online['language'] ?? 'en',
            'score_pct' => $online['score_pct'] ?? null,
            'pass_mark_pct' => $online['pass_mark_pct'] ?? null,
            'attempts_used' => $online['attempts_used'] ?? null,
            'duration_minutes' => $online['duration_minutes'] ?? ($session === null ? null : ($session['tsession_duration_minutes'] === null ? null : (int) $session['tsession_duration_minutes'])),
            'run_id' => $runId,
            'tsession_id' => $session === null ? null : (int) $session['tsession_id'],
            'tattendee_id' => $attId,
            'evaluation_id' => $evalId,
            'trainer_contact_id' => $session === null || $session['tsession_trainer_contact_id'] === null ? null : (int) $session['tsession_trainer_contact_id'],
            'trainer_user_id' => $session === null || $session['tsession_trainer_user_id'] === null ? null : (int) $session['tsession_trainer_user_id'],
            'trainer_name' => $session === null ? null : ((string) $session['tsession_trainer_name'] === '' ? null : (string) $session['tsession_trainer_name']),
            'evaluator_name' => $eval['evaluation_evaluator_name'] ?? null,
            'learner_tsig_id' => $online['learner_tsig_id'] ?? null,
            'kiosk_id' => $online['kiosk_id'] ?? null,
            'asset_id' => $online['asset_id'] ?? null,
            'pin_source' => $online['pin_source'] ?? null,
            'odoo_employee_id' => $online['odoo_employee_id'] ?? null,
            'evidence_media_id' => ($eval !== null && $eval['evaluation_evidence_media_id'] !== null) ? (int) $eval['evaluation_evidence_media_id']
                : (($session !== null && $session['tsession_evidence_media_id'] !== null) ? (int) $session['tsession_evidence_media_id'] : null),
        ], $deferEvents);
    }

    /** Why the last tryIssueComponents() returned null: needs_session | needs_practical | needs_online | outside_window | ... */
    public function lastPendingReason(): ?string
    {
        return $this->lastPending;
    }

    /** The public verify token of an issued certificate (re-derived; null for documents or after a key rotation). */
    public function reprintToken(int $completionId): ?string
    {
        if ($this->certKey === '') {
            return null;
        }
        return CertIssuer::reprint($this->c->db, $completionId, $this->certKey);
    }

    // =========================================================================================
    // shared
    // =========================================================================================

    /**
     * One reconcile call after a records commit, best-effort: the record stands whatever happens
     * (spec §3.3 "Save stands"). Busy/deadlock => {error:'busy'}, anything else => {error:'failed'}.
     *
     * @param list<int> $contactIds
     */
    public static function reconcile(Ctx $c, array $contactIds, string $trigger): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn(int $i) => $i > 0)));
        if ($ids === [] || !class_exists(AssignmentService::class)) {
            return null;
        }
        try {
            $r = (new AssignmentService($c))->reconcile($ids, $trigger, 1);
            // Another reconcile held the lock: the record stands; assignments catch up on the next run.
            return !empty($r['skipped_busy']) ? ['error' => 'busy'] + $r : $r;
        } catch (\mysqli_sql_exception $e) {
            $busy = in_array((int) $e->getCode(), [1205, 1213], true);
            error_log("Training: reconcile after $trigger failed: " . $e->getMessage());
            return ['error' => $busy ? 'busy' : 'failed'];
        } catch (\Throwable $e) {
            error_log("Training: reconcile after $trigger failed: " . get_class($e) . ': ' . $e->getMessage());
            return ['error' => 'failed'];
        }
    }

    /** The ledger actor fields for this context (user when a user is logged in, else system). */
    public function actor(array $v): array
    {
        $type = $v['actor_type'] ?? ($this->c->userId > 0 ? 'user' : 'system');
        return [
            'actor_type' => $type,
            'actor_user_id' => $this->c->userId > 0 ? $this->c->userId : null,
            'actor_contact_id' => $v['actor_contact_id'] ?? null,
            'kiosk_id' => $v['kiosk_id'] ?? null,
            'ksess_id' => $v['ksess_id'] ?? null,
            'user_agent' => $this->c->userAgent,
        ];
    }

    private function issueInTx(array $v, string $today, bool $deferEvents): array
    {
        $db = $this->c->db;
        RecordsMutex::acquire($db);

        $hit = Db::one($db, 'SELECT completion_id, completion_contact_id, completion_course_id, completion_course_kind, completion_cert_number,
                completion_assignment_id
            FROM training_completions WHERE completion_source_key = ? FOR UPDATE', 's', [$v['source_key']]);
        if ($hit !== null) {
            if ((int) $hit['completion_contact_id'] !== $v['contact_id'] || (int) $hit['completion_course_id'] !== $v['course_id']) {
                throw new ApiException(409, 'source_key_conflict', 'That request was already used for a different record. Reload and try again.');
            }
            $id = (int) $hit['completion_id'];
            $token = $this->reprintToken($id);
            $out = [
                'completion_id' => $id,
                'contact_id' => $v['contact_id'],
                'course_id' => $v['course_id'],
                'kind' => (string) $hit['completion_course_kind'],
                'cert_number' => $hit['completion_cert_number'] === null ? null : (string) $hit['completion_cert_number'],
                'token' => $token,
                'verify_url' => $token === null ? null : CertIssuer::verifyUrl($this->c->baseUrl, $token),
                'assignment_id' => $hit['completion_assignment_id'] === null ? null : (int) $hit['completion_assignment_id'],
                'duplicate' => true,
            ];
            if ($deferEvents) {
                $out['events'] = [];
            }
            return $out;
        }

        $contact = Db::one($db, 'SELECT c.contact_id, c.contact_name, c.contact_title, c.contact_client_id, cl.client_name
            FROM contacts c LEFT JOIN clients cl ON cl.client_id = c.contact_client_id WHERE c.contact_id = ?', 'i', [$v['contact_id']]);
        if ($contact === null) {
            throw ApiException::notFound('That person was not found.');
        }
        $course = CourseFacts::load($db, $v['course_id'], $v['revision_id']);
        if ($course === null) {
            throw ApiException::notFound('That course was not found.');
        }
        $needsRevision = in_array($v['method'], self::REVISION_METHODS, true);
        if ($needsRevision && $course['revision'] === null) {
            throw new ApiException(422, 'course_unpublished', 'This course has not been published yet.', ['course_id' => 'Not published.']);
        }
        // External and paper records name a revision only when the caller chose one (documents).
        $revision = ($needsRevision || $v['revision_id'] !== null) ? $course['revision'] : null;
        if ($v['expires_on_override'] !== null && $needsRevision) {
            throw ApiException::validation(['expires_on' => 'Only external and paper records can set their own expiry.']);
        }
        if ($v['supersedes_id'] !== null) {
            $sup = Db::one($db, 'SELECT completion_contact_id, completion_course_id FROM training_completions WHERE completion_id = ?', 'i', [$v['supersedes_id']]);
            if ($sup === null || (int) $sup['completion_contact_id'] !== $v['contact_id'] || (int) $sup['completion_course_id'] !== $v['course_id']) {
                throw ApiException::validation(['supersedes_id' => 'That record is not for this person and course.']);
            }
        }
        if ($v['evidence_media_id'] !== null) {
            $m = Db::one($db, 'SELECT media_kind FROM training_media WHERE media_id = ?', 'i', [$v['evidence_media_id']]);
            if ($m === null || $m['media_kind'] !== 'evidence') {
                throw new ApiException(422, 'evidence_token_invalid', 'The attached scan could not be found. Upload it again.', ['evidence_token' => 'Upload the scan again.']);
            }
        }

        // Lock order: records mutex (held) -> source key (held) -> assignment rows -> year counter -> reads -> inserts -> ledger head.
        $open = AssignmentStore::lockOpenForPair($db, $v['contact_id'], $v['course_id']);
        $facts = RecordFacts::forPair($db, $v['contact_id'], $v['course_id'], $open === null ? null : self::openOnboarding($open), true);

        $recordedAt = Clock::nowUtc();
        $kind = (string) $course['kind'];
        $certNumber = $kind === 'training' ? CertIssuer::nextNumber($db, Clock::localDate($recordedAt)) : null;
        $expires = $v['expires_on_override']
            ?? (($course['validity_months'] ?? 0) > 0 ? Clock::addMonths($v['completed_on'], (int) $course['validity_months']) : null);
        $revNumber = $revision === null ? null : (int) $revision['number'];

        $candidate = [
            'completion_id' => 0,
            'completed_on' => $v['completed_on'],
            'expires_on' => $expires,
            'revision_number' => $revNumber,
            'supersedes_id' => $v['supersedes_id'],
            'voided_at_utc' => null,
        ];
        $satisfied = $open !== null && PairRules::satisfies($open, $candidate, $facts, $today);
        $openId = $open === null ? null : self::openId($open);
        $assignmentId = $satisfied ? $openId : null;

        $s = static fn($x): ?string => $x === null ? null : (string) $x;
        $clientId = (int) $contact['contact_client_id'];
        $row = [
            'completion_contact_id' => (string) $v['contact_id'],
            'completion_course_id' => (string) $v['course_id'],
            'completion_course_kind' => $kind,
            'completion_revision_id' => $revision === null ? null : (string) $revision['id'],
            'completion_revision_sha256' => $revision === null ? null : (string) $revision['sha256'],
            'completion_assignment_id' => $s($assignmentId),
            'completion_source_key' => $v['source_key'],
            'completion_method' => $v['method'],
            'completion_proof' => $v['proof'],
            'completion_completed_on' => $v['completed_on'],
            'completion_trained_on' => $v['trained_on'],
            'completion_evaluated_on' => $v['evaluated_on'],
            'completion_expires_on' => $expires,
            'completion_language' => $v['language'],
            'completion_score_pct' => $v['score_pct'],
            'completion_pass_mark_pct' => $s($v['pass_mark_pct']),
            'completion_attempts_used' => $s($v['attempts_used']),
            'completion_duration_minutes' => $s($v['duration_minutes']),
            'completion_run_id' => $s($v['run_id']),
            'completion_attempt_id' => $s($v['attempt_id']),
            'completion_tsession_id' => $s($v['tsession_id']),
            'completion_tattendee_id' => $s($v['tattendee_id']),
            'completion_evaluation_id' => $s($v['evaluation_id']),
            'completion_trainer_contact_id' => $s($v['trainer_contact_id']),
            'completion_trainer_user_id' => $s($v['trainer_user_id']),
            'completion_trainer_name' => $v['trainer_name'],
            'completion_evaluator_name' => $v['evaluator_name'],
            'completion_learner_tsig_id' => $s($v['learner_tsig_id']),
            'completion_trainer_tsig_id' => $s($v['trainer_tsig_id']),
            'completion_kiosk_id' => $s($v['kiosk_id']),
            'completion_asset_id' => $s($v['asset_id']),
            'completion_pin_source' => $v['pin_source'],
            'completion_odoo_employee_id' => $s($v['odoo_employee_id']),
            'completion_external_issuer' => $v['external_issuer'],
            'completion_external_ref' => $v['external_ref'],
            'completion_evidence_media_id' => $s($v['evidence_media_id']),
            'completion_recorded_by_user_id' => $this->c->userId > 0 ? (string) $this->c->userId : null,
            'completion_attestation_text' => $v['attestation_text'],
            'completion_notes' => $v['notes'],
            'completion_cert_number' => $certNumber,
            'completion_snap_contact_name' => (string) Text::clip((string) $contact['contact_name'], 200),
            'completion_snap_contact_title' => ($contact['contact_title'] ?? '') === '' ? null : Text::clip((string) $contact['contact_title'], 200),
            'completion_snap_client_id' => (string) $clientId,
            'completion_snap_client_name' => $clientId > 0 && ($contact['client_name'] ?? '') !== '' ? Text::clip((string) $contact['client_name'], 200) : null,
            'completion_snap_course_name' => (string) Text::clip((string) $course['name'], 200),
            'completion_snap_course_code' => $course['code'] === null ? null : Text::clip((string) $course['code'], 40),
            'completion_snap_revision_number' => $s($revNumber),
            'completion_snap_regulation_ref' => $course['regulation_ref'] === null ? null : Text::clip((string) $course['regulation_ref'], 100),
            'completion_supersedes_id' => $s($v['supersedes_id']),
            'completion_recorded_at_utc' => $recordedAt,
            'completion_hash_v' => '1',
        ];
        $ins = HashedInsert::insert($db, 'training_completions', $row);
        $id = $ins['id'];

        $actor = $this->actor($v);
        $events = [$actor + [
            'type' => 'completion.recorded',
            'subject_contact_id' => $v['contact_id'],
            'course_id' => $v['course_id'],
            'entity_type' => 'completion',
            'entity_id' => $id,
            'entity_sha256' => $ins['sha'],
            'payload' => [
                'course_id' => $v['course_id'],
                'method' => $v['method'],
                'proof' => $v['proof'],
                'cert_number' => $certNumber,
                'completed_on' => $v['completed_on'],
                'expires_on' => $expires,
                'source_key' => $v['source_key'],
                'assignment_id' => $assignmentId,
                'revision_id' => $revision === null ? null : (int) $revision['id'],
                'language' => $v['language'],
                'score_pct' => $v['score_pct'],
            ],
        ]];

        $token = null;
        if ($kind === 'training') {
            if ($this->certKey === '') {
                throw new \RuntimeException('cert_key_missing');
            }
            $tok = CertIssuer::issueToken($db, $id, $this->certKey, $v['contact_id'], $v['course_id'], $actor);
            $token = $tok['token'];
            $events[] = $tok['event'];
        }
        if ($satisfied) {
            $events[] = AssignmentStore::closeCompleted($db, $open, $id, $this->c->userId > 0 ? $this->c->userId : null, $actor);
        }

        if (!$deferEvents) {
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
        }

        $out = [
            'completion_id' => $id,
            'contact_id' => $v['contact_id'],
            'course_id' => $v['course_id'],
            'kind' => $kind,
            'cert_number' => $certNumber,
            'token' => $token,
            'verify_url' => $token === null ? null : CertIssuer::verifyUrl($this->c->baseUrl, $token),
            'assignment_id' => $assignmentId,
            'duplicate' => false,
        ];
        if ($deferEvents) {
            $out['events'] = $events;
        }
        return $out;
    }

    /** The open assignment's id, whichever key AssignmentStore uses. */
    private static function openId(array $open): ?int
    {
        $id = $open['id'] ?? $open['tassign_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    private static function openOnboarding(array $open): ?string
    {
        $v = array_key_exists('onboarding_from_on', $open) ? $open['onboarding_from_on'] : ($open['tassign_onboarding_from_on'] ?? null);
        return $v === null || $v === '' ? null : (string) $v;
    }

    /** Validates and normalizes issue() input into typed values (text-protocol friendly). */
    private function normalize(array $in): array
    {
        $int = static function (string $k, bool $required = false, int $min = 0, int $max = 2147483647) use ($in): ?int {
            $x = $in[$k] ?? null;
            if ($x === null || $x === '') {
                if ($required) {
                    throw ApiException::validation([$k => 'Required.']);
                }
                return null;
            }
            if (is_string($x) && preg_match('/^-?[0-9]{1,10}$/D', $x) === 1) {
                $x = (int) $x;
            }
            if (!is_int($x) || $x < $min || $x > $max) {
                throw ApiException::validation([$k => 'Not a valid value.']);
            }
            return $x;
        };
        $date = static function (string $k, bool $required = false) use ($in): ?string {
            $x = $in[$k] ?? null;
            if ($x === null || $x === '') {
                if ($required) {
                    throw ApiException::validation([$k => 'Required.']);
                }
                return null;
            }
            if (!is_string($x) || !Clock::isYmd($x)) {
                throw ApiException::validation([$k => 'Must be a date (YYYY-MM-DD).']);
            }
            return $x;
        };

        $v = [
            'contact_id' => $int('contact_id', true, 1),
            'course_id' => $int('course_id', true, 1),
            'completed_on' => $date('completed_on', true),
            'trained_on' => $date('trained_on'),
            'evaluated_on' => $date('evaluated_on'),
            'expires_on_override' => $date('expires_on_override'),
        ];
        $method = $in['method'] ?? null;
        if (!is_string($method) || !in_array($method, self::METHODS, true)) {
            throw ApiException::validation(['method' => 'Not a valid choice.']);
        }
        $proof = $in['proof'] ?? null;
        if (!is_string($proof) || !in_array($proof, self::PROOFS, true)) {
            throw ApiException::validation(['proof' => 'Not a valid choice.']);
        }
        $key = $in['source_key'] ?? null;
        if (!is_string($key) || preg_match(self::SOURCE_KEY_RE, $key) !== 1) {
            throw ApiException::validation(['source_key' => 'Not a valid request key.']);
        }
        $v += ['method' => $method, 'proof' => $proof, 'source_key' => $key];

        $v['pass_mark_pct'] = $int('pass_mark_pct', false, 0, 100);
        $v['attempts_used'] = $int('attempts_used', false, 0, 255);
        $v['duration_minutes'] = $int('duration_minutes', false, 0, 65535);
        foreach (self::INT_KEYS as $k) {
            if (!array_key_exists($k, $v)) {
                $v[$k] = $int($k, false, 1);
            }
        }

        $lang = $in['language'] ?? 'en';
        if (!is_string($lang) || preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,6})?$/D', $lang) !== 1) {
            throw ApiException::validation(['language' => 'Not a valid language.']);
        }
        $v['language'] = $lang;

        $score = $in['score_pct'] ?? null;
        if ($score !== null && (!is_string($score) || preg_match('/^(100\.00|[0-9]{1,2}\.[0-9]{2})$/D', $score) !== 1)) {
            throw ApiException::validation(['score_pct' => "Send the score as a '%.2f' string."]);
        }
        $v['score_pct'] = $score;

        $pin = $in['pin_source'] ?? null;
        if ($pin !== null && !in_array($pin, ['odoo', 'local'], true)) {
            throw ApiException::validation(['pin_source' => 'Not a valid choice.']);
        }
        $v['pin_source'] = $pin;

        foreach (self::TEXT_KEYS as $k => $max) {
            $x = $in[$k] ?? null;
            if ($x === null) {
                $v[$k] = null;
                continue;
            }
            if (!is_string($x) || !mb_check_encoding($x, 'UTF-8')) {
                throw ApiException::validation([$k => 'Must be text.']);
            }
            $x = trim($x);
            if (mb_strlen($x, 'UTF-8') > $max) {
                throw ApiException::validation([$k => "Too long (at most $max characters)."]);
            }
            $v[$k] = $x === '' ? null : $x;
        }

        $actorType = $in['actor_type'] ?? null;
        if ($actorType !== null && !in_array($actorType, self::ACTOR_TYPES, true)) {
            throw new \InvalidArgumentException('CompletionService: bad actor_type');
        }
        $v['actor_type'] = $actorType;
        return $v;
    }
}
