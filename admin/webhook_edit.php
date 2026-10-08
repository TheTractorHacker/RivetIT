<?php
require_once "includes/inc_all_admin.php";
require_once "includes/webhook_events.php";
require_once "../includes/event_bus.php";
require_once "includes/webhook_form_parts.php";

use ITFlow\Webhooks\ChatDelivery;
use ITFlow\Webhooks\DestinationConfig;
use ITFlow\Webhooks\WebhookTester;
use RivetCore\Webhooks\Destinations;

// Edit webhook, in tabs: Connection | Events | Payload & advanced | Deliveries. The three form tabs are ONE form (one Save); Deliveries is a
// read-only log. A stored secret is never shown: its field says "Saved. Leave blank to keep it." (the handler keeps it when blank).
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$webhook_id = intval($_GET['id'] ?? 0);
$wh = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id LIMIT 1"));
if (!$wh) {
    flash_alert("Webhook not found.", 'error');
    header('Location: settings_webhooks.php');
    exit;
}
// The preset this webhook is (a pre-preset row is shown as its equivalent); ?dest= switches the form to another platform.
$current_dest = DestinationConfig::effectiveDestinationId($wh);
$d = Destinations::get((string) ($_GET['dest'] ?? '')) ?? Destinations::get($current_dest);
$ctx = webhookFormContext($d, $wh, true);
$tab = (string) ($_GET['tab'] ?? 'connection');
$tab = in_array($tab, ['connection', 'events', 'advanced', 'deliveries'], true) ? $tab : 'connection';
$asset = static fn (string $f): string => '/' . $f . '?v=' . (is_file(__DIR__ . '/../' . $f) ? filemtime(__DIR__ . '/../' . $f) : time());
[$fs, $fi] = webhookBrandIcon($d);
$enabled = !empty($wh['webhook_enabled']);

$last = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT http_status, created_at, event_type FROM webhook_deliveries WHERE webhook_id = $webhook_id ORDER BY delivery_id DESC LIMIT 1"));
$deliveries = [];
$dl_sql = mysqli_query($mysqli, "SELECT * FROM webhook_deliveries WHERE webhook_id = $webhook_id ORDER BY delivery_id DESC LIMIT 30");
while ($dl_sql && ($dr = mysqli_fetch_assoc($dl_sql))) {
    $deliveries[] = $dr;
}
$wh_events = $ctx['wh_events'];
?>
<link rel="stylesheet" href="<?= $asset('css/webhook_form.css') ?>">

