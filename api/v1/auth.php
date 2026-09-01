<?php
// POST   /api/v1/auth  -> login (returns token, or requires_2fa: true)
// DELETE /api/v1/auth  -> logout (revoke token)
defined('FROM_API') || die();

require_once $DOCUMENT_ROOT . '/plugins/totp/totp.php';

// ── Passkey begin (GET) ──────────────────────────────────────────────────────
// Returns a WebAuthn assertion challenge stored in api_passkey_challenges.
// No authentication required — anyone can request a challenge; identity is
// proven during the completion step.
if ($method === 'GET') {
    require_once $DOCUMENT_ROOT . '/includes/webauthn.php';
    require_once $DOCUMENT_ROOT . '/includes/load_global_settings.php';

    mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS api_passkey_challenges (
        challenge_token VARCHAR(64)  NOT NULL PRIMARY KEY,
        challenge_b64u  VARCHAR(100) NOT NULL,
        created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_query($mysqli, "DELETE FROM api_passkey_challenges
                           WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

    $challenge       = random_bytes(32);
    $challenge_b64u  = wa_b64u_encode($challenge);
    $challenge_token = bin2hex(random_bytes(32));
    $esc_token       = mysqli_real_escape_string($mysqli, $challenge_token);
    $esc_challenge   = mysqli_real_escape_string($mysqli, $challenge_b64u);

    mysqli_query($mysqli, "INSERT INTO api_passkey_challenges
                           (challenge_token, challenge_b64u) VALUES ('$esc_token', '$esc_challenge')");

    api_response(200, [
        'challenge'        => $challenge_b64u,
        'timeout'          => 60000,
        'rpId'             => wa_rp_id(),
        'allowCredentials' => [],
        'userVerification' => 'preferred',
        'challengeToken'   => $challenge_token,
    ]);
}

// ── Logout ───────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $authH = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        $authH = $authH ?: ($h['Authorization'] ?? $h['authorization'] ?? '');
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', $authH, $bm)) {
        $hash = mysqli_real_escape_string($mysqli, hash('sha256', $bm[1]));
        mysqli_query($mysqli, "DELETE FROM api_tokens WHERE token_hash = '$hash'");
    }
    api_response(200, ['ok' => true]);
}

if ($method !== 'POST') api_error(405, 'Method not allowed');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Passkey complete (POST with passkey_response) ─────────────────────────────
if (isset($body['passkey_response'], $body['challenge_token'])) {
    require_once $DOCUMENT_ROOT . '/includes/webauthn.php';
    require_once $DOCUMENT_ROOT . '/includes/load_global_settings.php';

    $challenge_token = $body['challenge_token'];
    $esc_token = mysqli_real_escape_string($mysqli, $challenge_token);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT challenge_b64u FROM api_passkey_challenges
         WHERE challenge_token = '$esc_token'
           AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1"
    ));
    if (!$row) api_error(400, 'Challenge not found or expired — please try again');
    mysqli_query($mysqli, "DELETE FROM api_passkey_challenges WHERE challenge_token = '$esc_token'");

    $pr = $body['passkey_response'];
    $credId = $pr['id'] ?? '';
    if (empty($credId)) api_error(400, 'Missing credential ID');

    $userHandleB64 = $pr['response']['userHandle'] ?? '';
    if (empty($userHandleB64)) api_error(400, 'No user handle — passkey may need re-registration');
    $userHandleBytes = wa_b64u_decode($userHandleB64);
    if (strlen($userHandleBytes) < 4) api_error(400, 'Invalid user handle');
    $userId = intval(unpack('N', substr($userHandleBytes, 0, 4))[1]);
    if ($userId <= 0) api_error(400, 'Could not identify user from passkey');

    $credSafe = mysqli_real_escape_string($mysqli, $credId);
    $pkRow = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT * FROM user_passkeys
         WHERE passkey_credential_id = '$credSafe' AND passkey_user_id = $userId LIMIT 1"
    ));
    if (!$pkRow) api_error(401, 'Passkey not found');

    $userRow = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT * FROM users
         WHERE user_id = $userId AND user_type = 1 AND user_status = 1
           AND user_archived_at IS NULL LIMIT 1"
    ));
    if (!$userRow) api_error(401, 'Account not found or inactive');

    // Android clients send origin as android:apk-key-hash:<base64url>
    // Build the allowed list from global config (set in config.php or admin settings)
    global $config_passkey_android_origins;
    $androidOrigins = is_array($config_passkey_android_origins ?? null)
        ? $config_passkey_android_origins : [];

    try {
        $newSignCount = wa_verify_assertion(
            $credId,
            $pr['response']['clientDataJSON']    ?? '',
            $pr['response']['authenticatorData'] ?? '',
            $pr['response']['signature']         ?? '',
            $pkRow['passkey_public_key'],
            intval($pkRow['passkey_sign_count']),
            $row['challenge_b64u'],
            $androidOrigins,
            false   // requireUV=false — we ask for 'preferred', not 'required'
        );

        $passkey_id = intval($pkRow['passkey_id']);
        mysqli_query($mysqli,
            "UPDATE user_passkeys SET passkey_sign_count = $newSignCount,
             passkey_last_used_at = NOW() WHERE passkey_id = $passkey_id"
        );

        // Issue API token
        $uid        = $userId;
        $raw_token  = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $raw_token);
        $esc_hash   = mysqli_real_escape_string($mysqli, $token_hash);
        $esc_device = mysqli_real_escape_string($mysqli, 'ITFlow MSP Android (passkey)');

        // Recover master key via passkey bootstrap key if available
        $master_key = null;
        $bootstrap_key = $userRow['user_passkey_bootstrap_key'] ?? null;
        if ($bootstrap_key && !empty($userRow['user_passkey_enc_ciphertext'])) {
            $pk_iv      = base64_decode($userRow['user_passkey_enc_iv']);
            $master_key = openssl_decrypt($userRow['user_passkey_enc_ciphertext'], 'aes-128-cbc', $bootstrap_key, 0, $pk_iv);
        }
        if (empty($master_key)) {
            $master_key = getCanonicalVaultKey($mysqli) ?? '';
        }

        $enc_key     = substr(hash('sha256', $raw_token . 'itflow_enc', true), 0, 16);
        $enc_iv      = random_bytes(16);
        $enc_key_str = openssl_encrypt($master_key, 'aes-128-cbc', $enc_key, 0, $enc_iv);
        $esc_enc_key = mysqli_real_escape_string($mysqli, $enc_key_str);
        $esc_enc_iv  = mysqli_real_escape_string($mysqli, bin2hex($enc_iv));

        mysqli_query($mysqli,
            "INSERT INTO api_tokens
             (token_user_id, token_hash, token_name, token_enc_master_key, token_enc_master_iv, token_created_at)
             VALUES ($uid, '$esc_hash', '$esc_device', '$esc_enc_key', '$esc_enc_iv', NOW())"
        );

        logAction('Login', 'Success', "{$userRow['user_name']} logged in via passkey (mobile API)");

        api_response(200, [
            'token' => $raw_token,
            'user'  => [
                'id'    => $uid,
                'name'  => $userRow['user_name'],
                'email' => $userRow['user_email'],
                'type'  => intval($userRow['user_type']),
            ],
        ]);
    } catch (Throwable $e) {
        $err = preg_replace("/['\\']/", '', $e->getMessage());
        logAction('Login', 'Failed', "Mobile passkey auth failed for user $userId: $err");
        api_error(401, 'Passkey authentication failed');
    }
}

