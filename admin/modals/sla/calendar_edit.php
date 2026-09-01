<?php
require_once '../../../includes/modal_header.php';

$calendar_id = intval($_GET['id']);

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM sla_business_hours WHERE calendar_id = $calendar_id LIMIT 1"));
if (!$row) { exit('Calendar not found'); }

$calendar_name = nullable_htmlentities($row['calendar_name']);
$calendar_timezone_raw = $row['calendar_timezone'] ?: 'UTC';
$calendar_is_default = intval($row['calendar_is_default']);

$timezones = DateTimeZone::listIdentifiers();
$day_names = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

// Existing windows keyed by day of week (first window per day drives the single-window UI)
$day_windows = [];
$pres = mysqli_query($mysqli, "SELECT day_of_week, open_time, close_time FROM sla_business_hours_periods WHERE calendar_id = $calendar_id ORDER BY day_of_week, open_time");
while ($pr = mysqli_fetch_assoc($pres)) {
    $dow = intval($pr['day_of_week']);
    if (!isset($day_windows[$dow])) {
        $day_windows[$dow] = ['open' => substr($pr['open_time'], 0, 5), 'close' => substr($pr['close_time'], 0, 5)];
    }
}

// Existing holidays
$holidays = [];
$hres = mysqli_query($mysqli, "SELECT holiday_id, holiday_date, holiday_name FROM sla_holidays WHERE calendar_id = $calendar_id ORDER BY holiday_date");
while ($hr = mysqli_fetch_assoc($hres)) { $holidays[] = $hr; }

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-business-time me-2"></i>Editing Calendar: <strong><?php echo $calendar_name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="calendar_id" value="<?php echo $calendar_id; ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="calendar_name" value="<?php echo $calendar_name; ?>" maxlength="150" required>
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
                        <option value="<?php echo nullable_htmlentities($tz); ?>" <?php if ($tz === $calendar_timezone_raw) { echo 'selected'; } ?>><?php echo nullable_htmlentities($tz); ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <div class="form-check form-check form-switch">
                <input type="checkbox" class="form-check-input" name="calendar_is_default" value="1" id="calDefaultSwitchEdit" <?php if ($calendar_is_default) { echo 'checked'; } ?>>
                <label class="form-check-label" for="calDefaultSwitchEdit">Default calendar</label>
            </div>
        </div>

        <hr>
        <label class="text-bold">Business Hours</label>
        <p class="text-secondary small mb-2">Tick a day to mark it open and set its window. Unticked days are treated as closed.</p>
        <div class="table-responsive-sm">
            <table class="table table-sm table-borderless mb-0">
                <thead>
                    <tr class="text-secondary small">
                        <th style="width:40px;"></th>
                        <th>Day</th>
                        <th>Open</th>
                        <th>Close</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($day_names as $dow => $dname) {
                        $is_open = isset($day_windows[$dow]);
                        $open_val = $is_open ? $day_windows[$dow]['open'] : '09:00';
                        $close_val = $is_open ? $day_windows[$dow]['close'] : '17:00';
                    ?>
                        <tr>
                            <td class="align-middle text-center">
                                <input type="checkbox" name="day_open[<?php echo $dow; ?>]" value="1" <?php if ($is_open) { echo 'checked'; } ?>>
                            </td>
                            <td class="align-middle"><?php echo $dname; ?></td>
                            <td><input type="time" class="form-control form-control-sm" name="open_time[<?php echo $dow; ?>]" value="<?php echo nullable_htmlentities($open_val); ?>"></td>
                            <td><input type="time" class="form-control form-control-sm" name="close_time[<?php echo $dow; ?>]" value="<?php echo nullable_htmlentities($close_val); ?>"></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="save_sla_calendar" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<div class="modal-body border-top">
    <label class="text-bold">Holidays</label>
    <?php if (empty($holidays)) { ?>
        <p class="text-secondary small">No holidays configured. The calendar is treated as open on all business days.</p>
    <?php } else { ?>
        <ul class="list-group mb-3">
            <?php foreach ($holidays as $h) {
                $hid = intval($h['holiday_id']);
                $hdate = nullable_htmlentities($h['holiday_date']);
                $hname = nullable_htmlentities($h['holiday_name']);
            ?>
                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                    <span><i class="fas fa-fw fa-calendar-day me-2 text-secondary"></i><?php echo $hdate; ?> <?php if ($hname !== '') { echo "&mdash; $hname"; } ?></span>
                    <a class="text-danger confirm-link" href="post.php?delete_sla_holiday=<?php echo $hid; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>"><i class="fas fa-trash"></i></a>
                </li>
            <?php } ?>
        </ul>
    <?php } ?>

    <form action="post.php" method="post" autocomplete="off" class="form-row align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="calendar_id" value="<?php echo $calendar_id; ?>">
        <div class="form-group col-sm-4 mb-2">
            <label class="small">Date</label>
            <input type="date" class="form-control form-control-sm" name="holiday_date" required>
        </div>
        <div class="form-group col-sm-5 mb-2">
            <label class="small">Name</label>
            <input type="text" class="form-control form-control-sm" name="holiday_name" placeholder="e.g. Christmas Day" maxlength="150">
        </div>
        <div class="form-group col-sm-3 mb-2">
            <button type="submit" name="add_sla_holiday" class="btn btn-secondary btn-sm btn-block"><i class="fas fa-plus me-1"></i>Add</button>
        </div>
    </form>
</div>

<?php
require_once '../../../includes/modal_footer.php';
