<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Media\ArticleSanitizer;

/**
 * Validation of one `fields{}` patch value at a time (the keys were already allowlisted by
 * ApiContext::fields()). Every failure is a 422 `validation` naming the field, so the client
 * can mark the exact input.
 *
 * Nullable text: null or an all-whitespace string clears the column (stored as NULL). Strings
 * must be valid UTF-8 and are measured in characters (the columns are varchar(N) characters),
 * matching ApiContext::str().
 */
final class Patch
{
    public const COLOR_RE = '/^#[0-9A-Fa-f]{6}$/D';

    /** Loose equality between a stored column value and a normalised patch value. */
    public static function same(mixed $stored, mixed $new): bool
    {
        if ($stored === null || $new === null) {
            return $stored === null && $new === null;
        }
        if (is_bool($new)) {
            return ((int) $stored === 1) === $new;
        }
        return (string) $stored === (string) $new;
    }

    /** Text (trimmed). null/'' => null unless $required, which makes it a 422. */
    public static function text(array $f, string $k, int $maxChars, bool $required = false): ?string
    {
        $v = $f[$k] ?? null;
        if ($v === null) {
            if ($required) {
                throw ApiException::validation([$k => 'Required.']);
            }
            return null;
        }
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            throw ApiException::validation([$k => 'Must be text.']);
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            throw ApiException::validation([$k => 'Contains characters that could not be read. Retype it and try again.']);
        }
        $v = trim($v);
        if ($v === '') {
            if ($required) {
                throw ApiException::validation([$k => 'Required.']);
            }
            return null;
        }
        if (mb_strlen($v, 'UTF-8') > $maxChars) {
            throw ApiException::validation([$k => "Too long (at most $maxChars characters)."]);
        }
        return $v;
    }

    /**
     * Raw HTML string (not trimmed of inner content). null/'' => null. Capped at
     * ArticleSanitizer::MAX_HTML_BYTES by default - the one limit every import also enforces,
     * so whatever is imported can be saved again and projected (sanitiser memory grows with size).
     */
    public static function html(array $f, string $k, int $maxBytes = ArticleSanitizer::MAX_HTML_BYTES): ?string
    {
        $v = $f[$k] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw ApiException::validation([$k => 'Must be text.']);
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            throw ApiException::validation([$k => 'Contains characters that could not be read. Retype it and try again.']);
        }
        if (strlen($v) > $maxBytes) {
            throw ApiException::validation([$k => 'This content is too long to save (at most ' . intdiv($maxBytes, 1024)
                . ' KB of text and formatting). Split it into more than one lesson.']);
        }
        return trim($v) === '' ? null : $v;
    }

    /** Integer in [$min, $max]; null allowed only when $nullable. */
    public static function int(array $f, string $k, int $min, int $max, bool $nullable = true): ?int
    {
        $v = $f[$k] ?? null;
        if ($v === null || $v === '') {
            if ($nullable) {
                return null;
            }
            throw ApiException::validation([$k => 'Required.']);
        }
        if (is_string($v) && preg_match('/^-?[0-9]{1,18}$/D', trim($v)) === 1) {
            $v = (int) trim($v);
        }
        if (!is_int($v)) {
            throw ApiException::validation([$k => 'Must be a whole number.']);
        }
        if ($v < $min || $v > $max) {
            throw ApiException::validation([$k => "Must be between $min and $max."]);
        }
        return $v;
    }

    /** An id (>= 1) or null. */
    public static function id(array $f, string $k): ?int
    {
        return self::int($f, $k, 1, 2147483647, true);
    }

    public static function bool(array $f, string $k): bool
    {
        $v = $f[$k] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === '1' || $v === 'true' || $v === 'on') {
            return true;
        }
        if ($v === 0 || $v === '0' || $v === 'false' || $v === 'off') {
            return false;
        }
        throw ApiException::validation([$k => 'Must be true or false.']);
    }

    /** '#RRGGBB' (normalised to upper case) or null. */
    public static function color(array $f, string $k, bool $nullable = true): ?string
    {
        $v = $f[$k] ?? null;
        if ($v === null || $v === '') {
            if ($nullable) {
                return null;
            }
            throw ApiException::validation([$k => 'Choose a color.']);
        }
        if (!is_string($v) || preg_match(self::COLOR_RE, $v) !== 1) {
            throw ApiException::validation([$k => 'Use a color like #0D9488.']);
        }
        return strtoupper($v);
    }

    /**
     * A list of tag names: trimmed, 1..60 characters, duplicates (case-insensitive) dropped.
     *
     * @return list<string>
     */
    public static function tagNames(mixed $v, string $k = 'tags', int $max = 20): array
    {
        if ($v === null) {
            return [];
        }
        if (!is_array($v) || !array_is_list($v)) {
            throw ApiException::validation([$k => 'Must be a list of tag names.']);
        }
        $out = [];
        $seen = [];
        foreach ($v as $name) {
            if (!is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
                throw ApiException::validation([$k => 'Tag names must be text.']);
            }
            $name = preg_replace('/\s+/u', ' ', trim($name));
            if ($name === '' || $name === null) {
                continue;
            }
            if (mb_strlen($name, 'UTF-8') > 60) {
                throw ApiException::validation([$k => 'A tag can be at most 60 characters.']);
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $name;
        }
        if (count($out) > $max) {
            throw ApiException::validation([$k => "At most $max tags."]);
        }
        return $out;
    }

    /** Escapes LIKE wildcards in a user search term. */
    public static function like(string $q): string
    {
        return '%' . addcslashes($q, '\\%_') . '%';
    }
}
