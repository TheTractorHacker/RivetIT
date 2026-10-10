<?php

/*
 * Wave 1 security - policy settings, staff password rules, MFA enforcement and recovery codes.
 *
 * Settings live in the small key/value table `security_settings` (not in `settings`, which is at MariaDB's row-size limit).
 * Every key has a default here, so a missing table (before the database update has run) or a missing row reads as the default.
 *
 *   password_min_length     12     shortest staff password (8..128)
 *   password_hibp_check     0      1 = reject passwords found in the Have I Been Pwned range data (k-anonymity; fails OPEN)
 *   mfa_policy              off    off | admins | all  (who must have TOTP two-factor)
 *   mfa_grace_days          7      days a user who is required but not enrolled may still sign in
 *   mfa_policy_since        ''     when the policy was last switched on (grace starts there, or at account creation if later)
 *   session_idle_minutes    480    idle timeout (includes/security_sessions.php)
 *   remember_me_skips_mfa   0      1 = a remember-me cookie may skip the second factor even for admins and vault users
 *   vault_stepup_minutes    15     re-enter the password for a vault reveal after this many minutes without one (0 = never ask)
 *   vault_reveal_limit      30     reveals per user per 10 minutes before they are blocked and an alert is raised
 */

if (!function_exists('secSetting')) {

    /** Defaults for every key. @return array<string,string> */
    function secSettingDefaults(): array
    {
        return [
            'password_min_length'   => '12',
            'password_hibp_check'   => '0',
            'mfa_policy'            => 'off',
            'mfa_grace_days'        => '7',
            'mfa_policy_since'      => '',
            'session_idle_minutes'  => '480',
            'remember_me_skips_mfa' => '0',
            'vault_stepup_minutes'  => '15',
            'vault_reveal_limit'    => '30',
        ];
    }

    /** All stored values merged over the defaults; cached for the request. @param bool $reload drop the cache first */
    function secSettingsAll(?mysqli $mysqli = null, bool $reload = false): array
    {
        static $cache = null;
        if ($reload) {
            $cache = null;
        }
        if ($cache !== null) {
            return $cache;
        }
        $mysqli ??= $GLOBALS['mysqli'] ?? null;
        $cache = secSettingDefaults();
        if ($mysqli instanceof mysqli) {
            try {
                $res = @mysqli_query($mysqli, 'SELECT setting_key, setting_value FROM security_settings');
                while ($res && ($r = mysqli_fetch_assoc($res))) {
                    if (array_key_exists($r['setting_key'], $cache)) {
                        $cache[$r['setting_key']] = (string) $r['setting_value'];
                    }
                }
            } catch (\Throwable $e) {
                // table not there yet (before the database update): defaults apply
            }
        }

        return $cache;
    }

    function secSetting(string $key, ?mysqli $mysqli = null): string
    {
        return secSettingsAll($mysqli)[$key] ?? (secSettingDefaults()[$key] ?? '');
    }

    function secSettingInt(string $key, ?mysqli $mysqli = null): int
    {
        return (int) secSetting($key, $mysqli);
    }

    /** Clamp / validate a value for a key; returns the string to store. */
    function secSettingNormalize(string $key, $value): string
    {
        switch ($key) {
            case 'password_min_length':   return (string) max(8, min(128, (int) $value));
            case 'password_hibp_check':
            case 'remember_me_skips_mfa': return ((int) $value) === 1 ? '1' : '0';
            case 'mfa_policy':            return in_array((string) $value, ['off', 'admins', 'all'], true) ? (string) $value : 'off';
            case 'mfa_grace_days':        return (string) max(0, min(90, (int) $value));
            case 'session_idle_minutes':  return (string) max(5, min(129600, (int) $value));
            case 'vault_stepup_minutes':  return (string) max(0, min(1440, (int) $value));
            case 'vault_reveal_limit':    return (string) max(1, min(1000, (int) $value));
            case 'mfa_policy_since':      return (string) $value;
        }

        return (string) $value;
    }

    /** Store one setting. Unknown keys are ignored (returns false). */
    function secSettingSet(mysqli $mysqli, string $key, $value): bool
    {
        if (!array_key_exists($key, secSettingDefaults())) {
            return false;
        }
        $v    = secSettingNormalize($key, $value);
        $stmt = mysqli_prepare($mysqli, 'INSERT INTO security_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $key, $v);
        $okay = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        secSettingsAll($mysqli, true);

        return (bool) $okay;
    }

    // ------------------------------------------------------------------------------------------------------------------------------
    // Staff passwords
    // ------------------------------------------------------------------------------------------------------------------------------

    /** Argon2id options for new hashes: 64 MiB, 3 passes, 1 lane. */
    function secPasswordHashOptions(): array
    {
        return ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1];
    }

    /** A new password hash: Argon2id when this PHP has it, else the platform default (bcrypt). Old bcrypt hashes keep verifying. */
    function secPasswordHash(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, secPasswordHashOptions());
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** True when a stored hash should be replaced at the next successful login (older algorithm or weaker parameters). */
    function secPasswordNeedsRehash(string $hash): bool
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            return password_needs_rehash($hash, PASSWORD_DEFAULT);
        }

        return password_needs_rehash($hash, PASSWORD_ARGON2ID, secPasswordHashOptions());
    }

    /**
     * After a successful password check, upgrade the stored hash if it is out of date. Never fails a login.
     */
    function secPasswordRehashIfNeeded(mysqli $mysqli, int $userId, string $password, string $storedHash): bool
    {
        if (!secPasswordNeedsRehash($storedHash)) {
            return false;
        }
        try {
            $new = mysqli_real_escape_string($mysqli, secPasswordHash($password));
            mysqli_query($mysqli, "UPDATE users SET user_password = '$new' WHERE user_id = $userId AND user_password = '" . mysqli_real_escape_string($mysqli, $storedHash) . "'");

            return mysqli_affected_rows($mysqli) > 0;
        } catch (\Throwable $e) {
            error_log('password rehash failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Fetch the Have I Been Pwned range body for a 5 character SHA-1 prefix, or null when it cannot be had (fail open).
     * SSRF-safe: the URL is vetted by RivetCore's UrlPolicy (public addresses only, TLS, no redirects) and the connection is
     * pinned to the vetted addresses. Only the 5 character prefix ever leaves the server.
     */
    function secHibpFetchRange(string $prefix): ?string
    {
        if (!preg_match('/^[0-9A-F]{5}$/', $prefix) || !function_exists('curl_init') || !class_exists(\RivetCore\Webhooks\UrlPolicy::class)) {
            return null;
        }
        $url    = 'https://api.pwnedpasswords.com/range/' . $prefix;
        $target = (new \RivetCore\Webhooks\UrlPolicy(false))->vet($url);
        if ($target === null) {
            return null;
        }
        $ch  = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_PROXY          => '',
            CURLOPT_NOPROXY        => '*',
            CURLOPT_HTTPHEADER     => ['Add-Padding: true', 'User-Agent: RivetIT-password-check'],
            CURLOPT_MAXFILESIZE    => 1048576,
        ];
        if (!filter_var($target['host'], FILTER_VALIDATE_IP) && $target['ips'] !== []) {
            $opt[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . implode(',', $target['ips'])];
        }
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ($body !== false && $code === 200) ? (string) $body : null;
    }

    /**
     * Has this password appeared in a public breach? true = found, false = not found, null = could not check (fail open).
     * $fetcher(prefix): ?string replaces the network call (tests). The password and its full hash are never sent anywhere.
     */
    function secPasswordIsPwned(string $password, ?callable $fetcher = null): ?bool
    {
        $sha = strtoupper(sha1($password));
        try {
            $body = $fetcher !== null ? $fetcher(substr($sha, 0, 5)) : secHibpFetchRange(substr($sha, 0, 5));
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($body) || $body === '') {
            return null;
        }
        $suffix = substr($sha, 5);
        foreach (preg_split('/\R/', $body) as $line) {
            [$s, $count] = array_pad(explode(':', trim($line), 2), 2, '0');
            if (hash_equals($suffix, strtoupper(trim($s))) && (int) $count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Staff password rules. Returns an error message, or null when the password is acceptable.
     * $who: name / email / username of the account (any may be missing). $fetcher: HIBP fetch override (tests).
     */
    function secPasswordPolicyError(string $password, array $who = [], ?mysqli $mysqli = null, ?callable $fetcher = null): ?string
    {
        $min = secSettingInt('password_min_length', $mysqli);
        if (mb_strlen($password) < $min) {
            return "The password must be at least $min characters long.";
        }
        if (strlen($password) > 1024) {
            return 'The password is too long (1024 characters at most).';
        }
        $lower = mb_strtolower($password);
        $forbidden = [];
        foreach (['email', 'username', 'name'] as $k) {
            $v = mb_strtolower(trim((string) ($who[$k] ?? '')));
            if ($v !== '') {
                $forbidden[] = $v;
            }
        }
        $email = mb_strtolower(trim((string) ($who['email'] ?? '')));
        if ($email !== '' && str_contains($email, '@')) {
            $forbidden[] = substr($email, 0, (int) strpos($email, '@'));
        }
        foreach ($forbidden as $f) {
            if ($f !== '' && $lower === $f) {
                return 'The password must not be the same as your name, username or email address.';
            }
        }
        if (secSetting('password_hibp_check', $mysqli) === '1') {
            if (secPasswordIsPwned($password, $fetcher) === true) {
                return 'That password appears in a public data breach. Choose a different one.';
            }
        }

        return null;
    }

    // ------------------------------------------------------------------------------------------------------------------------------
    // MFA enforcement
    // ------------------------------------------------------------------------------------------------------------------------------

    /** Is the user an administrator role? */
    function secUserIsAdmin(mysqli $mysqli, int $userId): bool
    {
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_roles.role_is_admin FROM users LEFT JOIN user_roles ON user_roles.role_id = users.user_role_id WHERE users.user_id = $userId"));

        return $r && (int) ($r['role_is_admin'] ?? 0) === 1;
    }

    /** Does the user's role grant any access to the credential vault (module_credential)? Admins always do. */
    function secUserHasVaultAccess(mysqli $mysqli, int $userId): bool
    {
        if (secUserIsAdmin($mysqli, $userId)) {
            return true;
        }
        $r = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT MAX(p.user_role_permission_level) AS lvl
             FROM users u
             JOIN user_role_permissions p ON p.user_role_id = u.user_role_id
             JOIN modules m ON m.module_id = p.module_id AND m.module_name = 'module_credential'
             WHERE u.user_id = $userId"
        ));

        return $r && (int) ($r['lvl'] ?? 0) >= 1;
    }

    /**
     * Where does this user stand against the MFA rules?
     *   applies      true when the user must have two-factor (the per-user "require MFA" flag, or the global policy)
     *   enrolled     true when a TOTP seed is saved (accounts that sign in through an identity provider are exempt: applies = false)
     *   grace_ends   unix time until which an unenrolled user may still sign in (null = no grace, e.g. the per-user flag)
     *   blocked      applies, not enrolled, and out of grace: the app sends the user to enrolment and nothing else
     *
     * @return array{applies:bool, enrolled:bool, grace_ends:?int, in_grace:bool, blocked:bool, reason:string}
     */
    function secMfaEnforcementState(mysqli $mysqli, int $userId, ?int $now = null): array
    {
        $now ??= time();
        $none = ['applies' => false, 'enrolled' => false, 'grace_ends' => null, 'in_grace' => false, 'blocked' => false, 'reason' => ''];
        $u = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT users.user_type, users.user_token, users.user_auth_method, users.user_created_at,
                    COALESCE(user_roles.role_is_admin, 0) AS role_is_admin, COALESCE(user_settings.user_config_force_mfa, 0) AS force_mfa
             FROM users
             LEFT JOIN user_roles ON user_roles.role_id = users.user_role_id
             LEFT JOIN user_settings ON user_settings.user_id = users.user_id
             WHERE users.user_id = $userId"
        ));
        if (!$u || (int) $u['user_type'] !== 1) {
            return $none;
        }
        if (strtolower((string) $u['user_auth_method']) !== 'local') {
            return $none; // the identity provider does the second factor
        }
        $enrolled = (string) $u['user_token'] !== '';
        $policy   = secSetting('mfa_policy', $mysqli);
        $byPolicy = $policy === 'all' || ($policy === 'admins' && (int) $u['role_is_admin'] === 1);
        $byFlag   = (int) $u['force_mfa'] === 1;
        if (!$byPolicy && !$byFlag) {
            return $none;
        }
        $graceEnds = null;
        if (!$byFlag) {
            $since = (int) strtotime((string) secSetting('mfa_policy_since', $mysqli));
            $start = max($since, (int) strtotime((string) $u['user_created_at']));
            $graceEnds = $start + secSettingInt('mfa_grace_days', $mysqli) * 86400;
        }
        $inGrace = !$enrolled && $graceEnds !== null && $now < $graceEnds;

        return [
            'applies'    => true,
            'enrolled'   => $enrolled,
            'grace_ends' => $graceEnds,
            'in_grace'   => $inGrace,
            'blocked'    => !$enrolled && !$inGrace,
            'reason'     => $byFlag ? 'user' : $policy,
        ];
    }

    /** May a remember-me cookie stand in for the second factor for this user? Never for admins or vault users unless the setting allows it. */
    function secRememberMeMaySkipMfa(mysqli $mysqli, int $userId): bool
    {
        if (secSetting('remember_me_skips_mfa', $mysqli) === '1') {
            return true;
        }

        return !secUserHasVaultAccess($mysqli, $userId);
    }

    /**
     * Hard gate for an authenticated agent request: an account that must have two-factor, has not, and is out of grace may use only
     * the account pages needed to enrol (/agent/user/*). Everything else redirects there. In grace, a one-time notice names the deadline.
     * Call from includes/check_login.php after the user session is loaded.
     */
    function secMfaGate(mysqli $mysqli, int $userId, string $script): void
    {
        $st = secMfaEnforcementState($mysqli, $userId);
        if (!$st['applies'] || $st['enrolled']) {
            return;
        }
        if ($st['in_grace']) {
            if (empty($_SESSION['mfa_grace_notice'])) {
                $_SESSION['mfa_grace_notice'] = 1;
                $_SESSION['alert_message'] = 'Two-factor authentication is required on your account. Set it up under Account > Security before ' . date('Y-m-d', (int) $st['grace_ends']) . '.';
                $_SESSION['alert_type'] = 'warning';
            }

            return;
        }
        if (str_starts_with($script, '/agent/user/') || $script === '/agent/post.php') {
            return;
        }
        if (function_exists('redirect')) {
            redirect('/agent/user/mfa_enforcement.php');
        }
        header('Location: /agent/user/mfa_enforcement.php');
        exit;
    }

    // ------------------------------------------------------------------------------------------------------------------------------
    // Recovery codes
    // ------------------------------------------------------------------------------------------------------------------------------

    define('SEC_RECOVERY_CODE_COUNT', 10);
    define('SEC_RECOVERY_CODE_LENGTH', 10);
    define('SEC_RECOVERY_CODE_ALPHABET', 'abcdefghjkmnpqrstuvwxyz23456789'); // no i, l, o, 0, 1

    /** Reduce user input to the bare code characters: lower case, no dashes or spaces. */
    function secRecoveryCodeNormalize(string $input): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($input)) ?? '';
    }

    /** Does the input have the shape of a recovery code (so it is not tried as a six digit TOTP code)? */
    function secLooksLikeRecoveryCode(string $input): bool
    {
        $n = secRecoveryCodeNormalize($input);

        return strlen($n) === SEC_RECOVERY_CODE_LENGTH && strspn($n, SEC_RECOVERY_CODE_ALPHABET) === strlen($n);
    }

    /**
     * Replace the user's recovery codes with ten new single-use ones. Returns the plaintext codes (xxxxx-xxxxx) - the only time they
     * exist in clear: only password_hash() values are stored. Old codes, used or not, are removed.
     *
     * @return string[]
     */
    function secRecoveryCodesGenerate(mysqli $mysqli, int $userId): array
    {
        mysqli_query($mysqli, "DELETE FROM user_recovery_codes WHERE code_user_id = $userId");
        $alphabet = SEC_RECOVERY_CODE_ALPHABET;
        $codes    = [];
        for ($i = 0; $i < SEC_RECOVERY_CODE_COUNT; $i++) {
            $raw = '';
            for ($j = 0; $j < SEC_RECOVERY_CODE_LENGTH; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $hash = mysqli_real_escape_string($mysqli, password_hash($raw, PASSWORD_BCRYPT, ['cost' => 10]));
            mysqli_query($mysqli, "INSERT INTO user_recovery_codes (code_user_id, code_hash) VALUES ($userId, '$hash')");
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }

        return $codes;
    }

    /** How many unused recovery codes the user has left. */
    function secRecoveryCodesRemaining(mysqli $mysqli, int $userId): int
    {
        $r = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM user_recovery_codes WHERE code_user_id = $userId AND code_used_at IS NULL"));

        return (int) ($r[0] ?? 0);
    }

    /** Remove all of a user's recovery codes (two-factor switched off). */
    function secRecoveryCodesDelete(mysqli $mysqli, int $userId): void
    {
        mysqli_query($mysqli, "DELETE FROM user_recovery_codes WHERE code_user_id = $userId");
    }

    /**
     * Try a recovery code. True only if it matches an unused code of this user; that code is then marked used, atomically, so the same
     * code can never be accepted twice (not even by two simultaneous requests).
     */
    function secRecoveryCodeConsume(mysqli $mysqli, int $userId, string $input, string $ip = ''): bool
    {
        if (!secLooksLikeRecoveryCode($input)) {
            return false;
        }
        $code = secRecoveryCodeNormalize($input);
        $res  = mysqli_query($mysqli, "SELECT code_id, code_hash FROM user_recovery_codes WHERE code_user_id = $userId AND code_used_at IS NULL");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            if (password_verify($code, (string) $r['code_hash'])) {
                $id    = (int) $r['code_id'];
                $ipEsc = mysqli_real_escape_string($mysqli, substr($ip, 0, 45));
                mysqli_query($mysqli, "UPDATE user_recovery_codes SET code_used_at = NOW(), code_used_ip = '$ipEsc' WHERE code_id = $id AND code_used_at IS NULL");

                return mysqli_affected_rows($mysqli) === 1;
            }
        }

        return false;
    }
}
