<?php

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_url = '';
}

// Ticket client access overide - This is the only way to show tickets without a client to agents with restricted client access
$access_permission_query_overide = '';
if ($client_access_string) {
    $access_permission_query_overide = "AND ticket_client_id IN (0,$client_access_string)";
}

// Perms
enforceUserPermission('module_support');

// AI helpers (aiEnabled) for the optional "Draft with AI" reply control
require_once "../includes/ai_functions.php";

// SLA engine helpers (slaStatus / policy + business-hours calendar resolution) for the timer UI
require_once "../includes/sla_functions.php";

// Initialize the HTML Purifier to prevent XSS
require_once "../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

if (isset($_GET['ticket_id'])) {
    $ticket_id = intval($_GET['ticket_id']);

    $sql = mysqli_query(
        $mysqli,
        "SELECT * FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN users ON ticket_assigned_to = user_id
        LEFT JOIN locations ON ticket_location_id = location_id
        LEFT JOIN assets ON ticket_asset_id = asset_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        LEFT JOIN vendors ON ticket_vendor_id = vendor_id
        LEFT JOIN projects ON ticket_project_id = project_id
        LEFT JOIN quotes ON ticket_quote_id = quote_id
        LEFT JOIN invoices ON ticket_invoice_id = invoice_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN categories ON ticket_category = category_id
        WHERE ticket_id = $ticket_id
        $access_permission_query_overide
        LIMIT 1"
    );

    if (mysqli_num_rows($sql) == 0) {
        echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='tickets.php'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";

        require_once "../includes/footer.php";
    } else {

        $row = mysqli_fetch_assoc($sql);
        $client_id = intval($row['client_id']);
        $client_name = nullable_htmlentities($row['client_name']);
        $client_type = nullable_htmlentities($row['client_type']);
        $client_website = nullable_htmlentities($row['client_website']);
        $client_issues_usage = $client_id ? getClientIncludedIssuesUsage($mysqli, $client_id) : ['remote' => ['included' => null], 'onsite' => ['included' => null]];

        $client_net_terms = intval($row['client_net_terms']);
        if ($client_net_terms == 0) {
            $client_net_terms = $config_default_net_terms;
        }

        $client_rate = floatval($row['client_rate']);

        $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_source = nullable_htmlentities($row['ticket_source']);
        $ticket_category = intval($row['ticket_category']);
        $ticket_category_display = nullable_htmlentities($row['category_name']);
        $ticket_subject = nullable_htmlentities($row['ticket_subject']);
        $ticket_details = $purifier->purify($row['ticket_details']);
        $ticket_priority = nullable_htmlentities($row['ticket_priority']);
        $ticket_billable = intval($row['ticket_billable']);
        $ticket_scheduled_for = nullable_htmlentities($row['ticket_schedule']);
        $ticket_schedule_end  = nullable_htmlentities($row['ticket_schedule_end'] ?? '');
        $ticket_appt_notes    = nullable_htmlentities($row['ticket_appointment_notes'] ?? '');
        $ticket_onsite = intval($row['ticket_onsite']);
        $ticket_delivery_method = nullable_htmlentities($row['ticket_delivery_method']);
        if ($ticket_scheduled_for) {
            $sched_ts  = strtotime($ticket_scheduled_for);
            $sched_fmt = date('D, M j · g:i A', $sched_ts);
            if ($ticket_schedule_end) {
                $end_ts = strtotime($ticket_schedule_end);
                $dur_mins = round(($end_ts - $sched_ts) / 60);
                $dur_label = $dur_mins < 60 ? "{$dur_mins}m" : round($dur_mins/60, 1).'h';
                $sched_fmt .= ' – ' . date('g:i A', $end_ts) . " ({$dur_label})";
            }
            $ticket_scheduled_wording = $sched_fmt;
        } else {
            $ticket_scheduled_wording = "Add";
        }

        //Set Ticket Badge Color based of priority
        if ($ticket_priority == "High") {
            $ticket_priority_display = "<span class='p-2 badge rounded-pill text-bg-danger'>$ticket_priority</span>";
        } elseif ($ticket_priority == "Medium") {
            $ticket_priority_display = "<span class='p-2 badge rounded-pill text-bg-warning'>$ticket_priority</span>";
        } elseif ($ticket_priority == "Low") {
            $ticket_priority_display = "<span class='p-2 badge rounded-pill text-bg-info'>$ticket_priority</span>";
        } else {
            $ticket_priority_display = "";
        }
        $ticket_feedback = nullable_htmlentities($row['ticket_feedback']);
        $ticket_csat_rating = intval($row['ticket_csat_rating'] ?? 0);
        $ticket_csat_comment = nullable_htmlentities($row['ticket_csat_comment']);

        $ticket_status = intval($row['ticket_status_id']);
        $ticket_status_id = intval($row['ticket_status_id']);
        $ticket_status_name = nullable_htmlentities($row['ticket_status_name']);
        $ticket_status_color = nullable_htmlentities($row['ticket_status_color']);

        $ticket_vendor_ticket_number = nullable_htmlentities($row['ticket_vendor_ticket_number']);
        $ticket_created_at = nullable_htmlentities($row['ticket_created_at']);
        $ticket_created_at_ago = timeAgo($row['ticket_created_at']);
        $ticket_created_by = intval($row['ticket_created_by']);
        $ticket_initial_issue_reply_id = $row['ticket_initial_issue_reply_id'] !== null ? intval($row['ticket_initial_issue_reply_id']) : null;
        $ticket_date = date('Y-m-d', strtotime($ticket_created_at));
        $ticket_updated_at = nullable_htmlentities($row['ticket_updated_at']);
        $ticket_updated_at_ago = timeAgo($row['ticket_updated_at']);
        $ticket_first_response_at = nullable_htmlentities($row['ticket_first_response_at']);
        $ticket_resolved_at = nullable_htmlentities($row['ticket_resolved_at']);
        $ticket_resolved_at_ago = timeAgo($row['ticket_resolved_at']);
        $ticket_resolved_date = date('Y-m-d', strtotime($ticket_resolved_at));
        $ticket_closed_at = nullable_htmlentities($row['ticket_closed_at']);
        $ticket_closed_at_ago = timeAgo($row['ticket_closed_at']);
        $ticket_closed_date = date('Y-m-d', strtotime($ticket_closed_at));
        $ticket_closed_by = intval($row['ticket_closed_by']);

        $ticket_assigned_to = intval($row['ticket_assigned_to']);
        if (empty($ticket_assigned_to)) {
            $ticket_assigned_to_display = "<span class='badge rounded-pill text-bg-light'>Unassigned</span>";
            $ticket_assigned_user_name = '';
        } else {
            $ticket_assigned_to_display = nullable_htmlentities($row['user_name']);
            $ticket_assigned_user_name = nullable_htmlentities($row['user_name']);
        }

        $ticket_contract_id = intval($row['ticket_contract_id'] ?? 0);
        $ticket_sla_response_due = $row['ticket_sla_response_due'] ?? null;
        $ticket_sla_resolution_due = $row['ticket_sla_resolution_due'] ?? null;
        $now = new DateTime();
        $sla_contract_name = '';
        if ($ticket_contract_id) {
            $sql_ct = mysqli_query($mysqli, "SELECT contract_name FROM contracts WHERE contract_id = $ticket_contract_id LIMIT 1");
            if ($ctr = mysqli_fetch_assoc($sql_ct)) $sla_contract_name = nullable_htmlentities($ctr['contract_name']);
        }

        // SLA timer status (policy + business-hours calendar aware; falls back cleanly on
        // legacy tickets that only have contract-hour due dates and no policy/calendar).
        $sla_policy        = slaGetPolicyForTicket($mysqli, $ticket_id);
        $sla_calendar      = slaLoadCalendar($mysqli, ($sla_policy && isset($sla_policy['policy_calendar_id'])) ? intval($sla_policy['policy_calendar_id']) : null);
        $sla_policy_name   = ($sla_policy && !empty($sla_policy['policy_name'])) ? nullable_htmlentities($sla_policy['policy_name']) : '';
        $sla_calendar_name = !empty($sla_calendar['calendar_name']) ? nullable_htmlentities($sla_calendar['calendar_name']) : '';
        $sla_open_now      = slaIsOpenNow($sla_calendar);
        $sla_status_data   = slaStatus([
            'ticket_created_at'         => $row['ticket_created_at'] ?? null,
            'ticket_sla_paused_at'      => $row['ticket_sla_paused_at'] ?? null,
            'ticket_sla_response_due'   => $ticket_sla_response_due,
            'ticket_sla_resolution_due' => $ticket_sla_resolution_due,
            'ticket_first_response_at'  => $row['ticket_first_response_at'] ?? null,
            'ticket_resolved_at'        => $row['ticket_resolved_at'] ?? null,
            'ticket_sla_response_met'   => $row['ticket_sla_response_met'] ?? null,
            'ticket_sla_resolution_met' => $row['ticket_sla_resolution_met'] ?? null,
        ], $sla_calendar);

        // Tab Title // No Sanitizing needed
        $page_title = $row['ticket_subject'];
        $tab_title = "{$row['ticket_prefix']}{$row['ticket_number']}";

        $contact_id = intval($row['contact_id']);
        $contact_name = nullable_htmlentities($row['contact_name']);
        $contact_title = nullable_htmlentities($row['contact_title']);
        $contact_email = nullable_htmlentities($row['contact_email']);
        $contact_phone_country_code = nullable_htmlentities($row['contact_phone_country_code']);
        $contact_phone = nullable_htmlentities(formatPhoneNumber($row['contact_phone'], $contact_phone_country_code));
        $contact_extension = nullable_htmlentities($row['contact_extension']);
        $contact_mobile_country_code = nullable_htmlentities($row['contact_mobile_country_code']);
        $contact_mobile = nullable_htmlentities(formatPhoneNumber($row['contact_mobile'], $contact_mobile_country_code));

        $asset_id = intval($row['asset_id']);
        $asset_ip = nullable_htmlentities($row['interface_ip']);
        $asset_name = nullable_htmlentities($row['asset_name']);
        $asset_type = nullable_htmlentities($row['asset_type']);
        $asset_uri = nullable_htmlentities($row['asset_uri']);
        $asset_make = nullable_htmlentities($row['asset_make']);
        $asset_model = nullable_htmlentities($row['asset_model']);
        $asset_serial = nullable_htmlentities($row['asset_serial']);
        $asset_os = nullable_htmlentities($row['asset_os']);
        $asset_warranty_expire = nullable_htmlentities($row['asset_warranty_expire']);
        $asset_icon = getAssetIcon($asset_type);

        $vendor_id = intval($row['ticket_vendor_id']);
        $vendor_name = nullable_htmlentities($row['vendor_name']);
        $vendor_description = nullable_htmlentities($row['vendor_description']);
        $vendor_account_number = nullable_htmlentities($row['vendor_account_number']);
        $vendor_contact_name = nullable_htmlentities($row['vendor_contact_name']);
        $vendor_phone_country_code = nullable_htmlentities($row['vendor_phone_country_code']);
        $vendor_phone = nullable_htmlentities(formatPhoneNumber($row['vendor_phone'], $vendor_phone_country_code));
        $vendor_extension = nullable_htmlentities($row['vendor_extension']);
        $vendor_email = nullable_htmlentities($row['vendor_email']);
        $vendor_website = nullable_htmlentities($row['vendor_website']);
        $vendor_hours = nullable_htmlentities($row['vendor_hours']);
        $vendor_sla = nullable_htmlentities($row['vendor_sla']);
        $vendor_code = nullable_htmlentities($row['vendor_code']);
        $vendor_notes = nullable_htmlentities($row['vendor_notes']);

        $location_id = intval($row['location_id']);
        $location_name = nullable_htmlentities($row['location_name']);
        $location_address = nullable_htmlentities($row['location_address']);
        $location_city = nullable_htmlentities($row['location_city']);
        $location_state = nullable_htmlentities($row['location_state']);
        $location_zip = nullable_htmlentities($row['location_zip']);
        $location_phone = formatPhoneNumber($row['location_phone']);

        $quote_id = intval($row['ticket_quote_id']);
        $quote_prefix = nullable_htmlentities($row['quote_prefix']);
        $quote_number = intval($row['quote_number']);
        $quote_created_at = nullable_htmlentities($row['quote_created_at']);

        $invoice_id = intval($row['ticket_invoice_id']);
        $invoice_prefix = nullable_htmlentities($row['invoice_prefix']);
        $invoice_number = intval($row['invoice_number']);
        $invoice_created_at = nullable_htmlentities($row['invoice_created_at']);

        $project_id = intval($row['project_id']);
        $project_prefix = nullable_htmlentities($row['project_prefix']);
        $project_number = intval($row['project_number']);
        $project_name = nullable_htmlentities($row['project_name']);
        $project_description = nullable_htmlentities($row['project_description']);
        $project_due = nullable_htmlentities($row['project_due']);
        $project_manager = nullable_htmlentities($row['project_manager']);

        if($project_manager) {
            $sql_project_manager = mysqli_query($mysqli,"SELECT * FROM users WHERE user_id = $project_manager");
            $row = mysqli_fetch_assoc($sql_project_manager);
            $project_manager_name = nullable_htmlentities($row['user_name']);
        }

        if ($contact_id) {
            //Get Contact Ticket Stats
            $ticket_related_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS ticket_related_open FROM tickets WHERE ticket_status != 'Closed' AND ticket_contact_id = $contact_id ");
            $row = mysqli_fetch_assoc($ticket_related_open);
            $ticket_related_open = intval($row['ticket_related_open']);

            $ticket_related_closed = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS ticket_related_closed  FROM tickets WHERE ticket_status = 'Closed' AND ticket_contact_id = $contact_id ");
            $row = mysqli_fetch_assoc($ticket_related_closed);
            $ticket_related_closed = intval($row['ticket_related_closed']);

            $ticket_related_total = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS ticket_related_total FROM tickets WHERE ticket_contact_id = $contact_id ");
            $row = mysqli_fetch_assoc($ticket_related_total);
            $ticket_related_total = intval($row['ticket_related_total']);
        }

        //Get Total Ticket Time
        $ticket_total_reply_time = mysqli_query($mysqli, "SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(ticket_reply_time_worked))) AS ticket_total_reply_time FROM ticket_replies WHERE ticket_reply_archived_at IS NULL AND ticket_reply_ticket_id = $ticket_id");
        $row = mysqli_fetch_assoc($ticket_total_reply_time);
        $ticket_total_reply_time = nullable_htmlentities($row['ticket_total_reply_time']);

        // Get multiple schedule entries
        $sql_schedules = mysqli_query($mysqli, "SELECT ts.*, u.user_name AS tech_name
            FROM ticket_schedules ts
            LEFT JOIN users u ON u.user_id = ts.schedule_tech_id
            WHERE ts.schedule_ticket_id = $ticket_id AND ts.schedule_archived_at IS NULL
            ORDER BY ts.schedule_start ASC");

        // Get additional technicians
        $sql_techs = mysqli_query($mysqli, "SELECT tt.tech_id, tt.tech_user_id, u.user_name
            FROM ticket_techs tt
            LEFT JOIN users u ON u.user_id = tt.tech_user_id
            WHERE tt.tech_ticket_id = $ticket_id
            ORDER BY tt.tech_created_at ASC");
        $ticket_techs_rows = [];
        while ($tt = mysqli_fetch_assoc($sql_techs)) $ticket_techs_rows[] = $tt;

        // Get individual time entries for the Time Entry Log card
        $sql_time_entries = mysqli_query($mysqli, "SELECT tr.ticket_reply_id, tr.ticket_reply_time_worked, tr.ticket_reply_created_at,
            tr.ticket_reply_by, tr.ticket_reply_onsite, tr.ticket_reply_type, tr.ticket_reply_labor_type_id,
            COALESCE(u.user_name, c.contact_name) AS time_entry_user_name,
            lt.labor_type_name, lt.labor_type_color
            FROM ticket_replies tr
            LEFT JOIN users u ON tr.ticket_reply_by = u.user_id
            LEFT JOIN contacts c ON tr.ticket_reply_by = c.contact_id
            LEFT JOIN labor_types lt ON lt.labor_type_id = tr.ticket_reply_labor_type_id
            WHERE tr.ticket_reply_ticket_id = $ticket_id
            AND tr.ticket_reply_archived_at IS NULL
            AND tr.ticket_reply_time_worked IS NOT NULL
            AND tr.ticket_reply_time_worked != '00:00:00'
            AND tr.ticket_reply_type NOT IN ('System','Automation','RMM Alert')
            ORDER BY tr.ticket_reply_created_at DESC, tr.ticket_reply_id DESC");

        // Get the number of ticket Responses
        $ticket_responses_sql = mysqli_query($mysqli, "SELECT COUNT(ticket_reply_id) AS ticket_responses FROM ticket_replies WHERE ticket_reply_archived_at IS NULL AND ticket_reply_ticket_id = $ticket_id");
        $row = mysqli_fetch_assoc($ticket_responses_sql);
        $ticket_responses = intval($row['ticket_responses']);

        $ticket_all_comments_sql = mysqli_query($mysqli, "SELECT COUNT(ticket_reply_id) AS ticket_all_comments_count FROM ticket_replies WHERE ticket_reply_archived_at IS NULL AND ticket_reply_ticket_id = $ticket_id");
        $row = mysqli_fetch_assoc($ticket_all_comments_sql);
        $ticket_all_comments_count = intval($row['ticket_all_comments_count']);

        $ticket_internal_notes_sql = mysqli_query($mysqli, "SELECT COUNT(ticket_reply_id) AS ticket_internal_notes_count FROM ticket_replies WHERE ticket_reply_archived_at IS NULL AND ticket_reply_type IN ('Internal','System','Automation','RMM Alert','Labor') AND ticket_reply_ticket_id = $ticket_id");
        $row = mysqli_fetch_assoc($ticket_internal_notes_sql);
        $ticket_internal_notes_count = intval($row['ticket_internal_notes_count']);

        $ticket_public_comments_sql = mysqli_query($mysqli, "SELECT COUNT(ticket_reply_id) AS ticket_public_comments_count FROM ticket_replies WHERE ticket_reply_archived_at IS NULL AND (ticket_reply_type = 'Public' OR ticket_reply_type = 'Client') AND ticket_reply_ticket_id = $ticket_id");
        $row = mysqli_fetch_assoc($ticket_public_comments_sql);
        $ticket_public_comments_count = intval($row['ticket_public_comments_count']);

        $ticket_events_sql = mysqli_query($mysqli, "SELECT COUNT(log_id) AS ticket_events_count FROM logs WHERE log_type = 'Ticket' AND  log_entity_id = $ticket_id");
        $row = mysqli_fetch_assoc($ticket_events_sql);
        $ticket_events_count = intval($row['ticket_events_count']);


        // Get & format asset warranty expiry
        $date = date('Y-m-d H:i:s');
        $dt_value = $asset_warranty_expire; //sample date
        $warranty_check = date('m/d/Y', strtotime('-8 hours'));
        if ($dt_value <= $date) {
            $dt_value = "Expired on $asset_warranty_expire";
            $warranty_status_color = 'red';
        } else {
            $warranty_status_color = 'green';
        }

        if ($asset_warranty_expire == "NULL") {
            $dt_value = "None";
            $warranty_status_color = 'red';
        }


        // Lazy-backfill: store the ticket's original description as a real
        // "Initial Issue" reply so it appears in the reply history below.
        if ($ticket_initial_issue_reply_id === null && trim($ticket_details) !== '') {
            $initial_issue_reply = mysqli_real_escape_string($mysqli, '<strong>Initial Issue</strong><br>' . $ticket_details);
            // No specific staff member actually created this ticket (ticket_created_by
            // is 0 for tickets that came in via API/portal/email rather than an agent
            // typing it in) - attribute the initial issue to the reporting contact
            // instead of leaving it to fall through to a blank/wrong "created by"
            // staff name.
            if (!$ticket_created_by && $contact_id) {
                $initial_issue_reply_type = 'Client';
                $initial_issue_reply_by   = $contact_id;
            } elseif (!$ticket_created_by && $client_id) {
                // No specific contact captured (e.g. a ticket submitted via the API
                // using the shared/legacy key, which doesn't send a contact_id) -
                // fall back to the client's primary contact so this still shows a
                // real, recognizable name instead of misattributing to whichever
                // admin the legacy key happens to resolve to.
                $primary_contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL ORDER BY contact_primary DESC LIMIT 1"));
                if ($primary_contact) {
                    $initial_issue_reply_type = 'Client';
                    $initial_issue_reply_by   = intval($primary_contact['contact_id']);
                } else {
                    // Client has no contacts on file at all - nothing real to
                    // attribute to. The render loop below shows the client's name
                    // instead of the generic "System" label for this specific row.
                    $initial_issue_reply_type = 'Internal';
                    $initial_issue_reply_by   = 0;
                }
            } else {
                $initial_issue_reply_type = 'Internal';
                $initial_issue_reply_by   = $ticket_created_by;
            }
            $initial_issue_reply_type_esc = mysqli_real_escape_string($mysqli, $initial_issue_reply_type);
            mysqli_query($mysqli, "INSERT INTO ticket_replies SET
                ticket_reply = '$initial_issue_reply',
                ticket_reply_type = '$initial_issue_reply_type_esc',
                ticket_reply_created_at = '$ticket_created_at',
                ticket_reply_by = $initial_issue_reply_by,
                ticket_reply_ticket_id = $ticket_id");
            $ticket_initial_issue_reply_id = mysqli_insert_id($mysqli);
            mysqli_query($mysqli, "UPDATE tickets SET ticket_initial_issue_reply_id = $ticket_initial_issue_reply_id WHERE ticket_id = $ticket_id");
        }

        // Get ticket replies
        $sql_ticket_replies = mysqli_query($mysqli, "SELECT * FROM ticket_replies
            LEFT JOIN users ON ticket_reply_by = user_id
            LEFT JOIN contacts ON ticket_reply_by = contact_id
            WHERE ticket_reply_ticket_id = $ticket_id
            AND ticket_reply_archived_at IS NULL
            ORDER BY ticket_reply_created_at DESC, ticket_reply_id DESC"
        );
        $ticket_replies_count = mysqli_num_rows($sql_ticket_replies);

        // Get ticket Events
        $sql_ticket_events = mysqli_query($mysqli, "SELECT * FROM ticket_history
            WHERE ticket_history_ticket_id = $ticket_id
            ORDER BY ticket_history_id DESC"
        );

        // Get Technicians to assign the ticket to
        $sql_assign_to_select = mysqli_query(
            $mysqli,
            "SELECT user_id, user_name FROM users
            WHERE user_role_id > 1
            AND user_type = 1
            AND user_status = 1
            AND user_archived_at IS NULL
            ORDER BY user_name ASC"
        );


        // Get Watchers
        $sql_ticket_watchers = mysqli_query($mysqli, "SELECT * FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id ORDER BY watcher_email DESC");

        // Get Additional Assets
        $sql_additional_assets = mysqli_query($mysqli, "SELECT * FROM assets, ticket_assets
            WHERE assets.asset_id = ticket_assets.asset_id
            AND ticket_id = $ticket_id
            AND assets.asset_id != $asset_id"
        );

        // Get all ticket attachments (ticket-level + reply attachments)
        $sql_ticket_all_attachments = mysqli_query(
            $mysqli,
            "SELECT * FROM ticket_attachments
            WHERE ticket_attachment_ticket_id = $ticket_id
            ORDER BY ticket_attachment_created_at DESC"
        );


        // Get Charges
        $sql_charges = mysqli_query($mysqli, "SELECT tc.*, t.tax_percent, t.tax_name, lt.labor_type_name, lt.labor_type_color FROM ticket_charges tc LEFT JOIN taxes t ON tc.charge_tax_id = t.tax_id LEFT JOIN labor_types lt ON tc.charge_labor_type_id = lt.labor_type_id WHERE tc.charge_ticket_id = $ticket_id AND tc.charge_archived_at IS NULL ORDER BY tc.charge_id ASC");
        $charge_rows = [];
        $charges_subtotal = 0.00;
        while ($cr = mysqli_fetch_assoc($sql_charges)) {
            $charges_subtotal += floatval($cr['charge_total']);
            $charge_rows[] = $cr;
        }

        // Get Tasks
        $sql_tasks = mysqli_query( $mysqli, "SELECT * FROM tasks WHERE task_ticket_id = $ticket_id ORDER BY task_order ASC, task_id ASC");
        $task_count = mysqli_num_rows($sql_tasks);

        // Get Completed Task Count
        $sql_tasks_completed = mysqli_query($mysqli,
            "SELECT * FROM tasks
            WHERE task_ticket_id = $ticket_id
            AND task_completed_at IS NOT NULL"
        );
        $completed_task_count = mysqli_num_rows($sql_tasks_completed);

        // Tasks Completed Percent
        if ($task_count) {
            $tasks_completed_percent = round(($completed_task_count / $task_count) * 100);
        }

        // Get all Assigned ticket Users as a comma-separated string
        $sql_ticket_collaborators = mysqli_query($mysqli, "
            SELECT GROUP_CONCAT(DISTINCT user_name SEPARATOR ', ') AS user_names
            FROM users
            LEFT JOIN ticket_replies ON user_id = ticket_reply_by
            WHERE ticket_reply_archived_at IS NULL AND ticket_reply_ticket_id = $ticket_id
        ");

        // Fetch the result
        $row = mysqli_fetch_assoc($sql_ticket_collaborators);

        // The user names in a comma-separated string
        $ticket_collaborators = nullable_htmlentities($row['user_names']);

        ?>

        <div class="ticket-alga-theme" data-ticket-id="<?= $ticket_id ?>" data-live-chat="<?= $config_module_enable_live_chat ? '1' : '0' ?>" data-csrf="<?= $_SESSION['csrf_token'] ?>" data-user-name="<?= nullable_htmlentities($session_name) ?>" data-user-id="<?= intval($session_user_id) ?>" data-user-type="agent">

        <!-- Breadcrumbs-->
        <ol class="breadcrumb d-print-none">
             <li class="breadcrumb-item">
                <a href="tickets.php">All Tickets</a>
            </li>
            <?php if ($client_url) { ?>
            <li class="breadcrumb-item">
                <a href="tickets.php?client_id=<?php echo $client_id; ?>"><?= $client_name ?> Tickets</a>
            </li>
            <?php } ?>
            <li class="breadcrumb-item active"><?php echo "$ticket_prefix$ticket_number";?></li>
        </ol>

        <div class="card shadow-sm mb-3 ticket-header-compact">
            <div class="card-body pb-3">

                <!-- Subject + action toolbar -->
                <div class="d-flex align-items-start justify-content-between flex-wrap" style="gap:.5rem;">
                    <div class="d-flex align-items-center flex-wrap">
                        <h4 class="fw-bold mb-0 me-2"><?= $ticket_subject ?></h4>
                        <?php if (empty($ticket_closed_at)) { ?>
                        <button type="button" class="btn btn-tool flex-shrink-0 ajax-modal" data-modal-url="modals/ticket/ticket_edit.php?id=<?= $ticket_id ?>" data-modal-size="lg" title="Edit Ticket"><i class="fas fa-edit"></i></button>
                        <?php } ?>
                    </div>

                    <!-- Action toolbar -->
                    <?php if (lookupUserPermission("module_support") >= 2) { ?>
                    <div class="d-flex flex-wrap align-items-center d-print-none" style="gap:.5rem;">

                        <?php if ($config_module_enable_accounting && $ticket_billable == 1 && empty($quote_id) && empty($invoice_id) && lookupUserPermission("module_sales") >= 2) { ?>
                        <a href="#" class="btn btn-light btn-sm ajax-modal" data-modal-url="modals/ticket/ticket_quote_add.php?ticket_id=<?= $ticket_id ?>" data-modal-size="lg">
                            <i class="fas fa-fw fa-comment-dollar me-2"></i>Quote
                        </a>
                        <?php }

                        if ($config_module_enable_accounting && $ticket_billable == 1 && empty($invoice_id) && lookupUserPermission("module_sales") >= 2) { ?>
                            <a href="#" class="btn btn-light btn-sm ajax-modal" data-modal-url="modals/ticket/ticket_invoice_add.php?ticket_id=<?= $ticket_id ?>" data-modal-size="lg">
                                <i class="fas fa-fw fa-file-invoice me-2"></i>Invoice
                            </a>
                        <?php } ?>

                        <?php if (!empty($ticket_resolved_at) || !empty($ticket_closed_at)) { ?>
                            <a href="post.php?reopen_ticket=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-light btn-sm">
                                <i class="fas fa-fw fa-redo me-2"></i>Reopen
                            </a>
                        <?php } ?>

                        <?php if (empty($ticket_closed_at)) { ?>

                            <?php if (empty($ticket_resolved_at) && $task_count == $completed_task_count) { ?>
                                <a href="post.php?resolve_ticket=<?php echo $ticket_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" class="btn btn-dark btn-sm confirm-link" id="ticket_close">
                                    <i class="fas fa-fw fa-check me-2"></i>Resolve
                                </a>
                            <?php } ?>

                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-light btn-sm" type="button" id="newItemDropdown" data-bs-toggle="dropdown">
                                    <i class="fas fa-fw fa-plus me-1"></i>New
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_attachment_add.php?ticket_id=<?= $ticket_id ?>">
                                        <i class="fas fa-fw fa-paperclip me-2"></i>Upload Attachment
                                    </a>
                                    <a class="dropdown-item js-create-outtake" href="#" data-ticket-id="<?= $ticket_id ?>" data-client-id="<?= $client_id ?: 0 ?>" data-csrf-token="<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-file-signature me-2"></i>Add Outtake Form
                                    </a>
                                </div>
                            </div>

                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                                    <i class="fas fa-fw fa-ellipsis-v"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <?php if (function_exists('aiEnabled') && aiEnabled($client_id)) { ?>
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_summary.php?ticket_id=<?= $ticket_id ?>" data-modal-size="lg">
                                        <i class="fas fa-fw fa-lightbulb me-2"></i>Summarize
                                    </a>
                                    <?php } ?>
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_merge.php?ticket_id=<?= $ticket_id ?>">
                                        <i class="fas fa-fw fa-clone me-2"></i>Merge Ticket
                                    </a>
                                    <?php if ($client_id) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_contact.php?id=<?= $ticket_id ?>">
                                            <i class="fa fa-fw fa-user me-2"></i>Add Contact
                                        </a>
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_asset.php?id=<?= $ticket_id ?>">
                                            <i class="fas fa-fw fa-desktop me-2"></i>Add Asset
                                        </a>
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_vendor.php?ticket_id=<?= $ticket_id ?>">
                                            <i class="fas fa-fw fa-building me-2"></i>Add Vendor
                                        </a>
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_add_watcher.php?ticket_id=<?= $ticket_id ?>">
                                            <i class="fas fa-fw fa-users me-2"></i>Add Watcher
                                        </a>
                                    <?php } ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item ajax-modal" href="#" id="clientChangeTicketModalLoad" data-modal-url="modals/ticket/ticket_change_client.php?ticket_id=<?= $ticket_id ?>">
                                        <i class="fas fa-fw fa-people-carry me-2"></i>Change Client
                                    </a>
                                    <?php if (lookupUserPermission("module_support") == 3) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_ticket=<?php echo $ticket_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </a>
                                    <?php } ?>
                                </div>
                            </div>
                        <?php } ?>

                    </div>
                    <?php } ?>

                </div>

                <!-- Ticket #, status, tags + timestamps -->
                <div class="d-flex align-items-center justify-content-between flex-wrap mt-2" style="gap:.5rem;">

                    <div class="d-flex align-items-center flex-wrap text-muted small" style="gap:.6rem;">
                        <span>Ticket# <?= "$ticket_prefix$ticket_number" ?></span>

                        <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_closed_at)) { ?>
                        <a class="ajax-modal" href="#" data-modal-url="modals/ticket/ticket_status.php?id=<?= $ticket_id ?>" title="Change Status">
                            <span class="badge rounded-pill tkt-pill-badge <?= tagTextClass($ticket_status_color) ?>" style="background-color:<?= $ticket_status_color ?>;">
                                <?= $ticket_status_name ?> <i class="fas fa-pen ms-1" style="font-size:.6rem;opacity:.75;"></i>
                            </span>
                        </a>
                        <?php } else { ?>
                        <span class="badge rounded-pill tkt-pill-badge <?= tagTextClass($ticket_status_color) ?>" style="background-color:<?= $ticket_status_color ?>;">
                            <?= $ticket_status_name ?>
                        </span>
                        <?php } ?>

                        <?php
                        $sql_ticket_tags_display = mysqli_query($mysqli, "SELECT * FROM ticket_tags LEFT JOIN tags ON ticket_tag_tag_id = tag_id WHERE ticket_tag_ticket_id = $ticket_id ORDER BY tag_name ASC");
                        if (mysqli_num_rows($sql_ticket_tags_display) > 0) {
                            while ($tag_row = mysqli_fetch_assoc($sql_ticket_tags_display)) {
                                $ticket_tag_name = nullable_htmlentities($tag_row['tag_name']);
                                $ticket_tag_color = nullable_htmlentities($tag_row['tag_color']) ?: 'dark';
                                $ticket_tag_icon = nullable_htmlentities($tag_row['tag_icon']) ?: 'tag';
                                ?>
                                <span class='ticket-tag-pill <?= tagTextClass($ticket_tag_color) ?>' style='background-color: <?= $ticket_tag_color ?>;'><i class='fa fa-fw fa-<?= $ticket_tag_icon ?> me-1'></i><?= $ticket_tag_name ?></span>
                            <?php }
                        } else { ?>
                            <span>No tags</span>
                        <?php } ?>
                        <?php if (lookupUserPermission("module_support") >= 2) { ?>
                        <a class="btn btn-tool btn-sm ajax-modal" href="#" data-modal-url="modals/ticket/ticket_tags.php?id=<?= $ticket_id ?>" title="Edit Tags">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php } ?>

                        <span class="text-info" id="ticket_collision_viewing"></span>
                    </div>

                    <div class="text-muted small text-end flex-shrink-0">
                        Created <?= $ticket_created_at_ago ?>
                        <?php if ($ticket_updated_at) { ?>
                            &middot; Updated <span title="<?= $ticket_updated_at ?>"><?= $ticket_updated_at_ago ?></span>
                        <?php } ?>
                    </div>
                </div>

            </div>

            <div class="card-body py-3 border-top">

                <!-- Assigned To / Priority / Board / Category / Schedule -->
                <div class="d-flex flex-wrap align-items-center" style="gap:1.25rem; row-gap:.5rem;">

                    <!-- Assigned To -->
                    <div class="d-flex align-items-center">
                        <?php if ($ticket_assigned_to) { ?>
                        <span class="avatar-badge"><?= initials($ticket_assigned_user_name) ?></span>
                        <?php } else { ?>
                        <span class="avatar-badge" style="background-color:#adb5bd;"><i class="fas fa-user"></i></span>
                        <?php } ?>
                        <?php if (empty($ticket_closed_at) && lookupUserPermission("module_support") >= 2) { ?>
                        <select id="quickAssignSelect" class="form-control form-control-sm" style="max-width:150px;font-size:.8rem;" data-ticket-id="<?= $ticket_id ?>" data-csrf="<?= $_SESSION['csrf_token'] ?>">
                            <option value="0" <?= !$ticket_assigned_to ? 'selected' : '' ?>>— Unassigned —</option>
                            <?php
                            mysqli_data_seek($sql_assign_to_select, 0);
                            while ($u = mysqli_fetch_assoc($sql_assign_to_select)) {
                                $uid = intval($u['user_id']);
                                $uname = nullable_htmlentities($u['user_name']);
                                echo "<option value=\"$uid\"" . ($uid === $ticket_assigned_to ? ' selected' : '') . ">$uname</option>";
                            }
                            ?>
                        </select>
                        <span id="quickAssignStatus" class="ms-2" style="font-size:13px;"></span>
                        <?php } else { ?>
                        <span><?= $ticket_assigned_to_display ?></span>
                        <?php } ?>
                    </div>

                    <!-- Priority -->
                    <div>
                        <a href="#" title="Priority"
                            <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_closed_at)) { ?>
                                class="ajax-modal"
                                data-modal-url="modals/ticket/ticket_priority.php?id=<?= $ticket_id ?>"
                            <?php } ?>
                        >
                            <?= $ticket_priority_display ?: "<span class='text-muted'><i class=\"fas fa-fw fa-flag me-1\"></i>No priority</span>" ?>
                        </a>
                    </div>

                    <!-- Category -->
                    <div class="text-muted">
                        <i class="fas fa-fw fa-folder me-1"></i><?= $ticket_category_display ?: "No category" ?>
                    </div>

                    <!-- Delivery Method -->
                    <div>
                        <a href="#" title="Delivery Method - which included-issues allowance this counts against"
                            <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_closed_at)) { ?>
                                class="ajax-modal"
                                data-modal-url="modals/ticket/ticket_delivery_method.php?id=<?= $ticket_id ?>"
                            <?php } ?>
                        >
                            <?php if ($ticket_delivery_method === 'Remote') { ?>
                                <span class="text-muted"><i class="fas fa-fw fa-laptop me-1"></i>Remote</span>
                            <?php } elseif ($ticket_delivery_method === 'Onsite') { ?>
                                <span class="text-muted"><i class="fas fa-fw fa-house-user me-1"></i>Onsite</span>
                            <?php } else { ?>
                                <span class="text-muted"><i class="fas fa-fw fa-route me-1"></i>No delivery method</span>
                            <?php } ?>
                        </a>
                    </div>

                </div>
                <!-- End Assigned To / Priority / Board / Category / Schedule -->

                <!-- SLA -->
                    <?php if ($ticket_sla_response_due || $ticket_sla_resolution_due) {
                        // Prefer the SLA policy (+ its business-hours calendar) as the source
                        // label; fall back to the legacy contract name for tickets with no policy.
                        $sla_source_label = $sla_policy_name
                            ? ($sla_policy_name . ($sla_calendar_name ? ' &middot; ' . $sla_calendar_name : ''))
                            : $sla_contract_name;
                        $sla_created_ts = !empty($row['ticket_created_at']) ? strtotime($row['ticket_created_at']) : time();
                        $sla_meter_items = [
                            'Response'   => ['st' => $sla_status_data['response'],   'due' => $ticket_sla_response_due],
                            'Resolution' => ['st' => $sla_status_data['resolution'], 'due' => $ticket_sla_resolution_due],
                        ];
                        // Compact human label for a positive remaining-seconds countdown.
                        $sla_fmt_remaining = function ($secs) {
                            $secs = (int) $secs;
                            if ($secs <= 0) return 'Breached';
                            $d = intdiv($secs, 86400); $secs %= 86400;
                            $h = intdiv($secs, 3600);  $secs %= 3600;
                            $m = intdiv($secs, 60);    $s = $secs % 60;
                            if ($d > 0) return "in {$d}d {$h}h";
                            if ($h > 0) return "in {$h}h {$m}m";
                            return "in {$m}m {$s}s";
                        };
                    ?>
                    <div class="mb-2 border-top pt-2">
                        <div class="mb-1"><i class="fas fa-fw fa-stopwatch text-secondary me-1"></i><small class="text-uppercase fw-bold" style="letter-spacing:.4px;">SLA</small>
                            <?php if ($sla_source_label) { ?><small class="text-muted ms-1">(<?= $sla_source_label ?>)</small><?php } ?>
                        </div>
                        <?php
                        foreach ($sla_meter_items as $sla_label => $sla_item) {
                            $st = $sla_item['st'];
                            $state = $st['state'];
                            if ($state === 'none') continue; // no due date configured for this target
                            $due_ts    = $sla_item['due'] ? strtotime($sla_item['due']) : 0;
                            $total_sec = ($due_ts > 0) ? max(1, $due_ts - $sla_created_ts) : 0;
                            $pct       = ($st['pct'] === null) ? 0 : floatval($st['pct']);
                            $remaining = ($st['remaining_sec'] === null) ? 0 : intval($st['remaining_sec']);

                            // Effective display state: a running timer that is currently outside
                            // the policy's business hours is neither ticking nor accruing.
                            $disp_state = $state;
                            if ($state === 'running' && !$sla_open_now) $disp_state = 'outside';

                            if ($disp_state === 'met')          { $lbl = 'Met';           $barcls = 'bg-success';   $pct = 100; }
                            elseif ($disp_state === 'breached') { $lbl = 'Breached';      $barcls = 'bg-danger';    $pct = 100; }
                            elseif ($disp_state === 'paused')   { $lbl = 'Paused';        $barcls = 'bg-secondary'; }
                            elseif ($disp_state === 'outside')  { $lbl = 'Outside hours'; $barcls = 'bg-info'; }
                            else /* running */                  { $lbl = $sla_fmt_remaining($remaining); $barcls = ($pct >= 90 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-success')); }
                            $bar_pct = round(max(0, min(100, $pct)), 1);
                        ?>
                        <div class="sla-meter mb-1" style="max-width:320px;"
                             data-sla-state="<?= htmlspecialchars($disp_state) ?>"
                             data-sla-remaining="<?= $remaining ?>"
                             data-sla-total="<?= $total_sec ?>"
                             title="<?= $due_ts ? date('M j, Y g:i A', $due_ts) : '' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted"><?= $sla_label ?>:</small>
                                <small class="sla-meter-label fw-bold"><?= htmlspecialchars($lbl) ?></small>
                            </div>
                            <div class="progress mt-1" style="height:6px;">
                                <div class="progress-bar sla-meter-bar <?= $barcls ?>" role="progressbar" style="width:<?= $bar_pct ?>%;"></div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                    <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
                    (function () {
                        var meters = document.querySelectorAll('.sla-meter');
                        if (!meters.length) return;
                        function fmt(secs) {
                            if (secs <= 0) return 'Breached';
                            var d = Math.floor(secs / 86400); secs -= d * 86400;
                            var h = Math.floor(secs / 3600);  secs -= h * 3600;
                            var m = Math.floor(secs / 60);    var s = secs - m * 60;
                            if (d > 0) return 'in ' + d + 'd ' + h + 'h';
                            if (h > 0) return 'in ' + h + 'h ' + m + 'm';
                            return 'in ' + m + 'm ' + s + 's';
                        }
                        function barClass(pct) {
                            if (pct >= 90) return 'bg-danger';
                            if (pct >= 75) return 'bg-warning';
                            return 'bg-success';
                        }
                        var timers = [];
                        meters.forEach(function (el) {
                            // Only running timers tick; paused/outside/met/breached stay as rendered.
                            if (el.getAttribute('data-sla-state') !== 'running') return;
                            timers.push({
                                remaining: parseInt(el.getAttribute('data-sla-remaining'), 10) || 0,
                                total:     parseInt(el.getAttribute('data-sla-total'), 10) || 0,
                                label:     el.querySelector('.sla-meter-label'),
                                bar:       el.querySelector('.sla-meter-bar')
                            });
                        });
                        if (!timers.length) return;
                        var interval = setInterval(function () {
                            timers.forEach(function (t) {
                                t.remaining -= 1;
                                if (t.remaining <= 0) {
                                    t.remaining = 0;
                                    t.label.textContent = 'Breached';
                                    t.bar.className = 'progress-bar sla-meter-bar bg-danger';
                                    t.bar.style.width = '100%';
                                    t.done = true;
                                    return;
                                }
                                var pct = t.total > 0 ? Math.max(0, Math.min(100, ((t.total - t.remaining) / t.total) * 100)) : 100;
                                t.label.textContent = fmt(t.remaining);
                                t.bar.style.width = pct.toFixed(1) + '%';
                                t.bar.className = 'progress-bar sla-meter-bar ' + barClass(pct);
                            });
                            timers = timers.filter(function (t) { return !t.done; });
                            if (!timers.length) clearInterval(interval);
                        }, 1000);
                    })();
                    </script>
                    <?php } ?>
                    <!-- End SLA -->

                    <!-- Billable -->
                    <?php if ($config_module_enable_accounting && lookupUserPermission("module_sales") >= 1) { ?>
                    <div class="border-top pt-3">

                        <?php if ($quote_id) { ?>
                            <div class="mt-1">
                                <i class="fa fa-fw fa-comment-dollar text-secondary me-2"></i>Quoted: <a href="quote.php?quote_id=<?php echo $quote_id ?>"><?php echo "$quote_prefix$quote_number"; ?></a>
                            </div>
                        <?php } ?>

                        <?php if ($invoice_id) { ?>
                            <div class="mt-1">
                                <i class="fa fa-fw fa-dollar-sign text-secondary me-2"></i>Invoiced: <a href="invoice.php?invoice_id=<?php echo $invoice_id ?>"><?php echo "$invoice_prefix$invoice_number"; ?></a>
                            </div>
                        <?php } else { ?>
                            <div class="mt-1">
                                <i class="fa fa-fw fa-dollar-sign text-secondary me-2"></i>Billable:
                                <a class="ajax-modal" href="#"
                                   data-modal-url="modals/ticket/ticket_billable.php?id=<?= $ticket_id ?>">
                                    <?php
                                    if ($ticket_billable == 1) {
                                        echo "<span class='text-bold text-dark'>Yes</span>";
                                    } else {
                                        echo "<span class='text-muted'>No</span>";
                                    }
                                    ?>
                                </a>
                            </div>
                        <?php } ?>

                    </div>
                    <?php } ?>
                    <!-- End billable options -->

            </div>
        </div>


        <div class="row">

            <div class="col-md-9">

                <!-- Reply composer: promoted above the thread and the asset card - this is
                     the action a technician takes on nearly every visit, so it shouldn't
                     require scrolling past reference info first. -->
                <!-- Only show ticket reply modal if status is not closed -->
                <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_resolved_at) && empty($ticket_closed_at)) { ?>

                        <form action="post.php" method="post" autocomplete="off" id="ticketReplyForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="ticket_id" id="ticket_id" value="<?php echo $ticket_id; ?>">
                            <input type="hidden" name="client_id" id="client_id" value="<?php echo $client_id; ?>">

                            <div class="card card-body d-print-none p-3 ticket-primary-panel">

                                <div class="form-group mb-0">
                                    <div class="btn-group btn-block js-btn-group-toggle">
                                        <label class="btn btn-outline-dark active">
                                            <input type="radio" name="public_reply_type" value="0" checked>Internal
                                        </label>
                                        <?php if ($contact_email) { ?>
                                        <label class="btn btn-outline-info">
                                            <input type="radio" name="public_reply_type" value="2">Public + Email
                                        </label>
                                        <?php } ?>
                                        <label class="btn btn-outline-info">
                                            <input type="radio" name="public_reply_type" value="1">Public Note
                                        </label>
                                    </div>
                                </div>

                            </div>

                            <div class="form-group">
                                <div id="ticket-reply-draft-banner" class="alert alert-warning mb-2" style="display:none;border-radius:6px;padding:8px 14px;align-items:center;justify-content:space-between;">
                                    <span><i class="fas fa-history me-2"></i>You have an unsaved draft for this ticket.</span>
                                    <div>
                                        <button type="button" id="draft-restore-btn" class="btn btn-sm btn-warning me-2">Restore Draft</button>
                                        <button type="button" id="draft-discard-btn" class="btn btn-sm btn-outline-secondary">Discard</button>
                                    </div>
                                </div>
                                <?php
                                $sql_canned_responses = mysqli_query($mysqli, "SELECT canned_response_id, canned_response_name, canned_response_message FROM canned_responses WHERE canned_response_archived_at IS NULL ORDER BY canned_response_name ASC");
                                $canned_responses_list = [];
                                while ($cr = mysqli_fetch_assoc($sql_canned_responses)) $canned_responses_list[] = $cr;
                                ?>
                                <?php $ai_reply_draft_enabled = function_exists('aiEnabled') && aiEnabled($client_id); ?>
                                <div class="d-flex align-items-center flex-wrap mb-2" style="gap:.5rem;">
                                    <?php if ($canned_responses_list) { ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="cannedResponseDropdown" data-bs-toggle="dropdown">
                                            <i class="fas fa-fw fa-comment-dots me-1"></i>Insert Canned Response
                                        </button>
                                        <div class="dropdown-menu">
                                            <?php foreach ($canned_responses_list as $cr) {
                                                $cr_name = nullable_htmlentities($cr['canned_response_name']);
                                                $cr_message_json = json_encode($cr['canned_response_message'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                            ?>
                                                <a class="dropdown-item insert-canned-response" href="#" data-message='<?= $cr_message_json ?>'><?= $cr_name ?></a>
                                            <?php } ?>
                                        </div>
                                    </div>
                                    <?php } ?>
                                    <?php if ($ai_reply_draft_enabled) { ?>
                                    <button type="button" id="ai-draft-reply-btn" class="btn btn-sm btn-outline-primary" data-ticket-id="<?= $ticket_id ?>" title="Draft a reply with AI (review before sending)">
                                        <i class="fas fa-fw fa-magic me-1" id="ai-draft-reply-icon"></i><span id="ai-draft-reply-label">Draft with AI</span>
                                    </button>
                                    <span id="ai-draft-reply-error" class="text-danger small" style="display:none;"></span>
                                    <?php } ?>
                                </div>
                                <textarea
                                    id="ticket-reply-editor"
                                    class="form-control tinymceTicketReply" name="ticket_reply"
                                    data-ticket-id="<?= $ticket_id ?>"
                                    placeholder="Type a response">
                                </textarea>
                            </div>

                            <?php
                            $sql_lt_reply = mysqli_query($mysqli, "SELECT labor_type_id, labor_type_name, labor_type_color FROM labor_types WHERE labor_type_archived_at IS NULL ORDER BY labor_type_order ASC, labor_type_name ASC");
                            $lt_reply_rows = [];
                            while ($lt = mysqli_fetch_assoc($sql_lt_reply)) $lt_reply_rows[] = $lt;
                            ?>
                            <div class="form-row align-items-center mt-3" style="background:var(--color-surface-alt);border-radius:var(--input-radius);padding:.75rem;">

                                <!-- Charge now -->
                                <?php if ($config_module_enable_ticket_charges && $lt_reply_rows) { ?>
                                <div class="col-auto">
                                    <div class="form-group mb-2">
                                        <div class="form-check form-check">
                                            <input type="checkbox" class="form-check-input" id="reply_charge_now" name="reply_charge_now" value="1" checked>
                                            <label class="form-check-label font-weight-600" for="reply_charge_now">Charge now</label>
                                        </div>
                                    </div>
                                </div>
                                <?php } ?>

                                <!-- Submit with status split-button (Syncro-style) -->
                                <div class="col-auto ml-auto">
                                    <div class="form-group mb-2">
                                        <input type="hidden" name="status" id="reply_status_val" value="<?= $ticket_status ?>">
                                        <div class="btn-group">
                                            <button type="submit" id="ticket_add_reply" name="add_ticket_reply" class="btn btn-success">
                                                <i class="fas fa-check me-1"></i><span id="reply_submit_label">Submit</span>
                                            </button>
                                            <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                                <span class="sr-only">Set status &amp; submit</span>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-right">
                                                <h6 class="dropdown-header">Submit &amp; set status to…</h6>
                                                <?php
                                                $status_snippet_reply = '';
                                                if ($task_count !== $completed_task_count) {
                                                    $status_snippet_reply = "AND ticket_status_id != 4";
                                                }
                                                $sql_ticket_status_reply = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name, ticket_status_color FROM ticket_statuses WHERE ticket_status_id != 1 AND ticket_status_id != 5 AND ticket_status_active = 1 $status_snippet_reply ORDER BY ticket_status_order");
                                                while ($row_ts = mysqli_fetch_assoc($sql_ticket_status_reply)) {
                                                    $sid   = intval($row_ts['ticket_status_id']);
                                                    $sname = nullable_htmlentities($row_ts['ticket_status_name']);
                                                    $scol  = nullable_htmlentities($row_ts['ticket_status_color']);
                                                    $active = ($ticket_status == $sid) ? ' active' : '';
                                                    echo "<a class=\"dropdown-item reply-status-submit$active\" href=\"#\" data-status-id=\"$sid\" data-status-name=\"$sname\">"
                                                       . "<span class=\"d-inline-block rounded-circle me-2\" style=\"width:10px;height:10px;background:$scol;vertical-align:middle;\"></span>$sname</a>";
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>

                        </form>

                    <!-- End IF for reply modal -->
                <?php } ?>

                <!-- Live update notice (populated by js/live_ticket.js) -->
                <div id="ticket-replies-notice"></div>

                <!-- Comment tabs -->
                <ul class="nav nav-tabs comment-tabs mb-3 d-print-none" id="commentTabs">
                    <li class="nav-item"><a class="nav-link active" href="#" data-comment-filter="all">All Comments</a></li>
                    <li class="nav-item"><a class="nav-link" href="#" data-comment-filter="client">Client</a></li>
                    <li class="nav-item"><a class="nav-link" href="#" data-comment-filter="internal">Internal</a></li>
                    <li class="nav-item"><a class="nav-link" href="#" data-comment-filter="system">System</a></li>
                </ul>

                <!-- Ticket replies -->
                <?php if ($ticket_replies_count === 0) { ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-comment-slash fa-3x mb-3"></i>
                    <p class="mb-0">No activity yet. Add a reply or internal note to get started.</p>
                </div>
                <?php } ?>
                <?php

                while ($row = mysqli_fetch_assoc($sql_ticket_replies)) {
                    $ticket_reply_id = intval($row['ticket_reply_id']);
                    $ticket_reply = $purifier->purify($row['ticket_reply']);
                    $ticket_reply_type = nullable_htmlentities($row['ticket_reply_type']);
                    $ticket_reply_type_border = ['Internal' => 'dark', 'Public' => 'warning', 'Client' => 'warning', 'System' => 'secondary', 'Automation' => 'primary', 'RMM Alert' => 'danger', 'Labor' => 'success'][$ticket_reply_type] ?? 'info';
                    // 'Public' covers both a tech's own reply that got emailed to the client and a
                    // public-visible note with no email sent — ticket_reply_emailed tells them apart
                    // for display. 'Client' (a genuine inbound reply from the client) is unaffected.
                    $ticket_reply_type_label = ['Internal' => 'Internal Note', 'Public' => (!empty($row['ticket_reply_emailed']) ? 'Email Sent' : 'Public Note'), 'Client' => 'Client Reply', 'System' => 'System Note', 'Automation' => 'Automation Note', 'RMM Alert' => 'RMM Alert Note', 'Labor' => 'Labor Note'][$ticket_reply_type] ?? $ticket_reply_type;
                    if ($ticket_reply_id === $ticket_initial_issue_reply_id) {
                        $ticket_reply_type_label = "Initial Issue";
                    }
                    $ticket_reply_created_at = nullable_htmlentities($row['ticket_reply_created_at']);
                    $ticket_reply_created_at_ago = timeAgo($row['ticket_reply_created_at']);
                    $ticket_reply_updated_at = nullable_htmlentities($row['ticket_reply_updated_at']);
                    $ticket_reply_updated_at_ago = timeAgo($row['ticket_reply_updated_at']);
                    $ticket_reply_by = intval($row['ticket_reply_by']);
                    $is_system_generated_reply = false;

                    $is_portal_reply = (stripos($row['ticket_reply'] ?? '', 'Client message from portal') === 0);

                    if ($ticket_reply_type == "Client") {
                        $ticket_reply_by_display = nullable_htmlentities($row['contact_name']);
                        $user_initials = initials($row['contact_name']);
                        $user_avatar = nullable_htmlentities($row['contact_photo']);
                        $avatar_link = "../uploads/clients/$client_id/$user_avatar";
                    } elseif ($is_portal_reply) {
                        $ticket_reply_by_display = 'Client Portal';
                        $user_initials = 'CP';
                        $user_avatar = '';
                        $avatar_link = '';
                    } elseif ($ticket_reply_by === 0 && $ticket_reply_id === $ticket_initial_issue_reply_id && !empty($client_name)) {
                        // The initial-issue backfill couldn't attribute to a specific
                        // contact (client has none on file) or staff creator - show the
                        // client's name rather than the generic "System" label below,
                        // which is meant for genuinely automated events, not a ticket a
                        // client actually submitted.
                        $ticket_reply_by_display = $client_name;
                        $user_initials = initials($client_name);
                        $user_avatar = '';
                        $avatar_link = '';
                    } elseif ($ticket_reply_by === 0) {
                        // No real user - a genuinely system/automation-generated entry
                        // (e.g. an auto-reopen note, a scheduled sync note), not a person
                        // acting on session_user_id. Looking up user_id=0 in the users
                        // table always came back empty, rendering a blank gray circle
                        // with no initials - show a distinct system icon instead.
                        $ticket_reply_by_display = 'System';
                        $user_initials = '';
                        $user_avatar = '';
                        $avatar_link = '';
                        $is_system_generated_reply = true;
                    } else {
                        $ticket_reply_by_display = nullable_htmlentities($row['user_name']);
                        $user_id = intval($row['user_id']);
                        $user_avatar = nullable_htmlentities($row['user_avatar']);
                        $user_initials = initials($row['user_name']);
                        $avatar_link = "../uploads/users/$user_id/$user_avatar";
                        $ticket_reply_time_worked = $row['ticket_reply_time_worked'];
                    }

                    $sql_ticket_reply_attachments = mysqli_query(
                        $mysqli,
                        "SELECT * FROM ticket_attachments
                        WHERE ticket_attachment_reply_id = $ticket_reply_id
                        AND ticket_attachment_ticket_id = $ticket_id"
                    );

                    ?>

                    <!-- Begin ticket reply card -->
                    <div class="card ticket-thread-reply border-left border-<?= $ticket_reply_type_border ?> mb-3" style="border-left-width: 8px !important;" data-reply-type="<?= $ticket_reply_type ?>">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center w-100">
                                <!-- Left side content -->
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($user_avatar)) { ?>
                                        <img src="<?php echo $avatar_link; ?>" alt="User Avatar" class="img-size-50 me-3 img-circle">
                                    <?php } elseif ($is_system_generated_reply) { ?>
                                        <span class="fa-stack fa-2x">
                                            <i class="fa fa-circle fa-stack-2x text-secondary"></i>
                                            <i class="fas fa-robot fa-stack-1x text-white"></i>
                                        </span>
                                    <?php } else { ?>
                                        <span class="fa-stack fa-2x">
                                            <i class="fa fa-circle fa-stack-2x text-secondary"></i>
                                            <span class="fa fa-stack-1x text-white"><?php echo $user_initials; ?></span>
                                        </span>
                                    <?php } ?>

                                    <div class="ms-2">
                                        <h3 class="card-title"><?php echo $ticket_reply_by_display; ?></h3>
                                        <div>
                                            <?php if (!$is_portal_reply && $ticket_reply_type !== "Client" && !empty($ticket_reply_time_worked) && $ticket_reply_time_worked !== "00:00:00") { ?>
                                                <div>
                                                    <br>
                                                    <small>
                                                        <i class="far fa-fw fa-clock text-secondary"></i>
                                                        Time worked:
                                                        <span class="text-muted">
                                                            <?= formatDuration($ticket_reply_time_worked) ?>
                                                        </span>
                                                    </small>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Right-side content -->
                                <div class="text-end d-flex flex-column align-items-end">
                                    <div class="card-tools d-print-none mb-2">
                                        <div class="dropdown dropleft">
                                            <?php if (lookupUserPermission("module_support") >= 2) { ?>
                                                <button class="btn btn-sm btn-tool" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                                                    <i class="fas fa-fw fa-ellipsis-v"></i>
                                                </button>
                                                <div class="dropdown-menu">
                                                    <a href="#" class="dropdown-item ajax-modal"
                                                       data-modal-size = "lg"
                                                       data-modal-url="modals/ticket/ticket_reply_redact.php?id=<?= $ticket_reply_id ?>">
                                                        <i class="fas fa-fw fa-pen text-danger me-2"></i>Redact
                                                    </a>
                                                    <?php if ($ticket_reply_type !== "Client" && empty($ticket_closed_at)) { ?>
                                                    <div class="dropdown-divider"></div>
                                                    <?php if (in_array($ticket_reply_type, ['Internal', 'Public'])) { ?>
                                                    <a href="#" class="dropdown-item ajax-modal"
                                                       data-modal-size = "lg"
                                                       data-modal-url="modals/ticket/ticket_reply_edit.php?id=<?=$ticket_reply_id ?>">
                                                        <i class="fas fa-fw fa-edit text-secondary me-2"></i>Edit
                                                    </a>
                                                    <div class="dropdown-divider"></div>
                                                    <?php } ?>
                                                    <a class="dropdown-item text-danger confirm-link" href="post.php?archive_ticket_reply=<?= $ticket_reply_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                        <i class="fas fa-fw fa-archive me-2"></i>Archive
                                                    </a>
                                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_ticket_reply=<?= $ticket_reply_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                        <i class="fas fa-fw fa-trash me-2"></i>Delete
                                                    </a>
                                                    <?php } ?>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    </div>

                                    <small class="text-muted">
                                        <div title="Created: <?php echo $ticket_reply_created_at; if ($ticket_reply_updated_at) { echo '. Edited: ' . $ticket_reply_updated_at; } ?>">
                                            <?php echo $ticket_reply_type_label . " - " .  $ticket_reply_created_at_ago; if ($ticket_reply_updated_at) { echo '*'; } ?>
                                        </div>
                                    </small>

                                </div>
                            </div>
                        </div>

                        <div class="card-body prettyContent">
                            <?php echo $ticket_reply; ?>

                            <?php
                            while ($ticket_attachment = mysqli_fetch_assoc($sql_ticket_reply_attachments)) {
                                $name = nullable_htmlentities($ticket_attachment['ticket_attachment_name']);
                                $ref_name = nullable_htmlentities($ticket_attachment['ticket_attachment_reference_name']);
                                echo "<hr><i class='fas fa-fw fa-paperclip text-secondary me-1'></i>$name | <a href='../uploads/tickets/$ticket_id/$ref_name' download='$name'><i class='fas fa-fw fa-download me-1'></i>Download</a> | <a target='_blank' href='../uploads/tickets/$ticket_id/$ref_name'><i class='fas fa-fw fa-external-link-alt me-1'></i>View</a>";
                            }
                            ?>
                        </div>
                    </div>
                    <!-- End ticket reply card -->

                    <?php

                }

                ?>

                <!-- Asset card: moved below the thread - reference info a technician
                     checks occasionally, not the primary reason they opened this ticket. -->
                <?php if ($asset_id) { ?>
                    <div class="card mt-3">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1"><i class="fas fa-fw fa-desktop me-2"></i>Assets</h5>
                            <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                            <div class="card-tools">
                                <a class="btn btn-tool ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_asset.php?id=<?= $ticket_id ?>">
                                    <i class="fas fa-fw fa-edit"></i>
                                </a>
                            </div>
                            <?php } ?>
                        </div>
                        <div class="card-body p-3">
                            <div>
                                <a class="ajax-modal" href="#" data-modal-size="lg"
                                    data-modal-url="modals/asset/asset_details.php?<?= $client_url ?>&id=<?= $asset_id ?>">
                                    <i class="fa fa-fw fa-<?php echo $asset_icon; ?> text-secondary me-2"></i><strong><?php echo $asset_name; ?></strong>
                                </a>
                            </div>

                            <?php
                            // RMM status for the primary asset (Syncro-Beta)
                            if ($config_module_enable_rmm && lookupUserPermission("module_rmm") >= 1) {
                                $rmm_tklink = mysqli_fetch_assoc(mysqli_query($mysqli,
                                    "SELECT arl.id, arl.rmm_status, arl.hostname, arl.last_seen, arl.os_name, arl.logged_in_user
                                      FROM asset_rmm_links arl WHERE arl.asset_id=$asset_id LIMIT 1"
                                ));
                                if ($rmm_tklink) {
                                    $rmm_badge = $rmm_tklink['rmm_status'] === 'online' ? 'text-bg-success' : ($rmm_tklink['rmm_status'] === 'offline' ? 'text-bg-danger' : 'text-bg-secondary');
                                    ?>
                                    <div class="mt-2 pt-2 border-top small">
                                        <div class="d-flex align-items-center mb-1">
                                            <i class="fas fa-fw fa-server text-secondary me-2"></i>
                                            <span class="me-2"><?= nullable_htmlentities($rmm_tklink['hostname']) ?></span>
                                            <span class="badge <?= $rmm_badge ?>"><?= ucfirst($rmm_tklink['rmm_status']) ?></span>
                                        </div>
                                        <div class="text-muted">
                                            OS: <?= nullable_htmlentities($rmm_tklink['os_name']) ?>
                                            &nbsp;&middot;&nbsp; Last seen: <?= nullable_htmlentities($rmm_tklink['last_seen']) ?>
                                            <?php if ($rmm_tklink['logged_in_user']): ?>
                                            &nbsp;&middot;&nbsp; User: <?= nullable_htmlentities($rmm_tklink['logged_in_user']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <a href="/agent/asset_details.php?asset_id=<?= $asset_id ?>" class="btn btn-xs btn-info mt-2">
                                            <i class="fas fa-desktop me-1"></i>View Asset
                                        </a>
                                    </div>
                                    <?php
                                }
                            }
                            ?>

                            <?php
                            while ($row = mysqli_fetch_assoc($sql_additional_assets)) {
                                $additional_asset_id = intval($row['asset_id']);
                                $additional_asset_name = nullable_htmlentities($row['asset_name']);
                                $additional_asset_type = nullable_htmlentities($row['asset_type']);
                                $additional_asset_icon = getAssetIcon($additional_asset_type);
                                ?>
                                <div class="mt-1">
                                    <a class="ajax-modal" href="#" data-modal-size="lg"
                                        data-modal-url="modals/asset/asset_details.php?<?= $client_url ?>&id=<?= $additional_asset_id ?>">
                                        <i class="fa fa-fw fa-<?php echo $additional_asset_icon; ?> text-secondary me-2"></i><?php echo $additional_asset_name; ?>
                                    </a>
                                    <?php if (empty($ticket_closed_at)) { ?>
                                        <a class="confirm-link float-end" href="post.php?delete_ticket_additional_asset=<?= $additional_asset_id; ?>&ticket_id=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" title="Remove asset from ticket">
                                            <i class="fas fa-fw fa-times text-secondary"></i>
                                        </a>
                                    <?php } ?>
                                </div>
                            <?php

                            }
                            ?>
                        </div>
                    </div>
                <?php } else { ?>
                    <div class="card mt-3">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1"><i class="fas fa-fw fa-desktop me-2"></i>Assets</h5>
                            <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                            <div class="card-tools">
                                <a class="btn btn-tool ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_asset.php?id=<?= $ticket_id ?>">
                                    <i class="fas fa-fw fa-edit"></i>
                                </a>
                            </div>
                            <?php } ?>
                        </div>
                        <div class="card-body p-3 text-muted">
                            No assets linked to this ticket.
                        </div>
                    </div>
                <?php } // End if asset_id ?>
                <!-- End Asset card -->

            </div>

            <div class="col-md-3 ticket-sidebar">

                <!-- Sidebar is a flex column - each card below carries an explicit
                     `order` so it's tiered by how often it's actually used, independent
                     of PHP render/query order. Tier 1 (order 1-4, always expanded):
                     Time Entry, Tasks, Contact, Attachments. Tier 2 (order 5+, body
                     collapsed by default via native Bootstrap collapse - header stays
                     visible so nothing is hidden from awareness, just deferred). -->

                <!-- Time Entry card -->
                <div class="card time-entry-card" style="order:1;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1"><i class="fas fa-fw fa-stopwatch me-2"></i>Time Entry</h5>
                    </div>
                    <div class="card-body p-3">
                        <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_resolved_at) && empty($ticket_closed_at)) { ?>
                        <?php if ($client_issues_usage['remote']['included'] !== null || $client_issues_usage['onsite']['included'] !== null) { ?>
                        <div class="text-center small text-muted mb-2">
                            <?php foreach (['remote' => ['icon' => 'fa-laptop', 'label' => 'Remote'], 'onsite' => ['icon' => 'fa-house-user', 'label' => 'Onsite']] as $key => $meta):
                                $u = $client_issues_usage[$key];
                                if ($u['included'] === null) continue;
                            ?>
                            <div><i class="fas fa-fw <?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?>: <?= $u['used'] ?> / <?= $u['included'] ?> issues used this month
                                <?php if ($u['remaining'] < 0) { ?>
                                    <span class="text-danger fw-bold" style="white-space:nowrap;">(<?= abs($u['remaining']) ?> over)</span>
                                <?php } ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php } ?>
                        <div class="d-flex align-items-center justify-content-center mb-3">
                            <input type="text" inputmode="numeric" maxlength="2" id="hours" name="hours" placeholder="00" form="ticketReplyForm" class="form-control form-control-sm text-center" style="max-width:3.5rem;">
                            <span class="mx-1 fw-bold">:</span>
                            <input type="text" inputmode="numeric" maxlength="2" id="minutes" name="minutes" placeholder="00" form="ticketReplyForm" class="form-control form-control-sm text-center" style="max-width:3.5rem;">
                            <span class="mx-1 fw-bold">:</span>
                            <input type="text" inputmode="numeric" maxlength="2" id="seconds" name="seconds" placeholder="00" form="ticketReplyForm" class="form-control form-control-sm text-center" style="max-width:3.5rem;">
                        </div>
                        <div class="btn-group btn-block mb-3">
                            <button type="button" class="btn btn-outline-purple" id="startStopTimer"><i class="fas fa-play me-1"></i>Start</button>
                            <button type="button" class="btn btn-outline-purple" id="resetTimer"><i class="fas fa-redo-alt me-1"></i>Reset</button>
                        </div>
                        <?php if ($lt_reply_rows) { ?>
                        <select class="form-control" name="reply_labor_type_id" id="reply_labor_type_id" form="ticketReplyForm">
                            <option value="0">— Labor Type —</option>
                            <?php foreach ($lt_reply_rows as $lt) { ?>
                            <option value="<?= intval($lt['labor_type_id']) ?>" data-color="<?= nullable_htmlentities($lt['labor_type_color']) ?>">
                                <?= nullable_htmlentities($lt['labor_type_name']) ?>
                            </option>
                            <?php } ?>
                        </select>
                        <?php } ?>
                        <?php } else { ?>
                        <span class="text-muted">Ticket closed</span>
                        <?php } ?>
                    </div>
                </div>

                <!-- Time Entry Log card -->
                <?php if (mysqli_num_rows($sql_time_entries) > 0) { ?>
                <div class="card ticket-sidebar-compact" style="order:8;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-timeEntryLog" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-list-ul me-2"></i>Time Entry Log</h5>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-timeEntryLog" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                        </div>
                    </div>
                    <div class="collapse" id="sidebarBody-timeEntryLog">
                    <div class="card-body p-3" style="max-height:220px;overflow-y:auto;">
                        <?php while ($te = mysqli_fetch_assoc($sql_time_entries)) {
                            $te_onsite     = intval($te['ticket_reply_onsite'] ?? 0);
                            $te_lt_id      = intval($te['ticket_reply_labor_type_id'] ?? 0);
                            $te_lt_name    = nullable_htmlentities($te['labor_type_name'] ?? '');
                            $te_lt_color   = nullable_htmlentities($te['labor_type_color'] ?? '#6c757d');
                        ?>
                        <div class="d-flex justify-content-between align-items-start mb-2 small">
                            <div>
                                <div class="font-weight-500"><?= nullable_htmlentities($te['time_entry_user_name']) ?: 'System' ?></div>
                                <div class="text-muted" style="font-size:.75rem;"><?= date('M j, Y g:i A', strtotime($te['ticket_reply_created_at'])) ?></div>
                                <div class="mt-1">
                                    <?php if (lookupUserPermission("module_support") >= 2) { ?>
                                    <form action="post.php" method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="ticket_reply_id" value="<?= intval($te['ticket_reply_id']) ?>">
                                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                                        <input type="hidden" name="onsite" value="<?= $te_onsite ? 0 : 1 ?>">
                                        <button type="submit" name="toggle_reply_onsite" class="badge badge-<?= $te_onsite ? 'warning' : 'secondary' ?> border-0 p-1" style="cursor:pointer;" title="Click to toggle">
                                            <?= $te_onsite ? 'Onsite' : 'Remote' ?>
                                        </button>
                                    </form>
                                    <?php } else { ?>
                                    <span class="badge badge-<?= $te_onsite ? 'warning' : 'secondary' ?>"><?= $te_onsite ? 'Onsite' : 'Remote' ?></span>
                                    <?php } ?>
                                    <?php if (lookupUserPermission("module_support") >= 2 && !empty($lt_reply_rows)) { ?>
                                    <div class="dropdown d-inline-block">
                                        <a href="#" class="badge border-0 dropdown-toggle" data-bs-toggle="dropdown" style="background-color:<?= $te_lt_name ? $te_lt_color : '#6c757d' ?>;color:#fff;cursor:pointer;" title="Click to change labor type">
                                            <?= $te_lt_name ?: '+ Labor Type' ?>
                                        </a>
                                        <div class="dropdown-menu">
                                            <form action="post.php" method="post">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="ticket_reply_id" value="<?= intval($te['ticket_reply_id']) ?>">
                                                <input type="hidden" name="labor_type_id" value="0">
                                                <button type="submit" name="update_reply_labor_type" class="dropdown-item text-muted <?= $te_lt_id === 0 ? 'active' : '' ?>">— None —</button>
                                            </form>
                                            <?php foreach ($lt_reply_rows as $lt) {
                                                $lt_id = intval($lt['labor_type_id']);
                                            ?>
                                            <form action="post.php" method="post">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <input type="hidden" name="ticket_reply_id" value="<?= intval($te['ticket_reply_id']) ?>">
                                                <input type="hidden" name="labor_type_id" value="<?= $lt_id ?>">
                                                <button type="submit" name="update_reply_labor_type" class="dropdown-item <?= $lt_id === $te_lt_id ? 'active' : '' ?>">
                                                    <span class="me-1" style="display:inline-block;width:.6rem;height:.6rem;border-radius:50%;background-color:<?= nullable_htmlentities($lt['labor_type_color']) ?>;"></span>
                                                    <?= nullable_htmlentities($lt['labor_type_name']) ?>
                                                </button>
                                            </form>
                                            <?php } ?>
                                        </div>
                                    </div>
                                    <?php } elseif ($te_lt_name) { ?>
                                    <span class="badge" style="background-color:<?= $te_lt_color ?>;color:#fff;"><?= $te_lt_name ?></span>
                                    <?php } ?>
                                </div>
                            </div>
                            <span class="badge rounded-pill tkt-pill-badge text-bg-light border ms-2 flex-shrink-0"><?= formatDuration($te['ticket_reply_time_worked']) ?></span>
                        </div>
                        <?php } ?>
                    </div>
                    <?php if ($ticket_total_reply_time) { ?>
                    <div class="card-footer px-3 py-2 d-flex justify-content-between align-items-center">
                        <strong class="small">Total</strong>
                        <span class="badge rounded-pill tkt-pill-badge text-light" style="background-color:var(--color-accent);"><?= formatDuration($ticket_total_reply_time) ?></span>
                    </div>
                    <?php } ?>
                    </div>
                </div>
                <?php } ?>

                <!-- ── Appointments card ──────────────────────────────── -->
                <div class="card ticket-sidebar-compact" style="order:6;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-appointments" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-calendar-alt me-2"></i>Appointments</h5>
                        <div class="card-tools">
                            <?php if (empty($ticket_closed_at) && lookupUserPermission("module_support") >= 2) { ?>
                            <a href="#" class="btn btn-tool ajax-modal"
                               data-modal-url="modals/ticket/ticket_schedule_add.php?ticket_id=<?= $ticket_id ?>"
                               title="Add Appointment"><i class="fas fa-plus"></i></a>
                            <?php } ?>
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-appointments" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                        </div>
                    </div>
                    <div class="collapse" id="sidebarBody-appointments">
                    <?php if (mysqli_num_rows($sql_schedules) > 0) { ?>
                    <div class="card-body p-0">
                        <?php $sched_idx = 0; while ($se = mysqli_fetch_assoc($sql_schedules)) {
                            $sched_idx++;
                            $se_start   = date('M j, Y · g:i A', strtotime($se['schedule_start']));
                            $se_end_str = '';
                            if ($se['schedule_end']) {
                                $se_end_str = ' – ' . date('g:i A', strtotime($se['schedule_end']));
                                $dur_mins = round((strtotime($se['schedule_end']) - strtotime($se['schedule_start'])) / 60);
                                if ($dur_mins >= 60) {
                                    $h = $dur_mins / 60;
                                    $se_end_str .= ' (' . (($h == floor($h)) ? intval($h) : number_format($h, 1)) . 'hr)';
                                } else {
                                    $se_end_str .= " ({$dur_mins}m)";
                                }
                            }
                            $se_onsite  = intval($se['schedule_onsite']);
                            $se_tech    = nullable_htmlentities($se['tech_name'] ?? '');
                            $se_notes   = nullable_htmlentities($se['schedule_notes'] ?? '');
                        ?>
                        <div class="px-3 py-2 small <?= $sched_idx > 1 ? 'border-top' : '' ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold"><?= $se_start . $se_end_str ?></div>
                                    <div class="text-muted">
                                        <?php if ($se_tech) { ?><i class="fas fa-user-cog fa-fw me-1"></i><?= $se_tech ?> &nbsp;<?php } ?>
                                        <span class="badge badge-<?= $se_onsite ? 'warning' : 'secondary' ?>"><?= $se_onsite ? 'Onsite' : 'Remote' ?></span>
                                        <?php if ($se_notes) { ?><br><span class="text-muted"><?= $se_notes ?></span><?php } ?>
                                    </div>
                                </div>
                                <?php if (empty($ticket_closed_at) && lookupUserPermission("module_support") >= 2) { ?>
                                <a href="#" class="btn btn-sm btn-tool ajax-modal ms-2"
                                   data-modal-url="modals/ticket/ticket_schedule_edit.php?schedule_id=<?= intval($se['schedule_id']) ?>"
                                   title="Edit"><i class="fas fa-pencil-alt text-muted"></i></a>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                    <?php } else { ?>
                    <div class="card-body p-3">
                        <?php if (empty($ticket_closed_at) && lookupUserPermission("module_support") >= 2) { ?>
                        <a href="#" class="ajax-modal text-muted small"
                           data-modal-url="modals/ticket/ticket_schedule_add.php?ticket_id=<?= $ticket_id ?>">
                            <i class="fa fa-plus me-1"></i>Add appointment
                        </a>
                        <?php } else { ?>
                        <span class="text-muted small">No appointments</span>
                        <?php } ?>
                    </div>
                    <?php } ?>
                    </div>
                </div>
                <!-- ── End Appointments card ────────────────────────────── -->

                <!-- ── Additional Technicians card ────────────────────── -->
                <div class="card ticket-sidebar-compact" style="order:7;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-technicians" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-user-cog me-2"></i>Technicians</h5>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-technicians" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                        </div>
                    </div>
                    <div class="collapse" id="sidebarBody-technicians">
                    <div class="card-body p-3">

                        <!-- Primary assigned tech (read-only display) -->
                        <?php if ($ticket_assigned_to) { ?>
                        <div class="d-flex align-items-center justify-content-between mb-2 small">
                            <div>
                                <span class="avatar-badge me-2" style="width:24px;height:24px;font-size:.65rem;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:var(--color-accent);color:#fff;"><?= initials($ticket_assigned_user_name) ?></span>
                                <?= htmlspecialchars($ticket_assigned_user_name) ?>
                            </div>
                            <span class="badge text-bg-light border">Primary</span>
                        </div>
                        <?php } ?>

                        <!-- Additional techs -->
                        <?php foreach ($ticket_techs_rows as $tt) {
                            $tt_name = nullable_htmlentities($tt['user_name']);
                        ?>
                        <div class="d-flex align-items-center justify-content-between mb-2 small">
                            <div>
                                <span class="avatar-badge me-2" style="width:24px;height:24px;font-size:.65rem;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#6c757d;color:#fff;"><?= initials($tt_name) ?></span>
                                <?= $tt_name ?>
                            </div>
                            <?php if (empty($ticket_closed_at) && lookupUserPermission("module_support") >= 2) { ?>
                            <a href="post.php?delete_ticket_tech=<?= intval($tt['tech_id']) ?>&ticket_id=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                               class="confirm-link text-danger ms-2" title="Remove tech" style="font-size:.75rem;">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php } ?>
                        </div>
                        <?php } ?>

                        <!-- Add tech form -->
                        <?php if (empty($ticket_closed_at) && lookupUserPermission("module_support") >= 2) { ?>
                        <form action="post.php" method="post" class="mt-2">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                            <div class="input-group input-group-sm">
                                <select name="tech_user_id" class="form-control" required>
                                    <option value="">+ Add technician</option>
                                    <?php
                                    $existing_ids = array_column($ticket_techs_rows, 'tech_user_id');
                                    $existing_ids[] = $ticket_assigned_to;
                                    mysqli_data_seek($sql_assign_to_select, 0);
                                    while ($u = mysqli_fetch_assoc($sql_assign_to_select)) {
                                        $uid   = intval($u['user_id']);
                                        $uname = nullable_htmlentities($u['user_name']);
                                        if (in_array($uid, $existing_ids)) continue;
                                        echo "<option value=\"$uid\">$uname</option>";
                                    }
                                    ?>
                                </select>
                                <div class="input-group-append">
                                    <button type="submit" name="add_ticket_tech" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                        </form>
                        <?php } ?>

                    </div>
                    </div>
                </div>
                <!-- ── End Additional Technicians card ────────────────── -->

                <?php if ($config_module_enable_live_chat) {
                    $ticket_chat_history = [];
                    $sql_ticket_chat_history = mysqli_query($mysqli, "SELECT tcm.id, tcm.sender_type, tcm.sender_id, tcm.message, tcm.created_at,
                        COALESCE(u.user_name, c.contact_name) AS sender_name
                        FROM ticket_chat_messages tcm
                        LEFT JOIN users u ON tcm.sender_type = 'agent' AND u.user_id = tcm.sender_id
                        LEFT JOIN contacts c ON tcm.sender_type = 'contact' AND c.contact_id = tcm.sender_id
                        WHERE tcm.ticket_id = $ticket_id
                        ORDER BY tcm.id ASC
                        LIMIT 100");
                    while ($row = mysqli_fetch_assoc($sql_ticket_chat_history)) {
                        $ticket_chat_history[] = [
                            'chat_id' => intval($row['id']),
                            'sender_type' => $row['sender_type'],
                            'sender_id' => intval($row['sender_id']),
                            'sender_name' => $row['sender_name'],
                            'message' => $row['message'],
                            'created_at' => $row['created_at'],
                        ];
                    }
                ?>
                <!-- Live Chat card -->
                <div class="card ticket-sidebar-compact" style="order:11;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-liveChat" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-comments me-2"></i>Live Chat</h5>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-liveChat" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                        </div>
                    </div>
                    <div class="collapse" id="sidebarBody-liveChat">
                    <div class="card-body p-3">
                        <div id="ticket-chat-messages" class="mb-2" style="max-height:260px;overflow-y:auto;"></div>
                        <form id="ticket-chat-form" class="d-flex" autocomplete="off">
                            <input type="text" id="ticket-chat-input" class="form-control form-control-sm me-2" placeholder="Type a message...">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i></button>
                        </form>
                    </div>
                    </div>
                </div>
                <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">window.__ticketChatHistory = <?= json_encode($ticket_chat_history, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
                <?php } ?>

                <!-- Ticket activity right card -->
                <div class="card ticket-sidebar-compact" style="order:12;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-activitySummary" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-history me-2"></i>Activity Summary</h5>

                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-activitySummary" aria-expanded="false">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="sidebarBody-activitySummary">
                    <div class="card-body p-3 ">

                        <!-- Created -->
                        <div>
                            <i class="fas fa-fw fa-calendar-alt text-secondary me-1"></i><strong class="me-1">Created:</strong><?= date('M d, Y', strtotime($ticket_date)) ?>
                            <span class="text-muted small">(<?= $ticket_created_at_ago ?>)</span>
                        </div>

                        <!-- Created by -->
                        <?php if ($ticket_created_by) {
                            $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $ticket_created_by"));
                            $ticket_created_by_display = nullable_htmlentities($row['user_name']);
                            ?>

                            <div class="mt-2">
                                <i class="far fa-fw fa-user text-secondary me-1"></i><strong class="me-1">Created by:</strong><?= $ticket_created_by_display ?>
                            </div>
                        <?php } ?>

                        <!-- Source -->
                        <?php if ($ticket_source) { ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-inbox text-secondary me-1"></i><strong class="me-1">Source:</strong><?= $ticket_source ?>
                            </div>
                        <?php } ?>

                        <!-- Category intentionally not repeated here - already shown
                             as a chip in the header's metadata row, this card is the second
                             place it'd otherwise appear with no cross-reference. -->

                        <!-- First response (for SLA) -->
                        <?php if ($ticket_first_response_at) { ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-reply-all text-secondary me-1"></i><strong class="me-1">1st  resp:</strong><?= date('M d • g:i A', strtotime($ticket_first_response_at)) ?>
                            </div>
                        <?php } ?>

                        <!-- Time tracking -->
                        <?php if ($ticket_total_reply_time) { ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-stopwatch text-secondary me-1"></i><strong class="me-1">Total time:</strong><?= formatDuration($ticket_total_reply_time) ?>
                            </div>
                        <?php } ?>

                        <!-- Internal collaborators -->
                        <!-- Commented - there is still something wrong with this -->
<!--                        --><?php //if ($ticket_collaborators) { ?>
<!--                            <div class="mt-1">-->
<!--                                <i class="fas fa-fw fa-users me-2 text-secondary"></i><strong>Collaborators: </strong>--><?php //echo $ticket_collaborators; ?>
<!--                            </div>-->
<!--                        --><?php //} ?>

                        <!-- Resolved -->
                        <?php if ($ticket_resolved_at) { ?>
                            <hr>
                            <div class="mt-2" title="<?= $ticket_resolved_at ?>">
                                <i class="fas fa-fw fa-check text-secondary me-1"></i><strong class="me-1">Resolved:</strong><?= date('M d, Y • g:i A', strtotime($ticket_resolved_at)) . " ($ticket_resolved_at_ago)" ?>
                            </div>
                        <?php } ?>

                        <!-- Ticket closure info -->
                        <?php if ($ticket_closed_at) {

                            $ticket_closed_by_display = 'User';
                            if (!empty($ticket_closed_by)) {
                                $sql_closed_by = mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $ticket_closed_by");
                                $row = mysqli_fetch_assoc($sql_closed_by);
                                $ticket_closed_by_display = nullable_htmlentities($row['user_name']);
                            }
                            ?>
                            <div class="mt-2">
                                <i class="fas fa-fw fa-user text-secondary me-1"></i><strong class="me-1">Closed by:</strong><?= ucwords($ticket_closed_by_display) ?>
                            </div>

                            <div class="mt-2">
                                <i class="fas fa-fw fa-clock text-secondary me-1"></i><strong class="me-1">Closed:</strong><?= date('M d, Y • g:i A', strtotime($ticket_closed_at)) . " ($ticket_closed_at_ago)" ?>
                            </div>

                        <?php } ?>
                        <!-- END Ticket closure info -->

                        <?php // CSAT is intentionally OUTSIDE the "if closed" block above - a
                              // low rating auto-reopens the ticket (clears ticket_closed_at), and
                              // that's exactly the case where the rating is most important to see
                              // in this sidebar, not the case where it should disappear. ?>
                        <?php if ($ticket_csat_rating > 0) { ?>
                            <div class="mt-2">
                                <i class="fa fa-fw fa-comment-dots text-secondary me-1"></i><strong class="me-1">CSAT:</strong>
                                <span class="csat-face-display" title="<?= csatFaceLabel($ticket_csat_rating) ?>"><?= csatFaceEmoji($ticket_csat_rating) ?></span>
                                <?php if (empty($ticket_closed_at)) { ?>
                                    <span class="badge text-bg-danger ms-1">Needs follow-up</span>
                                <?php } ?>
                            </div>
                            <?php if ($ticket_csat_comment) { ?>
                                <div class="mt-1 ms-4 ps-1 text-muted small">"<?php echo $ticket_csat_comment; ?>"</div>
                            <?php } ?>
                        <?php } ?>

                    </div>
                    </div>
                </div>
                <!-- End details card -->

                <!-- Tasks Card -->
                <?php if (empty($ticket_resolved_at) || (!empty($ticket_resolved_at) && $task_count > 0)) { ?>
                <div class="card" style="order:2;">
                    <div class="card-header px-3 py-2 d-flex align-items-center" style="gap:8px;">
                        <i class="fas fa-fw fa-tasks text-muted" style="font-size:13px;flex-shrink:0;"></i>
                        <span class="fw-bold me-1" style="font-size:14px;">Tasks</span>
                        <?php if ($task_count) { ?>
                        <span class="text-muted" style="font-size:12px;white-space:nowrap;"><?= $completed_task_count ?>/<?= $task_count ?></span>
                        <div class="progress flex-grow-1" style="height:5px;max-width:80px;" title="<?= "$completed_task_count/$task_count ($tasks_completed_percent%)" ?>">
                            <div class="progress-bar bg-success" style="width:<?= $tasks_completed_percent ?>%;transition:width .3s;"></div>
                        </div>
                        <?php } ?>
                        <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                        <div class="dropdown dropleft ml-auto">
                            <button class="btn btn-sm btn-link text-muted p-0" type="button" data-bs-toggle="dropdown" style="line-height:1;">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item text-success" href="post.php?complete_all_tasks=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-check-double me-2"></i>Mark All Complete
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="post.php?undo_complete_all_tasks=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="far fa-fw fa-circle me-2"></i>Mark All Incomplete
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="#">
                                    <i class="fas fa-fw fa-trash-alt me-2"></i>Delete All
                                </a>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-unstyled mb-0" id="tasks">
                        <?php
                        while ($row = mysqli_fetch_assoc($sql_tasks)) {
                            $task_id = intval($row['task_id']);
                            $task_name = nullable_htmlentities($row['task_name']);
                            $task_completion_estimate = intval($row['task_completion_estimate']);
                            $task_completed_at = nullable_htmlentities($row['task_completed_at']);
                            $is_done = !empty($task_completed_at);

                            // Check for approvals
                            $task_needs_approval = mysqli_num_rows(mysqli_query(
                                $mysqli,
                                "SELECT 1 FROM task_approvals
                                 WHERE approval_task_id = $task_id
                                   AND approval_status IN ('pending','declined')
                                 LIMIT 1"
                            )) > 0;

                            $approval_id = 0;
                            $user_can_approve = false;
                            $approval_rows = mysqli_query($mysqli,
                                "SELECT approval_id, approval_scope, approval_type, approval_required_user_id, approval_created_by
                                 FROM task_approvals WHERE approval_task_id = $task_id AND approval_status = 'pending'"
                            );
                            while ($approval = mysqli_fetch_assoc($approval_rows)) {
                                $scope = nullable_htmlentities($approval['approval_scope']);
                                $type  = nullable_htmlentities($approval['approval_type']);
                                $required_user = intval($approval['approval_required_user_id']);
                                $created_by    = intval($approval['approval_created_by']);
                                if ($scope == 'internal' && $type == 'specific' && $required_user == $session_user_id) {
                                    $user_can_approve = true;
                                    $approval_id = intval($approval['approval_id']);
                                }
                                if ($scope == 'internal' && $type == 'any' && $created_by !== $session_user_id) {
                                    $user_can_approve = true;
                                    $approval_id = intval($approval['approval_id']);
                                }
                            }
                        ?>
                        <li data-task-id="<?= $task_id ?>" class="d-flex align-items-center px-3 border-bottom<?= $is_done ? '' : '' ?>" style="padding-top:9px;padding-bottom:9px;gap:10px;<?= $is_done ? 'background:#fafffe;' : '' ?>">

                            <!-- Check icon -->
                            <div style="flex-shrink:0;width:20px;text-align:center;">
                                <?php if ($is_done) { ?>
                                    <i class="fas fa-check-circle text-success" style="font-size:17px;"></i>
                                <?php } elseif (lookupUserPermission("module_support") >= 2) { ?>
                                    <?php if ($task_needs_approval) { ?>
                                        <i class="fas fa-shield-alt text-warning" style="font-size:15px;" data-bs-toggle="tooltip" data-placement="top" title="Approval required"></i>
                                        <?php if ($user_can_approve) { ?>
                                        <a class="confirm-link ms-1" href="post.php?approve_ticket_task=<?= $task_id ?>&approval_id=<?= $approval_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-thumbs-up text-success" title="Approve task"></i>
                                        </a>
                                        <?php } ?>
                                    <?php } else { ?>
                                        <a href="post.php?complete_task=<?= $task_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="text-muted" style="font-size:17px;line-height:1;" title="Mark complete">
                                            <i class="far fa-circle"></i>
                                        </a>
                                    <?php } ?>
                                <?php } else { ?>
                                    <i class="far fa-circle text-muted" style="font-size:17px;"></i>
                                <?php } ?>
                            </div>

                            <!-- Task name -->
                            <span class="flex-grow-1" style="font-size:13px;<?= $is_done ? 'text-decoration:line-through;color:#aaa;' : 'color:#333;' ?>"><?= $task_name ?></span>

                            <!-- Actions -->
                            <div class="d-flex align-items-center" style="flex-shrink:0;gap:2px;">
                                <button class="btn btn-sm btn-link drag-handle text-muted p-0 px-1" style="cursor:grab;" title="Drag to reorder">
                                    <i class="fas fa-grip-vertical" style="font-size:12px;"></i>
                                </button>
                                <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                                <div class="dropdown dropleft">
                                    <button class="btn btn-sm btn-link text-muted p-0 px-1" type="button" data-bs-toggle="dropdown" style="line-height:1;">
                                        <i class="fas fa-ellipsis-v" style="font-size:12px;"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_task_edit.php?id=<?= $task_id ?>">
                                            <i class="fas fa-fw fa-edit me-2"></i>Edit
                                        </a>
                                        <?php if (!$is_done) { ?>
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_task_approver_add.php?id=<?= $task_id ?>">
                                            <i class="fas fa-fw fa-shield-alt me-2"></i>Add Approvers
                                        </a>
                                        <?php } ?>
                                        <?php if ($is_done) { ?>
                                        <a class="dropdown-item" href="post.php?undo_complete_task=<?= $task_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-arrow-circle-left me-2"></i>Mark Incomplete
                                        </a>
                                        <?php } ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger confirm-link" href="post.php?delete_task=<?= $task_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash-alt me-2"></i>Delete
                                        </a>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </li>
                        <?php } ?>
                        </ul>

                        <!-- Add task -->
                        <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                        <form action="post.php" method="post" autocomplete="off" class="px-3 py-2<?= $task_count ? ' border-top' : '' ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-white border-right-0 text-muted"><i class="fas fa-plus" style="font-size:11px;"></i></span>
                                </div>
                                <input type="text" class="form-control border-left-0" name="name" placeholder="Add a task…" required maxlength="255">
                                <div class="input-group-append">
                                    <button type="submit" name="add_task" class="btn btn-outline-primary btn-sm">Add</button>
                                </div>
                            </div>
                        </form>
                        <?php } ?>
                    </div>
                </div>
                <?php } ?>
                <!-- End Tasks Card -->

                <!-- Worksheets Card -->
                <?php
                $sql_worksheets = mysqli_query($mysqli, "SELECT tw.*, wt.worksheet_template_name, wt.worksheet_template_id FROM ticket_worksheets tw JOIN worksheet_templates wt ON tw.worksheet_template_id = wt.worksheet_template_id WHERE tw.worksheet_ticket_id = $ticket_id ORDER BY tw.worksheet_created_at ASC");
                $worksheet_count = mysqli_num_rows($sql_worksheets);
                if (empty($ticket_resolved_at) || $worksheet_count > 0) {
                ?>
                <div class="card ticket-sidebar-compact" style="order:9;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-worksheets" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-clipboard-list me-2"></i>Worksheets</h5>
                        <div class="card-tools">
                            <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                            <a href="#" class="btn btn-tool ajax-modal" data-modal-url="modals/ticket/ticket_worksheet_add.php?ticket_id=<?= $ticket_id ?>">
                                <i class="fas fa-plus"></i>
                            </a>
                            <?php } ?>
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-worksheets" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                        </div>
                    </div>
                    <div class="collapse" id="sidebarBody-worksheets">
                    <div class="card-body p-0">
                        <?php if ($worksheet_count == 0) { ?>
                            <p class="text-secondary text-center p-3 mb-0">No worksheets yet.</p>
                        <?php } ?>

                        <?php while ($ws_row = mysqli_fetch_assoc($sql_worksheets)) {
                            $ws_id = intval($ws_row['worksheet_id']);
                            $ws_tmpl_id = intval($ws_row['worksheet_template_id']);
                            $ws_name = nullable_htmlentities($ws_row['worksheet_template_name']);
                            $ws_outtake = intval($ws_row['worksheet_is_outtake']);
                            $ws_completed = $ws_row['worksheet_completed_at'];
                            $ws_signed = $ws_row['worksheet_signed_at'];
                            $ws_signed_name = nullable_htmlentities($ws_row['worksheet_signed_name']);
                            $ws_token = nullable_htmlentities($ws_row['worksheet_sign_token']);

                            // Load fields + responses
                            $ws_fields = mysqli_query($mysqli, "SELECT f.*, COALESCE(r.response_value,'') AS response_value FROM worksheet_template_fields f LEFT JOIN ticket_worksheet_responses r ON r.response_field_id = f.field_id AND r.response_worksheet_id = $ws_id WHERE f.field_template_id = $ws_tmpl_id ORDER BY f.field_order");
                            $ws_field_count = mysqli_num_rows($ws_fields);
                            $ws_total = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM worksheet_template_fields WHERE field_template_id = $ws_tmpl_id AND field_type != 'heading'"))[0]);
                            $ws_filled = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM ticket_worksheet_responses r JOIN worksheet_template_fields f ON r.response_field_id = f.field_id WHERE r.response_worksheet_id = $ws_id AND f.field_type != 'heading' AND r.response_value != ''"))[0]);
                            $ws_pct = $ws_total > 0 ? round(($ws_filled / $ws_total) * 100) : ($ws_completed ? 100 : 0);
                            $ws_is_locked = !empty($ws_completed) && !$ws_outtake;
                        ?>

                        <!-- Worksheet: <?= $ws_name ?> -->
                        <div class="border-bottom">
                            <!-- Worksheet Header -->
                            <div class="d-flex align-items-center px-3 py-2 js-worksheet-toggle" style="cursor:pointer;" data-bs-target="#ws_body_<?= $ws_id ?>">
                                <i class="fas fa-clipboard-list text-secondary me-2"></i>
                                <strong class="flex-grow-1"><?= $ws_name ?></strong>
                                <?php if ($ws_signed) { ?>
                                    <span class="badge text-bg-success me-2"><i class="fas fa-check me-1"></i>Signed by <?= $ws_signed_name ?></span>
                                <?php } elseif ($ws_completed) { ?>
                                    <span class="badge text-bg-secondary me-2"><i class="fas fa-lock me-1"></i>Finalized</span>
                                <?php } else { ?>
                                    <span class="badge badge-<?= $ws_pct == 100 ? 'success' : 'primary' ?> me-2"><?= $ws_pct ?>%</span>
                                <?php } ?>
                                <?php if ($ws_completed && !$ws_signed && lookupUserPermission("module_support") >= 2) { ?>
                                    <a href="post.php?unfinalize_worksheet=<?= $ws_id ?>&ticket_id=<?= $ticket_id ?>&client_id=<?= $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                       class="btn btn-xs btn-warning ms-1" title="Unfinalize">
                                        <i class="fas fa-lock-open me-1"></i>Unfinalize
                                    </a>
                                <?php } ?>
                                <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_resolved_at)) { ?>
                                    <a href="post.php?delete_ticket_worksheet=<?= $ws_id ?>&ticket_id=<?= $ticket_id ?>&client_id=<?= $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-xs btn-danger confirm-link ms-1" title="Delete"><i class="fas fa-trash"></i></a>
                                <?php } ?>
                                <i class="fas fa-chevron-down text-secondary ms-2"></i>
                            </div>

                            <!-- Worksheet Body (collapsible) -->
                            <div class="collapse show" id="ws_body_<?= $ws_id ?>">
                                <form action="post.php" method="post" autocomplete="off" class="px-3 pb-3 pt-2">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="worksheet_id" value="<?= $ws_id ?>">
                                    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                                    <input type="hidden" name="client_id" value="<?= $client_id ?>">

                                    <?php while ($frow = mysqli_fetch_assoc($ws_fields)) {
                                        $fid = intval($frow['field_id']);
                                        $fname = nullable_htmlentities($frow['field_name']);
                                        $ftype = $frow['field_type'];
                                        $fopts = $frow['field_options'];
                                        $freq = intval($frow['field_required']);
                                        $fval = nullable_htmlentities($frow['response_value']);
                                        $fdisabled = $ws_is_locked ? 'disabled' : '';
                                    ?>

                                    <?php if ($ftype === 'heading') { ?>
                                        <div class="mt-3 mb-2 mx-n3 px-3 py-2" style="background:#e9ecef;border-top:1px solid #dee2e6;border-bottom:1px solid #dee2e6;">
                                            <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#495057;"><?= $fname ?></span>
                                        </div>
                                    <?php } elseif ($ftype === 'checkbox') { ?>
                                        <div class="d-flex align-items-center py-1 border-bottom">
                                            <span class="flex-grow-1 text-sm"><?= $fname ?></span>
                                            <input type="checkbox" name="field_<?= $fid ?>" value="1" <?= $fval == '1' ? 'checked' : '' ?> <?= $fdisabled ?> style="width:18px;height:18px;">
                                        </div>
                                    <?php } elseif ($ftype === 'textarea') { ?>
                                        <div class="form-group mt-2 mb-1">
                                            <label class="mb-0 small text-secondary"><?= $fname ?></label>
                                            <textarea class="form-control form-control-sm" name="field_<?= $fid ?>" rows="2" <?= $freq ? 'required' : '' ?> <?= $fdisabled ?>><?= $fval ?></textarea>
                                        </div>
                                    <?php } elseif ($ftype === 'select') { ?>
                                        <div class="form-group mt-2 mb-1">
                                            <label class="mb-0 small text-secondary"><?= $fname ?></label>
                                            <select class="form-control form-control-sm" name="field_<?= $fid ?>" <?= $fdisabled ?>>
                                                <option value="">- Select -</option>
                                                <?php foreach (array_filter(explode("
", $fopts)) as $opt) {
                                                    $opt = trim($opt);
                                                    echo "<option" . ($fval === $opt ? ' selected' : '') . ">" . htmlspecialchars($opt) . "</option>";
                                                } ?>
                                            </select>
                                        </div>
                                    <?php } elseif ($ftype === 'signature') { ?>
                                        <div class="mt-2 mb-1">
                                            <label class="mb-0 small text-secondary"><?= $fname ?></label>
                                            <?php if ($fval) { ?>
                                                <div><img src="<?= $fval ?>" style="max-height:70px;border:1px solid #ccc;border-radius:4px;background:#fff;display:block;"></div>
                                            <?php } elseif (!$ws_is_locked) { ?>
                                                <canvas id="sig_c_<?= $ws_id ?>_<?= $fid ?>" style="border:1px solid #ccc;border-radius:4px;background:#fff;touch-action:none;display:block;width:100%;height:80px;"></canvas>
                                                <input type="hidden" name="field_<?= $fid ?>" id="sig_d_<?= $ws_id ?>_<?= $fid ?>">
                                                <button type="button" class="btn btn-xs btn-outline-secondary mt-1 js-clear-ws-sig" data-ws-id="<?= $ws_id ?>" data-field-id="<?= $fid ?>">Clear</button>
                                            <?php } ?>
                                        </div>
                                    <?php } else { ?>
                                        <div class="form-group mt-2 mb-1">
                                            <label class="mb-0 small text-secondary"><?= $fname ?></label>
                                            <input type="text" class="form-control form-control-sm" name="field_<?= $fid ?>" value="<?= $fval ?>" <?= $freq ? 'required' : '' ?> <?= $fdisabled ?>>
                                        </div>
                                    <?php } ?>

                                    <?php } // end field loop ?>

                                    <?php if (!$ws_is_locked) { ?>
                                    <div class="d-flex mt-3">
                                        <button type="submit" name="save_worksheet" class="btn btn-sm btn-outline-primary me-2"><i class="fas fa-save me-1"></i>Save</button>
                                        <button type="submit" name="complete_worksheet" class="btn btn-sm btn-dark"><i class="fas fa-check me-1"></i>Finalize</button>
                                    </div>
                                    <?php } ?>

                                </form>
                            </div>
                        </div>

                        <?php } // end worksheet loop ?>
                    </div>
                    </div>
                </div>
                <?php } ?>
                <!-- End Worksheets Card -->

                <!-- Attachments Card -->
                <?php
                $sql_pending_outtakes = mysqli_query($mysqli, "SELECT * FROM ticket_outtake_forms WHERE outtake_ticket_id = $ticket_id AND outtake_signed_at IS NULL ORDER BY outtake_created_at DESC");
                $pending_outtake_count = mysqli_num_rows($sql_pending_outtakes);
                $attachment_count = mysqli_num_rows($sql_ticket_all_attachments);
                ?>
                <div class="card" style="order:4;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1"><i class="fas fa-fw fa-paperclip me-2"></i>Attachments</h5>
                    </div>
                    <?php if (empty($ticket_closed_at) && lookupUserPermission("module_support") >= 2) { ?>
                    <div class="card-body px-3 py-2 border-bottom d-flex flex-wrap" style="gap:6px;">
                        <a href="#" class="btn btn-sm btn-outline-purple ajax-modal" data-modal-url="modals/ticket/ticket_attachment_add.php?ticket_id=<?= $ticket_id ?>">
                            <i class="fas fa-fw fa-upload me-1"></i>Upload File
                        </a>
                        <a href="#" class="btn btn-sm btn-outline-purple js-create-outtake" data-ticket-id="<?= $ticket_id ?>" data-client-id="<?= $client_id ?: 0 ?>" data-csrf-token="<?= $_SESSION['csrf_token'] ?>">
                            <i class="fas fa-fw fa-file-signature me-1"></i>Add Outtake Form
                        </a>
                    </div>
                    <?php } ?>
                    <div class="card-body p-0">

                        <?php while ($ot = mysqli_fetch_assoc($sql_pending_outtakes)) {
                            $ot_id    = intval($ot['outtake_id']);
                            $ot_date  = date('M j, Y', strtotime($ot['outtake_created_at']));
                        ?>
                        <div class="border-bottom px-3 py-2 d-flex align-items-center flex-wrap" style="gap:4px;">
                            <i class="fas fa-file-signature text-secondary me-2 flex-shrink-0"></i>
                            <span class="flex-grow-1 me-2" style="min-width:0;">
                                <strong>Outtake Form</strong> <small class="text-secondary"><?= $ot_date ?></small>
                                <span class="badge text-bg-warning text-dark ms-1">Awaiting Signature</span>
                            </span>
                            <div class="d-flex flex-shrink-0" style="gap:4px;">
                                <?php if (lookupUserPermission("module_support") >= 2) { ?>
                                <button type="button" class="btn btn-xs btn-success js-sign-outtake" title="Sign now in-person"
                                    data-outtake-id="<?= $ot_id ?>" data-ticket-id="<?= $ticket_id ?>" data-client-id="<?= $client_id ?: 0 ?>">
                                    <i class="fas fa-pen-nib me-1"></i><span class="d-none d-sm-inline">Sign In-Person</span><span class="d-sm-none">Sign</span>
                                </button>
                                <?php } ?>
                                <a href="outtake_form.php?outtake_id=<?= $ot_id ?>&ticket_id=<?= $ticket_id ?><?= $client_id ? "&client_id=".$client_id : "" ?>" class="btn btn-xs btn-secondary" title="View/Edit"><i class="fas fa-edit"></i></a>
                                <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_resolved_at)) { ?>
                                <a href="post.php?delete_outtake=<?= $ot_id ?>&ticket_id=<?= $ticket_id ?>&client_id=<?= $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-xs btn-danger confirm-link" title="Delete"><i class="fas fa-trash"></i></a>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>

                        <?php if ($attachment_count == 0 && $pending_outtake_count == 0) { ?>
                            <p class="text-secondary text-center p-3 mb-0">No attachments yet.</p>
                        <?php } ?>

                        <?php while ($att = mysqli_fetch_assoc($sql_ticket_all_attachments)) {
                            $att_id   = intval($att['ticket_attachment_id']);
                            $att_name = nullable_htmlentities($att['ticket_attachment_name']);
                            $att_ref  = nullable_htmlentities($att['ticket_attachment_reference_name']);
                            $att_date = date('M j, Y', strtotime($att['ticket_attachment_created_at']));
                        ?>
                        <div class="border-bottom px-3 py-2 d-flex align-items-center">
                            <i class="fas fa-file text-secondary me-2"></i>
                            <span class="flex-grow-1">
                                <a target="_blank" href="../uploads/tickets/<?= $ticket_id ?>/<?= $att_ref ?>"><?= $att_name ?></a> <small class="text-secondary"><?= $att_date ?></small>
                            </span>
                            <div class="ms-2 dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-fw fa-ellipsis-v"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a target="_blank" class="dropdown-item" href="../uploads/tickets/<?= $ticket_id ?>/<?= $att_ref ?>">
                                        <i class="fas fa-fw fa-eye me-2"></i>View
                                    </a>
                                    <a class="dropdown-item" download="<?= $att_name ?>" href="../uploads/tickets/<?= $ticket_id ?>/<?= $att_ref ?>">
                                        <i class="fas fa-fw fa-download me-2"></i>Download
                                    </a>
                                    <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_closed_at)) { ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_ticket_attachment=<?= $att_id ?>&ticket_id=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-trash me-2"></i>Delete
                                    </a>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                        <?php } ?>

                    </div>
                </div>
                <!-- End Attachments Card -->

                <!-- Charges Card -->
                <?php if ($config_module_enable_ticket_charges && (empty($ticket_resolved_at) || !empty($charge_rows))) { ?>
                <div class="card ticket-sidebar-compact" style="order:10;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-charges" aria-expanded="false" style="cursor:pointer;">
                            <i class="fas fa-fw fa-dollar-sign me-2"></i>Charges
                            <?php if ($charge_rows) { ?>
                                <span class="badge text-bg-secondary ms-1">$<?= number_format($charges_subtotal, 2) ?></span>
                            <?php } ?>
                        </h5>
                        <div class="card-tools">
                            <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                            <a href="#" class="btn btn-tool ajax-modal"
                               data-modal-url="modals/ticket/ticket_charge_add.php?ticket_id=<?= $ticket_id ?>&client_id=<?= $client_id ?>">
                                <i class="fas fa-plus"></i>
                            </a>
                            <?php } ?>
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-charges" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                        </div>
                    </div>
                    <div class="collapse" id="sidebarBody-charges">
                    <div class="card-body p-0">
                        <?php if (empty($charge_rows)) { ?>
                            <p class="text-secondary text-center p-3 mb-0">No charges yet.</p>
                        <?php } else { ?>
                        <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>Item</th>
                                    <th class="text-end" style="white-space:nowrap;">Qty</th>
                                    <th class="text-end" style="white-space:nowrap;"><span class="d-none d-md-inline">Unit </span>Price</th>
                                    <th class="text-end" style="white-space:nowrap;">Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($charge_rows as $cr) {
                                    $cr_id    = intval($cr['charge_id']);
                                    $cr_name  = nullable_htmlentities($cr['charge_name']);
                                    $cr_desc  = nullable_htmlentities($cr['charge_description']);
                                    $cr_qty   = floatval($cr['charge_quantity']);
                                    $cr_price = floatval($cr['charge_unit_price']);
                                    $cr_total = floatval($cr['charge_total']);
                                    $cr_tax      = $cr['tax_name'] ? nullable_htmlentities($cr['tax_name']) . ' ' . floatval($cr['tax_percent']) . '%' : '';
                                    $cr_lt_name  = nullable_htmlentities($cr['labor_type_name']);
                                    $cr_lt_color = nullable_htmlentities($cr['labor_type_color'] ?? '#6c757d');
                                    $cr_invoiced = !empty($cr['charge_invoiced_at']);
                                ?>
                                <tr>
                                    <td style="min-width:120px;">
                                        <?php if ($cr_lt_name) { ?>
                                            <span class="badge rounded-pill text-white me-1" style="background:<?= $cr_lt_color ?>;"><?= $cr_lt_name ?></span>
                                        <?php } ?>
                                        <strong><?= $cr_name ?></strong>
                                        <?php if ($cr_desc) { ?><br><small class="text-muted"><?= $cr_desc ?></small><?php } ?>
                                        <?php if ($cr_tax) { ?><br><small class="text-muted"><?= $cr_tax ?></small><?php } ?>
                                    </td>
                                    <td class="text-end" style="white-space:nowrap;"><?= $cr_qty ?></td>
                                    <td class="text-end" style="white-space:nowrap;">$<?= number_format($cr_price, 2) ?></td>
                                    <td class="text-end" style="white-space:nowrap;"><strong>$<?= number_format($cr_total, 2) ?></strong></td>
                                    <td class="text-end" style="white-space:nowrap;">
                                        <?php if (!$cr_invoiced && empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                                        <a href="#" class="btn btn-xs btn-secondary ajax-modal"
                                           data-modal-url="modals/ticket/ticket_charge_edit.php?charge_id=<?= $cr_id ?>"
                                           title="Edit"><i class="fas fa-edit"></i></a>
                                        <a href="post.php?delete_ticket_charge=<?= $cr_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                           class="btn btn-xs btn-danger confirm-link ms-1"
                                           title="Delete"><i class="fas fa-trash"></i></a>
                                        <?php } elseif ($cr_invoiced) { ?>
                                        <span class="badge text-bg-success">Invoiced</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-end"><strong>Subtotal</strong></td>
                                    <td class="text-end" style="white-space:nowrap;"><strong>$<?= number_format($charges_subtotal, 2) ?></strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                        <?php } ?>
                    </div>
                    </div>
                </div>
                <?php } ?>
                <!-- End Charges Card -->

                <!-- Contact card -->
                <?php if ($contact_id) { ?>
                    <div class="card" style="order:3;">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1"><i class="fas fa-fw fa-user-check me-2"></i>Contact</h5>
                            <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                            <div class="card-tools">
                                <a class="btn btn-tool ajax-modal" href="#"
                                    data-modal-url="modals/ticket/ticket_contact.php?id=<?= $ticket_id ?>">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </div>
                            <?php } ?>
                        </div>
                        <div class="card-body p-3">

                            <div>
                                <i class="fa fa-fw fa-user text-secondary me-2"></i><a href="#" class="ajax-modal"
                                   data-modal-size="lg"
                                   data-modal-url="modals/contact/contact_details.php?id=<?= $contact_id ?>"><strong><?= $contact_name ?></strong>
                                </a>
                            </div>

                            <?php

                            if (!empty($location_name)) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-map-marker-alt text-secondary me-2"></i><?php echo $location_name; ?>
                                </div>
                            <?php }

                            if (!empty($contact_email)) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-envelope text-secondary me-2"></i><a href="mailto:<?php echo $contact_email; ?>"><?php echo $contact_email; ?></a>
                                </div>
                            <?php }

                            if (!empty($contact_phone)) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-phone text-secondary me-2"></i><a href="tel:<?php echo $contact_phone; ?>"><?php echo $contact_phone; ?></a>
                                </div>
                            <?php }

                            if (!empty($contact_mobile)) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-mobile-alt text-secondary me-2"></i><a href="tel:<?php echo $contact_mobile; ?>"><?php echo $contact_mobile; ?></a>
                                </div>
                            <?php } ?>

                        </div>
                    </div>
                <?php } ?>
                <!-- End contact card -->

                <!-- Ticket watchers card -->
                <?php if (empty($ticket_closed_at) && mysqli_num_rows($sql_ticket_watchers) > 0) { ?>

                    <div class="card ticket-sidebar-compact" style="order:5;">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-watchers" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-eye me-2"></i>Watchers</h5>
                            <div class="card-tools">
                                <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                                <a class="btn btn-tool ajax-modal" href="#" data-modal-url="modals/ticket/ticket_add_watcher.php?ticket_id=<?= $ticket_id ?>">
                                    <i class="fas fa-fw fa-user-plus"></i>
                                </a>
                                <?php } ?>
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-watchers" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                            </div>
                        </div>
                        <div class="collapse" id="sidebarBody-watchers">
                        <div class="card-body p-3">

                            <?php
                            // Get Watchers
                            while ($row = mysqli_fetch_assoc($sql_ticket_watchers)) {
                                $watcher_id = intval($row['watcher_id']);
                                $ticket_watcher_email = nullable_htmlentities($row['watcher_email']);
                                ?>
                                <div class='mt-1'>
                                    <i class="fa fa-fw fa-envelope text-secondary me-2"></i><?php echo $ticket_watcher_email; ?>
                                    <?php if (empty($ticket_closed_at)) { ?>
                                        <a class="confirm-link float-end" href="post.php?delete_ticket_watcher=<?= $watcher_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-times text-secondary"></i>
                                        </a>
                                    <?php } ?>
                                </div>

                            <?php } ?>
                        </div>
                        </div>
                    </div>
                <?php } ?>
                <!-- End Ticket watchers card -->

                <!-- Linked RMM Alerts card (Syncro-Beta) -->
                <?php
                if ($config_module_enable_rmm && lookupUserPermission("module_rmm_alerts") >= 1) {
                    $sql_linked_alerts = mysqli_query($mysqli, "SELECT * FROM rmm_alerts WHERE ticket_id = $ticket_id ORDER BY created_at DESC");
                    if (mysqli_num_rows($sql_linked_alerts) > 0):
                ?>
                <div class="card ticket-sidebar-compact mb-3" style="border-top:2px solid #17a2b8;order:13;">
                    <div class="card-header px-3 py-2">
                        <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-rmmAlerts" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-bell me-2"></i>Linked RMM Alerts</h5>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-rmmAlerts" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                        </div>
                    </div>
                    <div class="collapse" id="sidebarBody-rmmAlerts">
                    <div class="card-body p-3 small">
                        <?php while ($linked_alert = mysqli_fetch_assoc($sql_linked_alerts)):
                            $la_id = intval($linked_alert['id']);
                            $la_sev_color = ['critical'=>'danger','error'=>'danger','warning'=>'warning','info'=>'info'][$linked_alert['severity']] ?? 'secondary';
                            $la_status_color = ['new'=>'danger','acknowledged'=>'warning','resolved'=>'success'][$linked_alert['status']] ?? 'secondary';
                        ?>
                        <div class="border-bottom pb-2 mb-2" id="linked-alert-<?= $la_id ?>">
                            <div class="d-flex align-items-center mb-1">
                                <span class="badge badge-<?= $la_sev_color ?> me-1"><?= ucfirst($linked_alert['severity']) ?></span>
                                <span class="badge badge-<?= $la_status_color ?>"><?= ucfirst($linked_alert['status']) ?></span>
                                <span class="text-muted ml-auto"><?= nullable_htmlentities($linked_alert['created_at']) ?></span>
                            </div>
                            <div class="mb-1"><?= nullable_htmlentities($linked_alert['message']) ?></div>
                            <?php if ($linked_alert['status'] !== 'resolved' && lookupUserPermission('module_rmm_alerts_ack') >= 1): ?>
                            <div>
                                <?php if ($linked_alert['status'] === 'new'): ?>
                                <button class="btn btn-xs btn-outline-warning js-ticket-alert-action" data-alert-id="<?= $la_id ?>" data-alert-action="acknowledge">Acknowledge</button>
                                <?php endif; ?>
                                <button class="btn btn-xs btn-outline-success js-ticket-alert-action" data-alert-id="<?= $la_id ?>" data-alert-action="resolve">Resolve</button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    </div>
                </div>
                <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
                function ticketAlertAction(alertId, action) {
                    fetch('/agent/post/rmm_alert.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>&action=${action}&alert_id=${alertId}`
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            window.location.reload();
                        } else {
                            alert('Failed: ' + (d.error || 'Unknown error'));
                        }
                    });
                }
                document.addEventListener('click', function (e) {
                    var el = e.target.closest('.js-ticket-alert-action');
                    if (el) { ticketAlertAction(parseInt(el.dataset.alertId, 10), el.dataset.alertAction); }
                });
                </script>
                <?php
                    endif;
                }
                ?>

                <!-- Vendor card -->
                <?php if ($vendor_id) { ?>
                    <div class="card ticket-sidebar-compact mb-3" style="order:14;">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-vendor" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-building me-2"></i>Vendor</h5>
                            <div class="card-tools">
                                <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                                <a class="btn btn-tool ajax-modal" href="#" data-modal-url="modals/ticket/ticket_edit_vendor.php?ticket_id=<?= $ticket_id ?>">
                                    <i class="fas fa-fw fa-edit"></i>
                                </a>
                                <?php } ?>
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-vendor" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                            </div>
                        </div>
                        <div class="collapse" id="sidebarBody-vendor">
                        <div class="card-body p-3">

                            <div>
                                <i class="fa fa-fw fa-building text-secondary me-2"></i><strong><?php echo $vendor_name; ?></strong>
                            </div>
                            <?php

                            if (!empty($vendor_contact_name)) { ?>
                                <div class="mt-1">
                                    <i class="fa fa-fw fa-user text-secondary me-2"></i><?php echo $vendor_contact_name; ?>
                                </div>
                            <?php }

                            if (!empty($ticket_vendor_ticket_number)) { ?>
                                <div class="mt-1">
                                    <i class="fa fa-fw fa-tag text-secondary me-2"></i><?php echo $ticket_vendor_ticket_number; ?>
                                </div>
                            <?php }

                            if (!empty($vendor_email)) { ?>
                                <div class="mt-1">
                                    <i class="fa fa-fw fa-envelope text-secondary me-2"></i><a href="mailto:<?php echo $vendor_email; ?>"><?php echo $vendor_email; ?></a>
                                </div>
                            <?php }

                            if (!empty($vendor_phone)) { ?>
                                <div class="mt-1">
                                    <i class="fa fa-fw fa-phone text-secondary me-2"></i><?php echo $vendor_phone; ?>
                                </div>
                            <?php }

                            if (!empty($vendor_website)) { ?>
                                <div class="mt-1">
                                    <i class="fa fa-fw fa-globe text-secondary me-2"></i><?php echo $vendor_website; ?>
                                </div>
                            <?php } ?>

                        </div>
                        </div>
                    </div>
                <?php } //End Else ?>
                <!-- End Vendor card -->

                <!-- project card -->
                <?php if ($project_id) { ?>
                    <div class="card ticket-sidebar-compact" style="order:15;">
                        <div class="card-header px-3 py-2">
                            <h5 class="card-title mt-1" data-bs-toggle="collapse" data-bs-target="#sidebarBody-project" aria-expanded="false" style="cursor:pointer;"><i class="fas fa-fw fa-project-diagram me-2"></i>Project</h5>
                            <div class="card-tools">
                                <?php if (empty($ticket_resolved_at) && lookupUserPermission("module_support") >= 2) { ?>
                                <button type="button" class="btn btn-tool ajax-modal" data-modal-url="modals/ticket/ticket_edit_project.php?id=<?= $ticket_id ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php } ?>
                                <button type="button" class="btn btn-tool" data-bs-toggle="collapse" data-bs-target="#sidebarBody-project" aria-expanded="false"><i class="fas fa-chevron-down"></i></button>
                            </div>
                        </div>
                        <div class="collapse" id="sidebarBody-project">
                        <div class="card-body p-3">
                            <div>
                                <i class="fa fa-fw fa-project-diagram text-secondary me-2"></i><a href="project_details.php?project_id=<?php echo $project_id; ?>" target="_blank"><strong><?= $project_name ?><i class="fa fa-fw fa-external-link-alt ms-1"></i></strong>
                                </a>
                            </div>

                            <?php if ($project_manager) { ?>
                                <div class="mt-2">
                                    <i class="fa fa-fw fa-user-tie text-secondary me-2"></i><?= $project_manager_name ?>
                                </div>
                            <?php } ?>
                        </div>
                        </div>
                    </div>
                <?php } ?>
                <!-- End project card -->

            </div> <!-- End col-3 -->

        </div> <!-- End row -->

    <?php
    }
}

