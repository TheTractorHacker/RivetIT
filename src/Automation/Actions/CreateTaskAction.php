<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Automation\Template;
use ITFlow\Workflow\ActionGateway;

/** Adds a task (checklist item) to the ticket the event is about, optionally assigned and due in N days. */
class CreateTaskAction extends AbstractAction
{
    public function key(): string
    {
        return 'create_task';
    }

    public function label(): string
    {
        return 'Add a task to the ticket';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        $cfg = ['name' => $this->text($input, 'name', 255, true, 'task name'), 'assignee_id' => 0, 'due_days' => 0];
        $uid = (int) ($input['assignee_id'] ?? 0);
        if ($uid > 0) {
            if ($this->agent($mysqli, $uid) === null) {
                throw new \InvalidArgumentException('The task can only be assigned to an active technician.');
            }
            $cfg['assignee_id'] = $uid;
        }
        $days = (int) ($input['due_days'] ?? 0);
        if ($days < 0 || $days > 365) {
            throw new \InvalidArgumentException('The due date must be 0 to 365 days away.');
        }
        $cfg['due_days'] = $days;

        return $cfg;
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        $t = $this->ticket($mysqli, $context);
        $name = mb_substr(Template::render((string) $config['name'], $context, 'text'), 0, 255);
        if ($name === '') {
            throw new \RuntimeException('the task name is empty after filling in the event values');
        }
        $uid = (int) ($config['assignee_id'] ?? 0);
        $days = (int) ($config['due_days'] ?? 0);
        if ($uid > 0 && $this->agent($mysqli, $uid) === null) {
            throw new \RuntimeException('the task assignee is no longer an active technician');
        }
        if ($dry) {
            return 'WOULD add the task "' . $name . '" to ticket ' . $this->ticketRef($t) . ($uid ? " assigned to user #$uid" : '') . ($days ? " due in $days day(s)" : '');
        }
        $tid = (int) $t['ticket_id'];
        $assigned = $uid > 0 ? $uid : null;
        $stmt = mysqli_prepare($mysqli, 'INSERT INTO tasks SET task_name = ?, task_ticket_id = ?, task_assigned_to = ?, task_due = ' . ($days > 0 ? 'DATE_ADD(CURDATE(), INTERVAL ' . $days . ' DAY)' : 'NULL'));
        mysqli_stmt_bind_param($stmt, 'sii', $name, $tid, $assigned);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $this->record($gateway, $rule, 'task_created', $t, 'added task "' . $name . '" to ticket ' . $this->ticketRef($t));

        return 'added the task "' . $name . '" to ticket ' . $this->ticketRef($t);
    }
}
