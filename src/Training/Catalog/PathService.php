<?php

namespace ITFlow\Training\Catalog;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Authoring\CourseService;
use ITFlow\Training\Authoring\I18nService;
use ITFlow\Training\Authoring\MediaRefs;
use ITFlow\Training\Authoring\Patch;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Uid;
use ITFlow\Training\Media\MediaStore;

/**
 * Learning paths (plan A15): an ordered set of courses, e.g. "New-Hire Safety Orientation",
 * optionally sequential and optionally earning an achievement (awarded from Phase 3).
 *
 * Paths are live catalog configuration - never revision content, so nothing here touches a
 * course. tpath_version guards the editor's explicit Save (409 conflict with data.current).
 * The base columns hold the first configured language; other languages are training_i18n.
 * Level 1 readers only ever see published, non-archived courses inside a path.
 */
final class PathService
{
    public const MAX_COURSES = 50;
    private const COLS = 'tpath_id, tpath_uid, tpath_name, tpath_description, tpath_color, tpath_cover_media_id, tpath_sequential,
        tpath_achievement_id, tpath_version, tpath_created_at, tpath_updated_at, tpath_archived_at';

    public function __construct(private readonly Ctx $c)
    {
    }

    /** Path cards. Archived paths are included only for authors who ask for them. */
    public function list(bool $includeArchived = false): array
    {
        $where = ($includeArchived && $this->c->level >= 2) ? '' : ' WHERE tpath_archived_at IS NULL';
        $rows = Db::all($this->c->db, 'SELECT ' . self::COLS . " FROM training_paths$where ORDER BY tpath_archived_at IS NOT NULL, tpath_name, tpath_id");
        return $this->shapeMany($rows);
    }

    public function get(int $id): array
    {
        $row = $this->row($id);
        if ($row['tpath_archived_at'] !== null && $this->c->level < 2) {
            throw ApiException::notFound('That learning path no longer exists.');
        }
        return $this->shapeMany([$row])[0];
    }

