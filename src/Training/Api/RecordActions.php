<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\CertSecret;
use ITFlow\Training\Records\CompletionService;
use ITFlow\Training\Records\CompletionView;
use ITFlow\Training\Records\CourseFacts;
use ITFlow\Training\Records\EvaluationService;
use ITFlow\Training\Records\ExternalRecordService;
use ITFlow\Training\Records\SessionService;
use ITFlow\Training\Records\TrainerService;

/**
 * Records actions (Lane C; Phase 2 spec §4.2 "Routes/records.php"): completions, external and
 * paper records, voids, course components, sessions, practical evaluations and trainers.
 *
 * Authorization happens HERE, at the edge, in the spec §0 #2 order: the Router has already
 * enforced the route level (Access::api); then People\Scope::forCtx (fail-closed departments);
 * then Scope::assertContact for every person the request names (404 not_found, never 403).
 * The Records services never authorize. Own-record rules (RecordRules::notOwn) and the
 * evaluator/trainer checks run inside the services because they need the course facts.
 *
 * Every POST is JSON with the CSRF header (Router). Creates carry a client-generated
 * request_uid (32 hex) that is re-used on retry, so a double submit returns the first record.
 */
final class RecordActions
{
    private const UID_RE = '/^[0-9a-f]{32}$/D';

    // ---- completions ---------------------------------------------------------------------------

    /** GET completion_list (L1): the records log. */
    public static function completionList(Ctx $c, ApiContext $a): array
    {
        $f = [
            'q' => $a->str('q', 100, false),
            'course_id' => $a->int('course_id', false, 1),
            'client_id' => $a->int('client_id', false, 0),
            'contact_id' => $a->int('contact_id', false, 1),
            'method' => $a->enum('method', CompletionService::METHODS, false),
            'strength' => $a->enum('strength', ['A', 'B', 'C', 'D', 'E'], false),
            'voided' => $a->enum('voided', ['include', 'exclude', 'only', '0', '1'], false),
            'from' => $a->date('from', false),
            'to' => $a->date('to', false),
            'page' => $a->int('page', false, 1, 100000),
        ];
        return (new CompletionView($c))->list($f, Scope::forCtx($c));
    }

    /** GET completion_get (L1): CompletionDetail. */
    public static function completionGet(Ctx $c, ApiContext $a): array
    {
        return (new CompletionView($c))->detail((int) $a->int('completion_id', true, 1), Scope::forCtx($c));
    }

    /** POST completion_record (L2): office entry of an external card / paper record / paper acknowledgment. */
    public static function completionRecord(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $contactId = (int) $a->int('contact_id', true, 1);
        $scope->assertContact($c->db, $contactId);
        $uid = self::uid($a, true);

        $in = [
            'contact_id' => $contactId,
            'course_id' => (int) $a->int('course_id', true, 1),
            'method' => $a->enum('method', ['external', 'legacy_paper']),
            'issuer' => $a->str('issuer', 200, false),
            'ref' => $a->str('ref', 100, false),
            'trained_on' => $a->date('trained_on', false),
            'evaluated_on' => $a->date('evaluated_on', false),
            'completed_on' => $a->date('completed_on'),
            'expires_on' => $a->date('expires_on', false),
            'revision_id' => $a->int('revision_id', false, 1),
            'evidence_token' => $a->str('evidence_token', 64, false),
            'no_evidence_reason' => $a->str('no_evidence_reason', 255, false),
            'notes' => $a->str('notes', 2000, false),
            'request_uid' => $uid,
        ];
        $cs = self::completions($c);
        $r = (new ExternalRecordService($c, $cs))->record($in);

        $view = new CompletionView($c);
        $completion = $view->shapeMany([$view->load((int) $r['completion_id'], Scope::all())])[0];
        return [
            'completion' => $completion,
            'certificate_url' => $completion['certificate_url'],
            'duplicate' => (bool) $r['duplicate'],
            'reconcile' => $r['reconcile'] ?? null,
        ];
    }

    /** POST completion_void (L3): {completion_id, reason (>= 10)} => {void_id, reconcile}. */
    public static function completionVoid(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('completion_id', true, 1);
        $reason = (string) $a->str('reason', 1000);
        (new CompletionView($c))->load($id, Scope::forCtx($c));   // 404 when missing or out of scope
        $r = self::completions($c)->void($id, $reason);
        return ['void_id' => $r['void_id'], 'completion_id' => $r['completion_id'], 'reconcile' => $r['reconcile']];
    }

