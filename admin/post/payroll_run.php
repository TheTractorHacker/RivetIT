<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/payroll_functions.php';

if (isset($_POST['recalculate_payroll_run'])) {

    validateCSRFToken($_POST['csrf_token']);

    $run_id = intval($_POST['run_id'] ?? 0);
    $run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_runs WHERE payroll_run_id = $run_id AND payroll_run_archived_at IS NULL LIMIT 1"));

    if (!$run) {
        flash_alert("Payroll run not found.", 'error');
        redirect('/admin/payroll_runs.php');
    }
    // Server-side guard - the finalized run's UI hides this button, but a
    // direct POST must be rejected too, not just hidden client-side.
    if ($run['payroll_run_status'] === 'finalized') {
        flash_alert("This payroll run is finalized and can no longer be recalculated.", 'error');
        redirect("/admin/payroll_run.php?id=$run_id");
    }

    payrollComputeRunLineItems($mysqli, $run_id, intval($run['payroll_run_period_id']), (string) $run['payroll_run_currency_code']);

    logAction("Payroll", "Edit", "$session_name recalculated payroll run #$run_id");

    flash_alert("Payroll run recalculated");
    redirect("/admin/payroll_run.php?id=$run_id");
}

if (isset($_POST['finalize_payroll_run'])) {

    validateCSRFToken($_POST['csrf_token']);

    $run_id = intval($_POST['run_id'] ?? 0);
    $run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_runs WHERE payroll_run_id = $run_id AND payroll_run_archived_at IS NULL LIMIT 1"));

    if (!$run) {
        flash_alert("Payroll run not found.", 'error');
        redirect('/admin/payroll_runs.php');
    }
    if ($run['payroll_run_status'] === 'finalized') {
        flash_alert("This payroll run is already finalized.", 'error');
        redirect("/admin/payroll_run.php?id=$run_id");
    }

    payrollFinalizeRun($mysqli, $run_id, intval($session_user_id));

    logAction("Payroll", "Edit", "$session_name finalized payroll run #$run_id");

    flash_alert("Payroll run finalized - it and its pay period are now locked");
    redirect("/admin/payroll_run.php?id=$run_id");
}

if (isset($_POST['edit_payroll_run_hours'])) {

    validateCSRFToken($_POST['csrf_token']);

    $run_id = intval($_POST['run_id'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);
    $period_id = intval($_POST['period_id'] ?? 0);

    $run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_runs WHERE payroll_run_id = $run_id AND payroll_run_archived_at IS NULL LIMIT 1"));
    $period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id LIMIT 1"));

    if (!$run || !$period) {
        flash_alert("Payroll run or period not found.", 'error');
        redirect('/admin/payroll_runs.php');
    }
    // Server-side guard - reject correcting hours on a finalized run.
    if ($run['payroll_run_status'] === 'finalized') {
        flash_alert("This payroll run is finalized - hours can no longer be edited.", 'error');
        redirect("/admin/payroll_run.php?id=$run_id");
    }

    $valid_week_starts = [];
    foreach (payrollGetWeekBuckets($period['payroll_period_start_date'], $period['payroll_period_end_date']) as $bucket) {
        $valid_week_starts[$bucket['start']] = true;
    }

    // The employee must actually be on this run.
    $on_run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT 1 AS ok FROM payroll_run_line_items WHERE payroll_run_line_item_run_id = $run_id AND payroll_run_line_item_user_id = $user_id LIMIT 1"));
    if (!$on_run) {
        flash_alert("That employee is not on this payroll run.", 'error');
        redirect("/admin/payroll_run.php?id=$run_id");
    }

    // Validate every entry first so a typo never silently zeroes a week; nothing is saved unless all of it is valid.
    $weeks_input = $_POST['weeks'] ?? [];
    $to_save = [];
    if (is_array($weeks_input)) {
        foreach ($weeks_input as $week_start => $hm) {
            if (!isset($valid_week_starts[$week_start]) || !is_array($hm)) {
                continue;
            }
            $h_raw = trim((string) ($hm['h'] ?? '0'));
            $m_raw = trim((string) ($hm['m'] ?? '0'));
            $h_raw = $h_raw === '' ? '0' : $h_raw;
            $m_raw = $m_raw === '' ? '0' : $m_raw;
            if (!ctype_digit($h_raw) || !ctype_digit($m_raw) || intval($h_raw) > 168 || intval($m_raw) > 59) {
                flash_alert("Hours must be whole numbers (0-168) and minutes 0-59. Nothing was saved.", 'error');
                redirect("/admin/payroll_run.php?id=$run_id");
            }
            $to_save[$week_start] = [intval($h_raw), intval($m_raw)];
        }
    }
    foreach ($to_save as $week_start => $hm) {
        payrollUpsertHoursEntry($mysqli, $user_id, $week_start, $hm[0], $hm[1]);
    }

    logAction("Payroll", "Edit", "$session_name corrected hours for user #$user_id on payroll run #$run_id");

    flash_alert("Hours updated - click Recalculate to apply the change to this run's totals.");
    redirect("/admin/payroll_run.php?id=$run_id");
}
