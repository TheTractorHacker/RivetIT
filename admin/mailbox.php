<?php

require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli, "SELECT * FROM mailboxes WHERE mailbox_archived_at IS NULL ORDER BY mailbox_order, mailbox_id");

$mailbox_type_labels = [
    'standard_imap'   => ['label' => 'Standard IMAP', 'badge' => 'text-bg-secondary', 'icon_style' => 'fas', 'icon' => 'fa-server'],
    'google_oauth'    => ['label' => 'Google Workspace', 'badge' => 'text-bg-info', 'icon_style' => 'fab', 'icon' => 'fa-google'],
    'microsoft_oauth' => ['label' => 'Microsoft 365', 'badge' => 'text-bg-primary', 'icon_style' => 'fab', 'icon' => 'fa-microsoft'],
];

$intake_summary = \ITFlow\Mail\MailHealth::summary($mysqli);
$intake_settings = \ITFlow\Mail\MailSettings::all($mysqli);

// Relative "5 min ago" style text for the health columns.
$mb_ago = function (?string $ts): string {
    if (empty($ts)) {
        return 'never';
    }
    $secs = max(0, time() - strtotime($ts));
    if ($secs < 90) { return 'just now'; }
    if ($secs < 5400) { return round($secs / 60) . ' min ago'; }
    if ($secs < 129600) { return round($secs / 3600) . ' h ago'; }
    return round($secs / 86400) . ' d ago';
};

?>

<?php if ($intake_summary['attention'] > 0) { ?>
<div class="alert alert-warning d-flex align-items-center" role="alert">
    <i class="fas fa-exclamation-triangle me-3"></i>
    <div>
        <strong><?= intval($intake_summary['attention']) ?> mail intake item(s) need attention:</strong>
        <?= intval($intake_summary['unhealthy']) ?> mailbox(es) unhealthy,
        <?= intval($intake_summary['quarantined']) ?> quarantined message(s),
        <?= intval($intake_summary['exhausted']) ?> outbound email(s) out of retries.
    </div>
</div>
<?php } ?>

