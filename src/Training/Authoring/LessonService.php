<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Media\ArticleSanitizer;
use ITFlow\Training\Media\Captions;
use ITFlow\Training\Media\MediaException;
use ITFlow\Training\Media\VideoCheckService;
use ITFlow\Training\Quiz\QuizCloner;
use ITFlow\Training\Quiz\QuizService;

/**
 * Lessons ("content" in the builder): create, the autosave patch, type change, duplicate,
 * delete/restore (archive), per-language copy and KB article import.
 *
 * Write rules (spec §3.4):
 *   - lesson -> course and section -> course ownership; archived courses are read-only (409);
 *   - lesson_version guards author-typed content only. It is not bumped by section moves, tags,
 *     the responsible user or video verification, and a patch of only those needs no version;
 *   - no-op patches change nothing (no bump, no touch);
 *   - CourseTouch only for revision content (not tags, not responsible);
 *   - video links arrive as a check token: VideoCheckService::persist() runs BEFORE the
 *     transaction (it may ingest a thumbnail, which must happen at depth 0);
 *   - document-kind courses keep their shape: one content lesson (document or article) plus a
 *     fixed acknowledgment lesson (422 document_shape otherwise).
 */
final class LessonService
{
    /**
     * lesson_update allowlist. Per language (variant): title, description_html, body_html, media_id,
     * caption, caption_media_id (a video's closed-caption file, DB 2.6.98), video_check_token,
     * video_provider, kb_source (null = unlink the KB article).
     * Language-neutral (lesson row): everything else.
     */
    public const UPDATE_FIELDS = ['title', 'description_html', 'body_html', 'media_id', 'caption', 'caption_media_id', 'video_check_token', 'video_provider',
        'required', 'duration_s', 'allow_download', 'preview_enabled', 'responsible_user_id', 'thumb_media_id', 'min_watch_pct',
        'ack_require_signature', 'ack_require_pin', 'section_id', 'tags', 'kb_source'];
    /** Fields that are neither version-guarded nor bump the version. */
    private const UNVERSIONED = ['section_id', 'tags', 'responsible_user_id'];

    private const MEDIA_REF = ['document' => 'document', 'video' => 'video', 'image' => 'image'];

    public function __construct(private readonly Ctx $c)
    {
    }

    public function create(int $courseId, ?int $sectionId, string $type, string $lang, ?string $title, ?int $mediaId, ?string $videoCheckToken, ?int $afterLessonId): array
    {
        $db = $this->c->db;
        $course = Guard::writableCourse($db, $courseId);
        Guard::courseLang($course, $lang);
        if (!in_array($type, Guard::LESSON_TYPES, true)) {
            throw ApiException::validation(['type' => 'Choose a content type.']);
        }
        if ($course['course_kind'] === 'document') {
            $this->assertDocumentCanAdd($courseId, $type);
        }
        if ($afterLessonId !== null) {
            $after = Guard::lesson($db, $afterLessonId);
            if ((int) $after['lesson_course_id'] !== $courseId) {
                throw ApiException::validation(['after_lesson_id' => 'That content is not part of this course.']);
            }
            $afterSection = $after['lesson_section_id'] === null ? null : (int) $after['lesson_section_id'];
            if ($sectionId !== null && $sectionId !== $afterSection) {
                throw ApiException::validation(['after_lesson_id' => 'That content is in a different section.']);
            }
            $sectionId = $afterSection;
        }
        if ($sectionId !== null) {
            Guard::sectionOfCourse($db, $sectionId, $courseId);
        }
        $title = Patch::text(['title' => $title], 'title', 200) ?? '';

        $variant = ['lvar_title' => $title];
        if ($mediaId !== null) {
            $ref = self::MEDIA_REF[$type] ?? null;
            if ($ref === null) {
                throw ApiException::validation(['media_id' => 'This content type has no file.']);
            }
            $this->assertDocumentPages(MediaRefs::require($db, $mediaId, $ref, 'media_id'), $ref);
            $variant['lvar_media_id'] = $mediaId;
            if ($type === 'video') {
                $variant['lvar_video_provider'] = 'upload';
            }
        }
        if ($videoCheckToken !== null) {
            if ($type !== 'video') {
                throw ApiException::validation(['video_check_token' => 'Only video content can use a video link.']);
            }
            if ($mediaId !== null) {
                throw ApiException::validation(['video_check_token' => 'Choose either an uploaded video or a link, not both.']);
            }
            $variant += $this->persistVideo($videoCheckToken);
        }

        $id = Db::tx($db, function () use ($db, $courseId, $sectionId, $type, $lang, $variant, $afterLessonId): int {
            [$order, $sorts] = $this->lockGroup($courseId, $sectionId);
            $pos = count($order);
            if ($afterLessonId !== null) {
                $idx = array_search($afterLessonId, $order, true);
                $pos = $idx === false ? count($order) : $idx + 1;
            }
            $id = Rows::insertLesson($db, $courseId, $sectionId, $type, $pos, $this->c->userId);
            array_splice($order, $pos, 0, [$id]);
            $sorts[$id] = $pos;
            $this->renumber($order, $sorts);
            Rows::putVariant($db, $id, $lang, $variant, $this->c->userId);
            if ($type === 'quiz') {
                (new QuizService($this->c))->ensureForLesson($id, 'standalone');
            }
            CourseTouch::touch($db, $courseId);
            return $id;
        });
        return LessonView::detail($db, $id);
    }

