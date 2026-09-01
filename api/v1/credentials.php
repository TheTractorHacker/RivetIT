<?php
// GET /api/v1/credentials           list (usernames only, no secrets)
// GET /api/v1/credentials/{id}      detail with decrypted secrets — requires a
//                                   signed biometric step-up challenge (see
//                                   _api_verify_biometric_assertion() below)
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once $DOCUMENT_ROOT . '/includes/webauthn.php'; // for wa_b64u_decode()
if ($method !== 'GET') api_error(405, 'Method not allowed');

api_require_module_permission($mysqli, $api_user_id, 'module_credential');

// Helper: decrypt a credential field using the token's stored master key
function _api_decrypt(string $ciphertext, string $raw_token, array $token_row): string {
    if (!$ciphertext || !$token_row['token_enc_master_key']) return '';
    $enc_key    = substr(hash('sha256', $raw_token . 'itflow_enc', true), 0, 16);
    $enc_iv     = hex2bin($token_row['token_enc_master_iv']);
    $master_key = openssl_decrypt($token_row['token_enc_master_key'], 'aes-128-cbc', $enc_key, 0, $enc_iv);
    if (!$master_key) return '';
    $cred_iv    = substr($ciphertext, 0, 16);
    $cred_ct    = substr($ciphertext, 16);
    return openssl_decrypt($cred_ct, 'aes-128-cbc', $master_key, 0, $cred_iv) ?: '';
}

// Extract raw token from Authorization header for decryption
$raw_token = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $bm)) {
    $raw_token = $bm[1];
}

if ($id === null) {
    $page      = max(1, intval($_GET['page'] ?? 1));
    $limit     = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset    = ($page - 1) * $limit;
    $search    = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;

    $where = [
        'credential_archived_at IS NULL',
        api_client_scope_sql('credential_client_id'),
    ];
    if ($client_id) $where[] = "credential_client_id = $client_id";
    if ($search)    $where[] = "(credential_name LIKE '%$search%' OR credential_uri LIKE '%$search%')";
    $w = implode(' AND ', $where);

    $total = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM credentials WHERE $w"))['c']);
    $creds = [];
    $sql   = mysqli_query($mysqli,
        "SELECT cr.credential_id, cr.credential_name, cr.credential_uri,
                c.client_name
         FROM credentials cr LEFT JOIN clients c ON cr.credential_client_id = c.client_id
         WHERE $w ORDER BY cr.credential_name ASC LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $creds[] = [
            'id'     => intval($row['credential_id']),
            'name'   => $row['credential_name'],
            'uri'    => $row['credential_uri'],
            'client' => $row['client_name'],
        ];
    }
    api_response(200, ['data' => $creds, 'total' => $total]);
}

// Detail — requires a signed biometric step-up challenge from the device.
//
// Previously this checked a static "X-Biometric: 1" header the client could
// set to any value it liked - the server had no way to verify a real
// biometric event occurred. The client now must:
//   1. GET /api/v1/auth (already used for passkey login) to obtain a fresh,
//      single-use challenge + challengeToken.
//   2. Prompt biometric auth locally, then sign the raw challenge bytes with
//      a device Keystore key that itself requires biometric auth to use
//      (setUserAuthenticationRequired(true)), and whose public key was
//      previously registered via PUT /api/v1/me {device_public_key_pem}.
//   3. Send the result as X-Biometric-Challenge-Token / X-Biometric-Signature.
//
// This is real cryptographic proof, bound to a fresh nonce (so a captured
// header pair can't be replayed) and to a key that only exists/works after a
// real biometric unlock on that specific device.
function _api_verify_biometric_assertion($mysqli, int $api_user_id): void {
    $challenge_token = $_SERVER['HTTP_X_BIOMETRIC_CHALLENGE_TOKEN'] ?? '';
    $signature_b64   = $_SERVER['HTTP_X_BIOMETRIC_SIGNATURE'] ?? '';
    if (!$challenge_token || !$signature_b64) {
        api_error(403, 'Biometric verification required');
    }

    $esc_token = mysqli_real_escape_string($mysqli, $challenge_token);
    $chal_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT challenge_b64u FROM api_passkey_challenges
         WHERE challenge_token = '$esc_token'
           AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1"
    ));
    if (!$chal_row) {
        api_error(403, 'Biometric challenge not found or expired');
    }
    // Single-use: consume it immediately, before verification, same as the
    // passkey login flow - a challenge can only ever be spent once.
    mysqli_query($mysqli, "DELETE FROM api_passkey_challenges WHERE challenge_token = '$esc_token'");

    $key_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT device_public_key_pem FROM api_biometric_keys WHERE user_id = $api_user_id LIMIT 1"
    ));
    if (!$key_row) {
        api_error(403, 'No biometric key registered for this device');
    }

    $challenge_bytes = wa_b64u_decode($chal_row['challenge_b64u']);
    $signature = base64_decode($signature_b64, true);
    if ($signature === false) {
        api_error(403, 'Invalid biometric signature encoding');
    }

    $pub_key = openssl_pkey_get_public($key_row['device_public_key_pem']);
    if (!$pub_key) {
        api_error(403, 'Invalid registered biometric key');
    }

    $verify_result = openssl_verify($challenge_bytes, $signature, $pub_key, OPENSSL_ALGO_SHA256);
    if ($verify_result !== 1) {
        api_error(403, 'Biometric verification failed');
    }
}

_api_verify_biometric_assertion($mysqli, $api_user_id);

$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT cr.*, c.client_name FROM credentials cr
     LEFT JOIN clients c ON cr.credential_client_id = c.client_id
     WHERE cr.credential_id = $id AND cr.credential_archived_at IS NULL LIMIT 1"
));
if (!$row) api_error(404, 'Credential not found');

// Verify the API user has permission to access this credential's client
$cred_client_id = intval($row['credential_client_id']);
if ($cred_client_id > 0 && !api_client_scope_ok($cred_client_id)) {
    api_error(403, 'Access denied');
}

api_response(200, [
    'id'         => intval($row['credential_id']),
    'name'       => $row['credential_name'],
    'username'   => _api_decrypt($row['credential_username'], $raw_token, $api_token_row),
    'password'   => _api_decrypt($row['credential_password'], $raw_token, $api_token_row),
    'uri'        => $row['credential_uri'],
    'uri_2'      => $row['credential_uri_2'] ?? '',
    'otp_secret' => $row['credential_otp_secret'] ?? '',
    'note'       => $row['credential_note'] ?? '',
    'client'     => $row['client_name'],
]);
