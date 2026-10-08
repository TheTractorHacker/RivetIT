<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * "Add device" / "Download installer" (Agent Fleet page, department page, Endpoints menu). A direct endpoint like rmm_agent.php.
 *
 *   action=download   streams the department's stamped installer (.exe). Same call as Administration > Endpoint agent > Deployment:
 *                     RivetCore\Rmm\Admin\RmmAdmin::downloadInstaller(), which checks the permission, creates the audited enrollment token and
 *                     refuses (revoking the token again) when the file cannot be served. A browser form post that fails is sent back with a
 *                     flash message; a request that asks for JSON (the modal's script) gets {success:false,error}.
 *   action=linux      RmmAdmin::deploymentCommands(): an audited token and the one install snippet for Linux (JSON, no binary download).
 *   action=locations  the department's locations for the modal's select (JSON).
 *
 * POST with the session CSRF token only. Hiding the button is cosmetic; every decision is Core's.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_installer.php';

mysqli_report(MYSQLI_REPORT_OFF);

$inst_action = (string) ($_POST['action'] ?? 'download');
$inst_json = $inst_action !== 'download' || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

/** Answer and stop: JSON for the script, a flash message and a redirect back for a plain form post. */
function inst_fail(int $http, string $code, string $message, bool $json): never
{
    if ($json) {
        http_response_code($http);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['success' => false, 'code' => $code, 'error' => $message]);
        exit;
    }
    if ($http === 403 || $http === 404 || $http === 405) {
        http_response_code($http);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
    flash_alert(nullable_htmlentities($message), 'error');
    redirect();
    exit;
}

function inst_int(string $k, int $min, int $max, int $default): int
{
    $v = $_POST[$k] ?? null;

    return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    inst_fail(405, 'method', 'POST only.', $inst_json);
}
$inst_csrf = $_POST['csrf_token'] ?? null;
if (!is_string($inst_csrf) || $inst_csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $inst_csrf)) {
    inst_fail(403, 'csrf', 'Invalid CSRF token. Reload the page and try again.', $inst_json);
}

$uid = (int) $session_user_id;
if (!rivetRmmEnabled($mysqli)) {
    inst_fail(404, 'module_off', 'The RMM module is switched off.', $inst_json);
}
$clientId = intval($_POST['client_id'] ?? 0);
if (!rivetRmmInstallerAllowed($mysqli, $uid, $clientId)) {
    inst_fail(403, 'forbidden', 'Administrator access is required to create installers for this department.', $inst_json);
}

$rmm = rivetRmmModule();

if ($inst_action === 'locations') {
    if ($clientId <= 0 || !$rmm->authorizer()->clientOk($uid, $clientId)) {
        inst_fail(403, 'forbidden', 'Not allowed.', true);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'locations' => rivetRmmInstallerLocations($mysqli, $clientId)]);
    exit;
}

if (!in_array($inst_action, ['download', 'linux'], true)) {
    inst_fail(422, 'invalid', 'Unknown action.', $inst_json);
}

$admin = $rmm->admin();
$who = rivetRmmPrincipal($uid, (string) $session_name);
$arch = (string) ($_POST['arch'] ?? 'amd64');
$ring = (string) ($_POST['ring'] ?? 'stable');
$args = [$clientId, intval($_POST['location_id'] ?? 0), in_array($ring, ['stable', 'pilot'], true) ? $ring : 'stable', inst_int('ttl_hours', 1, 720, 24),
    inst_int('max_uses', 1, 5000, 1), trim((string) ($_POST['label'] ?? '')), in_array($arch, ['amd64', 'arm64'], true) ? $arch : 'amd64'];

if ($inst_action === 'linux') {
    $r = $admin->deploymentCommands($who, ...$args);
    if (!$r->ok) {
        inst_fail($r->http, $r->code, $r->message, true);
    }
    $cmds = $r->data['commands'] ?? null;
    if (!is_array($cmds) || !isset($cmds['linux'])) {
        inst_fail(422, 'invalid', 'Set the service URL to an https:// address first.', true);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'command' => $cmds['linux'], 'department' => $r->data['department'], 'arch' => $args[6],
        'expires_at' => $r->data['token']['expires_at'] ?? null, 'max_uses' => (int) ($r->data['token']['max_uses'] ?? 0), 'token_id' => (int) ($r->data['token']['token_id'] ?? 0)]);
    exit;
}

$r = $admin->downloadInstaller($who, ...$args);
if (!$r->ok) {
    inst_fail($r->http, $r->code, $r->message, $inst_json);
}
(new \RivetCore\Rmm\Http\SapiEmitter())->emit($r->data['download']);   // streamed with exact length after the size and SHA-256 check
exit;
