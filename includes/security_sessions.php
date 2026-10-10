<?php

/*
 * Wave 1 security - sessions.
 *
 *   - idle timeout (security_settings.session_idle_minutes, default 8 h) and absolute lifetime (settings.config_login_session_lifetime,
 *     default 14 days, floor 60 minutes, cap 90 days) enforced on the server, per request, from includes/auth_check.php
 *   - session.use_strict_mode, HttpOnly and (on HTTPS) Secure cookies, SameSite=Lax session cookie
 *   - user_sessions: one row per signed-in browser (sha256 of the session id, IP, user agent, created, last seen), listed on the
 *     profile page, revocable one by one, all at once, and on every password change. A revoked session is signed out on its next request.
 *
 * Requests that are only a background poll (an EventSource stream, or an XHR sent with ?_bg=1 / X-Rivet-Background: 1) never extend
 * the idle timer, so a tab left open does not keep a session alive for ever.
 */

if (!function_exists('secSessionLimits')) {

    /** Absolute lifetime in seconds from settings.config_login_session_lifetime (minutes): default 14 days, floor 1 hour, cap 90 days. */
    function secSessionAbsoluteSeconds(?mysqli $mysqli = null): int
    {
        $minutes = 20160;
        $mysqli ??= $GLOBALS['mysqli'] ?? null;
        if ($mysqli instanceof mysqli) {
            try {
                $r = @mysqli_query($mysqli, 'SELECT config_login_session_lifetime FROM settings WHERE company_id = 1 LIMIT 1');
                $row = $r ? mysqli_fetch_assoc($r) : null;
                if ($row && (int) $row['config_login_session_lifetime'] > 0) {
                    $minutes = (int) $row['config_login_session_lifetime'];
                }
            } catch (\Throwable $e) {
                // keep the default
            }
        }

        return max(60, min(129600, $minutes)) * 60;
    }

    /** @return array{idle:int, absolute:int} seconds */
    function secSessionLimits(?mysqli $mysqli = null): array
    {
        if (!function_exists('secSettingInt')) {
            require_once __DIR__ . '/security_policy.php';
        }

        return [
            'idle'     => max(5, secSettingInt('session_idle_minutes', $mysqli)) * 60,
            'absolute' => secSessionAbsoluteSeconds($mysqli),
        ];
    }

    /**
     * Apply the session cookie / ini settings. Call BEFORE session_start(). Used by includes/session_init.php and login.php.
     */
    function secSessionIniApply(?mysqli $mysqli, bool $httpsOnly): int
    {
        $absolute = secSessionAbsoluteSeconds($mysqli);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        if ($httpsOnly) {
            ini_set('session.cookie_secure', '1');
        }
        ini_set('session.gc_maxlifetime', (string) $absolute);
        session_set_cookie_params(['lifetime' => $absolute, 'path' => '/', 'httponly' => true, 'secure' => $httpsOnly, 'samesite' => 'Lax']);

        return $absolute;
    }

    /** Is this request a background poll that must not count as user activity? */
    function secSessionIsBackground(): bool
    {
        if (defined('RIVET_BACKGROUND_REQUEST')) {
            return true;
        }
        if (($_GET['_bg'] ?? '') === '1' || ($_SERVER['HTTP_X_RIVET_BACKGROUND'] ?? '') === '1') {
            return true;
        }

        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/event-stream');
    }

    /** sha256 of the current session id - the only form of it that is stored. */
    function secSessionHash(?string $id = null): string
    {
        return hash('sha256', $id ?? session_id());
    }

    /**
     * Make sure the current browser session has a user_sessions row, keep it current, and report whether it is still allowed.
     * False = the row was revoked (sign-out everywhere, password change, revoke from the profile page).
     * Fails open when the table is missing (before the database update runs).
     */
    function secSessionTrack(mysqli $mysqli, int $userId, bool $touch = true): bool
    {
        $hash  = secSessionHash();
        $rowId = (int) ($_SESSION['sec_row'] ?? 0);
        try {
            if ($rowId > 0) {
                $res = @mysqli_query($mysqli, "SELECT session_user_id, session_hash, session_revoked_at FROM user_sessions WHERE session_row_id = $rowId");
                if ($res === false) {
                    return true; // table missing
                }
                $r = mysqli_fetch_assoc($res);
                if ($r && (int) $r['session_user_id'] === $userId) {
                    if ($r['session_revoked_at'] !== null) {
                        return false;
                    }
                    if (!hash_equals((string) $r['session_hash'], $hash)) {
                        // the session id was rotated (session_regenerate_id): follow it
                        mysqli_query($mysqli, "UPDATE user_sessions SET session_hash = '$hash' WHERE session_row_id = $rowId");
                    }
                    $seen = (int) ($_SESSION['sec_seen_db'] ?? 0);
                    if ($touch && time() - $seen >= 60) {
                        mysqli_query($mysqli, "UPDATE user_sessions SET session_last_seen_at = NOW() WHERE session_row_id = $rowId");
                        $_SESSION['sec_seen_db'] = time();
                    }

                    return true;
                }
                // row deleted, or it belongs to someone else: fall through and register afresh
            }

            secSessionRegister($mysqli, $userId);

            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** Create the user_sessions row for the current session; returns its id (0 when the table is missing). */
    function secSessionRegister(mysqli $mysqli, int $userId): int
    {
        $hash = secSessionHash();
        $ip   = mysqli_real_escape_string($mysqli, substr((string) (function_exists('getIP') ? getIP() : ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 64));
        $ua   = mysqli_real_escape_string($mysqli, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
        $ok   = @mysqli_query(
            $mysqli,
            "INSERT INTO user_sessions (session_user_id, session_hash, session_ip, session_user_agent)
             VALUES ($userId, '$hash', '$ip', '$ua')
             ON DUPLICATE KEY UPDATE session_user_id = VALUES(session_user_id), session_last_seen_at = NOW(), session_revoked_at = NULL, session_revoked_reason = NULL"
        );
        if (!$ok) {
            return 0;
        }
        $r = mysqli_fetch_row(mysqli_query($mysqli, "SELECT session_row_id FROM user_sessions WHERE session_hash = '$hash'"));
        $id = (int) ($r[0] ?? 0);
        $_SESSION['sec_row']     = $id;
        $_SESSION['sec_seen_db'] = time();

        return $id;
    }

    /** Start the clocks for a session that has just been signed in (login.php, remember-me restore). */
    function secSessionStart(mysqli $mysqli, int $userId): void
    {
        $_SESSION['sec_created'] = time();
        $_SESSION['sec_last']    = time();
        $_SESSION['vault_stepup_at'] = time();
        unset($_SESSION['sec_row']);
        secSessionRegister($mysqli, $userId);
    }

    /**
     * Per-request check for a signed-in session: idle timeout, absolute lifetime, revocation. On failure the session is emptied
     * (not destroyed, so a valid remember-me cookie can still restore it) and false is returned.
     */
    function secSessionEnforce(mysqli $mysqli, ?int $now = null): bool
    {
        if (empty($_SESSION['logged'])) {
            return true;
        }
        $now ??= time();
        $lim = secSessionLimits($mysqli);
        if (!isset($_SESSION['sec_created'])) {
            // a session that predates this feature: its clocks start now
            $_SESSION['sec_created'] = $now;
            $_SESSION['sec_last']    = $now;
        }
        $reason = null;
        if ($now - (int) ($_SESSION['sec_last'] ?? $now) > $lim['idle']) {
            $reason = 'idle';
        } elseif ($now - (int) $_SESSION['sec_created'] > $lim['absolute']) {
            $reason = 'absolute';
        } elseif (!secSessionTrack($mysqli, (int) ($_SESSION['user_id'] ?? 0), !secSessionIsBackground())) {
            $reason = 'revoked';
        }
        if ($reason !== null) {
            secSessionEnd($mysqli, $reason);

            return false;
        }
        if (!secSessionIsBackground()) {
            $_SESSION['sec_last'] = $now;
        }

        return true;
    }

    /** End the current session: mark its row, empty the session, rotate the id, drop the vault-key cookie. */
    function secSessionEnd(mysqli $mysqli, string $reason): void
    {
        $rowId = (int) ($_SESSION['sec_row'] ?? 0);
        if ($rowId > 0) {
            $why = mysqli_real_escape_string($mysqli, substr($reason, 0, 40));
            @mysqli_query($mysqli, "UPDATE user_sessions SET session_revoked_at = COALESCE(session_revoked_at, NOW()), session_revoked_reason = COALESCE(session_revoked_reason, '$why') WHERE session_row_id = $rowId");
        }
        $notice = ['idle' => 'You were signed out after a period of inactivity.', 'absolute' => 'Your session reached its maximum length. Please sign in again.', 'revoked' => 'This session was signed out from another place.'][$reason] ?? '';
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        if ($notice !== '') {
            $_SESSION['login_notice'] = $notice;
        }
        if (!headers_sent()) {
            setcookie('user_encryption_session_key', '', time() - 3600, '/');
        }
        unset($_COOKIE['user_encryption_session_key']);
    }

    /** Revoke one of the user's own sessions. True if a live row was revoked. */
    function secSessionRevoke(mysqli $mysqli, int $userId, int $rowId, string $reason = 'revoked'): bool
    {
        $why = mysqli_real_escape_string($mysqli, substr($reason, 0, 40));
        mysqli_query($mysqli, "UPDATE user_sessions SET session_revoked_at = NOW(), session_revoked_reason = '$why' WHERE session_row_id = $rowId AND session_user_id = $userId AND session_revoked_at IS NULL");

        return mysqli_affected_rows($mysqli) > 0;
    }

    /** Revoke every live session of the user, optionally keeping one row (the current browser). Returns how many were revoked. */
    function secSessionRevokeAll(mysqli $mysqli, int $userId, ?int $exceptRowId = null, string $reason = 'revoked'): int
    {
        $why    = mysqli_real_escape_string($mysqli, substr($reason, 0, 40));
        $except = $exceptRowId !== null && $exceptRowId > 0 ? " AND session_row_id <> $exceptRowId" : '';
        @mysqli_query($mysqli, "UPDATE user_sessions SET session_revoked_at = NOW(), session_revoked_reason = '$why' WHERE session_user_id = $userId AND session_revoked_at IS NULL$except");

        return max(0, mysqli_affected_rows($mysqli));
    }

    /**
     * After a password change: every other session is signed out and remember-me cookies stop working.
     * $keepRowId = the browser that made the change (null when an admin changed someone else's password).
     */
    function secSessionsOnPasswordChange(mysqli $mysqli, int $userId, ?int $keepRowId = null): int
    {
        mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $userId");

        return secSessionRevokeAll($mysqli, $userId, $keepRowId, 'password_changed');
    }

    /** The user's live sessions, newest activity first. @return array<int,array<string,mixed>> */
    function secSessionList(mysqli $mysqli, int $userId): array
    {
        $absolute = secSessionAbsoluteSeconds($mysqli);
        $idle     = secSessionLimits($mysqli)['idle'];
        $cut      = max($absolute, $idle);
        $out      = [];
        $res      = @mysqli_query($mysqli, "SELECT * FROM user_sessions WHERE session_user_id = $userId AND session_revoked_at IS NULL AND session_last_seen_at > (NOW() - INTERVAL $idle SECOND) AND session_created_at > (NOW() - INTERVAL $cut SECOND) ORDER BY session_last_seen_at DESC");
        $cur      = (int) ($_SESSION['sec_row'] ?? 0);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $r['is_current'] = (int) $r['session_row_id'] === $cur;
            $out[] = $r;
        }

        return $out;
    }

    /** Housekeeping (cron): forget rows that ended or went quiet long ago. */
    function secSessionPurge(mysqli $mysqli): void
    {
        @mysqli_query($mysqli, 'DELETE FROM user_sessions WHERE session_last_seen_at < (NOW() - INTERVAL 30 DAY)');
    }
}
