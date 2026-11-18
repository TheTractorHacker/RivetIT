<?php
declare(strict_types=1);

namespace ITFlow\Onboarding;

/**
 * Where an onboarding packet comes from, resolved from database rows only: the department NEVER comes from the
 * request. Used by both the modal and the endpoint so the two cannot disagree. Exactly one mode:
 *
 *   ticket mode  (ticket id given; any contact id is ignored): the department is the ticket's. The person is a
 *                new hire with no contact record yet, so the name is typed in. The ticket's contact is usually
 *                the manager who asked, so it is only offered as the manager name, never used as the person.
 *   contact mode (contact id, no ticket id): the person is that contact, in the contact's department.
 *
 * Unknown, archived, department-less or department-denied all return null (the caller answers a plain 404, one
 * shape, so ids cannot be probed). The caller still runs enforceClientAccess() as the backstop.
 */
final class PacketContext
{
    public int $clientId = 0;
    /** The person's contact id in contact mode; always 0 in ticket mode. */
    public int $contactId = 0;
    public string $clientName = '';
    /** The contact's name in contact mode; '' in ticket mode (typed in). */
    public string $personName = '';
    public bool $personFromContact = false;
    /** @var array{id:int,prefix:string,number:int,client_id:int,closed:bool}|null set in ticket mode only */
    public ?array $ticket = null;
    /** Ticket mode: the ticket contact's name (the manager prefill), '' when the ticket has no live contact. */
    public string $ticketContactName = '';

    public static function resolve(\mysqli $db, int $contactId, int $ticketId, int $userId, bool $isAdmin): ?self
    {
        $ctx = new self();

        if ($ticketId > 0) {
            $st = $db->prepare('SELECT ticket_prefix, ticket_number, ticket_client_id, ticket_closed_at, client_name, contact_name FROM tickets'
                . ' JOIN clients ON client_id = ticket_client_id'
                . ' LEFT JOIN contacts ON contact_id = ticket_contact_id AND contact_archived_at IS NULL'
                . ' WHERE ticket_id = ?');
            $st->bind_param('i', $ticketId);
            $st->execute();
            $t = $st->get_result()->fetch_assoc();
            $st->close();
            // A ticket with no department has nobody to onboard.
            if (!$t || (int) $t['ticket_client_id'] <= 0 || self::departmentDenied($db, (int) $t['ticket_client_id'], $userId, $isAdmin)) {
                return null;
            }
            $ctx->clientId = (int) $t['ticket_client_id'];
            $ctx->clientName = (string) ($t['client_name'] ?? '');
            $ctx->ticket = [
                'id' => $ticketId,
                'prefix' => (string) ($t['ticket_prefix'] ?? ''),
                'number' => (int) $t['ticket_number'],
                'client_id' => (int) $t['ticket_client_id'],
                'closed' => !empty($t['ticket_closed_at']),
            ];
            $ctx->ticketContactName = (string) ($t['contact_name'] ?? '');
            return $ctx;
        }

        if ($contactId <= 0) {
            return null;
        }
        $st = $db->prepare('SELECT contact_name, contact_client_id, client_name FROM contacts LEFT JOIN clients ON client_id = contact_client_id WHERE contact_id = ? AND contact_archived_at IS NULL');
        $st->bind_param('i', $contactId);
        $st->execute();
        $c = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$c || self::departmentDenied($db, (int) $c['contact_client_id'], $userId, $isAdmin)) {
            return null;
        }
        $ctx->contactId = $contactId;
        $ctx->clientId = (int) $c['contact_client_id'];
        $ctx->clientName = (string) ($c['client_name'] ?? '');
        $ctx->personName = (string) $c['contact_name'];
        $ctx->personFromContact = true;
        return $ctx;
    }

    /**
     * True when this non-admin user has department permissions set and $clientId is not one of them (the same rule as
     * enforceClientAccess(), answered as a bool so the caller can reply with a plain 404 instead of a redirect).
     */
    public static function departmentDenied(\mysqli $db, int $clientId, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin || $clientId <= 0) {
            return false;
        }
        $st = $db->prepare('SELECT COUNT(*) AS total, SUM(client_id = ?) AS hit FROM user_client_permissions WHERE user_id = ?');
        $st->bind_param('ii', $clientId, $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return (int) ($r['total'] ?? 0) > 0 && (int) ($r['hit'] ?? 0) === 0;
    }
}