    public function get(int $lessonId): array
    {
        Guard::lesson($this->c->db, $lessonId);
        return LessonView::detail($this->c->db, $lessonId);
    }

    /** The autosave patch. Returns LessonDetail plus `warnings` from HTML purification. */
    public function update(int $lessonId, int $version, array $fields, string $lang): array
    {
        $db = $this->c->db;
        [$lesson, $course] = Guard::writableLesson($db, $lessonId);
        $courseId = (int) $course['course_id'];
        Guard::courseLang($course, $lang);
        $type = (string) $lesson['lesson_type'];
        $warnings = [];

        // ---- validate and normalise (no locks, no network yet) ----
        $v = [];      // variant column => value
        $l = [];      // lesson column => value
        $tags = null;
        if (array_key_exists('title', $fields)) {
            $v['lvar_title'] = Patch::text($fields, 'title', 200) ?? '';
        }
        if (array_key_exists('description_html', $fields)) {
            $p = MediaRefs::purify($db, Patch::html($fields, 'description_html'));
            $v['lvar_description_html'] = $p['html'];
            $warnings = array_merge($warnings, $p['warnings']);
        }
        if (array_key_exists('body_html', $fields)) {
            if (!in_array($type, ['article', 'acknowledgment'], true)) {
                throw ApiException::validation(['body_html' => 'This content type has no text body.']);
            }
            $p = MediaRefs::purify($db, Patch::html($fields, 'body_html'));
            $v['lvar_body_html'] = $p['html'];
            $v['lvar_word_count'] = $p['html'] === null ? 0 : ArticleSanitizer::wordCount($p['html']);
            $warnings = array_merge($warnings, $p['warnings']);
        }
        if (array_key_exists('caption', $fields)) {
            $v['lvar_caption'] = Patch::text($fields, 'caption', 500);
        }
        if (array_key_exists('caption_media_id', $fields)) {
            // Closed captions for an uploaded video, per language: a 'caption' media row (training_upload.php
            // purpose lesson_caption made it plain WebVTT). null removes them.
            if ($type !== 'video') {
                throw ApiException::validation(['caption_media_id' => 'Only video content has captions.']);
            }
            if (!Captions::schemaReady($db)) {
                throw new ApiException(409, 'update_required', 'Captions need the latest database update. Ask an administrator to run it (Admin › Update).');
            }
            $capId = Patch::id($fields, 'caption_media_id');
            if ($capId !== null) {
                MediaRefs::require($db, $capId, 'caption', 'caption_media_id');
            }
            $v['lvar_caption_media_id'] = $capId;
        }
        if (array_key_exists('kb_source', $fields)) {
            if ($fields['kb_source'] !== null) {
                throw ApiException::validation(['kb_source' => 'Use Import from KB to link an article.']);
            }
            $v += ['lvar_kb_source_article_id' => null, 'lvar_kb_source_sha256' => null, 'lvar_kb_import_body_sha256' => null, 'lvar_kb_imported_at_utc' => null];
        }
        $hasMedia = array_key_exists('media_id', $fields);
        $hasToken = array_key_exists('video_check_token', $fields) && $fields['video_check_token'] !== null && $fields['video_check_token'] !== '';
        $hasProvider = array_key_exists('video_provider', $fields);
        if ($hasMedia) {
            $ref = self::MEDIA_REF[$type] ?? null;
            if ($ref === null) {
                throw ApiException::validation(['media_id' => 'This content type has no file.']);
            }
            $mediaId = Patch::id($fields, 'media_id');
            if ($mediaId !== null) {
                $this->assertDocumentPages(MediaRefs::require($db, $mediaId, $ref, 'media_id'), $ref);
            }
            $v['lvar_media_id'] = $mediaId;
            if ($type === 'video' && $mediaId !== null) {
                $v += ['lvar_video_provider' => 'upload', 'lvar_video_ext_id' => null, 'lvar_video_ext_hash' => null];
            }
        }
        if (($hasToken || $hasProvider) && $type !== 'video') {
            throw ApiException::validation([$hasToken ? 'video_check_token' : 'video_provider' => 'Only video content has a video source.']);
        }
        if ($hasToken && $hasMedia && $v['lvar_media_id'] !== null) {
            throw ApiException::validation(['video_check_token' => 'Choose either an uploaded video or a link, not both.']);
        }
        if ($hasProvider && !$hasToken) {
            $provider = $fields['video_provider'];
            if ($provider === null || $provider === '') {
                $v = array_merge($v, ['lvar_video_provider' => null, 'lvar_video_ext_id' => null, 'lvar_video_ext_hash' => null, 'lvar_media_id' => null]);
            } elseif ($provider === 'upload') {
                $v = array_merge($v, ['lvar_video_provider' => 'upload', 'lvar_video_ext_id' => null, 'lvar_video_ext_hash' => null]);
            } elseif (in_array($provider, ['youtube', 'vimeo'], true)) {
                throw ApiException::validation(['video_provider' => 'Paste the link and check it first.']);
            } else {
                throw ApiException::validation(['video_provider' => 'Not a valid choice.']);
            }
        }

        foreach (['required', 'allow_download', 'preview_enabled', 'ack_require_signature', 'ack_require_pin'] as $b) {
            if (array_key_exists($b, $fields)) {
                $l['lesson_' . $b] = Patch::bool($fields, $b) ? 1 : 0;
            }
        }
        if (array_key_exists('duration_s', $fields)) {
            $l['lesson_duration_s'] = Patch::int($fields, 'duration_s', 1, 86400);
        }
        if (array_key_exists('min_watch_pct', $fields)) {
            $l['lesson_min_watch_pct'] = Patch::int($fields, 'min_watch_pct', 0, 100, false);
        }
        if (array_key_exists('thumb_media_id', $fields)) {
            $thumb = Patch::id($fields, 'thumb_media_id');
            if ($thumb !== null) {
                MediaRefs::require($db, $thumb, 'thumb', 'thumb_media_id');
            }
            $l['lesson_thumb_media_id'] = $thumb;
        }
        if (array_key_exists('responsible_user_id', $fields)) {
            $uid = Patch::id($fields, 'responsible_user_id');
            if ($uid !== null) {
                UserDirectory::requireActive($db, $uid);
            }
            $l['lesson_responsible_user_id'] = $uid;
        }
        if (array_key_exists('section_id', $fields)) {
            $sid = Patch::id($fields, 'section_id');
            if ($sid !== null) {
                Guard::sectionOfCourse($db, $sid, $courseId);
            }
            $l['lesson_section_id'] = $sid;
        }
        if (array_key_exists('tags', $fields)) {
            $tags = Patch::tagNames($fields['tags']);
        }

        // ---- network-free, depth-0 video persist (may ingest the thumbnail) ----
        if ($hasToken) {
            $token = $fields['video_check_token'];
            if (!is_string($token)) {
                throw ApiException::validation(['video_check_token' => 'Check the link again.']);
            }
            $v = array_merge($v, $this->persistVideo($token), ['lvar_media_id' => null]);
        }

        $guarded = array_diff(array_keys($fields), self::UNVERSIONED) !== [];

        Db::tx($db, function () use ($db, $lessonId, $courseId, $version, $lang, $v, $l, $tags, $guarded): void {
            $current = Guard::lesson($db, $lessonId, true);
            if ($guarded && (int) $current['lesson_version'] !== $version) {
                throw ApiException::conflict(LessonView::detail($db, $lessonId), 'This content was changed in another tab.');
            }
            $variant = Db::one($db, 'SELECT ' . LessonFacts::variantCols($db) . ', lvar_description_html, lvar_body_html FROM training_lesson_variants WHERE lvar_lesson_id = ? AND lvar_lang = ? FOR UPDATE', 'is', [$lessonId, $lang]);

            $vChanges = [];
            foreach ($v as $col => $val) {
                $stored = $variant[$col] ?? ($col === 'lvar_title' ? '' : ($col === 'lvar_word_count' ? 0 : null));
                if (!Patch::same($stored, $val)) {
                    $vChanges[$col] = $val;
                }
            }
            $lChanges = [];
            foreach ($l as $col => $val) {
                if (!Patch::same($current[$col], $val)) {
                    $lChanges[$col] = $val;
                }
            }
            if (isset($lChanges['lesson_section_id'])) {
                $lChanges['lesson_sort'] = min(65535, Rows::nextLessonSort($db, $courseId, $lChanges['lesson_section_id']));
            } elseif (array_key_exists('lesson_section_id', $lChanges)) {
                $lChanges['lesson_sort'] = min(65535, Rows::nextLessonSort($db, $courseId, null));
            }

            $tagsChanged = false;
            if ($tags !== null) {
                $have = array_map(static fn($t) => mb_strtolower($t['name'], 'UTF-8'), TagService::forEntities($db, 'lesson', [$lessonId])[$lessonId] ?? []);
                $want = array_map(static fn($t) => mb_strtolower($t, 'UTF-8'), $tags);
                sort($have);
                sort($want);
                if ($have !== $want) {
                    (new TagService($this->c))->setFor('lesson', $lessonId, $tags);
                    $tagsChanged = true;
                }
            }

            if ($vChanges === [] && $lChanges === [] && !$tagsChanged) {
                return;   // no-op patch: no version bump, no touch
            }
            if ($vChanges !== []) {
                Rows::putVariant($db, $lessonId, $lang, $vChanges, $this->c->userId);
            }
            $bump = $vChanges !== [] || array_diff(array_keys($lChanges), ['lesson_section_id', 'lesson_sort', 'lesson_responsible_user_id']) !== [];
            if ($lChanges !== [] || $bump) {
                $sets = array_map(static fn($c) => "$c = ?", array_keys($lChanges));
                if ($bump) {
                    $sets[] = 'lesson_version = lesson_version + 1';
                }
                Db::exec(
                    $db,
                    'UPDATE training_lessons SET ' . implode(', ', $sets) . ' WHERE lesson_id = ?',
                    str_repeat('s', count($lChanges)) . 'i',
                    array_merge(array_values($lChanges), [$lessonId])
                );
            }
            $touch = $vChanges !== [] || array_diff(array_keys($lChanges), ['lesson_responsible_user_id']) !== [];
            if ($touch) {
                CourseTouch::touch($db, $courseId);
            }
        });

        return LessonView::detail($db, $lessonId) + ['warnings' => $warnings];
    }

