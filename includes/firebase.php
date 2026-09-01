<?php

function _firebase_b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function firebase_load_sa(): ?array {
    $sa_file = $_SERVER['DOCUMENT_ROOT'] . '/config/firebase_service_account.json';
    if (!file_exists($sa_file)) return null;
    $sa = json_decode(file_get_contents($sa_file), true);
    return (is_array($sa) && !empty($sa['project_id']) && !empty($sa['private_key'])) ? $sa : null;
}

function firebase_get_access_token(): ?string {
    $sa = firebase_load_sa();
    if (!$sa) return null;

    $now = time();
    $header  = _firebase_b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = _firebase_b64u(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));

    $to_sign = "$header.$payload";
    openssl_sign($to_sign, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);

    $jwt = "$to_sign." . _firebase_b64u($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw = curl_exec($ch);
    $resp = json_decode($raw, true);
    curl_close($ch);

    if (empty($resp['access_token'])) {
        error_log("ITFlow - Firebase OAuth token fetch failed: " . $raw);
    }

    return $resp['access_token'] ?? null;
}

function firebase_send_push(string $fcm_token, string $title, string $body, array $data = []): bool {
    $sa    = firebase_load_sa();
    $token = firebase_get_access_token();
    if (!$sa || !$token) return false;

    $project_id = $sa['project_id'];
    $payload = json_encode([
        'message' => [
            'token'        => $fcm_token,
            'notification' => ['title' => $title, 'body' => $body],
            'data'         => array_map('strval', $data),
        ],
    ]);

    $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        error_log("ITFlow - FCM push failed (HTTP $code) for token " . substr($fcm_token, 0, 12) . "...: " . ($curl_err ?: $resp));
    }

    return $code === 200;
}

function firebase_send_push_to_user(int $user_id, string $title, string $body, array $data = []): void {
    global $mysqli;
    $uid = intval($user_id);
    $sql = mysqli_query($mysqli, "SELECT token_fcm_token FROM api_tokens WHERE token_user_id = $uid AND token_fcm_token IS NOT NULL");
    while ($row = mysqli_fetch_assoc($sql)) {
        firebase_send_push($row['token_fcm_token'], $title, $body, $data);
    }
}
