<?php

/*
 * ITFlow - Payroll calculation engine (gross pay only, no tax withholding).
 *
 * Shared by admin/payroll_period.php (hours entry), admin/post/payroll_period.php
 * (Run Payroll) and admin/post/payroll_run.php (Recalculate/Finalize) so there is
 * one implementation of the actual math, not one per caller.
 *
 * Every payroll_run_line_items row is a SNAPSHOT taken at compute time (rate, pay
 * type, overtime rule, employee name) - later edits to payroll_pay_rates/settings/
 * users can never alter a run once it exists, draft or finalized. Finalizing just
 * additionally locks the period and blocks further recompute.
 */

/**
 * Split a period into 7-day workweek buckets anchored at the period's own start
 * date (a legitimate fixed FLSA workweek, not necessarily Mon-Sun). The last
 * bucket may be shorter than 7 days for odd-length periods (e.g. semimonthly).
 * Returns a list of ['start' => 'Y-m-d', 'end' => 'Y-m-d'].
 */
function payrollGetWeekBuckets(string $period_start, string $period_end): array {
    $buckets = [];
    $cursor = strtotime($period_start);
    $end_ts = strtotime($period_end);
    if ($cursor === false || $end_ts === false || $cursor > $end_ts) {
        return $buckets;
    }
    while ($cursor <= $end_ts) {
        $bucket_end_ts = min($cursor + 6 * 86400, $end_ts);
        $buckets[] = [
            'start' => date('Y-m-d', $cursor),
            'end'   => date('Y-m-d', $bucket_end_ts),
        ];
        $cursor = $bucket_end_ts + 86400;
    }
    return $buckets;
}

/**
 * Upsert one employee's hours for one workweek. $hours may exceed 24 (a workweek
 * total, not a single day) - MySQL TIME supports up to 838:59:59, so this is
 * never capped the way a single ticket-reply TIME entry's HH:MM:SS regex is.
 */
function payrollUpsertHoursEntry(mysqli $mysqli, int $user_id, string $week_start_date, int $hours, int $minutes): void {
    $hours = max(0, $hours);
    $minutes = max(0, min(59, $minutes));
    $time = sprintf('%02d:%02d:00', $hours, $minutes);
    $week_esc = mysqli_real_escape_string($mysqli, $week_start_date);
    mysqli_query(
        $mysqli,
        "INSERT INTO payroll_hours_entries
            (payroll_hours_entry_user_id, payroll_hours_entry_week_start_date, payroll_hours_entry_worked_time, payroll_hours_entry_source)
         VALUES
            ($user_id, '$week_esc', '$time', 'manual')
         ON DUPLICATE KEY UPDATE
            payroll_hours_entry_worked_time = VALUES(payroll_hours_entry_worked_time),
            payroll_hours_entry_source = 'manual'"
    );
}

/**
 * Compute one employee's gross-pay breakdown for one period. Reads current
 * payroll_hours_entries / payroll_employee_deductions / payroll_job_hours_entries
 * - callers persist the result as a snapshot, this function itself has no side
 * effects.
 */
