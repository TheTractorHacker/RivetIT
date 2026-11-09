<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Audit\AuditService;
use RivetCore\Compliance\RetentionPolicy;

if (isset($_POST['save_compliance_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    $profile = (string) ($_POST['compliance_profile'] ?? '');
    if (!RetentionPolicy::isValidProfile($profile)) {
        flash_alert('Choose one of the listed presets.', 'error');
        redirect();
    }
    $audit_days = max(0, min(36500, intval($_POST['audit_retention_days'] ?? 0)));
    $log_days = max(0, min(36500, intval($_POST['log_retention_days'] ?? 0)));

    $row = null;
    $res = @mysqli_query($mysqli, "SELECT config_compliance_profile, config_audit_retention_days, config_log_retention FROM settings WHERE company_id = 1");
    if ($res) {
        $row = mysqli_fetch_assoc($res) ?: null;
    }
    if (!$row) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }

    // A preset is a floor: a positive value below it is raised to it. 0 (keep forever) is always allowed.
    $raised = [];
    if (RetentionPolicy::isBelowFloor($profile, $audit_days)) {
        $raised[] = 'audit trail';
        $audit_days = RetentionPolicy::floorDays($profile);
    }
    if (RetentionPolicy::isBelowFloor($profile, $log_days)) {
        $raised[] = 'activity log';
        $log_days = RetentionPolicy::floorDays($profile);
    }

    $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_compliance_profile = ?, config_audit_retention_days = ?, config_log_retention = ? WHERE company_id = 1");
    mysqli_stmt_bind_param($stmt, 'sii', $profile, $audit_days, $log_days);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logAction('Settings', 'Edit', "$session_name changed compliance settings");
    AuditService::record('compliance.settings_changed', (int) $session_user_id, 'settings', 'compliance', 'update', 'Compliance settings changed', [
        'before' => ['profile' => $row['config_compliance_profile'], 'audit_days' => (int) $row['config_audit_retention_days'], 'log_days' => (int) $row['config_log_retention']],
        'after' => ['profile' => $profile, 'audit_days' => $audit_days, 'log_days' => $log_days],
    ]);

    $message = 'Compliance settings saved.';
    if ($raised) {
        $message .= ' The ' . implode(' and ', $raised) . ' retention was raised to ' . RetentionPolicy::floorDays($profile) . ' days, the minimum for ' . RetentionPolicy::PROFILES[$profile]['label'] . '.';
    }
    flash_alert($message, $raised ? 'warning' : 'success');
    redirect();
}
