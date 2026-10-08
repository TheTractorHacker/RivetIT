<?php

namespace ITFlow\Automation;

use ITFlow\Automation\Actions\ActionRegistry;

/** Plain-language descriptions of a stored rule, shared by the Event rules and Automation pages (returns text; callers escape). */
final class RuleView
{
    /** @return list<string> one line per top-level condition or group */
    public static function conditions($raw): array
    {
        $m = ConditionEvaluator::normalize($raw);
        if ($m === null) {
            return ['(invalid conditions: the rule never fires)'];
        }
        $leaf = static fn (array $c): string => $c['field'] . ' ' . ConditionEvaluator::OPERATORS[$c['op']] . ($c['op'] === 'is_empty' ? '' : ' "' . $c['value'] . '"');
        $lines = [];
        foreach ($m['conditions'] as $c) {
            $lines[] = isset($c['conditions'])
                ? '(' . implode($c['mode'] === 'all' ? ' AND ' : ' OR ', array_map($leaf, $c['conditions'])) . ')'
                : $leaf($c);
        }
        if (count($lines) > 1) {
            $lines = [implode($m['mode'] === 'all' ? ' AND ' : ' OR ', $lines)];
        }

        return $lines;
    }

    /** One-line summary of what the action does, with ids shown as names. */
    public static function action(\mysqli $mysqli, array $rule): string
    {
        $cfg = json_decode((string) ($rule['action_config_json'] ?? ''), true);
        $cfg = is_array($cfg) ? $cfg : [];
        $label = ActionRegistry::labels()[$rule['action_type']] ?? (string) $rule['action_type'];
        $user = static function (int $id) use ($mysqli): string {
            $r = $id > 0 ? mysqli_fetch_row(mysqli_query($mysqli, 'SELECT user_name FROM users WHERE user_id = ' . $id)) : null;

            return $r ? (string) $r[0] : ($id > 0 ? "user #$id (gone)" : 'nobody');
        };
        switch ($rule['action_type']) {
            case 'set_ticket_field':
                $bits = [];
                foreach (['status' => 'status', 'priority' => 'priority'] as $k => $l) {
                    if (isset($cfg[$k])) {
                        $bits[] = "$l = " . $cfg[$k];
                    }
                }
                if (isset($cfg['category_id'])) {
                    $r = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT category_name FROM categories WHERE category_id = ' . (int) $cfg['category_id']));
                    $bits[] = 'category = ' . ($r[0] ?? '#' . (int) $cfg['category_id']);
                }
                if (isset($cfg['assignee'])) {
                    $bits[] = 'assignee = ' . $user((int) $cfg['assignee']);
                }

                return $label . ': ' . implode(', ', $bits);
            case 'assign_ticket':
                return $label . ': ' . (($cfg['mode'] ?? '') === 'round_robin' ? 'rotate through ' . implode(', ', array_map($user, array_map('intval', (array) ($cfg['pool'] ?? [])))) : $user((int) ($cfg['user_id'] ?? 0)));
            case 'send_mail':
                return $label . ': ' . ($cfg['subject'] ?? '') . ' (to ' . (\ITFlow\Automation\Actions\SendMailAction::TO[$cfg['to_type'] ?? ''] ?? '?') . ')';
            case 'add_ticket_note':
                return $label . ': ' . mb_substr((string) ($cfg['note'] ?? ''), 0, 80);
            case 'create_task':
                return $label . ': ' . ($cfg['name'] ?? '');
            case 'create_ticket':
                return $label . ': ' . mb_substr((string) ($cfg['subject'] ?? ''), 0, 80);
            case 'notify_user':
                return $label . ': ' . mb_substr((string) ($cfg['message'] ?? ''), 0, 80);
            case 'send_webhook':
                return $label . ': ' . (string) parse_url((string) ($cfg['url'] ?? ''), PHP_URL_HOST);
            case 'start_workflow':
                $r = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT name FROM workflow_templates WHERE workflow_template_id = ' . (int) ($cfg['template_id'] ?? 0)));

                return $label . ': ' . ($r[0] ?? 'template gone');
        }

        return $label;
    }
}
