<?php

// Set Page Title 

// Get the current page name without the .php extension
$page_title = basename($_SERVER['PHP_SELF'], '.php');

// Lets make the Page title look pretty
// Replace any underscores with spaces
$page_title = str_replace('_', ' ', $page_title);

// Capitize
$page_title = ucwords($page_title);

// Display-only fixes for names the file name gets wrong: pages whose UI says Department while the file
// (and its URL) still says client, and acronyms. The file names and URLs themselves never change.
$page_title_display_names = [
    'clients'                   => 'Departments',
    'clients_with_balance'      => 'Departments with a Balance',
    'ticket_by_client'          => 'Tickets by Department',
    'client_ticket_time_detail' => 'Department Time Detail Audit',
    'income_by_client'          => 'Income By Department',
    'recurring_by_client'       => 'Recurring Income By Department',
];
$page_title_file = basename($_SERVER['PHP_SELF'], '.php');
if (isset($page_title_display_names[$page_title_file])) {
    $page_title = $page_title_display_names[$page_title_file];
} else {
    $page_title = preg_replace_callback('/\b(Kb|Rmm|Sla|Csat|Api|It|Ai|Mfa|Mrr|Pin)\b/', static function ($m) { return strtoupper($m[1]); }, $page_title);
}

// Sanitize title for SQL input such as logging
$page_title_sanitized = sanitizeInput($page_title);

// Sanitize the page title to prevent XSS for output
$page_title = nullable_htmlentities($page_title);

$tab_title = $session_company_name;
