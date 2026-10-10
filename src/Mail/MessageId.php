<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * RFC 5322 Message-ID helpers. Ids are stored normalised (lower case, no angle brackets, max 255 bytes) so a
 * lookup is a plain indexed equality, whatever bracket/case/whitespace style the sending server used.
 */
final class MessageId
{
    public const MAX_LENGTH = 255;

    /** Normalise one id ("<Abc@Host>" -> "abc@host"); '' when there is nothing usable. */
    public static function normalize(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $raw = trim($raw);
        if (preg_match('/<([^<>\s]+)>/', $raw, $m)) {
            $raw = $m[1];
        }
        $raw = strtolower(trim($raw, " \t\r\n<>"));
        if ($raw === '' || preg_match('/[\s<>]/', $raw)) {
            return '';
        }
        return substr($raw, 0, self::MAX_LENGTH);
    }

    /** Every id in an In-Reply-To / References header value, normalised, in order, without duplicates. */
    public static function parseList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $ids = [];
        if (preg_match_all('/<([^<>\s]+)>/', $value, $m)) {
            foreach ($m[1] as $id) {
                $n = self::normalize($id);
                if ($n !== '') {
                    $ids[$n] = true;
                }
            }
            return array_keys($ids);
        }
        // Tolerate servers that omit the angle brackets: whitespace separated tokens containing an @.
        foreach (preg_split('/[\s,]+/', trim($value)) ?: [] as $token) {
            if (strpos($token, '@') !== false) {
                $n = self::normalize($token);
                if ($n !== '') {
                    $ids[$n] = true;
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * The ids to try when threading a reply, most specific first: In-Reply-To, then References newest to oldest
     * (the last id in References is the direct parent).
     */
    public static function threadCandidates(?string $inReplyTo, ?string $references): array
    {
        $out = [];
        foreach (self::parseList($inReplyTo) as $id) {
            $out[$id] = true;
        }
        foreach (array_reverse(self::parseList($references)) as $id) {
            $out[$id] = true;
        }
        return array_keys($out);
    }

    /** A fresh outbound id on the sender's domain: <rivet.<random>@domain>. Returned normalised (no brackets). */
    public static function generate(string $fromEmail): string
    {
        $at = strrpos($fromEmail, '@');
        $domain = $at !== false ? strtolower(trim(substr($fromEmail, $at + 1))) : '';
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain)) {
            $domain = 'rivetit.invalid';
        }
        return 'rivet.' . bin2hex(random_bytes(12)) . '@' . $domain;
    }

    /** Stable stand-in id for mail that arrived without a Message-ID, so dedupe still works (same sender, date, subject, body). */
    public static function synthetic(string $fromEmail, string $date, string $subject, string $body): string
    {
        return 'noid-' . sha1(strtolower($fromEmail) . '|' . $date . '|' . $subject . '|' . $body) . '@rivetit.local';
    }

    /** Wire format for a header or PHPMailer->MessageID. */
    public static function bracket(string $normalized): string
    {
        return '<' . $normalized . '>';
    }
}
