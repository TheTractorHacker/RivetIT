<?php

/*
 * Training automation cards on Admin > Training (LMS Phase 5, spec §4.3): every ta_* form on
 * admin/settings_training.php. Required at the end of admin/post/settings_training.php, which
 * admin/post.php includes for admins only (the Referer is the one-page settings).
 *
 * CSRF first; then ITFlow\Training\Settings\AutomationActions picks the action, checks the schema,
 * calls the owning lane's handler (OdooSync\OdooAdmin, Certificates\CertAdmin,
 * Reminders\ReminderAdmin) and writes logAction + the training.automation_saved audit event. The same
 * class serves agent/training_settings.php for Training 3, where the admin-only actions are refused.
 * Returns to the card's anchor (browsers never send the #fragment, so the Referer is pointed there).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (class_exists('ITFlow\Training\Settings\AutomationActions')
    && \ITFlow\Training\Settings\AutomationActions::actionIn($_POST) !== null) {

    validateCSRFToken($_POST['csrf_token'] ?? '');

    $ta_out = \ITFlow\Training\Settings\AutomationActions::handle($mysqli, $_POST, $_FILES, [
        'user_id' => intval($session_user_id),
        'name' => (string) $session_name,
        'is_admin' => ($session_is_admin ?? false) === true,
    ], true);
    $_SERVER['HTTP_REFERER'] = '/admin/settings_training.php' . ($ta_out['anchor'] !== '' ? '#' . $ta_out['anchor'] : '');
    flash_alert($ta_out['message'], $ta_out['type']);
    redirect();
}
