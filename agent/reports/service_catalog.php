<?php

require_once "includes/inc_all_reports.php";

enforceUserPermission('module_support');

// Service catalog request report (issue #30): volume, outcome, rejection rate and approval turnaround, per item and per category.
// inc_all_reports.php -> filter_header.php resolves the canned/custom date filter into $dtf/$dtt.
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);

$report_from = $dtf;
$report_to   = $dtt;
// "All time" sentinels are valid dates; the service takes them as they are. Show the real bounds in the header line.

$catalog_stats = $catalog_service->requestStats($report_from, $report_to, \ITFlow\Reports\ReportScope::clause('r.client_id'));
$t = $catalog_stats['totals'];

$fmt_hours = function ($h) {
    if ($h === null) { return '-'; }
    return $h < 1 ? round($h * 60) . ' min' : ($h >= 48 ? round($h / 24, 1) . ' d' : $h . ' h');
};

// CSV export: one row per catalog item (same rows as the table below).
if (!empty($report_export_csv)) {
    $csv_header = ['Item', 'Category', 'Requests', 'Approved', 'Auto-approved', 'Rejected', 'Pending', 'No approval needed', 'Rejection rate %', 'Avg turnaround (h)', 'Longest turnaround (h)'];
    $csv_rows = [];
    foreach ($catalog_stats['items'] as $r) {
        $csv_rows[] = [$r['name'], $r['category_name'], $r['requests'], $r['approved'], $r['auto_approved'], $r['rejected'], $r['pending'], $r['no_approval'], $r['rejection_rate'] ?? '', $r['avg_hours'] ?? '', $r['max_hours'] ?? ''];
    }
    report_send_csv('service_catalog_' . $report_from . '_to_' . $report_to . '.csv', $csv_header, $csv_rows);
}

$trend_max = 0;
foreach ($catalog_stats['trend'] as $d) { $trend_max = max($trend_max, $d['requests']); }

?>

