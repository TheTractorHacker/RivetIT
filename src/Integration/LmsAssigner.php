<?php

namespace ITFlow\Integration;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Assign\RequirementService;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * POST /api/v1/lms/assignments: create (or reuse) a training assignment on behalf of an integration client.
 *
 * Assignments are created ONLY through the existing assignment engine (RequirementService::createManual -> AssignmentService::reconcile), so
 * the hash-chained ledger gets its requirement.saved / assignment.created events and the open-assignment unique guard
 * (uq_training_assign_open) is honoured exactly as for a person using "Assign training" in the web UI. The caller is not a user: the
 * service context has user id 0 (ledger actor "system") and the rule is named after the client.
 *
 * Results: ['status' => 201|200, 'assignment' => shape, 'reused' => bool]. Failures throw LmsApiException.
 */
final class LmsAssigner
{
    public function __construct(private readonly \mysqli $db, private readonly LmsApi $api)
    {
    }

    /**
     * @param array<string,mixed> $in      decoded JSON body
     * @param array<string,mixed> $client  integration_clients row
     * @return array{status:int, assignment:array, reused:bool}
     */
    public function assign(array $in, array $client, string $idempotencyKey): array
    {
        $allowed = ['employee', 'course_id', 'due_on', 'source', 'external_system', 'external_ref'];
        foreach (array_keys($in) as $k) {
            if (!in_array($k, $allowed, true)) {
                throw new LmsApiException(422, 'unknown_field', 'Unknown field "' . substr((string) $k, 0, 40) . '".');
            }
        }
        $contactId = $this->resolveEmployee($in['employee'] ?? null);

        $uid = $in['course_id'] ?? null;
        if (!is_string($uid) || preg_match('/^[A-Za-z0-9]{12}$/D', $uid) !== 1) {
            throw new LmsApiException(422, 'invalid_course_id', 'course_id must be a course id from GET /lms/courses.');
        }
        $course = Db::one($this->db, 'SELECT course_id, course_current_revision_id, course_archived_at FROM training_courses WHERE course_uid = ?', 's', [$uid]);
        if ($course === null) {
            throw new LmsApiException(422, 'course_not_found', 'No such course.');
        }
        if ($course['course_current_revision_id'] === null || $course['course_archived_at'] !== null) {
            throw new LmsApiException(422, 'course_not_published', 'The course has no published revision (or is archived), so it cannot be assigned.');
        }
        $courseId = (int) $course['course_id'];

        $dueOn = $in['due_on'] ?? null;
        if (!is_string($dueOn) || !Clock::isYmd($dueOn)) {
            throw new LmsApiException(422, 'invalid_due_on', 'due_on must be a date, YYYY-MM-DD.');
        }
        if ($dueOn < Clock::todayLocal()) {
            throw new LmsApiException(422, 'due_on_in_past', 'due_on must be today or later.');
        }
        $label = '/^[A-Za-z0-9][A-Za-z0-9 ._:\/#-]{0,39}$/D';
        $source = $in['source'] ?? null;
        if ($source !== null && (!is_string($source) || preg_match($label, $source) !== 1)) {
            throw new LmsApiException(422, 'invalid_source', 'source must be a short label (letters, digits, space . _ : / # -).');
        }
        $extSystem = $in['external_system'] ?? null;
        $extRef = $in['external_ref'] ?? null;
        if (($extSystem === null) !== ($extRef === null)) {
            throw new LmsApiException(422, 'external_pair_required', 'Send external_system and external_ref together, or neither.');
        }
        if ($extSystem !== null) {
            if (!is_string($extSystem) || preg_match($label, $extSystem) !== 1) {
                throw new LmsApiException(422, 'invalid_external_system', 'external_system must be a short label.');
            }
            if (!is_string($extRef) || preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:\/#@+-]{0,119}$/D', $extRef) !== 1) {
                throw new LmsApiException(422, 'invalid_external_ref', 'external_ref must be 1 to 120 characters (letters, digits, space . _ : / # @ + -).');
            }
        }

        // The external reference maps to exactly one assignment.
        if ($extSystem !== null) {
            $byRef = $this->api->assignmentByExternal($extSystem, $extRef);
            if ($byRef !== null) {
                if ($byRef['employee']['rivetit_id'] !== (string) $contactId || $byRef['course_id'] !== $uid) {
                    throw new LmsApiException(409, 'external_ref_conflict', 'That external_ref is already mapped to a different employee or course.');
                }
                return ['status' => 200, 'assignment' => $byRef, 'reused' => true];
            }
        }
        // Reuse an open assignment of the pair (from a rule, another request or the web UI).
        $open = $this->api->openAssignmentFor($contactId, $courseId);
        if ($open !== null) {
            return ['status' => 200, 'assignment' => $this->attachReference($open, $extSystem, $extRef), 'reused' => true];
        }

        $ctx = LmsApi::serviceCtx($this->db, 'rivetit-lms-api:' . $client['client_uid']);
        $note = mb_substr('Via integration client ' . $client['client_name'] . ($source !== null ? '; source ' . $source : '')
            . ($extSystem !== null ? '; ' . $extSystem . ' ' . $extRef : ''), 0, 500, 'UTF-8');
        $requestUid = substr(hash('sha256', $client['client_uid'] . '|' . $idempotencyKey . '|' . $contactId . '|' . $courseId), 0, 32);
        try {
            $r = (new RequirementService($ctx))->createManual([$contactId], $courseId, $dueOn, $note, $requestUid,
                'integration client ' . $client['client_name']);
        } catch (ApiException $e) {
            throw new LmsApiException($e->http >= 400 && $e->http < 500 ? $e->http : 500, $e->errCode === 'validation' ? 'invalid_request' : $e->errCode, $e->getMessage());
        }
        $outcome = $r['outcomes'][0]['outcome'] ?? 'unknown';
        switch ($outcome) {
            case 'not_eligible':
                throw new LmsApiException(422, 'employee_not_eligible', 'The employee is not eligible for training (inactive, excluded from the roster or not in Training).');
            case 'waived':
                throw new LmsApiException(409, 'assignment_waived', 'This course is waived for the employee.');
            case 'current':
                throw new LmsApiException(409, 'already_current', 'The employee already holds a current completion for this course; no assignment is needed.');
        }
        $created = $this->api->openAssignmentFor($contactId, $courseId);
        if ($created === null) {
            if (($r['reconcile']['error'] ?? null) === 'busy') {
                throw new LmsApiException(503, 'busy', 'The assignment engine is busy; retry shortly.', ['Retry-After' => '2']);
            }
            throw new LmsApiException(409, 'not_assigned', 'The assignment engine did not create an assignment (outcome: ' . $outcome . ').');
        }
        // 'already_assigned' here means a concurrent request or rule got there first: the same reuse answer.
        $reused = $outcome !== 'assigned';
        return ['status' => $reused ? 200 : 201, 'assignment' => $this->attachReference($created, $extSystem, $extRef), 'reused' => $reused];
    }

