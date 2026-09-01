<?php
require_once '../../../includes/modal_header.php';

$ALL_EVENTS = ['ticket.created','ticket.replied','ticket.assigned','ticket.status_changed','ticket.resolved'];

$wid = intval($_GET['id']);
$wh  = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $wid LIMIT 1"));
if (!$wh) { echo '<div class="p-3 text-danger">Webhook not found.</div>'; require_once '../../../includes/modal_footer.php'; exit; }

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
            <label>Endpoint URL <span class="text-danger">*</span></label>
            <input type="url" class="form-control" name="webhook_url" required value="<?= nullable_htmlentities($wh['webhook_url']) ?>">
        </div>

        <div class="form-group">
            <label>Secret <small class="text-secondary">(leave blank to keep existing; enter a new value to rotate)</small></label>
            <input type="text" class="form-control font-monospace" name="webhook_secret" placeholder="(unchanged)" autocomplete="off">
        </div>

        <div class="form-group">
            <label>Subscribe to Events <span class="text-danger">*</span></label>
            <?php foreach ($ALL_EVENTS as $ev) { ?>
            <div class="form-check form-check">
                <input type="checkbox" class="form-check-input" id="ev_edit_<?= $ev ?>" name="webhook_events[]" value="<?= $ev ?>"
                    <?= in_array($ev, $cur_events) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ev_edit_<?= $ev ?>"><code><?= $ev ?></code></label>
            </div>
            <?php } ?>
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
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
