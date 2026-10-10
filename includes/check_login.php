<?php

require_once "session_init.php";
require_once "redirect_if_setup_enabled.php";
require_once "auth_check.php";
require_once "inc_set_timezone.php";
require_once "load_user_session.php";
require_once "load_company_settings.php";
require_once "load_global_settings.php";
require_once "detect_device_type.php";

// MFA enforcement (Admin > Settings > Security > Sign-in policy, or a user's own "require MFA" flag): an agent who must have two-factor,
// has not set it up and is out of the grace period can only reach the account pages needed to enrol. In grace, a one-time notice
// names the deadline. Department (portal) logins are handled by client/includes/check_login.php.
if (($session_user_type ?? 0) === 1) {
    secMfaGate($mysqli, intval($session_user_id), itflow_request_script());
}

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
    // The Training settings hint for a Training Full login on an admin Training page; otherwise which
    // permission this page needs (the default "Go to <home>" button either way unless the hint names one).
    itflow_render_denied(itflow_training_settings_hint() ?: itflow_limited_denial_detail(), "You don't have access to this page", itflow_training_settings_go());
}
unset($itflow_limited_decision);
