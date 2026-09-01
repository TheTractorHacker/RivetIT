<?php

if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_url = '';
    $client_id = 0;
}

enforceUserPermission('module_support');

$outtake_id = intval($_GET['outtake_id']);
$ticket_id  = intval($_GET['ticket_id']);

$sql = mysqli_query($mysqli, "SELECT ot.*, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_details, t.ticket_created_at, c.client_name, co.contact_name
    FROM ticket_outtake_forms ot
    JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id
    LEFT JOIN clients c ON t.ticket_client_id = c.client_id
    LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
    WHERE ot.outtake_id = $outtake_id LIMIT 1");

if (mysqli_num_rows($sql) == 0) {
    echo "<div class='alert alert-danger m-3'>Outtake form not found.</div>";
    require_once "../includes/footer.php";
    exit;
}

$row        = mysqli_fetch_assoc($sql);
$ticket_num = nullable_htmlentities($row['ticket_prefix']) . intval($row['ticket_number']);
$subject    = nullable_htmlentities($row['ticket_subject']);
$client_nm  = nullable_htmlentities($row['client_name']);
$contact_nm = nullable_htmlentities($row['contact_name']);
$tech_notes = nullable_htmlentities($row['outtake_tech_notes']);
$ot_token   = nullable_htmlentities($row['outtake_sign_token']);
$signed_at  = $row['outtake_signed_at'];
$signed_nm  = nullable_htmlentities($row['outtake_signed_name']);
$signature  = $row['outtake_signature'];
$ticket_id  = intval($row['outtake_ticket_id']);
$client_id  = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id"))[0]);

// Confirm the requesting agent has access to the client that actually owns
// this outtake form's ticket, regardless of any client_id passed in the URL.
if ($client_id) {
    enforceClientAccess($client_id);
}

// Ticket replies for "comments" section
$sql_replies = mysqli_query($mysqli, "SELECT tr.ticket_reply, tr.ticket_reply_type, tr.ticket_reply_created_at, u.user_name, co.contact_name
    FROM ticket_replies tr
    LEFT JOIN users u ON tr.ticket_reply_by = u.user_id
    LEFT JOIN contacts co ON tr.ticket_reply_by = co.contact_id
    WHERE tr.ticket_reply_ticket_id = $ticket_id AND tr.ticket_reply_archived_at IS NULL
    AND tr.ticket_reply_type NOT IN ('System','Automation','RMM Alert')
    ORDER BY tr.ticket_reply_id ASC LIMIT 10");

// Contact email for sending the magic link
$contact_email_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT co.contact_email FROM tickets t LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id WHERE t.ticket_id = $ticket_id LIMIT 1"));
$contact_email = nullable_htmlentities($contact_email_row['contact_email'] ?? '');

$sign_url = "https://$config_base_url/guest/outtake_sign.php?token=$ot_token";

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-signature me-2"></i>Outtake Form — <?= $ticket_num ?>: <?= $subject ?></h3>
        <div class="card-tools">
            <a href="ticket.php?ticket_id=<?= $ticket_id ?><?= $client_id ? '&client_id='.$client_id : '' ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i>Back to Ticket
            </a>
        </div>
    </div>
    <div class="card-body">

        <?php if ($signed_at) { ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>Signed by <strong><?= $signed_nm ?></strong> on <?= date('M j, Y g:i A', strtotime($signed_at)) ?>
            <?php if ($signature) { ?><br><img src="<?= $signature ?>" style="max-height:80px;border:1px solid #ccc;border-radius:4px;background:#fff;display:block;margin-top:8px;"><?php } ?>
        </div>
        <?php } else { ?>
        <div class="alert alert-warning">
            <div class="d-flex align-items-start flex-wrap">
                <div class="flex-grow-1">
                    <i class="fas fa-clock me-2"></i><strong>Awaiting customer signature.</strong>
                    <?php if ($ot_token) { ?>
                    <div class="mt-1" style="font-size:12px;word-break:break-all;color:#856404;"><?= $sign_url ?></div>
                    <?php } ?>
                </div>
                <div class="d-flex flex-wrap mt-2 mt-md-0 ml-md-3" style="gap:6px;">
                    <?php if ($ot_token && $contact_email) { ?>
                    <form action="post.php" method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="outtake_id" value="<?= $outtake_id ?>">
                        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                        <input type="hidden" name="client_id" value="<?= $client_id ?>">
                        <button type="submit" name="send_outtake_email" class="btn btn-primary" title="Email signing link to <?= $contact_email ?>">
                            <i class="fas fa-envelope me-1"></i>Email Link to <?= $contact_email ?>
                        </button>
                    </form>
                    <?php } elseif ($ot_token) { ?>
                    <button class="btn btn-outline-secondary btn-sm js-copy-outtake-link" data-url="<?= $sign_url ?>">
                        <i class="fas fa-copy me-1"></i>Copy Link
                    </button>
                    <?php } ?>
                    <button type="button" class="btn btn-success js-sign-outtake" title="Sign now in-person"
                        data-outtake-id="<?= $outtake_id ?>" data-ticket-id="<?= $ticket_id ?>" data-client-id="<?= $client_id ?: 0 ?>">
                        <i class="fas fa-pen-nib me-1"></i>Sign In-Person
                    </button>
                </div>
            </div>
        </div>
        <?php } ?>

        <div class="row">
            <div class="col-md-8">
                <!-- Tech Notes -->
                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="outtake_id" value="<?= $outtake_id ?>">
                    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                    <div class="form-group">
                        <label><strong>Tech Notes / Work Summary</strong> <small class="text-secondary">(visible on the outtake form)</small></label>
                        <textarea class="form-control" name="outtake_tech_notes" rows="5" placeholder="Describe the work completed, parts used, recommendations..."><?= $tech_notes ?></textarea>
                    </div>
                    <button type="submit" name="save_outtake_notes" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Notes</button>
                </form>

                <!-- Ticket Replies Preview -->
                <?php if (mysqli_num_rows($sql_replies) > 0) { ?>
                <div class="mt-4">
                    <strong class="text-secondary d-block mb-2">Ticket Comments (will appear on form)</strong>
                    <?php while ($reply = mysqli_fetch_assoc($sql_replies)) {
                        $by = !empty($reply['user_name']) ? nullable_htmlentities($reply['user_name']) : nullable_htmlentities($reply['contact_name']);
                        $date = date('M j, Y', strtotime($reply['ticket_reply_created_at']));
                        $excerpt = substr(strip_tags($reply['ticket_reply']), 0, 300);
                    ?>
                    <div class="border rounded p-2 mb-2 bg-light">
                        <small class="text-secondary"><?= $by ?> — <?= $date ?></small>
                        <div class="mt-1" style="font-size:13px;"><?= nullable_htmlentities($excerpt) ?><?= strlen(strip_tags($reply['ticket_reply'])) > 300 ? '…' : '' ?></div>
                    </div>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
            <div class="col-md-4">
                <div class="card border">
                    <div class="card-body p-3">
                        <h6 class="border-bottom pb-2">Form Details</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><td class="text-secondary">Ticket</td><td><?= $ticket_num ?></td></tr>
                            <tr><td class="text-secondary">Client</td><td><?= $client_nm ?></td></tr>
                            <?php if ($contact_nm) { ?><tr><td class="text-secondary">Contact</td><td><?= $contact_nm ?></td></tr><?php } ?>
                            <tr><td class="text-secondary">Subject</td><td><?= $subject ?></td></tr>
                            <tr><td class="text-secondary">Status</td><td><?= $signed_at ? '<span class="badge text-bg-success">Signed</span>' : '<span class="badge text-bg-warning text-dark">Unsigned</span>' ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>

<!-- Outtake form creation + in-person signing -->
<script src="/js/signature_pad.js"></script>
<script src="/js/outtake.js"></script>