    public function setType(int $lessonId, int $version, string $type): array
    {
        $db = $this->c->db;
        [$lesson, $course] = Guard::writableLesson($db, $lessonId);
        if (!in_array($type, Guard::LESSON_TYPES, true)) {
            throw ApiException::validation(['type' => 'Choose a content type.']);
        }
        $old = (string) $lesson['lesson_type'];
        if ($course['course_kind'] === 'document') {
            if ($old === 'acknowledgment') {
                throw new ApiException(422, 'document_shape', "The acknowledgment of a required document can't change type.");
            }
            if (!in_array($type, ['document', 'article'], true)) {
                throw new ApiException(422, 'document_shape', 'A required document is either a PDF or an article.');
            }
        }

        Db::tx($db, function () use ($db, $lessonId, $version, $type, $old, $course): void {
            $current = Guard::lesson($db, $lessonId, true);
            if ((int) $current['lesson_version'] !== $version) {
                throw ApiException::conflict(LessonView::detail($db, $lessonId), 'This content was changed in another tab.');
            }
            if ($type === $old) {
                return;
            }
            $keepBody = in_array($type, ['article', 'acknowledgment'], true);
            $sets = 'lvar_media_id = NULL, lvar_caption = NULL, lvar_video_provider = NULL, lvar_video_ext_id = NULL, lvar_video_ext_hash = NULL'
                . (Captions::schemaReady($db) ? ', lvar_caption_media_id = NULL' : '');
            if (!$keepBody) {
                $sets .= ', lvar_body_html = NULL, lvar_word_count = 0';
            }
            if ($type !== 'article') {
                $sets .= ', lvar_kb_source_article_id = NULL, lvar_kb_source_sha256 = NULL, lvar_kb_import_body_sha256 = NULL, lvar_kb_imported_at_utc = NULL';
            }
            Db::exec($db, "UPDATE training_lesson_variants SET $sets, lvar_updated_by = ? WHERE lvar_lesson_id = ?", 'ii', [$this->c->userId, $lessonId]);
            Db::exec($db, 'UPDATE training_lessons SET lesson_type = ?, lesson_version = lesson_version + 1 WHERE lesson_id = ?', 'si', [$type, $lessonId]);

            $quizzes = new QuizService($this->c);
            $hasQuiz = Db::one($db, 'SELECT quiz_id FROM training_quizzes WHERE quiz_lesson_id = ?', 'i', [$lessonId]) !== null;
            if ($hasQuiz && ($old === 'quiz' || $type === 'quiz' || $type === 'acknowledgment')) {
                // A quiz lesson's questions stay in its (archived) auto bank; re-attaching restores them.
                $quizzes->detachFromLesson($lessonId);
            }
            if ($type === 'quiz') {
                $quizzes->ensureForLesson($lessonId, 'standalone');
            }
            CourseTouch::touch($db, (int) $course['course_id']);
        });
        return LessonView::detail($db, $lessonId);
    }

