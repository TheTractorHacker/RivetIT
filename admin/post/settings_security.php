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

if (isset($_POST['edit_security_policy'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once __DIR__ . '/../../includes/security_policy.php';

    $before = secSettingsAll($mysqli, true);
    $new = [
        'mfa_policy'            => (string) ($_POST['mfa_policy'] ?? 'off'),
        'mfa_grace_days'        => intval($_POST['mfa_grace_days'] ?? 7),
        'remember_me_skips_mfa' => isset($_POST['remember_me_skips_mfa']) ? 1 : 0,
        'password_min_length'   => intval($_POST['password_min_length'] ?? 12),
        'password_hibp_check'   => isset($_POST['password_hibp_check']) ? 1 : 0,
        'session_idle_minutes'  => intval($_POST['session_idle_minutes'] ?? 480),
        'vault_stepup_minutes'  => intval($_POST['vault_stepup_minutes'] ?? 15),
        'vault_reveal_limit'    => intval($_POST['vault_reveal_limit'] ?? 30),
    ];
    $changes = [];
    foreach ($new as $k => $v) {
        $stored = secSettingNormalize($k, $v);
        if ($stored !== $before[$k]) {
            $changes[] = "$k: {$before[$k]} -> $stored";
        }
        secSettingSet($mysqli, $k, $v);
    }
    // The grace period for a newly required MFA policy starts when the policy is switched on (or widened).
    $rank = ['off' => 0, 'admins' => 1, 'all' => 2];
    $policy_after = secSettingNormalize('mfa_policy', $new['mfa_policy']);
    if (($rank[$policy_after] ?? 0) > ($rank[$before['mfa_policy']] ?? 0)) {
        secSettingSet($mysqli, 'mfa_policy_since', date('Y-m-d H:i:s'));
    }

    if ($changes) {
        logAction("Settings", "Edit", "$session_name changed the sign-in policy (" . implode('; ', $changes) . ")");
        \ITFlow\Audit\AuditService::record('settings.security_policy_changed', $session_user_id, 'settings', 'security_policy', 'edit', "$session_name changed the sign-in policy", ['changes' => $changes]);
    }
    flash_alert('Sign-in policy saved');
    redirect();
}

if (isset($_POST['edit_security_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $config_login_message = sanitizeInput($_POST['config_login_message']);
    $config_login_key_required = intval($_POST['config_login_key_required'] ?? 0);
    $config_login_key_secret = sanitizeInput($_POST['config_login_key_secret']);
    $config_login_remember_me_expire = max(30, intval($_POST['config_login_remember_me_expire']));
    $config_login_session_lifetime = max(60, min(129600, intval($_POST['config_login_session_lifetime'] ?? 20160)));
    $config_log_retention = max(0, intval($_POST['config_log_retention']));
    // A compliance preset (Administration > Compliance) is a minimum: a shorter retention is raised to it. 0 keeps everything.
    $security_retention_raised = false;
    $compliance_row = @mysqli_fetch_assoc(@mysqli_query($mysqli, "SELECT config_compliance_profile FROM settings WHERE company_id = 1"));
    if ($compliance_row && class_exists(\RivetCore\Compliance\RetentionPolicy::class)
        && \RivetCore\Compliance\RetentionPolicy::isBelowFloor((string) $compliance_row['config_compliance_profile'], $config_log_retention)) {
        $config_log_retention = \RivetCore\Compliance\RetentionPolicy::floorDays((string) $compliance_row['config_compliance_profile']);
        $security_retention_raised = true;
    }

    // Network path: blank = not configured (legacy IP detection); otherwise 0-10 local reverse proxies
    $net_posted = isset($_POST['config_proxy_hops']);
    $proxy_hops_raw = trim($_POST['config_proxy_hops'] ?? '');
    $config_proxy_hops_sql = ($proxy_hops_raw === '' || !ctype_digit($proxy_hops_raw)) ? 'NULL' : max(0, min(10, intval($proxy_hops_raw)));
    $config_behind_cloudflare = intval($_POST['config_behind_cloudflare'] ?? 0) === 1 ? 1 : 0;

    // Disallow turning on login key without a secret
    if (empty($config_login_key_secret)) {
        $config_login_key_required = 0;
    }

    // The login key secret is stored wrapped (encryptSetting); an empty one stays empty.
    require_once __DIR__ . '/../../includes/security_crypto.php';
    $config_login_key_secret_stored = mysqli_real_escape_string($mysqli, secWrapIfPlain(trim(strip_tags((string) ($_POST['config_login_key_secret'] ?? '')))));

    mysqli_query($mysqli,"UPDATE settings SET config_login_message = '$config_login_message', config_login_key_required = '$config_login_key_required', config_login_key_secret = '$config_login_key_secret_stored', config_login_remember_me_expire = $config_login_remember_me_expire, config_login_session_lifetime = $config_login_session_lifetime, config_log_retention = $config_log_retention" . ($net_posted ? ", config_proxy_hops = $config_proxy_hops_sql, config_behind_cloudflare = $config_behind_cloudflare" : '') . " WHERE company_id = 1");

    // Permanent deletes (Delete on archived records). Only written when the form carried the marker, so a partial
    // post (script, other form) can never switch it off by omission. Off unless ticked; the change is audit-logged.
    if (isset($_POST['destructive_deletes_present'])) {
        $destructive_new = intval($_POST['config_destructive_deletes_enable'] ?? 0) === 1 ? 1 : 0;
        $destructive_old = intval($config_destructive_deletes_enable ?? 0);
        if ($destructive_new !== $destructive_old) {
            mysqli_query($mysqli, "UPDATE settings SET config_destructive_deletes_enable = $destructive_new WHERE company_id = 1");
            logAction("Settings", "Edit", "$session_name " . ($destructive_new ? "ENABLED" : "disabled") . " permanent deletes of archived records (config_destructive_deletes_enable)");
        }
    }

    logAction("Settings", "Edit", "$session_name edited security settings");

    flash_alert($security_retention_raised ? "Security settings updated. Log retention was raised to " . $config_log_retention . " days, the minimum for your compliance preset." : "Security settings updated", $security_retention_raised ? "warning" : "success");

    redirect();

}
