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

    // HR requests (DB 2.6.232): its own switch, audited when it changes. Skipped on an install that has not applied the update.
    if (!empty($config_portal_hr_ready)) {
        $hr_on = isset($_POST['hr_requests']) && $_POST['hr_requests'] === '1' ? 1 : 0;
        if ($hr_on !== intval($config_portal_hr_requests)) {
            mysqli_query($mysqli, "UPDATE settings SET config_portal_hr_requests = $hr_on WHERE company_id = 1");
            rivetAudit('settings.portal_hr_requests_changed', (int) $session_user_id, 'settings', 'config_portal_hr_requests', 'update', 'Portal HR requests turned ' . ($hr_on ? 'on' : 'off'));
        }
    }

    logAction("Settings", "Edit", "$session_name edited employee portal settings");

    flash_alert("Employee portal settings updated");

    redirect();
}
