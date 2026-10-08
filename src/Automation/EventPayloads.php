<?php

namespace ITFlow\Automation;

/** The event payloads shared by emit points, the Test rule page and tests (one place, so they cannot drift apart). */
final class EventPayloads
{
    /** The webhook/event payload of a ticket (what getWebhookTicketPayload() returns). @return array<string,mixed> */
    public static function ticket(\mysqli $mysqli, int $ticketId): array
    {
        $stmt = mysqli_prepare($mysqli,
            'SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_priority, t.ticket_client_id, t.ticket_assigned_to, t.ticket_contact_id,
                    ts.ticket_status_name, c.client_name, co.contact_name, u.user_name AS assigned_user_name
             FROM tickets t
             LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
             LEFT JOIN clients c ON t.ticket_client_id = c.client_id
             LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
             LEFT JOIN users u ON t.ticket_assigned_to = u.user_id
             WHERE t.ticket_id = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'i', $ticketId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) {
            return ['ticket_id' => $ticketId];
        }

        return [
            'ticket_id' => (int) $row['ticket_id'],
            'ticket_number' => $row['ticket_prefix'] . $row['ticket_number'],
            'ticket_subject' => $row['ticket_subject'],
            'ticket_priority' => $row['ticket_priority'],
            'ticket_status' => $row['ticket_status_name'],
            'client_id' => (int) $row['ticket_client_id'],
            'client_name' => $row['client_name'],
            'contact_id' => (int) $row['ticket_contact_id'],
            'contact_name' => $row['contact_name'],
            'assigned_to_user_id' => (int) $row['ticket_assigned_to'],
            'assigned_to_user_name' => $row['assigned_user_name'],
        ];
    }

    /** What AuditService hands the event bus for an audit row. @return array<string,mixed> */
    public static function fromAuditRow(array $row): array
    {
        $meta = json_decode((string) ($row['metadata_json'] ?? ''), true);

        return ['actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null, 'entity_type' => $row['entity_type'], 'entity_id' => $row['entity_id'], 'action' => $row['action'], 'summary' => $row['summary'], 'metadata' => is_array($meta) ? $meta : []];
    }

    /** The context the engine matches against (same flattening as the live event bus). @return array<string,string> */
    public static function context(string $event, array $data): array
    {
        return \RivetCore\Automation\EventContext::flatten($data + ['event' => $event]);
    }

    /** A plausible payload for an event type, for the Test rule page. Ticket events use the newest real ticket (read only). @return array<string,mixed> */
    public static function sample(\mysqli $mysqli, string $event): array
    {
        if (str_starts_with($event, 'ticket.') || str_starts_with($event, 'catalog.request_')) {
            $row = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT ticket_id FROM tickets WHERE ticket_archived_at IS NULL ORDER BY ticket_id DESC LIMIT 1'));
            $p = $row ? self::ticket($mysqli, (int) $row[0]) : ['ticket_id' => 0, 'ticket_subject' => 'Sample ticket', 'ticket_priority' => 'High'];
            if (str_starts_with($event, 'catalog.')) {
                return ['request_id' => 1, 'ticket_id' => $p['ticket_id'], 'client_id' => $p['client_id'] ?? 0, 'contact_id' => $p['contact_id'] ?? 0, 'decided_by_user_id' => 0, 'reason' => ''];
            }
            if (str_starts_with($event, 'ticket.sla_')) {
                $p += ['sla_clock' => 'resolution', 'sla_percent_used' => 85.0, 'sla_remaining_seconds' => 1800];
            }

            return $p;
        }
        switch ($event) {
            case 'asset.created':
                return ['asset_id' => 1, 'asset_name' => 'LAPTOP-001', 'asset_type' => 'Laptop', 'client_id' => 1, 'source' => 'agent'];
            case 'contact.created':
                return ['contact_id' => 1, 'contact_name' => 'Sample Person', 'contact_email' => 'sample@example.test', 'client_id' => 1, 'source' => 'agent'];
            case 'workflow.task_completed':
                return ['run_id' => 1, 'run_task_id' => 1, 'task_title' => 'Order laptop', 'contact_id' => 1, 'completed_by' => 'person'];
        }

        return ['actor_user_id' => null, 'entity_type' => '', 'entity_id' => '', 'action' => '', 'summary' => 'Sample event', 'metadata' => []];
    }
}
