<?php

/*
 * Kiosk & sign-in action of admin/settings_training.php (P3 spec §5.8; a page of its own,
 * settings_training_kiosk.php, until 2026-09-26). Required by admin/post/settings_training.php,
 * which admin/post.php includes for admins only; admin/post.php also includes this file directly
 * for a POST whose Referer is the old page URL (now a 302 to settings_training.php#kiosk).
 *
 * edit_training_kiosk_settings: CSRF -> ITFlow\Training\Settings\SettingsService::saveKiosk() with
 * every threshold and the Odoo-PIN switch (each threshold clamped by KioskSettings::clamp, one
 * UPDATE, logAction + audit 'training.kiosk_settings_changed' with old/new numbers only) -> flash
 * + redirect. The same service saves the session timeouts and setup-slip validity from
 * agent/training_settings.php (Training 3 + Kiosk 3); the rest stays admin-only (roles audit P2).
 * Runtime state columns (pauses, breaker, sync time) are never written here.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_training_kiosk_settings'])) {

    validateCSRFToken($_POST['csrf_token'] ?? '');

    $tk_out = (new \ITFlow\Training\Settings\SettingsService($mysqli, intval($session_user_id), $session_name))->saveKiosk($_POST, true);
    flash_alert($tk_out['message'], $tk_out['type']);
    redirect();
}