    /** GET course_components (L1): {course:CourseCard, checklist:[{item,critical}], revisions:[{id,number}]}. */
    public static function courseComponents(Ctx $c, ApiContext $a): array
    {
        $courseId = (int) $a->int('course_id', true, 1);
        $f = CourseFacts::load($c->db, $courseId);
        if ($f === null) {
            throw ApiException::notFound('That course was not found.');
        }
        $card = CourseFacts::card($f) + [
            'revision_id' => $f['revision']['id'] ?? null,
            'archived' => (bool) $f['archived'],
            'is_qualification' => (bool) $f['is_qualification'],
            'window_days' => (int) $f['window_days'],
        ];
        return [
            'course' => $card,
            'checklist' => array_map(static fn(array $i) => ['item' => $i['item'], 'critical' => (bool) $i['critical']], $f['checklist']),
            'revisions' => CourseFacts::revisions($c->db, $courseId),
        ];
    }

    // ---- sessions (S2) -------------------------------------------------------------------------

    /** GET session_list (L1). */
    public static function sessionList(Ctx $c, ApiContext $a): array
    {
        $f = [
            'status' => $a->enum('status', ['open', 'finalized', 'cancelled'], false),
            'course_id' => $a->int('course_id', false, 1),
            'from' => $a->date('from', false),
            'to' => $a->date('to', false),
            'page' => $a->int('page', false, 1, 100000),
        ];
        return self::sessions($c)->list($f, Scope::forCtx($c));
    }

    /** GET session_get (L1): Session. */
    public static function sessionGet(Ctx $c, ApiContext $a): array
    {
        return self::sessions($c)->get((int) $a->int('session_id', true, 1), Scope::forCtx($c));
    }

    /** POST session_save (L2): create (request_uid) or update (session_id + version) an open session. */
    public static function sessionSave(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $svc = self::sessions($c);
        $id = $a->int('session_id', false, 1);
        $version = null;
        if ($id !== null) {
            $svc->get($id, $scope);   // 404 unless visible
            $version = (int) $a->int('version', true, 0);
        }
        $attendees = [];
        $raw = $a->arr('attendees', false);
        if ($raw !== [] && !array_is_list($raw)) {
            throw ApiException::validation(['attendees' => 'Must be a list.']);
        }
        if (count($raw) > 500) {
            throw ApiException::validation(['attendees' => 'Too many people on one session.']);
        }
        foreach ($raw as $i => $row) {
            if (!is_array($row)) {
                throw ApiException::validation(['attendees' => 'Each attendee must be an object.']);
            }
            $r = new ApiContext('POST', $row);
            $cid = (int) $r->int('contact_id', true, 1);
            $scope->assertContact($c->db, $cid);
            $attendees[] = [
                'contact_id' => $cid,
                'attendance' => $r->enum('attendance', ['present', 'partial', 'absent'], false) ?? 'present',
                'practical' => $r->enum('practical', ['not_evaluated', 'pass', 'fail'], false) ?? 'not_evaluated',
                'proof' => $r->enum('proof', SessionService::AGENT_PROOFS, false) ?? 'document',
                'attest_reason' => $r->str('attest_reason', 255, false),
                'notes' => $r->str('notes', 500, false),
            ];
        }
        $data = [
            'request_uid' => $id === null ? self::uid($a, false) : null,
            'course_id' => (int) $a->int('course_id', true, 1),
            'held_on' => $a->date('held_on'),
            'start_time' => $a->str('start_time', 8, false),
            'duration_minutes' => $a->int('duration_minutes', false, 0, 1440),
            'client_id' => $a->int('client_id', false, 0) ?? 0,
            'location' => $a->str('location', 200, false),
            'topic' => $a->str('topic', 200, false),
            'notes' => $a->str('notes', 5000, false),
            'trainer_contact_id' => $a->int('trainer_contact_id', false, 1),
            'trainer_name' => $a->str('trainer_name', 200, false),
            'evidence_token' => $a->str('evidence_token', 64, false),
            'remove_reason' => $a->str('remove_reason', 255, false),
            'attendees' => $attendees,
        ];
        return $svc->save($id, $version, $data, $scope);
    }

