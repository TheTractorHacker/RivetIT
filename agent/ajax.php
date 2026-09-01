<?php

/*
 * ajax.php
 * Similar to post.php, but for requests using Asynchronous JavaScript
 * Always returns data in JSON format, unless otherwise specified
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
require_once "../plugins/totp/totp.php";

/*
 * Live global-search dropdown (top nav). Read-only GET, no CSRF needed (mirrors
 * certificate_fetch_parse_json_details below). Auth already enforced by check_login.php above.
 * Mirrors global_search.php's own queries/scoping for clients, contacts, tickets, quotes,
 * invoices, and assets - a fast LIMIT-5-per-type subset for per-keystroke use. Documents,
 * files, credentials, vendors, domains, products, recurring_tickets, and ticket_replies are
 * intentionally out of scope here (kept cheap, and avoid decrypting credentials on every
 * keystroke); the full, unabridged search remains at global_search.php.
 */
if (isset($_GET['global_search_live'])) {
    header('Content-Type: application/json');

    $raw_query = trim((string) ($_GET['q'] ?? ''));

    if (mb_strlen($raw_query) < 2) {
        echo json_encode(['ok' => true, 'groups' => new stdClass()]);
        exit;
    }

    $query = sanitizeInput($raw_query); // strip_tags + trim + mysqli_real_escape_string, same as global_search.php
    $phone_query = preg_replace("/[^0-9]/", '', $query);
    if (empty($phone_query)) {
        $phone_query = $query;
    }
    $ticket_num_query = str_replace("$config_ticket_prefix", "", "$query");

    $groups = [];

    // Clients
    $sql = mysqli_query($mysqli, "SELECT clients.client_id, client_name, client_abbreviation
        FROM clients
        WHERE client_archived_at IS NULL
            AND (client_name LIKE '%$query%' OR client_abbreviation LIKE '%$query%')
            $access_permission_query
        ORDER BY client_id DESC LIMIT 5"
    );
    $rows = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $rows[] = [
            'title' => $row['client_name'],
            'subtitle' => $row['client_abbreviation'],
            'url' => '/agent/client_overview.php?client_id=' . intval($row['client_id']),
        ];
    }
    if ($rows) { $groups['clients'] = $rows; }

    // Contacts
    $sql = mysqli_query($mysqli, "SELECT contacts.contact_id, contact_name, contact_title, contact_email, contact_phone, clients.client_id, client_name
        FROM contacts
        LEFT JOIN clients ON client_id = contact_client_id
        WHERE contact_archived_at IS NULL
            AND (contact_name LIKE '%$query%'
            OR contact_title LIKE '%$query%'
            OR contact_email LIKE '%$query%'
            OR contact_phone LIKE '%$phone_query%'
            OR contact_mobile LIKE '%$phone_query%')
            $access_permission_query
        ORDER BY contact_id DESC LIMIT 5"
    );
    $rows = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $subtitle = (string) $row['client_name'];
        if (!empty($row['contact_email'])) {
            $subtitle .= ' · ' . $row['contact_email'];
        } elseif (!empty($row['contact_phone'])) {
            $subtitle .= ' · ' . $row['contact_phone'];
        }
        $rows[] = [
            'title' => $row['contact_name'],
            'subtitle' => $subtitle,
            'url' => '/agent/contact_details.php?client_id=' . intval($row['client_id']) . '&contact_id=' . intval($row['contact_id']),
        ];
    }
    if ($rows) { $groups['contacts'] = $rows; }

    // Tickets
    $sql = mysqli_query($mysqli, "SELECT tickets.ticket_id, tickets.ticket_client_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, client_name
        FROM tickets
        LEFT JOIN clients on tickets.ticket_client_id = clients.client_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_archived_at IS NULL
            AND (ticket_subject LIKE '%$query%'
            OR ticket_details LIKE '%$query%'
            OR CONCAT(ticket_prefix,ticket_number) LIKE '%$query%'
            OR ticket_number = '$ticket_num_query')
            $access_permission_query
        ORDER BY ticket_id DESC LIMIT 5"
    );
    $rows = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $subtitle = (string) $row['client_name'];
        if (!empty($row['ticket_status_name'])) {
            $subtitle .= ' · ' . $row['ticket_status_name'];
        }
        $rows[] = [
            'title' => $row['ticket_prefix'] . $row['ticket_number'] . ' — ' . mb_substr((string) $row['ticket_subject'], 0, 80),
            'subtitle' => $subtitle,
            'url' => '/agent/ticket.php?client_id=' . intval($row['ticket_client_id']) . '&ticket_id=' . intval($row['ticket_id']),
        ];
    }
    if ($rows) { $groups['tickets'] = $rows; }

    // Quotes
    $sql = mysqli_query($mysqli, "SELECT quotes.quote_id, quote_prefix, quote_number, quote_status, clients.client_id, client_name
        FROM quotes
        LEFT JOIN clients ON quote_client_id = client_id
        WHERE quote_archived_at IS NULL
            AND (CONCAT(quote_prefix,quote_number) LIKE '%$query%' OR quote_number LIKE '%$query%' OR quote_scope LIKE '%$query%')
            $access_permission_query
        ORDER BY quote_number DESC LIMIT 5"
    );
    $rows = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $subtitle = (string) $row['client_name'];
        if (!empty($row['quote_status'])) {
            $subtitle .= ' · ' . $row['quote_status'];
        }
        $rows[] = [
            'title' => $row['quote_prefix'] . $row['quote_number'],
            'subtitle' => $subtitle,
            'url' => '/agent/quote.php?client_id=' . intval($row['client_id']) . '&quote_id=' . intval($row['quote_id']),
        ];
    }
    if ($rows) { $groups['quotes'] = $rows; }

    // Invoices
    $sql = mysqli_query($mysqli, "SELECT invoices.invoice_id, invoice_prefix, invoice_number, invoice_status, clients.client_id, client_name
        FROM invoices
        LEFT JOIN clients ON invoice_client_id = client_id
        WHERE invoice_archived_at IS NULL
            AND (CONCAT(invoice_prefix,invoice_number) LIKE '%$query%' OR invoice_number LIKE '%$query%' OR invoice_scope LIKE '%$query%')
            $access_permission_query
        ORDER BY invoice_number DESC LIMIT 5"
    );
    $rows = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $subtitle = (string) $row['client_name'];
        if (!empty($row['invoice_status'])) {
            $subtitle .= ' · ' . $row['invoice_status'];
        }
        $rows[] = [
            'title' => $row['invoice_prefix'] . $row['invoice_number'],
            'subtitle' => $subtitle,
            'url' => '/agent/invoice.php?client_id=' . intval($row['client_id']) . '&invoice_id=' . intval($row['invoice_id']),
        ];
    }
    if ($rows) { $groups['invoices'] = $rows; }

    // Assets
    $sql = mysqli_query($mysqli, "SELECT assets.asset_id, asset_name, asset_type, clients.client_id, client_name
        FROM assets
        LEFT JOIN clients ON asset_client_id = client_id
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_archived_at IS NULL
            AND (asset_name LIKE '%$query%' OR asset_description LIKE '%$query%' OR asset_type LIKE '%$query%' OR asset_make LIKE '%$query%' OR asset_model LIKE '%$query%' OR asset_serial LIKE '%$query%' OR asset_os LIKE '%$query%' OR interface_ip LIKE '%$query%' OR interface_nat_ip LIKE '%$query%' OR interface_mac LIKE '%$query%' OR asset_status LIKE '%$query%')
            $access_permission_query
        ORDER BY asset_name DESC LIMIT 5"
    );
    $rows = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $subtitle = (string) $row['client_name'];
        if (!empty($row['asset_type'])) {
            $subtitle .= ' · ' . $row['asset_type'];
        }
        $rows[] = [
            'title' => $row['asset_name'],
            'subtitle' => $subtitle,
            'url' => '/agent/asset_details.php?client_id=' . intval($row['client_id']) . '&asset_id=' . intval($row['asset_id']),
        ];
    }
    if ($rows) { $groups['assets'] = $rows; }

    echo json_encode(['ok' => true, 'groups' => $groups]);
    exit;
}

/*
 * Fetches SSL certificates from remote hosts & returns the relevant info (issuer, expiry, public key)
 */
