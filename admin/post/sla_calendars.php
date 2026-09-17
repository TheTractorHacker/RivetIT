<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * SLA Business Hours Calendars - admin POST/GET handler.
 * Loaded by admin/post.php when the referring page is sla_calendars.php.
 */

// Validate a timezone string against PHP's known identifiers; fall back to UTC.
function slaValidateTimezone(string $tz): string {
    static $valid = null;
    if ($valid === null) { $valid = array_flip(DateTimeZone::listIdentifiers()); }
    return isset($valid[$tz]) ? $tz : 'UTC';
}

if (isset($_POST['add_sla_calendar'])) {

    validateCSRFToken($_POST['csrf_token']);

    $calendar_name = sanitizeInput($_POST['calendar_name']);
    $calendar_timezone = slaValidateTimezone($_POST['calendar_timezone'] ?? 'UTC');
    $calendar_timezone = mysqli_real_escape_string($mysqli, $calendar_timezone);
    $is_default = isset($_POST['calendar_is_default']) ? 1 : 0;

    if ($is_default) {
        mysqli_query($mysqli, "UPDATE sla_business_hours SET calendar_is_default = 0");
    }

    mysqli_query($mysqli, "INSERT INTO sla_business_hours SET calendar_name = '$calendar_name', calendar_timezone = '$calendar_timezone', calendar_is_default = $is_default");
    $calendar_id = mysqli_insert_id($mysqli);

    // Seed Mon-Fri 09:00-17:00
    for ($dow = 1; $dow <= 5; $dow++) {
        mysqli_query($mysqli, "INSERT INTO sla_business_hours_periods SET calendar_id = $calendar_id, day_of_week = $dow, open_time = '09:00:00', close_time = '17:00:00'");
    }

    logAction("SLA Calendar", "Create", "$session_name created SLA calendar $calendar_name", 0, $calendar_id);
    flash_alert("SLA Calendar <strong>$calendar_name</strong> created");
    redirect();
}

if (isset($_POST['save_sla_calendar'])) {

    validateCSRFToken($_POST['csrf_token']);

    $calendar_id = intval($_POST['calendar_id']);
    $calendar_name = sanitizeInput($_POST['calendar_name']);
    $calendar_timezone = slaValidateTimezone($_POST['calendar_timezone'] ?? 'UTC');
    $calendar_timezone = mysqli_real_escape_string($mysqli, $calendar_timezone);
    $is_default = isset($_POST['calendar_is_default']) ? 1 : 0;

    if ($is_default) {
        mysqli_query($mysqli, "UPDATE sla_business_hours SET calendar_is_default = 0");
    }

    mysqli_query($mysqli, "UPDATE sla_business_hours SET calendar_name = '$calendar_name', calendar_timezone = '$calendar_timezone', calendar_is_default = $is_default WHERE calendar_id = $calendar_id");

    // Replace all daily windows for this calendar based on the submitted grid
    mysqli_query($mysqli, "DELETE FROM sla_business_hours_periods WHERE calendar_id = $calendar_id");
    $day_open = $_POST['day_open'] ?? [];
    $open_times = $_POST['open_time'] ?? [];
    $close_times = $_POST['close_time'] ?? [];
    for ($dow = 0; $dow <= 6; $dow++) {
        if (empty($day_open[$dow])) continue;
        $open_raw = $open_times[$dow] ?? '';
        $close_raw = $close_times[$dow] ?? '';
        // Expect HH:MM from <input type=time>
        if (!preg_match('/^\d{2}:\d{2}$/', $open_raw) || !preg_match('/^\d{2}:\d{2}$/', $close_raw)) continue;
        $open_sql = $open_raw . ':00';
        $close_sql = $close_raw . ':00';
        if (strtotime($close_sql) <= strtotime($open_sql)) continue; // skip inverted/empty windows
        $open_sql = mysqli_real_escape_string($mysqli, $open_sql);
        $close_sql = mysqli_real_escape_string($mysqli, $close_sql);
        mysqli_query($mysqli, "INSERT INTO sla_business_hours_periods SET calendar_id = $calendar_id, day_of_week = $dow, open_time = '$open_sql', close_time = '$close_sql'");
    }

    logAction("SLA Calendar", "Edit", "$session_name edited SLA calendar $calendar_name", 0, $calendar_id);
    flash_alert("SLA Calendar <strong>$calendar_name</strong> saved");
    redirect();
}

if (isset($_GET['set_default_sla_calendar'])) {

    validateCSRFToken($_GET['csrf_token']);

    $calendar_id = intval($_GET['set_default_sla_calendar']);
    mysqli_query($mysqli, "UPDATE sla_business_hours SET calendar_is_default = 0");
    mysqli_query($mysqli, "UPDATE sla_business_hours SET calendar_is_default = 1 WHERE calendar_id = $calendar_id");

    $calendar_name = sanitizeInput(getFieldById('sla_business_hours', $calendar_id, 'calendar_name'));
    logAction("SLA Calendar", "Edit", "$session_name set default SLA calendar to $calendar_name", 0, $calendar_id);
    flash_alert("Default SLA Calendar set to <strong>$calendar_name</strong>");
    redirect();
}

