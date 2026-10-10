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

$revoked = mysqli_affected_rows($mysqli) > 0;
if ($revoked) {
    try {
        \ITFlow\Audit\AuditService::record('api_token.revoke', intval($session_user_id) ?: null, 'api_token', $token_id, 'revoke', 'Revoked one of their own API tokens', ['token_id' => $token_id]);
    } catch (\Throwable $e) {
        // auditing never breaks the action
    }
}

echo json_encode(['ok' => $revoked]);
