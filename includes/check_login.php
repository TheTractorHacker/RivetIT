<?php

require_once "session_init.php";
require_once "redirect_if_setup_enabled.php";
require_once "auth_check.php";
require_once "inc_set_timezone.php";
require_once "load_user_session.php";
require_once "load_company_settings.php";
require_once "load_global_settings.php";
require_once "detect_device_type.php";

// Roles audit P0: a module-only (limited) login - no Departments, Tickets/assets/docs or Assets - may reach
// only its own modules' pages, its account and its notifications (includes/module_access.php). Everyone
// else is 'allow' here and keeps each page's own checks. Runs at the top level of every agent/admin
// request, so the denial page below is drawn in the global scope like any other page.
$itflow_limited_decision = itflow_limited_access_decision();
if ($itflow_limited_decision === 'home') {
    header('Location: ' . itflow_home_url());
    exit;
}
if ($itflow_limited_decision === 'deny') {
    itflow_render_denied();
}
unset($itflow_limited_decision);
