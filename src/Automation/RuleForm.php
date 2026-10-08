<?php

namespace ITFlow\Automation;

use ITFlow\Automation\Actions\ActionRegistry;

/**
 * The Event rules editor's form contract, shared by the save handler (admin/post/event_rules.php) and the JSON endpoint
 * (admin/modals/event_rules_api.php), so what "Save", the live summary and the Test drawer accept can never drift apart.
 *
 * Fields: rule_name, trigger_event, cond_mode, cond_field[] / cond_op[] / cond_value[] / cond_group[] (group letter A-J, '' = top level),
 * group_mode[A..J], action_type, cfg_* (per action, see actionInput()), is_enabled, rule_priority, stop_on_match, rate_limit.
 * An older form (A, B, C groups, three fixed rows) posts the same names.
 */
final class RuleForm
{
    public const GROUPS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

    /** @param array<string,mixed> $p the raw POST @return array{name:string,trigger:string,mode:string,rows:list<array<string,string>>,groupModes:array<string,string>,action:string,input:array<string,mixed>,enabled:bool,priority:int,stop:bool,rate:int} */
    public static function parse(array $p): array
    {
        $rows = [];
        $fields = (array) ($p['cond_field'] ?? []);
        $ops = (array) ($p['cond_op'] ?? []);
        $values = (array) ($p['cond_value'] ?? []);
        $groups = (array) ($p['cond_group'] ?? []);
        foreach ($fields as $i => $f) {
            $g = is_scalar($groups[$i] ?? '') ? (string) ($groups[$i] ?? '') : '';
            $rows[] = ['field' => is_scalar($f) ? (string) $f : '', 'op' => is_scalar($ops[$i] ?? 'eq') ? (string) ($ops[$i] ?? 'eq') : 'eq',
                'value' => is_scalar($values[$i] ?? '') ? (string) ($values[$i] ?? '') : '', 'group' => in_array($g, self::GROUPS, true) ? $g : ''];
        }
        $action = is_scalar($p['action_type'] ?? '') ? (string) ($p['action_type'] ?? '') : '';

        return [
            'name' => is_scalar($p['rule_name'] ?? '') ? (string) ($p['rule_name'] ?? '') : '',
            'trigger' => is_scalar($p['trigger_event'] ?? '') ? (string) ($p['trigger_event'] ?? '') : '',
            'mode' => is_scalar($p['cond_mode'] ?? 'all') ? (string) ($p['cond_mode'] ?? 'all') : 'all',
            'rows' => $rows,
            'groupModes' => array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', (array) ($p['group_mode'] ?? [])),
            'action' => $action,
            'input' => self::actionInput($action, $p),
            'enabled' => isset($p['is_enabled']),
            'priority' => intval($p['rule_priority'] ?? 100),
            'stop' => isset($p['stop_on_match']),
            'rate' => intval($p['rate_limit'] ?? RuleEngine::DEFAULT_RATE),
        ];
    }

    /** The condition model of a parsed form. @throws \InvalidArgumentException */
    public static function model(array $parsed): array
    {
        return ConditionEvaluator::fromForm($parsed['mode'], $parsed['rows'], $parsed['groupModes']);
    }

    /** Saves a parsed form. @throws \InvalidArgumentException @return int rule id */
    public static function save(RuleEngine $engine, ?int $id, array $parsed): int
    {
        return $engine->save($id, $parsed['name'], $parsed['trigger'], self::model($parsed), $parsed['action'], $parsed['input'], $parsed['enabled'], $parsed['priority'], $parsed['stop'], $parsed['rate']);
    }

