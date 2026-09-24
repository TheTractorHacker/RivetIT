<?php

namespace ITFlow\Training\Assign;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Directory;
use ITFlow\Training\People\Roster;
use ITFlow\Training\People\Scope;

/**
 * Requirement rules (M5) and manual "Assign training" (M7) (Phase 2 spec §3.3, §1.4 #1).
 *
 * A rule targets a course for either everyone on the roster (all_people) or the people matching its
 * criteria (AND across kinds, OR within a kind). Criteria rows snapshot their labels. Saving or
 * archiving commits first, then runs a full reconcile whose failure never undoes the save.
 */
final class RequirementService
{
    public const MAX_VALUES_PER_KIND = 500;
    public const SAMPLE = 25;

    public function __construct(private readonly Ctx $c)
    {
    }

    // =========================================================================================
    // Reads
    // =========================================================================================

    /** f: course_id?, include_archived?, manual? (true = only manual, false = only rules) ; stats over IN-SCOPE people. */
    public function list(array $f, Scope $s): array
    {
        $db = $this->c->db;
        $rules = RuleMatcher::loadRules($db, false, null, !empty($f['include_archived']));
        $rules = array_values(array_filter($rules, static function ($r) use ($f) {
            if (isset($f['course_id']) && $f['course_id'] !== null && (int) $f['course_id'] !== $r['course_id']) {
                return false;
            }
            if (isset($f['manual']) && $f['manual'] !== null && (bool) $f['manual'] !== $r['is_manual']) {
                return false;
            }
            return true;
        }));
        return $this->shape($rules, $s, true);
    }

    public function get(int $id, Scope $s): array
    {
        $rules = RuleMatcher::loadRules($this->c->db, false, [$id], true);
        if ($rules === []) {
            throw ApiException::notFound('That rule was not found.');
        }
        return $this->shape($rules, $s, true)[0];
    }

