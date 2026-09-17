<?php
require_once '../../../includes/modal_header.php';
require_once '../../../includes/holiday_functions.php';

$company_country = sanitizeInput(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_country FROM companies WHERE company_id = 1"))['company_country'] ?? '');
$federal_holiday_countries = getFederalHolidayCountries();
$this_year = intval(date('Y'));

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-cloud-download-alt me-2"></i>Load Holidays</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">

        <p class="text-secondary small">Generates that country's public/bank/federal holidays (whichever term applies) for the year(s) below and adds them to the catalog. Existing entries for the same country/date are left alone - safe to run again later.</p>

        <div class="form-group">
            <label>Country <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-globe-americas"></i></span>
                </div>
                <select class="form-control select2" name="holiday_country" required>
                    <option value="">Select Country</option>
                    <?php foreach ($federal_holiday_countries as $c) { ?>
                        <option value="<?php echo nullable_htmlentities($c); ?>" <?php if ($company_country === $c) { echo "selected"; } ?>><?php echo nullable_htmlentities($c); ?></option>
                    <?php } ?>
                </select>
            </div>
            <small class="text-secondary">Only countries with a built-in holiday rule set are listed. Anything else - add holidays as Custom instead.</small>
        </div>

        <div class="form-group">
            <label>From Year <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                </div>
                <input type="number" class="form-control" name="holiday_year_from" value="<?php echo $this_year; ?>" min="1970" max="2100" required>
            </div>
        </div>

        <div class="form-group">
            <label>To Year <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                </div>
                <input type="number" class="form-control" name="holiday_year_to" value="<?php echo $this_year + 1; ?>" min="1970" max="2100" required>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="load_holidays" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Load</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
