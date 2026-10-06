<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/../../../includes/icon_picker.php';

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-th-large me-2"></i>New Catalog Item</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="e.g. New Laptop Request" maxlength="200" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Description <small class="text-secondary">(shown on the Request Something tile)</small></label>
            <textarea class="form-control" name="description" rows="2" maxlength="500"></textarea>
        </div>

        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Icon</label>
                    <?php iconPickerField('icon', '', 'fa-ticket-alt'); ?>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>Sort Order</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-sort-numeric-down"></i></span>
                        </div>
                        <input type="number" class="form-control" name="sort_order" value="0" step="1">
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
                <input type="text" class="form-control" name="ticket_subject_template" placeholder="e.g. New laptop request" maxlength="500" required>
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
                            <?php echo ticketCategoryOptions($mysqli); ?>
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
                            <option value="">Not set</option>
                            <option>Low</option>
                            <option selected>Medium</option>
                            <option>High</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" name="is_active" value="1" id="isActiveAdd" checked>
                <label class="form-check-label" for="isActiveAdd">Active (visible on Request Something)</label>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_service_catalog_item" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