    /**
     * Live preview of a draft rule (L3; all scope; read-only).
     * draft: requirement_id?, course_id, all_people, criteria, new_hires_only, due_days, baseline_due_on, due_days_from_hire, required
     */
    public function preview(array $draft): array
    {
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $s = RecordsSettings::fromDb($db);
        $warnings = [];
        $courseId = (int) ($draft['course_id'] ?? 0);
        $course = CourseCards::load($db, [$courseId])[$courseId] ?? null;
        if ($course === null) {
            throw ApiException::validation(['course_id' => 'Choose a course.']);
        }
        if (!$course['published']) {
            $warnings[] = ['code' => 'course_unpublished', 'message' => 'This course has no published version yet (or is archived). Rules can only be saved for published courses.'];
        }
        $criteria = self::normCriteria($draft['criteria'] ?? []);
        $allPeople = !empty($draft['all_people']);
        $hasCriteria = self::criteriaCount($criteria) > 0;
        if (!$allPeople && !$hasCriteria) {
            $warnings[] = ['code' => 'criteria_required', 'message' => 'Add at least one condition, or target everyone.'];
        }
        if ($allPeople) {
            $warnings[] = ['code' => 'everyone', 'message' => 'This rule applies to everyone on the training roster.'];
        }
        $unknown = $this->unknownOdooIds($criteria);
        if ($unknown !== []) {
            $warnings[] = ['code' => 'unknown_odoo_id', 'message' => 'Some Odoo job or location ids are not on anyone right now: ' . implode(', ', $unknown) . '.'];
        }
        $id = isset($draft['requirement_id']) ? (int) $draft['requirement_id'] : null;
        $existing = $id ? (RuleMatcher::loadRules($db, false, [$id], true)[0] ?? null) : null;
        $baseline = (string) ($draft['baseline_due_on'] ?? $today);
        if (!Clock::isYmd($baseline)) {
            $baseline = $today;
        }
        if ($baseline < $today) {
            $warnings[] = ['code' => 'baseline_past', 'message' => 'The due date for current staff is in the past; they will get today + the minimum days instead.'];
        }
        $rule = [
            'id' => $id ?? 0,
            'course_id' => $courseId,
            'all_people' => $allPeople,
            'criteria' => $criteria,
            'new_hires_only' => !empty($draft['new_hires_only']),
            'due_days' => max(0, min(365, (int) ($draft['due_days'] ?? 30))),
            'baseline_due_on' => $baseline,
            'due_days_from_hire' => max(0, min(365, (int) ($draft['due_days_from_hire'] ?? 7))),
            'one_time' => !empty($draft['one_time']),
            'required' => !array_key_exists('required', $draft) || !empty($draft['required']),
            'is_manual' => $existing['is_manual'] ?? false,
            'effective_on' => $existing['effective_on'] ?? $today,
        ];
        $people = Directory::load($db, Scope::all());
        $matcher = new RuleMatcher([$rule]);
        $matched = [];
        if ($allPeople || $hasCriteria) {
            foreach ($people as $p) {
                if ($matcher->matches($rule, $p)) {
                    $matched[$p['contact_id']] = $p;
                }
            }
        }
        $ids = array_keys($matched);
        $facts = $ids === [] ? [] : RecordFacts::load($db, $ids, [$courseId], false, null, $s, $today);
        $open = $this->openFor($ids, $courseId);
        // Overlaps: other active rules for the same course matching the same people.
        $others = array_values(array_filter(RuleMatcher::loadRules($db, true), static fn($r) => $r['course_id'] === $courseId && $r['id'] !== ($id ?? 0)));
        $otherMatcher = new RuleMatcher($others);
        $overlapPeople = 0;
        $overlapRules = [];
        $counts = ['assign' => 0, 'current' => 0, 'assigned' => 0, 'waived' => 0, 'new_hire' => 0];
        $sample = [];
        $sampleMax = max(1, min(1000, (int) ($draft['sample_limit'] ?? self::SAMPLE)));
        foreach ($matched as $cid => $p) {
            $f = $facts[$cid][$courseId] ?? RecordFacts::none();
            $due = RuleMatcher::ruleDue($rule, $p, $today);
            $newHire = $p['hire_date'] !== null && $p['hire_date'] >= $rule['effective_on'] && !$rule['is_manual'];
            if (!empty($f['waiver'])) {
                $outcome = 'waived';
            } elseif (isset($open[$cid])) {
                $outcome = 'assigned';
                $due = $open[$cid]['due_on'];
            } else {
                $since = $rule['new_hires_only'] ? $p['hire_date'] : null;
                $ff = $since === null ? $f : (RecordFacts::load($db, [$cid], [$courseId], false, [$cid => [$courseId => $since]], $s, $today)[$cid][$courseId]);
                $want = PairRules::want(['due_on' => $due, 'requirement_id' => $rule['id'], 'required' => $rule['required'], 'one_time' => $rule['one_time'],
                    'onboarding_since' => $since], $ff, $course, $today, $s);
                if ($want === null) {
                    $outcome = 'current';
                    $due = null;
                } else {
                    $outcome = $newHire ? 'new_hire' : 'assign';
                    $due = $want['due_on'];
                }
            }
            $counts[$outcome]++;
            $ids2 = $otherMatcher->matchingRuleIds($p, $courseId);
            if ($ids2 !== []) {
                $overlapPeople++;
                foreach ($ids2 as $rid) {
                    $overlapRules[$rid] = $rid;
                }
            }
            if (count($sample) < $sampleMax) {
                $sample[] = ['person' => Directory::ref($p), 'outcome' => $outcome, 'due_on' => $due];
            }
        }
        usort($sample, static fn($a, $b) => strcmp($a['person']['name'], $b['person']['name']));
        ksort($overlapRules);
        $currentStaffDue = max($baseline, Clock::addDays($today, $rule['due_days']));
        return [
            'course' => CourseCards::card($course),
            'matched' => count($matched),
            'will_assign' => $counts['assign'] + $counts['new_hire'],
            'already_current' => $counts['current'],
            'already_assigned' => $counts['assigned'],
            'waived' => $counts['waived'],
            'due_on_current_staff' => $rule['new_hires_only'] ? null : $currentStaffDue,
            'due_rule_text' => self::dueText($rule, $currentStaffDue),
            'sample' => $sample,
            'sample_total' => count($matched),
            'overlaps' => ['people' => $overlapPeople, 'rule_ids' => array_values($overlapRules)],
            'warnings' => $warnings,
        ];
    }

    // =========================================================================================
    // Writes
    // =========================================================================================

