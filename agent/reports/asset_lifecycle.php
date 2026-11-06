<?php

require_once "includes/inc_all_reports.php";
require_once __DIR__ . "/includes/report_table.php";

enforceUserPermission('module_assets');

use ITFlow\Reports\Queries\AssetLifecycleReport;

$window = isset($_GET['days']) ? max(1, min(intval($_GET['days']), 3650)) : 90;
$age_years = isset($_GET['age_years']) ? max(1, min(intval($_GET['age_years']), 50)) : 5;
$al = AssetLifecycleReport::data($mysqli, $window, $age_years);
$tot = $al['totals'];

render_page_header('Asset Lifecycle and Warranty', 'Warranty status, device age and replacement candidates across the assets you can see.');
?>

<form class="mb-3 d-print-none row g-3 align-items-end">
    <?php foreach (['f_client', 'f_location'] as $keep) { if (!empty($_GET[$keep])) { ?>
        <input type="hidden" name="<?php echo $keep; ?>" value="<?php echo intval($_GET[$keep]); ?>">
    <?php } } ?>
    <div class="col-auto">
        <label class="form-label mb-1 d-block" for="al-days">Warranty expiring within</label>
        <select class="form-select auto-submit-select" id="al-days" name="days">
            <?php foreach ([30, 60, 90, 180, 365] as $d) { ?>
                <option value="<?php echo $d; ?>"<?php echo $d === $window ? ' selected' : ''; ?>><?php echo $d; ?> days</option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label mb-1 d-block" for="al-age">Replacement age</label>
        <select class="form-select auto-submit-select" id="al-age" name="age_years">
            <?php foreach ([3, 4, 5, 6, 7, 10] as $y) { ?>
                <option value="<?php echo $y; ?>"<?php echo $y === $age_years ? ' selected' : ''; ?>><?php echo $y; ?> years</option>
            <?php } ?>
        </select>
    </div>
</form>

<div class="it-stat-grid mb-3">
    <?php render_stat_card('Assets', (string) $tot['assets'], 'fas fa-laptop', 'slate'); ?>
    <?php render_stat_card('Warranty expired', (string) $tot['warranty_expired'], 'fas fa-shield-alt', $tot['warranty_expired'] > 0 ? 'danger' : 'slate'); ?>
    <?php render_stat_card('Expiring within ' . $window . ' days', (string) $tot['warranty_expiring'], 'fas fa-hourglass-half', 'warning'); ?>
    <?php render_stat_card('Older than ' . $age_years . ' years', (string) $tot['aged'], 'fas fa-history', 'info'); ?>
</div>

<?php
rpt_table('Summary', ['Measure', 'Value'], [
    ['Assets', $tot['assets']],
    ['Warranty expired', $tot['warranty_expired']],
    ['Warranty expiring within ' . $window . ' days', $tot['warranty_expiring']],
    ['No warranty date recorded', $tot['no_warranty_date']],
    ['No purchase or install date recorded', $tot['no_purchase_date']],
    ['Older than ' . $age_years . ' years', $tot['aged']],
]);
rpt_table('By asset type', ['Type', 'Assets', 'Average age (years)', 'Warranty expired', 'Older than ' . $age_years . ' years'],
    array_map(static fn ($r) => [$r['label'], $r['count'], $r['avg_years'] ?? '-', $r['expired'], $r['aged']], $al['by_type']));
rpt_table('By department', ['Department', 'Assets', 'Warranty expired', 'Expiring within ' . $window . ' days', 'Older than ' . $age_years . ' years'],
    array_map(static fn ($r) => [$r['label'], $r['count'], $r['expired'], $r['expiring'], $r['aged']], $al['by_client']));
$rows = [];
foreach ($al['assets'] as $a) {
    $rows[] = [['html' => '<a class="text-nowrap" href="/agent/asset_details.php?asset_id=' . intval($a['asset_id']) . '">' . nullable_htmlentities($a['asset_name']) . '</a>'],
        $a['asset_type'], trim(($a['asset_make'] ?? '') . ' ' . ($a['asset_model'] ?? '')), $a['asset_serial'] ?? '', $a['client_name'] ?? '', $a['location_name'] ?? '',
        $a['asset_purchase_date'] ?? ($a['asset_install_date'] ?? ''), $a['asset_warranty_expire'] ?? '',
        $a['days_left'] === null ? '' : ((int) $a['days_left'] < 0 ? 'Expired ' . abs((int) $a['days_left']) . 'd ago' : (int) $a['days_left'] . 'd left'),
        $a['age_years'] === null ? '' : number_format((float) $a['age_years'], 1)];
}
rpt_table('Warranty expiring or expired, and devices past replacement age', ['Asset', 'Type', 'Make / model', 'Serial', 'Department', 'Location', 'Purchased', 'Warranty ends', 'Warranty', 'Age (years)'], $rows, 'No assets need attention.');

require_once "../../includes/footer.php";
