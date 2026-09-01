<?php

if (!isset($_SESSION)) {
    // HTTP Only cookies
    ini_set("session.cookie_httponly", true);

    if ($config_https_only) {
        // Tell client to only send cookie(s) over HTTPS
        ini_set("session.cookie_secure", true);
    }

    // Configurable session lifetime (default 8 hours; must be set before session_start)
    $session_lifetime_seconds = 28800;
    if (isset($mysqli)) {
        $sl_result = mysqli_query($mysqli, "SELECT config_login_session_lifetime FROM settings WHERE company_id = 1 LIMIT 1");
        if ($sl_result) {
            $sl_row = mysqli_fetch_assoc($sl_result);
            if ($sl_row && intval($sl_row['config_login_session_lifetime']) > 0) {
                $session_lifetime_seconds = max(1800, min(2592000, intval($sl_row['config_login_session_lifetime']) * 60));
            }
        }
    }
    ini_set('session.gc_maxlifetime', $session_lifetime_seconds);
    session_set_cookie_params(['lifetime' => $session_lifetime_seconds, 'samesite' => 'Lax']);

    session_start();

    // Store so generateUserSessionKey can match the encryption cookie lifetime
    $_SESSION['session_lifetime_seconds'] = $session_lifetime_seconds;

}
