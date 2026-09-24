<?php

namespace ITFlow\Training\Assign;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * Who a requirement rule targets, and the desired (person, course) pairs with their due dates
 * (Phase 2 spec §3.3, M5). Pure over its inputs apart from the static loader.
 *
 * Matching: all_people => every eligible person; otherwise AND across criteria kinds (department,
 * odoo_job, odoo_location, jobgroup, contact) and OR inside a kind. A new-hires-only rule matches only
 * people whose hire date is on/after the rule's effective_on. A rule with no criteria and
 * all_people = 0 matches nobody (saving one is refused with criteria_required).
 *
 * Due date per rule (ruleDue; fixed at the assignment's first INSERT, never recomputed):
 *   is_manual                          max(baseline_due_on, today)
 *   new_hires_only (hire >= effective) max(hire + due_days_from_hire, today)
 *   hire_date >= effective_on          max(hire + due_days_from_hire, today)
 *   otherwise (current staff, movers)  max(baseline_due_on, today + due_days)
 */
final class RuleMatcher
{
    public const KINDS = ['department', 'odoo_job', 'odoo_location', 'jobgroup', 'contact'];

    /** @var array<int, list<array>> course_id => rules */
    private array $byCourse = [];

    /**
     * @param list<array> $rules active rules, course published & not archived, with 'criteria' (self::rule shape)
     * @param array<int, list<int>> $jobGroupMembership contact_id => jobgroup ids (unused when persons carry jobgroup_ids)
     */
    public function __construct(array $rules, private readonly array $jobGroupMembership = [])
    {
        foreach ($rules as $r) {
            $this->byCourse[(int) $r['course_id']][] = $r;
        }
        ksort($this->byCourse);
    }

    /** @return list<int> */
    public function courseIds(): array
    {
        return array_keys($this->byCourse);
    }

    public function matches(array $rule, array $person): bool
    {
        if (empty($person['eligible']) && empty($rule['_ignore_eligibility'])) {
            return false;
        }
        if (!empty($rule['new_hires_only'])) {
            $hire = $person['hire_date'] ?? null;
            if ($hire === null || $hire < (string) $rule['effective_on']) {
                return false;
            }
        }
        if (!empty($rule['all_people'])) {
            return true;
        }
        $crit = $rule['criteria'] ?? [];
        $any = false;
        foreach (self::KINDS as $kind) {
            $vals = $crit[$kind] ?? [];
            if ($vals === []) {
                continue;
            }
            $any = true;
            $hit = match ($kind) {
                'department' => in_array((int) $person['client_id'], $vals, true),
                'odoo_job' => $person['job_id'] !== null && in_array((int) $person['job_id'], $vals, true),
                'odoo_location' => $person['location_id'] !== null && in_array((int) $person['location_id'], $vals, true),
                'jobgroup' => array_intersect($vals, $person['jobgroup_ids'] ?? ($this->jobGroupMembership[(int) $person['contact_id']] ?? [])) !== [],
                'contact' => in_array((int) $person['contact_id'], $vals, true),
            };
            if (!$hit) {
                return false;
            }
        }
        return $any;
    }

    /** The due date one matching rule gives this person. */
    public static function ruleDue(array $rule, array $person, string $today): string
    {
        $hire = $person['hire_date'] ?? null;
        if (!empty($rule['is_manual'])) {
            return max((string) $rule['baseline_due_on'], $today);
        }
        if ($hire !== null && $hire >= (string) $rule['effective_on']) {
            return max(Clock::addDays($hire, (int) $rule['due_days_from_hire']), $today);
        }
        return max((string) $rule['baseline_due_on'], Clock::addDays($today, (int) $rule['due_days']));
    }

    /**
     * @return array<int, array{due_on:string, requirement_id:int, required:bool, one_time:bool, onboarding_since:?string}>
     *         course_id => desired pair (ascending course id)
     */
    public function desiredFor(array $person, string $today): array
    {
        $out = [];
        foreach ($this->byCourse as $courseId => $rules) {
            $best = null;
            $required = false;
            $oneTime = true;
            $allNewHire = true;
            foreach ($rules as $r) {
                if (!$this->matches($r, $person)) {
                    continue;
                }
                $due = self::ruleDue($r, $person, $today);
                if ($best === null || $due < $best['due'] || ($due === $best['due'] && (int) $r['id'] < $best['id'])) {
                    $best = ['due' => $due, 'id' => (int) $r['id']];
                }
                $required = $required || !empty($r['required']);
                $oneTime = $oneTime && !empty($r['one_time']);
                $allNewHire = $allNewHire && !empty($r['new_hires_only']);
            }
            if ($best !== null) {
                $out[$courseId] = [
                    'due_on' => $best['due'],
                    'requirement_id' => $best['id'],
                    'required' => $required,
                    'one_time' => $oneTime,
                    'onboarding_since' => $allNewHire ? ($person['hire_date'] ?? null) : null,
                ];
            }
        }
        return $out;
    }

    /** Ids of the rules (for a course) that match the person. @return list<int> */
    public function matchingRuleIds(array $person, int $courseId): array
    {
        $ids = [];
        foreach ($this->byCourse[$courseId] ?? [] as $r) {
            if ($this->matches($r, $person)) {
                $ids[] = (int) $r['id'];
            }
        }
        return $ids;
    }

    /**
     * Active rules (not archived) whose course is published and not archived, with criteria.
     *
     * @param bool $matchable false = also rules on unpublished/archived courses (the rule list)
     * @return list<array> rule shape: {id, name, course_id, all_people, required, new_hires_only, due_days, baseline_due_on,
     *   due_days_from_hire, one_time, is_manual, note, effective_on, criteria_sha256, version, archived_at, created_by,
     *   created_at_utc, criteria:{kind: list<int>}, labels:{kind: {id: label}}}
     */
    public static function loadRules(\mysqli $db, bool $matchable = true, ?array $ids = null, bool $includeArchived = false): array
    {
        $where = [];
        $types = '';
        $params = [];
        if (!$includeArchived) {
            $where[] = 'r.requirement_archived_at IS NULL';
        }
        if ($matchable) {
            $where[] = 'c.course_archived_at IS NULL AND c.course_current_revision_id IS NOT NULL';
        }
        if ($ids !== null) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if ($ids === []) {
                return [];
            }
            $where[] = 'r.requirement_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $types .= str_repeat('i', count($ids));
            array_push($params, ...$ids);
        }
        $rows = Db::all($db, 'SELECT r.requirement_id, r.requirement_request_uid, r.requirement_name, r.requirement_course_id, r.requirement_all_people,
                r.requirement_required, r.requirement_new_hires_only, r.requirement_due_days, r.requirement_baseline_due_on, r.requirement_due_days_from_hire,
                r.requirement_one_time, r.requirement_is_manual, r.requirement_note, r.requirement_effective_on, r.requirement_criteria_sha256,
                r.requirement_version, r.requirement_created_by, r.requirement_created_at_utc, r.requirement_archived_at, r.requirement_archived_by
            FROM training_requirements r JOIN training_courses c ON c.course_id = r.requirement_course_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY r.requirement_id', $types, $params);
        if ($rows === []) {
            return [];
        }
        $ruleIds = array_map(static fn($r) => (int) $r['requirement_id'], $rows);
        $crit = [];
        $labels = [];
        foreach (Db::all($db, 'SELECT rcrit_requirement_id, rcrit_kind, rcrit_value_id, rcrit_value_label FROM training_requirement_criteria
                WHERE rcrit_requirement_id IN (' . implode(',', array_fill(0, count($ruleIds), '?')) . ') ORDER BY rcrit_requirement_id, rcrit_kind, rcrit_value_id',
                str_repeat('i', count($ruleIds)), $ruleIds) as $c) {
            $rid = (int) $c['rcrit_requirement_id'];
            $crit[$rid][(string) $c['rcrit_kind']][] = (int) $c['rcrit_value_id'];
            $labels[$rid][(string) $c['rcrit_kind']][(int) $c['rcrit_value_id']] = $c['rcrit_value_label'];
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['requirement_id'];
            $criteria = [];
            foreach (self::KINDS as $k) {
                $criteria[$k] = $crit[$id][$k] ?? [];
            }
            $out[] = [
                'id' => $id,
                'name' => (string) $r['requirement_name'],
                'request_uid' => $r['requirement_request_uid'],
                'course_id' => (int) $r['requirement_course_id'],
                'all_people' => (int) $r['requirement_all_people'] === 1,
                'required' => (int) $r['requirement_required'] === 1,
                'new_hires_only' => (int) $r['requirement_new_hires_only'] === 1,
                'due_days' => (int) $r['requirement_due_days'],
                'baseline_due_on' => (string) $r['requirement_baseline_due_on'],
                'due_days_from_hire' => (int) $r['requirement_due_days_from_hire'],
                'one_time' => (int) $r['requirement_one_time'] === 1,
                'is_manual' => (int) $r['requirement_is_manual'] === 1,
                'note' => $r['requirement_note'],
                'effective_on' => (string) $r['requirement_effective_on'],
                'criteria_sha256' => (string) $r['requirement_criteria_sha256'],
                'version' => (int) $r['requirement_version'],
                'created_by' => (int) $r['requirement_created_by'],
                'created_at_utc' => (string) $r['requirement_created_at_utc'],
                'archived_at' => $r['requirement_archived_at'],
                'archived_by' => $r['requirement_archived_by'] === null ? null : (int) $r['requirement_archived_by'],
                'criteria' => $criteria,
                'labels' => $labels[$id] ?? [],
            ];
        }
        return $out;
    }
}