<style>
  .catalog-report-bar { display: inline-block; height: .7rem; border-radius: 3px; background: var(--color-accent, #0d9488); vertical-align: middle; min-width: 2px; }
  .catalog-report-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: .75rem; }
</style>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-concierge-bell me-2"></i>Service Catalog Requests</h3>
        <div class="card-tools">
            <a href="?<?php echo nullable_htmlentities(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>" class="btn btn-success d-print-none me-1"><i class="fas fa-fw fa-file-csv me-2"></i>Export CSV</a>
            <button type="button" class="btn btn-primary d-print-none js-print-page"><i class="fas fa-fw fa-print me-2"></i>Print</button>
        </div>
    </div>
    <div class="card-body p-0">

        <form class="p-3 d-print-none">
            <label class="mb-1 d-block">Date range</label>
            <?php echo dateRangePickerField($date_range, 'canned_date', ['dates' => [$report_from, $report_to]]); ?>
        </form>

        <div class="px-3 pb-2">
            <small class="text-muted">
                Requests raised from the catalog between <strong><?php echo nullable_htmlentities($report_from); ?></strong> and <strong><?php echo nullable_htmlentities($report_to); ?></strong>.
                Turnaround is the time from submission to the final decision, for requests that went through approval and were decided (auto-approved requests are not counted).
            </small>
        </div>

        <div class="px-3 pb-3 catalog-report-stats">
            <?php render_stat_card('Requests', (string) $t['requests'], 'fas fa-inbox', 'primary'); ?>
            <?php render_stat_card('Approved', (string) $t['approved'], 'fas fa-check-circle', 'success'); ?>
            <?php render_stat_card('Rejected', (string) $t['rejected'], 'fas fa-times-circle', 'danger'); ?>
            <?php render_stat_card('Waiting now', (string) $t['pending'], 'fas fa-hourglass-half', 'warning'); ?>
            <?php render_stat_card('Rejection rate', $t['rejection_rate'] !== null ? $t['rejection_rate'] . '%' : '-', 'fas fa-percentage', 'violet'); ?>
            <?php render_stat_card('Avg approval time', $fmt_hours($t['avg_hours']), 'fas fa-stopwatch', 'info'); ?>
            <?php render_stat_card('Longest approval', $fmt_hours($t['max_hours']), 'fas fa-clock', 'slate'); ?>
            <?php render_stat_card('Auto-approved', (string) $t['auto_approved'], 'fas fa-bolt', 'slate'); ?>
        </div>

        <div class="px-3">
            <h6 class="mt-2"><i class="fas fa-th-large me-2"></i>By item</h6>
            <div class="table-responsive-sm">
                <table class="table table-striped table-sm" id="catalogReportItems">
                    <thead>
                        <tr><th>Item</th><th>Category</th><th class="text-end">Requests</th><th class="text-end">Approved</th><th class="text-end">Rejected</th><th class="text-end">Waiting</th><th class="text-end">Rejection rate</th><th class="text-end">Avg approval time</th><th class="text-end">Longest</th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$catalog_stats['items']) { ?>
                            <tr><td colspan="9" class="text-center text-secondary py-4">No catalog requests in this period.</td></tr>
                        <?php } ?>
                        <?php foreach ($catalog_stats['items'] as $r) { ?>
                            <tr>
                                <td><?php echo nullable_htmlentities($r['name']); ?></td>
                                <td><?php echo nullable_htmlentities($r['category_name']); ?></td>
                                <td class="text-end"><?php echo $r['requests']; ?></td>
                                <td class="text-end"><?php echo $r['approved']; ?><?php if ($r['auto_approved']) { ?> <small class="text-secondary">(<?php echo $r['auto_approved']; ?> auto)</small><?php } ?></td>
                                <td class="text-end"><?php echo $r['rejected']; ?></td>
                                <td class="text-end"><?php echo $r['pending']; ?></td>
                                <td class="text-end"><?php echo $r['rejection_rate'] !== null ? $r['rejection_rate'] . '%' : '-'; ?></td>
                                <td class="text-end"><?php echo $fmt_hours($r['avg_hours']); ?></td>
                                <td class="text-end"><?php echo $fmt_hours($r['max_hours']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row px-3 pb-3">
            <div class="col-lg-6">
                <h6 class="mt-2"><i class="fas fa-folder me-2"></i>By category</h6>
                <div class="table-responsive-sm">
                    <table class="table table-striped table-sm">
                        <thead><tr><th>Category</th><th class="text-end">Requests</th><th class="text-end">Rejection rate</th><th class="text-end">Avg approval time</th></tr></thead>
                        <tbody>
                            <?php if (!$catalog_stats['by_category']) { ?><tr><td colspan="4" class="text-center text-secondary py-3">Nothing to show.</td></tr><?php } ?>
                            <?php foreach ($catalog_stats['by_category'] as $r) { ?>
                                <tr>
                                    <td><?php echo nullable_htmlentities($r['category_name']); ?></td>
                                    <td class="text-end"><?php echo $r['requests']; ?></td>
                                    <td class="text-end"><?php echo $r['rejection_rate'] !== null ? $r['rejection_rate'] . '%' : '-'; ?></td>
                                    <td class="text-end"><?php echo $fmt_hours($r['avg_hours']); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-6">
                <h6 class="mt-2"><i class="fas fa-chart-bar me-2"></i>Requests per day</h6>
                <div class="table-responsive-sm" style="max-height: 360px; overflow:auto;">
                    <table class="table table-sm">
                        <tbody>
                            <?php if (!$catalog_stats['trend']) { ?><tr><td class="text-center text-secondary py-3">Nothing to show.</td></tr><?php } ?>
                            <?php foreach ($catalog_stats['trend'] as $d) { ?>
                                <tr>
                                    <td style="width:7.5rem"><?php echo nullable_htmlentities($d['day']); ?></td>
                                    <td><span class="catalog-report-bar" style="width: <?php echo $trend_max > 0 ? max(1, round($d['requests'] / $trend_max * 100)) : 0; ?>%"></span> <?php echo $d['requests']; ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
require_once "../../includes/footer.php";
