<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
// Buffer the page (roles audit P1h) so a permission check that runs after the shell is printed can still
// answer HTTP 403 (itflow_render_denied); PHP flushes this buffer when the page ends.
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/page_title.php';
if (!isset($session_is_admin) || !$session_is_admin) {
    // A proper 403 page in the agent shell with a way back (roles audit P1h), not a bare text line.
    // Training level 3 has its own Training settings page (roles audit P2): the hint says where it is.
    itflow_render_denied(itflow_training_settings_hint()
        ?: 'Administration is for administrators only. Ask an administrator if you need something changed.',
        "You don't have access to this page", itflow_training_settings_go());
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/top_nav.php';
require_once 'includes/side_nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_wrapper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_alert_feedback.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/app_version.php';
