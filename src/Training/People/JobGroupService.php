<?php

namespace ITFlow\Training\People;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;

/**
 * Job groups (S10, v0 §8.2): a named set of people = explicit members ∪ contacts whose normalized
 * title (LOWER(TRIM(contact_title))) is in the group's title list, so "welder", "Welder " and a
 * misspelt "machinest" can be ticked together and new hires with those titles join automatically.
 * Rules can target a job group (criteria kind `jobgroup`).
 *
 * Department groups (DepartmentGroups): one per active department, linked by a reserved marker row
 * in training_job_group_titles. Their members are the department's contacts, live (membership()
 * joins them), also while the department is archived (the same people a Department condition
 * matches); they are read-only here (save/archive refuse them) and never show the marker. Like the
 * department lists elsewhere in Training, a caller who may not see a department does not see its
 * group either (list() leaves it out, get() is 404).
 */
final class JobGroupService
{
    public const MAX_MEMBERS = 1000;
    public const MAX_TITLES = 200;

    /**
     * (cid, gid) for department groups: the marker " #dept:<client_id>" joined to the contacts whose
     * department is that client right now, archived department or not (exactly the people a Department
     * condition matches). Callers append the group filter (AND g.… ).
     */
    private const DEPT_MEMBERS_SQL = "SELECT c.contact_id AS cid, t.jgtitle_jobgroup_id AS gid FROM training_job_group_titles t
            JOIN training_job_groups g ON g.jobgroup_id = t.jgtitle_jobgroup_id
            JOIN contacts c ON c.contact_client_id > 0 AND t.jgtitle_normalized = CONCAT('" . DepartmentGroups::MARKER_PREFIX . "', c.contact_client_id)
            WHERE " . DepartmentGroups::SQL_MARKER_ROW;

