<?php

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/load_global_settings.php";

session_start();
require_once "../includes/inc_set_timezone.php";

if (!isset($_GET['token'])) {
    http_response_code(404);
    die("<h2>Invalid link.</h2>");
}

$token = sanitizeInput($_GET['token']);

// Load company info
$sql_co = mysqli_query($mysqli, "SELECT * FROM companies WHERE company_id = 1 LIMIT 1");
$co = mysqli_fetch_assoc($sql_co);
$co_name    = nullable_htmlentities($co['company_name']);
$co_address = nullable_htmlentities($co['company_address']);
$co_city    = nullable_htmlentities($co['company_city']);
$co_state   = nullable_htmlentities($co['company_state']);
$co_zip     = nullable_htmlentities($co['company_zip']);
$co_phone   = nullable_htmlentities($co['company_phone']);
$co_email   = nullable_htmlentities($co['company_email']);
$co_logo    = nullable_htmlentities($co['company_logo']);

// Load worksheet + ticket
$sql = mysqli_query($mysqli, "SELECT tw.*, wt.worksheet_template_name, wt.worksheet_template_id,
    t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_details, t.ticket_created_at,
    c.client_name,
    co2.contact_name, co2.contact_email, co2.contact_phone
    FROM ticket_worksheets tw
    JOIN worksheet_templates wt ON tw.worksheet_template_id = wt.worksheet_template_id
    JOIN tickets t ON tw.worksheet_ticket_id = t.ticket_id
    LEFT JOIN clients c ON t.ticket_client_id = c.client_id
    LEFT JOIN contacts co2 ON t.ticket_contact_id = co2.contact_id
    WHERE tw.worksheet_sign_token = '$token' AND tw.worksheet_is_outtake = 1 LIMIT 1");

if (mysqli_num_rows($sql) !== 1) {
    die("<h2 style='font-family:sans-serif;padding:40px;'>This link is invalid or has already been completed.</h2>");
}

$row = mysqli_fetch_assoc($sql);
$worksheet_id   = intval($row['worksheet_id']);
$tmpl_id        = intval($row['worksheet_template_id']);
$tmpl_name      = nullable_htmlentities($row['worksheet_template_name']);
$ticket_num     = nullable_htmlentities($row['ticket_prefix']) . intval($row['ticket_number']);
$ticket_subject = nullable_htmlentities($row['ticket_subject']);
$ticket_details = nullable_htmlentities(strip_tags($row['ticket_details']));
$ticket_date    = date('m/d/Y', strtotime($row['ticket_created_at']));
$client_name    = nullable_htmlentities($row['client_name']);
$contact_name   = nullable_htmlentities($row['contact_name']);
$already_signed = !empty($row['worksheet_signed_at']);
$signed_name    = nullable_htmlentities($row['worksheet_signed_name']);
$signed_at      = $row['worksheet_signed_at'];
$existing_sig   = $row['worksheet_signature'];

$fields = mysqli_query($mysqli, "SELECT f.*, COALESCE(r.response_value,'') AS response_value
    FROM worksheet_template_fields f
    LEFT JOIN ticket_worksheet_responses r ON r.response_field_id = f.field_id AND r.response_worksheet_id = $worksheet_id
    WHERE f.field_template_id = $tmpl_id ORDER BY f.field_order");

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $tmpl_name ?> — <?= $client_name ?></title>
    <link rel="stylesheet" href="/plugins/bootstrap5/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/itflow_bs5_bridge.css">
    <link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css">
    <style>
        body { background: #f4f6f9; font-family: Arial, sans-serif; }
        .outtake-card { max-width: 780px; margin: 30px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.1); overflow: hidden; }
        .outtake-header { background: #1a1a2e; color: #fff; padding: 20px 24px; display: flex; justify-content: space-between; align-items: flex-start; }
        .outtake-header .co-info { font-size: 13px; opacity: .85; line-height: 1.6; }
        .outtake-header .ticket-info { text-align: right; font-size: 13px; opacity: .85; line-height: 1.6; }
        .outtake-header h4 { margin: 0 0 4px; font-size: 20px; font-weight: 700; }
        .section-title { background: #f0f0f0; padding: 8px 16px; font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: #444; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; }
        .field-row { display: flex; align-items: center; padding: 9px 16px; border-bottom: 1px solid #f0f0f0; }
        .field-row .field-label { flex: 1; font-size: 14px; color: #333; }
        .field-row .field-input { min-width: 200px; display: flex; justify-content: flex-end; align-items: center; }
        .field-row .field-input input[type="checkbox"] { width: 20px; height: 20px; margin: 0; }
        .disclaimer { padding: 16px; background: #fafafa; border-top: 1px solid #eee; font-size: 13px; color: #555; }
        .disclaimer ul { padding-left: 20px; margin-top: 8px; }
        .disclaimer li { margin-bottom: 6px; }
        .sig-section { padding: 20px 16px; }
        canvas { border: 1px solid #ccc; border-radius: 4px; background: #fff; touch-action: none; display: block; width: 100%; height: 130px; }
        .signed-box { background: #f0fff4; border: 1px solid #68d391; border-radius: 6px; padding: 16px; margin: 16px; }
        @media print { body { background: #fff; } .outtake-card { box-shadow: none; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="outtake-card">

    <!-- Header -->
    <div class="outtake-header">
        <div>
            <?php if ($co_logo) { ?><img src="<?= $co_logo ?>" style="max-height:50px;margin-bottom:8px;"><br><?php } ?>
            <h4><?= $co_name ?></h4>
            <div class="co-info">
                <?= $co_address ? $co_address . '<br>' : '' ?>
                <?= $co_city ? "$co_city, $co_state $co_zip<br>" : '' ?>
                <?= $co_phone ? $co_phone . '<br>' : '' ?>
                <?= $co_email ? $co_email : '' ?>
            </div>
        </div>
        <div class="ticket-info">
            <strong style="font-size:16px;"><?= $tmpl_name ?></strong><br>
            <br>
            <strong>Ticket #</strong> <?= $ticket_num ?><br>
            <strong>Date</strong> <?= $ticket_date ?><br>
            <strong>Client</strong> <?= $client_name ?><br>
            <?php if ($contact_name) { ?><strong>Contact</strong> <?= $contact_name ?><br><?php } ?>
            <strong>Subject</strong> <?= $ticket_subject ?>
        </div>
    </div>

    <?php if ($already_signed) { ?>
    <!-- Already Signed -->
    <div class="signed-box">
        <h5 class="text-success mb-2"><i class="fas fa-check-circle me-2"></i>Form Signed</h5>
        <p class="mb-1">Signed by <strong><?= $signed_name ?></strong> on <?= date('M j, Y g:i A', strtotime($signed_at)) ?></p>
        <?php if ($existing_sig) { ?>
        <img src="<?= htmlspecialchars($existing_sig, ENT_QUOTES, 'UTF-8') ?>" style="max-height:80px; border:1px solid #ccc; border-radius:4px; background:#fff; display:block; margin-top:8px;">
        <?php } ?>
    </div>
    <div class="text-center py-3 no-print">
        <button class="btn btn-outline-secondary btn-sm js-print-page"><i class="fas fa-print me-1"></i>Print / Save PDF</button>
    </div>

    <?php } else { ?>

    <!-- Ticket Problem -->
    <?php if ($ticket_details) { ?>
    <div class="section-title">Ticket Problem</div>
    <div class="px-3 py-2" style="font-size:14px; white-space:pre-wrap;"><?= nl2br($ticket_details) ?></div>
    <?php } ?>

    <!-- Worksheet Fields -->
    <?php if (mysqli_num_rows($fields) > 0) { ?>
    <form action="guest_post.php" method="post" id="signForm">
        <input type="hidden" name="worksheet_sign_token" value="<?= $token ?>">

        <div class="section-title">Work Details</div>

        <?php while ($frow = mysqli_fetch_assoc($fields)) {
            $fid  = intval($frow['field_id']);
            $fname = nullable_htmlentities($frow['field_name']);
            $ftype = $frow['field_type'];
            $fopts = $frow['field_options'];
            $freq  = intval($frow['field_required']);
            $fval  = nullable_htmlentities($frow['response_value']);
        ?>

        <?php if ($ftype === 'heading') { ?>
            <div style="background:#eef0f3;border-top:1px solid #ddd;border-bottom:1px solid #ddd;padding:7px 16px;margin:16px -16px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#555;"><?= $fname ?></div>
        <?php } elseif ($ftype === 'checkbox') { ?>
            <div class="field-row">
                <span class="field-label"><?= $fname ?></span>
                <span class="field-input"><input type="checkbox" name="field_<?= $fid ?>" value="1" <?= $fval == '1' ? 'checked' : '' ?>></span>
            </div>
        <?php } elseif ($ftype === 'textarea') { ?>
            <div class="field-row" style="flex-direction:column; align-items:flex-start;">
                <label class="mb-1" style="font-size:13px; color:#666;"><?= $fname ?> <?= $freq ? '<span style="color:red">*</span>' : '' ?></label>
                <textarea class="form-control form-control-sm w-100" name="field_<?= $fid ?>" rows="2" <?= $freq ? 'required' : '' ?>><?= $fval ?></textarea>
            </div>
        <?php } elseif ($ftype === 'select') { ?>
            <div class="field-row">
                <span class="field-label"><?= $fname ?> <?= $freq ? '<span style="color:red">*</span>' : '' ?></span>
                <span class="field-input">
                    <select class="form-control form-control-sm" name="field_<?= $fid ?>" <?= $freq ? 'required' : '' ?>>
                        <option value="">- Select -</option>
                        <?php foreach (array_filter(explode("\n", $fopts)) as $opt) {
                            $opt = trim($opt);
                            echo "<option" . ($fval === $opt ? ' selected' : '') . ">" . htmlspecialchars($opt) . "</option>";
                        } ?>
                    </select>
                </span>
            </div>
        <?php } elseif ($ftype === 'signature') { ?>
            <div class="sig-section">
                <label style="font-size:13px; color:#666; font-weight:600;"><?= $fname ?> <?= $freq ? '<span style="color:red">*</span>' : '' ?></label>
                <canvas id="sig_canvas_<?= $fid ?>"></canvas>
                <input type="hidden" name="field_<?= $fid ?>" id="sig_data_<?= $fid ?>">
                <button type="button" class="btn btn-sm btn-outline-secondary mt-1 js-clear-sig-canvas" data-sig-id="<?= $fid ?>">Clear</button>
            </div>
        <?php } else { ?>
            <div class="field-row">
                <span class="field-label"><?= $fname ?> <?= $freq ? '<span style="color:red">*</span>' : '' ?></span>
                <span class="field-input"><input type="text" class="form-control form-control-sm" name="field_<?= $fid ?>" value="<?= $fval ?>" <?= $freq ? 'required' : '' ?> style="max-width:220px;"></span>
            </div>
        <?php } ?>

        <?php } // end fields ?>

        <!-- Disclaimer -->
        <div class="disclaimer">
            <strong>Outtake Form Disclaimer</strong>
            <p class="mt-1 mb-1">By signing this outtake form, the undersigned acknowledges the following:</p>
            <ul>
                <li><strong>Receipt of Equipment:</strong> The undersigned confirms that any equipment associated with this ticket has been delivered and received in satisfactory condition.</li>
                <li><strong>Payment for Parts and Services:</strong> The undersigned agrees to pay for all parts and services rendered. Payment terms are as agreed upon in your service agreement or our standard payment policy.</li>
                <li><strong>Acknowledgement of Work:</strong> The undersigned acknowledges that the work performed, as described in this form, has been completed.</li>
            </ul>
            <p class="mt-2 mb-0">For full terms and conditions, please visit <a href="https://foleyit.com/ticket-terms" target="_blank" rel="noopener">foleyit.com/ticket-terms</a>.</p>
        </div>

        <!-- Signature -->
        <div class="sig-section" style="border-top:2px solid #eee;">
            <div class="form-group mb-2">
                <label style="font-weight:600;">Full Name <span style="color:red">*</span></label>
                <input type="text" class="form-control" name="signed_name" required placeholder="Type your full name to confirm signature">
            </div>
            <label style="font-weight:600;">Signature <span style="color:red">*</span></label>
            <canvas id="sig_canvas_main"></canvas>
            <input type="hidden" name="main_signature" id="sig_data_main">
            <div class="d-flex mt-2">
                <button type="button" class="btn btn-sm btn-outline-secondary me-2 js-clear-sig-canvas" data-sig-id="main">Clear</button>
                <small class="text-secondary align-self-center">Draw your signature above</small>
            </div>
            <div class="form-check mt-3 mb-3">
                <input class="form-check-input" type="checkbox" id="terms_agree" required>
                <label class="form-check-label" for="terms_agree" style="font-size:13px;">
                    I have read and agree to the <a href="https://foleyit.com/ticket-terms" target="_blank" rel="noopener">Terms &amp; Conditions</a>
                </label>
            </div>
            <div class="mt-2">
                <button type="submit" name="sign_worksheet" class="btn btn-success btn-block btn-lg">
                    <i class="fas fa-check-circle me-2"></i>Submit & Sign Outtake Form
                </button>
            </div>
        </div>
    </form>
    <?php } ?>

    <?php } // end not-signed ?>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
var sigPads = {};
function initSig(id) {
    var c = document.getElementById('sig_canvas_' + id);
    if (!c) return;
    c.width = c.offsetWidth; c.height = 130;
    var ctx = c.getContext('2d'), drawing = false, lx, ly;
    function pos(e) { var r = c.getBoundingClientRect(), s = e.touches ? e.touches[0] : e; return {x:(s.clientX-r.left)*(c.width/r.width), y:(s.clientY-r.top)*(c.height/r.height)}; }
    c.addEventListener('mousedown', function(e){drawing=true; var p=pos(e); lx=p.x; ly=p.y;});
    c.addEventListener('mousemove', function(e){if(!drawing)return; var p=pos(e); ctx.beginPath(); ctx.moveTo(lx,ly); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#1a1a2e'; ctx.lineWidth=2.5; ctx.lineCap='round'; ctx.stroke(); lx=p.x; ly=p.y;});
    c.addEventListener('mouseup', function(){drawing=false; save(id);});
    c.addEventListener('mouseleave', function(){drawing=false;});
    c.addEventListener('touchstart', function(e){e.preventDefault(); drawing=true; var p=pos(e); lx=p.x; ly=p.y;}, {passive:false});
    c.addEventListener('touchmove', function(e){e.preventDefault(); if(!drawing)return; var p=pos(e); ctx.beginPath(); ctx.moveTo(lx,ly); ctx.lineTo(p.x,p.y); ctx.strokeStyle='#1a1a2e'; ctx.lineWidth=2.5; ctx.lineCap='round'; ctx.stroke(); lx=p.x; ly=p.y;}, {passive:false});
    c.addEventListener('touchend', function(){drawing=false; save(id);});
    sigPads[id] = c;
}
function save(id) { var c=sigPads[id]; if(c) document.getElementById('sig_data_'+id).value=c.toDataURL(); }
function clearCanvas(id) { var c=sigPads[id]; if(c){c.getContext('2d').clearRect(0,0,c.width,c.height); document.getElementById('sig_data_'+id).value='';} }
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-clear-sig-canvas');
    if (btn) { clearCanvas(btn.dataset.sigId); }
});
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('canvas[id^="sig_canvas_"]').forEach(function(c){
        initSig(c.id.replace('sig_canvas_',''));
    });
    // Require main signature before submit
    document.getElementById('signForm') && document.getElementById('signForm').addEventListener('submit', function(e){
        var mainSig = document.getElementById('sig_data_main').value;
        if (!mainSig) { e.preventDefault(); alert('Please draw your signature before submitting.'); }
    });
});
</script>
</body>
</html>
