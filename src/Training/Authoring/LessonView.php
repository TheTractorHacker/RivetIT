<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\KbSnapshot;
use ITFlow\Training\Media\MediaStore;

/**
 * Builds the §6.1 LessonSummary (builder outline rows) and LessonDetail (the Create Content
 * window) shapes from LessonFacts.
 */
final class LessonView
{
    /** LessonSummary for one lesson of already-loaded facts. */
    public static function summary(array $facts, int $lessonId, array $languages, string $defaultLang, bool $withIssues = true): array
    {
        $l = $facts['lessons'][$lessonId];
        $default = LessonFacts::variant($facts, $lessonId, $defaultLang);
        $duration = LessonFacts::duration($facts, $lessonId, $defaultLang);
        $quiz = $facts['quiz'][$lessonId] ?? null;
        [$thumbUrl, $thumbAuto] = self::thumb($facts, $l, $default);

        $langs = [];
        foreach ($languages as $lang) {
            $langs[$lang] = ['complete' => LessonIssues::variantComplete($facts, $l, LessonFacts::variant($facts, $lessonId, $lang))];
        }

        return [
            'id' => (int) $l['lesson_id'],
            'uid' => (string) $l['lesson_uid'],
            'section_id' => $l['lesson_section_id'] === null ? null : (int) $l['lesson_section_id'],
            'sort' => (int) $l['lesson_sort'],
            'type' => (string) $l['lesson_type'],
            'title' => $default === null ? '' : (string) $default['lvar_title'],
            'required' => (int) $l['lesson_required'] === 1,
            'duration_s' => $duration['effective'],
            'duration_auto_s' => $duration['auto'],
            'duration_source' => $duration['source'],
            'thumb_url' => $thumbUrl,
            'thumb_auto' => $thumbAuto,
            'video_provider' => $default['lvar_video_provider'] ?? null,
            'preview_enabled' => (int) $l['lesson_preview_enabled'] === 1,
            'resources_count' => (int) ($facts['resources'][$lessonId] ?? 0),
            'quiz' => $quiz === null ? null : [
                'id' => $quiz['quiz_id'],
                'role' => $quiz['role'],
                'question_count' => $quiz['question_count'],
                'translated' => $quiz['translated'],
            ],
            'languages' => $langs,
            'issues' => $withIssues ? LessonIssues::fromFacts($facts, $lessonId, $languages, $defaultLang) : [],
            'version' => (int) $l['lesson_version'],
            'archived' => $l['lesson_archived_at'] !== null,
            'updated_at' => Clock::toIso($l['lesson_updated_at'] ?? $l['lesson_created_at'], false),
        ];
    }

    /** LessonDetail for one lesson (reads everything it needs). */
    public static function detail(\mysqli $db, int $lessonId): array
    {
        $course = Db::one(
            $db,
            'SELECT c.course_id, c.course_kind, c.course_default_language, c.course_languages
             FROM training_lessons l JOIN training_courses c ON c.course_id = l.lesson_course_id WHERE l.lesson_id = ?',
            'i',
            [$lessonId]
        );
        if ($course === null) {
            throw new \RuntimeException("LessonView: lesson $lessonId has no course");
        }
        $default = (string) $course['course_default_language'];
        $languages = Guard::languages($course);
        $facts = LessonFacts::load($db, [$lessonId], $languages, true);
        $l = $facts['lessons'][$lessonId];
        $summary = self::summary($facts, $lessonId, $languages, $default);

        $responsibleName = null;
        if ($l['lesson_responsible_user_id'] !== null) {
            $responsibleName = UserDirectory::names($db, [(int) $l['lesson_responsible_user_id']])[(int) $l['lesson_responsible_user_id']] ?? null;
        }

        $variants = [];
        foreach ($languages as $lang) {
            $v = LessonFacts::variant($facts, $lessonId, $lang);
            $variants[$lang] = $v === null ? null : self::variant($db, $facts, $l, $v);
        }

        return $summary + [
            'course_id' => (int) $course['course_id'],
            'requires_previous' => (int) $l['lesson_requires_previous'] === 1,
            'allow_download' => (int) $l['lesson_allow_download'] === 1,
            'responsible_user_id' => $l['lesson_responsible_user_id'] === null ? null : (int) $l['lesson_responsible_user_id'],
            'responsible_name' => $responsibleName,
            'thumb_media_id' => $l['lesson_thumb_media_id'] === null ? null : (int) $l['lesson_thumb_media_id'],
            'min_watch_pct' => (int) $l['lesson_min_watch_pct'],
            'ack_require_signature' => (int) $l['lesson_ack_require_signature'] === 1,
            'ack_require_pin' => (int) $l['lesson_ack_require_pin'] === 1,
            'tags' => TagService::forEntities($db, 'lesson', [$lessonId])[$lessonId] ?? [],
            'variants' => $variants,
            'resources' => ResourceService::listFor($db, $lessonId),
        ];
    }

