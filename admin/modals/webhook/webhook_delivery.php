<?php
require_once '../../../includes/modal_header.php';

use ITFlow\Webhooks\DestinationConfig;
use ITFlow\Webhooks\WebhookTester;

// One logged delivery: what was sent (secrets masked), the answer, and Replay where the stored body allows it.
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$delivery_id = intval($_GET['id'] ?? 0);
$dl = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT wd.*, w.webhook_name, w.webhook_type, w.webhook_destination, w.webhook_format FROM webhook_deliveries wd LEFT JOIN webhooks w ON w.webhook_id = wd.webhook_id WHERE wd.delivery_id = $delivery_id LIMIT 1"));
if (!$dl) {
    http_response_code(404);
    echo json_encode(['error' => 'Delivery not found.']);
    exit;
}
$wh = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = " . intval($dl['webhook_id']) . " LIMIT 1")) ?: null;
$plan = $wh ? WebhookTester::replayable($dl, $wh) : null;
$http = intval($dl['http_status']);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-bolt me-2"></i>Delivery #<?= $delivery_id ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="delivery_id" value="<?= $delivery_id ?>">
    <div class="modal-body">
        <dl class="row small mb-3">
            <dt class="col-sm-3">Webhook</dt><dd class="col-sm-9"><?= $h($dl['webhook_name'] ?? '(deleted)') ?></dd>
            <dt class="col-sm-3">Event</dt><dd class="col-sm-9"><code><?= $h($dl['event_type']) ?></code></dd>
            <dt class="col-sm-3">When</dt><dd class="col-sm-9"><?= $h($dl['created_at']) ?> (attempt <?= intval($dl['attempt_number']) ?>)</dd>
            <dt class="col-sm-3">Result</dt><dd class="col-sm-9"><?= $http > 0 ? 'HTTP ' . $http : 'no response' ?>, <?= intval($dl['duration_ms']) ?> ms</dd>
            <dt class="col-sm-3">Response</dt><dd class="col-sm-9 text-break"><?= $h(mb_substr((string) $dl['response_body_snippet'], 0, 300)) ?: '<span class="text-secondary">(none)</span>' ?></dd>
        </dl>
        <div class="fw-bold small mb-1">Payload sent <span class="text-secondary fw-normal">(secret-looking values masked)</span></div>
        <pre class="wh-code" tabindex="0"><?= $h(($dl['request_payload_json'] ?? '') !== '' ? WebhookTester::redactBody((string) $dl['request_payload_json']) : '(not stored)') ?></pre>
        <p class="small text-secondary mb-0"><?= $plan ? 'Replay sends this event again now, through the same delivery path, with a new timestamp and signature.' : 'This delivery cannot be replayed: only deliveries with the standard JSON body (and test sends) can be rebuilt from the log. Use Send test on the webhook instead.' ?></p>
    </div>
    <div class="modal-footer">
        <?php if ($plan) { ?><button type="submit" name="replay_webhook_delivery" class="btn btn-primary"><i class="fas fa-redo me-1"></i>Replay</button><?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
