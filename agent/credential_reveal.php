<?php

/*
 * Credential vault list: reveal or copy ONE field of ONE credential (POST, JSON).
 *
 *   credential_id, field (username|password), mode (reveal|copy), csrf_token, stepup_password (only when asked for)
 *
 * Every value returned is audited, counted against the per-user reveal limit and may need the password again.
 * The rules live in includes/vault_reveal.php (vaultReveal).
 */

// Same buffering as ajax.php: a stray notice from the bootstrap chain must not land ahead of the JSON.
ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
require_once "../includes/security_policy.php";
require_once "../includes/vault_reveal.php";
ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}
$csrf = (string) ($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$own_auth = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_auth_method FROM users WHERE user_id = " . intval($session_user_id)));
$result = vaultReveal(
    $mysqli,
    $_SESSION,
    [
        'id'           => intval($session_user_id),
        'name'         => (string) $session_name,
        'is_admin'     => !empty($session_is_admin),
        'perm'         => (int) lookupUserPermission('module_credential'),
        'has_password' => strtolower((string) ($own_auth['user_auth_method'] ?? 'local')) === 'local',
    ],
    [
        'credential_id'   => intval($_POST['credential_id'] ?? 0),
        'field'           => (string) ($_POST['field'] ?? ''),
        'mode'            => (string) ($_POST['mode'] ?? 'reveal'),
        'stepup_password' => isset($_POST['stepup_password']) ? (string) $_POST['stepup_password'] : null,
    ]
);

http_response_code($result['status']);
echo json_encode($result['body']);
