<?php
// Discoverable-credential assertion options — no email needed.
// The browser shows the OS passkey picker and the user selects their account.

ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/redirect_if_setup_enabled.php';
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/webauthn.php';
ob_end_clean();

header('Content-Type: application/json');

$challenge = random_bytes(32);
$_SESSION['passkey_auth_challenge'] = wa_b64u_encode($challenge);

// No allowCredentials = discoverable / usernameless flow.
// The authenticator returns userHandle which we decode in passkey_auth_complete.php.
echo json_encode([
    'challenge'        => wa_b64u_encode($challenge),
    'timeout'          => 60000,
    'rpId'             => wa_rp_id(),
    'allowCredentials' => [],
    'userVerification' => 'required',
]);
