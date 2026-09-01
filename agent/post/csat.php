<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Quick toggle for whether a rated ticket's CSAT comment is allowed to appear
// in the public CSAT API (GET /api/v1/csat) used to embed a rating widget on
// the marketing site. Defaults to off for every rating - a client's "anything
// you'd like to add?" comment was never captured with the expectation it
// might be quoted publicly, and free text can carry PII the structured
// rating/date fields don't, so this is a deliberate human-review gate rather
// than an automatic publish.
if (isset($_POST['toggle_csat_public_approved'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $approved  = intval($_POST['approved']) ? 1 : 0;

    $td = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_client_id, ticket_csat_rating FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    if (!$td || $td['ticket_csat_rating'] === null) { echo json_encode(['ok' => false]); exit; }

    $client_id = intval($td['ticket_client_id']);
    if ($client_id) { enforceClientAccess($client_id); }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_csat_public_approved = $approved WHERE ticket_id = $ticket_id");

    logAction("Ticket", "Edit", "$session_name " . ($approved ? "approved" : "un-approved") . " the CSAT comment on ticket {$td['ticket_prefix']}{$td['ticket_number']} for the public rating widget", $client_id, $ticket_id);

    echo json_encode(['ok' => true]);
    exit;
}
