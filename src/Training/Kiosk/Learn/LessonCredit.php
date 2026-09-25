<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Server-credited lesson time and coverage (P3 spec §3.4 "applyTick", §8 "Credit integrity").
 * Pure functions: no database, no clock except the $nowUtc argument, so the table in §10.2
 * "Runs and credit" is unit-testable.
 *
 * STATE ($st) is the run's current-lesson columns, keyed without their prefix:
 *   last_tick (UTC 'Y-m-d H:i:s.v'), last_active (bool), credit (int s), max_position (int s),
 *   pages_hex (?string lowercase hex bitmap, page n = bit n-1), rejected (int)
 *
 * applyTick() credits wall time only for intervals that STARTED active (visible and playing for
 * video, visible and recently interacted-with otherwise), at most 20 s per interval, so credit
 * never exceeds the wall time the client reported activity for, and a pause earns nothing (the
 * pause tick closes the interval). A state change is always recorded; same-state chatter under
 * 5 s changes nothing except the page bitmap (a page is only coverage - the time gate still has to
 * be met, so OR-ing it early lets a last-page flip survive an immediate Complete).
 *
 * Video position is bounded by the elapsed interval: a jump beyond max + min(dt,20)*1.1 + 2 is
 * clamped to that bound (one late tick can never strand a lesson) and counted as rejected.
 */
final class LessonCredit
{
    public const TICK_MIN_S = 5;
    public const TICK_MAX_CREDIT_S = 20;
    public const CAP_S = 1800;
    /** Video: the furthest position may be this many seconds short of the watch requirement. */
    public const POSITION_SLACK_S = 5;
    /** Fallback required time for a video whose length was never recorded (publish refuses those; defensive only). */
    public const UNKNOWN_VIDEO_S = 60;
    public const MAX_PAGES = 150;

    /** The run-row columns that hold the state, in applyTick() key order. */
    public const COLUMNS = [
        'last_tick' => 'trun_lesson_last_tick_at_utc',
        'last_active' => 'trun_lesson_last_active',
        'credit' => 'trun_lesson_credit_s',
        'max_position' => 'trun_lesson_max_position',
        'pages_hex' => 'trun_lesson_pages_hex',
        'rejected' => 'trun_lesson_rejected_ticks',
    ];

    /**
     * Seconds of server credit a lesson needs (capped at CAP_S):
     *   video (upload|youtube|vimeo)  floor(duration_s * min_watch_pct / 100)
     *   document                      5 * page_count
     *   article                       max(15, floor(min(lesson duration_s, 600) * 0.4))
     *   image / acknowledgment        5
     *   quiz                          0 (graded instead)
     *
     * @param array $lessonRev the revision's lesson object (type, duration_s, min_watch_pct, …)
     * @param array $variant   the lesson variant in the run's language (video, pages, …)
     */
    public static function requiredSeconds(array $lessonRev, array $variant): int
    {
        $type = (string) ($lessonRev['type'] ?? '');
        $s = match ($type) {
            'video' => self::videoRequired($lessonRev, $variant),
            'document' => 5 * self::pageCount($variant),
            'article' => max(15, (int) floor(min(max(0, (int) ($lessonRev['duration_s'] ?? 0)), 600) * 0.4)),
            'image', 'acknowledgment' => 5,
            default => 0,
        };
        return max(0, min(self::CAP_S, $s));
    }

    /** Frozen video length of a variant in seconds (0 when unknown). */
    public static function videoDuration(array $variant): int
    {
        return max(0, (int) ($variant['video']['duration_s'] ?? 0));
    }

    public static function pageCount(array $variant): int
    {
        return min(self::MAX_PAGES, is_array($variant['pages'] ?? null) ? count($variant['pages']) : 0);
    }

