<?php

namespace ITFlow\Automation\Actions;

/** Every action an event rule can take. Add a new one here (and to the automation_rules.action_type enum in a migration). */
final class ActionRegistry
{
    /** @return array<string,ActionInterface> key => action */
    public static function all(): array
    {
        static $all = null;
        if ($all === null) {
            $all = [];
            foreach ([new CreateTicketAction(), new SendWebhookAction(), new NotifyUserAction(), new StartWorkflowAction(),
                new SetTicketFieldAction(), new AddTicketNoteAction(), new AssignTicketAction(), new SendMailAction(), new CreateTaskAction()] as $a) {
                $all[$a->key()] = $a;
            }
        }

        return $all;
    }

    public static function get(string $key): ?ActionInterface
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string,string> key => label */
    public static function labels(): array
    {
        return array_map(static fn (ActionInterface $a) => $a->label(), self::all());
    }

    /** Actions that need a ticket to act on (shown with a hint when the chosen event is not a ticket event). */
    public const TICKET_ACTIONS = ['set_ticket_field', 'add_ticket_note', 'assign_ticket', 'create_task'];
}