<div class="wz wz-edit" data-wz data-wz-mode="edit" data-wz-initial-tab="<?= $h($tab) ?>" data-wz-dest="<?= $h($d->id) ?>" data-wz-dest-name="<?= $h($d->name) ?>" data-wz-id="<?= $webhook_id ?>"
     data-wz-action="modals/webhook/webhook_action.php" data-wz-guide="modals/webhook/webhook_guide.php" data-wz-list="settings_webhooks.php">
    <nav aria-label="Breadcrumb" class="mb-2"><a href="settings_webhooks.php"><i class="fas fa-fw fa-arrow-left me-1" aria-hidden="true"></i>All webhooks</a></nav>

    <div class="wz-head wz-edithead">
        <span class="wz-pcard-icon wz-pcard-icon-lg"><i class="<?= $h($fs . ' ' . $fi) ?>" aria-hidden="true"></i></span>
        <div class="me-auto min-w-0">
            <h1 class="wz-title text-truncate"><?= $h($wh['webhook_name']) ?></h1>
            <div class="wz-meta">
                <span class="badge text-bg-info"><?= $h($d->name) ?></span>
                <?php if (!$enabled) { ?><span class="badge text-bg-secondary" data-wz-statusbadge>Disabled</span>
                <?php } elseif (!$last) { ?><span class="badge text-bg-light" data-wz-statusbadge>No deliveries yet</span>
                <?php } elseif ((int) $last['http_status'] >= 200 && (int) $last['http_status'] < 300) { ?><span class="badge text-bg-success" data-wz-statusbadge title="<?= $h($last['created_at']) ?>">Last delivery OK, <?= $h(timeAgo($last['created_at'])) ?></span>
                <?php } else { ?><span class="badge text-bg-danger" data-wz-statusbadge title="<?= $h($last['created_at']) ?>">Last delivery failed<?= (int) $last['http_status'] > 0 ? ' (HTTP ' . (int) $last['http_status'] . ')' : '' ?>, <?= $h(timeAgo($last['created_at'])) ?></span>
                <?php } ?>
            </div>
        </div>
        <label class="wz-switch" title="Turn this webhook on or off. Saved straight away.">
            <input type="checkbox" class="form-check-input" form="wz-form" name="webhook_enabled" value="1" <?= $enabled ? 'checked' : '' ?> data-wz-toggle-live aria-label="Enabled">
            <span class="wz-switch-label" data-wz-switch-label><?= $enabled ? 'Enabled' : 'Disabled' ?></span>
        </label>
        <div class="wz-headbtns">
            <button type="button" class="btn btn-outline-primary btn-sm" data-wz-test><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Send test</button>
            <form action="post.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="webhook_id" value="<?= $webhook_id ?>">
                <button type="submit" name="duplicate_webhook" class="btn btn-outline-secondary btn-sm"><i class="far fa-clone me-1" aria-hidden="true"></i>Duplicate</button>
            </form>
            <a href="post.php?delete_webhook=<?= $webhook_id ?>&amp;csrf_token=<?= $h($_SESSION['csrf_token']) ?>" class="btn btn-outline-danger btn-sm confirm-link"><i class="fas fa-trash me-1" aria-hidden="true"></i>Delete</a>
        </div>
    </div>
    <div class="wz-result" data-wz-result hidden role="status" aria-live="polite"></div>
    <?php if ($d->id !== $current_dest) { ?>
    <div class="alert alert-warning py-2 mb-2">You are changing the platform from <?= $h(Destinations::get($current_dest)->name ?? $current_dest) ?> to <?= $h($d->name) ?>. This keeps the name and events; re-enter its URL, secrets and options before you save.</div>
    <?php } ?>

    <ul class="wz-tabs" role="tablist" aria-label="Webhook settings">
        <li role="presentation"><button type="button" class="wz-tab" role="tab" id="wz-tab-connection" data-wz-tab="connection" aria-controls="wz-pane-connection"><i class="fas fa-plug me-1" aria-hidden="true"></i>Connection</button></li>
        <li role="presentation"><button type="button" class="wz-tab" role="tab" id="wz-tab-events" data-wz-tab="events" aria-controls="wz-pane-events"><i class="fas fa-bell me-1" aria-hidden="true"></i>Events <span class="badge text-bg-secondary ms-1" data-wz-evcount><?= count($wh_events) ?></span></button></li>
        <li role="presentation"><button type="button" class="wz-tab" role="tab" id="wz-tab-advanced" data-wz-tab="advanced" aria-controls="wz-pane-advanced"><i class="fas fa-sliders-h me-1" aria-hidden="true"></i>Payload &amp; advanced</button></li>
        <li role="presentation"><button type="button" class="wz-tab" role="tab" id="wz-tab-deliveries" data-wz-tab="deliveries" aria-controls="wz-pane-deliveries"><i class="fas fa-bolt me-1" aria-hidden="true"></i>Deliveries <span class="badge text-bg-secondary ms-1"><?= count($deliveries) ?></span></button></li>
    </ul>

    <div class="wz-layout" data-wz-layout>
    <div class="wz-main">
    <form action="post.php" method="post" autocomplete="off" novalidate id="wz-form" data-wz-form>
        <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="webhook_destination" value="<?= $h($d->id) ?>">
        <input type="hidden" name="webhook_type" value="<?= $h($ctx['type']) ?>">
        <input type="hidden" name="webhook_id" value="<?= $webhook_id ?>">

        <section class="wz-pane" role="tabpanel" id="wz-pane-connection" aria-labelledby="wz-tab-connection" data-wz-pane="connection" hidden>
            <div class="wz-stephead">
                <div class="wz-platform"><span class="small text-secondary">Platform</span>
                    <select class="form-select form-select-sm w-auto" aria-label="Platform" data-wz-switch="webhook_edit.php?id=<?= $webhook_id ?>&amp;dest=">
                        <?php foreach (Destinations::byCategory() as $cat => $list) { ?>
                        <optgroup label="<?= $h(Destinations::categoryLabels()[$cat] ?? $cat) ?>">
                            <?php foreach ($list as $dd) { ?><option value="<?= $h($dd->id) ?>" <?= $dd->id === $d->id ? 'selected' : '' ?>><?= $h($dd->name) ?></option><?php } ?>
                        </optgroup>
                        <?php } ?>
                    </select>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-wz-help><i class="far fa-life-ring me-1" aria-hidden="true"></i>Need help?</button>
            </div>
            <div class="wz-errbox" data-wz-steperrors role="alert" hidden></div>
            <?php webhookPartConnect($ctx); ?>
        </section>

        <section class="wz-pane" role="tabpanel" id="wz-pane-events" aria-labelledby="wz-tab-events" data-wz-pane="events" hidden>
            <div class="wz-errbox" data-wz-steperrors role="alert" hidden></div>
            <?php webhookPartEvents($ctx); ?>
        </section>

        <section class="wz-pane" role="tabpanel" id="wz-pane-advanced" aria-labelledby="wz-tab-advanced" data-wz-pane="advanced" hidden>
            <div class="wz-errbox" data-wz-steperrors role="alert" hidden></div>
            <?php webhookPartAdvanced($ctx); ?>
            <div class="wz-card mt-3">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <div class="wz-card-title me-auto mb-0">Payload preview</div>
                    <label class="small text-secondary mb-0" for="wz-sample">Sample event</label>
                    <select class="form-select form-select-sm w-auto" id="wz-sample" data-wz-sample aria-label="Sample event"></select>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-wz-refresh-preview><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Refresh</button>
                </div>
                <div data-wz-preview aria-live="polite"><span class="text-secondary small">Shows what would be sent with the settings above (secrets masked). Nothing is sent.</span></div>
            </div>
        </section>

        <div class="wz-footer wz-savebar" data-wz-savebar>
            <span class="wz-dirty text-warning" data-wz-dirty hidden><i class="fas fa-circle me-1" aria-hidden="true"></i>Unsaved changes</span>
            <a class="btn btn-outline-secondary ms-auto" href="settings_webhooks.php" data-wz-cancel>Cancel</a>
            <button type="submit" name="edit_webhook" class="btn btn-primary btn-lg wz-next" data-wz-save><i class="fas fa-check me-1" aria-hidden="true"></i>Save changes</button>
        </div>
    </form>

    <section class="wz-pane" role="tabpanel" id="wz-pane-deliveries" aria-labelledby="wz-tab-deliveries" data-wz-pane="deliveries" hidden>
        <p class="text-secondary small">The latest <?= count($deliveries) ?> attempts for this webhook (including retries and tests). A failed delivery is retried after 1, 5, 30 and 120 minutes. Full log: <a href="settings_webhooks.php#deliveries">Webhooks page</a>.</p>
        <?php if (!$deliveries) { ?>
        <div class="wz-empty-small">No deliveries yet. Use <strong>Send test</strong> above to send the first one.</div>
        <?php } else { ?>
        <div class="table-responsive">
        <table class="table table-sm table-striped table-borderless align-middle mb-0">
            <thead class="text-dark"><tr><th>When</th><th>Event</th><th>Attempt</th><th>Result</th><th>Time</th><th>Response</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $dr) {
                $http = intval($dr['http_status']);
                $plan = WebhookTester::replayable($dr, $wh);
                ?>
                <tr>
                    <td class="text-nowrap text-secondary" title="<?= $h($dr['created_at']) ?>"><?= $h(timeAgo($dr['created_at'])) ?></td>
                    <td><code><?= $h($dr['event_type']) ?></code></td>
                    <td><?= intval($dr['attempt_number']) ?></td>
                    <td><?= $http >= 200 && $http < 300 ? '<span class="badge text-bg-success">' . $http . '</span>' : ($http > 0 ? '<span class="badge text-bg-danger">' . $http . '</span>' : '<span class="badge text-bg-danger">no response</span>') ?></td>
                    <td class="text-nowrap"><?= intval($dr['duration_ms']) ?> ms</td>
                    <td class="text-truncate wz-resp" title="<?= $h($dr['response_body_snippet']) ?>"><?= $h($dr['response_body_snippet']) ?></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-light ajax-modal" data-modal-size="lg" data-modal-url="modals/webhook/webhook_delivery.php?id=<?= intval($dr['delivery_id']) ?>" aria-label="View payload of delivery <?= intval($dr['delivery_id']) ?>" title="View payload"><i class="fas fa-eye" aria-hidden="true"></i></button>
                        <?php if ($plan) { ?>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="delivery_id" value="<?= intval($dr['delivery_id']) ?>">
                            <button type="submit" name="replay_webhook_delivery" class="btn btn-sm btn-light" aria-label="Replay delivery <?= intval($dr['delivery_id']) ?>" title="Replay: send this event again now"><i class="fas fa-redo" aria-hidden="true"></i></button>
                        </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <?php } ?>
    </section>
    </div>

    <aside class="wz-slideover" data-wz-slideover hidden aria-label="Setup guide" role="dialog" aria-modal="false">
        <div class="wz-slideover-head">
            <strong class="me-auto"><i class="far fa-life-ring me-1" aria-hidden="true"></i>Setup guide: <?= $h($d->name) ?></strong>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-wz-guide-close aria-label="Close the guide"><i class="fas fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="wz-slideover-body" data-wz-guide-body><span class="text-secondary small">Loading the guide...</span></div>
    </aside>
    </div>
    <div class="wz-backdrop" data-wz-backdrop hidden></div>
</div>
<script src="<?= $asset('js/webhook_wizard.js') ?>" defer></script>
<?php require_once "../includes/footer.php"; ?>