    public function duplicate(int $lessonId): array
    {
        $db = $this->c->db;
        [$lesson, $course] = Guard::writableLesson($db, $lessonId);
        if ($course['course_kind'] === 'document') {
            throw new ApiException(422, 'document_shape', 'A required document has one document and one acknowledgment. Duplicate the whole document instead.');
        }
        $courseId = (int) $course['course_id'];
        $sectionId = $lesson['lesson_section_id'] === null ? null : (int) $lesson['lesson_section_id'];

        $newId = Db::tx($db, function () use ($db, $lessonId, $courseId, $sectionId): int {
            $src = Guard::lesson($db, $lessonId, true);
            [$order, $sorts] = $this->lockGroup($courseId, $sectionId);
            $idx = array_search($lessonId, $order, true);
            $pos = $idx === false ? count($order) : $idx + 1;
            $newId = Rows::insertLesson($db, $courseId, $sectionId, (string) $src['lesson_type'], $pos, $this->c->userId, [
                'required' => (int) $src['lesson_required'] === 1,
                'duration_s' => $src['lesson_duration_s'] === null ? null : (int) $src['lesson_duration_s'],
                'allow_download' => (int) $src['lesson_allow_download'] === 1,
                'preview_enabled' => (int) $src['lesson_preview_enabled'] === 1,
                'responsible_user_id' => $src['lesson_responsible_user_id'] === null ? null : (int) $src['lesson_responsible_user_id'],
                'thumb_media_id' => $src['lesson_thumb_media_id'] === null ? null : (int) $src['lesson_thumb_media_id'],
                'min_watch_pct' => (int) $src['lesson_min_watch_pct'],
                'ack_require_signature' => (int) $src['lesson_ack_require_signature'] === 1,
                'ack_require_pin' => (int) $src['lesson_ack_require_pin'] === 1,
            ]);
            array_splice($order, $pos, 0, [$newId]);
            $sorts[$newId] = $pos;
            $this->renumber($order, $sorts);
            self::copyVariants($db, $lessonId, $newId, $this->c->userId, ' (copy)');
            ResourceService::copyAll($db, $lessonId, $newId, $this->c->userId);
            TagService::copyLinks($db, 'lesson', $lessonId, $newId);
            if (Db::one($db, 'SELECT quiz_id FROM training_quizzes WHERE quiz_lesson_id = ?', 'i', [$lessonId]) !== null) {
                QuizCloner::cloneCourse($this->c, $courseId, $courseId, [$lessonId => $newId]);
            }
            CourseTouch::touch($db, $courseId);
            return $newId;
        });
        return LessonView::detail($db, $newId);
    }

