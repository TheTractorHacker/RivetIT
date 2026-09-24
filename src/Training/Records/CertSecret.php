<?php

namespace ITFlow\Training\Records;

/**
 * The certificate key that derives public verify tokens (Phase 2 spec §1.4 #3, frozen):
 *
 *   certKey = hash_hmac('sha256', 'itflow-training-cert-key|v1', $config_settings_enc_key, true)   // raw 32 bytes
 *
 * This is the ONLY place training code reads $config_settings_enc_key (the boundary, §8
 * "Secrets"); services receive the derived key as a string and never see the settings key.
 * It fails closed: a missing key is \RuntimeException('cert_key_missing'), never an empty or
 * default key, because a token derived from a guessable key could be forged.
 *
 * Rotating $config_settings_enc_key breaks REPRINTS of existing certificates (the stored
 * sha256 still verifies printed QR codes); key rotation must never be routine (R6).
 */
final class CertSecret
{
    public const LABEL = 'itflow-training-cert-key|v1';

    /** The raw 32-byte certKey from config.php's $config_settings_enc_key. */
    public static function fromGlobals(): string
    {
        $key = $GLOBALS['config_settings_enc_key'] ?? null;
        if (!is_string($key) || $key === '') {
            throw new \RuntimeException('cert_key_missing');
        }
        return self::derive($key);
    }

    /** certKey for a given settings key (tests and fromGlobals()). */
    public static function derive(string $settingsEncKey): string
    {
        if ($settingsEncKey === '') {
            throw new \RuntimeException('cert_key_missing');
        }
        return hash_hmac('sha256', self::LABEL, $settingsEncKey, true);
    }
}
