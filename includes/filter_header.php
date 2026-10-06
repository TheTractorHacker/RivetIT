<?php
/*
 * Filter - Head
 * Sets the paging/sort for use in limit/order by
 * Sets the default search query from GET to $q
 *
 * Should not be accessed directly, but called from other pages
 */

// Unset Array Var to prevent Duplicate Get VARs
$get_copy = $_GET; // create a copy of the $_GET array
//unset($get_copy['page']);
unset($get_copy['sort']);
unset($get_copy['order']);
//Rebuild URL
$url_query_strings_sort = http_build_query($get_copy);

// Paging
if (isset($_GET['page'])) {
    $page = intval($_GET['page']);
    $record_from = (($page)-1)*$user_config_records_per_page;
    $record_to = $user_config_records_per_page;
} else {
    $record_from = 0;
    $record_to = $user_config_records_per_page;
    $page = 1;
}

if (isset($_GET['order']) && $_GET['order'] == 'ASC') {
    $order = "ASC";
    $disp = "DESC";
}

if (isset($_GET['order']) && $_GET['order'] == 'DESC') {
    $order = "DESC";
    $disp = "ASC";
}

// Order
// $disp is only the flip of the CURRENT order and is wrong for a different column; heading links use sortLinkOrder($col)
// (functions.php) instead. $disp is kept for any older caller.
if(isset($order) && $order == "ASC") {
    $disp = "DESC";
    $order_icon = "<i class='fas fa-sort-down'></i>";
} else {
    $disp = "ASC";
    $order_icon = "<i class='fas fa-sort-up'></i>";
}

// Search
if (isset($_GET['q'])) {
    $q = sanitizeInput($_GET['q']);
    //Phone Numbers
    $phone_query = preg_replace("/[^0-9]/", '', $q);
    if (empty($phone_query)) {
        $phone_query = $q;
    }
} else {
    $q = "";
    $phone_query = "";
}

// Sortby
if (!empty($_GET['sort'])) {
    $sort = sanitizeInput(preg_replace('/[^a-z_]/', '', $_GET['sort'])); // JQ 2023-05-09 - See issue #673 on GitHub to see the reasoning why we used preg_replace technically sanitizeInput() should have been enough to escape SQL Commands
}

// Date Handling
// Resolved by RivetCore\Ui\DateRange (includes/date_range.php) in the app's timezone. Legacy ids (today..lastyear, alltime,
// custom) resolve exactly as before; the newer presets (last7, last30, thisquarter, next7, ...) work on every page that
// includes this file. $dtf / $dtt stay validated Y-m-d strings (all time = 1970-01-01 / 2099-12-31) and $date_range is the
// DateRange object (use dateRangeSqlBetween() for sargable queries). $date_range_explicit tells a page whether the request
// actually carried a range, so a report with its own default can apply it.
require_once __DIR__ . '/date_range.php';

$date_range_explicit = (!empty($_GET['canned_date']) && $_GET['canned_date'] !== 'custom') || !empty($_GET['dtf']) || !empty($_GET['dtt']);
$date_range = dateRangeFromRequest($_GET);

if (empty($_GET['canned_date'])) {
    //Prevents lots of undefined variable errors (pages read $_GET['canned_date'] directly).
    $_GET['canned_date'] = 'custom';
}

$dtf = $date_range->from();
$dtt = $date_range->to();

// Archived
if (isset($_GET['archived']) && $_GET['archived'] == 1) {
    $archived = 1;
    $archive_query = "archived_at IS NOT NULL";
} else {
    $archived = 0;
    $archive_query = "archived_at IS NULL";
}
