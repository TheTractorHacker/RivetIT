<?php

declare(strict_types=1);

namespace ITFlow\Security;

/**
 * Normalises text that comes from an external directory (Entra, Google, Odoo, Intune) into a plain display string
 * BEFORE it is stored. Those values (a user's displayName, a department name, a device name) are rendered in many
 * places, some of which do not escape; stripping tags at store time keeps markup out of the database entirely
 * (defence in depth - output escaping is still required everywhere).
 */
final class PlainText
{
    public static function clean(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $s = (string) $value;
        // Drop NUL / control characters other than tab and newline, then any tags (strip_tags also removes an unterminated "<img ...").
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        $s = strip_tags($s);
        // A stripped name that still contains a bare angle bracket ("a < b") is harmless as text, but never allow one that could
        // reopen a tag once concatenated by a renderer.
        $s = str_replace(['<', '>'], '', $s);
        return trim($s);
    }
}
