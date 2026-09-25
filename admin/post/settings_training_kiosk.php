<?php

/*
 * Handler for admin/settings_training_kiosk.php (P3 spec §5.8). admin/post.php includes this
 * file by the referer's basename, for admins only.
 *
 * edit_training_kiosk_settings: CSRF -> every threshold clamped by KioskSettings::clamp (the same
 * ranges the reader applies) -> one UPDATE -> logAction + audit 'training.kiosk_settings_changed'
 * (old/new numbers only) -> flash + redirect. Runtime state columns (pauses, breaker, sync time)
 * are never written here.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Training\Kiosk\Core\KioskSettings;

if (isset($_POST['edit_training_kiosk_settings'])) {

    validateCSRFToken($_POST['csrf_token'] ?? '');

    $tk_cols = array_keys(KioskSettings::RANGES);
    $tk_cols = array_values(array_filter($tk_cols, static fn($c) => $c !== 'config_training_odoo_breaker_errors'));   // runtime state
    try {
        $tk_old = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT ' . implode(', ', $tk_cols) . ', ' . KioskSettings::SWITCH
            . ' FROM settings WHERE company_id = 1')) ?: [];
    } catch (\mysqli_sql_exception $e) {
        flash_alert('Run the database update first: the Training kiosk settings are not installed yet.', 'error');
        redirect();
    }

    $tk_new = [];
    foreach ($tk_cols as $tk_col) {
        $tk_new[$tk_col] = KioskSettings::clamp($tk_col, $_POST[$tk_col] ?? null);
    }
    if ($tk_new['config_training_pin_hard_failures'] <= $tk_new['config_training_pin_soft_failures']) {
        flash_alert('The hard-lock count must be more than the soft-lock count. Nothing was saved.', 'error');
        redirect();
    }
    $tk_new[KioskSettings::SWITCH] = (($_POST[KioskSettings::SWITCH] ?? '') === '1') ? 1 : 0;

    $tk_set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($tk_new)));
    \ITFlow\Training\Core\Db::exec($mysqli, "UPDATE settings SET $tk_set WHERE company_id = 1", str_repeat('i', count($tk_new)), array_values($tk_new));

    $tk_changed = [];
    foreach ($tk_new as $tk_col => $tk_val) {
        if ((string) ($tk_old[$tk_col] ?? '') !== (string) $tk_val) {
            $tk_changed[$tk_col] = ['from' => isset($tk_old[$tk_col]) ? (int) $tk_old[$tk_col] : null, 'to' => $tk_val];
        }
    }

    logAction("Training", "Edit", "$session_name edited Training kiosk settings");
    if ($tk_changed) {
        \ITFlow\Audit\AuditService::record('training.kiosk_settings_changed', $session_user_id, 'settings', 1, 'update',
            "$session_name changed Training kiosk settings", ['changed' => $tk_changed]);
    }

    if (isset($tk_changed[KioskSettings::SWITCH])) {
        flash_alert($tk_new[KioskSettings::SWITCH] === 1
            ? 'Training kiosk settings saved. Odoo PIN sign-in is ON: people with a usable Odoo PIN now sign in with it.'
            : 'Training kiosk settings saved. Odoo PIN sign-in is off: everyone uses a training PIN.');
    } else {
        flash_alert('Training kiosk settings saved');
    }
    redirect();
}
