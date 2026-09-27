<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;

/**
 * training_odoo_outbox (spec §2.1, §3.4, §3.7): one row per (target, source, action). An operational
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

    /** INSERT IGNORE on the (target, source, action) unique key: true when a new row was queued. */
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
     * Claims up to $limit due rows of this target (closes last, so a create claimed in the same batch
     * goes first) and marks them running with a lease. Expired leases are reclaimed.
     *
     * @return list<array> the claimed rows as they are now (status running, attempts already counted)
     */
    public function claim(string $targetKey, int $limit, string $nowUtc): array
    {
        $limit = max(1, min(500, $limit));
        $lease = self::plus($nowUtc, self::LEASE_S);
        $ids = Db::tx($this->db, function () use ($targetKey, $limit, $nowUtc, $lease): array {
            $rows = Db::all($this->db, "SELECT todoo_id FROM training_odoo_outbox
                WHERE todoo_target_key = ?
                  AND ((todoo_status IN ('pending', 'failed') AND todoo_next_attempt_at_utc <= ?)
                       OR (todoo_status = 'running' AND todoo_lease_until_utc < ?))
                ORDER BY (todoo_action = 'close'), todoo_id
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
            ORDER BY (todoo_action = 'close'), todoo_id", str_repeat('i', count($ids)), $ids);
    }

    public function done(int $id, string $model, int $resId, int $employeeId, array $payload, string $nowUtc): void
    {
        Db::exec($this->db, "UPDATE training_odoo_outbox SET todoo_status = 'done', todoo_done_at_utc = ?, todoo_lease_until_utc = NULL,
                todoo_odoo_model = ?, todoo_odoo_res_id = ?, todoo_odoo_employee_id = ?, todoo_payload_json = ?, todoo_error_class = NULL, todoo_last_error = NULL
            WHERE todoo_id = ?", 'ssiisi',
            [$nowUtc, $model, $resId, $employeeId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
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

    public function findCreate(string $targetKey, string $sourceType, int $sourceId): ?array
    {
        return Db::one($this->db, 'SELECT ' . self::COLS . " FROM training_odoo_outbox
            WHERE todoo_target_key = ? AND todoo_source_type = ? AND todoo_source_id = ? AND todoo_action = 'create'", 'ssi',
            [$targetKey, $sourceType, $sourceId]);
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
        if (!$conds) {
            return [];
        }
        return Db::all($this->db, 'SELECT ' . self::COLS . ' FROM training_odoo_outbox WHERE todoo_target_key = ? AND (' . implode(' OR ', $conds) . ')
            ORDER BY COALESCE(todoo_updated_at, todoo_created_at_utc) DESC, todoo_id DESC LIMIT ?', 'si', [$targetKey, max(1, min(200, $limit))]);
    }

    /** Pending rows next in line (for the dry-run preview). */
    public function upcoming(string $targetKey, int $limit): array
    {
        return Db::all($this->db, 'SELECT ' . self::COLS . " FROM training_odoo_outbox WHERE todoo_target_key = ? AND todoo_status IN ('pending', 'failed')
            ORDER BY todoo_next_attempt_at_utc, (todoo_action = 'close'), todoo_id LIMIT ?", 'si', [$targetKey, max(1, min(200, $limit))]);
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
