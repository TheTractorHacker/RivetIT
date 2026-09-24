<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\ArticleSanitizer;

/**
 * What still stands between a lesson and publishing (spec §3.4). The builder shows these as a
 * neutral to-do list; the publish modal (Lane D's PublishValidator, which includes every code
 * here) shows them with their severity.
 *
 * The course's default language is checked field by field. Every other language passed in is
 * summarised as one `translation_incomplete` warning (offering Spanish never blocks publishing
 * English; a required language is enforced by the publish check).
 *
 * Issue = {code, severity:'error'|'warning', message, lesson_id, lesson_uid, lang?, quiz_id?}
 */
final class LessonIssues
{
    public const ERROR_CODES = ['title_missing', 'body_missing', 'media_missing', 'pdf_pages_pending', 'video_missing',
        'video_unverified', 'video_verification_stale', 'video_check_failed', 'video_duration_short', 'image_missing',
        'ack_statement_missing', 'quiz_empty', 'article_data_uri', 'article_foreign_media'];
    public const WARNING_CODES = ['translation_incomplete'];

    private const LANG_NAMES = ['en' => 'English', 'es' => 'Spanish'];

    /** @param list<string> $languages the course languages to check (the default is always checked) */
    public static function forLesson(\mysqli $db, int $lessonId, array $languages): array
    {
        $row = Db::one(
            $db,
            'SELECT c.course_default_language FROM training_lessons l JOIN training_courses c ON c.course_id = l.lesson_course_id WHERE l.lesson_id = ?',
            'i',
            [$lessonId]
        );
        if ($row === null) {
            return [];
        }
        $default = (string) $row['course_default_language'];
        $langs = array_values(array_unique(array_merge([$default], array_map('strval', $languages))));
        $facts = LessonFacts::load($db, [$lessonId], $langs, true);
        return self::fromFacts($facts, $lessonId, $langs, $default);
    }

    /** Issues from already-loaded facts (LessonFacts::load with HTML). */
    public static function fromFacts(array $facts, int $lessonId, array $languages, string $defaultLang): array
    {
        $lesson = $facts['lessons'][$lessonId] ?? null;
        if ($lesson === null || $lesson['lesson_archived_at'] !== null) {
            return [];
        }
        $base = ['lesson_id' => $lessonId, 'lesson_uid' => (string) $lesson['lesson_uid']];
        $issues = [];
        $add = static function (string $code, string $message, array $extra = []) use (&$issues, $base): void {
            $issues[] = ['code' => $code, 'severity' => in_array($code, self::WARNING_CODES, true) ? 'warning' : 'error', 'message' => $message] + $base + $extra;
        };

        // Default language: every problem individually.
        foreach (self::contentProblems($facts, $lesson, LessonFacts::variant($facts, $lessonId, $defaultLang)) as [$code, $message]) {
            $add($code, $message, ['lang' => $defaultLang]);
        }

        // Quizzes: an empty quiz lesson, or an attached check with no questions.
        $quiz = $facts['quiz'][$lessonId] ?? null;
        if ($lesson['lesson_type'] === 'quiz' && ($quiz === null || $quiz['pool'] === 0)) {
            $add('quiz_empty', 'Add at least one question to this quiz.', $quiz ? ['quiz_id' => $quiz['quiz_id']] : []);
        } elseif ($lesson['lesson_type'] !== 'quiz' && $quiz !== null && $quiz['pool'] === 0) {
            $add('quiz_empty', 'The quick check after this lesson has no questions yet. Add some or turn it off.', ['quiz_id' => $quiz['quiz_id']]);
        }

        // Other languages: one summary per language.
        foreach ($languages as $lang) {
            if ($lang === $defaultLang) {
                continue;
            }
            $missing = self::missingParts($facts, $lesson, LessonFacts::variant($facts, $lessonId, $lang));
            if ($missing !== []) {
                $name = self::LANG_NAMES[$lang] ?? strtoupper($lang);
                $add('translation_incomplete', "$name is not finished: " . implode(', ', $missing) . '.', ['lang' => $lang]);
            }
        }
        return $issues;
    }

    /** Whether the variant has everything its type needs (verification state aside). */
    public static function variantComplete(array $facts, array $lesson, ?array $variant): bool
    {
        return self::missingParts($facts, $lesson, $variant) === [];
    }

    /** @return list<string> human names of what is missing in a variant */
    private static function missingParts(array $facts, array $lesson, ?array $variant): array
    {
        if ($variant === null) {
            return ['title and content'];
        }
        $missing = [];
        if (trim((string) $variant['lvar_title']) === '') {
            $missing[] = 'title';
        }
        switch ($lesson['lesson_type']) {
            case 'article':
                if (self::bodyEmpty($variant)) {
                    $missing[] = 'article text';
                }
                break;
            case 'acknowledgment':
                if (self::bodyEmpty($variant)) {
                    $missing[] = 'statement';
                }
                break;
            case 'document':
                if (!self::hasMedia($facts, $variant, 'pdf')) {
                    $missing[] = 'PDF';
                }
                break;
            case 'image':
                if (!self::hasMedia($facts, $variant, 'image')) {
                    $missing[] = 'image';
                }
                break;
            case 'video':
                $p = $variant['lvar_video_provider'];
                if ($p === null || ($p === 'upload' && !self::hasMedia($facts, $variant, 'video')) || ($p !== 'upload' && $variant['lvar_video_ext_id'] === null)) {
                    $missing[] = 'video';
                }
                break;
        }
        return $missing;
    }

