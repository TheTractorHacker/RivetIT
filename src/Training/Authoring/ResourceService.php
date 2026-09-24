<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Uid;

/**
 * Extra downloadable files and links on a lesson (training_lesson_resources): checklists, SDS
 * sheets, forms. Files are training media of kind file, image or pdf (never evidence); links
 * must be https: and are never fetched by the server. A resource can be for every language
 * (lang NULL) or one language.
 *
 * Resources are in the revision JSON, so every change touches the course. They carry no
 * version of their own and never bump the lesson's. Deleting archives the row.
 */
final class ResourceService
{
    private const COLS = 'lres_id, lres_uid, lres_lesson_id, lres_lang, lres_kind, lres_title, lres_media_id, lres_url, lres_sort, lres_archived_at';

    public function __construct(private readonly Ctx $c)
    {
    }

    public function add(int $lessonId, string $kind, string $title, ?int $mediaId, ?string $url, ?string $lang): array
    {
        $db = $this->c->db;
        [, $course] = Guard::writableLesson($db, $lessonId);
        if (!in_array($kind, ['file', 'link'], true)) {
            throw ApiException::validation(['kind' => 'Choose a file or a link.']);
        }
        $title = Patch::text(['title' => $title], 'title', 200, true);
        if ($lang !== null) {
            Guard::courseLang($course, $lang);
        }
        if ($kind === 'file') {
            if ($mediaId === null) {
                throw ApiException::validation(['media_id' => 'Upload the file first.']);
            }
            MediaRefs::require($db, $mediaId, 'resource', 'media_id');
            $url = null;
        } else {
            $url = self::httpsUrl($url);
            $mediaId = null;
        }

        $id = Db::tx($db, function () use ($db, $lessonId, $course, $kind, $title, $mediaId, $url, $lang): int {
            Guard::lesson($db, $lessonId, true);
            $sort = (int) (Db::one($db, 'SELECT COALESCE(MAX(lres_sort), -1) + 1 AS s FROM training_lesson_resources WHERE lres_lesson_id = ? AND lres_archived_at IS NULL', 'i', [$lessonId])['s'] ?? 0);
            $id = Db::insert(
                $db,
                'INSERT INTO training_lesson_resources (lres_uid, lres_lesson_id, lres_lang, lres_kind, lres_title, lres_media_id, lres_url, lres_sort, lres_created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'sisssisii',
                [Uid::new('r'), $lessonId, $lang, $kind, $title, $mediaId, $url, min($sort, 65535), $this->c->userId]
            );
            CourseTouch::touch($db, (int) $course['course_id']);
            return $id;
        });
        return $this->get($id);
    }

    /** @param array $f allowlisted: title, lang, url */
    public function update(int $id, array $f): array
    {
        $db = $this->c->db;
        $row = $this->row($id);
        [, $course] = Guard::writableLesson($db, (int) $row['lres_lesson_id']);
        $set = [];
        if (array_key_exists('title', $f)) {
            $set['lres_title'] = Patch::text($f, 'title', 200, true);
        }
        if (array_key_exists('lang', $f)) {
            $lang = $f['lang'];
            if ($lang !== null && $lang !== '') {
                if (!is_string($lang)) {
                    throw ApiException::validation(['lang' => 'Not a valid choice.']);
                }
                Guard::courseLang($course, $lang);
            }
            $set['lres_lang'] = ($lang === '' ? null : $lang);
        }
        if (array_key_exists('url', $f)) {
            if ($row['lres_kind'] !== 'link') {
                throw ApiException::validation(['url' => 'Only links have an address.']);
            }
            $set['lres_url'] = self::httpsUrl(is_string($f['url']) ? $f['url'] : null);
        }
        $changes = [];
        foreach ($set as $col => $val) {
            if (!Patch::same($row[$col], $val)) {
                $changes[$col] = $val;
            }
        }
        if ($changes !== []) {
            Db::tx($db, function () use ($db, $id, $changes, $course): void {
                $this->row($id, true);
                $cols = array_keys($changes);
                Db::exec(
                    $db,
                    'UPDATE training_lesson_resources SET ' . implode(', ', array_map(static fn($c) => "$c = ?", $cols)) . ' WHERE lres_id = ?',
                    str_repeat('s', count($cols)) . 'i',
                    array_merge(array_values($changes), [$id])
                );
                CourseTouch::touch($db, (int) $course['course_id']);
            });
        }
        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $db = $this->c->db;
        $row = $this->row($id);
        [, $course] = Guard::writableLesson($db, (int) $row['lres_lesson_id']);
        Db::tx($db, function () use ($db, $id, $course): void {
            $n = Db::exec($db, 'UPDATE training_lesson_resources SET lres_archived_at = NOW() WHERE lres_id = ? AND lres_archived_at IS NULL', 'i', [$id]);
            if ($n > 0) {
                CourseTouch::touch($db, (int) $course['course_id']);
            }
        });
    }

