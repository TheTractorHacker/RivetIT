<?php

if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_url = '';
}

enforceUserPermission('module_support');

$worksheet_id = intval($_GET['worksheet_id']);
$ticket_id = intval($_GET['ticket_id'] ?? 0);

$sql = mysqli_query($mysqli, "SELECT tw.*, wt.worksheet_template_name, wt.worksheet_template_description, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_client_id
    FROM ticket_worksheets tw
    JOIN worksheet_templates wt ON tw.worksheet_template_id = wt.worksheet_template_id
    JOIN tickets t ON tw.worksheet_ticket_id = t.ticket_id
    WHERE tw.worksheet_id = $worksheet_id LIMIT 1");

if (mysqli_num_rows($sql) == 0) {
    echo "<div class='alert alert-danger m-3'>Worksheet not found.</div>";
    require_once "../includes/footer.php";
    exit;
}

$row = mysqli_fetch_assoc($sql);
$tmpl_name = nullable_htmlentities($row['worksheet_template_name']);
$ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
$ticket_number = intval($row['ticket_number']);
$ticket_subject = nullable_htmlentities($row['ticket_subject']);
$ticket_id = intval($row['worksheet_ticket_id']);
$client_id = intval($row['ticket_client_id']);

// Confirm the requesting agent has access to the client that actually owns
// this worksheet's ticket, regardless of any client_id passed in the URL.
if ($client_id) {
    enforceClientAccess($client_id);
}

$is_outtake = intval($row['worksheet_is_outtake']);
$completed_at = $row['worksheet_completed_at'];
$signed_name = nullable_htmlentities($row['worksheet_signed_name']);
$signed_at = $row['worksheet_signed_at'];
$sign_token = nullable_htmlentities($row['worksheet_sign_token']);
$signature_data = $row['worksheet_signature'];

$fields = mysqli_query($mysqli, "SELECT f.*, COALESCE(r.response_value, '') AS response_value
    FROM worksheet_template_fields f
    LEFT JOIN ticket_worksheet_responses r ON r.response_field_id = f.field_id AND r.response_worksheet_id = $worksheet_id
    WHERE f.field_template_id = (SELECT worksheet_template_id FROM ticket_worksheets WHERE worksheet_id = $worksheet_id)
    ORDER BY f.field_order");

$is_completed = !empty($completed_at);

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2">
            <i class="fas fa-fw fa-clipboard-list me-2"></i><?= $tmpl_name ?>
            <small class="ms-2 text-secondary">Ticket: <a href="ticket.php?ticket_id=<?= $ticket_id ?><?= $client_id ? "&client_id=$client_id" : '' ?>"><?= "$ticket_prefix$ticket_number — $ticket_subject" ?></a></small>
        </h3>
        <div class="card-tools">
            <?php if ($is_outtake && $sign_token && !$signed_at) { ?>
            <a href="<?= "https://$config_base_url/guest/worksheet_sign.php?token=$sign_token" ?>" target="_blank" class="btn btn-warning btn-sm me-2">
                <i class="fas fa-external-link-alt me-1"></i>Customer Sign Link
            </a>
            <?php } ?>
            <a href="ticket.php?ticket_id=<?= $ticket_id ?><?= $client_id ? "&client_id=$client_id" : '' ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Back to Ticket
            </a>
        </div>
    </div>
    <div class="card-body">

        <?php if ($signed_at) { ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>Signed by <strong><?= $signed_name ?></strong> on <?= date('M j, Y g:i A', strtotime($signed_at)) ?>
            <?php if ($signature_data) { ?>
            <br><img src="<?= htmlspecialchars($signature_data, ENT_QUOTES) ?>" style="max-height:80px; background:#fff; border:1px solid #ccc; border-radius:4px; display:block; margin-top:8px;">
            <?php } ?>
        </div>
        <?php } ?>

        <?php if ($is_completed && !$is_outtake) { ?>
        <div class="alert alert-info"><i class="fas fa-lock me-2"></i>This worksheet was completed on <?= date('M j, Y', strtotime($completed_at)) ?>.</div>
        <?php } ?>

        <form action="post.php" method="post" autocomplete="off" id="worksheetForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="worksheet_id" value="<?= $worksheet_id ?>">
            <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
            <input type="hidden" name="client_id" value="<?= $client_id ?>">

            <?php while ($frow = mysqli_fetch_assoc($fields)) {
                $fid = intval($frow['field_id']);
                $fname = nullable_htmlentities($frow['field_name']);
                $ftype = $frow['field_type'];
                $fopts = $frow['field_options'];
                $freq = intval($frow['field_required']);
                $fval = nullable_htmlentities($frow['response_value']);
                $disabled = ($is_completed && !$is_outtake) ? 'disabled' : '';
            ?>

            <?php if ($ftype === 'heading') { ?>
                <h5 class="mt-4 border-bottom pb-2"><?= $fname ?></h5>
            <?php } elseif ($ftype === 'checkbox') { ?>
                <div class="form-group">
                    <div class="form-check form-check">
                        <input type="checkbox" class="form-check-input" id="field_<?= $fid ?>" name="field_<?= $fid ?>" value="1" <?= $fval == '1' ? 'checked' : '' ?> <?= $disabled ?>>
                        <label class="form-check-label" for="field_<?= $fid ?>"><?= $fname ?> <?= $freq ? '<strong class="text-danger">*</strong>' : '' ?></label>
                    </div>
                </div>
            <?php } elseif ($ftype === 'textarea') { ?>
                <div class="form-group">
                    <label><?= $fname ?> <?= $freq ? '<strong class="text-danger">*</strong>' : '' ?></label>
                    <textarea class="form-control" name="field_<?= $fid ?>" rows="3" <?= $freq ? 'required' : '' ?> <?= $disabled ?>><?= $fval ?></textarea>
                </div>
            <?php } elseif ($ftype === 'select') { ?>
                <div class="form-group">
                    <label><?= $fname ?> <?= $freq ? '<strong class="text-danger">*</strong>' : '' ?></label>
                    <select class="form-control select2" name="field_<?= $fid ?>" <?= $freq ? 'required' : '' ?> <?= $disabled ?>>
                        <option value="">- Select -</option>
                        <?php foreach (array_filter(explode("\n", $fopts)) as $opt) {
                            $opt = trim($opt);
                            echo "<option value=\"" . htmlspecialchars($opt) . "\"" . ($fval === $opt ? ' selected' : '') . ">" . htmlspecialchars($opt) . "</option>";
                        } ?>
                    </select>
                </div>
            <?php } elseif ($ftype === 'signature') { ?>
                <div class="form-group">
                    <label><?= $fname ?> <?= $freq ? '<strong class="text-danger">*</strong>' : '' ?></label>
                    <?php if ($fval) { ?>
                        <div><img src="<?= $fval ?>" style="max-height:100px; border:1px solid #ccc; border-radius:4px; background:#fff;"></div>
                        <?php if (!$disabled) { ?><button type="button" class="btn btn-sm btn-outline-secondary mt-1 js-clear-worksheet-sig" data-field-id="<?= $fid ?>">Clear & Re-sign</button><?php } ?>
                        <input type="hidden" name="field_<?= $fid ?>" id="sig_data_<?= $fid ?>" value="<?= htmlspecialchars($fval) ?>">
                    <?php } else { ?>
                        <canvas id="sig_canvas_<?= $fid ?>" width="500" height="150" style="border:1px solid #ccc; border-radius:4px; background:#fff; touch-action:none; display:block;"></canvas>
                        <input type="hidden" name="field_<?= $fid ?>" id="sig_data_<?= $fid ?>">
                        <div class="mt-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary js-clear-worksheet-canvas" data-field-id="<?= $fid ?>">Clear</button>
                        </div>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <div class="form-group">
                    <label><?= $fname ?> <?= $freq ? '<strong class="text-danger">*</strong>' : '' ?></label>
                    <input type="text" class="form-control" name="field_<?= $fid ?>" value="<?= $fval ?>" <?= $freq ? 'required' : '' ?> <?= $disabled ?>>
                </div>
            <?php } ?>

            <?php } ?>

            <?php if (!$is_completed || $is_outtake) { ?>
            <div class="mt-4">
                <button type="submit" name="save_worksheet" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Worksheet</button>
                <button type="submit" name="complete_worksheet" class="btn btn-success ms-2"><i class="fas fa-check me-2"></i>Save & Mark Complete</button>
            </div>
            <?php } ?>

        </form>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
var sigPads = {};

function initSig(fieldId) {
    var canvas = document.getElementById('sig_canvas_' + fieldId);
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var lastX, lastY;

    function getPos(e) {
        var rect = canvas.getBoundingClientRect();
        var src = e.touches ? e.touches[0] : e;
        return { x: src.clientX - rect.left, y: src.clientY - rect.top };
    }

    canvas.addEventListener('mousedown', function(e) { drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; });
    canvas.addEventListener('mousemove', function(e) {
        if (!drawing) return;
        var p = getPos(e);
        ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y);
        ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke();
        lastX = p.x; lastY = p.y;
    });
    canvas.addEventListener('mouseup', function() { drawing = false; saveSig(fieldId); });
    canvas.addEventListener('mouseleave', function() { drawing = false; });
    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); drawing = true; var p = getPos(e); lastX = p.x; lastY = p.y; });
    canvas.addEventListener('touchmove', function(e) {
        e.preventDefault(); if (!drawing) return;
        var p = getPos(e);
        ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p.x, p.y);
        ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke();
        lastX = p.x; lastY = p.y;
    });
    canvas.addEventListener('touchend', function() { drawing = false; saveSig(fieldId); });
    sigPads[fieldId] = { canvas: canvas, ctx: ctx };
}

function saveSig(fieldId) {
    var canvas = document.getElementById('sig_canvas_' + fieldId);
    if (canvas) document.getElementById('sig_data_' + fieldId).value = canvas.toDataURL();
}

function clearCanvas(fieldId) {
    var pad = sigPads[fieldId];
    if (pad) { pad.ctx.clearRect(0, 0, pad.canvas.width, pad.canvas.height); document.getElementById('sig_data_' + fieldId).value = ''; }
}

function clearSig(fieldId) {
    document.getElementById('sig_data_' + fieldId).value = '';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('canvas[id^="sig_canvas_"]').forEach(function(c) {
        var id = c.id.replace('sig_canvas_', '');
        initSig(id);
    });
});
document.addEventListener('click', function (e) {
    var el;
    if ((el = e.target.closest('.js-clear-worksheet-sig'))) { clearSig(el.dataset.fieldId); return; }
    if ((el = e.target.closest('.js-clear-worksheet-canvas'))) { clearCanvas(el.dataset.fieldId); }
});
</script>

<?php require_once "../includes/footer.php"; ?>
