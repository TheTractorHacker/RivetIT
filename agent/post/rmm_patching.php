<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the patch management pages (RMM Phase 4, RivetCore 1.0.0-rc.20): scan, dry run, install, approve, decline (on one device or across the fleet), decide / cancel a request that
 * waits for a second person, and policy / assignment / ring / approval-rule changes. Browser forms get a flash message and a redirect back; a request that asks for JSON gets JSON.
 *
 * Every action is a call into RivetCore's technician API as the signed-in user (rivetRmmAutoApi()), which decides (the 403 / 404 rules, the department scope, the abilities rmm.patch.view /
 * manage / install, the confirmations, the audit entries); this file only checks the CSRF token, the module and the `patching` switch, and turns the form into typed values (ints stay ints,
 * Core's validators care). A real installation also needs the explicit `confirm=1` field here and Core demands `confirm: true` again.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_pat_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'patching', (int) $session_user_id, (string) $session_name);
$api = static fn (string $method, array $segments, array $query = [], ?array $body = null): array => rivetRmmAutoApi($mysqli, $ctx['uid'], $ctx['name'], $method, $segments, $query, $body);
$return = $ctx['return'];
$deviceId = static fn (): int => max(0, (int) (rivetRmmAutoInt('device_id') ?? 0));
$needDevice = static function (int $id) use ($return): void {
    if ($id <= 0) {
        rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'No device was named.'], '', $return);
    }
};
/** update ids from the form: a list of strings, at most 200 (Core validates the syntax again). */
$updateIds = static function (): array {
    $raw = is_array($_POST['update_ids'] ?? null) ? $_POST['update_ids'] : [];

    return array_slice(array_values(array_unique(array_filter($raw, static fn ($v): bool => is_string($v) && $v !== '' && strlen($v) <= 120))), 0, 200);
};
$policies = '/agent/rmm_patch_policies.php';
$updatesPage = '/agent/rmm_patch_updates.php';

