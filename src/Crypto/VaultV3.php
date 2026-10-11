<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

use RivetCore\Crypto\CryptoException;
use RivetCore\Crypto\DecryptionFailed;
use RivetCore\Crypto\Envelope;
use RivetCore\Crypto\KdfParams;
use RivetCore\Crypto\KeyRing;
use RivetCore\Crypto\LegacyOtpReader;
use RivetCore\Crypto\Reencryptor;
use RivetCore\Crypto\VaultCipher;
use RivetCore\Crypto\VaultFieldReencryptor;
use RivetCore\Crypto\VaultKeyWrap;

/**
 * The credential vault on the v3 format (ADR-011), behind the setting settings.config_vault_v3_enabled (default 0, never flipped by an
 * update). With the flag off nothing here changes behaviour: the legacy functions in functions.php run exactly as before.
 *
 * Keys:
 *   DEK        32 random bytes, the key of every credential field (AAD credential:<id>:<field>). Stored once, wrapped by the key file
 *              (settings.config_vault_dek_wrap, context "vault"): the recovery copy and the source for users not yet migrated.
 *   user wrap  vw3:... in users.user_specific_encryption_ciphertext (Argon2id), context user:<id>; created at the user's next login
 *              from a verified legacy wrap, rewrapped (never re-derived from credentials) on a password change.
 *   legacy     the old 16-character master key. Kept in the canonical key row while any legacy field remains; the session holds it
 *              beside the DEK so a field that is not converted yet still opens.
 *
 * Field contexts (frozen): credentials username / password / otp; credential_versions "version-<version_id>" previous_username /
 * previous_password. The mobile token wraps the DEK (api_tokens.token_enc_dek) with a key derived from the raw token, next to the old
 * master key wrap. Share links keep their own per-link key (key in the URL fragment) and are unaffected.
 */
final class VaultV3
{
    public const WRAP_CONTEXT = 'vault';
    private static ?VaultKeyWrap $wrap = null;
    /** @var array<string, mixed> */
    private static array $req = [];

    public static function reset(): void
    {
        self::$req = [];
        self::$wrap = null;
    }

    public static function wrap(): VaultKeyWrap
    {
        if (self::$wrap === null) {
            $m = $GLOBALS['config_vault_argon_memory_kib'] ?? null;
            $o = $GLOBALS['config_vault_argon_ops'] ?? null;
            self::$wrap = new VaultKeyWrap(is_int($m) && is_int($o) && KdfParams::argon2idAvailable() ? KdfParams::argon2id($m, $o) : null);
        }

        return self::$wrap;
    }

    public static function userContext(int $userId): string
    {
        return 'user:' . $userId;
    }

    /** Flag on, DEK wrap stored, key ring usable. Any problem means "off": the legacy path keeps working. */
    public static function enabled(?\mysqli $db = null): bool
    {
        $db ??= ($GLOBALS['mysqli'] ?? null);
        if (!$db instanceof \mysqli) {
            return false;
        }
        $sig = spl_object_id($db);
        if (isset(self::$req['enabled'][$sig])) {
            return self::$req['enabled'][$sig];
        }
        $on = false;
        try {
            $r = $db->query('SELECT config_vault_v3_enabled AS f, config_vault_dek_wrap AS w FROM settings WHERE company_id = 1');
            $row = $r ? $r->fetch_assoc() : null;
            $on = $row && (int) $row['f'] === 1 && (string) $row['w'] !== '' && KeyStore::load()->usable();
        } catch (\Throwable) {
            $on = false;
        }

        return self::$req['enabled'][$sig] = $on;
    }

    public static function forget(): void
    {
        unset(self::$req['enabled'], self::$req['dek']);
    }

