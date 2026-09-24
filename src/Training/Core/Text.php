<?php

namespace ITFlow\Training\Core;

/**
 * Text normalisation for SERVER-SOURCED strings on their way into length-limited columns.
 *
 * The database runs STRICT_TRANS_TABLES, so an over-long value throws instead of being
 * truncated, and Canonical refuses invalid UTF-8. A user agent, an uploaded file's original
 * name or an oEmbed title is not something the author typed and cannot be rejected back to
 * them, so it is scrubbed to valid UTF-8 and clipped by characters (never bytes, which could
 * split a multi-byte sequence). Author-typed text goes through ApiContext::str() instead,
 * which rejects rather than clips.
 */
final class Text
{
    public static function clip(?string $s, int $maxChars): ?string
    {
        if ($s === null) {
            return null;
        }
        return mb_substr(mb_scrub($s, 'UTF-8'), 0, max(0, $maxChars), 'UTF-8');
    }
}