function payrollComputeEmployeeLineItem(mysqli $mysqli, array $employee, int $period_id, string $period_start, string $period_end, float $overtime_threshold, float $overtime_multiplier): array {
    $user_id   = intval($employee['user_id']);
    $pay_type  = $employee['payroll_pay_rate_type'] === 'salary' ? 'salary' : 'hourly';
    $rate      = round(floatval($employee['payroll_pay_rate_amount']), 2);

    $regular_hours  = 0.0;
    $overtime_hours = 0.0;
    $regular_pay    = 0.0;
    $overtime_pay   = 0.0;

    if ($pay_type === 'salary') {
        // Flat amount per period - no proration for partial periods (v1 limitation).
        $gross_pay = $rate;
    } else {
        foreach (payrollGetWeekBuckets($period_start, $period_end) as $bucket) {
            $row = mysqli_fetch_assoc(mysqli_query(
                $mysqli,
                "SELECT payroll_hours_entry_worked_time FROM payroll_hours_entries
                 WHERE payroll_hours_entry_user_id = $user_id AND payroll_hours_entry_week_start_date = '{$bucket['start']}'
                 LIMIT 1"
            ));
            $worked_time = $row['payroll_hours_entry_worked_time'] ?? '00:00:00';
            $parts = array_map('intval', explode(':', $worked_time));
            $h = $parts[0] ?? 0;
            $m = $parts[1] ?? 0;
            $s = $parts[2] ?? 0;
            $hours_decimal = $h + ($m / 60) + ($s / 3600);

            $regular_bucket  = min($hours_decimal, $overtime_threshold);
            $overtime_bucket = max(0, $hours_decimal - $overtime_threshold);
            $regular_hours  += $regular_bucket;
            $overtime_hours += $overtime_bucket;
        }
        $regular_pay  = round($regular_hours * $rate, 2);
        $overtime_pay = round($overtime_hours * $rate * $overtime_multiplier, 2);
        $gross_pay    = round($regular_pay + $overtime_pay, 2);
    }

    // Per-job pay-rate overrides: hours logged against a specific ticket at a
    // one-off rate, kept separate from the weekly overtime bucket entirely -
    // always paid flat as hours x that job's rate, on top of the regular/
    // overtime/salary pay above. Applies regardless of pay_type (a salaried
    // employee can still pick up occasional job-rate work).
    $jobs = [];
    $job_hours_total = 0.0;
    $job_pay_total = 0.0;
    $job_sql = mysqli_query(
        $mysqli,
        "SELECT j.payroll_job_hours_entry_id, j.payroll_job_hours_entry_ticket_id, j.payroll_job_hours_entry_worked_time, j.payroll_job_hours_entry_rate,
                t.ticket_prefix, t.ticket_number, t.ticket_subject
         FROM payroll_job_hours_entries j
         LEFT JOIN tickets t ON t.ticket_id = j.payroll_job_hours_entry_ticket_id
         WHERE j.payroll_job_hours_entry_period_id = $period_id AND j.payroll_job_hours_entry_user_id = $user_id"
    );
    while ($jrow = mysqli_fetch_assoc($job_sql)) {
        $jparts = array_map('intval', explode(':', $jrow['payroll_job_hours_entry_worked_time']));
        $jh = $jparts[0] ?? 0;
        $jm = $jparts[1] ?? 0;
        $js = $jparts[2] ?? 0;
        $j_hours_decimal = round($jh + ($jm / 60) + ($js / 3600), 2);
        $j_rate = round(floatval($jrow['payroll_job_hours_entry_rate']), 2);
        $j_pay  = round($j_hours_decimal * $j_rate, 2);

        $ticket_label = $jrow['ticket_subject'] !== null
            ? trim((string) $jrow['ticket_prefix'] . $jrow['ticket_number'] . ' - ' . $jrow['ticket_subject'])
            : 'Ticket #' . intval($jrow['payroll_job_hours_entry_ticket_id']) . ' (deleted)';

        $jobs[] = [
            'ticket_id'    => intval($jrow['payroll_job_hours_entry_ticket_id']),
            'ticket_label' => $ticket_label,
            'hours'        => $j_hours_decimal,
            'rate'         => $j_rate,
            'pay'          => $j_pay,
        ];
        $job_hours_total += $j_hours_decimal;
        $job_pay_total   += $j_pay;
    }
    $job_hours_total = round($job_hours_total, 2);
    $job_pay_total   = round($job_pay_total, 2);
    $gross_pay = round($gross_pay + $job_pay_total, 2);

    $deductions = [];
    $total_deductions = 0.0;
    $ded_sql = mysqli_query(
        $mysqli,
        "SELECT ed.payroll_employee_deduction_amount, c.payroll_deduction_category_id, c.payroll_deduction_category_name
         FROM payroll_employee_deductions ed
         JOIN payroll_deduction_categories c ON c.payroll_deduction_category_id = ed.payroll_employee_deduction_category_id
         WHERE ed.payroll_employee_deduction_user_id = $user_id AND ed.payroll_employee_deduction_archived_at IS NULL"
    );
    while ($drow = mysqli_fetch_assoc($ded_sql)) {
        $amount = round(floatval($drow['payroll_employee_deduction_amount']), 2);
        $deductions[] = [
            'category_id'   => intval($drow['payroll_deduction_category_id']),
            'category_name' => (string) $drow['payroll_deduction_category_name'],
            'amount'        => $amount,
        ];
        $total_deductions += $amount;
    }
    $total_deductions = round($total_deductions, 2);
    $net_pay = round($gross_pay - $total_deductions, 2);

    return [
        'user_id'           => $user_id,
        'employee_name'     => (string) $employee['user_name'],
        'pay_type'          => $pay_type,
        'rate'              => $rate,
        'regular_hours'     => round($regular_hours, 2),
        'overtime_hours'    => round($overtime_hours, 2),
        'regular_pay'       => $regular_pay,
        'overtime_pay'      => $overtime_pay,
        'job_hours'         => $job_hours_total,
        'job_pay'           => $job_pay_total,
        'jobs'              => $jobs,
        'gross_pay'         => $gross_pay,
        'total_deductions'  => $total_deductions,
        'net_pay'           => $net_pay,
        'deductions'        => $deductions,
    ];
}

