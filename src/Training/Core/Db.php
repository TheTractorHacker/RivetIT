<?php

namespace ITFlow\Training\Core;

/**
 * Prepared-statement helpers, the depth-counted transaction, and database-scoped named locks.
 *
 * Everything under src/Training talks to MySQL through here: prepared statements only, ids
 * bound as 'i', never the legacy string sanitizer (spec §0 "SQL"). mysqli exceptions are on (the PHP 8.4
 * default; this codebase never calls mysqli_report()), so every failure throws
 * \mysqli_sql_exception and the Router maps 1062 / 1205 / 1213 to user-facing codes.
 *
 * TRANSACTIONS. tx() is depth-counted: only the outermost call begins and commits, so a
 * service may call another service that also uses tx(). A nested tx() is NOT a savepoint:
 * if any level throws, the whole transaction is marked rollback-only, and an outer caller
 * that catches the exception and carries on still gets a rollback - never a silent partial
 * commit (after a 1213 deadlock InnoDB has already rolled everything back, and later
 * statements would otherwise run outside any transaction). The outermost tx() then rethrows
 * that FIRST inner exception itself, so the Router still maps it (1062 -> 422, 1213/1205 ->
 * 409 busy) and the log shows the real cause.
 *   Rule for every lane: never catch-and-continue around a nested Db::tx(). For "1062 =>
 *   reuse the existing row", call Db::insert() directly inside the outer tx and catch the
 *   \mysqli_sql_exception there (a failed INSERT undoes only itself).
 * Lock order inside a transaction is always: entity rows -> owning course row -> ledger head
 * (Ledger::append is always the last locking statement).
 *
 * NAMED LOCKS are prefixed with DATABASE() so a scratch/verify database on the same server
 * never blocks the live one (MariaDB user-level locks are server-wide).
 */
final class Db
{
    /** Unique-key name => request field, for turning a 1062 into a 422 with `fields` (spec §0). */
    public const KEY_FIELDS = [
        'uq_training_tcat_name'          => 'name',
        'uq_training_ttag_name'          => 'name',
        'uq_training_course_uid'         => 'uid',
        'uq_training_course_code'        => 'code',
        'uq_training_csection_uid'       => 'uid',
        'uq_training_lesson_uid'         => 'uid',
        'uq_training_lres_uid'           => 'uid',
        'uq_training_vcheck'             => 'url',
        'uq_training_qbank_uid'          => 'uid',
        'uq_training_question_uid'       => 'uid',
        'uq_training_option_uid'         => 'uid',
        'uq_training_quiz_uid'           => 'uid',
        'uq_training_quiz_lesson'        => 'lesson_id',
        'uq_training_qrule_uid'          => 'uid',
        'uq_training_tpath_uid'          => 'uid',
        'uq_training_achievement_uid'    => 'uid',
        'uq_training_media_sha_kind'     => 'media_id',
        'uq_training_revision'           => 'number',
        'uq_training_tevent_hash'        => 'hash',
        'uq_training_certtok_completion' => 'completion_id',
        'uq_training_certtok_token'      => 'token',
        // Phase 2 (spec §3.1)
        'uq_training_jobgroup_name'      => 'name',
        'uq_training_req_request'        => 'request_uid',
        'uq_training_assign_open'        => 'course_id',
        'uq_training_completion_source'  => 'request_uid',
        'uq_training_completion_cert'    => 'cert_number',
        'uq_training_cvoid'              => 'completion_id',
        'uq_training_tsession_request'   => 'request_uid',
        'uq_training_tattendee'          => 'contact_id',
        'uq_training_eval_source'        => 'request_uid',
        'PRIMARY'                        => 'id',
    ];

    private static int $depth = 0;
    private static bool $rollbackOnly = false;
    private static ?\Throwable $innerError = null;
    private static ?\mysqli $txDb = null;

