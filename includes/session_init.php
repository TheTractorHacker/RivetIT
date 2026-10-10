<?php

if (!isset($_SESSION)) {
    // Session cookie and ini settings: HttpOnly, Secure on HTTPS, strict mode, SameSite=Lax, lifetime = the absolute session
    // lifetime (Admin > Settings > Security; default 14 days). The idle timeout is enforced per request in includes/auth_check.php.
    // Must be set before session_start.
    require_once __DIR__ . '/security_policy.php';
    require_once __DIR__ . '/security_sessions.php';
    $session_lifetime_seconds = secSessionIniApply($mysqli ?? null, !empty($config_https_only));

    session_start();

    // Store so generateUserSessionKey can match the encryption cookie to the session
    $_SESSION['session_lifetime_seconds'] = $session_lifetime_seconds;

}