    /**
     * Applies one client sample. Returns the new state plus 'applied' (bool: last_tick moved or a
     * state change was recorded) and 'rejected_now' (bool).
     *
     * @param array $sample {position_s?:number, pages_seen?:list<int>, playing?:bool, visible?:bool, active?:bool, video_id?:string}
     */
    public static function applyTick(array $st, array $sample, string $type, int $durationS, ?string $videoId, int $pageCount, string $nowUtc): array
    {
        $st = self::normalizeState($st);
        $st['applied'] = false;
        $st['rejected_now'] = false;
        $now = KTime::epoch($nowUtc);
        $last = KTime::epoch($st['last_tick']);
        if ($now === null) {
            throw new \InvalidArgumentException('LessonCredit::applyTick: bad now');
        }
        $dt = $last === null ? 0.0 : max(0.0, $now - $last);

        $given = isset($sample['video_id']) && is_string($sample['video_id']) && $sample['video_id'] !== '' ? $sample['video_id'] : null;
        if ($given !== null && $videoId !== null && $given !== $videoId) {
            $st['rejected'] = min(65535, $st['rejected'] + 1);
            $st['rejected_now'] = true;
            return $st;
        }

        $cur = !empty($sample['visible']) && ($type === 'video' ? !empty($sample['playing']) : !empty($sample['active']));
        if ($cur !== $st['last_active'] || $last === null) {
            if ($st['last_active'] && $last !== null) {
                $st['credit'] += (int) floor(min($dt, self::TICK_MAX_CREDIT_S));
            }
            $st['last_active'] = $cur;
            $st['last_tick'] = $nowUtc;
            $st['applied'] = true;
            self::applyCoverage($st, $sample, $type, $dt, $pageCount);
            return self::cap($st);
        }
        if ($dt < self::TICK_MIN_S) {
            // Same-state chatter: nothing but page coverage (see the class comment).
            if ($type === 'document') {
                $st['pages_hex'] = self::orPages($st['pages_hex'], $sample['pages_seen'] ?? [], $pageCount);
            }
            return $st;
        }
        if ($st['last_active']) {
            $st['credit'] += (int) floor(min($dt, self::TICK_MAX_CREDIT_S));
        }
        $st['last_tick'] = $nowUtc;
        $st['applied'] = true;
        self::applyCoverage($st, $sample, $type, $dt, $pageCount);
        return self::cap($st);
    }

    /**
     * Whether the lesson may be completed now.
     *
     * @return array{credit_s:int, required_s:int, max_position_s:int, pages_seen:int, can_complete:bool, reason:?string}
     */
    public static function gate(array $st, string $type, int $required, int $durationS, int $minPct, int $pageCount): array
    {
        $st = self::normalizeState($st);
        $pagesSeen = count(self::pagesFromHex($st['pages_hex'], $pageCount));
        $reason = null;
        if ($st['credit'] < $required) {
            $reason = 'time';
        } elseif ($type === 'video' && $durationS > 0 && $st['max_position'] < (int) floor($durationS * $minPct / 100) - self::POSITION_SLACK_S) {
            $reason = 'position';
        } elseif ($type === 'document' && $pagesSeen < $pageCount) {
            $reason = 'pages';
        }
        return [
            'credit_s' => $st['credit'],
            'required_s' => $required,
            'max_position_s' => $st['max_position'],
            'pages_seen' => $pagesSeen,
            'can_complete' => $reason === null,
            'reason' => $reason,
        ];
    }

    /** The state stored on a run row. */
    public static function fromRun(array $run): array
    {
        $st = [];
        foreach (self::COLUMNS as $k => $col) {
            $st[$k] = $run[$col] ?? null;
        }
        return self::normalizeState($st);
    }

    /** Fresh state for a lesson opened at $nowUtc (active from the start except for video, which starts on play). */
    public static function fresh(string $type, string $nowUtc): array
    {
        return ['last_tick' => $nowUtc, 'last_active' => $type !== 'video', 'credit' => 0, 'max_position' => 0, 'pages_hex' => null, 'rejected' => 0];
    }

    // ---- page bitmap -------------------------------------------------------------------------------

