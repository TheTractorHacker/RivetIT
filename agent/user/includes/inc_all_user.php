<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
// Buffer the page (roles audit P1h) so a permission check that runs after the shell is printed can still
// answer HTTP 403 (itflow_render_denied); PHP flushes this buffer when the page ends.
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/page_title.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/top_nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/user/includes/user_side_nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_wrapper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_alert_feedback.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
