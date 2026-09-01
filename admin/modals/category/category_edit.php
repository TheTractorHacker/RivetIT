<?php

require_once '../../../includes/modal_header.php';

$category_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_id = $category_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$category_name = nullable_htmlentities($row['category_name']);
$category_description = nullable_htmlentities($row['category_description']);
$category_color = nullable_htmlentities($row['category_color']);
$category_type = nullable_htmlentities($row['category_type']);
$category_parent = intval($row['category_parent']);

// Generate the HTML form content using output buffering.
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-list-ul me-2"></i>Editing category: <strong><?php echo $category_name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
    <input type="hidden" name="type" value="<?php echo $category_type; ?>">
    <div class="modal-body">

        <?php if ($category_type === 'Ticket') { ?>
        <div class="form-group">
            <label>Group <small class="text-muted">(leave blank = this is a group itself)</small></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span></div>
                <select class="form-control select2" name="category_parent">
                    <option value="0">— New Group / Ungrouped —</option>
                    <?php
                    $sql_groups = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL AND category_id != $category_id ORDER BY category_name");
                    while ($g = mysqli_fetch_assoc($sql_groups)) echo "<option value=\"{$g['category_id']}\"" . ($category_parent == $g['category_id'] ? ' selected' : '') . ">" . nullable_htmlentities($g['category_name']) . "</option>";
                    ?>
                </select>
            </div>
        </div>
        <?php } else { ?>
        <input type="hidden" name="category_parent" value="0">
        <?php } ?>

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-list-ul"></i></span>
                </div>
                <input type="text" class="form-control" name="name" maxlength="200" value="<?php echo $category_name; ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Color <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-paint-brush"></i></span>
                </div>
                <input type="color" class="form-control col-3" name="color" value="<?php echo $category_color; ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fas fa-fw fa-align-left"></i></span>
                </div>
                <input type="text" class="form-control" name="description" placeholder="Enter a description" maxlength="200" value="<?= $category_description ?>">
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_category" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
