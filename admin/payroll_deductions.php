<?php

require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli, "SELECT * FROM payroll_deduction_categories WHERE payroll_deduction_category_archived_at IS NULL ORDER BY payroll_deduction_category_order ASC, payroll_deduction_category_name ASC");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-minus-circle me-2"></i>Deduction Categories</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/payroll_deduction/payroll_deduction_add.php">
                <i class="fas fa-plus me-2"></i>New Deduction Category
            </button>
        </div>
    </div>

    <div class="card-body">
        <p class="text-muted">Define the deductions you want to subtract from gross pay (Health Insurance, 401k, etc.) - ITFlow just subtracts the dollar amount you assign per employee, no tax/benefit rules are calculated.</p>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($sql) === 0) { ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No deduction categories yet.</td></tr>
                <?php } ?>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $dc_id   = intval($row['payroll_deduction_category_id']);
                    $dc_name = nullable_htmlentities($row['payroll_deduction_category_name']);
                    $dc_desc = nullable_htmlentities($row['payroll_deduction_category_description']);
                ?>
                <tr>
                    <td><?= $dc_name ?></td>
                    <td class="text-muted"><?= $dc_desc !== '' ? $dc_desc : '—' ?></td>
                    <td class="text-center" style="white-space:nowrap;">
                        <a href="#" class="btn btn-xs btn-secondary ajax-modal" data-modal-url="modals/payroll_deduction/payroll_deduction_edit.php?id=<?= $dc_id ?>">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="post.php?delete_payroll_deduction_category=<?= $dc_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                           class="btn btn-xs btn-danger confirm-link ms-1">
                            <i class="fas fa-trash"></i> Delete
                        </a>
                    </td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
