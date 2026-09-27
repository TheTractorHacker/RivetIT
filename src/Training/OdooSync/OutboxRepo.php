<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;

/**
 * training_odoo_outbox (spec §2.1, §3.4, §3.7): one row per (target, source, action, mode) - since 2.6.97 the
 * mode (Targets: resume | skill | note) is part of the unique key, so one record has one row per Odoo target
 * and action, each with its own attempts, backoff, status and close. An operational
 * copy - never hashed, never ledgered; done, dead and skipped rows are kept forever for audit.
 *
 * Statuses: pending -> running (claimed, 5-minute lease) -> done | failed (retry later) | dead |
 * skipped. 'hold' rows (no link, flagged link, name mismatch) stay pending, are not charged an
 * attempt and come back in 24 h. Only claim() uses a transaction (FOR UPDATE SKIP LOCKED); every
 * other write is one autocommit statement. No network I/O happens here.
 */
final class OutboxRepo
{
    public const MAX_ATTEMPTS = 12;
    public const LEASE_S = 300;
    public const BACKOFF_S = [300, 900, 3600, 3600, 14400, 14400, 43200, 43200, 86400, 86400, 86400, 86400];
    public const HOLD_S = 86400;

    private const COLS = 'todoo_id, todoo_target_key, todoo_integration_id, todoo_source_type, todoo_source_id, todoo_action, todoo_contact_id,
        todoo_mode, todoo_marker, todoo_status, todoo_attempts, todoo_next_attempt_at_utc, todoo_lease_until_utc, todoo_odoo_employee_id,
        todoo_odoo_model, todoo_odoo_res_id, todoo_payload_json, todoo_error_class, todoo_last_error, todoo_created_at_utc,
        todoo_done_at_utc, todoo_updated_by, todoo_updated_at';

    public function __construct(private readonly \mysqli $db)
    {
    }

