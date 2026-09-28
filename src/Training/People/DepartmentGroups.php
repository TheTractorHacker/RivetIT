<?php

namespace ITFlow\Training\People;

use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\Scratch;
use ITFlow\Training\Core\SystemCtx;

/**
 * Department job groups (owner request 2026-09-25): one job group per active department, made and
 * kept in step automatically, so a rule can target "Fabrication" like any hand-made group.
 *
 * DEPARTMENT. A RivetIT department is a `clients` row. It is active when it is not archived and not a
 * CRM lead (client_lead = 0). A deleted, archived or lead row has no active group.
 *
 * MEMBERSHIP IS LIVE. A department group stores no members. JobGroupService::membership() (which
 * Directory::load(), and through it the rule matcher, reconcile, compliance and reports, all read)
 * joins the group to the contacts whose contact_client_id is the department right now. A new hire or
 * someone who moves department is in the right group at once; the existing contact_edit / roster /
 * hire_date reconcile hooks then fix their assignments.
 *
 * SAME PEOPLE AS A DEPARTMENT CONDITION. A rule on a department group and a rule with a Department
 * condition on that department match exactly the same people, also after the department is archived:
 * the group is archived with its department (so it is no longer offered for new rules and is listed
 * under the archived groups), but the people still in the department stay matched, as a Department
 * condition keeps matching them. Archiving or restoring a department therefore changes no one's
 * assignments. Only a retired duplicate (a second group carrying the same marker; the lowest group id
 * is the department's group) matches no one.
 *
 * THE LINK, WITHOUT A SCHEMA CHANGE. The group <-> department link is one reserved row in
 * training_job_group_titles: jgtitle_normalized = MARKER_PREFIX . client_id, e.g. " #dept:12" (the
 * first character is U+0020, a plain space). It can never collide with a real title:
 *   - a title row matches people on LOWER(TRIM(contact_title)) = jgtitle_normalized. MariaDB's TRIM()
 *     strips every leading U+0020 and LOWER() maps no character to U+0020, so that side never starts
 *     with a space;
 *   - under the column's utf8mb4_general_ci no other character sorts equal to U+0020 or is ignorable
 *     (checked for all 1,112,063 non-space Unicode scalar values on MariaDB 10.11: 0 hits), and PAD
 *     SPACE only ignores TRAILING spaces, so "first character is a space" survives the comparison;
 *   - titles typed in the group editor go through JobGroupService::normalizeTitle(), which trims
 *     spaces, and save() also refuses anything that reads like a marker (isReservedTitle()), so a
 *     user can neither create a marker nor delete one (save() refuses department groups outright, and
 *     its title DELETE skips marker rows).
 * The SQL title joins also say NOT LIKE ' #dept:%' (SQL_TITLE_ROW), so the guarantee does not rest on
 * the collation alone. get(), list(), titles() and the UI never show the marker; they expose
 * department:{id,name} and auto:true instead.
 *
 * SYNC. sync() creates, renames (name and description), archives and restores groups to match the
 * departments. It reads a plan without locks; when there is anything to do it takes the named lock
 * `trdeptgrp` (Db::lock, database-scoped), re-reads the plan under it and applies each change in its
 * own transaction with the same ledger events a manual save writes (jobgroup.saved / jobgroup.archived),
 * actor `system`. Two concurrent syncs therefore never create two groups for one department. A name
 * already taken (by a hand-made group or another department of the same name) becomes
 * "<Dept> (department)", then "<Dept> (department #<id>)"; a 1062 from a racing manual save moves on
 * to the next candidate. Triggers: jobgroup_list (at most every STALE_S seconds, stamp in Core\Scratch),
 * the training cron before reconcile, department create/edit/archive/restore/delete in
 * agent/post/client.php, and after the directory syncs.
 */