switch (rivetRmmAutoStr('action', 40)) {
    case 'scan':
        $id = $deviceId();
        $needDevice($id);
        $r = $api('POST', [$id, 'patches', 'scan']);
        rivetRmmAutoDone($r, ($r['data']['state'] ?? '') === 'already_queued' ? 'A scan is already waiting for this device.' : 'Scan queued. The device picks it up at its next check-in and the list refreshes when it answers.', $return);

    case 'dry_run':
        $id = $deviceId();
        $needDevice($id);
        $r = $api('POST', [$id, 'patches', 'install'], [], rivetRmmPatInstallBody($_POST, 'dry_run'));
        $msg = (int) ($r['data']['queued'] ?? 0) > 0 ? 'Dry run queued for ' . (int) $r['data']['queued'] . ' update(s). Nothing is changed on the device; the result appears under Install history.' : (string) ($r['data']['state'] ?? $r['error'] ?? 'Nothing to do.');
        rivetRmmAutoDone($r, $r['ok'] && (int) ($r['data']['queued'] ?? 0) === 0 ? 'There is nothing to install: no approved update on this device (or one is already waiting).' : $msg, $return);

    case 'install':
        $id = $deviceId();
        $needDevice($id);
        if (rivetRmmAutoStr('confirm', 1) !== '1') {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick the confirmation box to install. Nothing was installed.'], '', $return);
        }
        $r = $api('POST', [$id, 'patches', 'install'], [], rivetRmmPatInstallBody($_POST, 'install'));
        rivetRmmAutoDone($r, $r['ok'] && (int) ($r['data']['queued'] ?? 0) === 0 ? 'There is nothing to install: no approved update on this device (or an install is already waiting).' : 'Install queued for ' . (int) ($r['data']['queued'] ?? 0) . ' update(s)'
            . ((int) ($r['data']['remaining'] ?? 0) > 0 ? ' (' . (int) $r['data']['remaining'] . ' more follow in a later job)' : '') . '. The device picks it up at its next check-in.', $return);

    case 'approve':
    case 'decline':
        $approve = rivetRmmAutoStr('action', 40) === 'approve';
        $ids = $updateIds();
        if ($ids === []) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick at least one update first.'], '', $return);
        }
        $body = ['update_ids' => $ids];
        if (($d = $deviceId()) > 0) {
            $body['device_id'] = $d;
        } elseif (($c = (int) (rivetRmmAutoInt('client_id') ?? 0)) > 0) {
            $body['client_id'] = $c;
        }
        $reason = rivetRmmAutoStr('reason', 200);
        if (!$approve && $reason !== '') {
            $body['reason'] = $reason;
        }
        $r = $api('POST', ['patches', $approve ? 'approve' : 'decline'], [], $body);
        if ($r['ok'] && ($r['data']['state'] ?? '') === 'pending_approval') {
            rivetRmmAutoDone($r, 'A policy asks for a second person: your approval waits under Patch approvals until someone else approves it.', $updatesPage);
        }
        rivetRmmAutoDone($r, ($approve ? 'Approved' : 'Declined') . ' ' . (int) ($r['data']['changed'] ?? 0) . ' update row(s)' . ((int) ($r['data']['matched'] ?? 0) > (int) ($r['data']['changed'] ?? 0) ? ' (the rest already had that status)' : '') . '.', $return);

    case 'decide_approval':
    case 'reject_approval':
        $id = rivetRmmAutoInt('approval_id') ?? 0;
        $approve = rivetRmmAutoStr('action', 40) === 'decide_approval';
        $r = $api('POST', ['approvals', $id, $approve ? 'approve' : 'reject'], [], ['note' => rivetRmmAutoStr('note', 300)]);
        rivetRmmAutoDone($r, $approve ? 'Approved. The updates are now approved; installation follows each device\'s patch policy.' : 'Rejected. Nothing was approved.', $updatesPage);

    case 'cancel_approval':
        $id = rivetRmmAutoInt('approval_id') ?? 0;
        rivetRmmAutoDone($api('POST', ['approvals', $id, 'cancel'], [], []), 'Request cancelled. Nothing was approved.', $updatesPage);

    case 'save_policy':
        $id = rivetRmmAutoInt('policy_id') ?? 0;
        $back = $id > 0 ? $policies . '?policy_id=' . $id : $policies . '?new=1';
        [$body, $err] = rivetRmmPatPolicyBody($_POST);
        if ($body === null) {
            $_SESSION['rmm_pat_draft']['policy'] = rivetRmmPatDraft($_POST, $id);
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $back);
        }
        $r = $id > 0 ? $api('PUT', ['patch_policies', $id], [], $body) : $api('POST', ['patch_policies'], [], $body);
        if (!$r['ok']) {
            $_SESSION['rmm_pat_draft']['policy'] = rivetRmmPatDraft($_POST, $id);
        }
        $newId = (int) ($r['data']['policy']['policy_id'] ?? $id);
        rivetRmmAutoDone($r, $id > 0 ? 'Policy saved.' : 'Policy created. It applies to nothing until you assign it below.', $r['ok'] ? $policies . '?policy_id=' . $newId : $back);

    case 'delete_policy':
        rivetRmmAutoDone($api('DELETE', ['patch_policies', rivetRmmAutoInt('policy_id') ?? 0]), 'Policy deleted.', $policies);

    case 'assign_policy':
        $id = rivetRmmAutoInt('policy_id') ?? 0;
        $scope = rivetRmmAutoReadScope('asg', ['global', 'client', 'site', 'group', 'tag', 'device']);
        $prio = rivetRmmAutoInt('priority') ?? 100;
        rivetRmmAutoDone($api('POST', ['patch_policies', $id, 'assignments'], [], ['scope_type' => $scope['type'], 'scope_id' => $scope['id'], 'priority' => $prio, 'enforce' => rivetRmmAutoStr('enforce', 1) === '1']),
            'Policy assigned.', $policies . '?policy_id=' . $id);

    case 'unassign_policy':
        $id = rivetRmmAutoInt('policy_id') ?? 0;
        rivetRmmAutoDone($api('DELETE', ['patch_policies', $id, 'assignments', rivetRmmAutoInt('assignment_id') ?? 0]), 'Assignment removed.', $policies . '?policy_id=' . $id);

    case 'save_ring':
        $id = rivetRmmAutoInt('ring_id') ?? 0;
        $delay = rivetRmmAutoStr('delay_days', 5);
        $order = rivetRmmAutoStr('sort_order', 7);
        if (preg_match('/^\d{1,3}$/', $delay) !== 1 || ($order !== '' && preg_match('/^\d{1,6}$/', $order) !== 1)) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'The delay must be a whole number of days (0 to 365) and the order a whole number.'], '', $policies . '#rings');
        }
        $body = ['name' => rivetRmmAutoStr('name', 39), 'delay_days' => (int) $delay, 'sort_order' => $order === '' ? 100 : (int) $order, 'description' => rivetRmmAutoStr('description', 200)];
        rivetRmmAutoDone($id > 0 ? $api('PUT', ['patches', 'rings', $id], [], $body) : $api('POST', ['patches', 'rings'], [], $body), 'Ring saved.', $policies . '#rings');

    case 'delete_ring':
        rivetRmmAutoDone($api('DELETE', ['patches', 'rings', rivetRmmAutoInt('ring_id') ?? 0]), 'Ring deleted.', $policies . '#rings');

    case 'save_rule':
        $id = rivetRmmAutoInt('rule_id') ?? 0;
        $back = $policies . ($id > 0 ? '?rule_id=' . $id : '?new_rule=1') . '#rules';
        [$body, $err] = rivetRmmPatRuleBody($_POST);
        if ($body === null) {
            $_SESSION['rmm_pat_draft']['rule'] = rivetRmmPatDraft($_POST, $id);
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $back);
        }
        $r = $id > 0 ? $api('PUT', ['patches', 'rules', $id], [], $body) : $api('POST', ['patches', 'rules'], [], $body);
        if (!$r['ok']) {
            $_SESSION['rmm_pat_draft']['rule'] = rivetRmmPatDraft($_POST, $id);
        }
        rivetRmmAutoDone($r, 'Rule saved.', $r['ok'] ? $policies . '#rules' : $back);

    case 'delete_rule':
        rivetRmmAutoDone($api('DELETE', ['patches', 'rules', rivetRmmAutoInt('rule_id') ?? 0]), 'Rule deleted.', $policies . '#rules');
}
rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $return);
