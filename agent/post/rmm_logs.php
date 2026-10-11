<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the Log rules page (RMM Phase 8): create / change a rule, switch it on or off, delete it. Browser forms get a flash message and a redirect back; a request that
 * asks for JSON gets JSON. Every action is a call into RivetCore's LogActions as the signed-in user, which decides (403 / 404 rules, department scope, audit); this file only
 * checks the CSRF token, the module and the `logs` switch, and turns the form into typed values.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_log_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'logs', (int) $session_user_id, (string) $session_name);
$actions = rivetRmmModule($mysqli)->logActions();
$who = rivetRmmPrincipal($ctx['uid'], $ctx['name']);
$list = '/agent/rmm_log_rules.php';

switch (rivetRmmAutoStr('action', 40)) {
    case 'save_rule':
        $id = rivetRmmAutoInt('rule_id') ?? 0;
        $back = $id > 0 ? $list . '?rule_id=' . $id : $list . '?new=1';
        [$body, $err] = rivetRmmLogRuleBody($_POST);
        if ($body === null) {
            $_SESSION['rmm_log_rule_draft'] = rivetRmmLogRuleDraft($_POST, $id);
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => $err], '', $back);
        }
        $r = rivetRmmLogResult($id > 0 ? $actions->updateRule($who, $id, $body) : $actions->createRule($who, $body));
        if (!$r['ok']) {
            $_SESSION['rmm_log_rule_draft'] = rivetRmmLogRuleDraft($_POST, $id);
        }
        rivetRmmAutoDone($r, $id > 0 ? 'Rule saved.' : 'Rule created.', $r['ok'] ? $list : $back, ['rule_id' => (int) ($r['data']['rule_id'] ?? $id)]);

    case 'toggle_rule':
        $id = rivetRmmAutoInt('rule_id') ?? 0;
        $cur = $actions->getRule($who, $id);
        if (!$cur->ok) {
            rivetRmmAutoDone(rivetRmmLogResult($cur), '', $list);
        }
        $on = !$cur->data['enabled'];
        rivetRmmAutoDone(rivetRmmLogResult($actions->updateRule($who, $id, ['enabled' => $on ? 1 : 0])), $on ? 'Rule turned on.' : 'Rule turned off. Alerts it has open close at the next housekeeping run.', $list);

    case 'delete_rule':
        $id = rivetRmmAutoInt('rule_id') ?? 0;
        rivetRmmAutoDone(rivetRmmLogResult($actions->deleteRule($who, $id)), 'Rule deleted. Alerts it had open close at the next housekeeping run.', $list);
}
rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $list);