if (isset($_GET['certificate_fetch_parse_json_details'])) {
    enforceUserPermission('module_support');

    // PHP doesn't appreciate attempting SSL sockets to non-existent domains
    if (empty($_GET['domain'])) {
        exit();
    }

    $name = $_GET['domain'];

    // Get SSL cert for domain (if exists)
    $certificate = getSSL($name);

    if ($certificate['success'] == "TRUE") {
        $response['success'] = "TRUE";
        $response['expire'] = $certificate['expire'];
        $response['issued_by'] = $certificate['issued_by'];
        $response['public_key'] = $certificate['public_key'];
    } else {
        $response['success'] = "FALSE";
    }

    echo json_encode($response);

}

if (isset($_POST['client_set_notes'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    $client_id = intval($_POST['client_id']);
    $notes = sanitizeInput($_POST['notes']);

    // Update notes
    mysqli_query($mysqli, "UPDATE clients SET client_notes = '$notes' WHERE client_id = $client_id");

    // Logging
    logAction("Client", "Edit", "$session_name edited client notes", $client_id);

}

if (isset($_POST['contact_set_notes'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_client', 2);

    $contact_id = intval($_POST['contact_id']);
    $notes = sanitizeInput($_POST['notes']);

    // Get Contact Details and Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT contact_name, contact_client_id
        FROM contacts WHERE contact_id = $contact_id"
    );
    $row = mysqli_fetch_assoc($sql);
    $contact_name = sanitizeInput($row['contact_name']);
    $client_id = intval($row['contact_client_id']);

    // Update notes
    mysqli_query($mysqli, "UPDATE contacts SET contact_notes = '$notes' WHERE contact_id = $contact_id");

    // Logging
    logAction("Contact", "Edit", "$session_name edited contact notes for $contact_name", $client_id, $contact_id);

}

if (isset($_POST['asset_set_notes'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $asset_id = intval($_POST['asset_id']);
    $notes = sanitizeInput($_POST['notes']);

    // Get Asset Details and Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT asset_name, asset_client_id
        FROM assets WHERE asset_id = $asset_id"
    );
    $row = mysqli_fetch_assoc($sql);
    $asset_name = sanitizeInput($row['asset_name']);
    $client_id = intval($row['asset_client_id']);

    // Update notes
    mysqli_query($mysqli, "UPDATE assets SET asset_notes = '$notes' WHERE asset_id = $asset_id");

    // Logging
    logAction("Asset", "Edit", "$session_name edited asset notes for $asset_name", $client_id, $asset_id);

}

/*
 * Ticketing Collision Detection/Avoidance
 * Called upon loading a ticket, and every 2 mins thereafter
 * Is used in conjunction with ticket_query_views to show who is currently viewing a ticket
 */
if (isset($_GET['ticket_add_view'])) {
    $ticket_id = intval($_GET['ticket_id']);

    $client_query = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id"));
    $client_id = intval($client_query['ticket_client_id'] ?? 0);
    if ($client_id) { enforceClientAccess(); }

    mysqli_query($mysqli, "INSERT INTO ticket_views SET view_ticket_id = $ticket_id, view_user_id = $session_user_id, view_timestamp = NOW()");
}

/*
 * Ticketing Collision Detection/Avoidance
 * Returns formatted text of the agents currently viewing a ticket
 * Called upon loading a ticket, and every 2 mins thereafter
 */
if (isset($_GET['ticket_query_views'])) {
    $ticket_id = intval($_GET['ticket_id']);

    $client_query = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id"));
    $client_id = intval($client_query['ticket_client_id'] ?? 0);
    if ($client_id) { enforceClientAccess(); }

    $query = mysqli_query($mysqli, "SELECT user_name FROM ticket_views LEFT JOIN users ON view_user_id = user_id WHERE view_ticket_id = $ticket_id AND view_user_id != $session_user_id AND view_timestamp > DATE_SUB(NOW(), INTERVAL 2 MINUTE)");
    while ($row = mysqli_fetch_assoc($query)) {
        $users[] = $row['user_name'];
    }

    if (!empty($users)) {
        $users = array_unique($users);
        if (count($users) > 1) {
            // Multiple viewers
            $response['message'] = "<i class='fas fa-fw fa-eye me-2'></i>" . nullable_htmlentities(implode(", ", $users) . " are viewing this ticket.");
        } else {
            // Single viewer
            $response['message'] = "<i class='fas fa-fw fa-eye me-2'></i>" . nullable_htmlentities(implode("", $users) . " is viewing this ticket.");
        }
    } else {
        // No viewers
        $response['message'] = "";
    }

    echo json_encode($response);
}

/*
 * Generates public/guest links for sharing credentials/docs
 */
if (isset($_GET['share_generate_link'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $item_encrypted_username = '';  // Default empty
    $item_encrypted_credential = '';  // Default empty

    $client_id = intval($_GET['client_id']);

    enforceClientAccess();

    $item_type = sanitizeInput($_GET['type']);
    $item_id = intval($_GET['id']);
    $item_email = sanitizeInput($_GET['contact_email']);
    $item_note = sanitizeInput($_GET['note']);
    $item_view_limit = intval($_GET['views']);
    $item_view_limit_wording = "";
    if ($item_view_limit == 1) {
        $item_view_limit_wording = " and may only be viewed <strong>once</strong>, before the link is destroyed.";
    }
    $item_expires_raw = sanitizeInput($_GET['expires']);
    $allowed_expires = ["1 HOUR" => "1 hour", "24 HOUR" => "1 day", "168 HOUR" => "1 week", "730 HOUR" => "1 month"];
    $item_expires = isset($allowed_expires[$item_expires_raw]) ? $item_expires_raw : null;
    $item_expires_friendly = $item_expires ? $allowed_expires[$item_expires] : "never";

    $item_key = randomString(32);

    if ($item_type == "Document") {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT document_name FROM documents WHERE document_id = $item_id AND document_client_id = $client_id LIMIT 1"));
        $item_name = sanitizeInput($row['document_name']);
    }

    if ($item_type == "File") {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT file_name FROM files WHERE file_id = $item_id AND file_client_id = $client_id LIMIT 1"));
        $item_name = sanitizeInput($row['file_name']);
    }

    if ($item_type == "Credential") {
        $credential = mysqli_query($mysqli, "SELECT credential_name, credential_username, credential_password FROM credentials WHERE credential_id = $item_id AND credential_client_id = $client_id LIMIT 1");
        $row = mysqli_fetch_assoc($credential);

        $item_name = sanitizeInput($row['credential_name']);

        // Decrypt & re-encrypt username/password for sharing
        $credential_encryption_key = randomString();

        $credential_username_cleartext = decryptCredentialEntry($row['credential_username']);
        $iv = randomString();
        $username_ciphertext = openssl_encrypt($credential_username_cleartext, 'aes-128-cbc', $credential_encryption_key, 0, $iv);
        $item_encrypted_username = $iv . $username_ciphertext;

        $credential_password_cleartext = decryptCredentialEntry($row['credential_password']);
        $iv = randomString();
        $password_ciphertext = openssl_encrypt($credential_password_cleartext, 'aes-128-cbc', $credential_encryption_key, 0, $iv);
        $item_encrypted_credential = $iv . $password_ciphertext;

        $otp_plain = decryptOtpSecret($row['credential_otp_secret'] ?? '');
        $item_encrypted_otp = '';
        if (!empty($otp_plain)) {
            $iv_otp = randomString();
            $otp_ct = openssl_encrypt($otp_plain, 'aes-128-cbc', $credential_encryption_key, 0, $iv_otp);
            $item_encrypted_otp = $iv_otp . $otp_ct;
        }
    }

    // Insert entry into DB
    $expire_sql = $item_expires ? "NOW() + INTERVAL $item_expires" : "NULL";
    $sql = mysqli_query($mysqli, "INSERT INTO shared_items SET item_active = 1, item_key = '$item_key', item_type = '$item_type', item_related_id = $item_id, item_encrypted_username = '$item_encrypted_username', item_encrypted_credential = '$item_encrypted_credential', item_encrypted_otp = '$item_encrypted_otp', item_note = '$item_note', item_recipient = '$item_email', item_views = 0, item_view_limit = $item_view_limit, item_expire_at = $expire_sql, item_client_id = $client_id");
    $share_id = $mysqli->insert_id;

    // Return URL
    if ($item_type == "Credential") {
        // 'ek' is intentionally placed in the URL FRAGMENT, not the query string.
        // Fragments are never sent to the server (not in access logs, not in Referer
        // headers) and don't persist in server-side history the way a query param does
        // - only browser-local history retains it. guest_view_item.php reads it
        // client-side via window.location.hash and decrypts the credential in-browser.
        $url = "https://$config_base_url/guest/guest_view_item.php?id=$share_id&key=$item_key#ek=$credential_encryption_key";
    }
    else {
        $url = "https://$config_base_url/guest/guest_view_item.php?id=$share_id&key=$item_key";
    }

    $sql = mysqli_query($mysqli,"SELECT * FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $company_name = sanitizeInput($row['company_name']);
    $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

    // Sanitize Config vars from get_settings.php
    $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
    $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
    $config_mail_from_name = sanitizeInput($config_mail_from_name);
    $config_mail_from_email = sanitizeInput($config_mail_from_email);

    // Send user e-mail, if specified
    if(!empty($config_smtp_host) && filter_var($item_email, FILTER_VALIDATE_EMAIL)){

        $subject = "Time sensitive - $company_name secure link enclosed";
        if ($item_expires_friendly == "never") {
            $subject = "$company_name secure link enclosed";
        }
        $body = "Hello,<br><br>$session_name from $company_name sent you a time sensitive secure link regarding \"$item_name\".<br><br>The link will expire in <strong>$item_expires_friendly</strong>$item_view_limit_wording.<br><br><strong><a href=\'$url\'>Click here to access your secure content</a></strong><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

        // Add the intended recipient disclosure
        $body .= "<br><br><em>This email and any attachments are confidential and intended for the specified recipient(s) only. If you are not the intended recipient, please notify the sender and delete this email. Unauthorized use, disclosure, or distribution is prohibited.</em>";

        $data = [
            [
                'from' => $config_mail_from_email,
                'from_name' => $config_mail_from_name,
                'recipient' => $item_email,
                'recipient_name' => $item_email,
                'subject' => $subject,
                'body' => $body
            ]
        ];

        addToMailQueue($data);

    }

    echo json_encode($url);

    // Logging
    logAction("Share", "Create", "$session_name created shared link for $item_type - $item_name", $client_id, $item_id);

}

/*
 * Returns sorted list of active clients
 */
if (isset($_GET['get_active_clients'])) {
    enforceUserPermission('module_client');

    $client_sql = mysqli_query(
        $mysqli,
        "SELECT client_id, client_name FROM clients
        WHERE client_archived_at IS NULL
        $access_permission_query
        ORDER BY client_accessed_at DESC"
    );

    while ($row = mysqli_fetch_assoc($client_sql)) {
        $response['clients'][] = $row;
    }

    echo json_encode($response);
}

/*
 * Returns ordered list of active contacts for a specified client
 */
if (isset($_GET['get_client_contacts'])) {
    enforceUserPermission('module_client');

    $client_id = intval($_GET['client_id']);

    $contact_sql = mysqli_query(
        $mysqli,
        "SELECT contact_id, contact_name, contact_primary, contact_important, contact_technical FROM contacts
        LEFT JOIN clients on contact_client_id = client_id
        WHERE contacts.contact_archived_at IS NULL AND contact_client_id = $client_id
        $access_permission_query
        ORDER BY contact_primary DESC, contact_technical DESC, contact_important DESC, contact_name"
    );

    while ($row = mysqli_fetch_assoc($contact_sql)) {
        $response['contacts'][] = $row;
    }

    echo json_encode($response);
}

/*
 * Returns ordered list of active assets for a specified client
 */
if (isset($_GET['get_client_assets'])) {
    enforceUserPermission('module_client');

    $client_id = intval($_GET['client_id']);

    $asset_sql = mysqli_query(
        $mysqli,
        "SELECT asset_id, asset_name, contact_name FROM assets
        LEFT JOIN clients on asset_client_id = client_id
        LEFT JOIN contacts ON contact_id = asset_contact_id
        WHERE assets.asset_archived_at IS NULL AND asset_client_id = $client_id
        $access_permission_query
        ORDER BY asset_favorite DESC, asset_name"
    );

    while ($row = mysqli_fetch_assoc($asset_sql)) {
        $response['assets'][] = $row;
    }

    echo json_encode($response);
}

/*
 * Returns locations for a specified client
 */
if (isset($_GET['get_client_locations'])) {
    enforceUserPermission('module_client');

    $client_id = intval($_GET['client_id']);

    $locations_sql = mysqli_query(
        $mysqli,
        "SELECT location_id, location_name FROM locations
        LEFT JOIN clients on location_client_id = client_id
        WHERE locations.location_archived_at IS NULL AND location_client_id = $client_id
        $access_permission_query
        ORDER BY location_primary DESC, location_name ASC"
    );

    while ($row = mysqli_fetch_assoc($locations_sql)) {
        $response['locations'][] = $row;
    }

    echo json_encode($response);
}

/*
 * Returns ordered list of vendors for a specified client
 */
if (isset($_GET['get_client_vendors'])) {
    enforceUserPermission('module_client');

    $client_id = intval($_GET['client_id']);

    $vendors_sql = mysqli_query(
        $mysqli,
        "SELECT vendor_id, vendor_name FROM vendors
        LEFT JOIN clients on vendor_client_id = client_id
        WHERE vendors.vendor_archived_at IS NULL AND vendor_client_id = $client_id
        $access_permission_query
        ORDER BY vendor_name ASC"
    );

    while ($row = mysqli_fetch_assoc($vendors_sql)) {
        $response['vendors'][] = $row;
    }

    echo json_encode($response);
}

/*
 * NEW TOTP getter for client login/passwords page
 * When provided with a login ID, checks permissions and returns the 6-digit code
 */
if (isset($_GET['get_totp_token_via_id'])) {
    enforceUserPermission('module_credential');

    $credential_id = intval($_GET['credential_id']);

    $sql = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT credential_name, credential_otp_secret, credential_client_id FROM credentials WHERE credential_id = $credential_id"));
    $name = sanitizeInput($sql['credential_name']);
    $totp_secret_raw = $sql['credential_otp_secret'];
    $totp_secret = decryptOtpSecret($totp_secret_raw);
    $client_id = intval($sql['credential_client_id']);

    enforceClientAccess();

    $otp = TokenAuth6238::getTokenCode(strtoupper($totp_secret));
    echo json_encode($otp);

    // Logging
    //  Only log the TOTP view if the user hasn't already viewed this specific login entry recently, this prevents logs filling if a user hovers across an entry a few times
    $check_recent_totp_view_logged_sql = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(log_id) AS recent_totp_view FROM logs WHERE log_type = 'Credential' AND log_action = 'View TOTP' AND log_user_id = $session_user_id AND log_entity_id = $credential_id AND log_client_id = $client_id AND log_created_at > (NOW() - INTERVAL 5 MINUTE)"));
    $recent_totp_view_logged_count = intval($check_recent_totp_view_logged_sql['recent_totp_view']);

    if ($recent_totp_view_logged_count == 0) {
        // Logging
        logAction("Credential", "View TOTP", "$session_name viewed credential TOTP code for $name", $client_id, $credential_id);

    }
}

if (isset($_GET['get_readable_pass'])) {
    echo json_encode(GenerateReadablePassword(1));
}

/*
 * ITFlow - POST request handler for client tickets
 */
if (isset($_POST['update_kanban_status_position'])) {
    // Update multiple ticket status kanban orders
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $positions = $_POST['positions'];

    foreach ($positions as $position) {
        $status_id = intval($position['status_id']);
        $kanban = intval($position['status_kanban']);

        mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_order = $kanban WHERE ticket_status_id = $status_id");
    }

    // return a response
    echo json_encode(['status' => 'success']);
    exit;
}

/*
 * ITFlow - CRM pipeline: persist an opportunity's stage after a kanban drag
 */
if (isset($_POST['update_opportunity_stage'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $opportunity_id = intval($_POST['opportunity_id'] ?? 0);
    $stage = sanitizeInput($_POST['stage'] ?? '');

    // Validate stage against the known set
    $stages = getOpportunityStages();
    if (!isset($stages[$stage])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid stage']);
        exit;
    }

    // Client access check when the opportunity is tied to a client
    $opp = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT opportunity_client_id FROM opportunities WHERE opportunity_id = $opportunity_id"));
    if (!$opp) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Not found']);
        exit;
    }
    $client_id = intval($opp['opportunity_client_id']);
    if ($client_id) {
        enforceClientAccess();
    }

    $status = opportunityStatusForStage($stage);

    mysqli_query(
        $mysqli,
        "UPDATE opportunities SET
            opportunity_stage = '$stage',
            opportunity_status = '$status',
            opportunity_updated_at = NOW()
        WHERE opportunity_id = $opportunity_id"
    );

    logAction("Opportunity", "Edit", "$session_name moved opportunity to stage $stage", $client_id, $opportunity_id);

    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['update_kanban_ticket'])) {
    // Update ticket kanban order and status
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    // all tickets on the column
    $positions = $_POST['positions'];

    foreach ($positions as $position) {
        $ticket_id = intval($position['ticket_id']);

        // Client perms check
        $client_query = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id"));
        $client_id = intval($client_query['ticket_client_id']);
        enforceClientAccess();

        $kanban = intval($position['ticket_order']); // ticket kanban position
        $status = intval($position['ticket_status']); // ticket statuses

        // ticket_oldStatus is JS `false` for every card except the one actually
        // dragged (see tickets_kanban.js) - intval(false) and intval("false") both
        // come out 0, indistinguishable from a real status id, so detect "not moved"
        // from the raw value before casting, not after.
        $oldStatusRaw = $position['ticket_oldStatus'] ?? false;
        $ticketWasMoved = !($oldStatusRaw === false || $oldStatusRaw === 'false' || $oldStatusRaw === '');
        $oldStatus = $ticketWasMoved ? intval($oldStatusRaw) : false;

        $statuses['Closed'] = 5;
        $statuses['Resolved'] = 4;

        // Continue if status is null / Closed
        if ($status === null || $status === $statuses['Closed']) {
            continue;
        }


        if ($oldStatus === false) {
            // if ticket was not moved, just uptdate the order on kanban. Also clear
            // any stale closed_at/closed_by here, since a ticket dragged around the
            // board (status != Closed, guaranteed by the "continue" above) that still
            // has these set from a previous bug/edge-case keeps rendering as closed
            // on the ticket page, which gates on ticket_closed_at rather than status.
            mysqli_query($mysqli, "UPDATE tickets SET ticket_order = $kanban, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $ticket_id");
            customAction('ticket_update', $ticket_id);
        } else {
            // If the ticket was moved from a resolved status to another status, we need to update ticket_resolved_at
            if ($oldStatus === $statuses['Resolved']) {
                mysqli_query($mysqli, "UPDATE tickets SET ticket_order = $kanban, ticket_status = $status, ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $ticket_id");
                customAction('ticket_update', $ticket_id);
            } elseif ($status === $statuses['Resolved']) {
                // Resolved is an immediate alias for Closed everywhere else in this
                // app (reply form, quick-status, status dropdown, bulk reply) - match
                // that here too instead of leaving the ticket at status 4 relying on
                // the slow cron-based auto-close.
                $final_status = resolveTicketStatusId($status);
                mysqli_query($mysqli, "UPDATE tickets SET ticket_order = $kanban, ticket_status = $final_status, ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $session_user_id WHERE ticket_id = $ticket_id");
                customAction('ticket_update', $ticket_id);

                // Client notification email
                if (!empty($config_smtp_host) && $config_ticket_client_general_notifications == 1) {

                    // Get details
                    $ticket_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, ticket_assigned_to, ticket_url_key, ticket_client_id FROM tickets
                        LEFT JOIN clients ON ticket_client_id = client_id
                        LEFT JOIN contacts ON ticket_contact_id = contact_id
                        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
                        WHERE ticket_id = $ticket_id
                    ");
                    $row = mysqli_fetch_assoc($ticket_sql);

                    $contact_name = sanitizeInput($row['contact_name']);
                    $contact_email = sanitizeInput($row['contact_email']);
                    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
                    $ticket_number = intval($row['ticket_number']);
                    $ticket_subject = sanitizeInput($row['ticket_subject']);
                    $client_id = intval($row['ticket_client_id']);
                    $ticket_assigned_to = intval($row['ticket_assigned_to']);
                    $ticket_status = sanitizeInput($row['ticket_status_name']);
                    $url_key = sanitizeInput($row['ticket_url_key']);

                    // Sanitize Config vars from get_settings.php
                    $config_ticket_from_name = sanitizeInput($config_ticket_from_name);
                    $config_ticket_from_email = sanitizeInput($config_ticket_from_email);
                    $config_base_url = sanitizeInput($config_base_url);

                    $ticket_from = resolveTicketFromIdentity($ticket_id);

                    // Get Company Info
                    $sql = mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1");
                    $row = mysqli_fetch_assoc($sql);
                    $company_name = sanitizeInput($row['company_name']);
                    $company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));

                    // EMAIL
                    $subject = "Ticket resolved - [$ticket_prefix$ticket_number] - $ticket_subject";
                    $csat_rating_html = ($config_ticket_csat_enable == 1) ? csatEmailRatingLinksHtml($config_base_url, $ticket_id, $url_key) : '';
                    $body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>Your ticket regarding $ticket_subject has been resolved and closed.<br><br>We hope this resolved things to your satisfaction - how would you rate it?<br>$csat_rating_html<br>If you need further assistance, please reply or <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>re-open</a> to let us know! <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: $ticket_status<br>Portal: <a href=\'https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key\'>View ticket</a><br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

                    // Check email valid
                    if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

                        $data = [];

                        // Email Ticket Contact
                        // Queue Mail

                        $data[] = [
                            'from' => $ticket_from['email'],
                            'from_name' => $ticket_from['name'],
                            'recipient' => $contact_email,
                            'recipient_name' => $contact_name,
                            'subject' => $subject,
                            'body' => $body
                        ];
                    }

                    // Also Email all the watchers
                    $sql_watchers = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id");
                    $body .= "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
                    while ($row = mysqli_fetch_assoc($sql_watchers)) {
                        $watcher_email = sanitizeInput($row['watcher_email']);

                        // Queue Mail
                        $data[] = [
                            'from' => $ticket_from['email'],
                            'from_name' => $ticket_from['name'],
                            'recipient' => $watcher_email,
                            'recipient_name' => $watcher_email,
                            'subject' => $subject,
                            'body' => $body
                        ];
                    }
                    addToMailQueue($data);
                }
                //End Mail IF

            } else {
                // If the ticket was moved from any status to another status
                mysqli_query($mysqli, "UPDATE tickets SET ticket_order = $kanban, ticket_status = $status, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $ticket_id");
                customAction('ticket_update', $ticket_id);
            }
        }

    }

    // return a response
    echo json_encode(['status' => 'success','payload' => $positions]);
    exit;
}

if (isset($_POST['update_ticket_tasks_order'])) {
    // Update multiple ticket tasks order

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $positions = $_POST['positions'];
    $ticket_id = intval($_POST['ticket_id']);

    foreach ($positions as $position) {
        $id = intval($position['id']);
        $order = intval($position['order']);

        mysqli_query($mysqli, "UPDATE tasks SET task_order = $order WHERE task_ticket_id = $ticket_id AND task_id = $id");
    }

    // return a response
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['update_task_templates_order'])) {
    // Update multiple task templates order

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $positions = $_POST['positions'];
    $ticket_template_id = intval($_POST['ticket_template_id']);

    foreach ($positions as $position) {
        $id = intval($position['id']);
        $order = intval($position['order']);

        mysqli_query($mysqli, "UPDATE task_templates SET task_template_order = $order WHERE task_template_ticket_template_id = $ticket_template_id AND task_template_id = $id");
    }

    // return a response
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['update_project_template_ticket_order'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $positions = $_POST['positions'];
    $project_template_id = intval($_POST['project_template_id']);

    foreach ($positions as $position) {
        $id = intval($position['id']);
        $order = intval($position['order']);

        mysqli_query($mysqli, "UPDATE project_template_ticket_templates SET ticket_template_order = $order WHERE project_template_id = $project_template_id AND ticket_template_id = $id");
    }

    // return a response
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['update_quote_items_order'])) {
    // Update multiple quote items order

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $positions = $_POST['positions'];
    $quote_id = intval($_POST['quote_id']);

    foreach ($positions as $position) {
        $id = intval($position['id']);
        $order = intval($position['order']);

        mysqli_query($mysqli, "UPDATE quote_items SET item_order = $order WHERE item_quote_id = $quote_id AND item_id = $id");
    }

    // return a response
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['update_invoice_items_order'])) {
    // Update multiple invoice items order

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $positions = $_POST['positions'];
    $invoice_id = intval($_POST['invoice_id']);

    foreach ($positions as $position) {
        $id = intval($position['id']);
        $order = intval($position['order']);

        mysqli_query($mysqli, "UPDATE invoice_items SET item_order = $order WHERE item_invoice_id = $invoice_id AND item_id = $id");
    }

    // return a response
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_POST['update_recurring_invoice_items_order'])) {
    // Update multiple recurring invoice items order

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_sales', 2);

    $positions = $_POST['positions'];
    $recurring_invoice_id = intval($_POST['recurring_invoice_id']);

    foreach ($positions as $position) {
        $id = intval($position['id']);
        $order = intval($position['order']);

        mysqli_query($mysqli, "UPDATE recurring_invoice_items SET item_order = $order WHERE item_recurring_invoice_id = $recurring_invoice_id AND item_id = $id");
    }

    // return a response
    echo json_encode(['status' => 'success']);
    exit;
}

if (isset($_GET['client_duplicate_check'])) {
    enforceUserPermission('module_client', 2);

    $name = sanitizeInput($_GET['name']);

    $response['message'] = ""; // default

    if (strlen($name) >= 5) {
        $sql_clients = mysqli_query($mysqli, "SELECT client_name FROM clients
        WHERE client_archived_at IS NULL
        AND client_name LIKE '%$name%'
        ORDER BY client_id DESC LIMIT 1"
        );

        if (mysqli_num_rows($sql_clients) > 0) {
            while ($row = mysqli_fetch_assoc($sql_clients)) {
                $response['message'] = "<i class='fas fa-fw fa-copy me-2'></i> Potential duplicate: <i>" . nullable_htmlentities($row['client_name']) . "</i> already exists.";
            }
        }
    }

    echo json_encode($response);
}

if (isset($_GET['contact_email_check'])) {
    enforceUserPermission('module_client', 2);

    $email = sanitizeInput($_GET['email']);
    $domain = sanitizeInput(substr($_GET['email'], strpos($_GET['email'], '@') + 1));

    $response['message'] = ""; // default

    if (strlen($email) >= 3) {

        // 1. Duplicate check
        $sql_contacts = mysqli_query($mysqli, "SELECT contact_email FROM contacts WHERE contact_email = '$email' LIMIT 1");
        if (mysqli_num_rows($sql_contacts) > 0) {
            while ($row = mysqli_fetch_assoc($sql_contacts)) {
                $response['message'] = "<i class='fas fa-fw fa-copy me-2'></i> Potential duplicate: <i>" . nullable_htmlentities($row['contact_email']) . "</i> already exists.";
            }
        }

        // 2. MX record check
        if (!checkdnsrr($domain, 'MX')) {
            $response['message'] = "<i class='fas fa-fw fa-exclamation-triangle me-2'></i> E-mail domain invalid.";
        }

    }

    echo json_encode($response);
}

if (isset($_GET['ai_reword'])) {

    enforceUserPermission('module_support');

    header('Content-Type: application/json');

    require_once __DIR__ . "/../includes/ai_functions.php";

    // Collecting the input data from the AJAX request.
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, TRUE); // Convert JSON into array.

    validateCSRFToken($input['csrf_token'] ?? '');

    // The reword system prompt comes from the configured 'General' model.
    $promptText = '';
    $model_q = mysqli_query($mysqli, "SELECT ai_model_prompt FROM ai_models WHERE ai_model_use_case = 'General' LIMIT 1");
    if ($model_q && ($model_row = mysqli_fetch_assoc($model_q))) {
        $promptText = $model_row['ai_model_prompt'];
    }

    $userText = $input['text'] ?? '';

    $messages = [
        ["role" => "system", "content" => $promptText],
        ["role" => "user", "content" => $userText],
    ];

    // Centralised call: gains timeout, input cap and max_tokens for free.
    $ai = aiChat($messages, 'General', ['temperature' => 0.5]);

    if ($ai['ok']) {
        // Get the response content.
        $content = $ai['content'];

        // Clean any leading "html" word or other unwanted text at the beginning.
        $content = preg_replace('/^html/i', '', $content);  // Remove any occurrence of 'html' at the start

        // Clean the response content to remove backticks or code block markers.
        $cleanedContent = str_replace('```', '', $content); // Remove backticks if they exist.

        // Trim any leading/trailing whitespace.
        $cleanedContent = trim($cleanedContent);

        // Return the cleaned response.
        echo json_encode(['ok' => true, 'rewordedText' => $cleanedContent]);
    } else {
        // Surface the real failure server-side so a broken provider/model config
        // is diagnosable, and tell the client NOT to overwrite the editor's
        // existing content with a canned error string (that used to destroy
        // whatever the agent had already typed).
        error_log('ai_reword failed: ' . ($ai['error'] ?? 'unknown error'));
        echo json_encode(['ok' => false, 'error' => $ai['error'] ?? 'AI reword unavailable']);
    }

}

