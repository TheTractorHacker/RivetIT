<?php

require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli, "SELECT * FROM labor_types WHERE labor_type_archived_at IS NULL ORDER BY labor_type_order ASC, labor_type_name ASC");

?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-clock me-2"></i>Labor Types</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/labor_type/labor_type_add.php">
                <i class="fas fa-plus me-2"></i>New Labor Type
            </button>
        </div>
    </div>

    <div class="card-body">
        <p class="text-muted">Define labor types (Remote, Onsite, After Hours, etc.) with default rates. These appear as quick-select options when adding charges to tickets.</p>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Name</th>
                        <th>Default Rate</th>
                        <th>Color</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $lt_id    = intval($row['labor_type_id']);
                    $lt_name  = nullable_htmlentities($row['labor_type_name']);
                    $lt_rate  = floatval($row['labor_type_rate']);
                    $lt_color = nullable_htmlentities($row['labor_type_color']);
                ?>
                <tr>
                    <td>
                        <span class="badge rounded-pill text-white px-3 py-2" style="background:<?= $lt_color ?>;"><?= $lt_name ?></span>
                    </td>
                    <td><?= $lt_rate > 0 ? '$' . number_format($lt_rate, 2) . '/hr' : '<span class="text-muted">—</span>' ?></td>
                    <td><span style="display:inline-block;width:24px;height:24px;border-radius:4px;background:<?= $lt_color ?>;border:1px solid #ccc;"></span> <?= $lt_color ?></td>
                    <td class="text-center" style="white-space:nowrap;">
                        <div class="dropdown dropstart d-inline-block">
                            <button class="btn btn-light btn-sm text-secondary" type="button" data-bs-toggle="dropdown" data-boundary="window" aria-label="Actions for <?= $lt_name ?>">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/labor_type/labor_type_edit.php?id=<?= $lt_id ?>"><i class="fas fa-fw fa-edit me-2"></i>Edit</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="post.php?delete_labor_type=<?= $lt_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-trash-alt me-2"></i>Delete</a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php } ?>
                <?php if (mysqli_num_rows($sql) === 0) { ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Nothing here yet. Use the New button above to add the first labor type.</td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
