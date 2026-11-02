<?php
/*
 * Report toolbar, shown above every catalogued report page by inc_all_reports.php:
 * saved views (restore / save the current filters), filters panel (universal report filters), Export CSV, Download PDF, Print, shortcut to scheduling.
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
$tb_pdf_url   = '?' . http_build_query($tb_params + ['export' => 'pdf']);
$tb_filter_keys = ReportCatalog::definitions()[$report_key]['filters'] ?? [];
$tb_active_filters = array_intersect_key($tb_params, array_flip($tb_filter_keys));

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

        <?php if ($tb_filter_keys) { ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#rpt-filters" id="rpt-filters-toggle"><i class="fas fa-fw fa-filter me-1"></i>Filters<?php echo $tb_active_filters ? ' <span class="badge text-bg-primary ms-1">' . count($tb_active_filters) . '</span>' : ''; ?></button>
        <?php } ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#rpt-save-view"><i class="fas fa-fw fa-save me-1"></i>Save this view</button>
        <a class="btn btn-sm btn-outline-secondary" href="/agent/reports/saved_views.php"><i class="fas fa-fw fa-list me-1"></i>Manage</a>

        <span class="ms-auto d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-success" href="<?php echo nullable_htmlentities($tb_csv_url); ?>"><i class="fas fa-fw fa-file-csv me-1"></i>Export CSV</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo nullable_htmlentities($tb_pdf_url); ?>"><i class="fas fa-fw fa-file-pdf me-1"></i>Download PDF</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo nullable_htmlentities($tb_print_url); ?>" target="_blank" rel="noopener"><i class="fas fa-fw fa-print me-1"></i>Print</a>
            <?php if (lookupUserPermission('module_reporting') >= 2) { ?>
                <a class="btn btn-sm btn-outline-secondary" href="/agent/reports/schedules.php"><i class="fas fa-fw fa-paper-plane me-1"></i>Schedule</a>
            <?php } ?>
        </span>
    </div>
    <?php if ($tb_filter_keys) {
        $tb_opts = static function (string $sql) use ($mysqli): array {
            $out = [];
            $res = mysqli_query($mysqli, $sql);
            while ($res && ($r = mysqli_fetch_row($res))) { $out[(string) $r[0]] = (string) $r[1]; }
            return $out;
        };
        $tb_lists = [];
        if (in_array('f_client', $tb_filter_keys, true)) {
            $tb_lists['f_client'] = $tb_opts("SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . \ITFlow\Reports\ReportScope::clauseFor(\ITFlow\Reports\ReportScope::sessionIds(), 'client_id') . " ORDER BY client_name LIMIT 1000");
        }
        if (in_array('f_location', $tb_filter_keys, true)) {
            $tb_lists['f_location'] = $tb_opts("SELECT l.location_id, CONCAT(l.location_name, ' (', COALESCE(c.client_name, '-'), ')') FROM locations l LEFT JOIN clients c ON c.client_id = l.location_client_id WHERE l.location_archived_at IS NULL" . \ITFlow\Reports\ReportScope::clauseFor(\ITFlow\Reports\ReportScope::sessionIds(), 'l.location_client_id') . " ORDER BY l.location_name LIMIT 1000");
        }
        if (in_array('f_tech', $tb_filter_keys, true)) {
            $tb_lists['f_tech'] = $tb_opts("SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name LIMIT 500");
        }
        if (in_array('f_category', $tb_filter_keys, true)) {
            $tb_lists['f_category'] = $tb_opts("SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name LIMIT 500");
        }
        if (in_array('f_status', $tb_filter_keys, true)) {
            $tb_lists['f_status'] = $tb_opts("SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order, ticket_status_name");
        }
        if (in_array('f_priority', $tb_filter_keys, true)) {
            $tb_lists['f_priority'] = array_combine(\ITFlow\Reports\ReportFilters::PRIORITIES, \ITFlow\Reports\ReportFilters::PRIORITIES);
        }
        $tb_keep = array_diff_key($tb_params, array_flip($tb_filter_keys));
        ?>
    <div class="collapse<?php echo $tb_active_filters ? ' show' : ''; ?>" id="rpt-filters">
        <div class="card-body border-top py-2">
            <form method="get" class="row g-2 align-items-end" id="rpt-filter-form">
                <?php foreach ($tb_keep as $pk => $pv) { ?>
                    <input type="hidden" name="<?php echo nullable_htmlentities($pk); ?>" value="<?php echo nullable_htmlentities($pv); ?>">
                <?php } ?>
                <?php foreach ($tb_filter_keys as $fk) {
                    $fl = \ITFlow\Reports\ReportFilters::TICKET[$fk][0]; ?>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="form-label mb-1" for="rpt-<?php echo nullable_htmlentities($fk); ?>"><?php echo nullable_htmlentities($fl); ?></label>
                        <?php if (isset($tb_lists[$fk])) { ?>
                            <select class="form-control form-control-sm" id="rpt-<?php echo nullable_htmlentities($fk); ?>" name="<?php echo nullable_htmlentities($fk); ?>">
                                <option value="">Any</option>
                                <?php foreach ($tb_lists[$fk] as $ov => $ol) { ?>
                                    <option value="<?php echo nullable_htmlentities($ov); ?>"<?php echo (($tb_params[$fk] ?? '') === (string) $ov) ? ' selected' : ''; ?>><?php echo nullable_htmlentities($ol); ?></option>
                                <?php } ?>
                            </select>
                        <?php } else { ?>
                            <input type="number" min="1" class="form-control form-control-sm" id="rpt-<?php echo nullable_htmlentities($fk); ?>" name="<?php echo nullable_htmlentities($fk); ?>" value="<?php echo nullable_htmlentities($tb_params[$fk] ?? ''); ?>" placeholder="ID">
                        <?php } ?>
                    </div>
                <?php } ?>
                <div class="col-12 col-md-auto d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-fw fa-check me-1"></i>Apply</button>
                    <a class="btn btn-sm btn-outline-secondary" href="?<?php echo nullable_htmlentities(http_build_query($tb_keep)); ?>">Clear</a>
                </div>
            </form>
        </div>
    </div>
    <?php } ?>
    <?php if ($tb_active_filters) { ?>
    <script nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
    // The report's own date / year forms are plain GET forms; carry the active filters through them so changing the date does not drop them.
    (function () {
        var active = <?php echo json_encode($tb_active_filters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (!f || f.id === 'rpt-filter-form' || (f.method || 'get').toLowerCase() !== 'get') { return; }
            var a = f.getAttribute('action');
            if (a && a.indexOf('saved_views') !== -1) { return; }
            Object.keys(active).forEach(function (k) {
                if (f.elements[k]) { return; }
                var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = active[k]; f.appendChild(i);
            });
        }, true);
    })();
    </script>
    <?php } ?>
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