/**
 * (Re)compute every enrolled employee's line item for a run, deleting whatever
 * line items currently exist for it first. Used both by the initial "Run
 * Payroll" and by "Recalculate" (draft runs only - callers must check status).
 */
function payrollComputeRunLineItems(mysqli $mysqli, int $run_id, int $period_id, string $currency_code): void {
    $period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id LIMIT 1"));
    if (!$period) {
        return;
    }
    $settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_payroll_overtime_threshold_hours, config_payroll_overtime_multiplier FROM settings WHERE company_id = 1 LIMIT 1"));
    $overtime_threshold  = floatval($settings['config_payroll_overtime_threshold_hours'] ?? 40);
    $overtime_multiplier = floatval($settings['config_payroll_overtime_multiplier'] ?? 1.5);
    $currency_esc = mysqli_real_escape_string($mysqli, $currency_code);

    // Wipe existing line items (+ their itemized deductions) for a clean recompute.
    $existing_ids = [];
    $eres = mysqli_query($mysqli, "SELECT payroll_run_line_item_id FROM payroll_run_line_items WHERE payroll_run_line_item_run_id = $run_id");
    while ($r = mysqli_fetch_assoc($eres)) {
        $existing_ids[] = intval($r['payroll_run_line_item_id']);
    }
    if (!empty($existing_ids)) {
        mysqli_query($mysqli, "DELETE FROM payroll_run_line_item_deductions WHERE payroll_run_line_item_deduction_line_item_id IN (" . implode(',', $existing_ids) . ")");
        mysqli_query($mysqli, "DELETE FROM payroll_run_line_item_jobs WHERE payroll_run_line_item_job_line_item_id IN (" . implode(',', $existing_ids) . ")");
    }
    mysqli_query($mysqli, "DELETE FROM payroll_run_line_items WHERE payroll_run_line_item_run_id = $run_id");

    $employees_sql = mysqli_query(
        $mysqli,
        "SELECT u.user_id, u.user_name, r.payroll_pay_rate_type, r.payroll_pay_rate_amount
         FROM users u
         JOIN payroll_pay_rates r ON r.payroll_pay_rate_user_id = u.user_id AND r.payroll_pay_rate_archived_at IS NULL
         WHERE u.user_type = 1 AND u.user_archived_at IS NULL
         ORDER BY u.user_name ASC"
    );

    $total_gross = 0.0;
    $total_deductions_sum = 0.0;
    $total_net = 0.0;

    while ($emp = mysqli_fetch_assoc($employees_sql)) {
        $calc = payrollComputeEmployeeLineItem($mysqli, $emp, $period_id, $period['payroll_period_start_date'], $period['payroll_period_end_date'], $overtime_threshold, $overtime_multiplier);

        $name_esc = mysqli_real_escape_string($mysqli, $calc['employee_name']);
        $pay_type_esc = $calc['pay_type'];

        mysqli_query(
            $mysqli,
            "INSERT INTO payroll_run_line_items SET
                payroll_run_line_item_run_id = $run_id,
                payroll_run_line_item_user_id = {$calc['user_id']},
                payroll_run_line_item_employee_name_snapshot = '$name_esc',
                payroll_run_line_item_pay_type_snapshot = '$pay_type_esc',
                payroll_run_line_item_rate_snapshot = {$calc['rate']},
                payroll_run_line_item_overtime_threshold_snapshot = $overtime_threshold,
                payroll_run_line_item_overtime_multiplier_snapshot = $overtime_multiplier,
                payroll_run_line_item_regular_hours = {$calc['regular_hours']},
                payroll_run_line_item_overtime_hours = {$calc['overtime_hours']},
                payroll_run_line_item_regular_pay = {$calc['regular_pay']},
                payroll_run_line_item_overtime_pay = {$calc['overtime_pay']},
                payroll_run_line_item_job_hours = {$calc['job_hours']},
                payroll_run_line_item_job_pay = {$calc['job_pay']},
                payroll_run_line_item_gross_pay = {$calc['gross_pay']},
                payroll_run_line_item_total_deductions = {$calc['total_deductions']},
                payroll_run_line_item_net_pay = {$calc['net_pay']},
                payroll_run_line_item_currency_code = '$currency_esc'"
        );
        $line_item_id = mysqli_insert_id($mysqli);

        foreach ($calc['jobs'] as $job) {
            $ticket_label_esc = mysqli_real_escape_string($mysqli, $job['ticket_label']);
            $ticket_id_sql = $job['ticket_id'] > 0 ? $job['ticket_id'] : 'NULL';
            mysqli_query(
                $mysqli,
                "INSERT INTO payroll_run_line_item_jobs SET
                    payroll_run_line_item_job_line_item_id = $line_item_id,
                    payroll_run_line_item_job_ticket_id = $ticket_id_sql,
                    payroll_run_line_item_job_ticket_label_snapshot = '$ticket_label_esc',
                    payroll_run_line_item_job_hours = {$job['hours']},
                    payroll_run_line_item_job_rate = {$job['rate']},
                    payroll_run_line_item_job_pay = {$job['pay']}"
            );
        }

        foreach ($calc['deductions'] as $ded) {
            $cat_name_esc = mysqli_real_escape_string($mysqli, $ded['category_name']);
            mysqli_query(
                $mysqli,
                "INSERT INTO payroll_run_line_item_deductions SET
                    payroll_run_line_item_deduction_line_item_id = $line_item_id,
                    payroll_run_line_item_deduction_category_id = {$ded['category_id']},
                    payroll_run_line_item_deduction_category_name_snapshot = '$cat_name_esc',
                    payroll_run_line_item_deduction_amount = {$ded['amount']}"
            );
        }

        $total_gross += $calc['gross_pay'];
        $total_deductions_sum += $calc['total_deductions'];
        $total_net += $calc['net_pay'];
    }

    $total_gross = round($total_gross, 2);
    $total_deductions_sum = round($total_deductions_sum, 2);
    $total_net = round($total_net, 2);

    mysqli_query($mysqli, "UPDATE payroll_runs SET payroll_run_total_gross = $total_gross, payroll_run_total_deductions = $total_deductions_sum, payroll_run_total_net = $total_net WHERE payroll_run_id = $run_id");
}