    /** Sets the external pair on an assignment that has none yet; a different existing pair is left alone. */
    private function attachReference(array $assignment, ?string $system, ?string $ref): array
    {
        if ($system === null || $assignment['external_system'] !== null) {
            return $assignment;
        }
        $id = (int) substr($assignment['id'], 4);
        try {
            Db::exec($this->db, 'UPDATE training_assignments SET tassign_external_system = ?, tassign_external_ref = ?
                WHERE tassign_id = ? AND tassign_external_system IS NULL', 'ssi', [$system, $ref, $id]);
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                throw new LmsApiException(409, 'external_ref_conflict', 'That external_ref is already mapped to a different assignment.');
            }
            throw $e;
        }
        return $this->api->assignment($id) ?? $assignment;
    }

    /** Employee by Odoo employee id or RivetIT contact id, validated against the link table / contacts; never by name. */
    private function resolveEmployee(mixed $e): int
    {
        if (!is_array($e) || array_is_list($e) || $e === []) {
            throw new LmsApiException(422, 'invalid_employee', 'employee must be an object with odoo_employee_id and/or rivetit_id.');
        }
        foreach (array_keys($e) as $k) {
            if (!in_array($k, ['odoo_employee_id', 'rivetit_id'], true)) {
                throw new LmsApiException(422, 'invalid_employee', 'employee accepts only odoo_employee_id and rivetit_id (people are never matched by name).');
            }
        }
        $found = [];
        if (array_key_exists('rivetit_id', $e)) {
            $v = $e['rivetit_id'];
            if ((!is_string($v) && !is_int($v)) || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $v) !== 1) {
                throw new LmsApiException(422, 'invalid_employee', 'rivetit_id must be a RivetIT employee id.');
            }
            $row = Db::one($this->db, 'SELECT contact_id FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL', 'i', [(int) $v]);
            if ($row === null) {
                throw new LmsApiException(422, 'employee_not_found', 'No such employee.');
            }
            $found[(int) $row['contact_id']] = true;
        }
        if (array_key_exists('odoo_employee_id', $e)) {
            $v = $e['odoo_employee_id'];
            if ((!is_string($v) && !is_int($v)) || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $v) !== 1) {
                throw new LmsApiException(422, 'invalid_employee', 'odoo_employee_id must be an Odoo employee id.');
            }
            $rows = Db::all($this->db, 'SELECT DISTINCT l.contact_id FROM contact_odoo_links l JOIN contacts c ON c.contact_id = l.contact_id
                WHERE l.odoo_employee_id = ? AND c.contact_archived_at IS NULL', 'i', [(int) $v]);
            if ($rows === []) {
                throw new LmsApiException(422, 'employee_not_found', 'No employee is linked to that odoo_employee_id.');
            }
            if (count($rows) > 1) {
                throw new LmsApiException(422, 'employee_ambiguous', 'That odoo_employee_id is linked to more than one employee; send rivetit_id.');
            }
            $cid = (int) $rows[0]['contact_id'];
            if ($found !== [] && !isset($found[$cid])) {
                throw new LmsApiException(422, 'employee_mismatch', 'odoo_employee_id and rivetit_id identify different employees.');
            }
            $found[$cid] = true;
        }
        return (int) array_key_first($found);
    }
}
