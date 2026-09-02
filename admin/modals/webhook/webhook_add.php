<?php
require_once '../../../includes/modal_header.php';
require_once '../../includes/webhook_events.php';

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-satellite-dish me-2"></i>Add Webhook</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>

<form action="post.php" method="post">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="webhook_name" required placeholder="e.g. Flutter App, Slack, n8n">
        </div>

        <div class="form-group">
            <label>Endpoint URL <span class="text-danger">*</span></label>
            <input type="url" class="form-control" name="webhook_url" required placeholder="https://example.com/webhook">
        </div>

        <div class="form-group">
            <label>Secret <small class="text-secondary">(used for HMAC-SHA256 signature — leave blank to skip verification)</small></label>
            <input type="text" class="form-control font-monospace" name="webhook_secret" placeholder="your-secret-here" autocomplete="off">
        </div>

        <div class="form-group">
            <label>Subscribe to Events <span class="text-danger">*</span></label>
            <?php foreach (webhook_event_groups() as $group_label => $group_events) { ?>
            <div class="text-secondary small text-uppercase mt-2 mb-1"><?= htmlspecialchars($group_label) ?></div>
            <?php foreach ($group_events as $ev) {
                $ev_id = 'ev_add_' . preg_replace('/[^a-z0-9]+/', '_', $ev); ?>
            <div class="form-check form-check">
                <input type="checkbox" class="form-check-input" id="<?= $ev_id ?>" name="webhook_events[]" value="<?= htmlspecialchars($ev) ?>">
                <label class="form-check-label" for="<?= $ev_id ?>"><code><?= htmlspecialchars($ev) ?></code></label>
            </div>
            <?php } } ?>
        </div>

        <div class="form-group">
            <div class="form-check form-check form-switch">
                <input type="checkbox" class="form-check-input" id="webhook_enabled_add" name="webhook_enabled" value="1" checked>
                <label class="form-check-label" for="webhook_enabled_add">Enabled</label>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_webhook" class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
