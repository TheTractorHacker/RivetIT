<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * "Add device" / "Download installer" (Agent Fleet page, department page, Endpoints menu). A direct endpoint like rmm_agent.php.
 *
 *   action=download   streams the department's stamped installer (.exe, or .msi with format=msi, RivetCore rc.16). Same call as Administration > Endpoint agent > Deployment:
 *                     RivetCore\Rmm\Admin\RmmAdmin::downloadInstaller(), which checks the permission, creates the audited enrollment token and
 *                     refuses (revoking the token again) when the file cannot be served. A browser form post that fails is sent back with a
 *                     flash message; a request that asks for JSON (the modal's script) gets {success:false,error}.
 *   action=linux      RmmAdmin::deploymentCommands(): an audited token and the one install snippet for Linux (JSON, no binary download).
 *   Both take the optional install_mesh_agent=1 (the tick-box "Also install the MeshCentral agent", off by default): passed as the trailing bool; Core refuses with its
 *   own text when MeshCentral is off or not configured and creates no token.
 *   action=windows_commands  RmmAdmin::deploymentCommands(): an audited token and the silent-install commands for Windows (PowerShell for the .exe, and with format=msi the msiexec / PowerShell /
 *                     properties / uninstall commands of the MSI) as JSON, for the Add device page (RivetIT 26.10.52).
 *   action=locations  the department's locations for the modal's select (JSON).
 *   The Linux answer (RivetIT 26.10.52) is a self-contained command when the Linux files of an agent release are recorded (Administration > Endpoint agent > Agent updates): it downloads
 *   install-linux.sh and the agent, checks both against the SHA-256 recorded on this server and installs; otherwise Core's own text (unpack the tarball first). It also lists the packages.
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

if (!in_array($inst_action, ['download', 'linux', 'windows_commands'], true)) {
    inst_fail(422, 'invalid', 'Unknown action.', $inst_json);
}

$admin = $rmm->admin();
$who = rivetRmmPrincipal($uid, (string) $session_name);
$arch = (string) ($_POST['arch'] ?? 'amd64');
$ring = (string) ($_POST['ring'] ?? 'stable');
$args = [$clientId, intval($_POST['location_id'] ?? 0), in_array($ring, ['stable', 'pilot'], true) ? $ring : 'stable', inst_int('ttl_hours', 1, 720, 24),
    inst_int('max_uses', 1, 5000, 1), trim((string) ($_POST['label'] ?? '')), in_array($arch, ['amd64', 'arm64'], true) ? $arch : 'amd64',
    ($_POST['install_mesh_agent'] ?? '') === '1'];   // the trailing bool: also install the MeshCentral agent (RivetCore rc.12). Core refuses when Mesh cannot support it and creates nothing.
// RivetCore 1.0.0-rc.16: the installer format, `exe` (the default) or `msi` (a stamped copy of the published MSI template). Only the Windows download takes it; the Linux snippet is always the exe's.
$format = (string) ($_POST['format'] ?? 'exe');
if ($format === '') {
    $format = 'exe';
}
if (!in_array($format, ['exe', 'msi'], true)) {
    inst_fail(422, 'invalid', 'Choose the installer format: exe or msi.', $inst_json);
}
$args[] = $inst_action === 'linux' ? 'exe' : $format;
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_agent_feed.php';

/**
 * A Linux install command for a server that has no Windows agent published (RivetIT 26.10.52). Returns an error text, or the Core-shaped result and Core's snippet text.
 *
 * @param array<int,mixed> $args the argument list of RmmAdmin::deploymentCommands()
 * @return array{result:\RivetCore\Rmm\Technician\ActionResult,command:string}|string
 */
function inst_linux_without_binary(\RivetCore\Rmm\RmmModule $rmm, \RivetCore\Rmm\Authz\RmmPrincipal $who, \mysqli $mysqli, array $args, string $actor)
{
    [$clientId, $locationId, $ring, $ttl, $uses, $label, $arch, $mesh] = $args;
    $summary = $rmm->readModel()->settingsSummary();
    if ($summary['service_base'] === null) {
        return 'Set the service URL to an https:// address first.';
    }
    $q = mysqli_query($mysqli, 'SELECT client_name FROM clients WHERE client_id = ' . (int) $clientId . ' AND client_archived_at IS NULL');
    $client = $q ? mysqli_fetch_assoc($q) : null;
    if (!$client) {
        return 'Choose the department the devices belong to.';
    }
    if ($locationId > 0 && !in_array($locationId, array_column(rivetRmmInstallerLocations($mysqli, $clientId), 'id'), true)) {
        $locationId = 0;
    }
    $meshBlock = null;
    if ($mesh) {
        $why = $rmm->meshAgents()->installRefusal();
        if ($why !== null) {
            return $why;
        }
        $meshBlock = $rmm->meshAgents()->installBlock();
    }
    $t = $rmm->technician()->createToken($who, $clientId, $locationId, $ring, $ttl, $uses, $label !== '' ? $label : 'Linux install ' . bin2hex(random_bytes(3)));
    if (!$t->ok) {
        return $t->message;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT token_id, expires_at, max_uses FROM endpoint_agent_enrollment_tokens WHERE token_id = ' . (int) $t->data['token_id'])) ?: [];
    $dept = \RivetCore\Rmm\Enrollment\DeviceValidator::cleanText((string) $client['client_name'], 200) ?? ('Department ' . $clientId);
    $cmd = \RivetCore\Rmm\Installer\InstallerService::linuxSnippet((string) $summary['service_base'], (string) $t->data['token'], $arch, $dept, ($summary['ca_pem'] ?? '') !== '' ? (string) $summary['ca_pem'] : null, $meshBlock);
    logAction('Endpoint Agent', 'Installer Created', "$actor created a Linux install command ($arch, no Windows agent published) for client \"$dept\" with enrollment token #{$row['token_id']}, {$row['max_uses']} uses, expires {$row['expires_at']} UTC", $clientId);

    return ['result' => \RivetCore\Rmm\Technician\ActionResult::ok('Enrollment token created.', 201, 'created', ['token' => $row, 'token_plain' => $t->data['token'], 'department' => $dept]), 'command' => $cmd];
}

if ($inst_action === 'windows_commands') {
    $r = $admin->deploymentCommands($who, ...$args);
    if (!$r->ok) {
        inst_fail($r->http, $r->code, $r->message, true);
    }
    $cmds = $r->data['commands'] ?? null;
    if (!is_array($cmds) || !isset($cmds['powershell'])) {
        inst_fail(422, 'invalid', 'Set the service URL to an https:// address first.', true);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'department' => $r->data['department'], 'arch' => $args[6], 'format' => $format, 'filename' => $cmds['filename'], 'powershell' => $cmds['powershell'],
        'msi' => isset($cmds['msi']) && is_array($cmds['msi']) ? $cmds['msi'] : null, 'detection' => $cmds['detection'] ?? null,
        'expires_at' => $r->data['token']['expires_at'] ?? null, 'max_uses' => (int) ($r->data['token']['max_uses'] ?? 0), 'token_id' => (int) ($r->data['token']['token_id'] ?? 0)]);
    exit;
}

if ($inst_action === 'linux') {
    $r = $admin->deploymentCommands($who, ...$args);
    $linuxCommand = null;
    if (!$r->ok && str_contains($r->message, 'No agent binary is published')) {
        // Core's issue() wants a published WINDOWS agent for the architecture even for a Linux command, which never uses it. A Linux-only install must not be blocked by that:
        // make the token through the ordinary token action (same ability and department check, audited by Core) and write the snippet with Core's own text builder.
        $fb = inst_linux_without_binary($rmm, $who, $mysqli, $args, (string) $session_name);
        if (is_string($fb)) {
            inst_fail(422, 'invalid', $fb, true);
        }
        $r = $fb['result'];
        $linuxCommand = $fb['command'];
    }
    if (!$r->ok) {
        inst_fail($r->http, $r->code, $r->message, true);
    }
    $cmds = $r->data['commands'] ?? null;
    $core = $linuxCommand ?? (is_array($cmds) && isset($cmds['linux']) ? (string) $cmds['linux'] : null);
    if ($core === null) {
        inst_fail(422, 'invalid', 'Set the service URL to an https:// address first.', true);
    }
    $lin = rivetAgentFeedLinuxAssets($mysqli);
    $lc = rivetAgentFeedLinuxCommand($core, $lin, $args[6]);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'command' => $lc['command'], 'self_contained' => $lc['self_contained'], 'agent_version' => $lc['version'], 'packages' => rivetAgentFeedLinuxPackages($lin, $args[6]),
        'department' => $r->data['department'], 'arch' => $args[6],
        'expires_at' => $r->data['token']['expires_at'] ?? null, 'max_uses' => (int) ($r->data['token']['max_uses'] ?? 0), 'token_id' => (int) ($r->data['token']['token_id'] ?? 0)]);
    exit;
}

$r = $admin->downloadInstaller($who, ...$args);
if (!$r->ok) {
    inst_fail($r->http, $r->code, $r->message, $inst_json);
}
(new \RivetCore\Rmm\Http\SapiEmitter())->emit($r->data['download']);   // streamed with exact length after the size and SHA-256 check
exit;
