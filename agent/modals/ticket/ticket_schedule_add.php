<?php

require_once '../../../includes/modal_header.php';

$ticket_id = intval($_GET['ticket_id']);

$sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$ticket_prefix  = nullable_htmlentities($row['ticket_prefix']);
$ticket_number  = intval($row['ticket_number']);
$primary_tech   = intval($row['ticket_assigned_to']);

$sql_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_role_id > 1 AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");

$durations = [30 => '30 min', 60 => '1 hr', 90 => '1.5 hr', 120 => '2 hr', 180 => '3 hr', 240 => '4 hr', 480 => '8 hr (All day)'];

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fa fa-fw fa-calendar-plus me-2"></i>Add Schedule: <strong><?= $ticket_prefix . $ticket_number ?></strong>
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
                <button type="button" class="btn btn-primary onsite-opt" data-val="0">
                    <i class="fas fa-laptop me-1"></i>Remote
                </button>
                <button type="button" class="btn btn-outline-primary onsite-opt" data-val="1">
                    <i class="fas fa-map-marker-alt me-1"></i>Onsite
                </button>
            </div>
            <input type="hidden" name="schedule_onsite" id="schedule_onsite" value="0">
        </div>

        <!-- Start + Duration -->
        <div class="form-row">
            <div class="col-sm-7">
                <div class="form-group">
                    <label>Start <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-calendar-day"></i></span></div>
                        <input type="datetime-local" class="form-control" name="schedule_start" id="sched_start" required>
                    </div>
                </div>
            </div>
            <div class="col-sm-5">
                <div class="form-group">
                    <label>Duration</label>
                    <select class="form-control" id="sched_duration">
                        <?php foreach ($durations as $mins => $label) {
                            $sel = ($mins === 60) ? 'selected' : '';
                            echo "<option value=\"$mins\" $sel>$label</option>";
                        } ?>
                        <option value="custom">Custom end time</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Custom end time -->
        <div class="form-group" id="custom_end_group" style="display:none;">
            <label>End Time</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-calendar-check"></i></span></div>
                <input type="datetime-local" class="form-control" name="schedule_end_custom" id="sched_end_custom">
            </div>
        </div>
        <input type="hidden" name="schedule_end" id="sched_end_calc" value="">

        <!-- Technicians (multi-select) -->
        <div class="form-group">
            <label>Technicians <small class="text-muted">(optional – select one or more)</small></label>
            <div class="border rounded p-2" style="max-height:140px;overflow-y:auto;background:#fff;">
                <?php while ($u = mysqli_fetch_assoc($sql_users)) {
                    $uid   = intval($u['user_id']);
                    $uname = nullable_htmlentities($u['user_name']);
                    $chk   = ($uid === $primary_tech) ? ' checked' : '';
                    echo "<div class=\"form-check form-check\">"
                       . "<input type=\"checkbox\" class=\"form-check-input\" id=\"sched_tech_$uid\" name=\"schedule_tech_ids[]\" value=\"$uid\"$chk>"
                       . "<label class=\"form-check-label\" for=\"sched_tech_$uid\">$uname</label>"
                       . "</div>";
                } ?>
            </div>
        </div>

        <!-- Notes -->
        <div class="form-group mb-0">
            <label>Notes <small class="text-muted">(internal)</small></label>
            <textarea class="form-control" name="schedule_notes" rows="2" placeholder="Access codes, parts to bring, contact instructions..."></textarea>
        </div>

        <!-- Preview -->
        <div id="appt_preview" class="alert alert-info py-2 mt-3 mb-0" style="font-size:.875rem;display:none;">
            <i class="fa fa-clock me-1"></i><span id="appt_preview_text"></span>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="add_ticket_schedule" class="btn btn-primary"><i class="fa fa-check me-1"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-1"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
$(function () {
    var useCustomEnd = false;

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
});
</script>

<?php
require_once '../../../includes/modal_footer.php';
