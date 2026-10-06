<?php
require_once '../../../includes/modal_header.php';
require_once '../../includes/webhook_events.php';
require_once '../../../includes/webhook_guide.php';

use RivetCore\Webhooks\Destinations;

// Step 1 (no ?dest=): pick the platform. Step 2 (?dest=n8n): the form for that platform, with its setup guide beside it.
// Choosing a card loads step 2 into this same modal (js/webhook_form.js).
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$d = Destinations::get((string) ($_GET['dest'] ?? ''));
$editing = false;
$wh = null;

ob_start();
if ($d === null) { ?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-satellite-dish me-2"></i>Add webhook</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<div class="wh-modal">
    <div class="wh-steps"><span class="is-current">1. Choose a platform</span><span aria-hidden="true">&rsaquo;</span><span>2. Configure</span>
        <a class="ms-auto" href="settings_webhook_guides.php"><i class="fas fa-book me-1" aria-hidden="true"></i>Guides for every platform</a></div>
    <div class="wh-chooser" data-wh-chooser>
        <input type="search" class="form-control mb-2" placeholder="Search platforms (n8n, ntfy, Discord, Telegram ...)" aria-label="Search platforms" autocomplete="off" data-wh-chooser-search>
        <?php foreach (Destinations::byCategory() as $cat => $list) { ?>
        <div class="wh-cat" data-wh-cat>
            <div class="wh-cat-title"><?= $h(Destinations::categoryLabels()[$cat] ?? $cat) ?></div>
            <div class="wh-cards">
                <?php foreach ($list as $dd) { ?>
                <button type="button" class="wh-card" data-wh-dest="<?= $h($dd->id) ?>" data-wh-search="<?= $h(strtolower($dd->name . ' ' . $dd->id . ' ' . $dd->description . ' ' . $dd->format)) ?>">
                    <span class="wh-card-title"><i class="fas <?= $h(webhookGuideIcon($dd)) ?>" aria-hidden="true"></i><?= $h($dd->name) ?></span>
                    <span class="wh-card-desc"><?= $h($dd->description) ?></span>
                </button>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
        <p class="text-secondary mt-3 d-none" data-wh-chooser-none>No platform matches. Use <strong>Generic JSON</strong> or <strong>Custom template</strong> for anything else.</p>
    </div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button></div>
<?php } else { ?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-satellite-dish me-2"></i>Add webhook: <?= $h($d->name) ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" class="wh-modal" autocomplete="off" data-wh-form data-wh-action-url="modals/webhook/webhook_action.php">
    <div class="wh-steps"><button type="button" class="btn btn-sm btn-link p-0" data-wh-back="modals/webhook/webhook_add.php"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Choose another platform</button><span aria-hidden="true">&rsaquo;</span><span class="is-current">2. Configure <?= $h($d->name) ?></span></div>
    <div class="modal-body">
        <?php require __DIR__ . '/_form.php'; ?>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary me-auto" data-wh-preview><i class="fas fa-eye me-1"></i>Preview payload</button>
        <button type="button" class="btn btn-outline-primary" data-wh-test><i class="fas fa-paper-plane me-1"></i>Send test</button>
        <button type="submit" name="add_webhook" class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
    </div>
</form>
<?php }
require_once '../../../includes/modal_footer.php';
