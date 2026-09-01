<?php
// Verifies a discoverable-credential assertion and creates a session.
// User identity comes from userHandle (set during registration as pack('N', userId)).

ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/redirect_if_setup_enabled.php';
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/webauthn.php';
require_once __DIR__ . '/includes/load_global_settings.php';
ob_end_clean();

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { echo json_encode(['ok' => false, 'error' => 'Invalid request']); exit; }

$challenge = $_SESSION['passkey_auth_challenge'] ?? null;
if (!$challenge) {
    echo json_encode(['ok' => false, 'error' => 'No challenge in session — please try again']);
    exit;
}
unset($_SESSION['passkey_auth_challenge']);

$credId = $body['id'] ?? '';
if (empty($credId)) { echo json_encode(['ok' => false, 'error' => 'Missing credential ID']); exit; }

// ── Identify user from userHandle ─────────────────────────────────────────────
// userHandle was stored as wa_b64u_encode(pack('N', $userId)) during registration.
$userHandleB64 = $body['response']['userHandle'] ?? '';
if (empty($userHandleB64)) {
    echo json_encode(['ok' => false, 'error' => 'No user handle in response — passkey may need to be re-registered']);
    exit;
}
$userHandleBytes = wa_b64u_decode($userHandleB64);
if (strlen($userHandleBytes) < 4) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user handle']);
    exit;
}
$userId = intval(unpack('N', substr($userHandleBytes, 0, 4))[1]);
if ($userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Could not identify user from passkey']);
    exit;
}

// ── Load passkey record ───────────────────────────────────────────────────────
$credSafe = mysqli_real_escape_string($mysqli, $credId);
$pkRow    = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT * FROM user_passkeys
     WHERE passkey_credential_id = '$credSafe' AND passkey_user_id = $userId LIMIT 1"
));
if (!$pkRow) {
    echo json_encode(['ok' => false, 'error' => 'Passkey not found']);
    exit;
}

// ── Load user ─────────────────────────────────────────────────────────────────
$userRow = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT * FROM users LEFT JOIN user_settings ON users.user_id = user_settings.user_id
     WHERE users.user_id = $userId AND users.user_type = 1
       AND users.user_status = 1 AND users.user_archived_at IS NULL LIMIT 1"
));
if (!$userRow) {
    echo json_encode(['ok' => false, 'error' => 'Account not found or inactive']);
    exit;
}

// ── Verify assertion ──────────────────────────────────────────────────────────
try {
    $newSignCount = wa_verify_assertion(
        $credId,
        $body['response']['clientDataJSON']    ?? '',
        $body['response']['authenticatorData'] ?? '',
        $body['response']['signature']         ?? '',
        $pkRow['passkey_public_key'],
        intval($pkRow['passkey_sign_count']),
        $challenge
    );

    $passkey_id = intval($pkRow['passkey_id']);
    mysqli_query($mysqli,
        "UPDATE user_passkeys SET passkey_sign_count = $newSignCount, passkey_last_used_at = NOW()
         WHERE passkey_id = $passkey_id"
    );

    // ── Create session ────────────────────────────────────────────────────────
    $_SESSION['user_id']    = $userId;
    $_SESSION['csrf_token'] = randomString(32);
    $_SESSION['logged']     = true;
    session_regenerate_id(true);

    $user_name  = sanitizeInput($userRow['user_name']);
    $user_email = sanitizeInput($userRow['user_email']);

    $session_ip         = sanitizeInput(getIP());
    $session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? '');

    $ip_prev = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(log_id) AS c FROM logs
         WHERE log_type='Login' AND log_action='Success'
           AND log_ip='$session_ip' AND log_user_id=$userId"
    ))['c']);
    $ua_prev = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(log_id) AS c FROM logs
         WHERE log_type='Login' AND log_action='Success'
           AND log_user_agent='$session_user_agent' AND log_user_id=$userId"
    ))['c']);

    if ($ip_prev === 0 && $ua_prev === 0 && !empty($config_smtp_host)) {
        $data = [[
            'from'           => $config_mail_from_email,
            'from_name'      => $config_mail_from_name,
            'recipient'      => $user_email,
            'recipient_name' => $user_name,
            'subject'        => 'New login to your ITFlow account',
            'body'           => "Hi $user_name,<br><br>A passkey sign-in was detected from a new device or location.<br><br>IP: $session_ip<br>Browser: $session_user_agent<br><br>If this was not you, please contact your administrator immediately.",
        ]];
        addToMailQueue($data);
    }

    $session_user_id = $userId;
    logAction('Login', 'Success', "$user_name logged in via passkey");

    // Restore credential encryption session from stored passkey enc key
    $passkey_cookie_key = $_COOKIE['user_passkey_enc_key'] ?? null;
    $bootstrap_key      = $userRow['user_passkey_bootstrap_key'] ?? null;
    $active_enc_key     = $passkey_cookie_key ?? $bootstrap_key;

    $master_key = null;
    if ($active_enc_key && !empty($userRow['user_passkey_enc_ciphertext'])) {
        $pk_iv      = base64_decode($userRow['user_passkey_enc_iv']);
        $master_key = openssl_decrypt($userRow['user_passkey_enc_ciphertext'], 'aes-128-cbc', $active_enc_key, 0, $pk_iv);
    }

    if (empty($master_key)) {
        // Passkey-enc decryption failed (missing/expired cookie, first passkey login,
        // or cross-device). Fall back to the canonical vault key so the credential
        // vault stays accessible. storePasskeyEncKey() below refreshes the cookie
        // so subsequent passkey logins use the faster enc path.
        $master_key = getCanonicalVaultKey($mysqli);
    }

    if (!empty($master_key)) {
        generateUserSessionKey($master_key);
        // Re-store to refresh the cookie's expiry and clear any stale bootstrap key.
        storePasskeyEncKey($mysqli, $userId, $master_key, $config_https_only, PASSKEY_ENC_KEY_LIFETIME);
    }
    // else: this account has a real password-derived vault, but the true master key
    // couldn't be recovered via the passkey cookie. Leave the credential encryption
    // session unset rather than minting an unrelated key that would overwrite
    // user_passkey_enc_ciphertext - agent/credentials.php shows a "vault locked, sign
    // in with your password" notice in this case, and a password login resyncs the
    // passkey cookie with the true key.

    $start = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT config_start_page FROM settings WHERE company_id = 1 LIMIT 1"
    ))['config_start_page'] ?? 'dashboard.php';

    echo json_encode(['ok' => true, 'redirect' => "/agent/$start"]);

} catch (Throwable $e) {
    $session_user_id = $userId;
    $err = preg_replace("/['\\']/", '', $e->getMessage());
    logAction('Login', 'Failed', "Passkey auth failed: $err");
    echo json_encode(['ok' => false, 'error' => 'Authentication failed']);
}
