<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashedInsert;

/**
 * Certificate numbers and public verify tokens (plan A5; Phase 2 spec §1.4 #2-#3, §3.5).
 *
 * NUMBERS. LMS-YYYY-NNNNNN, where YYYY is the LOCAL date the record is issued (recorded), not
 * the training date, and NNNNNN is a per-year sequence from training_cert_counters. The row
 * for the year is bumped with INSERT ... ON DUPLICATE KEY UPDATE and re-read FOR UPDATE inside
 * the issuing transaction, so a rollback leaves no gap and a voided number is never reused.
 * The caller must hold the records mutex (row certctr_year = 0 of the same table), which is
 * what serializes every issuer; year 0 itself is never a certificate year.
 *
 * TOKENS. One insert-only, row-hashed training_cert_tokens row per training-kind completion:
 *   token = first 24 chars of base64url(HMAC-SHA256("cert|v1|{completionId}|{nonce}", certKey))   (144 bits)
 *   stored: the nonce and sha256(token) - never the token. Reprints re-derive the same token
 *   from the stored nonce. Tokens never appear in ledger payloads or logs.
 *   Frozen verify URL: {baseUrl}/verify/?t={token} (Phase 5 builds the page on PublicVerify).
 */
final class CertIssuer
{
    public const TOKEN_RE = '/^[A-Za-z0-9_-]{24}$/D';
    public const NUMBER_RE = '/^LMS-[0-9]{4}-[0-9]{6}$/D';

    /**
     * The next certificate number for the year of $localDate ('Y-m-d', local). The caller holds
     * the records mutex inside its Db::tx.
     */
    public static function nextNumber(\mysqli $db, string $localDate): string
    {
        if (Db::depth() < 1) {
            throw new \LogicException('CertIssuer::nextNumber must run inside Db::tx (holding the records mutex)');
        }
        if (!Clock::isYmd($localDate)) {
            throw new \InvalidArgumentException("CertIssuer::nextNumber: not a date: '$localDate'");
        }
        $year = (int) substr($localDate, 0, 4);
        if ($year < 1990 || $year > 9999) {
            throw new \InvalidArgumentException('CertIssuer::nextNumber: year out of range');
        }
        Db::exec($db,
            'INSERT INTO training_cert_counters (certctr_year, certctr_last_seq, certctr_updated_at_utc) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE certctr_last_seq = certctr_last_seq + 1, certctr_updated_at_utc = VALUES(certctr_updated_at_utc)',
            'is', [$year, Clock::nowUtc()]);
        $row = Db::one($db, 'SELECT certctr_last_seq FROM training_cert_counters WHERE certctr_year = ? FOR UPDATE', 'i', [$year]);
        $seq = (int) ($row['certctr_last_seq'] ?? 0);
        if ($seq < 1 || $seq > 999999) {
            throw new \RuntimeException('CertIssuer: certificate sequence out of range for ' . $year);
        }
        return sprintf('LMS-%04d-%06d', $year, $seq);
    }

    /**
     * Writes the completion's token row (HashedInsert) and returns the token and its ledger event
     * (appended LAST by the caller, after its other writes).
     *
     * @return array{token:string, certtok_id:int, sha:string, token_sha256:string, event:array}
     */
    public static function issueToken(\mysqli $db, int $completionId, string $certKey, ?int $subjectContactId = null, ?int $courseId = null, array $actor = []): array
    {
        if ($completionId < 1) {
            throw new \InvalidArgumentException('CertIssuer::issueToken: bad completion id');
        }
        $nonce = bin2hex(random_bytes(16));
        $token = self::deriveToken($certKey, $completionId, $nonce);
        $tokenSha = hash('sha256', $token);
        $ins = HashedInsert::insert($db, 'training_cert_tokens', [
            'certtok_completion_id' => (string) $completionId,
            'certtok_nonce' => $nonce,
            'certtok_token_sha256' => $tokenSha,
            'certtok_created_at_utc' => Clock::nowUtc(),
            'certtok_hash_v' => '1',
        ]);
        $event = $actor + [
            'type' => 'cert.token_issued',
            'subject_contact_id' => $subjectContactId,
            'course_id' => $courseId,
            'entity_type' => 'cert_token',
            'entity_id' => $ins['id'],
            'entity_sha256' => $ins['sha'],
            'payload' => ['completion_id' => $completionId],
        ];
        return ['token' => $token, 'certtok_id' => $ins['id'], 'sha' => $ins['sha'], 'token_sha256' => $tokenSha, 'event' => $event];
    }

    /** §1.4 #3, exactly. $certKey is the raw 32-byte key from CertSecret. */
    public static function deriveToken(string $certKey, int $completionId, string $nonce): string
    {
        if ($certKey === '') {
            throw new \RuntimeException('cert_key_missing');
        }
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', "cert|v1|{$completionId}|{$nonce}", $certKey, true)), '+/', '-_'), '='), 0, 24);
    }

    /** Frozen: {baseUrl}/verify/?t={token}. $baseUrl is Ctx::$baseUrl ('https://host', no trailing slash). */
    public static function verifyUrl(string $baseUrl, string $token): string
    {
        return rtrim($baseUrl, '/') . '/verify/?t=' . $token;
    }

    /**
     * The token of an issued certificate, re-derived from its stored nonce, or null when the
     * completion has none (document kind) or the key no longer derives the stored token
     * (the settings key was rotated: printed QR codes still verify, reprints cannot).
     */
    public static function reprint(\mysqli $db, int $completionId, string $certKey): ?string
    {
        $row = Db::one($db, 'SELECT certtok_nonce, certtok_token_sha256 FROM training_cert_tokens WHERE certtok_completion_id = ?', 'i', [$completionId]);
        if ($row === null) {
            return null;
        }
        $token = self::deriveToken($certKey, $completionId, (string) $row['certtok_nonce']);
        return hash_equals((string) $row['certtok_token_sha256'], hash('sha256', $token)) ? $token : null;
    }
}