    /** Deletes a lesson by archiving it (undo = restore). */
    public function delete(int $lessonId): void
    {
        $db = $this->c->db;
        [$lesson, $course] = Guard::writableLesson($db, $lessonId);
        if ($course['course_kind'] === 'document' && $lesson['lesson_type'] === 'acknowledgment') {
            throw new ApiException(422, 'document_shape', "The acknowledgment can't be removed from a required document.");
        }
        Db::tx($db, function () use ($db, $lessonId, $course): void {
            $n = Db::exec($db, 'UPDATE training_lessons SET lesson_archived_at = NOW() WHERE lesson_id = ? AND lesson_archived_at IS NULL', 'i', [$lessonId]);
            if ($n > 0) {
                CourseTouch::touch($db, (int) $course['course_id']);
            }
        });
    }

    /** Undoes a delete: the lesson comes back at the end of its section (or unsectioned). */
    public function restore(int $lessonId): void
    {
        $db = $this->c->db;
        [$lesson, $course] = Guard::writableLesson($db, $lessonId, false, true);
        $courseId = (int) $course['course_id'];
        if ($lesson['lesson_archived_at'] === null) {
            return;
        }
        if ($course['course_kind'] === 'document') {
            $this->assertDocumentCanAdd($courseId, (string) $lesson['lesson_type']);
        }
        Db::tx($db, function () use ($db, $lessonId, $courseId): void {
            $row = Guard::lesson($db, $lessonId, true, true);
            if ($row['lesson_archived_at'] === null) {
                return;
            }
            $sectionId = $row['lesson_section_id'] === null ? null : (int) $row['lesson_section_id'];
            if ($sectionId !== null && Db::one($db, 'SELECT csection_id FROM training_course_sections WHERE csection_id = ? AND csection_course_id = ?', 'ii', [$sectionId, $courseId]) === null) {
                $sectionId = null;
            }
            $sort = Rows::nextLessonSort($db, $courseId, $sectionId);
            Db::exec($db, 'UPDATE training_lessons SET lesson_archived_at = NULL, lesson_section_id = ?, lesson_sort = ? WHERE lesson_id = ?', 'iii', [$sectionId, min($sort, 65535), $lessonId]);
            CourseTouch::touch($db, $courseId);
        });
    }

