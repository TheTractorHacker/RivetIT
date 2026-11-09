<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/payroll_functions.php';

if (isset($_POST['save_payroll_hours'])) {

    validateCSRFToken($_POST['csrf_token']);

    $period_id = intval($_POST['period_id'] ?? 0);
    $period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id AND payroll_period_archived_at IS NULL LIMIT 1"));

    if (!$period) {
        flash_alert("Pay period not found.", 'error');
        redirect('/admin/payroll_periods.php');
    }
    if ($period['payroll_period_status'] === 'locked') {
        flash_alert("This pay period is locked - its payroll run has already been finalized.", 'error');
        redirect("/admin/payroll_period.php?id=$period_id");
    }

    // Only accept week-start dates that are actually real buckets of this
    // period - never trust the POSTed keys blindly.
    $valid_week_starts = [];
    foreach (payrollGetWeekBuckets($period['payroll_period_start_date'], $period['payroll_period_end_date']) as $bucket) {
        $valid_week_starts[$bucket['start']] = true;
    }

    $hours_input = $_POST['hours'] ?? [];
    if (is_array($hours_input)) {
        foreach ($hours_input as $user_id => $weeks) {
            $user_id = intval($user_id);
            if ($user_id <= 0 || !is_array($weeks)) {
                continue;
            }
            foreach ($weeks as $week_start => $hm) {
                if (!isset($valid_week_starts[$week_start]) || !is_array($hm)) {
                    continue;
                }
                $h = intval($hm['h'] ?? 0);
                $m = intval($hm['m'] ?? 0);
                payrollUpsertHoursEntry($mysqli, $user_id, $week_start, $h, $m);
            }
        }
    }

    logAction("Payroll", "Edit", "$session_name saved hours for pay period #$period_id");

    flash_alert("Hours saved");
    redirect("/admin/payroll_period.php?id=$period_id");
}

if (isset($_POST['run_payroll'])) {

    validateCSRFToken($_POST['csrf_token']);

    $period_id = intval($_POST['period_id'] ?? 0);
    $period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id AND payroll_period_archived_at IS NULL LIMIT 1"));

    if (!$period) {
        flash_alert("Pay period not found.", 'error');
        redirect('/admin/payroll_periods.php');
    }
    if ($period['payroll_period_status'] === 'locked') {
        flash_alert("This pay period is already locked.", 'error');
        redirect("/admin/payroll_period.php?id=$period_id");
    }

    $existing_run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payroll_run_id FROM payroll_runs WHERE payroll_run_period_id = $period_id AND payroll_run_archived_at IS NULL LIMIT 1"));
    if ($existing_run) {
        redirect("/admin/payroll_run.php?id=" . intval($existing_run['payroll_run_id']));
    }

    $run_id = payrollCreateRun($mysqli, $period_id, intval($session_user_id), (string) $session_company_currency);

    logAction("Payroll", "Create", "$session_name ran payroll for pay period #$period_id");

    flash_alert("Payroll run created");
    redirect("/admin/payroll_run.php?id=$run_id");
}

if (isset($_POST['add_payroll_job_hours'])) {

    validateCSRFToken($_POST['csrf_token']);

    $period_id = intval($_POST['period_id'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);
    $ticket_id = intval($_POST['ticket_id'] ?? 0);
    $hours_h = max(0, intval($_POST['hours_h'] ?? 0));
    $hours_m = max(0, min(59, intval($_POST['hours_m'] ?? 0)));
    $rate = round(floatval($_POST['rate'] ?? 0), 2);
    $note = sanitizeInput($_POST['note'] ?? '');

    $period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id AND payroll_period_archived_at IS NULL LIMIT 1"));
    if (!$period) {
        flash_alert("Pay period not found.", 'error');
        redirect('/admin/payroll_periods.php');
    }
    if ($period['payroll_period_status'] === 'locked') {
        flash_alert("This pay period is locked - its payroll run has already been finalized.", 'error');
        redirect("/admin/payroll_period.php?id=$period_id");
    }

    $user_name = sanitizeInput(getFieldById('users', $user_id, 'user_name'));
    $ticket_subject = getFieldById('tickets', $ticket_id, 'ticket_subject');
    if ($user_id <= 0 || $user_name === '' || $ticket_id <= 0 || $ticket_subject === null || $ticket_subject === false) {
        flash_alert("Invalid employee or ticket.", 'error');
        redirect("/admin/payroll_period.php?id=$period_id");
    }
    if ($rate < 0) {
        flash_alert("Rate cannot be negative.", 'error');
        redirect("/admin/payroll_period.php?id=$period_id");
    }

    $time = sprintf('%02d:%02d:00', $hours_h, $hours_m);
    $note_esc = mysqli_real_escape_string($mysqli, $note);

    mysqli_query($mysqli, "INSERT INTO payroll_job_hours_entries SET
        payroll_job_hours_entry_period_id = $period_id,
        payroll_job_hours_entry_user_id = $user_id,
        payroll_job_hours_entry_ticket_id = $ticket_id,
        payroll_job_hours_entry_worked_time = '$time',
        payroll_job_hours_entry_rate = $rate,
        payroll_job_hours_entry_note = '$note_esc'");

    logAction("Payroll", "Create", "$session_name added job-specific hours for $user_name on ticket #$ticket_id (pay period #$period_id)");

    flash_alert("Job hours added");
    redirect("/admin/payroll_period.php?id=$period_id");
}

if (isset($_GET['remove_payroll_job_hours'])) {

    validateCSRFToken($_GET['csrf_token']);

    $job_hours_id = intval($_GET['remove_payroll_job_hours']);
    $period_id = intval($_GET['period_id'] ?? 0);

    $period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id LIMIT 1"));
    if ($period && $period['payroll_period_status'] === 'locked') {
        flash_alert("This pay period is locked - its payroll run has already been finalized.", 'error');
        redirect("/admin/payroll_period.php?id=$period_id");
    }

    mysqli_query($mysqli, "DELETE FROM payroll_job_hours_entries WHERE payroll_job_hours_entry_id = $job_hours_id AND payroll_job_hours_entry_period_id = $period_id");

    logAction("Payroll", "Delete", "$session_name removed a job-specific hours entry (#$job_hours_id) from pay period #$period_id");

    flash_alert("Job hours removed", 'error');
    redirect("/admin/payroll_period.php?id=$period_id");
}