// ── Password login ────────────────────────────────────────────────────────────
$username = trim($body['username'] ?? '');
$password = trim($body['password'] ?? '');
$totp     = trim($body['totp_code'] ?? '');
$device   = trim($body['device_name'] ?? 'Mobile App');

if (!$username || !$password) api_error(400, 'username and password required');

$esc_user = mysqli_real_escape_string($mysqli, $username);
$sql      = mysqli_query($mysqli,
    "SELECT user_id, user_name, user_email, user_type, user_password,
            user_specific_encryption_ciphertext, user_token,
            user_failed_login_count, user_failed_login_at
     FROM users
     WHERE (user_name = '$esc_user' OR user_email = '$esc_user')
     AND user_type = 1
     AND user_status = 1
     AND user_archived_at IS NULL
     LIMIT 1"
);
$user = mysqli_fetch_assoc($sql);

// ── Rate limiting (5 failures → 5-minute lockout) ────────────────────────────
if ($user) {
    $fail_count = intval($user['user_failed_login_count'] ?? 0);
    $fail_time  = strtotime($user['user_failed_login_at'] ?? '1970-01-01');
    if ($fail_count >= 5 && (time() - $fail_time) < 300) {
        api_error(429, 'Too many failed attempts. Try again in 5 minutes.');
    }
    if ($fail_count >= 5 && (time() - $fail_time) >= 300) {
        // Reset after lockout window passes
        $uid_rl = intval($user['user_id']);
        mysqli_query($mysqli, "UPDATE users SET user_failed_login_count = 0 WHERE user_id = $uid_rl");
        $user['user_failed_login_count'] = 0;
    }
}

