<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Policies and custom fields: the one handler behind agent/rmm_policies.php, agent/rmm_fields.php and the "Policy and fields" tab of the asset page.
 * Each action turns the form into the exact typed JSON RivetCore expects and calls its technician API as the signed-in user; RivetCore decides (admin,
 * client scope, audit) and its validation message is what the user sees. Policy actions need the `policies` switch, field actions the `scripts` switch.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_pol_ui.php';

mysqli_report(MYSQLI_REPORT_OFF);

$action = rivetRmmAutoStr('action', 40);
$feature = in_array($action, ['save_field', 'delete_field', 'set_field_value', 'set_device_field'], true) ? 'scripts' : 'policies';
$ctx = rivetRmmAutoPostBegin($mysqli, $feature, (int) $session_user_id, (string) $session_name);
$ctx['mysqli'] = $mysqli;
$ret = $ctx['return'];
$call = static fn (string $method, array $seg, ?array $body = null): array => rivetRmmPolCall($ctx, $method, $seg, [], $body);

/** A whole number field or an error result. */
$fail = static fn (string $msg): array => ['ok' => false, 'status' => 422, 'error' => $msg];

switch ($action) {
    case 'save_policy':
        $pid = max(0, (int) rivetRmmAutoInt('policy_id'));
        $catalog = rivetRmmPolCatalog($ctx);
        [$settings, $errors] = rivetRmmPolBuildSettings($_POST, $catalog);
        $name = rivetRmmAutoStr('name', 200);
        $desc = rivetRmmAutoStr('description', 400);
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] !== '';
        $draft = ['for' => $pid, 'name' => $name, 'description' => $desc, 'enabled' => $enabled, 'settings' => $settings];
        $back = $pid > 0 ? '/agent/rmm_policies.php?policy_id=' . $pid : '/agent/rmm_policies.php?new=1';
        if ($errors !== []) {
            $_SESSION['rmm_pol_draft'] = $draft;
            rivetRmmAutoDone($fail(implode(' ', array_slice($errors, 0, 4))), '', $back);
        }
        if ($pid > 0) {
            $r = $call('PUT', ['policies', $pid], ['name' => $name, 'description' => $desc, 'enabled' => $enabled, 'settings' => $settings]);
            if (!$r['ok']) {
                $_SESSION['rmm_pol_draft'] = $draft;
            }
            rivetRmmAutoDone($r, 'Policy saved.', $back);
        }
        $r = $call('POST', ['policies'], ['name' => $name, 'description' => $desc, 'settings' => $settings]);
        if (!$r['ok']) {
            $_SESSION['rmm_pol_draft'] = $draft;
            rivetRmmAutoDone($r, '', $back);
        }
        $newId = (int) ($r['data']['policy']['policy_id'] ?? 0);
        if (!$enabled && $newId > 0) {
            $call('PUT', ['policies', $newId], ['enabled' => false]);
        }
        rivetRmmAutoDone($r, 'Policy created. Assign it to a scope so it reaches devices.', '/agent/rmm_policies.php?policy_id=' . $newId);

    case 'delete_policy':
        $pid = (int) rivetRmmAutoInt('policy_id');
        rivetRmmAutoDone($call('DELETE', ['policies', $pid]), 'Policy deleted.', '/agent/rmm_policies.php');

    case 'toggle_policy':
        $pid = (int) rivetRmmAutoInt('policy_id');
        $on = rivetRmmAutoInt('enabled') === 1;
        rivetRmmAutoDone($call('PUT', ['policies', $pid], ['enabled' => $on]), $on ? 'Policy turned on.' : 'Policy turned off.', $ret);

    case 'assign_policy':
        $pid = (int) rivetRmmAutoInt('policy_id');
        $scope = rivetRmmAutoReadScope('as', ['global', 'client', 'site', 'group', 'tag', 'device']);
        $prioRaw = rivetRmmAutoStr('priority', 12);
        $prio = $prioRaw === '' ? 100 : rivetRmmPolIntOf($prioRaw);
        if ($prio === null || $prio === false || $prio < 0 || $prio > 1000000) {
            rivetRmmAutoDone($fail('Priority must be a whole number from 0 to 1000000.'), '', $ret);
        }
        $body = ['scope_type' => $scope['type'], 'priority' => $prio, 'enforce' => isset($_POST['enforce']) && $_POST['enforce'] !== ''];
        if ($scope['type'] !== 'global') {
            if ($scope['id'] <= 0) {
                rivetRmmAutoDone($fail('Choose what to assign the policy to.'), '', $ret);
            }
            $body['scope_id'] = $scope['id'];
        }
        rivetRmmAutoDone($call('POST', ['policies', $pid, 'assignments'], $body), 'Policy assigned.', $ret);

    case 'unassign_policy':
        rivetRmmAutoDone($call('DELETE', ['policies', (int) rivetRmmAutoInt('policy_id'), 'assignments', (int) rivetRmmAutoInt('assignment_id')]), 'Assignment removed.', $ret);

    case 'save_field':
        $fid = max(0, (int) rivetRmmAutoInt('field_id'));
        $lines = static fn (string $k): array => array_values(array_filter(array_map('trim', preg_split('/\R/', rivetRmmAutoStr($k, 10000)) ?: []), static fn (string $s): bool => $s !== ''));
        if ($fid === 0) {
            $body = ['name' => rivetRmmAutoStr('name', 80), 'label' => rivetRmmAutoStr('label', 200), 'scope' => rivetRmmAutoStr('scope', 10), 'type' => rivetRmmAutoStr('type', 10), 'description' => rivetRmmAutoStr('description', 400)];
            if ($body['type'] === 'list') {
                $body['options'] = $lines('options');
            }
            if ($body['type'] !== 'secret' && rivetRmmAutoStr('default', 2000) !== '') {
                $body['default'] = rivetRmmAutoStr('default', 2000);
            }
            rivetRmmAutoDone($call('POST', ['fields'], $body), 'Field defined.', $ret);
        }
        $body = ['label' => rivetRmmAutoStr('label', 200), 'description' => rivetRmmAutoStr('description', 400)];
        if (isset($_POST['options'])) {
            $body['options'] = $lines('options');
        }
        if (isset($_POST['default'])) {
            $body['default'] = rivetRmmAutoStr('default', 2000) === '' ? null : rivetRmmAutoStr('default', 2000);
        }
        rivetRmmAutoDone($call('PUT', ['fields', $fid], $body), 'Field saved.', $ret);

    case 'delete_field':
        rivetRmmAutoDone($call('DELETE', ['fields', (int) rivetRmmAutoInt('field_id')]), 'Field deleted.', '/agent/rmm_fields.php');

    case 'set_field_value':
        $fid = (int) rivetRmmAutoInt('field_id');
        $sid = (int) rivetRmmAutoInt('scope_id');
        $clear = isset($_POST['clear']) && $_POST['clear'] !== '';
        $value = $_POST['value'] ?? '';
        $value = is_string($value) ? $value : '';
        if (!$clear && trim($value) === '') {
            rivetRmmAutoDone($fail('Enter a value, or use Clear value.'), '', $ret);
        }
        $body = ['value' => $clear ? null : $value];
        $def = $call('GET', ['fields', $fid]);
        if ($def['ok'] && ($def['data']['field']['scope'] ?? '') === 'site') {
            // Core wants the department the site belongs to; it checks the site really is in it and that the user may manage that department.
            $q = mysqli_query($mysqli, 'SELECT location_client_id FROM locations WHERE location_id = ' . $sid);
            $row = $q ? mysqli_fetch_assoc($q) : null;
            $body['client_id'] = (int) ($row['location_client_id'] ?? 0);
        }
        rivetRmmAutoDone($call('PUT', ['fields', $fid, 'values', $sid], $body), $clear ? 'Value cleared.' : 'Value saved.', $ret);

    case 'set_device_field':
        $clear = isset($_POST['clear']) && $_POST['clear'] !== '';
        $value = $_POST['value'] ?? '';
        $value = is_string($value) ? $value : '';
        if (!$clear && trim($value) === '') {
            rivetRmmAutoDone($fail('Enter a value, or use Clear.'), '', $ret);
        }
        rivetRmmAutoDone($call('PUT', [(int) rivetRmmAutoInt('device_id'), 'fields', (int) rivetRmmAutoInt('field_id')], ['value' => $clear ? null : $value]), $clear ? 'Value cleared.' : 'Value saved.', $ret);
}

rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ret);