    /**
     * Runs $fn inside a transaction. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function tx(\mysqli $db, callable $fn): mixed
    {
        if (self::$depth > 0 && self::$txDb !== $db) {
            throw new \LogicException('Db::tx: nested transaction on a different connection');
        }
        $outermost = self::$depth === 0;
        if ($outermost) {
            $db->begin_transaction();
            self::$txDb = $db;
            self::$rollbackOnly = false;
            self::$innerError = null;
        }
        self::$depth++;
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            self::$depth--;
            if ($outermost) {
                self::finish($db, false);
            } else {
                self::$rollbackOnly = true;
                self::$innerError ??= $e;
            }
            throw $e;
        }
        self::$depth--;
        if ($outermost) {
            if (self::$rollbackOnly) {
                $inner = self::$innerError;
                self::finish($db, false);
                // A caller caught an exception from a nested tx() and carried on. That is a bug in
                // the caller (see the class comment), so say so in the log - then surface the
                // original failure, not a generic one, so its HTTP mapping and cause survive.
                error_log('Training Db::tx: an exception from a nested Db::tx was caught and ignored by its caller; the whole transaction was rolled back. Cause: '
                    . ($inner ? get_class($inner) . ': ' . $inner->getMessage() : 'unknown'));
                throw $inner ?? new \LogicException('Db::tx: an inner transaction failed; the whole transaction was rolled back');
            }
            try {
                $db->commit();
            } catch (\Throwable $e) {
                self::finish($db, false);
                throw $e;
            }
            self::finish($db, null);
        }
        return $result;
    }

    public static function depth(): int
    {
        return self::$depth;
    }

    /**
     * Hashes are computed over the bytes the connection hands back, so the writer and the
     * verifier must both talk utf8mb4 (mysqlnd's default here; asserted, not assumed).
     */
    public static function ensureUtf8mb4(\mysqli $db): void
    {
        if ($db->character_set_name() !== 'utf8mb4') {
            $db->set_charset('utf8mb4');
        }
    }

    public static function one(\mysqli $db, string $sql, string $types = '', array $p = []): ?array
    {
        $stmt = self::run($db, $sql, $types, $p);
        try {
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($res) {
                $res->free();
            }
            return $row ?: null;
        } finally {
            $stmt->close();
        }
    }

    /** @return list<array<string, mixed>> */
    public static function all(\mysqli $db, string $sql, string $types = '', array $p = []): array
    {
        $stmt = self::run($db, $sql, $types, $p);
        try {
            $res = $stmt->get_result();
            if (!$res) {
                return [];
            }
            $rows = $res->fetch_all(MYSQLI_ASSOC);
            $res->free();
            return $rows;
        } finally {
            $stmt->close();
        }
    }

    /** @return int affected rows */
    public static function exec(\mysqli $db, string $sql, string $types = '', array $p = []): int
    {
        $stmt = self::run($db, $sql, $types, $p);
        try {
            return (int) $stmt->affected_rows;
        } finally {
            $stmt->close();
        }
    }

    /** @return int the new AUTO_INCREMENT id (0 for tables without one) */
    public static function insert(\mysqli $db, string $sql, string $types = '', array $p = []): int
    {
        $stmt = self::run($db, $sql, $types, $p);
        try {
            return (int) $stmt->insert_id;
        } finally {
            $stmt->close();
        }
    }

    /**
     * GET_LOCK(CONCAT(DATABASE(), ':', $name), $timeoutS). Returns false on timeout.
     * The full lock name must fit MariaDB's 64-character limit; a longer one is a programming
     * error and throws rather than silently locking a truncated (shared) name.
     */
    public static function lock(\mysqli $db, string $name, int $timeoutS): bool
    {
        self::fullLockName($db, $name);
        $row = self::one($db, "SELECT GET_LOCK(CONCAT(DATABASE(), ':', ?), ?) AS l", 'si', [$name, max(0, $timeoutS)]);
        if ($row === null || $row['l'] === null) {
            throw new \RuntimeException("Db::lock: GET_LOCK failed for '$name'");
        }
        return (int) $row['l'] === 1;
    }

    public static function unlock(\mysqli $db, string $name): void
    {
        self::one($db, "SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':', ?)) AS r", 's', [$name]);
    }

    /** The server-wide lock name lock() uses for $name on this connection's database. */
    public static function fullLockName(\mysqli $db, string $name): string
    {
        $row = $db->query("SELECT DATABASE() AS d")->fetch_assoc();
        $dbName = $row['d'] ?? null;
        if ($dbName === null || $dbName === '') {
            throw new \RuntimeException('Db::lock: no database selected');
        }
        $full = $dbName . ':' . $name;
        if (mb_strlen($full, 'UTF-8') > 64) {
            throw new \LengthException("Db::lock: lock name '$full' exceeds 64 characters");
        }
        return $full;
    }

    private static function run(\mysqli $db, string $sql, string $types, array $p): \mysqli_stmt
    {
        if (strlen($types) !== count($p)) {
            throw new \InvalidArgumentException('Db: ' . strlen($types) . ' bind types for ' . count($p) . ' parameters');
        }
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            // Only reachable if someone turned mysqli exceptions off.
            throw new \RuntimeException('Db: prepare failed: ' . $db->error);
        }
        if ($types !== '') {
            $params = array_values($p);
            $stmt->bind_param($types, ...$params);
        }
        if ($stmt->execute() === false) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('Db: execute failed: ' . $err);
        }
        return $stmt;
    }

    private static function finish(\mysqli $db, ?bool $commit): void
    {
        if ($commit === false) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // The connection may already be gone; the original error is what matters.
            }
        }
        self::$depth = 0;
        self::$txDb = null;
        self::$rollbackOnly = false;
        self::$innerError = null;
    }
}
