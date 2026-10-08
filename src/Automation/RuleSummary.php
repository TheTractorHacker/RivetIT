<?php

namespace ITFlow\Automation;

/**
 * One plain-English sentence for an event rule, for the Event rules list and the editor's live summary panel:
 *
 *   When a ticket is created, and priority is High or Critical, then notify all technicians.
 *
 * Pure (no database, no output): ids are shown as names only when the caller passes the lookups. Returns text; callers escape it.
 *
 * $lookups (all optional): 'events' => [eventId => label], 'agents' => [userId => name], 'clients' => [id => name],
 * 'categories' => [id => name], 'templates' => [id => name].
 */
final class RuleSummary
{
    /** Hand-written trigger phrases (after "When ..."). Anything else is built from the catalog label, see triggerPhrase(). */
    private const TRIGGERS = [
        'ticket.created' => 'a ticket is created',
        'ticket.updated' => 'a ticket is updated',
        'ticket.replied' => 'a ticket gets a reply',
        'ticket.assigned' => 'a ticket is assigned',
        'ticket.status_changed' => "a ticket's status changes",
        'ticket.resolved' => 'a ticket is resolved',
        'ticket.escalated' => 'a ticket is escalated',
        'ticket.sla_warning' => "a ticket's SLA is at risk",
        'ticket.sla_breached' => 'a ticket breaches its SLA',
        'sla.warning' => "a ticket's SLA is at risk",
        'sla.breached' => 'a ticket breaches its SLA',
        'auth.login_failed' => 'a sign-in fails',
        'auth.login_success' => 'someone signs in',
        'auth.login_blocked' => 'a sign-in is blocked',
        'auth.mfa_failed' => 'a multi-factor code is wrong',
        'vault.credential_revealed' => 'a stored credential is revealed',
        'employee.hired' => 'an employee is hired',
        'employee.terminated' => 'an employee is terminated',
        'workflow.action_failed' => 'a workflow action fails',
        'workflow.completed' => 'a workflow is completed',
        'catalog.request_approved' => 'a catalog request is approved',
        'catalog.request_rejected' => 'a catalog request is rejected',
        'backup.failed' => 'a backup fails',
        'backup.completed' => 'a backup completes',
    ];

    private const PARTICIPLES = ['created', 'updated', 'deleted', 'changed', 'resolved', 'closed', 'assigned', 'approved', 'rejected', 'started', 'completed',
        'cancelled', 'archived', 'restored', 'exported', 'published', 'linked', 'unlinked', 'paid', 'saved', 'added', 'removed', 'toggled', 'retried',
        'retired', 'hired', 'terminated', 'requested', 'decided', 'issued', 'revoked', 'enrolled', 'unlocked', 'executed', 'failed', 'recorded', 'received',
        'blocked', 'refused', 'reissued', 'purged', 'tested'];

    /** Friendlier names for event fields that read badly when only underscores are removed. */
    private const FIELDS = [
        'assigned_to_user_id' => 'assigned technician',
        'assigned_to_user_name' => 'assigned technician name',
        'client_id' => 'client',
        'contact_id' => 'contact',
        'ticket_id' => 'ticket id',
        'actor_user_id' => 'acting user',
        'sla_percent_used' => 'SLA used (%)',
        'sla_remaining_seconds' => 'SLA seconds remaining',
    ];

    /**
     * Rule row (or form-shaped array) to a sentence. Reads trigger_event, condition_json (or a decoded 'condition' model),
     * action_type and action_config_json (or a decoded 'action_config').
     * @param array<string,mixed> $rule
     * @param array<string,array<int|string,string>> $lookups
     */
    public static function describe(array $rule, array $lookups = []): string
    {
        $event = trim((string) ($rule['trigger_event'] ?? ''));
        $out = 'When ' . ($event === '' ? '(choose an event)' : self::triggerPhrase($event, $lookups));
        $model = ConditionEvaluator::normalize($rule['condition'] ?? $rule['condition_json'] ?? null);
        if ($model === null) {
            $out .= ', and the conditions are not valid (the rule never fires)';
        } else {
            $out .= self::conditionClause($model, $lookups);
        }
        $cfg = $rule['action_config'] ?? json_decode((string) ($rule['action_config_json'] ?? ''), true);

        return $out . ', then ' . self::actionPhrase((string) ($rule['action_type'] ?? ''), is_array($cfg) ? $cfg : [], $lookups) . '.';
    }