    /**
     * Create ($id null; requires request_uid) or update ($id + $version). Returns {rule, duplicate, reconcile}.
     * draft keys: request_uid, name, course_id, all_people, criteria, new_hires_only, due_days, baseline_due_on,
     * due_days_from_hire, one_time, required, note.
     */
    public function save(?int $id, ?int $version, array $draft, bool $runReconcile = true): array
    {
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $v = $this->validate($id, $draft);
        $existing = null;
        if ($id !== null) {
            $existing = RuleMatcher::loadRules($db, false, [$id], true)[0] ?? null;
            if ($existing === null) {
                throw ApiException::notFound('That rule was not found.');
            }
            if ($existing['archived_at'] !== null) {
                throw new ApiException(409, 'archived', 'This rule is archived.');
            }
            if ($existing['course_id'] !== $v['course_id']) {
                throw new ApiException(422, 'course_immutable', "A rule's course cannot be changed. Archive it and create a new rule.",
                    ['course_id' => 'Cannot be changed.']);
            }
            if ($version === null) {
                throw ApiException::validation(['version' => 'Required.']);
            }
            $v['is_manual'] = $existing['is_manual'];
        } else {
            $dup = Db::one($db, 'SELECT requirement_id FROM training_requirements WHERE requirement_request_uid = ?', 's', [$v['request_uid']]);
            if ($dup !== null) {
                return ['rule' => $this->get((int) $dup['requirement_id'], Scope::forCtx($this->c)), 'duplicate' => true, 'reconcile' => null];
            }
        }
        $labels = $this->labels($v['criteria'], $existing);
        $sha = self::criteriaSha($v['all_people'], $v['criteria']);
        $userId = $this->c->userId;

        $result = Db::tx($db, function () use ($db, $id, $version, $v, $labels, $sha, $today, $userId): array {
            if ($id === null) {
                try {
                    $rid = Db::insert($db, 'INSERT INTO training_requirements (requirement_request_uid, requirement_name, requirement_course_id,
                            requirement_all_people, requirement_required, requirement_new_hires_only, requirement_due_days, requirement_baseline_due_on,
                            requirement_due_days_from_hire, requirement_one_time, requirement_is_manual, requirement_note, requirement_effective_on,
                            requirement_criteria_sha256, requirement_version, requirement_created_by, requirement_created_at_utc)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)', 'ssiiiiisiiisssis',
                        [$v['request_uid'], $v['name'], $v['course_id'], $v['all_people'] ? 1 : 0, $v['required'] ? 1 : 0, $v['new_hires_only'] ? 1 : 0,
                         $v['due_days'], $v['baseline_due_on'], $v['due_days_from_hire'], $v['one_time'] ? 1 : 0, $v['is_manual'] ? 1 : 0, $v['note'],
                         $today, $sha, $userId, Clock::nowUtc()]);
                } catch (\mysqli_sql_exception $e) {
                    if ((int) $e->getCode() === 1062 && str_contains($e->getMessage(), 'uq_training_req_request')) {
                        return ['duplicate' => true];
                    }
                    throw $e;
                }
                $newVersion = 1;
            } else {
                $n = Db::exec($db, 'UPDATE training_requirements SET requirement_name = ?, requirement_all_people = ?, requirement_required = ?,
                        requirement_new_hires_only = ?, requirement_due_days = ?, requirement_baseline_due_on = ?, requirement_due_days_from_hire = ?,
                        requirement_one_time = ?, requirement_note = ?, requirement_criteria_sha256 = ?, requirement_version = requirement_version + 1
                    WHERE requirement_id = ? AND requirement_version = ? AND requirement_archived_at IS NULL', 'siiiisiissii',
                    [$v['name'], $v['all_people'] ? 1 : 0, $v['required'] ? 1 : 0, $v['new_hires_only'] ? 1 : 0, $v['due_days'], $v['baseline_due_on'],
                     $v['due_days_from_hire'], $v['one_time'] ? 1 : 0, $v['note'], $sha, $id, $version]);
                if ($n !== 1) {
                    $cur = Db::one($db, 'SELECT requirement_version, requirement_archived_at FROM training_requirements WHERE requirement_id = ?', 'i', [$id]);
                    if ($cur !== null && $cur['requirement_archived_at'] !== null) {
                        throw new ApiException(409, 'archived', 'This rule is archived.');
                    }
                    throw ApiException::conflict(['id' => $id, 'version' => (int) ($cur['requirement_version'] ?? 0)]);
                }
                $rid = $id;
                $newVersion = $version + 1;
                Db::exec($db, 'DELETE FROM training_requirement_criteria WHERE rcrit_requirement_id = ?', 'i', [$rid]);
            }
            foreach (RuleMatcher::KINDS as $kind) {
                foreach ($v['criteria'][$kind] as $val) {
                    Db::exec($db, 'INSERT INTO training_requirement_criteria (rcrit_requirement_id, rcrit_kind, rcrit_value_id, rcrit_value_label) VALUES (?, ?, ?, ?)',
                        'isis', [$rid, $kind, $val, $labels[$kind][$val] ?? null]);
                }
            }
            Ledger::append($db, [
                'type' => 'requirement.saved',
                'actor_type' => 'user',
                'actor_user_id' => $userId,
                'course_id' => $v['course_id'],
                'entity_type' => 'requirement',
                'entity_id' => $rid,
                'entity_sha256' => $sha,
                'payload' => self::payload($v, $newVersion),
                'user_agent' => $this->c->userAgent,
            ]);
            return ['id' => $rid, 'duplicate' => false];
        });
        if (!empty($result['duplicate'])) {
            $dup = Db::one($db, 'SELECT requirement_id FROM training_requirements WHERE requirement_request_uid = ?', 's', [$v['request_uid']]);
            return ['rule' => $this->get((int) $dup['requirement_id'], Scope::forCtx($this->c)), 'duplicate' => true, 'reconcile' => null];
        }
        $reconcile = $runReconcile ? AssignmentService::safeReconcile($this->c, null, 'rule_save') : null;
        return ['rule' => $this->get((int) $result['id'], Scope::forCtx($this->c)), 'duplicate' => false, 'reconcile' => $reconcile];
    }

    /** tx: archived_* ; Ledger requirement.archived ; commit ; reconcile(null, 'rule_archive'). */
    public function archive(int $id, string $reason): array
    {
        $reason = trim($reason);
        if (!mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason, 'UTF-8') < 3 || mb_strlen($reason, 'UTF-8') > 255) {
            throw ApiException::validation(['reason' => 'Say why (3 to 255 characters).']);
        }
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $id, $reason): void {
            $cur = Db::one($db, 'SELECT requirement_id, requirement_archived_at, requirement_version FROM training_requirements WHERE requirement_id = ? FOR UPDATE', 'i', [$id]);
            if ($cur === null) {
                throw ApiException::notFound('That rule was not found.');
            }
            if ($cur['requirement_archived_at'] !== null) {
                throw new ApiException(409, 'archived', 'This rule is already archived.');
            }
            Db::exec($db, 'UPDATE training_requirements SET requirement_archived_at = NOW(), requirement_archived_by = ?, requirement_version = requirement_version + 1
                WHERE requirement_id = ?', 'ii', [$this->c->userId, $id]);
            $r = RuleMatcher::loadRules($db, false, [$id], true)[0];
            Ledger::append($db, [
                'type' => 'requirement.archived',
                'actor_type' => 'user',
                'actor_user_id' => $this->c->userId,
                'course_id' => $r['course_id'],
                'entity_type' => 'requirement',
                'entity_id' => $id,
                'payload' => self::payload($r, $r['version']) + ['reason' => $reason],
                'user_agent' => $this->c->userAgent,
            ]);
        });
        return ['reconcile' => AssignmentService::safeReconcile($this->c, null, 'rule_archive')];
    }

    /**
     * Manual "Assign training": a one-time `contact` rule with the chosen due date as its baseline, then a
     * reconcile of just those people. Idempotent by request_uid (a retry returns the same rule).
     *
     * @return array{rule_id:int, duplicate:bool, outcomes:list<array{contact_id:int, outcome:string}>, reconcile:?array}
     */
    public function createManual(array $contactIds, int $courseId, string $dueOn, ?string $note, string $requestUid): array
    {
        $db = $this->c->db;
        $today = Clock::todayLocal();
        if (!Clock::isYmd($dueOn) || $dueOn < $today) {
            throw new ApiException(422, 'date_out_of_range', 'Pick a due date from today on.', ['due_on' => 'Must be today or later.']);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn($i) => $i > 0)));
        sort($ids);
        if ($ids === [] || count($ids) > 200) {
            throw ApiException::validation(['contact_ids' => 'Choose 1 to 200 people.']);
        }
        $userName = (string) (Db::one($db, 'SELECT user_name FROM users WHERE user_id = ?', 'i', [$this->c->userId])['user_name'] ?? 'user #' . $this->c->userId);
        $name = mb_substr('Assigned by ' . $userName . ' on ' . $today, 0, 150);
        $before = $this->openFor($ids, $courseId);
        $saved = $this->save(null, null, [
            'request_uid' => $requestUid,
            'name' => $name,
            'course_id' => $courseId,
            'all_people' => false,
            'criteria' => ['contact' => $ids],
            'new_hires_only' => false,
            'due_days' => 0,
            'baseline_due_on' => $dueOn,
            'due_days_from_hire' => 0,
            'one_time' => true,
            'required' => true,
            'note' => $note,
            '_manual' => true,
        ], false);
        $ruleId = (int) $saved['rule']['id'];
        // The rule's own contact list (a retried request returns the original rule), never the scope-filtered view.
        $ruleContacts = RuleMatcher::loadRules($db, false, [$ruleId], true)[0]['criteria']['contact'] ?? $ids;
        $reconcile = AssignmentService::safeReconcile($this->c, $ruleContacts, 'assign_manual');

        // Per-person outcome, decided from the same rules reconcile uses (so a busy reconcile still reports what will happen).
        $s = RecordsSettings::fromDb($db);
        $course = CourseCards::load($db, [$courseId])[$courseId] ?? ['validity_months' => null, 'renewal_lead_days' => 0];
        $people = Directory::load($db, Scope::all(), $ids, true);
        $after = $this->openFor($ids, $courseId);
        $facts = RecordFacts::load($db, $ids, [$courseId], false, null, $s, $today);
        $outcomes = [];
        foreach ($ids as $cid) {
            $p = $people[$cid] ?? null;
            $f = $facts[$cid][$courseId] ?? RecordFacts::none();
            if ($p === null || !$p['eligible']) {
                $o = 'not_eligible';
            } elseif (isset($after[$cid]) && $after[$cid]['requirement_id'] === $ruleId) {
                $o = 'assigned';
            } elseif (isset($before[$cid]) || isset($after[$cid])) {
                $o = 'already_assigned';
            } elseif (!empty($f['waiver'])) {
                $o = 'waived';
            } else {
                $want = PairRules::want(['due_on' => max($dueOn, $today), 'requirement_id' => $ruleId, 'required' => true, 'one_time' => true,
                    'onboarding_since' => null], $f, $course, $today, $s);
                $o = $want === null ? 'current' : 'assigned';
            }
            $outcomes[] = ['contact_id' => $cid, 'outcome' => $o];
        }
        return ['rule_id' => $ruleId, 'duplicate' => $saved['duplicate'], 'outcomes' => $outcomes, 'reconcile' => $reconcile];
    }

    // =========================================================================================
    // Helpers
    // =========================================================================================

    /** sha256 of the canonical {all_people, criteria} document (the requirement.saved entity_sha256). */
    public static function criteriaSha(bool $allPeople, array $criteria): string
    {
        $doc = ['all_people' => $allPeople, 'criteria' => []];
        foreach (RuleMatcher::KINDS as $k) {
            $vals = array_values(array_map('intval', $criteria[$k] ?? []));
            sort($vals);
            $doc['criteria'][$k] = $vals;
        }
        return Canonical::sha256(Canonical::doc($doc));
    }

    /** {kind: sorted unique positive ints} for every kind. */
    public static function normCriteria(mixed $in): array
    {
        $out = [];
        foreach (RuleMatcher::KINDS as $k) {
            $vals = [];
            $raw = is_array($in) ? ($in[$k] ?? []) : [];
            foreach (is_array($raw) ? $raw : [] as $v) {
                if (is_string($v) && preg_match('/^[0-9]{1,10}$/', trim($v))) {
                    $v = (int) trim($v);
                }
                if (is_int($v) && $v > 0) {
                    $vals[$v] = $v;
                }
            }
            ksort($vals);
            $out[$k] = array_values($vals);
        }
        return $out;
    }

    public static function criteriaCount(array $criteria): int
    {
        $n = 0;
        foreach (RuleMatcher::KINDS as $k) {
            $n += count($criteria[$k] ?? []);
        }
        return $n;
    }

    private static function payload(array $v, int $version): array
    {
        $crit = [];
        foreach (RuleMatcher::KINDS as $k) {
            $crit[$k] = array_values($v['criteria'][$k] ?? []);
        }
        return [
            'name' => $v['name'],
            'course_id' => $v['course_id'],
            'all_people' => (bool) $v['all_people'],
            'criteria' => $crit,
            'new_hires_only' => (bool) $v['new_hires_only'],
            'due_days' => (int) $v['due_days'],
            'baseline_due_on' => $v['baseline_due_on'],
            'due_days_from_hire' => (int) $v['due_days_from_hire'],
            'one_time' => (bool) $v['one_time'],
            'required' => (bool) $v['required'],
            'is_manual' => (bool) $v['is_manual'],
            'version' => $version,
        ];
    }

    private static function dueText(array $rule, string $currentStaffDue): string
    {
        $hire = $rule['due_days_from_hire'] === 1 ? '1 day' : $rule['due_days_from_hire'] . ' days';
        if ($rule['is_manual']) {
            return 'Due ' . $rule['baseline_due_on'] . '.';
        }
        if ($rule['new_hires_only']) {
            return "New hires (hired on or after {$rule['effective_on']}): due $hire after their hire date.";
        }
        return "Current staff: due $currentStaffDue. New hires: due $hire after their hire date.";
    }

    /** @return array validated draft */
    private function validate(?int $id, array $d): array
    {
        $errors = [];
        $manual = !empty($d['_manual']);
        $uid = isset($d['request_uid']) ? strtolower(trim((string) $d['request_uid'])) : null;
        if ($id === null && ($uid === null || preg_match('/^[0-9a-f]{32}$/D', $uid) !== 1)) {
            $errors['request_uid'] = 'Required (32 hex characters).';
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 150) {
            $errors['name'] = 'Give the rule a name (at most 150 characters).';
        }
        $note = isset($d['note']) && $d['note'] !== null ? trim((string) $d['note']) : '';
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note, 'UTF-8') > 500) {
            $errors['note'] = 'Too long (at most 500 characters).';
        }
        $dueDays = $d['due_days'] ?? 30;
        if (!is_int($dueDays) || $dueDays < 0 || $dueDays > 365) {
            $errors['due_days'] = 'Between 0 and 365 days.';
        }
        $hireDays = $d['due_days_from_hire'] ?? 7;
        if (!is_int($hireDays) || $hireDays < 0 || $hireDays > 365) {
            $errors['due_days_from_hire'] = 'Between 0 and 365 days.';
        }
        $baseline = (string) ($d['baseline_due_on'] ?? '');
        if (!Clock::isYmd($baseline) || $baseline < '2000-01-01') {
            $errors['baseline_due_on'] = 'Must be a date (YYYY-MM-DD).';
        }
        $courseId = (int) ($d['course_id'] ?? 0);
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $course = CourseCards::load($this->c->db, [$courseId])[$courseId] ?? null;
        if ($course === null) {
            throw ApiException::validation(['course_id' => 'Choose a course.']);
        }
        if (!$course['published']) {
            throw new ApiException(422, 'course_unpublished', 'Publish this course (and make sure it is not archived) before assigning it.', ['course_id' => 'Not published.']);
        }
        $allPeople = !empty($d['all_people']);
        $criteria = self::normCriteria($d['criteria'] ?? []);
        foreach (RuleMatcher::KINDS as $k) {
            if (count($criteria[$k]) > self::MAX_VALUES_PER_KIND) {
                throw ApiException::validation(['criteria' => 'Too many values (at most ' . self::MAX_VALUES_PER_KIND . ' per condition).']);
            }
        }
        $n = self::criteriaCount($criteria);
        if ($allPeople && $n > 0) {
            throw ApiException::validation(['all_people' => 'Target everyone, or add conditions - not both.']);
        }
        if (!$allPeople && $n === 0) {
            throw new ApiException(422, 'criteria_required', 'Add at least one condition, or target everyone.', ['criteria' => 'Add at least one condition.']);
        }
        $this->checkCriteriaIds($criteria);
        return [
            'request_uid' => $uid,
            'name' => $name,
            'course_id' => $courseId,
            'all_people' => $allPeople,
            'criteria' => $criteria,
            'new_hires_only' => !empty($d['new_hires_only']),
            'due_days' => (int) $dueDays,
            'baseline_due_on' => $baseline,
            'due_days_from_hire' => (int) $hireDays,
            'one_time' => !empty($d['one_time']),
            'required' => !array_key_exists('required', $d) || !empty($d['required']),
            'is_manual' => $manual,
            'note' => $note === '' ? null : $note,
        ];
    }

    /** Departments, job groups and contacts must exist; Odoo ids may be unknown (flagged known:false). */
    private function checkCriteriaIds(array $criteria): void
    {
        $db = $this->c->db;
        $check = static function (array $ids, string $sql, string $field, string $msg) use ($db): void {
            if ($ids === []) {
                return;
            }
            $found = Db::all($db, sprintf($sql, implode(',', array_fill(0, count($ids), '?'))), str_repeat('i', count($ids)), $ids);
            if (count($found) !== count($ids)) {
                throw ApiException::validation([$field => $msg]);
            }
        };
        $check($criteria['department'], 'SELECT client_id FROM clients WHERE client_id IN (%s)', 'criteria', 'One of those departments no longer exists.');
        $check($criteria['jobgroup'], 'SELECT jobgroup_id FROM training_job_groups WHERE jobgroup_archived_at IS NULL AND jobgroup_id IN (%s)', 'criteria',
            'One of those job groups no longer exists.');
        $check($criteria['contact'], 'SELECT contact_id FROM contacts WHERE contact_id IN (%s)', 'criteria', 'One of those people no longer exists.');
    }

    /** Label snapshots: {kind: {id: label}} from the directory now, else the previous snapshot, else a placeholder. */
    private function labels(array $criteria, ?array $existing): array
    {
        $db = $this->c->db;
        $out = [];
        $fetch = static function (array $ids, string $sql) use ($db): array {
            if ($ids === []) {
                return [];
            }
            $m = [];
            foreach (Db::all($db, sprintf($sql, implode(',', array_fill(0, count($ids), '?'))), str_repeat('i', count($ids)), $ids) as $r) {
                $m[(int) $r['id']] = $r['label'] === null ? null : mb_substr((string) $r['label'], 0, 200);
            }
            return $m;
        };
        $now = [
            'department' => $fetch($criteria['department'], 'SELECT client_id AS id, client_name AS label FROM clients WHERE client_id IN (%s)'),
            'odoo_job' => $fetch($criteria['odoo_job'], 'SELECT coattr_job_id AS id, MAX(coattr_job_name) AS label FROM contact_odoo_attributes WHERE coattr_job_id IN (%s) GROUP BY coattr_job_id'),
            'odoo_location' => $fetch($criteria['odoo_location'], 'SELECT coattr_work_location_id AS id, MAX(coattr_work_location_name) AS label FROM contact_odoo_attributes
                WHERE coattr_work_location_id IN (%s) GROUP BY coattr_work_location_id'),
            'jobgroup' => $fetch($criteria['jobgroup'], 'SELECT jobgroup_id AS id, jobgroup_name AS label FROM training_job_groups WHERE jobgroup_id IN (%s)'),
            'contact' => $fetch($criteria['contact'], 'SELECT contact_id AS id, contact_name AS label FROM contacts WHERE contact_id IN (%s)'),
        ];
        $placeholder = ['department' => 'Department #', 'odoo_job' => 'Odoo job #', 'odoo_location' => 'Odoo location #', 'jobgroup' => 'Job group #', 'contact' => 'Person #'];
        foreach (RuleMatcher::KINDS as $k) {
            foreach ($criteria[$k] as $id) {
                $out[$k][$id] = $now[$k][$id] ?? ($existing['labels'][$k][$id] ?? $placeholder[$k] . $id);
            }
        }
        return $out;
    }

    /** Odoo job/location ids no contact currently carries. @return list<string> e.g. ["job #12"] */
    private function unknownOdooIds(array $criteria): array
    {
        $out = [];
        foreach (['odoo_job' => ['coattr_job_id', 'job'], 'odoo_location' => ['coattr_work_location_id', 'location']] as $k => [$col, $word]) {
            $ids = $criteria[$k] ?? [];
            if ($ids === []) {
                continue;
            }
            $found = [];
            foreach (Db::all($this->c->db, "SELECT DISTINCT $col AS id FROM contact_odoo_attributes WHERE $col IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                str_repeat('i', count($ids)), $ids) as $r) {
                $found[(int) $r['id']] = true;
            }
            foreach ($ids as $id) {
                if (!isset($found[$id])) {
                    $out[] = "$word #$id";
                }
            }
        }
        return $out;
    }

    /** Open assignments for (contacts, course): contact_id => assignment. */
    private function openFor(array $contactIds, int $courseId): array
    {
        if ($contactIds === []) {
            return [];
        }
        $out = [];
        foreach (Db::all($this->c->db, 'SELECT ' . AssignmentStore::COLUMNS . " FROM training_assignments WHERE tassign_course_id = ? AND tassign_status = 'open'
                AND tassign_contact_id IN (" . implode(',', array_fill(0, count($contactIds), '?')) . ')', 'i' . str_repeat('i', count($contactIds)),
                array_merge([$courseId], $contactIds)) as $r) {
            $a = AssignmentStore::normalize($r);
            $out[$a['contact_id']] = $a;
        }
        return $out;
    }

    /**
     * Rule (spec §4.1) for each rule. Contact criteria outside $s collapse to contact_hidden; stats count
     * in-scope eligible people only.
     */
    private function shape(array $rules, Scope $s, bool $withStats): array
    {
        if ($rules === []) {
            return [];
        }
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $settings = RecordsSettings::fromDb($db);
        $courses = CourseCards::load($db, array_map(static fn($r) => $r['course_id'], $rules));
        $creators = [];
        $uids = array_values(array_unique(array_map(static fn($r) => $r['created_by'], $rules)));
        foreach (Db::all($db, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', array_fill(0, count($uids), '?')) . ')',
            str_repeat('i', count($uids)), $uids) as $u) {
            $creators[(int) $u['user_id']] = (string) $u['user_name'];
        }
        // Label "known" flags: Odoo ids someone carries now; departments/job groups/contacts that exist.
        $known = ['odoo_job' => [], 'odoo_location' => [], 'jobgroup' => [], 'department' => [], 'contact' => []];
        foreach (Db::all($db, 'SELECT DISTINCT coattr_job_id AS j, coattr_work_location_id AS l FROM contact_odoo_attributes') as $r) {
            if ($r['j'] !== null) {
                $known['odoo_job'][(int) $r['j']] = true;
            }
            if ($r['l'] !== null) {
                $known['odoo_location'][(int) $r['l']] = true;
            }
        }
        foreach (Db::all($db, 'SELECT jobgroup_id FROM training_job_groups WHERE jobgroup_archived_at IS NULL') as $r) {
            $known['jobgroup'][(int) $r['jobgroup_id']] = true;
        }
        foreach (Db::all($db, 'SELECT client_id FROM clients') as $r) {
            $known['department'][(int) $r['client_id']] = true;
        }
        $contactIds = [];
        foreach ($rules as $r) {
            array_push($contactIds, ...$r['criteria']['contact']);
        }
        $contactRows = [];
        if ($contactIds !== []) {
            $contactIds = array_values(array_unique($contactIds));
            foreach (Db::all($db, 'SELECT contact_id, contact_name, contact_client_id FROM contacts WHERE contact_id IN (' . implode(',', array_fill(0, count($contactIds), '?')) . ')',
                str_repeat('i', count($contactIds)), $contactIds) as $r) {
                $contactRows[(int) $r['contact_id']] = $r;
            }
        }

        $stats = [];
        if ($withStats) {
            $people = Directory::load($db, $s);
            $open = [];
            if ($people !== []) {
                [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
                foreach (Db::all($db, "SELECT a.tassign_requirement_id, a.tassign_due_on FROM training_assignments a JOIN contacts c ON c.contact_id = a.tassign_contact_id
                        WHERE a.tassign_status = 'open' AND a.tassign_requirement_id IS NOT NULL" . $scopeSql, $types, $params) as $r) {
                    $rid = (int) $r['tassign_requirement_id'];
                    $open[$rid]['open'] = ($open[$rid]['open'] ?? 0) + 1;
                    if ((string) $r['tassign_due_on'] < $today) {
                        $open[$rid]['overdue'] = ($open[$rid]['overdue'] ?? 0) + 1;
                    }
                }
            }
            $courseIds = array_values(array_unique(array_map(static fn($r) => $r['course_id'], $rules)));
            $facts = $people === [] ? [] : RecordFacts::load($db, array_keys($people), $courseIds, false, null, $settings, $today);
            foreach ($rules as $r) {
                $m = new RuleMatcher([$r + ['_ignore_eligibility' => false]]);
                $matched = 0;
                $current = 0;
                foreach ($people as $cid => $p) {
                    if (!$m->matches($r, $p)) {
                        continue;
                    }
                    $matched++;
                    $f = $facts[$cid][$r['course_id']] ?? RecordFacts::none();
                    if ($r['new_hires_only'] && $p['hire_date'] !== null && $f['latest'] !== null && $f['latest']['completed_on'] < $p['hire_date']) {
                        $f['latest'] = null;
                    }
                    if ($f['latest'] !== null && PairRules::isValid($f['latest'], $f['rr'], $today)) {
                        $current++;
                    }
                }
                $stats[$r['id']] = ['matched' => $matched, 'open' => $open[$r['id']]['open'] ?? 0, 'overdue' => $open[$r['id']]['overdue'] ?? 0, 'current' => $current];
            }
        }

        $out = [];
        foreach ($rules as $r) {
            $labels = [];
            $hidden = 0;
            $visibleContacts = [];
            foreach (RuleMatcher::KINDS as $k) {
                $labels[$k] = [];
                foreach ($r['criteria'][$k] as $vid) {
                    if ($k === 'contact') {
                        $row = $contactRows[$vid] ?? null;
                        if ($row === null || !$s->allows((int) $row['contact_client_id'])) {
                            $hidden++;
                            continue;
                        }
                        $visibleContacts[] = $vid;
                        $labels[$k][] = ['id' => $vid, 'name' => (string) $row['contact_name'], 'known' => true];
                        continue;
                    }
                    $labels[$k][] = ['id' => $vid, 'name' => $r['labels'][$k][$vid] ?? null, 'known' => isset($known[$k][$vid])];
                }
            }
            $criteria = $r['criteria'];
            $criteria['contact'] = $visibleContacts;
            $k = $courses[$r['course_id']] ?? null;
            $out[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'course' => $k !== null ? CourseCards::card($k) : ['id' => $r['course_id'], 'name' => 'Course #' . $r['course_id'], 'published' => false],
                'all_people' => $r['all_people'],
                'criteria' => $criteria,
                'labels' => $labels,
                'contact_hidden' => $hidden,
                'new_hires_only' => $r['new_hires_only'],
                'due_days' => $r['due_days'],
                'baseline_due_on' => $r['baseline_due_on'],
                'due_days_from_hire' => $r['due_days_from_hire'],
                'one_time' => $r['one_time'],
                'required' => $r['required'],
                'is_manual' => $r['is_manual'],
                'note' => $r['note'],
                'effective_on' => $r['effective_on'],
                'version' => $r['version'],
                'archived' => $r['archived_at'] !== null,
                'created_at' => Clock::toIso($r['created_at_utc'], true),
                'created_by_name' => $creators[$r['created_by']] ?? null,
                'stats' => $stats[$r['id']] ?? null,
            ];
        }
        return $out;
    }
}
