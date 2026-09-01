<?php
/*
 * Ticket automation engine helpers (Phase 6).
 *
 * Rules support either the legacy single condition/action columns
 * (rule_cond_field/op/value, rule_action/value) or the newer
 * rule_conditions_json / rule_actions_json columns, each holding a JSON
 * array of {field,op,value} / {action,value} objects. All conditions in
 * a rule are AND-ed together; actions run in order.
 */

function automationGetConditions(array $rule): array {
    if (!empty($rule['rule_conditions_json'])) {
        $decoded = json_decode($rule['rule_conditions_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }
    }
    if (!empty($rule['rule_cond_field'])) {
        return [[
            'field' => $rule['rule_cond_field'],
            'op'    => $rule['rule_cond_op'],
            'value' => $rule['rule_cond_value'],
        ]];
    }
    return [];
}

function automationGetActions(array $rule): array {
    if (!empty($rule['rule_actions_json'])) {
        $decoded = json_decode($rule['rule_actions_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            return $decoded;
        }
    }
    if (!empty($rule['rule_action'])) {
        return [[
            'action' => $rule['rule_action'],
            'value'  => $rule['rule_action_value'],
        ]];
    }
    return [];
}

function automationConditionsMatch(array $conditions, array $context): bool {
    if (empty($conditions)) return false;

    foreach ($conditions as $cond) {
        $field = $cond['field'] ?? '';
        $op    = $cond['op'] ?? 'equals';
        $rval  = $cond['value'] ?? '';

        if (!array_key_exists($field, $context)) return false;
        $tval = $context[$field];

        if ($op === 'contains') {
            if (stripos((string) $tval, (string) $rval) === false) return false;
            continue;
        }

        $tval_cmp = is_numeric($tval) ? floatval($tval) : strtolower((string) $tval);
        $rval_cmp = is_numeric($rval) ? floatval($rval) : strtolower((string) $rval);

        switch ($op) {
            case 'greater_than': $match = ($tval_cmp > $rval_cmp);  break;
            case 'less_than':    $match = ($tval_cmp < $rval_cmp);  break;
            case 'not_equals':   $match = ($tval_cmp != $rval_cmp); break;
            case 'equals':
            default:             $match = ($tval_cmp == $rval_cmp); break;
        }

        if (!$match) return false;
    }

    return true;
}

function automationLogRun($mysqli, array $rule, string $trigger_type, array $context, string $summary): void {
    $rule_id   = intval($rule['rule_id']);
    $rule_name = mysqli_real_escape_string($mysqli, $rule['rule_name']);
    $tt        = mysqli_real_escape_string($mysqli, $trigger_type);
    $ticket_id = isset($context['tid']) && $context['tid']           ? intval($context['tid'])     : 'NULL';
    $asset_id  = isset($context['asset_id']) && $context['asset_id'] ? intval($context['asset_id']) : 'NULL';
    $alert_id  = isset($context['alert_id']) && $context['alert_id'] ? intval($context['alert_id']) : 'NULL';
    $client_id = isset($context['client_id']) && $context['client_id'] ? intval($context['client_id']) : 'NULL';
    $summary_esc = mysqli_real_escape_string($mysqli, $summary);

    mysqli_query($mysqli,
        "INSERT INTO ticket_automation_runs
            (rule_id, rule_name, trigger_type, ticket_id, asset_id, alert_id, client_id, summary, created_at)
         VALUES
            ($rule_id, '$rule_name', '$tt', $ticket_id, $asset_id, $alert_id, $client_id, '$summary_esc', NOW())"
    );
}

/*
 * Executes a single automation action. May mutate $context (e.g.
 * create_ticket_from_alert sets $context['tid'] for later actions).
 * Returns a short human-readable summary string, or null if the action
 * was skipped/no-op.
 */
function automationExecuteAction($mysqli, array $action, array &$context, array $rule): ?string {
    $act       = $action['action'] ?? '';
    $aval      = (string) ($action['value'] ?? '');
    $rule_name = $rule['rule_name'];
    $tid       = intval($context['tid'] ?? 0);
    $client_id = intval($context['client_id'] ?? 0);

    switch ($act) {
        case 'set_priority':
            if (!$tid) return null;
            $p = mysqli_real_escape_string($mysqli, strtolower($aval));
            mysqli_query($mysqli, "UPDATE tickets SET ticket_priority = '$p' WHERE ticket_id = $tid");
            logAction("Automation", "Update", "Rule '$rule_name': set priority=$p on ticket $tid", $client_id, $tid);
            return "set priority=$p on ticket #$tid";

        case 'assign_to':
            if (!$tid) return null;
            $uid = intval($aval);
            mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = $uid WHERE ticket_id = $tid");
            logAction("Automation", "Update", "Rule '$rule_name': assigned ticket $tid to user $uid", $client_id, $tid);
            return "assigned ticket #$tid to user $uid";

        case 'escalate':
            // Tiered escalation: reassign to a named user and/or bump priority in one
            // action, then notify the new assignee. Value format: "userID:priority"
            // (either part optional, e.g. "5:critical", "5", ":high").
            if (!$tid) return null;
            $parts   = explode(':', $aval);
            $esc_uid = intval(trim($parts[0] ?? ''));
            $esc_pri = strtolower(trim($parts[1] ?? ''));
            $sets = [];
            $bits = [];
            if ($esc_uid > 0) {
                $sets[] = "ticket_assigned_to = $esc_uid";
                $bits[] = "assigned to user $esc_uid";
            }
            if (in_array($esc_pri, ['low', 'medium', 'high', 'critical'], true)) {
                $esc_pri_esc = mysqli_real_escape_string($mysqli, $esc_pri);
                $sets[] = "ticket_priority = '$esc_pri_esc'";
                $bits[] = "priority=$esc_pri";
            }
            if (empty($sets)) return null;
            mysqli_query($mysqli, "UPDATE tickets SET " . implode(', ', $sets) . " WHERE ticket_id = $tid");
            if ($esc_uid > 0) {
                appNotify("Automation", "Escalation: rule '$rule_name' escalated ticket #$tid to you", "/agent/ticket.php?ticket_id=$tid", $client_id);
            }
            logAction("Automation", "Escalate", "Rule '$rule_name': escalated ticket $tid (" . implode(', ', $bits) . ")", $client_id, $tid);
            return "escalated ticket #$tid (" . implode(', ', $bits) . ")";

        case 'set_status':
            if (!$tid) return null;
            // Resolved is an immediate alias for Closed everywhere else in this
            // app - match that here too instead of leaving the ticket stuck at
            // status 4 relying on the slow cron-based auto-close.
            $sid = resolveTicketStatusId(intval($aval));
            if ($sid == 5) {
                mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_resolved_at = NOW(), ticket_closed_at = NOW() WHERE ticket_id = $tid");
            } else {
                mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $sid WHERE ticket_id = $tid");
            }
            logAction("Automation", "Update", "Rule '$rule_name': set status=$sid on ticket $tid", $client_id, $tid);
            return "set status=$sid on ticket #$tid";

        case 'add_note':
            if (!$tid) return null;
            $note = mysqli_real_escape_string($mysqli, "🤖 Automation: $aval");
            mysqli_query($mysqli,
                "INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_ticket_id, ticket_reply_created_at)
                 VALUES ('$note', 'Automation', $tid, NOW())"
            );
            return "added note to ticket #$tid";

        case 'ai_triage':
            // AI-assisted triage. PHASE 1 = SUGGEST MODE: classify the ticket and
            // post an internal Automation note with the suggestion. Does NOT mutate
            // the ticket's category/priority/assignee. Never fatals - any AI failure
            // (disabled, unconfigured, timeout, bad JSON) is a silent no-op so it can
            // never block the ticket-created dispatch that calls it.
            if (!$tid) return null;
            require_once __DIR__ . '/ai_functions.php';

            // Subject + body come from the dispatch context; fall back to the row.
            $subject = trim((string) ($context['subject'] ?? ''));
            $body    = (string) ($context['details'] ?? '');
            if ($subject === '' && $body === '') {
                $trow = mysqli_fetch_assoc(mysqli_query($mysqli,
                    "SELECT ticket_subject, ticket_details FROM tickets WHERE ticket_id = $tid LIMIT 1"));
                if ($trow) {
                    $subject = trim((string) $trow['ticket_subject']);
                    $body    = (string) $trow['ticket_details'];
                }
            }
            $body = trim(strip_tags($body));

            // Build name -> id maps for validation. Categories and technicians are
            // company-wide; priorities are the fixed ticket enum.
            $cat_by_name = [];
            $cat_names   = [];
            $cres = mysqli_query($mysqli,
                "SELECT category_id, category_name FROM categories
                 WHERE category_type = 'Ticket' AND category_archived_at IS NULL");
            while ($cres && $c = mysqli_fetch_assoc($cres)) {
                $cat_by_name[strtolower(trim($c['category_name']))] = ['id' => intval($c['category_id']), 'name' => $c['category_name']];
                $cat_names[] = $c['category_name'];
            }
            $valid_priorities = ['low', 'medium', 'high', 'critical'];
            $agent_by_name = [];
            $agent_names   = [];
            $ures = mysqli_query($mysqli,
                "SELECT user_id, user_name FROM users
                 WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL");
            while ($ures && $u = mysqli_fetch_assoc($ures)) {
                $agent_by_name[strtolower(trim($u['user_name']))] = ['id' => intval($u['user_id']), 'name' => $u['user_name']];
                $agent_names[] = $u['user_name'];
            }

            $cat_list   = !empty($cat_names)   ? implode(', ', $cat_names)   : '(none configured)';
            $agent_list = !empty($agent_names) ? implode(', ', $agent_names) : '(none configured)';

            $prompt = "You are an IT helpdesk triage assistant. Classify the following support ticket.\n\n"
                . "Valid categories: $cat_list\n"
                . "Valid priorities: Low, Medium, High, Critical\n"
                . "Valid agents: $agent_list\n\n"
                . "Ticket subject: $subject\n"
                . "Ticket body: $body\n\n"
                . "Respond with STRICT JSON only, no prose and no code fences, in exactly this shape:\n"
                . '{"category": "<one of the valid categories or null>", "priority": "<Low|Medium|High|Critical>", "assignee": "<one of the valid agents or null>"}' . "\n"
                . "Use the exact names from the lists above. Use null for any field you are unsure about.";

            $messages = [
                ['role' => 'system', 'content' => 'You classify IT support tickets and reply with strict JSON only.'],
                ['role' => 'user',   'content' => $prompt],
            ];

            $ai = aiChat($messages, 'General', ['temperature' => 0, 'max_tokens' => 300, 'client_id' => $client_id]);
            if (empty($ai['ok'])) {
                // AI disabled/unconfigured/failed - silent no-op by design (must
                // never block the ticket-created dispatch), but log the real
                // reason so a broken provider/model config is diagnosable.
                if (!empty($ai['error']) && $ai['error'] !== 'AI is disabled') {
                    error_log("Automation ai_triage failed for ticket $tid: " . $ai['error']);
                }
                return null;
            }

            // Extract the JSON object (tolerate stray code fences / prose).
            $raw = trim((string) $ai['content']);
            if (preg_match('/\{.*\}/s', $raw, $mjson)) {
                $raw = $mjson[0];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }

            $parts = [];
            $cat_in = strtolower(trim((string) ($decoded['category'] ?? '')));
            if ($cat_in !== '' && $cat_in !== 'null' && isset($cat_by_name[$cat_in])) {
                $parts[] = 'Category: ' . $cat_by_name[$cat_in]['name'];
            }
            $pri_in = strtolower(trim((string) ($decoded['priority'] ?? '')));
            if (in_array($pri_in, $valid_priorities, true)) {
                $parts[] = 'Priority: ' . ucfirst($pri_in);
            }
            $asg_in = strtolower(trim((string) ($decoded['assignee'] ?? '')));
            if ($asg_in !== '' && $asg_in !== 'null' && isset($agent_by_name[$asg_in])) {
                $parts[] = 'Assign to @' . $agent_by_name[$asg_in]['name'];
            }

            if (empty($parts)) {
                return null; // nothing validated - don't post an empty suggestion
            }

            $note     = '🤖 AI Triage suggests: ' . implode(' / ', $parts);
            $note_esc = mysqli_real_escape_string($mysqli, $note);
            mysqli_query($mysqli,
                "INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_ticket_id, ticket_reply_by, ticket_reply_created_at)
                 VALUES ('$note_esc', 'Automation', $tid, 0, NOW())"
            );
            logAction("Automation", "AI Triage", "Rule '$rule_name': posted AI triage suggestion on ticket $tid", $client_id, $tid);
            return "posted AI triage suggestion on ticket #$tid";

        case 'notify_assignee':
            if ($tid) {
                appNotify("Automation", "Rule '$rule_name' triggered on ticket #$tid", "/agent/ticket.php?ticket_id=$tid", $client_id);
                return "notified for ticket #$tid";
            }
            if (!empty($context['asset_id'])) {
                $asset_id = intval($context['asset_id']);
                appNotify("Automation", "Rule '$rule_name' triggered on asset #$asset_id", "/agent/asset_details.php?asset_id=$asset_id", $client_id);
                return "notified for asset #$asset_id";
            }
            return null;

        case 'close_ticket':
            if (!$tid) return null;
            $assigned_to = intval($context['assigned_to'] ?? 0);
            $closer = $assigned_to > 0 ? $assigned_to : 1;
            mysqli_query($mysqli,
                "UPDATE tickets SET ticket_status = 5, ticket_closed_at = NOW(), ticket_closed_by = $closer
                 WHERE ticket_id = $tid AND ticket_resolved_at IS NULL"
            );
            logAction("Automation", "Close", "Rule '$rule_name': auto-closed ticket $tid", $client_id, $tid);
            return "closed ticket #$tid";

        case 'add_worksheet':
            if (!$tid) return null;
            $template_id = intval($aval);
            if ($template_id <= 0) return null;
            $exists = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT worksheet_id FROM ticket_worksheets
                 WHERE worksheet_ticket_id = $tid AND worksheet_template_id = $template_id LIMIT 1"
            ));
            if ($exists) return null;
            mysqli_query($mysqli,
                "INSERT INTO ticket_worksheets (worksheet_ticket_id, worksheet_template_id, worksheet_created_by)
                 VALUES ($tid, $template_id, 1)"
            );
            logAction("Automation", "Worksheet", "Rule '$rule_name': added worksheet template $template_id to ticket $tid", $client_id, $tid);
            return "added worksheet template $template_id to ticket #$tid";

        case 'create_ticket_from_alert':
            if (empty($context['alert'])) return null;
            require_once __DIR__ . '/rmm_functions.php';
            $result = createTicketFromRmmAlert($mysqli, $context['alert'], 0, "Automation: $rule_name");
            $context['tid'] = intval($result['ticket_id']);
            $verb = $result['existing'] ? 'linked existing ticket' : 'created ticket';
            return "$verb #{$context['tid']} from alert";

        case 'acknowledge_alert':
            if (empty($context['alert_id'])) return null;
            $aid = intval($context['alert_id']);
            mysqli_query($mysqli,
                "UPDATE rmm_alerts SET status = 'acknowledged', acknowledged_at = NOW() WHERE id = $aid AND status = 'new'"
            );
            return "acknowledged alert #$aid";

        case 'run_script':
            $script_id = intval($aval);
            $asset_id  = intval($context['asset_id'] ?? 0);
            if ($script_id <= 0 || $asset_id <= 0) return null;

            $script = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT * FROM rmm_scripts WHERE id = $script_id AND enabled = 1 LIMIT 1"
            ));
            if (!$script || empty($script['tactical_script_id'])) return null;

            $link = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT arl.* FROM asset_rmm_links arl
                 JOIN rmm_integrations ri ON ri.id = arl.integration_id
                 WHERE arl.asset_id = $asset_id AND ri.type = 'tactical_rmm' AND ri.enabled = 1 LIMIT 1"
            ));
            if (!$link || empty($link['tactical_agent_id'])) return null;

            try {
                $client = getRmmClient(intval($link['integration_id']));
                $resp   = $client->runScript($link['tactical_agent_id'], intval($script['tactical_script_id']));
            } catch (\Throwable $e) {
                logApp("Automation", "error", "Rule '$rule_name': run_script failed for asset $asset_id: " . $e->getMessage());
                return null;
            }

            $job_id = mysqli_real_escape_string($mysqli, (string) ($resp['id'] ?? ''));
            $script_name_esc = mysqli_real_escape_string($mysqli, $script['name']);
            mysqli_query($mysqli,
                "INSERT INTO rmm_script_runs (script_id, asset_id, user_id, status, tactical_job_id, started_at)
                 VALUES ($script_id, $asset_id, 0, 'running', '$job_id', NOW())"
            );
            logAction("Automation", "Script", "Rule '$rule_name': ran script '$script_name_esc' on asset $asset_id", $client_id, $tid ?: 0);
            return "ran script '{$script['name']}' on asset #$asset_id";

        default:
            return null;
    }
}
