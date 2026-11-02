<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/../../../includes/icon_picker.php';

// No id = a new category.
$category_id = intval($_GET['id'] ?? 0);
$row = ['name' => '', 'description' => '', 'icon' => '', 'sort_order' => 0, 'is_active' => 1];
if ($category_id > 0) {
    $found = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM service_catalog_categories WHERE category_id = $category_id LIMIT 1"));
    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Category not found.']);
        exit;
    }
    $row = $found;
}

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-folder me-2"></i><?= $category_id ? 'Edit' : 'New' ?> Catalog Category</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="category_id" value="<?= $category_id ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="category_name" maxlength="100" value="<?= nullable_htmlentities($row['name']) ?>" required autofocus>
        </div>
        <div class="form-group">
            <label>Description <small class="text-secondary">(shown under the tile title)</small></label>
            <input type="text" class="form-control" name="category_description" maxlength="500" value="<?= nullable_htmlentities($row['description']) ?>">
        </div>
        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Icon</label>
                    <?php iconPickerField('category_icon', (string) $row['icon'], 'fa-folder'); ?>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>Sort order</label>
                    <input type="number" class="form-control" name="category_sort_order" value="<?= intval($row['sort_order']) ?>" step="1">
                </div>
            </div>
        </div>
        <div class="form-check form-switch">
            <input type="checkbox" class="form-check-input" name="category_is_active" value="1" id="catIsActive" <?= intval($row['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="catIsActive">Active (shown on Request Something)</label>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="save_service_catalog_category" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