    /**
     * Creates ($id null) or updates a path. $data keys (all optional on update): name,
     * description, color, cover_media_id, sequential, achievement_id,
     * courses: [{course_id, required}] in order (replaces the list), i18n: {lang: {name, description}}.
     */
    public function save(?int $id, ?int $version, array $data): array
    {
        $db = $this->c->db;
        $allowed = ['name', 'description', 'color', 'cover_media_id', 'sequential', 'achievement_id', 'courses', 'i18n'];
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $allowed, true)) {
                throw ApiException::validation([(string) $k => 'This field cannot be changed here.']);
            }
        }
        $cols = [];
        if ($id === null || array_key_exists('name', $data)) {
            $cols['tpath_name'] = Patch::text($data, 'name', 200, true);
        }
        if (array_key_exists('description', $data)) {
            $cols['tpath_description'] = Patch::text($data, 'description', 1000);
        }
        if (array_key_exists('color', $data)) {
            $cols['tpath_color'] = Patch::color($data, 'color');
        }
        if (array_key_exists('cover_media_id', $data)) {
            $cover = Patch::id($data, 'cover_media_id');
            if ($cover !== null) {
                MediaRefs::require($db, $cover, 'cover', 'cover_media_id');
            }
            $cols['tpath_cover_media_id'] = $cover;
        }
        if (array_key_exists('sequential', $data)) {
            $cols['tpath_sequential'] = Patch::bool($data, 'sequential') ? 1 : 0;
        }
        if (array_key_exists('achievement_id', $data)) {
            $aid = Patch::id($data, 'achievement_id');
            if ($aid !== null && Db::one($db, 'SELECT achievement_id FROM training_achievements WHERE achievement_id = ? AND achievement_archived_at IS NULL', 'i', [$aid]) === null) {
                throw ApiException::validation(['achievement_id' => 'Choose an achievement from the list.']);
            }
            $cols['tpath_achievement_id'] = $aid;
        }
        $courses = array_key_exists('courses', $data) ? $this->validateCourses($data['courses']) : null;
        $i18n = array_key_exists('i18n', $data) ? $this->validateI18n($data['i18n']) : null;

        $pathId = Db::tx($db, function () use ($db, $id, $version, $cols, $courses, $i18n): int {
            if ($id === null) {
                $names = array_keys($cols);
                $pathId = Db::insert(
                    $db,
                    'INSERT INTO training_paths (tpath_uid, tpath_created_by' . ($names === [] ? '' : ', ' . implode(', ', $names)) . ')
                     VALUES (?, ?' . str_repeat(', ?', count($names)) . ')',
                    'si' . str_repeat('s', count($names)),
                    array_merge([Uid::new('p'), $this->c->userId], array_values($cols))
                );
                $this->writeCourses($pathId, $courses ?? []);
                $this->writeI18n($pathId, $i18n ?? []);
                return $pathId;
            }

            $current = $this->row($id, true);
            if ($current['tpath_archived_at'] !== null) {
                throw ApiException::validation(['path_id' => 'This learning path is archived. Restore it first.']);
            }
            if ($version === null || (int) $current['tpath_version'] !== $version) {
                throw ApiException::conflict($this->shapeMany([$current])[0], 'This learning path was changed by someone else.');
            }
            $changes = [];
            foreach ($cols as $col => $val) {
                if (!Patch::same($current[$col], $val)) {
                    $changes[$col] = $val;
                }
            }
            $changed = $changes !== [];
            if ($courses !== null) {
                $changed = $this->writeCourses($id, $courses) || $changed;
            }
            if ($i18n !== null) {
                $changed = $this->writeI18n($id, $i18n) || $changed;
            }
            if ($changed) {
                $sets = array_map(static fn($c) => "$c = ?", array_keys($changes));
                $sets[] = 'tpath_version = tpath_version + 1';
                Db::exec($db, 'UPDATE training_paths SET ' . implode(', ', $sets) . ' WHERE tpath_id = ?', str_repeat('s', count($changes)) . 'i', array_merge(array_values($changes), [$id]));
            }
            return $id;
        });
        return $this->get($pathId);
    }

    public function archive(int $id): void
    {
        $this->row($id);
        Db::exec($this->c->db, 'UPDATE training_paths SET tpath_archived_at = NOW() WHERE tpath_id = ? AND tpath_archived_at IS NULL', 'i', [$id]);
    }

    public function restore(int $id): void
    {
        $this->row($id);
        Db::exec($this->c->db, 'UPDATE training_paths SET tpath_archived_at = NULL WHERE tpath_id = ?', 'i', [$id]);
    }

    // ------------------------------------------------------------------------------------------

    private function row(int $id, bool $forUpdate = false): array
    {
        $row = $id > 0 ? Db::one($this->c->db, 'SELECT ' . self::COLS . ' FROM training_paths WHERE tpath_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$id]) : null;
        if ($row === null) {
            throw ApiException::notFound('That learning path no longer exists.');
        }
        return $row;
    }

    /** @return list<array{course_id:int, required:bool}> */
    private function validateCourses(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw ApiException::validation(['courses' => 'Must be a list of courses.']);
        }
        if (count($list) > self::MAX_COURSES) {
            throw ApiException::validation(['courses' => 'A learning path can have at most ' . self::MAX_COURSES . ' courses.']);
        }
        $out = [];
        $seen = [];
        foreach ($list as $item) {
            $cid = is_array($item) ? ($item['course_id'] ?? null) : $item;
            if (is_string($cid) && preg_match('/^[0-9]{1,10}$/', $cid) === 1) {
                $cid = (int) $cid;
            }
            if (!is_int($cid) || $cid < 1) {
                throw ApiException::validation(['courses' => 'Must be a list of courses.']);
            }
            if (isset($seen[$cid])) {
                throw ApiException::validation(['courses' => 'A course appears twice.']);
            }
            $seen[$cid] = true;
            $required = is_array($item) && array_key_exists('required', $item) ? Patch::bool($item, 'required') : true;
            $out[] = ['course_id' => $cid, 'required' => $required];
        }
        if ($out !== []) {
            $ids = array_keys($seen);
            $live = Db::all($this->c->db, 'SELECT course_id FROM training_courses WHERE course_archived_at IS NULL AND course_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids);
            if (count($live) !== count($ids)) {
                throw ApiException::validation(['courses' => 'A course in the list no longer exists or is archived.']);
            }
        }
        return $out;
    }

    /** @return array<string, array{name:?string, description:?string}> */
    private function validateI18n(mixed $map): array
    {
        if (!is_array($map) || ($map !== [] && array_is_list($map))) {
            throw ApiException::validation(['i18n' => 'Must be an object of languages.']);
        }
        $base = $this->c->settings->languages[0];
        $out = [];
        foreach ($map as $lang => $fields) {
            $lang = (string) $lang;
            if ($lang === $base || !in_array($lang, $this->c->settings->languages, true) || !is_array($fields)) {
                throw ApiException::validation(['i18n' => 'Not a translatable language.']);
            }
            $out[$lang] = [];
            foreach ($fields as $f => $_) {
                if (!in_array($f, ['name', 'description'], true)) {
                    throw ApiException::validation(["i18n.$lang.$f" => 'This field cannot be translated.']);
                }
            }
            if (array_key_exists('name', $fields)) {
                $out[$lang]['name'] = Patch::text($fields, 'name', 200);
            }
            if (array_key_exists('description', $fields)) {
                $out[$lang]['description'] = Patch::text($fields, 'description', 1000);
            }
        }
        return $out;
    }

    /** Replaces the path's course list. Returns whether anything changed. */
    private function writeCourses(int $pathId, array $courses): bool
    {
        $db = $this->c->db;
        $current = Db::all($db, 'SELECT tpcourse_course_id, tpcourse_sort, tpcourse_required FROM training_path_courses WHERE tpcourse_path_id = ? ORDER BY tpcourse_sort, tpcourse_course_id FOR UPDATE', 'i', [$pathId]);
        $have = array_map(static fn($r) => [(int) $r['tpcourse_course_id'], (int) $r['tpcourse_required'] === 1], $current);
        $want = array_map(static fn($c) => [$c['course_id'], $c['required']], $courses);
        if ($have === $want) {
            return false;
        }
        Db::exec($db, 'DELETE FROM training_path_courses WHERE tpcourse_path_id = ?', 'i', [$pathId]);
        foreach ($courses as $i => $c) {
            Db::exec($db, 'INSERT INTO training_path_courses (tpcourse_path_id, tpcourse_course_id, tpcourse_sort, tpcourse_required) VALUES (?, ?, ?, ?)',
                'iiii', [$pathId, $c['course_id'], $i, $c['required'] ? 1 : 0]);
        }
        return true;
    }

    private function writeI18n(int $pathId, array $i18n): bool
    {
        $svc = new I18nService($this->c);
        $changed = false;
        foreach ($i18n as $lang => $fields) {
            foreach ($fields as $field => $value) {
                $changed = $svc->set('path', $pathId, $lang, $field, $value) || $changed;
            }
        }
        return $changed;
    }

    /** Cards/editor shape for several path rows, with their visible courses. */
    private function shapeMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $db = $this->c->db;
        $ids = array_map(static fn($r) => (int) $r['tpath_id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $links = Db::all($db, "SELECT tpcourse_path_id, tpcourse_course_id, tpcourse_sort, tpcourse_required FROM training_path_courses
            WHERE tpcourse_path_id IN ($in) ORDER BY tpcourse_path_id, tpcourse_sort, tpcourse_course_id", str_repeat('i', count($ids)), $ids);

        // Course facts through the level-aware course list (level 1: published text only).
        $courses = [];
        foreach ((new CourseService($this->c))->list(['status' => 'all'])['courses'] as $cs) {
            $courses[$cs['id']] = $cs;
        }
        $achievementIds = array_values(array_filter(array_map(static fn($r) => $r['tpath_achievement_id'] === null ? null : (int) $r['tpath_achievement_id'], $rows)));
        $achievements = [];
        if ($achievementIds !== []) {
            foreach (Db::all($db, 'SELECT achievement_id, achievement_name, achievement_icon, achievement_color, achievement_archived_at FROM training_achievements
                    WHERE achievement_id IN (' . implode(',', array_fill(0, count($achievementIds), '?')) . ')', str_repeat('i', count($achievementIds)), $achievementIds) as $a) {
                $achievements[(int) $a['achievement_id']] = [
                    'id' => (int) $a['achievement_id'], 'name' => (string) $a['achievement_name'], 'icon' => (string) $a['achievement_icon'],
                    'color' => (string) $a['achievement_color'], 'archived' => $a['achievement_archived_at'] !== null,
                ];
            }
        }
        $i18n = (new I18nService($this->c))->forEntities('path', $ids);

        $byPath = [];
        foreach ($links as $l) {
            $cid = (int) $l['tpcourse_course_id'];
            $cs = $courses[$cid] ?? null;
            if ($cs === null || ($this->c->level < 2 && $cs['status'] !== 'published')) {
                continue;   // not visible at this level (draft / archived)
            }
            $byPath[(int) $l['tpcourse_path_id']][] = [
                'course_id' => $cid,
                'name' => $cs['name'],
                'kind' => $cs['kind'],
                'status' => $cs['status'],
                'code' => $cs['code'],
                'required' => (int) $l['tpcourse_required'] === 1,
                'sort' => (int) $l['tpcourse_sort'],
                'est_minutes' => (int) $cs['est_minutes'],
                'lessons_count' => (int) $cs['lessons_count'],
                'category' => $cs['category'],
            ];
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['tpath_id'];
            $list = $byPath[$id] ?? [];
            $out[] = [
                'id' => $id,
                'uid' => (string) $r['tpath_uid'],
                'name' => (string) $r['tpath_name'],
                'description' => $r['tpath_description'],
                'color' => $r['tpath_color'],
                'cover_media_id' => $r['tpath_cover_media_id'] === null ? null : (int) $r['tpath_cover_media_id'],
                'cover_url' => $r['tpath_cover_media_id'] === null ? null : MediaStore::url((int) $r['tpath_cover_media_id']),
                'sequential' => (int) $r['tpath_sequential'] === 1,
                'achievement_id' => $r['tpath_achievement_id'] === null ? null : (int) $r['tpath_achievement_id'],
                'achievement' => $r['tpath_achievement_id'] === null ? null : ($achievements[(int) $r['tpath_achievement_id']] ?? null),
                'version' => (int) $r['tpath_version'],
                'archived' => $r['tpath_archived_at'] !== null,
                'i18n' => ($i18n[$id] ?? []) === [] ? new \stdClass() : $i18n[$id],
                'courses' => $list,
                'course_count' => count($list),
                'est_minutes' => array_sum(array_map(static fn($c) => $c['est_minutes'], $list)),
            ];
        }
        return $out;
    }
}
