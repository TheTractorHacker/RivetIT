<?php
// GET    /api/v1/me                  - current user profile
// PUT    /api/v1/me                  - update profile
defined('FROM_API') || die();

if ($method === 'GET') {
    $me_profile = itflow_user_access_profile(intval($api_user_id));
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT user_id, user_name, user_email, user_type, user_color, user_avatar
         FROM users WHERE user_id = $api_user_id LIMIT 1"));
    api_response(200, [
        'id'     => intval($row['user_id']),
        'name'   => $row['user_name'],
        'email'  => $row['user_email'],
        'type'   => intval($row['user_type']),
        'color'  => $row['user_color'],
        'avatar' => $row['user_avatar'],
        'modules' => [
            'accounting_enabled'     => (bool) $config_module_enable_accounting,
            'ticket_charges_enabled' => (bool) $config_module_enable_ticket_charges,
        ],
        // Roles audit P4: what this login may use, so the app can hide screens it cannot open.
        // permissions: every module => 0 (none), 1 (view), 2 (edit), 3 (full); admins are 3 everywhere.
        // limited: a module-only login (no Departments, Tickets/assets/docs or Assets) - the API allows it
        // only me, notifications and its own modules' kb/reports/alerts.
        'is_admin'    => !empty($me_profile['admin']),
        'limited'     => itflow_profile_is_limited($me_profile),
        'permissions' => itflow_profile_permission_map($me_profile),
    ]);
}

if ($method === 'PUT' && isset(json_decode(file_get_contents('php://input'), true)['device_public_key_pem'])) {
    // Registers/replaces this device's biometric-gate public key, used by
    // api/v1/credentials.php to verify a signed step-up challenge instead of
    // trusting a client-supplied "biometric happened" claim. One key per
    // user (single-device model) - re-registering (reinstall, new device, a
    // biometric-enrollment-invalidated key regenerating) overwrites the previous key.
    //
    // IT-INJ-6: the bearer token alone must NOT be able to do this, or whoever holds a stolen token enrols their own
    // key and passes the very step-up that is meant to protect the credentials. So enrolment needs the account password
    // (and the TOTP / recovery code when MFA is on) in the body - the same proof PUT /me asks for to change the password.
    // Every attempt and outcome is written to the audit trail.
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $pem  = trim($body['device_public_key_pem'] ?? '');

    require_once __DIR__ . '/../../includes/security_policy.php';
    require_once __DIR__ . '/../../includes/security_crypto.php';
    require_once $DOCUMENT_ROOT . '/plugins/totp/totp.php';

    $bio_audit = static function (string $result, string $why) use ($api_user_id, $session_name): void {
        \ITFlow\Audit\AuditService::record('vault.api_stepup_key_enrol', (int) $api_user_id, 'user', (int) $api_user_id, $result, ($session_name ?? 'User') . ' - API biometric step-up key enrolment: ' . $why, ['via' => 'api']);
    };

    // Brute-force guard on the password check: the app's API limiter (needs Redis; fails open without it) plus a count of
    // recent denied attempts from the audit trail, which works without Redis.
    $bio_denied = mysqli_fetch_row(mysqli_query($mysqli,
        "SELECT COUNT(*) FROM audit_events WHERE event_type = 'vault.api_stepup_key_enrol' AND action = 'denied' AND actor_user_id = " . intval($api_user_id) . " AND created_at > (NOW() - INTERVAL 10 MINUTE)"));
    if (!api_rate_limit('stepup_enrol:' . intval($api_user_id), 10, 600) || (int) ($bio_denied[0] ?? 0) >= 5) {
        $bio_audit('blocked', 'rate limited');
        header('Retry-After: ' . api_rate_limit_retry_after());
        api_error(429, 'Too many attempts. Try again later.');
    }

    $bio_user = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT user_password, user_token FROM users WHERE user_id = " . intval($api_user_id) . " LIMIT 1"));
    $bio_pass = (string) ($body['current_password'] ?? '');
    if (!$bio_user || $bio_pass === '' || !password_verify($bio_pass, (string) $bio_user['user_password'])) {
        $bio_audit('denied', 'current password missing or wrong');
        api_error(401, 'Current password is required to register a biometric key');
    }
    $bio_totp_secret = secUserTotpSecret($bio_user['user_token'] ?? '', (int) $api_user_id);
    if (!empty($bio_totp_secret)) {
        $bio_code = trim((string) ($body['totp_code'] ?? ''));
        $bio_code_ok = $bio_code !== '' && (
            TokenAuth6238::verifyOnce($bio_totp_secret, $bio_code)
            || (secLooksLikeRecoveryCode($bio_code) && secRecoveryCodeConsume($mysqli, (int) $api_user_id, $bio_code, (string) ($_SERVER['REMOTE_ADDR'] ?? '')))
        );
        if (!$bio_code_ok) {
            $bio_audit('denied', 'MFA code missing or wrong');
            api_error(401, 'MFA code required to register a biometric key');
        }
    }

    if (!preg_match('/^-----BEGIN PUBLIC KEY-----.+-----END PUBLIC KEY-----\s*$/s', $pem)) {
        api_error(400, 'Invalid public key format');
    }
    if (!openssl_pkey_get_public($pem)) {
        api_error(400, 'Invalid public key');
    }

    $bio_had_key = (bool) mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM api_biometric_keys WHERE user_id = " . intval($api_user_id) . " LIMIT 1"));
    $bio_audit('success', $bio_had_key ? 'replaced the registered key' : 'registered a new key');

    $esc_pem = mysqli_real_escape_string($mysqli, $pem);
    mysqli_query($mysqli,
        "INSERT INTO api_biometric_keys (user_id, device_public_key_pem)
         VALUES ($api_user_id, '$esc_pem')
         ON DUPLICATE KEY UPDATE device_public_key_pem = '$esc_pem'"
    );
    api_response(200, ['ok' => true]);
}

