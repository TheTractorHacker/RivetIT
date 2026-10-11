<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the alerting pages and the Alerting tab of the asset page (RMM Phase 3): acknowledge / resolve an agent alert, maintenance windows, escalation policies,
 * a device's parent, and per-device threshold overrides. Browser forms get a flash message and a redirect back; a request that asks for JSON gets JSON.
 * Every action is a call into RivetCore's technician API as the signed-in user, which decides (403 / 404 rules, client scoping, audit); this file only checks the
 * CSRF token, the module and the `alerting` switch and turns the form into typed JSON.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_alr_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'alerting', (int) $session_user_id, (string) $session_name);
$api = static fn (string $method, array $segments, ?array $body = null): array => rivetRmmAutoApi($mysqli, $ctx['uid'], $ctx['name'], $method, $segments, [], $body);
$ret = $ctx['return'];

switch (rivetRmmAutoStr('action', 40)) {
    case 'ack_alert':
    case 'resolve_alert':
        $id = rivetRmmAutoInt('alert_id') ?? 0;
        $ack = rivetRmmAutoStr('action', 40) === 'ack_alert';
        if ($id < 1) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing alert.'], '', $ret);
        }
        rivetRmmAutoDone($api('POST', ['alerts', $id, $ack ? 'ack' : 'resolve']), $ack ? 'Alert acknowledged. Its escalation has stopped.' : 'Alert resolved.', $ret);

    case 'save_window':
        [$body, $err] = rivetRmmAlrWindowBody($_POST);
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', rivetRmmAutoSafeReturn('/agent/rmm_maintenance.php?new=1'));
        }
        $id = rivetRmmAutoInt('window_id') ?? 0;
        $r = $id > 0 ? $api('PUT', ['maintenance', $id], $body) : $api('POST', ['maintenance'], $body);
        rivetRmmAutoDone($r, 'Maintenance window saved.', $r['ok'] ? '/agent/rmm_maintenance.php' : ($id > 0 ? '/agent/rmm_maintenance.php?window_id=' . $id : '/agent/rmm_maintenance.php?new=1'));

    case 'delete_window':
        $id = rivetRmmAutoInt('window_id') ?? 0;
        rivetRmmAutoDone($api('DELETE', ['maintenance', $id]), 'Maintenance window deleted.', '/agent/rmm_maintenance.php');

    case 'save_escalation':
        [$body, $err] = rivetRmmAlrPolicyBody($_POST);
        $id = rivetRmmAutoInt('policy_id') ?? 0;
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', '/agent/rmm_escalations.php?' . ($id > 0 ? 'policy_id=' . $id : 'new=1'));
        }
        $r = $id > 0 ? $api('PUT', ['escalation_policies', $id], $body) : $api('POST', ['escalation_policies'], $body);
        rivetRmmAutoDone($r, 'Escalation policy saved.', $r['ok'] ? '/agent/rmm_escalations.php' : '/agent/rmm_escalations.php?' . ($id > 0 ? 'policy_id=' . $id : 'new=1'));

    case 'delete_escalation':
        $id = rivetRmmAutoInt('policy_id') ?? 0;
        rivetRmmAutoDone($api('DELETE', ['escalation_policies', $id]), 'Escalation policy deleted.', '/agent/rmm_escalations.php');

    case 'set_parent':
        $dev = rivetRmmAutoInt('device_id') ?? 0;
        $par = rivetRmmAutoInt('parent_device_id') ?? 0;
        rivetRmmAutoDone($par > 0 ? $api('PUT', [$dev, 'parent'], ['parent_device_id' => $par]) : ['ok' => false, 'status' => 422, 'error' => 'Choose the parent device.'], 'Parent device saved.', $ret);

    case 'clear_parent':
        $dev = rivetRmmAutoInt('device_id') ?? 0;
        rivetRmmAutoDone($api('DELETE', [$dev, 'parent']), 'Parent device removed.', $ret);

    case 'save_thresholds':
    case 'clear_thresholds':
        $dev = rivetRmmAutoInt('device_id') ?? 0;
        $key = rivetRmmAutoStr('check_key', 64);
        if ($dev < 1 || $key === '') {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Missing device or check.'], '', $ret);
        }
        if (rivetRmmAutoStr('action', 40) === 'clear_thresholds') {
            rivetRmmAutoDone($api('DELETE', [$dev, 'checks', $key, 'thresholds']), 'Override removed. The limits of the check definition apply again.', $ret);
        }
        $cur = $api('GET', [$dev, 'checks', $key, 'thresholds']);
        if (!$cur['ok']) {
            rivetRmmAutoDone($cur, '', $ret);
        }
        [$body, $err] = rivetRmmAlrThresholdBody($_POST, is_array($cur['data']['defined'] ?? null) ? $cur['data']['defined'] : null);
        if ($body === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $ret);
        }
        rivetRmmAutoDone($api('PUT', [$dev, 'checks', $key, 'thresholds'], $body), 'Thresholds saved for this device.', $ret);
}
rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ret);
