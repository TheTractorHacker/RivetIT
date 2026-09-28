<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_telemetry_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Telemetry was removed (it only ever sent data to the upstream ITFlow project, never to this
    // fork); config_telemetry is forced to 0 rather than read from $_POST. The column stays so no
    // migration is needed and any historical value is not misread as "still sending".
    mysqli_query($mysqli,"UPDATE settings SET config_telemetry = 0 WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited telemetry settings");

    flash_alert("Telemetry Settings updated");

    redirect();

}
