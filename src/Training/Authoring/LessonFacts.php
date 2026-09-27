<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\Captions;

/**
 * One batched read of everything the lesson views, issue checks and time estimates need for a
 * set of lessons: the lesson rows, every language variant, the media rows they reference (with
 * rendered-page counts), the video checks, quiz counts and resource counts. A course with a
 * hundred lessons costs a fixed handful of queries instead of several per lesson.
 *
 * With $withHtml = false the (potentially large) HTML columns are not read - enough for list
 * estimates, not for issues or the lesson editor.
 */
final class LessonFacts
{
    public const VARIANT_COLS = 'lvar_lesson_id, lvar_lang, lvar_title, lvar_word_count, lvar_media_id, lvar_caption, lvar_video_provider,
        lvar_video_ext_id, lvar_video_ext_hash, lvar_kb_source_article_id, lvar_kb_source_sha256, lvar_kb_import_body_sha256,
        lvar_kb_imported_at_utc, lvar_updated_by, lvar_updated_at';

    /**
     * VARIANT_COLS plus lvar_caption_media_id (DB 2.6.98) - as NULL before that update, so every
     * reader sees the same shape either way.
     */
    public static function variantCols(\mysqli $db): string
    {
        return self::VARIANT_COLS . (Captions::schemaReady($db) ? ', lvar_caption_media_id' : ', NULL AS lvar_caption_media_id');
    }

