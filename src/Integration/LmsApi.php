<?php

namespace ITFlow\Integration;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\EvidenceStore;

/**
 * Read side of the LMS API (GET /api/v1/lms/...): the published course catalog, assignments and completions.
 *
 * SERVICE CONTEXT. src/Training/Core/Access.php is session-bound (it reads the logged-in user's module_training level), so it is not used
 * here. An integration client has no user: it sees only the departments it was granted (IntegrationScope: null = all, [] = none), and every
 * read query below is filtered by that department scope IN SQL, so an object outside the scope is indistinguishable from one that does not exist.
 *
 * DEPARTMENT SCOPE (least privilege, OWASP API1). A department is clients.client_id; a person belongs to the department in
 * contacts.contact_client_id (current value, evaluated per request). Assignments and completions are in scope when their person is.
 * A COURSE is in scope when the client has all departments, or when at least one of these links it to an allowed department:
 *   1. a live (not archived) requirement rule of the course that is "everyone" (requirement_all_people), has a department criterion on an
 *      allowed department, or has a contact criterion naming a person of an allowed department (this is how manual assignments are stored);
 *   2. an assignment of the course (any status) to a person of an allowed department;
 *   3. a completion of the course by a person of an allowed department.
 * A course with no such link (a draft-only catalog entry, or one whose rules use only job group / Odoo job / work location criteria) is
 * visible to all-department clients only. The `departments` and `requirements` fields of a course list only the rules that touch the
 * allowed departments, so another department's name or rule never appears. Evidence goes through EvidenceStore::canServe() with an explicit service Ctx (level 3, user 0) and
 * Scope::all(), i.e. the same "referenced by a completion whose person is in scope" rule the web endpoint applies.
 *
 * IDS. Courses are addressed by course_uid (12 characters, never the sequential row id), assignments as asg_<n> and completions as cmp_<n>.
 */
