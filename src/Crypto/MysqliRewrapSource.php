<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

use RivetCore\Crypto\RewrapItem;
use RivetCore\Crypto\RewrapSource;

/**
 * A {@see RewrapSource} over one column of one table (RivetCore\Crypto\Rewrapper contract): stable order by primary key, an exclusive
 * cursor, reads without side effects, and replace() as an atomic compare-and-set (`UPDATE ... WHERE pk = ? AND BINARY col = ?`).
 *
 * replace() refuses a value that would not fit the column (strict mode may be off, and a silently truncated secret is destroyed), counting
 * it as a conflict so the report shows it; widening the column is DB update 2.6.160.
 */
final class MysqliRewrapSource implements RewrapSource
{
    private ?int $capacity = null;
    private bool $capacityKnown = false;

    public function __construct(
        private \mysqli $db,
        private ColumnSpec $spec,
        private ?string $rowFilter = null,
    ) {
    }

    public function spec(): ColumnSpec
    {
        return $this->spec;
    }

    public function name(): string
    {
        return $this->spec->name();
    }

    /** True when the table and column exist on this install. */
    public static function exists(\mysqli $db, ColumnSpec $spec): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $table = $spec->table;
        $column = $spec->column;
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $found;
    }

    public function next(?string $afterId, int $limit): array
    {
        $t = $this->ident($this->spec->table);
        $pk = $this->ident($this->spec->pk);
        $col = $this->ident($this->spec->column);
        $where = "$col IS NOT NULL AND $col <> ''";
        if ($this->rowFilter !== null) {
            $where .= ' AND ' . $this->rowFilter;
        }
        $limit = max(1, $limit);
        if ($this->spec->pk2 !== null) {
            return $this->nextComposite($afterId, $limit, $t, $pk, $this->ident($this->spec->pk2), $col, $where);
        }
        if ($afterId !== null) {
            $where .= $this->spec->numericPk ? " AND $pk > ?" : " AND BINARY $pk > BINARY ?";
        }
        $sql = "SELECT $pk AS id, $col AS val FROM $t WHERE $where ORDER BY " . ($this->spec->numericPk ? $pk : "BINARY $pk") . " ASC LIMIT $limit";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Rewrap source ' . $this->name() . ' could not prepare its read.');
        }
        if ($afterId !== null) {
            if ($this->spec->numericPk) {
                $n = (int) $afterId;
                $stmt->bind_param('i', $n);
            } else {
                $stmt->bind_param('s', $afterId);
            }
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $id = (string) $row['id'];
            $items[] = new RewrapItem($id, (string) $row['val'], $this->spec->contextFor($id));
        }
        $stmt->close();

        return $items;
    }

    /** @return list<RewrapItem> composite integer key: ids are "<pk>:<pk2>", ordered by the pair, cursor exclusive */
    private function nextComposite(?string $afterId, int $limit, string $t, string $pk, string $pk2, string $col, string $where): array
    {
        $a = 0;
        $b = 0;
        if ($afterId !== null) {
            [$a, $b] = array_map('intval', explode(':', $afterId, 2) + [1 => '0']);
            $where .= " AND ($pk > ? OR ($pk = ? AND $pk2 > ?))";
        }
        $stmt = $this->db->prepare("SELECT $pk AS id1, $pk2 AS id2, $col AS val FROM $t WHERE $where ORDER BY $pk ASC, $pk2 ASC LIMIT $limit");
        if (!$stmt) {
            throw new \RuntimeException('Rewrap source ' . $this->name() . ' could not prepare its read.');
        }
        if ($afterId !== null) {
            $stmt->bind_param('iii', $a, $a, $b);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $id = (string) $row['id1'] . ':' . (string) $row['id2'];
            $items[] = new RewrapItem($id, (string) $row['val'], $this->spec->contextFor($id));
        }
        $stmt->close();

        return $items;
    }

    public function replace(string $id, string $expected, string $replacement): bool
    {
        $cap = $this->capacity();
        if ($cap !== null && strlen($replacement) > $cap) {
            return false;
        }
        if ($this->spec->pk2 !== null) {
            [$a, $b] = array_map('intval', explode(':', $id, 2) + [1 => '0']);
            $t = $this->ident($this->spec->table);
            $col = $this->ident($this->spec->column);
            $stmt = $this->db->prepare("UPDATE $t SET $col = ? WHERE " . $this->ident($this->spec->pk) . ' = ? AND ' . $this->ident($this->spec->pk2) . " = ? AND BINARY $col = BINARY ?");
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param('siis', $replacement, $a, $b, $expected);
            $ok = $stmt->execute();
            $changed = $ok ? $stmt->affected_rows : 0;
            $stmt->close();

            return $changed === 1;
        }
        $t = $this->ident($this->spec->table);
        $pk = $this->ident($this->spec->pk);
        $col = $this->ident($this->spec->column);
        $filter = $this->rowFilter !== null ? ' AND ' . $this->rowFilter : '';
        $stmt = $this->db->prepare("UPDATE $t SET $col = ? WHERE $pk = ? AND BINARY $col = BINARY ?$filter");
        if (!$stmt) {
            return false;
        }
        if ($this->spec->numericPk) {
            $n = (int) $id;
            $stmt->bind_param('sis', $replacement, $n, $expected);
        } else {
            $stmt->bind_param('sss', $replacement, $id, $expected);
        }
        $ok = $stmt->execute();
        $changed = $ok ? $stmt->affected_rows : 0;
        $stmt->close();

        return $changed === 1;
    }

    /** Byte capacity of the column, or null for TEXT and wider (effectively unbounded here). */
    private function capacity(): ?int
    {
        if (!$this->capacityKnown) {
            $this->capacityKnown = true;
            $this->capacity = self::columnCapacity($this->db, $this->spec->table, $this->spec->column);
        }

        return $this->capacity;
    }

    /** Byte capacity of a varchar/char/varbinary/binary column; null for TEXT/BLOB and wider, and for a column that does not exist. */
    public static function columnCapacity(\mysqli $db, string $table, string $column): ?int
    {
        $stmt = $db->prepare('SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && in_array(strtolower((string) $row['DATA_TYPE']), ['varchar', 'char', 'varbinary', 'binary'], true)) {
            return (int) $row['CHARACTER_MAXIMUM_LENGTH'];
        }

        return null;
    }

    private function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Bad identifier.');
        }

        return '`' . $name . '`';
    }
}
