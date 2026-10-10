<?php

/*
 * RivetIT - GET/POST request handler for user profiles (tech/agent)
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../../includes/security_crypto.php';
require_once __DIR__ . '/../../../includes/security_policy.php';
require_once __DIR__ . '/../../../includes/security_sessions.php';

if (isset($_POST['edit_your_user_details'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name = sanitizeInput($_POST['name']);
    $email = sanitizeInput($_POST['email']);
    $title = mysqli_real_escape_string($mysqli, sanitizeInput($_POST['title'] ?? ''));
    $phone = mysqli_real_escape_string($mysqli, sanitizeInput($_POST['phone'] ?? ''));
    $signature = mysqli_escape_string($mysqli,$_POST['signature']);

    $existing_file_name = sanitizeInput(getFieldById('users', $session_user_id, 'user_avatar'));

    $logout = false;
    $extended_log_description = '';

    // Email notification when password or email is changed
    $user_old_email_sql = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_email FROM users WHERE user_id = $session_user_id"));
    $user_old_email = sanitizeInput($user_old_email_sql['user_email']);

    // Sanitize Config Vars from get_settings.php and Session Vars from check_login.php
    $config_mail_from_name = sanitizeInput($config_mail_from_name);
    $config_mail_from_email = sanitizeInput($config_mail_from_email);
    $config_app_name = sanitizeInput($config_app_name);

    if (!empty($config_smtp_host) && ($user_old_email !== $email)) {

        $details = "Your email address was changed. New email: $email.";

        $subject = "$config_app_name account update confirmation for $name";
        $body = "Hi $name, <br><br>Your $config_app_name account has been updated, details below: <br><br> <b>$details</b> <br><br> If you did not perform this change, contact your $config_app_name administrator immediately. <br><br>Thanks, <br>$config_app_name<br>$session_company_name";

        $data = [
            [
                'from' => $config_mail_from_email,
                'from_name' => $config_mail_from_name,
                'recipient' => $user_old_email,
                'recipient_name' => $name,
                'subject' => $subject,
                'body' => $body
            ]
        ];
        $mail = addToMailQueue($data);
    }

    // Photo
    if (isset($_FILES['avatar']['tmp_name'])) {
        if ($new_file_name = checkFileUpload($_FILES['avatar'], array('jpg', 'jpeg', 'gif', 'png', 'webp'))) {

            $file_tmp_path = $_FILES['avatar']['tmp_name'];

            // directory in which the uploaded file will be moved
            $upload_file_dir = "../../uploads/users/$session_user_id/";
            $dest_path = $upload_file_dir . $new_file_name;

            if (!file_exists("$upload_file_dir")) {
                mkdir("$upload_file_dir");
            }

            move_uploaded_file($file_tmp_path, $dest_path);

            // Delete old file (only if one was actually set - otherwise this
            // unlinks the user's upload directory itself, since there's no
            // filename to append, and throws a warning on every first upload)
            if ($existing_file_name) {
                unlink("../../uploads/users/$session_user_id/$existing_file_name");
            }

            // Set Avatar
            mysqli_query($mysqli,"UPDATE users SET user_avatar = '$new_file_name' WHERE user_id = $session_user_id");

            // Extended Logging
            $extended_log_description .= ", avatar updated";

        }
    }

    mysqli_query($mysqli,"UPDATE users SET user_name = '$name', user_email = '$email', user_title = '$title', user_phone = '$phone' WHERE user_id = $session_user_id");

    mysqli_query($mysqli,"UPDATE user_settings SET user_config_signature = '$signature' WHERE user_id = $session_user_id");

    logAction("User Account", "Edit", "$session_name edited their account $extended_log_description");

    flash_alert("User details updated");

    if ($logout) {
        redirect('post.php?logout');
    } else {
        redirect();
    }

}

if (isset($_GET['clear_your_user_avatar'])) {

    validateCSRFToken($_GET['csrf_token']);

    $user_avatar = sanitizeInput(getFieldById('users', $session_user_id, 'user_avatar'));

    if ($user_avatar) {
        unlink("../../uploads/users/$session_user_id/$user_avatar");
    }

    mysqli_query($mysqli,"UPDATE users SET user_avatar = NULL WHERE user_id = $session_user_id");

    logAction("User Account", "Edit", "$session_name cleared their avatar");

    flash_alert("Avatar cleared", 'error');

    redirect();

}

if (isset($_POST['edit_your_user_password'])) {

    validateCSRFToken($_POST['csrf_token']);

    $new_password = trim($_POST['new_password']);
    $current_password = $_POST['current_password'] ?? '';

    if (empty($new_password) || empty($current_password)) {
        redirect('user_security.php');
    }

    // Verify current password before allowing change
    $pw_check = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_password FROM users WHERE user_id = $session_user_id"));
    if (!$pw_check || !password_verify($current_password, $pw_check['user_password'])) {
        flash_alert('Current password is incorrect', 'error');
        redirect('user_security.php');
    }

    // Staff password policy (length, not the name / email, optional breach check)
    $policy_error = secPasswordPolicyError($new_password, ['name' => $session_name, 'email' => $session_email, 'username' => $session_email], $mysqli);
    if ($policy_error !== null) {
        flash_alert($policy_error, 'error');
        redirect('user_security.php');
    }

    // Email notification when password or email is changed
    $user_sql = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE user_id = $session_user_id"));
    $name = sanitizeInput($user_sql['user_name']);
    $user_email = sanitizeInput($user_sql['user_email']);

    // Sanitize Config Vars from get_settings.php and Session Vars from check_login.php
    $config_mail_from_name = sanitizeInput($config_mail_from_name);
    $config_mail_from_email = sanitizeInput($config_mail_from_email);
    $config_app_name = sanitizeInput($config_app_name);

    if (!empty($config_smtp_host)){

        $details = "Your password was changed.";

        $subject = "$config_app_name account update confirmation for $name";
        $body = "Hi $name, <br><br>Your $config_app_name account has been updated, details below: <br><br> <b>$details</b> <br><br> If you did not perform this change, contact your $config_app_name administrator immediately. <br><br>Thanks, <br>$config_app_name";

        $data = [
            [
                'from' => $config_mail_from_email,
                'from_name' => $config_mail_from_name,
                'recipient' => $user_email,
                'recipient_name' => $name,
                'subject' => $subject,
                'body' => $body
            ]
        ];
        $mail = addToMailQueue($data);
    }

    $plain_new_password = $new_password;
    $new_password = mysqli_real_escape_string($mysqli, secPasswordHash($plain_new_password));
    $user_specific_encryption_ciphertext = encryptUserSpecificKey($plain_new_password);
    mysqli_query($mysqli,"UPDATE users SET user_password = '$new_password', user_specific_encryption_ciphertext = '$user_specific_encryption_ciphertext' WHERE user_id = $session_user_id");

    // A changed password signs the user out everywhere (this browser too: the page below logs out) and ends remember-me cookies.
    $sessions_ended = secSessionsOnPasswordChange($mysqli, $session_user_id, null);
    \ITFlow\Audit\AuditService::record('auth.password_changed', $session_user_id, 'user', $session_user_id, 'success', "$session_name changed their password", ['sessions_revoked' => $sessions_ended]);

    logAction("User Account", "Edit", "$session_name changed their password");

    flash_alert("Your password was updated");

    redirect('post.php?logout');
}

/*
 * Trainer PIN self-service (2.6.104): set/change the SAME contact's trainer sign-in PIN, kept
 * entirely separate from their own learner/Odoo training PIN (training_learner_credentials).
 * Authenticated-as-self only - gated on the logged-in user's OWN user_id matching this trainer
 * row's trainer_user_id (Kiosk\Pin\TrainerCredentialRepo::loadByUserId), never any other trainer's.
 * Deliberately NOT routed through agent/training_ajax.php: that router requires module_training
 * >= 1 for every action, which a trainer with no Training module access at all would not have -
 * this is an account-security action, reachable the same way the password/passkey ones above are.
 */
