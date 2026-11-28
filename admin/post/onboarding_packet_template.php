<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['save_onboarding_packet_template'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    try {
        $json = \ITFlow\Onboarding\PacketTemplate::encode($_POST);
    } catch (\InvalidArgumentException $e) {
        flash_alert(nullable_htmlentities($e->getMessage()), 'error');
        redirect('onboarding_packet_template.php');
    }
    $json_sql = mysqli_real_escape_string($mysqli, $json);
    mysqli_query($mysqli, "UPDATE settings SET config_onboarding_packet_template = '$json_sql' WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited the onboarding packet template");

    flash_alert('Onboarding packet template saved');
    redirect('onboarding_packet_template.php');
}

if (isset($_POST['reset_onboarding_packet_template'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    mysqli_query($mysqli, "UPDATE settings SET config_onboarding_packet_template = NULL WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name restored the onboarding packet template defaults");

    flash_alert('Restored the built-in wording');
    redirect('onboarding_packet_template.php');
}
