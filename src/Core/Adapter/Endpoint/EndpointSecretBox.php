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
    /**
     * @param (\Closure(string):string)|null $encrypt defaults to encryptSetting(); a seam for the conformance test
     * @param (\Closure(string):string)|null $decrypt defaults to decryptSetting()
     */
    public function __construct(private ?\Closure $encrypt = null, private ?\Closure $decrypt = null)
    {
    }

    public function encrypt(string $plaintext): string
    {
        return $this->encrypt !== null ? ($this->encrypt)($plaintext) : encryptSetting($plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        // decryptSetting() hands legacy unprefixed text back as it is (settings written before encryption existed). A signing or login key must never be
        // taken from text that is not a ciphertext, so anything without a known prefix is "not available" here, as the contract says.
        if (!str_starts_with($ciphertext, 'ENC2:') && !str_starts_with($ciphertext, 'ENC:')) {
            return '';
        }
        try {
            return $this->decrypt !== null ? ($this->decrypt)($ciphertext) : decryptSetting($ciphertext);
        } catch (\Throwable) {
            return '';
        }
    }
}
