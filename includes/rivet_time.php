<?php

/*
 * One place that turns a stored timestamp into the COMPANY's time for display (never "UTC" unless the company zone is UTC).
 *
 * Storage zones in this app (see docs/TIMESTAMP_DISPLAY.md for the per-column table):
 *   'local' - written with the app's NOW()/date() under the company zone (SET time_zone in includes/inc_set_timezone.php). Shown as is.
 *   'utc'   - UTC_TIMESTAMP(), gmdate(), RFC 3339 'Z' strings from RivetCore read models. Converted.
 *   'epoch' - Unix seconds (int or numeric string). Converted.
 * A value with an explicit offset or 'Z' is always read as that instant, whatever $storedAs says.
 */

/** The company's time zone name: the session value, else the settings row, else PHP's default (the server's), else UTC. */
function rivetCompanyTimezone(): string
{
    if (!empty($GLOBALS['rivet_company_tz_override'])) {
        return (string) $GLOBALS['rivet_company_tz_override'];
    }
    $tz = $_SESSION['session_timezone'] ?? '';
    if (!is_string($tz) || $tz === '') {
        $tz = (string) date_default_timezone_get();
    }
    try {
        new DateTimeZone($tz);
    } catch (Throwable $e) {
        $tz = 'UTC';
    }

    return $tz;
}

/** Parse a stored value to a UTC DateTimeImmutable, or null for empty/zero/invalid. */
function rivetTimeParse($value, string $storedAs = 'utc', ?string $companyTz = null): ?DateTimeImmutable
{
    if ($value === null || $value === false || $value === '') {
        return null;
    }
    try {
        if ($storedAs === 'epoch' || is_int($value)) {
            if (!is_numeric($value) || (float) $value <= 0) {
                return null;
            }

            return (new DateTimeImmutable('@' . (int) $value));
        }
        $s = trim((string) $value);
        if ($s === '' || str_starts_with($s, '0000-00-00')) {
            return null;
        }
        $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $s);
        if ($hasZone) {
            return new DateTimeImmutable($s);
        }
        $zone = new DateTimeZone($storedAs === 'local' ? ($companyTz ?? rivetCompanyTimezone()) : 'UTC');

        return (new DateTimeImmutable($s, $zone))->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Plain text (escape it in the caller): "2026-10-18 12:16 CDT". $withZone false drops the label; $format is a date() format.
 * Empty/zero/invalid input gives ''. For 'local' input DST-gap/overlap times resolve the way PHP resolves them (first occurrence).
 */
function rivetFormatTime($value, string $storedAs = 'utc', bool $withZone = true, string $format = 'Y-m-d H:i', ?string $companyTz = null): string
{
    try {
        new DateTimeZone((string) $companyTz);
    } catch (Throwable $e) {
        $companyTz = rivetCompanyTimezone();
    }
    $dt = rivetTimeParse($value, $storedAs, $companyTz);
    if ($dt === null) {
        return '';
    }
    $out = $dt->setTimezone(new DateTimeZone($companyTz));
    $label = $out->format('T');
    if (preg_match('/^[+-]\d/', $label)) {   // zones without an abbreviation (e.g. Asia/Dubai): "+04"
        $label = 'UTC' . $out->format('P');
    }

    return $out->format($format) . ($withZone ? ' ' . $label : '');
}

/** The ISO 8601 instant ("2026-10-18T17:16:53Z") for a <time datetime> attribute, or ''. */
function rivetTimeIso($value, string $storedAs = 'utc', ?string $companyTz = null): string
{
    $dt = rivetTimeParse($value, $storedAs, $companyTz);

    return $dt === null ? '' : $dt->format('Y-m-d\TH:i:s\Z');
}

/** Epoch seconds, or null. */
function rivetTimeEpoch($value, string $storedAs = 'utc', ?string $companyTz = null): ?int
{
    $dt = rivetTimeParse($value, $storedAs, $companyTz);

    return $dt === null ? null : $dt->getTimestamp();
}

/** The company's zone abbreviation right now ("CDT"), e.g. for a tooltip or a note under a table. */
function rivetTimeZoneLabel(?string $companyTz = null): string
{
    return substr(rivetFormatTime(time(), 'epoch', true, 'Y', $companyTz), 5);
}
