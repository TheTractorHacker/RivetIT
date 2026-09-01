<?php
// Verifies and stores a newly-registered passkey
// Expects JSON body: { id, response: { clientDataJSON, attestationObject }, passkeyName }

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/session_init.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/webauthn.php';
ob_end_clean();

header('Content-Type: application/json');

if (empty($_SESSION['logged']) || empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}
$session_user_id = intval($_SESSION['user_id']);
$userRow = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT user_name FROM users WHERE user_id = $session_user_id AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1"
));
if (!$userRow) {
    echo json_encode(['ok' => false, 'error' => 'Account not found']);
    exit;
}
$session_name = $userRow['user_name'];

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit; }

$challenge = $_SESSION['passkey_register_challenge'] ?? null;
if (!$challenge) { echo json_encode(['ok' => false, 'error' => 'No registration challenge in session']); exit; }
unset($_SESSION['passkey_register_challenge']);

try {
    $result = wa_verify_registration(
        $body['response']['clientDataJSON']    ?? '',
        $body['response']['attestationObject'] ?? '',
        $challenge
    );

    $credId    = $result['credId'];
    $pubKey    = mysqli_real_escape_string($mysqli, $result['pubKeyPem']);
    $signCount = intval($result['signCount']);
    $aaguid    = sanitizeInput($result['aaguid']);
    $name      = sanitizeInput($body['passkeyName'] ?? 'Passkey');
    $uid       = $session_user_id;

    // Prevent duplicate credential ID
    $dup = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT passkey_id FROM user_passkeys WHERE passkey_credential_id = '$credId' LIMIT 1"));
    if ($dup) { echo json_encode(['ok' => false, 'error' => 'Passkey already registered']); exit; }

    mysqli_query($mysqli, "INSERT INTO user_passkeys SET
        passkey_user_id = $uid,
        passkey_name = '$name',
        passkey_credential_id = '$credId',
        passkey_public_key = '$pubKey',
        passkey_sign_count = $signCount,
        passkey_aaguid = '$aaguid'
    ");

    logAction("Passkey", "Register", "$session_name registered passkey $name", 0, $uid);

    // Seed the passkey-stored encryption key from this (password-authenticated)
    // session's true master key, so the first passkey login never needs to self-heal.
    if (!empty($_SESSION['user_encryption_session_ciphertext']) && isset($_COOKIE['user_encryption_session_key'])) {
        $true_master_key = openssl_decrypt(
            $_SESSION['user_encryption_session_ciphertext'],
            'aes-128-cbc',
            $_COOKIE['user_encryption_session_key'],
            0,
            $_SESSION['user_encryption_session_iv']
        );
        if (!empty($true_master_key)) {
            storePasskeyEncKey($mysqli, $session_user_id, $true_master_key, $config_https_only, PASSKEY_ENC_KEY_LIFETIME);
        }
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    $_err_safe = substr(preg_replace("/['\\\\']/", '', $e->getMessage()), 0, 200);
    logAction("Passkey", "Register Failed", "Passkey registration failed: $_err_safe");
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
