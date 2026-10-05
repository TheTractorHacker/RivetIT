<?php
require_once "includes/inc_all_admin.php";
require_once "includes/webhook_events.php";
?>

<style nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    .webhook-url { max-width: 20rem; overflow-wrap: anywhere; }
    .webhook-events { min-width: 12rem; }
    .webhook-events .badge { margin: .125rem .25rem .125rem 0; font-weight: 500; }
    .webhook-actions { white-space: nowrap; }
</style>

<div class="card">
    <div class="card-header py-3 d-flex align-items-center gap-3">
        <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-satellite-dish me-2"></i>Webhooks</h3>
        <button class="btn btn-primary btn-sm ajax-modal" data-modal-url="modals/webhook/webhook_add.php">
            <i class="fas fa-plus me-1"></i>Add Webhook
        </button>
    </div>
    <div class="card-body pb-0">
        <p class="text-muted mb-3">Send selected events to another service. Delivery counts show the last seven days.</p>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-striped table-borderless table-hover align-middle mb-0">
            <thead class="text-dark">
                <tr>
                    <th>Name</th>
                    <th>URL</th>
                    <th>Events</th>
                    <th>Status</th>
                    <th>Recent Deliveries</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sql_wh = mysqli_query($mysqli, "SELECT w.*,
                    COALESCE(d.delivered, 0) + COALESCE(q.delivered, 0) AS delivered,
                    COALESCE(d.failed, 0) + COALESCE(q.failed, 0) AS failed,
                    COALESCE(j.pending, 0) + COALESCE(q.pending, 0) AS pending
                FROM webhooks w
                LEFT JOIN (
                    SELECT webhook_id,
                        SUM(http_status BETWEEN 200 AND 299) AS delivered,
                        SUM(http_status IS NULL OR http_status NOT BETWEEN 200 AND 299) AS failed
                    FROM webhook_deliveries
                    WHERE created_at > NOW() - INTERVAL 7 DAY
                    GROUP BY webhook_id
                ) d ON d.webhook_id = w.webhook_id
                LEFT JOIN (
                    SELECT CAST(JSON_VALUE(payload, '$.webhook_id') AS UNSIGNED) AS wid, COUNT(*) AS pending
                    FROM integration_jobs
                    WHERE job_type = 'webhook.deliver' AND status IN ('pending', 'running')
                    GROUP BY wid
                ) j ON j.wid = w.webhook_id
                LEFT JOIN (
                    SELECT queue_webhook_id,
                        SUM(queue_status = 'delivered') AS delivered,
                        SUM(queue_status = 'failed') AS failed,
                        SUM(queue_status = 'pending') AS pending
                    FROM webhook_queue
                    WHERE queue_created_at > NOW() - INTERVAL 7 DAY
                    GROUP BY queue_webhook_id
                ) q ON q.queue_webhook_id = w.webhook_id
                ORDER BY w.webhook_id ASC");
            if (mysqli_num_rows($sql_wh) == 0) { ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No webhooks yet. Use Add Webhook to connect a service.</td></tr>
            <?php } else {
                while ($wh = mysqli_fetch_assoc($sql_wh)) {
                    $wid     = intval($wh['webhook_id']);
                    $wname   = nullable_htmlentities($wh['webhook_name']);
                    $wurl    = nullable_htmlentities($wh['webhook_url']);
                    $wenabled = intval($wh['webhook_enabled']);
                    $wevents = array_filter(array_map('trim', explode(',', $wh['webhook_events'])));

                    $delivered = intval($wh['delivered']);
                    $failed    = intval($wh['failed']);
                    $pending   = intval($wh['pending']);
                    ?>
                    <tr>
                        <td><strong><?= $wname ?></strong></td>
                        <td class="webhook-url" title="<?= $wurl ?>"><?= $wurl ?></td>
                        <td class="webhook-events">
                            <?php foreach ($wevents as $ev) {
                                echo '<span class="badge text-bg-secondary me-1">' . htmlspecialchars($ev) . '</span>';
                            } ?>
                        </td>
                        <td>
                            <?= $wenabled
                                ? '<span class="badge text-bg-success">Enabled</span>'
                                : '<span class="badge text-bg-secondary">Disabled</span>' ?>
                        </td>
                        <td>
                            <span class="badge text-bg-success" title="Delivered (7d)"><?= $delivered ?></span>
                            <?php if ($pending > 0) { ?><span class="badge text-bg-warning" title="Pending"><?= $pending ?></span><?php } ?>
                            <?php if ($failed > 0) { ?><span class="badge text-bg-danger" title="Failed"><?= $failed ?></span><?php } ?>
                        </td>
                        <td class="text-end webhook-actions">
                            <button class="btn btn-sm btn-light ajax-modal"
                                    data-modal-url="modals/webhook/webhook_edit.php?id=<?= $wid ?>" aria-label="Edit <?= $wname ?>" title="Edit webhook">
                                <i class="fas fa-edit"></i>
                            </button>
                            <a href="post.php?delete_webhook=<?= $wid ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                               class="btn btn-sm btn-outline-danger confirm-link" aria-label="Delete <?= $wname ?>" title="Delete webhook">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php }
            } ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php
$legacy_queue_rows = (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM webhook_queue"))[0] ?? 0);
if (isset($sql_wh) && mysqli_num_rows($sql_wh) > 0 && $legacy_queue_rows > 0) { ?>
<div class="card mt-3">
    <div class="card-header py-3">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-list me-2"></i>Earlier queued deliveries</h3>
    </div>
    <p class="text-muted small px-3 pt-3 mb-2">Ticket events queued before deliveries moved to the job queue; they finish sending and then this list stops growing. Showing the latest 100.</p>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-striped table-borderless mb-0">
            <thead class="text-dark">
                <tr><th>When</th><th>Webhook</th><th>Event</th><th>Status</th><th>HTTP</th><th>Attempts</th></tr>
            </thead>
            <tbody>
            <?php
            $sql_log = mysqli_query($mysqli,
                "SELECT wq.*, w.webhook_name FROM webhook_queue wq
                 JOIN webhooks w ON wq.queue_webhook_id = w.webhook_id
                 ORDER BY wq.queue_id DESC LIMIT 100");
            if (mysqli_num_rows($sql_log) == 0) { ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No queued deliveries yet.</td></tr>
            <?php }
            while ($lrow = mysqli_fetch_assoc($sql_log)) {
                $status_badge = match($lrow['queue_status']) {
                    'delivered' => '<span class="badge text-bg-success">delivered</span>',
                    'failed'    => '<span class="badge text-bg-danger">failed</span>',
                    default     => '<span class="badge text-bg-warning">pending</span>',
                };
                ?>
                <tr>
                    <td class="text-nowrap text-secondary" title="<?= nullable_htmlentities($lrow['queue_created_at']) ?>"><?= timeAgo($lrow['queue_created_at']) ?></td>
                    <td><?= nullable_htmlentities($lrow['webhook_name']) ?></td>
                    <td><code><?= nullable_htmlentities($lrow['queue_event']) ?></code></td>
                    <td><?= $status_badge ?></td>
                    <td><?= $lrow['queue_response_code'] ?: '—' ?></td>
                    <td><?= intval($lrow['queue_attempts']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php } ?>

<?php if (isset($sql_wh) && mysqli_num_rows($sql_wh) > 0) { ?>
<div class="card mt-3">
    <div class="card-header py-3">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-bolt me-2"></i>Deliveries</h3>
    </div>
    <p class="text-muted small px-3 pt-3 mb-2">Every attempt, including retries (a failed delivery is retried after 1, 5, 30 and 120 minutes, then set aside as failed; see the Job queue page). Showing the latest 100.</p>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-striped table-borderless mb-0">
            <thead class="text-dark">
                <tr><th>When</th><th>Webhook</th><th>Event</th><th>Attempt</th><th>HTTP</th><th>Duration</th><th>Response</th></tr>
            </thead>
            <tbody>
            <?php
            $sql_direct = mysqli_query($mysqli,
                "SELECT wd.*, w.webhook_name FROM webhook_deliveries wd
                 JOIN webhooks w ON wd.webhook_id = w.webhook_id
                 ORDER BY wd.delivery_id DESC LIMIT 100");
            if (mysqli_num_rows($sql_direct) == 0) { ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No deliveries yet.</td></tr>
            <?php } else {
                while ($drow = mysqli_fetch_assoc($sql_direct)) {
                    $http = intval($drow['http_status']);
                    $http_badge = $http >= 200 && $http < 300
                        ? '<span class="badge text-bg-success">' . $http . '</span>'
                        : ($http > 0 ? '<span class="badge text-bg-danger">' . $http . '</span>' : '<span class="badge text-bg-danger">no response</span>');
                    ?>
                    <tr>
                        <td class="text-nowrap text-secondary" title="<?= nullable_htmlentities($drow['created_at']) ?>"><?= timeAgo($drow['created_at']) ?></td>
                        <td><?= nullable_htmlentities($drow['webhook_name']) ?></td>
                        <td><code><?= nullable_htmlentities($drow['event_type']) ?></code></td>
                        <td><?= intval($drow['attempt_number']) ?></td>
                        <td><?= $http_badge ?></td>
                        <td><?= intval($drow['duration_ms']) ?> ms</td>
                        <td class="text-truncate" style="max-width:260px;" title="<?= nullable_htmlentities($drow['response_body_snippet']) ?>"><?= nullable_htmlentities($drow['response_body_snippet']) ?></td>
                    </tr>
                <?php }
            } ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php"; ?>