    /**
     * A department group matches while it is active, and also while it is archived with its department
     * (its people stay matched, like a Department condition's). Only a retired duplicate - a group whose
     * marker a lower group id also carries (DepartmentGroups::canonical) - matches no one.
     */
    private const DEPT_LIVE_SQL = '(g.jobgroup_archived_at IS NULL OR NOT EXISTS (SELECT 1 FROM training_job_group_titles t2
            WHERE t2.jgtitle_normalized = t.jgtitle_normalized AND t2.jgtitle_jobgroup_id < t.jgtitle_jobgroup_id))';

    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * contact_id => list<jobgroup_id> over active groups (members ∪ title matches) and department groups
     * (everyone whose contact_client_id is the group's department, archived department or not; see DEPT_LIVE_SQL).
     */
    public static function membership(\mysqli $db): array
    {
        $out = [];
        $rows = Db::all($db, 'SELECT m.jgmember_contact_id AS cid, m.jgmember_jobgroup_id AS gid FROM training_job_group_members m
            JOIN training_job_groups g ON g.jobgroup_id = m.jgmember_jobgroup_id AND g.jobgroup_archived_at IS NULL
            UNION
            SELECT c.contact_id AS cid, t.jgtitle_jobgroup_id AS gid FROM training_job_group_titles t
            JOIN training_job_groups g ON g.jobgroup_id = t.jgtitle_jobgroup_id AND g.jobgroup_archived_at IS NULL
            JOIN contacts c ON LOWER(TRIM(c.contact_title)) = t.jgtitle_normalized
            WHERE ' . DepartmentGroups::SQL_TITLE_ROW . '
            UNION
            ' . self::DEPT_MEMBERS_SQL . ' AND ' . self::DEPT_LIVE_SQL);
        foreach ($rows as $r) {
            $out[(int) $r['cid']][] = (int) $r['gid'];
        }
        foreach ($out as &$g) {
            $g = array_values(array_unique($g));
            sort($g);
        }
        unset($g);
        return $out;
    }

    /** "  Welder " => "welder": exactly SQL LOWER(TRIM(contact_title)), which membership() joins on. */
    public static function normalizeTitle(string $t): string
    {
        return mb_strtolower(trim($t, ' '), 'UTF-8');
    }

    /**
     * Department groups first, then hand-made ones, each by name. A department group whose department is
     * outside $s is left out (fail-closed, like every department list in Training); hand-made groups are
     * listed for everyone, with matched counted in $s.
     * member_count = named people (hand-made) or the department's live head count (department groups).
     *
     * @return list<array{id,name,description,version,archived,member_count,title_count,matched,auto:bool,
     *         department:?array{id:int,name:string,status:string}}> matched = eligible people in $s;
     *         department.status = active | archived | lead | deleted
     */
    public function list(?Scope $s = null, bool $includeArchived = false): array
    {
        $db = $this->c->db;
        $s ??= Scope::forCtx($this->c);
        $rows = Db::all($db, 'SELECT g.jobgroup_id, g.jobgroup_name, g.jobgroup_description, g.jobgroup_version, g.jobgroup_archived_at,
                (SELECT COUNT(*) FROM training_job_group_members m WHERE m.jgmember_jobgroup_id = g.jobgroup_id) AS member_count,
                (SELECT COUNT(*) FROM training_job_group_titles t WHERE t.jgtitle_jobgroup_id = g.jobgroup_id AND ' . DepartmentGroups::SQL_TITLE_ROW . ') AS title_count
            FROM training_job_groups g' . ($includeArchived ? '' : ' WHERE g.jobgroup_archived_at IS NULL') . ' ORDER BY g.jobgroup_name');
        $matched = [];
        foreach (Directory::load($db, $s) as $p) {
            foreach ($p['jobgroup_ids'] as $gid) {
                $matched[$gid] = ($matched[$gid] ?? 0) + 1;
            }
        }
        $links = DepartmentGroups::links($db);
        $depts = DepartmentGroups::departments($db, array_values($links));
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['jobgroup_id'];
            $cid = $links[$id] ?? null;
            if ($cid !== null && !$s->allows($cid)) {
                continue;   // not one of the caller's departments: neither its name nor its head count is theirs to see
            }
            $out[] = [
                'id' => $id,
                'name' => (string) $r['jobgroup_name'],
                'description' => $r['jobgroup_description'],
                'version' => (int) $r['jobgroup_version'],
                'archived' => $r['jobgroup_archived_at'] !== null,
                'member_count' => $cid !== null ? (int) ($depts[$cid]['people'] ?? 0) : (int) $r['member_count'],
                'title_count' => (int) $r['title_count'],
                'matched' => $matched[$id] ?? 0,
                'auto' => $cid !== null,
                'department' => $cid !== null ? self::deptRef($cid, $depts[$cid] ?? null, (string) $r['jobgroup_name']) : null,
            ];
        }
        usort($out, static fn($a, $b) => (int) $b['auto'] <=> (int) $a['auto']);   // stable: name order kept within each kind
        return $out;
    }

    /**
     * The group with its in-scope members (PersonRef), titles (with in-scope counts) and the active rules
     * that target it (rules: [{id, name, course}], at most 50). A department group outside $s is 404.
     */
    public function get(int $id, ?Scope $s = null): array
    {
        $db = $this->c->db;
        $s ??= Scope::forCtx($this->c);
        $g = Db::one($db, 'SELECT jobgroup_id, jobgroup_name, jobgroup_description, jobgroup_version, jobgroup_archived_at, jobgroup_created_at
            FROM training_job_groups WHERE jobgroup_id = ?', 'i', [$id]);
        $cid = DepartmentGroups::departmentOf($db, $id);
        if ($g === null || ($cid !== null && !$s->allows($cid))) {
            throw ApiException::notFound('That job group was not found.');
        }
        $memberIds = array_map(static fn($r) => (int) $r['jgmember_contact_id'],
            Db::all($db, 'SELECT jgmember_contact_id FROM training_job_group_members WHERE jgmember_jobgroup_id = ? ORDER BY jgmember_contact_id', 'i', [$id]));
        $titles = array_map(static fn($r) => (string) $r['jgtitle_normalized'],
            Db::all($db, 'SELECT t.jgtitle_normalized FROM training_job_group_titles t WHERE t.jgtitle_jobgroup_id = ? AND ' . DepartmentGroups::SQL_TITLE_ROW
                . ' ORDER BY t.jgtitle_normalized', 'i', [$id]));
        $members = [];
        foreach (Directory::load($db, $s, $memberIds, true) as $p) {
            $members[] = Directory::ref($p);
        }
        $counts = [];
        foreach ($this->titles($s) as $t) {
            $counts[$t['title']] = $t['count'];
        }
        $matched = 0;
        $people = [];
        foreach (Directory::load($db, $s) as $p) {
            if (in_array($id, $p['jobgroup_ids'], true)) {
                $matched++;
                if ($cid !== null && count($people) < self::MAX_MEMBERS) {
                    $people[] = Directory::ref($p);
                }
            }
        }
        $out = [
            'id' => (int) $g['jobgroup_id'],
            'name' => (string) $g['jobgroup_name'],
            'description' => $g['jobgroup_description'],
            'version' => (int) $g['jobgroup_version'],
            'archived' => $g['jobgroup_archived_at'] !== null,
            'members' => $members,
            'members_hidden' => count($memberIds) - count($members),
            'titles' => array_map(static fn($t) => ['title' => $t, 'count' => $counts[$t] ?? 0], $titles),
            'matched' => $matched,
            'auto' => $cid !== null,
            'department' => null,
            'rules' => $this->rulesUsing($id),
        ];
        if ($cid !== null) {
            // A department group: its people are the department's, live (in scope, on the roster).
            $dept = DepartmentGroups::departments($db, [$cid])[$cid] ?? null;
            $out['department'] = self::deptRef($cid, $dept, (string) $g['jobgroup_name']);
            $out['member_count'] = (int) ($dept['people'] ?? 0);
            $out['people'] = $people;
        }
        return $out;
    }

    /**
     * Create ($id null) or update (optimistic $version). data: name, description, members[], titles[].
     * tx: row ; replace members + titles ; Ledger jobgroup.saved ; commit ; reconcile(affected, 'jobgroup').
     */
    public function save(?int $id, ?int $version, array $data): array
    {
        $db = $this->c->db;
        if ($id !== null) {
            $this->refuseDepartmentGroup($id);   // before field validation, so the reason is the one that matters (checked again under the row lock)
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || !mb_check_encoding($name, 'UTF-8')) {
            throw ApiException::validation(['name' => 'Give the group a name (at most 100 characters).']);
        }
        $desc = isset($data['description']) ? trim((string) $data['description']) : '';
        if (mb_strlen($desc, 'UTF-8') > 255 || !mb_check_encoding($desc, 'UTF-8')) {
            throw ApiException::validation(['description' => 'Too long (at most 255 characters).']);
        }
        $members = array_values(array_unique(array_filter(array_map('intval', (array) ($data['members'] ?? [])), static fn($i) => $i > 0)));
        sort($members);
        if (count($members) > self::MAX_MEMBERS) {
            throw ApiException::validation(['members' => 'Too many people (at most ' . self::MAX_MEMBERS . ').']);
        }
        $titles = [];
        foreach ((array) ($data['titles'] ?? []) as $t) {
            if (!is_string($t) || !mb_check_encoding($t, 'UTF-8')) {
                throw ApiException::validation(['titles' => 'Titles must be text.']);
            }
            $n = self::normalizeTitle($t);
            if ($n === '') {
                continue;
            }
            if (DepartmentGroups::isReservedTitle($n)) {
                throw ApiException::validation(['titles' => 'A title cannot start with "#dept:". That is reserved for department groups.']);
            }
            if (mb_strlen($n, 'UTF-8') > 200) {
                throw ApiException::validation(['titles' => 'A title is too long (at most 200 characters).']);
            }
            $titles[$n] = $n;
        }
        ksort($titles, SORT_STRING);
        $titles = array_values($titles);
        if (count($titles) > self::MAX_TITLES) {
            throw ApiException::validation(['titles' => 'Too many titles (at most ' . self::MAX_TITLES . ').']);
        }
        if ($members !== []) {
            $found = Db::all($db, 'SELECT contact_id FROM contacts WHERE contact_id IN (' . implode(',', array_fill(0, count($members), '?')) . ')',
                str_repeat('i', count($members)), $members);
            if (count($found) !== count($members)) {
                throw ApiException::validation(['members' => 'One of those people no longer exists.']);
            }
        }
        if ($id !== null && $version === null) {
            throw ApiException::validation(['version' => 'Required.']);
        }

        $before = $id === null ? [] : $this->membersOf($id);
        $gid = Db::tx($db, function () use ($db, $id, $version, $name, $desc, $members, $titles): int {
            $this->refuseTakenName($name, $id);
            if ($id === null) {
                $gid = $this->uniqueName(fn() => Db::insert($db, 'INSERT INTO training_job_groups (jobgroup_name, jobgroup_description, jobgroup_version, jobgroup_created_by) VALUES (?, ?, 1, ?)',
                    'ssi', [$name, $desc === '' ? null : $desc, $this->c->userId]));
            } else {
                $cur = Db::one($db, 'SELECT jobgroup_id, jobgroup_version, jobgroup_archived_at FROM training_job_groups WHERE jobgroup_id = ? FOR UPDATE', 'i', [$id]);
                if ($cur === null) {
                    throw ApiException::notFound('That job group was not found.');
                }
                $this->refuseDepartmentGroup($id);
                if ($cur['jobgroup_archived_at'] !== null) {
                    throw new ApiException(409, 'archived', 'This job group is archived.');
                }
                if ((int) $cur['jobgroup_version'] !== $version) {
                    throw ApiException::conflict(['id' => $id, 'version' => (int) $cur['jobgroup_version']]);
                }
                $this->uniqueName(fn() => Db::exec($db, 'UPDATE training_job_groups SET jobgroup_name = ?, jobgroup_description = ?, jobgroup_version = jobgroup_version + 1 WHERE jobgroup_id = ?',
                    'ssi', [$name, $desc === '' ? null : $desc, $id]));
                Db::exec($db, 'DELETE FROM training_job_group_members WHERE jgmember_jobgroup_id = ?', 'i', [$id]);
                Db::exec($db, 'DELETE t FROM training_job_group_titles t WHERE t.jgtitle_jobgroup_id = ? AND ' . DepartmentGroups::SQL_TITLE_ROW, 'i', [$id]);
                $gid = $id;
            }
            foreach ($members as $m) {
                Db::exec($db, 'INSERT INTO training_job_group_members (jgmember_jobgroup_id, jgmember_contact_id, jgmember_added_by) VALUES (?, ?, ?)',
                    'iii', [$gid, $m, $this->c->userId]);
            }
            foreach ($titles as $t) {
                Db::exec($db, 'INSERT INTO training_job_group_titles (jgtitle_jobgroup_id, jgtitle_normalized) VALUES (?, ?)', 'is', [$gid, $t]);
            }
            Ledger::append($db, [
                'type' => 'jobgroup.saved',
                'actor_type' => 'user',
                'actor_user_id' => $this->c->userId,
                'entity_type' => 'jobgroup',
                'entity_id' => $gid,
                'payload' => ['name' => $name, 'member_count' => count($members), 'titles' => $titles],
                'user_agent' => $this->c->userAgent,
            ]);
            return $gid;
        });
        $affected = array_values(array_unique(array_merge($before, $this->membersOf($gid))));
        $group = $this->get($gid, Scope::forCtx($this->c));
        $group['reconcile'] = $this->reconcileIfUsed($gid, $affected);
        return $group;
    }

    public function archive(int $id): array
    {
        $db = $this->c->db;
        $before = $this->membersOf($id);
        Db::tx($db, function () use ($db, $id): void {
            $cur = Db::one($db, 'SELECT jobgroup_name, jobgroup_archived_at FROM training_job_groups WHERE jobgroup_id = ? FOR UPDATE', 'i', [$id]);
            if ($cur === null) {
                throw ApiException::notFound('That job group was not found.');
            }
            $this->refuseDepartmentGroup($id);
            if ($cur['jobgroup_archived_at'] !== null) {
                throw new ApiException(409, 'archived', 'This job group is already archived.');
            }
            Db::exec($db, 'UPDATE training_job_groups SET jobgroup_archived_at = NOW(), jobgroup_version = jobgroup_version + 1 WHERE jobgroup_id = ?', 'i', [$id]);
            $titles = array_map(static fn($r) => (string) $r['jgtitle_normalized'],
                Db::all($db, 'SELECT t.jgtitle_normalized FROM training_job_group_titles t WHERE t.jgtitle_jobgroup_id = ? AND ' . DepartmentGroups::SQL_TITLE_ROW
                    . ' ORDER BY t.jgtitle_normalized', 'i', [$id]));
            $count = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_job_group_members WHERE jgmember_jobgroup_id = ?', 'i', [$id])['n'] ?? 0);
            Ledger::append($db, [
                'type' => 'jobgroup.archived',
                'actor_type' => 'user',
                'actor_user_id' => $this->c->userId,
                'entity_type' => 'jobgroup',
                'entity_id' => $id,
                'payload' => ['name' => (string) $cur['jobgroup_name'], 'member_count' => $count, 'titles' => $titles],
                'user_agent' => $this->c->userAgent,
            ]);
        });
        return ['reconcile' => $this->reconcileIfUsed($id, $before)];
    }

    /** Distinct normalized titles of eligible in-scope people, with counts. @return list<{title,count}> */
    public function titles(Scope $s): array
    {
        if ($s->isNone()) {
            return [];
        }
        [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
        $rows = Db::all($this->c->db, "SELECT LOWER(TRIM(c.contact_title)) AS t, COUNT(*) AS n FROM contacts c " . Roster::JOIN . "
            WHERE " . Roster::ELIGIBLE . $scopeSql . " AND c.contact_title IS NOT NULL AND TRIM(c.contact_title) <> ''
            GROUP BY LOWER(TRIM(c.contact_title)) ORDER BY t", $types, $params);
        $out = [];
        foreach ($rows as $r) {
            $t = self::normalizeTitle((string) $r['t']);
            $out[$t] = ['title' => $t, 'count' => ($out[$t]['count'] ?? 0) + (int) $r['n']];
        }
        return array_values($out);
    }

    /** Every contact currently in group $id (members ∪ title matches ∪ department, whether or not the group is archived). */
    private function membersOf(int $id): array
    {
        $rows = Db::all($this->c->db, 'SELECT jgmember_contact_id AS cid FROM training_job_group_members WHERE jgmember_jobgroup_id = ?
            UNION SELECT c.contact_id FROM training_job_group_titles t JOIN contacts c ON LOWER(TRIM(c.contact_title)) = t.jgtitle_normalized
            WHERE t.jgtitle_jobgroup_id = ? AND ' . DepartmentGroups::SQL_TITLE_ROW . '
            UNION SELECT x.cid FROM (' . self::DEPT_MEMBERS_SQL . ' AND g.jobgroup_id = ?) x', 'iii', [$id, $id, $id]);
        return array_map(static fn($r) => (int) $r['cid'], $rows);
    }

    /**
     * For rules: the state of each group. live = it can match people: an active group, or an archived
     * department group whose department still exists (archived with the department, its people stay
     * matched like a Department condition's). An archived hand-made group, the group of a deleted
     * department and a retired duplicate match no one.
     *
     * @param list<int>|null $gids null = every group
     * @return array<int, array{name:string, archived:bool, auto:bool, live:bool, department_status:?string}>
     */
    public static function states(\mysqli $db, ?array $gids = null): array
    {
        $sql = 'SELECT jobgroup_id, jobgroup_name, jobgroup_archived_at FROM training_job_groups';
        $types = '';
        $params = [];
        if ($gids !== null) {
            $gids = array_values(array_unique(array_filter(array_map('intval', $gids), static fn($i) => $i > 0)));
            if ($gids === []) {
                return [];
            }
            $sql .= ' WHERE jobgroup_id IN (' . implode(',', array_fill(0, count($gids), '?')) . ')';
            $types = str_repeat('i', count($gids));
            $params = $gids;
        }
        $rows = Db::all($db, $sql, $types, $params);
        $links = DepartmentGroups::links($db, array_map(static fn($r) => (int) $r['jobgroup_id'], $rows));
        $canonical = $links === [] ? [] : DepartmentGroups::canonical($db);
        $depts = DepartmentGroups::departments($db, array_values($links));
        $out = [];
        foreach ($rows as $r) {
            $gid = (int) $r['jobgroup_id'];
            $archived = $r['jobgroup_archived_at'] !== null;
            $cid = $links[$gid] ?? null;
            $status = $cid === null ? null : ($depts[$cid]['status'] ?? 'deleted');
            $out[$gid] = [
                'name' => (string) $r['jobgroup_name'],
                'archived' => $archived,
                'auto' => $cid !== null,
                'live' => !$archived || ($cid !== null && $status !== 'deleted' && ($canonical[$cid] ?? null) === $gid),
                'department_status' => $status,
            ];
        }
        return $out;
    }

    /** {id, name, status} for a department group's department (status 'deleted' when the row is gone). */
    private static function deptRef(int $cid, ?array $dept, string $groupName): array
    {
        return ['id' => $cid, 'name' => $dept['name'] ?? $groupName, 'status' => $dept['status'] ?? 'deleted'];
    }

    /** Active rules whose conditions name group $gid: [{id, name, course}] by name, at most 50. */
    private function rulesUsing(int $gid): array
    {
        $rows = Db::all($this->c->db, "SELECT r.requirement_id, r.requirement_name, k.course_name FROM training_requirement_criteria rc
            JOIN training_requirements r ON r.requirement_id = rc.rcrit_requirement_id
            LEFT JOIN training_courses k ON k.course_id = r.requirement_course_id
            WHERE rc.rcrit_kind = 'jobgroup' AND rc.rcrit_value_id = ? AND r.requirement_archived_at IS NULL
            ORDER BY r.requirement_name, r.requirement_id LIMIT 50", 'i', [$gid]);
        return array_map(static fn($r) => [
            'id' => (int) $r['requirement_id'],
            'name' => (string) $r['requirement_name'],
            'course' => $r['course_name'] === null ? null : (string) $r['course_name'],
        ], $rows);
    }

    /**
     * 422 on name when another group already has $name (not case-sensitive, as the unique key compares),
     * saying which: a department group names its department.
     */
    private function refuseTakenName(string $name, ?int $id): void
    {
        $db = $this->c->db;
        $other = $id === null
            ? Db::one($db, 'SELECT jobgroup_id, jobgroup_name, jobgroup_archived_at FROM training_job_groups WHERE jobgroup_name = ?', 's', [$name])
            : Db::one($db, 'SELECT jobgroup_id, jobgroup_name, jobgroup_archived_at FROM training_job_groups WHERE jobgroup_name = ? AND jobgroup_id <> ?', 'si', [$name, $id]);
        if ($other === null) {
            return;
        }
        $cid = DepartmentGroups::departmentOf($db, (int) $other['jobgroup_id']);
        if ($cid !== null) {
            $dept = DepartmentGroups::departments($db, [$cid])[$cid]['name'] ?? (string) $other['jobgroup_name'];
            $msg = 'The ' . $dept . ' department group already uses this name. Pick another name, or use that group in your rules.';
        } elseif ($other['jobgroup_archived_at'] !== null) {
            $msg = 'An archived job group is already called "' . $other['jobgroup_name'] . '". Pick another name.';
        } else {
            $msg = 'Another job group is already called "' . $other['jobgroup_name'] . '". Pick another name.';
        }
        throw ApiException::validation(['name' => $msg]);
    }

    /** Runs $write; a duplicate-name 1062 (a group saved at the same moment) becomes the same 422 on name. */
    private function uniqueName(callable $write): mixed
    {
        try {
            return $write();
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062 && str_contains($e->getMessage(), 'uq_training_jobgroup_name')) {
                throw ApiException::validation(['name' => 'Another job group already has this name. Pick another name.']);
            }
            throw $e;
        }
    }

    /** Department groups follow their department; they are never edited or archived by hand (409). */
    private function refuseDepartmentGroup(int $id): void
    {
        $cid = DepartmentGroups::departmentOf($this->c->db, $id);
        if ($cid === null) {
            return;
        }
        $dept = DepartmentGroups::departments($this->c->db, [$cid])[$cid]['name'] ?? ('#' . $cid);
        throw new ApiException(409, 'department_group', 'This group follows the ' . $dept . ' department automatically. '
            . 'Its people, name and archive state change with the department, so it cannot be edited or archived here.');
    }

    /** Reconciles the affected people only when an active rule targets this group. */
    private function reconcileIfUsed(int $gid, array $affected): ?array
    {
        if ($affected === []) {
            return null;
        }
        $used = Db::one($this->c->db, "SELECT 1 AS u FROM training_requirement_criteria rc JOIN training_requirements r ON r.requirement_id = rc.rcrit_requirement_id
            WHERE rc.rcrit_kind = 'jobgroup' AND rc.rcrit_value_id = ? AND r.requirement_archived_at IS NULL LIMIT 1", 'i', [$gid]);
        if ($used === null) {
            return null;
        }
        sort($affected);
        return AssignmentService::safeReconcile($this->c, count($affected) > 500 ? null : $affected, 'jobgroup');
    }
}
