<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;

/** Assign to one technician, or rotate through a list of technicians (round robin, the next one on each run). */
class AssignTicketAction extends AbstractAction
{
    public function key(): string
    {
        return 'assign_ticket';
    }

    public function label(): string
    {
        return 'Assign the ticket';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        $mode = (string) ($input['mode'] ?? 'user');
        if ($mode === 'user') {
            $uid = (int) ($input['user_id'] ?? 0);
            if ($this->agent($mysqli, $uid) === null) {
                throw new \InvalidArgumentException('Choose an active technician to assign the ticket to.');
            }

            return ['mode' => 'user', 'user_id' => $uid];
        }
        if ($mode !== 'round_robin') {
            throw new \InvalidArgumentException('Choose who to assign the ticket to.');
        }
        $pool = [];
        foreach ((array) ($input['pool'] ?? []) as $id) {
            $id = (int) $id;
            if ($this->agent($mysqli, $id) === null) {
                throw new \InvalidArgumentException('Everyone in the rotation must be an active technician.');
            }
            $pool[$id] = $id;
        }
        if (count($pool) < 2 || count($pool) > 50) {
            throw new \InvalidArgumentException('Choose between 2 and 50 technicians for a rotation.');
        }

        return ['mode' => 'round_robin', 'pool' => array_values($pool)];
    }

    /** The agent who is next in the rotation (skipping anyone no longer active), without advancing it. @return array{0:array|null,1:int} agent and the cursor value used */
    private function nextInRotation(\mysqli $mysqli, array $pool, int $cursor): array
    {
        $n = count($pool);
        for ($i = 0; $i < $n; $i++) {
            $a = $this->agent($mysqli, (int) $pool[($cursor + $i) % $n]);
            if ($a !== null) {
                return [$a, $cursor + $i];
            }
        }

        return [null, $cursor];
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        $t = $this->ticket($mysqli, $context);
        $ref = $this->ticketRef($t);
        if ($t['ticket_closed_at'] !== null) {
            return "skipped: ticket $ref is closed";
        }
        $ruleId = (int) ($rule['rule_id'] ?? 0);
        $used = 0;
        if (($config['mode'] ?? 'user') === 'round_robin') {
            $cursor = 0;
            if ($ruleId > 0) {
                $row = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT rr_cursor FROM automation_rules WHERE rule_id = ' . $ruleId));
                $cursor = (int) ($row[0] ?? 0);
            }
            [$agent, $used] = $this->nextInRotation($mysqli, (array) $config['pool'], $cursor);
        } else {
            $agent = $this->agent($mysqli, (int) ($config['user_id'] ?? 0));
        }
        if ($agent === null) {
            throw new \RuntimeException('nobody in the assignment list is an active technician');
        }
        if ((int) $t['ticket_assigned_to'] === $agent['user_id']) {
            return "ticket $ref is already assigned to " . $agent['user_name'];
        }
        if ($dry) {
            return "WOULD assign ticket $ref to " . $agent['user_name'] . (($config['mode'] ?? '') === 'round_robin' ? ' (next in the rotation; the rotation is not advanced by a test)' : '');
        }
        $tid = (int) $t['ticket_id'];
        $uid = $agent['user_id'];
        $statusSql = '';
        if (strcasecmp((string) $t['ticket_status_name'], 'New') === 0) {
            $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_active = 1 AND ticket_status_name IN ('Assigned','Open') ORDER BY ticket_status_name = 'Assigned' DESC LIMIT 1"));
            if ($row) {
                $statusSql = ', ticket_status = ' . (int) $row[0];
            }
        }
        mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = $uid$statusSql WHERE ticket_id = $tid");
        if (($config['mode'] ?? '') === 'round_robin' && $ruleId > 0) {
            // The rotation moves past whoever was just chosen (a best-effort cursor: two truly simultaneous runs could pick the same person).
            mysqli_query($mysqli, 'UPDATE automation_rules SET rr_cursor = ' . ($used + 1) . ' WHERE rule_id = ' . $ruleId);
        }
        $this->internalNote($mysqli, $tid, htmlspecialchars("Automation rule \"{$rule['name']}\" assigned this ticket to " . $agent['user_name'] . '.', ENT_QUOTES, 'UTF-8'));
        $this->record($gateway, $rule, 'ticket_assigned', $t, "assigned ticket $ref to " . $agent['user_name']);
        $gateway->notifyUser($uid, 'Ticket', "Ticket $ref - " . mb_substr((string) $t['ticket_subject'], 0, 100) . ' has been assigned to you by an automation rule', '/agent/ticket.php?ticket_id=' . $tid, (int) $t['ticket_client_id'], $tid);
        $payload = $this->ticketPayload($tid);
        $gateway->emitEvent('ticket.assigned', $payload);
        $gateway->emitEvent('ticket.updated', $payload + ['changed_by' => 'automation']);

        return "assigned ticket $ref to " . $agent['user_name'];
    }
}
