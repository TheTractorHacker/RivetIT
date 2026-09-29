<?php
/*
 * Client Portal
 * Manage training: the department's (or, for a supervisor, their reports') compliance overview.
 * Read-only. Same scope rules as training.php (fail-closed to contact_client_id; department-wide only for
 * primary/technical contacts and portal Managers; supervisors see only people below them).
 */

header("Content-Security-Policy: default-src 'self'");

ob_start();
require_once "includes/inc_all.php";

if (intval($config_module_enable_training ?? 0) !== 1 || empty($config_training_schema_ready)) {
    ob_end_clean();
    header("Location: index.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once __DIR__ . '/includes/training_common.php';

if (!$tp_show_team) {
    ob_end_clean();
    header("Location: training.php");
    exit();
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvSafe = static function ($v): string {
        $v = (string) $v;
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
    };
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="training-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Person', 'Title', 'Course', 'Required', 'Status', 'Due / expires']);
    foreach ($tp_team as $p) {
        if (!isset($tp_team_people[$p['contact_id']])) {
            continue;
        }
        fputcsv($out, [
            $csvSafe($tp_team_people[$p['contact_id']]['name'] ?? ''),
            $csvSafe($tp_team_people[$p['contact_id']]['title'] ?? ''),
            $csvSafe($p['course']['name']),
            ($p['required'] && !$p['waived']) ? 'Yes' : 'No',
            $csvSafe($p['waived'] ? 'Waived' : ($p['label'] !== '' ? $p['label'] : $p['status'])),
            $csvSafe($tp_when($p)),
        ]);
    }
    fclose($out);
    exit();
}
?>

<div class="row mb-4">
    <div class="col">
        <h3><i class="fas fa-fw fa-chart-bar me-2"></i>Manage training</h3>
        <div class="text-muted"><?= $tp_dept_wide ? 'Everyone in your department' : 'You and the people who report to you' ?>.</div>
    </div>
    <div class="col-auto">
        <a href="training.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>My training</a>
        <a href="training_manage.php?export=csv" class="btn btn-outline-primary"><i class="fas fa-download me-2"></i>Export CSV</a>
    </div>
</div>

<?php
    $required = array_values(array_filter($tp_team, static fn($p) => $p['required'] && !$p['waived']));
    $current = count(array_filter($required, static fn($p) => $p['counts_current']));
    $overdue = count(array_filter($required, static fn($p) => $p['status'] === 'overdue'));
    $soon = count(array_filter($required, static fn($p) => in_array($p['status'], ['due_soon', 'expiring', 'retrain_due'], true)));
    $pct = $required ? (int) round(100 * $current / count($required)) : 100;
    $attention = array_values(array_filter($tp_team, $tp_needs_attention));
    usort($attention, static function ($a, $b) use ($tp_urgency, $tp_team_people, $tp_when) {
        return [$tp_urgency[$a['status']] ?? 9, $tp_when($a), $tp_team_people[$a['contact_id']]['name'] ?? ''] <=> [$tp_urgency[$b['status']] ?? 9, $tp_when($b), $tp_team_people[$b['contact_id']]['name'] ?? ''];
    });
    $per = [];
    foreach ($tp_team_people as $cid => $person) {
        $per[$cid] = ['person' => $person, 'total' => 0, 'current' => 0, 'overdue' => 0, 'soon' => 0];
    }
    foreach ($required as $p) {
        if (!isset($per[$p['contact_id']])) {
            continue;
        }
        $per[$p['contact_id']]['total']++;
        if ($p['counts_current']) { $per[$p['contact_id']]['current']++; }
        if ($p['status'] === 'overdue') { $per[$p['contact_id']]['overdue']++; }
        elseif (in_array($p['status'], ['due_soon', 'expiring', 'retrain_due'], true)) { $per[$p['contact_id']]['soon']++; }
    }
    uasort($per, static fn($a, $b) => [$b['overdue'], $b['soon'], $a['person']['name']] <=> [$a['overdue'], $a['soon'], $b['person']['name']]);
    ?>
    <div class="row mb-3">
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h2 mb-0"><?= count($tp_team_people) ?></div><div class="text-muted"><?= $tp_dept_wide ? 'People in department' : 'People on your team' ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h2 mb-0 text-<?= $pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'danger') ?>"><?= $pct ?>%</div><div class="text-muted">Up to date</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h2 mb-0 text-<?= $overdue ? 'danger' : 'success' ?>"><?= $overdue ?></div><div class="text-muted">Overdue</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h2 mb-0 text-<?= $soon ? 'warning' : 'success' ?>"><?= $soon ?></div><div class="text-muted">Due soon / expiring</div></div></div></div>
    </div>

    <div class="card card-outline card-warning mb-4">
        <div class="card-header"><h5 class="mb-0">Needs attention</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light"><tr><th>Person</th><th>Course</th><th>Status</th><th>Due / expires</th></tr></thead>
                    <tbody>
                    <?php if (!$attention) { ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Nothing overdue or coming due.</td></tr>
                    <?php }
                    foreach ($attention as $p) {
                        [$cls, $txt] = $tp_badge($p); ?>
                        <tr>
                            <td><?= nullable_htmlentities($tp_team_people[$p['contact_id']]['name'] ?? '') ?></td>
                            <td><?= nullable_htmlentities($p['course']['name']) ?></td>
                            <td><span class="badge bg-<?= $cls ?>"><?= nullable_htmlentities($txt) ?></span></td>
                            <td><?= nullable_htmlentities($tp_when($p)) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card card-outline card-primary mb-4">
        <div class="card-header"><h5 class="mb-0">By person</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light"><tr><th>Person</th><th>Title</th><th class="text-end">Required</th><th class="text-end">Up to date</th><th class="text-end">Due soon</th><th class="text-end">Overdue</th></tr></thead>
                    <tbody>
                    <?php foreach ($per as $row) { ?>
                        <tr>
                            <td><?= nullable_htmlentities($row['person']['name']) ?></td>
                            <td class="text-muted"><?= nullable_htmlentities($row['person']['title'] ?? '') ?></td>
                            <td class="text-end"><?= $row['total'] ?></td>
                            <td class="text-end"><?= $row['current'] ?></td>
                            <td class="text-end"><?= $row['soon'] ? '<span class="badge bg-warning">' . $row['soon'] . '</span>' : '0' ?></td>
                            <td class="text-end"><?= $row['overdue'] ? '<span class="badge bg-danger">' . $row['overdue'] . '</span>' : '0' ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php
// People-by-course matrix (required courses only)
$courses = [];
foreach ($required as $p) {
    $courses[$p['course']['id']] = $p['course']['name'];
}
asort($courses);
$cell = [];
foreach ($required as $p) {
    $cell[$p['contact_id']][$p['course']['id']] = $p;
}
if ($courses) { ?>
    <div class="card card-outline card-secondary mb-4">
        <div class="card-header"><h5 class="mb-0">People by course</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light"><tr><th>Person</th>
                        <?php foreach ($courses as $cn) { ?><th class="text-center"><?= nullable_htmlentities($cn) ?></th><?php } ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($per as $cid => $row) { ?>
                        <tr><td><?= nullable_htmlentities($row['person']['name']) ?></td>
                        <?php foreach ($courses as $courseId => $cn) {
                            $p = $cell[$cid][$courseId] ?? null; ?>
                            <td class="text-center"><?php if ($p) { [$cls, $txt] = $tp_badge($p); ?><span class="badge bg-<?= $cls ?>"><?= nullable_htmlentities($txt) ?></span><?php } else { ?><span class="text-muted">-</span><?php } ?></td>
                        <?php } ?>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php } ?>

<?php require_once "includes/footer.php";