?>

        </div> <!-- End .ticket-alga-theme -->

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
function initWsSig(wsId, fId) {
    var c = document.getElementById('sig_c_' + wsId + '_' + fId);
    if (!c) return;
    c.width = c.offsetWidth; c.height = 80;
    var ctx = c.getContext('2d'), drawing = false, lx, ly;
    function pos(e) { var r = c.getBoundingClientRect(), s = e.touches ? e.touches[0] : e; return {x:(s.clientX-r.left)*(c.width/r.width), y:(s.clientY-r.top)*(c.height/r.height)}; }
    c.addEventListener('mousedown', function(e){drawing=true; var p=pos(e); lx=p.x; ly=p.y;});
    c.addEventListener('mousemove', function(e){if(!drawing)return; var p=pos(e); ctx.beginPath(); ctx.moveTo(lx,ly); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#000'; ctx.lineWidth=2; ctx.lineCap='round'; ctx.stroke(); lx=p.x; ly=p.y; saveSig(wsId,fId);});
    c.addEventListener('mouseup', function(){drawing=false;});
    c.addEventListener('touchstart', function(e){e.preventDefault(); drawing=true; var p=pos(e); lx=p.x; ly=p.y;}, {passive:false});
    c.addEventListener('touchmove', function(e){e.preventDefault(); if(!drawing)return; var p=pos(e); ctx.beginPath(); ctx.moveTo(lx,ly); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#000'; ctx.lineWidth=2; ctx.lineCap='round'; ctx.stroke(); lx=p.x; ly=p.y; saveSig(wsId,fId);}, {passive:false});
    c.addEventListener('touchend', function(){drawing=false;});
}
function saveSig(wsId, fId) { var c=document.getElementById('sig_c_'+wsId+'_'+fId); if(c) document.getElementById('sig_d_'+wsId+'_'+fId).value=c.toDataURL(); }
function clearWsSig(wsId, fId) { var c=document.getElementById('sig_c_'+wsId+'_'+fId); if(c){c.getContext('2d').clearRect(0,0,c.width,c.height); document.getElementById('sig_d_'+wsId+'_'+fId).value='';} }
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('canvas[id^="sig_c_"]').forEach(function(c) {
        var parts = c.id.split('_'); initWsSig(parts[2], parts[3]);
    });
});
document.addEventListener('click', function (e) {
    var clearBtn = e.target.closest('.js-clear-ws-sig');
    if (clearBtn) { clearWsSig(clearBtn.dataset.wsId, clearBtn.dataset.fieldId); return; }

    var header = e.target.closest('.js-worksheet-toggle');
    if (header && !e.target.closest('a, button')) {
        var targetEl = document.querySelector(header.getAttribute('data-bs-target'));
        if (targetEl) { bootstrap.Collapse.getOrCreateInstance(targetEl).toggle(); }
    }
});
</script>
<?php
require_once "../includes/footer.php";

?>

<script src="/js/show_modals.js"></script>

<!-- Outtake form creation + in-person signing -->
<script src="/js/signature_pad.js"></script>
<script src="/js/outtake.js"></script>

<?php if (empty($ticket_closed_at)) { ?>
    <!-- create js variable related to ticket timer setting -->
    <script type="text/javascript" nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
        var ticketAutoStart = <?php echo json_encode($config_ticket_timer_autostart); ?>;
    </script>

    <!-- Ticket Time Tracking JS -->
    <script src="js/ticket_time_tracking.js"></script>

    <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    // Warn when "Charge now" is checked + time logged but no labor type selected
    $('#ticketReplyForm').on('submit', function (e) {
        var chargeNow  = $('#reply_charge_now').is(':checked');
        var hasTime    = (parseInt($('#hours').val()) || 0) > 0
                      || (parseInt($('#minutes').val()) || 0) > 0
                      || (parseInt($('#seconds').val()) || 0) > 0;
        var laborType  = parseInt($('#reply_labor_type_id').val()) || 0;
        if (chargeNow && hasTime && laborType === 0) {
            if (!confirm('No labor type selected — time will be logged but no charge will be created.\n\nContinue anyway?')) {
                e.preventDefault();
            }
        }
    });
    </script>

    <!-- Ticket collision detect JS (jQuery is called in footer, so collision detection script MUST be below it) -->
    <script src="js/ticket_collision_detection.js"></script>
<?php } ?>

<!-- Live ticket updates (replies/status/chat via SSE) -->
<script src="/js/live_ticket.js"></script>

<script src="/js/pretty_content.js"></script>

<script src="/plugins/SortableJS/Sortable.min.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
var _tasksList = document.querySelector('#tasks');
if (_tasksList) new Sortable(_tasksList, {
    handle: '.drag-handle',
    animation: 150,
    onEnd: function (evt) {
        const rows = document.querySelectorAll('#tasks > li');
        const positions = Array.from(rows).map((row, index) => ({
            id: row.dataset.taskId,
            order: index
        }));

        $.post('ajax.php', {
            update_ticket_tasks_order: true,
            csrf_token: '<?= $_SESSION['csrf_token'] ?>',
            ticket_id: <?php echo $ticket_id; ?>,
            positions: positions
        });
    }
});
</script>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// Comment tabs filter
(function() {
    var clientTypes = ['Public', 'Client'];
    var internalTypes = ['Internal'];
    var systemTypes = ['System', 'Automation', 'RMM Alert', 'Labor'];

    $('#commentTabs .nav-link').on('click', function(e) {
        e.preventDefault();
        var filter = $(this).data('comment-filter');

        $('#commentTabs .nav-link').removeClass('active');
        $(this).addClass('active');

        $('[data-reply-type]').each(function() {
            var type = $(this).data('reply-type');
            var show = true;
            if (filter === 'client') show = clientTypes.includes(type);
            else if (filter === 'internal') show = internalTypes.includes(type);
            else if (filter === 'system') show = systemTypes.includes(type);
            $(this).toggle(show);
        });
    });
})();
</script>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// Ticket reply draft autosave
(function() {
    var draftKey = 'ticket_draft_<?= $ticket_id ?>';
    var $banner  = $('#ticket-reply-draft-banner');
    var saved    = localStorage.getItem(draftKey);

    // Show restore banner if draft exists
    if (saved && saved.trim() !== '' && saved.trim() !== '<p></p>') {
        $banner.css('display', 'flex');
        $('#draft-restore-btn').on('click', function() {
            tinymce.get('ticket-reply-editor').setContent(saved);
            $banner.hide();
        });
        $('#draft-discard-btn').on('click', function() {
            localStorage.removeItem(draftKey);
            $banner.hide();
        });
    }

    // Save every 3 seconds if content changed
    var lastSaved = '';
    setInterval(function() {
        var ed = tinymce.get('ticket-reply-editor');
        if (!ed) return;
        var content = ed.getContent();
        if (content === lastSaved) return;
        lastSaved = content;
        if (content.trim() !== '' && content.trim() !== '<p></p>') {
            localStorage.setItem(draftKey, content);
        } else {
            localStorage.removeItem(draftKey);
        }
    }, 3000);

    // Clear on submit
    $('form').on('submit', function() {
        localStorage.removeItem(draftKey);
    });
})();

// Insert canned response into the reply editor
$(document).on('click', '.insert-canned-response', function(e) {
    e.preventDefault();
    var ed = tinymce.get('ticket-reply-editor');
    if (!ed) return;
    var message = JSON.parse($(this).attr('data-message'));
    ed.execCommand('mceInsertContent', false, message);
});

// Draft a reply with AI. Fetches a draft from the server and fills the reply
// editor with it. Never submits the form; the agent always reviews first.
$(document).on('click', '#ai-draft-reply-btn', function(e) {
    e.preventDefault();
    var $btn   = $(this);
    var $icon  = $('#ai-draft-reply-icon');
    var $label = $('#ai-draft-reply-label');
    var $error = $('#ai-draft-reply-error');
    var ticketId = $btn.data('ticket-id');

    if ($btn.prop('disabled')) return;

    // Convert plain-text draft into safe HTML for the rich-text editor.
    function aiTextToHtml(text) {
        var esc = $('<div>').text(text || '').html();
        return esc.split(/\n{2,}/).map(function(block) {
            return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    function showError(msg) {
        $error.text(msg).css('display', 'inline');
        setTimeout(function() { $error.fadeOut(400); }, 6000);
    }

    // Spinner + disabled state while drafting.
    $error.hide().text('');
    $icon.removeClass('fa-magic').addClass('fa-spinner fa-spin');
    $label.text('Drafting…');
    $btn.prop('disabled', true);

    function done() {
        $icon.removeClass('fa-spinner fa-spin').addClass('fa-magic');
        $label.text('Draft with AI');
        $btn.prop('disabled', false);
    }

    fetch('ajax.php?ai_ticket_reply_draft', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            ticket_id: ticketId,
            csrf_token: '<?= $_SESSION['csrf_token'] ?>'
        })
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
        done();
        if (data && data.ok && data.draft && data.draft.trim() !== '') {
            var ed = tinymce.get('ticket-reply-editor');
            if (ed) {
                ed.setContent(aiTextToHtml(data.draft));
                ed.fire('change'); // triggers draft autosave
            }
        } else {
            showError((data && data.error) ? data.error : 'Could not generate a draft.');
        }
    })
    .catch(function() {
        done();
        showError('Could not generate a draft. Please try again.');
    });
});

// Reply status split-button: pick a status and auto-submit the reply form
$(document).on('click', '.reply-status-submit', function(e) {
    e.preventDefault();
    var sid   = $(this).data('status-id');
    var sname = $(this).data('status-name');
    $('#reply_status_val').val(sid);
    $('#reply_submit_label').text('Submit & ' + sname);
    $('#ticketReplyForm').find('[name="add_ticket_reply"]').click();
});

// Inline quick-status on ticket detail
$(document).on('change', '#quickStatusSelect', function() {
    var $sel = $(this);
    var ticketId = $sel.data('ticket-id');
    var csrf = $sel.data('csrf');
    var statusId = $sel.val();
    var color = $sel.find('option:selected').data('color');
    var $status = $('#quickStatusStatus');
    $status.html('<i class="fas fa-spinner fa-spin text-muted"></i>');
    $.post('post.php', {
        quick_status_ticket: 1,
        ticket_id: ticketId,
        ticket_status_id: statusId,
        csrf_token: csrf
    }, function(res) {
        if (res.ok) {
            $sel.css('background-color', color);
            $status.html('<i class="fas fa-check text-success"></i>');
            setTimeout(function() { $status.html(''); }, 2000);
        } else {
            $status.html('<i class="fas fa-times text-danger"></i>');
        }
    }, 'json').fail(function() {
        $status.html('<i class="fas fa-times text-danger"></i>');
    });
});

// Inline quick-assign on ticket detail
$(document).on('change', '#quickAssignSelect', function() {
    var $sel = $(this);
    var ticketId = $sel.data('ticket-id');
    var csrf = $sel.data('csrf');
    var assignedTo = $sel.val();
    var $status = $('#quickAssignStatus');
    $status.html('<i class="fas fa-spinner fa-spin text-muted"></i>');
    $.post('post.php', {
        quick_assign_ticket: 1,
        ticket_id: ticketId,
        assigned_to: assignedTo,
        csrf_token: csrf
    }, function(res) {
        if (res.ok) {
            $status.html('<i class="fas fa-check text-success"></i>');
            setTimeout(function() { $status.html(''); }, 2000);
        } else {
            $status.html('<i class="fas fa-times text-danger"></i>');
        }
    }, 'json').fail(function() {
        $status.html('<i class="fas fa-times text-danger"></i>');
    });
});
</script>
