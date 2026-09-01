<?php

require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli, "SELECT mr.*, m.mailbox_name, m.mailbox_email,
        (SELECT COUNT(*) FROM mail_request_attachments WHERE mail_request_attachment_mail_request_id = mr.mail_request_id) AS attachment_count
    FROM mail_requests mr
    LEFT JOIN mailboxes m ON m.mailbox_id = mr.mail_request_mailbox_id
    WHERE mr.mail_request_archived_at IS NULL
    ORDER BY mr.mail_request_created_at DESC");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-inbox me-2"></i>Requests</h3>
    </div>

    <div class="card-body">
        <p class="text-muted">Emails from senders that don't match a known contact or domain land here for review. Convert a request into a ticket (picking the client it belongs to), or dismiss it.</p>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>From</th>
                        <th>Subject</th>
                        <th>Mailbox</th>
                        <th>Received</th>
                        <th>Attachments</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $mr_id           = intval($row['mail_request_id']);
                    $mr_from_email   = nullable_htmlentities($row['mail_request_from_email']);
                    $mr_from_name    = nullable_htmlentities($row['mail_request_from_name']);
                    $mr_subject      = nullable_htmlentities($row['mail_request_subject']);
                    $mr_mailbox_name = nullable_htmlentities($row['mailbox_name'] ?: $row['mailbox_email']);
                    $mr_received_at  = nullable_htmlentities($row['mail_request_received_at']);
                    $mr_attachments  = intval($row['attachment_count']);
                ?>
                <tr>
                    <td>
                        <?= $mr_from_name ? "$mr_from_name " : '' ?><span class="text-muted">&lt;<?= $mr_from_email ?>&gt;</span>
                    </td>
                    <td><?= $mr_subject !== '' ? $mr_subject : '<span class="text-muted">No Subject</span>' ?></td>
                    <td><?= $mr_mailbox_name ?: '<span class="text-muted">Unknown</span>' ?></td>
                    <td><?= $mr_received_at ?></td>
                    <td>
                        <?php if ($mr_attachments > 0) { ?>
                            <span class="badge text-bg-secondary"><i class="fas fa-paperclip me-1"></i><?= $mr_attachments ?></span>
                        <?php } else { ?>
                            <span class="text-muted">&mdash;</span>
                        <?php } ?>
                    </td>
                    <td class="text-center" style="white-space:nowrap;">
                        <a href="#" class="btn btn-xs btn-secondary ajax-modal" data-modal-url="modals/mail_request/mail_request_view.php?id=<?= $mr_id ?>">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <a href="#" class="btn btn-xs btn-primary ajax-modal" data-modal-url="modals/mail_request/mail_request_convert.php?id=<?= $mr_id ?>">
                            <i class="fas fa-check"></i> Convert to Ticket
                        </a>
                        <a href="post.php?dismiss_mail_request=<?= $mr_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                           class="btn btn-xs btn-danger confirm-link ms-1">
                            <i class="fas fa-trash"></i> Dismiss
                        </a>
                    </td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