    /**
     * Section by section problems of a parsed form without saving, for inline hints and the summary panel.
     * RuleEngine::save() stays authoritative; this repeats its checks so they can be shown early.
     * @return array<string,string> section (name|trigger|conditions|action|settings) => message
     */
    public static function problems(\mysqli $mysqli, array $parsed): array
    {
        $out = [];
        $name = trim($parsed['name']);
        if ($name === '' || mb_strlen($name) > 200) {
            $out['name'] = 'Give the rule a name (up to 200 characters).';
        }
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', trim($parsed['trigger']))) {
            $out['trigger'] = 'Choose the event that triggers the rule.';
        }
        try {
            self::model($parsed);
        } catch (\InvalidArgumentException $e) {
            $out['conditions'] = $e->getMessage();
        }
        $action = ActionRegistry::get($parsed['action']);
        if ($action === null) {
            $out['action'] = 'Choose what the rule does.';
        } elseif (in_array($parsed['action'], ActionRegistry::TICKET_ACTIONS, true) && !RuleEngine::eventHasTicket(trim($parsed['trigger'])) && !isset($out['trigger'])) {
            $out['action'] = '"' . $action->label() . '" needs an event that is about a ticket (for example ticket.created); ' . trim($parsed['trigger']) . ' is not.';
        } else {
            try {
                $action->validate($mysqli, $parsed['input'], trim($parsed['trigger']));
            } catch (\InvalidArgumentException $e) {
                $out['action'] = $e->getMessage();
            }
        }
        if ($parsed['priority'] < 1 || $parsed['priority'] > 9999 || $parsed['rate'] < 1 || $parsed['rate'] > 1000) {
            $out['settings'] = 'Order must be 1-9999 and the rate limit 1-1000 runs per minute.';
        }

