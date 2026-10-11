<?php

/*
 * Wave 1 security - encryption of secrets that used to be stored in plaintext.
 *
 * The cipher is encryptSetting() / decryptSetting() in functions.php (v3: RivetCore\Crypto envelope once the key file exists, else ENC2:
 * AES-256-GCM; ENC: legacy CBC and unprefixed legacy plaintext stay readable). This file adds what the stragglers need:
 *   - secIsWrapped()         does a stored value already carry an ENC:/ENC2: prefix
 *   - secWrapIfPlain()       wrap a value for writing; never double-wraps
 *   - secRewrapColumn()      wrap every plaintext row of one column (used by DB update 2.6.153 and by the lazy re-wrap)
 *   - secLazyRewrapSettings  on a settings read: wrap the columns below that are still plaintext (a writer that has not been updated yet,
 *                            such as a cron job that stores a refreshed OAuth token, may still write plaintext)
 *   - secUserTotpSecret()    read a users.user_token value in either form
 *   - secUserTotpStore()     the value to write to users.user_token
 *
 * Nothing here ever wraps when $config_settings_enc_key is empty: encryptSetting() would throw, and a migration must not
 * "encrypt" with no key. Callers treat the empty-key case as "leave the row as it is".
 */

if (!function_exists('secIsWrapped')) {

    /** True when the stored value already carries one of the encryptSetting() prefixes. */
    function secIsWrapped(?string $stored): bool
    {
        return $stored !== null && (str_starts_with($stored, 'v3:') || str_starts_with($stored, 'ENC2:') || str_starts_with($stored, 'ENC:'));
    }

    /** True when a settings key is configured, so wrapping is possible. */
    function secSettingsKeyAvailable(): bool
    {
        return !empty($GLOBALS['config_settings_enc_key']) || \ITFlow\Crypto\KeyStore::load()->usable();
    }

    /**
     * The value to store for a secret: '' stays '', an already wrapped value is returned unchanged, anything else is wrapped.
     * Throws (like encryptSetting) when there is no settings key.
     */
    function secWrapIfPlain(string $value): string
    {
        if ($value === '' || secIsWrapped($value)) {
            return $value;
        }

        return encryptSetting($value);
    }

    /**
     * Every secret column that used to be stored in plaintext, as table => [primary key, [columns]].
     * One list for the migration and the lazy re-wrap, so they cannot drift apart.
     *
     * @return array<string, array{0:string, 1:string[]}>
     */
    function secStragglerColumns(): array
    {
        return [
            'settings'      => ['company_id', [
                'config_slack_bot_token',
                'config_login_key_secret',
                'config_whitelabel_key',
                // Deferred in DB update 2.6.77 because some readers pulled the raw column. Every reader now calls decryptSetting().
                'config_smtp_password',
                'config_azure_client_secret',
                'config_mail_oauth_client_secret',
                'config_mail_oauth_refresh_token',
                'config_mail_oauth_access_token',
            ]],
            'software'      => ['software_id', ['software_key']],
            'software_keys' => ['software_key_id', ['software_key']],
            'users'         => ['user_id', ['user_token']],
        ];
    }

    /**
     * Wrap every plaintext, non-empty row of one column. Idempotent (a wrapped row is skipped). Skips, loudly, a value whose wrapped form
     * would not fit a varchar column, because MySQL would truncate it and destroy the secret.
     *
     * @return array{wrapped:int, skipped_too_long:int, missing:bool}
     */
    function secRewrapColumn(mysqli $mysqli, string $table, string $pk, string $col): array
    {
        $result = ['wrapped' => 0, 'skipped_too_long' => 0, 'missing' => false];
        if (!secSettingsKeyAvailable()) {
            return $result;
        }

        $lenRow = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT CHARACTER_MAXIMUM_LENGTH AS max_len, DATA_TYPE AS dtype FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . mysqli_real_escape_string($mysqli, $table) . "'
               AND COLUMN_NAME = '" . mysqli_real_escape_string($mysqli, $col) . "'"
        ));
        if (!$lenRow) {
            $result['missing'] = true; // table or column not on this install

            return $result;
        }
        $isVarchar = in_array(strtolower((string) $lenRow['dtype']), ['varchar', 'char'], true);
        $maxLen    = $isVarchar ? (int) $lenRow['max_len'] : 0;

        $rows = mysqli_query(
            $mysqli,
            "SELECT `$pk` AS pk, `$col` AS val FROM `$table`
             WHERE `$col` IS NOT NULL AND `$col` <> '' AND `$col` NOT LIKE 'ENC:%' AND `$col` NOT LIKE 'ENC2:%' AND `$col` NOT LIKE 'v3:%'"
        );
        if (!$rows) {
            return $result;
        }

        while ($row = mysqli_fetch_assoc($rows)) {
            $wrapped = encryptSetting((string) $row['val']);
            if ($maxLen > 0 && strlen($wrapped) > $maxLen) {
                $result['skipped_too_long']++;
                if (function_exists('logApp')) {
                    logApp('Database', 'error', "Left $table.$col row {$row['pk']} in cleartext: the wrapped value needs " . strlen($wrapped) . " chars but the column holds $maxLen. Re-save this secret after widening the column.");
                }
                continue;
            }
            $esc = mysqli_real_escape_string($mysqli, $wrapped);
            mysqli_query($mysqli, "UPDATE `$table` SET `$col` = '$esc' WHERE `$pk` = " . (int) $row['pk'] . " AND `$col` = '" . mysqli_real_escape_string($mysqli, (string) $row['val']) . "'");
            $result['wrapped'] += max(0, mysqli_affected_rows($mysqli));
        }

        return $result;
    }

    /**
     * Lazy re-wrap of the secret columns of the settings row just read ($row is that row, from SELECT * FROM settings):
     *   1. a straggler column that is still cleartext is wrapped (a writer that has not been updated yet, such as a cron job that stores a
     *      refreshed OAuth token, may still write plaintext);
     *   2. with the v3 stage on, a legacy ENC:/ENC2: value or a v3 value under an older key id moves to the active key.
     * Cheap: string prefix tests only; a database write happens only when there is something to move. Does nothing without a key.
     */
    function secLazyRewrapSettings(mysqli $mysqli, array $row): void
    {
        if (!secSettingsKeyAvailable()) {
            return;
        }
        $spec = secStragglerColumns()['settings'];
        $set  = [];
        foreach ($spec[1] as $col) {
            $v = $row[$col] ?? null;
            if (is_string($v) && $v !== '' && !secIsWrapped($v)) {
                $set[] = $col;
            }
        }
        $move = [];   // column => [name, value]
        if (class_exists(\ITFlow\Crypto\SettingsCrypto::class) && \ITFlow\Crypto\SettingsCrypto::v3Enabled()) {
            $active = \ITFlow\Crypto\KeyStore::load()->ring->activeKid();
            foreach (\ITFlow\Crypto\ColumnRegistry::all() as $cs) {
                if ($cs->table !== 'settings' || !in_array($cs->kind, ['settings', 'canonical'], true)) {
                    continue;
                }
                $v = $row[$cs->column] ?? null;
                if (!is_string($v) || $v === '' || ($active !== null && str_starts_with($v, 'v3:' . $active . ':'))) {
                    continue;
                }
                if (secIsWrapped($v)) {   // cleartext is the first block's business
                    $move[$cs->column] = [$cs->kind === 'canonical' ? \ITFlow\Crypto\SettingsCrypto::VAULT_CANONICAL_KEY : \ITFlow\Crypto\SettingsCrypto::GENERIC, $v];
                }
            }
        }
        if (!$set && !$move) {
            return;
        }
        // A value too long for its column, or one that cannot be read, is skipped; do not retry it on every request, once an hour is enough.
        $marker = sys_get_temp_dir() . '/rivetit_rewrap_skip_' . md5((string) ($GLOBALS['database'] ?? 'db') . '|' . implode(',', $set) . '|' . implode(',', array_keys($move)));
        if (is_file($marker) && time() - (int) @filemtime($marker) < 3600) {
            return;
        }
        try {
            $skipped = 0;
            foreach ($set as $col) {
                $skipped += secRewrapColumn($mysqli, 'settings', $spec[0], $col)['skipped_too_long'];
            }
            foreach ($move as $col => [$name, $old]) {
                $new = \ITFlow\Crypto\SettingsCrypto::rewrapped($old, $name);
                $cap = $new === null ? null : \ITFlow\Crypto\MysqliRewrapSource::columnCapacity($mysqli, 'settings', $col);
                if ($new === null || ($cap !== null && strlen($new) > $cap)) {
                    $skipped++;   // unreadable, or the column is not wide enough yet (DB update 2.6.160): leave it as it is
                    continue;
                }
                $stmt = $mysqli->prepare("UPDATE settings SET `$col` = ? WHERE company_id = 1 AND BINARY `$col` = BINARY ?");
                if ($stmt) {
                    $stmt->bind_param('ss', $new, $old);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            if ($skipped > 0) {
                @touch($marker);
            }
        } catch (\Throwable $e) {
            // Never break a page load over a re-wrap; the next read tries again.
            error_log('settings lazy re-wrap failed: ' . get_class($e));
        }
    }

    /**
     * users.user_token (the TOTP seed) in any stored form: v3 (totp purpose, bound to the user), ENC2/ENC, or legacy plaintext.
     * Pass the owner's user id: a v3 seed is bound to it and cannot be read without it.
     */
    function secUserTotpSecret(?string $stored, ?int $userId = null): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        return $userId !== null ? \ITFlow\Crypto\SettingsCrypto::decryptTotp($stored, $userId) : decryptSetting($stored);
    }

    /**
     * The value to write to users.user_token for a new seed. Throws without a key (never silently plaintext). With the TOTP stage on the seed
     * is a v3 value of the totp purpose bound to the user, so the user id is required for that form; without one the settings form is used.
     */
    function secUserTotpStore(string $secret, ?int $userId = null): string
    {
        return $userId !== null ? \ITFlow\Crypto\SettingsCrypto::encryptTotp($secret, $userId) : encryptSetting($secret);
    }

    /** Lazily bring one user's TOTP seed to the current form after a successful read (cleartext, ENC2/ENC, an old key id, a settings-purpose v3). */
    function secUserTotpRewrap(mysqli $mysqli, int $userId, ?string $stored): void
    {
        if ($stored === null || $stored === '') {
            return;
        }
        try {
            $new = \ITFlow\Crypto\SettingsCrypto::rewrapTotp($stored, $userId);
            if ($new === null && !secIsWrapped($stored) && secSettingsKeyAvailable()) {
                $new = encryptSetting($stored);   // the TOTP stage is off: a cleartext seed still gets wrapped, the old way
            }
            if ($new === null) {
                return;
            }
            $esc  = mysqli_real_escape_string($mysqli, $new);
            $orig = mysqli_real_escape_string($mysqli, $stored);
            mysqli_query($mysqli, "UPDATE users SET user_token = '$esc' WHERE user_id = $userId AND user_token = '$orig'");
        } catch (\Throwable $e) {
            error_log('TOTP seed re-wrap failed: ' . get_class($e));
        }
    }
}