    /** The data key from the instance wrap (the key file opens it). Null when not prepared or unreadable. */
    public static function instanceDek(\mysqli $db): ?string
    {
        $r = $db->query('SELECT config_vault_dek_wrap AS w FROM settings WHERE company_id = 1');
        $w = $r ? (string) ($r->fetch_assoc()['w'] ?? '') : '';
        if ($w === '') {
            return null;
        }
        try {
            return self::wrap()->unwrapForInstance($w, SettingsCrypto::vault()->envelope(), self::WRAP_CONTEXT);
        } catch (CryptoException) {
            return null;
        }
    }

    /**
     * Create the data key (once) and store its instance wrap. Needs the v3 settings stage and the canonical vault key (the legacy master
     * key the conversion reads the old fields with). Idempotent.
     *
     * @return array{status:string, message:string}
     */
    public static function prepare(\mysqli $db): array
    {
        if (!SettingsCrypto::v3Enabled() || !KeyStore::load()->usable()) {
            return ['status' => 'refused', 'message' => 'Create the key file first (the v3 settings stage must be on).'];
        }
        if (self::instanceDek($db) !== null) {
            return ['status' => 'exists', 'message' => 'The vault data key already exists.'];
        }
        if (!function_exists('getCanonicalVaultKey') || getCanonicalVaultKey($db) === null) {
            return ['status' => 'refused', 'message' => 'Establish the canonical vault key first (Administration > Security > Vault Encryption); the conversion needs it.'];
        }
        $stored = self::wrap()->wrapForInstance(VaultKeyWrap::generateDek(), SettingsCrypto::vault()->envelope(), self::WRAP_CONTEXT);
        $stmt = $db->prepare("UPDATE settings SET config_vault_dek_wrap = ?, config_vault_v3_prepared_at = NOW() WHERE company_id = 1 AND (config_vault_dek_wrap IS NULL OR config_vault_dek_wrap = '')");
        $stmt->bind_param('s', $stored);
        $stmt->execute();
        $ok = $stmt->affected_rows === 1;
        $stmt->close();
        self::forget();

        return $ok ? ['status' => 'created', 'message' => 'Vault data key created and wrapped by the key file.'] : ['status' => 'refused', 'message' => 'Could not store the data key.'];
    }

    public static function setFlag(\mysqli $db, bool $on): void
    {
        if ($on && self::instanceDek($db) === null) {
            throw new \RuntimeException('Prepare the vault data key first (scripts/vault_v3_cli.php prepare).');
        }
        $v = $on ? 1 : 0;
        $db->query("UPDATE settings SET config_vault_v3_enabled = $v WHERE company_id = 1");
        self::forget();
    }

    // ── session ──────────────────────────────────────────────────────────────

    /** Keep the DEK in the session beside the legacy master key, sealed with the same browser-held cookie key (generateUserSessionKey). */
    public static function storeSessionDek(string $dek, ?string $cookieKey = null): void
    {
        $key = $cookieKey ?? ($_COOKIE['user_encryption_session_key'] ?? '');
        if ($key === '' || !isset($_SESSION)) {
            return;
        }
        $iv = random_bytes(16);
        $ct = openssl_encrypt($dek, 'aes-128-cbc', $key, 0, $iv);
        if ($ct !== false) {
            $_SESSION['vault_dek_ciphertext'] = $ct;
            $_SESSION['vault_dek_iv'] = bin2hex($iv);
        }
    }

    public static function sessionDek(): ?string
    {
        $ct = $_SESSION['vault_dek_ciphertext'] ?? '';
        $iv = $_SESSION['vault_dek_iv'] ?? '';
        $key = $_COOKIE['user_encryption_session_key'] ?? '';
        if ($ct === '' || $iv === '' || $key === '') {
            return null;
        }
        $iv = hex2bin($iv);
        $dek = $iv === false ? false : openssl_decrypt($ct, 'aes-128-cbc', $key, 0, $iv);

        return ($dek === false || strlen($dek) !== 32) ? null : $dek;
    }

    // ── fields ───────────────────────────────────────────────────────────────

    public static function isV3Field(string $stored): bool
    {
        return VaultCipher::isV3($stored);
    }

