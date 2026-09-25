<?php

namespace ITFlow\Training\Assign;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Compliance\PairRules;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsMutex;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Directory;
use ITFlow\Training\People\Scope;

/**
 * Assignments: the reconcile engine (M6) plus extend / waive / list / get (Phase 2 spec §3.3).
 *
 * RECONCILE turns rules + person facts + records into open assignments:
 *   - never inside a transaction (asserted); the named lock `trrec` serializes whole runs;
 *   - people and rules are loaded once; the records facts are re-read INSIDE each 50-contact chunk,
 *     after the records mutex and the chunk's open rows (FOR UPDATE) - so a completion issued while
 *     the run is going is seen by the chunk that decides on it (no stale `initial`);
 *   - per pair: want = PairRules::want(); an open row with the same anchor is kept (its due date and
 *     any extension stand); otherwise the open row is completed (when the latest record satisfies it)
 *     or cancelled, and the wanted anchor is reopened (a same-anchor row cancelled as no longer
 *     required / ineligible within the reopen window keeps its original due date) or inserted;
 *   - each chunk appends its ledger events LAST; a 1213/1205 retries the chunk once, then counts it
 *     in failed_chunks and carries on.
 * Actor: user-driven triggers record the calling user; everything else records `system`.
 */
final class AssignmentService
{
    public const CHUNK = 50;
    public const PAGE = 50;
    public const USER_TRIGGERS = ['rule_save', 'rule_archive', 'assign_manual', 'roster', 'hire_date', 'jobgroup', 'reconcile_now'];
    public const STATUS_FILTERS = ['open', 'overdue', 'due_soon', 'waived', 'completed', 'cancelled', 'cancelled_overdue', 'all'];
    public const SORT_KEYS = ['due', 'due_desc', 'name', 'course', 'created', 'closed'];
    private const SORTS = [
        'due' => 'a.tassign_due_on ASC, a.tassign_id ASC',
        'due_desc' => 'a.tassign_due_on DESC, a.tassign_id DESC',
        'name' => 'c.contact_name ASC, a.tassign_due_on ASC, a.tassign_id ASC',
        'course' => 'k.course_name ASC, a.tassign_due_on ASC, a.tassign_id ASC',
        'created' => 'a.tassign_id DESC',
        'closed' => 'a.tassign_closed_at_utc DESC, a.tassign_id DESC',
    ];

    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * reconcile() for after-commit hooks: never throws. Returns the stats, or {error:'busy'} (another
     * run holds the lock; stats still included) or {error:'failed'}.
     */
    public static function safeReconcile(Ctx $c, ?array $contactIds, string $trigger, ?int $lockWaitS = null): array
    {
        try {
            $r = (new self($c))->reconcile($contactIds, $trigger, $lockWaitS);
            return !empty($r['skipped_busy']) ? ['error' => 'busy'] + $r : $r;
        } catch (\Throwable $e) {
            error_log("Training: reconcile ($trigger) failed: " . get_class($e) . ': ' . $e->getMessage());
            return ['error' => 'failed'];
        }
    }

