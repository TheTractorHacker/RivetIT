<?php

namespace ITFlow\Training\Kiosk\Learn;

/**
 * "Continue from the last point" (owner report 2026-09-27: "There is no continue from last point"). The run keeps,
 * for its CURRENT lesson, where the learner last was - trun_lesson_resume_at: seconds into a video, or the 1-based
 * page of a document (PDF); NULL when nothing was recorded (runs from before DB 2.6.99, and whenever the current
 * lesson's state is reset: another lesson opened, the lesson completed, the run closed).
 *
 * NAVIGATION ONLY. It never grants credit and never moves the furthest point: LessonCredit's rules are untouched,
 * a video point is kept at or below the furthest point reached (trun_lesson_max_position) and a page within the
 * document. Pure functions, no database, no clock.
 *
 * The reopen rule (players: TrainingMediaControls.resumePoint / nearEnd, the Learning Center card here):
 *   point > 5 s and not in the last few seconds  -> "Resuming at 2:13 · Start over", the video starts there;
 *   point in the last few seconds                -> the video starts at 0 (with the end-of-video message when too
 *                                                   little time counted);
 *   no point (NULL, an older run)                -> the furthest point, as before (>= 5 s, not in the last few seconds).
 */
final class ResumePoint
{
    public const COLUMN = 'trun_lesson_resume_at';
    /** A video point must be past this many seconds to be offered (the NULL fallback keeps its old ">= 5"). */
    public const MIN_OFFER_S = 5;

    /**
     * The point a tick sample carries, or null when it carries none (the stored point is then kept):
     *   video     position_s, floored and clamped to [0, $maxAfter] - the furthest point AFTER this tick; a sample
     *             naming another video (video_id mismatch, a rejected tick) carries none;
     *   document  current_page when it is an int 1..page count;
     *   other     none.
     */
    public static function fromSample(array $sample, string $type, int $maxAfter, int $pageCount, ?string $videoId): ?int
    {
        if ($type === 'video') {
            $given = isset($sample['video_id']) && is_string($sample['video_id']) && $sample['video_id'] !== '' ? $sample['video_id'] : null;
            if ($given !== null && $videoId !== null && $given !== $videoId) {
                return null;
            }
            $p = $sample['position_s'] ?? null;
            if ((!is_int($p) && !is_float($p)) || !is_finite((float) $p)) {
                return null;
            }
            return max(0, min(max(0, $maxAfter), (int) floor(max(0.0, min(86400.0, (float) $p)))));
        }
        if ($type === 'document') {
            $p = $sample['current_page'] ?? null;
            return is_int($p) && $p >= 1 && $p <= min($pageCount, LessonCredit::MAX_PAGES) ? $p : null;
        }
        return null;
    }

    /** The stored point as a gate reports it (resume_at): a video point never past $maxPosition; a page within the document; else null. */
    public static function forGate(mixed $stored, string $type, int $maxPosition, int $pageCount): ?int
    {
        if ($stored === null || $stored === '' || !is_numeric($stored)) {
            return null;
        }
        $v = (int) $stored;
        if ($type === 'video') {
            return max(0, min($v, max(0, $maxPosition)));
        }
        if ($type === 'document') {
            return $v >= 1 && $v <= min($pageCount, LessonCredit::MAX_PAGES) ? $v : null;
        }
        return null;
    }

    /** In the last few seconds of a video: 2 % of its length, 5 to 10 s (TrainingMediaControls.nearEnd). */
    public static function nearEnd(int $sec, int $durationS): bool
    {
        if ($durationS <= 0) {
            return false;
        }
        $w = min(10, max(5, (int) round($durationS * 0.02)));
        return $sec >= $durationS - $w;
    }

    /**
     * Video: the point a reopen jumps to ("Resuming at …", the card's "Continue at …"), or null when the video starts
     * at 0. $resumeAt null = no last point recorded: the furthest point (the rule before 2.6.99).
     */
    public static function videoOffer(?int $resumeAt, int $maxPosition, int $durationS): ?int
    {
        $max = max(0, $maxPosition);
        if ($resumeAt !== null) {
            $p = max(0, min($resumeAt, $max));
            return $p > self::MIN_OFFER_S && !self::nearEnd($p, $durationS) ? $p : null;
        }
        return $max >= self::MIN_OFFER_S && !self::nearEnd($max, $durationS) ? $max : null;
    }
}