    /** $ids must be exactly the lesson's live resources, in the new order. */
    public function reorder(int $lessonId, array $ids): void
    {
        $db = $this->c->db;
        [, $course] = Guard::writableLesson($db, $lessonId);
        Db::tx($db, function () use ($db, $lessonId, $ids, $course): void {
            $rows = Db::all($db, 'SELECT lres_id, lres_sort FROM training_lesson_resources WHERE lres_lesson_id = ? AND lres_archived_at IS NULL ORDER BY lres_id FOR UPDATE', 'i', [$lessonId]);
            $current = [];
            foreach ($rows as $r) {
                $current[(int) $r['lres_id']] = (int) $r['lres_sort'];
            }
            $ids = array_map('intval', $ids);
            if (count($ids) !== count($current) || count(array_unique($ids)) !== count($ids) || array_diff($ids, array_keys($current)) !== []) {
                throw ApiException::validation(['ids' => 'Send every resource of this lesson exactly once.']);
            }
            $changed = false;
            foreach ($ids as $i => $rid) {
                if ($current[$rid] !== $i) {
                    Db::exec($db, 'UPDATE training_lesson_resources SET lres_sort = ? WHERE lres_id = ?', 'ii', [$i, $rid]);
                    $changed = true;
                }
            }
            if ($changed) {
                CourseTouch::touch($db, (int) $course['course_id']);
            }
        });
    }

    public function get(int $id): array
    {
        $row = $this->row($id);
        $media = $row['lres_media_id'] !== null ? (MediaRefs::rows($this->c->db, [(int) $row['lres_media_id']])[(int) $row['lres_media_id']] ?? null) : null;
        return self::shape($row, $media === null ? null : MediaRefs::shape($media));
    }

    /** Live resources of one lesson in display order (the LessonDetail `resources` list). */
    public static function listFor(\mysqli $db, int $lessonId): array
    {
        $rows = Db::all($db, 'SELECT ' . self::COLS . ' FROM training_lesson_resources WHERE lres_lesson_id = ? AND lres_archived_at IS NULL ORDER BY lres_sort, lres_id', 'i', [$lessonId]);
        $media = MediaRefs::rows($db, array_map(static fn($r) => (int) $r['lres_media_id'], array_filter($rows, static fn($r) => $r['lres_media_id'] !== null)));
        return array_map(static fn($r) => self::shape($r, ($r['lres_media_id'] !== null && isset($media[(int) $r['lres_media_id']])) ? MediaRefs::shape($media[(int) $r['lres_media_id']]) : null), $rows);
    }

    /** Copies live resources of one lesson to another (duplicates), with new uids. */
    public static function copyAll(\mysqli $db, int $fromLessonId, int $toLessonId, int $userId): void
    {
        foreach (Db::all($db, 'SELECT ' . self::COLS . ' FROM training_lesson_resources WHERE lres_lesson_id = ? AND lres_archived_at IS NULL ORDER BY lres_sort, lres_id', 'i', [$fromLessonId]) as $r) {
            Db::insert(
                $db,
                'INSERT INTO training_lesson_resources (lres_uid, lres_lesson_id, lres_lang, lres_kind, lres_title, lres_media_id, lres_url, lres_sort, lres_created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'sisssisii',
                [Uid::new('r'), $toLessonId, $r['lres_lang'], $r['lres_kind'], $r['lres_title'], $r['lres_media_id'] === null ? null : (int) $r['lres_media_id'], $r['lres_url'], (int) $r['lres_sort'], $userId]
            );
        }
    }

    private static function shape(array $r, ?array $media): array
    {
        return [
            'id' => (int) $r['lres_id'],
            'uid' => (string) $r['lres_uid'],
            'lesson_id' => (int) $r['lres_lesson_id'],
            'kind' => (string) $r['lres_kind'],
            'title' => (string) $r['lres_title'],
            'lang' => $r['lres_lang'],
            'media' => $media,
            'url' => $r['lres_url'],
            'sort' => (int) $r['lres_sort'],
        ];
    }

    private function row(int $id, bool $forUpdate = false): array
    {
        $row = $id > 0 ? Db::one($this->c->db, 'SELECT ' . self::COLS . ' FROM training_lesson_resources WHERE lres_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$id]) : null;
        if ($row === null || $row['lres_archived_at'] !== null) {
            throw ApiException::notFound('That resource no longer exists.');
        }
        return $row;
    }

    private static function httpsUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            throw ApiException::validation(['url' => 'Enter the link address.']);
        }
        if (!mb_check_encoding($url, 'UTF-8') || strlen($url) > 500) {
            throw ApiException::validation(['url' => 'That link is too long.']);
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\s<>"\x00-\x1f]/', $url) === 1) {
            throw ApiException::validation(['url' => 'Links must start with https://']);
        }
        return $url;
    }
}