    /** POST session_finalize (L2): {session_id, version, attest:true} => {session, issued, pending, reconcile}. */
    public static function sessionFinalize(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $id = (int) $a->int('session_id', true, 1);
        $svc = self::sessions($c);
        $svc->get($id, $scope);
        return $svc->finalize($id, (int) $a->int('version', true, 0), $a->bool('attest', false) === true, $scope);
    }

    /** POST session_cancel (L2): {session_id, reason} => Session. */
    public static function sessionCancel(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $id = (int) $a->int('session_id', true, 1);
        $svc = self::sessions($c);
        $svc->get($id, $scope);
        return $svc->cancel($id, (string) $a->str('reason', 255), $scope);
    }

    // ---- evaluations (S1) ----------------------------------------------------------------------

    /** POST evaluation_record (L2) => {evaluation_id, result, completion_id|null, cert_number|null, pending|null, duplicate}. */
    public static function evaluationRecord(Ctx $c, ApiContext $a): array
    {
        $scope = Scope::forCtx($c);
        $contactId = (int) $a->int('contact_id', true, 1);
        $scope->assertContact($c->db, $contactId);
        $in = [
            'contact_id' => $contactId,
            'course_id' => (int) $a->int('course_id', true, 1),
            'evaluator_contact_id' => $a->int('evaluator_contact_id', false, 1),
            'evaluator_name' => $a->str('evaluator_name', 200, false),
            'evaluated_on' => $a->date('evaluated_on'),
            'equipment' => $a->str('equipment', 200, false),
            'checklist' => $a->arr('checklist', false),
            'result' => $a->enum('result', ['pass', 'fail']),
            'notes' => $a->str('notes', 2000, false),
            'evidence_token' => $a->str('evidence_token', 64, false),
            'request_uid' => self::uid($a, true),
        ];
        return (new EvaluationService($c, self::completions($c)))->record($in);
    }

    // ---- trainers (S4) -------------------------------------------------------------------------

    /** GET trainer_list (L1): {trainers:[Trainer]} (in scope). */
    public static function trainerList(Ctx $c, ApiContext $a): array
    {
        return ['trainers' => (new TrainerService($c))->list(Scope::forCtx($c))];
    }

    /** POST trainer_save (L3) => Trainer. */
    public static function trainerSave(Ctx $c, ApiContext $a): array
    {
        $contactId = (int) $a->int('contact_id', true, 1);
        Scope::forCtx($c)->assertContact($c->db, $contactId);
        $flagsIn = $a->arr('flags', false);
        $flags = [];
        foreach (TrainerService::FLAGS as $k) {
            $flags[$k] = (new ApiContext('POST', is_array($flagsIn) ? $flagsIn : []))->bool($k, false) === true;
        }
        $f = [
            'title' => $a->str('title', 100, false),
            'flags' => $flags,
            'all_courses' => $a->bool('all_courses', false) === true,
            'all_departments' => $a->bool('all_departments', false) === true,
            'course_ids' => $a->ints('course_ids'),
            'client_ids' => $a->ints('client_ids'),
            'qualifications' => $a->str('qualifications', 2000, false),
            'active' => $a->bool('active', true) === true,
        ];
        return (new TrainerService($c))->save($contactId, $a->int('version', false, 0), $f);
    }

    // --------------------------------------------------------------------------------------------

    /** The records writer for this request. The certificate key is read only here, at the boundary. */
    private static function completions(Ctx $c): CompletionService
    {
        try {
            $key = CertSecret::fromGlobals();
        } catch (\RuntimeException $e) {
            // Documents need no key; a training-kind issue then fails closed with cert_key_missing.
            error_log('Training records: certificate key unavailable: ' . $e->getMessage());
            $key = '';
        }
        return new CompletionService($c, $key);
    }

    private static function sessions(Ctx $c): SessionService
    {
        return new SessionService($c, self::completions($c));
    }

    private static function uid(ApiContext $a, bool $required): ?string
    {
        $uid = $a->str('request_uid', 32, $required);
        if ($uid !== null && preg_match(self::UID_RE, $uid) !== 1) {
            throw ApiException::validation(['request_uid' => 'Reload the form and try again.']);
        }
        return $uid;
    }
}
