<?php
/*
 * Ticket-created automation dispatch.
 *
 * Runs ticket_automation_rules with rule_trigger = 'ticket_created' immediately
 * after a ticket is inserted, from EVERY ticket-creation path (agent POST handler,
 * email/portal addTicket(), RMM alert, live API create).
 *
 * FIRE-AND-FORGET: every failure - a slow/broken AI provider, a bad rule, a DB
 * hiccup, the automation tables not existing yet - is swallowed here so it can
 * NEVER block or fail the ticket insert that triggered it. The worst case is that
 * an automation simply doesn't run.
 *
 * Unlike the cron triggers, conditions are optional here: a ticket_created rule
 * with no conditions is treated as "match every new ticket" (so an admin can wire
 * up e.g. "AI triage on every new ticket" without a dummy condition).
 */

require_once __DIR__ . '/automation_functions.php';

if (!function_exists('runTicketCreatedAutomation')) {

    function runTicketCreatedAutomation($mysqli, int $ticket_id): void {
        try {
            $ticket_id = intval($ticket_id);
            if ($ticket_id <= 0 || !$mysqli) {
                return;
            }

            // Build the rule-matching context from the freshly-inserted ticket row.
            $t_res = mysqli_query($mysqli,
                "SELECT ticket_id, ticket_client_id, ticket_subject, ticket_details, ticket_category, ticket_priority
                 FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
            if (!$t_res) {
                return;
            }
            $t = mysqli_fetch_assoc($t_res);
            if (!$t) {
                return;
            }

            $context = [
                'tid'       => intval($t['ticket_id']),
                'client_id' => intval($t['ticket_client_id']),
                'subject'   => (string) ($t['ticket_subject'] ?? ''),
                'details'   => (string) ($t['ticket_details'] ?? ''),
                'category'  => intval($t['ticket_category']),
                'priority'  => strtolower((string) ($t['ticket_priority'] ?? '')),
            ];

            $rules = mysqli_query($mysqli,
                "SELECT * FROM ticket_automation_rules
                 WHERE rule_enabled = 1 AND rule_trigger = 'ticket_created'
                 ORDER BY rule_order ASC, rule_id ASC");
            if (!$rules) {
                return;
            }

            while ($rule = mysqli_fetch_assoc($rules)) {
                try {
                    // Empty conditions => match all new tickets (see header note).
                    $conditions = automationGetConditions($rule);
                    $matches = empty($conditions)
                        ? true
                        : automationConditionsMatch($conditions, $context);
                    if (!$matches) {
                        continue;
                    }

                    $actions   = automationGetActions($rule);
                    $summaries = [];
                    foreach ($actions as $action) {
                        $s = automationExecuteAction($mysqli, $action, $context, $rule);
                        if ($s !== null) {
                            $summaries[] = $s;
                        }
                    }

                    // Log every match, even when every action was a silent no-op
                    // (e.g. AI triage failing) - otherwise the Run Log gives zero
                    // signal that a rule is matching but never actually doing
                    // anything, which is the single most likely real-world failure.
                    automationLogRun($mysqli, $rule, 'ticket_created', $context,
                        !empty($summaries) ? implode('; ', $summaries) : 'Matched - no action produced a result (check error log)');
                } catch (\Throwable $e) {
                    // A single broken rule must not stop the others or the ticket insert.
                    if (function_exists('logApp')) {
                        logApp('Automation', 'error',
                            "ticket_created rule " . intval($rule['rule_id'] ?? 0) . " failed: " . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logApp')) {
                logApp('Automation', 'error', 'runTicketCreatedAutomation failed: ' . $e->getMessage());
            }
        }
    }
}