    private static function variant(\mysqli $db, array $facts, array $lesson, array $v): array
    {
        $media = null;
        $pages = null;
        if ($v['lvar_media_id'] !== null && isset($facts['media'][(int) $v['lvar_media_id']])) {
            $m = $facts['media'][(int) $v['lvar_media_id']];
            $media = MediaRefs::shape($m, $facts['pages_ready'][(int) $m['media_id']] ?? null);
            if ($m['media_kind'] === 'pdf') {
                $pages = MediaRefs::pages($db, (int) $m['media_id']);
            }
        }

        $video = null;
        $provider = $v['lvar_video_provider'];
        if ($provider === 'upload') {
            $video = ['provider' => 'upload', 'ext_id' => null, 'hash' => null, 'url' => $media['url'] ?? null, 'check' => null];
        } elseif ($provider === 'youtube' || $provider === 'vimeo') {
            $check = LessonFacts::vcheck($facts, $v);
            $video = [
                'provider' => $provider,
                'ext_id' => $v['lvar_video_ext_id'],
                'hash' => $v['lvar_video_ext_hash'],
                'url' => $v['lvar_video_ext_id'] === null ? null : VideoRefs::canonicalUrl($provider, (string) $v['lvar_video_ext_id'], $v['lvar_video_ext_hash']),
                'check' => $check === null ? null : VideoRefs::shape($check),
            ];
        }

        // The uploaded video's caption file for this language (DB 2.6.98; null before it or when none).
        $captionFile = null;
        $capId = ($v['lvar_caption_media_id'] ?? null) === null ? null : (int) $v['lvar_caption_media_id'];
        if ($capId !== null && isset($facts['media'][$capId]) && $facts['media'][$capId]['media_kind'] === 'caption') {
            $captionFile = MediaRefs::shape($facts['media'][$capId]);
        }

        $kb = null;
        if ($v['lvar_kb_source_article_id'] !== null) {
            $articleId = (int) $v['lvar_kb_source_article_id'];
            $article = Db::one($db, 'SELECT kb_article_title FROM kb_articles WHERE kb_article_id = ?', 'i', [$articleId]);
            $current = KbSnapshot::currentSha($db, $articleId);
            $body = (string) ($v['lvar_body_html'] ?? '');
            $kb = [
                'article_id' => $articleId,
                'title' => $article['kb_article_title'] ?? null,
                'imported_at' => Clock::toIso($v['lvar_kb_imported_at_utc'], true),
                'drift' => $current === null || $current !== $v['lvar_kb_source_sha256'],
                'locally_edited' => $v['lvar_kb_import_body_sha256'] !== null && hash('sha256', $body) !== $v['lvar_kb_import_body_sha256'],
            ];
        }

        return [
            'title' => (string) $v['lvar_title'],
            'description_html' => $v['lvar_description_html'],
            'body_html' => $v['lvar_body_html'],
            'word_count' => (int) $v['lvar_word_count'],
            'media' => $media,
            'pages' => $pages,
            'caption' => $v['lvar_caption'],
            'caption_file' => $captionFile,
            'video' => $video,
            'kb_source' => $kb,
            'updated_at' => Clock::toIso($v['lvar_updated_at'], false),
        ];
    }

    /** @return array{0:?string, 1:bool} [url, auto] */
    private static function thumb(array $facts, array $lesson, ?array $variant): array
    {
        if ($lesson['lesson_thumb_media_id'] !== null) {
            return [MediaStore::url((int) $lesson['lesson_thumb_media_id']), false];
        }
        if ($variant === null) {
            return [null, true];
        }
        $check = LessonFacts::vcheck($facts, $variant);
        if ($check !== null && $check['vcheck_thumb_media_id'] !== null) {
            return [MediaStore::url((int) $check['vcheck_thumb_media_id']), true];
        }
        $mediaId = $variant['lvar_media_id'] !== null ? (int) $variant['lvar_media_id'] : null;
        if ($mediaId !== null && isset($facts['media'][$mediaId])) {
            $kind = $facts['media'][$mediaId]['media_kind'];
            if ($kind === 'image') {
                return [MediaStore::url($mediaId), true];
            }
            if ($kind === 'pdf' && isset($facts['first_pages'][$mediaId])) {
                return [MediaStore::url($facts['first_pages'][$mediaId]), true];
            }
        }
        return [null, true];
    }
}