final class LmsApi
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    /**
     * @param list<int>|null $departments the client's allowed departments (IntegrationScope::allowedDepartmentIds): null = all, [] = none.
     *        No default on purpose: a caller that forgets the scope must fail, not silently see everything.
     */
    public function __construct(private readonly \mysqli $db, private readonly ?array $departments)
    {
    }

    // ---- department scope --------------------------------------------------------------------------------------------

    private function deptList(): string
    {
        return implode(',', array_map('intval', $this->departments ?? []));
    }

    /** SQL (leading AND) limiting $contactCol to people of the allowed departments. The ids are integers, so they are inlined. */
    private function personScope(string $contactCol): string
    {
        if ($this->departments === null) {
            return '';
        }
        if ($this->departments === []) {
            return ' AND 1 = 0';
        }
        return " AND EXISTS (SELECT 1 FROM contacts sc WHERE sc.contact_id = $contactCol AND sc.contact_client_id IN (" . $this->deptList() . '))';
    }

    /** SQL condition (no leading AND): the requirement rule row $q touches an allowed department (rule 1 in the class comment). */
    private function ruleTouches(string $q): string
    {
        $d = $this->deptList();
        return "($q.requirement_all_people = 1
            OR EXISTS (SELECT 1 FROM training_requirement_criteria rd WHERE rd.rcrit_requirement_id = $q.requirement_id AND rd.rcrit_kind = 'department' AND rd.rcrit_value_id IN ($d))
            OR EXISTS (SELECT 1 FROM training_requirement_criteria rp JOIN contacts rpc ON rpc.contact_id = rp.rcrit_value_id
                WHERE rp.rcrit_requirement_id = $q.requirement_id AND rp.rcrit_kind = 'contact' AND rpc.contact_client_id IN ($d)))";
    }

    /** SQL (leading AND) limiting $courseCol to courses linked to an allowed department (rules 1 to 3 in the class comment). */
    private function courseScope(string $courseCol): string
    {
        if ($this->departments === null) {
            return '';
        }
        if ($this->departments === []) {
            return ' AND 1 = 0';
        }
        $d = $this->deptList();
        return " AND (EXISTS (SELECT 1 FROM training_requirements sq WHERE sq.requirement_course_id = $courseCol AND sq.requirement_archived_at IS NULL AND " . $this->ruleTouches('sq') . ")
            OR EXISTS (SELECT 1 FROM training_assignments sa JOIN contacts sac ON sac.contact_id = sa.tassign_contact_id WHERE sa.tassign_course_id = $courseCol AND sac.contact_client_id IN ($d))
            OR EXISTS (SELECT 1 FROM training_completions sk JOIN contacts skc ON skc.contact_id = sk.completion_contact_id WHERE sk.completion_course_id = $courseCol AND skc.contact_client_id IN ($d)))";
    }

    /** Whether the person (contacts.contact_id) is in the client's department scope. */
    public function personInScope(int $contactId): bool
    {
        return Db::one($this->db, 'SELECT 1 AS ok FROM dual WHERE 1 = 1' . $this->personScope((string) (int) $contactId), '', []) !== null;
    }

    /** Whether the course (training_courses.course_id, any publication state) is linked to the client's department scope. */
    public function courseInScope(int $courseId): bool
    {
        return Db::one($this->db, 'SELECT 1 AS ok FROM dual WHERE 1 = 1' . $this->courseScope((string) (int) $courseId), '', []) !== null;
    }

    /** True when ANY assignment (in scope or not) carries this external pair. Only used to answer a conflict identically in and out of scope. */
    public function externalRefTaken(string $system, string $ref): bool
    {
        return Db::one($this->db, 'SELECT 1 AS ok FROM training_assignments WHERE tassign_external_system = ? AND tassign_external_ref = ?', 'ss', [$system, $ref]) !== null;
    }

    // ---- shared parsing ---------------------------------------------------------------------------------------------

    /** @return array{limit:int, after:int, since_utc:?string, since_local:?string} */
    public static function paging(array $q): array
    {
        $limit = self::DEFAULT_LIMIT;
        if (isset($q['limit'])) {
            if (!is_string($q['limit']) || preg_match('/^[0-9]{1,4}$/D', $q['limit']) !== 1 || (int) $q['limit'] < 1) {
                throw new LmsApiException(400, 'invalid_limit', 'limit must be an integer from 1 to ' . self::MAX_LIMIT . '.');
            }
            $limit = min(self::MAX_LIMIT, (int) $q['limit']);
        }
        $after = 0;
        if (isset($q['cursor'])) {
            $raw = is_string($q['cursor']) ? IntegrationAuth::b64uDecode($q['cursor']) : null;
            $j = $raw === null ? null : json_decode($raw, true);
            if (!is_array($j) || !isset($j['after']) || !is_int($j['after']) || $j['after'] < 0) {
                throw new LmsApiException(400, 'invalid_cursor', 'cursor is not valid; use next_cursor from a previous response.');
            }
            $after = $j['after'];
        }
        $sinceUtc = $sinceLocal = null;
        if (isset($q['updated_since'])) {
            $dt = is_string($q['updated_since']) ? \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $q['updated_since'])
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339_EXTENDED, $q['updated_since']) : false;
            // createFromFormat() silently rolls an impossible date (month 13, day 45) over; refuse it instead.
            $warn = \DateTimeImmutable::getLastErrors();
            if ($dt !== false && is_array($warn) && ($warn['warning_count'] ?? 0) > 0) {
                $dt = false;
            }
            if ($dt === false) {
                throw new LmsApiException(400, 'invalid_updated_since', 'updated_since must be an RFC 3339 timestamp, e.g. 2026-10-01T00:00:00Z.');
            }
            $sinceUtc = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
            $sinceLocal = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        }
        return ['limit' => $limit, 'after' => $after, 'since_utc' => $sinceUtc, 'since_local' => $sinceLocal];
    }

    /** @param list<array<string,mixed>> $rows fetched with limit+1 */
    private static function page(array $rows, int $limit, string $idKey, callable $shape): array
    {
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $next = $more && $rows !== [] ? IntegrationAuth::b64uEncode(json_encode(['after' => (int) end($rows)[$idKey]])) : null;
        return ['data' => array_map($shape, $rows), 'next_cursor' => $next];
    }

    private static function isoUtc(?string $v): ?string
    {
        if ($v === null || $v === '' || str_starts_with($v, '0000')) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', str_contains($v, '.') ? $v : $v . '.000', new \DateTimeZone('UTC'));
        return $dt === false ? null : $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function isoLocal(?string $v): ?string
    {
        $iso = Clock::toIso($v, false);
        if ($iso === null) {
            return null;
        }
        return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function maxIso(?string ...$isos): ?string
    {
        $isos = array_values(array_filter($isos, static fn($v) => $v !== null));
        return $isos === [] ? null : max($isos);
    }

    // ---- courses ---------------------------------------------------------------------------------------------------

    private const COURSE_SELECT = 'SELECT c.course_id, c.course_uid, c.course_kind, c.course_code, c.course_external_code, c.course_name, c.course_summary,
            c.course_est_minutes, c.course_validity_months, c.course_updated_at, cat.tcat_name, r.revision_id, r.revision_number, r.revision_published_at_utc,
            JSON_VALUE(r.revision_json, CONCAT(\'$.course.text.\', JSON_VALUE(r.revision_json, \'$.course.default_language\'), \'.name\')) AS rev_name,
            JSON_VALUE(r.revision_json, CONCAT(\'$.course.text.\', JSON_VALUE(r.revision_json, \'$.course.default_language\'), \'.summary\')) AS rev_summary
        FROM training_courses c
        JOIN training_revisions r ON r.revision_id = c.course_current_revision_id
        LEFT JOIN training_categories cat ON cat.tcat_id = c.course_category_id
        WHERE c.course_archived_at IS NULL';

    public function courses(array $q): array
    {
        $p = self::paging($q);
        $sql = self::COURSE_SELECT . $this->courseScope('c.course_id') . ' AND c.course_id > ?';
        $types = 'i';
        $args = [$p['after']];
        if ($p['since_utc'] !== null) {
            $sql .= ' AND (r.revision_published_at_utc >= ? OR c.course_updated_at >= ?)';
            $types .= 'ss';
            array_push($args, $p['since_utc'], $p['since_local']);
        }
        $rows = Db::all($this->db, $sql . ' ORDER BY c.course_id LIMIT ' . ($p['limit'] + 1), $types, $args);
        $meta = $this->courseRequirements(array_map(static fn($r) => (int) $r['course_id'], array_slice($rows, 0, $p['limit'])));
        return self::page($rows, $p['limit'], 'course_id', fn(array $r) => $this->shapeCourse($r, $meta[(int) $r['course_id']] ?? ['requirements' => [], 'departments' => []]));
    }

    public function course(string $uid): ?array
    {
        if (preg_match('/^[A-Za-z0-9]{12}$/D', $uid) !== 1) {
            return null;
        }
        $r = Db::one($this->db, self::COURSE_SELECT . $this->courseScope('c.course_id') . ' AND c.course_uid = ?', 's', [$uid]);
        if ($r === null) {
            return null;
        }
        $meta = $this->courseRequirements([(int) $r['course_id']]);
        return $this->shapeCourse($r, $meta[(int) $r['course_id']] ?? ['requirements' => [], 'departments' => []]);
    }

    /** @param list<int> $courseIds @return array<int, array{requirements:list<array>, departments:list<string>}> */
    private function courseRequirements(array $courseIds): array
    {
        $out = [];
        if ($courseIds === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($courseIds), '?'));
        $t = str_repeat('i', count($courseIds));
        // A restricted client sees only the rules that touch its departments (never another department's rule or name).
        $ruleScope = $this->departments === null ? '' : ' AND ' . ($this->departments === [] ? '1 = 0' : $this->ruleTouches('training_requirements'));
        $deptScope = $this->departments === null ? '' : ' AND rc.rcrit_value_id IN (' . ($this->departments === [] ? '0' : $this->deptList()) . ')';
        foreach (Db::all($this->db, "SELECT requirement_id, requirement_course_id, requirement_name, requirement_required, requirement_one_time, requirement_all_people
            FROM training_requirements WHERE requirement_course_id IN ($in) AND requirement_archived_at IS NULL AND requirement_is_manual = 0$ruleScope
            ORDER BY requirement_id", $t, $courseIds) as $r) {
            $out[(int) $r['requirement_course_id']]['requirements'][] = [
                'name' => (string) $r['requirement_name'],
                'required' => (int) $r['requirement_required'] === 1,
                'one_time' => (int) $r['requirement_one_time'] === 1,
                'all_people' => (int) $r['requirement_all_people'] === 1,
            ];
        }
        foreach (Db::all($this->db, "SELECT DISTINCT q.requirement_course_id, rc.rcrit_value_label FROM training_requirements q
            JOIN training_requirement_criteria rc ON rc.rcrit_requirement_id = q.requirement_id AND rc.rcrit_kind = 'department'
            WHERE q.requirement_course_id IN ($in) AND q.requirement_archived_at IS NULL AND q.requirement_is_manual = 0
            AND rc.rcrit_value_label IS NOT NULL$deptScope ORDER BY rc.rcrit_value_label", $t, $courseIds) as $r) {
            $out[(int) $r['requirement_course_id']]['departments'][] = (string) $r['rcrit_value_label'];
        }
        return $out;
    }

    private function shapeCourse(array $r, array $meta): array
    {
        return [
            'id' => (string) $r['course_uid'],
            'kind' => (string) $r['course_kind'],
            'code' => $r['course_code'],
            'external_code' => $r['course_external_code'],
            // the PUBLISHED revision's text (what learners see), falling back to the course row for a revision without it
            'title' => (string) (($r['rev_name'] ?? '') !== '' ? $r['rev_name'] : $r['course_name']),
            'description' => ($r['rev_summary'] ?? '') !== '' ? $r['rev_summary'] : $r['course_summary'],
            'status' => 'published',
            'revision' => (int) $r['revision_number'],
            'revision_id' => (int) $r['revision_id'],
            'departments' => $meta['departments'] ?? [],
            'est_minutes' => $r['course_est_minutes'] === null ? null : (int) $r['course_est_minutes'],
            'validity_months' => $r['course_validity_months'] === null ? null : (int) $r['course_validity_months'],
            'category' => $r['tcat_name'],
            'requirements' => $meta['requirements'] ?? [],
            'updated_at' => self::maxIso(self::isoUtc($r['revision_published_at_utc']), self::isoLocal($r['course_updated_at'])),
        ];
    }

    // ---- assignments -----------------------------------------------------------------------------------------------

    private const ASSIGNMENT_SELECT = 'SELECT a.tassign_id, a.tassign_contact_id, a.tassign_course_id, a.tassign_status, a.tassign_reason, a.tassign_required,
            a.tassign_due_on, a.tassign_original_due_on, a.tassign_completion_id, a.tassign_created_at_utc, a.tassign_closed_at_utc,
            a.tassign_close_reason, a.tassign_reopened_count, a.tassign_updated_at, a.tassign_external_system, a.tassign_external_ref,
            c.course_uid, r.revision_number,
            (SELECT MIN(l.odoo_employee_id) FROM contact_odoo_links l WHERE l.contact_id = a.tassign_contact_id) AS odoo_employee_id
        FROM training_assignments a
        JOIN training_courses c ON c.course_id = a.tassign_course_id
        LEFT JOIN training_revisions r ON r.revision_id = c.course_current_revision_id
        WHERE 1 = 1';

    public function assignments(array $q): array
    {
        $p = self::paging($q);
        $sql = self::ASSIGNMENT_SELECT . $this->personScope('a.tassign_contact_id') . ' AND a.tassign_id > ?';
        $types = 'i';
        $args = [$p['after']];
        $str = static fn($k) => isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '' ? $q[$k] : null;
        if (($v = $str('employee')) !== null) {
            $sql .= ' AND a.tassign_contact_id = ?';
            $types .= 'i';
            $args[] = ctype_digit($v) ? (int) $v : 0;
        }
        if (($v = $str('odoo_employee_id')) !== null) {
            $sql .= ' AND a.tassign_contact_id IN (SELECT l.contact_id FROM contact_odoo_links l WHERE l.odoo_employee_id = ?)';
            $types .= 'i';
            $args[] = ctype_digit($v) ? (int) $v : 0;
        }
        if (($v = $str('course')) !== null) {
            $sql .= ' AND c.course_uid = ?';
            $types .= 's';
            $args[] = $v;
        }
        if (($v = $str('status')) !== null) {
            if (!in_array($v, ['open', 'completed', 'waived', 'cancelled'], true)) {
                throw new LmsApiException(400, 'invalid_status', 'status must be open, completed, waived or cancelled.');
            }
            $sql .= ' AND a.tassign_status = ?';
            $types .= 's';
            $args[] = $v;
        }
        foreach (['external_ref' => 'a.tassign_external_ref', 'external_system' => 'a.tassign_external_system'] as $k => $col) {
            if (($v = $str($k)) !== null) {
                $sql .= " AND $col = ?";
                $types .= 's';
                $args[] = $v;
            }
        }
        if ($p['since_utc'] !== null) {
            $sql .= ' AND (a.tassign_created_at_utc >= ? OR a.tassign_closed_at_utc >= ? OR a.tassign_updated_at >= ?)';
            $types .= 'sss';
            array_push($args, $p['since_utc'], $p['since_utc'], $p['since_local']);
        }
        $rows = Db::all($this->db, $sql . ' ORDER BY a.tassign_id LIMIT ' . ($p['limit'] + 1), $types, $args);
        return self::page($rows, $p['limit'], 'tassign_id', fn(array $r) => $this->shapeAssignment($r));
    }

    public function assignment(int $id): ?array
    {
        $r = Db::one($this->db, self::ASSIGNMENT_SELECT . $this->personScope('a.tassign_contact_id') . ' AND a.tassign_id = ?', 'i', [$id]);
        return $r === null ? null : $this->shapeAssignment($r);
    }

    public function assignmentByExternal(string $system, string $ref): ?array
    {
        $r = Db::one($this->db, self::ASSIGNMENT_SELECT . $this->personScope('a.tassign_contact_id') . ' AND a.tassign_external_system = ? AND a.tassign_external_ref = ?', 'ss', [$system, $ref]);
        return $r === null ? null : $this->shapeAssignment($r);
    }

    public function openAssignmentFor(int $contactId, int $courseId): ?array
    {
        $r = Db::one($this->db, self::ASSIGNMENT_SELECT . $this->personScope('a.tassign_contact_id') . " AND a.tassign_contact_id = ? AND a.tassign_course_id = ? AND a.tassign_status = 'open'", 'ii', [$contactId, $courseId]);
        return $r === null ? null : $this->shapeAssignment($r);
    }

    private function shapeAssignment(array $r): array
    {
        $status = (string) $r['tassign_status'];
        $closed = $status !== 'open';
        $revision = 1 + (int) $r['tassign_reopened_count'] + ($closed ? 1 : 0) + ((string) $r['tassign_due_on'] !== (string) $r['tassign_original_due_on'] ? 1 : 0);
        return [
            'id' => 'asg_' . (int) $r['tassign_id'],
            'employee' => ['rivetit_id' => (string) (int) $r['tassign_contact_id'], 'odoo_employee_id' => $r['odoo_employee_id'] === null ? null : (string) (int) $r['odoo_employee_id']],
            'course_id' => (string) $r['course_uid'],
            'course_revision' => $r['revision_number'] === null ? null : (int) $r['revision_number'],
            'status' => $status,
            'overdue' => $status === 'open' && (string) $r['tassign_due_on'] < Clock::todayLocal(),
            'required' => (int) $r['tassign_required'] === 1,
            'reason' => (string) $r['tassign_reason'],
            'due_on' => (string) $r['tassign_due_on'],
            'original_due_on' => (string) $r['tassign_original_due_on'],
            'external_system' => $r['tassign_external_system'],
            'external_ref' => $r['tassign_external_ref'],
            'completion_id' => $r['tassign_completion_id'] === null ? null : 'cmp_' . (int) $r['tassign_completion_id'],
            'created_at' => self::isoUtc($r['tassign_created_at_utc']),
            'closed_at' => self::isoUtc($r['tassign_closed_at_utc']),
            'close_reason' => $r['tassign_close_reason'],
            'updated_at' => self::maxIso(self::isoUtc($r['tassign_created_at_utc']), self::isoUtc($r['tassign_closed_at_utc']), self::isoLocal($r['tassign_updated_at'])),
            'revision' => $revision,
        ];
    }

    // ---- completions -----------------------------------------------------------------------------------------------

    private const COMPLETION_SELECT = 'SELECT tc.completion_id, tc.completion_contact_id, tc.completion_course_id, tc.completion_revision_id, tc.completion_snap_revision_number,
            tc.completion_assignment_id, tc.completion_completed_on, tc.completion_expires_on, tc.completion_score_pct, tc.completion_pass_mark_pct,
            tc.completion_method, tc.completion_cert_number, tc.completion_external_issuer, tc.completion_external_ref, tc.completion_evidence_media_id,
            tc.completion_supersedes_id, tc.completion_recorded_at_utc, tc.completion_odoo_employee_id,
            c.course_uid, v.cvoid_at_utc,
            (SELECT MIN(s.completion_recorded_at_utc) FROM training_completions s WHERE s.completion_supersedes_id = tc.completion_id) AS superseded_at_utc,
            a.tassign_external_system, a.tassign_external_ref,
            (SELECT MIN(l.odoo_employee_id) FROM contact_odoo_links l WHERE l.contact_id = tc.completion_contact_id) AS link_odoo_employee_id
        FROM training_completions tc
        JOIN training_courses c ON c.course_id = tc.completion_course_id
        LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = tc.completion_id
        LEFT JOIN training_assignments a ON a.tassign_id = tc.completion_assignment_id
        WHERE 1 = 1';

    public function completions(array $q): array
    {
        $p = self::paging($q);
        $sql = self::COMPLETION_SELECT . $this->personScope('tc.completion_contact_id') . ' AND tc.completion_id > ?';
        $types = 'i';
        $args = [$p['after']];
        $str = static fn($k) => isset($q[$k]) && is_string($q[$k]) && $q[$k] !== '' ? $q[$k] : null;
        if (($v = $str('employee')) !== null) {
            $sql .= ' AND tc.completion_contact_id = ?';
            $types .= 'i';
            $args[] = ctype_digit($v) ? (int) $v : 0;
        }
        if (($v = $str('odoo_employee_id')) !== null) {
            $sql .= ' AND tc.completion_contact_id IN (SELECT l.contact_id FROM contact_odoo_links l WHERE l.odoo_employee_id = ?)';
            $types .= 'i';
            $args[] = ctype_digit($v) ? (int) $v : 0;
        }
        if (($v = $str('course')) !== null) {
            $sql .= ' AND c.course_uid = ?';
            $types .= 's';
            $args[] = $v;
        }
        if (($v = $str('assignment')) !== null) {
            $sql .= ' AND tc.completion_assignment_id = ?';
            $types .= 'i';
            $args[] = preg_match('/^asg_([0-9]{1,10})$/D', $v, $m) === 1 ? (int) $m[1] : 0;
        }
        if (($v = $str('status')) !== null) {
            $cond = match ($v) {
                'voided' => 'v.cvoid_id IS NOT NULL',
                'superseded' => 'v.cvoid_id IS NULL AND EXISTS (SELECT 1 FROM training_completions s WHERE s.completion_supersedes_id = tc.completion_id)',
                'completed' => 'v.cvoid_id IS NULL AND NOT EXISTS (SELECT 1 FROM training_completions s WHERE s.completion_supersedes_id = tc.completion_id)',
                default => throw new LmsApiException(400, 'invalid_status', 'status must be completed, voided or superseded.'),
            };
            $sql .= " AND $cond";
        }
        if ($p['since_utc'] !== null) {
            $sql .= ' AND (tc.completion_recorded_at_utc >= ? OR v.cvoid_at_utc >= ? OR EXISTS (SELECT 1 FROM training_completions s2
                WHERE s2.completion_supersedes_id = tc.completion_id AND s2.completion_recorded_at_utc >= ?))';
            $types .= 'sss';
            array_push($args, $p['since_utc'], $p['since_utc'], $p['since_utc']);
        }
        $rows = Db::all($this->db, $sql . ' ORDER BY tc.completion_id LIMIT ' . ($p['limit'] + 1), $types, $args);
        return self::page($rows, $p['limit'], 'completion_id', fn(array $r) => $this->shapeCompletion($r));
    }

    public function completion(int $id): ?array
    {
        $r = Db::one($this->db, self::COMPLETION_SELECT . $this->personScope('tc.completion_contact_id') . ' AND tc.completion_id = ?', 'i', [$id]);
        return $r === null ? null : $this->shapeCompletion($r);
    }

    private function shapeCompletion(array $r): array
    {
        $voided = $r['cvoid_at_utc'] !== null;
        $superseded = !$voided && $r['superseded_at_utc'] !== null;
        $odoo = $r['completion_odoo_employee_id'] ?? $r['link_odoo_employee_id'];
        $hasEvidence = $r['completion_evidence_media_id'] !== null;
        $id = (int) $r['completion_id'];
        return [
            'id' => 'cmp_' . $id,
            'employee' => ['rivetit_id' => (string) (int) $r['completion_contact_id'], 'odoo_employee_id' => $odoo === null ? null : (string) (int) $odoo],
            'course_id' => (string) $r['course_uid'],
            'course_revision' => $r['completion_snap_revision_number'] === null ? null : (int) $r['completion_snap_revision_number'],
            'course_revision_id' => $r['completion_revision_id'] === null ? null : (int) $r['completion_revision_id'],
            'assignment_id' => $r['completion_assignment_id'] === null ? null : 'asg_' . (int) $r['completion_assignment_id'],
            'assignment' => $r['completion_assignment_id'] === null ? null : [
                'external_system' => $r['tassign_external_system'], 'external_ref' => $r['tassign_external_ref'],
            ],
            'completed_on' => (string) $r['completion_completed_on'],
            'result' => 'passed',
            'score_pct' => $r['completion_score_pct'] === null ? null : (float) $r['completion_score_pct'],
            'pass_mark_pct' => $r['completion_pass_mark_pct'] === null ? null : (int) $r['completion_pass_mark_pct'],
            'method' => (string) $r['completion_method'],
            'status' => $voided ? 'voided' : ($superseded ? 'superseded' : 'completed'),
            'supersedes_id' => $r['completion_supersedes_id'] === null ? null : 'cmp_' . (int) $r['completion_supersedes_id'],
            'expires_on' => $r['completion_expires_on'],
            'evidence_ref' => [
                'certificate_number' => $r['completion_cert_number'],
                'external_issuer' => $r['completion_external_issuer'],
                'external_ref' => $r['completion_external_ref'],
                'evidence_available' => $hasEvidence,
                'evidence_path' => $hasEvidence ? "/api/v1/lms/completions/cmp_$id/evidence" : null,
            ],
            'recorded_at' => self::isoUtc($r['completion_recorded_at_utc']),
            'updated_at' => self::maxIso(self::isoUtc($r['completion_recorded_at_utc']), self::isoUtc($r['cvoid_at_utc']), self::isoUtc($r['superseded_at_utc'])),
            'revision' => 1 + ($voided ? 1 : 0) + ($superseded ? 1 : 0),
        ];
    }

    /**
     * The evidence scan of a completion as a training_media row, or null when there is none or the service context may not serve it
     * (EvidenceStore::canServe with Scope::all()). Missing and forbidden are the same answer.
     */
    public function evidenceMedia(int $completionId): ?array
    {
        $r = Db::one($this->db, 'SELECT tc.completion_evidence_media_id FROM training_completions tc WHERE tc.completion_id = ?' . $this->personScope('tc.completion_contact_id'), 'i', [$completionId]);
        $mid = (int) ($r['completion_evidence_media_id'] ?? 0);
        if ($mid < 1) {
            return null;
        }
        $ctx = self::serviceCtx($this->db, 'lms-api');
        $store = new MediaStore($ctx);
        $row = $store->get($mid);
        if ($row === null || !EvidenceStore::canServe($ctx, Scope::all(), $row)
            || preg_match(MediaStore::PATH_RE, (string) $row['media_path']) !== 1 || !str_starts_with((string) $row['media_path'], 'evidence/') || !$store->fileOk($row)) {
            return null;
        }
        $row['_absolute_path'] = $store->absolutePath($row);
        return $row;
    }

    /** The explicit service context: no session, no user (id 0), Training level 3 (read everything the scope allows). */
    public static function serviceCtx(\mysqli $db, string $agent): \ITFlow\Training\Core\Ctx
    {
        return SystemCtx::make($db, 0, $agent);
    }
}
