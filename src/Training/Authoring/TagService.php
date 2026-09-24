<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Free-form tags for courses and lessons (training_tags + training_tag_links).
 *
 * Tag names are unique under the column's utf8mb4_general_ci collation, so "PPE" and "ppe" are
 * the same tag: ensure() looks a name up with a collation-aware SELECT first and reuses the
 * existing row; if a concurrent request inserts the same name between the SELECT and the
 * INSERT, the 1062 is caught right at that INSERT (inside the caller's transaction; a failed
 * INSERT undoes only itself) and the winner's row is reused. Tag edits never touch the course
 * and never bump a version - tags are live catalog configuration, not revision content.
 */
final class TagService
{
    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * Live tags with how many courses/lessons use them. A level-1 reader sees only tags on
     * published, non-archived courses, counted over those courses (lesson tags live in drafts,
     * so their count is 0) - tag names and counts never reveal draft or archived work.
     */
    public function list(): array
    {
        if ($this->c->level < 2) {
            $rows = Db::all($this->c->db, "SELECT t.ttag_id, t.ttag_name, t.ttag_color, COUNT(DISTINCT c.course_id) AS courses, 0 AS lessons
                FROM training_tags t
                JOIN training_tag_links l ON l.ttlink_tag_id = t.ttag_id AND l.ttlink_entity = 'course'
                JOIN training_courses c ON c.course_id = l.ttlink_entity_id
                    AND c.course_archived_at IS NULL AND c.course_current_revision_id IS NOT NULL
                WHERE t.ttag_archived_at IS NULL
                GROUP BY t.ttag_id, t.ttag_name, t.ttag_color
                ORDER BY t.ttag_name");
        } else {
            $rows = Db::all($this->c->db, "SELECT t.ttag_id, t.ttag_name, t.ttag_color,
                    SUM(l.ttlink_entity = 'course') AS courses, SUM(l.ttlink_entity = 'lesson') AS lessons
                FROM training_tags t LEFT JOIN training_tag_links l ON l.ttlink_tag_id = t.ttag_id
                WHERE t.ttag_archived_at IS NULL
                GROUP BY t.ttag_id, t.ttag_name, t.ttag_color
                ORDER BY t.ttag_name");
        }
        return array_map(static fn($r) => [
            'id' => (int) $r['ttag_id'],
            'name' => (string) $r['ttag_name'],
            'color' => $r['ttag_color'],
            'courses' => (int) ($r['courses'] ?? 0),
            'lessons' => (int) ($r['lessons'] ?? 0),
        ], $rows);
    }

    /**
     * Returns the tag rows for $names, creating the missing ones (and un-archiving archived
     * ones). Must run inside the caller's Db::tx.
     *
     * @param list<string> $names already normalised by Patch::tagNames()
     * @return list<array{id:int, name:string, color:?string}>
     */
    public function ensure(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $row = $this->find($name);
            if ($row === null) {
                try {
                    Db::insert($this->c->db, 'INSERT INTO training_tags (ttag_name, ttag_created_by) VALUES (?, ?)', 'si', [$name, $this->c->userId]);
                } catch (\mysqli_sql_exception $e) {
                    if ((int) $e->getCode() !== 1062) {
                        throw $e;
                    }
                }
                $row = $this->find($name, true);
                if ($row === null) {
                    throw new \RuntimeException('TagService: tag vanished after insert');
                }
            } elseif ($row['ttag_archived_at'] !== null) {
                Db::exec($this->c->db, 'UPDATE training_tags SET ttag_archived_at = NULL WHERE ttag_id = ?', 'i', [(int) $row['ttag_id']]);
            }
            $out[(int) $row['ttag_id']] = ['id' => (int) $row['ttag_id'], 'name' => (string) $row['ttag_name'], 'color' => $row['ttag_color']];
        }
        return array_values($out);
    }

    /**
     * Replaces the tag set of one course or lesson. Returns the new set.
     *
     * @param 'course'|'lesson' $entity
     * @return list<array{id:int, name:string, color:?string}>
     */
    public function setFor(string $entity, int $entityId, array $names): array
    {
        $tags = $this->ensure($names);
        $ids = array_map(static fn($t) => $t['id'], $tags);
        $current = array_map(static fn($r) => (int) $r['ttlink_tag_id'], Db::all(
            $this->c->db,
            'SELECT ttlink_tag_id FROM training_tag_links WHERE ttlink_entity = ? AND ttlink_entity_id = ?',
            'si',
            [$entity, $entityId]
        ));
        foreach (array_diff($current, $ids) as $gone) {
            Db::exec($this->c->db, 'DELETE FROM training_tag_links WHERE ttlink_entity = ? AND ttlink_entity_id = ? AND ttlink_tag_id = ?', 'sii', [$entity, $entityId, $gone]);
        }
        foreach (array_diff($ids, $current) as $new) {
            Db::exec($this->c->db, 'INSERT IGNORE INTO training_tag_links (ttlink_tag_id, ttlink_entity, ttlink_entity_id) VALUES (?, ?, ?)', 'isi', [$new, $entity, $entityId]);
        }
        usort($tags, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $tags;
    }

    /**
     * Tags of many courses or lessons.
     *
     * @return array<int, list<array{id:int, name:string, color:?string}>> entity id => tags
     */
    public static function forEntities(\mysqli $db, string $entity, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Db::all(
                $db,
                'SELECT l.ttlink_entity_id, t.ttag_id, t.ttag_name, t.ttag_color FROM training_tag_links l
                 JOIN training_tags t ON t.ttag_id = l.ttlink_tag_id
                 WHERE l.ttlink_entity = ? AND l.ttlink_entity_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                 ORDER BY t.ttag_name',
                's' . str_repeat('i', count($chunk)),
                array_merge([$entity], $chunk)
            );
            foreach ($rows as $r) {
                $out[(int) $r['ttlink_entity_id']][] = ['id' => (int) $r['ttag_id'], 'name' => (string) $r['ttag_name'], 'color' => $r['ttag_color']];
            }
        }
        return $out;
    }

    /** Copies one entity's tag links to another (duplicates). */
    public static function copyLinks(\mysqli $db, string $entity, int $fromId, int $toId): void
    {
        Db::exec(
            $db,
            'INSERT IGNORE INTO training_tag_links (ttlink_tag_id, ttlink_entity, ttlink_entity_id)
             SELECT ttlink_tag_id, ttlink_entity, ? FROM training_tag_links WHERE ttlink_entity = ? AND ttlink_entity_id = ?',
            'isi',
            [$toId, $entity, $fromId]
        );
    }

    private function find(string $name, bool $locking = false): ?array
    {
        // general_ci comparison: case- and accent-insensitive, exactly like the unique key.
        return Db::one(
            $this->c->db,
            'SELECT ttag_id, ttag_name, ttag_color, ttag_archived_at FROM training_tags WHERE ttag_name = ?' . ($locking ? ' LOCK IN SHARE MODE' : ''),
            's',
            [$name]
        );
    }
}
