<?php

require_once '../../../includes/modal_header.php';

$catalog_item_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM service_catalog_items WHERE catalog_item_id = $catalog_item_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Catalog item not found.']);
    exit;
}

$name = nullable_htmlentities($row['name']);
$description = nullable_htmlentities($row['description']);
$icon = nullable_htmlentities($row['icon']);
$ticket_subject_template = nullable_htmlentities($row['ticket_subject_template']);
$ticket_category_id = intval($row['ticket_category_id']);
$default_priority = $row['default_priority'];
$is_active = intval($row['is_active']);
$sort_order = intval($row['sort_order']);

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-th-large me-2"></i>Edit Catalog Item: <strong><?php echo $name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="catalog_item_id" value="<?php echo $catalog_item_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" maxlength="200" value="<?php echo $name; ?>" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Description <small class="text-secondary">(shown on the Request Something tile)</small></label>
            <textarea class="form-control" name="description" rows="2" maxlength="500"><?php echo $description; ?></textarea>
        </div>

        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Icon <small class="text-secondary">(Font Awesome class, e.g. fa-laptop)</small></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-icons"></i></span>
                        </div>
                        <input type="text" class="form-control" name="icon" placeholder="fa-laptop" maxlength="100" value="<?php echo $icon; ?>">
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>Sort Order</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-sort-numeric-down"></i></span>
                        </div>
                        <input type="number" class="form-control" name="sort_order" value="<?php echo $sort_order; ?>" step="1">
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Ticket Subject <strong class="text-danger">*</strong> <small class="text-secondary">(used as-is - no merge fields)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-life-ring"></i></span>
                </div>
                <input type="text" class="form-control" name="ticket_subject_template" maxlength="500" value="<?php echo $ticket_subject_template; ?>" required>
            </div>
        </div>

        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Ticket Category</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                        </div>
                        <select class="form-control select2" name="ticket_category_id">
                            <option value="0">- Not Categorized -</option>
                            <?php echo ticketCategoryOptions($mysqli, $ticket_category_id); ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>Default Priority</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-thermometer-half"></i></span>
                        </div>
                        <select class="form-control select2" name="default_priority">
                            <option value="" <?php if (!$default_priority) { echo "selected"; } ?>>- Not set -</option>
                            <?php foreach (['Low', 'Medium', 'High'] as $p) { ?>
                                <option <?php if ($default_priority === $p) { echo "selected"; } ?>><?php echo $p; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" name="is_active" value="1" id="isActiveEdit" <?php if ($is_active) { echo "checked"; } ?>>
                <label class="form-check-label" for="isActiveEdit">Active (visible on Request Something)</label>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_service_catalog_item" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
