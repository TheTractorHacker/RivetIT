<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\People\Scope;

/**
 * Read-only lookups the report pages share: in-scope departments, published courses, a
 * course's current-revision facts, and the latest retrain revision per course (the `RR` input
 * of Compliance\PairRules). Prepared statements only.
 */
final class Lookup
{
    public const NO_DEPARTMENT = 'No department';

    /**
     * Active departments the scope can see, by name. Client 0 ("No department") is listed only
     * for all-scope users (spec §0 #3).
     *
     * @return list<array{id:int, name:string}>
     */
    public static function departments(\mysqli $db, Scope $s): array
    {
        if ($s->isNone()) {
            return [];
        }
        [$sql, $types, $params] = $s->sqlIn('client_id');
        $rows = Db::all($db, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL$sql ORDER BY client_name", $types, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['client_id'], 'name' => (string) $r['client_name']];
        }
        if ($s->isAll()) {
            $out[] = ['id' => 0, 'name' => self::NO_DEPARTMENT];
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int, string> client_id => name (0 => "No department") */
    public static function departmentNames(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [0 => self::NO_DEPARTMENT];
        $ids = array_values(array_filter($ids, static fn($i) => $i > 0));
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach (Db::all($db, "SELECT client_id, client_name FROM clients WHERE client_id IN ($in)", str_repeat('i', count($ids)), $ids) as $r) {
                $out[(int) $r['client_id']] = (string) $r['client_name'];
            }
        }
        return $out;
    }

    /**
     * Published, non-archived courses, by name.
     *
     * @return list<array{id:int, name:string, code:?string, kind:string, revision_number:?int}>
     */
    public static function publishedCourses(\mysqli $db, ?string $kind = null): array
    {
        $sql = "SELECT co.course_id, co.course_name, co.course_code, co.course_kind, r.revision_number
                FROM training_courses co
                LEFT JOIN training_revisions r ON r.revision_id = co.course_current_revision_id
                WHERE co.course_archived_at IS NULL AND co.course_current_revision_id IS NOT NULL";
        $types = '';
        $params = [];
        if ($kind !== null) {
            $sql .= ' AND co.course_kind = ?';
            $types = 's';
            $params = [$kind];
        }
        $out = [];
        foreach (Db::all($db, $sql . ' ORDER BY co.course_name', $types, $params) as $r) {
            $out[] = [
                'id' => (int) $r['course_id'],
                'name' => (string) $r['course_name'],
                'code' => $r['course_code'] !== null && $r['course_code'] !== '' ? (string) $r['course_code'] : null,
                'kind' => (string) $r['course_kind'],
                'revision_number' => $r['revision_number'] !== null ? (int) $r['revision_number'] : null,
            ];
        }
        return $out;
    }

    /**
     * A course with its current revision's settings (spec §3.3: course facts come from the
     * current revision JSON). Null when the course does not exist.
     *
     * @return ?array{id:int, name:string, code:?string, kind:string, archived:bool, revision_id:?int,
     *   revision_number:?int, published_on:?string, validity_months:?int, renewal_lead_days:int,
     *   est_minutes:?int, pass_pct:?int, regulation_ref:?string, is_qualification:bool,
     *   components:array, json:?array}
     */
    public static function course(\mysqli $db, int $courseId, bool $withJson = false): ?array
    {
        $row = Db::one($db, "SELECT co.course_id, co.course_name, co.course_code, co.course_kind, co.course_archived_at,
                co.course_validity_months, co.course_renewal_lead_days, co.course_est_minutes, co.course_regulation_ref,
                co.course_is_qualification, co.course_needs_online, co.course_needs_session, co.course_needs_practical,
                co.course_external_only, r.revision_id, r.revision_number, r.revision_json, r.revision_published_at_utc
            FROM training_courses co
            LEFT JOIN training_revisions r ON r.revision_id = co.course_current_revision_id
            WHERE co.course_id = ?", 'i', [$courseId]);
        if ($row === null) {
            return null;
        }
        $json = null;
        if ($row['revision_json'] !== null) {
            try {
                $json = json_decode((string) $row['revision_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = null;
            }
        }
        $jc = is_array($json['course'] ?? null) ? $json['course'] : [];
        $comp = is_array($jc['components'] ?? null) ? $jc['components'] : [];
        $passPct = null;
        foreach ((is_array($json['lessons'] ?? null) ? $json['lessons'] : []) as $lesson) {
            $quiz = is_array($lesson['quiz'] ?? null) ? $lesson['quiz'] : null;
            if ($quiz !== null && isset($quiz['pass_pct']) && ($quiz['role'] ?? '') === 'exam') {
                $passPct = (int) $quiz['pass_pct'];
                break;
            }
            if ($quiz !== null && isset($quiz['pass_pct']) && $passPct === null && !empty($quiz['must_pass'])) {
                $passPct = (int) $quiz['pass_pct'];
            }
        }
        $validity = array_key_exists('validity_months', $jc) ? $jc['validity_months'] : $row['course_validity_months'];
        return [
            'id' => (int) $row['course_id'],
            'name' => (string) $row['course_name'],
            'code' => $row['course_code'] !== null && $row['course_code'] !== '' ? (string) $row['course_code'] : null,
            'kind' => (string) $row['course_kind'],
            'archived' => $row['course_archived_at'] !== null,
            'revision_id' => $row['revision_id'] !== null ? (int) $row['revision_id'] : null,
            'revision_number' => $row['revision_number'] !== null ? (int) $row['revision_number'] : null,
            'published_on' => $row['revision_published_at_utc'] !== null ? Clock::localDate((string) $row['revision_published_at_utc']) : null,
            'validity_months' => $validity !== null ? (int) $validity : null,
            'renewal_lead_days' => (int) ($jc['renewal_lead_days'] ?? $row['course_renewal_lead_days'] ?? 30),
            'est_minutes' => isset($jc['est_minutes']) ? (int) $jc['est_minutes'] : ($row['course_est_minutes'] !== null ? (int) $row['course_est_minutes'] : null),
            'pass_pct' => $passPct,
            'regulation_ref' => ($jc['regulation_ref'] ?? $row['course_regulation_ref']) ?: null,
            'is_qualification' => (bool) ($jc['is_qualification'] ?? $row['course_is_qualification']),
            'components' => [
                'online' => (bool) ($comp['online'] ?? $row['course_needs_online']),
                'session' => (bool) ($comp['session'] ?? $row['course_needs_session']),
                'practical' => (bool) ($comp['practical'] ?? $row['course_needs_practical']),
                'external_only' => (bool) ($comp['external_only'] ?? $row['course_external_only']),
            ],
            'json' => $withJson ? $json : null,
        ];
    }

    /**
     * The latest requires-retraining revision per course, shaped as PairRules' RR:
     * {revision_id, revision_number, published_on (local), retrain_due_days}.
     *
     * @param list<int> $courseIds
     * @return array<int, array{revision_id:int, revision_number:int, published_on:string, retrain_due_days:int}>
     */
    public static function retrainRevisions(\mysqli $db, array $courseIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $courseIds), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all($db, "SELECT r.revision_course_id, r.revision_id, r.revision_number, r.revision_published_at_utc, r.revision_retrain_due_days
            FROM training_revisions r
            WHERE r.revision_requires_retraining = 1 AND r.revision_course_id IN ($in)
            ORDER BY r.revision_course_id, r.revision_number DESC", str_repeat('i', count($ids)), $ids);
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['revision_course_id'];
            if (isset($out[$cid])) {
                continue;
            }
            $out[$cid] = [
                'revision_id' => (int) $r['revision_id'],
                'revision_number' => (int) $r['revision_number'],
                'published_on' => Clock::localDate((string) $r['revision_published_at_utc']),
                'retrain_due_days' => (int) ($r['revision_retrain_due_days'] ?? 0),
            ];
        }
        return $out;
    }

    /** The user's display name (fresh read, never the session copy). */
    public static function userName(\mysqli $db, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $r = Db::one($db, 'SELECT user_name FROM users WHERE user_id = ?', 'i', [$userId]);
        return $r !== null ? (string) $r['user_name'] : null;
    }

    /** Whether a table exists in the current database (one information_schema read, memoised). */
    public static function tableExists(\mysqli $db, string $table): bool
    {
        static $memo = [];
        if (!array_key_exists($table, $memo)) {
            $r = Db::one($db, 'SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', [$table]);
            $memo[$table] = $r !== null;
        }
        return $memo[$table];
    }

    /**
     * The ledger head for footers: "#{seq}/{hash16}", or null before the ledger exists.
     */
    public static function ledgerStamp(\mysqli $db): ?string
    {
        try {
            $h = \ITFlow\Training\Core\Ledger::head($db);
            return '#' . $h['seq'] . '/' . substr($h['hash'], 0, 16);
        } catch (\Throwable) {
            return null;
        }
    }
}
