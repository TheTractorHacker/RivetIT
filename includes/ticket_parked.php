<?php
declare(strict_types=1);

/*
 * Parked ticket statuses (RivetCore docs/design/parked-ticket-statuses.md).
 *
 * A status flagged ticket_statuses.ticket_status_parked = 1 keeps its tickets unresolved but out of the attention surfaces
 * (dashboards, the default ticket list and board, stat cards, open-ticket metrics, overdue / breach notifications). Lookups
 * (search, client / asset / contact / project ticket tabs, single-ticket views, the "All" view, a Status filter that selects
 * the parked status, the "Show parked" toggle) still show them. Parking never pauses the SLA and never changes time or billing.
 *
 * Status ids are per install: nothing outside the one-time upgrade step may name a status. Everything goes through
 * ticketParkedStatusIds() and the SQL builders below. Every function tolerates the column not existing yet (before the
 * database update has run): no parked statuses, no SQL fragment, no error.
 */

/** Does ticket_statuses.ticket_status_parked exist yet? (False until the database update that adds it has run.) */
function ticketParkedColumnExists($mysqli, bool $refresh = false): bool
{
    static $exists = null;
    if ($refresh) {
        $exists = null;
    }
    if ($exists === null) {
        $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM ticket_statuses LIKE 'ticket_status_parked'");
        $exists = (bool) ($res && mysqli_num_rows($res) > 0);
    }
    return $exists;
}

/**
 * Ids of the statuses flagged Parked, cached for the request. $refresh re-reads (the admin save and the tests use it).
 *
 * @return list<int>
 */
function ticketParkedStatusIds($mysqli, bool $refresh = false): array
{
    static $ids = null;
    if ($refresh) {
        $ids = null;
        ticketParkedColumnExists($mysqli, true);
    }
    if ($ids === null) {
        $ids = [];
        if (ticketParkedColumnExists($mysqli)) {
            $res = @mysqli_query($mysqli, "SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_parked = 1");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $ids[] = (int) $r['ticket_status_id'];
            }
        }
    }
    return $ids;
}

/**
 * "AND <col> NOT IN (parked ids)" to append to a WHERE clause that already has a condition; '' when no status is parked.
 * $col is a trusted column expression (ticket_status, t.ticket_status), never user input.
 */
function ticketNotParkedSql($mysqli, string $col = 'ticket_status'): string
{
    $ids = ticketParkedStatusIds($mysqli);
    return $ids ? " AND $col NOT IN (" . implode(',', $ids) . ")" : '';
}

/** "<col> IN (parked ids)" (or "0 = 1" when none), for lookups that want the parked tickets themselves. */
function ticketIsParkedSql($mysqli, string $col = 'ticket_status'): string
{
    $ids = ticketParkedStatusIds($mysqli);
    return $ids ? "$col IN (" . implode(',', $ids) . ")" : '0 = 1';
}

/** Is this one status id parked? */
function ticketStatusIsParked($mysqli, int $status_id): bool
{
    return in_array($status_id, ticketParkedStatusIds($mysqli), true);
}

/**
 * Set or clear the Parked flag on one status. Admin only (the caller passes the session's admin flag, so the rule is
 * testable without a session), a no-op when the column is not there yet or the value does not change, and an audited settings
 * change (logAction) when it does. Returns true only when the flag really changed.
 */
function ticketStatusSetParked($mysqli, int $status_id, bool $parked, bool $is_admin): bool
{
    if (!$is_admin || $status_id <= 0 || !ticketParkedColumnExists($mysqli)) {
        return false;
    }
    $res = mysqli_query($mysqli, "SELECT ticket_status_name, ticket_status_parked FROM ticket_statuses WHERE ticket_status_id = $status_id LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row || (int) $row['ticket_status_parked'] === (int) $parked) {
        return false;
    }
    mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_parked = " . (int) $parked . " WHERE ticket_status_id = $status_id");
    ticketParkedStatusIds($mysqli, true); // the cached list is stale now
    if (function_exists('logAction')) {
        global $session_name;
        logAction("Ticket Status", "Edit", (string) ($session_name ?? 'An administrator') . " set ticket status " . $row['ticket_status_name'] . " to " . ($parked ? 'Parked (hidden from dashboards and metrics)' : 'not Parked'), 0, $status_id);
    }
    return true;
}
