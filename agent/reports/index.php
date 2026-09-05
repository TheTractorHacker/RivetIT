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

render_page_header(
    'Reports',
    'In addition to the general reporting permission, you must have read permissions to the reporting area you wish to view (e.g. support/financial). Use the menu on the left for the full list of reports.'
);
?>

<?php if ($reports_show_financial) { ?>
<div class="it-section">
    <div class="it-section-header">Financial</div>
    <div class="it-stat-grid">
        <?php render_stat_card(
            'Income This Month',
            numfmt_format_currency($currency_format, $reports_mtd_income, "$session_company_currency"),
            'fas fa-coins', 'success', '/agent/reports/income_summary.php'
        ); ?>
        <?php render_stat_card(
            'Outstanding AR (' . count($reports_ar['clients']) . ' ' . (count($reports_ar['clients']) == 1 ? 'department' : 'departments') . ')',
            numfmt_format_currency($currency_format, $reports_ar['buckets']['total'], "$session_company_currency"),
            'fas fa-exclamation-triangle', 'warning', '/agent/reports/clients_with_balance.php'
        ); ?>
        <?php render_stat_card(
            'Seriously Overdue (90+ Days)',
            numfmt_format_currency($currency_format, $reports_ar['buckets']['b_90_plus'], "$session_company_currency"),
            'fas fa-clock', 'danger', '/agent/reports/clients_with_balance.php'
        ); ?>
    </div>
</div>
<?php } ?>

<?php if ($reports_show_technical) { ?>
<div class="it-section">
    <div class="it-section-header">Technical</div>
    <div class="it-stat-grid">
        <?php render_stat_card('Open Tickets', (string) $reports_open_tickets, 'fas fa-life-ring', 'primary', '/agent/reports/ticket_summary.php'); ?>
        <?php render_stat_card('Unassigned Tickets', (string) $reports_unassigned_tickets, 'fas fa-user-slash', 'danger', '/agent/reports/service_desk.php'); ?>
        <?php render_stat_card('Opened Today', (string) $reports_opened_today, 'fas fa-calendar-day', 'info', '/agent/reports/ticket_summary.php'); ?>
        <?php if (!empty($config_ticket_csat_enable)) { ?>
        <?php render_stat_card('CSAT (30 days)', $reports_csat_avg !== null ? $reports_csat_avg . '/5' : '—', 'fas fa-star', 'warning', '/agent/reports/csat.php'); ?>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php if (!$reports_show_financial && !$reports_show_technical) {
    render_empty_state(
        'fas fa-lock',
        'No reporting access yet',
        "You don't currently have read access to a specific reporting area. Ask an administrator for Financial or Support reporting permission, or use the menu on the left if you already have access to a particular report."
    );
} ?>

<?php require_once "../../includes/footer.php"; ?>
