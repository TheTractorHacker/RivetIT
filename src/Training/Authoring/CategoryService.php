<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Icons;

/**
 * Course categories (training_categories): live catalog configuration, not revision content,
 * so changing a course's category never touches the course. Saving and archiving are level 3
 * (enforced by the route table); the icon must be in Core\Icons::ALLOWED and the color a
 * #RRGGBB hex. A duplicate name (case-insensitive under general_ci) is a 1062 the Router maps
 * to 422 {name}.
 */
final class CategoryService
{
    private const COLS = 'tcat_id, tcat_name, tcat_color, tcat_icon, tcat_sort, tcat_archived_at';

    public function __construct(private readonly Ctx $c)
    {
    }

    /** Live categories in display order, with how many live courses use each. */
    public function list(bool $includeArchived = false): array
    {
        $rows = Db::all($this->c->db, 'SELECT c.tcat_id, c.tcat_name, c.tcat_color, c.tcat_icon, c.tcat_sort, c.tcat_archived_at,
                (SELECT COUNT(*) FROM training_courses tc WHERE tc.course_category_id = c.tcat_id AND tc.course_archived_at IS NULL) AS courses
            FROM training_categories c' . ($includeArchived ? '' : ' WHERE c.tcat_archived_at IS NULL') . '
            ORDER BY c.tcat_sort, c.tcat_name');
        return array_map(static fn($r) => self::shape($r) + ['courses' => (int) $r['courses']], $rows);
    }

    public function get(int $id): array
    {
        $row = Db::one($this->c->db, 'SELECT ' . self::COLS . ' FROM training_categories WHERE tcat_id = ?', 'i', [$id]);
        if ($row === null) {
            throw ApiException::notFound('That category no longer exists.');
        }
        return self::shape($row);
    }

    public function save(?int $id, string $name, string $color, string $icon): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw ApiException::validation(['name' => $name === '' ? 'Required.' : 'Too long (at most 100 characters).']);
        }
        $color = Patch::color(['color' => $color], 'color', false);
        if (!Icons::valid($icon)) {
            throw ApiException::validation(['icon' => 'Choose one of the listed icons.']);
        }
        $db = $this->c->db;
        $newId = Db::tx($db, function () use ($db, $id, $name, $color, $icon): int {
            if ($id === null) {
                $sort = (int) (Db::one($db, 'SELECT COALESCE(MAX(tcat_sort), 0) + 1 AS s FROM training_categories')['s'] ?? 1);
                return Db::insert(
                    $db,
                    'INSERT INTO training_categories (tcat_name, tcat_color, tcat_icon, tcat_sort, tcat_created_by) VALUES (?, ?, ?, ?, ?)',
                    'sssii',
                    [$name, $color, $icon, min($sort, 65535), $this->c->userId]
                );
            }
            $row = Db::one($db, 'SELECT ' . self::COLS . ' FROM training_categories WHERE tcat_id = ? FOR UPDATE', 'i', [$id]);
            if ($row === null) {
                throw ApiException::notFound('That category no longer exists.');
            }
            if ($row['tcat_name'] !== $name || $row['tcat_color'] !== $color || $row['tcat_icon'] !== $icon) {
                Db::exec($db, 'UPDATE training_categories SET tcat_name = ?, tcat_color = ?, tcat_icon = ? WHERE tcat_id = ?', 'sssi', [$name, $color, $icon, $id]);
            }
            return $id;
        });
        return $this->get($newId);
    }

    /** Archives a category. Courses keep their category id; archived categories stop being offered. */
    public function archive(int $id): void
    {
        $n = Db::exec($this->c->db, 'UPDATE training_categories SET tcat_archived_at = NOW() WHERE tcat_id = ? AND tcat_archived_at IS NULL', 'i', [$id]);
        if ($n === 0 && Db::one($this->c->db, 'SELECT tcat_id FROM training_categories WHERE tcat_id = ?', 'i', [$id]) === null) {
            throw ApiException::notFound('That category no longer exists.');
        }
    }

    /** A category id from a request: must exist and be live (422 otherwise). */
    public static function requireLive(\mysqli $db, int $id, string $field = 'category_id'): array
    {
        $row = Db::one($db, 'SELECT ' . self::COLS . ' FROM training_categories WHERE tcat_id = ? AND tcat_archived_at IS NULL', 'i', [$id]);
        if ($row === null) {
            throw ApiException::validation([$field => 'Choose a category from the list.']);
        }
        return $row;
    }

    public static function shape(array $r): array
    {
        return [
            'id' => (int) $r['tcat_id'],
            'name' => (string) $r['tcat_name'],
            'color' => (string) $r['tcat_color'],
            'icon' => (string) $r['tcat_icon'],
            'sort' => (int) $r['tcat_sort'],
            'archived' => $r['tcat_archived_at'] !== null,
        ];
    }
}
