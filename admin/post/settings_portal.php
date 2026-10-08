<?php

/*
 * Handler for admin/settings_portal.php (the filename is load-bearing: admin/post.php picks the handler from the Referer's page name).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_portal_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once __DIR__ . '/../../src/Portal/EmployeeHome.php';

    if (empty($config_portal_settings_ready)) {
        flash_alert('Apply the database update first', 'danger');
        redirect();
    }

    $sections = \ITFlow\Portal\EmployeeHome::formatSections((array) ($_POST['sections'] ?? []));
    $sections_sql = mysqli_real_escape_string($mysqli, $sections);
    $onboarding = isset($_POST['onboarding_requests']) && $_POST['onboarding_requests'] === '1' ? 1 : 0;

    // Only an active onboarding template can be chosen (anything else, including a forged id, becomes "none").
    $template_id = intval($_POST['onboarding_template_id'] ?? 0);
    if (!(new \ITFlow\Portal\EmployeeHome($mysqli))->templateUsable($template_id)) {
        $template_id = 0;
    }

    mysqli_query($mysqli, "UPDATE settings SET config_portal_home_sections = '$sections_sql', config_portal_onboarding_requests = $onboarding, config_portal_onboarding_template_id = $template_id WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited employee portal settings");

    flash_alert("Employee portal settings updated");

    redirect();
}