    /** "Copy from English": copies one language's content into another. */
    public function copyVariant(int $lessonId, string $fromLang, string $toLang, bool $overwrite): array
    {
        $db = $this->c->db;
        [, $course] = Guard::writableLesson($db, $lessonId);
        Guard::courseLang($course, $fromLang, 'from');
        Guard::courseLang($course, $toLang, 'to');
        if ($fromLang === $toLang) {
            throw ApiException::validation(['to' => 'Choose a different language.']);
        }
        Db::tx($db, function () use ($db, $lessonId, $fromLang, $toLang, $overwrite, $course): void {
            Guard::lesson($db, $lessonId, true);
            $cols = 'lvar_title, lvar_description_html, lvar_body_html, lvar_word_count, lvar_media_id, lvar_caption, lvar_video_provider, lvar_video_ext_id, lvar_video_ext_hash';
            $from = Db::one($db, "SELECT $cols FROM training_lesson_variants WHERE lvar_lesson_id = ? AND lvar_lang = ?", 'is', [$lessonId, $fromLang]);
            if ($from === null) {
                throw ApiException::validation(['from' => 'There is nothing to copy in that language yet.']);
            }
            $to = Db::one($db, "SELECT $cols FROM training_lesson_variants WHERE lvar_lesson_id = ? AND lvar_lang = ? FOR UPDATE", 'is', [$lessonId, $toLang]);
            if ($to !== null && !$overwrite && (trim((string) $to['lvar_title']) !== '' || !MediaRefs::htmlEmpty($to['lvar_body_html'])
                    || !MediaRefs::htmlEmpty($to['lvar_description_html']) || $to['lvar_media_id'] !== null || $to['lvar_video_provider'] !== null)) {
                throw ApiException::validation(['overwrite' => 'That language already has content. Confirm to replace it.']);
            }
            $changes = [];
            foreach ($from as $col => $val) {
                if ($to === null || !Patch::same($to[$col], $val)) {
                    $changes[$col] = $col === 'lvar_word_count' ? (int) $val : ($col === 'lvar_media_id' && $val !== null ? (int) $val : $val);
                }
            }
            if ($changes === []) {
                return;
            }
            $changes['lvar_title'] = (string) ($changes['lvar_title'] ?? ($to['lvar_title'] ?? ''));
            Rows::putVariant($db, $lessonId, $toLang, $changes, $this->c->userId);
            Db::exec($db, 'UPDATE training_lessons SET lesson_version = lesson_version + 1 WHERE lesson_id = ?', 'i', [$lessonId]);
            CourseTouch::touch($db, (int) $course['course_id']);
        });
        return LessonView::detail($db, $lessonId);
    }

    /**
     * Writes a KB snapshot (KbSnapshot::import() result, already ingested and purified at depth
     * 0 by the caller) into an article variant. $import keys: html, title, source_sha256,
     * body_sha256, article_id (or kb_article_id).
     *
     * 409 kb_local_edits when the variant has text the author wrote or changed since the last
     * import, unless $confirmOverwriteEdits. data: {current_html, imported_html, imported_title}.
     */
    public function applyImportedArticle(int $lessonId, string $lang, array $import, bool $confirmOverwriteEdits): array
    {
        $db = $this->c->db;
        [$lesson, $course] = Guard::writableLesson($db, $lessonId);
        Guard::courseLang($course, $lang);
        if ($lesson['lesson_type'] !== 'article') {
            throw ApiException::validation(['lesson_id' => 'Knowledge Base articles can only be imported into Article content.']);
        }
        $articleId = (int) ($import['article_id'] ?? ($import['kb_article_id'] ?? 0));
        $html = (string) ($import['html'] ?? '');
        $sourceSha = (string) ($import['source_sha256'] ?? '');
        $bodySha = (string) ($import['body_sha256'] ?? '');
        if ($articleId <= 0 || preg_match('/^[0-9a-f]{64}$/D', $sourceSha) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $bodySha) !== 1) {
            throw new \InvalidArgumentException('applyImportedArticle: import needs article_id, source_sha256 and body_sha256');
        }
        $title = Text::clip(trim((string) ($import['title'] ?? '')), 200);
        $wordCount = MediaRefs::htmlEmpty($html) ? 0 : ArticleSanitizer::wordCount($html);

