<?php
// AJAX endpoint: delete one of the logged-in user's passkeys
// Expects JSON body: { passkey_id: int, csrf_token: string }

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/session_init.php';
ob_end_clean();

header('Content-Type: application/json');

if (empty($_SESSION['logged']) || empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$passkey_id = intval($body['passkey_id'] ?? 0);
$csrf       = $body['csrf_token'] ?? '';

if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$uid = intval($_SESSION['user_id']);

$pk = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT passkey_name FROM user_passkeys WHERE passkey_id = $passkey_id AND passkey_user_id = $uid LIMIT 1"
));

if (!$pk) {
    echo json_encode(['ok' => false, 'error' => 'Passkey not found']);
    exit;
}

mysqli_query($mysqli, "DELETE FROM user_passkeys WHERE passkey_id = $passkey_id AND passkey_user_id = $uid");

$pk_name = sanitizeInput($pk['passkey_name']);
logAction("Passkey", "Delete", "Passkey deleted: $pk_name");

echo json_encode(['ok' => true]);