<div class="card">
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
        <p class="text-muted">Manage the mailboxes <?= htmlspecialchars(APP_NAME) ?> monitors for email-to-ticket parsing. Each mailbox can use standard IMAP credentials or connect to Google Workspace / Microsoft 365 via OAuth.</p>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Type</th>
                        <th>Connected?</th>
                        <th>Health</th>
                        <th>Default Department</th>
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

                    // Health: last attempt / last success / consecutive failures / last error (written by cron/ticket_email_parser.php)
                    $mb_failures = intval($row['mailbox_consecutive_failures'] ?? 0);
                    $mb_last_polled = $row['mailbox_last_polled_at'] ?? null;
                    $mb_last_success = $row['mailbox_last_success_at'] ?? null;
                    $mb_last_error = nullable_htmlentities($row['mailbox_last_error'] ?? '');
                    $mb_silent_min = max(5, intval($intake_settings['poller_silent_minutes']));
                    $mb_is_silent = $mb_active && (empty($mb_last_polled) ? (time() - strtotime($row['mailbox_created_at'])) : (time() - strtotime($mb_last_polled))) > $mb_silent_min * 60;
                    if (!$mb_active) {
                        $mb_health = ['secondary', 'Inactive'];
                    } elseif ($mb_failures >= max(1, intval($intake_settings['mailbox_fail_threshold']))) {
                        $mb_health = ['danger', 'Failing'];
                    } elseif ($mb_is_silent) {
                        $mb_health = ['warning', 'Not polled'];
                    } elseif ($mb_failures > 0) {
                        $mb_health = ['warning', 'Retrying'];
                    } else {
                        $mb_health = ['success', 'Healthy'];
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
                    <td style="min-width:14rem;">
                        <span class="badge text-bg-<?= $mb_health[0] ?>"><?= $mb_health[1] ?></span>
                        <?php if ($mb_failures > 0) { ?><span class="badge text-bg-danger"><?= $mb_failures ?> failed in a row</span><?php } ?>
                        <div class="small text-muted mt-1">
                            Last polled: <?= $mb_ago($mb_last_polled) ?><br>
                            Last success: <?= $mb_ago($mb_last_success) ?>
                            <?php if ($mb_last_error !== '') { ?><br><span class="text-danger" title="<?= $mb_last_error ?>">Error: <?= mb_strimwidth($mb_last_error, 0, 90, '...') ?></span><?php } ?>
                        </div>
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


<?php
// Messages the poller set aside after repeated failures (mail_intake_state)
$quarantine_sql = mysqli_query($mysqli, "SELECT s.*, m.mailbox_name, m.mailbox_email FROM mail_intake_state s LEFT JOIN mailboxes m ON m.mailbox_id = s.intake_mailbox_id WHERE s.intake_state = 'quarantined' ORDER BY s.intake_quarantined_at DESC LIMIT 100");
?>
<div class="card mt-3">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-biohazard me-2"></i>Quarantined mail <span class="badge text-bg-<?= $intake_summary['quarantined'] ? 'danger' : 'secondary' ?>"><?= intval($intake_summary['quarantined']) ?></span></h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-2">Messages that failed <?= intval($intake_settings['poison_max_attempts']) ?> times are set aside here instead of being fetched again on every run. Messages that threw an error are moved to the <code>ITFlow-Quarantine</code> folder; messages that simply matched nothing stay in the Inbox, flagged and marked read.</p>
        <?php if (mysqli_num_rows($quarantine_sql) === 0) { ?>
            <p class="mb-0"><i class="fas fa-check-circle text-success me-1"></i>Nothing is quarantined.</p>
        <?php } else { ?>
        <div class="table-responsive-sm">
            <table class="table table-sm table-striped table-borderless">
                <thead class="text-dark"><tr><th>Mailbox</th><th>From</th><th>Subject</th><th>Attempts</th><th>Last error</th><th>Since</th><th class="text-center">Action</th></tr></thead>
                <tbody>
                <?php while ($qrow = mysqli_fetch_assoc($quarantine_sql)) { $q_id = intval($qrow['intake_id']); ?>
                    <tr>
                        <td><?= nullable_htmlentities($qrow['mailbox_email'] ?: $qrow['mailbox_name']) ?></td>
                        <td><?= nullable_htmlentities($qrow['intake_from_email']) ?></td>
                        <td><?= nullable_htmlentities($qrow['intake_subject']) ?></td>
                        <td><?= intval($qrow['intake_attempts']) ?></td>
                        <td class="small"><?= nullable_htmlentities(mb_strimwidth((string) $qrow['intake_last_error'], 0, 140, '...')) ?></td>
                        <td><?= nullable_htmlentities($qrow['intake_quarantined_at']) ?></td>
                        <td class="text-center" style="white-space:nowrap;">
                            <a href="post.php?reset_intake_attempts=<?= $q_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-xs btn-secondary" title="Give it a fresh set of attempts"><i class="fas fa-redo"></i> Reset</a>
                            <a href="post.php?dismiss_intake=<?= $q_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-xs btn-danger confirm-link ms-1"><i class="fas fa-times"></i> Dismiss</a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-sliders-h me-2"></i>Mail intake settings</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="row">
                <?php
                $intake_fields = [
                    ['rate_cap_per_hour', 'Messages per sender per hour', 'Over this a sender is quarantined as a mail request (loop/flood protection).'],
                    ['poison_max_attempts', 'Failures before quarantine', 'A message that fails this many polls is set aside.'],
                    ['max_attachment_mb', 'Max attachment size (MB)', 'Per file. Larger files are not imported; the ticket says so.'],
                    ['max_message_attachments_mb', 'Max attachments per message (MB)', 'Total across all files of one message.'],
                    ['inline_cid_max_kb', 'Inline image cap (KB)', 'Larger inline images become attachments instead of being embedded. 0 = no cap.'],
                    ['outbound_rate_per_min', 'Outbound emails per minute', 'The mail queue sends at most this many per minute.'],
                    ['mailbox_fail_threshold', 'Failed polls before alert', 'Consecutive failures before "Mailbox unreachable".'],
                    ['poller_silent_minutes', 'Poller silent after (minutes)', 'Alert when an active mailbox has not been polled for this long.'],
                    ['alert_dedupe_hours', 'Repeat an alert at most every (hours)', 'Per problem.'],
                ];
                foreach ($intake_fields as [$f_key, $f_label, $f_help]) { ?>
                <div class="col-md-4 mb-3">
                    <label class="form-label"><?= $f_label ?></label>
                    <input type="number" class="form-control" name="<?= $f_key ?>" min="<?= \ITFlow\Mail\MailSettings::NUMERIC[$f_key][0] ?>" max="<?= \ITFlow\Mail\MailSettings::NUMERIC[$f_key][1] ?>" value="<?= intval($intake_settings[$f_key]) ?>">
                    <div class="form-text"><?= $f_help ?></div>
                </div>
                <?php } ?>
                <div class="col-md-8 mb-3">
                    <label class="form-label">Alert email address(es)</label>
                    <input type="text" class="form-control" name="alert_email" maxlength="250" value="<?= nullable_htmlentities($intake_settings['alert_email']) ?>" placeholder="Leave blank to email all administrators">
                    <div class="form-text">Comma separated. Alerts always appear as notifications too.</div>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Virus scan attachments</label>
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="clamav_enabled" value="1" id="clamavSwitch" <?= intval($intake_settings['clamav_enabled']) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="clamavSwitch">Use ClamAV (<?= \ITFlow\Mail\ClamScanner::binary() ? 'clamdscan found' : '<span class="text-danger">clamdscan not installed</span>' ?>)</label>
                    </div>
                </div>
            </div>
            <button type="submit" name="save_mail_intake_settings" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
        </form>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
