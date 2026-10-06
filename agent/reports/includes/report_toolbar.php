<?php
/*
 * Report toolbar, shown above every catalogued report page by inc_all_reports.php:
 * saved views (restore / save the current filters), Export CSV, Print / PDF, shortcut to scheduling.
 * Not rendered in CSV or headless mode. $report_key and $report_print_view come from inc_all_reports.php.
 */

use ITFlow\Reports\ReportCatalog;
use ITFlow\Reports\SavedReports;

// Current whitelisted filters. filter_header.php defaults canned_date to "custom" when nothing was chosen; that is not a filter.
$tb_request = $_GET;
if (($tb_request['canned_date'] ?? '') === 'custom' && empty($tb_request['dtf'])) {
    unset($tb_request['canned_date']);
}
$tb_params = ReportCatalog::sanitizeParams($report_key, $tb_request);
$tb_csv_url   = '?' . http_build_query($tb_params + ['export' => 'csv']);
$tb_print_url = '?' . http_build_query($tb_params + ['print' => '1']);

if (!empty($report_print_view)) {
    // Print view: same page, app chrome hidden, the browser's print dialog opens (choose "Save as PDF" there).
    ?>
    <style nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
        aside.navbar-vertical, header.navbar, .navbar-vertical, .d-print-none, .page-header .btn-list { display: none !important; }
        .page-wrapper, .page { margin-left: 0 !important; padding-left: 0 !important; }
        body { background: #fff !important; }
    </style>
    <div class="d-print-none"></div>
    <script nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); });
    </script>
    <?php
    return;
}

$tb_views = SavedReports::listFor($mysqli, intval($session_user_id), $report_key, static fn ($m) => $m === '' || lookupUserPermission($m) >= 1);
?>
<div class="card mb-3 d-print-none">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <form method="get" action="/agent/reports/saved_views.php" class="d-flex align-items-center gap-2 mb-0">
            <input type="hidden" name="report" value="<?php echo nullable_htmlentities($report_key); ?>">
            <label class="mb-0 text-muted text-nowrap" for="rpt-saved-view"><i class="fas fa-fw fa-bookmark me-1"></i>Saved view</label>
            <select class="form-control form-control-sm auto-submit-select" id="rpt-saved-view" name="go" style="min-width: 14rem">
                <option value="">Choose a saved view (<?php echo count($tb_views); ?>)</option>
                <?php foreach ($tb_views as $v) { ?>
                    <option value="<?php echo intval($v['saved_report_id']); ?>">
                        <?php echo nullable_htmlentities($v['saved_report_name']); ?><?php echo intval($v['saved_report_user_id']) === intval($session_user_id) ? '' : ' (shared by ' . nullable_htmlentities($v['user_name'] ?? 'someone') . ')'; ?>
                    </option>
                <?php } ?>
            </select>
        </form>

        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#rpt-save-view"><i class="fas fa-fw fa-save me-1"></i>Save this view</button>
        <a class="btn btn-sm btn-outline-secondary" href="/agent/reports/saved_views.php"><i class="fas fa-fw fa-list me-1"></i>Manage</a>

        <span class="ms-auto d-flex gap-2">
            <a class="btn btn-sm btn-success" href="<?php echo nullable_htmlentities($tb_csv_url); ?>"><i class="fas fa-fw fa-file-csv me-1"></i>Export CSV</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo nullable_htmlentities($tb_print_url); ?>" target="_blank" rel="noopener"><i class="fas fa-fw fa-file-pdf me-1"></i>Print / PDF</a>
            <?php if (lookupUserPermission('module_reporting') >= 2) { ?>
                <a class="btn btn-sm btn-outline-secondary" href="/agent/reports/schedules.php"><i class="fas fa-fw fa-paper-plane me-1"></i>Schedule</a>
            <?php } ?>
        </span>
    </div>
    <div class="collapse" id="rpt-save-view">
        <div class="card-body border-top py-2">
            <form method="post" action="/agent/reports/saved_views.php" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="report" value="<?php echo nullable_htmlentities($report_key); ?>">
                <?php foreach ($tb_params as $pk => $pv) { ?>
                    <input type="hidden" name="p[<?php echo nullable_htmlentities($pk); ?>]" value="<?php echo nullable_htmlentities($pv); ?>">
                <?php } ?>
                <div class="col-md-5">
                    <label class="form-label mb-1" for="rpt-view-name">Name</label>
                    <input type="text" class="form-control form-control-sm" id="rpt-view-name" name="name" maxlength="<?php echo SavedReports::MAX_NAME; ?>" required placeholder="e.g. Last quarter, billable only">
                </div>
                <div class="col-md-4">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="shared" value="1" id="rpt-view-shared">
                        <label class="form-check-label" for="rpt-view-shared">Share with everyone who has Reporting access</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-fw fa-check me-1"></i>Save view</button>
                </div>
                <div class="col-12 text-muted small">Saves the filters currently applied (<?php echo $tb_params ? nullable_htmlentities(http_build_query($tb_params)) : 'none, the report defaults'; ?>).</div>
            </form>
        </div>
    </div>
</div>
