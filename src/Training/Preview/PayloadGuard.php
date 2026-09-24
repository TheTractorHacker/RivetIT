<?php

namespace ITFlow\Training\Preview;

/**
 * The answer-key leak guard (spec §3.7, §8 "Answer key", §10.5): walks a learner-facing payload
 * and rejects any key that is not in that payload's allowlist. The allowlists are exactly the
 * keys of LearnerView v1 (§3.7) and the preview_quiz_* response shapes (§6.2) - so `correct`,
 * `critical`, `points`, `pool`, `banks`, `sha256` and `kb_source` can never appear, and
 * `explanation` / `feedback` appear only in a submit response (after grading).
 *
 * Maps whose keys are data rather than field names are named in FREE_MAPS: `strings` (UI
 * string id => text), `answers` (question uid => option uids, answers_after_pass only) and
 * `saved` (kiosk resume: question uid => chosen option uids). SUB_SHAPES name keys whose value
 * is walked with its own allowlist: `achievements` items may carry only AWARD_PUBLIC keys, so
 * `name`/`icon`/`color` are allowed there and nowhere else in a kiosk quiz payload.
 *
 * The Actions run every learner-facing payload through assert() before sending it: a leak is
 * a 500, never a response.
 */
final class PayloadGuard
{
    public const LEARNER_VIEW = [
        'mode', 'source', 'type', 'course_id', 'revision_id', 'revision_number', 'lang', 'languages', 'can_verify_video', 'can_grade',
        'course', 'uid', 'kind', 'name', 'summary', 'description_html', 'cover_url', 'color', 'est_minutes', 'sequential',
        'requires_signature', 'attestation_text', 'sections', 'title', 'lesson_uids', 'lesson_order', 'lessons', 'id', 'required',
        'duration_s', 'preview_enabled', 'article', 'body_html', 'document', 'page_count', 'pages', 'n', 'url', 'w', 'h', 'download_url',
        'video', 'provider', 'src_url', 'min_watch_pct', 'embed_url', 'video_id', 'verified', 'image', 'caption', 'ack', 'statement_html',
        'require_signature', 'require_pin', 'quiz', 'role', 'question_count', 'pass_pct', 'max_attempts', 'time_limit_s', 'show_review',
        'must_pass', 'intro', 'resources', 'endpoints', 'quiz_start', 'quiz_submit', 'video_verify', 'strings',
    ];

    public const QUIZ_START = [
        'graded', 'attempt_token', 'quiz', 'title', 'intro', 'time_limit_s', 'deadline_remaining_s', 'show_review', 'question_count',
        'questions', 'uid', 'type', 'text', 'image_url', 'options',
    ];

    public const QUIZ_SUBMIT = [
        'score_pct', 'points_earned', 'points_possible', 'passed', 'pass_pct', 'critical_missed', 'topics_missed', 'feedback', 'mode',
        'missed', 'uid', 'text', 'topic', 'explanation', 'chosen_feedback', 'answers',
    ];

    /** Kiosk exam start (P3 spec §7.4): every QUIZ_START key plus the attempt bookkeeping and the resumed answers. */
    public const KIOSK_QUIZ_START = [
        'graded', 'attempt_token', 'quiz', 'title', 'intro', 'time_limit_s', 'deadline_remaining_s', 'show_review', 'question_count',
        'questions', 'uid', 'type', 'text', 'image_url', 'options',
        'attempt_id', 'attempt_number', 'attempts_max', 'saved',
    ];

    /** Kiosk exam submit: every QUIZ_SUBMIT key plus attempts, run state and the awards (walked with AWARD_PUBLIC only). */
    public const KIOSK_QUIZ_SUBMIT = [
        'score_pct', 'points_earned', 'points_possible', 'passed', 'pass_pct', 'critical_missed', 'topics_missed', 'feedback', 'mode',
        'missed', 'uid', 'text', 'topic', 'explanation', 'chosen_feedback', 'answers',
        'attempt_number', 'attempts_max', 'attempts_left', 'locked', 'run_status', 'next', 'achievements',
    ];

    /** One awarded achievement as a learner sees it. */
    public const AWARD_PUBLIC = ['uid', 'name', 'description', 'icon', 'color', 'awarded_at'];

    /** Keys whose value is walked with its OWN allowlist instead of the parent's. */
    public const SUB_SHAPES = ['achievements' => self::AWARD_PUBLIC];

    /** Keys whose value is a map with data keys: name => 'strings' (scalar values) | 'uid_lists' (lists of uid strings). */
    public const FREE_MAPS = ['strings' => 'strings', 'answers' => 'uid_lists', 'saved' => 'uid_lists'];

    private const SHAPES = ['learner_view' => self::LEARNER_VIEW, 'quiz_start' => self::QUIZ_START, 'quiz_submit' => self::QUIZ_SUBMIT,
                            'kiosk_quiz_start' => self::KIOSK_QUIZ_START, 'kiosk_quiz_submit' => self::KIOSK_QUIZ_SUBMIT];

    /** @throws \LogicException naming the first offending path */
    public static function assert(mixed $payload, string $shape): void
    {
        $bad = self::violations($payload, $shape);
        if ($bad !== []) {
            throw new \LogicException("Training preview payload ($shape) contains keys outside its allowlist: " . implode(', ', array_slice($bad, 0, 5)));
        }
    }

    /** @return list<string> JSON paths of keys not allowed in $shape */
    public static function violations(mixed $payload, string $shape): array
    {
        if (!isset(self::SHAPES[$shape])) {
            throw new \InvalidArgumentException("Unknown payload shape '$shape'");
        }
        $out = [];
        self::walk($payload, array_fill_keys(self::SHAPES[$shape], true), '$', $out);
        return $out;
    }

    private static function walk(mixed $v, array $allowed, string $path, array &$out): void
    {
        if ($v instanceof \stdClass) {
            $v = (array) $v;
        }
        if (!is_array($v)) {
            return;
        }
        if (array_is_list($v)) {
            foreach ($v as $i => $item) {
                self::walk($item, $allowed, $path . '[' . $i . ']', $out);
            }
            return;
        }
        foreach ($v as $k => $item) {
            $k = (string) $k;
            if (isset(self::SUB_SHAPES[$k]) && isset($allowed[$k])) {
                self::walk($item, array_fill_keys(self::SUB_SHAPES[$k], true), "$path.$k", $out);
                continue;
            }
            if (isset(self::FREE_MAPS[$k]) && isset($allowed[$k])) {
                self::checkFreeMap($item, self::FREE_MAPS[$k], "$path.$k", $out);
                continue;
            }
            if (!isset($allowed[$k])) {
                $out[] = "$path.$k";
                continue;
            }
            self::walk($item, $allowed, "$path.$k", $out);
        }
    }

    private static function checkFreeMap(mixed $v, string $kind, string $path, array &$out): void
    {
        if ($v instanceof \stdClass) {
            $v = (array) $v;
        }
        if (!is_array($v)) {
            $out[] = $path;
            return;
        }
        foreach ($v as $k => $item) {
            if ($kind === 'strings') {
                if (!is_string($item)) {
                    $out[] = "$path.$k";
                }
                continue;
            }
            if (!is_array($item) || !array_is_list($item) || array_filter($item, static fn($x) => !is_string($x)) !== []) {
                $out[] = "$path.$k";
            }
        }
    }
}
