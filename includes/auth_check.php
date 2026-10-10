<?php

require_once __DIR__ . '/security_policy.php';
require_once __DIR__ . '/security_sessions.php';

// Idle timeout, absolute lifetime and revocation (Admin > Settings > Security, Account > Security > Active sessions).
// A session that fails is emptied here, so the code below sends it to the sign-in page (or restores it from a valid remember-me cookie).
if (isset($mysqli) && !empty($_SESSION['logged'])) {
    secSessionEnforce($mysqli);
}

// Check user is logged in with a valid session
if (!isset($_SESSION['logged']) || !$_SESSION['logged']) {

    // Auto-restore session via remember-me cookie so internet outages don't force re-login
    if (!empty($_COOKIE['rememberme']) && isset($mysqli)) {
        $cookie_hash    = hash('sha256', $_COOKIE['rememberme']);
        $escaped_hash   = mysqli_real_escape_string($mysqli, $cookie_hash);

        $rm_settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_login_remember_me_expire FROM settings WHERE company_id = 1 LIMIT 1"));
        $rm_expire   = max(30, intval($rm_settings['config_login_remember_me_expire'] ?? 30));

        $rm_result = mysqli_query($mysqli, "
            SELECT rt.remember_token_user_id
            FROM remember_tokens rt
            INNER JOIN users u ON u.user_id = rt.remember_token_user_id
            WHERE rt.remember_token_token = '$escaped_hash'
              AND rt.remember_token_created_at > (NOW() - INTERVAL $rm_expire DAY)
              AND u.user_status = 1
              AND u.user_archived_at IS NULL
              AND u.user_type = 1
            LIMIT 1
        ");

        // A remember-me cookie never stands in for the second factor of an administrator or a user with vault access (unless the
        // "allow remember-me to skip MFA" setting is on): such a user must sign in again.
        $rm_uid_probe = ($rm_result && mysqli_num_rows($rm_result) === 1) ? intval(mysqli_fetch_assoc($rm_result)['remember_token_user_id']) : 0;
        $rm_allowed = $rm_uid_probe > 0;
        if ($rm_allowed) {
            $rm_has_mfa = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users WHERE user_id = $rm_uid_probe AND user_token IS NOT NULL AND user_token <> ''"));
            if ((int) ($rm_has_mfa[0] ?? 0) > 0 && !secRememberMeMaySkipMfa($mysqli, $rm_uid_probe)) {
                $rm_allowed = false;
            }
        }

        if ($rm_allowed) {
            $rm_row = ['remember_token_user_id' => $rm_uid_probe];
            $_SESSION['user_id']    = intval($rm_row['remember_token_user_id']);
            $_SESSION['logged']     = true;
            $_SESSION['csrf_token'] = randomString(32);
            session_regenerate_id(true);
            secSessionStart($mysqli, intval($rm_row['remember_token_user_id']));

            // Rotate the remember-me token on every use so a stolen cookie is a
            // single-use secret rather than a durable bearer credential valid for
            // the whole expiry window. The original issuance time is preserved
            // (only the token value changes), so this doesn't extend the absolute
            // expiry just because the cookie is actively used.
            $new_raw_token = bin2hex(random_bytes(64));
            $new_hash      = hash('sha256', $new_raw_token);
            mysqli_query($mysqli, "UPDATE remember_tokens SET remember_token_token = '$new_hash' WHERE remember_token_token = '$escaped_hash'");
            setcookie('rememberme', $new_raw_token, time() + 86400 * $rm_expire, '/', null, true, true);
        } elseif ($rm_uid_probe === 0) {
            setcookie('rememberme', '', time() - 3600, '/', null, true, true);
        }
    }

    if (!isset($_SESSION['logged']) || !$_SESSION['logged']) {
        if ($_SERVER["REQUEST_URI"] == "/") {
            header("Location: /login.php");
        } else {
            header("Location: /login.php?last_visited=" . base64_encode($_SERVER["REQUEST_URI"]));
        }
        exit;
    }
}
