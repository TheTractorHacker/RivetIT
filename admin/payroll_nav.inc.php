<?php
// Payroll section tab strip with live counts. Included by the admin/payroll_*.php pages (not a page itself).
if (!isset($mysqli)) { http_response_code(404); exit; }

$pn_count = function (string $sql) use ($mysqli): ?int {
    $q = @mysqli_query($mysqli, $sql);
    $r = $q ? mysqli_fetch_row($q) : null;
    return $r ? intval($r[0]) : null;
};
$pn_items = [
    ['payroll_employees.php', 'fa-user-friends', 'Employees', $pn_count("SELECT COUNT(*) FROM payroll_pay_rates")],
    ['payroll_periods.php', 'fa-calendar-alt', 'Pay periods', $pn_count("SELECT COUNT(*) FROM payroll_periods WHERE payroll_period_archived_at IS NULL")],
    ['payroll_runs.php', 'fa-play-circle', 'Runs', $pn_count("SELECT COUNT(*) FROM payroll_runs WHERE payroll_run_archived_at IS NULL")],
    ['payroll_deductions.php', 'fa-minus-circle', 'Deductions', $pn_count("SELECT COUNT(*) FROM payroll_deduction_categories")],
    ['payroll_settings.php', 'fa-sliders-h', 'Settings', null],
];
// Detail pages belong to their list's tab.
$pn_here = basename($_SERVER['SCRIPT_NAME'] ?? '');
$pn_map = ['payroll_employee.php' => 'payroll_employees.php', 'payroll_period.php' => 'payroll_periods.php', 'payroll_run.php' => 'payroll_runs.php'];
$pn_here = $pn_map[$pn_here] ?? $pn_here;
?>
<ul class="nav nav-pills mb-3 d-print-none" aria-label="Payroll sections">
    <?php foreach ($pn_items as [$pn_href, $pn_icon, $pn_label, $pn_n]) { ?>
        <li class="nav-item">
            <a class="nav-link <?= $pn_here === $pn_href ? 'active' : '' ?>" href="<?= $pn_href ?>"<?= $pn_here === $pn_href ? ' aria-current="page"' : '' ?>>
                <i class="fas fa-fw <?= $pn_icon ?> me-1"></i><?= $pn_label ?><?php if ($pn_n !== null) { ?> <span class="badge text-bg-light ms-1"><?= $pn_n ?></span><?php } ?>
            </a>
        </li>
    <?php } ?>
</ul>
