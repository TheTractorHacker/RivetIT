<?php
require_once '../../../includes/modal_header.php';

$company_country = sanitizeInput(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_country FROM companies WHERE company_id = 1"))['company_country'] ?? '');

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-calendar-plus me-2"></i>New Custom Holiday</h5>
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
                <input type="text" class="form-control" name="holiday_name" placeholder="e.g. Company Shutdown Day" maxlength="150" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Date <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar-day"></i></span>
                </div>
                <input type="date" class="form-control" name="holiday_date" required>
            </div>
        </div>

        <div class="form-group">
            <label>Country <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-globe-americas"></i></span>
                </div>
                <select class="form-control select2" name="holiday_country" required>
                    <?php foreach ($countries_array as $country_name) { ?>
                        <option <?php if ($company_country === $country_name) { echo "selected"; } ?>><?php echo nullable_htmlentities($country_name); ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_holiday" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