if (isset($_GET['ai_create_document_template'])) {
    // get_ai_document_template.php

    enforceUserPermission('module_support');

    header('Content-Type: text/html; charset=UTF-8');

    require_once __DIR__ . "/../includes/ai_functions.php";

    validateCSRFToken($_POST['csrf_token'] ?? '');

    $prompt = $_POST['prompt'] ?? '';

    // Basic validation
    if(empty($prompt)){
        echo "No prompt provided.";
        exit;
    }

    // Prepare prompt
    $system_message = "You are a helpful IT documentation assistant. You will create a well-structured HTML template for IT documentation based on a given prompt. Include headings, subheadings, bullet points, and possibly tables for clarity. No Lorem Ipsum, use realistic placeholders and professional language.";
    $user_message = "Create an HTML formatted IT documentation template based on the following request:\n\n\"$prompt\"\n\nThe template should be structured, professional, and useful for IT staff. Include relevant sections, instructions, prerequisites, and best practices.";

    $messages = [
        ["role" => "system", "content" => $system_message],
        ["role" => "user", "content" => $user_message]
    ];

    // Centralised call: gains timeout, input cap and max_tokens for free.
    $ai = aiChat($messages, 'General', ['temperature' => 0.5]);

    if ($ai['ok']) {
        // Print the generated HTML template directly
        echo $ai['content'];
    } else {
        error_log('ai_create_document_template failed: ' . ($ai['error'] ?? 'unknown error'));
        echo "<p>No content returned from AI: " . htmlspecialchars($ai['error'] ?? 'unknown error') . "</p>";
    }
}

