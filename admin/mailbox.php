<?php

require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli, "SELECT * FROM mailboxes WHERE mailbox_archived_at IS NULL ORDER BY mailbox_order, mailbox_id");

$mailbox_type_labels = [
    'standard_imap'   => ['label' => 'Standard IMAP', 'badge' => 'text-bg-secondary', 'icon_style' => 'fas', 'icon' => 'fa-server'],
    'google_oauth'    => ['label' => 'Google Workspace', 'badge' => 'text-bg-info', 'icon_style' => 'fab', 'icon' => 'fa-google'],
    'microsoft_oauth' => ['label' => 'Microsoft 365', 'badge' => 'text-bg-primary', 'icon_style' => 'fab', 'icon' => 'fa-microsoft'],
];

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-inbox me-2"></i>Mailboxes</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-outline-secondary ajax-modal" data-modal-url="modals/mailbox/mailbox_check_access.php">
                <i class="fas fa-satellite-dish me-2"></i>Check Shared Mailbox Access
            </button>
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/mailbox/mailbox_add.php">
                <i class="fas fa-plus me-2"></i>Add Mailbox
            </button>
        </div>
    </div>

    <div class="card-body">
        <p class="text-muted">Manage the mailboxes ITFlow monitors for email-to-ticket parsing. Each mailbox can use standard IMAP credentials or connect to Google Workspace / Microsoft 365 via OAuth.</p>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Connected?</th>
                        <th>Default Client</th>
                        <th>Active</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $mb_id        = intval($row['mailbox_id']);
                    $mb_name      = nullable_htmlentities($row['mailbox_name']);
                    $mb_email     = nullable_htmlentities($row['mailbox_email']);
                    $mb_type      = $row['mailbox_type'] ?? 'standard_imap';
                    $mb_type_info = $mailbox_type_labels[$mb_type] ?? $mailbox_type_labels['standard_imap'];
                    $mb_active    = intval($row['mailbox_active']);

                    $is_oauth_type = in_array($mb_type, ['google_oauth', 'microsoft_oauth'], true);

                    if ($is_oauth_type) {
                        $mb_connected = !empty($row['mailbox_oauth_refresh_token_enc']);
                        $mb_connected_label = $mb_connected ? 'Connected' : 'Needs reconnect';
                    } else {
                        $mb_connected = !empty($row['mailbox_imap_host']) && !empty($row['mailbox_imap_username']);
                        $mb_connected_label = $mb_connected ? 'Configured' : 'Needs setup';
                    }

                    $mb_default_client_name = '';
                    if (!empty($row['mailbox_default_client_id'])) {
                        $client_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = " . intval($row['mailbox_default_client_id']) . " LIMIT 1"));
                        if ($client_row) {
                            $mb_default_client_name = nullable_htmlentities($client_row['client_name']);
                        }
                    }
                ?>
                <tr>
                    <td><?= $mb_name ?></td>
                    <td><?= $mb_email ?></td>
                    <td><span class="badge <?= $mb_type_info['badge'] ?>"><i class="<?= $mb_type_info['icon_style'] ?> fa-fw <?= $mb_type_info['icon'] ?> me-1"></i><?= $mb_type_info['label'] ?></span></td>
                    <td>
                        <?php if ($mb_connected) { ?>
                            <span class="badge text-bg-success"><i class="fas fa-check-circle me-1"></i><?= $mb_connected_label ?></span>
                        <?php } else { ?>
                            <span class="badge text-bg-danger"><i class="fas fa-times-circle me-1"></i><?= $mb_connected_label ?></span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ($mb_default_client_name !== '') { ?>
                            <?= $mb_default_client_name ?>
                        <?php } else { ?>
                            <span class="text-muted">Guest / Unmatched</span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ($mb_active) { ?>
                            <span class="badge text-bg-success">Active</span>
                        <?php } else { ?>
                            <span class="badge text-bg-secondary">Inactive</span>
                        <?php } ?>
                    </td>
                    <td class="text-center" style="white-space:nowrap;">
                        <a href="#" class="btn btn-xs btn-secondary ajax-modal" data-modal-url="modals/mailbox/mailbox_edit.php?id=<?= $mb_id ?>">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="post.php?delete_mailbox=<?= $mb_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                           class="btn btn-xs btn-danger confirm-link ms-1">
                            <i class="fas fa-trash"></i> Delete
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
