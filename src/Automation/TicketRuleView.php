<?php

namespace ITFlow\Automation;

/**
 * Readable text for the separate ticket-only automation rules (ticket_automation_rules): a status, user or category is shown by NAME
 * instead of its id, and "escalate" (value "userID:priority") is shown as what it does. Display only; the rules themselves are untouched.
 * Returns plain text: callers escape it.
 */
final class TicketRuleView
{
    public const ACTIONS = [
        'set_priority' => 'Set priority', 'assign_to' => 'Assign to', 'escalate' => 'Escalate', 'set_status' => 'Set status',
        'add_note' => 'Add automation note', 'ai_triage' => 'AI triage (suggest)', 'notify_assignee' => 'Notify assigned tech',
        'close_ticket' => 'Close ticket', 'reopen_ticket' => 'Reopen ticket', 'add_worksheet' => 'Add worksheet from template',
        'run_script' => 'Run RMM script', 'create_ticket_from_alert' => 'Create ticket from alert', 'acknowledge_alert' => 'Acknowledge alert',
    ];
    public const TRIGGERS = [
        'schedule' => 'Scheduled check', 'ticket_created' => 'Ticket created', 'rmm_alert' => 'New RMM alert', 'asset_offline' => 'Asset offline',
        'asset_online' => 'Asset online', 'vacation_return' => 'Requester returns from vacation',
    ];
    public const FIELDS = [
        'age_hours' => 'Ticket age (hours)', 'priority' => 'Priority', 'status_id' => 'Status', 'assigned_to' => 'Assigned to', 'idle_hours' => 'Hours since last reply',
        'category' => 'Ticket category', 'subject' => 'Ticket subject', 'details' => 'Ticket body', 'sla_response_breached' => 'SLA response breached (1/0)',
        'sla_resolution_breached' => 'SLA resolution breached (1/0)', 'severity' => 'Alert severity', 'message' => 'Alert message', 'asset_id' => 'Asset',
        'client_id' => 'Department', 'integration_id' => 'RMM integration', 'hostname' => 'Asset hostname',
    ];
    public const OPS = ['equals' => '=', 'not_equals' => '!=', 'greater_than' => '>', 'less_than' => '<', 'contains' => 'contains'];

    private \mysqli $mysqli;
    /** @var array<string,array<int,string>> */
    private array $cache = [];

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /** @return array<int,string> */
    private function names(string $kind): array
    {
        if (!isset($this->cache[$kind])) {
            $sql = [
                'user' => 'SELECT user_id AS i, user_name AS n FROM users',
                'status' => 'SELECT ticket_status_id AS i, ticket_status_name AS n FROM ticket_statuses',
                'category' => "SELECT category_id AS i, category_name AS n FROM categories WHERE category_type = 'Ticket'",
                'client' => 'SELECT client_id AS i, client_name AS n FROM clients',
                'worksheet' => 'SELECT worksheet_template_id AS i, worksheet_template_name AS n FROM worksheet_templates',
                'script' => 'SELECT id AS i, name AS n FROM rmm_scripts',
                'asset' => 'SELECT asset_id AS i, asset_name AS n FROM assets',
            ][$kind];
            $this->cache[$kind] = [];
            $res = @mysqli_query($this->mysqli, $sql);
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $this->cache[$kind][(int) $r['i']] = (string) $r['n'];
            }
        }

        return $this->cache[$kind];
    }

    private function name(string $kind, $id): string
    {
        $id = (int) $id;

        return $this->names($kind)[$id] ?? "#$id (deleted)";
    }

    public function trigger(string $t): string
    {
        return self::TRIGGERS[$t ?: 'schedule'] ?? $t;
    }

    /** @param array{field?:string,op?:string,value?:mixed} $c */
    public function condition(array $c): string
    {
        $field = (string) ($c['field'] ?? '');
        $value = (string) ($c['value'] ?? '');
        $map = ['status_id' => 'status', 'assigned_to' => 'user', 'category' => 'category', 'client_id' => 'client', 'asset_id' => 'asset'];
        if (isset($map[$field]) && ctype_digit($value) && (int) $value > 0) {
            $value = $this->name($map[$field], $value);
        } elseif (isset($map[$field]) && $value === '0' && $field === 'assigned_to') {
            $value = 'nobody';
        }

        return (self::FIELDS[$field] ?? $field) . ' ' . (self::OPS[$c['op'] ?? ''] ?? (string) ($c['op'] ?? '')) . ' ' . $value;
    }

    /** @param array{action?:string,value?:mixed} $a */
    public function action(array $a): string
    {
        $act = (string) ($a['action'] ?? '');
        $v = (string) ($a['value'] ?? '');
        $label = self::ACTIONS[$act] ?? $act;
        switch ($act) {
            case 'assign_to':
                return $label . ' ' . ((int) $v > 0 ? $this->name('user', $v) : 'nobody');
            case 'set_status':
                return $label . ' ' . ((int) $v > 0 ? $this->name('status', $v) : $v);
            case 'escalate':
                $parts = explode(':', $v) + [0 => '', 1 => ''];
                $bits = [];
                if ((int) trim($parts[0]) > 0) {
                    $bits[] = 'reassign to ' . $this->name('user', trim($parts[0]));
                }
                if (trim($parts[1]) !== '') {
                    $bits[] = 'priority ' . ucfirst(strtolower(trim($parts[1])));
                }

                return $label . ($bits ? ': ' . implode(', ', $bits) : '');
            case 'set_priority':
                return $label . ' ' . ucfirst(strtolower($v));
            case 'add_worksheet':
                return $label . ((int) $v > 0 ? ': ' . $this->name('worksheet', $v) : '');
            case 'run_script':
                return $label . ((int) $v > 0 ? ': ' . $this->name('script', $v) : '');
        }

        return $label . ($v !== '' ? ': ' . mb_substr($v, 0, 80) : '');
    }

    /** Conditions of a ticket rule (new JSON column, falling back to the legacy single-condition columns). @return list<array<string,mixed>> */
    public static function conditionsOf(array $rule): array
    {
        if (!empty($rule['rule_conditions_json'])) {
            $d = json_decode($rule['rule_conditions_json'], true);
            if (is_array($d) && $d) {
                return $d;
            }
        }

        return !empty($rule['rule_cond_field']) ? [['field' => $rule['rule_cond_field'], 'op' => $rule['rule_cond_op'], 'value' => $rule['rule_cond_value']]] : [];
    }

    /** @return list<array<string,mixed>> */
    public static function actionsOf(array $rule): array
    {
        if (!empty($rule['rule_actions_json'])) {
            $d = json_decode($rule['rule_actions_json'], true);
            if (is_array($d) && $d) {
                return $d;
            }
        }

        return !empty($rule['rule_action']) ? [['action' => $rule['rule_action'], 'value' => $rule['rule_action_value']]] : [];
    }
}