    /**
     * @param list<int>|null $contactIds null = everyone
     * @return array{created:int, reopened:int, completed:int, cancelled:int, unchanged:int, raced:int, failed_chunks:int,
     *               contacts:int, ms:int, skipped_busy:bool}
     */
    public function reconcile(?array $contactIds, string $trigger, ?int $lockWaitS = null): array
    {
        if (Db::depth() !== 0) {
            throw new \LogicException('AssignmentService::reconcile must not run inside a transaction');
        }
        if (preg_match('/^[a-z_]{2,32}$/D', $trigger) !== 1) {
            throw new \InvalidArgumentException('reconcile: bad trigger');
        }
        $t0 = hrtime(true);
        $stats = ['created' => 0, 'reopened' => 0, 'completed' => 0, 'cancelled' => 0, 'unchanged' => 0, 'raced' => 0,
                  'failed_chunks' => 0, 'contacts' => 0, 'ms' => 0, 'skipped_busy' => false];
        $ids = null;
        if ($contactIds !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn($i) => $i > 0)));
            sort($ids);
            if ($ids === []) {
                return $stats;
            }
        }
        $db = $this->c->db;
        $wait = $lockWaitS ?? ($ids === null ? ($trigger === 'cron' ? 30 : 10) : 1);
        if (!Db::lock($db, 'trrec', $wait)) {
            $stats['skipped_busy'] = true;
            return $stats;
        }
        try {
            $today = Clock::todayLocal();
            $s = RecordsSettings::fromDb($db);
            $actorUserId = in_array($trigger, self::USER_TRIGGERS, true) && $this->c->userId > 0 ? $this->c->userId : null;

            // People: the requested contacts (any state), or every eligible person plus anyone holding an open assignment.
            $openRows = $ids === null
                ? Db::all($db, "SELECT DISTINCT tassign_contact_id, tassign_course_id FROM training_assignments WHERE tassign_status = 'open'")
                : Db::all($db, "SELECT DISTINCT tassign_contact_id, tassign_course_id FROM training_assignments WHERE tassign_status = 'open'
                    AND tassign_contact_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids);
            $openCourses = [];
            $openContacts = [];
            foreach ($openRows as $r) {
                $openCourses[(int) $r['tassign_course_id']] = true;
                $openContacts[(int) $r['tassign_contact_id']] = true;
            }
            if ($ids === null) {
                $people = Directory::load($db, Scope::all());
                $extra = array_values(array_diff(array_keys($openContacts), array_keys($people)));
                if ($extra !== []) {
                    $people += Directory::load($db, Scope::all(), $extra, true);
                }
                $all = array_unique(array_merge(array_keys($people), array_keys($openContacts)));
            } else {
                $people = Directory::load($db, Scope::all(), $ids, true);
                $all = $ids;
            }
            sort($all);

            $matcher = new RuleMatcher(RuleMatcher::loadRules($db, true));
            $desired = [];
            foreach ($all as $cid) {
                $p = $people[$cid] ?? null;
                $desired[$cid] = ($p !== null && $p['eligible']) ? $matcher->desiredFor($p, $today) : [];
            }
            $courses = CourseCards::load($db, array_merge($matcher->courseIds(), array_keys($openCourses)));

            foreach (array_chunk($all, self::CHUNK) as $chunk) {
                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    try {
                        $delta = $this->chunk($chunk, $people, $desired, $courses, $today, $s, $trigger, $actorUserId);
                        foreach ($delta as $k => $v) {
                            $stats[$k] += $v;
                        }
                        break;
                    } catch (\mysqli_sql_exception $e) {
                        $code = (int) $e->getCode();
                        if ($code !== 1213 && $code !== 1205) {
                            throw $e;
                        }
                        if ($attempt === 2) {
                            $stats['failed_chunks']++;
                            error_log("Training reconcile ($trigger): chunk starting at contact #{$chunk[0]} failed twice ($code)");
                        }
                    }
                }
            }
            $stats['contacts'] = count($all);
            if ($ids === null && $stats['failed_chunks'] === 0 && $s->schemaReady) {
                Db::exec($db, 'UPDATE settings SET config_training_reconciled_at_utc = ? WHERE company_id = 1', 's', [Clock::nowUtc()]);
            }
        } finally {
            Db::unlock($db, 'trrec');
        }
        $stats['ms'] = (int) ((hrtime(true) - $t0) / 1e6);
        return $stats;
    }

    /** One chunk in one transaction. @return array<string,int> stats delta */
    private function chunk(array $chunk, array $people, array $desired, array $courses, string $today, RecordsSettings $s,
                           string $trigger, ?int $actorUserId): array
    {
        $db = $this->c->db;
        return Db::tx($db, function () use ($db, $chunk, $people, $desired, $courses, $today, $s, $trigger, $actorUserId): array {
            $st = ['created' => 0, 'reopened' => 0, 'completed' => 0, 'cancelled' => 0, 'unchanged' => 0, 'raced' => 0];
            RecordsMutex::acquire($db);
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $open = [];
            foreach (Db::all($db, 'SELECT ' . AssignmentStore::COLUMNS . " FROM training_assignments
                    WHERE tassign_contact_id IN ($in) AND tassign_status = 'open' ORDER BY tassign_id FOR UPDATE", str_repeat('i', count($chunk)), $chunk) as $r) {
                $a = AssignmentStore::normalize($r);
                $open[$a['contact_id']][$a['course_id']] = $a;
            }
            $courseIds = [];
            $since = [];
            foreach ($chunk as $cid) {
                foreach ($desired[$cid] ?? [] as $k => $d) {
                    $courseIds[$k] = $k;
                    if ($d['onboarding_since'] !== null) {
                        $since[$cid][$k] = $d['onboarding_since'];
                    }
                }
                foreach ($open[$cid] ?? [] as $k => $_) {
                    $courseIds[$k] = $k;
                }
            }
            $facts = RecordFacts::load($db, $chunk, array_values($courseIds), true, $since, $s, $today);
            $now = Clock::nowUtc();
            $events = [];
            $ev = function (string $type, array $a, array $payload) use (&$events, $trigger, $actorUserId): void {
                $events[] = AssignmentStore::event($type, $a, $payload + ['trigger' => $trigger], $actorUserId,
                    $actorUserId !== null ? ['user_agent' => $this->c->userAgent] : []);
            };

            foreach ($chunk as $cid) {
                $P = $people[$cid] ?? null;
                $eligible = $P !== null && $P['eligible'];
                $ks = array_unique(array_merge(array_keys($desired[$cid] ?? []), array_keys($open[$cid] ?? [])));
                sort($ks);
                foreach ($ks as $k) {
                    $d = $eligible ? ($desired[$cid][$k] ?? null) : null;
                    $O = $open[$cid][$k] ?? null;
                    $f = $facts[$cid][$k] ?? RecordFacts::none();
                    $course = $courses[$k] ?? ['validity_months' => null, 'renewal_lead_days' => 0];
                    $want = PairRules::want($d, $f, $course, $today, $s);

                    if ($O !== null && $want !== null && $O['anchor'] === $want['anchor']) {
                        $reqId = $d['requirement_id'] ?? $O['requirement_id'];
                        $required = $d !== null ? $d['required'] : $O['required'];
                        $floor = $d !== null ? $d['onboarding_since'] : $O['onboarding_from_on'];
                        if ($reqId !== $O['requirement_id'] || $required !== $O['required'] || $floor !== $O['onboarding_from_on']) {
                            Db::exec($db, 'UPDATE training_assignments SET tassign_requirement_id = ?, tassign_required = ?, tassign_onboarding_from_on = ?
                                WHERE tassign_id = ?', 'iisi', [$reqId, $required ? 1 : 0, $floor, $O['id']]);
                        }
                        $st['unchanged']++;
                        continue;
                    }
                    if ($O !== null) {
                        $L = $f['latest'];
                        if ($L !== null && PairRules::satisfies($O, $L, $f, $today)) {
                            Db::exec($db, "UPDATE training_assignments SET tassign_status = 'completed', tassign_open_guard = NULL, tassign_completion_id = ?,
                                tassign_closed_at_utc = ?, tassign_closed_by_user_id = ?, tassign_close_reason = 'completed'
                                WHERE tassign_id = ? AND tassign_status = 'open'", 'isii', [$L['completion_id'], $now, $actorUserId, $O['id']]);
                            $ev('assignment.completed', $O, ['course_id' => $k, 'anchor' => $O['anchor'], 'completion_id' => $L['completion_id'], 'close_reason' => 'completed']);
                            $st['completed']++;
                        } else {
                            $reason = !$eligible ? 'contact_ineligible' : ($want !== null ? 'superseded' : 'no_longer_required');
                            Db::exec($db, "UPDATE training_assignments SET tassign_status = 'cancelled', tassign_open_guard = NULL, tassign_closed_at_utc = ?,
                                tassign_closed_by_user_id = ?, tassign_close_reason = ? WHERE tassign_id = ? AND tassign_status = 'open'",
                                'sisi', [$now, $actorUserId, $reason, $O['id']]);
                            $ev('assignment.cancelled', $O, ['course_id' => $k, 'anchor' => $O['anchor'], 'close_reason' => $reason]);
                            $st['cancelled']++;
                        }
                    }
                    if ($want === null) {
                        continue;
                    }
                    $R = null;
                    foreach ($f['recentCancelled'] as $cand) {
                        if ($cand['anchor'] === $want['anchor']) {
                            $R = $cand;
                            break;
                        }
                    }
                    $floor = $d['onboarding_since'] ?? null;
                    if ($R !== null) {
                        Db::exec($db, "UPDATE training_assignments SET tassign_status = 'open', tassign_open_guard = 1, tassign_closed_at_utc = NULL,
                                tassign_closed_by_user_id = NULL, tassign_close_reason = NULL, tassign_close_note = NULL,
                                tassign_reopened_count = LEAST(tassign_reopened_count + 1, 65535), tassign_requirement_id = ?, tassign_required = ?,
                                tassign_onboarding_from_on = ?
                            WHERE tassign_id = ? AND tassign_status = 'cancelled'", 'iisi', [$d['requirement_id'], $d['required'] ? 1 : 0, $floor, $R['id']]);
                        $ev('assignment.reopened', $R, ['course_id' => $k, 'anchor' => $R['anchor'], 'reason' => $R['reason'], 'requirement_id' => $d['requirement_id'],
                            'due_on' => $R['due_on'], 'required' => $d['required'], 'onboarding_from_on' => $floor]);
                        $st['reopened']++;
                        continue;
                    }
                    try {
                        $newId = Db::insert($db, "INSERT INTO training_assignments (tassign_contact_id, tassign_course_id, tassign_reason, tassign_anchor,
                                tassign_requirement_id, tassign_required, tassign_due_on, tassign_original_due_on, tassign_onboarding_from_on,
                                tassign_status, tassign_open_guard, tassign_created_at_utc, tassign_created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', 1, ?, ?)", 'iissiissssi',
                            [$cid, $k, $want['reason'], $want['anchor'], $d['requirement_id'], $d['required'] ? 1 : 0, $want['due_on'], $want['due_on'],
                             $floor, $now, $actorUserId]);
                    } catch (\mysqli_sql_exception $e) {
                        if ((int) $e->getCode() !== 1062) {
                            throw $e;
                        }
                        $st['raced']++;
                        continue;
                    }
                    $ev('assignment.created', ['id' => $newId, 'contact_id' => $cid, 'course_id' => $k], ['course_id' => $k, 'anchor' => $want['anchor'],
                        'reason' => $want['reason'], 'requirement_id' => $d['requirement_id'], 'due_on' => $want['due_on'], 'required' => $d['required'],
                        'onboarding_from_on' => $floor]);
                    $st['created']++;
                }
            }
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
            return $st;
        });
    }

    // =========================================================================================
    // Supervisor actions (S3)
    // =========================================================================================

    /** Moves an open assignment's due date (original_due_on is kept). Ledger assignment.due_changed. */
    public function extend(int $assignmentId, string $dueOn, string $reason): array
    {
        $today = Clock::todayLocal();
        if (!Clock::isYmd($dueOn)) {
            throw ApiException::validation(['due_on' => 'Must be a date (YYYY-MM-DD).']);
        }
        if ($dueOn <= $today || $dueOn > Clock::addDays($today, 3660)) {
            throw new ApiException(422, 'date_out_of_range', 'Pick a due date after today.', ['due_on' => 'Must be after today.']);
        }
        $reason = self::reason($reason);
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $assignmentId, $dueOn, $reason): void {
            $a = $this->lockRow($assignmentId);
            if ($a['status'] !== 'open') {
                throw new ApiException(409, 'conflict', 'This assignment is no longer open.');
            }
            Db::exec($db, 'UPDATE training_assignments SET tassign_due_on = ? WHERE tassign_id = ?', 'si', [$dueOn, $assignmentId]);
            Ledger::append($db, AssignmentStore::event('assignment.due_changed', $a,
                ['course_id' => $a['course_id'], 'from' => $a['due_on'], 'to' => $dueOn, 'reason' => $reason],
                $this->c->userId, ['user_agent' => $this->c->userAgent]));
        });
        return $this->get($assignmentId, Scope::all())['assignment'];
    }

    /** Waives an open assignment (optionally until a date). Ledger assignment.waived. */
    public function waive(int $assignmentId, ?string $until, string $reason): array
    {
        $today = Clock::todayLocal();
        if ($until !== null && (!Clock::isYmd($until) || $until <= $today)) {
            throw new ApiException(422, 'date_out_of_range', 'A waiver end date must be after today.', ['until' => 'Must be after today.']);
        }
        $reason = self::reason($reason);
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $assignmentId, $until, $reason): void {
            $a = $this->lockRow($assignmentId);
            if ($a['status'] !== 'open') {
                throw new ApiException(409, 'conflict', 'This assignment is no longer open.');
            }
            Db::exec($db, "UPDATE training_assignments SET tassign_status = 'waived', tassign_open_guard = NULL, tassign_waived_until = ?,
                    tassign_closed_at_utc = ?, tassign_closed_by_user_id = ?, tassign_close_reason = 'waived', tassign_close_note = ?
                WHERE tassign_id = ?", 'ssisi', [$until, Clock::nowUtc(), $this->c->userId, $reason, $assignmentId]);
            Ledger::append($db, AssignmentStore::event('assignment.waived', $a,
                ['course_id' => $a['course_id'], 'until' => $until, 'reason' => $reason], $this->c->userId, ['user_agent' => $this->c->userAgent]));
        });
        return $this->get($assignmentId, Scope::all())['assignment'];
    }

    // =========================================================================================
    // Reads
    // =========================================================================================

    /**
     * f: status (STATUS_FILTERS, default open), course_id, client_id, contact_id, requirement_id, q, page, sort (SORTS keys).
     *
     * @return array{rows:list<array>, total:int, page:int, per_page:int, counts:array{overdue:int, due_soon:int, open:int, waived:int}}
     */
    public function list(array $f, Scope $s): array
    {
        $db = $this->c->db;
        $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => self::PAGE, 'counts' => ['overdue' => 0, 'due_soon' => 0, 'open' => 0, 'waived' => 0]];
        if ($s->isNone()) {
            return $empty;
        }
        $today = Clock::todayLocal();
        $settings = RecordsSettings::fromDb($db);
        $soon = Clock::addDays($today, $settings->dueSoonDays);
        [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
        $where = 'WHERE 1=1' . $scopeSql;
        foreach (['course_id' => 'a.tassign_course_id', 'client_id' => 'c.contact_client_id', 'contact_id' => 'a.tassign_contact_id',
                  'requirement_id' => 'a.tassign_requirement_id'] as $k => $col) {
            if (isset($f[$k]) && $f[$k] !== null && $f[$k] !== '') {
                $where .= " AND $col = ?";
                $types .= 'i';
                $params[] = (int) $f[$k];
            }
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (c.contact_name LIKE ? OR k.course_name LIKE ? OR k.course_code LIKE ?)';
            $types .= 'sss';
            array_push($params, $like, $like, $like);
        }
        $from = ' FROM training_assignments a JOIN contacts c ON c.contact_id = a.tassign_contact_id
            LEFT JOIN training_courses k ON k.course_id = a.tassign_course_id ';

        $cnt = Db::one($db, "SELECT
                SUM(a.tassign_status = 'open' AND a.tassign_due_on < ?) AS overdue,
                SUM(a.tassign_status = 'open' AND a.tassign_due_on >= ? AND a.tassign_due_on <= ?) AS due_soon,
                SUM(a.tassign_status = 'open') AS open_n,
                SUM(a.tassign_status = 'waived') AS waived" . $from . $where, 'sss' . $types, array_merge([$today, $today, $soon], $params));
        $counts = ['overdue' => (int) ($cnt['overdue'] ?? 0), 'due_soon' => (int) ($cnt['due_soon'] ?? 0),
                   'open' => (int) ($cnt['open_n'] ?? 0), 'waived' => (int) ($cnt['waived'] ?? 0)];

        $status = in_array($f['status'] ?? null, self::STATUS_FILTERS, true) ? $f['status'] : 'open';
        switch ($status) {
            case 'open':
            case 'waived':
            case 'completed':
            case 'cancelled':
                $where .= ' AND a.tassign_status = ?';
                $types .= 's';
                $params[] = $status;
                break;
            case 'overdue':
                $where .= " AND a.tassign_status = 'open' AND a.tassign_due_on < ?";
                $types .= 's';
                $params[] = $today;
                break;
            case 'due_soon':
                $where .= " AND a.tassign_status = 'open' AND a.tassign_due_on >= ? AND a.tassign_due_on <= ?";
                $types .= 'ss';
                array_push($params, $today, $soon);
                break;
            case 'cancelled_overdue':
                // Cancelled while overdue (S17, the A6 audit exception): due before the local close date.
                $offset = (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->getOffset();
                $where .= " AND a.tassign_status = 'cancelled' AND a.tassign_due_on < DATE(DATE_ADD(a.tassign_closed_at_utc, INTERVAL ? SECOND))";
                $types .= 'i';
                $params[] = $offset;
                break;
        }
        $total = (int) (Db::one($db, 'SELECT COUNT(*) AS n' . $from . $where, $types, $params)['n'] ?? 0);
        $page = max(1, (int) ($f['page'] ?? 1));
        $order = self::SORTS[$f['sort'] ?? 'due'] ?? self::SORTS['due'];
        $rows = Db::all($db, 'SELECT a.tassign_id' . $from . $where . " ORDER BY $order LIMIT ? OFFSET ?", $types . 'ii',
            array_merge($params, [self::PAGE, ($page - 1) * self::PAGE]));
        $ids = array_map(static fn($r) => (int) $r['tassign_id'], $rows);
        return ['rows' => $this->shapeMany($ids), 'total' => $total, 'page' => $page, 'per_page' => self::PAGE, 'counts' => $counts];
    }

    /** @return array{assignment:array, history:list<array>} 404 when missing or out of scope. */
    public function get(int $id, Scope $s): array
    {
        $row = Db::one($this->c->db, 'SELECT a.tassign_id, c.contact_client_id FROM training_assignments a JOIN contacts c ON c.contact_id = a.tassign_contact_id
            WHERE a.tassign_id = ?', 'i', [$id]);
        if ($row === null || !$s->allows((int) $row['contact_client_id'])) {
            throw ApiException::notFound('That assignment was not found.');
        }
        $a = $this->shapeMany([$id])[0];
        $history = [];
        $events = Db::all($this->c->db, "SELECT e.tevent_seq, e.tevent_type, e.tevent_at_utc, e.tevent_actor_type, e.tevent_actor_user_id, u.user_name,
                e.tevent_payload_json
            FROM training_events e LEFT JOIN users u ON u.user_id = e.tevent_actor_user_id
            WHERE e.tevent_entity_type = 'assignment' AND e.tevent_entity_id = ? ORDER BY e.tevent_seq", 'i', [$id]);
        foreach ($events as $e) {
            $payload = json_decode((string) $e['tevent_payload_json'], true);
            $history[] = [
                'seq' => (int) $e['tevent_seq'],
                'type' => (string) $e['tevent_type'],
                'at' => Clock::toIso((string) $e['tevent_at_utc'], true),
                'actor' => ['type' => (string) $e['tevent_actor_type'], 'user_id' => $e['tevent_actor_user_id'] === null ? null : (int) $e['tevent_actor_user_id'],
                            'name' => $e['tevent_actor_type'] === 'system' ? 'System' : ($e['user_name'] ?? null)],
                'payload' => is_array($payload) ? $payload : null,
            ];
        }
        return ['assignment' => $a, 'history' => $history];
    }

    /**
     * Assignment (spec §4.1) for each id, in the given order.
     *
     * @param list<int> $ids
     * @return list<array>
     */
    public function shapeMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $settings = RecordsSettings::fromDb($db);
        $rows = Db::all($db, 'SELECT ' . AssignmentStore::COLUMNS . ' FROM training_assignments WHERE tassign_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids);
        $byId = [];
        $contactIds = [];
        $courseIds = [];
        $reqIds = [];
        $compIds = [];
        $revIds = [];
        foreach ($rows as $r) {
            $a = AssignmentStore::normalize($r);
            $byId[$a['id']] = $a;
            $contactIds[] = $a['contact_id'];
            $courseIds[] = $a['course_id'];
            if ($a['requirement_id'] !== null) {
                $reqIds[] = $a['requirement_id'];
            }
            $p = PairRules::parseAnchor($a['anchor']);
            if ($p['kind'] === 'renew' || $p['kind'] === 'reissue') {
                $compIds[] = $p['id'];
            } elseif ($p['kind'] === 'retrain') {
                $revIds[] = $p['id'];
            }
        }
        $people = Directory::load($db, Scope::all(), $contactIds, true);
        $courses = CourseCards::load($db, $courseIds);
        $reqs = [];
        if ($reqIds !== []) {
            $reqIds = array_values(array_unique($reqIds));
            foreach (Db::all($db, 'SELECT requirement_id, requirement_name, requirement_is_manual FROM training_requirements WHERE requirement_id IN ('
                . implode(',', array_fill(0, count($reqIds), '?')) . ')', str_repeat('i', count($reqIds)), $reqIds) as $r) {
                $reqs[(int) $r['requirement_id']] = ['id' => (int) $r['requirement_id'], 'name' => (string) $r['requirement_name'],
                                                     'is_manual' => (int) $r['requirement_is_manual'] === 1];
            }
        }
        $comps = [];
        if ($compIds !== []) {
            $compIds = array_values(array_unique($compIds));
            foreach (Db::all($db, 'SELECT completion_id, completion_expires_on FROM training_completions WHERE completion_id IN ('
                . implode(',', array_fill(0, count($compIds), '?')) . ')', str_repeat('i', count($compIds)), $compIds) as $r) {
                $comps[(int) $r['completion_id']] = $r['completion_expires_on'];
            }
        }
        $revs = [];
        if ($revIds !== []) {
            $revIds = array_values(array_unique($revIds));
            foreach (Db::all($db, 'SELECT revision_id, revision_number FROM training_revisions WHERE revision_id IN ('
                . implode(',', array_fill(0, count($revIds), '?')) . ')', str_repeat('i', count($revIds)), $revIds) as $r) {
                $revs[(int) $r['revision_id']] = (int) $r['revision_number'];
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $a = $byId[$id] ?? null;
            if ($a === null) {
                continue;
            }
            $p = $people[$a['contact_id']] ?? null;
            $k = $courses[$a['course_id']] ?? null;
            $req = $a['requirement_id'] !== null ? ($reqs[$a['requirement_id']] ?? null) : null;
            $anchor = PairRules::parseAnchor($a['anchor']);
            $label = match ($anchor['kind']) {
                'renew' => 'Renewal · expires ' . ($comps[$anchor['id']] ?? '?'),
                'retrain' => 'Retrain · Version ' . ($revs[$anchor['id']] ?? '?'),
                'reissue' => 'Record voided · redo',
                default => 'Required by ' . ($req['name'] ?? 'a rule'),
            };
            $display = match ($a['status']) {
                'open' => $a['due_on'] < $today ? 'overdue' : ($a['due_on'] <= Clock::addDays($today, $settings->dueSoonDays) ? 'due_soon' : 'due'),
                default => $a['status'],
            };
            $out[] = [
                'id' => $a['id'],
                'person' => $p !== null ? Directory::ref($p) : ['contact_id' => $a['contact_id'], 'name' => 'Deleted contact #' . $a['contact_id'],
                    'department' => null, 'title' => null, 'job' => null, 'location' => null, 'hire_date' => null, 'employee_no' => null, 'archived' => true],
                'course' => $k !== null ? CourseCards::brief($k) : ['id' => $a['course_id'], 'name' => 'Course #' . $a['course_id'], 'kind' => 'training', 'code' => null],
                'reason' => $a['reason'],
                'anchor' => $a['anchor'],
                'anchor_label' => $label,
                'requirement' => $req,
                'required' => $a['required'],
                'due_on' => $a['due_on'],
                'original_due_on' => $a['original_due_on'],
                'status' => $a['status'],
                'display_status' => $display,
                'days_overdue' => $display === 'overdue' ? PairRules::daysBetween($a['due_on'], $today) : 0,
                'waived_until' => $a['waived_until'],
                'completion_id' => $a['completion_id'],
                'created_at' => Clock::toIso($a['created_at_utc'], true),
                'closed_at' => Clock::toIso($a['closed_at_utc'], true),
                'close_reason' => $a['close_reason'],
                'close_note' => $a['close_note'],
                'reopened_count' => $a['reopened_count'],
            ];
        }
        return $out;
    }

    private function lockRow(int $id): array
    {
        $row = Db::one($this->c->db, 'SELECT ' . AssignmentStore::COLUMNS . ' FROM training_assignments WHERE tassign_id = ? FOR UPDATE', 'i', [$id]);
        if ($row === null) {
            throw ApiException::notFound('That assignment was not found.');
        }
        return AssignmentStore::normalize($row);
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (!mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 500) {
            throw ApiException::validation(['reason' => 'Say why (5 to 500 characters).']);
        }
        return $reason;
    }
}
