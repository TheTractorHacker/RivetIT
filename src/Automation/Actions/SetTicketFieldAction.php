<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;

/** Set any of: status (by NAME, ids differ per install), priority, category, assignee. Only fields that change are written. */
class SetTicketFieldAction extends AbstractAction
{
    public function key(): string
    {
        return 'set_ticket_field';
    }

    public function label(): string
    {
        return 'Set ticket fields';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        $cfg = [];
        $status = $this->text($input, 'status', 200, false, 'status');
        if ($status !== '') {
            if ($this->statusId($mysqli, $status) === null) {
                throw new \InvalidArgumentException("There is no active ticket status named \"$status\".");
            }
            $cfg['status'] = $status;
        }
        $priority = (string) ($input['priority'] ?? '');
        if ($priority !== '') {
            if (!in_array($priority, self::PRIORITIES, true)) {
                throw new \InvalidArgumentException('Priority must be Low, Medium or High.');
            }
            $cfg['priority'] = $priority;
        }
        $category = (int) ($input['category_id'] ?? 0);
        if ($category > 0) {
            $stmt = mysqli_prepare($mysqli, "SELECT 1 FROM categories WHERE category_id = ? AND category_type = 'Ticket' AND category_archived_at IS NULL");
            mysqli_stmt_bind_param($stmt, 'i', $category);
            mysqli_stmt_execute($stmt);
            $ok = (bool) mysqli_fetch_row(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$ok) {
                throw new \InvalidArgumentException('Choose an existing ticket category.');
            }
            $cfg['category_id'] = $category;
        }
        $assignee = (string) ($input['assignee'] ?? '');
        if ($assignee === 'none') {
            $cfg['assignee'] = 0;
        } elseif ($assignee !== '') {
            if ($this->agent($mysqli, (int) $assignee) === null) {
                throw new \InvalidArgumentException('The assignee must be an active technician.');
            }
            $cfg['assignee'] = (int) $assignee;
        }
        if (!$cfg) {
            throw new \InvalidArgumentException('Choose at least one ticket field to set.');
        }

        return $cfg;
    }

    private function statusId(\mysqli $mysqli, string $name): ?int
    {
        $stmt = mysqli_prepare($mysqli, 'SELECT ticket_status_id FROM ticket_statuses WHERE LOWER(ticket_status_name) = LOWER(?) AND ticket_status_active = 1 LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $name);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return $row ? (int) $row[0] : null;
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        $t = $this->ticket($mysqli, $context);
        $ref = $this->ticketRef($t);
        $sets = [];
        $changes = [];
        $statusChanged = $assignedChanged = false;
        $newStatus = null;

        if (isset($config['status'])) {
            $id = $this->statusId($mysqli, (string) $config['status']);
            if ($id === null) {
                throw new \RuntimeException('the status "' . $config['status'] . '" no longer exists or is inactive');
            }
            $id = $id === 4 ? 5 : $id; // Resolved is an alias for Closed everywhere in this app
            if ($id !== (int) $t['ticket_status']) {
                $newStatus = $id;
                $changes[] = 'status ' . ($t['ticket_status_name'] ?? '?') . ' -> ' . $config['status'];
                $statusChanged = true;
            }
        }
        if (isset($config['priority']) && $config['priority'] !== $t['ticket_priority']) {
            $changes[] = 'priority ' . ($t['ticket_priority'] ?? '-') . ' -> ' . $config['priority'];
            $sets[] = ['ticket_priority', 's', $config['priority']];
        }
        if (isset($config['category_id']) && (int) $config['category_id'] !== (int) $t['ticket_category']) {
            $changes[] = 'category -> #' . (int) $config['category_id'];
            $sets[] = ['ticket_category', 'i', (int) $config['category_id']];
        }
        if (isset($config['assignee']) && (int) $config['assignee'] !== (int) $t['ticket_assigned_to']) {
            $who = 'nobody';
            if ((int) $config['assignee'] > 0) {
                $a = $this->agent($mysqli, (int) $config['assignee']);
                if ($a === null) {
                    throw new \RuntimeException('the chosen assignee is no longer an active technician');
                }
                $who = $a['user_name'];
            }
            $changes[] = 'assignee -> ' . $who;
            $sets[] = ['ticket_assigned_to', 'i', (int) $config['assignee']];
            $assignedChanged = true;
        }
        if (!$changes) {
            return "ticket $ref already matches; nothing to change";
        }
        if ($dry) {
            return "WOULD update ticket $ref: " . implode(', ', $changes);
        }

        foreach ($sets as [$col, $type, $val]) {
            $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET $col = ? WHERE ticket_id = ?");
            $tid = (int) $t['ticket_id'];
            mysqli_stmt_bind_param($stmt, $type . 'i', $val, $tid);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $tid = (int) $t['ticket_id'];
        if ($newStatus !== null) {
            mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $newStatus WHERE ticket_id = $tid");
            if ($newStatus === 5) {
                mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = 0 WHERE ticket_id = $tid AND ticket_resolved_at IS NULL");
                if (function_exists('slaStampResponseIfMissing')) {
                    slaStampResponseIfMissing($mysqli, $tid);
                }
            } else {
                $reopen = function_exists('ticketReopenSql') ? ticketReopenSql() : '';
                mysqli_query($mysqli, "UPDATE tickets SET {$reopen}ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $tid");
            }
            if (function_exists('slaAccruePause')) {
                slaAccruePause($mysqli, $tid, (int) $t['ticket_status'], $newStatus);
            }
        }
        $summary = 'updated ticket ' . $ref . ': ' . implode(', ', $changes);
        $this->internalNote($mysqli, $tid, htmlspecialchars("Automation rule \"{$rule['name']}\": " . implode('; ', $changes), ENT_QUOTES, 'UTF-8'));
        $this->record($gateway, $rule, 'ticket_fields_set', $t, $summary);
        $payload = $this->ticketPayload($tid);
        if ($statusChanged) {
            $gateway->emitEvent('ticket.status_changed', $payload);
        }
        if ($assignedChanged) {
            $gateway->emitEvent('ticket.assigned', $payload);
        }
        $gateway->emitEvent('ticket.updated', $payload + ['changed_by' => 'automation']);

        return $summary;
    }
}
