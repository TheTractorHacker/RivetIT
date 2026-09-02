<?php

// Default Column Sortby Filter
$sort = "changes.created_at";
$order = "DESC";

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

if (isset($_GET['status']) && $_GET['status'] !== '') {
    $status_filter = sanitizeInput($_GET['status']);
    $status_query = "AND changes.status = '$status_filter'";
} else {
    $status_filter = '';
    $status_query = '';
}

if (isset($_GET['risk']) && $_GET['risk'] !== '') {
    $risk_filter = sanitizeInput($_GET['risk']);
    $risk_query = "AND changes.risk = '$risk_filter'";
} else {
    $risk_filter = '';
    $risk_query = '';
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS changes.*, u.user_name AS created_by_name
     FROM changes
     LEFT JOIN users u ON u.user_id = changes.created_by
     WHERE (changes.title LIKE '%$q%' OR changes.reason LIKE '%$q%')
     $status_query
     $risk_query
     ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$change_status_badge = [
    'draft' => 'text-bg-secondary',
    'awaiting_approval' => 'text-bg-warning',
    'approved' => 'text-bg-info',
    'scheduled' => 'text-bg-primary',
    'in_progress' => 'text-bg-primary',
    'successful' => 'text-bg-success',
    'failed' => 'text-bg-danger',
    'rolled_back' => 'text-bg-dark',
    'cancelled' => 'text-bg-secondary',
];

$change_risk_badge = [
    'low' => 'text-bg-success',
    'medium' => 'text-bg-warning',
    'high' => 'text-bg-danger',
];

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-exchange-alt me-2"></i>Changes</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/change/change_add.php"><i class="fas fa-plus me-2"></i>New Change</button>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <div class="row align-items-center">
                <div class="col-auto mb-2">
                    <select class="form-control select2 auto-submit-select" name="status" data-placeholder="Status" style="width:170px;">
                        <option value="">- All Statuses -</option>
                        <?php foreach (['draft' => 'Draft', 'awaiting_approval' => 'Awaiting Approval', 'approved' => 'Approved', 'scheduled' => 'Scheduled', 'in_progress' => 'In Progress', 'successful' => 'Successful', 'failed' => 'Failed', 'rolled_back' => 'Rolled Back', 'cancelled' => 'Cancelled'] as $val => $label) { ?>
                            <option value="<?= $val ?>" <?= $status_filter === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-auto mb-2">
                    <select class="form-control select2 auto-submit-select" name="risk" data-placeholder="Risk" style="width:130px;">
                        <option value="">- All Risk -</option>
                        <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $val => $label) { ?>
                            <option value="<?= $val ?>" <?= $risk_filter === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col mb-2">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Changes">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <hr>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=changes.title&order=<?= $disp ?>">
                            Title <?php if ($sort == 'changes.title') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Risk</th>
                    <th>Status</th>
                    <th>Scheduled</th>
                    <th>Created By</th>
                    <th>
                        <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=changes.created_at&order=<?= $disp ?>">
                            Created <?php if ($sort == 'changes.created_at') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $change_id = intval($row['change_id']);
                    $status_badge = $change_status_badge[$row['status']] ?? 'text-bg-secondary';
                    $risk_badge = $change_risk_badge[$row['risk']] ?? 'text-bg-secondary';
                    ?>
                    <tr>
                        <td><a class="text-dark" href="change_details.php?id=<?= $change_id ?>"><?= nullable_htmlentities($row['title']) ?></a></td>
                        <td><span class="badge <?= $risk_badge ?>"><?= ucfirst($row['risk']) ?></span></td>
                        <td><span class="badge <?= $status_badge ?>"><?= ucwords(str_replace('_', ' ', $row['status'])) ?></span></td>
                        <td><?= $row['scheduled_at'] ? nullable_htmlentities($row['scheduled_at']) : '<span class="text-muted">&mdash;</span>' ?></td>
                        <td><?= nullable_htmlentities($row['created_by_name']) ?></td>
                        <td><?= nullable_htmlentities($row['created_at']) ?></td>
                        <td class="text-center">
                            <a class="btn btn-secondary btn-sm" href="change_details.php?id=<?= $change_id ?>"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
