<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Db;

/**
 * Chooses the attempt-statistics provider: SqlAttemptStats when every column in
 * SqlAttemptStats::REQUIRED_COLUMNS exists in this database, else NullAttemptStats. The probe
 * is one information_schema.COLUMNS query, memoised per request (spec §3.6).
 */
final class AttemptStats
{
    /** @var array<string, bool> database name => probe result */
    private static array $probe = [];

    public static function provider(\mysqli $db): AttemptStatsProvider
    {
        return self::probe($db) ? new SqlAttemptStats($db) : new NullAttemptStats();
    }

    public static function probe(\mysqli $db): bool
    {
        $name = (string) ($db->query('SELECT DATABASE() AS d')->fetch_assoc()['d'] ?? '');
        if (array_key_exists($name, self::$probe)) {
            return self::$probe[$name];
        }
        $tables = array_keys(SqlAttemptStats::REQUIRED_COLUMNS);
        $in = implode(',', array_fill(0, count($tables), '?'));
        $have = [];
        try {
            foreach (Db::all($db, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)", str_repeat('s', count($tables)), $tables) as $r) {
                $have[$r['TABLE_NAME'] . '.' . $r['COLUMN_NAME']] = true;
            }
        } catch (\mysqli_sql_exception) {
            return self::$probe[$name] = false;
        }
        foreach (SqlAttemptStats::REQUIRED_COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                if (!isset($have[$table . '.' . $col])) {
                    return self::$probe[$name] = false;
                }
            }
        }
        return self::$probe[$name] = true;
    }

    /** Test seam: forget the memoised probe. */
    public static function reset(): void
    {
        self::$probe = [];
    }
}
