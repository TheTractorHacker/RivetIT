<?php

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/load_global_settings.php";
require_once "../plugins/htmlpurifier/HTMLPurifier.standalone.php";

session_start();
require_once "../includes/inc_set_timezone.php";

if (!isset($_GET['token'])) { http_response_code(404); die("<h2>Invalid link.</h2>"); }

$token = sanitizeInput($_GET['token']);

// Company info
$co = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM companies WHERE company_id = 1 LIMIT 1"));
$co_name    = nullable_htmlentities($co['company_name']);
$co_address = nullable_htmlentities($co['company_address']);
$co_city    = nullable_htmlentities($co['company_city']);
$co_state   = nullable_htmlentities($co['company_state']);
$co_zip     = nullable_htmlentities($co['company_zip']);
$co_phone   = nullable_htmlentities($co['company_phone']);
$co_email   = nullable_htmlentities($co['company_email']);
$co_logo    = nullable_htmlentities($co['company_logo']);

// Load outtake + ticket
$sql = mysqli_query($mysqli, "SELECT ot.*, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_details, t.ticket_created_at, t.ticket_id,
    c.client_name,
    co2.contact_name
    FROM ticket_outtake_forms ot
    JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id
    LEFT JOIN clients c ON t.ticket_client_id = c.client_id
    LEFT JOIN contacts co2 ON t.ticket_contact_id = co2.contact_id
    WHERE ot.outtake_sign_token = '$token' LIMIT 1");

if (mysqli_num_rows($sql) !== 1) {
    die("<div style='font-family:sans-serif;padding:60px;text-align:center;'><h2>This link is invalid or has expired.</h2></div>");
}

$row           = mysqli_fetch_assoc($sql);
$outtake_id    = intval($row['outtake_id']);
$ticket_id     = intval($row['ticket_id']);
$ticket_num    = nullable_htmlentities($row['ticket_prefix']) . intval($row['ticket_number']);
$ticket_subj   = nullable_htmlentities($row['ticket_subject']);
$_purifier_cfg = HTMLPurifier_Config::createDefault();
$_purifier_cfg->set('Cache.DefinitionImpl', null);
$_purifier_cfg->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
$_purifier     = new HTMLPurifier($_purifier_cfg);
$ticket_detail = $_purifier->purify($row['ticket_details'] ?? '');
$ticket_date   = date('m/d/Y', strtotime($row['ticket_created_at']));
$client_name   = nullable_htmlentities($row['client_name']);
$contact_name  = nullable_htmlentities($row['contact_name']);
$tech_notes    = nullable_htmlentities($row['outtake_tech_notes']);
$already_signed = !empty($row['outtake_signed_at']);
$signed_name   = nullable_htmlentities($row['outtake_signed_name']);
$signed_at     = $row['outtake_signed_at'];
$existing_sig  = $row['outtake_signature'];

// Load ticket replies
$sql_replies = mysqli_query($mysqli, "SELECT tr.ticket_reply, tr.ticket_reply_type, tr.ticket_reply_created_at, u.user_name, co3.contact_name as reply_contact
    FROM ticket_replies tr
    LEFT JOIN users u ON tr.ticket_reply_by = u.user_id
    LEFT JOIN contacts co3 ON tr.ticket_reply_by = co3.contact_id
    WHERE tr.ticket_reply_ticket_id = $ticket_id AND tr.ticket_reply_archived_at IS NULL
    ORDER BY tr.ticket_reply_id ASC LIMIT 10");

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Outtake Form — <?= $ticket_num ?></title>
    <link rel="stylesheet" href="/plugins/bootstrap5/css/bootstrap.min.css">
    <link rel="stylesheet" href="/css/itflow_bs5_bridge.css">
    <style>
        body { background: #f0f2f5; font-family: Arial, sans-serif; font-size: 14px; }
        .form-card { max-width: 800px; margin: 30px auto 60px; background: #fff; border-radius: 6px; box-shadow: 0 2px 16px rgba(0,0,0,.12); overflow: hidden; }
        .form-header { background: #1a1a2e; color: #fff; padding: 22px 28px; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
        .form-header .co-side h4 { margin: 0 0 4px; font-size: 18px; font-weight: 700; }
        .form-header .co-side .co-detail { font-size: 12px; opacity: .8; line-height: 1.7; }
        .form-header .ticket-side { text-align: right; font-size: 12px; opacity: .85; line-height: 1.9; white-space: nowrap; }
        .form-header .ticket-side strong { font-size: 16px; display: block; margin-bottom: 6px; }
        .section-bar { background: #eef0f3; padding: 7px 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #555; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; }
        .section-body { padding: 14px 20px; }
        .disclaimer-list li { margin-bottom: 8px; line-height: 1.5; }
        canvas { border: 2px solid #ccc; border-radius: 4px; background: #fff; display: block; width: 100%; height: 140px; touch-action: none; cursor: crosshair; }
        canvas:active, canvas:focus { border-color: #1a1a2e; }
        .signed-banner { background: #e6ffed; border: 1px solid #6fcf97; border-radius: 6px; padding: 18px 20px; margin: 20px; }
        @media print { body { background: #fff; } .form-card { box-shadow: none; margin: 0; border-radius: 0; } .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="form-card">

    <!-- Header -->
    <div class="form-header">
        <div class="co-side">
            <?php if ($co_logo) { ?><img src="/uploads/settings/<?= $co_logo ?>" style="max-height:45px;margin-bottom:10px;display:block;" alt=""><br><?php } ?>
            <h4><?= $co_name ?></h4>
            <div class="co-detail">
                <?= $co_address ? $co_address . '<br>' : '' ?>
                <?= $co_city ? "$co_city, $co_state $co_zip<br>" : '' ?>
                <?= $co_phone ?: '' ?>
                <?= $co_phone && $co_email ? ' &bull; ' : '' ?>
                <?= $co_email ?: '' ?>
            </div>
        </div>
        <div class="ticket-side">
            <strong>Outtake Form</strong>
            Ticket #: <?= $ticket_num ?><br>
            Date: <?= $ticket_date ?><br>
            Client: <?= $client_name ?><br>
            <?= $contact_name ? 'Contact: ' . $contact_name . '<br>' : '' ?>
            Subject: <?= $ticket_subj ?>
        </div>
    </div>

    <?php if ($already_signed) { ?>

    <!-- Signed state -->
    <div class="signed-banner">
        <strong style="color:#27ae60; font-size:16px;">&#10003; Outtake Form Signed</strong><br>
        <span>Signed by <strong><?= $signed_name ?></strong> on <?= date('F j, Y \a\t g:i A', strtotime($signed_at)) ?></span>
        <?php if ($existing_sig) { ?>
        <div class="mt-3"><img src="<?= htmlspecialchars($existing_sig, ENT_QUOTES, 'UTF-8') ?>" style="max-height:90px;border:1px solid #ccc;border-radius:4px;background:#fff;"></div>
        <?php } ?>
    </div>

    <!-- Show ticket details for record -->
    <?php if ($ticket_detail) { ?>
    <div class="section-bar">Ticket Problem</div>
    <div class="section-body" style="color:#333;"><?= $ticket_detail ?></div>
    <?php } ?>

    <?php if ($tech_notes) { ?>
    <div class="section-bar">Work Completed</div>
    <div class="section-body" style="white-space:pre-wrap;color:#333;"><?= $tech_notes ?></div>
    <?php } ?>

    <div class="text-center py-4 no-print">
        <button class="btn btn-outline-secondary js-print-page"><i class="fas fa-print me-1"></i>Print / Save PDF</button>
    </div>

    <?php } else { ?>

    <!-- Ticket Problem -->
    <?php if ($ticket_detail) { ?>
    <div class="section-bar">Ticket Problem</div>
    <div class="section-body" style="color:#333;"><?= $ticket_detail ?></div>
    <?php } ?>

    <!-- Ticket Comments -->
    <?php if (mysqli_num_rows($sql_replies) > 0) { ?>
    <div class="section-bar">Ticket Comments</div>
    <div class="section-body">
        <?php while ($reply = mysqli_fetch_assoc($sql_replies)) {
            $by = $reply['ticket_reply_type'] == 'Agent' ? nullable_htmlentities($reply['user_name']) : nullable_htmlentities($reply['reply_contact']);
            $dt = date('M j, Y', strtotime($reply['ticket_reply_created_at']));
            $body = htmlspecialchars(substr(strip_tags($reply['ticket_reply']), 0, 400));
        ?>
        <div class="mb-3">
            <div style="font-size:11px;color:#888;"><?= $by ?> &mdash; <?= $dt ?></div>
            <div style="color:#333;margin-top:2px;"><?= nl2br($body) ?></div>
        </div>
        <?php } ?>
    </div>
    <?php } ?>

    <!-- Work Summary -->
    <?php if ($tech_notes) { ?>
    <div class="section-bar">Work Completed</div>
    <div class="section-body" style="white-space:pre-wrap;color:#333;"><?= $tech_notes ?></div>
    <?php } ?>

    <!-- Disclaimer -->
    <div class="section-bar">Outtake Form Disclaimer</div>
    <div class="section-body disclaimer">
        <p class="mb-2">By signing this outtake form, the undersigned acknowledges the following:</p>
        <ul class="disclaimer-list">
            <li><strong>Receipt of Equipment:</strong> The undersigned confirms that any equipment associated with this ticket has been delivered and received in satisfactory condition. The details of the parts used, though not itemized on this outtake form, are available upon request and will be reflected on the final invoice.</li>
            <li><strong>Payment for Parts and Services:</strong> The undersigned agrees to pay for all parts and services rendered. Payment terms are as agreed upon in your service agreement or our standard payment policy.</li>
            <li><strong>Acknowledgement of Work:</strong> The undersigned acknowledges that the work performed, as described in this form, has been completed.</li>
        </ul>
        <p class="mt-2 mb-0" style="font-size:13px;">For full terms and conditions, please visit <a href="https://foleyit.com/ticket-terms" target="_blank" rel="noopener">foleyit.com/ticket-terms</a>.</p>
    </div>

    <!-- Signature Form -->
    <form action="guest_post.php" method="post" id="outtakeForm">
        <input type="hidden" name="outtake_sign_token" value="<?= $token ?>">

        <div class="section-bar">Customer Signature</div>
        <div class="section-body">
            <div class="form-group mb-3">
                <label style="font-weight:700;">Full Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="signed_name" required placeholder="Type your full name">
            </div>
            <div class="form-group mb-2">
                <label style="font-weight:700;">Signature <span class="text-danger">*</span></label>
                <canvas id="sig_canvas"></canvas>
                <input type="hidden" name="outtake_signature" id="sig_data">
                <div class="mt-2 d-flex align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-secondary me-3 no-print js-clear-outtake-sig">Clear</button>
                    <small class="text-secondary">Draw your signature in the box above</small>
                </div>
            </div>
            <div class="form-check mt-3 mb-3">
                <input class="form-check-input" type="checkbox" id="terms_agree" required>
                <label class="form-check-label" for="terms_agree">
                    I have read and agree to the <a href="https://foleyit.com/ticket-terms" target="_blank" rel="noopener">Terms &amp; Conditions</a>
                </label>
            </div>
            <div class="mt-2">
                <button type="submit" name="sign_outtake" class="btn btn-success btn-lg btn-block">
                    &#10003;&nbsp; Submit &amp; Sign Outtake Form
                </button>
            </div>
        </div>
    </form>

    <?php } // end not-signed ?>
</div>

<link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css">
<script src="/js/signature_pad.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
function clearSig() {
    var hidden = document.getElementById('sig_data');
    if (hidden) hidden.value = '';
    var canvas = document.getElementById('sig_canvas');
    if (canvas) canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
}
document.addEventListener('click', function (e) {
    if (e.target.closest('.js-clear-outtake-sig')) { clearSig(); }
});

document.addEventListener('DOMContentLoaded', function() {
    initSignaturePad('sig_canvas', 'sig_data');

    document.getElementById('outtakeForm') && document.getElementById('outtakeForm').addEventListener('submit', function(e){
        if (!document.getElementById('sig_data').value) {
            e.preventDefault();
            alert('Please draw your signature before submitting.');
        }
    });
});
</script>
</body>
</html>
