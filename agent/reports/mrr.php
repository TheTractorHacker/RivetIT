<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_financial');

$report = getMrrReport($mysqli);

// CSV export: top clients by MRR (same rows as the "Top Clients by MRR" table).
if (!empty($report_export_csv)) {
    $csv_header = ['Client', 'MRR', 'ARR'];
    $csv_rows = [];
    foreach ($report['top_clients'] as $c) {
        $csv_rows[] = [
            $c['client_name'],
            round($c['mrr'], 2),
            round($c['mrr'] * 12, 2),
        ];
    }
    report_send_csv('mrr_' . date('Y-m-d') . '.csv', $csv_header, $csv_rows);
}

$freq_labels = [
    'month'   => 'Monthly',
    'year'    => 'Yearly',
    'quarter' => 'Quarterly',
    'week'    => 'Weekly',
    'day'     => 'Daily',
];

?>

<style>
  .chart-h-320 { position: relative; height: 320px; }
  @media (max-width: 576px) { .chart-h-320 { height: 260px; } }

  /* KPI stat tiles: Bootstrap's .bg-* utilities only set background-color, so the
     default dark body text sits directly on a saturated fill (unreadable on
     bg-dark/success/danger/primary/secondary), and the undefined .bg-teal utility
     renders as a blank tile next to its colored siblings. Muted surface + colored
     left accent + colored icon instead, matching the app's contextual-row
     treatment (see itflow_design.css "Contextual rows"). */
  .info-box.bg-primary, .info-box.bg-secondary, .info-box.bg-success, .info-box.bg-danger,
  .info-box.bg-warning, .info-box.bg-info, .info-box.bg-dark, .info-box.bg-teal {
      background-color: var(--if-surface) !important;
      color: var(--if-ink) !important;
      border: 1px solid var(--if-border);
      border-inline-start-width: 3px;
      box-shadow: var(--if-shadow);
  }
  .info-box.bg-primary   { border-inline-start-color: #0d6efd; }
  .info-box.bg-secondary { border-inline-start-color: #6c757d; }
  .info-box.bg-success   { border-inline-start-color: #059669; }
  .info-box.bg-danger    { border-inline-start-color: #dc2626; }
  .info-box.bg-warning   { border-inline-start-color: #d97706; }
  .info-box.bg-info      { border-inline-start-color: #0891b2; }
  .info-box.bg-dark      { border-inline-start-color: #475569; }
  .info-box.bg-teal      { border-inline-start-color: var(--color-accent, #0d9488); }
  .info-box.bg-primary .info-box-icon   { color: #0d6efd; }
  .info-box.bg-secondary .info-box-icon { color: #6c757d; }
  .info-box.bg-success .info-box-icon   { color: #059669; }
  .info-box.bg-danger .info-box-icon    { color: #dc2626; }
  .info-box.bg-warning .info-box-icon   { color: #b45309; }
  .info-box.bg-info .info-box-icon      { color: #0891b2; }
  .info-box.bg-dark .info-box-icon      { color: #475569; }
  .info-box.bg-teal .info-box-icon      { color: var(--color-accent, #0d9488); }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-sync-alt me-2"></i>Monthly Recurring Revenue (MRR)</h3>
        <div class="card-tools">
            <a href="?<?php echo nullable_htmlentities(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-success d-print-none me-1"><i class="fas fa-fw fa-file-csv me-2"></i>Export CSV</a>
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body p-0">

        <div class="px-3 pt-3 pb-1">
            <small class="text-muted">Live snapshot of active recurring invoices. Trend approximates active-at-month-end (recurring invoices retain no amount-change history). Forecast projects scheduled billing forward from each schedule's next date.</small>
        </div>

        <!-- Headline stats -->
        <div class="row px-3">
            <div class="col-6 col-md-3">
                <div class="info-box bg-success mb-3">
                    <span class="info-box-icon"><i class="fas fa-sync-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Current MRR</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $report['mrr'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-primary mb-3">
                    <span class="info-box-icon"><i class="fas fa-calendar-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">ARR (MRR &times; 12)</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $report['arr'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box bg-info mb-3">
                    <span class="info-box-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active Schedules</span>
                        <span class="info-box-number"><?php echo intval($report['active_count']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="info-box <?php echo $report['movement']['net_new_mrr'] >= 0 ? 'bg-teal' : 'bg-danger'; ?> mb-3">
                    <span class="info-box-icon"><i class="fas fa-chart-line"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net New MRR (30d)</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $report['movement']['net_new_mrr'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="row px-3">
            <div class="col-lg-7">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>MRR Trend (trailing 12 months)</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="mrrTrendChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card card-outline card-secondary">
                    <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Forecast Billing (next 6 months)</h6></div>
                    <div class="card-body">
                        <div class="chart-h-320"><canvas id="mrrForecastChart"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Movement + Frequency -->
        <div class="row px-3">
            <div class="col-md-6">
                <h6 class="mt-2"><i class="fas fa-exchange-alt me-2"></i>MRR Movement (last 30 days)</h6>
                <div class="table-responsive-sm">
                    <table class="table table-striped table-sm">
                        <tbody>
                            <tr>
                                <td><span class="badge text-bg-success">New</span></td>
                                <td class="text-end"><?php echo intval($report['movement']['new_count']); ?> schedules</td>
                                <td class="text-end text-success">+<?php echo numfmt_format_currency($currency_format, $report['movement']['new_mrr'], $session_company_currency); ?></td>
                            </tr>
                            <tr>
                                <td><span class="badge text-bg-danger">Churned</span></td>
                                <td class="text-end"><?php echo intval($report['movement']['churned_count']); ?> schedules</td>
                                <td class="text-end text-danger">-<?php echo numfmt_format_currency($currency_format, $report['movement']['churned_mrr'], $session_company_currency); ?></td>
                            </tr>
                            <tr class="fw-bold">
                                <td>Net New</td>
                                <td class="text-end"></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $report['movement']['net_new_mrr'], $session_company_currency); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <h6 class="mt-2"><i class="fas fa-layer-group me-2"></i>MRR by Frequency</h6>
                <div class="table-responsive-sm">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr><th>Frequency</th><th class="text-end">Schedules</th><th class="text-end">MRR</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($report['by_frequency'])) { ?>
                                <tr><td colspan="3" class="text-center text-muted">No active recurring invoices.</td></tr>
                            <?php } else {
                                foreach ($report['by_frequency'] as $f) { ?>
                                <tr>
                                    <td><?php echo nullable_htmlentities($freq_labels[$f['frequency']] ?? ucfirst((string) $f['frequency'])); ?></td>
                                    <td class="text-end"><?php echo intval($f['count']); ?></td>
                                    <td class="text-end"><?php echo numfmt_format_currency($currency_format, $f['mrr'], $session_company_currency); ?></td>
                                </tr>
                            <?php } } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Top clients by MRR -->
        <div class="px-3 pb-3">
            <h6 class="mt-2"><i class="fas fa-crown me-2"></i>Top Clients by MRR</h6>
            <div class="table-responsive-sm">
                <table class="table table-striped table-sm">
                    <thead>
                        <tr><th>Client</th><th class="text-end">MRR</th><th class="text-end">ARR</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report['top_clients'])) { ?>
                            <tr><td colspan="3" class="text-center text-muted">No recurring revenue yet.</td></tr>
                        <?php } else {
                            foreach ($report['top_clients'] as $c) { ?>
                            <tr>
                                <td><a href="../../agent/client_overview.php?client_id=<?php echo intval($c['client_id']); ?>"><?php echo nullable_htmlentities($c['client_name']); ?></a></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, $c['mrr'], $session_company_currency); ?></td>
                                <td class="text-end"><?php echo numfmt_format_currency($currency_format, round($c['mrr'] * 12, 2), $session_company_currency); ?></td>
                            </tr>
                        <?php } } ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    // MRR TREND
    (function () {
        var ctx = document.getElementById("mrrTrendChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($report['trend']['labels']); ?>,
                datasets: [{
                    label: 'MRR',
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40,167,69,0.10)',
                    pointBackgroundColor: '#28a745',
                    fill: true,
                    tension: 0.3,
                    data: <?php echo json_encode($report['trend']['mrr']); ?>
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

    // FORECAST
    (function () {
        var ctx = document.getElementById("mrrForecastChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($report['forecast']['labels']); ?>,
                datasets: [{
                    label: 'Scheduled billing',
                    data: <?php echo json_encode($report['forecast']['amount']); ?>,
                    backgroundColor: '#007bff'
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
