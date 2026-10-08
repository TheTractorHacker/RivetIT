<?php

/*
 * Renders a destination's setup guide from RivetCore's Destinations catalog (never from Markdown files): the compact side panel of the
 * Add / Edit webhook form and the full guides page share it. Everything printed is escaped; code blocks get a copy button
 * (js/webhook_form.js, delegated, so it works inside AJAX-loaded modals). The signature snippets are Core's own
 * (Destination::$verifySnippets), shown in tabs.
 */

use RivetCore\Webhooks\Destination;
use RivetCore\Webhooks\Destinations;

if (!function_exists('webhookGuideCode')) {
    /** A code block with a copy button. */
    function webhookGuideCode(string $code, string $idPrefix): void
    {
        static $seq = 0;
        $seq++;
        $id = $idPrefix . '-code-' . $seq;
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="wh-codewrap">
            <button type="button" class="btn btn-sm btn-outline-secondary wh-copy" data-wh-copy="#<?= $h($id) ?>" aria-label="Copy to clipboard"><i class="far fa-copy me-1" aria-hidden="true"></i>Copy</button>
            <pre class="wh-code" id="<?= $h($id) ?>" tabindex="0"><?= $h($code) ?></pre>
        </div>
        <?php
    }

    function webhookGuideIcon(Destination $d): string
    {
        return match ($d->category) {
            'chat' => 'fa-comments',
            'notify' => 'fa-bell',
            'home' => 'fa-home',
            'generic' => $d->id === 'custom-template' ? 'fa-code' : 'fa-plug',
            default => 'fa-project-diagram',
        };
    }

    /**
     * @param bool $full true on the guides page (adds the walk-through for n8n and the full verification section), false in the side panel
     */
    function webhookGuideHtml(Destination $d, bool $full = false): void
    {
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $p = 'wg-' . preg_replace('/[^a-z0-9]+/', '-', $d->id) . ($full ? '-f' : '-p');
        $authLabels = ['none' => 'none', 'hmac' => 'our signature only', 'bearer' => 'bearer token', 'basic' => 'basic (user + password)', 'header' => 'custom header'];
        ?>
        <div class="wh-guide-body">
            <h6>About</h6>
            <p class="mb-1"><?= $h($d->description) ?></p>
            <ul class="list-unstyled small mb-2">
                <li><strong>Body:</strong> <code><?= $h($d->format) ?></code> over <code><?= $h($d->method) ?></code></li>
                <li><strong>URL looks like:</strong> <code class="text-break"><?= $h($d->urlHint) ?></code></li>
                <li><strong>Authentication:</strong> <?= $h(implode(', ', array_map(static fn (string $m): string => $authLabels[$m] ?? $m, $d->authModes))) ?></li>
                <?php if (preg_match('#^https?://#', $d->docsUrl)) { ?>
                <li><a href="<?= $h($d->docsUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt me-1" aria-hidden="true"></i>Vendor documentation</a></li>
                <?php } ?>
            </ul>

            <h6>Setup</h6>
            <ol class="small">
                <?php foreach ($d->setupSteps as $step) { ?><li><?= $h($step) ?></li><?php } ?>
            </ol>

            <?php if ($d->notes) { ?>
            <h6>Things to know</h6>
            <ul class="small">
                <?php foreach ($d->notes as $note) { ?><li><?= $h($note) ?></li><?php } ?>
            </ul>
            <?php } ?>

            <h6>Try it from a shell</h6>
            <p class="small text-secondary mb-1">What this platform receives, as a curl command (replace the placeholders).</p>
            <?php webhookGuideCode($d->sampleCurl, $p); ?>

            <h6>Verify our signature</h6>
            <?php if ($d->verifySnippets) { ?>
                <p class="small text-secondary mb-1">Every request carries <code>X-Rivet-Timestamp</code> and <code>X-Rivet-Signature-V2</code> (HMAC-SHA256 of <code>&lt;timestamp&gt;.&lt;raw body&gt;</code> with this webhook's signing secret). Check it against the raw body and reject anything older than 5 minutes.</p>
                <ul class="nav nav-tabs small" role="tablist">
                    <?php $first = true; foreach ($d->verifySnippets as $lang => $code) {
                        $tid = $p . '-' . $lang; ?>
                    <li class="nav-item" role="presentation">
                        <button type="button" class="nav-link py-1 px-2 <?= $first ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#<?= $h($tid) ?>" role="tab" aria-selected="<?= $first ? 'true' : 'false' ?>"><?= $h(['node' => 'Node.js', 'python' => 'Python', 'php' => 'PHP', 'bash' => 'Bash', 'n8n-code' => 'n8n Code', 'n8n' => 'n8n Code'][$lang] ?? ucfirst($lang)) ?></button>
                    </li>
                    <?php $first = false; } ?>
                </ul>
                <div class="tab-content pt-2">
                    <?php $first = true; foreach ($d->verifySnippets as $lang => $code) {
                        $tid = $p . '-' . $lang; ?>
                    <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="<?= $h($tid) ?>" role="tabpanel"><?php webhookGuideCode($code, $tid); ?></div>
                    <?php $first = false; } ?>
                </div>
            <?php } else { ?>
                <p class="small text-secondary mb-0">This platform cannot check a signature (it does not expose the raw request). The signature headers are still sent; the destination URL is the secret, so keep it private.</p>
            <?php } ?>

            <?php if ($full && $d->id === 'n8n') { ?>
            <h6>Receiving in n8n: walk-through</h6>
            <ol class="small">
                <li>In n8n choose <strong>Create workflow</strong>, then <strong>Add first step</strong> and pick the <strong>Webhook</strong> trigger.</li>
                <li>Set <strong>HTTP Method</strong> to <code>POST</code> (n8n defaults to GET) and give it a <strong>Path</strong>, for example <code>rivetit</code>.</li>
                <li>Under <strong>Authentication</strong> choose <em>Header Auth</em> or <em>Basic Auth</em> if you want n8n to check who is calling, create the credential, and enter the same header or user and password here under <em>Authentication</em>. Leave it on <em>None</em> and rely on our signature if you prefer.</li>
                <li>Open <strong>Options</strong> and add <strong>Raw Body</strong> (on) if you want to verify <code>X-Rivet-Signature-V2</code>; set <strong>Respond</strong> to <em>Immediately</em>.</li>
                <li>Click <strong>Listen for test event</strong> (the node now waits on the <code>/webhook-test/</code> URL), then in RivetIT press <strong>Send test</strong>. The sample ticket appears in n8n: the JSON is under <code>{{ $json.body }}</code> (for example <code>{{ $json.body.data.ticket_number }}</code>) and the headers under <code>{{ $json.headers }}</code>.</li>
                <li>Build the rest of the workflow (an <em>IF</em> node on <code>{{ $json.body.data.ticket_priority }}</code>, a Slack, e-mail or HTTP Request node ...). To check our signature add the <em>n8n Code</em> snippet above right after the Webhook node.</li>
                <li><strong>Activate</strong> the workflow, copy the <strong>Production URL</strong> (it contains <code>/webhook/</code>, not <code>/webhook-test/</code>) into the URL field here and save.</li>
                <li>If n8n runs on your own network, add that network under <em>Internal network access</em> on the Webhooks page first, otherwise the address is refused.</li>
            </ol>
            <?php } ?>
        </div>
        <?php
    }
}
