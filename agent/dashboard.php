<?php

/*
 * agent/dashboard.php used to be its own fixed-layout dashboard (stats, Financial and Technical sections, the
 * cash flow / ticket flow charts, recently resolved tickets, etc.) - "why have two dashboards?" led to all of
 * that becoming widgets a user can add from agent/my_dashboard.php's Customise screen (src/Reports/Widgets/
 * WidgetRegistry.php), which this page now just redirects to. Every link, bookmark and nav entry that used to
 * point here keeps working; ?year= is forwarded since several of the merged-in widgets are "for a year" the
 * same way this page's own Financial/Historical sections were.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

$dash_redirect = 'my_dashboard.php';
if (isset($_GET['year']) && ctype_digit((string) $_GET['year'])) {
    $dash_redirect .= '?year=' . intval($_GET['year']);
}
header('Location: ' . $dash_redirect);
exit;
