<?php
/*
 * Per-category "SLA exempt" and "Non-billable" ticket settings (DB 2.6.201).
 *
 *   categories.category_sla_exempt     1 = tickets in this (category_type = 'Ticket') category get no SLA at all.
 *                                       Implemented by reusing tickets.ticket_counts_sla = 0, the exact same
 *                                       column RivetMSP uses for "this ticket has no SLA: no due dates, no policy, never
 *                                       breached, left out of the SLA reports" (RivetIT gained the column, with
 *                                       ticket_sla_started_at, in the same DB 2.6.201 step; see recalculateTicketSla()
 *                                       and getServiceDeskReport() in includes/sla_functions.php / functions.php).
 *   categories.category_non_billable   1 = tickets in this category default to ticket_billable = 0.
 *   tickets.ticket_billable_overridden 1 = a human explicitly set THIS ticket's billable value at some point after
 *                                       creation (the billable modal, the ticket edit form's billable checkbox when
 *                                       it actually changes, or a bulk/API billable edit). A category's
 *                                       non-billable rule never clobbers an explicit human choice; an explicit
 *                                       choice always wins, whether made before or in the same request as a
 *                                       category change.
 *
 * ONE set of helpers, called from every ticket-creation and category-change path (agent UI, bulk edit, automation
 * rules, the RMM alert pipeline, cron recurring-ticket generation, the inbound-email parser, the client portal, the
 * API) so the rule behaves identically everywhere:
 *
 *   ticketCategoryRuleColumnsExist()      - has DB 2.6.201 run yet? (categories columns)
 *   ticketBillableOverrideColumnExists()  - has DB 2.6.201 run yet? (tickets column)
 *   ticketSlaFlagColumnsExist()           - has DB 2.6.201 run yet? (tickets.ticket_counts_sla / ticket_sla_started_at)
 *   ticketCategoryRules()                 - ['sla_exempt' => bool, 'non_billable' => bool] for one category id
 *   ticketCategoryBillableForNewTicket()   - billable value to INSERT for a brand-new ticket
 *   ticketCategoryApplyOnCreate()          - call right after INSERT, before recalculateTicketSla(): stamps
 *                                            ticket_counts_sla = 0 when the category is SLA-exempt
 *   ticketBillableSetExplicit()            - call wherever a human explicitly sets an EXISTING ticket's billable
 *                                            value with no category change in the same request (the billable
 *                                            modal, a bulk billable edit, an API billable field)
 *   ticketCategoryApplyOnCategoryChange()  - call whenever an EXISTING ticket's category actually changes
 *   ticketCategoryApplyToExistingOpenTickets() - the admin "Apply to existing open tickets" bulk action
 *
 * No back-fill: none of this touches a ticket whose category was not just assigned/changed to it (or, for the last
 * one, that the admin did not explicitly ask to sweep).
 */

/** Have the DB 2.6.201 `categories` columns been added yet? Cached once true (a column is never dropped again). */
function ticketCategoryRuleColumnsExist($mysqli): bool
{
    static $exists = null;
    if ($exists === null) {
        $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM categories LIKE 'category_sla_exempt'");
        $exists = (bool) ($res && mysqli_num_rows($res) > 0);
    }

    return $exists;
}

/** Have the DB 2.6.201 `tickets`.`ticket_billable_overridden` column been added yet? */
function ticketBillableOverrideColumnExists($mysqli): bool
{
    static $exists = null;
    if ($exists === null) {
        $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM tickets LIKE 'ticket_billable_overridden'");
        $exists = (bool) ($res && mysqli_num_rows($res) > 0);
    }

    return $exists;
}

/** Have the DB 2.6.201 `tickets`.`ticket_counts_sla` / `ticket_sla_started_at` columns been added yet? */
function ticketSlaFlagColumnsExist($mysqli): bool
{
    static $exists = null;
    if ($exists === null) {
        $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM tickets LIKE 'ticket_counts_sla'");
        $exists = (bool) ($res && mysqli_num_rows($res) > 0);
    }

    return $exists;
}

/** SLA-exempt / non-billable flags for one Ticket category id. Safe before DB 2.6.201 and for category_id <= 0 (both -> all false). */
function ticketCategoryRules($mysqli, int $category_id): array
{
    $out = ['sla_exempt' => false, 'non_billable' => false];
    if ($category_id <= 0 || !ticketCategoryRuleColumnsExist($mysqli)) {
        return $out;
    }
    $stmt = mysqli_prepare($mysqli, "SELECT category_sla_exempt, category_non_billable FROM categories WHERE category_id = ? AND category_type = 'Ticket' LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $category_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($row) {
        $out['sla_exempt'] = (int) $row['category_sla_exempt'] === 1;
        $out['non_billable'] = (int) $row['category_non_billable'] === 1;
    }

    return $out;
}

