<?php

// Default Column Sortby Filter
$sort = "mail_log_id";
$order = "DESC";

require_once "includes/inc_all_admin.php";

$outcome_labels = [
    'ticket_created' => ['label' => 'Ticket Created', 'badge' => 'text-bg-success'],
    'reply_added'    => ['label' => 'Reply Added',    'badge' => 'text-bg-info'],
    'mail_request'   => ['label' => 'Queued as Request', 'badge' => 'text-bg-primary'],
    'ndr'            => ['label' => 'Bounce (NDR)',   'badge' => 'text-bg-warning'],
    'ignored'        => ['label' => 'Ignored',        'badge' => 'text-bg-secondary'],
];

// Outcome Filter
if (isset($_GET['outcome']) && !empty($_GET['outcome'])) {
    $outcome_query = "AND (mail_log_outcome = '" . sanitizeInput($_GET['outcome']) . "')";
    $outcome_filter = nullable_htmlentities($_GET['outcome']);
} else {
    $outcome_query = '';
    $outcome_filter = '';
}

// Mailbox Filter
if (isset($_GET['mailbox_id']) && !empty($_GET['mailbox_id'])) {
    $mailbox_query = "AND (mail_log_mailbox_id = " . intval($_GET['mailbox_id']) . ")";
    $mailbox_filter = intval($_GET['mailbox_id']);
} else {
    $mailbox_query = '';
    $mailbox_filter = '';
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS ml.*, m.mailbox_name, m.mailbox_email
    FROM mail_log ml
    LEFT JOIN mailboxes m ON m.mailbox_id = ml.mail_log_mailbox_id
    WHERE (ml.mail_log_from_email LIKE '%$q%' OR ml.mail_log_from_name LIKE '%$q%' OR ml.mail_log_subject LIKE '%$q%' OR ml.mail_log_detail LIKE '%$q%')
    AND DATE(ml.mail_log_created_at) BETWEEN '$dtf' AND '$dtt'
    $outcome_query
    $mailbox_query
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-inbox me-2"></i>Email Log</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">Every email the mailbox poller has touched, and what happened to it. Mailbox connection failures are in <a href="app_log.php?category=Cron-Email-Parser">App Logs</a> instead.</p>
            <hr>
            <form autocomplete="off">
                <div class="row">
                    <div class="col-sm-4">
                        <div class="form-group">
                            <div class="input-group">
                                <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search from, subject, or detail">
                                <div class="input-group-append">
                                    <button class="btn btn-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                    <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-sm-3">
                        <div class="form-group">
                            <select class="form-control select2 auto-submit-select" name="outcome">
                                <option value="">- All Outcomes -</option>
                                <?php foreach ($outcome_labels as $key => $info) { ?>
                                    <option value="<?= $key ?>" <?php if ($outcome_filter === $key) { echo "selected"; } ?>><?= $info['label'] ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-sm-3">
                        <div class="form-group">
                            <select class="form-control select2 auto-submit-select" name="mailbox_id">
                                <option value="">- All Mailboxes -</option>
                                <?php
                                $sql_mailboxes_filter = mysqli_query($mysqli, "SELECT mailbox_id, mailbox_name FROM mailboxes WHERE mailbox_archived_at IS NULL ORDER BY mailbox_name ASC");
                                while ($mb_row = mysqli_fetch_assoc($sql_mailboxes_filter)) {
                                    $mb_id = intval($mb_row['mailbox_id']);
                                ?>
                                    <option value="<?= $mb_id ?>" <?php if ($mailbox_filter === $mb_id) { echo "selected"; } ?>><?= nullable_htmlentities($mb_row['mailbox_name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="collapse mt-3 <?php if (isset($_GET['dtf']) && $_GET['dtf'] !== '1970-01-01') { echo "show"; } ?>" id="advancedFilter">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Date range</label>
                                <input type="text" id="dateFilter" class="form-control" autocomplete="off">
                                <input type="hidden" name="canned_date" id="canned_date" value="<?php echo nullable_htmlentities($_GET['canned_date']) ?? ''; ?>">
                                <input type="hidden" name="dtf" id="dtf" value="<?php echo nullable_htmlentities($dtf ?? ''); ?>">
                                <input type="hidden" name="dtt" id="dtt" value="<?php echo nullable_htmlentities($dtt ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <hr>
            <div class="table-responsive-sm">
                <table class="table table-sm table-striped table-borderless table-hover">
                    <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                    <tr>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=mail_log_created_at&order=<?php echo $disp; ?>">
                                Timestamp <?php if ($sort == 'mail_log_created_at') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>Mailbox</th>
                        <th>From</th>
                        <th>Subject</th>
                        <th>Outcome</th>
                        <th>Detail</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    while ($row = mysqli_fetch_assoc($sql)) {
                        $outcome = $row['mail_log_outcome'];
                        $outcome_info = $outcome_labels[$outcome] ?? ['label' => $outcome, 'badge' => 'text-bg-secondary'];
                        $ticket_id = intval($row['mail_log_ticket_id']);
                    ?>
                        <tr>
                            <td class="text-nowrap"><?php echo nullable_htmlentities($row['mail_log_created_at']); ?></td>
                            <td><?php echo nullable_htmlentities($row['mailbox_name'] ?: $row['mailbox_email']) ?: '<span class="text-muted">&mdash;</span>'; ?></td>
                            <td>
                                <?php if ($row['mail_log_from_name']) { echo nullable_htmlentities($row['mail_log_from_name']) . ' '; } ?>
                                <span class="text-muted">&lt;<?php echo nullable_htmlentities($row['mail_log_from_email']); ?>&gt;</span>
                            </td>
                            <td><?php echo $row['mail_log_subject'] !== '' ? nullable_htmlentities($row['mail_log_subject']) : '<span class="text-muted">No Subject</span>'; ?></td>
                            <td><span class="badge <?php echo $outcome_info['badge']; ?>"><?php echo $outcome_info['label']; ?></span></td>
                            <td>
                                <?php echo nullable_htmlentities($row['mail_log_detail']); ?>
                                <?php if ($ticket_id) { ?>
                                    <a href="/agent/ticket.php?ticket_id=<?php echo $ticket_id; ?>" target="_blank">#<?php echo $ticket_id; ?></a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php require_once "../includes/filter_footer.php"; ?>
        </div>
    </div>

<?php
require_once "../includes/footer.php";
