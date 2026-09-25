<?php

namespace ITFlow\Training\Core;

/**
 * The only way a Phase 2 hashed (insert-only) row is written (Phase 2 spec §0 #6, §3.1).
 *
 * The caller hands over the row in TEXT-PROTOCOL shape: for every column of the table's
 * current HashSpecs list (including `*_hash_v`) the exact string a plain SELECT returns,
 * or null. That means decimal(5,2) as sprintf('%.2f') ('92.00'), TIME as 'HH:MM:SS', DATE
 * as 'Y-m-d', DATETIME(3) from Clock::nowUtc(), booleans '0'/'1' (PHP ints and bools are
 * accepted and converted the same way RowHasher does; floats and arrays throw).
 *
 * insert() hashes that row with RowHasher, adds the hash column, INSERTs with all-'s' binds,
 * then RE-READS the new row through the text protocol ($db->query) and re-hashes it. If the
 * stored bytes differ from what was hashed (a '92' for a decimal, '07:30' for a TIME, a
 * truncated datetime), it throws \LogicException naming the column, which rolls the caller's
 * transaction back - so a row the verifier could not re-hash is never committed.
 *
 * Rules: must run inside the caller's Db::tx (asserted); keys must be exactly the spec's
 * columns (an unknown or missing key throws - no unhashed column is ever written into a
 * hashed table); the version column must be the table's current version.
 */
final class HashedInsert
{
    /**
     * @param array<string, string|int|bool|null> $row every column of the table's current spec incl. *_hash_v
     * @return array{id:int, sha:string} id = the new AUTO_INCREMENT id (0 for a table without one)
     */
    public static function insert(\mysqli $db, string $table, array $row): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('HashedInsert::insert must run inside Db::tx');
        }
        $meta = HashSpecs::meta($table);
        if ($meta['chain'] !== null) {
            throw new \LogicException("HashedInsert: $table is a chained table; use Ledger::append");
        }
        $v = HashSpecs::current($table);
        $cols = HashSpecs::columns($table, $v);

        $unknown = array_diff(array_map('strval', array_keys($row)), $cols);
        if ($unknown !== []) {
            throw new \InvalidArgumentException("HashedInsert: $table has no hashed column(s) " . implode(', ', $unknown));
        }
        $missing = array_diff($cols, array_keys($row));
        if ($missing !== []) {
            throw new \InvalidArgumentException("HashedInsert: $table row is missing " . implode(', ', $missing));
        }

        $text = RowHasher::normalize($row);
        if (($text[$meta['version']] ?? null) !== (string) $v) {
            throw new \InvalidArgumentException("HashedInsert: {$meta['version']} must be '$v'");
        }

        // Spec column order, not caller order: the INSERT is the same statement every time.
        $ordered = [];
        foreach ($cols as $c) {
            $ordered[$c] = $text[$c];
        }
        $sha = RowHasher::hash($table, $ordered, $v);
        $ordered[$meta['hash']] = $sha;

        Db::ensureUtf8mb4($db);
        $names = array_keys($ordered);
        $id = Db::insert(
            $db,
            'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
            str_repeat('s', count($names)),
            array_values($ordered)
        );

        self::assertReread($db, $table, $meta, $cols, $ordered, $id, $sha);

        return ['id' => $id, 'sha' => $sha];
    }

    /** Re-reads the row just written (text protocol) and proves it re-hashes to $sha. */
    private static function assertReread(\mysqli $db, string $table, array $meta, array $cols, array $written, int $id, string $sha): void
    {
        $where = [];
        if (count($meta['id']) === 1 && !array_key_exists($meta['id'][0], $written)) {
            if ($id < 1) {
                throw new \LogicException("HashedInsert: $table returned no insert id");
            }
            $where[] = $meta['id'][0] . ' = ' . $id;
        } else {
            foreach ($meta['id'] as $k) {
                $val = $written[$k] ?? null;
                if ($val === null || preg_match('/^-?[0-9]{1,18}$/', (string) $val) !== 1) {
                    throw new \LogicException("HashedInsert: $table key column $k must be an integer");
                }
                $where[] = $k . ' = ' . (int) $val;
            }
        }
        $res = $db->query('SELECT ' . implode(', ', array_merge($cols, [$meta['hash']])) . " FROM $table WHERE " . implode(' AND ', $where));
        $back = $res->fetch_assoc();
        $res->free();
        if (!$back) {
            throw new \LogicException("HashedInsert: $table row vanished before re-read");
        }
        if (!hash_equals($sha, RowHasher::hash($table, $back))) {
            foreach ($cols as $c) {
                if ($back[$c] !== $written[$c]) {
                    throw new \LogicException("HashedInsert: $table.$c was stored as " . self::show($back[$c])
                        . ' but hashed as ' . self::show($written[$c]) . ' (bind the text-protocol form)');
                }
            }
            throw new \LogicException("HashedInsert: $table row does not re-hash after insert");
        }
    }

    /** Numbers, dates and times are shown verbatim; free text only by length (no personal data in logs). */
    private static function show(?string $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (strlen($v) <= 32 && preg_match('/^[0-9:. +-]*$/', $v) === 1) {
            return "'$v'";
        }
        return 'text(' . mb_strlen($v, 'UTF-8') . ' chars)';
    }
}