/**
 * Billable value to INSERT for a brand-new ticket in $category_id. $requested_billable is whatever the creation
 * path would otherwise use (config_ticket_default_billable, a recurring-ticket template's stored value, an
 * explicit create-form checkbox, etc.) - a non-billable category forces 0 regardless of it, unconditionally,
 * exactly like config_ticket_default_billable is itself ignored for such a category.
 */
function ticketCategoryBillableForNewTicket($mysqli, int $category_id, int $requested_billable): int
{
    $rules = ticketCategoryRules($mysqli, $category_id);

    return $rules['non_billable'] ? 0 : ($requested_billable ? 1 : 0);
}

/**
 * Call right after INSERTing a new ticket (any creation path), before recalculateTicketSla() runs (or, for paths
 * that never call it, instead of it ever being called at all): stamps ticket_counts_sla = 0 when $category_id is
 * SLA-exempt, so the ticket never gets a policy or due dates assigned, now or later. A no-op for a non-exempt
 * category and before DB 2.6.201.
 */
function ticketCategoryApplyOnCreate($mysqli, int $ticket_id, int $category_id): void
{
    if ($ticket_id <= 0) {
        return;
    }
    $rules = ticketCategoryRules($mysqli, $category_id);
    if ($rules['non_billable']) {
        // Covers every creation path, including ones that never set ticket_billable in their INSERT.
        $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_billable = 0 WHERE ticket_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
    if (!$rules['sla_exempt'] || !ticketSlaFlagColumnsExist($mysqli)) {
        return;
    }
    $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_counts_sla = 0, ticket_sla_policy_id = NULL, ticket_sla_response_due = NULL, ticket_sla_resolution_due = NULL WHERE ticket_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * A human explicitly set an EXISTING ticket's billable value, with no category change in the same request (the
 * billable modal, a bulk billable edit, an API billable field). Always records the override - this is, by
 * definition, the human explicitly choosing - so a later category change never overwrites it.
 */
function ticketBillableSetExplicit($mysqli, int $ticket_id, int $billable): void
{
    if ($ticket_id <= 0) {
        return;
    }
    $billable = $billable ? 1 : 0;
    if (ticketBillableOverrideColumnExists($mysqli)) {
        $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_billable = ?, ticket_billable_overridden = 1 WHERE ticket_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $billable, $ticket_id);
    } else {
        $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_billable = ? WHERE ticket_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $billable, $ticket_id);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Apply category rules when an EXISTING ticket's category actually changes (a no-op when $old_category_id ===
 * $new_category_id). $submitted_billable/$old_billable/$old_billable_overridden let a caller that is ALSO changing
 * billable in the same request (the full ticket-edit form) fold that into the same call: an explicit change this
 * request ($submitted_billable !== $old_billable) always wins and is recorded as an override, even over a
 * brand-new non-billable category. A caller that is not touching billable at all just passes $old_billable for
 * $submitted_billable.
 *
 * SLA: entering an exempt category clears the policy/due dates from now (ticket_counts_sla = 0, see
 * ticketCategoryApplyOnCreate() above) and logs an SLA history event; leaving one re-starts the SLA clock from NOW
 * (ticket_sla_started_at), never backdated to ticket_created_at or to when the ticket entered the exempt category.
 *
 * @return array{sla_changed: bool, billable_changed: bool, billable_kept_override: bool}
 */
