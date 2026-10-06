<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;

/** Shared helpers: input cleaning, finding the ticket an event is about, vetted assignees. */
abstract class AbstractAction implements ActionInterface
{
    public const PRIORITIES = ['Low', 'Medium', 'High'];

    protected function text(array $input, string $key, int $max, bool $required, string $label): string
    {
        $v = mb_substr(trim((string) ($input[$key] ?? '')), 0, $max);
        if ($required && $v === '') {
            throw new \InvalidArgumentException("Enter the $label.");
        }

        return $v;
    }

    /** The ticket the event is about: its ticket_id (ticket events) or an audit event whose entity is a ticket. 0 when it is not about a ticket. */
    protected function ticketId(array $ctx): int
    {
        foreach (['ticket_id', 'tid'] as $k) {
            if (isset($ctx[$k]) && ctype_digit((string) $ctx[$k]) && (int) $ctx[$k] > 0) {
                return (int) $ctx[$k];
            }
        }
        if (($ctx['entity_type'] ?? '') === 'ticket' && ctype_digit((string) ($ctx['entity_id'] ?? '')) && (int) $ctx['entity_id'] > 0) {
            return (int) $ctx['entity_id'];
        }

        return 0;
    }

    /** @return array<string,mixed> @throws \RuntimeException */
    protected function ticket(\mysqli $mysqli, array $ctx): array
    {
        $id = $this->ticketId($ctx);
        if ($id <= 0) {
            throw new \RuntimeException('the event is not about a ticket');
        }
        $stmt = mysqli_prepare($mysqli, 'SELECT t.*, ts.ticket_status_name FROM tickets t LEFT JOIN ticket_statuses ts ON ts.ticket_status_id = t.ticket_status WHERE t.ticket_id = ? AND t.ticket_archived_at IS NULL');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) {
            throw new \RuntimeException("ticket #$id no longer exists");
        }

        return $row;
    }

    /** An active agent (never a portal login or archived user), or null. @return array{user_id:int,user_name:string,user_email:string}|null */
    protected function agent(\mysqli $mysqli, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = mysqli_prepare($mysqli, 'SELECT user_id, user_name, user_email FROM users WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL');
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return $row ? ['user_id' => (int) $row['user_id'], 'user_name' => (string) $row['user_name'], 'user_email' => (string) $row['user_email']] : null;
    }

    protected function ticketRef(array $t): string
    {
        return $t['ticket_prefix'] . $t['ticket_number'];
    }

    /** The webhook-style payload of a ticket for events an action emits. @return array<string,mixed> */
    protected function ticketPayload(int $ticketId): array
    {
        return function_exists('getWebhookTicketPayload') ? (array) getWebhookTicketPayload($ticketId) : ['ticket_id' => $ticketId];
    }

    protected function internalNote(\mysqli $mysqli, int $ticketId, string $html): void
    {
        $stmt = mysqli_prepare($mysqli, "INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = 0, ticket_reply_ticket_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $html, $ticketId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    protected function record(ActionGateway $gateway, array $rule, string $what, array $ticket, string $summary): void
    {
        if (function_exists('logAction')) {
            logAction('Automation', 'Update', "Rule '" . $rule['name'] . "': $summary", (int) $ticket['ticket_client_id'], (int) $ticket['ticket_id']);
        }
        $gateway->audit('automation.' . $what, null, 'ticket', (int) $ticket['ticket_id'], 'update', mb_substr("Rule '" . $rule['name'] . "': $summary", 0, 480), ['rule_id' => (int) ($rule['rule_id'] ?? 0)]);
    }
}