    /** @return list<array{0:string, 1:string}> [code, message] for the default-language variant */
    private static function contentProblems(array $facts, array $lesson, ?array $variant): array
    {
        $out = [];
        $type = (string) $lesson['lesson_type'];
        if ($variant === null || trim((string) $variant['lvar_title']) === '') {
            $out[] = ['title_missing', 'Give this content a title.'];
        }
        switch ($type) {
            case 'article':
                if ($variant === null || self::bodyEmpty($variant)) {
                    $out[] = ['body_missing', 'Write the article text, or import it from the Knowledge Base or a Word file.'];
                }
                break;

            case 'acknowledgment':
                if ($variant === null || self::bodyEmpty($variant)) {
                    $out[] = ['ack_statement_missing', 'Write the statement people acknowledge.'];
                }
                break;

            case 'document':
                $m = self::media($facts, $variant);
                if ($m === null || $m['media_kind'] !== 'pdf') {
                    $out[] = ['media_missing', 'Upload the PDF for this document.'];
                } else {
                    $ready = (int) ($facts['pages_ready'][(int) $m['media_id']] ?? 0);
                    $total = (int) ($m['media_page_count'] ?? 0);
                    if ($total === 0 || $ready < $total) {
                        $out[] = ['pdf_pages_pending', $total > 0 ? "Pages are still being prepared ($ready of $total)." : 'Pages are still being prepared.'];
                    }
                }
                break;

            case 'image':
                $m = self::media($facts, $variant);
                if ($m === null || $m['media_kind'] !== 'image') {
                    $out[] = ['image_missing', 'Upload the image.'];
                }
                break;

            case 'video':
                $p = $variant['lvar_video_provider'] ?? null;
                if ($p === null) {
                    $out[] = ['video_missing', 'Upload a video or paste a YouTube or Vimeo link.'];
                } elseif ($p === 'upload') {
                    $m = self::media($facts, $variant);
                    if ($m === null || $m['media_kind'] !== 'video') {
                        $out[] = ['media_missing', 'Upload the video file.'];
                    }
                } elseif ($variant['lvar_video_ext_id'] === null) {
                    $out[] = ['video_missing', 'Paste the YouTube or Vimeo link again.'];
                } else {
                    $check = LessonFacts::vcheck($facts, $variant);
                    $state = VideoRefs::state($check);
                    if ($check !== null && $state['failed']) {
                        $out[] = ['video_check_failed', self::failedMessage($check)];
                    }
                    if (!$state['verified']) {
                        $out[] = ['video_unverified', 'Press play once in the content window to confirm this video works.'];
                    } elseif ($state['stale']) {
                        $out[] = ['video_verification_stale', 'This video was last confirmed more than 30 days ago. Press play once to confirm it again.'];
                    }
                    if ($state['duration_s'] !== null && $state['duration_s'] < VideoRefs::MIN_DURATION_S) {
                        $out[] = ['video_duration_short', 'This video is shorter than 10 seconds.'];
                    }
                }
                break;
        }

        // Stored HTML is purified at save, but content imported before a rule change (or edited
        // outside the editor) is still checked, so publish never meets a surprise.
        if ($variant !== null) {
            $codes = [];
            foreach (['lvar_body_html', 'lvar_description_html'] as $col) {
                $html = $variant[$col] ?? null;
                if ($html === null || $html === '') {
                    continue;
                }
                foreach ((array) ArticleSanitizer::issues((string) $html) as $issue) {
                    $code = is_array($issue) ? (string) ($issue['code'] ?? '') : (string) $issue;
                    if (in_array($code, ['article_data_uri', 'article_foreign_media'], true)) {
                        $codes[$code] = true;
                    }
                }
            }
            if (isset($codes['article_data_uri'])) {
                $out[] = ['article_data_uri', 'An embedded image was pasted as data. Remove it and upload the image instead.'];
            }
            if (isset($codes['article_foreign_media'])) {
                $out[] = ['article_foreign_media', 'An image or file comes from outside Training. Remove it and upload it here instead.'];
            }
        }
        return $out;
    }

    private static function media(array $facts, ?array $variant): ?array
    {
        if ($variant === null || $variant['lvar_media_id'] === null) {
            return null;
        }
        return $facts['media'][(int) $variant['lvar_media_id']] ?? null;
    }

    private static function hasMedia(array $facts, array $variant, string $kind): bool
    {
        $m = self::media($facts, $variant);
        return $m !== null && $m['media_kind'] === $kind;
    }

    private static function bodyEmpty(array $variant): bool
    {
        if (array_key_exists('lvar_body_html', $variant) && $variant['lvar_body_html'] === null && (int) ($variant['lvar_word_count'] ?? 0) > 0) {
            // Facts loaded without HTML: fall back to the stored word count.
            return false;
        }
        return MediaRefs::htmlEmpty($variant['lvar_body_html'] ?? null);
    }

    private static function failedMessage(array $check): string
    {
        return match ($check['vcheck_status'] ?? null) {
            'private' => 'This video is private. In YouTube Studio set Visibility to Unlisted.',
            'embed_disabled' => 'Embedding is turned off for this video. Studio › Video › Show more › Allow embedding.',
            'not_found' => 'This video could not be found. Check the link.',
            'live' => "Live streams and Premieres can't be used.",
            default => 'The last check of this video failed. Press play once to confirm it works.',
        };
    }
}