        Db::tx($db, function () use ($db, $lessonId, $lang, $html, $title, $articleId, $sourceSha, $bodySha, $wordCount, $confirmOverwriteEdits, $course): void {
            Guard::lesson($db, $lessonId, true);
            $v = Db::one($db, 'SELECT lvar_title, lvar_body_html, lvar_kb_source_article_id, lvar_kb_source_sha256, lvar_kb_import_body_sha256
                FROM training_lesson_variants WHERE lvar_lesson_id = ? AND lvar_lang = ? FOR UPDATE', 'is', [$lessonId, $lang]);
            $body = $v['lvar_body_html'] ?? null;
            $edited = !MediaRefs::htmlEmpty($body)
                && ($v['lvar_kb_import_body_sha256'] === null || hash('sha256', (string) $body) !== $v['lvar_kb_import_body_sha256']);
            if ($edited && !$confirmOverwriteEdits) {
                throw new ApiException(409, 'kb_local_edits', 'This article has edits that are not in the Knowledge Base. Replace your edits?', [], [
                    'current_html' => (string) $body,
                    'imported_html' => $html,
                    'imported_title' => $title,
                ]);
            }
            $cols = [
                'lvar_body_html' => $html === '' ? null : $html,
                'lvar_word_count' => $wordCount,
                'lvar_kb_source_article_id' => $articleId,
                'lvar_kb_source_sha256' => $sourceSha,
                'lvar_kb_import_body_sha256' => $bodySha,
                'lvar_kb_imported_at_utc' => Clock::nowUtc(),
            ];
            if ($v === null || trim((string) $v['lvar_title']) === '') {
                $cols['lvar_title'] = (string) $title;
            }
            $contentChanged = $v === null
                || !Patch::same($v['lvar_body_html'], $cols['lvar_body_html'])
                || !Patch::same($v['lvar_kb_source_article_id'], $articleId)
                || !Patch::same($v['lvar_kb_source_sha256'], $sourceSha)
                || (isset($cols['lvar_title']) && !Patch::same($v['lvar_title'] ?? '', $cols['lvar_title']));
            Rows::putVariant($db, $lessonId, $lang, $cols, $this->c->userId);
            if ($contentChanged) {
                Db::exec($db, 'UPDATE training_lessons SET lesson_version = lesson_version + 1 WHERE lesson_id = ?', 'i', [$lessonId]);
                CourseTouch::touch($db, (int) $course['course_id']);
            }
        });
        return LessonView::detail($db, $lessonId);
    }

    // ------------------------------------------------------------------------------------------