    /** "a ticket is created" for an event id. */
    public static function triggerPhrase(string $event, array $lookups = []): string
    {
        if (isset(self::TRIGGERS[$event])) {
            return self::TRIGGERS[$event];
        }
        $label = self::eventLabel($event, $lookups);
        if ($label === $event) {
            return 'the event "' . $event . '" happens';
        }
        $words = explode(' ', mb_strtolower(trim($label)));
        $last = array_pop($words);
        if ($words && in_array($last, self::PARTICIPLES, true)) {
            $noun = implode(' ', $words);

            return (preg_match('/^[aeiou]/', $noun) ? 'an ' : 'a ') . $noun . ' is ' . $last;
        }

        return 'the event "' . $label . '" happens';
    }

    /** The catalog label of an event, or its id when unknown. */
    public static function eventLabel(string $event, array $lookups = []): string
    {
        $label = $lookups['events'][$event] ?? null;
        if ($label === null && class_exists(\RivetCore\Webhooks\EventCatalog::class)) {
            $label = \RivetCore\Webhooks\EventCatalog::get($event)?->label;
        }

        return $label === null || trim((string) $label) === '' ? $event : (string) $label;
    }

    /** ", and priority is High, and ..." (empty string when there are no conditions). @param array{mode:string,conditions:array<int,array<string,mixed>>} $model */
    public static function conditionClause(array $model, array $lookups = []): string
    {
        $items = $model['conditions'];
        if ($items === []) {
            return '';
        }
        $parts = array_map(fn (array $c): string => isset($c['conditions']) ? self::group($c, $lookups) : self::leaf($c, $lookups), $items);
        if (count($parts) === 1 || $model['mode'] === 'all') {
            return implode('', array_map(static fn (string $p): string => ', and ' . $p, $parts));
        }

        return ', and any of these: ' . implode('; ', $parts);
    }

    private static function group(array $g, array $lookups): string
    {
        $parts = array_map(fn (array $c): string => self::leaf($c, $lookups), $g['conditions']);

        return count($parts) === 1 ? $parts[0] : '(' . implode($g['mode'] === 'all' ? ' and ' : ' or ', $parts) . ')';
    }

    /** "priority is High or Critical". @param array{field:string,op:string,value:string} $c */
    public static function leaf(array $c, array $lookups = []): string
    {
        $field = self::fieldLabel($c['field']);
        $show = fn (string $v): string => self::valueLabel($c['field'], $v, $lookups);
        switch ($c['op']) {
            case 'eq':
                return $field . ' is ' . $show($c['value']);
            case 'ne':
                return $field . ' is not ' . $show($c['value']);
            case 'in':
                $items = array_values(array_filter(array_map('trim', explode(',', $c['value'])), static fn ($s) => $s !== ''));
                $items = array_map($show, $items);
                if (count($items) > 1) {
                    $last = array_pop($items);

                    return $field . ' is ' . implode(', ', $items) . ' or ' . $last;
                }

                return $field . ' is ' . ($items[0] ?? '(nothing chosen)');
            case 'contains':
                return $field . ' contains "' . self::clip($c['value']) . '"';
            case 'gt':
                return $field . ' is greater than ' . $c['value'];
            case 'lt':
                return $field . ' is less than ' . $c['value'];
            case 'is_empty':
                return $field . ' is empty';
        }

        return $field . ' ' . $c['op'] . ' ' . $c['value'];
    }

    public static function fieldLabel(string $field): string
    {
        if (isset(self::FIELDS[$field])) {
            return self::FIELDS[$field];
        }
        $f = str_replace(['_', '.'], ' ', $field);

        return preg_replace('/^ticket /', '', $f) ?: $f;
    }

