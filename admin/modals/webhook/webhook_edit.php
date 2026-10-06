<?php
require_once '../../../includes/modal_header.php';
require_once '../../includes/webhook_events.php';
require_once '../../../includes/webhook_guide.php';

use ITFlow\Webhooks\DestinationConfig;
use RivetCore\Webhooks\Destinations;

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$webhook_id = intval($_GET['id'] ?? 0);
$wh = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id LIMIT 1"));
if (!$wh) {
    http_response_code(404);
    echo json_encode(['error' => 'Webhook not found.']);
    exit;
}
$editing = true;
// The preset this webhook is (a pre-preset row is shown as its equivalent); ?dest= switches the form to another platform.
$current_dest = DestinationConfig::effectiveDestinationId($wh);
$d = Destinations::get((string) ($_GET['dest'] ?? '')) ?? Destinations::get($current_dest);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-satellite-dish me-2"></i>Edit webhook: <?= $h($wh['webhook_name']) ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" class="wh-modal" autocomplete="off" data-wh-form data-wh-action-url="modals/webhook/webhook_action.php">
    <div class="wh-steps">
        <label class="mb-0" for="wh-platform">Platform</label>
        <select class="form-select form-select-sm w-auto" id="wh-platform" data-wh-switch="modals/webhook/webhook_edit.php?id=<?= $webhook_id ?>">
            <?php foreach (Destinations::byCategory() as $cat => $list) { ?>
            <optgroup label="<?= $h(Destinations::categoryLabels()[$cat] ?? $cat) ?>">
                <?php foreach ($list as $dd) { ?><option value="<?= $h($dd->id) ?>" <?= $dd->id === $d->id ? 'selected' : '' ?>><?= $h($dd->name) ?></option><?php } ?>
            </optgroup>
            <?php } ?>
        </select>
        <?php if ($d->id !== $current_dest) { ?><span class="text-warning small">Changing the platform keeps the name and events; re-enter its URL, secrets and options.</span><?php } ?>
    </div>
    <div class="modal-body">
        <?php require __DIR__ . '/_form.php'; ?>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary me-auto" data-wh-preview><i class="fas fa-eye me-1"></i>Preview payload</button>
        <button type="button" class="btn btn-outline-primary" data-wh-test><i class="fas fa-paper-plane me-1"></i>Send test</button>
        <button type="submit" name="edit_webhook" class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
