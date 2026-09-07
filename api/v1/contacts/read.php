<?php

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

/*
 * contacts.contact_pin is a caller-verification secret (the code a contact quotes
 * to prove who they are on the phone - agent/contact_details.php shows it behind
 * the agent login, and the newer /api/v1/contacts endpoint never returns it).
 * SELECT * handed it to every API key. The endpoint's documented shape is
 * "the contact row", so rather than freezing a hand-written column list that would
 * silently drop future columns, enumerate the table and omit just the PIN.
 * Same SHOW COLUMNS idiom as getFieldById() in functions.php.
 */
$contact_fields = array();
$columns_sql = mysqli_query($mysqli, "SHOW COLUMNS FROM `contacts`");
while ($columns_sql && $column = mysqli_fetch_assoc($columns_sql)) {
    if ($column['Field'] !== 'contact_pin') {
        $contact_fields[] = "`" . $column['Field'] . "`";
    }
}
$contact_fields = implode(', ', $contact_fields);

// Fail closed - the only alternative to a column list is SELECT *, which is what leaked the PIN
if (empty($contact_fields)) {
    header("HTTP/1.1 500 Internal Server Error");
    echo json_encode(['success' => 'False', 'message' => 'Unable to read the contact schema.']);
    exit();
}

// Specific contact via ID (single)
if (isset($_GET['contact_id'])) {
    $id = intval($_GET['contact_id']);
    $sql = mysqli_query($mysqli, "SELECT $contact_fields FROM contacts WHERE contact_id = '$id' AND contact_client_id LIKE '$client_id'");

} elseif (isset($_GET['contact_email'])) {
    // Specific contact via email (single)
    $email = mysqli_real_escape_string($mysqli, $_GET['contact_email']);
    $sql = mysqli_query($mysqli, "SELECT $contact_fields FROM contacts WHERE contact_email = '$email' AND contact_client_id LIKE '$client_id'");

} elseif (isset($_GET['contact_phone_or_mobile'])) {
    // Specific contact via phone number or mobile (single)
    // The OR group MUST stay parenthesised: AND binds tighter than OR, so without the
    // brackets this parsed as "mobile = X OR (phone = X AND client scope)" and any mobile
    // match returned a contact belonging to a department the API key isn't scoped to.
    $phone_or_mob = mysqli_real_escape_string($mysqli, $_GET['contact_phone_or_mobile']);
    $sql = mysqli_query($mysqli, "SELECT $contact_fields FROM contacts WHERE (contact_mobile = '$phone_or_mob' OR contact_phone = '$phone_or_mob') AND contact_client_id LIKE '$client_id' LIMIT 1");

} else {
    // All contacts (by client ID, or all in general if key permits)
    $sql = mysqli_query($mysqli, "SELECT $contact_fields FROM contacts WHERE contact_client_id LIKE '$client_id' ORDER BY contact_id LIMIT $limit OFFSET $offset");
}

// Output
require_once "../read_output.php";

