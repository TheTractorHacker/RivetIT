<?php

require_once '../validate_api_key.php';
require_once '../require_post_method.php';

// Default
$update_count = false;

if (!empty($client_id)) {

    // Fetch client info
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "
        SELECT client_name
        FROM clients
        WHERE client_id = $client_id AND client_archived_at IS NULL
        LIMIT 1
    "));

    if ($row) {

        $client_name = sanitizeInput($row['client_name']);

        // Archive client
        $update_sql = mysqli_query($mysqli, "UPDATE clients SET client_archived_at = NOW() WHERE client_id = $client_id");

        if ($update_sql) {
            $update_count = mysqli_affected_rows($mysqli);

            // Logging
            logAction("Contact", "Archive", "$client_name archived via API ($api_key_name)", $client_id);
        }
    }
}

// Output
require_once '../update_output.php';
