<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

use RivetCore\Crypto\CryptoException;
use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\KeyPurpose;
use RivetCore\Crypto\LegacyReaders;
use RivetCore\Crypto\SettingsVault;

/**
 * The boundary between encryptSetting()/decryptSetting()/TOTP seeds (functions.php, includes/security_crypto.php) and RivetCore\Crypto
 * (ADR-011). Callers keep their old signatures and their old failure behaviour:
 *
 *   encrypt  - no key: RuntimeException, exactly as the old function threw. '' stays ''.
 *   decrypt  - a value that cannot be read gives '' (logged once per request, never the value), exactly as the old function returned.
 *              Legacy unprefixed cleartext is still handed back as it is.
 *
 * Writes are v3 (`v3:<kid>:...`, AES-256-GCM, the context bound as AAD) once the settings stage is on, else the old ENC2 form. The stage is
 * on when a key file exists (the operator's explicit upgrade step) or when config.php says `$config_crypto_v3_settings = true`;
 * `= false` switches it off again (new writes are ENC2 again; v3 values already stored stay readable). The TOTP stage has its own flag,
 * `$config_crypto_v3_totp`, defaulting to the settings stage. Reads never depend on a flag: they accept v3, ENC2, ENC and cleartext.
 *
 * Contexts (frozen: changing one makes stored data unreadable):
 *   settings family   purpose "settings", name {@see self::GENERIC}   every value written through encryptSetting($v)
 *   canonical vault   purpose "settings", name "settings.vault_canonical_key"
 *   TOTP seed         purpose "totp",     name "user:<users.user_id>"
 *   instance DEK wrap purpose "settings", name {@see self::VAULT_DEK}
 * The AAD of the settings family is one shared name because the 90-odd call sites do not pass the column; binding each column is the
 * follow-up that needs them to. A value cannot be moved to a different PURPOSE or to a context above that has its own name.
 */
final class SettingsCrypto
{
    public const GENERIC = 'generic';
    public const VAULT_CANONICAL_KEY = 'settings.vault_canonical_key';
    public const VAULT_DEK = 'settings.vault_dek';

    /** @var array<string, SettingsVault> */
    private static array $vaults = [];
    private static string $vaultSig = '';
    /** @var array<string, true> */
    private static array $reported = [];

    public static function v3Enabled(): bool
    {
        $f = $GLOBALS['config_crypto_v3_settings'] ?? null;
        if ($f !== null) {
            return (bool) $f;
        }

        return KeyStore::load()->fromFile();
    }

    public static function totpV3Enabled(): bool
    {
        $f = $GLOBALS['config_crypto_v3_totp'] ?? null;
        if ($f !== null) {
            return (bool) $f;
        }

        return self::v3Enabled();
    }

    /** The shared vault for a purpose (settings, totp, ...), built from the current key state and cached until that changes. */
    public static function vault(string $purpose = KeyPurpose::SETTINGS): SettingsVault
    {
        $state = KeyStore::load();
        $legacy = KeyStore::legacyKey();
        $sig = implode(',', $state->ring->kids()) . '|' . (string) $state->ring->activeKid() . '|' . $state->source . '|' . hash('sha256', $legacy) . '|' . ($state->ring->isEmpty() ? '' : hash('sha256', $state->ring->activeKey()));
        if ($sig !== self::$vaultSig) {
            self::$vaults = [];
            self::$vaultSig = $sig;
        }
        if (!isset(self::$vaults[$purpose])) {
            $readers = $legacy !== '' ? LegacyReaders::settings($legacy) : [];
            // Cleartext columns (stored before encryption existed) stay readable; the plaintext reader refuses every known prefix.
            self::$vaults[$purpose] = new SettingsVault($state->ring, array_merge($readers, LegacyReaders::plaintext()), $purpose);
        }

        return self::$vaults[$purpose];
    }

    /** The full AAD string of a purpose and name, for rewrap sources (SettingsVault builds the same). */
    public static function contextFor(string $purpose, string $name): string
    {
        return $purpose . '|' . $name;
    }

