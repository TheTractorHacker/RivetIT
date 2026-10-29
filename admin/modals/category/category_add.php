<?php

require_once '../../../includes/modal_header.php';

$category = nullable_htmlentities($_GET['category'] ?? '');

$category_types_array = ['Expense', 'Income', 'Referral', 'Ticket'];

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-list-ul me-2"></i>New <strong><?= nullable_htmlentities(ucwords(str_replace('_', ' ', $category))); ?></strong> Category</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

    <div class="modal-body">

        <?php if ($category) { ?>
        <input type="hidden" name="type" value="<?= $category ?>">
        <?php } else { ?>

        <div class="form-group">
            <label>Type <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <select class="form-control select2" name="type" required>
                    <option value="">Select Type</option>
                    <?php foreach ($category_types_array as $type_select) { ?>
                        <option><?= $type_select ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <?php } ?>

        <?php if ($category === 'Ticket') { ?>
        <div class="form-group">
            <label>Group <small class="text-muted">(optional — leave blank to create a new group)</small></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span></div>
                <select class="form-control select2" name="category_parent">
                    <option value="0">— New Group / Ungrouped —</option>
                    <?php
                    $sql_groups = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL ORDER BY category_name");
                    while ($g = mysqli_fetch_assoc($sql_groups)) echo "<option value=\"{$g['category_id']}\">" . nullable_htmlentities($g['category_name']) . "</option>";
                    ?>
                </select>
            </div>
        </div>
        <?php } else { ?>
        <input type="hidden" name="category_parent" value="0">
        <?php } ?>

        <?php if ($category === 'Ticket') { ?>
        <div class="form-group">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="category_sla_exempt" value="1" id="categorySlaExempt">
                <label class="form-check-label" for="categorySlaExempt">SLA exempt <small class="text-muted">(tickets in this category get no SLA policy, due dates or breaches)</small></label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="category_non_billable" value="1" id="categoryNonBillable">
                <label class="form-check-label" for="categoryNonBillable">Non-billable <small class="text-muted">(tickets in this category default to not billable)</small></label>
            </div>
        </div>
        <?php } ?>

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-list-ul"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Category name" maxlength="200" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Color <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-paint-brush"></i></span>
                </div>
                <input type="color" class="form-control col-3" name="color" required>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fas fa-fw fa-align-left"></i></span>
                </div>
                <input type="text" class="form-control" name="description" placeholder="Enter a description" maxlength="200">
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_category" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Create Category</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