/**
 * Create a new draft run for a period and compute its line items. Caller must
 * have already checked no non-archived run exists for this period.
 */
function payrollCreateRun(mysqli $mysqli, int $period_id, int $created_by_user_id, string $currency_code): int {
    $currency_esc = mysqli_real_escape_string($mysqli, $currency_code);
    mysqli_query($mysqli, "INSERT INTO payroll_runs SET payroll_run_period_id = $period_id, payroll_run_status = 'draft', payroll_run_created_by = $created_by_user_id, payroll_run_currency_code = '$currency_esc'");
    $run_id = mysqli_insert_id($mysqli);
    payrollComputeRunLineItems($mysqli, $run_id, $period_id, $currency_code);
    return $run_id;
}

/**
 * Finalize a draft run: locks the run (no more Recalculate/Edit Hours) and its
 * parent period. No-ops if already finalized or not found - callers that need
 * to distinguish "already finalized" for a user-facing message should check
 * payroll_run_status themselves before calling this.
 */
function payrollFinalizeRun(mysqli $mysqli, int $run_id, int $finalized_by_user_id): bool {
    $run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_runs WHERE payroll_run_id = $run_id LIMIT 1"));
    if (!$run || $run['payroll_run_status'] === 'finalized') {
        return false;
    }
    mysqli_query($mysqli, "UPDATE payroll_runs SET payroll_run_status = 'finalized', payroll_run_finalized_at = NOW(), payroll_run_finalized_by = $finalized_by_user_id WHERE payroll_run_id = $run_id");
    mysqli_query($mysqli, "UPDATE payroll_periods SET payroll_period_status = 'locked' WHERE payroll_period_id = " . intval($run['payroll_run_period_id']));
    return true;
}
