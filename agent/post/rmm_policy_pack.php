<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the "Starter policies" card on the Policies page (RivetCore 1.0.0-rc.27, no migration): plan, install, update, reset, uninstall.
 * A direct endpoint like agent/post/rmm_starter.php. The prologue (rivetRmmAutoPostBegin) checks POST, the CSRF token, the module and the
 * `policies` sub-switch; every action then calls RivetCore's technician API as the signed-in user (endpoint_devices/starter/policies/*),
 * which needs rmm.admin and decides everything else (states, blocking, the instance-wide and in-use guards). The ticked ids and the
 * per-item install parameters are converted into the exact typed JSON Core wants. An empty selection is refused HERE, because Core reads an
 * empty selection of ids as "every item in the pack". The answer is kept in the session for the page to show once.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_pack_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'policies', (int) $session_user_id, (string) $session_name);
$ret = $ctx['return'];
$action = rivetRmmAutoStr('action', 30);
if (!in_array($action, ['plan', 'install', 'update', 'reset', 'uninstall'], true)) {
    rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ret);
}
if (!$ctx['perm']['admin']) {
    rivetRmmAutoDone(['ok' => false, 'status' => 403, 'error' => 'The policy starter pack needs an RMM administrator.'], '', $ret);
}
$sel = rivetRmmPackSelectionFromPost();
if ($sel['ids'] === []) {
    rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick at least one item first. Nothing was changed.'], '', $ret);
}
$body = ['ids' => $sel['ids']];
if ($sel['params'] !== [] && in_array($action, ['plan', 'install', 'update', 'reset'], true)) {
    $body['params'] = $sel['params'];
}
if ($sel['confirm_instance_wide']) {
    $body['confirm_instance_wide'] = true;
}
if ($sel['include_live'] && in_array($action, ['plan', 'update'], true)) {
    $body['include_live'] = true;
}
// The card's checkbox selection and "Plan" button only ever preview an install (its one bulk op); Update/Reset/Uninstall are single-item
// row actions that go straight to the real call, because their outcome is already determined by the row's own state badge.
$op = 'install';
if ($action === 'plan') {
    $body['op'] = $op;
}
$path = $action === 'plan' ? 'plan' : $action;
$r = rivetRmmAutoApi($mysqli, $ctx['uid'], $ctx['name'], 'POST', ['starter', 'policies', $path], [], $body);
if (!$r['ok']) {
    rivetRmmAutoDone($r, '', $ret);
}
$counts = (array) ($r['data']['counts'] ?? []);
$n = (int) array_sum(array_map('intval', $counts));   // Core's JSON answer, not trusted as a type: cast to int before it goes anywhere, even into a plain English message.
$_SESSION['rmm_pack_result'] = ['kind' => $action, 'op' => $op, 'data' => ['items' => (array) ($r['data']['items'] ?? []), 'counts' => $counts], 'ids' => $sel['ids'], 'params' => $sel['raw_params'], 'confirm_instance_wide' => $sel['confirm_instance_wide']];
$msg = [
    'plan' => 'Planned ' . (int) $n . ' item' . ($n === 1 ? '' : 's') . '. Nothing was changed.',
    'install' => 'Install finished for ' . (int) $n . ' item' . ($n === 1 ? '' : 's') . '.',
    'update' => 'Update finished for ' . (int) $n . ' item' . ($n === 1 ? '' : 's') . '.',
    'reset' => 'Reset finished for ' . (int) $n . ' item' . ($n === 1 ? '' : 's') . '.',
    'uninstall' => 'Uninstall finished for ' . (int) $n . ' item' . ($n === 1 ? '' : 's') . '.',
][$action];
rivetRmmAutoDone($r, $msg, $ret . '#pack-result');
