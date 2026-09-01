<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['establish_canonical_vault_key'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Recover this admin's session master key (same recovery used by encryptUserSpecificKey()).
    $ciphertext = $_SESSION['user_encryption_session_ciphertext'] ?? '';
    $iv         = $_SESSION['user_encryption_session_iv'] ?? '';
    $sess_key   = $_COOKIE['user_encryption_session_key'] ?? '';
    $master_key = ($ciphertext && $iv && $sess_key)
        ? openssl_decrypt($ciphertext, 'aes-128-cbc', $sess_key, 0, $iv)
        : false;

    if (empty($master_key)) {
        flash_alert("Your vault must be unlocked (signed in with your password this session) to do this", "error");
        redirect();
    }

    setCanonicalVaultKey($mysqli, $master_key);

    logAction("Settings", "Edit", "$session_name established the canonical vault encryption key");

    flash_alert("Canonical vault encryption key established");

    redirect();

}

if (isset($_POST['edit_security_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $config_login_message = sanitizeInput($_POST['config_login_message']);
    $config_login_key_required = intval($_POST['config_login_key_required'] ?? 0);
    $config_login_key_secret = sanitizeInput($_POST['config_login_key_secret']);
    $config_login_remember_me_expire = intval($_POST['config_login_remember_me_expire']);
    $config_login_session_lifetime = max(30, min(43200, intval($_POST['config_login_session_lifetime'] ?? 480)));
    $config_log_retention = intval($_POST['config_log_retention']);

    // Disallow turning on login key without a secret
    if (empty($config_login_key_secret)) {
        $config_login_key_required = 0;
    }

    mysqli_query($mysqli,"UPDATE settings SET config_login_message = '$config_login_message', config_login_key_required = '$config_login_key_required', config_login_key_secret = '$config_login_key_secret', config_login_remember_me_expire = $config_login_remember_me_expire, config_login_session_lifetime = $config_login_session_lifetime, config_log_retention = $config_log_retention WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited security settings");

    flash_alert("Security settings updated");

    redirect();

}
