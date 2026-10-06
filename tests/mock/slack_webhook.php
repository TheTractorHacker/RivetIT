<?php
/*
 * Local stand-in for a Slack Incoming Webhook / Teams Workflows webhook. Run as a router script:
 *   MOCK_LOG=/path/to/log.jsonl php -S 127.0.0.1:PORT tests/mock/slack_webhook.php
 * Every request is appended to MOCK_LOG as one JSON line (method, path, content type, raw body). The path picks the answer:
 *   /slack/ok -> 200 "ok"   /teams/ok -> 202 (empty)   /fail/500 -> 500   /fail/404 -> 404 "no_service"   /redirect -> 302 to /slack/ok
 * Also the bits of the Slack Web API / interactivity the app calls back:
 *   POST /api/users.info   (Authorization: Bearer <bot token>, form user=<id>) -> a canned Slack user by id: UALICE (confirmed alice@x.test), UBOBB (confirmed
 *     bob@x.test, a non-admin tech), UUNCONF (email NOT confirmed), UBOT (bot), UGUEST (guest), UOTHERTEAM (other workspace), UNOAGENT (email no agent has),
 *     UDUP (email two agents share), UNOEMAIL (no email); anything else -> {"ok":false,"error":"user_not_found"}. "xoxb-bad" as token -> invalid_auth.
 *   POST /response/*       the response_url the app answers an interaction on (logged, 200 ok)
 * Never talks to anything outside this machine.
 */
$log = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/chat_mock.jsonl';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
file_put_contents($log, json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'content_type' => $_SERVER['CONTENT_TYPE'] ?? '', 'sig' => $_SERVER['HTTP_X_ITFLOW_SIGNATURE'] ?? '', 'event' => $_SERVER['HTTP_X_ITFLOW_EVENT'] ?? '', 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '', 'body' => $raw]) . "\n", FILE_APPEND | LOCK_EX);

if ($path === '/api/users.info') {
    header('Content-Type: application/json');
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer xoxb-bad') { echo json_encode(['ok' => false, 'error' => 'invalid_auth']); return; }
    parse_str($raw, $f);
    $id = (string) ($f['user'] ?? '');
    $u = ['UALICE' => ['alice@x.test', true, []], 'UBOBB' => ['bob@x.test', true, []], 'UUNCONF' => ['alice@x.test', false, []], 'UBOT' => ['alice@x.test', true, ['is_bot' => true]],
        'UGUEST' => ['alice@x.test', true, ['is_restricted' => true]], 'UOTHERTEAM' => ['alice@x.test', true, ['team_id' => 'TOTHER']], 'UNOAGENT' => ['nobody@x.test', true, []],
        'UDUP' => ['dup@x.test', true, []], 'UNOEMAIL' => [null, true, []]];
    if (!isset($u[$id])) { echo json_encode(['ok' => false, 'error' => 'user_not_found']); return; }
    [$email, $confirmed, $extra] = $u[$id];
    echo json_encode(['ok' => true, 'user' => $extra + ['id' => $id, 'team_id' => 'T1', 'deleted' => false, 'is_bot' => false, 'is_email_confirmed' => $confirmed, 'profile' => $email === null ? [] : ['email' => $email]]]);
    return;
}
if (str_starts_with($path, '/response/')) { header('Content-Type: text/plain'); echo 'ok'; return; }
if (str_starts_with($path, '/slack/')) { header('Content-Type: text/plain'); echo 'ok'; return; }
if ($path === '/generic') { echo 'ok'; return; }
if (str_starts_with($path, '/teams/')) { http_response_code(202); return; }
if ($path === '/fail/500') { http_response_code(500); echo 'boom'; return; }
if ($path === '/fail/404') { http_response_code(404); echo 'no_service'; return; }
if ($path === '/redirect') { http_response_code(302); header('Location: /slack/ok'); return; }
http_response_code(404);
