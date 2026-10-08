<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\SecretBoxInterface;

/**
 * RivetIT's settings encryption (encryptSetting / decryptSetting in functions.php, key $config_settings_enc_key) for the RMM module's
 * Ed25519 signing key and MeshCentral login key. The ciphertext format ("ENC2:" AES-256-GCM, legacy "ENC:" read-only) is the application's and
 * is deliberately untouched, so rows written by the old ITFlow\EndpointAgent code keep decrypting.
 */
final class EndpointSecretBox implements SecretBoxInterface
{
    public function encrypt(string $plaintext): string
    {
        return encryptSetting($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        try {
            return decryptSetting($ciphertext);
        } catch (\Throwable) {
            return '';
        }
    }
}
