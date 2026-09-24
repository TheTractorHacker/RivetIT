<?php

namespace ITFlow\Training\Core;

/**
 * Row hashes for the insert-only training tables (spec §2.6).
 *
 * The hash is always computed over the TEXT-PROTOCOL shape of the row - the strings that a
 * plain mysqli_query() returns - so the writer (which holds PHP ints and bools) and the
 * verifier (which re-reads the row years later) produce identical bytes. normalize() is that
 * shape: int -> decimal string, bool -> '1'/'0', strings as-is, null stays null. A value the
 * writer binds must therefore be exactly what the column hands back: DATETIME(3) values come
 * from Clock::nowUtc() ('Y-m-d H:i:s.v'), never NOW().
 */
final class RowHasher
{
    /** @return array<string, ?string> */
    public static function normalize(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if ($v === null || is_string($v)) {
                $out[$k] = $v;
            } elseif (is_int($v)) {
                $out[$k] = (string) $v;
            } elseif (is_bool($v)) {
                $out[$k] = $v ? '1' : '0';
            } else {
                throw new \InvalidArgumentException("RowHasher: unsupported " . get_debug_type($v) . " in '$k'");
            }
        }
        return $out;
    }

    /**
     * @param string   $table a table registered in HashSpecs
     * @param array    $row   must contain every column of the selected spec (extra keys are ignored)
     * @param int|null $v     spec version; defaults to the row's own `*_hash_v`, else the current version
     */
    public static function hash(string $table, array $row, ?int $v = null): string
    {
        $meta = HashSpecs::meta($table);
        if ($v === null) {
            $v = isset($row[$meta['version']]) && $row[$meta['version']] !== '' ? (int) $row[$meta['version']] : HashSpecs::current($table);
        }
        $subset = [];
        foreach (HashSpecs::columns($table, $v) as $col) {
            if (!array_key_exists($col, $row)) {
                throw new \InvalidArgumentException("RowHasher: $table v$v needs column '$col'");
            }
            $subset[$col] = $row[$col];
        }
        $canonical = Canonical::row(self::normalize($subset));
        if ($meta['chain'] !== null) {
            $prev = (string) $row[$meta['chain']];
            return Canonical::sha256($prev . "\n" . $canonical);
        }
        return Canonical::sha256($canonical);
    }
}
