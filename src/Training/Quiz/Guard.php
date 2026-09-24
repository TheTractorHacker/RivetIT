<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\TrainingSettings;

/**
 * Existence, ownership and read-only checks shared by the quiz, publish and preview services.
 *
 *   - a missing (or deleted) row is 404 not_found;
 *   - any write to an archived course or its children is 409 archived (spec §3.4 "Archived
 *     courses are read-only"). Shared banks (no course) are never read-only.
 *
 * Every read here is a prepared statement with ids bound as 'i'. Callers that need a row lock
 * pass $forUpdate and must already be inside Db::tx.
 */
final class Guard
{
    public const COURSE_COLS = 'course_id, course_uid, course_kind, course_name, course_default_language, course_languages,
        course_required_languages, course_archived_at, course_current_revision_id, course_draft_updated_at_utc';

    public const LESSON_COLS = 'lesson_id, lesson_uid, lesson_course_id, lesson_section_id, lesson_type, lesson_required, lesson_archived_at';

    public const BANK_COLS = 'qbank_id, qbank_uid, qbank_parent_id, qbank_name, qbank_description, qbank_course_id,
        qbank_quiz_lesson_id, qbank_sort, qbank_archived_at';

    public static function course(\mysqli $db, int $courseId, bool $forUpdate = false): array
    {
        $row = Db::one($db, 'SELECT ' . self::COURSE_COLS . ' FROM training_courses WHERE course_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            'i', [$courseId]);
        if ($row === null) {
            throw ApiException::notFound('That course no longer exists.');
        }
        return $row;
    }

    public static function writableCourse(\mysqli $db, int $courseId): array
    {
        $row = self::course($db, $courseId);
        if ($row['course_archived_at'] !== null) {
            throw ApiException::archived();
        }
        return $row;
    }

    /** A live (not deleted) lesson. */
    public static function lesson(\mysqli $db, int $lessonId): array
    {
        $row = Db::one($db, 'SELECT ' . self::LESSON_COLS . ' FROM training_lessons WHERE lesson_id = ?', 'i', [$lessonId]);
        if ($row === null || $row['lesson_archived_at'] !== null) {
            throw ApiException::notFound('That lesson no longer exists.');
        }
        return $row;
    }

    /** A live lesson whose course is not archived. Returns [lesson, course]. */
    public static function writableLesson(\mysqli $db, int $lessonId): array
    {
        $lesson = self::lesson($db, $lessonId);
        $course = self::writableCourse($db, (int) $lesson['lesson_course_id']);
        return [$lesson, $course];
    }

    /** A bank that exists and is not archived. */
    public static function bank(\mysqli $db, int $bankId, bool $forUpdate = false): array
    {
        $row = Db::one($db, 'SELECT ' . self::BANK_COLS . ' FROM training_question_banks WHERE qbank_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            'i', [$bankId]);
        if ($row === null || $row['qbank_archived_at'] !== null) {
            throw ApiException::notFound('That question bank no longer exists.');
        }
        return $row;
    }

    /** A live bank whose course (if it belongs to one) is not archived. */
    public static function writableBank(\mysqli $db, int $bankId, bool $forUpdate = false): array
    {
        $bank = self::bank($db, $bankId, $forUpdate);
        if ($bank['qbank_course_id'] !== null) {
            self::writableCourse($db, (int) $bank['qbank_course_id']);
        }
        return $bank;
    }

    /**
     * A course's languages: default first, offered (always containing the default), and required
     * (always containing the default, always a subset of offered).
     *
     * @return array{default:string, offered:list<string>, required:list<string>}
     */
    public static function languages(array $course): array
    {
        $default = (string) ($course['course_default_language'] ?? 'en');
        if (preg_match('/^[a-z]{2}$/D', $default) !== 1) {
            $default = 'en';
        }
        $offered = [$default];
        foreach (explode(',', (string) ($course['course_languages'] ?? '')) as $l) {
            $l = strtolower(trim($l));
            if (preg_match('/^[a-z]{2}$/D', $l) === 1 && !in_array($l, $offered, true)) {
                $offered[] = $l;
            }
        }
        $required = [$default];
        foreach (explode(',', (string) ($course['course_required_languages'] ?? '')) as $l) {
            $l = strtolower(trim($l));
            if (in_array($l, $offered, true) && !in_array($l, $required, true)) {
                $required[] = $l;
            }
        }
        return ['default' => $default, 'offered' => $offered, 'required' => $required];
    }

    /**
     * The languages a bank's questions are judged in: its course's offered languages, or for a
     * shared bank the languages Admin › Training offers.
     *
     * @return array{default:string, offered:list<string>}
     */
    public static function bankLanguages(\mysqli $db, array $bank, TrainingSettings $settings): array
    {
        if ($bank['qbank_course_id'] !== null) {
            $course = Db::one($db, 'SELECT ' . self::COURSE_COLS . ' FROM training_courses WHERE course_id = ?', 'i', [(int) $bank['qbank_course_id']]);
            if ($course !== null) {
                $l = self::languages($course);
                return ['default' => $l['default'], 'offered' => $l['offered']];
            }
        }
        $offered = $settings->languages !== [] ? array_values($settings->languages) : ['en'];
        return ['default' => $offered[0], 'offered' => $offered];
    }
}
