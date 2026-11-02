<?php
/*
 * Client Portal
 * Quality, department view for managers (issue #52): open and overdue corrective actions, overdue tasks, training compliance and pending acknowledgments for
 * the manager's OWN department. The department comes from the manager's contact record and is signed into the user context; the QMS enforces it too.
 * Live from the QMS, never stale. Employees who are not managers are sent back to quality.php.
 */

header("Content-Security-Policy: default-src 'self'");

ob_start();
require_once "includes/inc_all.php";
require_once "includes/quality_common.php";

use ITFlow\Quality\QualityService;

if (!$q_allowed) {
    ob_end_clean();
    $q_deny();
}
if (!$q_access['manager'] || empty($q_features['reports'])) {
    ob_end_clean();
    $_SESSION['alert_type'] = 'warning';
    $_SESSION['alert_message'] = 'The department view is for department managers.';
    header('Location: quality.php');
    exit();
}

$tabs = ['summary' => 'Overview', 'actions' => 'Corrective actions', 'overdue' => 'Overdue tasks', 'compliance' => 'Training compliance', 'acknowledgments' => 'Pending acknowledgments'];
$tab = (string) ($_GET['tab'] ?? 'summary');
if (!isset($tabs[$tab])) {
    $tab = 'summary';
}
$svc = new QualityService($mysqli);
$result = $svc->managerView(intval($session_contact_id), $tab);
$e = static fn($v): string => nullable_htmlentities((string) $v);
$num = static fn($v): string => $v === null ? 'n/a' : (string) (int) $v;
?>

<div class="row mb-3 gy-2">
    <div class="col-12 col-xl"><h3><i class="fas fa-fw fa-chart-bar me-2"></i>Quality: department view</h3></div>
    <div class="col-12 col-xl-auto"><a href="quality.php" class="btn btn-outline-primary"><i class="fas fa-user me-2"></i>My Quality</a></div>
</div>

<ul class="nav nav-pills mb-3 flex-wrap gap-1" aria-label="Department sections">
    <?php foreach ($tabs as $key => $label) { ?>
        <li class="nav-item"><a class="nav-link <?= $key === $tab ? 'active' : '' ?>" href="quality_manage.php?tab=<?= $e($key) ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a></li>
    <?php } ?>
</ul>

<div class="card card-outline card-primary mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <h5 class="mb-0"><?= $e($tabs[$tab]) ?></h5>
        <?php if (!empty($result['ok'])) { ?><div class="text-muted small ms-auto">Read from the quality system at <?= $e(date('H:i', strtotime($result['as_of']))) ?>. <a href="quality_manage.php?tab=<?= $e($tab) ?>">Refresh</a></div><?php } ?>
    </div>
    <div class="card-body <?= $tab === 'summary' ? '' : 'p-0' ?>">
    <?php if (empty($result['ok'])) { ?>
        <div class="p-4 text-center" role="alert" data-quality-unavailable="1">
            <div class="display-6 text-muted mb-2"><i class="fas fa-plug" aria-hidden="true"></i></div>
            <h5>The quality system is not available</h5>
            <p class="text-muted mb-2"><?= $e($result['message']) ?></p>
            <?php if (!empty($result['reference'])) { ?><p class="small text-muted mb-2">If this keeps happening, tell your administrator this reference: <code><?= $e($result['reference']) ?></code></p><?php } ?>
            <a class="btn btn-outline-primary btn-sm" href="quality_manage.php?tab=<?= $e($tab) ?>"><i class="fas fa-redo me-1"></i>Try again</a>
        </div>
    <?php } elseif ($tab === 'summary') { $sm = $result['summary']; ?>
        <div class="row g-3">
            <?php foreach ([['Open corrective actions', $sm['open_actions']], ['Overdue corrective actions', $sm['overdue_actions']], ['Overdue tasks', $sm['overdue_tasks']], ['Pending acknowledgments', $sm['pending_acknowledgments']]] as [$label, $val]) { ?>
                <div class="col-6 col-lg-3"><div class="border rounded p-3 h-100"><div class="text-muted small"><?= $e($label) ?></div><div class="fs-2 fw-bold"><?= $e($num($val)) ?></div></div></div>
            <?php } ?>
            <div class="col-12 col-lg-6"><div class="border rounded p-3 h-100"><div class="text-muted small">Training compliance</div><div class="fs-2 fw-bold"><?= $sm['training_compliance_pct'] === null ? 'n/a' : (int) $sm['training_compliance_pct'] . '%' ?></div></div></div>
        </div>
    <?php } elseif (!$result['items']) { ?>
        <p class="text-muted text-center py-4 mb-0">Nothing here right now.</p>
    <?php } else { ?>
        <ul class="list-group list-group-flush" data-quality-list="<?= $e($tab) ?>">
        <?php foreach ($result['items'] as $it) {
            $meta = [];
            if ($tab === 'compliance') {
                $meta = ['Assigned ' . (int) $it['assigned'], 'Completed ' . (int) $it['completed'], 'Overdue ' . (int) $it['overdue']];
            } elseif ($tab === 'acknowledgments') {
                $meta = ['Pending ' . (int) $it['pending_count']];
                if (($it['version'] ?? '') !== '') { $meta[] = 'Version ' . $it['version']; }
                if (!empty($it['due_on'])) { $meta[] = 'Due ' . $it['due_on']; }
            } else {
                if (!empty($it['due_on'])) { $meta[] = 'Due ' . $it['due_on']; }
                if (!empty($it['owner'])) { $meta[] = 'Owner ' . $it['owner']; }
                if (!empty($it['assignee'])) { $meta[] = $it['assignee']; }
            } ?>
            <li class="list-group-item">
                <div class="d-flex flex-wrap align-items-start gap-2">
                    <div class="flex-grow-1 text-break">
                        <div class="fw-bold"><?php if ($it['url']) { ?><a href="<?= $e($it['url']) ?>" rel="noopener noreferrer"><?= $e($it['title'] ?: $it['id']) ?></a><?php } else { echo $e($it['title'] ?: $it['id']); } ?></div>
                        <?php if ($meta) { ?><div class="text-muted small"><?= $e(implode('  |  ', $meta)) ?></div><?php } ?>
                    </div>
                    <div>
                    <?php if ($tab === 'compliance') { ?><span class="badge bg-<?= ($it['pct'] ?? 0) >= 90 ? 'success' : (($it['pct'] ?? 0) >= 70 ? 'warning text-dark' : 'danger') ?>"><?= $it['pct'] === null ? 'n/a' : (int) $it['pct'] . '%' ?></span>
                    <?php } elseif ($tab === 'acknowledgments') { ?><span class="badge bg-warning text-dark"><?= (int) $it['pending_count'] ?> pending</span>
                    <?php } elseif (!empty($it['overdue'])) { ?><span class="badge bg-danger">Overdue</span>
                    <?php } elseif ($it['status'] !== '') { ?><span class="badge bg-secondary"><?= $e(str_replace('_', ' ', $it['status'])) ?></span><?php } ?>
                    </div>
                </div>
            </li>
        <?php } ?>
        </ul>
        <?php if (!empty($result['more'])) { ?><p class="text-muted small p-3 mb-0">Showing the first <?= count($result['items']) ?>. Open the quality system to see the rest.</p><?php } ?>
    <?php } ?>
    </div>
</div>

<?php require_once "includes/footer.php";