    /** INSERT IGNORE on the (target, source, action, mode) unique key: true when a new row was queued. */
    public function enqueue(Target $t, string $sourceType, int $sourceId, string $action, int $contactId, string $mode, string $marker, string $nowUtc): bool
    {
        if (!in_array($sourceType, ['completion', 'award'], true) || !in_array($action, ['create', 'close'], true)
            || !in_array($mode, ['resume', 'skill', 'note'], true) || preg_match(Marker::RE, $marker) !== 1) {
            throw new \InvalidArgumentException('OutboxRepo::enqueue: bad arguments');
        }
        return Db::exec($this->db, "INSERT IGNORE INTO training_odoo_outbox (todoo_target_key, todoo_integration_id, todoo_source_type, todoo_source_id,
                todoo_action, todoo_contact_id, todoo_mode, todoo_marker, todoo_status, todoo_attempts, todoo_next_attempt_at_utc, todoo_created_at_utc)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?)", 'sisisissss',
            [$t->key, $t->integrationId, $sourceType, $sourceId, $action, $contactId, $mode, $marker, $nowUtc, $nowUtc]) === 1;
    }

    /**
     * Claim and processing order: closes whose create is already done first (a void is one cheap call, and with
     * one row per target a backlog of creates - e.g. people waiting for an employee link - must not hold a revocation
     * back for hours), then creates, then the other closes (their create may be claimed in the same batch and goes
     * first, as in Phase 5).
     */
    private const ORDER = "CASE WHEN todoo_action = 'create' THEN 1
            WHEN EXISTS (SELECT 1 FROM training_odoo_outbox c WHERE c.todoo_target_key = training_odoo_outbox.todoo_target_key
                AND c.todoo_source_type = training_odoo_outbox.todoo_source_type AND c.todoo_source_id = training_odoo_outbox.todoo_source_id
                AND c.todoo_action = 'create' AND c.todoo_mode = training_odoo_outbox.todoo_mode AND c.todoo_status = 'done') THEN 0
            ELSE 2 END, todoo_id";

    /**
     * Claims up to $limit due rows of this target (in ORDER: closes of sent records first, closes waiting for
     * their create last) and marks them running with a lease. Expired leases are reclaimed.
     *
     * @return list<array> the claimed rows as they are now (status running, attempts already counted)
     */
    public function claim(string $targetKey, int $limit, string $nowUtc, ?array $createModes = null): array
    {
        $limit = max(1, min(500, $limit));
        $lease = self::plus($nowUtc, self::LEASE_S);
        $modeSql = self::createModesSql($createModes);
        $ids = Db::tx($this->db, function () use ($targetKey, $limit, $nowUtc, $lease, $modeSql): array {
            $rows = Db::all($this->db, "SELECT todoo_id FROM training_odoo_outbox
                WHERE todoo_target_key = ?
                  AND ((todoo_status IN ('pending', 'failed') AND todoo_next_attempt_at_utc <= ?)
                       OR (todoo_status = 'running' AND todoo_lease_until_utc < ?))$modeSql
                ORDER BY " . self::ORDER . "
                LIMIT ? FOR UPDATE SKIP LOCKED", 'sssi', [$targetKey, $nowUtc, $nowUtc, $limit]);
            $ids = array_map(static fn($r) => (int) $r['todoo_id'], $rows);
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'running', todoo_lease_until_utc = ?, todoo_attempts = todoo_attempts + 1
                    WHERE todoo_id IN ($in)", 's' . str_repeat('i', count($ids)), array_merge([$lease], $ids));
            }
            return $ids;
        });
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return Db::all($this->db, 'SELECT ' . self::COLS . " FROM training_odoo_outbox WHERE todoo_id IN ($in)
            ORDER BY " . self::ORDER, str_repeat('i', count($ids)), $ids);
    }

    public function done(int $id, string $model, int $resId, int $employeeId, array $payload, string $nowUtc): void
    {
        Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'done', todoo_done_at_utc = ?, todoo_lease_until_utc = NULL,
                todoo_odoo_model = ?, todoo_odoo_res_id = ?, todoo_odoo_employee_id = ?, todoo_payload_json = ?, todoo_error_class = NULL, todoo_last_error = NULL
            WHERE todoo_id = ?", 'ssiisi',
            [$nowUtc, $model, $resId, $employeeId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    }

    /**
     * Certification target: saves the values of the create about to be sent on its (running) row, BEFORE the call, in
     * todoo_payload_json {"odoo_skill_sent": {...}} (the column holds the full payload only once the row is done). A
     * lost answer is then looked for with exactly these values on every later attempt and on a void, even if the course's
     * mapping or the level changed in between (sentSkill). An admin Retry keeps them.
     */
    public function rememberSkill(int $id, array $vals): void
    {
        $sent = self::skillVals($vals);
        if ($sent === null) {
            throw new \InvalidArgumentException('OutboxRepo::rememberSkill: bad certification values');
        }
        Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_payload_json = ? WHERE todoo_id = ? AND todoo_status <> 'done'", 'si',
            [json_encode(['odoo_skill_sent' => $sent], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    }

    /**
     * The certification values an earlier attempt of this create row saved before its create call, or null (never sent).
     *
     * @return array{employee_id:int, skill_id:int, skill_level_id:int, skill_type_id:int, valid_from:string, valid_to:string|false}|null
     */
    public static function sentSkill(array $row): ?array
    {
        if (($row['todoo_mode'] ?? '') !== 'skill' || ($row['todoo_action'] ?? '') !== 'create' || !is_string($row['todoo_payload_json'] ?? null)) {
            return null;
        }
        $p = json_decode($row['todoo_payload_json'], true);
        return is_array($p) && is_array($p['odoo_skill_sent'] ?? null) ? self::skillVals($p['odoo_skill_sent']) : null;
    }

    /** hr.employee.skill create values, typed, or null when any is missing or malformed. */
    private static function skillVals(array $v): ?array
    {
        $out = [];
        foreach (['employee_id', 'skill_id', 'skill_level_id', 'skill_type_id'] as $k) {
            if (!is_int($v[$k] ?? null) || $v[$k] < 1) {
                return null;
            }
            $out[$k] = $v[$k];
        }
        $date = static fn(mixed $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) === 1;
        if (!$date($v['valid_from'] ?? null)) {
            return null;
        }
        $to = $v['valid_to'] ?? false;
        if ($to !== false && !$date($to)) {
            return null;
        }
        return $out + ['valid_from' => $v['valid_from'], 'valid_to' => $to];
    }

    /**
     * transient: failed, retried after BACKOFF_S[attempts-1]; dead at MAX_ATTEMPTS
     * permanent: dead   |  hold: pending, attempt given back, retried in 24 h   |  policy: skipped
     *
     * @return string the row's new status ('failed' | 'dead' | 'pending' | 'skipped')
     */
    public function fail(int $id, string $class, string $message, string $nowUtc): string
    {
        $msg = (string) Text::clip($message, 500);
        $row = Db::one($this->db, 'SELECT todoo_attempts FROM training_odoo_outbox WHERE todoo_id = ?', 'i', [$id]);
        if ($row === null) {
            return 'missing';
        }
        $attempts = (int) $row['todoo_attempts'];
        switch ($class) {
            case 'transient':
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $status = 'dead';
                    $next = $nowUtc;
                } else {
                    $status = 'failed';
                    $next = self::plus($nowUtc, self::BACKOFF_S[max(0, min(count(self::BACKOFF_S) - 1, $attempts - 1))]);
                }
                Db::exec($this->db, 'UPDATE training_odoo_outbox SET todoo_status = ?, todoo_next_attempt_at_utc = ?, todoo_lease_until_utc = NULL,
                    todoo_error_class = ?, todoo_last_error = ? WHERE todoo_id = ?', 'ssssi', [$status, $next, 'transient', $msg, $id]);
                return $status;
            case 'permanent':
                Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'dead', todoo_lease_until_utc = NULL, todoo_error_class = 'permanent',
                    todoo_last_error = ? WHERE todoo_id = ?", 'si', [$msg, $id]);
                return 'dead';
            case 'hold':
                Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'pending', todoo_attempts = GREATEST(todoo_attempts - 1, 0),
                    todoo_next_attempt_at_utc = ?, todoo_lease_until_utc = NULL, todoo_error_class = 'hold', todoo_last_error = ? WHERE todoo_id = ?",
                    'ssi', [self::plus($nowUtc, self::HOLD_S), $msg, $id]);
                return 'pending';
            case 'policy':
                Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'skipped', todoo_lease_until_utc = NULL, todoo_error_class = 'policy',
                    todoo_last_error = ? WHERE todoo_id = ?", 'si', [$msg, $id]);
                return 'skipped';
        }
        throw new \InvalidArgumentException("OutboxRepo::fail: unknown class $class");
    }

    /**
     * Back to pending without charging the attempt (an auth/config pause, a close waiting for its
     * create, the rest of a run that stopped). $class/$message are recorded when given.
     */
    public function release(array $ids, int $delayS, string $nowUtc, ?string $class = null, ?string $message = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) {
            return;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE training_odoo_outbox SET todoo_status = 'pending', todoo_attempts = GREATEST(todoo_attempts - 1, 0),
                todoo_next_attempt_at_utc = ?, todoo_lease_until_utc = NULL";
        $types = 's';
        $params = [self::plus($nowUtc, max(0, $delayS))];
        if ($class !== null) {
            $sql .= ', todoo_error_class = ?, todoo_last_error = ?';
            $types .= 'ss';
            $params[] = $class;
            $params[] = (string) Text::clip((string) $message, 500);
        }
        Db::exec($this->db, $sql . " WHERE todoo_status = 'running' AND todoo_id IN ($in)", $types . str_repeat('i', count($ids)), array_merge($params, $ids));
    }

    public function find(int $id): ?array
    {
        return Db::one($this->db, 'SELECT ' . self::COLS . ' FROM training_odoo_outbox WHERE todoo_id = ?', 'i', [$id]);
    }

    /** The create row of one record for one target (mode). */
    public function findCreate(string $targetKey, string $sourceType, int $sourceId, string $mode = 'resume'): ?array
    {
        return Db::one($this->db, 'SELECT ' . self::COLS . " FROM training_odoo_outbox
            WHERE todoo_target_key = ? AND todoo_source_type = ? AND todoo_source_id = ? AND todoo_action = 'create' AND todoo_mode = ?", 'ssis',
            [$targetKey, $sourceType, $sourceId, $mode]);
    }

    /** Every create row of one record (one per target it was queued for), mode order resume, skill, note. */
    public function findCreates(string $targetKey, string $sourceType, int $sourceId): array
    {
        return Db::all($this->db, 'SELECT ' . self::COLS . " FROM training_odoo_outbox
            WHERE todoo_target_key = ? AND todoo_source_type = ? AND todoo_source_id = ? AND todoo_action = 'create'
            ORDER BY FIELD(todoo_mode, 'resume', 'skill', 'note')", 'ssi', [$targetKey, $sourceType, $sourceId]);
    }

    /**
     * The OTHER record whose done create (same target and mode) already holds Odoo record $resId, with the status
     * of its close for that mode (null when none is queued), or null. The certification target uses it: two ITFlow
     * records never share one Odoo certification.
     *
     * @return array{source_type:string, source_id:int, close_status:?string}|null
     */
    public function holderOf(string $targetKey, string $mode, int $resId, string $exceptType, int $exceptId): ?array
    {
        $r = Db::one($this->db, "SELECT c.todoo_source_type, c.todoo_source_id, x.todoo_status AS close_status
            FROM training_odoo_outbox c
            LEFT JOIN training_odoo_outbox x ON x.todoo_target_key = c.todoo_target_key AND x.todoo_source_type = c.todoo_source_type
                 AND x.todoo_source_id = c.todoo_source_id AND x.todoo_action = 'close' AND x.todoo_mode = c.todoo_mode
            WHERE c.todoo_target_key = ? AND c.todoo_mode = ? AND c.todoo_action = 'create' AND c.todoo_status = 'done' AND c.todoo_odoo_res_id = ?
              AND NOT (c.todoo_source_type = ? AND c.todoo_source_id = ?)
            ORDER BY c.todoo_id LIMIT 1", 'ssisi', [$targetKey, $mode, $resId, $exceptType, $exceptId]);
        return $r === null ? null : ['source_type' => (string) $r['todoo_source_type'], 'source_id' => (int) $r['todoo_source_id'],
            'close_status' => $r['close_status'] === null ? null : (string) $r['close_status']];
    }

    /**
     * counts() per target: mode => {pending, held, running, done, failed, dead, skipped}, every mode present.
     *
     * @return array<string, array<string,int>>
     */
    public function countsByMode(string $targetKey): array
    {
        $zero = ['pending' => 0, 'held' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0];
        $out = array_fill_keys(Targets::MODES, $zero);
        $rows = Db::all($this->db, "SELECT todoo_mode AS m, todoo_status AS s, (todoo_status = 'pending' AND todoo_error_class = 'hold') AS h, COUNT(*) AS n
            FROM training_odoo_outbox WHERE todoo_target_key = ? GROUP BY m, s, h", 's', [$targetKey]);
        foreach ($rows as $r) {
            $m = (string) $r['m'];
            if (!isset($out[$m])) {
                continue;
            }
            $key = (int) $r['h'] === 1 ? 'held' : (string) $r['s'];
            $out[$m][$key] = ($out[$m][$key] ?? 0) + (int) $r['n'];
        }
        return $out;
    }

    /** @return array{pending:int, held:int, running:int, done:int, failed:int, dead:int, skipped:int} pending excludes held */
    public function counts(string $targetKey): array
    {
        $out = ['pending' => 0, 'held' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 0];
        $rows = Db::all($this->db, "SELECT todoo_status AS s, (todoo_status = 'pending' AND todoo_error_class = 'hold') AS h, COUNT(*) AS n
            FROM training_odoo_outbox WHERE todoo_target_key = ? GROUP BY s, h", 's', [$targetKey]);
        foreach ($rows as $r) {
            $key = (int) $r['h'] === 1 ? 'held' : (string) $r['s'];
            $out[$key] = ($out[$key] ?? 0) + (int) $r['n'];
        }
        return $out;
    }

    /** @param list<string> $statuses outbox statuses, plus 'held' for pending rows on hold */
    public function recent(string $targetKey, array $statuses, int $limit): array
    {
        $conds = [];
        $real = array_values(array_intersect($statuses, ['pending', 'running', 'done', 'failed', 'dead', 'skipped']));
        if ($real) {
            // $real is an intersection with a fixed list, so these literals never come from input.
            $conds[] = "todoo_status IN ('" . implode("','", $real) . "')";
        }
        if (in_array('held', $statuses, true)) {
            $conds[] = "(todoo_status = 'pending' AND todoo_error_class = 'hold')";
        }
        if (in_array('skipped_mapping', $statuses, true)) {
            // certification rows skipped because the course/achievement has no (valid) Odoo skill mapping: Retry after mapping
            $conds[] = "(todoo_status = 'skipped' AND todoo_mode = 'skill' AND todoo_error_class = 'policy'
                         AND (todoo_last_error LIKE 'not\\_mapped:%' OR todoo_last_error LIKE 'skill\\_not\\_in\\_type:%'))";
        }
        if (!$conds) {
            return [];
        }
        return Db::all($this->db, 'SELECT ' . self::COLS . ' FROM training_odoo_outbox WHERE todoo_target_key = ? AND (' . implode(' OR ', $conds) . ')
            ORDER BY COALESCE(todoo_updated_at, todoo_created_at_utc) DESC, todoo_id DESC LIMIT ?', 'si', [$targetKey, max(1, min(200, $limit))]);
    }

    /** Pending rows next in line (for the dry-run preview); creates only for the targets switched on. */
    public function upcoming(string $targetKey, int $limit, ?array $createModes = null): array
    {
        return Db::all($this->db, 'SELECT ' . self::COLS . " FROM training_odoo_outbox WHERE todoo_target_key = ? AND todoo_status IN ('pending', 'failed')"
            . self::createModesSql($createModes) . "
            ORDER BY todoo_next_attempt_at_utc, (todoo_action = 'close'), todoo_id LIMIT ?", 'si', [$targetKey, max(1, min(200, $limit))]);
    }

    /**
     * " AND (close OR mode IN (…))": closes of every target are always sent (a void must reach every target that got the
     * record); creates only for the targets switched on - a create queued while a target was on waits, untouched, until
     * it is switched on again. null = no filter. The modes come from Targets::MODES only, never from input.
     */
    private static function createModesSql(?array $createModes): string
    {
        if ($createModes === null) {
            return '';
        }
        $modes = array_values(array_intersect(Targets::MODES, $createModes));
        return $modes === [] ? " AND todoo_action = 'close'" : " AND (todoo_action = 'close' OR todoo_mode IN ('" . implode("','", $modes) . "'))";
    }

    /** Admin Retry: a dead, failed, skipped or held row goes back to pending now with a fresh attempt count. */
    public function retry(int $id, int $userId): bool
    {
        return Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'pending', todoo_attempts = 0, todoo_next_attempt_at_utc = ?,
                todoo_error_class = NULL, todoo_last_error = NULL, todoo_updated_by = ?
            WHERE todoo_id = ? AND (todoo_status IN ('dead', 'failed', 'skipped') OR (todoo_status = 'pending' AND todoo_error_class = 'hold'))",
            'sii', [self::nowUtc(), $userId, $id]) === 1;
    }

    /** Admin "Retry all failed" for one target. @return int rows re-queued */
    public function retryAll(string $targetKey, int $userId): int
    {
        return Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'pending', todoo_attempts = 0, todoo_next_attempt_at_utc = ?,
                todoo_error_class = NULL, todoo_last_error = NULL, todoo_updated_by = ?
            WHERE todoo_target_key = ? AND todoo_status IN ('dead', 'failed')", 'sis', [self::nowUtc(), $userId, $targetKey]);
    }

    /** Admin Skip (with a reason): a row that is not done or running is skipped for good (Retry undoes it). */
    public function skip(int $id, string $reason, int $userId): bool
    {
        return Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'skipped', todoo_error_class = 'policy', todoo_last_error = ?, todoo_updated_by = ?
            WHERE todoo_id = ? AND todoo_status IN ('pending', 'failed', 'dead')", 'sii',
            [(string) Text::clip('Skipped by an admin: ' . $reason, 500), $userId, $id]) === 1;
    }

    public static function plus(string $utc, int $seconds): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', strlen($utc) === 19 ? $utc . '.000' : $utc, new \DateTimeZone('UTC'));
        if ($dt === false) {
            throw new \InvalidArgumentException("OutboxRepo: not a UTC datetime: $utc");
        }
        return $dt->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('Y-m-d H:i:s.v');
    }

    private static function nowUtc(): string
    {
        return Clock::nowUtc();
    }
}