if (isset($_GET['delete_sla_calendar'])) {

    validateCSRFToken($_GET['csrf_token']);

    $calendar_id = intval($_GET['delete_sla_calendar']);
    $is_default = intval(getFieldById('sla_business_hours', $calendar_id, 'calendar_is_default'));
    if ($is_default) {
        flash_alert("Can't delete the default calendar. Set another calendar as default first.", 'error');
        redirect();
    }

    $calendar_name = sanitizeInput(getFieldById('sla_business_hours', $calendar_id, 'calendar_name'));

    // Detach any policies pointing at this calendar (they revert to 24/7)
    mysqli_query($mysqli, "UPDATE sla_policies SET policy_calendar_id = NULL WHERE policy_calendar_id = $calendar_id");
    mysqli_query($mysqli, "DELETE FROM sla_business_hours_periods WHERE calendar_id = $calendar_id");
    mysqli_query($mysqli, "DELETE FROM sla_holidays WHERE calendar_id = $calendar_id");
    mysqli_query($mysqli, "DELETE FROM sla_business_hours WHERE calendar_id = $calendar_id");

    logAction("SLA Calendar", "Delete", "$session_name deleted SLA calendar $calendar_name");
    flash_alert("SLA Calendar <strong>$calendar_name</strong> deleted", 'error');
    redirect();
}

if (isset($_POST['add_sla_holiday'])) {

    validateCSRFToken($_POST['csrf_token']);

    $calendar_id = intval($_POST['calendar_id']);
    $holiday_name = sanitizeInput($_POST['holiday_name'] ?? '');
    $holiday_date_raw = $_POST['holiday_date'] ?? '';
    $d = DateTime::createFromFormat('Y-m-d', $holiday_date_raw);
    if ($d === false) {
        flash_alert("Invalid holiday date", 'error');
        redirect();
    }
    $holiday_date = $d->format('Y-m-d');

    mysqli_query($mysqli, "INSERT INTO sla_holidays SET calendar_id = $calendar_id, holiday_date = '$holiday_date', holiday_name = '$holiday_name'");

    logAction("SLA Calendar", "Edit", "$session_name added holiday $holiday_date to SLA calendar", 0, $calendar_id);
    flash_alert("Holiday <strong>$holiday_date</strong> added");
    redirect();
}

if (isset($_GET['load_federal_holidays'])) {

    validateCSRFToken($_GET['csrf_token']);

    require_once __DIR__ . '/../../includes/holiday_functions.php';

    $calendar_id = intval($_GET['load_federal_holidays']);
    $calendar_name = sanitizeInput(getFieldById('sla_business_hours', $calendar_id, 'calendar_name'));
    if ($calendar_name === '') {
        flash_alert("Calendar not found", 'error');
        redirect();
    }

    $company_country = sanitizeInput(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_country FROM companies WHERE company_id = 1"))['company_country'] ?? '');
    if (!in_array($company_country, getFederalHolidayCountries(), true)) {
        flash_alert("No default federal holiday list available for the configured company country.", 'error');
        redirect();
    }

    // Existing dates for this calendar, so re-running this (e.g. next year,
    // to top up) never creates duplicates for a date already there -
    // whether it came from this same loader before or was added by hand.
    $existing_dates = [];
    $eres = mysqli_query($mysqli, "SELECT holiday_date FROM sla_holidays WHERE calendar_id = $calendar_id");
    while ($er = mysqli_fetch_assoc($eres)) { $existing_dates[$er['holiday_date']] = true; }

    $this_year = intval(date('Y'));
    $added = 0;
    foreach ([$this_year, $this_year + 1] as $year) {
        foreach (getFederalHolidaysForCountry($company_country, $year) as $h) {
            if (isset($existing_dates[$h['date']])) { continue; }
            $h_date = mysqli_real_escape_string($mysqli, $h['date']);
            $h_name = mysqli_real_escape_string($mysqli, $h['name']);
            mysqli_query($mysqli, "INSERT INTO sla_holidays SET calendar_id = $calendar_id, holiday_date = '$h_date', holiday_name = '$h_name'");
            $existing_dates[$h['date']] = true;
            $added++;
        }
    }

    logAction("SLA Calendar", "Edit", "$session_name loaded $added $company_country federal holiday(s) into SLA calendar $calendar_name", 0, $calendar_id);
    flash_alert("Loaded <strong>$added</strong> $company_country federal holiday(s) for $this_year&ndash;" . ($this_year + 1));
    redirect();
}

if (isset($_GET['delete_sla_holiday'])) {

    validateCSRFToken($_GET['csrf_token']);

    $holiday_id = intval($_GET['delete_sla_holiday']);
    $calendar_id = intval(getFieldById('sla_holidays', $holiday_id, 'calendar_id'));

    mysqli_query($mysqli, "DELETE FROM sla_holidays WHERE holiday_id = $holiday_id");

    logAction("SLA Calendar", "Edit", "$session_name removed a holiday from SLA calendar", 0, $calendar_id);
    flash_alert("Holiday removed");
    redirect();
}