    public static function encrypt(#[\SensitiveParameter] string $plaintext, string $name = self::GENERIC): string
    {
        if ($plaintext === '') {
            return $plaintext;
        }
        if (!self::v3Enabled()) {
            return self::legacyEncrypt($plaintext);
        }

        return self::seal(KeyPurpose::SETTINGS, $name, $plaintext);
    }

    /**
     * @param (callable(string $new, string $previous): void)|null $persist called after a successful read of a legacy or old-key value with its
     *        v3 replacement, only while the settings stage is on; `UPDATE t SET col = :new WHERE col = :previous`
     */
    public static function decrypt(string $stored, string $name = self::GENERIC, ?callable $persist = null): string
    {
        if ($stored === '') {
            return $stored;
        }
        try {
            $read = self::vault(KeyPurpose::SETTINGS)->read($name, $stored);
        } catch (CryptoException $e) {
            self::report($e, $stored);

            return '';
        }
        if ($persist !== null && $read->rewrapped !== null && $read->format !== 'legacy:plaintext' && self::v3Enabled()) {
            try {
                $persist($read->rewrapped, $stored);
            } catch (\Throwable $e) {
                self::report($e, $stored);
            }
        }

        return $read->plaintext;
    }

    /** The v3 form of a stored settings value (any readable format), or null when it already is current, is cleartext, or cannot be read. */
    public static function rewrapped(string $stored, string $name = self::GENERIC): ?string
    {
        if ($stored === '' || !self::v3Enabled()) {
            return null;
        }
        try {
            $read = self::vault(KeyPurpose::SETTINGS)->read($name, $stored);
        } catch (CryptoException) {
            return null;
        }

        return $read->format === 'legacy:plaintext' ? null : $read->rewrapped;
    }

    public static function encryptTotp(#[\SensitiveParameter] string $seed, int $userId): string
    {
        if ($seed === '') {
            return $seed;
        }
        if (!self::totpV3Enabled()) {
            return self::encrypt($seed);
        }

        return self::seal(KeyPurpose::TOTP, self::totpName($userId), $seed);
    }

    /** A stored TOTP seed in any format: v3 (totp purpose, or a settings-purpose v3 written while the TOTP stage was off), ENC2/ENC, cleartext. */
    public static function decryptTotp(string $stored, int $userId): string
    {
        if ($stored === '') {
            return $stored;
        }
        try {
            return self::readTotp($stored, $userId)->plaintext;
        } catch (CryptoException $e) {
            self::report($e, $stored);

            return '';
        }
    }

    /** The current-form replacement of a TOTP seed (cleartext included: a seed must not stay in cleartext), or null when nothing is to do. */
    public static function rewrapTotp(string $stored, int $userId): ?string
    {
        if ($stored === '' || !self::totpV3Enabled()) {
            return null;
        }
        try {
            return self::readTotp($stored, $userId)->rewrapped;
        } catch (CryptoException) {
            return null;
        }
    }

    public static function totpName(int $userId): string
    {
        return 'user:' . $userId;
    }

    private static function readTotp(string $stored, int $userId): \RivetCore\Crypto\SettingRead
    {
        $totp = self::vault(KeyPurpose::TOTP);
        if (Envelope::isV3($stored)) {
            try {
                return $totp->read(self::totpName($userId), $stored);
            } catch (DecryptionFailed $e) {
                // A settings-purpose v3 seed (written while only the settings stage was on). Reading it as such is fine; it is re-sealed as totp.
                $generic = self::vault(KeyPurpose::SETTINGS)->read(self::GENERIC, $stored);

                return new \RivetCore\Crypto\SettingRead($generic->plaintext, $totp->encrypt(self::totpName($userId), $generic->plaintext), $generic->format);
            }
        }

        return $totp->read(self::totpName($userId), $stored);
    }

    private static function seal(string $purpose, string $name, #[\SensitiveParameter] string $plaintext): string
    {
        $state = KeyStore::load();
        if ($state->error !== null) {
            throw new \RuntimeException('Refusing to store a secret: ' . $state->error);
        }
        if (!$state->ring->hasActive()) {
            throw new \RuntimeException(
                'Refusing to store a secret in cleartext: there is no encryption key. Create the key file (php scripts/keys_cli.php generate) ' .
                'or add $config_settings_enc_key = bin2hex(random_bytes(32)); to config.php and re-run the database update.'
            );
        }
        try {
            return self::vault($purpose)->encrypt($name, $plaintext);
        } catch (CryptoException $e) {
            throw new \RuntimeException('Refusing to store a secret in cleartext: ' . $e->getMessage(), 0, $e);
        }
    }

    /** The pre-ADR-011 writer, kept byte for byte: ENC2:base64(nonce12 . tag16 . ct), AES-256-GCM under sha256($config_settings_enc_key). */
    private static function legacyEncrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $legacy = KeyStore::legacyKey();
        if ($legacy === '') {
            throw new \RuntimeException(
                'Refusing to store a secret in cleartext: $config_settings_enc_key is missing from config.php. ' .
                'Add  $config_settings_enc_key = bin2hex(random_bytes(32));  to config.php and re-run the database update.'
            );
        }
        $key = hash('sha256', $legacy, true);
        $nonce = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Refusing to store a secret in cleartext: openssl_encrypt() failed.');
        }

        return 'ENC2:' . base64_encode($nonce . $tag . $ct);
    }

    /** Log a failure once per request and reason, class and format only: never a value, a key or a message that could carry one. */
    private static function report(\Throwable $e, string $stored): void
    {
        $fmt = Envelope::isV3($stored) ? 'v3' : (str_starts_with($stored, 'ENC') ? strstr($stored, ':', true) : 'other');
        $key = get_class($e) . '|' . $fmt;
        if (isset(self::$reported[$key])) {
            return;
        }
        self::$reported[$key] = true;
        $msg = 'Settings crypto: a ' . $fmt . ' value could not be read (' . substr(strrchr('\\' . get_class($e), '\\'), 1) . '); it was treated as empty.';
        error_log($msg);
        if (function_exists('logApp') && isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof \mysqli) {
            try {
                logApp('Security', 'error', $msg);
            } catch (\Throwable) {
                // the application log is best effort here
            }
        }
    }

    /** Test seam: forget cached vaults and the once-per-request log memory. */
    public static function reset(): void
    {
        self::$vaults = [];
        self::$vaultSig = '';
        self::$reported = [];
        KeyStore::reset();
    }
}
