<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Uid;

/**
 * Low-level inserts shared by course creation, templates, lesson creation and duplication.
 * Callers run these inside their own Db::tx and do the validation and CourseTouch.
 */
final class Rows
{
    public static function insertSection(\mysqli $db, int $courseId, string $title, int $sort): int
    {
        return Db::insert(
            $db,
            'INSERT INTO training_course_sections (csection_uid, csection_course_id, csection_title, csection_sort) VALUES (?, ?, ?, ?)',
            'sisi',
            [Uid::new('s'), $courseId, $title, max(0, min($sort, 65535))]
        );
    }

    /**
     * @param array{required?:bool, duration_s?:?int, allow_download?:bool, preview_enabled?:bool, responsible_user_id?:?int,
     *              thumb_media_id?:?int, min_watch_pct?:int, ack_require_signature?:bool, ack_require_pin?:bool} $opts
     */
    public static function insertLesson(\mysqli $db, int $courseId, ?int $sectionId, string $type, int $sort, int $userId, array $opts = []): int
    {
        if (!in_array($type, Guard::LESSON_TYPES, true)) {
            throw new \InvalidArgumentException("Rows: bad lesson type '$type'");
        }
        return Db::insert(
            $db,
            'INSERT INTO training_lessons (lesson_uid, lesson_course_id, lesson_section_id, lesson_sort, lesson_type, lesson_required,
                lesson_duration_s, lesson_allow_download, lesson_preview_enabled, lesson_responsible_user_id, lesson_thumb_media_id,
                lesson_min_watch_pct, lesson_ack_require_signature, lesson_ack_require_pin, lesson_created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'siiisiiiiiiiiii',
            [
                Uid::new('l'), $courseId, $sectionId, max(0, min($sort, 65535)), $type,
                ($opts['required'] ?? true) ? 1 : 0,
                $opts['duration_s'] ?? null,
                ($opts['allow_download'] ?? false) ? 1 : 0,
                ($opts['preview_enabled'] ?? false) ? 1 : 0,
                $opts['responsible_user_id'] ?? null,
                $opts['thumb_media_id'] ?? null,
                $opts['min_watch_pct'] ?? 90,
                ($opts['ack_require_signature'] ?? true) ? 1 : 0,
                ($opts['ack_require_pin'] ?? true) ? 1 : 0,
                $userId,
            ]
        );
    }

    /**
     * Inserts or updates one language variant. $cols keys are lvar_* column names (without
     * lvar_lesson_id / lvar_lang / lvar_updated_by).
     */
    public static function putVariant(\mysqli $db, int $lessonId, string $lang, array $cols, int $userId): void
    {
        $allowed = ['lvar_title', 'lvar_description_html', 'lvar_body_html', 'lvar_word_count', 'lvar_media_id', 'lvar_caption',
            'lvar_video_provider', 'lvar_video_ext_id', 'lvar_video_ext_hash', 'lvar_kb_source_article_id', 'lvar_kb_source_sha256',
            'lvar_kb_import_body_sha256', 'lvar_kb_imported_at_utc', 'lvar_caption_media_id'];
        foreach (array_keys($cols) as $k) {
            if (!in_array($k, $allowed, true)) {
                throw new \InvalidArgumentException("Rows: unknown variant column '$k'");
            }
        }
        $cols['lvar_title'] ??= null;
        $exists = Db::one($db, 'SELECT lvar_lesson_id FROM training_lesson_variants WHERE lvar_lesson_id = ? AND lvar_lang = ? FOR UPDATE', 'is', [$lessonId, $lang]) !== null;
        if ($exists) {
            if ($cols['lvar_title'] === null) {
                unset($cols['lvar_title']);
            }
            $cols['lvar_updated_by'] = $userId;
            $names = array_keys($cols);
            Db::exec(
                $db,
                'UPDATE training_lesson_variants SET ' . implode(', ', array_map(static fn($c) => "$c = ?", $names)) . ' WHERE lvar_lesson_id = ? AND lvar_lang = ?',
                self::types($cols) . 'is',
                array_merge(array_values($cols), [$lessonId, $lang])
            );
            return;
        }
        $cols['lvar_title'] = (string) ($cols['lvar_title'] ?? '');
        $cols['lvar_updated_by'] = $userId;
        $names = array_merge(['lvar_lesson_id', 'lvar_lang'], array_keys($cols));
        Db::exec(
            $db,
            'INSERT INTO training_lesson_variants (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')',
            'is' . self::types($cols),
            array_merge([$lessonId, $lang], array_values($cols))
        );
    }

    /** Next sort value at the end of a section (or of the unsectioned group). */
    public static function nextLessonSort(\mysqli $db, int $courseId, ?int $sectionId): int
    {
        $row = $sectionId === null
            ? Db::one($db, 'SELECT COALESCE(MAX(lesson_sort), -1) + 1 AS s FROM training_lessons WHERE lesson_course_id = ? AND lesson_section_id IS NULL AND lesson_archived_at IS NULL', 'i', [$courseId])
            : Db::one($db, 'SELECT COALESCE(MAX(lesson_sort), -1) + 1 AS s FROM training_lessons WHERE lesson_course_id = ? AND lesson_section_id = ? AND lesson_archived_at IS NULL', 'ii', [$courseId, $sectionId]);
        return (int) ($row['s'] ?? 0);
    }

    public static function nextSectionSort(\mysqli $db, int $courseId): int
    {
        return (int) (Db::one($db, 'SELECT COALESCE(MAX(csection_sort), -1) + 1 AS s FROM training_course_sections WHERE csection_course_id = ?', 'i', [$courseId])['s'] ?? 0);
    }

    private static function types(array $cols): string
    {
        $t = '';
        foreach ($cols as $v) {
            $t .= is_int($v) ? 'i' : 's';
        }
        return $t;
    }
}
