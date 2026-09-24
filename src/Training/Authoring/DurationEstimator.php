<?php

namespace ITFlow\Training\Authoring;

/**
 * Lesson time estimate (plan A13 "Duration estimate is automatic and editable"): video
 * length, PDF pages x 30 s, article words at 200 wpm, plus quiz questions. The author's
 * override (lesson_duration_s) wins when set.
 *
 * Inputs are plain arrays so the Publish lane can call this from its build without the
 * authoring services. Keys are read in either the raw column form or the short form:
 *   $lesson  : lesson_type|type, lesson_duration_s|duration_s (override, null = auto)
 *   $variant : lvar_word_count|word_count, lvar_video_provider|video_provider,
 *              media_duration_ms|duration_ms (uploaded video), media_page_count|page_count (PDF)
 *   $quizQ   : questions a learner answers in a quiz lesson (null = not a quiz / unknown)
 *   $checkQ  : questions in the knowledge check attached to a content lesson (null = none)
 *   $vcheck  : the training_video_checks row (vcheck_meta_duration_s / vcheck_play_duration_s,
 *              or meta_duration_s / play_duration_s) for a YouTube/Vimeo variant
 */
final class DurationEstimator
{
    public const WORDS_PER_MINUTE = 200;
    public const SECONDS_PER_PAGE = 30;
    public const SECONDS_PER_QUESTION = 45;
    public const IMAGE_SECONDS = 30;
    public const ACK_SECONDS = 60;
    public const MIN_READ_SECONDS = 30;

    /** Effective duration in whole seconds: the override when set, else the estimate. */
    public static function forLesson(array $lesson, array $variant, ?int $quizQ, ?int $checkQ, ?array $vcheck): int
    {
        $override = $lesson['lesson_duration_s'] ?? ($lesson['duration_s'] ?? null);
        if ($override !== null && $override !== '' && (int) $override > 0) {
            return (int) $override;
        }
        return self::estimate($lesson, $variant, $quizQ, $checkQ, $vcheck)['seconds'];
    }

    /**
     * The automatic estimate and where it came from.
     *
     * @return array{seconds:int, source:string}  source: video|pages|words|image|ack|quiz|none
     */
    public static function estimate(array $lesson, array $variant, ?int $quizQ, ?int $checkQ, ?array $vcheck): array
    {
        $type = (string) ($lesson['lesson_type'] ?? ($lesson['type'] ?? ''));
        $words = (int) ($variant['lvar_word_count'] ?? ($variant['word_count'] ?? 0));
        $check = max(0, (int) $checkQ) * self::SECONDS_PER_QUESTION;

        switch ($type) {
            case 'article':
                if ($words <= 0) {
                    return ['seconds' => $check, 'source' => $check > 0 ? 'quiz' : 'none'];
                }
                return ['seconds' => max(self::MIN_READ_SECONDS, self::readSeconds($words)) + $check, 'source' => 'words'];

            case 'document':
                $pages = (int) ($variant['media_page_count'] ?? ($variant['page_count'] ?? 0));
                if ($pages <= 0) {
                    return ['seconds' => $check, 'source' => $check > 0 ? 'quiz' : 'none'];
                }
                return ['seconds' => $pages * self::SECONDS_PER_PAGE + $check, 'source' => 'pages'];

            case 'video':
                $provider = (string) ($variant['lvar_video_provider'] ?? ($variant['video_provider'] ?? ''));
                $seconds = null;
                if ($provider === 'upload') {
                    $ms = $variant['media_duration_ms'] ?? ($variant['duration_ms'] ?? null);
                    $seconds = ($ms !== null && (int) $ms > 0) ? (int) ceil(((int) $ms) / 1000) : null;
                } elseif ($provider === 'youtube' || $provider === 'vimeo') {
                    $meta = $vcheck['vcheck_meta_duration_s'] ?? ($vcheck['meta_duration_s'] ?? null);
                    $play = $vcheck['vcheck_play_duration_s'] ?? ($vcheck['play_duration_s'] ?? null);
                    $seconds = ($meta !== null && (int) $meta > 0) ? (int) $meta : (($play !== null && (int) $play > 0) ? (int) $play : null);
                }
                if ($seconds === null) {
                    return ['seconds' => $check, 'source' => $check > 0 ? 'quiz' : 'none'];
                }
                return ['seconds' => $seconds + $check, 'source' => 'video'];

            case 'image':
                return ['seconds' => self::IMAGE_SECONDS + $check, 'source' => 'image'];

            case 'acknowledgment':
                return ['seconds' => max(self::ACK_SECONDS, self::readSeconds($words) + 30) + $check, 'source' => 'ack'];

            case 'quiz':
                $q = max(0, (int) $quizQ);
                return ['seconds' => $q * self::SECONDS_PER_QUESTION, 'source' => $q > 0 ? 'quiz' : 'none'];
        }
        return ['seconds' => 0, 'source' => 'none'];
    }

    private static function readSeconds(int $words): int
    {
        return (int) ceil($words * 60 / self::WORDS_PER_MINUTE);
    }
}
