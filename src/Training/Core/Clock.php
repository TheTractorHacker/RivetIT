<?php

namespace ITFlow\Training\Core;

/**
 * The one clock training code reads.
 *
 * Two kinds of time live in the training tables (spec §0 "Time"):
 *   - `*_utc DATETIME(3)` columns hold UTC with milliseconds. Their value always comes
 *     from nowUtc() here and is bound as a literal, never from NOW()/UTC_TIMESTAMP(),
 *     so the exact string that is hashed is the exact string that is stored and later
 *     re-read through the text protocol ('Y-m-d H:i:s.v').
 *   - `*_at DATETIME` columns use the database's current_timestamp(), i.e. the MySQL
 *     session time zone, which includes/inc_set_timezone.php sets to the app's offset.
 *
 * API responses never send either raw: toIso() converts to ISO-8601 with an offset in the
 * app's time zone, so the browser never has to guess which convention a column uses.
 */
final class Clock
{
    /** Current UTC time as 'Y-m-d H:i:s.v' (millisecond precision, matches DATETIME(3)). */
    public static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /**
     * Converts a stored DATETIME / DATETIME(3) string to ISO-8601 with an offset.
     *
     * @param string|null $v     'Y-m-d H:i:s' or 'Y-m-d H:i:s.v' (anything else returns null)
     * @param bool        $isUtc true for `*_utc` columns; false for `*_at` columns, which are
     *                           in the app's local zone (date_default_timezone_get()).
     */
    public static function toIso(?string $v, bool $isUtc): ?string
    {
        if ($v === null || $v === '' || str_starts_with($v, '0000-00-00')) {
            return null;
        }
        $local = new \DateTimeZone(date_default_timezone_get());
        $src = $isUtc ? new \DateTimeZone('UTC') : $local;
        $format = str_contains($v, '.') ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s';
        $dt = \DateTimeImmutable::createFromFormat('!' . $format, $v, $src);
        if ($dt === false) {
            return null;
        }
        return $dt->setTimezone($local)->format(\DateTimeInterface::ATOM);
    }

    // ---- Business dates (Phase 2 spec §0 #5) -------------------------------------------------
    // `*_on DATE` columns hold LOCAL calendar dates in the app's time zone
    // (date_default_timezone_get(), America/Chicago here). They come from todayLocal() or
    // localDate($utc), never from CURDATE() (the MySQL session zone is an offset, not a zone,
    // and a hashed value must not depend on it). Date arithmetic below is pure calendar math
    // on 'Y-m-d' strings (done in UTC, so a DST change can never shift a day).

    /** Today's local date, 'Y-m-d', in the app's time zone. */
    public static function todayLocal(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
    }

    /**
     * The local calendar date of a UTC timestamp: 'Y-m-d H:i:s' or 'Y-m-d H:i:s.v' (as stored
     * in `*_utc` columns) -> 'Y-m-d' in the app's time zone.
     *
     * @throws \InvalidArgumentException on anything else
     */
    public static function localDate(string $utc): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $utc) !== 1) {
            throw new \InvalidArgumentException("Clock::localDate: not a UTC datetime: '$utc'");
        }
        $format = str_contains($utc, '.') ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s';
        $dt = \DateTimeImmutable::createFromFormat('!' . $format, $utc, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException("Clock::localDate: not a UTC datetime: '$utc'");
        }
        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    /** 'Y-m-d' plus $days calendar days (negative subtracts). */
    public static function addDays(string $ymd, int $days): string
    {
        return self::ymd($ymd)->modify(($days >= 0 ? '+' : '-') . abs($days) . ' days')->format('Y-m-d');
    }

    /**
     * 'Y-m-d' plus $months calendar months with an end-of-month clamp (never PHP's overflow):
     * 2026-01-31 +1 => 2026-02-28, 2024-01-31 +1 => 2024-02-29, 2026-03-31 -1 => 2026-02-28.
     */
    public static function addMonths(string $ymd, int $months): string
    {
        $d = self::ymd($ymd);
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n');
        $day = (int) $d->format('j');
        $total = $y * 12 + ($m - 1) + $months;
        $ny = intdiv($total, 12);
        $nm = $total % 12;
        if ($nm < 0) {
            $nm += 12;
            $ny -= 1;
        }
        $nm += 1;
        if ($ny < 1 || $ny > 9999) {
            throw new \InvalidArgumentException('Clock::addMonths: result out of range');
        }
        $last = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $ny, $nm), new \DateTimeZone('UTC')))->format('t');
        return sprintf('%04d-%02d-%02d', $ny, $nm, min($day, $last));
    }

    /** Strict 'Y-m-d': ^\d{4}-\d{2}-\d{2}$ and a real calendar date (checkdate). */
    public static function isYmd(?string $s): bool
    {
        if ($s === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) !== 1) {
            return false;
        }
        return (int) $m[1] >= 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function ymd(string $ymd): \DateTimeImmutable
    {
        if (!self::isYmd($ymd)) {
            throw new \InvalidArgumentException("Clock: not a Y-m-d date: '$ymd'");
        }
        return new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone('UTC'));
    }
}
