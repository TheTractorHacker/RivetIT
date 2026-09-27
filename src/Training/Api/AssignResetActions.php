<?php

namespace ITFlow\Training\Api;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Assign\AssignmentReset;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\People\Scope;

/**
 * JSON handlers for resetting and un-waiving assignments (Routes/assign_reset.php). Authorization at the edge
 * (Phase 2 §0 #2): the Router enforces the route level (reset / un-waive 2, take again 3 - voiding revokes a
 * certificate, the level of completion_void); every handler then resolves the assignment through
 * AssignmentService::get with the caller's People scope, so an assignment outside their departments is the
 * same 404 as one that does not exist. POSTs carry the Router's CSRF check.
 */
final class AssignResetActions
{
    /** GET assignment_reset_preview {assignment_id} (L2) -> what Reset / Un-waive would do (AssignmentReset::preview). */
    public static function preview(Ctx $c, ApiContext $a): array
    {
        $id = self::scoped($c, $a);
        return (new AssignmentReset($c))->preview($id);
    }

    /** POST assignment_reset {assignment_id, run_id, reason (5-500), due_on?} (L2): clears the open assignment's kiosk progress. */
    public static function reset(Ctx $c, ApiContext $a): array
    {
        $id = self::scoped($c, $a);
        $r = (new AssignmentReset($c))->resetProgress($id, (int) $a->int('run_id', true, 1), (string) $a->str('reason', 500), $a->date('due_on', false));
        $summary = 'Reset kiosk progress on training assignment #' . $id . ' (contact #' . $r['contact_id'] . ', course #' . $r['course_id'] . ')'
            . ($r['due_changed'] ? ', due ' . $r['assignment']['due_on'] : '');
        CourseActions::log('Modify', $summary, $id);
        self::audit($c, 'training.assignment_progress_reset', $id, 'update', $summary,
            ['contact_id' => $r['contact_id'], 'course_id' => $r['course_id'], 'run_id' => $r['run_id'], 'due_changed' => $r['due_changed']]);
        unset($r['contact_id'], $r['course_id']);
        return $r;
    }

    /** POST assignment_retake {assignment_id, reason (5-500), due_on} (L3): voids the record and assigns the course again. */
    public static function retake(Ctx $c, ApiContext $a): array
    {
        $id = self::scoped($c, $a);
        $r = (new AssignmentReset($c))->retake($id, (string) $a->str('reason', 500), (string) $a->date('due_on'));
        $summary = 'Reset training assignment #' . $id . ' to take again: voided record ' . ($r['cert_number'] ?? ('#' . ($r['previous']['completion_id'] ?? '?')))
            . ($r['assignment'] !== null ? ', new assignment #' . $r['assignment']['id'] . ' due ' . $r['assignment']['due_on'] : ', new assignment pending');
        CourseActions::log('Modify', $summary, $id);
        self::audit($c, 'training.assignment_retake', $id, 'update', $summary,
            ['contact_id' => $r['contact_id'], 'course_id' => $r['course_id'], 'void_id' => $r['void_id'],
             'new_assignment_id' => $r['assignment']['id'] ?? null]);
        unset($r['contact_id'], $r['course_id']);
        return $r;
    }

    /** POST assignment_unwaive {assignment_id, reason (5-500), due_on?} (L2): ends a waiver that is still active. */
    public static function unwaive(Ctx $c, ApiContext $a): array
    {
        $id = self::scoped($c, $a);
        $r = (new AssignmentReset($c))->unwaive($id, (string) $a->str('reason', 500), $a->date('due_on', false));
        $summary = 'Ended the waiver on training assignment #' . $id . match ($r['outcome']) {
            'reopened' => ', reopened, due ' . $r['assignment']['due_on'],
            'replaced' => ', new assignment #' . $r['assignment']['id'] . ' due ' . $r['assignment']['due_on'],
            'open_exists' => ' (an open assignment already covers it)',
            default => ' (nothing to take right now)',
        };
        CourseActions::log('Modify', $summary, $id);
        self::audit($c, 'training.assignment_unwaived', $id, 'update', $summary,
            ['contact_id' => $r['contact_id'], 'course_id' => $r['course_id'], 'outcome' => $r['outcome'], 'prior_until' => $r['prior_until'],
             'open_assignment_id' => in_array($r['outcome'], ['reopened', 'replaced', 'open_exists'], true) ? $r['assignment']['id'] : null]);
        unset($r['contact_id'], $r['course_id']);
        return $r;
    }

    /** The assignment id, after the caller's scope check (404 when missing or outside their departments). */
    private static function scoped(Ctx $c, ApiContext $a): int
    {
        $id = (int) $a->int('assignment_id', true, 1);
        (new AssignmentService($c))->get($id, Scope::forCtx($c));
        return $id;
    }

    /** AuditService after commit; best-effort. */
    private static function audit(Ctx $c, string $event, int $id, string $action, string $summary, array $meta): void
    {
        try {
            (new AuditService($c->db))->log($event, $c->userId, 'training_assignment', $id, $action, mb_substr($summary, 0, 500), $meta);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . $e->getMessage());
        }
    }
}