        return $out;
    }

    /**
     * A rule-shaped array for RuleEngine::test() built from the unsaved form (nothing is stored). @throws \InvalidArgumentException when conditions or action are invalid
     * @return array<string,mixed>
     */
    public static function ruleFromForm(\mysqli $mysqli, array $parsed, int $ruleId = 0): array
    {
        $action = ActionRegistry::get($parsed['action']);
        if ($action === null) {
            throw new \InvalidArgumentException('Choose what the rule does.');
        }
        $cfg = $action->validate($mysqli, $parsed['input'], trim($parsed['trigger']));

        return ['rule_id' => $ruleId, 'name' => trim($parsed['name']) ?: '(unsaved rule)', 'trigger_event' => trim($parsed['trigger']), 'condition_json' => ConditionEvaluator::serialize(self::model($parsed)),
            'action_type' => $parsed['action'], 'action_config' => $cfg, 'action_config_json' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_enabled' => $parsed['enabled'] ? 1 : 0, 'priority' => $parsed['priority'], 'stop_on_match' => $parsed['stop'] ? 1 : 0, 'rate_limit_per_min' => $parsed['rate']];
    }

    /** The raw form values of the chosen action, in the shape each action's validate() expects. @param array<string,mixed> $p */
    public static function actionInput(string $action, array $p): array
    {
        $s = static fn ($k, $d = ''): string => is_scalar($p[$k] ?? $d) ? (string) ($p[$k] ?? $d) : '';
        switch ($action) {
            case 'create_ticket':
                return ['subject' => $s('cfg_subject'), 'details' => $s('cfg_details'), 'priority' => $s('cfg_priority', 'Low')];
            case 'send_webhook':
                return ['url' => $s('cfg_url'), 'secret' => $s('cfg_secret')];
            case 'notify_user':
                return ['message' => $s('cfg_message')];
            case 'start_workflow':
                return ['template_id' => intval($s('cfg_template_id'))];
            case 'set_ticket_field':
                return ['status' => $s('cfg_sf_status'), 'priority' => $s('cfg_sf_priority'), 'category_id' => intval($s('cfg_sf_category')), 'assignee' => $s('cfg_sf_assignee')];
            case 'add_ticket_note':
                return ['note' => $s('cfg_note')];
            case 'assign_ticket':
                return ['mode' => $s('cfg_as_mode', 'user'), 'user_id' => intval($s('cfg_as_user')), 'pool' => is_array($p['cfg_as_pool'] ?? null) ? $p['cfg_as_pool'] : []];
            case 'send_mail':
                return ['to_type' => $s('cfg_mail_to'), 'address' => $s('cfg_mail_address'), 'user_id' => intval($s('cfg_mail_user')), 'subject' => $s('cfg_mail_subject'), 'body' => $s('cfg_mail_body')];
            case 'create_task':
                return ['name' => $s('cfg_task_name'), 'assignee_id' => intval($s('cfg_task_assignee')), 'due_days' => intval($s('cfg_task_due_days'))];
        }

        return [];
    }

    /** Stored action config back to the form field names (the inverse of actionInput()). @param array<string,mixed> $cfg @return array<string,mixed> */
    public static function configToForm(string $action, array $cfg): array
    {
        switch ($action) {
            case 'create_ticket':
                return ['cfg_subject' => $cfg['subject'] ?? '', 'cfg_details' => $cfg['details'] ?? '', 'cfg_priority' => $cfg['priority'] ?? 'Low'];
            case 'send_webhook':
                return ['cfg_url' => $cfg['url'] ?? '', 'cfg_secret' => $cfg['secret'] ?? ''];
            case 'notify_user':
                return ['cfg_message' => $cfg['message'] ?? ''];
            case 'start_workflow':
                return ['cfg_template_id' => (int) ($cfg['template_id'] ?? 0)];
            case 'set_ticket_field':
                return ['cfg_sf_status' => $cfg['status'] ?? '', 'cfg_sf_priority' => $cfg['priority'] ?? '', 'cfg_sf_category' => (int) ($cfg['category_id'] ?? 0),
                    'cfg_sf_assignee' => array_key_exists('assignee', $cfg) ? ((int) $cfg['assignee'] === 0 ? 'none' : (string) (int) $cfg['assignee']) : ''];
            case 'add_ticket_note':
                return ['cfg_note' => $cfg['note'] ?? ''];
            case 'assign_ticket':
                return ['cfg_as_mode' => $cfg['mode'] ?? 'user', 'cfg_as_user' => (int) ($cfg['user_id'] ?? 0), 'cfg_as_pool' => array_map('intval', (array) ($cfg['pool'] ?? []))];
            case 'send_mail':
                return ['cfg_mail_to' => $cfg['to_type'] ?? 'assignee', 'cfg_mail_address' => $cfg['address'] ?? '', 'cfg_mail_user' => (int) ($cfg['user_id'] ?? 0), 'cfg_mail_subject' => $cfg['subject'] ?? '', 'cfg_mail_body' => $cfg['body'] ?? ''];
            case 'create_task':
                return ['cfg_task_name' => $cfg['name'] ?? '', 'cfg_task_assignee' => (int) ($cfg['assignee_id'] ?? 0), 'cfg_task_due_days' => (int) ($cfg['due_days'] ?? 0)];
        }

        return [];
    }

    /**
     * Condition model to builder rows for the editor: top-level mode, and a flat list of leaves with their group letter.
     * @param array{mode:string,conditions:array<int,array<string,mixed>>} $model
     * @return array{mode:string,rows:list<array{field:string,op:string,value:string,group:string}>,groupModes:array<string,string>}
     */
    public static function modelToRows(array $model): array
    {
        $rows = [];
        $modes = [];
        $gi = 0;
        foreach ($model['conditions'] as $c) {
            if (isset($c['conditions'])) {
                $letter = self::GROUPS[min($gi++, count(self::GROUPS) - 1)];
                $modes[$letter] = $c['mode'];
                foreach ($c['conditions'] as $leaf) {
                    $rows[] = ['field' => $leaf['field'], 'op' => $leaf['op'], 'value' => $leaf['value'], 'group' => $letter];
                }
            } else {
                $rows[] = ['field' => $c['field'], 'op' => $c['op'], 'value' => $c['value'], 'group' => ''];
            }
        }

        return ['mode' => $model['mode'], 'rows' => $rows, 'groupModes' => $modes];
    }
}
