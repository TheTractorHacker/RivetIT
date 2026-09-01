<?php

/*
 * ###############################################################################################################
 *  METRICS ROLLUP  (cron/metrics_rollup.php)
 * ###############################################################################################################
 *
 *  Upserts a per-day ticket-flow snapshot into ticket_metrics_daily so dashboards/reports can serve the
 *  "opened vs resolved vs backlog" trend from one indexed row per day instead of many live COUNT queries.
 *
 *  Recomputes YESTERDAY and TODAY on every run (idempotent via the UNIQUE(company_id, metric_date) key):
 *  yesterday's numbers stabilise once the day is over, today's keeps refreshing as tickets move.
 *
 *  Included daily from cron/cron.php. Also runnable stand-alone from the CLI for backfill/testing:
 *      php cron/metrics_rollup.php
 *
 *  ITFlow is single-company per install, so company_id is fixed at 1 (tickets carry no company_id column).
 */

// Stand-alone bootstrap (skipped when included from cron.php, which already has $mysqli + helpers).
$rollup_standalone = !isset($mysqli);
if ($rollup_standalone) {
    chdir(dirname(__FILE__));

    if (php_sapi_name() !== 'cli') {
        die("This script must be run from the command line.\n");
    }

    require_once "../config.php";
    require_once "../includes/inc_set_timezone.php";
    require_once "../functions.php";
}

$rollup_company_id = 1;

// Recompute the tail end of the window every run so late ticket activity is captured.
$rollup_dates = [
    date('Y-m-d', strtotime('-1 day')),
    date('Y-m-d'),
];

$rollup_written = 0;

foreach ($rollup_dates as $rollup_date) {
    $d         = mysqli_real_escape_string($mysqli, $rollup_date);
    $day_start = "$d 00:00:00";
    $day_end   = "$d 23:59:59";

    // Opened that day.
    $opened = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_id) AS c FROM tickets
         WHERE ticket_created_at BETWEEN '$day_start' AND '$day_end'
           AND ticket_archived_at IS NULL"))['c']);

    // Resolved that day (ticket_resolved_at is set when an agent marks resolution).
    $resolved = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_id) AS c FROM tickets
         WHERE ticket_resolved_at BETWEEN '$day_start' AND '$day_end'
           AND ticket_archived_at IS NULL"))['c']);

    // Closed that day.
    $closed = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_id) AS c FROM tickets
         WHERE ticket_closed_at BETWEEN '$day_start' AND '$day_end'
           AND ticket_archived_at IS NULL"))['c']);

    // Backlog = tickets open at the END of that day: created on/before day-end and either
    // never closed or closed after day-end. This makes historical rows point-in-time correct.
    $backlog = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(ticket_id) AS c FROM tickets
         WHERE ticket_created_at <= '$day_end'
           AND (ticket_closed_at IS NULL OR ticket_closed_at > '$day_end')
           AND ticket_archived_at IS NULL"))['c']);

    mysqli_query($mysqli,
        "INSERT INTO ticket_metrics_daily (metric_date, company_id, opened, resolved, closed, backlog_open)
         VALUES ('$d', $rollup_company_id, $opened, $resolved, $closed, $backlog)
         ON DUPLICATE KEY UPDATE
            opened       = VALUES(opened),
            resolved     = VALUES(resolved),
            closed       = VALUES(closed),
            backlog_open = VALUES(backlog_open)");

    $rollup_written++;
}

if (function_exists('logApp')) {
    logApp("Cron", "info", "Metrics rollup upserted $rollup_written day(s) into ticket_metrics_daily (" . implode(', ', $rollup_dates) . ")");
}

// Give CLI operators visible confirmation only when run directly (silent as a cron.php include).
if ($rollup_standalone && php_sapi_name() === 'cli') {
    echo "metrics_rollup: upserted " . $rollup_written . " day(s): " . implode(', ', $rollup_dates) . "\n";
}
