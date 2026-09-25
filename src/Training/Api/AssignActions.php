<?php

namespace ITFlow\Training\Api;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Assign\RequirementService;
use ITFlow\Training\Compliance\ComplianceService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;

/**
 * JSON handlers for rules, assignments and person status (Routes/assign.php; Phase 2 spec §4.2).
 * Authorization at the edge (§0 #2): the Router enforces the level; these apply Scope::forCtx and
 * assertContact()/assertContacts() for every person touched. Services never authorize.
 */
final class AssignActions
{
    /** GET course_id?, include_archived?, manual? -> {rules:[Rule]} (stats over the caller's scope). */
    public static function ruleList(Ctx $c, ApiContext $a): array
    {
        return ['rules' => (new RequirementService($c))->list([
            'course_id' => $a->int('course_id', false, 1),
            'include_archived' => (bool) $a->bool('include_archived', false),
            'manual' => $a->bool('manual', null),
        ], Scope::forCtx($c))];
    }

    /** GET requirement_id -> Rule. */
    public static function ruleGet(Ctx $c, ApiContext $a): array
    {
        return (new RequirementService($c))->get((int) $a->int('requirement_id', true, 1), Scope::forCtx($c));
    }

    /** POST draft (L3) -> Preview. sample_limit (1-1000, default 25) sizes the sample for "Show all". */
    public static function rulePreview(Ctx $c, ApiContext $a): array
    {
        return (new RequirementService($c))->preview(self::draft($a) + [
            'requirement_id' => $a->int('requirement_id', false, 1),
            'sample_limit' => $a->int('sample_limit', false, 1, 1000) ?? RequirementService::SAMPLE,
        ]);
    }

    /** POST (L3) -> {rule, duplicate, reconcile}. */
    public static function ruleSave(Ctx $c, ApiContext $a): array
    {
        $id = $a->int('requirement_id', false, 1);
        $draft = self::draft($a);
        if ($id === null) {
            $draft['request_uid'] = $a->str('request_uid', 32);
        }
        $name = $a->str('name', 150);
        $draft['name'] = $name;
        $r = (new RequirementService($c))->save($id, $a->int('version', false, 0), $draft);
        if (empty($r['duplicate'])) {
            CourseActions::log($id === null ? 'Create' : 'Edit', ($id === null ? 'Created' : 'Edited') . " training requirement rule '" . $r['rule']['name'] . "'",
                (int) $r['rule']['id']);
            self::audit($c, 'training.requirement_saved', 'training_requirement', (int) $r['rule']['id'], $id === null ? 'create' : 'edit',
                'Requirement rule ' . ($id === null ? 'created' : 'edited') . ': ' . $r['rule']['name'],
                ['course_id' => $r['rule']['course']['id'] ?? null, 'version' => $r['rule']['version']]);
        }
        return $r;
    }

    /** POST {requirement_id, reason} (L3) -> {reconcile}. */
    public static function ruleArchive(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('requirement_id', true, 1);
        $r = (new RequirementService($c))->archive($id, (string) $a->str('reason', 255));
        CourseActions::log('Archive', 'Archived training requirement rule #' . $id, $id);
        return $r;
    }

    /** POST {request_uid, contact_ids (1-200, each in scope), course_id, due_on (>= today), note?} (L2) -> {rule_id, outcomes, reconcile}. */
    public static function assignManual(Ctx $c, ApiContext $a): array
    {
        $ids = $a->ints('contact_ids');
        if ($ids === [] || count($ids) > 200) {
            throw ApiException::validation(['contact_ids' => 'Choose 1 to 200 people.']);
        }
        Scope::forCtx($c)->assertContacts($c->db, $ids);
        $r = (new RequirementService($c))->createManual($ids, (int) $a->int('course_id', true, 1), (string) $a->date('due_on'),
            $a->str('note', 500, false), (string) $a->str('request_uid', 32));
        if (empty($r['duplicate'])) {
            CourseActions::log('Create', 'Assigned training course #' . $a->int('course_id') . ' to ' . count($ids) . ' people by hand', $r['rule_id']);
        }
        return $r;
    }

    /** GET filters (§3.3) -> {rows:[Assignment], total, page, per_page, counts}. */
    public static function assignmentList(Ctx $c, ApiContext $a): array
    {
        return (new AssignmentService($c))->list([
            'status' => $a->enum('status', AssignmentService::STATUS_FILTERS, false),
            'course_id' => $a->int('course_id', false, 1),
            'client_id' => $a->int('client_id', false, 0),
            'contact_id' => $a->int('contact_id', false, 1),
            'requirement_id' => $a->int('requirement_id', false, 1),
            'q' => $a->str('q', 100, false),
            'page' => $a->int('page', false, 1, 100000),
            'sort' => $a->enum('sort', AssignmentService::SORT_KEYS, false),
        ], Scope::forCtx($c));
    }

