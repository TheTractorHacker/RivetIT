<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the software deployment pages (RMM Phase 5, RivetCore 1.0.0-rc.21): detect, dry run and real run on one device; catalog changes (package, version, retire a version,
 * delete a package); the allowed installer hosts; assignments; and the second person's decision on a software request. Browser forms get a flash message and a redirect back; a request
 * that asks for JSON gets JSON.
 *
 * Every action is a call into RivetCore's technician API as the signed-in user (rivetRmmAutoApi()), which decides (the 403 / 404 rules, the department scope, the abilities
 * rmm.software.view / manage / deploy and rmm.admin for the host list, the confirmations, the two-person rule, the audit entries); this file only checks the CSRF token, the module and the
 * `software_deploy` switch, and turns the form into typed values (ints stay ints, bools stay bools, Core's validators care). A real run also needs the explicit `confirm=1` field here and
 * Core demands `confirm: true` again. NOTHING the agent runs is typed here: the address, hash, arguments and package name of a job are rebuilt by Core from the catalog row.
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
$deviceId = static fn (): int => max(0, (int) (rivetRmmAutoInt('device_id') ?? 0));
$needDevice = static function (int $id) use ($return): void {
    if ($id <= 0) {
        rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'No device was named.'], '', $return);
    }
};
$catalog = '/agent/rmm_software.php';
$assignments = '/agent/rmm_software_assignments.php';

