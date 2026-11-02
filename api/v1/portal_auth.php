<?php
// POST   /api/v1/portal_auth   {"username":"<portal email>","password":"...","totp_code":"123456","device_name":"..."}
//                              -> 200 {token, contact:{id,name,department_id}} | 200 {requires_2fa:true} | 401 | 403 | 429
// DELETE /api/v1/portal_auth   (Authorization: Bearer <employee token>)  -> 200 {ok:true}   signs this device out
// Employee sign-in for the mobile / API view of the department portal. Same rules as the web portal login (login.php): a local-password
// portal user (user_type 2), active, not archived, whose contact and department are not archived, with the portal switched on and 2FA
// honoured. Single sign-on (Microsoft / OIDC / Odoo) accounts have no local password and cannot use this. UNVERIFIED LIVE against a real
// directory; covered by tests/employee_portal_i32.php on a scratch install.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/portal_api.php';
require_once $DOCUMENT_ROOT . '/plugins/totp/totp.php';
require_once $DOCUMENT_ROOT . '/includes/security_crypto.php';
require_once $DOCUMENT_ROOT . '/includes/security_policy.php';

if ($method === 'DELETE') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+([0-9a-f]{64})$/i', $auth, $bm)) {
        $hash = mysqli_real_escape_string($mysqli, hash('sha256', strtolower($bm[1])));
        mysqli_query($mysqli, "DELETE FROM portal_api_tokens WHERE token_hash = '$hash'");
    }
    api_response(200, ['ok' => true]);
}
if ($method !== 'POST') {
    header('Allow: POST, DELETE');
    api_error(405, 'Method not allowed');
}

$body = json_decode(file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
$totp = trim((string) ($body['totp_code'] ?? ''));
$device = trim((string) ($body['device_name'] ?? 'Mobile App'));
if ($username === '' || $password === '') {
    api_error(400, 'username and password required');
}

$esc_user = mysqli_real_escape_string($mysqli, $username);
$user = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT u.user_id, u.user_name, u.user_email, u.user_password, u.user_token, u.user_failed_login_count, u.user_failed_login_at,
            c.contact_id, c.contact_name, c.contact_client_id, us.user_config_force_mfa
     FROM users u
     JOIN contacts c ON c.contact_user_id = u.user_id AND c.contact_archived_at IS NULL
     JOIN clients cl ON cl.client_id = c.contact_client_id AND cl.client_archived_at IS NULL
     LEFT JOIN user_settings us ON us.user_id = u.user_id
     WHERE u.user_email = '$esc_user' AND u.user_type = 2 AND u.user_status = 1 AND u.user_archived_at IS NULL AND u.user_auth_method = 'local'
     LIMIT 1"));

if ($user) {
    $fail_count = intval($user['user_failed_login_count'] ?? 0);
    $fail_time = strtotime(($user['user_failed_login_at'] ?? '1970-01-01') . ' UTC');
    if ($fail_count >= 5 && (time() - $fail_time) < 300) {
        api_error(429, 'Too many failed attempts. Try again in 5 minutes.');
    }
    if ($fail_count >= 5) {
        mysqli_query($mysqli, "UPDATE users SET user_failed_login_count = 0 WHERE user_id = " . intval($user['user_id']));
    }
}

// Always verify a hash (a dummy one for an unknown login) so a wrong login and a wrong password take the same time.
$dummy_hash = '$argon2id$v=19$m=65536,t=3,p=1$N1VaRC5XUEdVbnU5Z2UubA$C4R2R3xEKv6O2YVS6Sq6jZL+L90lDoCQBl+nigKL/t8';
$password_ok = password_verify($password, $user ? (string) $user['user_password'] : $dummy_hash);

if (!$user || !$password_ok || intval($config_client_portal_enable ?? 0) !== 1) {
    if ($user && !$password_ok) {
        mysqli_query($mysqli, "UPDATE users SET user_failed_login_count = user_failed_login_count + 1, user_failed_login_at = UTC_TIMESTAMP() WHERE user_id = " . intval($user['user_id']));
    }
    api_error(401, 'Invalid credentials');
}

$uid = intval($user['user_id']);
$totp_secret = secUserTotpSecret($user['user_token'] ?? '', $uid);
if (!empty($totp_secret)) {
    if ($totp === '') {
        api_response(200, ['requires_2fa' => true]);
    }
    if (!(strlen($totp) === 6 && ctype_digit($totp) && TokenAuth6238::verifyOnce($totp_secret, $totp, 1))) {
        mysqli_query($mysqli, "UPDATE users SET user_failed_login_count = user_failed_login_count + 1, user_failed_login_at = UTC_TIMESTAMP() WHERE user_id = $uid");
        api_error(401, 'Invalid 2FA code');
    }
} elseif (intval($user['user_config_force_mfa'] ?? 0) === 1) {
    // An administrator requires 2FA and this login has none yet: it must be set up in the web portal first (same gate as the web).
    api_error(403, 'Two-factor authentication is required. Set it up in the web portal first.');
}

mysqli_query($mysqli, "UPDATE users SET user_failed_login_count = 0 WHERE user_id = $uid");

$raw_token = bin2hex(random_bytes(32));
$esc_hash = mysqli_real_escape_string($mysqli, hash('sha256', $raw_token));
$esc_device = mysqli_real_escape_string($mysqli, substr($device, 0, 100));
mysqli_query($mysqli, "INSERT INTO portal_api_tokens (token_hash, token_user_id, token_contact_id, token_name) VALUES ('$esc_hash', $uid, " . intval($user['contact_id']) . ", '$esc_device')");

$session_ip = getIP();
$session_user_agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
$session_user_id = $uid;
logAction('Login', 'Success', "{$user['contact_name']} signed in to the employee API", intval($user['contact_client_id']), $uid);

api_response(200, [
    'token' => $raw_token,
    'contact' => ['id' => intval($user['contact_id']), 'name' => (string) $user['contact_name'], 'department_id' => intval($user['contact_client_id'])],
]);
