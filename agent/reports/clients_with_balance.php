<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_financial');

// Wave 2: AR aging — outstanding balance aged into 0-30/31-60/61-90/90+ buckets by
// days past invoice_due (set-based, shares getArAgingReport() with the API).
$ar = getArAgingReport($mysqli);
$b  = $ar['buckets'];

// CSV export: per-client AR aging (same rows as the aging table + a totals row).
if (!empty($report_export_csv)) {
    $csv_header = ['Client', '0-30', '31-60', '61-90', '90+', 'Balance'];
    $csv_rows = [];
    foreach ($ar['clients'] as $row) {
        $csv_rows[] = [
            $row['client_name'],
            round($row['b_0_30'], 2),
            round($row['b_31_60'], 2),
            round($row['b_61_90'], 2),
            round($row['b_90_plus'], 2),
            round($row['balance'], 2),
        ];
    }
    $csv_rows[] = ['Total', round($b['b_0_30'], 2), round($b['b_31_60'], 2), round($b['b_61_90'], 2), round($b['b_90_plus'], 2), round($b['total'], 2)];
    report_send_csv('ar_aging_' . $ar['as_of'] . '.csv', $csv_header, $csv_rows);
}

?>

<style>
  .chart-h-260 { position: relative; height: 260px; }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-exclamation-triangle me-2"></i>Clients with a Balance &mdash; AR Aging</h3>
        <div class="card-tools">
            <a href="?<?php echo nullable_htmlentities(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-success d-print-none me-1"><i class="fas fa-fw fa-file-csv me-2"></i>Export CSV</a>
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body p-0">

        <div class="px-3 pt-3 pb-1">
            <small class="text-muted">As of <strong><?php echo nullable_htmlentities($ar['as_of']); ?></strong>. Balances aged by days past the invoice due date; not-yet-due amounts sit in the 0-30 bucket.</small>
        </div>

        <!-- Aging bucket headline -->
        <div class="row px-3">
            <div class="col-6 col-md-3">
                <div class="info-box bg-success mb-3">
                    <span class="info-box-icon"><i class="fas fa-hourglass-start"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">0-30 days</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $b['b_0_30'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-warning mb-3">
                    <span class="info-box-icon"><i class="fas fa-hourglass-half"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">31-60 days</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $b['b_31_60'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-orange mb-3">
                    <span class="info-box-icon"><i class="fas fa-hourglass-end"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">61-90 days</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $b['b_61_90'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-danger mb-3">
                    <span class="info-box-icon"><i class="fas fa-exclamation-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">90+ days</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $b['b_90_plus'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row px-3">
            <div class="col-lg-5">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Outstanding by Age</h6></div>
                    <div class="card-body">
                        <div class="chart-h-260"><canvas id="arAgingChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="table-responsive-sm">
                    <table class="table table-striped table-sm">
                        <thead>
                        <tr>
                            <th>Client</th>
                            <th class="text-end">0-30</th>
                            <th class="text-end">31-60</th>
                            <th class="text-end">61-90</th>
                            <th class="text-end">90+</th>
                            <th class="text-end">Balance</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($ar['clients'])) { ?>
                            <tr><td colspan="6" class="text-center text-muted">No outstanding balances.</td></tr>
                        <?php } else {
                            foreach ($ar['clients'] as $row) {
                                $client_id = intval($row['client_id']);
                                $client_name = nullable_htmlentities($row['client_name']); ?>
                            <tr>
                                <td><a href="../../agent/invoices.php?client_id=<?php echo $client_id; ?>"><?php echo $client_name; ?></a></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $row['b_0_30'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $row['b_31_60'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $row['b_61_90'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $row['b_90_plus'], $session_company_currency); ?></td>
                                <td class="text-end text-bold"><?php echo numfmt_format_currency($currency_format, $row['balance'], $session_company_currency); ?></td>
                            </tr>
                        <?php } } ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold">
                                <td>Total</td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $b['b_0_30'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $b['b_31_60'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $b['b_61_90'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $b['b_90_plus'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $b['total'], $session_company_currency); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    (function () {
        var ctx = document.getElementById("arAgingChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['0-30', '31-60', '61-90', '90+'],
                datasets: [{
                    label: 'Outstanding',
                    data: [
                        <?php echo $b['b_0_30']; ?>,
                        <?php echo $b['b_31_60']; ?>,
                        <?php echo $b['b_61_90']; ?>,
                        <?php echo $b['b_90_plus']; ?>
                    ],
                    backgroundColor: ['#28a745', '#ffc107', '#fd7e14', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { maxTicksLimit: 6 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: false } }
            }
        });
    })();
});
</script>
