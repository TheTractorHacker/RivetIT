<?php

namespace ITFlow\EndpointAgent;

/**
 * Prepared-statement helpers for the endpoint agent. Every value reaching SQL from a device, a token or a form goes through
 * bound parameters here; nothing in this namespace builds SQL by string concatenation of untrusted input.
 */
final class Db
{
    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::exec($sql, $params);
        $res = $stmt->get_result();
        $rows = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $rows = self::all($sql, $params);
        return $rows[0] ?? null;
    }

    public static function val(string $sql, array $params = [])
    {
        $r = self::one($sql, $params);
        return $r === null ? null : array_values($r)[0];
    }

    /** Runs a write; returns affected rows. */
    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::exec($sql, $params);
        $n = $stmt->affected_rows;
        $stmt->close();
        return max(0, (int) $n);
    }

    /** Runs an INSERT; returns the new AUTO_INCREMENT id. */
    public static function insert(string $sql, array $params = []): int
    {
        global $mysqli;
        $stmt = self::exec($sql, $params);
        $stmt->close();
        return (int) $mysqli->insert_id;
    }

    private static function exec(string $sql, array $params): \mysqli_stmt
    {
        global $mysqli;
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('endpoint agent: prepare failed: ' . $mysqli->error);
        }
        if ($params) {
            $types = '';
            $vals = [];
            foreach ($params as $p) {
                if (is_bool($p)) {
                    $p = $p ? 1 : 0;
                }
                $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
                $vals[] = $p;
            }
            $stmt->bind_param($types, ...$vals);
        }
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new \RuntimeException('endpoint agent: execute failed: ' . $err);
        }
        return $stmt;
    }

    public static function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** DB (UTC) datetime to RFC 3339 ("Z"), or null. */
    public static function iso(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $ts = strtotime($utc . ' UTC');
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
