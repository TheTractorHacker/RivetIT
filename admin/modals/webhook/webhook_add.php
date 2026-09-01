<?php
require_once '../../../includes/modal_header.php';

$ALL_EVENTS = ['ticket.created','ticket.replied','ticket.assigned','ticket.status_changed','ticket.resolved'];

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
            <?php foreach ($ALL_EVENTS as $ev) { ?>
            <div class="form-check form-check">
                <input type="checkbox" class="form-check-input" id="ev_add_<?= $ev ?>" name="webhook_events[]" value="<?= $ev ?>">
                <label class="form-check-label" for="ev_add_<?= $ev ?>"><code><?= $ev ?></code></label>
            </div>
            <?php } ?>
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