    /** @return string|false the plaintext, false when it cannot be opened */
    public static function openField(string $stored, string $dek, string|int $id, string $field): string|false
    {
        try {
            return (new VaultCipher($dek))->open($stored, $id, $field);
        } catch (CryptoException) {
            return false;
        }
    }

    public static function sealField(string $plain, string $dek, string|int $id, string $field): string
    {
        return (new VaultCipher($dek))->seal($plain, $id, $field);
    }

    /**
     * Bring one credential row's fields to v3 after a write (the sites write the legacy format with the session key, then call this).
     * Needs the legacy master key to read a field it just wrote and the DEK to seal it; without either the row is left as written and
     * the rewrap drains it later. Returns the number of fields converted.
     */
    public static function finalizeCredential(\mysqli $db, int $credentialId, ?string $master = null, ?string $dek = null): int
    {
        if (!self::enabled($db)) {
            return 0;
        }
        $dek ??= self::sessionDek() ?? self::instanceDek($db);
        if ($dek === null) {
            return 0;
        }
        $master ??= self::sessionMaster();
        $stmt = $db->prepare('SELECT credential_username, credential_password, credential_otp_secret FROM credentials WHERE credential_id = ?');
        $stmt->bind_param('i', $credentialId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return 0;
        }
        $cipher = new VaultCipher($dek);
        $set = [];
        foreach (['credential_username' => 'username', 'credential_password' => 'password'] as $col => $field) {
            $v = (string) ($row[$col] ?? '');
            if ($v === '' || VaultCipher::isV3($v) || $master === null || $master === '') {
                continue;
            }
            try {
                $set[$col] = $cipher->migrate($v, $credentialId, $master, $field);
            } catch (CryptoException) {
                continue;
            }
        }
        $otp = (string) ($row['credential_otp_secret'] ?? '');
        if ($otp !== '' && !VaultCipher::isV3($otp)) {
            $plain = null;
            if (str_starts_with($otp, 'enc:') && $master !== null && $master !== '') {
                try {
                    $plain = (new LegacyOtpReader($master))->read($otp, '');
                } catch (CryptoException) {
                    $plain = null;
                }
            } elseif (!str_starts_with($otp, 'enc:')) {
                $plain = $otp;   // unprefixed legacy OTP secrets are cleartext
            }
            if ($plain !== null) {
                $set['credential_otp_secret'] = $cipher->seal($plain, $credentialId, 'otp');
            }
        }
        foreach ($set as $col => $val) {
            $u = $db->prepare("UPDATE credentials SET `$col` = ? WHERE credential_id = ?");
            $u->bind_param('si', $val, $credentialId);
            $u->execute();
            $u->close();
        }

        return count($set);
    }

    /** The legacy master key of the signed-in session (the generateUserSessionKey layout), or null. */
    public static function sessionMaster(): ?string
    {
        $ct = $_SESSION['user_encryption_session_ciphertext'] ?? '';
        $iv = $_SESSION['user_encryption_session_iv'] ?? '';
        $key = $_COOKIE['user_encryption_session_key'] ?? '';
        if ($ct === '' || $iv === '' || $key === '') {
            return null;
        }
        $m = openssl_decrypt($ct, 'aes-128-cbc', $key, 0, $iv);

        return ($m === false || $m === '') ? null : $m;
    }

    // ── users, API keys, tokens ──────────────────────────────────────────────

    public static function isUserWrap(?string $stored): bool
    {
        return $stored !== null && VaultKeyWrap::isWrap($stored);
    }