if ($method === 'PUT' && isset(json_decode(file_get_contents('php://input'), true)['fcm_token'])) {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $fcm_token = mysqli_real_escape_string($mysqli, trim($body['fcm_token'] ?? ''));

    // Get the api_token row for this user's current Bearer token
    $auth_header = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';
    if (empty($auth_header) && function_exists('getallheaders')) {
        $hdrs = getallheaders();
        $auth_header = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $bearer_match);
    $token_hash_esc = mysqli_real_escape_string($mysqli, hash('sha256', $bearer_match[1] ?? ''));

    if ($fcm_token) {
        mysqli_query($mysqli, "UPDATE api_tokens SET token_fcm_token = '$fcm_token' WHERE token_hash = '$token_hash_esc' AND token_user_id = $api_user_id");
    } else {
        mysqli_query($mysqli, "UPDATE api_tokens SET token_fcm_token = NULL WHERE token_hash = '$token_hash_esc' AND token_user_id = $api_user_id");
    }
    api_response(200, ['ok' => true]);
}

if ($method === 'PUT' || $method === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $name     = mysqli_real_escape_string($mysqli, trim($body['name'] ?? ''));
    $email    = mysqli_real_escape_string($mysqli, trim($body['email'] ?? ''));
    $cur_pass = trim($body['current_password'] ?? '');
    $new_pass = trim($body['new_password'] ?? '');

    if (!$name || !$email) api_error(400, 'name and email required');

    $updates = ["user_name = '$name'", "user_email = '$email'"];

    if ($new_pass) {
        require_once __DIR__ . '/../../includes/security_policy.php';
        require_once __DIR__ . '/../../includes/security_sessions.php';
        require_once __DIR__ . '/../../includes/security_crypto.php';
        require_once $DOCUMENT_ROOT . '/plugins/totp/totp.php';
        // Verify current password
        $user = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT user_password, user_token FROM users WHERE user_id = $api_user_id LIMIT 1"));
        if (!password_verify($cur_pass, $user['user_password'])) {
            api_error(401, 'Current password is incorrect');
        }
        // A valid API bearer token alone used to be enough to change the account password - the token was only
        // ever checked against MFA once, at the login step that issued it. If MFA is on, this step-up re-checks
        // it too, the same as disabling MFA now does (agent/user/post/profile.php) - otherwise a stolen/leaked
        // bearer token could set a new password with no second factor at all.
        $own_totp_secret = secUserTotpSecret($user['user_token'] ?? '', (int) $api_user_id);
        if (!empty($own_totp_secret)) {
            $totp_code = trim((string) ($body['totp_code'] ?? ''));
            $totp_ok = !empty($totp_code) && (
                TokenAuth6238::verifyOnce($own_totp_secret, $totp_code)
                || (secLooksLikeRecoveryCode($totp_code) && secRecoveryCodeConsume($mysqli, (int) $api_user_id, $totp_code, (string) ($_SERVER['REMOTE_ADDR'] ?? '')))
            );
            if (!$totp_ok) {
                api_error(401, 'MFA code required to change your password');
            }
        }
        // Staff password policy (length from Admin > Settings > Security, not the name or email, optional breach check)
        $policy_error = secPasswordPolicyError($new_pass, ['name' => trim($body['name'] ?? ''), 'email' => trim($body['email'] ?? ''), 'username' => trim($body['email'] ?? '')], $mysqli);
        if ($policy_error !== null) api_error(400, $policy_error);
        $hash = secPasswordHash($new_pass);
        $esc_hash = mysqli_real_escape_string($mysqli, $hash);
        $updates[] = "user_password = '$esc_hash'";
    }

    $set = implode(', ', $updates);
    mysqli_query($mysqli, "UPDATE users SET $set WHERE user_id = $api_user_id");
    if ($new_pass) {
        // A changed password signs every browser session out and ends remember-me cookies.
        secSessionsOnPasswordChange($mysqli, intval($api_user_id), null);
    }
    api_response(200, ['ok' => true]);
}

api_error(405, 'Method not allowed');