function ticketCategoryApplyOnCategoryChange($mysqli, int $ticket_id, int $old_category_id, int $new_category_id, int $submitted_billable, int $old_billable, int $old_billable_overridden): array
{
    $result = ['sla_changed' => false, 'billable_changed' => false, 'billable_kept_override' => false];
    if ($ticket_id <= 0 || $old_category_id === $new_category_id) {
        return $result;
    }

    require_once __DIR__ . '/sla_functions.php';

    $old_rules = ticketCategoryRules($mysqli, $old_category_id);
    $new_rules = ticketCategoryRules($mysqli, $new_category_id);

    // --- Billable ---
    $billable = $submitted_billable ? 1 : 0;
    $billable_overridden = $old_billable_overridden ? 1 : 0;
    if ($billable !== $old_billable) {
        // Explicit choice in this very request always wins, whatever the new category says.
        $billable_overridden = 1;
    } elseif ($new_rules['non_billable'] && !$old_rules['non_billable']) {
        if ($old_billable_overridden) {
            $result['billable_kept_override'] = true;
        } elseif ($old_billable === 1) {
            $billable = 0;
        }
    }
    if ($billable !== $old_billable || $billable_overridden !== $old_billable_overridden) {
        if (ticketBillableOverrideColumnExists($mysqli)) {
            $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_billable = ?, ticket_billable_overridden = ? WHERE ticket_id = ?");
            mysqli_stmt_bind_param($stmt, 'iii', $billable, $billable_overridden, $ticket_id);
        } else {
            $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_billable = ? WHERE ticket_id = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $billable, $ticket_id);
        }
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $result['billable_changed'] = ($billable !== $old_billable);
    }

    // --- SLA ---
    if ($new_rules['sla_exempt'] && !$old_rules['sla_exempt']) {
        if (ticketSlaFlagColumnsExist($mysqli)) {
            $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_counts_sla = 0 WHERE ticket_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        recalculateTicketSla($mysqli, $ticket_id); // clears due dates + policy (ticket_counts_sla = 0 path)
        slaLogEvent($mysqli, $ticket_id, 'category_exempt', null, null);
        $result['sla_changed'] = true;
    } elseif (!$new_rules['sla_exempt'] && $old_rules['sla_exempt']) {
        if (ticketSlaFlagColumnsExist($mysqli)) {
            // Start the clock now - never backdated to ticket_created_at or to when the ticket became exempt.
            $stmt = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_counts_sla = 1, ticket_sla_started_at = NOW(), ticket_sla_paused_seconds = 0 WHERE ticket_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $ticket_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        slaSyncPause($mysqli, $ticket_id);
        recalculateTicketSla($mysqli, $ticket_id);
        slaLogEvent($mysqli, $ticket_id, 'category_assigned', null, null);
        $result['sla_changed'] = true;
    }

    return $result;
}

/**
 * The admin "Apply to existing open tickets" action (category edit form, Ticket categories only): retroactively
 * enforces $category_id's CURRENT saved sla_exempt / non_billable flags on every still-open ticket (ticket_closed_at
 * IS NULL AND ticket_archived_at IS NULL) already in that category that does not already match - same effect as if
 * each one were "moved into" the category right now. $dry = true only counts; nothing is written. Always counts/
 * acts within $category_id only (never recurses into child/group categories) and never touches a closed/archived
 * ticket, matching "No back-fill" + an explicit, admin-only, opt-in sweep of OPEN tickets only.
 *
 * @return array{sla_count: int, billable_count: int, total_count: int}
 */
function ticketCategoryApplyToExistingOpenTickets($mysqli, int $category_id, bool $dry = false): array
{
    $out = ['sla_count' => 0, 'billable_count' => 0, 'total_count' => 0];
    if ($category_id <= 0) {
        return $out;
    }
    $rules = ticketCategoryRules($mysqli, $category_id);
    if (!$rules['sla_exempt'] && !$rules['non_billable']) {
        return $out;
    }

    require_once __DIR__ . '/sla_functions.php';

    $open_clause = "ticket_category = ? AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL";
    $affected_ids = []; // union, for total_count

    if ($rules['sla_exempt'] && ticketSlaFlagColumnsExist($mysqli)) {
        $stmt = mysqli_prepare($mysqli, "SELECT ticket_id FROM tickets WHERE $open_clause AND ticket_counts_sla = 1");
        mysqli_stmt_bind_param($stmt, 'i', $category_id);
        mysqli_stmt_execute($stmt);
        $ids = [];
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $ids[] = (int) $row['ticket_id'];
        }
        mysqli_stmt_close($stmt);
        $out['sla_count'] = count($ids);
        foreach ($ids as $tid) {
            $affected_ids[$tid] = true;
        }
        if (!$dry) {
            foreach ($ids as $tid) {
                $u = mysqli_prepare($mysqli, "UPDATE tickets SET ticket_counts_sla = 0 WHERE ticket_id = ?");
                mysqli_stmt_bind_param($u, 'i', $tid);
                mysqli_stmt_execute($u);
                mysqli_stmt_close($u);
                recalculateTicketSla($mysqli, $tid);
                slaLogEvent($mysqli, $tid, 'category_exempt', null, null);
            }
        }
    }

    if ($rules['non_billable']) {
        $overridden_clause = ticketBillableOverrideColumnExists($mysqli) ? ' AND ticket_billable_overridden = 0' : '';
        $stmt = mysqli_prepare($mysqli, "SELECT ticket_id FROM tickets WHERE $open_clause AND ticket_billable = 1$overridden_clause");
        mysqli_stmt_bind_param($stmt, 'i', $category_id);
        mysqli_stmt_execute($stmt);
        $ids = [];
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $ids[] = (int) $row['ticket_id'];
        }
        mysqli_stmt_close($stmt);
        $out['billable_count'] = count($ids);
        foreach ($ids as $tid) {
            $affected_ids[$tid] = true;
        }
        if (!$dry && $ids) {
            $in = implode(',', $ids);
            mysqli_query($mysqli, "UPDATE tickets SET ticket_billable = 0 WHERE ticket_id IN ($in)");
        }
    }

    $out['total_count'] = count($affected_ids);

    return $out;
}
