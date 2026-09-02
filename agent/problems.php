<?php

// Default Column Sortby Filter
$sort = "problems.created_at";
$order = "DESC";

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

if (isset($_GET['status']) && $_GET['status'] !== '') {
    $status_filter = sanitizeInput($_GET['status']);
    $status_query = "AND problems.status = '$status_filter'";
} else {
    $status_filter = '';
    $status_query = '';
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS problems.*, u.user_name AS created_by_name, c.title AS change_title,
        (SELECT COUNT(*) FROM tickets WHERE ticket_problem_id = problems.problem_id) AS linked_ticket_count
     FROM problems
     LEFT JOIN users u ON u.user_id = problems.created_by
     LEFT JOIN changes c ON c.change_id = problems.change_problem_id
     WHERE (problems.title LIKE '%$q%' OR problems.description LIKE '%$q%')
     $status_query
     ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$problem_status_badge = [
    'open' => 'text-bg-danger',
    'investigating' => 'text-bg-warning',
    'resolved' => 'text-bg-info',
    'closed' => 'text-bg-secondary',
];

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-exclamation-circle me-2"></i>Problems</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/problem/problem_add.php"><i class="fas fa-plus me-2"></i>New Problem</button>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <div class="row align-items-center">
                <div class="col-auto mb-2">
                    <select class="form-control select2 auto-submit-select" name="status" data-placeholder="Status" style="width:160px;">
                        <option value="">- All Statuses -</option>
                        <?php foreach (['open' => 'Open', 'investigating' => 'Investigating', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $val => $label) { ?>
                            <option value="<?= $val ?>" <?= $status_filter === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col mb-2">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Problems">
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
                        <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=problems.title&order=<?= $disp ?>">
                            Title <?php if ($sort == 'problems.title') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Status</th>
                    <th class="text-center">Linked Tickets</th>
                    <th>Linked Change</th>
                    <th>Created By</th>
                    <th>
                        <a class="text-dark" href="?<?= $url_query_strings_sort ?>&sort=problems.created_at&order=<?= $disp ?>">
                            Created <?php if ($sort == 'problems.created_at') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $problem_id = intval($row['problem_id']);
                    $badge = $problem_status_badge[$row['status']] ?? 'text-bg-secondary';
                    ?>
                    <tr>
                        <td><a class="text-dark" href="problem_details.php?id=<?= $problem_id ?>"><?= nullable_htmlentities($row['title']) ?></a></td>
                        <td><span class="badge <?= $badge ?>"><?= ucfirst($row['status']) ?></span></td>
                        <td class="text-center"><?= intval($row['linked_ticket_count']) ?></td>
                        <td>
                            <?php if ($row['change_problem_id']) { ?>
                                <a href="change_details.php?id=<?= intval($row['change_problem_id']) ?>"><?= nullable_htmlentities($row['change_title']) ?></a>
                            <?php } else { ?>
                                <span class="text-muted">&mdash;</span>
                            <?php } ?>
                        </td>
                        <td><?= nullable_htmlentities($row['created_by_name']) ?></td>
                        <td><?= nullable_htmlentities($row['created_at']) ?></td>
                        <td class="text-center">
                            <a class="btn btn-secondary btn-sm" href="problem_details.php?id=<?= $problem_id ?>"><i class="fas fa-eye"></i></a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php require_once "includes/filter_footer.php"; ?>
    </div>
</div>

<?php require_once "includes/footer.php"; ?>
