<?php

namespace ITFlow\Training\Upstream;

/**
 * Table probes for Phase 5 (spec §3.1, §0 "Everything degrades"). Every Phase 5 entry point checks,
 * in order: the module toggle, then Upstream availability (these probes and P2::has), then its own
 * tables - so nothing 500s before the P2, P3 or P5 migrations have run.
 *
 * has() runs ONE information_schema query per table list and memoises the answer per request
 * (per connection and list). Any error answers false; it never throws.
 */
final class Schema
{
    public const P2 = ['training_completions', 'training_completion_voids', 'training_assignments', 'training_cert_tokens'];
    public const P2_LINKS = ['contact_odoo_attributes'];
    public const P3 = ['training_attempts', 'training_attempt_results', 'training_attempt_answers'];
    public const P3_AWARDS = ['training_achievement_awards'];
    public const P5 = ['training_automation', 'training_odoo_outbox', 'training_odoo_map', 'training_reminder_log', 'training_video_watch'];

    /** @var array<string, bool> */
    private static array $memo = [];

    /** @param list<string> $tables */
    public static function has(\mysqli $db, array $tables): bool
    {
        $tables = array_values(array_unique(array_map('strval', $tables)));
        if ($tables === []) {
            return true;
        }
        foreach ($tables as $t) {
            if (preg_match('/^[a-z0-9_]{1,64}$/D', $t) !== 1) {
                return false;
            }
        }
        sort($tables);
        $key = spl_object_id($db) . ':' . implode(',', $tables);
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $ok = false;
        try {
            $stmt = $db->prepare('SELECT COUNT(DISTINCT TABLE_NAME) AS n FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
            if ($stmt !== false) {
                $stmt->bind_param(str_repeat('s', count($tables)), ...$tables);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($res) {
                    $res->free();
                }
                $stmt->close();
                $ok = (int) ($row['n'] ?? 0) === count($tables);
            }
        } catch (\Throwable $e) {
            error_log('Training Upstream\Schema: probe failed: ' . get_class($e) . ': ' . $e->getMessage());
            $ok = false;
        }
        return self::$memo[$key] = $ok;
    }

    /** Tests and the migration harness: forget the memoised answers (a migration just ran). */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
