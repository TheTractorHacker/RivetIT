<?php
require_once '../../../includes/modal_header.php';
require_once '../../../includes/holiday_functions.php';

$calendar_id = intval($_GET['id']);

// Company's configured country (Admin > Settings > Company) sorts the
// catalog picker below so the relevant country's holidays are at the top.
$company_country = sanitizeInput(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_country FROM companies WHERE company_id = 1"))['company_country'] ?? '');

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

// Existing holidays already on this calendar
$holidays = [];
$hres = mysqli_query($mysqli, "SELECT holiday_id, holiday_date, holiday_name FROM sla_holidays WHERE calendar_id = $calendar_id ORDER BY holiday_date");
while ($hr = mysqli_fetch_assoc($hres)) { $holidays[] = $hr; }
$used_dates = array_column($holidays, 'holiday_date');

// Catalog entries (Admin > Ticketing > Holidays) not already added to this
// calendar - the company's own country sorts first, everything else follows
// by date, so the relevant options are right at the top of the picker.
$catalog_holidays = [];
$company_country_esc = mysqli_real_escape_string($mysqli, $company_country);
$catres = mysqli_query($mysqli, "SELECT holiday_id, holiday_date, holiday_name, holiday_country FROM holidays ORDER BY (holiday_country = '$company_country_esc') DESC, holiday_date ASC");
while ($catr = mysqli_fetch_assoc($catres)) {
    if (in_array($catr['holiday_date'], $used_dates, true)) { continue; }
    $catalog_holidays[] = $catr;
}

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

    <p class="small text-secondary mb-2">
        <i class="fas fa-fw fa-flag me-1"></i>Company country: <strong><?php echo $company_country !== '' ? nullable_htmlentities($company_country) : 'not set'; ?></strong>
        &nbsp;&mdash;&nbsp;<a href="../holidays.php" target="_blank">Manage the holiday catalog <i class="fas fa-external-link-alt fa-xs"></i></a>
    </p>

    <form action="post.php" method="post" autocomplete="off" class="form-row align-items-end" id="addSlaHolidayForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="calendar_id" value="<?php echo $calendar_id; ?>">

        <div class="form-group col-sm-8 mb-2">
            <label class="small">Holiday</label>
            <select class="form-control form-control-sm" name="catalog_holiday_id" id="catalogHolidaySelect">
                <?php if (empty($catalog_holidays)) { ?>
                    <option value="0">No catalog holidays available &mdash; enter a custom one</option>
                <?php } else { ?>
                    <?php foreach ($catalog_holidays as $ch) { ?>
                        <option value="<?php echo intval($ch['holiday_id']); ?>">
                            <?php echo date('M j, Y', strtotime($ch['holiday_date'])); ?> &mdash; <?php echo nullable_htmlentities($ch['holiday_name']); ?> (<?php echo nullable_htmlentities($ch['holiday_country']); ?>)
                        </option>
                    <?php } ?>
                    <option value="0">+ Enter a custom holiday&hellip;</option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group col-sm-4 mb-2">
            <button type="submit" name="add_sla_holiday" class="btn btn-secondary btn-sm btn-block"><i class="fas fa-plus me-1"></i>Add</button>
        </div>

        <div class="form-group col-sm-4 mb-2" id="customHolidayDateWrap" hidden>
            <label class="small">Date</label>
            <input type="date" class="form-control form-control-sm" name="holiday_date" id="customHolidayDate">
        </div>
        <div class="form-group col-sm-8 mb-2" id="customHolidayNameWrap" hidden>
            <label class="small">Name</label>
            <input type="text" class="form-control form-control-sm" name="holiday_name" id="customHolidayName" placeholder="e.g. Company Shutdown Day" maxlength="150">
        </div>
    </form>

    <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    (function () {
        var select = document.getElementById('catalogHolidaySelect');
        var dateWrap = document.getElementById('customHolidayDateWrap');
        var nameWrap = document.getElementById('customHolidayNameWrap');
        var dateInput = document.getElementById('customHolidayDate');
        function syncCustomFields() {
            var isCustom = select.value === '0';
            dateWrap.hidden = !isCustom;
            nameWrap.hidden = !isCustom;
            if (dateInput) { dateInput.required = isCustom; }
        }
        if (select) {
            select.addEventListener('change', syncCustomFields);
            syncCustomFields();
        }
    })();
    </script>
</div>

<?php
require_once '../../../includes/modal_footer.php';