    /**
     * @param list<int>    $lessonIds
     * @param list<string> $languages languages to count quiz translations for
     * @return array{lessons: array<int, array>, variants: array<int, array<string, array>>, media: array<int, array>,
     *               pages_ready: array<int, int>, first_pages: array<int, int>, vchecks: array<string, array>,
     *               quiz: array<int, array>, resources: array<int, int>}
     */
    public static function load(\mysqli $db, array $lessonIds, array $languages, bool $withHtml = true): array
    {
        $facts = ['lessons' => [], 'variants' => [], 'media' => [], 'pages_ready' => [], 'first_pages' => [],
                  'vchecks' => [], 'quiz' => [], 'resources' => []];
        $lessonIds = array_values(array_unique(array_filter(array_map('intval', $lessonIds), static fn($i) => $i > 0)));
        if ($lessonIds === []) {
            return $facts;
        }

        $mediaIds = [];
        foreach (array_chunk($lessonIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('i', count($chunk));
            foreach (Db::all($db, 'SELECT ' . Guard::LESSON_COLS . " FROM training_lessons WHERE lesson_id IN ($in)", $types, $chunk) as $l) {
                $facts['lessons'][(int) $l['lesson_id']] = $l;
                if ($l['lesson_thumb_media_id'] !== null) {
                    $mediaIds[] = (int) $l['lesson_thumb_media_id'];
                }
            }
            $cols = self::variantCols($db) . ($withHtml ? ', lvar_description_html, lvar_body_html' : ', NULL AS lvar_description_html, NULL AS lvar_body_html');
            foreach (Db::all($db, "SELECT $cols FROM training_lesson_variants WHERE lvar_lesson_id IN ($in) ORDER BY lvar_lesson_id, lvar_lang", $types, $chunk) as $v) {
                $facts['variants'][(int) $v['lvar_lesson_id']][(string) $v['lvar_lang']] = $v;
                if ($v['lvar_media_id'] !== null) {
                    $mediaIds[] = (int) $v['lvar_media_id'];
                }
                if ($v['lvar_caption_media_id'] !== null) {
                    $mediaIds[] = (int) $v['lvar_caption_media_id'];
                }
            }
            foreach (Db::all($db, "SELECT lres_lesson_id, COUNT(*) AS n FROM training_lesson_resources WHERE lres_lesson_id IN ($in) AND lres_archived_at IS NULL GROUP BY lres_lesson_id", $types, $chunk) as $r) {
                $facts['resources'][(int) $r['lres_lesson_id']] = (int) $r['n'];
            }
        }

        $triples = [];
        foreach ($facts['variants'] as $byLang) {
            foreach ($byLang as $v) {
                if (in_array($v['lvar_video_provider'], ['youtube', 'vimeo'], true) && $v['lvar_video_ext_id'] !== null) {
                    $triples[] = [(string) $v['lvar_video_provider'], (string) $v['lvar_video_ext_id'], $v['lvar_video_ext_hash']];
                }
            }
        }
        $facts['vchecks'] = VideoRefs::rows($db, $triples);
        foreach ($facts['vchecks'] as $vc) {
            if ($vc['vcheck_thumb_media_id'] !== null) {
                $mediaIds[] = (int) $vc['vcheck_thumb_media_id'];
            }
        }

        $facts['media'] = MediaRefs::rows($db, $mediaIds);
        $pdfIds = [];
        foreach ($facts['media'] as $id => $m) {
            if ($m['media_kind'] === 'pdf') {
                $pdfIds[] = $id;
            }
        }
        $facts['pages_ready'] = MediaRefs::pagesReady($db, $pdfIds);
        $facts['first_pages'] = MediaRefs::firstPages($db, $pdfIds);
        $facts['quiz'] = QuizFacts::forLessons($db, $lessonIds, $languages);
        return $facts;
    }

    /** The variant of $lessonId in $lang (null when that language has none). */
    public static function variant(array $facts, int $lessonId, string $lang): ?array
    {
        return $facts['variants'][$lessonId][$lang] ?? null;
    }

    /** The video check row for a variant, if it uses YouTube/Vimeo. */
    public static function vcheck(array $facts, ?array $variant): ?array
    {
        if ($variant === null || !in_array($variant['lvar_video_provider'], ['youtube', 'vimeo'], true) || $variant['lvar_video_ext_id'] === null) {
            return null;
        }
        return $facts['vchecks'][VideoRefs::key((string) $variant['lvar_video_provider'], (string) $variant['lvar_video_ext_id'], $variant['lvar_video_ext_hash'])] ?? null;
    }

    /** Variant row merged with the media facts DurationEstimator reads. */
    public static function estimateInput(array $facts, ?array $variant): array
    {
        if ($variant === null) {
            return [];
        }
        $in = $variant;
        $m = $variant['lvar_media_id'] !== null ? ($facts['media'][(int) $variant['lvar_media_id']] ?? null) : null;
        if ($m !== null) {
            $in['media_duration_ms'] = $m['media_duration_ms'];
            $in['media_page_count'] = $m['media_page_count'];
        }
        return $in;
    }

    /**
     * Auto and effective duration for a lesson, from its default-language variant (or the first
     * variant that exists).
     *
     * @return array{auto:int, effective:int, source:string}
     */
    public static function duration(array $facts, int $lessonId, string $defaultLang): array
    {
        $lesson = $facts['lessons'][$lessonId];
        $variant = self::variant($facts, $lessonId, $defaultLang) ?? (array_values($facts['variants'][$lessonId] ?? [])[0] ?? null);
        $quiz = $facts['quiz'][$lessonId] ?? null;
        $isQuiz = $lesson['lesson_type'] === 'quiz';
        $est = DurationEstimator::estimate(
            $lesson,
            self::estimateInput($facts, $variant),
            $isQuiz && $quiz ? $quiz['question_count'] : null,
            !$isQuiz && $quiz ? $quiz['question_count'] : null,
            self::vcheck($facts, $variant)
        );
        $override = $lesson['lesson_duration_s'] !== null ? (int) $lesson['lesson_duration_s'] : null;
        return [
            'auto' => $est['seconds'],
            'effective' => ($override !== null && $override > 0) ? $override : $est['seconds'],
            'source' => ($override !== null && $override > 0) ? 'override' : $est['source'],
        ];
    }
}
