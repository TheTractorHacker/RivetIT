<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/webhook_guide.php";

use RivetCore\Webhooks\Destinations;

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="card">
    <div class="card-header py-3 d-flex align-items-center gap-3">
        <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-book me-2"></i>Webhook guides</h3>
        <a href="settings_webhooks.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>Add Webhook</a>
    </div>
    <ul class="nav nav-tabs px-3" role="tablist">
        <li class="nav-item"><a class="nav-link" href="settings_webhooks.php">Webhooks</a></li>
        <li class="nav-item"><a class="nav-link active" aria-current="page" href="settings_webhook_guides.php">Guides</a></li>
    </ul>
    <div class="card-body">
        <p class="text-muted">One guide per platform, generated from the same catalog the Add Webhook form uses: setup steps, things to know, a sample request and how to check our signature. Platform details change; every guide links the vendor's own documentation.</p>
        <div class="row g-4">
            <nav class="col-lg-3" aria-label="Platforms">
                <div class="position-sticky" style="top: 1rem;">
                    <?php foreach (Destinations::byCategory() as $cat => $list) { ?>
                    <div class="wh-cat-title"><?= $h(Destinations::categoryLabels()[$cat] ?? $cat) ?></div>
                    <ul class="list-unstyled mb-2">
                        <?php foreach ($list as $d) { ?><li><a href="#<?= $h($d->id) ?>"><?= $h($d->name) ?></a></li><?php } ?>
                    </ul>
                    <?php } ?>
                    <div class="wh-cat-title">Reference</div>
                    <ul class="list-unstyled"><li><a href="#signing">How signing works</a></li><li><a href="#n8n-walkthrough">Receiving in n8n</a></li></ul>
                </div>
            </nav>
            <div class="col-lg-9">
                <section class="mb-4" id="signing">
                    <h4>How signing works</h4>
                    <p>Every request carries <code>X-Rivet-Timestamp</code> and <code>X-Rivet-Signature-V2</code> (<code>t=&lt;timestamp&gt;,v1=&lt;hex&gt;</code>), an HMAC-SHA256 of <code>&lt;timestamp&gt;.&lt;raw body&gt;</code> keyed with the webhook's signing secret. The older <code>X-ITFlow-Signature</code> / <code>X-RivetIT-Signature</code> (<code>sha256=&lt;hex&gt;</code> over the body alone) is still sent. A retry re-sends the same body with a fresh timestamp and signature, so treat deliveries as at-least-once. Any 2xx answer counts as delivered; answer quickly and work asynchronously. Chat services (Slack, Discord, ...) cannot check signatures: for them the URL is the secret.</p>
                </section>
                <?php foreach (Destinations::all() as $d) { ?>
                <section class="card mb-4" id="<?= $h($d->id) ?>">
                    <div class="card-header d-flex align-items-center gap-2">
                        <h4 class="card-title mb-0 me-auto"><i class="fas <?= $h(webhookGuideIcon($d)) ?> me-2" aria-hidden="true"></i><?= $h($d->name) ?></h4>
                        <a href="settings_webhooks.php?add=<?= $h($d->id) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus me-1"></i>Add <?= $h($d->name) ?> webhook</a>
                    </div>
                    <div class="card-body wh-guide border-0">
                        <?php if ($d->id === 'n8n') { echo '<span id="n8n-walkthrough"></span>'; } ?>
                        <?php webhookGuideHtml($d, true); ?>
                    </div>
                </section>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
<?php require_once "../includes/footer.php"; ?>
