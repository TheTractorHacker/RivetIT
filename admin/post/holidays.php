<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Holiday catalog - admin POST/GET handler.
 * Loaded by admin/post.php when the referring page is holidays.php (or a
 * modal under admin/modals/holiday/).
 */

require_once __DIR__ . '/../../includes/holiday_functions.php';

if (isset($_POST['load_holidays'])) {

    validateCSRFToken($_POST['csrf_token']);

    $holiday_country = sanitizeInput($_POST['holiday_country'] ?? '');
    $year_from = intval($_POST['holiday_year_from'] ?? 0);
    $year_to = intval($_POST['holiday_year_to'] ?? 0);

    if (!in_array($holiday_country, getFederalHolidayCountries(), true)) {
        flash_alert("No built-in holiday rules for that country.", 'error');
        redirect();
    }
    if ($year_from < 1970 || $year_to < $year_from || ($year_to - $year_from) > 10) {
        flash_alert("Enter a valid year range (10 years or fewer).", 'error');
        redirect();
    }

    $holiday_country_esc = mysqli_real_escape_string($mysqli, $holiday_country);

    // Existing (country, date) pairs, so running this again for an
    // overlapping range - or a range that includes a year already loaded -
    // never creates duplicates.
    $existing = [];
    $eres = mysqli_query($mysqli, "SELECT holiday_date FROM holidays WHERE holiday_country = '$holiday_country_esc'");
    while ($er = mysqli_fetch_assoc($eres)) { $existing[$er['holiday_date']] = true; }

    $added = 0;
    for ($year = $year_from; $year <= $year_to; $year++) {
        foreach (getFederalHolidaysForCountry($holiday_country, $year) as $h) {
            if (isset($existing[$h['date']])) { continue; }
            $h_date = mysqli_real_escape_string($mysqli, $h['date']);
            $h_name = mysqli_real_escape_string($mysqli, $h['name']);
            mysqli_query($mysqli, "INSERT INTO holidays SET holiday_country = '$holiday_country_esc', holiday_year = $year, holiday_date = '$h_date', holiday_name = '$h_name', holiday_is_custom = 0, holiday_created_by = $session_user_id");
            $existing[$h['date']] = true;
            $added++;
        }
    }

    $term = getHolidayTermForCountry($holiday_country);
    logAction("Holidays", "Create", "$session_name loaded $added $holiday_country $term ($year_from-$year_to)");
    flash_alert("Loaded <strong>$added</strong> $holiday_country $term ($year_from&ndash;$year_to)");
    redirect();

}

if (isset($_POST['add_holiday'])) {

    validateCSRFToken($_POST['csrf_token']);

    $holiday_name = sanitizeInput($_POST['holiday_name'] ?? '');
    $holiday_country = sanitizeInput($_POST['holiday_country'] ?? '');
    $holiday_date_raw = $_POST['holiday_date'] ?? '';
    $d = DateTime::createFromFormat('Y-m-d', $holiday_date_raw);
    if ($d === false) {
        flash_alert("Invalid date", 'error');
        redirect();
    }
    $holiday_date = $d->format('Y-m-d');
    $holiday_year = intval($d->format('Y'));

    mysqli_query($mysqli, "INSERT INTO holidays SET holiday_country = '$holiday_country', holiday_year = $holiday_year, holiday_date = '$holiday_date', holiday_name = '$holiday_name', holiday_is_custom = 1, holiday_created_by = $session_user_id");
    $holiday_id = mysqli_insert_id($mysqli);

    logAction("Holidays", "Create", "$session_name added custom holiday $holiday_name ($holiday_date)", 0, $holiday_id);
    flash_alert("Holiday <strong>$holiday_name</strong> created");
    redirect();

}

if (isset($_POST['edit_holiday'])) {

    validateCSRFToken($_POST['csrf_token']);

    $holiday_id = intval($_POST['holiday_id']);
    $holiday_name = sanitizeInput($_POST['holiday_name'] ?? '');
    $holiday_country = sanitizeInput($_POST['holiday_country'] ?? '');
    $holiday_date_raw = $_POST['holiday_date'] ?? '';
    $d = DateTime::createFromFormat('Y-m-d', $holiday_date_raw);
    if ($d === false) {
        flash_alert("Invalid date", 'error');
        redirect();
    }
    $holiday_date = $d->format('Y-m-d');
    $holiday_year = intval($d->format('Y'));

    mysqli_query($mysqli, "UPDATE holidays SET holiday_name = '$holiday_name', holiday_country = '$holiday_country', holiday_date = '$holiday_date', holiday_year = $holiday_year WHERE holiday_id = $holiday_id");

    logAction("Holidays", "Edit", "$session_name edited holiday $holiday_name", 0, $holiday_id);
    flash_alert("Holiday <strong>$holiday_name</strong> saved");
    redirect();

}

if (isset($_GET['delete_holiday'])) {

    validateCSRFToken($_GET['csrf_token']);

    $holiday_id = intval($_GET['delete_holiday']);
    $holiday_name = sanitizeInput(getFieldById('holidays', $holiday_id, 'holiday_name'));

    mysqli_query($mysqli, "DELETE FROM holidays WHERE holiday_id = $holiday_id");

    logAction("Holidays", "Delete", "$session_name deleted holiday $holiday_name");
    flash_alert("Holiday <strong>$holiday_name</strong> deleted", 'error');
    redirect();

}

if (isset($_POST['bulk_delete_holidays'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['holiday_ids'])) {

        $count = count($_POST['holiday_ids']);

        foreach ($_POST['holiday_ids'] as $holiday_id) {
            $holiday_id = intval($holiday_id);
            mysqli_query($mysqli, "DELETE FROM holidays WHERE holiday_id = $holiday_id");
        }

        logAction("Holidays", "Bulk Delete", "$session_name deleted $count holiday(s)");
        flash_alert("Deleted <strong>$count</strong> holiday(s)", 'error');

    }

    redirect();

}
