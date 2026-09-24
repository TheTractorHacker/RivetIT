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
}
