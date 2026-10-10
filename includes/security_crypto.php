<?php

/*
 * Wave 1 security - encryption of secrets that used to be stored in plaintext.
 *
 * The cipher is encryptSetting() / decryptSetting() in functions.php (ENC2: AES-256-GCM, ENC: legacy CBC, unprefixed legacy plaintext
 * that stays readable). This file adds what the stragglers need:
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
        return $stored !== null && (str_starts_with($stored, 'ENC2:') || str_starts_with($stored, 'ENC:'));
    }

    /** True when a settings key is configured, so wrapping is possible. */
    function secSettingsKeyAvailable(): bool
    {
        return !empty($GLOBALS['config_settings_enc_key']);
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
             WHERE `$col` IS NOT NULL AND `$col` <> '' AND `$col` NOT LIKE 'ENC:%' AND `$col` NOT LIKE 'ENC2:%'"
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
     * Lazy re-wrap of the straggler columns of the settings row just read ($row is that row, from SELECT * FROM settings).
     * Cheap: string prefix tests only; a database write happens only when a plaintext secret is found. Does nothing without a key.
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
        if (!$set) {
            return;
        }
        try {
            foreach ($set as $col) {
                secRewrapColumn($mysqli, 'settings', $spec[0], $col);
            }
        } catch (\Throwable $e) {
            // Never break a page load over a re-wrap; the next read tries again.
            error_log('settings lazy re-wrap failed: ' . $e->getMessage());
        }
    }

    /** users.user_token (the TOTP seed) in either stored form: wrapped or legacy plaintext. */
    function secUserTotpSecret(?string $stored): string
    {
        return $stored === null || $stored === '' ? '' : decryptSetting($stored);
    }

    /** The value to write to users.user_token for a new seed. Throws without a settings key (never silently plaintext). */
    function secUserTotpStore(string $secret): string
    {
        return encryptSetting($secret);
    }

    /** Lazily wrap one user's TOTP seed after a successful read of a legacy plaintext one. */
    function secUserTotpRewrap(mysqli $mysqli, int $userId, ?string $stored): void
    {
        if ($stored === null || $stored === '' || secIsWrapped($stored) || !secSettingsKeyAvailable()) {
            return;
        }
        try {
            $esc  = mysqli_real_escape_string($mysqli, encryptSetting($stored));
            $orig = mysqli_real_escape_string($mysqli, $stored);
            mysqli_query($mysqli, "UPDATE users SET user_token = '$esc' WHERE user_id = $userId AND user_token = '$orig'");
        } catch (\Throwable $e) {
            error_log('TOTP seed re-wrap failed: ' . $e->getMessage());
        }
    }
}
