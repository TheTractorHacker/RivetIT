<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashedInsert;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsMutex;
use ITFlow\Training\Core\Text;

/**
 * Practical evaluations recorded in the office (S1; v0 §6.5; Phase 2 spec §3.5).
 *
 * The evaluator is a trainer contact who may evaluate this course (active, can_evaluate, all
 * courses or listed for it), or an outside evaluator named in free text. Nobody evaluates
 * themselves (422 evaluator_is_learner). The checklist must be exactly the current revision's
 * eval_checklist (same items, same order, same critical flags; 422 checklist_mismatch), and a
 * failed critical item forces the result to fail. The scanned, signed checklist is REQUIRED on
 * this channel (422 evidence_required), so the proof is 'document'.
 *
 * One transaction: records mutex (first) -> source key 'agt:<request_uid>' -> HashedInsert
 * evaluation -> on a pass, CompletionService::tryIssueComponents(deferEvents) -> events LAST
 * [evaluation.recorded, completion events...]. After commit the completion's afterCommit runs.
 */
final class EvaluationService
{
    public function __construct(private readonly Ctx $c, private readonly CompletionService $completions)
    {
    }

    /**
     * @param array{contact_id:int, course_id:int, evaluator_contact_id:?int, evaluator_name:?string, evaluated_on:string,
     *   equipment:?string, checklist:list<array{item:string, critical:bool, result:string}>, result:string, notes:?string,
     *   evidence_token:?string, request_uid:string} $in (typed by the Action)
     * @return array{evaluation_id:int, result:string, completion_id:?int, cert_number:?string, pending:?string, duplicate:bool}
     */
    public function record(array $in): array
    {
        $db = $this->c->db;
        $contactId = (int) $in['contact_id'];
        $courseId = (int) $in['course_id'];
        $uid = (string) ($in['request_uid'] ?? '');
        if (preg_match('/^[0-9a-f]{32}$/D', $uid) !== 1) {
            throw ApiException::validation(['request_uid' => 'Reload the form and try again.']);
        }
        $course = CourseFacts::load($db, $courseId);
        if ($course === null) {
            throw ApiException::notFound('That course was not found.');
        }
        if ($course['revision'] === null) {
            throw new ApiException(422, 'course_unpublished', 'This course has not been published yet.', ['course_id' => 'Not published.']);
        }
        if (!$course['needs']['practical']) {
            throw ApiException::validation(['course_id' => 'This course has no practical evaluation.']);
        }
        $notes = self::text($in['notes'] ?? null);
        RecordRules::notOwn($this->c, $db, $contactId, $notes);
        $evaluatedOn = (string) ($in['evaluated_on'] ?? '');
        RecordRules::dates(['evaluated_on' => $evaluatedOn], Clock::todayLocal());

        // Evaluator: a trainer allowed for this course, or an outside evaluator by name.
        $evaluatorContactId = isset($in['evaluator_contact_id']) && $in['evaluator_contact_id'] !== null ? (int) $in['evaluator_contact_id'] : null;
        $evaluatorUserId = null;
        if ($evaluatorContactId !== null) {
            if ($evaluatorContactId === $contactId) {
                throw new ApiException(422, 'evaluator_is_learner', 'Someone else must evaluate this person.', ['evaluator_contact_id' => 'Cannot be the person evaluated.']);
            }
            $t = TrainerService::evaluatorFor($db, $evaluatorContactId, $courseId);
            if ($t === null) {
                throw new ApiException(422, 'evaluator_not_allowed', 'That person is not set up to evaluate this course (People › Trainers).',
                    ['evaluator_contact_id' => 'Not an evaluator for this course.']);
            }
            $evaluatorName = $t['name'];
            $evaluatorUserId = $t['user_id'];
        } else {
            $evaluatorName = self::text($in['evaluator_name'] ?? null);
            if ($evaluatorName === null) {
                throw ApiException::validation(['evaluator_name' => 'Choose the evaluator, or type the outside evaluator\'s name.']);
            }
            if (mb_strlen($evaluatorName, 'UTF-8') > 200) {
                throw ApiException::validation(['evaluator_name' => 'Too long (at most 200 characters).']);
            }
            $learner = Db::one($db, 'SELECT contact_name FROM contacts WHERE contact_id = ?', 'i', [$contactId]);
            if ($learner !== null && mb_strtolower(trim((string) $learner['contact_name']), 'UTF-8') === mb_strtolower($evaluatorName, 'UTF-8')) {
                throw new ApiException(422, 'evaluator_is_learner', 'Someone else must evaluate this person.', ['evaluator_name' => 'Cannot be the person evaluated.']);
            }
        }
        if ($evaluatorUserId !== null) {
            // The same person under another contact row: the learner's login is the evaluator's.
            $lu = Db::one($db, 'SELECT contact_user_id FROM contacts WHERE contact_id = ?', 'i', [$contactId]);
            if ($lu !== null && (int) $lu['contact_user_id'] > 0 && (int) $lu['contact_user_id'] === $evaluatorUserId) {
                throw new ApiException(422, 'evaluator_is_learner', 'Someone else must evaluate this person.', ['evaluator_contact_id' => 'Cannot be the person evaluated.']);
            }
        }

        $checklist = self::checklist($in['checklist'] ?? [], $course['checklist']);
        $result = (string) ($in['result'] ?? '');
        if (!in_array($result, ['pass', 'fail'], true)) {
            throw ApiException::validation(['result' => 'Choose Pass or Fail.']);
        }
        foreach ($checklist as $row) {
            if ($row['critical'] && $row['result'] === 'fail') {
                $result = 'fail';   // any failed critical item fails the evaluation
            }
        }
        $mediaId = EvidenceStore::resolveToken($this->c, isset($in['evidence_token']) ? (string) $in['evidence_token'] : null);
        if ($mediaId === null) {
            throw new ApiException(422, 'evidence_required', 'Attach the scanned, signed checklist.', ['evidence_token' => 'Required.']);
        }
        $equipment = self::text($in['equipment'] ?? null);
        if ($equipment !== null && mb_strlen($equipment, 'UTF-8') > 200) {
            throw ApiException::validation(['equipment' => 'Too long (at most 200 characters).']);
        }

        $sourceKey = 'agt:' . $uid;
        $actor = $this->completions->actor([]);
        $out = Db::tx($db, function () use ($db, $sourceKey, $contactId, $courseId, $course, $evaluatorContactId, $evaluatorUserId, $evaluatorName,
                                           $evaluatedOn, $result, $equipment, $checklist, $notes, $mediaId, $actor): array {
            RecordsMutex::acquire($db);
            $hit = Db::one($db, 'SELECT evaluation_id, evaluation_contact_id, evaluation_course_id, evaluation_result FROM training_evaluations
                WHERE evaluation_source_key = ? FOR UPDATE', 's', [$sourceKey]);
            if ($hit !== null) {
                if ((int) $hit['evaluation_contact_id'] !== $contactId || (int) $hit['evaluation_course_id'] !== $courseId) {
                    throw new ApiException(409, 'source_key_conflict', 'That request was already used for a different record. Reload and try again.');
                }
                $done = Db::one($db, 'SELECT completion_id, completion_cert_number FROM training_completions WHERE completion_evaluation_id = ? ORDER BY completion_id LIMIT 1',
                    'i', [(int) $hit['evaluation_id']]);
                return ['evaluation_id' => (int) $hit['evaluation_id'], 'result' => (string) $hit['evaluation_result'],
                        'completion_id' => $done === null ? null : (int) $done['completion_id'],
                        'cert_number' => $done['completion_cert_number'] ?? null, 'pending' => null, 'duplicate' => true, 'issue' => null];
            }
            $ins = HashedInsert::insert($db, 'training_evaluations', [
                'evaluation_source_key' => $sourceKey,
                'evaluation_contact_id' => (string) $contactId,
                'evaluation_course_id' => (string) $courseId,
                'evaluation_revision_id' => (string) $course['revision']['id'],
                'evaluation_run_id' => null,
                'evaluation_tsession_id' => null,
                'evaluation_channel' => 'agent',
                'evaluation_evaluator_contact_id' => $evaluatorContactId === null ? null : (string) $evaluatorContactId,
                'evaluation_evaluator_user_id' => $evaluatorUserId === null ? null : (string) $evaluatorUserId,
                'evaluation_evaluator_name' => (string) Text::clip($evaluatorName, 200),
                'evaluation_evaluated_on' => $evaluatedOn,
                'evaluation_result' => $result,
                'evaluation_equipment' => $equipment,
                'evaluation_checklist_json' => Canonical::doc($checklist),
                'evaluation_notes' => $notes,
                'evaluation_proof' => 'document',
                'evaluation_evaluator_tsig_id' => null,
                'evaluation_evaluatee_tsig_id' => null,
                'evaluation_evidence_media_id' => (string) $mediaId,
                'evaluation_kiosk_id' => null,
                'evaluation_recorded_by_user_id' => $this->c->userId > 0 ? (string) $this->c->userId : null,
                'evaluation_recorded_at_utc' => Clock::nowUtc(),
                'evaluation_hash_v' => '1',
            ]);
            $events = [$actor + [
                'type' => 'evaluation.recorded',
                'subject_contact_id' => $contactId,
                'course_id' => $courseId,
                'entity_type' => 'evaluation',
                'entity_id' => $ins['id'],
                'entity_sha256' => $ins['sha'],
                'payload' => ['course_id' => $courseId, 'result' => $result, 'evaluated_on' => $evaluatedOn, 'channel' => 'agent', 'tsession_id' => null],
            ]];
            $issued = null;
            $pending = null;
            if ($result === 'pass') {
                $issued = $this->completions->tryIssueComponents($contactId, $courseId, true);
                if ($issued === null) {
                    $pending = $this->completions->lastPendingReason();
                } else {
                    foreach ($issued['events'] ?? [] as $e) {
                        $events[] = $e;
                    }
                }
            }
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
            return ['evaluation_id' => $ins['id'], 'result' => $result,
                    'completion_id' => $issued === null ? null : (int) $issued['completion_id'],
                    'cert_number' => $issued['cert_number'] ?? null, 'pending' => $pending, 'duplicate' => false, 'issue' => $issued];
        });

        if (!$out['duplicate']) {
            $summary = 'Recorded practical evaluation #' . $out['evaluation_id'] . " ({$out['result']}) for contact #$contactId";
            AfterCommit::log('Create', $summary, $out['evaluation_id']);
            if ($out['issue'] !== null) {
                unset($out['issue']['events']);
                $this->completions->afterCommit($out['issue']);
            }
        }
        unset($out['issue']);
        return $out;
    }

    /**
     * The submitted checklist must be the revision's items in order (item text and critical flag),
     * each with a pass/fail result.
     *
     * @param list<array{item:string, critical:bool}> $expected
     * @return list<array{critical:bool, item:string, result:string}>
     */
    public static function checklist(mixed $given, array $expected): array
    {
        $mismatch = new ApiException(422, 'checklist_mismatch', 'The checklist changed. Reload the form and fill it in again.', ['checklist' => 'Does not match the course checklist.']);
        if (!is_array($given) || !array_is_list($given) || count($given) !== count($expected)) {
            throw $mismatch;
        }
        $out = [];
        foreach ($expected as $i => $exp) {
            $g = $given[$i] ?? null;
            if (!is_array($g) || !is_string($g['item'] ?? null) || trim($g['item']) !== $exp['item']
                || (bool) ($g['critical'] ?? false) !== (bool) $exp['critical']) {
                throw $mismatch;
            }
            $r = $g['result'] ?? null;
            if (!in_array($r, ['pass', 'fail'], true)) {
                throw ApiException::validation(['checklist' => 'Mark every item Pass or Fail.']);
            }
            $out[] = ['critical' => (bool) $exp['critical'], 'item' => $exp['item'], 'result' => $r];
        }
        return $out;
    }

    private static function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