// ── Password verification (constant-time to prevent user enumeration) ─────────
// Always call password_verify — even for non-existent users — so timing is
// indistinguishable between "wrong username" and "wrong password". This must run
// unconditionally, BEFORE the `!$user ||` check below — PHP's `||` short-circuits,
// so folding the call into that condition would skip password_verify() entirely
// for a nonexistent user and reintroduce the exact timing oracle this comment
// describes preventing.
// Cost must match real user hashes (this install's PASSWORD_DEFAULT resolves to bcrypt
// cost 12, not the commonly-assumed 10) or the dummy comparison itself becomes a smaller
// but still measurable timing oracle between valid and invalid usernames.
$dummy_hash = '$2y$12$mCEDSkd7cWaDz5Quq.mMlOg6GgQ1yX6yubN/yr07IPX0tMnq8X0tC';
$hash_to_check = $user ? $user['user_password'] : $dummy_hash;
$password_ok = password_verify($password, $hash_to_check);

if (!$user || !$password_ok) {
    if ($user) {
        $uid_fail = intval($user['user_id']);
        mysqli_query($mysqli,
            "UPDATE users SET
                user_failed_login_count = user_failed_login_count + 1,
                user_failed_login_at = UTC_TIMESTAMP()
             WHERE user_id = $uid_fail"
        );
    }
    api_error(401, 'Invalid credentials');
}

// ── Reset failure counter on success ─────────────────────────────────────────
$uid = intval($user['user_id']);
mysqli_query($mysqli, "UPDATE users SET user_failed_login_count = 0 WHERE user_id = $uid");

// ── 2FA (TOTP) ───────────────────────────────────────────────────────────────
$totp_secret = $user['user_token'] ?? '';
if (!empty($totp_secret)) {
    if (empty($totp)) {
        api_response(200, ['requires_2fa' => true]);
    }
    if (!TokenAuth6238::verify($totp_secret, intval($totp))) {
        api_error(401, 'Invalid 2FA code');
    }
}

// ── Issue token ───────────────────────────────────────────────────────────────
$raw_token  = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $raw_token);
$esc_hash   = mysqli_real_escape_string($mysqli, $token_hash);
$esc_device = mysqli_real_escape_string($mysqli, substr($device, 0, 100));

// Derive and store master encryption key
$master_key  = decryptUserSpecificKey($user['user_specific_encryption_ciphertext'] ?? '', $password);
$enc_key     = substr(hash('sha256', $raw_token . 'itflow_enc', true), 0, 16);
$enc_iv      = random_bytes(16);
$enc_key_str = openssl_encrypt($master_key ?: '', 'aes-128-cbc', $enc_key, 0, $enc_iv);
$esc_enc_key = mysqli_real_escape_string($mysqli, $enc_key_str);
$esc_enc_iv  = mysqli_real_escape_string($mysqli, bin2hex($enc_iv));

mysqli_query($mysqli,
    "INSERT INTO api_tokens
     (token_user_id, token_hash, token_name, token_enc_master_key, token_enc_master_iv, token_created_at)
     VALUES ($uid, '$esc_hash', '$esc_device', '$esc_enc_key', '$esc_enc_iv', NOW())"
);

api_response(200, [
    'token' => $raw_token,
    'user'  => [
        'id'    => $uid,
        'name'  => $user['user_name'],
        'email' => $user['user_email'],
        'type'  => intval($user['user_type']),
    ],
]);
