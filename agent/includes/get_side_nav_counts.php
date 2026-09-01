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

// Open Invoices Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('invoice_id') AS num FROM invoices LEFT JOIN clients ON client_id = invoice_client_id WHERE (invoice_status = 'Sent' OR invoice_status = 'Viewed' OR invoice_status = 'Partial') AND invoice_archived_at IS NULL $access_permission_query"));
$num_open_invoices = $row['num'];

// Recurring Invoice Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('recurring_invoice_id') AS num FROM recurring_invoices LEFT JOIN clients ON client_id = recurring_invoice_client_id WHERE recurring_invoice_archived_at IS NULL $access_permission_query"));
$num_recurring_invoices = $row['num'];

// Open Quotes Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('quote_id') AS num FROM quotes LEFT JOIN clients ON client_id = quote_client_id WHERE (quote_status = 'Sent' OR quote_status = 'Viewed') AND quote_archived_at IS NULL $access_permission_query"));
$num_open_quotes = $row['num'];

// Recurring Expenses Count
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('recurring_expense_id') AS num FROM recurring_expenses LEFT JOIN clients ON client_id = recurring_expense_client_id WHERE recurring_expense_archived_at IS NULL $access_permission_query"));
$num_recurring_expenses = $row['num'];
