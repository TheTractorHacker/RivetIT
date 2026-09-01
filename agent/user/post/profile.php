<?php

/*
 * ITFlow - GET/POST request handler for user profiles (tech/agent)
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

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
        $body = "Hi $name, <br><br>Your $config_app_name account has been updated, details below: <br><br> <b>$details</b> <br><br> If you did not perform this change, contact your $config_app_name administrator immediately. <br><br>Thanks, <br>ITFlow<br>$session_company_name";

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

            // Delete old file
            unlink("../../uploads/users/$session_user_id/$existing_file_name");

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

    unlink("../../uploads/users/$session_user_id/$user_avatar");

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
    $new_password = password_hash($new_password, PASSWORD_DEFAULT);
    $user_specific_encryption_ciphertext = encryptUserSpecificKey($plain_new_password);
    mysqli_query($mysqli,"UPDATE users SET user_password = '$new_password', user_specific_encryption_ciphertext = '$user_specific_encryption_ciphertext' WHERE user_id = $session_user_id");

    logAction("User Account", "Edit", "$session_name changed their password");

    flash_alert("Your password was updated");

    redirect('post.php?logout');
}

if (isset($_POST['edit_your_user_preferences'])) {

    validateCSRFToken($_POST['csrf_token']);

    $calendar_first_day = intval($_POST['calendar_first_day']);
    $dark_mode = intval($_POST['dark_mode'] ?? 0);

    // Calendar
    $records_per_page = in_array(intval($_POST['records_per_page'] ?? 10), [10,25,50,100])
        ? intval($_POST['records_per_page'])
        : 10;

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

        // SUCCESS
        mysqli_query($mysqli,"UPDATE users SET user_token = '$token' WHERE user_id = $session_user_id");

        // Delete any existing MFA tokens - these browsers should be re-validated
        mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $session_user_id");

        logAction("User Account", "Edit", "$session_name enabled MFA on their account");

        flash_alert("Multi-Factor authentication enabled");

        // Clear the mfa_token from the session to avoid re-use.
        unset($_SESSION['mfa_token']);

        // Check if the previous page is mfa_enforcement.php
        if (isset($_SERVER['HTTP_REFERER'])) {
            $previousPage = basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH));
            if ($previousPage === 'mfa_enforcement.php') {
                // Redirect back to mfa_enforcement.php
                redirect("../$config_start_page");

            }
        }

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

    // Delete any existing MFA tokens - these browsers should be re-validated
    mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_user_id = $session_user_id");

    // Sanitize Config Vars from get_settings.php and Session Vars from check_login.php
    $config_mail_from_name = sanitizeInput($config_mail_from_name);
    $config_mail_from_email = sanitizeInput($config_mail_from_email);
    $config_app_name = sanitizeInput($config_app_name);

    // Email notification
    if (!empty($config_smtp_host)) {
        $subject = "$config_app_name account update confirmation for $session_name";
        $body = "Hi $session_name, <br><br>Your $config_app_name account has been updated, details below: <br><br> <b>2FA was disabled.</b> <br><br> If you did not perform this change, contact your $config_app_name administrator immediately. <br><br>Thanks, <br>ITFlow<br>$session_company_name";

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
        if (firebase_send_push($tok['token_fcm_token'], 'ITFlow Test', 'Push notifications are working!', ['type' => 'test'])) {
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
