<?php

/*
 * COMPANY-WIDE page shell.
 *
 * Identical to includes/inc_all.php except for one line: it includes
 * client_overview_side_nav.php (the company-wide rail) where inc_all.php includes
 * side_nav.php (the app-level rail). It never includes get_side_nav_counts.php,
 * because the company-wide rail computes its own counts.
 *
 * WHO INCLUDES THIS, AND ON WHAT CONDITION
 *   contacts.php, contact_details.php, assets.php, asset_details.php,
 *   networks.php, services.php   - unconditionally, on the no-client_id branch.
 *       These pages have no app-level branch; a bare URL means company-wide.
 *
 *   locations.php, software.php, credentials.php, certificates.php, domains.php
 *       - on the no-client_id branch AND ONLY WHEN the URL says ?scope=company.
 *       These five are also destinations of the app-level rail (side_nav.php links
 *       to them under Infrastructure and Knowledge), so a bare URL is genuinely
 *       ambiguous there and has to keep meaning "app-level". Without that marker
 *       the company-wide rail's own links to them drew side_nav.php instead, and
 *       the rail the user was standing in disappeared after a single click.
 *
 * The scope is therefore always stated in the URL - by ?client_id=N (one
 * department), by ?scope=company (company-wide), or by neither (app-level). It is
 * never carried over in $_SESSION and never inferred from a stale client_id.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
// Buffer the page (roles audit P1h) so a permission check that runs after the shell is printed can still
// answer HTTP 403 (itflow_render_denied); PHP flushes this buffer when the page ends.
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/page_title.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/top_nav.php';
require_once 'includes/client_overview_side_nav.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_wrapper.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_alert_feedback.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
