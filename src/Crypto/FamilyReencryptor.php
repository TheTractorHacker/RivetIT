<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\EnvelopeInterface;
use RivetCore\Crypto\KeyPurpose;
use RivetCore\Crypto\Reencryptor;

/**
 * Brings one column family to the active key: ENC/ENC2 and v3 under an old kid become v3 under the active kid, with the family's context.
 *
 * Cleartext is wrapped only when the column says it may be ($wrapPlaintext); otherwise it is skipped untouched, because a column that
 * still has a raw reader would break. A TOTP seed that is a settings-purpose v3 (written while only the settings stage was on) is opened
 * as such and sealed as a totp-purpose value.
 */
final class FamilyReencryptor implements Reencryptor
{
    public function __construct(
        private EnvelopeInterface $envelope,
        private bool $wrapPlaintext,
        private ?EnvelopeInterface $fallback = null,
        private ?string $fallbackContext = null,
    ) {
    }

    public function reencrypt(string $stored, string $context): ?string
    {
        if ($stored === '') {
            return null;
        }
        $label = $this->envelope->label($stored);
        if ($label === 'legacy:plaintext' && !$this->wrapPlaintext) {
            return null;
        }
        if ($this->fallback !== null && Envelope::isV3($stored)) {
            try {
                $this->envelope->open($stored, $context);
            } catch (DecryptionFailed) {
                // not ours: a settings-purpose v3 value in a column that now has its own purpose
                return $this->envelope->seal($this->fallback->open($stored, (string) $this->fallbackContext), $context);
            }
        }
        if (!$this->envelope->needsRewrap($stored)) {
            return null;
        }

        return $this->envelope->rewrap($stored, $context);
    }

    /** The reencryptor for a column on the current key state. */
    public static function forSpec(ColumnSpec $spec): self
    {
        $vault = SettingsCrypto::vault($spec->purpose());
        if ($spec->kind === 'totp') {
            return new self($vault->envelope(), $spec->wrapPlaintext, SettingsCrypto::vault(KeyPurpose::SETTINGS)->envelope(), SettingsCrypto::contextFor(KeyPurpose::SETTINGS, SettingsCrypto::GENERIC));
        }

        return new self($vault->envelope(), $spec->wrapPlaintext);
    }
}
