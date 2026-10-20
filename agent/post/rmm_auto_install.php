<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of "install apps when the agent installs" (RivetCore 1.0.0-rc.26, migration 0033): the per-package auto-install policy, the write-only vendor
 * parameter panel, the hosted installer file upload, and "new version from a starter template" (all on the Software catalog page, agent/rmm_software.php).
 * The device's and the installer's parts (ticking apps) have no form of their own: the device tab is read-only (includes/rmm_auto_install_ui.php) and the
 * ticks ride on the existing installer forms (agent/post/rmm_installer.php).
 *
 * Every action is a call into RivetCore's technician API as the signed-in user (rivetRmmAutoApi(), `endpoint_devices/software_autoinstall...`,
 * `.../software_params/{id}`, `.../software_files`, `.../software_templates`), exactly like agent/post/rmm_software_deploy.php; this file only checks the
 * CSRF token, the module and the `software_deploy` switch, and turns the form into typed values. A vendor parameter VALUE is read from $_POST once, sent to
 * Core to seal, and never stored, logged or echoed back by this file.
 *
 * The file upload is the one action that is not JSON: software_files takes a raw body with an X-Filename header. Rather than re-encode the uploaded
 * tmp_name as a body stream, this file calls RivetCore\Rmm\AutoInstall\AutoInstallActions::uploadFile() directly (the alternative UPGRADING.md names
 * explicitly: "Pages can call $module->autoInstallActions() instead"), through the SAME authenticated principal and the SAME ActionResult every other
 * action here answers with, so the 403/404 rules, the MZ/MSI header check and the size cap are still Core's, not this file's.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_sw_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'software_deploy', (int) $session_user_id, (string) $session_name);
$api = static fn (string $method, array $segments, array $query = [], ?array $body = null): array => rivetRmmAutoApi($mysqli, $ctx['uid'], $ctx['name'], $method, $segments, $query, $body);
$return = $ctx['return'];
$catalog = '/agent/rmm_software.php';

/** @return array{ok:bool,status:int,data:array<string,mixed>,error:string,code:string} the same shape rivetRmmAutoApi() returns, from a direct ActionResult call */
function rivetRmmAutoInstFromResult(\RivetCore\Rmm\Technician\ActionResult $r): array
{
    return ['ok' => $r->ok, 'status' => $r->http, 'data' => $r->data, 'error' => $r->ok ? '' : $r->message, 'code' => $r->code];
}

switch (rivetRmmAutoStr('action', 40)) {
    case 'set_auto':
        $id = rivetRmmAutoInt('package_id') ?? 0;
        $back = $catalog . '?package_id=' . $id . '#autoinstall';
        $scope = rivetRmmAutoStr('scope', 20);
        $body = ['scope' => $scope, 'install_order' => max(1, min(9999, rivetRmmAutoInt('install_order') ?? 100)), 'required' => rivetRmmAutoStr('required', 1) === '1'];
        if ($scope === 'os') {
            $body['os'] = rivetRmmAutoStr('os', 20) === 'linux' ? 'linux' : 'windows';
        } elseif ($scope === 'clients') {
            $ids = [];
            foreach (explode(',', rivetRmmAutoStr('clients_csv', 2000)) as $piece) {
                $piece = trim($piece);
                if ($piece !== '' && ctype_digit($piece)) {
                    $ids[] = (int) $piece;
                }
            }
            if ($ids === []) {
                rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'List at least one department id.'], '', $back);
            }
            $body['clients'] = array_values(array_unique($ids));
        }
        rivetRmmAutoDone($api('PUT', ['software_autoinstall', $id], [], $body), 'Saved.', $back);

    case 'set_param':
    case 'clear_param':
        $id = rivetRmmAutoInt('package_id') ?? 0;
        $back = $catalog . '?package_id=' . $id . '#params';
        $name = rivetRmmAutoStr('name', 30);
        $scope = rivetRmmAutoStr('scope', 20);
        $scope = in_array($scope, ['global', 'client', 'location'], true) ? $scope : 'global';
        $scopeId = $scope === 'global' ? 0 : max(1, rivetRmmAutoInt('scope_id') ?? 0);
        if ($scope !== 'global' && $scopeId <= 0) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Name the department (or location) this value is for.'], '', $back);
        }
        $body = ['name' => $name, 'scope' => $scope, 'scope_id' => $scopeId];
        if (rivetRmmAutoStr('action', 40) === 'set_param') {
            // The value is read from $_POST exactly once, for this one call; it is never assigned to a variable that outlives this branch, logged, or put back into $_SESSION (unlike
            // the catalog's other drafts), so a refusal cannot leave it sitting in a session array for the next page view to echo.
            $v = $_POST['value'] ?? '';
            if (!is_string($v) || $v === '') {
                rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Enter a value.'], '', $back);
            }
            $body['value'] = mb_substr($v, 0, 200);
            rivetRmmAutoDone($api('PUT', ['software_params', $id], [], $body), 'Saved. The value is sealed and will not be shown again.', $back);
        }
        rivetRmmAutoDone($api('DELETE', ['software_params', $id], [], $body), 'Cleared.', $back);

    case 'upload_file':
        $back = $catalog . '#hosted-files';
        $f = $_FILES['installer_file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
            $why = is_array($f) && ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'The file is larger than this server accepts in one upload.' : 'Choose a file to upload.';
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $why], '', $back);
        }
        $name = is_string($f['name'] ?? null) ? $f['name'] : 'installer';
        $principal = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
        $r = rivetRmmModule($mysqli)->autoInstallActions()->uploadFile($principal, (string) $f['tmp_name'], $name);
        rivetRmmAutoDone(rivetRmmAutoInstFromResult($r), 'Uploaded. Add a version that points at it.', $back);

    case 'delete_file':
        rivetRmmAutoDone($api('DELETE', ['software_files', rivetRmmAutoInt('file_id') ?? 0]), 'Deleted.', $catalog . '#hosted-files');

    case 'from_template':
        $key = rivetRmmAutoStr('template_key', 60);
        $pkgId = rivetRmmAutoInt('package_id');
        $body = ['version' => rivetRmmAutoStr('version', 64)];
        if ($pkgId !== null && $pkgId > 0) {
            $body['package_id'] = $pkgId;
        }
        $sha = rivetRmmAutoStr('sha256', 64);
        if ($sha !== '') {
            $body['sha256'] = $sha;
        }
        $size = rivetRmmAutoInt('size_bytes');
        if ($size !== null && $size > 0) {
            $body['size_bytes'] = $size;
        }
        $url = rivetRmmAutoStr('url', 600);
        if ($url !== '') {
            $body['url'] = $url;
        }
        $hostedId = rivetRmmAutoInt('hosted_file_id');
        if ($hostedId !== null && $hostedId > 0) {
            $body['hosted_file_id'] = $hostedId;
        }
        $args = rivetRmmAutoStr('silent_args', 200);
        if ($args !== '') {
            $body['silent_args'] = $args;
        }
        $r = $api('POST', ['software_templates', $key], [], $body);
        $newId = (int) ($r['data']['package_id'] ?? 0);
        rivetRmmAutoDone($r, 'Created from the template. UNVERIFIED: run a dry run, then one pilot machine, before relying on it.', $r['ok'] && $newId > 0 ? $catalog . '?package_id=' . $newId . '#versions' : $catalog);

    default:
        rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $return);
}
