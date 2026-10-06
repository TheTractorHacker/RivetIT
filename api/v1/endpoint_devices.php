<?php
// Technician API for the built-in endpoint agent (user API token required; the legacy shared X-Api-Key is refused).
//   GET  /api/v1/endpoint_devices                      list devices in the caller's departments   (module_rmm >= 1)
//   GET  /api/v1/endpoint_devices/{id}                 device detail, checks, recent jobs
//   GET  /api/v1/endpoint_devices/{id}/jobs            job history (output only with module_rmm_scripts >= 2)
//   POST /api/v1/endpoint_devices/{id}/jobs            {type: powershell|reboot|collect, script|script_id, params, timeout_s, destructive, confirm}
//   POST /api/v1/endpoint_devices/{id}/jobs/{job}/cancel
//   POST /api/v1/endpoint_devices/{id}/remote          {force?} -> {url, session_id}              (module_rmm_remote_connect >= 1)
// Authorization is decided in ITFlow\EndpointAgent\Authz on every call, including department scope.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once __DIR__ . '/includes/api_mobile.php';
require_once $DOCUMENT_ROOT . '/vendor/autoload.php';

use ITFlow\EndpointAgent\Actions;
use ITFlow\EndpointAgent\Authz;
use ITFlow\EndpointAgent\Config;
use ITFlow\EndpointAgent\Db;
use ITFlow\EndpointAgent\View;

mysqli_report(MYSQLI_REPORT_OFF);
api_mobile_require_user_token();
api_mobile_audit_context();
$uid = intval($api_user_id);
$ea_fail = static function (array $r): void {
    api_response($r['http'], ['error' => $r['message'], 'code' => $r['code']]);
};

if (!Config::enabled()) {
    api_response(404, ['error' => 'The endpoint agent is not enabled.', 'code' => 'disabled']);
}
$cfg = Config::get();
$canSeeOutput = Authz::check($uid, Authz::RUN_SAVED, 0) === null;   // department 0: this is only the role-level question

// Parse /endpoint_devices/{id}[/jobs[/{job}[/cancel]]|/remote]
$ea_seg = array_slice($segments, 1);
$ea_id = isset($ea_seg[0]) && ctype_digit($ea_seg[0]) ? (int) $ea_seg[0] : null;
$ea_what = $ea_seg[1] ?? null;
$ea_job = $ea_seg[2] ?? null;
$ea_op = $ea_seg[3] ?? null;

if ($ea_id === null) {
    if ($method !== 'GET' || $ea_seg) {
        api_response(404, ['error' => 'Not found', 'code' => 'not_found']);
    }
    $denied = Authz::check($uid, Authz::VIEW, 0);
    if ($denied !== null) {
        api_response(403, ['error' => $denied, 'code' => 'forbidden']);
    }
    [$scope, $scopeParams] = Authz::clientScopeSql($uid, 'client_id');
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $rows = Db::all("SELECT * FROM endpoint_agent_devices WHERE retired_at IS NULL AND $scope ORDER BY hostname, device_id LIMIT $limit OFFSET $offset", $scopeParams);
    $items = array_map(static fn($d) => View::summary($d, $cfg), $rows);
    if (isset($_GET['status'])) {
        $items = array_values(array_filter($items, static fn($i) => $i['status'] === $_GET['status'] || $i['link_state'] === $_GET['status']));
    }
    api_response(200, ['data' => $items, 'total' => count($items)]);
}

$denied = Authz::check($uid, Authz::VIEW, 0);
if ($denied !== null) {
    api_response(403, ['error' => $denied, 'code' => 'forbidden']);
}
$dev = Actions::visibleDevice($uid, $ea_id);
if (!$dev) {
    api_response(404, ['error' => 'Device not found.', 'code' => 'not_found']);
}
$canSeeOutput = Authz::check($uid, Authz::RUN_SAVED, (int) $dev['client_id']) === null;

if ($ea_what === null) {
    if ($method !== 'GET') {
        api_response(405, ['error' => 'Method not allowed', 'code' => 'method_not_allowed']);
    }
    api_response(200, View::detail($dev, $cfg, $canSeeOutput));
}

if ($ea_what === 'jobs') {
    if ($ea_job === null && $method === 'GET') {
        api_response(200, ['data' => View::jobs($ea_id, (int) ($_GET['limit'] ?? 50), $canSeeOutput)]);
    }
    if ($ea_job === null && $method === 'POST') {
        $in = api_mobile_json_body();
        $r = Actions::submitJob($uid, (string) $session_name, $ea_id, $in);
        if (!$r['ok']) {
            $ea_fail($r);
        }
        api_response(201, ['job_id' => $r['job_id'], 'state' => 'queued']);
    }
    if ($ea_job !== null && $ea_op === 'cancel' && $method === 'POST') {
        $r = Actions::cancelJob($uid, (string) $session_name, $ea_id, (string) $ea_job);
        if (!$r['ok']) {
            $ea_fail($r);
        }
        api_response(200, ['ok' => true]);
    }
    api_response(404, ['error' => 'Not found', 'code' => 'not_found']);
}

if ($ea_what === 'remote' && $method === 'POST') {
    $in = api_mobile_json_body();
    $r = Actions::launchRemote($uid, (string) $session_name, $ea_id, !empty($in['force']));
    if (!$r['ok']) {
        $ea_fail($r);
    }
    api_response(200, ['url' => $r['url'], 'session_id' => $r['session_id']]);
}

api_response(404, ['error' => 'Not found', 'code' => 'not_found']);
