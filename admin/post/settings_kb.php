<?php

/*
 * Handler for admin/settings_kb.php.
 *
 * The filename is load-bearing: admin/post.php derives the module from the basename of
 * the HTTP referer, so this file must stay named after the page that posts to it.
 *
 * The submit name must also stay unique across admin/post/ - admin/post/settings_module.php
 * already owns 'edit_module_settings', and both pages write config_module_enable_kb.
 * Because each handler only fires on its own submit name, there is no last-writer hazard,
 * but if the meaning of the toggle ever changes both pages need updating.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_kb_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $config_module_enable_kb = intval($_POST['config_module_enable_kb'] ?? 0);

    mysqli_query($mysqli, "UPDATE settings SET config_module_enable_kb = $config_module_enable_kb WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited knowledge base settings");

    flash_alert("Knowledge Base Settings updated");

    redirect();

}
