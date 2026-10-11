<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the Starter pack page (RivetCore 1.0.0-rc.13): plan, install, uninstall_preview, uninstall. A direct endpoint like agent/post/rmm_automation_scr.php. The prologue
 * (rivetRmmAutoPostBegin) checks POST, the CSRF token, the module and the `scripts` sub-switch; every action then calls RivetCore's technician API as the signed-in user
 * (endpoint_devices/starter/*), which needs rmm.admin and decides everything else. The ticked ids and the policy parameters are converted into the exact typed JSON Core
 * wants. An empty selection is refused HERE, because Core reads an empty selection as "the whole pack". The answer is kept in the session for the page to show once.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_starter_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'scripts', (int) $session_user_id, (string) $session_name);
$ret = $ctx['return'];
$action = rivetRmmAutoStr('action', 30);
if (!in_array($action, ['plan', 'install', 'uninstall_preview', 'uninstall'], true)) {
    rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ret);
}
if (!$ctx['perm']['admin']) {
    rivetRmmAutoDone(['ok' => false, 'status' => 403, 'error' => 'The starter pack needs an RMM administrator.'], '', $ret);
}
$sel = rivetRmmStarterSelectionFromPost();
if ($sel['ids'] === []) {
    rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Tick at least one item first. Nothing was changed.'], '', $ret);
}
$body = ['ids' => $sel['ids']];
if ($sel['params'] !== [] && in_array($action, ['plan', 'install'], true)) {
    $body['params'] = $sel['params'];
}
if ($action === 'uninstall_preview') {
    $body['dry_run'] = true;
}
$path = ['plan' => 'plan', 'install' => 'install', 'uninstall_preview' => 'uninstall', 'uninstall' => 'uninstall'][$action];
$r = rivetRmmAutoApi($mysqli, $ctx['uid'], $ctx['name'], 'POST', ['starter', $path], [], $body);
if (!$r['ok']) {
    rivetRmmAutoDone($r, '', $ret);
}
$counts = (array) ($r['data']['counts'] ?? []);
$n = array_sum(array_map('intval', $counts));
$_SESSION['rmm_starter_result'] = ['kind' => $action, 'data' => ['items' => (array) ($r['data']['items'] ?? []), 'counts' => $counts, 'ready' => $r['data']['ready'] ?? null], 'ids' => $sel['ids'], 'params' => $sel['raw_params']];
$msg = [
    'plan' => 'Planned ' . $n . ' item' . ($n === 1 ? '' : 's') . '. Nothing was changed.',
    'install' => 'Install finished for ' . $n . ' item' . ($n === 1 ? '' : 's') . '.',
    'uninstall_preview' => 'Previewed ' . $n . ' item' . ($n === 1 ? '' : 's') . '. Nothing was changed.',
    'uninstall' => 'Uninstall finished for ' . $n . ' item' . ($n === 1 ? '' : 's') . '.',
][$action];
rivetRmmAutoDone($r, $msg, $ret . '#starter-result');
