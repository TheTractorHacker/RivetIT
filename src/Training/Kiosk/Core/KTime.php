<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Clock;

/**
 * UTC arithmetic for the kiosk (P3 spec §0.11): every comparison against a `*_utc` column binds a
 * literal computed HERE from Clock::nowUtc(), never an SQL-side clock function (the
 * MySQL session zone is the app's local offset). Strings are 'Y-m-d H:i:s.v' (DATETIME(3)).
 */
final class KTime
{
    public static function now(): string
    {
        return Clock::nowUtc();
    }

    /** $base (or now) plus $seconds (may be negative), as 'Y-m-d H:i:s.v' UTC. */
    public static function plus(float $seconds, ?string $base = null): string
    {
        $t = $base === null ? microtime(true) : self::epoch($base);
        if ($t === null) {
            throw new \InvalidArgumentException('KTime::plus: bad base time');
        }
        return self::fromEpoch($t + $seconds);
    }

    /** Seconds since the epoch of a stored UTC 'Y-m-d H:i:s[.v]' value; null for null/garbage. */
    public static function epoch(?string $utc): ?float
    {
        if ($utc === null || $utc === '' || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(\.(\d{1,6}))?$/D', $utc, $m) !== 1) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1], new \DateTimeZone('UTC'));
        if ($dt === false) {
            return null;
        }
        $frac = isset($m[3]) ? (float) ('0.' . $m[3]) : 0.0;
        return (float) $dt->getTimestamp() + $frac;
    }

    public static function fromEpoch(float $t): string
    {
        $sec = (int) floor($t);
        $ms = (int) floor(($t - $sec) * 1000);
        if ($ms > 999) {
            $ms = 999;
        }
        return gmdate('Y-m-d H:i:s', $sec) . '.' . str_pad((string) $ms, 3, '0', STR_PAD_LEFT);
    }

    /** Seconds from now until $utc (negative when past); null when $utc is null. */
    public static function secondsUntil(?string $utc): ?float
    {
        $e = self::epoch($utc);
        return $e === null ? null : $e - microtime(true);
    }

    /** True when $utc is set and in the future. */
    public static function isFuture(?string $utc): bool
    {
        $s = self::secondsUntil($utc);
        return $s !== null && $s > 0;
    }

    /**
     * A stored UTC time as a kiosk clock time in the app's time zone: "3:13 PM" (en) / "15:13" (es)
     * when it is today there, else with the date ("Sat, Sep 27, 3:13 PM" / "27/9 15:13"). Plain text.
     */
    public static function localClock(?string $utc, string $lang): string
    {
        $e = self::epoch($utc);
        if ($e === null) {
            return '';
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        $at = (new \DateTimeImmutable('@' . (int) floor($e)))->setTimezone($tz);
        $es = $lang === 'es';
        $time = $at->format($es ? 'G:i' : 'g:i A');
        if ($at->format('Y-m-d') === (new \DateTimeImmutable('now', $tz))->format('Y-m-d')) {
            return $time;
        }
        return $es ? $at->format('j/n') . ' ' . $time : $at->format('D, M j') . ', ' . $time;
    }

    /** Whole minutes (rounded up, >= 1) until $utc, 0 when not in the future. */
    public static function minutesUntil(?string $utc): int
    {
        $s = self::secondsUntil($utc);
        return ($s === null || $s <= 0) ? 0 : max(1, (int) ceil($s / 60));
    }
}