    /**
     * What a password login gets from the stored wrap.
     * vw3: the DEK (and the legacy master from the canonical row). Legacy wrap: the master as before, and with the flag on the DEK too, and
     * the wrap is replaced by a vw3 wrap - but only when the master equals the canonical key, so the legacy key stays recoverable.
     *
     * @return array{master:?string, dek:?string}
     */
    public static function resolveLogin(\mysqli $db, int $userId, ?string $stored, #[\SensitiveParameter] string $password): array
    {
        $stored = (string) $stored;
        if (self::isUserWrap($stored)) {
            try {
                $dek = self::wrap()->unwrap($stored, $password, self::userContext($userId));
            } catch (CryptoException) {
                return ['master' => null, 'dek' => null];
            }
            if (self::wrap()->needsUpgrade($stored)) {
                self::storeUserWrap($db, $userId, self::wrap()->wrap($dek, $password, self::userContext($userId)));
            }

            return ['master' => function_exists('getCanonicalVaultKey') ? getCanonicalVaultKey($db) : null, 'dek' => $dek];
        }
        $master = $stored !== '' ? decryptUserSpecificKey($stored, $password) : null;
        $master = ($master === false || $master === '') ? null : $master;
        if ($master === null || !self::enabled($db)) {
            return ['master' => $master, 'dek' => null];
        }
        $dek = self::instanceDek($db);
        if ($dek !== null && function_exists('getCanonicalVaultKey') && hash_equals((string) getCanonicalVaultKey($db), $master)) {
            self::storeUserWrap($db, $userId, self::wrap()->wrap($dek, $password, self::userContext($userId)));
        }

        return ['master' => $master, 'dek' => $dek];
    }

    public static function storeUserWrap(\mysqli $db, int $userId, string $wrap): void
    {
        $stmt = $db->prepare('UPDATE users SET user_specific_encryption_ciphertext = ? WHERE user_id = ?');
        $stmt->bind_param('si', $wrap, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Password change. A user who has a vw3 wrap keeps one: with the old password it is rewrapped (the data key never leaves the wrap), without
     * it (an administrator's reset) it is made from the session data key. Returns the new wrap, or null to use the legacy writer.
     */
    public static function wrapForPasswordChange(\mysqli $db, int $userId, string $newPassword, ?string $oldPassword): ?string
    {
        $r = $db->query('SELECT user_specific_encryption_ciphertext AS w FROM users WHERE user_id = ' . $userId);
        $stored = $r ? (string) ($r->fetch_assoc()['w'] ?? '') : '';
        if (!self::isUserWrap($stored) || !self::enabled($db)) {
            return null;
        }
        if ($oldPassword !== null) {
            try {
                return self::wrap()->rewrap($stored, $oldPassword, $newPassword, self::userContext($userId));
            } catch (CryptoException) {
                // the old password does not open it: fall through to the data key we hold
            }
        }
        $dek = self::sessionDek() ?? self::instanceDek($db);

        return $dek === null ? null : self::wrap()->wrap($dek, $newPassword, self::userContext($userId));
    }

    /** API key: the DEK and master from the key's own wrap and password. @return array{master:?string, dek:?string} */
    public static function resolveApiKey(\mysqli $db, string $hash, #[\SensitiveParameter] string $password): array
    {
        if (self::isUserWrap($hash)) {
            try {
                $dek = self::wrap()->unwrap($hash, $password, 'apikey');
            } catch (CryptoException) {
                return ['master' => null, 'dek' => null];
            }

            return ['master' => function_exists('getCanonicalVaultKey') ? getCanonicalVaultKey($db) : null, 'dek' => $dek];
        }
        $master = decryptUserSpecificKey($hash, $password);
        $master = ($master === false || $master === '') ? null : $master;

        return ['master' => $master, 'dek' => ($master !== null && self::enabled($db)) ? self::instanceDek($db) : null];
    }

    /** The mobile token's copy of the DEK: AES-256-GCM under a key derived from the raw token (like the master key copy, but authenticated). */
    public static function wrapForToken(string $dek, string $rawToken): string
    {
        $key = hash_hkdf('sha256', $rawToken, 32, 'rivetit-api-token-dek|v1');

        return (new Envelope(KeyRing::single($key, 'tok')))->seal($dek, 'api_token_dek');
    }

    public static function unwrapFromToken(?string $stored, string $rawToken): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        try {
            $key = hash_hkdf('sha256', $rawToken, 32, 'rivetit-api-token-dek|v1');

            return (new Envelope(KeyRing::single($key, 'tok')))->open($stored, 'api_token_dek');
        } catch (CryptoException) {
            return null;
        }
    }

    // ── rewrap (legacy -> v3) ────────────────────────────────────────────────

    /** Context of a credential field's rewrap item. */
    public static function itemContext(string $kind, string $id, string $field): string
    {
        return VaultCipher::contextFor($kind === 'version' ? 'version-' . $id : $id, $field);
    }

    /**
     * Add the vault columns to a rewrap run (scripts/rewrap_cli.php --vault). Needs the prepared DEK and the canonical legacy key; a
     * known credential is test-decrypted first (legacy CBC is unauthenticated: a wrong key can yield garbage).
     */
    public static function addRewrapJobs(RewrapService $svc, \mysqli $db): void
    {
        $dek = self::instanceDek($db);
        $legacy = function_exists('getCanonicalVaultKey') ? getCanonicalVaultKey($db) : null;
        if ($dek === null || $legacy === null) {
            throw new \RuntimeException('The vault rewrap needs the prepared data key and the canonical vault key (scripts/vault_v3_cli.php prepare).');
        }
        self::assertLegacyKeyPlausible($db, $legacy);
        $cipher = new VaultCipher($dek);
        $fields = new VaultFieldReencryptor($cipher, null, $legacy);
        $otp = new class ($cipher, $legacy) implements Reencryptor {
            public function __construct(private VaultCipher $cipher, private string $legacy)
            {
            }

            public function reencrypt(string $stored, string $context): ?string
            {
                if (VaultCipher::isV3($stored)) {
                    $this->cipher->openWithContext($stored, $context);

                    return null;
                }
                $plain = str_starts_with($stored, 'enc:') ? (new LegacyOtpReader($this->legacy))->read($stored, '') : $stored;

                return $this->cipher->sealWithContext($plain, $context);
            }
        };
        $defs = [
            ['credentials', 'credential_id', 'credential_username', 'username', 'credential', $fields],
            ['credentials', 'credential_id', 'credential_password', 'password', 'credential', $fields],
            ['credentials', 'credential_id', 'credential_otp_secret', 'otp', 'credential', $otp],
            ['credential_versions', 'version_id', 'version_previous_username_enc', 'previous_username', 'version', $fields],
            ['credential_versions', 'version_id', 'version_previous_password_enc', 'previous_password', 'version', $fields],
        ];
        foreach ($defs as [$table, $pk, $col, $field, $kind, $re]) {
            $spec = new ColumnSpec($table, $pk, $col, 'custom', false, true, static fn (string $id): string => self::itemContext($kind, $id, $field));
            if (MysqliRewrapSource::exists($db, $spec)) {
                $svc->withJob(new MysqliRewrapSource($db, $spec), $re);
            }
        }
    }

    /** Spot check: at least one stored legacy password must open with the canonical key into printable text, else refuse the bulk run. */
    private static function assertLegacyKeyPlausible(\mysqli $db, string $legacy): void
    {
        $r = $db->query("SELECT credential_password AS p FROM credentials WHERE credential_password IS NOT NULL AND credential_password <> '' LIMIT 25");
        $tried = 0;
        $good = 0;
        while ($r && ($row = $r->fetch_assoc())) {
            $p = (string) $row['p'];
            if (VaultCipher::isV3($p) || !VaultCipher::isLegacy($p)) {
                continue;
            }
            $tried++;
            $plain = openssl_decrypt(substr($p, 16), 'aes-128-cbc', $legacy, 0, substr($p, 0, 16));
            if ($plain !== false && preg_match('//u', $plain) === 1 && !preg_match('/[\x00-\x08\x0e-\x1f]/', $plain)) {
                $good++;
            }
        }
        if ($tried > 0 && $good === 0) {
            throw new \RuntimeException('None of the sampled stored passwords opens with the canonical vault key: it is not the key they were written with. Nothing was changed.');
        }
    }
}
