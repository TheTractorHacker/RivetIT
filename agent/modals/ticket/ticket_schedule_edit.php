<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$schedule_id = intval($_GET['schedule_id']);

$sql = mysqli_query($mysqli, "SELECT ts.*, t.ticket_prefix, t.ticket_number, t.ticket_assigned_to, t.ticket_client_id
    FROM ticket_schedules ts
    LEFT JOIN tickets t ON t.ticket_id = ts.schedule_ticket_id
    WHERE ts.schedule_id = $schedule_id AND ts.schedule_archived_at IS NULL
    LIMIT 1");
$row = mysqli_fetch_assoc($sql);

if (!$row) {
    echo '<div class="modal-body text-danger">Schedule entry not found.</div>';
    require_once '../../../includes/modal_footer.php';
    exit;
}

$client_id = intval($row['ticket_client_id']);
if ($client_id) enforceClientAccess();

$ticket_id      = intval($row['schedule_ticket_id']);
$ticket_prefix  = nullable_htmlentities($row['ticket_prefix']);
$ticket_number  = intval($row['ticket_number']);
$onsite         = intval($row['schedule_onsite']);
$tech_id        = intval($row['schedule_tech_id']);
$notes          = nullable_htmlentities($row['schedule_notes'] ?? '');
$start_val      = $row['schedule_start'] ? date('Y-m-d\TH:i', strtotime($row['schedule_start'])) : '';
$end_val        = $row['schedule_end']   ? date('Y-m-d\TH:i', strtotime($row['schedule_end']))   : '';

$existing_duration = 60;
if ($row['schedule_start'] && $row['schedule_end']) {
    $existing_duration = (strtotime($row['schedule_end']) - strtotime($row['schedule_start'])) / 60;
}

$sql_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_role_id > 1 AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");

$durations = [30 => '30 min', 60 => '1 hr', 90 => '1.5 hr', 120 => '2 hr', 180 => '3 hr', 240 => '4 hr', 480 => '8 hr (All day)'];
$is_custom = $end_val && !in_array((int)$existing_duration, array_keys($durations));

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fa fa-fw fa-calendar-check me-2"></i>Edit Schedule: <strong><?= $ticket_prefix . $ticket_number ?></strong>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
    <input type="hidden" name="ticket_id"   value="<?= $ticket_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label class="d-block">Appointment Type</label>
            <div class="btn-group btn-group-sm w-100" role="group">
                <button type="button" class="btn <?= !$onsite ? 'btn-primary' : 'btn-outline-primary' ?> onsite-opt" data-val="0">
                    <i class="fas fa-laptop me-1"></i>Remote
                </button>
                <button type="button" class="btn <?= $onsite ? 'btn-primary' : 'btn-outline-primary' ?> onsite-opt" data-val="1">
                    <i class="fas fa-map-marker-alt me-1"></i>Onsite
                </button>
            </div>
            <input type="hidden" name="schedule_onsite" id="schedule_onsite" value="<?= $onsite ?>">
        </div>

        <div class="form-row">
            <div class="col-sm-7">
                <div class="form-group">
                    <label>Start <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-calendar-day"></i></span></div>
                        <input type="datetime-local" class="form-control" name="schedule_start" id="sched_start" value="<?= $start_val ?>" required>
                    </div>
                </div>
            </div>
            <div class="col-sm-5">
                <div class="form-group">
                    <label>Duration</label>
                    <select class="form-control" id="sched_duration">
                        <?php foreach ($durations as $mins => $label) {
                            $sel = (!$is_custom && abs($existing_duration - $mins) <= 5) ? 'selected' : '';
                            echo "<option value=\"$mins\" $sel>$label</option>";
                        } ?>
                        <option value="custom" <?= $is_custom ? 'selected' : '' ?>>Custom end time</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-group" id="custom_end_group" style="<?= $is_custom ? '' : 'display:none;' ?>">
            <label>End Time</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-calendar-check"></i></span></div>
                <input type="datetime-local" class="form-control" name="schedule_end_custom" id="sched_end_custom" value="<?= $is_custom ? $end_val : '' ?>">
            </div>
        </div>
        <input type="hidden" name="schedule_end" id="sched_end_calc" value="<?= !$is_custom ? $end_val : '' ?>">

        <div class="form-group">
            <label>Technician <small class="text-muted">(optional)</small></label>
            <select class="form-control" name="schedule_tech_id">
                <option value="0">— Unassigned —</option>
                <?php while ($u = mysqli_fetch_assoc($sql_users)) {
                    $uid   = intval($u['user_id']);
                    $uname = nullable_htmlentities($u['user_name']);
                    $sel   = ($uid === $tech_id) ? ' selected' : '';
                    echo "<option value=\"$uid\"$sel>$uname</option>";
                } ?>
            </select>
        </div>

        <div class="form-group mb-0">
            <label>Notes <small class="text-muted">(internal)</small></label>
            <textarea class="form-control" name="schedule_notes" rows="2" placeholder="Access codes, parts to bring..."><?= $notes ?></textarea>
        </div>

        <div id="appt_preview" class="alert alert-info py-2 mt-3 mb-0" style="font-size:.875rem;<?= $start_val ? '' : 'display:none;' ?>">
            <i class="fa fa-clock me-1"></i><span id="appt_preview_text"></span>
        </div>

    </div>

    <div class="modal-footer">
        <a href="post.php?delete_ticket_schedule=<?= $schedule_id ?>&ticket_id=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
           class="btn btn-outline-danger mr-auto confirm-link">
            <i class="fa fa-trash me-1"></i>Remove
        </a>
        <button type="submit" name="edit_ticket_schedule_entry" class="btn btn-primary"><i class="fa fa-check me-1"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-1"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
$(function () {
    var useCustomEnd = <?= $is_custom ? 'true' : 'false' ?>;

    function pad(n) { return ('0' + n).slice(-2); }

    function addMins(dtStr, mins) {
        var d = new Date(dtStr);
        d.setMinutes(d.getMinutes() + mins);
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function minsLabel(m) {
        if (m < 60) return m + ' min';
        var h = m / 60;
        return (h === Math.floor(h) ? h : h.toFixed(1)) + ' hr' + (h !== 1 ? 's' : '');
    }

    function calcEnd() {
        if (useCustomEnd) return;
        var dur   = parseInt($('#sched_duration').val()) || 0;
        var start = $('#sched_start').val();
        if (!start || !dur) { $('#sched_end_calc').val(''); return; }
        $('#sched_end_calc').val(addMins(start, dur));
        updatePreview();
    }

    function updatePreview() {
        var start = $('#sched_start').val();
        if (!start) { $('#appt_preview').hide(); return; }
        var endVal = useCustomEnd ? $('#sched_end_custom').val() : $('#sched_end_calc').val();
        var fmt = {weekday:'short', month:'short', day:'numeric', hour:'numeric', minute:'2-digit'};
        var txt = new Date(start).toLocaleString('en-US', fmt);
        if (endVal) {
            var endD = new Date(endVal);
            txt += ' – ' + endD.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
            var durMins = Math.round((endD - new Date(start)) / 60000);
            if (durMins > 0) txt += ' (' + minsLabel(durMins) + ')';
        }
        var type = $('#schedule_onsite').val() == '1' ? ' &nbsp;·&nbsp; <strong>Onsite</strong>' : ' &nbsp;·&nbsp; Remote';
        $('#appt_preview_text').html(txt + type);
        $('#appt_preview').show();
    }

    $('#sched_duration').on('change', function () {
        useCustomEnd = $(this).val() === 'custom';
        $('#custom_end_group').toggle(useCustomEnd);
        if (useCustomEnd) {
            $('#sched_end_calc').val('');
        } else {
            calcEnd();
        }
    });

    $('#sched_start').on('change input', function () { calcEnd(); updatePreview(); });
    $('#sched_end_custom').on('change input', function () { $('#sched_end_calc').val($(this).val()); updatePreview(); });

    $(document).on('click', '.onsite-opt', function () {
        $('.onsite-opt').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        $('#schedule_onsite').val($(this).data('val'));
        updatePreview();
    });

    $('form').on('submit', function () {
        if (useCustomEnd) {
            $('#sched_end_calc').val($('#sched_end_custom').val());
        }
    });

    // Initial preview render
    updatePreview();
    if (!useCustomEnd && $('#sched_end_calc').val() === '' && $('#sched_start').val()) {
        calcEnd();
    }
});
</script>

<?php
require_once '../../../includes/modal_footer.php';
