<?php
/*
 * Local stand-in for a Slack Incoming Webhook / Teams Workflows webhook. Run as a router script:
 *   MOCK_LOG=/path/to/log.jsonl php -S 127.0.0.1:PORT tests/mock/slack_webhook.php
 * Every request is appended to MOCK_LOG as one JSON line (method, path, content type, raw body). The path picks the answer:
 *   /slack/ok -> 200 "ok"   /teams/ok -> 202 (empty)   /fail/500 -> 500   /fail/404 -> 404 "no_service"   /redirect -> 302 to /slack/ok
 * Never talks to anything outside this machine.
 */
$log = getenv('MOCK_LOG') ?: sys_get_temp_dir() . '/chat_mock.jsonl';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
file_put_contents($log, json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'content_type' => $_SERVER['CONTENT_TYPE'] ?? '', 'sig' => $_SERVER['HTTP_X_ITFLOW_SIGNATURE'] ?? '', 'event' => $_SERVER['HTTP_X_ITFLOW_EVENT'] ?? '', 'body' => $raw]) . "\n", FILE_APPEND | LOCK_EX);

if (str_starts_with($path, '/slack/')) { header('Content-Type: text/plain'); echo 'ok'; return; }
if ($path === '/generic') { echo 'ok'; return; }
if (str_starts_with($path, '/teams/')) { http_response_code(202); return; }
if ($path === '/fail/500') { http_response_code(500); echo 'boom'; return; }
if ($path === '/fail/404') { http_response_code(404); echo 'no_service'; return; }
if ($path === '/redirect') { http_response_code(302); header('Location: /slack/ok'); return; }
http_response_code(404);
