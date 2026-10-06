<?php
require_once '../../../includes/modal_header.php';
require_once '../../includes/webhook_events.php';

$wid = intval($_GET['id']);
$wh  = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $wid LIMIT 1"));
if (!$wh) { echo '<div class="p-3 text-danger">Webhook not found.</div>'; require_once '../../../includes/modal_footer.php'; exit; }

$is_chat_dest = \ITFlow\Webhooks\ChatFormatter::isChatType(\ITFlow\Webhooks\ChatFormatter::normalizeType($wh['webhook_type'] ?? ''));
$cur_events = array_map('trim', explode(',', $wh['webhook_events']));

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-satellite-dish me-2"></i>Edit Webhook</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>

<form action="post.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="webhook_id" value="<?= $wid ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="webhook_name" required value="<?= nullable_htmlentities($wh['webhook_name']) ?>">
        </div>

        <div class="form-group">
            <label>Endpoint URL <?php if (!$is_chat_dest) { ?><span class="text-danger">*</span><?php } ?></label>
            <?php if ($is_chat_dest) { ?>
            <input type="url" class="form-control" name="webhook_url" placeholder="(saved and hidden - paste a new URL only to replace it)" autocomplete="off">
            <?php } else { ?>
            <input type="url" class="form-control" name="webhook_url" required value="<?= nullable_htmlentities($wh['webhook_url']) ?>">
            <?php } ?>
        </div>

        <div class="form-group">
            <label>Secret <small class="text-secondary">(leave blank to keep existing; enter a new value to rotate. Generic: HMAC signing secret. Slack: your Slack app's <em>Signing Secret</em>, which turns on the Acknowledge / Assign to me buttons. Teams: not used)</small></label>
            <input type="password" class="form-control font-monospace" name="webhook_secret" placeholder="<?= $wh['webhook_secret'] !== '' ? '(saved - unchanged)' : '(none)' ?>" autocomplete="new-password">
            <?php if ($wh['webhook_secret'] !== '' && ($wh['webhook_type'] ?? '') === 'slack') { ?>
            <div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="webhook_secret_clear" value="1" id="whSecretClear<?= $wid ?>"><label class="form-check-label small" for="whSecretClear<?= $wid ?>">Remove the signing secret (turns the interactive buttons off)</label></div>
            <?php } ?>
        </div>

        <?php require __DIR__ . '/_destination_fields.php'; ?>

        <div class="form-group">
            <label>Subscribe to Events <span class="text-danger">*</span></label>
            <?php foreach (webhook_event_groups() as $group_label => $group_events) { ?>
            <div class="text-secondary small text-uppercase mt-2 mb-1"><?= htmlspecialchars($group_label) ?></div>
            <?php foreach ($group_events as $ev) {
                $ev_id = 'ev_edit_' . preg_replace('/[^a-z0-9]+/', '_', $ev); ?>
            <div class="form-check form-check">
                <input type="checkbox" class="form-check-input" id="<?= $ev_id ?>" name="webhook_events[]" value="<?= htmlspecialchars($ev) ?>"
                    <?= in_array($ev, $cur_events) ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= $ev_id ?>"><code><?= htmlspecialchars($ev) ?></code></label>
            </div>
            <?php } } ?>
        </div>

        <div class="form-group">
            <div class="form-check form-check form-switch">
                <input type="checkbox" class="form-check-input" id="webhook_enabled_edit" name="webhook_enabled" value="1"
                    <?= intval($wh['webhook_enabled']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="webhook_enabled_edit">Enabled</label>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_webhook" class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
        <?php if ($is_chat_dest) { ?>
        <button type="submit" name="test_webhook" value="1" formnovalidate class="btn btn-outline-secondary" title="Sends a clearly labelled test message to the saved destination (save first if you changed it)"><i class="fas fa-paper-plane me-1"></i>Send test message</button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
