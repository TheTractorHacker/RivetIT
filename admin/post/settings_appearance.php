<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_appearance_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Preset accent (AdminLTE accent name). Restrict to letters/digits/hyphen like the theme handler.
    $config_theme = preg_replace("/[^0-9a-zA-Z-]/", "", sanitizeInput($_POST['config_theme'] ?? ''));
    if ($config_theme === '') {
        $config_theme = 'teal';
    }

    // Custom accent hex - only accept a valid #RRGGBB value, otherwise clear it (NULL) so the preset is used.
    $accent_input = trim($_POST['config_theme_accent_custom'] ?? '');
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $accent_input)) {
        $accent_custom_sql = "'" . strtoupper(mysqli_real_escape_string($mysqli, $accent_input)) . "'";
    } else {
        $accent_custom_sql = "NULL";
    }

    // Card radius (px). Clamp 0-40; blank/0 clears the override (NULL).
    $radius_px = intval($_POST['config_theme_card_radius'] ?? 0);
    if ($radius_px > 0) {
        if ($radius_px > 40) { $radius_px = 40; }
        $card_radius_sql = "'" . $radius_px . "px'";
    } else {
        $card_radius_sql = "NULL";
    }

    // Company-wide default dark mode
    $config_theme_dark_default = intval($_POST['config_theme_dark_default'] ?? 0);

    mysqli_query($mysqli, "UPDATE settings SET config_theme = '$config_theme', config_theme_accent_custom = $accent_custom_sql, config_theme_card_radius = $card_radius_sql, config_theme_dark_default = $config_theme_dark_default WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited appearance settings");

    flash_alert("Appearance settings updated");

    redirect();

}