final class DepartmentGroups
{
    /** Leading U+0020 is what makes a marker impossible to spoof or collide with; see the class comment. */
    public const MARKER_PREFIX = ' #dept:';
    /** SQL predicates on a training_job_group_titles row aliased `t`. */
    public const SQL_MARKER_ROW = "t.jgtitle_normalized LIKE ' #dept:%'";
    public const SQL_TITLE_ROW = "t.jgtitle_normalized NOT LIKE ' #dept:%'";

    public const STALE_S = 300;
    public const NAME_MAX = 100;
    public const DESC_MAX = 255;
    private const LOCK = 'trdeptgrp';
    private const STAMP = 'deptgroups_sync';
    private const UA = 'training_dept_groups';
    private const SUFFIX = ' (department)';

    public static function marker(int $clientId): string
    {
        if ($clientId <= 0) {
            throw new \InvalidArgumentException('DepartmentGroups: bad client id');
        }
        return self::MARKER_PREFIX . $clientId;
    }

    /** The client id a marker row names, or null for any other text (strict: " #dept:<id>" only). */
    public static function clientIdOf(string $title): ?int
    {
        return preg_match('/^ #dept:([1-9][0-9]{0,9})$/D', $title, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * True for a title the group editor must refuse: anything starting with a space, or that reads
     * "#dept:" once leading spaces, separators and control characters are ignored (not case-sensitive).
     */
    public static function isReservedTitle(string $title): bool
    {
        if ($title !== '' && $title[0] === ' ') {
            return true;
        }
        $bare = (string) preg_replace('/^[\s\p{Z}\p{C}]+/u', '', $title);
        return stripos($bare, '#dept:') === 0;
    }

    /**
     * Groups that follow a department: gid => client_id (archived groups included). A group carrying a
     * marker is a department group even if another group carries the same one (sync() never writes
     * such a duplicate, and retires one if it finds it).
     *
     * @param list<int>|null $gids only these groups
     * @return array<int, int>
     */
    public static function links(\mysqli $db, ?array $gids = null): array
    {
        $sql = 'SELECT t.jgtitle_jobgroup_id AS gid, t.jgtitle_normalized AS m FROM training_job_group_titles t WHERE ' . self::SQL_MARKER_ROW;
        $types = '';
        $params = [];
        if ($gids !== null) {
            $gids = array_values(array_unique(array_filter(array_map('intval', $gids), static fn($i) => $i > 0)));
            if ($gids === []) {
                return [];
            }
            $sql .= ' AND t.jgtitle_jobgroup_id IN (' . implode(',', array_fill(0, count($gids), '?')) . ')';
            $types = str_repeat('i', count($gids));
            $params = $gids;
        }
        $out = [];
        foreach (Db::all($db, $sql . ' ORDER BY t.jgtitle_jobgroup_id', $types, $params) as $r) {
            $cid = self::clientIdOf((string) $r['m']);
            if ($cid !== null) {
                $out[(int) $r['gid']] ??= $cid;
            }
        }
        return $out;
    }

    /** The department a group follows, or null for a hand-made group. */
    public static function departmentOf(\mysqli $db, int $gid): ?int
    {
        return self::links($db, [$gid])[$gid] ?? null;
    }

    /**
     * Each department's own group: client_id => the lowest group id carrying its marker (archived or not).
     * Any other group with that marker is a duplicate that sync() retires and that matches no one.
     *
     * @return array<int, int>
     */
    public static function canonical(\mysqli $db): array
    {
        $out = [];
        foreach (Db::all($db, 'SELECT t.jgtitle_normalized AS m, MIN(t.jgtitle_jobgroup_id) AS gid FROM training_job_group_titles t WHERE '
            . self::SQL_MARKER_ROW . ' GROUP BY t.jgtitle_normalized') as $r) {
            $cid = self::clientIdOf((string) $r['m']);
            if ($cid !== null) {
                $out[$cid] = (int) $r['gid'];
            }
        }
        return $out;
    }

    /**
     * Department facts for the given client ids: id => {id, name, active, status, people}.
     * status = 'active' | 'archived' | 'lead' (a CRM lead is not a department); a deleted department is absent.
     * people = non-archived contacts in the department (the live head count).
     */
    public static function departments(\mysqli $db, array $clientIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Db::all($db, 'SELECT cl.client_id, cl.client_name, cl.client_archived_at, cl.client_lead,
                (SELECT COUNT(*) FROM contacts c WHERE c.contact_client_id = cl.client_id AND c.contact_archived_at IS NULL) AS people
            FROM clients cl WHERE cl.client_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids) as $r) {
            $status = $r['client_archived_at'] !== null ? 'archived' : ((int) $r['client_lead'] !== 0 ? 'lead' : 'active');
            $out[(int) $r['client_id']] = [
                'id' => (int) $r['client_id'],
                'name' => (string) $r['client_name'],
                'active' => $status === 'active',
                'status' => $status,
                'people' => (int) $r['people'],
            ];
        }
        return $out;
    }

    /** "Everyone in the <Dept> department, kept in sync automatically." (<= 255 characters). */
    public static function description(string $deptName, int $clientId): string
    {
        $tail = ' department, kept in sync automatically.';
        $head = 'Everyone in the ';
        $room = self::DESC_MAX - mb_strlen($head . $tail, 'UTF-8');
        return $head . self::clip(self::base($deptName, $clientId), $room) . $tail;
    }

    /**
     * Names to try, in order: "<Dept>", "<Dept> (department)", "<Dept> (department #<id>)", then
     * numbered variants. Each fits jobgroup_name (100 characters).
     *
     * @return list<string>
     */
    public static function candidates(string $deptName, int $clientId): array
    {
        $base = self::base($deptName, $clientId);
        $out = [self::clip($base, self::NAME_MAX)];
        $out[] = self::clip($base, self::NAME_MAX - mb_strlen(self::SUFFIX, 'UTF-8')) . self::SUFFIX;
        $sfx = ' (department #' . $clientId . ')';
        $out[] = self::clip($base, self::NAME_MAX - mb_strlen($sfx, 'UTF-8')) . $sfx;
        for ($n = 2; $n <= 9; $n++) {
            $sfx = ' (department #' . $clientId . '-' . $n . ')';
            $out[] = self::clip($base, self::NAME_MAX - mb_strlen($sfx, 'UTF-8')) . $sfx;
        }
        return array_values(array_unique($out));
    }

    /**
     * Hook for pages that change departments (agent/post/client.php, the directory syncs): runs sync()
     * when the module is on and the Phase 2 schema is there. Never throws and never runs inside a
     * transaction; a failure is logged and the caller carries on.
     */
    public static function safeSync(\mysqli $db, string $trigger, bool $reconcile = true): ?array
    {
        try {
            if (Db::depth() !== 0) {
                error_log("Training: department job groups ($trigger) skipped: called inside a transaction");
                return null;
            }
            $on = Db::one($db, 'SELECT config_module_enable_training AS m FROM settings WHERE company_id = 1');
            if ((int) ($on['m'] ?? 0) !== 1 || !RecordsSettings::fromDb($db)->schemaReady) {
                return null;
            }
            $r = self::sync($db, $trigger, 10, $reconcile);
            if (empty($r['busy'])) {
                self::stamp();
            }
            return $r;
        } catch (\Throwable $e) {
            error_log("Training: department job groups ($trigger) failed: " . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * For the Microsoft and Google directory syncs (admin/post/settings_directory_sync.php). They create,
     * rename and archive departments and move people between them but, unlike the Odoo sync, have no
     * Training step of their own: this brings the department groups in step, then reconciles everyone
     * (trigger directory_sync), so a move changes department-group and Department-condition assignments now
     * rather than at the nightly cron. Module on and schema ready only; never throws and never runs inside a
     * transaction (null when skipped or failed).
     *
     * @return array{dept_groups:?array, reconcile:array}|null
     */
    public static function afterDirectorySync(Ctx $c): ?array
    {
        try {
            $db = $c->db;
            if (Db::depth() !== 0) {
                error_log('Training: after the directory sync, skipped: called inside a transaction');
                return null;
            }
            $on = Db::one($db, 'SELECT config_module_enable_training AS m FROM settings WHERE company_id = 1');
            if ((int) ($on['m'] ?? 0) !== 1 || !RecordsSettings::fromDb($db)->schemaReady) {
                return null;
            }
            $groups = self::safeSync($db, 'directory_sync', false);   // no per-group reconcile: everyone is reconciled next
            return ['dept_groups' => $groups, 'reconcile' => AssignmentService::safeReconcile($c, null, 'directory_sync')];
        } catch (\Throwable $e) {
            error_log('Training: after the directory sync, failed: ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * For jobgroup_list (the Job groups tab and the rule editor's group picker): syncs when the last
     * sync is older than $maxAgeS. Never throws. Needs a bound Core\Scratch (any Ctx does that).
     */
    public static function syncIfStale(\mysqli $db, int $maxAgeS = self::STALE_S): ?array
    {
        try {
            $age = Scratch::stampAge(self::STAMP);
        } catch (\Throwable) {
            $age = null;   // no usable stamp: the sync is cheap, run it
        }
        if ($age !== null && $age < $maxAgeS) {
            return null;
        }
        try {
            $r = self::sync($db, 'list', 2, true);
        } catch (\Throwable $e) {
            error_log('Training: department job groups (list) failed: ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
        if (empty($r['busy'])) {
            self::stamp();
        }
        return $r;
    }

    /**
     * Brings the department groups in line with the departments. Idempotent; a no-op run takes no
     * lock and writes nothing. Must not run inside a transaction.
     *
     * @param bool $reconcile reconcile the department's people when a retired duplicate group (whose people
     *                        stop matching) is targeted by an active rule (the training cron passes false:
     *                        its full reconcile follows). Archiving or restoring a department's own group
     *                        changes no one's membership, so it needs no reconcile.
     * @return array{created:int, renamed:int, archived:int, restored:int, errors:int, busy:bool, reconcile:list<array>}
     */
    public static function sync(\mysqli $db, string $trigger = 'manual', int $lockWaitS = 5, bool $reconcile = true): array
    {
        if (Db::depth() !== 0) {
            throw new \LogicException('DepartmentGroups::sync must not run inside a transaction');
        }
        $stats = ['created' => 0, 'renamed' => 0, 'archived' => 0, 'restored' => 0, 'errors' => 0, 'busy' => false, 'reconcile' => []];
        if (self::plan($db) === []) {
            return $stats;
        }
        if (!Db::lock($db, self::LOCK, $lockWaitS)) {
            $stats['busy'] = true;
            return $stats;
        }
        $ctx = SystemCtx::make($db, 0, self::UA . ':' . preg_replace('/[^a-z0-9_]/', '', strtolower($trigger)));
        $touched = [];   // gid => client_id, for retired duplicates (the only change that switches membership off)
        try {
            foreach (self::plan($db) as $op) {
                try {
                    $done = match ($op['op']) {
                        'create' => self::create($ctx, $op['client_id']),
                        'rename' => self::rename($ctx, $op['gid'], $op['client_id']),
                        'restore' => self::restore($ctx, $op['gid'], $op['client_id']),
                        'archive' => self::archive($ctx, $op['gid'], $op['client_id'], $op['reason']),
                    };
                    if ($done) {
                        $stats[['create' => 'created', 'rename' => 'renamed', 'restore' => 'restored', 'archive' => 'archived'][$op['op']]]++;
                        if ($op['op'] === 'archive' && ($op['reason'] ?? '') === 'duplicate') {
                            $touched[$op['gid']] = $op['client_id'];
                        }
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    error_log("Training: department job group {$op['op']} for department #{$op['client_id']} ($trigger) failed: "
                        . get_class($e) . ': ' . $e->getMessage());
                }
            }
        } finally {
            Db::unlock($db, self::LOCK);
        }
        if ($reconcile) {
            foreach ($touched as $gid => $cid) {
                $r = self::reconcileIfUsed($ctx, $gid, $cid);
                if ($r !== null) {
                    $stats['reconcile'][] = ['jobgroup_id' => $gid] + $r;
                }
            }
        }
        return $stats;
    }

    // ------------------------------------------------------------------------------------------------

    /** @return list<array{op:string, client_id:int, gid?:int, reason?:string}> */
    private static function plan(\mysqli $db): array
    {
        $ops = [];
        $clients = [];
        foreach (Db::all($db, 'SELECT client_id, client_name, client_archived_at, client_lead FROM clients') as $r) {
            $clients[(int) $r['client_id']] = [
                'name' => (string) $r['client_name'],
                'active' => $r['client_archived_at'] === null && (int) $r['client_lead'] === 0,
            ];
        }
        $groups = [];
        foreach (Db::all($db, 'SELECT t.jgtitle_jobgroup_id AS gid, t.jgtitle_normalized AS m, g.jobgroup_name, g.jobgroup_description, g.jobgroup_archived_at
            FROM training_job_group_titles t JOIN training_job_groups g ON g.jobgroup_id = t.jgtitle_jobgroup_id
            WHERE ' . self::SQL_MARKER_ROW . ' ORDER BY t.jgtitle_jobgroup_id') as $r) {
            $cid = self::clientIdOf((string) $r['m']);
            if ($cid === null) {
                continue;
            }
            $g = ['gid' => (int) $r['gid'], 'name' => (string) $r['jobgroup_name'], 'desc' => $r['jobgroup_description'], 'archived' => $r['jobgroup_archived_at'] !== null];
            if (isset($groups[$cid])) {
                // Never written by this class; a stray duplicate link is retired so one group owns the department.
                if (!$g['archived']) {
                    $ops[] = ['op' => 'archive', 'gid' => $g['gid'], 'client_id' => $cid, 'reason' => 'duplicate'];
                }
                continue;
            }
            $groups[$cid] = $g;
        }
        foreach ($clients as $cid => $cl) {
            if (!$cl['active']) {
                continue;
            }
            $g = $groups[$cid] ?? null;
            if ($g === null) {
                $ops[] = ['op' => 'create', 'client_id' => $cid];
            } elseif ($g['archived']) {
                $ops[] = ['op' => 'restore', 'gid' => $g['gid'], 'client_id' => $cid];
            } elseif (!in_array($g['name'], self::candidates($cl['name'], $cid), true) || $g['desc'] !== self::description($cl['name'], $cid)) {
                $ops[] = ['op' => 'rename', 'gid' => $g['gid'], 'client_id' => $cid];
            }
        }
        foreach ($groups as $cid => $g) {
            if (!$g['archived'] && !($clients[$cid]['active'] ?? false)) {
                $ops[] = ['op' => 'archive', 'gid' => $g['gid'], 'client_id' => $cid,
                    'reason' => isset($clients[$cid]) ? 'department_archived' : 'department_deleted'];
            }
        }
        return $ops;
    }

    private static function create(Ctx $c, int $cid): bool
    {
        $db = $c->db;
        return Db::tx($db, function () use ($c, $db, $cid): bool {
            $dept = self::departments($db, [$cid])[$cid] ?? null;
            if ($dept === null || !$dept['active'] || self::linkedGroup($db, $cid) !== null) {
                return false;   // changed since the plan was read
            }
            $desc = self::description($dept['name'], $cid);
            $gid = 0;
            $name = null;
            foreach (self::candidates($dept['name'], $cid) as $cand) {
                if (Db::one($db, 'SELECT jobgroup_id FROM training_job_groups WHERE jobgroup_name = ?', 's', [$cand]) !== null) {
                    continue;
                }
                try {
                    $gid = Db::insert($db, 'INSERT INTO training_job_groups (jobgroup_name, jobgroup_description, jobgroup_version, jobgroup_created_by) VALUES (?, ?, 1, 0)',
                        'ss', [$cand, $desc]);
                    $name = $cand;
                    break;
                } catch (\mysqli_sql_exception $e) {
                    if ((int) $e->getCode() !== 1062) {
                        throw $e;
                    }
                }
            }
            if ($name === null) {
                throw new \RuntimeException('every name for this department group is taken');
            }
            Db::exec($db, 'INSERT INTO training_job_group_titles (jgtitle_jobgroup_id, jgtitle_normalized) VALUES (?, ?)', 'is', [$gid, self::marker($cid)]);
            self::ledger($c, 'jobgroup.saved', $gid, $name, $dept, 'created');
            return true;
        });
    }

    private static function rename(Ctx $c, int $gid, int $cid): bool
    {
        $db = $c->db;
        return Db::tx($db, function () use ($c, $db, $gid, $cid): bool {
            $cur = self::lockGroup($db, $gid, $cid);
            $dept = self::departments($db, [$cid])[$cid] ?? null;
            if ($cur === null || $cur['jobgroup_archived_at'] !== null || $dept === null || !$dept['active']) {
                return false;
            }
            if (in_array((string) $cur['jobgroup_name'], self::candidates($dept['name'], $cid), true)
                && $cur['jobgroup_description'] === self::description($dept['name'], $cid)) {
                return false;   // already in step
            }
            $name = self::applyName($db, $gid, $cid, $dept, (string) $cur['jobgroup_name'], false);
            self::ledger($c, 'jobgroup.saved', $gid, $name, $dept, 'renamed', ['from' => (string) $cur['jobgroup_name']]);
            return true;
        });
    }

    private static function restore(Ctx $c, int $gid, int $cid): bool
    {
        $db = $c->db;
        return Db::tx($db, function () use ($c, $db, $gid, $cid): bool {
            $cur = self::lockGroup($db, $gid, $cid);
            $dept = self::departments($db, [$cid])[$cid] ?? null;
            if ($cur === null || $cur['jobgroup_archived_at'] === null || $dept === null || !$dept['active']) {
                return false;
            }
            $name = self::applyName($db, $gid, $cid, $dept, (string) $cur['jobgroup_name'], true);
            self::ledger($c, 'jobgroup.saved', $gid, $name, $dept, 'restored');
            return true;
        });
    }

    private static function archive(Ctx $c, int $gid, int $cid, string $reason): bool
    {
        $db = $c->db;
        return Db::tx($db, function () use ($c, $db, $gid, $cid, $reason): bool {
            $cur = self::lockGroup($db, $gid, $cid);
            if ($cur === null || $cur['jobgroup_archived_at'] !== null) {
                return false;
            }
            $dept = self::departments($db, [$cid])[$cid] ?? null;
            if ($reason !== 'duplicate' && $dept !== null && $dept['active']) {
                return false;   // restored since the plan was read
            }
            Db::exec($db, 'UPDATE training_job_groups SET jobgroup_archived_at = NOW(), jobgroup_version = jobgroup_version + 1 WHERE jobgroup_id = ?', 'i', [$gid]);
            self::ledger($c, 'jobgroup.archived', $gid, (string) $cur['jobgroup_name'],
                $dept ?? ['id' => $cid, 'name' => null, 'people' => 0], $reason);
            return true;
        });
    }

    /** The group row FOR UPDATE, only while it still carries the marker for $cid. */
    private static function lockGroup(\mysqli $db, int $gid, int $cid): ?array
    {
        $cur = Db::one($db, 'SELECT jobgroup_id, jobgroup_name, jobgroup_description, jobgroup_archived_at FROM training_job_groups WHERE jobgroup_id = ? FOR UPDATE', 'i', [$gid]);
        if ($cur === null) {
            return null;
        }
        $has = Db::one($db, 'SELECT 1 AS x FROM training_job_group_titles WHERE jgtitle_jobgroup_id = ? AND jgtitle_normalized = ?', 'is', [$gid, self::marker($cid)]);
        return $has === null ? null : $cur;
    }

    /** The group linked to $cid (lowest id), or null. */
    private static function linkedGroup(\mysqli $db, int $cid): ?int
    {
        $r = Db::one($db, 'SELECT MIN(jgtitle_jobgroup_id) AS gid FROM training_job_group_titles WHERE jgtitle_normalized = ?', 's', [self::marker($cid)]);
        return ($r === null || $r['gid'] === null) ? null : (int) $r['gid'];
    }

    /**
     * Sets name (kept when it is still a valid candidate for the department's name), description,
     * version + 1, and on $restore clears jobgroup_archived_at. Returns the name it kept or chose.
     */
    private static function applyName(\mysqli $db, int $gid, int $cid, array $dept, string $current, bool $restore): string
    {
        $desc = self::description($dept['name'], $cid);
        $cands = self::candidates($dept['name'], $cid);
        if (in_array($current, $cands, true)) {
            $cands = array_merge([$current], array_values(array_diff($cands, [$current])));
        }
        foreach ($cands as $cand) {
            if (Db::one($db, 'SELECT jobgroup_id FROM training_job_groups WHERE jobgroup_name = ? AND jobgroup_id <> ?', 'si', [$cand, $gid]) !== null) {
                continue;
            }
            try {
                Db::exec($db, 'UPDATE training_job_groups SET jobgroup_name = ?, jobgroup_description = ?, jobgroup_version = jobgroup_version + 1'
                    . ($restore ? ', jobgroup_archived_at = NULL' : '') . ' WHERE jobgroup_id = ?', 'ssi', [$cand, $desc, $gid]);
                return $cand;
            } catch (\mysqli_sql_exception $e) {
                if ((int) $e->getCode() !== 1062) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('every name for this department group is taken');
    }

    /** Same event type and payload keys as a manual save/archive, plus the department and what changed. */
    private static function ledger(Ctx $c, string $type, int $gid, string $name, array $dept, string $change, array $extra = []): void
    {
        Ledger::append($c->db, [
            'type' => $type,
            'actor_type' => 'system',
            'entity_type' => 'jobgroup',
            'entity_id' => $gid,
            'payload' => [
                'name' => $name,
                'member_count' => (int) ($dept['people'] ?? 0),
                'titles' => [],
                'auto' => true,
                'department' => ['id' => (int) $dept['id'], 'name' => $dept['name']],
                'change' => $change,
            ] + $extra,
            'user_agent' => $c->userAgent,
        ]);
    }

    /** After a retired duplicate stopped matching its department's people: reconcile them when an active rule targets it. */
    private static function reconcileIfUsed(Ctx $c, int $gid, int $cid): ?array
    {
        $used = Db::one($c->db, "SELECT 1 AS u FROM training_requirement_criteria rc JOIN training_requirements r ON r.requirement_id = rc.rcrit_requirement_id
            WHERE rc.rcrit_kind = 'jobgroup' AND rc.rcrit_value_id = ? AND r.requirement_archived_at IS NULL LIMIT 1", 'i', [$gid]);
        if ($used === null) {
            return null;
        }
        $ids = array_map(static fn($r) => (int) $r['contact_id'], Db::all($c->db, 'SELECT contact_id FROM contacts WHERE contact_client_id = ? ORDER BY contact_id', 'i', [$cid]));
        if ($ids === []) {
            return null;
        }
        return AssignmentService::safeReconcile($c, count($ids) > 500 ? null : $ids, 'jobgroup');
    }

    private static function stamp(): void
    {
        try {
            Scratch::stamp(self::STAMP);
        } catch (\Throwable) {
            // Best effort: without a stamp the next jobgroup_list just syncs again (cheap).
        }
    }

    private static function base(string $deptName, int $clientId): string
    {
        $b = trim((string) preg_replace('/\s+/u', ' ', $deptName));
        return $b === '' ? 'Department #' . $clientId : $b;
    }

    private static function clip(string $s, int $max): string
    {
        return mb_strlen($s, 'UTF-8') <= $max ? $s : rtrim(mb_substr($s, 0, max(1, $max), 'UTF-8'));
    }
}
