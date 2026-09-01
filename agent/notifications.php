<?php

// Default Column Sortby Filter
$sort = "notification_timestamp";
$order = "DESC";

require_once "includes/inc_all.php";

// Dismissed Filter
if (isset($_GET['dismissed'])) {
    $dismissed_query = 'AND notification_dismissed_at IS NOT NULL';
    $dismissed_filter = 1;
} else {
    $dismissed_query = 'AND notification_dismissed_at IS NULL';
    $dismissed_filter = 0;
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM notifications
    LEFT JOIN clients ON notification_client_id = client_id
    WHERE (notification_type LIKE '%$q%' OR notification LIKE '%$q%')
    AND DATE(notification_timestamp) BETWEEN '$dtf' AND '$dtt'
    AND notification_user_id = $session_user_id
    $dismissed_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Icon map for notification types
function notif_icon(string $type): string {
    $map = [
        'Ticket'       => 'fa-ticket-alt text-primary',
        'Invoice'      => 'fa-file-invoice-dollar text-success',
        'Domain'       => 'fa-globe text-warning',
        'Certificate'  => 'fa-lock text-info',
        'Asset'        => 'fa-desktop text-secondary',
        'Shared Item'  => 'fa-share-alt text-purple',
        'Cron'         => 'fa-clock text-muted',
        'Update'       => 'fa-download text-info',
        'RMM'          => 'fa-server text-danger',
        'Payment'      => 'fa-dollar-sign text-success',
        'Contract'     => 'fa-file-contract text-primary',
    ];
    foreach ($map as $key => $icon) {
        if (stripos($type, $key) !== false) return $icon;
    }
    return 'fa-bell text-secondary';
}

// Push notification device status
$sql_push_devices = mysqli_query($mysqli, "SELECT COUNT(*) AS cnt FROM api_tokens WHERE token_user_id = $session_user_id AND token_fcm_token IS NOT NULL AND token_fcm_token != ''");
$push_device_count = intval(mysqli_fetch_assoc($sql_push_devices)['cnt']);
?>

<!-- Mobile Push Notifications card -->
<div class="card card-primary card-outline mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-mobile-alt me-2"></i>Mobile App Push Notifications</h3>
    </div>
    <div class="card-body">
        <?php if ($push_device_count > 0) { ?>
        <div class="d-flex align-items-center flex-wrap" style="gap:1rem;">
            <span class="text-success"><i class="fas fa-check-circle me-1"></i>
                <strong><?= $push_device_count ?></strong> registered device<?= $push_device_count !== 1 ? 's' : '' ?>
            </span>
            <form method="POST" action="post.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" name="test_push_notification" class="btn btn-sm btn-primary">
                    <i class="fas fa-paper-plane me-1"></i>Send Test Push
                </button>
            </form>
        </div>
        <?php } else { ?>
        <div class="text-muted">
            <i class="fas fa-exclamation-circle me-1 text-warning"></i>
            No mobile devices registered. Log in to the <strong>ITFlow mobile app</strong> to register your device for push notifications.
        </div>
        <?php } ?>
    </div>
</div>


<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mt-1 mr-auto">
            <i class="fas fa-fw fa-bell me-2"></i><?php if ($dismissed_filter) echo "Dismissed "; ?>Notifications
            <?php if ($num_rows[0] > 0) { ?><span class="badge text-bg-secondary ms-2"><?= $num_rows[0] ?></span><?php } ?>
        </h3>
        <div class="d-flex align-items-center" style="gap:.5rem;">
            <?php if (!$dismissed_filter && $num_rows[0] > 0) { ?>
            <a href="post.php?dismiss_all_notifications&csrf_token=<?= $_SESSION['csrf_token'] ?>"
               class="btn btn-sm btn-outline-secondary confirm-link" title="Dismiss All">
                <i class="fas fa-check-double me-1"></i>Dismiss All
            </a>
            <?php } ?>
            <?php if ($dismissed_filter) { ?>
            <a href="notifications.php" class="btn btn-sm btn-primary"><i class="fas fa-bell me-1"></i>Active</a>
            <?php } else { ?>
            <a href="notifications.php?dismissed" class="btn btn-sm btn-outline-secondary"><i class="fas fa-history me-1"></i>Dismissed</a>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <!-- Search bar -->
        <form class="mb-3" autocomplete="off">
            <?php if ($dismissed_filter) { ?><input type="hidden" name="dismissed" value=""><?php } ?>
            <div class="input-group" style="max-width:440px;">
                <input type="search" class="form-control" name="q"
                       value="<?php if (isset($q)) echo stripslashes(nullable_htmlentities($q)); ?>"
                       placeholder="Search notifications…">
                <div class="input-group-append">
                    <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                    <button class="btn btn-outline-secondary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#advancedFilter">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </div>
            <div class="collapse mt-2 <?php if (!empty($_GET['dtf'])) echo 'show'; ?>" id="advancedFilter">
                <div class="d-flex" style="gap:.75rem;">
                    <div>
                        <label class="small text-muted mb-1">From</label>
                        <input type="date" class="form-control form-control-sm" name="dtf" max="2999-12-31" value="<?= nullable_htmlentities($dtf) ?>">
                    </div>
                    <div>
                        <label class="small text-muted mb-1">To</label>
                        <input type="date" class="form-control form-control-sm" name="dtt" max="2999-12-31" value="<?= nullable_htmlentities($dtt) ?>">
                    </div>
                </div>
            </div>
        </form>

        <?php if ($num_rows[0] == 0) { ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-bell-slash fa-3x mb-3 d-block"></i>
            <?= $dismissed_filter ? 'No dismissed notifications.' : 'All caught up — no notifications.' ?>
        </div>
        <?php } else { ?>
        <div class="notif-feed">
        <?php while ($row = mysqli_fetch_assoc($sql)) {
            $notification_id      = intval($row['notification_id']);
            $notification_ts      = nullable_htmlentities($row['notification_timestamp']);
            $notification_type    = nullable_htmlentities($row['notification_type']);
            $notification         = nullable_htmlentities($row['notification']);
            $notification_dismissed_at = nullable_htmlentities($row['notification_dismissed_at']);
            $client_name          = nullable_htmlentities($row['client_name']);
            $client_id            = intval($row['client_id']);
            $icon_class           = notif_icon($notification_type);
            $ago                  = timeAgo($row['notification_timestamp']);
        ?>
        <div class="d-flex align-items-start py-3 border-bottom notif-item">
            <!-- Icon -->
            <div class="me-3 mt-1">
                <span class="notif-icon-circle">
                    <i class="fas fa-fw <?= $icon_class ?>"></i>
                </span>
            </div>
            <!-- Content -->
            <div class="flex-grow-1 min-width-0">
                <div class="d-flex align-items-center flex-wrap mb-1" style="gap:.4rem;">
                    <span class="badge text-bg-secondary rounded-pill" style="font-size:.7rem;"><?= $notification_type ?></span>
                    <?php if ($client_name) { ?>
                    <a href="client_overview.php?client_id=<?= $client_id ?>" class="text-muted small">
                        <i class="fas fa-building me-1"></i><?= $client_name ?>
                    </a>
                    <?php } ?>
                </div>
                <div class="mb-1"><?= $notification ?></div>
                <div class="small text-muted" title="<?= $notification_ts ?>">
                    <i class="far fa-clock me-1"></i><?= $ago ?>
                    <?php if ($dismissed_filter && $notification_dismissed_at) { ?>
                    &nbsp;&middot;&nbsp;<i class="fas fa-check me-1"></i>Dismissed <?= timeAgo($notification_dismissed_at) ?>
                    <?php } ?>
                </div>
            </div>
            <!-- Action -->
            <?php if (!$dismissed_filter) { ?>
            <div class="ms-3 flex-shrink-0 d-flex" style="gap:.35rem;">
                <a href="post.php?push_notification=<?= $notification_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                   class="btn btn-sm btn-outline-primary" title="Send as Push">
                    <i class="fas fa-mobile-alt"></i>
                </a>
                <a href="post.php?dismiss_notification=<?= $notification_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                   class="btn btn-sm btn-outline-secondary" title="Dismiss">
                    <i class="fas fa-check"></i>
                </a>
            </div>
            <?php } ?>
        </div>
        <?php } ?>
        </div>
        <?php } ?>

        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<style>
.notif-icon-circle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.2rem;
    height: 2.2rem;
    border-radius: 50%;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
}
.notif-item:last-child { border-bottom: 0 !important; }
.notif-feed { margin: -.75rem; padding: .75rem; }
</style>

<?php require_once "../includes/footer.php"; ?>