if (isset($_GET['ai_ticket_summary'])) {

    enforceUserPermission('module_support');

    header('Content-Type: text/html; charset=UTF-8');

    require_once __DIR__ . "/../includes/ai_functions.php";

    validateCSRFToken($_POST['csrf_token'] ?? '');

    // Retrieve the ticket_id from POST
    $ticket_id = intval($_POST['ticket_id']);

    // Query the database for ticket details
    $sql = mysqli_query($mysqli, "
        SELECT ticket_subject, ticket_details, ticket_source, ticket_priority, ticket_status_name, category_name, ticket_client_id
        FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN categories ON ticket_category = category_id
        WHERE ticket_id = $ticket_id
        LIMIT 1
    ");
    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        echo "Ticket not found.";
        exit;
    }
    $ticket_subject = $row['ticket_subject'];
    $ticket_details = strip_tags($row['ticket_details']); // strip HTML for cleaner prompt
    $ticket_status = $row['ticket_status_name'];
    $ticket_category = $row['category_name'];
    $ticket_source = $row['ticket_source'];
    $ticket_priority = $row['ticket_priority'];
    $client_id = intval($row['ticket_client_id']);

    enforceClientAccess();

    // --- Summary cache lookup ---------------------------------------------
    // Serve the stored summary only when it is not flagged stale AND it was
    // generated against the ticket's current latest (non-archived) reply. The
    // reply-id check is the authoritative freshness signal - the stale flag set
    // by markTicketSummaryStale() at reply-insert points is a secondary hint.
    $latest_reply_res = mysqli_query($mysqli,
        "SELECT MAX(ticket_reply_id) AS max_id FROM ticket_replies
         WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply_archived_at IS NULL");
    $latest_reply_row = $latest_reply_res ? mysqli_fetch_assoc($latest_reply_res) : null;
    $latest_reply_id  = intval($latest_reply_row['max_id'] ?? 0);

    $cache_res = mysqli_query($mysqli,
        "SELECT summary_html, based_on_reply_id, stale FROM ticket_ai_summaries WHERE ticket_id = $ticket_id LIMIT 1");
    $cache_row = $cache_res ? mysqli_fetch_assoc($cache_res) : null;

    if ($cache_row && intval($cache_row['stale']) === 0 && intval($cache_row['based_on_reply_id']) === $latest_reply_id) {
        // Fresh cache hit - already-purified HTML.
        echo $cache_row['summary_html'];
    } else {

        // Get ticket replies
        $sql_replies = mysqli_query($mysqli, "
            SELECT ticket_reply, ticket_reply_type, user_name
            FROM ticket_replies
            LEFT JOIN users ON ticket_reply_by = user_id
            WHERE ticket_reply_ticket_id = $ticket_id
            AND ticket_reply_archived_at IS NULL
            ORDER BY ticket_reply_id ASC
        ");

        $all_replies_text = "";
        while ($reply = mysqli_fetch_assoc($sql_replies)) {
            $reply_type = $reply['ticket_reply_type'];
            $reply_text = strip_tags($reply['ticket_reply']);
            $reply_by = $reply['user_name'];
            $all_replies_text .= "\nReply Type: $reply_type Reply By: $reply_by: Reply Text: $reply_text";
        }

        $prompt = "
        Summarize the following IT support ticket and its responses in a concise, clear, and professional manner.
        The summary should include:

        1. Main Issue: What was the problem reported by the user?
        2. Actions Taken: What steps were taken to address the issue?
        3. Resolution or Next Steps: Was the issue resolved or is it ongoing?

        Please ensure:
        - If there are multiple issues, summarize each separately.
        - Urgency: If the ticket or replies express urgency or escalation, highlight it.
        - Attachments: If mentioned in the ticket, note any relevant attachments or files.
        - Avoid extra explanations or unnecessary information.

        Ticket Data:
        - Ticket Source: $ticket_source
        - Current Ticket Status: $ticket_status
        - Ticket Priority: $ticket_priority
        - Ticket Category: $ticket_category
        - Ticket Subject: $ticket_subject
        - Ticket Details: $ticket_details
        - Replies:
        $all_replies_text

        Formatting instructions:
        - Use valid HTML tags only.
        - Use <h3> for section headers (Main Issue, Actions Taken, Resolution).
        - Use <ul><li> for bullet points under each section.
        - Do NOT wrap the output in ```html or any other code fences.
        - Do NOT include <html>, <head>, or <body>.
        - Output only the summary content in pure HTML.
        If any part of the ticket or replies is unclear or ambiguous, mention it in the summary and suggest if further clarification is needed.
        ";

        $messages = [
            ["role" => "system", "content" => "Your task is to summarize IT support tickets with clear, concise details."],
            ["role" => "user", "content" => $prompt]
        ];

        // Centralised call: gains timeout, input cap and max_tokens for free.
        // Pass the ticket's client so the per-client AI opt-out is honoured.
        $ai = aiChat($messages, 'General', ['temperature' => 0.3, 'client_id' => $client_id]);

        if (!empty($ai['ok'])) {
            // Sanitise the provider HTML before it is stored or echoed. Strip any
            // stray code fences the model may add despite the prompt, then purify.
            $raw = (string) $ai['content'];
            $raw = preg_replace('/^\s*```(?:html)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```\s*$/', '', $raw);

            if (!class_exists('HTMLPurifier')) {
                require_once __DIR__ . '/../plugins/htmlpurifier/HTMLPurifier.standalone.php';
            }
            $purifier_config = HTMLPurifier_Config::createDefault();
            $purifier_config->set('Cache.DefinitionImpl', null);
            $purifier_config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
            $purifier = new HTMLPurifier($purifier_config);
            $summary  = $purifier->purify($raw);

            // Record which model produced this summary (aiChat resolves it internally).
            $model_used = '';
            $model_res  = mysqli_query($mysqli, "SELECT ai_model_name FROM ai_models WHERE ai_model_use_case = 'General' LIMIT 1");
            if ($model_res && ($model_row = mysqli_fetch_assoc($model_res))) {
                $model_used = (string) $model_row['ai_model_name'];
            }

            // Upsert the fresh, purified summary and clear the stale flag.
            $summary_esc   = mysqli_real_escape_string($mysqli, $summary);
            $model_esc     = mysqli_real_escape_string($mysqli, $model_used);
            $tokens_sql    = isset($ai['tokens']) && $ai['tokens'] !== null ? intval($ai['tokens']) : 'NULL';
            $based_on_sql  = $latest_reply_id > 0 ? $latest_reply_id : 'NULL';
            mysqli_query($mysqli,
                "INSERT INTO ticket_ai_summaries
                    (ticket_id, summary_html, based_on_reply_id, model_used, tokens, stale, created_at)
                 VALUES ($ticket_id, '$summary_esc', $based_on_sql, '$model_esc', $tokens_sql, 0, NOW())
                 ON DUPLICATE KEY UPDATE
                    summary_html = VALUES(summary_html),
                    based_on_reply_id = VALUES(based_on_reply_id),
                    model_used = VALUES(model_used),
                    tokens = VALUES(tokens),
                    stale = 0,
                    created_at = NOW()");

            echo $summary;
        } else {
            // AI disabled/unconfigured/failed - degrade gracefully, do not cache.
            if (!empty($ai['error']) && $ai['error'] !== 'AI is disabled') {
                error_log('ai_ticket_summary failed for ticket ' . $ticket_id . ': ' . $ai['error']);
            }
            echo "No summary available.";
        }
    }
}

/*
 * AI ticket reply draft
 * ?ai_ticket_reply_draft  (POST ticket_id)
 *
 * Drafts a professional reply for an agent using the ticket's recent public +
 * client conversation and up to ~3 relevant knowledge base snippets that are
 * scoped to the ticket's client (never cross-client). Returns the plain draft
 * text as JSON; NEVER auto-sends and NEVER fatals - on any AI failure it returns
 * {"ok":false,...} so the caller can degrade gracefully.
 *
 * Permission gating mirrors ?ai_ticket_summary: module_support + client access.
 */
if (isset($_GET['ai_ticket_reply_draft'])) {

    enforceUserPermission('module_support');

    header('Content-Type: application/json; charset=UTF-8');

    require_once __DIR__ . "/../includes/ai_functions.php";

    validateCSRFToken($_POST['csrf_token'] ?? '');

    $ticket_id = intval($_POST['ticket_id'] ?? 0);

    // Resolve the ticket + its client (for access control and KB scoping)
    $sql = mysqli_query($mysqli, "
        SELECT ticket_subject, ticket_details, ticket_client_id
        FROM tickets
        WHERE ticket_id = $ticket_id
        LIMIT 1
    ");
    $row = $sql ? mysqli_fetch_assoc($sql) : null;

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Ticket not found']);
        exit;
    }

    $ticket_subject = (string) $row['ticket_subject'];
    $ticket_details = strip_tags((string) $row['ticket_details']);
    $client_id      = intval($row['ticket_client_id']);

    // Same client-access gate as the summary handler (uses global $client_id)
    enforceClientAccess();

    // --- Gather the last N public + client replies (chronological) -----------
    $reply_limit = 10;
    $sql_replies = mysqli_query($mysqli, "
        SELECT ticket_reply, ticket_reply_type, user_name, contact_name
        FROM ticket_replies
        LEFT JOIN users ON ticket_reply_by = user_id
        LEFT JOIN contacts ON ticket_reply_by = contact_id
        WHERE ticket_reply_ticket_id = $ticket_id
        AND ticket_reply_archived_at IS NULL
        AND ticket_reply_type IN ('Public', 'Client')
        ORDER BY ticket_reply_id DESC
        LIMIT $reply_limit
    ");

    $recent_replies = [];
    if ($sql_replies) {
        while ($reply = mysqli_fetch_assoc($sql_replies)) {
            $recent_replies[] = $reply;
        }
    }
    $recent_replies = array_reverse($recent_replies); // back to chronological order

    $conversation_text = "";
    foreach ($recent_replies as $reply) {
        $reply_author = $reply['ticket_reply_type'] === 'Client'
            ? ($reply['contact_name'] ?: 'Client')
            : ($reply['user_name'] ?: 'Agent');
        $reply_body = trim(strip_tags((string) $reply['ticket_reply']));
        if ($reply_body === '') {
            continue;
        }
        if (strlen($reply_body) > 1500) {
            $reply_body = substr($reply_body, 0, 1500) . '…';
        }
        $conversation_text .= "\n[{$reply['ticket_reply_type']}] {$reply_author}: {$reply_body}";
    }
    if (trim($conversation_text) === '') {
        $conversation_text = "(No public conversation yet.)";
    }

    // --- Retrieve up to 3 relevant KB snippets, SCOPED to this client --------
    // Scope rule (prevents cross-client leakage): an article is eligible only
    // when it belongs to this ticket's client OR is a global article
    // (kb_article_client_id = 0). Articles owned by any OTHER client are never
    // eligible. Archived articles are always excluded.
    $client_scope = "(kb_article_client_id = $client_id OR kb_article_client_id = 0) AND kb_article_archived_at IS NULL";

    // Build a search string from the subject + latest client message.
    $search_terms = $ticket_subject;
    for ($i = count($recent_replies) - 1; $i >= 0; $i--) {
        if ($recent_replies[$i]['ticket_reply_type'] === 'Client') {
            $search_terms .= ' ' . strip_tags((string) $recent_replies[$i]['ticket_reply']);
            break;
        }
    }
    $search_terms = trim(preg_replace('/\s+/', ' ', $search_terms));
    if (strlen($search_terms) > 400) {
        $search_terms = substr($search_terms, 0, 400);
    }
    $search_esc = mysqli_real_escape_string($mysqli, $search_terms);

    $kb_rows = [];
    if ($search_terms !== '') {
        // FULLTEXT match on the raw content (the only fulltext-indexed column).
        $kb_res = mysqli_query($mysqli, "
            SELECT kb_article_title, kb_article_content
            FROM kb_articles
            WHERE $client_scope
            AND MATCH(kb_article_content_raw) AGAINST('$search_esc' IN NATURAL LANGUAGE MODE)
            LIMIT 3
        ");
        if ($kb_res) {
            while ($kb = mysqli_fetch_assoc($kb_res)) {
                $kb_rows[] = $kb;
            }
        }
    }

    // LIKE fallback when fulltext returns nothing (short/stopword-only terms).
    if (empty($kb_rows) && $search_terms !== '') {
        $like_esc = mysqli_real_escape_string($mysqli, '%' . $search_terms . '%');
        $kb_res = mysqli_query($mysqli, "
            SELECT kb_article_title, kb_article_content
            FROM kb_articles
            WHERE $client_scope
            AND (kb_article_title LIKE '$like_esc' OR kb_article_content_raw LIKE '$like_esc')
            ORDER BY kb_article_updated_at DESC
            LIMIT 3
        ");
        if ($kb_res) {
            while ($kb = mysqli_fetch_assoc($kb_res)) {
                $kb_rows[] = $kb;
            }
        }
    }

    $kb_text = "";
    foreach ($kb_rows as $kb) {
        $kb_title = trim((string) $kb['kb_article_title']);
        $kb_body  = trim(strip_tags((string) $kb['kb_article_content']));
        if (strlen($kb_body) > 900) {
            $kb_body = substr($kb_body, 0, 900) . '…';
        }
        $kb_text .= "\n--- KB Article: {$kb_title} ---\n{$kb_body}\n";
    }
    if (trim($kb_text) === '') {
        $kb_text = "(No relevant knowledge base articles found for this client.)";
    }

    // --- Build the prompt & call the model -----------------------------------
    $prompt = "Draft a professional, courteous reply to the customer for the IT support ticket below.\n\n" .
        "Guidelines:\n" .
        "- Write only the reply body, ready for an agent to review and send.\n" .
        "- Be clear and concise; where steps are appropriate, cite them in a numbered list.\n" .
        "- Use ONLY facts present in the ticket conversation and the knowledge base excerpts. Do NOT invent facts, account details, credentials, prices, or steps.\n" .
        "- If key information is missing, ask a brief clarifying question instead of guessing.\n" .
        "- Do not promise timelines or actions that are not supported by the ticket.\n" .
        "- Output plain text only (no markdown code fences, no HTML).\n\n" .
        "Ticket Subject: $ticket_subject\n\n" .
        "Ticket Details:\n$ticket_details\n\n" .
        "Recent Conversation (oldest first):$conversation_text\n\n" .
        "Relevant Knowledge Base Excerpts (client-scoped):\n$kb_text\n";

    $messages = [
        ["role" => "system", "content" => "You are an experienced IT support agent drafting reply messages. You are helpful, precise, and never fabricate information."],
        ["role" => "user", "content" => $prompt],
    ];

    // Pass the ticket's client so the per-client AI opt-out is honoured.
    // Use case 'Reply Draft' falls back to 'General' inside aiChat() if no
    // dedicated model row exists.
    $ai = aiChat($messages, 'Reply Draft', [
        'temperature' => 0.4,
        'client_id'   => $client_id,
        'max_tokens'  => 1200,
    ]);

    if (!empty($ai['ok'])) {
        // Strip any stray code fences the model may add despite the prompt.
        $draft = (string) $ai['content'];
        $draft = preg_replace('/^\s*```(?:\w+)?\s*/', '', $draft);
        $draft = preg_replace('/\s*```\s*$/', '', $draft);
        $draft = trim($draft);
        echo json_encode(['ok' => true, 'draft' => $draft]);
    } else {
        // AI disabled / unconfigured / provider failure - degrade gracefully.
        if (!empty($ai['error']) && $ai['error'] !== 'AI is disabled') {
            error_log('ai_ticket_reply_draft failed for ticket ' . $ticket_id . ': ' . $ai['error']);
        }
        echo json_encode(['ok' => false, 'error' => $ai['error'] ?? 'AI draft unavailable']);
    }
    exit;
}

// Stops people trying to use sub-domains in the domains tracker
if (isset($_GET['apex_domain_check'])) {
    enforceUserPermission('module_support', 2);

    $domain = sanitizeInput($_GET['domain']);

    $response['message'] = ""; // default

    if (strlen($domain) >= 4) {

        // SOA record check
        //  This isn't 100%, as sub-domains can have their own SOA but will capture 99%
        if (!checkdnsrr($domain, 'SOA')) {
            $response['message'] = "<i class='fas fa-fw fa-exclamation-triangle me-2'></i> Domain name is invalid.";
        }

    }

    echo json_encode($response);
}

// Get internal users/techs
if (isset($_GET['get_internal_users'])) {
    enforceUserPermission('module_support');

    $sql = mysqli_query(
        $mysqli,
        "SELECT user_id, user_name
         FROM users
         WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
         ORDER BY user_name"
    );

    while ($row = mysqli_fetch_assoc($sql)) {
        $response['users'][] = $row;
    }

    echo json_encode($response);
    exit;
}

/*
 * PROJECTS - Gantt data feed
 * ?project_gantt=<project_id>
 * Returns the project's dated tasks + milestones (zero-width bars) as a JSON
 * array in the frappe-gantt shape. Tasks with a NULL start OR due are excluded.
 */
if (isset($_GET['project_gantt'])) {
    enforceUserPermission('module_support');

    header('Content-Type: application/json');

    $project_id = intval($_GET['project_gantt']);

    // Confirm the project exists & the user may access its client
    $prow = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT project_client_id FROM projects WHERE project_id = $project_id LIMIT 1"));
    if (!$prow) {
        echo json_encode([]);
        exit;
    }
    $client_id = intval($prow['project_client_id']);
    if ($client_id) {
        enforceClientAccess($client_id);
    }

    $gantt = [];

    // Dated tasks belonging to this project (directly, or via a project ticket).
    // LEFT JOIN mirrors project_details.php so project-only tasks are included.
    $sql_tasks = mysqli_query($mysqli,
        "SELECT tasks.task_id, tasks.task_name, tasks.task_start, tasks.task_due, tasks.task_progress
         FROM tasks
         LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id
         WHERE (tasks.task_project_id = $project_id OR tickets.ticket_project_id = $project_id)
         AND tasks.task_start IS NOT NULL AND tasks.task_due IS NOT NULL
         ORDER BY tasks.task_start ASC, tasks.task_order ASC, tasks.task_id ASC");

    $rows = [];
    $included_ids = [];
    while ($r = mysqli_fetch_assoc($sql_tasks)) {
        $rows[] = $r;
        $included_ids[intval($r['task_id'])] = true;
    }

    // Build dependency map (task_id -> ['task_<predecessor>', ...]) limited to tasks on the chart
    $dep_map = [];
    if (!empty($included_ids)) {
        $id_list = implode(',', array_map('intval', array_keys($included_ids)));
        $sql_deps = mysqli_query($mysqli, "SELECT task_id, predecessor_id FROM project_task_dependencies WHERE task_id IN ($id_list)");
        while ($d = mysqli_fetch_assoc($sql_deps)) {
            $tid = intval($d['task_id']);
            $pid = intval($d['predecessor_id']);
            if (isset($included_ids[$pid])) {
                $dep_map[$tid][] = 'task_' . $pid;
            }
        }
    }

    foreach ($rows as $r) {
        $tid = intval($r['task_id']);
        $start = date('Y-m-d', strtotime($r['task_start']));
        $end = date('Y-m-d', strtotime($r['task_due']));
        if (strtotime($end) < strtotime($start)) {
            $end = $start;
        }
        $gantt[] = [
            'id' => 'task_' . $tid,
            'name' => $r['task_name'],
            'start' => $start,
            'end' => $end,
            'progress' => intval($r['task_progress']),
            'dependencies' => isset($dep_map[$tid]) ? implode(',', $dep_map[$tid]) : ''
        ];
    }

    // Milestones as zero-width (start == end) bars
    $sql_ms = mysqli_query($mysqli, "SELECT milestone_id, milestone_name, milestone_due, milestone_status FROM project_milestones WHERE milestone_project_id = $project_id AND milestone_due IS NOT NULL ORDER BY milestone_due ASC");
    while ($m = mysqli_fetch_assoc($sql_ms)) {
        $mid = intval($m['milestone_id']);
        $due = date('Y-m-d', strtotime($m['milestone_due']));
        $gantt[] = [
            'id' => 'milestone_' . $mid,
            'name' => $m['milestone_name'],
            'start' => $due,
            'end' => $due,
            'progress' => ($m['milestone_status'] === 'completed' ? 100 : 0),
            'dependencies' => '',
            'custom_class' => 'bar-milestone'
        ];
    }

    echo json_encode($gantt);
    exit;
}

/*
 * PROJECTS - Persist a task's Gantt schedule (start / due / progress)
 * POST update_task_schedule
 * Called by the Gantt on_date_change / on_progress_change handlers.
 */
if (isset($_POST['update_task_schedule'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    header('Content-Type: application/json');

    $task_ref = $_POST['task_id'] ?? '';
    // Milestones are not persisted via this endpoint
    if (strpos($task_ref, 'milestone_') === 0) {
        echo json_encode(['status' => 'ignored']);
        exit;
    }
    $task_id = intval(str_replace('task_', '', $task_ref));
    if ($task_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid task']);
        exit;
    }

    // Resolve owning project & enforce client access
    $prow = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(tasks.task_project_id, tickets.ticket_project_id) AS pid FROM tasks LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id WHERE tasks.task_id = $task_id LIMIT 1"));
    if (!$prow) {
        echo json_encode(['status' => 'error', 'message' => 'Task not found']);
        exit;
    }
    $pid = intval($prow['pid']);
    if ($pid) {
        $crow = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT project_client_id FROM projects WHERE project_id = $pid LIMIT 1"));
        $client_id = intval($crow['project_client_id'] ?? 0);
        if ($client_id) {
            enforceClientAccess($client_id);
        }
    }

    $start_raw = $_POST['start'] ?? '';
    $end_raw = $_POST['end'] ?? '';
    $progress = isset($_POST['progress']) ? max(0, min(100, intval($_POST['progress']))) : 0;

    $sets = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw)) {
        $s = mysqli_real_escape_string($mysqli, $start_raw);
        $sets[] = "task_start = '$s'";
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw)) {
        $e = mysqli_real_escape_string($mysqli, $end_raw);
        $sets[] = "task_due = '$e'";
    }
    $sets[] = "task_progress = $progress";

    $set_sql = implode(', ', $sets);
    mysqli_query($mysqli, "UPDATE tasks SET $set_sql WHERE task_id = $task_id");

    echo json_encode(['status' => 'success']);
    exit;
}

/*
 * PROJECTS - Kanban drag persistence
 * POST update_project_task_kanban with positions[][task_id|task_order|task_status]
 * Lanes are fixed: To Do / In Progress / Blocked / Done.
 */
if (isset($_POST['update_project_task_kanban'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    header('Content-Type: application/json');

    $positions = $_POST['positions'] ?? [];
    $allowed_statuses = ['To Do', 'In Progress', 'Blocked', 'Done'];

    foreach ($positions as $position) {
        $task_id = intval($position['task_id'] ?? 0);
        $order = intval($position['task_order'] ?? 0);
        $status_raw = $position['task_status'] ?? '';

        if ($task_id <= 0 || !in_array($status_raw, $allowed_statuses, true)) {
            continue;
        }

        // Resolve owning project & enforce client access
        $prow = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(tasks.task_project_id, tickets.ticket_project_id) AS pid FROM tasks LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id WHERE tasks.task_id = $task_id LIMIT 1"));
        if (!$prow) {
            continue;
        }
        $pid = intval($prow['pid']);
        if ($pid) {
            $crow = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT project_client_id FROM projects WHERE project_id = $pid LIMIT 1"));
            $client_id = intval($crow['project_client_id'] ?? 0);
            if ($client_id) {
                enforceClientAccess($client_id);
            }
        }

        $status_esc = mysqli_real_escape_string($mysqli, $status_raw);

        if ($status_raw === 'Done') {
            // Mark complete, preserving an existing completion time/author if present
            mysqli_query($mysqli, "UPDATE tasks SET task_order = $order, task_status = '$status_esc', task_progress = 100, task_completed_at = COALESCE(task_completed_at, NOW()), task_completed_by = COALESCE(task_completed_by, $session_user_id) WHERE task_id = $task_id");
        } else {
            // Any non-Done lane: clear completion state
            mysqli_query($mysqli, "UPDATE tasks SET task_order = $order, task_status = '$status_esc', task_completed_at = NULL, task_completed_by = NULL WHERE task_id = $task_id");
        }
    }

    echo json_encode(['status' => 'success']);
    exit;
}

/*
 * Comet Backup device/job status for client_overview.php's Comet card,
 * fetched async - see the comment in client_overview.php's Comet block for
 * why this was split out of that page's own synchronous render path.
 * Returns an HTML fragment (not JSON) since it's injected directly into the
 * card body via fetch().then(r => r.text()).
 */
if (isset($_GET['comet_client_status'])) {
    header('Content-Type: text/html; charset=utf-8');

    enforceUserPermission('module_client');

    $client_id = intval($_GET['client_id']);
    enforceClientAccess();

    if (empty($config_comet_enabled)) {
        echo '<p class="text-muted text-center py-3 mb-0">Comet Backup is not enabled.</p>';
        exit;
    }

    require_once "../includes/comet.php";

    $comet_map = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT map_comet_username FROM comet_client_map WHERE map_client_id = $client_id LIMIT 1"
    ));
    if (!$comet_map) {
        echo '<p class="text-muted text-center py-3 mb-0">No Comet mapping configured for this client.</p>';
        exit;
    }

    $c_username  = $comet_map['map_comet_username'];
    $c_last_jobs = comet_last_jobs_per_device($c_username);
    $c_devices   = (comet_get_users()[$c_username] ?? [])['Devices'] ?? [];

    if (empty($c_devices)) {
        echo '<p class="text-muted text-center py-3 mb-0">No devices found for this user in Comet.</p>';
        exit;
    }
?>
<table class="table table-sm table-borderless table-hover mb-0">
    <thead style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;" class="text-muted border-bottom">
        <tr>
            <th class="ps-3" style="width:30px;"></th>
            <th>Device</th>
            <th>Status</th>
            <th>Last Backup</th>
            <th>Size</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($c_devices as $devid => $devdata):
        $dev_name = $devdata['FriendlyName'] ?? $devid;
        $job      = $c_last_jobs[$dev_name] ?? null;
        $status   = $job ? intval($job['Status']) : 0;
        $sc  = comet_status_class($status);
        $sl  = comet_status_label($status);
        $si  = comet_status_icon($status);
        $ago = $job ? timeAgo(date('Y-m-d H:i:s', $job['StartTime'])) : '—';
        $full= $job ? date('Y-m-d H:i', $job['StartTime']) : '';
        $sz  = ($job && ($job['BytesOut'] ?? 0)) ? comet_fmt_bytes(intval($job['BytesOut'])) : '—';
        $err = !empty($job['ErrorString']) ? htmlspecialchars(mb_strimwidth($job['ErrorString'], 0, 60, '…')) : '';
    ?>
    <tr>
        <td class="ps-3 text-center"><i class="fas fa-<?= $si ?> text-<?= $sc ?>"></i></td>
        <td class="small fw-bold"><?= htmlspecialchars($dev_name) ?></td>
        <td>
            <span class="badge badge-<?= $sc ?>"><?= $sl ?></span>
            <?php if ($err): ?><span class="text-danger small d-block"><?= $err ?></span><?php endif; ?>
        </td>
        <td class="text-muted small" title="<?= $full ?>"><?= $ago ?></td>
        <td class="text-muted small"><?= $sz ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
    exit;
}
