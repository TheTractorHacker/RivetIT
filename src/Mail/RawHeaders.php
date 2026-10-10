<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/** Parses a raw RFC 5322 header block into lower-case name => list of (unfolded, RFC 2047 decoded) values. */
final class RawHeaders
{
    /** @return array<string, string[]> */
    public static function parse(string $raw): array
    {
        // Only the header block: stop at the first blank line when a whole message was passed.
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $block = $parts[0] ?? '';
        $block = preg_replace('/\r?\n[ \t]+/', ' ', $block) ?? $block; // unfold continuation lines

        $headers = [];
        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            if ($name === '' || preg_match('/[^\x21-\x39\x3b-\x7e]/', $name)) {
                continue; // not a header name (e.g. an mbox "From " line)
            }
            $headers[$name][] = trim(substr($line, $pos + 1));
        }
        return $headers;
    }

    public static function first(array $headers, string $name): ?string
    {
        $v = $headers[strtolower($name)][0] ?? null;
        return $v === null ? null : self::decode($v);
    }

    public static function has(array $headers, string $name): bool
    {
        return isset($headers[strtolower($name)]);
    }

    public static function decode(string $value): string
    {
        if (strpos($value, '=?') === false) {
            return $value;
        }
        $decoded = function_exists('iconv_mime_decode') ? @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
        return $decoded === false ? $value : $decoded;
    }
}
