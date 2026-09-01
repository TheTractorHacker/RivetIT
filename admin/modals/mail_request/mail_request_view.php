<?php
require_once '../../../includes/modal_header.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    exit(WORDING_ROLECHECK_FAILED . "<br>Tell your admin: Your role does not have admin access.");
}

$mail_request_id = intval($_GET['id'] ?? 0);
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mr.*, m.mailbox_name, m.mailbox_email
    FROM mail_requests mr
    LEFT JOIN mailboxes m ON m.mailbox_id = mr.mail_request_mailbox_id
    WHERE mr.mail_request_id = $mail_request_id AND mr.mail_request_archived_at IS NULL LIMIT 1"));

if (!$row) {
    echo '<div class="p-3 text-danger">Request not found (it may have already been converted or dismissed).</div>';
    require_once '../../../includes/modal_footer.php';
    exit;
}

$attachments_sql = mysqli_query($mysqli, "SELECT mail_request_attachment_name, mail_request_attachment_reference_name FROM mail_request_attachments WHERE mail_request_attachment_mail_request_id = $mail_request_id");

// Initialize the HTML Purifier to prevent XSS — mail_request_body is the raw HTML body
// of an inbound email from an untrusted external sender.
require "../../../plugins/htmlpurifier/HTMLPurifier.standalone.php";
$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);
$mail_request_body = $purifier->purify($row['mail_request_body']);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-eye me-2"></i>View Request</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">

    <div class="row mb-3">
        <div class="col-6">
            <small class="text-muted d-block">From</small>
            <?= nullable_htmlentities($row['mail_request_from_name']) ?>
            &lt;<?= nullable_htmlentities($row['mail_request_from_email']) ?>&gt;
        </div>
        <div class="col-6">
            <small class="text-muted d-block">Received</small>
            <?= nullable_htmlentities($row['mail_request_received_at']) ?>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-6">
            <small class="text-muted d-block">Mailbox</small>
            <?= nullable_htmlentities($row['mailbox_name'] ?: $row['mailbox_email']) ?>
        </div>
        <?php if (!empty($row['mail_request_ccs'])) { ?>
        <div class="col-6">
            <small class="text-muted d-block">CC</small>
            <?= nullable_htmlentities($row['mail_request_ccs']) ?>
        </div>
        <?php } ?>
    </div>

    <div class="mb-3">
        <small class="text-muted d-block">Subject</small>
        <strong><?= $row['mail_request_subject'] !== '' ? nullable_htmlentities($row['mail_request_subject']) : 'No Subject' ?></strong>
    </div>

    <div class="mb-3">
        <small class="text-muted d-block mb-1">Message</small>
        <div class="border rounded p-3" style="max-height:300px;overflow-y:auto;background:#fff;color:#111;">
            <?= $mail_request_body ?>
        </div>
    </div>

    <div>
        <small class="text-muted d-block mb-1">Attachments</small>
        <ul class="ps-3 mb-2">
            <?php if (!empty($row['mail_request_eml_reference_name'])) { ?>
            <li><a href="/uploads/mail_requests/<?= $mail_request_id ?>/<?= rawurlencode($row['mail_request_eml_reference_name']) ?>" target="_blank">Original-parsed-email.eml</a></li>
            <?php } ?>
            <?php
            $has_attachments = false;
            while ($att = mysqli_fetch_assoc($attachments_sql)) {
                $has_attachments = true;
            ?>
            <li><a href="/uploads/mail_requests/<?= $mail_request_id ?>/<?= rawurlencode($att['mail_request_attachment_reference_name']) ?>" download="<?= nullable_htmlentities($att['mail_request_attachment_name']) ?>"><?= nullable_htmlentities($att['mail_request_attachment_name']) ?></a></li>
            <?php } ?>
        </ul>
        <?php if (!$has_attachments && empty($row['mail_request_eml_reference_name'])) { ?>
        <span class="text-muted">None</span>
        <?php } ?>
    </div>

</div>
<div class="modal-footer">
    <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/mail_request/mail_request_convert.php?id=<?= $mail_request_id ?>" data-bs-dismiss="modal"><i class="fas fa-check me-2"></i>Convert to Ticket</button>
    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close</button>
</div>
<?php
require_once '../../../includes/modal_footer.php';
