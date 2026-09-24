<?php

namespace ITFlow\Training\Core;

/**
 * Canonical byte encodings that every training hash is computed over (plan A8, spec §0 "Hashing").
 *
 * The encoding must be byte-stable across PHP versions, across the prepared-statement and
 * text protocols, and across a JSON decode/re-encode round trip, because a hash computed at
 * write time is re-computed years later by LedgerVerifier from whatever the database returns.
 * That is why the rules are strict rather than forgiving:
 *
 *   row(): a flat DB row. Keys sorted (byte order). int -> decimal string, bool -> '1'/'0',
 *          string as-is (valid UTF-8 only), null -> null. Floats and arrays throw: no hashed
 *          column is a float, and a float's text form is not stable.
 *   doc(): a nested document (revision JSON, ledger payloads). Associative arrays are
 *          key-sorted with strcmp (recursively); lists keep their order; int/bool/string/null
 *          are emitted natively. Floats and objects (including an empty stdClass, i.e. `{}`)
 *          throw - an empty map must be expressed as null or omitted, never as `{}`, because
 *          json_decode(..., true) turns `{}` into `[]` and the bytes would no longer round-trip.
 *
 * Both encode with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR.
 */
final class Canonical
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public static function row(array $row): string
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[(string) $k] = self::rowValue((string) $k, $v);
        }
        ksort($out, SORT_STRING);
        if ($out === []) {
            throw new \InvalidArgumentException('Canonical::row: empty row');
        }
        if (array_is_list($out)) {
            // Only possible with keys "0","1",... which PHP turns into ints - not a column row.
            throw new \InvalidArgumentException('Canonical::row: row keys must be column names');
        }
        return json_encode($out, self::FLAGS);
    }

    public static function doc(array $doc): string
    {
        return json_encode(self::normDoc($doc, '$'), self::FLAGS);
    }

    public static function sha256(string $s): string
    {
        return hash('sha256', $s);
    }

    private static function rowValue(string $key, mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            if (!mb_check_encoding($v, 'UTF-8')) {
                throw new \InvalidArgumentException("Canonical::row: invalid UTF-8 in '$key'");
            }
            return $v;
        }
        throw new \InvalidArgumentException("Canonical::row: unsupported " . get_debug_type($v) . " in '$key'");
    }

    private static function normDoc(mixed $v, string $path): mixed
    {
        if ($v === null || is_bool($v) || is_int($v)) {
            return $v;
        }
        if (is_string($v)) {
            if (!mb_check_encoding($v, 'UTF-8')) {
                throw new \InvalidArgumentException("Canonical::doc: invalid UTF-8 at $path");
            }
            return $v;
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                $out = [];
                foreach ($v as $i => $item) {
                    $out[] = self::normDoc($item, $path . '[' . $i . ']');
                }
                return $out;
            }
            $out = [];
            foreach ($v as $k => $item) {
                if (is_int($k)) {
                    // An int-keyed map cannot round-trip: json_decode(..., true) may hand it back
                    // as a list. Training maps are keyed by uids and language codes, which always
                    // start with a letter (spec §0 "UIDs").
                    throw new \InvalidArgumentException("Canonical::doc: numeric map key '$k' at $path");
                }
                $out[$k] = self::normDoc($item, $path . '.' . $k);
            }
            uksort($out, static fn($a, $b) => strcmp((string) $a, (string) $b));
            return $out;
        }
        throw new \InvalidArgumentException('Canonical::doc: unsupported ' . get_debug_type($v) . " at $path");
    }
}
