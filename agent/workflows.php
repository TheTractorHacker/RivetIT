<?php
require_once "includes/inc_all.php";

// Employee lifecycle workflows (onboarding, offboarding, role change, transfer) in one place: find, open, cancel, delete, start.
enforceUserPermission('module_client');

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'active');
$type = (string) ($_GET['type'] ?? '');
$can_edit = lookupUserPermission('module_client') >= 2;
$can_delete = lookupUserPermission('module_client') >= 3;

$where = ["1=1"];
if ($status === 'active') { $where[] = "wr.status IN ('in_progress','paused')"; }
elseif (in_array($status, ['in_progress', 'paused', 'completed', 'completed_with_exceptions', 'cancelled'], true)) { $where[] = "wr.status = '" . mysqli_real_escape_string($mysqli, $status) . "'"; }
if (in_array($type, ['onboarding', 'offboarding', 'role_change', 'transfer'], true)) { $where[] = "wr.type = '$type'"; }
if ($q !== '') {
    $e = mysqli_real_escape_string($mysqli, $q);
    $where[] = "(c.contact_name LIKE '%$e%' OR t.name LIKE '%$e%' OR cl.client_name LIKE '%$e%')";
}
$where_sql = implode(' AND ', $where);

$rows = mysqli_fetch_all(mysqli_query($mysqli, "SELECT wr.run_id, wr.type, wr.status, wr.started_at, wr.completed_at, wr.contact_id,
        c.contact_name, c.contact_client_id, cl.client_name, t.name AS template_name, u.user_name AS started_by_name,
        (SELECT COUNT(*) FROM workflow_run_tasks x WHERE x.run_id = wr.run_id) AS n_tasks,
        (SELECT COUNT(*) FROM workflow_run_tasks x WHERE x.run_id = wr.run_id AND x.status IN ('completed','skipped')) AS n_done
    FROM workflow_runs wr
    LEFT JOIN contacts c ON c.contact_id = wr.contact_id
    LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
    LEFT JOIN workflow_templates t ON t.workflow_template_id = wr.workflow_template_id
    LEFT JOIN users u ON u.user_id = wr.started_by
    WHERE $where_sql $access_permission_query
    ORDER BY wr.started_at DESC LIMIT 300"), MYSQLI_ASSOC);

$badge = ['in_progress' => 'text-bg-primary', 'completed' => 'text-bg-success', 'completed_with_exceptions' => 'text-bg-warning', 'cancelled' => 'text-bg-secondary', 'paused' => 'text-bg-warning'];
$type_badge = ['onboarding' => 'text-bg-success', 'offboarding' => 'text-bg-danger'];
?>
<div class="card">
    <div class="card-header py-2 d-flex align-items-center flex-wrap gap-2">
        <h3 class="card-title me-auto"><i class="fas fa-fw fa-tasks me-2"></i>Workflows</h3>
        <?php if ($can_edit) { ?>
        <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/ticket/ticket_from_workflow.php" data-modal-size="lg"><i class="fas fa-play me-2"></i>Start workflow</button>
        <?php } ?>
        <?php if (!empty($session_is_admin)) { ?>
        <a class="btn btn-light" href="/admin/employee_workflow_templates.php"><i class="fas fa-edit me-2"></i>Edit templates</a>
        <?php } ?>
    </div>
    <div class="card-body">
        <form class="row g-2 mb-3" autocomplete="off">
            <div class="col-md-5"><input type="search" class="form-control" name="q" value="<?= nullable_htmlentities($q) ?>" placeholder="Search person, department or workflow" autofocus></div>
            <div class="col-md-3">
                <select class="form-select" name="status" onchange="this.form.submit()">
                    <?php foreach (['active' => 'Active (in progress)', 'all' => 'All statuses', 'completed' => 'Completed', 'completed_with_exceptions' => 'Completed with exceptions', 'cancelled' => 'Cancelled'] as $k => $l) { ?>
                        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="type" onchange="this.form.submit()">
                    <option value="">All types</option>
                    <?php foreach (['onboarding' => 'Onboarding', 'offboarding' => 'Offboarding', 'role_change' => 'Role change', 'transfer' => 'Transfer'] as $k => $l) { ?>
                        <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100" type="submit"><i class="fas fa-search me-2"></i>Search</button></div>
        </form>

        <?php if (!$rows) { ?>
            <p class="text-muted text-center my-4">No workflows match. <?= $can_edit ? 'Use <strong>Start workflow</strong> to begin one.' : '' ?></p>
        <?php } else { ?>
        <div>
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Person</th><th>Workflow</th><th>Progress</th><th>Status</th><th>Started</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r) { $pct = $r['n_tasks'] ? (int) round(100 * $r['n_done'] / $r['n_tasks']) : 0; $href = 'workflow_run.php?run_id=' . intval($r['run_id']); ?>
                <tr>
                    <td><a class="fw-bold" href="<?= $href ?>"><?= nullable_htmlentities($r['contact_name'] ?? 'Unknown') ?></a><div class="small text-muted"><?= nullable_htmlentities($r['client_name'] ?? '') ?></div></td>
                    <td><span class="badge <?= $type_badge[$r['type']] ?? 'text-bg-info' ?>"><?= ucwords(str_replace('_', ' ', $r['type'])) ?></span> <span class="ms-1"><?= nullable_htmlentities($r['template_name'] ?? '') ?></span></td>
                    <td style="min-width:140px"><div class="progress" style="height:6px"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div><div class="small text-muted mt-1"><?= intval($r['n_done']) ?> of <?= intval($r['n_tasks']) ?> done</div></td>
                    <td><span class="badge <?= $badge[$r['status']] ?? 'text-bg-secondary' ?>"><?= ucfirst(str_replace('_', ' ', $r['status'])) ?></span></td>
                    <td class="small"><?= nullable_htmlentities(date('M j, Y', strtotime($r['started_at']))) ?><div class="text-muted"><?= nullable_htmlentities($r['started_by_name'] ?? '') ?></div></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-primary" href="<?= $href ?>">Open</a>
                        <?php if ($can_edit || $can_delete) { ?>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-light dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="More actions"></button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item" href="contact_details.php?contact_id=<?= intval($r['contact_id']) ?>&client_id=<?= intval($r['contact_client_id']) ?>"><i class="fas fa-fw fa-user me-2"></i>Open contact</a>
                                <?php if ($can_edit && in_array($r['status'], ['in_progress', 'paused'], true)) { ?>
                                <form action="post.php" method="post" class="m-0" data-confirm-submit="Cancel this workflow? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="run_id" value="<?= intval($r['run_id']) ?>">
                                    <button type="submit" name="cancel_workflow_run" class="dropdown-item"><i class="fas fa-fw fa-ban me-2"></i>Cancel workflow</button>
                                </form>
                                <?php } ?>
                                <?php if ($can_delete) { ?>
                                <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_workflow_run=<?= intval($r['run_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete</a>
                                <?php } ?>
                            </div>
                        </div>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <?php } ?>
    </div>
</div>
<?php require_once "../includes/footer.php"; ?>