    private static function valueLabel(string $field, string $value, array $lookups): string
    {
        $map = match ($field) {
            'assigned_to_user_id', 'actor_user_id' => $lookups['agents'] ?? [],
            'client_id' => $lookups['clients'] ?? [],
            default => [],
        };
        if (isset($map[$value])) {
            return (string) $map[$value];
        }
        if ($value === '0' && in_array($field, ['assigned_to_user_id', 'client_id', 'contact_id'], true)) {
            return $field === 'assigned_to_user_id' ? 'nobody' : 'none';
        }

        return self::clip($value);
    }

    private static function clip(string $s, int $max = 60): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '...' : $s;
    }

    /** "notify all technicians", "assign the ticket to Dana Smith". @param array<string,mixed> $cfg */
    public static function actionPhrase(string $type, array $cfg, array $lookups = []): string
    {
        $agent = static function ($id) use ($lookups): string {
            $id = (int) $id;

            return $id > 0 ? (string) ($lookups['agents'][$id] ?? "technician #$id") : 'nobody';
        };
        switch ($type) {
            case 'notify_user':
                return 'notify all technicians' . (($m = trim((string) ($cfg['message'] ?? ''))) !== '' ? ': "' . self::clip($m, 50) . '"' : '');
            case 'create_ticket':
                return 'create a ' . ($cfg['priority'] ?? 'Low') . ' priority ticket' . (($s = trim((string) ($cfg['subject'] ?? ''))) !== '' ? ' "' . self::clip($s, 50) . '"' : '');
            case 'send_webhook':
                $host = (string) parse_url((string) ($cfg['url'] ?? ''), PHP_URL_HOST);

                return 'send a webhook' . ($host !== '' ? ' to ' . $host : '');
            case 'start_workflow':
                $id = (int) ($cfg['template_id'] ?? 0);

                return $id > 0 ? 'start the workflow "' . ($lookups['templates'][$id] ?? "#$id") . '"' : 'start a workflow';
            case 'set_ticket_field':
                $bits = [];
                if (isset($cfg['status'])) {
                    $bits[] = 'status to ' . $cfg['status'];
                }
                if (isset($cfg['priority'])) {
                    $bits[] = 'priority to ' . $cfg['priority'];
                }
                if (isset($cfg['category_id'])) {
                    $bits[] = 'category to ' . ($lookups['categories'][(int) $cfg['category_id']] ?? '#' . (int) $cfg['category_id']);
                }
                if (array_key_exists('assignee', $cfg)) {
                    $bits[] = 'assignee to ' . $agent($cfg['assignee']);
                }

                return $bits ? "set the ticket's " . implode(', ', $bits) : 'set ticket fields';
            case 'add_ticket_note':
                return 'add an internal note to the ticket';
            case 'assign_ticket':
                if (($cfg['mode'] ?? '') === 'round_robin') {
                    return 'assign the ticket in turn to ' . implode(', ', array_map($agent, (array) ($cfg['pool'] ?? [])));
                }

                return 'assign the ticket to ' . $agent($cfg['user_id'] ?? 0);
            case 'send_mail':
                $who = match ((string) ($cfg['to_type'] ?? '')) {
                    'address' => (string) ($cfg['address'] ?? 'an address'),
                    'user' => $agent($cfg['user_id'] ?? 0),
                    'assignee' => 'the assigned technician',
                    'requester' => "the ticket's contact",
                    default => 'someone',
                };

                return 'email ' . $who . (($s = trim((string) ($cfg['subject'] ?? ''))) !== '' ? ': "' . self::clip($s, 50) . '"' : '');
            case 'create_task':
                $due = (int) ($cfg['due_days'] ?? 0);

                return 'add the task "' . self::clip((string) ($cfg['name'] ?? ''), 50) . '" to the ticket' . ((int) ($cfg['assignee_id'] ?? 0) > 0 ? ' for ' . $agent($cfg['assignee_id']) : '') . ($due > 0 ? " (due in $due day" . ($due === 1 ? '' : 's') . ')' : '');
        }

        return $type === '' ? '(choose what to do)' : 'run "' . $type . '"';
    }
}
