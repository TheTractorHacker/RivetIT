<?php
require_once '../../../includes/modal_header.php';
require_once '../../../includes/webhook_guide.php';

use RivetCore\Webhooks\Destinations;

// The setup guide of one platform as an HTML fragment, loaded on demand into the slide-over of the Add / Edit webhook pages
// (js/webhook_wizard.js). The same catalog output as the Guides page; nothing is embedded in the form page itself.
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$d = Destinations::get((string) ($_GET['dest'] ?? ''));
if ($d === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown platform.']);
    exit;
}

ob_start();
?>
<div class="wh-guide">
    <div class="d-flex align-items-center mb-2">
        <strong class="me-auto"><i class="fas <?= $h(webhookGuideIcon($d)) ?> me-1" aria-hidden="true"></i><?= $h($d->name) ?></strong>
        <a class="small" href="settings_webhook_guides.php#<?= $h($d->id) ?>" target="_blank" rel="noopener">Open full guide <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
    </div>
    <?php webhookGuideHtml($d, false); ?>
</div>
<?php
require_once '../../../includes/modal_footer.php';