    /** GET assignment_id -> {assignment, history}. */
    public static function assignmentGet(Ctx $c, ApiContext $a): array
    {
        return (new AssignmentService($c))->get((int) $a->int('assignment_id', true, 1), Scope::forCtx($c));
    }

    /** POST {assignment_id, due_on, reason (>= 5)} (L2) -> Assignment. */
    public static function assignmentExtend(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('assignment_id', true, 1);
        $svc = new AssignmentService($c);
        $svc->get($id, Scope::forCtx($c));   // 404 when out of scope
        $row = $svc->extend($id, (string) $a->date('due_on'), (string) $a->str('reason', 500));
        CourseActions::log('Edit', 'Extended training assignment #' . $id . ' to ' . $row['due_on'], $id);
        return $row;
    }

    /** POST {assignment_id, until?, reason (>= 5)} (L2) -> Assignment. */
    public static function assignmentWaive(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('assignment_id', true, 1);
        $svc = new AssignmentService($c);
        $svc->get($id, Scope::forCtx($c));
        $until = $a->date('until', false);
        $reason = (string) $a->str('reason', 500);
        $row = $svc->waive($id, $until, $reason);
        CourseActions::log('Edit', 'Waived training assignment #' . $id . ($until !== null ? ' until ' . $until : ''), $id);
        self::audit($c, 'training.assignment_waived', 'training_assignment', $id, 'waive', 'Training assignment waived' . ($until !== null ? ' until ' . $until : ''),
            ['until' => $until, 'course_id' => $row['course']['id'] ?? null, 'contact_id' => $row['person']['contact_id'] ?? null]);
        return $row;
    }

    /** POST (L2) -> reconcile stats (skipped_busy:true when another run holds the lock). */
    public static function reconcileNow(Ctx $c, ApiContext $a): array
    {
        return (new AssignmentService($c))->reconcile(null, 'reconcile_now');
    }

    /** GET contact_id -> personStatus(). */
    public static function personStatus(Ctx $c, ApiContext $a): array
    {
        $contactId = (int) $a->int('contact_id', true, 1);
        $s = Scope::forCtx($c);
        $s->assertContact($c->db, $contactId);
        return (new ComplianceService($c, $s, RecordsSettings::fromDb($c->db)))->personStatus($contactId);
    }

    // ------------------------------------------------------------------------------------------

    private static function draft(ApiContext $a): array
    {
        $criteria = $a->arr('criteria', false);
        if ($criteria !== [] && array_is_list($criteria)) {
            throw ApiException::validation(['criteria' => 'Must be an object of condition lists.']);
        }
        foreach (array_keys($criteria) as $k) {
            if (!in_array((string) $k, \ITFlow\Training\Assign\RuleMatcher::KINDS, true)) {
                throw ApiException::validation(['criteria' => 'Unknown condition: ' . mb_substr((string) $k, 0, 40)]);
            }
            if (!is_array($criteria[$k]) || !array_is_list($criteria[$k])) {
                throw ApiException::validation(['criteria' => 'Each condition must be a list of ids.']);
            }
        }
        return [
            'course_id' => (int) $a->int('course_id', true, 1),
            'all_people' => (bool) $a->bool('all_people', false),
            'criteria' => $criteria,
            'new_hires_only' => (bool) $a->bool('new_hires_only', false),
            'due_days' => $a->int('due_days', false, 0, 365) ?? 30,
            'baseline_due_on' => $a->date('baseline_due_on', false) ?? \ITFlow\Training\Core\Clock::addDays(\ITFlow\Training\Core\Clock::todayLocal(), 30),
            'due_days_from_hire' => $a->int('due_days_from_hire', false, 0, 365) ?? 7,
            'one_time' => (bool) $a->bool('one_time', false),
            'required' => (bool) $a->bool('required', true),
            'note' => $a->str('note', 500, false),
        ];
    }

    /** AuditService after commit; best-effort. */
    private static function audit(Ctx $c, string $event, string $entityType, int $entityId, string $action, string $summary, array $meta = []): void
    {
        try {
            (new AuditService($c->db))->log($event, $c->userId, $entityType, $entityId, $action, mb_substr($summary, 0, 500), $meta);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . $e->getMessage());
        }
    }
}
