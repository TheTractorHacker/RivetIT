<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashSpecs;
use ITFlow\Training\Core\RowHasher;

/**
 * The only writer of the Phase 3 hashed (insert-only) tables (P3 spec §2.5):
 * training_lesson_completions, training_attempts, training_attempt_results, training_signatures,
 * training_achievement_awards.
 *
 * $row is given in TEXT-PROTOCOL shape (strings exactly as a plain SELECT returns them; PHP ints
 * and bools are converted like RowHasher does, floats/arrays throw). It must hold every column of
 * the table's current HashSpecs list; `*_hash_v` may be omitted and is then set to the current
 * version. Columns outside the hash list are allowed only when they are covered some other way
 * (tsig_png_base64, covered by tsig_png_sha256) - never the hash column or the surrogate id.
 *
 * insert(): RowHasher::hash over the spec columns, one INSERT with all-'s' binds, then a
 * text-protocol RE-READ that must re-hash to the same value - a value the column stores
 * differently ('92' for decimal(5,2), a DATETIME(3) without milliseconds) throws \LogicException
 * naming the column, which rolls the caller's transaction back. Asserts Db::depth() > 0.
 */
final class Hashed
{
    /** Hashed-table columns that may be written although they are not in the hash list. */
    private const COVERED_EXTRAS = ['training_signatures' => ['tsig_png_base64']];

    /** @return array{id:int, sha:string} id = AUTO_INCREMENT id, or the natural key for training_attempt_results */
    public static function insert(\mysqli $db, string $table, array $row): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('Hashed::insert must run inside Db::tx');
        }
        $meta = HashSpecs::meta($table);
        if ($meta['chain'] !== null) {
            throw new \LogicException("Hashed: $table is a chained table; use Ledger::append");
        }
        $v = HashSpecs::current($table);
        $cols = HashSpecs::columns($table, $v);
        if (!array_key_exists($meta['version'], $row)) {
            $row[$meta['version']] = (string) $v;
        }
        $text = RowHasher::normalize($row);
        if (($text[$meta['version']] ?? null) !== (string) $v) {
            throw new \InvalidArgumentException("Hashed: {$meta['version']} must be '$v'");
        }
        $missing = array_diff($cols, array_keys($text));
        if ($missing !== []) {
            throw new \InvalidArgumentException("Hashed: $table row is missing " . implode(', ', $missing));
        }
        $extras = array_diff(array_keys($text), $cols);
        $allowed = self::COVERED_EXTRAS[$table] ?? [];
        foreach ($extras as $x) {
            if (!in_array($x, $allowed, true)) {
                throw new \InvalidArgumentException("Hashed: $table has no hashed column $x");
            }
        }

        $ordered = [];
        foreach ($cols as $c) {
            $ordered[$c] = $text[$c];
        }
        $sha = RowHasher::hash($table, $ordered, $v);
        $write = $ordered;
        foreach ($extras as $x) {
            $write[$x] = $text[$x];
        }
        $write[$meta['hash']] = $sha;

        Db::ensureUtf8mb4($db);
        $names = array_keys($write);
        $id = Db::insert(
            $db,
            'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
            str_repeat('s', count($names)),
            array_values($write)
        );

        $idCol = $meta['id'][0];
        if (array_key_exists($idCol, $ordered)) {
            $id = (int) $ordered[$idCol];
        }
        if ($id < 1) {
            throw new \LogicException("Hashed: $table returned no id");
        }
        $res = $db->query('SELECT ' . implode(', ', array_merge($cols, [$meta['hash']])) . " FROM $table WHERE $idCol = " . $id);
        $back = $res->fetch_assoc();
        $res->free();
        if (!$back) {
            throw new \LogicException("Hashed: $table row vanished before re-read");
        }
        if (!hash_equals($sha, RowHasher::hash($table, $back))) {
            foreach ($cols as $c) {
                if ($back[$c] !== $ordered[$c]) {
                    throw new \LogicException("Hashed: $table.$c was stored differently from how it was hashed (bind the text-protocol form)");
                }
            }
            throw new \LogicException("Hashed: $table row does not re-hash after insert");
        }
        return ['id' => $id, 'sha' => $sha];
    }
}
