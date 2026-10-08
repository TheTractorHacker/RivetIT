<?php

namespace ITFlow\Automation;

use ITFlow\Automation\Actions\ActionRegistry;

/**
 * "Start from a recipe": ready-made rules that PREFILL the editor (nothing is saved until the administrator saves).
 * A recipe is offered only when its event is one this edition really emits and its action exists; see available().
 * Field names in 'cfg' are the editor's form names (RuleForm::actionInput()). 'status' recipes name the ticket status they need and are
 * offered only when this installation has one of the candidate names (status ids and names differ per installation).
 */
final class RuleRecipes
{
    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return [
            ['key' => 'critical-ticket', 'title' => 'Tell me when a High or Critical ticket arrives', 'blurb' => 'Notify the technicians the moment an urgent ticket is created.', 'icon' => 'fa-bell',
                'name' => 'Urgent ticket created', 'event' => 'ticket.created', 'rows' => [['ticket_priority', 'in', 'High,Critical']], 'action' => 'notify_user',
                'cfg' => ['cfg_message' => 'Urgent ticket {ticket_number}: {ticket_subject} ({client_name})']],
            ['key' => 'sla-breach-chat', 'title' => 'Post to Slack or Teams when an SLA is breached', 'blurb' => 'Send the event to a chat webhook URL (paste your incoming-webhook address).', 'icon' => 'fa-paper-plane',
                'name' => 'SLA breached: post to chat', 'event' => 'ticket.sla_breached', 'rows' => [], 'action' => 'send_webhook', 'cfg' => ['cfg_url' => '']],
            ['key' => 'offboarding', 'title' => 'Start the offboarding workflow when an employee is terminated', 'blurb' => 'Runs the offboarding checklist you choose, once per person.', 'icon' => 'fa-sitemap',
                'name' => 'Employee terminated: start offboarding', 'event' => 'employee.terminated', 'rows' => [], 'action' => 'start_workflow', 'cfg' => ['cfg_template_id' => 0]],
            ['key' => 'escalate-on-hold-reply', 'title' => 'Raise the priority when someone replies to a ticket on hold', 'blurb' => 'A reply on a waiting ticket usually means the wait is over: make it High.', 'icon' => 'fa-level-up-alt',
                'name' => 'Reply on a held ticket: raise priority', 'event' => 'ticket.replied', 'status_candidates' => ['On Hold', 'Pending', 'Waiting', 'Waiting on customer'],
                'rows' => [['ticket_status', 'eq', '{status}']], 'action' => 'set_ticket_field', 'cfg' => ['cfg_sf_priority' => 'High']],
            ['key' => 'credential-revealed', 'title' => 'Notify when a stored credential is revealed', 'blurb' => 'Every time someone views a password in the vault, the technicians get a notification.', 'icon' => 'fa-key',
                'name' => 'Credential revealed', 'event' => 'vault.credential_revealed', 'rows' => [], 'action' => 'notify_user', 'cfg' => ['cfg_message' => 'A stored credential was revealed: {summary}']],
            ['key' => 'failed-signin', 'title' => 'Alert when a sign-in fails', 'blurb' => 'Notify on every failed sign-in, at most 5 alerts a minute so an attack cannot flood you.', 'icon' => 'fa-user-lock',
                'name' => 'Failed sign-in alert', 'event' => 'auth.login_failed', 'rows' => [], 'action' => 'notify_user', 'cfg' => ['cfg_message' => 'Failed sign-in: {summary}'], 'rate' => 5],
            ['key' => 'note-on-resolved', 'title' => 'Add a note when a ticket is resolved', 'blurb' => 'Leave an internal note on the ticket recording that it was resolved.', 'icon' => 'fa-sticky-note',
                'name' => 'Ticket resolved: add a note', 'event' => 'ticket.status_changed', 'status_candidates' => ['Resolved'], 'rows' => [['ticket_status', 'eq', '{status}']], 'action' => 'add_ticket_note',
                'cfg' => ['cfg_note' => 'The ticket moved to {ticket_status}. Please check the customer is happy before closing.']],
            ['key' => 'email-on-assign', 'title' => 'Email the technician when a ticket is assigned to them', 'blurb' => 'The assigned technician gets an email with the ticket number and subject.', 'icon' => 'fa-envelope',
                'name' => 'Ticket assigned: email the technician', 'event' => 'ticket.assigned', 'rows' => [], 'action' => 'send_mail',
                'cfg' => ['cfg_mail_to' => 'assignee', 'cfg_mail_subject' => 'Assigned to you: {ticket_number}', 'cfg_mail_body' => '{ticket_number} "{ticket_subject}" for {client_name} was assigned to you.']],
            ['key' => 'round-robin', 'title' => 'Hand new tickets to the team in turn', 'blurb' => 'Round-robin assignment: pick two or more technicians in the Then step.', 'icon' => 'fa-random',
                'name' => 'New ticket: assign in rotation', 'event' => 'ticket.created', 'rows' => [['assigned_to_user_id', 'eq', '0']], 'action' => 'assign_ticket', 'cfg' => ['cfg_as_mode' => 'round_robin']],
            ['key' => 'sla-warning-task', 'title' => 'Add a follow-up task when an SLA is at risk', 'blurb' => 'A checklist task on the ticket, due the next day.', 'icon' => 'fa-hourglass-half',
                'name' => 'SLA at risk: add a task', 'event' => 'ticket.sla_warning', 'rows' => [], 'action' => 'create_task', 'cfg' => ['cfg_task_name' => 'Check SLA on {ticket_number}', 'cfg_task_due_days' => 1]],
            ['key' => 'workflow-failed-ticket', 'title' => 'Open a ticket when an automated workflow step fails', 'blurb' => 'So a failed offboarding or onboarding action never goes unnoticed.', 'icon' => 'fa-exclamation-triangle',
                'name' => 'Workflow action failed: open a ticket', 'event' => 'workflow.action_failed', 'rows' => [], 'action' => 'create_ticket',
                'cfg' => ['cfg_subject' => 'Workflow step failed: {summary}', 'cfg_details' => 'Event {event}: {summary}', 'cfg_priority' => 'High']],
        ];
    }

    /**
     * The recipes this installation can use, each with its status placeholder resolved.
     * @param list<string> $eventIds ids of the events the picker offers @param list<string> $statuses active ticket status names
     * @return list<array<string,mixed>>
     */
    public static function available(array $eventIds, array $statuses): array
    {
        $out = [];
        foreach (self::all() as $r) {
            if (!in_array($r['event'], $eventIds, true) || ActionRegistry::get($r['action']) === null) {
                continue;
            }
            if (isset($r['status_candidates'])) {
                $hit = null;
                foreach ($r['status_candidates'] as $c) {
                    if (in_array($c, $statuses, true)) {
                        $hit = $c;
                        break;
                    }
                }
                if ($hit === null) {
                    continue;
                }
                foreach ($r['rows'] as &$row) {
                    $row[2] = str_replace('{status}', $hit, $row[2]);
                }
                unset($row);
            }
            $out[] = $r;
        }

        return $out;
    }

    /** A recipe as editor state (the same shape the editor builds from a saved rule). @param array<string,mixed> $r @return array<string,mixed> */
    public static function toState(array $r): array
    {
        $rows = [];
        foreach ($r['rows'] as $row) {
            $rows[] = ['field' => $row[0], 'op' => $row[1], 'value' => $row[2], 'group' => ''];
        }

        return ['rule_id' => 0, 'name' => $r['name'], 'trigger' => $r['event'], 'mode' => 'all', 'rows' => $rows, 'groupModes' => [], 'action' => $r['action'], 'form' => $r['cfg'],
            'enabled' => true, 'priority' => 100, 'stop' => false, 'rate' => (int) ($r['rate'] ?? RuleEngine::DEFAULT_RATE), 'recipe' => $r['key']];
    }
}
