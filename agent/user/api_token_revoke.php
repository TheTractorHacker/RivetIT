<?php
require_once "includes/inc_all_user.php";

header('Content-Type: application/json');
$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$token_id = intval($body['token_id'] ?? 0);
$csrf     = $body['csrf_token'] ?? '';

if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
    exit;
}

$result = mysqli_query($mysqli,
    "DELETE FROM api_tokens WHERE token_id = $token_id AND token_user_id = $session_user_id"
);

echo json_encode(['ok' => mysqli_affected_rows($mysqli) > 0]);