    /** Copies every variant of a lesson (duplicates). $suffix is appended to non-empty titles. */
    public static function copyVariants(\mysqli $db, int $fromId, int $toId, int $userId, string $suffix = ''): void
    {
        // Duplicates keep each language's caption file (DB 2.6.98); "Copy from English" (copyVariant) does not,
        // because captions are in the language they were written in.
        $caps = Captions::schemaReady($db);
        $rows = Db::all($db, 'SELECT lvar_lang, lvar_title, lvar_description_html, lvar_body_html, lvar_word_count, lvar_media_id, lvar_caption,
                lvar_video_provider, lvar_video_ext_id, lvar_video_ext_hash, lvar_kb_source_article_id, lvar_kb_source_sha256,
                lvar_kb_import_body_sha256, lvar_kb_imported_at_utc' . ($caps ? ', lvar_caption_media_id' : '') . '
            FROM training_lesson_variants WHERE lvar_lesson_id = ?', 'i', [$fromId]);
        foreach ($rows as $r) {
            $lang = (string) $r['lvar_lang'];
            unset($r['lvar_lang']);
            $title = (string) $r['lvar_title'];
            if ($suffix !== '' && $title !== '') {
                $title = mb_substr($title, 0, 200 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
            }
            $r['lvar_title'] = $title;
            $r['lvar_word_count'] = (int) $r['lvar_word_count'];
            foreach (['lvar_media_id', 'lvar_kb_source_article_id', 'lvar_caption_media_id'] as $k) {
                if (array_key_exists($k, $r)) {
                    $r[$k] = $r[$k] === null ? null : (int) $r[$k];
                }
            }
            Rows::putVariant($db, $toId, $lang, $r, $userId);
        }
    }

    /**
     * A document's PDF must fit Admin › Training's page limit, whatever it was uploaded as: a
     * resource-file or KB-copied PDF is not page-capped at upload, and every page of a document
     * is rendered (media budget, render time).
     */
    private function assertDocumentPages(array $media, string $ref): void
    {
        if ($ref !== 'document') {
            return;
        }
        $pages = (int) ($media['media_page_count'] ?? 0);
        $max = $this->c->settings->pdfMaxPages;
        if ($max > 0 && $pages > $max) {
            throw ApiException::validation(['media_id' => "This PDF has $pages pages; the limit is $max. Split it into smaller documents."]);
        }
    }

    /** Persists a video link check (depth 0) and returns the variant columns for it. */
    private function persistVideo(string $token): array
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) {
            throw ApiException::validation(['video_check_token' => 'Check the link again.']);
        }
        try {
            $row = (new VideoCheckService($this->c))->persist($token);
        } catch (MediaException $e) {
            // The Router maps only ApiException (expired token 422, video_live 422, …).
            throw $e->toApi();
        }
        $provider = (string) ($row['vcheck_provider'] ?? ($row['provider'] ?? ''));
        $extId = (string) ($row['vcheck_ext_id'] ?? ($row['ext_id'] ?? ''));
        $extHash = $row['vcheck_ext_hash'] ?? ($row['ext_hash'] ?? null);
        $extHash = ($extHash === null || $extHash === '') ? null : (string) $extHash;
        if (!in_array($provider, ['youtube', 'vimeo'], true) || $extId === '') {
            throw ApiException::validation(['video_check_token' => 'Check the link again.']);
        }
        $check = VideoRefs::row($this->c->db, $provider, $extId, $extHash);
        if (($check['vcheck_status'] ?? null) === 'live') {
            throw new ApiException(422, 'video_live', "Live streams and Premieres can't be used.");
        }
        return ['lvar_video_provider' => $provider, 'lvar_video_ext_id' => $extId, 'lvar_video_ext_hash' => $extHash];
    }

    /** Document-kind shape: at most one content lesson (document|article) and one acknowledgment. */
    private function assertDocumentCanAdd(int $courseId, string $type): void
    {
        $rows = Db::all($this->c->db, 'SELECT lesson_type FROM training_lessons WHERE lesson_course_id = ? AND lesson_archived_at IS NULL', 'i', [$courseId]);
        $content = 0;
        $ack = 0;
        foreach ($rows as $r) {
            $r['lesson_type'] === 'acknowledgment' ? $ack++ : $content++;
        }
        if ($type === 'acknowledgment' ? $ack > 0 : ($content > 0 || !in_array($type, ['document', 'article'], true))) {
            throw new ApiException(422, 'document_shape', 'A required document has one document (a PDF or an article) and one acknowledgment. Add a quick check from the document instead.');
        }
    }

    /**
     * Locks the live lessons of one section (or the unsectioned group).
     *
     * @return array{0: list<int>, 1: array<int, int>} [ids in order, id => current sort]
     */
    private function lockGroup(int $courseId, ?int $sectionId): array
    {
        $rows = $sectionId === null
            ? Db::all($this->c->db, 'SELECT lesson_id, lesson_sort FROM training_lessons WHERE lesson_course_id = ? AND lesson_section_id IS NULL AND lesson_archived_at IS NULL ORDER BY lesson_sort, lesson_id FOR UPDATE', 'i', [$courseId])
            : Db::all($this->c->db, 'SELECT lesson_id, lesson_sort FROM training_lessons WHERE lesson_course_id = ? AND lesson_section_id = ? AND lesson_archived_at IS NULL ORDER BY lesson_sort, lesson_id FOR UPDATE', 'ii', [$courseId, $sectionId]);
        $sorts = [];
        foreach ($rows as $r) {
            $sorts[(int) $r['lesson_id']] = (int) $r['lesson_sort'];
        }
        return [array_keys($sorts), $sorts];
    }

    /** Writes sort = position for every lesson of a locked group whose sort differs. */
    private function renumber(array $order, array $sorts): void
    {
        foreach ($order as $i => $id) {
            if (($sorts[$id] ?? -1) !== $i) {
                Db::exec($this->c->db, 'UPDATE training_lessons SET lesson_sort = ? WHERE lesson_id = ?', 'ii', [min($i, 65535), $id]);
            }
        }
    }
}
