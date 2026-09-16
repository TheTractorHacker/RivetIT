<?php
// Get Main Side Bar Badge Counts

// Active Clients Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('client_id') AS num FROM clients WHERE client_archived_at IS NULL $access_permission_query"));
$num_active_clients = $row['num'];

// Active Ticket Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('ticket_id') AS num FROM tickets LEFT JOIN clients ON client_id = ticket_client_id WHERE ticket_archived_at IS NULL AND ticket_closed_at IS NULL AND ticket_status != 4 $access_permission_query"));
$num_active_tickets = $row['num'];

// Recurring Ticket Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('recurring_ticket_id') AS num FROM recurring_tickets LEFT JOIN clients ON client_id = recurring_ticket_client_id WHERE 1 = 1 $access_permission_query"));
$num_recurring_tickets = $row['num'];

// Pending Mail Request Count (unknown-sender email awaiting review)
// mail_requests have no client_id of their own, but if the mailbox they came in on has a
// default client set, scope by that - same fail-open pattern as $access_permission_query
$mail_request_scope_query = "";
if ($client_access_string && !$session_is_admin) {
    $mail_request_scope_query = "AND (m.mailbox_default_client_id IS NULL OR m.mailbox_default_client_id = 0 OR m.mailbox_default_client_id IN ($client_access_string))";
}
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('mail_request_id') AS num FROM mail_requests mr LEFT JOIN mailboxes m ON m.mailbox_id = mr.mail_request_mailbox_id WHERE mr.mail_request_archived_at IS NULL $mail_request_scope_query"));
$num_mail_requests = $row['num'];

// Active Project Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('project_id') AS num FROM projects LEFT JOIN clients ON client_id = project_client_id WHERE project_archived_at IS NULL AND project_completed_at IS NULL $access_permission_query"));
$num_active_projects = $row['num'];

// Credentials Count (central Password Manager nav item - all departments)
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('credential_id') AS num FROM credentials LEFT JOIN clients ON client_id = credential_client_id WHERE credential_archived_at IS NULL $access_permission_query"));
$num_credentials_all = $row['num'];

// Locations Count (all departments)
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('location_id') AS num FROM locations LEFT JOIN clients ON client_id = location_client_id WHERE location_archived_at IS NULL $access_permission_query"));
$num_locations_all = $row['num'];

// Licenses (software) Count (all departments)
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('software_id') AS num FROM software LEFT JOIN clients ON client_id = software_client_id WHERE software_archived_at IS NULL $access_permission_query"));
$num_software_all = $row['num'];

// Domains Count (all departments)
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('domain_id') AS num FROM domains LEFT JOIN clients ON client_id = domain_client_id WHERE domain_archived_at IS NULL $access_permission_query"));
$num_domains_all = $row['num'];

// Certificates Count (all departments)
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('certificate_id') AS num FROM certificates LEFT JOIN clients ON client_id = certificate_client_id WHERE certificate_archived_at IS NULL $access_permission_query"));
$num_certificates_all = $row['num'];

// Printers Count (all departments)
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('printer_id') AS num FROM printers LEFT JOIN clients ON client_id = printer_client_id WHERE printer_archived_at IS NULL $access_permission_query"));
$num_printers_all = $row['num'];

// Network Drives Count (all departments)
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('network_drive_id') AS num FROM network_drives LEFT JOIN clients ON client_id = network_drive_client_id WHERE network_drive_archived_at IS NULL $access_permission_query"));
$num_network_drives_all = $row['num'];
