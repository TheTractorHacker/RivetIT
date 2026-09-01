<?php
/*
 * ITFlow
 * Shared helpers for RMM <-> Ticketing integration (Syncro-Beta)
 */

/**
 * Create (or reuse) a ticket from an RMM alert.
 *
 * - If the alert is already linked to a still-open ticket, no new ticket is created;
 *   the existing ticket is returned instead (prevents duplicate alert tickets).
 * - Severity is mapped to ticket priority and the ticket details are enriched with
 *   asset/RMM context (hostname, OS, last seen, logged-in user).
 *
 * @param mysqli $mysqli
 * @param array  $alert        Row from rmm_alerts
 * @param int    $created_by   session_user_id, or 0 for automation
 * @param string $source       ticket_source value, e.g. 'RMM Alert' or 'RMM Automation'
 * @return array{existing: bool, ticket_id: int, redirect: string}
 */
function createTicketFromRmmAlert($mysqli, array $alert, int $created_by, string $source = 'RMM Alert'): array {
    $alert_id  = intval($alert['id']);
    $client_id = intval($alert['client_id']);
    $asset_id  = intval($alert['asset_id']);

    // Dedup: if alert already linked to a still-open ticket, return that ticket
    if (!empty($alert['ticket_id'])) {
        $existing_id = intval($alert['ticket_id']);
        $existing = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT ticket_id FROM tickets WHERE ticket_id = $existing_id
             AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL"
        ));
        if ($existing) {
            return [
                'existing'  => true,
                'ticket_id' => $existing_id,
                'redirect'  => "/agent/ticket.php?ticket_id=$existing_id",
            ];
        }
    }

    // Severity -> priority mapping
    $priority = ['critical' => 'High', 'error' => 'High', 'warning' => 'Medium', 'info' => 'Low'][$alert['severity']] ?? 'Medium';

    // Enrich details with asset/RMM context
    $asset_name   = '';
    $rmm          = null;
    if ($asset_id) {
        $asset_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT asset_name FROM assets WHERE asset_id = $asset_id"));
        if ($asset_row) {
            $asset_name = $asset_row['asset_name'];
        }
        $rmm = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT hostname, os_name, os_version, last_seen, logged_in_user
             FROM asset_rmm_links WHERE asset_id = $asset_id LIMIT 1"
        ));
    }

    $details_lines = ["RMM Alert"];
    $details_lines[] = "Severity: " . ($alert['severity'] ?? '');
    $details_lines[] = "Message: " . ($alert['message'] ?? '');
    if ($asset_name)              { $details_lines[] = "Asset: $asset_name"; }
    if ($rmm) {
        if (!empty($rmm['hostname']))       { $details_lines[] = "Hostname: " . $rmm['hostname']; }
        $os = trim(($rmm['os_name'] ?? '') . ' ' . ($rmm['os_version'] ?? ''));
        if ($os)                             { $details_lines[] = "OS: $os"; }
        if (!empty($rmm['logged_in_user']))  { $details_lines[] = "Logged-in User: " . $rmm['logged_in_user']; }
        if (!empty($rmm['last_seen']))       { $details_lines[] = "Last Seen: " . $rmm['last_seen']; }
    }
    if (!empty($alert['tactical_alert_id'])) { $details_lines[] = "Alert ID: " . $alert['tactical_alert_id']; }

    $subject     = 'RMM Alert: ' . substr($alert['message'] ?? '', 0, 200);
    $subject_esc = mysqli_real_escape_string($mysqli, $subject);
    $details_esc = mysqli_real_escape_string($mysqli, implode("\n", $details_lines));
    $source_esc  = mysqli_real_escape_string($mysqli, $source);
    $priority_esc = mysqli_real_escape_string($mysqli, $priority);

    // Get next ticket number. Atomically increment-and-fetch via LAST_INSERT_ID(),
    // same pattern used by every other ticket-creation path (e.g. functions.php's
    // addTicket(), agent/post/ticket.php) - a plain SELECT-then-UPDATE here raced
    // against those paths and produced duplicate ticket_number values.
    $settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_ticket_prefix FROM settings WHERE company_id=1"));
    $prefix   = mysqli_real_escape_string($mysqli, $settings['config_ticket_prefix']);
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
            config_ticket_next_number = config_ticket_next_number + 1
        WHERE company_id = 1
    ");
    $number   = mysqli_insert_id($mysqli);
    $url_key  = randomString(32);
    $resolved_assigned_to = resolveTicketAssignee(0);
    $ticket_status = $resolved_assigned_to > 0 ? 2 : 1;

    mysqli_query($mysqli,
        "INSERT INTO tickets SET
         ticket_prefix='$prefix',
         ticket_number=$number,
         ticket_subject='$subject_esc',
         ticket_details='$details_esc',
         ticket_status=$ticket_status,
         ticket_priority='$priority_esc',
         ticket_source='$source_esc',
         ticket_client_id=$client_id,
         ticket_asset_id=$asset_id,
         ticket_created_by=$created_by,
         ticket_assigned_to=$resolved_assigned_to,
         ticket_url_key='$url_key',
         ticket_created_at=NOW()"
    );
    $ticket_id = intval(mysqli_insert_id($mysqli));

    // Link alert to the new ticket and acknowledge it
    mysqli_query($mysqli,
        "UPDATE rmm_alerts SET ticket_id=$ticket_id, status='acknowledged', acknowledged_by=" . ($created_by ?: 'NULL') . ", acknowledged_at=NOW()
         WHERE id=$alert_id"
    );

    logAction('RMM', 'Alert Ticket Created', "Ticket $prefix$number created from RMM alert ID $alert_id", $client_id, $asset_id);

    // Run ticket_created automation rules (fire-and-forget; never blocks/fails insert)
    require_once __DIR__ . '/ticket_automation_dispatch.php';
    runTicketCreatedAutomation($mysqli, $ticket_id);

    return [
        'existing'  => false,
        'ticket_id' => $ticket_id,
        'redirect'  => "/agent/ticket.php?ticket_id=$ticket_id",
    ];
}
