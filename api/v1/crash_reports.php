<?php
// POST /api/v1/crash-reports
// Semi-public: reachable without a valid bearer token (see the pre-auth-gate
// carve-out in index.php). $api_user_id is set only if a valid token WAS
// presented, for correlation - never required to be present.
//
// Logged via the existing logApp() -> app_logs pipeline (category
// 'mobile_crash') so reports show up in the existing App Logs admin page
// (admin/app_log.php) without a bespoke new screen.
defined('FROM_API') || die();
if ($method !== 'POST') api_error(405, 'Method not allowed');

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$stack_trace = trim((string) ($body['stack_trace'] ?? ''));
if ($stack_trace === '') {
    api_error(400, 'stack_trace is required');
}
$stack_trace = substr($stack_trace, 0, 20000);

$platform          = substr(trim((string) ($body['platform'] ?? 'android')), 0, 20);
$app_version_name  = substr(trim((string) ($body['app_version_name'] ?? '')), 0, 20);
$app_version_code  = intval($body['app_version_code'] ?? 0);
$device_model      = substr(trim((string) ($body['device_model'] ?? '')), 0, 100);
$os_version        = substr(trim((string) ($body['os_version'] ?? '')), 0, 20);
$sdk_int           = intval($body['sdk_int'] ?? 0);
$thread_name       = substr(trim((string) ($body['thread_name'] ?? '')), 0, 50);
$is_fatal          = !empty($body['is_fatal']);
$occurred_at       = substr(trim((string) ($body['occurred_at'] ?? '')), 0, 40);

$who = $api_user_id ? ('user #' . intval($api_user_id)) : 'unauthenticated';

$details = "Platform: $platform $app_version_name ($app_version_code)\n"
         . "Device: $device_model, OS $os_version (SDK $sdk_int)\n"
         . "Caller: $who\n"
         . "Fatal: " . ($is_fatal ? 'yes' : 'no') . "\n"
         . "Occurred at (device time): " . ($occurred_at !== '' ? $occurred_at : 'unknown') . "\n"
         . "Thread: " . ($thread_name !== '' ? $thread_name : 'unknown') . "\n\n"
         . $stack_trace;

logApp('mobile_crash', 'error', $details);

api_response(201, ['logged' => true]);
