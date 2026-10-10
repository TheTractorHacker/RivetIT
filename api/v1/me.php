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
    // user (single-device model, matching how api_tokens are already
    // per-login rather than per-device) - re-registering (e.g. reinstall,
    // new device, or a biometric-enrollment-invalidated key regenerating)
    // simply overwrites the previous key.
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $pem  = trim($body['device_public_key_pem'] ?? '');

    if (!preg_match('/^-----BEGIN PUBLIC KEY-----.+-----END PUBLIC KEY-----\s*$/s', $pem)) {
        api_error(400, 'Invalid public key format');
    }
    if (!openssl_pkey_get_public($pem)) {
        api_error(400, 'Invalid public key');
    }

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
        // Verify current password
        $user = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT user_password FROM users WHERE user_id = $api_user_id LIMIT 1"));
        if (!password_verify($cur_pass, $user['user_password'])) {
            api_error(401, 'Current password is incorrect');
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
