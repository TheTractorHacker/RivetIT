<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\TrainingSettings;

/**
 * What an upload is FOR decides everything about it (spec §3.3, §4.1): which kinds of file
 * are accepted, the size cap, and which entity the upload must be scoped to.
 *
 *   kinds  the media kinds FileValidator may classify the bytes as ([] = not stored as media:
 *          DOCX and CSV imports are converted, never kept)
 *   max    a TrainingSettings limit name (pdf|video|image|file) or a fixed byte count
 *   scope  course        course_id exists and is not archived; an optional lesson_id belongs to it
 *          bank          bank_id exists and is not archived (nor is its course)
 *          path_or_none  an optional path_id exists and is not archived
 *
 * The scope check is what stops an author uploading "into" a course they are not working on
 * (or an archived one) by editing the form fields; it runs before a single byte is sniffed.
 */
final class UploadPurpose
{
    public const RULES = [
        'lesson_document' => ['kinds' => ['pdf'], 'max' => 'pdf', 'scope' => 'course'],
        'lesson_video'    => ['kinds' => ['video'], 'max' => 'video', 'scope' => 'course'],
        'lesson_image'    => ['kinds' => ['image'], 'max' => 'image', 'scope' => 'course'],
        'lesson_thumb'    => ['kinds' => ['image'], 'max' => 'image', 'scope' => 'course'],
        'course_cover'    => ['kinds' => ['image'], 'max' => 'image', 'scope' => 'course'],
        'article_image'   => ['kinds' => ['image'], 'max' => 'image', 'scope' => 'course'],
        'resource_file'   => ['kinds' => ['file', 'image', 'pdf'], 'max' => 'file', 'scope' => 'course'],
        'docx_import'     => ['kinds' => [], 'max' => 20971520, 'scope' => 'course'],
        'path_cover'      => ['kinds' => ['image'], 'max' => 'image', 'scope' => 'path_or_none'],
        'question_image'  => ['kinds' => ['image'], 'max' => 'image', 'scope' => 'bank'],
        'csv_import'      => ['kinds' => [], 'max' => 2097152, 'scope' => 'bank'],
    ];

    /** Purposes whose result is an `image` media row (re-encoded). */
    public const IMAGE_PURPOSES = ['lesson_image', 'lesson_thumb', 'course_cover', 'article_image', 'path_cover', 'question_image'];

    /** @return array{kinds:list<string>, max:string|int, scope:string} */
    public static function rule(string $purpose): array
    {
        if (!isset(self::RULES[$purpose])) {
            throw MediaException::validation('purpose', 'Unknown upload purpose.');
        }
        return self::RULES[$purpose];
    }

    /** The byte cap for $purpose under the admin's current limits. */
    public static function maxBytes(string $purpose, TrainingSettings $s): int
    {
        $max = self::rule($purpose)['max'];
        if (is_int($max)) {
            return $max;
        }
        return match ($max) {
            'pdf' => $s->pdfMaxBytes,
            'video' => $s->videoMaxBytes,
            'image' => $s->imageMaxBytes,
            'file' => $s->fileMaxBytes,
        };
    }

    /** The message a 413 for $purpose shows (spec §4.4: long videos go to YouTube). */
    public static function tooLargeMessage(string $purpose, int $maxBytes): string
    {
        $mb = max(1, (int) floor($maxBytes / 1048576));
        if ($purpose === 'lesson_video') {
            return "This video is larger than $mb MB. For longer videos, upload to the company YouTube channel as Unlisted and paste the link.";
        }
        return "This file is larger than the $mb MB limit for this kind of upload.";
    }

    /**
     * Validates the purpose's scope ids against the database.
     *
     * @param array{course_id?:?int, lesson_id?:?int, bank_id?:?int, path_id?:?int} $ids
     * @return array{course_id:?int, lesson_id:?int, bank_id:?int, path_id:?int}
     * @throws MediaException 422 validation, 409 archived
     */
    public static function checkScope(Ctx $c, string $purpose, array $ids): array
    {
        $rule = self::rule($purpose);
        $out = ['course_id' => null, 'lesson_id' => null, 'bank_id' => null, 'path_id' => null];
        $id = static fn(string $k): ?int => isset($ids[$k]) && (int) $ids[$k] > 0 ? (int) $ids[$k] : null;

        switch ($rule['scope']) {
            case 'course':
                $courseId = $id('course_id');
                if ($courseId === null) {
                    throw MediaException::validation('course_id', 'Choose the course this file belongs to.');
                }
                $course = Db::one($c->db, 'SELECT course_id, course_archived_at FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
                if ($course === null) {
                    throw MediaException::validation('course_id', 'That course does not exist.');
                }
                if ($course['course_archived_at'] !== null) {
                    throw new MediaException(409, 'archived', 'This course is archived and read-only.');
                }
                $out['course_id'] = $courseId;
                $lessonId = $id('lesson_id');
                if ($lessonId !== null) {
                    $lesson = Db::one($c->db, 'SELECT lesson_id, lesson_archived_at FROM training_lessons WHERE lesson_id = ? AND lesson_course_id = ?',
                        'ii', [$lessonId, $courseId]);
                    if ($lesson === null) {
                        throw MediaException::validation('lesson_id', 'That lesson is not part of this course.');
                    }
                    if ($lesson['lesson_archived_at'] !== null) {
                        throw MediaException::validation('lesson_id', 'This lesson was deleted. Restore it first.');
                    }
                    $out['lesson_id'] = $lessonId;
                }
                break;

            case 'bank':
                $bankId = $id('bank_id');
                if ($bankId === null) {
                    throw MediaException::validation('bank_id', 'Choose the question bank.');
                }
                $bank = Db::one($c->db, 'SELECT b.qbank_id, b.qbank_archived_at, c.course_archived_at
                    FROM training_question_banks b LEFT JOIN training_courses c ON c.course_id = b.qbank_course_id
                    WHERE b.qbank_id = ?', 'i', [$bankId]);
                if ($bank === null || $bank['qbank_archived_at'] !== null) {
                    throw MediaException::validation('bank_id', 'That question bank does not exist.');
                }
                if ($bank['course_archived_at'] !== null) {
                    throw new MediaException(409, 'archived', 'This course is archived and read-only.');
                }
                $out['bank_id'] = $bankId;
                break;

            case 'path_or_none':
                $pathId = $id('path_id');
                if ($pathId !== null) {
                    $path = Db::one($c->db, 'SELECT tpath_id FROM training_paths WHERE tpath_id = ? AND tpath_archived_at IS NULL', 'i', [$pathId]);
                    if ($path === null) {
                        throw MediaException::validation('path_id', 'That learning path does not exist.');
                    }
                    $out['path_id'] = $pathId;
                }
                break;

            default:
                throw new \LogicException("UploadPurpose: unknown scope '{$rule['scope']}'");
        }
        return $out;
    }
}
