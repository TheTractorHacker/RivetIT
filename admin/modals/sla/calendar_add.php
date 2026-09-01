<?php
require_once '../../../includes/modal_header.php';

// Detect the app's configured timezone so we can pre-select it
$tz_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_timezone FROM settings WHERE company_id = 1 LIMIT 1"));
$default_tz = ($tz_row && !empty($tz_row['config_timezone'])) ? $tz_row['config_timezone'] : 'UTC';
$timezones = DateTimeZone::listIdentifiers();

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-business-time me-2"></i>New Business Hours Calendar</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="calendar_name" placeholder="e.g. Standard Support Hours" maxlength="150" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Timezone <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-globe"></i></span>
                </div>
                <select class="form-control select2" name="calendar_timezone" required>
                    <?php foreach ($timezones as $tz) { ?>
                        <option value="<?php echo nullable_htmlentities($tz); ?>" <?php if ($tz === $default_tz) { echo 'selected'; } ?>><?php echo nullable_htmlentities($tz); ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <div class="form-check form-check form-switch">
                <input type="checkbox" class="form-check-input" name="calendar_is_default" value="1" id="calDefaultSwitch">
                <label class="form-check-label" for="calDefaultSwitch">Set as default calendar</label>
            </div>
        </div>

        <div class="alert alert-info mb-0">
            <i class="fas fa-info-circle me-1"></i>Business hours default to <strong>Mon&ndash;Fri 09:00&ndash;17:00</strong>. Edit the calendar after creating it to adjust the daily windows and add holidays.
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_sla_calendar" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
