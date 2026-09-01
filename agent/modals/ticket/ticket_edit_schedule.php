<?php

require_once '../../../includes/modal_header.php';

$ticket_id = intval($_GET['ticket_id']);

$sql = mysqli_query($mysqli, "SELECT * FROM tickets
    LEFT JOIN clients ON client_id = ticket_client_id
    LEFT JOIN contacts ON ticket_contact_id = contact_id
    LEFT JOIN locations ON contact_location_id = location_id
    LEFT JOIN users ON ticket_assigned_to = user_id
    WHERE ticket_id = $ticket_id
    LIMIT 1"
);

$row = mysqli_fetch_assoc($sql);
$ticket_prefix    = nullable_htmlentities($row['ticket_prefix']);
$ticket_number    = intval($row['ticket_number']);
$ticket_subject   = nullable_htmlentities($row['ticket_subject']);
$ticket_scheduled = nullable_htmlentities($row['ticket_schedule']);
$ticket_sched_end = nullable_htmlentities($row['ticket_schedule_end']);
$ticket_appt_notes= nullable_htmlentities($row['ticket_appointment_notes'] ?? '');
$ticket_onsite    = intval($row['ticket_onsite']);
$assigned_name    = nullable_htmlentities($row['user_name'] ?? '');
$location_address = nullable_htmlentities($row['location_address'] ?? '');

$sched_val     = $ticket_scheduled ? date('Y-m-d\TH:i', strtotime($ticket_scheduled)) : '';
$sched_end_val = $ticket_sched_end ? date('Y-m-d\TH:i', strtotime($ticket_sched_end)) : '';

$existing_duration = 60;
if ($ticket_scheduled && $ticket_sched_end) {
    $existing_duration = (strtotime($ticket_sched_end) - strtotime($ticket_scheduled)) / 60;
}

$durations = [30 => '30 min', 60 => '1 hr', 90 => '1.5 hr', 120 => '2 hr', 180 => '3 hr', 240 => '4 hr', 480 => '8 hr (All day)'];

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fa fa-fw fa-calendar-check me-2"></i>Schedule: <strong><?= $ticket_prefix . $ticket_number ?></strong>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

    <div class="modal-body">

        <!-- Type toggle -->
        <div class="form-group">
            <label class="d-block">Appointment Type</label>
            <div class="btn-group btn-group-sm w-100" role="group">
                <button type="button" class="btn <?= !$ticket_onsite ? 'btn-primary' : 'btn-outline-primary' ?> onsite-opt" data-val="0">
                    <i class="fas fa-laptop me-1"></i>Remote
                </button>
                <button type="button" class="btn <?= $ticket_onsite ? 'btn-primary' : 'btn-outline-primary' ?> onsite-opt" data-val="1">
                    <i class="fas fa-map-marker-alt me-1"></i>Onsite
                </button>
            </div>
            <input type="hidden" name="onsite" id="onsite_value" value="<?= $ticket_onsite ?>">
        </div>

        <!-- Start + Duration -->
        <div class="form-row">
            <div class="col-sm-7">
                <div class="form-group">
                    <label>Start <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-calendar-day"></i></span></div>
                        <input type="datetime-local" class="form-control" name="scheduled_date_time" id="sched_start"
                               value="<?= $sched_val ?>" required>
                    </div>
                </div>
            </div>
            <div class="col-sm-5">
                <div class="form-group">
                    <label>Duration</label>
                    <select class="form-control" id="sched_duration">
                        <?php foreach ($durations as $mins => $label) {
                            $sel = (abs($existing_duration - $mins) <= 5) ? 'selected' : '';
                            echo "<option value=\"$mins\" $sel>$label</option>";
                        } ?>
                        <option value="custom" <?= (!in_array($existing_duration, array_keys($durations)) && $sched_end_val) ? 'selected' : '' ?>>Custom end time</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Custom end time (hidden unless custom selected) -->
        <div class="form-group" id="custom_end_group" style="display:none;">
            <label>End Time</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-calendar-check"></i></span></div>
                <input type="datetime-local" class="form-control" name="scheduled_end_time" id="sched_end"
                       value="<?= $sched_end_val ?>">
            </div>
        </div>
        <input type="hidden" name="scheduled_end_calculated" id="sched_end_calc" value="">

        <?php if ($assigned_name) { ?>
        <div class="form-group">
            <label>Technician</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-user-cog"></i></span></div>
                <input type="text" class="form-control bg-light" value="<?= $assigned_name ?>" readonly>
            </div>
        </div>
        <?php } ?>

        <?php if ($location_address) { ?>
        <div class="form-group">
            <label>Location</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-map-pin"></i></span></div>
                <input type="text" class="form-control bg-light" value="<?= $location_address ?>" readonly>
            </div>
        </div>
        <?php } ?>

        <div class="form-group">
            <label>Appointment Notes <small class="text-muted">(internal)</small></label>
            <textarea class="form-control" name="appointment_notes" rows="2"
                      placeholder="Access codes, parts to bring, contact instructions..."><?= $ticket_appt_notes ?></textarea>
        </div>

        <!-- Live preview -->
        <div id="appt_preview" class="alert alert-info py-2 mb-0" style="font-size:.875rem;display:none;">
            <i class="fa fa-clock me-1"></i><span id="appt_preview_text"></span>
        </div>

    </div>

    <div class="modal-footer">
        <?php if ($ticket_scheduled) { ?>
        <a href="post.php?cancel_ticket_schedule=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
           class="btn btn-outline-danger mr-auto confirm-link">
            <i class="fa fa-trash me-1"></i>Remove
        </a>
        <?php } ?>
        <button type="submit" name="edit_ticket_schedule" class="btn btn-primary"><i class="fa fa-check me-1"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-1"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