switch (rivetRmmAutoStr('action', 40)) {
    case 'detect':
        $id = $deviceId();
        $needDevice($id);
        $r = $api('POST', [$id, 'deployments', 'detect']);
        $skipped = (string) ($r['data']['skipped'] ?? '');
        rivetRmmAutoDone($r, $skipped === 'nothing_to_detect' ? 'No package is assigned to this device, so there is nothing to look for.' : ($skipped !== '' ? 'A detection is already waiting for this device.'
            : 'Detection queued. The device picks it up at its next check-in and the table refreshes when it answers. Nothing is installed.'), $return);

    case 'dry_run':
    case 'run':
        $mode = rivetRmmAutoStr('action', 40) === 'run' ? 'run' : 'dry_run';
        $id = $deviceId();
        $needDevice($id);
        if ($mode === 'run' && rivetRmmAutoStr('confirm', 1) !== '1') {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick the confirmation box to install or remove for real. Nothing was changed.'], '', $return);
        }
        $body = rivetRmmSwRunBody($_POST, $mode);
        if ($body['package_id'] <= 0) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Choose a package first.'], '', $return);
        }
        $r = $api('POST', [$id, 'deployments', 'run'], [], $body);
        $word = $body['action'] === 'uninstall' ? 'removal' : 'installation';
        if ($r['ok'] && ($r['data']['state'] ?? '') === 'pending_approval') {
            rivetRmmAutoDone($r, 'This package needs a second person: your request waits under Software assignments (and Approvals) until someone else approves it. Nothing was queued yet.', $return);
        }
        if ($r['ok'] && isset($r['data']['skipped'])) {
            rivetRmmAutoDone($r, 'A software job is already waiting or running on this device. Nothing new was queued.', $return);
        }
        rivetRmmAutoDone($r, $mode === 'run' ? 'Queued: a real ' . $word . '. The device picks it up at its next check-in; the result appears under Install history.'
            : 'Dry run queued. Nothing is changed on the device; the result appears under Install history.', $return);

    case 'save_package':
        $id = rivetRmmAutoInt('package_id') ?? 0;
        $back = $id > 0 ? $catalog . '?package_id=' . $id : $catalog . '?new=1';
        [$body, $err] = rivetRmmSwPackageBody($_POST);
        if ($body === null) {
            $_SESSION['rmm_sw_draft']['package'] = rivetRmmSwDraft($_POST, $id);
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $back);
        }
        $r = $id > 0 ? $api('PUT', ['software_packages', $id], [], $body) : $api('POST', ['software_packages'], [], $body);
        if (!$r['ok']) {
            $_SESSION['rmm_sw_draft']['package'] = rivetRmmSwDraft($_POST, $id);
        }
        $newId = (int) ($r['data']['package_id'] ?? $id);
        rivetRmmAutoDone($r, $id > 0 ? 'Package saved.' : 'Package created. Add a version to it next; nothing is installed until a version exists and the package is assigned or run.', $r['ok'] ? $catalog . '?package_id=' . $newId : $back);

    case 'delete_package':
        rivetRmmAutoDone($api('DELETE', ['software_packages', rivetRmmAutoInt('package_id') ?? 0]), 'Package deleted.', $catalog);

    case 'add_version':
        $id = rivetRmmAutoInt('package_id') ?? 0;
        $back = $catalog . '?package_id=' . $id . '#versions';
        [$body, $err] = rivetRmmSwVersionBody($_POST);
        if ($body === null) {
            $_SESSION['rmm_sw_draft']['version'] = rivetRmmSwDraft($_POST, $id);
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $back);
        }
        $r = $api('POST', ['software_packages', $id, 'versions'], [], $body);
        if (!$r['ok']) {
            $_SESSION['rmm_sw_draft']['version'] = rivetRmmSwDraft($_POST, $id);
        }
        rivetRmmAutoDone($r, 'Version added. It cannot be changed afterwards; add a new one instead.', $back);

    case 'retire_version':
        $id = rivetRmmAutoInt('package_id') ?? 0;
        $retire = rivetRmmAutoStr('retired', 1) === '1';
        rivetRmmAutoDone($api('PATCH', ['software_packages', $id, 'versions', rivetRmmAutoInt('version_id') ?? 0], [], ['retired' => $retire]), $retire ? 'Version retired. It is never installed again.' : 'Version restored.', $catalog . '?package_id=' . $id . '#versions');

    case 'add_host':
        $host = rivetRmmAutoStr('host', 253);
        rivetRmmAutoDone($api('POST', ['software_hosts'], [], ['host' => $host, 'note' => rivetRmmAutoStr('note', 120)]), 'Host allowed. Installers can now be downloaded from it.', $catalog . '#hosts');

    case 'remove_host':
        $host = rivetRmmAutoStr('host', 253);
        if ($host === '' || preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $host) !== 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'That is not a host name.'], '', $catalog . '#hosts');
        }
        rivetRmmAutoDone($api('DELETE', ['software_hosts', $host]), 'Host removed. Versions that download from it are refused until it is allowed again.', $catalog . '#hosts');

    case 'assign':
        [$body, $err] = rivetRmmSwAssignBody($_POST);
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $return);
        }
        $r = $api('POST', ['software_assignments'], [], $body);
        if ($r['ok'] && ($r['data']['state'] ?? '') === 'pending_approval') {
            rivetRmmAutoDone($r, 'This package needs a second person: the assignment is saved switched off and goes live when someone else approves it (listed below).', $assignments);
        }
        rivetRmmAutoDone($r, 'Assigned. Devices in the scope are looked at by the next housekeeping pass: first a read-only detection, then a dry run, and a real install only when live installs are switched on.', $assignments);

    case 'unassign':
        rivetRmmAutoDone($api('DELETE', ['software_assignments', rivetRmmAutoInt('assignment_id') ?? 0]), 'Assignment removed. Software already installed stays installed.', $assignments);

    case 'decide_approval':
    case 'reject_approval':
        $id = rivetRmmAutoInt('approval_id') ?? 0;
        $approve = rivetRmmAutoStr('action', 40) === 'decide_approval';
        $r = $api('POST', ['approvals', $id, $approve ? 'approve' : 'reject'], [], ['note' => rivetRmmAutoStr('note', 300)]);
        rivetRmmAutoDone($r, $approve ? 'Approved. The request is applied as it was asked.' : 'Rejected. Nothing was changed.', $assignments);

    case 'cancel_approval':
        rivetRmmAutoDone($api('POST', ['approvals', rivetRmmAutoInt('approval_id') ?? 0, 'cancel'], [], []), 'Request cancelled. Nothing was changed.', $assignments);
}
rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $return);
