<?php

namespace ITFlow\Training\Core;

/**
 * Stable public identifiers for training entities (spec §0 "UIDs").
 *
 * One type letter + 11 characters of a 32-symbol lowercase alphabet with no i, l, o or u
 * (nothing to misread when a uid is read aloud or typed from a printout). Because every uid
 * starts with a letter it is never a numeric PHP array key, which is what lets revision JSON
 * maps be keyed by uid and still round-trip through Canonical::doc().
 */
final class Uid
{
    public const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    /** c course, s section, l lesson, r resource, b bank, q question, o option, z quiz, u rule, p path, a achievement */
    public const PREFIXES = ['c', 's', 'l', 'r', 'b', 'q', 'o', 'z', 'u', 'p', 'a'];

    public const REGEX = '/^[a-z][0-9abcdefghjkmnpqrstvwxyz]{11}$/';

    public static function new(string $prefix): string
    {
        if (!in_array($prefix, self::PREFIXES, true)) {
            throw new \InvalidArgumentException("Unknown uid prefix '$prefix'");
        }
        $bytes = random_bytes(11);
        $uid = $prefix;
        for ($i = 0; $i < 11; $i++) {
            $uid .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return $uid;
    }

    public static function valid(string $uid, ?string $prefix = null): bool
    {
        if (preg_match(self::REGEX, $uid) !== 1) {
            return false;
        }
        return $prefix === null || $uid[0] === $prefix;
    }
}
