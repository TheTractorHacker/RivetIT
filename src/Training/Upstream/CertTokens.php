<?php

namespace ITFlow\Training\Upstream;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Records\CertIssuer;
use ITFlow\Training\Records\CertSecret;
use ITFlow\Training\Records\CompletionService;

/**
 * The public verify token contract Phase 5 consumes (M2/M3; P2 spec §1.4 #3, frozen): the URL
 * {baseUrl}/verify/?t=<token>, the token format ^[A-Za-z0-9_-]{24}$, lookup by sha256(token).
 * Phase 5 provides nothing Phase 2 must call.
 */
final class CertTokens
{
    public const FORMAT_RE = '/^[A-Za-z0-9_-]{24}$/D';

    /** is_string && exactly 24 chars of [A-Za-z0-9_-]. No database. */
    public static function wellFormed(mixed $t): bool
    {
        return is_string($t) && preg_match(self::FORMAT_RE, $t) === 1;
    }

    /** The P1 §2.5 contract: training_cert_tokens.certtok_token_sha256 = hash('sha256', token). */
    public static function sha(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * S1: the verify URL a certificate PDF prints as its QR code, or null (with an error_log line)
     * when P2 'cert' is unavailable, the certificate key is missing, the completion has no token
     * row (documents), or the re-derived token does not hash to the stored sha (a rotated settings
     * key or a tampered nonce) - so a QR code that would not verify is never printed.
     * The token comes from P2: CompletionService::reprintToken(), else CertIssuer::deriveToken()
     * over the stored nonce; the URL from CertIssuer::verifyUrl($c->baseUrl, $token).
     */
    public static function verifyUrlForCompletion(Ctx $c, int $completionId): ?string
    {
        if ($completionId < 1) {
            return null;
        }
        if (!P2::has($c->db, 'cert')) {
            error_log('Training Upstream\CertTokens: P2 certificates are not available; no QR for #' . $completionId);
            return null;
        }
        try {
            $key = CertSecret::fromGlobals();
        } catch (\Throwable $e) {
            error_log('Training Upstream\CertTokens: certificate key unavailable (' . $e->getMessage() . '); no QR for #' . $completionId);
            return null;
        }
        try {
            $row = Db::one($c->db, 'SELECT certtok_nonce, certtok_token_sha256 FROM training_cert_tokens WHERE certtok_completion_id = ?', 'i', [$completionId]);
            if ($row === null) {
                return null;   // documents and pre-token records have no token: no QR, no log noise
            }
            $token = null;
            if (class_exists(CompletionService::class) && method_exists(CompletionService::class, 'reprintToken')) {
                $token = (new CompletionService($c, $key))->reprintToken($completionId);
            }
            if ($token === null) {
                $token = CertIssuer::deriveToken($key, $completionId, (string) $row['certtok_nonce']);
            }
            if (!self::wellFormed($token) || !hash_equals((string) $row['certtok_token_sha256'], self::sha($token))) {
                error_log('Training Upstream\CertTokens: the re-derived token of #' . $completionId . ' does not match its stored hash (key rotated or row changed); no QR printed');
                return null;
            }
            return CertIssuer::verifyUrl($c->baseUrl, $token);
        } catch (\Throwable $e) {
            error_log('Training Upstream\CertTokens: ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
    }
}
