<?php

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

// Client-scope restriction: a mail request has no client_id of its own until converted,
// so techs restricted via user_client_permissions are scoped by the mailbox's configured
// default client instead. Mailboxes with no default client (shared/general inboxes) stay
// visible to everyone, since there's no client to scope them to.
$sql = mysqli_query($mysqli, "SELECT mr.*, m.mailbox_name, m.mailbox_email,
        (SELECT COUNT(*) FROM mail_request_attachments WHERE mail_request_attachment_mail_request_id = mr.mail_request_id) AS attachment_count
    FROM mail_requests mr
    LEFT JOIN mailboxes m ON m.mailbox_id = mr.mail_request_mailbox_id
    WHERE mr.mail_request_archived_at IS NULL
      AND (
          NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $session_user_id LIMIT 1)
          OR m.mailbox_default_client_id IS NULL
          OR EXISTS (SELECT 1 FROM user_client_permissions ucp WHERE ucp.user_id = $session_user_id AND ucp.client_id = m.mailbox_default_client_id LIMIT 1)
      )
    ORDER BY mr.mail_request_created_at DESC");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-inbox me-2"></i>Requests</h3>
    </div>

    <div class="card-body">
        <p class="text-muted">Emails from senders that don't match a known contact or domain land here for review. Convert a request into a ticket (picking the department it belongs to), or dismiss it.</p>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover" style="min-width:880px;">
                <thead class="text-dark">
                    <tr>
                        <th style="min-width:13rem;">From</th>
                        <th>Subject</th>
                        <th>Mailbox</th>
                        <th>Received</th>
                        <th>Attachments</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($sql) === 0) { ?>
                <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No requests waiting for review. Mail from unknown senders will appear here.</td></tr>
                <?php } ?>
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
                        <?php if ($mr_from_name) { ?><div class="fw-semibold"><?= $mr_from_name ?></div><?php } ?>
                        <div class="text-muted small text-break"><?= $mr_from_email ?></div>
                    </td>
                    <td style="min-width:16rem;">
                        <div><?= $mr_subject !== '' ? $mr_subject : '<span class="text-muted">No Subject</span>' ?></div>
                        <?php
                        // Body preview: enough to decide convert vs dismiss without opening the request.
                        $mr_preview = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) ($row['mail_request_body'] ?? '')), ENT_QUOTES | ENT_HTML5)));
                        if ($mr_preview !== '') { ?>
                        <div class="text-muted small mt-1"><?= nullable_htmlentities(mb_strlen($mr_preview) > 110 ? mb_substr($mr_preview, 0, 110) . '...' : $mr_preview) ?></div>
                        <?php } ?>
                    </td>
                    <td class="text-nowrap"><?= $mr_mailbox_name ?: '<span class="text-muted">Unknown</span>' ?></td>
                    <td class="text-nowrap" title="<?= $mr_received_at ?>"><?= timeAgo($row['mail_request_received_at']) ?><div class="text-muted small"><?= $mr_received_at ?></div></td>
                    <td>
                        <?php if ($mr_attachments > 0) { ?>
                            <span class="badge text-bg-secondary"><i class="fas fa-paperclip me-1"></i><?= $mr_attachments ?></span>
                        <?php } else { ?>
                            <span class="text-muted">&mdash;</span>
                        <?php } ?>
                    </td>
                    <td class="text-end">
                      <div class="d-flex flex-wrap gap-1 justify-content-end" style="width:12.5rem;margin-left:auto;">
                        <?php if (lookupUserPermission('module_support') >= 2) { ?>
                        <a href="#" class="btn btn-xs btn-primary ajax-modal" data-modal-url="modals/mail_request/mail_request_convert.php?id=<?= $mr_id ?>">
                            <i class="fas fa-check me-1"></i>Convert to Ticket
                        </a>
                        <?php } ?>
                        <a href="#" class="btn btn-xs btn-secondary ajax-modal" data-modal-url="modals/mail_request/mail_request_view.php?id=<?= $mr_id ?>">
                            <i class="fas fa-eye me-1"></i>View
                        </a>
                        <?php if (lookupUserPermission('module_support') >= 2) { ?>
                        <a href="post.php?dismiss_mail_request=<?= $mr_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                           class="btn btn-xs btn-danger confirm-link">
                            <i class="fas fa-trash me-1"></i>Dismiss
                        </a>
                        <?php } ?>
                      </div>
                    </td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
