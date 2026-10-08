<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/webhook_guide.php";
require_once "../includes/webhook_guide_page.php";

use RivetCore\Webhooks\Destinations;

$h = 'wgH';
$wg_cats = Destinations::categoryLabels();
$wg_groups = Destinations::byCategory();
$wg_order = [];
foreach ($wg_groups as $list) {
    foreach ($list as $d) {
        $wg_order[] = $d;
    }
}
$wg_snip = Destinations::get('generic-json');
$wg_verify = $wg_snip ? $wg_snip->verifySnippets : [];
?>
<link rel="stylesheet" href="/css/webhook_guides.css?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/webhook_guides.css') ?>">
<div class="card wg-page">
    <div class="card-header py-3 d-flex align-items-center gap-3">
        <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-book me-2"></i>Webhook guides</h3>
        <a href="settings_webhooks.php" class="btn btn-primary btn-sm d-print-none"><i class="fas fa-plus me-1"></i>Add Webhook</a>
    </div>
    <ul class="nav nav-tabs px-3 d-print-none" role="tablist">
        <li class="nav-item"><a class="nav-link" href="settings_webhooks.php">Webhooks</a></li>
        <li class="nav-item"><a class="nav-link active" aria-current="page" href="settings_webhook_guides.php">Guides</a></li>
    </ul>
    <div class="card-body" id="wg-root">
        <a class="wg-skip" href="#wg-main">Skip to the guide</a>

        <header class="wg-hero">
            <div class="wg-hero-text">
                <h2 class="wg-hero-title">Webhook guides</h2>
                <p class="wg-hero-pitch">Connect RivetIT events to n8n, Home Assistant, ntfy, Discord and more. One guide per platform, generated from the same catalog the Add Webhook form uses: setup steps, things to know, a sample request and how to check our signature. Platform details change; every guide links the vendor's own documentation.</p>
                <div class="wg-hero-links d-print-none">
                    <a class="btn btn-sm btn-primary" href="settings_webhooks.php"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add a webhook</a>
                    <a class="btn btn-sm btn-outline-primary" href="#signing"><i class="fas fa-signature me-1" aria-hidden="true"></i>How signing works</a>
                    <a class="btn btn-sm btn-outline-primary" href="#networks"><i class="fas fa-network-wired me-1" aria-hidden="true"></i>Allowed internal networks</a>
                </div>
            </div>
            <ol class="wg-flow" aria-label="How it works">
                <li><i class="fas fa-bolt" aria-hidden="true"></i><strong>Event</strong><span>Something happens in RivetIT</span></li>
                <li><i class="fas fa-file-code" aria-hidden="true"></i><strong>Format</strong><span>Body shaped for the platform</span></li>
                <li><i class="fas fa-signature" aria-hidden="true"></i><strong>Sign</strong><span>HMAC with timestamp</span></li>
                <li><i class="fas fa-paper-plane" aria-hidden="true"></i><strong>Deliver</strong><span>HTTPS POST, any 2xx is success</span></li>
                <li><i class="fas fa-redo" aria-hidden="true"></i><strong>Retry</strong><span>Failures are re-sent</span></li>
            </ol>
        </header>

        <div class="wg-layout">
            <aside class="wg-side" aria-label="Guide navigation">
                <div class="wg-side-inner">
                    <label class="visually-hidden" for="wg-search">Search platforms</label>
                    <div class="wg-search"><i class="fas fa-search" aria-hidden="true"></i><input type="search" id="wg-search" class="form-control form-control-sm" placeholder="Search platforms" autocomplete="off" data-wg-search></div>
                    <div class="wg-chips" role="group" aria-label="Filter by category">
                        <button type="button" class="wg-chipbtn is-active" data-wg-cat="" aria-pressed="true">All</button>
                        <?php foreach ($wg_groups as $cat => $list) { ?>
                        <button type="button" class="wg-chipbtn" data-wg-cat="<?= $h($cat) ?>" aria-pressed="false"><i class="fas <?= $h(wgCategoryIcon($cat)) ?>" aria-hidden="true"></i> <?= $h($wg_cats[$cat] ?? $cat) ?></button>
                        <?php } ?>
                    </div>
                    <div class="wg-jump">
                        <label class="visually-hidden" for="wg-jump-select">Jump to a guide</label>
                        <select id="wg-jump-select" class="form-select form-select-sm" data-wg-jump>
                            <option value="guides">All guides</option>
                            <optgroup label="Reference"><option value="signing">How signing works</option><option value="networks">Allowed internal networks</option></optgroup>
                            <?php foreach ($wg_groups as $cat => $list) { ?>
                            <optgroup label="<?= $h($wg_cats[$cat] ?? $cat) ?>" data-cat="<?= $h($cat) ?>">
                                <?php foreach ($list as $d) { ?><option value="<?= $h($d->id) ?>" data-wg-item data-cat="<?= $h($cat) ?>" data-search="<?= $h(strtolower($d->name . ' ' . $d->id . ' ' . $d->description)) ?>"><?= $h($d->name) ?></option><?php } ?>
                            </optgroup>
                            <?php } ?>
                        </select>
                    </div>
                    <nav class="wg-nav" aria-label="Platforms">
                        <ul class="wg-navlist">
                            <li><a href="#guides" data-wg-link="guides"><i class="fas fa-th-large" aria-hidden="true"></i> All guides</a></li>
                        </ul>
                        <div class="wh-cat-title">Reference</div>
                        <ul class="wg-navlist">
                            <li><a href="#signing" data-wg-link="signing"><i class="fas fa-signature" aria-hidden="true"></i> How signing works</a></li>
                            <li><a href="#networks" data-wg-link="networks"><i class="fas fa-network-wired" aria-hidden="true"></i> Allowed internal networks</a></li>
                            <li><a href="#n8n-walkthrough" data-wg-link="n8n"><i class="fas fa-route" aria-hidden="true"></i> Receiving in n8n</a></li>
                        </ul>
                        <?php foreach ($wg_groups as $cat => $list) { ?>
                        <div class="wg-navgroup" data-cat="<?= $h($cat) ?>">
                            <div class="wh-cat-title"><i class="fas <?= $h(wgCategoryIcon($cat)) ?>" aria-hidden="true"></i> <?= $h($wg_cats[$cat] ?? $cat) ?></div>
                            <ul class="wg-navlist">
                                <?php foreach ($list as $d) { ?><li data-wg-item data-cat="<?= $h($cat) ?>" data-search="<?= $h(strtolower($d->name . ' ' . $d->id . ' ' . $d->description . ' ' . ($wg_cats[$cat] ?? $cat))) ?>"><a href="#<?= $h($d->id) ?>" data-wg-link="<?= $h($d->id) ?>"><?= wgBadge($d, 'wg-badge-sm') ?><span><?= $h($d->name) ?></span></a></li><?php } ?>
                            </ul>
                        </div>
                        <?php } ?>
                        <p class="wg-empty" data-wg-empty hidden role="status">No platform matches your search.</p>
                    </nav>
                </div>
            </aside>

            <main class="wg-main" id="wg-main" tabindex="-1">
                <section class="wg-index" id="guides" data-wg-guide data-guide="guides" aria-labelledby="wg-index-title">
                    <h2 id="wg-index-title" class="wg-h2">All platforms <span class="wg-count" data-wg-count><?= count($wg_order) ?></span></h2>
                    <div class="wg-grid">
                        <?php foreach ($wg_order as $d) { wgCard($d, $wg_cats); } ?>
                    </div>
                    <p class="wg-empty" data-wg-empty-grid hidden role="status">No platform matches your search. Clear it to see all guides.</p>
                </section>

                <section class="wg-guide wg-general" id="signing" data-wg-guide data-guide="signing" aria-labelledby="wg-signing-title">
                    <nav class="wg-crumbs d-print-none" aria-label="Breadcrumb"><a href="#guides">All guides</a> <span aria-hidden="true">/</span> <span>Reference</span></nav>
                    <header class="wg-guide-head">
                        <span class="wg-badge wg-badge-lg" style="--wg-brand:#0ca678"><i class="fas fa-signature" aria-hidden="true"></i></span>
                        <div class="wg-guide-titles"><h2 id="wg-signing-title">How signing works</h2><p class="wg-lead">Prove a request really came from RivetIT and was not altered or replayed.</p></div>
                    </header>
                    <div class="wg-guide-main">
                        <section class="wg-block" aria-labelledby="wg-sign-h1">
                            <h3 id="wg-sign-h1"><i class="fas fa-shield-alt" aria-hidden="true"></i> Headers and algorithm</h3>
                            <p>Every request carries <code>X-Rivet-Timestamp</code> and <code>X-Rivet-Signature-V2</code> (<code>t=&lt;timestamp&gt;,v1=&lt;hex&gt;</code>), an HMAC-SHA256 of <code>&lt;timestamp&gt;.&lt;raw body&gt;</code> keyed with the webhook's signing secret. The older <code>X-ITFlow-Signature</code> / <code>X-RivetIT-Signature</code> (<code>sha256=&lt;hex&gt;</code> over the body alone) is still sent.</p>
                            <div class="wg-table-wrap"><table class="wg-table"><thead><tr><th scope="col">Header</th><th scope="col">Value</th><th scope="col">Use</th></tr></thead><tbody>
                                <tr><th scope="row"><code>X-Rivet-Timestamp</code></th><td>Unix seconds</td><td>Signed together with the body; reject old values</td></tr>
                                <tr><th scope="row"><code>X-Rivet-Signature-V2</code></th><td><code>t=&lt;timestamp&gt;,v1=&lt;hex&gt;</code></td><td>Preferred: covers timestamp and body</td></tr>
                                <tr><th scope="row"><code>X-ITFlow-Signature</code>, <code>X-RivetIT-Signature</code></th><td><code>sha256=&lt;hex&gt;</code></td><td>Legacy: body only, no replay protection</td></tr>
                            </tbody></table></div>
                        </section>
                        <section class="wg-block" aria-labelledby="wg-sign-h2">
                            <h3 id="wg-sign-h2"><i class="far fa-clock" aria-hidden="true"></i> Timestamp and tolerance</h3>
                            <p>Compute the HMAC over the timestamp, a dot and the exact raw bytes you received, compare in constant time, and reject any request whose timestamp is more than <strong>5 minutes (300 seconds)</strong> from your clock. A retry re-sends the same body with a fresh timestamp and signature, so treat deliveries as at-least-once. Any 2xx answer counts as delivered; answer quickly and work asynchronously.</p>
                            <div class="wg-callout wg-callout-warning" role="note"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><div>Verify against the raw body, not a parsed and re-serialised copy. Chat services (Slack, Discord, ...) cannot check signatures: for them the URL is the secret.</div></div>
                        </section>
                        <?php if ($wg_verify) { ?>
                        <section class="wg-block" aria-labelledby="wg-sign-h3">
                            <h3 id="wg-sign-h3"><i class="fas fa-code" aria-hidden="true"></i> Verification snippets</h3>
                            <?php wgSnippetTabs($wg_verify, 'wg-signing'); ?>
                        </section>
                        <?php } ?>
                        <section class="wg-block" id="networks" aria-labelledby="wg-net-h">
                            <h3 id="wg-net-h"><i class="fas fa-network-wired" aria-hidden="true"></i> Allowed internal networks</h3>
                            <p>Webhooks may only call public addresses plus the internal networks an administrator lists. Loopback (127.0.0.0/8), link-local (169.254.0.0/16) and cloud-metadata addresses are never allowed, and only private ranges (10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7) can be listed. If n8n, Home Assistant, Node-RED or ntfy runs on your own LAN, add its network first, otherwise the address is refused.</p>
                            <a class="btn btn-outline-primary btn-sm d-print-none" href="settings_webhooks.php#internal-networks"><i class="fas fa-network-wired me-1" aria-hidden="true"></i>Open Internal network access</a>
                        </section>
                    </div>
                </section>

                <?php foreach ($wg_order as $i => $d) {
                    $related = array_values(array_filter($wg_groups[$d->category] ?? [], static fn ($o) => $o->id !== $d->id));
                    wgGuide($d, $wg_cats, ['prev' => $wg_order[$i - 1] ?? null, 'next' => $wg_order[$i + 1] ?? null, 'related' => array_slice($related, 0, 3)], $d->id === 'n8n');
                } ?>
            </main>
        </div>
    </div>
</div>
<script src="/js/webhook_guides.js?v=<?= (int) @filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/webhook_guides.js') ?>" nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>" defer></script>
<?php require_once "../includes/footer.php"; ?>
