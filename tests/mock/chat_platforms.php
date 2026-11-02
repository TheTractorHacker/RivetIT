<?php
/*
 * Local stand-in for the Slack Web API calls RivetIT makes with the bot token, and for the Microsoft Bot Framework (token endpoint, connector, OpenID keys).
 *   MOCK_LOG=/path/log.jsonl MOCK_JWKS=/path/jwks.json php -S 127.0.0.1:PORT tests/mock/chat_platforms.php
 * Every request is appended to MOCK_LOG (method, path, auth header, raw body). Routes:
 *   POST /api/chat.postMessage  -> {"ok":true,"ts":"17000000NN.000100"}      (channel "CFAIL" -> {"ok":false,"error":"channel_not_found"})
 *   POST /api/views.open        -> {"ok":true}                                (trigger "BAD" -> {"ok":false,"error":"expired_trigger_id"})
 *   POST /api/users.info        -> canned Slack users (see tests/mock/slack_webhook.php)
 *   POST /teams/token           -> {"access_token":"mock-bot-token","expires_in":3600}   (client_secret "wrong" -> 401)
 *   POST /teams/service/v3/conversations/{id}/activities -> {"id":"act-N"}    (needs Authorization: Bearer mock-bot-token)
 *   GET  /teams/service/v3/conversations/{id}/members/{aad} -> {"objectId":aad,"email":...}
 *   GET  /teams/openid          -> {"jwks_uri":"http://127.0.0.1:PORT/teams/keys"}
 *   GET  /teams/keys            -> the file named by MOCK_JWKS
 *   GET  /teams/openid-evil     -> a metadata document whose jwks_uri points at a foreign host (must be refused)
 * Never talks to anything outside this machine.
 */
$log = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/chat_platforms.jsonl';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
file_put_contents($log, json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'body' => $raw]) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');

if ($path === '/api/chat.postMessage') {
    $b = json_decode($raw, true) ?: [];
    if (($b['channel'] ?? '') === 'CFAIL') { echo json_encode(['ok' => false, 'error' => 'channel_not_found']); return; }
    $n = (int) @file_get_contents($log . '.n') + 1; file_put_contents($log . '.n', (string) $n);
    echo json_encode(['ok' => true, 'ts' => sprintf('17000000%02d.000100', $n % 100), 'channel' => $b['channel'] ?? '']); return;
}
if ($path === '/api/views.open') {
    $b = json_decode($raw, true) ?: [];
    echo json_encode(($b['trigger_id'] ?? '') === 'BAD' ? ['ok' => false, 'error' => 'expired_trigger_id'] : ['ok' => true]); return;
}
if ($path === '/api/users.info') {
    parse_str($raw, $f); $id = (string) ($f['user'] ?? '');
    $u = ['UALICE' => ['alice@x.test', true], 'UBOBB' => ['bob@x.test', true], 'UCAROL' => ['carol@x.test', true], 'UUNCONF' => ['alice@x.test', false], 'UCONTACT' => ['pat@client.test', true]];
    if (!isset($u[$id])) { echo json_encode(['ok' => false, 'error' => 'user_not_found']); return; }
    echo json_encode(['ok' => true, 'user' => ['id' => $id, 'team_id' => 'T1', 'deleted' => false, 'is_bot' => false, 'is_email_confirmed' => $u[$id][1], 'profile' => ['email' => $u[$id][0]]]]); return;
}
if ($path === '/teams/token') {
    parse_str($raw, $f);
    if (($f['client_secret'] ?? '') === 'wrong') { http_response_code(401); echo json_encode(['error' => 'invalid_client']); return; }
    echo json_encode(['access_token' => 'mock-bot-token', 'expires_in' => 3600, 'token_type' => 'Bearer']); return;
}
if (preg_match('#^/teams/service/v3/conversations/([^/]+)/activities$#', $path)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer mock-bot-token') { http_response_code(401); echo '{}'; return; }
    $n = (int) @file_get_contents($log . '.t') + 1; file_put_contents($log . '.t', (string) $n);
    echo json_encode(['id' => 'act-' . $n]); return;
}
if (preg_match('#^/teams/service/v3/conversations/([^/]+)/members/([^/]+)$#', $path, $m)) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer mock-bot-token') { http_response_code(401); echo '{}'; return; }
    $emails = ['11111111-1111-1111-1111-111111111111' => 'alice@x.test', '22222222-2222-2222-2222-222222222222' => 'nobody@x.test'];
    $aad = rawurldecode($m[2]);
    echo json_encode(['objectId' => $aad, 'email' => $emails[$aad] ?? null, 'userPrincipalName' => $emails[$aad] ?? null]); return;
}
if ($path === '/teams/openid') {
    echo json_encode(['jwks_uri' => 'http://' . $_SERVER['HTTP_HOST'] . '/teams/keys']); return;
}
if ($path === '/teams/openid-evil') {
    echo json_encode(['jwks_uri' => 'http://evil.example.test:1/keys']); return;
}
if ($path === '/teams/keys') {
    echo (string) @file_get_contents((string) getenv('MOCK_JWKS')); return;
}
http_response_code(404); echo '{}';
