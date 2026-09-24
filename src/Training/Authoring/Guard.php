<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;

/**
 * Row loaders for the authoring write rules (spec §3.4 "Rules for write services"):
 * every id must exist and belong to its named parent, and every write to an archived course
 * (or anything inside it) is a 409 `archived`.
 *
 * Column lists are explicit (never a star select). `$forUpdate` takes the entity row's X lock; the
 * lock order inside a transaction is entity rows first, the owning course row last
 * (CourseTouch), the ledger head after that.
 */
final class Guard
{
    public const COURSE_COLS = 'course_id, course_uid, course_kind, course_code, course_name, course_summary, course_description_html,
        course_category_id, course_cover_media_id, course_color, course_default_language, course_languages, course_required_languages,
        course_regulation_ref, course_responsible_user_id, course_sequential, course_est_minutes, course_validity_months,
        course_renewal_lead_days, course_requires_signature, course_attestation_text, course_is_qualification, course_needs_online,
        course_needs_session, course_needs_practical, course_external_only, course_component_window_days, course_allow_trainer_attest,
        course_eval_checklist, course_template_key, course_current_revision_id, course_draft_updated_at_utc, course_version,
        course_created_by, course_created_at, course_updated_at, course_archived_at, course_archived_by';

    public const LESSON_COLS = 'lesson_id, lesson_uid, lesson_course_id, lesson_section_id, lesson_sort, lesson_type, lesson_required,
        lesson_duration_s, lesson_allow_download, lesson_preview_enabled, lesson_responsible_user_id, lesson_thumb_media_id,
        lesson_min_watch_pct, lesson_ack_require_signature, lesson_ack_require_pin, lesson_version, lesson_created_by,
        lesson_created_at, lesson_updated_at, lesson_archived_at';

    public const LESSON_TYPES = ['article', 'document', 'video', 'image', 'quiz', 'acknowledgment'];

    /** The course row, or 404. */
    public static function course(\mysqli $db, int $courseId, bool $forUpdate = false): array
    {
        $row = $courseId > 0
            ? Db::one($db, 'SELECT ' . self::COURSE_COLS . ' FROM training_courses WHERE course_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$courseId])
            : null;
        if ($row === null) {
            throw ApiException::notFound('That course no longer exists.');
        }
        return $row;
    }

    /** The course row for a write: 404 when missing, 409 `archived` when archived. */
    public static function writableCourse(\mysqli $db, int $courseId, bool $forUpdate = false): array
    {
        $row = self::course($db, $courseId, $forUpdate);
        if ($row['course_archived_at'] !== null) {
            throw ApiException::archived();
        }
        return $row;
    }

    /**
     * The lesson row, or 404. An archived (deleted) lesson is a 404 carrying
     * data {deleted: true} (the builder offers Restore) unless $allowArchived.
     */
    public static function lesson(\mysqli $db, int $lessonId, bool $forUpdate = false, bool $allowArchived = false): array
    {
        $row = $lessonId > 0 ? Db::one(
            $db,
            'SELECT ' . self::LESSON_COLS . ' FROM training_lessons WHERE lesson_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            'i',
            [$lessonId]
        ) : null;
        if ($row === null) {
            throw ApiException::notFound('That content no longer exists.');
        }
        if (!$allowArchived && $row['lesson_archived_at'] !== null) {
            throw new ApiException(404, 'not_found', 'This content was deleted.', [], ['deleted' => true, 'lesson_id' => (int) $row['lesson_id']]);
        }
        return $row;
    }

    /** [lesson row, course row] for a write: 404 / 409 archived. */
    public static function writableLesson(\mysqli $db, int $lessonId, bool $forUpdate = false, bool $allowArchived = false): array
    {
        $lesson = self::lesson($db, $lessonId, $forUpdate, $allowArchived);
        $course = self::writableCourse($db, (int) $lesson['lesson_course_id']);
        return [$lesson, $course];
    }

    /** The section row, or 404. */
    public static function section(\mysqli $db, int $sectionId, bool $forUpdate = false): array
    {
        $row = $sectionId > 0 ? Db::one(
            $db,
            'SELECT csection_id, csection_uid, csection_course_id, csection_title, csection_sort FROM training_course_sections WHERE csection_id = ?'
                . ($forUpdate ? ' FOR UPDATE' : ''),
            'i',
            [$sectionId]
        ) : null;
        if ($row === null) {
            throw ApiException::notFound('That section no longer exists.');
        }
        return $row;
    }

    /** A section id from a request that must belong to $courseId (422 otherwise). */
    public static function sectionOfCourse(\mysqli $db, int $sectionId, int $courseId, string $field = 'section_id'): array
    {
        $row = Db::one(
            $db,
            'SELECT csection_id, csection_uid, csection_course_id, csection_title, csection_sort FROM training_course_sections WHERE csection_id = ? AND csection_course_id = ?',
            'ii',
            [$sectionId, $courseId]
        );
        if ($row === null) {
            throw ApiException::validation([$field => 'That section is not part of this course.']);
        }
        return $row;
    }

    /** Offered languages of a course row, default first. @return list<string> */
    public static function languages(array $course): array
    {
        return self::langList((string) $course['course_languages'], (string) $course['course_default_language']);
    }

    /** Required languages of a course row (always includes the default). @return list<string> */
    public static function requiredLanguages(array $course): array
    {
        $req = self::langList((string) $course['course_required_languages'], (string) $course['course_default_language']);
        $offered = self::languages($course);
        return array_values(array_filter($req, static fn($l) => in_array($l, $offered, true)));
    }

    /** A lang from a request that must be offered by the course (422 otherwise). */
    public static function courseLang(array $course, string $lang, string $field = 'lang'): string
    {
        if (!in_array($lang, self::languages($course), true)) {
            throw ApiException::validation([$field => 'This course is not offered in that language. Add it under Settings › Languages first.']);
        }
        return $lang;
    }

    /** @return list<string> */
    private static function langList(string $csv, string $default): array
    {
        $out = [$default];
        foreach (explode(',', $csv) as $l) {
            $l = strtolower(trim($l));
            if (preg_match('/^[a-z]{2}$/', $l) === 1 && !in_array($l, $out, true)) {
                $out[] = $l;
            }
        }
        return $out;
    }
}