    /** @return list<int> sorted page numbers set in $hex, limited to 1..$pageCount */
    public static function pagesFromHex(?string $hex, int $pageCount): array
    {
        if ($hex === null || $hex === '' || preg_match('/^[0-9a-f]+$/D', $hex) !== 1) {
            return [];
        }
        $out = [];
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $nibble = hexdec($hex[$len - 1 - $i]);
            for ($b = 0; $b < 4; $b++) {
                if ($nibble & (1 << $b)) {
                    $page = $i * 4 + $b + 1;
                    if ($page <= $pageCount) {
                        $out[] = $page;
                    }
                }
            }
        }
        return $out;
    }

    /** ORs page numbers (1..$pageCount; others ignored) into the bitmap. Null when nothing is set. */
    public static function orPages(?string $hex, mixed $pages, int $pageCount): ?string
    {
        $pageCount = max(0, min(self::MAX_PAGES, $pageCount));
        $set = array_fill_keys(self::pagesFromHex($hex, $pageCount), true);
        if (is_array($pages)) {
            foreach ($pages as $p) {
                if (is_int($p) || (is_string($p) && preg_match('/^[0-9]{1,3}$/D', $p) === 1) || (is_float($p) && floor($p) === $p)) {
                    $p = (int) $p;
                    if ($p >= 1 && $p <= $pageCount) {
                        $set[$p] = true;
                    }
                }
            }
        }
        if ($set === [] || $pageCount === 0) {
            return null;
        }
        $nibbles = (int) ceil($pageCount / 4);
        $out = '';
        for ($i = $nibbles - 1; $i >= 0; $i--) {
            $v = 0;
            for ($b = 0; $b < 4; $b++) {
                if (isset($set[$i * 4 + $b + 1])) {
                    $v |= 1 << $b;
                }
            }
            $out .= dechex($v);
        }
        return $out;
    }

    // ---- internals ---------------------------------------------------------------------------------

    private static function videoRequired(array $lessonRev, array $variant): int
    {
        $d = self::videoDuration($variant);
        if ($d <= 0) {
            return self::UNKNOWN_VIDEO_S;
        }
        $pct = max(0, min(100, (int) ($lessonRev['min_watch_pct'] ?? 100)));
        return (int) floor($d * $pct / 100);
    }

    private static function applyCoverage(array &$st, array $sample, string $type, float $dt, int $pageCount): void
    {
        if ($type === 'video' && isset($sample['position_s']) && (is_int($sample['position_s']) || is_float($sample['position_s']))) {
            $p = (int) floor(max(0, min(86400, (float) $sample['position_s'])));
            $bound = $st['max_position'] + min($dt, self::TICK_MAX_CREDIT_S) * 1.1 + 2;
            if ($p <= $bound) {
                $st['max_position'] = max($st['max_position'], $p);
            } else {
                $st['max_position'] = max($st['max_position'], (int) floor($bound));
                $st['rejected'] = min(65535, $st['rejected'] + 1);
                $st['rejected_now'] = true;
            }
        }
        if ($type === 'document') {
            $st['pages_hex'] = self::orPages($st['pages_hex'], $sample['pages_seen'] ?? [], $pageCount);
        }
    }

    private static function cap(array $st): array
    {
        $st['credit'] = min($st['credit'], 4294967295);
        return $st;
    }

    private static function normalizeState(array $st): array
    {
        return [
            'last_tick' => isset($st['last_tick']) && $st['last_tick'] !== '' ? (string) $st['last_tick'] : null,
            'last_active' => !empty($st['last_active']) && (string) $st['last_active'] !== '0',
            'credit' => max(0, (int) ($st['credit'] ?? 0)),
            'max_position' => max(0, (int) ($st['max_position'] ?? 0)),
            'pages_hex' => isset($st['pages_hex']) && $st['pages_hex'] !== '' ? strtolower((string) $st['pages_hex']) : null,
            'rejected' => max(0, (int) ($st['rejected'] ?? 0)),
        ] + array_intersect_key($st, ['applied' => 1, 'rejected_now' => 1]);
    }
}