$(function () {
    var useCustomEnd = $('#sched_duration').val() === 'custom';

    function pad(n) { return ('0' + n).slice(-2); }

    function addMins(dtLocalStr, mins) {
        var d = new Date(dtLocalStr);
        d.setMinutes(d.getMinutes() + mins);
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function minsLabel(m) {
        if (m < 60) return m + ' min';
        var h = m / 60;
        return (h === Math.floor(h) ? h : h.toFixed(1)) + ' hr' + (h !== 1 ? 's' : '');
    }

    function calcEnd() {
        var dur = parseInt($('#sched_duration').val()) || 0;
        var start = $('#sched_start').val();
        if (!start || !dur || useCustomEnd) return;
        var endStr = addMins(start, dur);
        $('#sched_end_calc').val(endStr);
        updatePreview();
    }

    function updatePreview() {
        var start = $('#sched_start').val();
        if (!start) { $('#appt_preview').hide(); return; }
        var endVal = useCustomEnd ? $('#sched_end').val() : $('#sched_end_calc').val();
        var startD = new Date(start);
        var fmt = {weekday:'short', month:'short', day:'numeric', hour:'numeric', minute:'2-digit'};
        var txt = startD.toLocaleString('en-US', fmt);
        if (endVal) {
            var endD = new Date(endVal);
            txt += ' \u2013 ' + endD.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
            var durMins = Math.round((endD - startD) / 60000);
            if (durMins > 0) txt += ' (' + minsLabel(durMins) + ')';
        }
        var typeTxt = $('#onsite_value').val() == '1' ? ' &nbsp;·&nbsp; <strong>Onsite</strong>' : ' &nbsp;·&nbsp; Remote';
        $('#appt_preview_text').html(txt + typeTxt);
        $('#appt_preview').show();
    }

    $('#sched_duration').on('change', function () {
        useCustomEnd = $(this).val() === 'custom';
        $('#custom_end_group').toggle(useCustomEnd);
        calcEnd();
    });

    $('#sched_start').on('change input', function () { calcEnd(); updatePreview(); });
    $('#sched_end').on('change input', function () { $('#sched_end_calc').val($(this).val()); updatePreview(); });

    $(document).on('click', '.onsite-opt', function () {
        $('.onsite-opt').removeClass('btn-primary').addClass('btn-outline-primary');
        $(this).removeClass('btn-outline-primary').addClass('btn-primary');
        $('#onsite_value').val($(this).data('val'));
        updatePreview();
    });

    $('form').on('submit', function () {
        if (useCustomEnd) {
            // scheduled_end_time field already has value
        } else {
            // Put calc'd end into the custom end field so it submits
            $('<input>').attr({type:'hidden', name:'scheduled_end_time', value: $('#sched_end_calc').val()}).appendTo($(this));
        }
    });

    // Show custom group if custom was pre-selected
    if (useCustomEnd) {
        $('#custom_end_group').show();
        $('#sched_end').val('<?= $sched_end_val ?>');
    }

    calcEnd();
    updatePreview();
});
</script>

<?php
require_once '../../../includes/modal_footer.php';