if (isset($_POST['set_trainer_pin'])) {

    validateCSRFToken($_POST['csrf_token']);

    $trainer = \ITFlow\Training\Kiosk\Pin\TrainerCredentialRepo::loadByUserId($mysqli, $session_user_id);
    if ($trainer === null) {
        flash_alert('You are not set up as an active trainer.', 'error');
        redirect('user_security.php');
    }

    $new_trainer_pin = (string) ($_POST['new_trainer_pin'] ?? '');
    $new_trainer_pin2 = (string) ($_POST['new_trainer_pin2'] ?? '');
    if (!preg_match('/^[0-9]{6}$/', $new_trainer_pin) || $new_trainer_pin !== $new_trainer_pin2) {
        flash_alert('Enter the same 6-digit PIN twice.', 'error');
        redirect('user_security.php');
    }

    try {
        $trainer_pin_keys = \ITFlow\Training\Kiosk\Core\KioskKeys::fromSecret((string) ($config_settings_enc_key ?? ''));
    } catch (\ITFlow\Training\Kiosk\Core\KioskConfigException $e) {
        flash_alert('Training devices are not configured on this server yet.', 'error');
        redirect('user_security.php');
    }
    $trainer_pin_hasher = new \ITFlow\Training\Kiosk\Pin\PinHasher($trainer_pin_keys);
    $trainer_contact_id = (int) $trainer['trainer_contact_id'];
    $trainer_pin_rule = \ITFlow\Training\Kiosk\Pin\PinPolicy::check($trainer_contact_id, $new_trainer_pin, $trainer_pin_hasher, $trainer['trainer_pin_prev_hash']);
    if ($trainer_pin_rule !== null) {
        flash_alert('Pick a different PIN — that one is too easy to guess, or repeats your last one.', 'error');
        redirect('user_security.php');
    }

    $trainer_pin_hash = $trainer_pin_hasher->hashPin($trainer_contact_id, $new_trainer_pin);
    $new_trainer_pin = $new_trainer_pin2 = null;

    \ITFlow\Training\Kiosk\Pin\TrainerCredentialRepo::setPin($mysqli, $trainer_contact_id, $trainer_pin_hash, 'self', null, [
        'actor_type' => 'user', 'actor_user_id' => $session_user_id, 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    logAction("User Account", "Edit", "$session_name set their own trainer PIN");

    flash_alert('Your trainer PIN was updated.');

    redirect('user_security.php');
}

if (isset($_POST['edit_your_user_preferences'])) {

    validateCSRFToken($_POST['csrf_token']);

    $calendar_first_day = intval($_POST['calendar_first_day']);
    $dark_mode = in_array(intval($_POST['dark_mode'] ?? 0), [0, 1, 2]) ? intval($_POST['dark_mode'] ?? 0) : 0; // 0 company default, 1 dark, 2 light

    // Calendar
    $records_per_page = normalizeRecordsPerPage($_POST['records_per_page'] ?? null, intval($user_config_records_per_page ?? 10) ?: 10);

    if (isset($calendar_first_day)) {
        mysqli_query($mysqli, "UPDATE user_settings SET user_config_calendar_first_day = $calendar_first_day, user_config_theme_dark = $dark_mode, user_config_records_per_page = $records_per_page WHERE user_id = $session_user_id");
    }

    // Enable extension access, only if it isn't already setup (user doesn't have cookie)
    if (isset($_POST['extension']) && $_POST['extension'] == 'Yes') {
        if (!isset($_COOKIE['user_extension_key'])) {
            $extension_key = randomString(32);
            mysqli_query($mysqli, "UPDATE users SET user_extension_key = '$extension_key' WHERE user_id = $session_user_id");

            $extended_log_description .= "enabled browser extension access";
            $logout = true;
        }
    }

    // Disable extension access
    if (!isset($_POST['extension'])) {
        mysqli_query($mysqli, "UPDATE users SET user_extension_key = '' WHERE user_id = $session_user_id");
        $extended_log_description .= "disabled browser extension access";
    }

    logAction("User Account", "Edit", "$session_name $extended_log_description");

    flash_alert("User preferences updated");

    redirect();

}

if (isset($_POST['enable_mfa'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once "../../plugins/totp/totp.php";

    // Grab the code from the user
    $verify_code = trim($_POST['verify_code']);
    // Ensure it's numeric
    if (!ctype_digit($verify_code)) {
        $verify_code = '';
    }

    // Grab the secret from the session
    $token = $_SESSION['mfa_token'] ?? '';

    // Verify
    if (TokenAuth6238::verify($token, $verify_code)) {

        // SUCCESS - the seed is stored wrapped (encryptSetting); never in plaintext
        try {
            $token_stored = mysqli_real_escape_string($mysqli, secUserTotpStore($token));
        } catch (\Throwable $e) {
            flash_alert('Two-factor authentication could not be saved securely: the server has no settings encryption key.', 'error');
            redirect('user_security.php');
        }
        mysqli_query($mysqli,"UPDATE users SET user_token = '$token_stored' WHERE user_id = $session_user_id");

        // Ten single-use recovery codes, shown once on the Security page
        $_SESSION['new_recovery_codes'] = secRecoveryCodesGenerate($mysqli, $session_user_id);
        \ITFlow\Audit\AuditService::record('auth.recovery_codes_generated', $session_user_id, 'user', $session_user_id, 'success', "$session_name turned on MFA and received recovery codes");

        // Delete any existing MFA tokens - these browsers should be re-validated
        mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $session_user_id");

        logAction("User Account", "Edit", "$session_name enabled MFA on their account");

        flash_alert("Multi-Factor authentication enabled");

        // Clear the mfa_token from the session to avoid re-use.
        unset($_SESSION['mfa_token']);

        // The recovery codes are shown once on the Security page, including after the enrolment page.
        redirect("user_security.php");

    } else {
        // FAILURE
        flash_alert("Verification code invalid, please try again.", 'error');

        // Set a flag to automatically open the MFA modal again
        $_SESSION['show_mfa_modal'] = true;

        // Check if the previous page is mfa_enforcement.php
        if (isset($_SERVER['HTTP_REFERER'])) {
            $previousPage = basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH));
            if ($previousPage === 'mfa_enforcement.php') {
                // Redirect back to mfa_enforcement.php
                redirect();
            }
        }
    }

    redirect("user_security.php");

}

if (isset($_GET['disable_mfa'])){

    if ($session_user_config_force_mfa) {
        flash_alert("Multi-Factor authentication cannot be disabled for your account", 'error');
        redirect();
    }

    validateCSRFToken($_GET['csrf_token']);

    mysqli_query($mysqli,"UPDATE users SET user_token = '' WHERE user_id = $session_user_id");
    secRecoveryCodesDelete($mysqli, $session_user_id);
    \ITFlow\Audit\AuditService::record('auth.mfa_disabled', $session_user_id, 'user', $session_user_id, 'success', "$session_name turned off MFA");

    // Delete any existing MFA tokens - these browsers should be re-validated
    mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $session_user_id");

    // Sanitize Config Vars from get_settings.php and Session Vars from check_login.php
    $config_mail_from_name = sanitizeInput($config_mail_from_name);
    $config_mail_from_email = sanitizeInput($config_mail_from_email);
    $config_app_name = sanitizeInput($config_app_name);

    // Email notification
    if (!empty($config_smtp_host)) {
        $subject = "$config_app_name account update confirmation for $session_name";
        $body = "Hi $session_name, <br><br>Your $config_app_name account has been updated, details below: <br><br> <b>2FA was disabled.</b> <br><br> If you did not perform this change, contact your $config_app_name administrator immediately. <br><br>Thanks, <br>$config_app_name<br>$session_company_name";

        $data = [
            [
                'from' => $config_mail_from_email,
                'from_name' => $config_mail_from_name,
                'recipient' => $session_email,
                'recipient_name' => $session_name,
                'subject' => $subject,
                'body' => $body
            ]
            ];
        $mail = addToMailQueue($data);
    }

    logAction("User Account", "Edit", "$session_name disabled MFA on their account");

    flash_alert("Multi-Factor authentication disabled", 'error');

    redirect();

}

// New set of recovery codes (the old ones stop working). The password is asked again.
if (isset($_POST['regenerate_recovery_codes'])) {

    validateCSRFToken($_POST['csrf_token']);

    $own = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_password, user_token FROM users WHERE user_id = $session_user_id"));
    if (!$own || !password_verify((string) ($_POST['current_password'] ?? ''), (string) $own['user_password'])) {
        flash_alert('Current password is incorrect', 'error');
        redirect('user_security.php');
    }
    if (empty($own['user_token'])) {
        flash_alert('Turn on two-factor authentication first; recovery codes belong to it.', 'error');
        redirect('user_security.php');
    }

    $_SESSION['new_recovery_codes'] = secRecoveryCodesGenerate($mysqli, $session_user_id);
    logAction("User Account", "Edit", "$session_name regenerated their MFA recovery codes");
    \ITFlow\Audit\AuditService::record('auth.recovery_codes_generated', $session_user_id, 'user', $session_user_id, 'regenerated', "$session_name regenerated their MFA recovery codes");

    redirect('user_security.php');
}

// Active sessions: sign out one other browser
if (isset($_POST['revoke_session'])) {

    validateCSRFToken($_POST['csrf_token']);

    $row_id = intval($_POST['session_row_id'] ?? 0);
    if ($row_id === intval($_SESSION['sec_row'] ?? 0)) {
        flash_alert('That is this browser. Use Sign out to end it.', 'error');
        redirect('user_security.php');
    }
    if (secSessionRevoke($mysqli, $session_user_id, $row_id, 'revoked_by_user')) {
        logAction("User Account", "Edit", "$session_name signed out one of their sessions");
        \ITFlow\Audit\AuditService::record('auth.session_revoked', $session_user_id, 'user', $session_user_id, 'success', "$session_name signed out session #$row_id");
        flash_alert('Session signed out');
    }
    redirect('user_security.php');
}

// Active sessions: sign out everywhere, this browser included
if (isset($_POST['sign_out_everywhere'])) {

    validateCSRFToken($_POST['csrf_token']);

    $ended = secSessionRevokeAll($mysqli, $session_user_id, null, 'sign_out_everywhere');
    mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $session_user_id");
    logAction("User Account", "Edit", "$session_name signed out of every session ($ended)");
    \ITFlow\Audit\AuditService::record('auth.sign_out_everywhere', $session_user_id, 'user', $session_user_id, 'success', "$session_name signed out of every session", ['sessions' => $ended]);

    redirect('post.php?logout');
}

if (isset($_POST['revoke_your_2fa_remember_tokens'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Delete tokens
    mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $session_user_id");

    logAction("User Account", "Edit", "$session_name revoked all their remember-me tokens");

    flash_alert("Remember me tokens revoked", 'error');

    redirect();

}

if (isset($_POST['save_user_color'])) {
    validateCSRFToken($_POST['csrf_token']);

    $color = sanitizeInput($_POST['user_color']);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#3498db';
    }

    mysqli_query($mysqli, "UPDATE users SET user_color = '$color' WHERE user_id = $session_user_id");

    logAction("User Account", "Edit", "$session_name updated their calendar color");
    flash_alert("Calendar color saved.");
    redirect();
}

if (isset($_GET['delete_passkey'])) {

    validateCSRFToken($_GET['csrf_token']);

    $passkey_id = intval($_GET['delete_passkey']);

    // Ensure the passkey belongs to the logged-in user
    $pk = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT passkey_name FROM user_passkeys WHERE passkey_id = $passkey_id AND passkey_user_id = $session_user_id LIMIT 1"
    ));

    if ($pk) {
        $pk_name = sanitizeInput($pk['passkey_name']);
        mysqli_query($mysqli, "DELETE FROM user_passkeys WHERE passkey_id = $passkey_id AND passkey_user_id = $session_user_id");
        logAction("Passkey", "Delete", "$session_name removed passkey '$pk_name'");
        flash_alert("Passkey <strong>$pk_name</strong> removed", 'error');
    }

    redirect();

}


require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/firebase.php';

if (isset($_POST['test_push_notification'])) {
    validateCSRFToken($_POST['csrf_token']);

    $sent = false;
    $sql_tokens = mysqli_query($mysqli, "SELECT token_fcm_token FROM api_tokens WHERE token_user_id = $session_user_id AND token_fcm_token IS NOT NULL");
    while ($tok = mysqli_fetch_assoc($sql_tokens)) {
        if (firebase_send_push($tok['token_fcm_token'], APP_NAME . ' Test Notification', 'Push notifications are working!', ['type' => 'test'])) {
            $sent = true;
        }
    }

    if ($sent) {
        flash_alert('<i class="fas fa-bell me-2"></i>Test push notification sent to your registered devices.');
    } else {
        flash_alert('No registered devices found, or push delivery failed. Make sure the app is logged in with push enabled.', 'error');
    }

    redirect();
}

if (isset($_POST['save_my_push_categories'])) {
    validateCSRFToken($_POST['csrf_token']);

    $valid_keys = array_keys(push_notification_categories());
    $selected   = array_values(array_intersect($_POST['push_categories'] ?? [], $valid_keys));
    $json_esc   = mysqli_real_escape_string($mysqli, json_encode($selected));

    mysqli_query($mysqli, "UPDATE user_settings SET user_config_push_types = '$json_esc' WHERE user_id = $session_user_id");

    flash_alert('Push notification preferences updated.');
    redirect();
}
