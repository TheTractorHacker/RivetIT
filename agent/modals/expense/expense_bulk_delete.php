<?php

require_once '../../../includes/modal_header.php';

$expense_ids = array_map('intval', $_GET['expense_ids'] ?? []);

$count = count($expense_ids);

// Generate the HTML form content using output buffering.
ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-trash me-2"></i>Delete <?= $count ?> expense<?= $count == 1 ? '' : 's' ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($expense_ids as $expense_id) { ?> <input type="hidden" name="expense_ids[]" value="<?= $expense_id ?>"><?php } ?>
    <div class="modal-body">
        <p class="mb-2">This will permanently delete the selected expense<?= $count == 1 ? '' : 's' ?> and all associated data.</p>
        <p class="text-muted small mb-0">This action cannot be undone.</p>
    </div>
    <div class="modal-footer">
        <button type="submit" name="bulk_delete_expenses" class="btn btn-danger text-bold"><i class="fa fa-fw fa-trash me-2"></i>Delete</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
