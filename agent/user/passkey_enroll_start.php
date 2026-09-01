<?php
// Returns WebAuthn PublicKeyCredentialCreationOptions as JSON
// Must be called while authenticated

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/session_init.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/webauthn.php';
ob_end_clean();

header('Content-Type: application/json');

// Auth check — return JSON instead of redirect for AJAX
if (empty($_SESSION['logged']) || empty($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Load minimal user context
$session_user_id = intval($_SESSION['user_id']);
$userRow = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT user_name, user_email FROM users WHERE user_id = $session_user_id AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1"
));
if (!$userRow) {
    echo json_encode(['error' => 'Account not found']);
    exit;
}
$session_name  = $userRow['user_name'];
$session_email = $userRow['user_email'];

$challenge = random_bytes(32);
$_SESSION['passkey_register_challenge'] = wa_b64u_encode($challenge);

$rpId   = wa_rp_id();
$origin = wa_origin();

// Gather already-registered credential IDs to exclude (prevent duplicate registration)
$excludeCredentials = [];
$sql_exc = mysqli_query($mysqli, "SELECT passkey_credential_id FROM user_passkeys WHERE passkey_user_id = $session_user_id");
while ($exc = mysqli_fetch_assoc($sql_exc)) {
    $excludeCredentials[] = [
        'type' => 'public-key',
        'id'   => $exc['passkey_credential_id'],
    ];
}

echo json_encode([
    'rp'    => ['id' => $rpId, 'name' => 'ITFlow'],
    'user'  => [
        'id'          => wa_b64u_encode(pack('N', $session_user_id)),
        'name'        => $session_email,
        'displayName' => $session_name,
    ],
    'challenge'              => wa_b64u_encode($challenge),
    'pubKeyCredParams'       => [
        ['type' => 'public-key', 'alg' => -7],   // ES256
        ['type' => 'public-key', 'alg' => -257],  // RS256
    ],
    'timeout'                => 60000,
    'excludeCredentials'     => $excludeCredentials,
    'authenticatorSelection' => [
        'residentKey'        => 'required',
        'userVerification'   => 'preferred',
    ],
    'attestation'            => 'none',
]);
