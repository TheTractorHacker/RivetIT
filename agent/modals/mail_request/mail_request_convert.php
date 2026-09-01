<?php
require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$mail_request_id = intval($_GET['id'] ?? 0);
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mr.mail_request_id, mr.mail_request_from_email, mr.mail_request_from_name, mr.mail_request_subject, m.mailbox_default_client_id
    FROM mail_requests mr
    LEFT JOIN mailboxes m ON m.mailbox_id = mr.mail_request_mailbox_id
    WHERE mr.mail_request_id = $mail_request_id AND mr.mail_request_archived_at IS NULL LIMIT 1"));

if (!$row) {
    echo '<div class="p-3 text-danger">Request not found (it may have already been converted or dismissed).</div>';
    require_once '../../../includes/modal_footer.php';
    exit;
}

$default_client_id = intval($row['mailbox_default_client_id'] ?? 0);

// If the mailbox this request came in on has a default client, make sure the current
// agent is actually permitted to act on that client before letting them convert it
if ($default_client_id > 0) {
    enforceClientAccess($default_client_id);
}

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-check me-2"></i>Convert to Ticket</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="mail_request_id" value="<?= $mail_request_id ?>">
    <div class="modal-body">

        <p class="text-muted">
            From <strong><?= nullable_htmlentities($row['mail_request_from_name']) ?></strong>
            &lt;<?= nullable_htmlentities($row['mail_request_from_email']) ?>&gt;
            &mdash; <?= $row['mail_request_subject'] !== '' ? nullable_htmlentities($row['mail_request_subject']) : 'No Subject' ?>
        </p>

        <div class="form-group">
            <label>Client</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-user-tag"></i></span></div>
                <select class="form-control select2" name="client_id">
                    <option value="0" <?= $default_client_id === 0 ? 'selected' : '' ?>>- None (Guest / unassigned) -</option>
                    <?php
                    $sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
                    while ($client_row = mysqli_fetch_assoc($sql_clients)) {
                        $client_id = intval($client_row['client_id']);
                        $client_name = nullable_htmlentities($client_row['client_name']);
                    ?>
                    <option value="<?= $client_id ?>" <?= $default_client_id === $client_id ? 'selected' : '' ?>><?= $client_name ?></option>
                    <?php } ?>
                </select>
            </div>
            <small class="form-text text-muted">If the sender doesn't already exist as a contact under this client, a contact is created automatically. Leave as Guest to create an unassigned ticket, same as before.</small>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="convert_mail_request" class="btn btn-primary"><i class="fas fa-check me-2"></i>Create Ticket</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
