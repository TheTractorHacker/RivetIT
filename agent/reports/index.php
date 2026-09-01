<?php

require_once "includes/inc_all_reports.php";

?>

<?php
// MTD income (payments + revenues this month) — Financial section
$sql_mtd_pay = mysqli_query($mysqli, "SELECT SUM(payment_amount) AS v FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())");
$mtd_pay = floatval(mysqli_fetch_assoc($sql_mtd_pay)['v'] ?? 0);
$sql_mtd_rev = mysqli_query($mysqli, "SELECT SUM(revenue_amount) AS v FROM revenues WHERE YEAR(revenue_date) = YEAR(CURDATE()) AND MONTH(revenue_date) = MONTH(CURDATE()) AND revenue_category_id > 0");
$mtd_rev = floatval(mysqli_fetch_assoc($sql_mtd_rev)['v'] ?? 0);
$reports_mtd_income = $mtd_pay + $mtd_rev;

$reports_show_financial = ($config_module_enable_accounting == 1 && lookupUserPermission('module_financial') >= 1);
if ($reports_show_financial) {
    $reports_ar = getArAgingReport($mysqli);
}

$reports_show_technical = ($config_module_enable_ticketing == 1 && lookupUserPermission('module_support') >= 1);
if ($reports_show_technical) {
    $reports_open_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND ticket_resolved_at IS NULL"))[0]);
    $reports_unassigned_tickets = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND (ticket_assigned_to IS NULL OR ticket_assigned_to = 0)"))[0]);
    $reports_opened_today = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE DATE(ticket_created_at) = CURDATE()"))[0]);
    $reports_csat_avg = null;
    if (!empty($config_ticket_csat_enable)) {
        $reports_csat_avg_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT AVG(ticket_csat_rating) AS v FROM tickets WHERE ticket_csat_rated_at >= NOW() - INTERVAL 30 DAY"));
        $reports_csat_avg = $reports_csat_avg_row['v'] !== null ? round(floatval($reports_csat_avg_row['v']), 2) : null;
    }
}
?>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-coins me-2"></i>Reports</h3>
        </div>
        <div class="card-body">
            <small class="text-muted d-block mb-3">In addition to the general reporting permission, you must have read permissions to the reporting area you wish to view (e.g. support/financial). Use the menu on the left for the full list of reports.</small>

            <?php if ($reports_show_financial) { ?>
            <h6 class="text-muted text-uppercase mb-2" style="font-size:.72rem; letter-spacing:.06em;">Financial</h6>
            <div class="row mb-3">
                <div class="col-6 col-md-4 mb-3">
                    <a href="/agent/reports/income_summary.php" class="text-decoration-none">
                        <div class="small-box text-bg-success bg-gradient mb-0">
                            <div class="inner">
                                <h3><?php echo numfmt_format_currency($currency_format, $reports_mtd_income, "$session_company_currency"); ?></h3>
                                <p>Income This Month</p>
                            </div>
                            <div class="icon"><i class="fas fa-coins"></i></div>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-4 mb-3">
                    <a href="/agent/reports/clients_with_balance.php" class="text-decoration-none">
                        <div class="small-box text-bg-warning bg-gradient mb-0">
                            <div class="inner">
                                <h3><?php echo numfmt_format_currency($currency_format, $reports_ar['buckets']['total'], "$session_company_currency"); ?></h3>
                                <p>Outstanding AR <?php echo "(" . count($reports_ar['clients']) . " " . (count($reports_ar['clients']) == 1 ? "client" : "clients") . ")"; ?></p>
                            </div>
                            <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-4 mb-3">
                    <a href="/agent/reports/clients_with_balance.php" class="text-decoration-none">
                        <div class="small-box text-bg-danger bg-gradient mb-0">
                            <div class="inner">
                                <h3><?php echo numfmt_format_currency($currency_format, $reports_ar['buckets']['b_90_plus'], "$session_company_currency"); ?></h3>
                                <p>Seriously Overdue (90+ Days)</p>
                            </div>
                            <div class="icon"><i class="fas fa-clock"></i></div>
                        </div>
                    </a>
                </div>
            </div>
            <?php } ?>

            <?php if ($reports_show_technical) { ?>
            <h6 class="text-muted text-uppercase mb-2" style="font-size:.72rem; letter-spacing:.06em;">Technical</h6>
            <div class="row">
                <div class="col-6 col-md-3 mb-3">
                    <a href="/agent/reports/ticket_summary.php" class="text-decoration-none">
                        <div class="small-box text-bg-primary bg-gradient mb-0">
                            <div class="inner">
                                <h3><?php echo $reports_open_tickets; ?></h3>
                                <p>Open Tickets</p>
                            </div>
                            <div class="icon"><i class="fas fa-life-ring"></i></div>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-3 mb-3">
                    <a href="/agent/reports/service_desk.php" class="text-decoration-none">
                        <div class="small-box text-bg-danger bg-gradient mb-0">
                            <div class="inner">
                                <h3><?php echo $reports_unassigned_tickets; ?></h3>
                                <p>Unassigned Tickets</p>
                            </div>
                            <div class="icon"><i class="fas fa-user-slash"></i></div>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-3 mb-3">
                    <a href="/agent/reports/ticket_summary.php" class="text-decoration-none">
                        <div class="small-box text-bg-info bg-gradient mb-0">
                            <div class="inner">
                                <h3><?php echo $reports_opened_today; ?></h3>
                                <p>Opened Today</p>
                            </div>
                            <div class="icon"><i class="fas fa-calendar-day"></i></div>
                        </div>
                    </a>
                </div>
                <?php if (!empty($config_ticket_csat_enable)) { ?>
                <div class="col-6 col-md-3 mb-3">
                    <a href="/agent/reports/csat.php" class="text-decoration-none">
                        <div class="small-box text-bg-warning bg-gradient mb-0">
                            <div class="inner">
                                <h3><?php echo $reports_csat_avg !== null ? $reports_csat_avg . '/5' : '—'; ?></h3>
                                <p>CSAT (30 days)</p>
                            </div>
                            <div class="icon"><i class="fas fa-star"></i></div>
                        </div>
                    </a>
                </div>
                <?php } ?>
            </div>
            <?php } ?>

            <?php if (!$reports_show_financial && !$reports_show_technical) { ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-lock fa-2x mb-2 d-block"></i>
                    You don't currently have read access to a specific reporting area. Ask an administrator for Financial or Support reporting permission, or use the menu on the left if you already have access to a particular report.
                </div>
            <?php } ?>
        </div>
    </div>

<?php require_once "../../includes/footer.php"; ?>

