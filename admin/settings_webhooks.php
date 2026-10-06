<?php
require_once "includes/inc_all_admin.php";
require_once "includes/webhook_events.php";
require_once "../includes/event_bus.php";
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
        <a href="settings_webhook_guides.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-book me-1"></i>Guides</a>
        <button class="btn btn-primary btn-sm ajax-modal" data-modal-url="modals/webhook/webhook_add.php" data-modal-size="xl">
            <i class="fas fa-plus me-1"></i>Add Webhook
        </button>
    </div>
    <ul class="nav nav-tabs px-3" role="tablist">
        <li class="nav-item"><a class="nav-link active" aria-current="page" href="settings_webhooks.php">Webhooks</a></li>
        <li class="nav-item"><a class="nav-link" href="settings_webhook_guides.php">Guides</a></li>
    </ul>
    <div class="card-body pb-0">
        <p class="text-muted mb-3 pt-3">Send selected events to another service: n8n, Node-RED, ntfy, Discord, Telegram, Slack, Microsoft Teams, Home Assistant and more, or any endpoint as signed JSON or your own template. Pick a platform in <strong>Add Webhook</strong>; each one comes with its own setup guide. Delivery counts show the last seven days.</p>
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
                    $wtype   = \ITFlow\Webhooks\ChatFormatter::normalizeType($wh['webhook_type'] ?? '');
                    $wchat   = \ITFlow\Webhooks\ChatFormatter::isChatType($wtype);
                    $wdest   = \ITFlow\Webhooks\DestinationConfig::destination($wh);
                    // A secret URL (every preset except the generic ones, and Slack / Teams) is stored encrypted: only the host is ever shown.
                    $wurl_plain = decryptSetting((string) $wh['webhook_url']);
                    $wsecret = $wchat || ($wdest !== null && \ITFlow\Webhooks\DestinationConfig::urlIsSecret($wdest->id));
                    $wurl    = $wsecret ? nullable_htmlentities(\ITFlow\Webhooks\ChatDelivery::maskUrl($wurl_plain)) : nullable_htmlentities($wh['webhook_url']);
                    $wenabled = intval($wh['webhook_enabled']);
                    $wevents = array_filter(array_map('trim', explode(',', $wh['webhook_events'])));
                    $wrouted = $wh['webhook_min_priority'] !== '' || $wh['webhook_client_ids'] !== '';

                    $delivered = intval($wh['delivered']);
                    $failed    = intval($wh['failed']);
                    $pending   = intval($wh['pending']);
                    ?>
                    <tr>
                        <td><strong><?= $wname ?></strong>
                            <?php if ($wdest !== null) { ?><span class="badge text-bg-info ms-1"><?= nullable_htmlentities($wdest->name) ?></span><?php } elseif ($wchat) { ?><span class="badge text-bg-info ms-1"><?= $wtype === 'slack' ? 'Slack' : 'Teams' ?></span><?php } ?>
                            <?php if ($wrouted) { ?>
                                <div class="small text-secondary"><?= $wh['webhook_min_priority'] !== '' ? nullable_htmlentities($wh['webhook_min_priority']) . '+ ' : '' ?><?= $wh['webhook_client_ids'] !== '' ? count(array_filter(explode(',', $wh['webhook_client_ids']))) . ' client filter' : '' ?></div>
                            <?php } ?>
                        </td>
                        <td class="webhook-url" title="<?= $wurl ?>"><?= $wurl ?></td>
                        <td class="webhook-events">
                            <?php foreach (array_slice($wevents, 0, 8) as $ev) {
                                echo '<span class="badge ' . (str_contains($ev, '*') ? 'text-bg-primary' : 'text-bg-secondary') . ' me-1">' . htmlspecialchars($ev === '*' ? 'All events' : $ev) . '</span>';
                            }
                            if (count($wevents) > 8) { echo '<span class="badge text-bg-light me-1" title="' . htmlspecialchars(implode(', ', array_slice($wevents, 8))) . '">+' . (count($wevents) - 8) . ' more</span>'; } ?>
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
                            <form action="post.php" method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="webhook_id" value="<?= $wid ?>">
                                <button type="submit" name="test_webhook" class="btn btn-sm btn-light" aria-label="Send a test to <?= $wname ?>" title="Send a test event now"><i class="fas fa-paper-plane"></i></button>
                            </form>
                            <button class="btn btn-sm btn-light ajax-modal" data-modal-size="xl"
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
$slack_cfg = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_slack_link_by_email, config_slack_bot_token, config_slack_team_id FROM settings WHERE company_id = 1")) ?: [];
$slack_base = trim((string) ($config_base_url ?? ''));
$slack_endpoint = ($slack_base !== '' ? (preg_match('#^https?://#i', $slack_base) ? rtrim($slack_base, '/') : 'https://' . rtrim($slack_base, '/')) : 'https://your-rivetit-host') . '/slack_interactive.php';
?>
<div class="card mt-3">
    <div class="card-header py-3">
        <h3 class="card-title mb-0"><i class="fab fa-fw fa-slack me-2"></i>Slack interactive actions</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            A Slack destination that has a <strong>Signing Secret</strong> (Edit webhook) gets <em>Acknowledge</em> and <em>Assign to me</em> buttons on ticket messages.
            In your Slack app, turn on <strong>Interactivity</strong> and set its Request URL to
            <code><?= nullable_htmlentities($slack_endpoint) ?></code>. Requests are verified with that Signing Secret (5 minute window, single use).
            Teams cannot do this (Teams cards need a Bot Framework app), see docs/SLACK_TEAMS_SETUP.md.
        </p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-check form-switch mb-1">
                <input type="checkbox" class="form-check-input" name="config_slack_link_by_email" value="1" id="slackLinkByEmail" <?= !empty($slack_cfg['config_slack_link_by_email']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="slackLinkByEmail">Match Slack users to agents by their confirmed Slack email address</label>
            </div>
            <small class="text-muted d-block mb-3">
                <strong>Off by default.</strong> While off, a click answers "your Slack account isn't linked" and changes nothing. When on, RivetIT asks Slack
                (<code>users.info</code>, bot token below) for the clicker's email and only acts when Slack marks it <em>confirmed</em>, the person is not a bot or guest,
                and exactly one active agent has that email, with the same role and department rights as in the web app. Anyone who can change their Slack email
                to an agent's address in a workspace that does NOT confirm emails could pose as that agent, so only turn this on for a workspace you control.
            </small>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label>Slack bot token <small class="text-secondary">(needs the <code>users:read</code> and <code>users:read.email</code> scopes)</small></label>
                    <input type="password" class="form-control font-monospace" name="config_slack_bot_token" autocomplete="new-password" placeholder="<?= !empty($slack_cfg['config_slack_bot_token']) ? 'Stored encrypted - leave blank to keep' : 'xoxb-...' ?>">
                    <?php if (!empty($slack_cfg['config_slack_bot_token'])) { ?>
                    <div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="config_slack_bot_token_clear" value="1" id="slackTokenClear"><label class="form-check-label small" for="slackTokenClear">Remove the stored token</label></div>
                    <?php } ?>
                </div>
                <div class="col-md-6 form-group">
                    <label>Slack workspace (team) ID <small class="text-secondary">(recommended; starts with T)</small></label>
                    <input type="text" class="form-control font-monospace" name="config_slack_team_id" maxlength="32" value="<?= nullable_htmlentities($slack_cfg['config_slack_team_id'] ?? '') ?>" placeholder="T0123ABCD">
                </div>
            </div>
            <button type="submit" name="save_slack_interactive" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save</button>
        </form>
    </div>
</div>

<?php
$wh_nets = rivetWebhookAllowedNetworks($mysqli);
$wh_nets_text = $_SESSION['webhook_networks_draft'] ?? implode("\n", $wh_nets);
unset($_SESSION['webhook_networks_draft']);
$wh_detect = isset($_GET['detect']);
$wh_detected = [];
if ($wh_detect) {
    foreach (\RivetCore\Support\LocalNetworks::detect() as $d) {
        $wh_detected[$d['cidr']] = $d;
    }
}
?>
<div class="card mt-3" id="internal-networks">
    <div class="card-header py-3">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-network-wired me-2"></i>Internal network access</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">Webhooks may only call public addresses plus the internal networks listed here. Loopback (127.0.0.0/8), link-local (169.254.0.0/16) and cloud-metadata addresses are never allowed, and only private ranges (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7) can be listed.</p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <label class="form-label" for="webhook_allowed_networks">Allowed internal networks (one per line, CIDR)</label>
            <textarea class="form-control font-monospace mb-2" id="webhook_allowed_networks" name="webhook_allowed_networks" rows="4" placeholder="192.168.1.0/24"><?= nullable_htmlentities($wh_nets_text) ?></textarea>
            <button type="submit" name="save_webhook_networks" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save networks</button>
            <a href="settings_webhooks.php?detect=1#internal-networks" class="btn btn-outline-secondary btn-sm ms-2"><i class="fas fa-search me-1"></i>Use this server's network</a>
        </form>
        <?php if ($wh_detect) { ?>
            <div class="mt-3" id="detected-networks">
                <?php if (!$wh_detected) { ?>
                    <p class="text-muted mb-0">No private network was detected on this server.</p>
                <?php } else { ?>
                    <p class="mb-1">Detected on this server:</p>
                    <?php foreach ($wh_detected as $cidr => $d) { ?>
                        <form action="post.php" method="post" class="d-flex align-items-center gap-2 mb-1">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="network" value="<?= nullable_htmlentities($cidr) ?>">
                            <span class="text-muted"><?= nullable_htmlentities($d['interface']) ?></span>
                            <code><?= nullable_htmlentities($cidr) ?></code>
                            <?php if (in_array($cidr, $wh_nets, true)) { ?>
                                <span class="badge text-bg-success">Allowed</span>
                            <?php } else { ?>
                                <button type="submit" name="add_webhook_network" class="btn btn-sm btn-outline-primary">Add</button>
                            <?php } ?>
                        </form>
                    <?php } ?>
                <?php } ?>
            </div>
        <?php } ?>
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
                <tr><th>When</th><th>Webhook</th><th>Event</th><th>Attempt</th><th>HTTP</th><th>Duration</th><th>Response</th><th></th></tr>
            </thead>
            <tbody>
            <?php
            $sql_direct = mysqli_query($mysqli,
                "SELECT wd.delivery_id, wd.event_type, wd.http_status, wd.duration_ms, wd.attempt_number, wd.response_body_snippet, wd.created_at, w.webhook_name, w.webhook_type FROM webhook_deliveries wd
                 JOIN webhooks w ON wd.webhook_id = w.webhook_id
                 ORDER BY wd.delivery_id DESC LIMIT 100");
            if (mysqli_num_rows($sql_direct) == 0) { ?>
                <tr><td colspan="8" class="text-center text-muted py-3">No deliveries yet.</td></tr>
            <?php } else {
                while ($drow = mysqli_fetch_assoc($sql_direct)) {
                    $http = intval($drow['http_status']);
                    $http_badge = $http >= 200 && $http < 300
                        ? '<span class="badge text-bg-success">' . $http . '</span>'
                        : ($http > 0 ? '<span class="badge text-bg-danger">' . $http . '</span>' : '<span class="badge text-bg-danger">no response</span>');
                    ?>
                    <tr>
                        <td class="text-nowrap text-secondary" title="<?= nullable_htmlentities($drow['created_at']) ?>"><?= timeAgo($drow['created_at']) ?></td>
                        <td><?= nullable_htmlentities($drow['webhook_name']) ?>
                            <?php if (in_array($drow['webhook_type'], ['slack', 'teams'], true)) { ?><span class="badge text-bg-info"><?= $drow['webhook_type'] === 'slack' ? 'Slack' : 'Teams' ?></span><?php } ?></td>
                        <td><code><?= nullable_htmlentities($drow['event_type']) ?></code></td>
                        <td><?= intval($drow['attempt_number']) ?></td>
                        <td><?= $http_badge ?></td>
                        <td><?= intval($drow['duration_ms']) ?> ms</td>
                        <td class="text-truncate" style="max-width:260px;" title="<?= nullable_htmlentities($drow['response_body_snippet']) ?>"><?= nullable_htmlentities($drow['response_body_snippet']) ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-light ajax-modal" data-modal-size="lg" data-modal-url="modals/webhook/webhook_delivery.php?id=<?= intval($drow['delivery_id']) ?>" title="View the payload and replay it" aria-label="View delivery <?= intval($drow['delivery_id']) ?>"><i class="fas fa-eye"></i></button>
                        </td>
                    </tr>
                <?php }
            } ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php } ?>

<?php
// Deep link from the guides page (settings_webhooks.php?add=n8n): open the Add Webhook form on that platform.
$wh_add = (string) ($_GET['add'] ?? '');
if ($wh_add !== '' && \RivetCore\Webhooks\Destinations::has($wh_add)) { ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () { if (window.openAjaxModal) { window.openAjaxModal('modals/webhook/webhook_add.php?dest=<?= rawurlencode($wh_add) ?>', 'xl'); } });
</script>
<?php } ?>
<?php require_once "../includes/footer.php"; ?>
