<?php

/*
 * Presentation helpers for the Webhook guides hub (admin/settings_webhook_guides.php): brand badges, "what you need" lists, example
 * payloads rendered with RivetCore's PayloadFormatter, troubleshooting rows and the per-platform guide section. Everything printed
 * is escaped through wgH(); nothing here reads a secret (the example body is built from Core's sample event only).
 * The compact side-panel guide of the Add / Edit form stays in includes/webhook_guide.php.
 */

use RivetCore\Webhooks\Destination;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\PayloadFormatter;
use RivetCore\Webhooks\PayloadTemplate;

if (!function_exists('wgH')) {
    function wgH(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** DOM-safe id fragment. */
    function wgSlug(string $s): string
    {
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');

        return $s === '' ? 'x' : $s;
    }

    /**
     * Badge look for a platform: Font Awesome brand icon where the shipped 5.15 free set has one, otherwise a coloured letter avatar.
     *
     * @return array{icon:string,color:string,letter:string}
     */
    function wgBrand(Destination $d): array
    {
        $brands = [
            'discord' => ['fab fa-discord', '#5865f2'],
            'slack' => ['fab fa-slack', '#611f69'],
            'telegram' => ['fab fa-telegram-plane', '#229ed9'],
            'teams' => ['fab fa-microsoft', '#5059c9'],
            'rocketchat' => ['fab fa-rocketchat', '#f5455c'],
            'node-red' => ['fab fa-node', '#8f0000'],
            'home-assistant' => ['fas fa-home', '#18bcf2'],
            'custom-template' => ['fas fa-code', '#6c7a91'],
            'generic-json' => ['fas fa-plug', '#6c7a91'],
            'generic-form' => ['fas fa-plug', '#6c7a91'],
        ];
        $palette = ['automation' => '#ae3ec9', 'chat' => '#4263eb', 'notify' => '#d6336c', 'home' => '#0ca678', 'generic' => '#6c7a91'];
        $letter = strtoupper(mb_substr((string) preg_replace('/[^A-Za-z0-9]/', '', $d->name), 0, 1)) ?: '?';
        if (isset($brands[$d->id])) {
            return ['icon' => $brands[$d->id][0], 'color' => $brands[$d->id][1], 'letter' => $letter];
        }

        return ['icon' => '', 'color' => $palette[$d->category] ?? '#6c7a91', 'letter' => $letter];
    }

    /** Category icon (Font Awesome 5.15 solid). */
    function wgCategoryIcon(string $cat): string
    {
        return ['automation' => 'fa-project-diagram', 'chat' => 'fa-comments', 'notify' => 'fa-bell', 'home' => 'fa-home', 'generic' => 'fa-plug'][$cat] ?? 'fa-plug';
    }

    function wgBadge(Destination $d, string $extra = ''): string
    {
        $b = wgBrand($d);
        $inner = $b['icon'] !== '' ? '<i class="' . wgH($b['icon']) . '" aria-hidden="true"></i>' : '<span aria-hidden="true">' . wgH($b['letter']) . '</span>';

        return '<span class="wg-badge ' . wgH($extra) . '" style="--wg-brand:' . wgH($b['color']) . '">' . $inner . '</span>';
    }

    /** @return list<string> labels of the values the platform makes you supply (required extra fields; a URL when there are none) */
    function wgNeeds(Destination $d): array
    {
        $out = [];
        foreach ($d->extraFields as $f) {
            if ($f->required) {
                $out[] = strtolower($f->label);
            }
        }

        return $out ?: ['URL'];
    }

    /** Rough setup time shown on the cards, derived from the number of steps. */
    function wgSetupTime(Destination $d): string
    {
        $n = count($d->setupSteps);

        return $n <= 3 ? '2 min' : ($n <= 5 ? '5 min' : '10 min');
    }

    /** @return array{0:string,1:string} [language for the highlighter, label] for a verifySnippets key */
    function wgLang(string $key): array
    {
        $map = ['node' => ['javascript', 'Node.js'], 'python' => ['python', 'Python'], 'php' => ['php', 'PHP'], 'bash' => ['bash', 'Bash'],
            'n8n-code' => ['javascript', 'n8n Code'], 'n8n' => ['javascript', 'n8n Code']];

        return $map[$key] ?? [in_array($key, ['json', 'javascript', 'python', 'php', 'bash'], true) ? $key : 'text', ucfirst($key)];
    }

    /** Callout level of a note: danger (secrets), warning (limits and traps), info. */
    function wgNoteLevel(string $note): string
    {
        if (preg_match('/\bsecret\b|anyone who|can read it|public server/i', $note)) {
            return 'danger';
        }
        if (preg_match('/\b(only|must|without|never|cannot|can\'t|not |limit|blocked|refus|required|default is|expire)/i', $note)) {
            return 'warning';
        }

        return 'info';
    }

    /**
     * The body this platform receives for a sample ticket event, formatted by RivetCore (server-side; the sample is Core's own, no
     * stored values or secrets are involved).
     *
     * @return array{body:string,lang:string,type:string,headers:array<string,string>,error:string}
     */
    function wgExample(Destination $d): array
    {
        $ctx = PayloadTemplate::sampleContext('ticket.created');
        $fo = $d->formatOptions;
        foreach ($d->extraFields as $f) {
            if ($f->target === 'option' && $f->option !== '' && $f->example !== '') {
                $fo[$f->option] = $f->example;
            }
        }
        $fo['app_name'] = defined('APP_NAME') ? (string) APP_NAME : 'RivetIT';
        try {
            $r = PayloadFormatter::format($d->format, ['event' => 'ticket.created', 'timestamp' => (string) $ctx['timestamp'], 'data' => $ctx['data']], $fo);
        } catch (\Throwable $e) {
            return ['body' => '', 'lang' => 'text', 'type' => '', 'headers' => [], 'error' => $e->getMessage()];
        }
        $body = $r->body;
        $lang = 'text';
        if (stripos($r->contentType, 'json') !== false) {
            $dec = json_decode($body, true);
            if (is_array($dec)) {
                $body = (string) json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $lang = 'json';
            }
        }

        return ['body' => $body, 'lang' => $lang, 'type' => $r->contentType, 'headers' => $r->headers, 'error' => ''];
    }

    /** @return list<array{0:string,1:string,2:string}> symptom, likely cause, fix */
    function wgTroubleshooting(Destination $d): array
    {
        $rows = [];
        $n = $d->name;
        $specific = [
            'n8n' => [['404 from a /webhook-test/ URL', 'The test URL answers one call after "Listen for test event" and is not the production URL.', 'Activate the workflow and paste the Production URL (it contains /webhook/).']],
            'node-red' => [['Every attempt times out', 'The flow has no "http response" node, so Node-RED never answers.', 'End the flow with an "http response" node returning status 200.']],
            'telegram' => [['400 "chat not found"', 'The bot is not a member of the chat, or the Chat ID is wrong.', 'Add the bot to the group or channel, then use the numeric id (negative for groups) or @channelname.']],
            'discord' => [['400 or truncated message', 'Discord limits content and embeds in size.', 'Pick fewer events or shorter subjects; the formatter already truncates to the limits.']],
            'slack' => [['404 "no_service" or "invalid_token"', 'The incoming webhook was revoked or the URL was copied incompletely.', 'Create a fresh Incoming Webhook and paste the full URL again.']],
            'teams' => [['400 or 202 with no message', 'The URL is an old connector URL rather than a Workflows webhook.', 'Create the webhook with the Workflows app ("Post to a channel when a webhook request is received").']],
            'ntfy' => [['Nothing arrives on the phone', 'The app is subscribed to a different topic, or the topic name is mistyped.', 'Subscribe to exactly the topic you entered and use a long random name; public topics are readable by anyone.']],
            'home-assistant' => [['404 from the webhook URL', 'The webhook ID in the automation does not match the URL.', 'Copy the full URL from the trigger (Webhook ID) and keep POST enabled in its settings.']],
            'matrix-client' => [['403 "Forbidden"', 'The bot user is not in the room or the access token is invalid.', 'Join the bot to the room and use a valid access token under Authentication.']],
        ];
        foreach ($specific[$d->id] ?? [] as $r) {
            $rows[] = [$r[0], $r[1], $r[2], 'platform'];
        }
        $generic = [
            ['401 or 403', 'The receiver rejected our credentials, or a chat URL was revoked.', in_array('bearer', $d->authModes, true) || in_array('basic', $d->authModes, true) || in_array('header', $d->authModes, true)
                ? 'Re-enter the token, user and password or header under Authentication so it matches ' . $n . '. For URL-as-secret platforms, regenerate the URL.' : 'Regenerate the URL on the platform and paste the full address again.'],
            ['404 Not Found', 'The URL is wrong, incomplete or points at a test or inactive endpoint.', 'Copy the address again from ' . $n . ' (it should look like ' . $d->urlHint . ').'],
            ['429 Too Many Requests', 'The receiver rate-limits the sender.', 'Failed deliveries are retried automatically; narrow the subscribed events so fewer messages are sent.'],
            ['Timeouts or "no response"', 'The receiver answers slowly or not at all.', 'Answer with a 2xx immediately and do the work asynchronously; any 2xx counts as delivered.'],
            ['URL blocked or address refused', 'The host resolves to a private or internal address, which webhooks may not call by default.', 'Add the network under "Internal network access" on the Webhooks page. Loopback, link-local and cloud-metadata addresses are never allowed.'],
            ['Signature mismatch', 'The check ran on re-serialised JSON instead of the raw body, or used a different secret.', 'Verify X-Rivet-Signature-V2 over "<timestamp>.<raw body>" with this webhook\'s signing secret.'],
            ['Timestamp too old', 'The receiver\'s clock is out of sync, so the 5 minute tolerance is exceeded.', 'Enable NTP time sync on the receiver and keep the tolerance at 300 seconds.'],
        ];
        foreach ($generic as $g) {
            $rows[] = [$g[0], $g[1], $g[2], 'generic'];
        }

        return $rows;
    }

    /** A code block: header with language label, wrap toggle and copy button. Highlighting is done by js/webhook_guides.js. */
    function wgCode(string $code, string $lang, string $id, string $title = ''): void
    {
        ?>
        <figure class="wg-code" data-lang="<?= wgH($lang) ?>">
            <figcaption class="wg-code-head">
                <span class="wg-code-lang"><?= wgH($title !== '' ? $title : $lang) ?></span>
                <span class="wg-code-tools">
                    <button type="button" class="wg-btn-ghost" data-wg-wrap aria-pressed="false" title="Toggle line wrapping"><i class="fas fa-align-left" aria-hidden="true"></i><span class="visually-hidden">Wrap lines</span></button>
                    <button type="button" class="wg-btn-ghost" data-wg-copy="#<?= wgH($id) ?>" aria-label="Copy to clipboard"><i class="far fa-copy" aria-hidden="true"></i> <span data-wg-copy-label>Copy</span></button>
                </span>
            </figcaption>
            <pre tabindex="0"><code id="<?= wgH($id) ?>"><?= wgH($code) ?></code></pre>
        </figure>
        <?php
    }

    /** Language tabs for a set of snippets (arrow keys move between tabs; the chosen language is shared across the page). */
    function wgSnippetTabs(array $snippets, string $prefix): void
    {
        $i = 0;
        ?>
        <div class="wg-tabs" data-wg-tabs>
            <div class="wg-tablist" role="tablist" aria-label="Language">
                <?php foreach ($snippets as $lang => $code) { $i++; $lang = (string) $lang; ?>
                <button type="button" role="tab" class="wg-tab" id="<?= wgH($prefix . '-tab-' . wgSlug($lang)) ?>" data-wg-lang="<?= wgH(wgSlug($lang)) ?>" aria-controls="<?= wgH($prefix . '-pane-' . wgSlug($lang)) ?>" aria-selected="<?= $i === 1 ? 'true' : 'false' ?>" tabindex="<?= $i === 1 ? '0' : '-1' ?>"><?= wgH(wgLang($lang)[1]) ?></button>
                <?php } ?>
            </div>
            <?php $i = 0; foreach ($snippets as $lang => $code) { $i++; $lang = (string) $lang; ?>
            <div class="wg-tabpanel" role="tabpanel" id="<?= wgH($prefix . '-pane-' . wgSlug($lang)) ?>" aria-labelledby="<?= wgH($prefix . '-tab-' . wgSlug($lang)) ?>" <?= $i === 1 ? '' : 'hidden' ?>>
                <?php wgCode((string) $code, wgLang($lang)[0], $prefix . '-c-' . wgSlug($lang), wgLang($lang)[1]); ?>
            </div>
            <?php } ?>
        </div>
        <?php
    }

    /** One index card. */
    function wgCard(Destination $d, array $cats): void
    {
        $needs = wgNeeds($d);
        ?>
        <article class="wg-card" data-wg-item data-cat="<?= wgH($d->category) ?>" data-search="<?= wgH(strtolower($d->name . ' ' . $d->id . ' ' . $d->description . ' ' . ($cats[$d->category] ?? $d->category) . ' ' . $d->format)) ?>">
            <div class="wg-card-top">
                <?= wgBadge($d) ?>
                <div class="min-w-0">
                    <h3 class="wg-card-title"><?= wgH($d->name) ?></h3>
                    <span class="wg-chip wg-chip-cat"><i class="fas <?= wgH(wgCategoryIcon($d->category)) ?>" aria-hidden="true"></i> <?= wgH($cats[$d->category] ?? $d->category) ?></span>
                </div>
            </div>
            <p class="wg-card-desc"><?= wgH($d->description) ?></p>
            <ul class="wg-pills" aria-label="Summary">
                <li class="wg-pill"><i class="far fa-clock" aria-hidden="true"></i> Setup time: <?= wgH(wgSetupTime($d)) ?></li>
                <li class="wg-pill"><i class="fas fa-key" aria-hidden="true"></i> Needs: <?= wgH(implode(', ', $needs)) ?></li>
            </ul>
            <div class="wg-card-actions">
                <a class="btn btn-sm btn-primary" href="#<?= wgH($d->id) ?>">View guide</a>
                <a class="btn btn-sm btn-outline-primary" href="settings_webhooks.php?add=<?= wgH(rawurlencode($d->id)) ?>"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add webhook</a>
            </div>
        </article>
        <?php
    }

    /**
     * The full guide for one platform.
     *
     * @param array{prev:?Destination,next:?Destination,related:list<Destination>} $nav
     */
    function wgGuide(Destination $d, array $cats, array $nav, bool $n8nWalkthrough = false): void
    {
        $id = wgH($d->id);
        $p = 'wg-' . wgSlug($d->id);
        $authLabels = ['none' => 'none', 'hmac' => 'our signature only', 'bearer' => 'bearer token', 'basic' => 'basic (user + password)', 'header' => 'custom header'];
        $ex = wgExample($d);
        $docs = preg_match('#^https?://#', $d->docsUrl) === 1;
        $canVerify = $d->verifySnippets !== [];
        $toc = ['need' => 'What you need', 'setup' => 'Setup steps'];
        if ($n8nWalkthrough) {
            $toc['walkthrough'] = 'Receiving in n8n';
        }
        if ($d->notes) {
            $toc['notes'] = 'Things to know';
        }
        $toc['try'] = 'Try it';
        $toc['verify'] = 'Verify our signature';
        $toc['example'] = 'Example payload';
        $toc['trouble'] = 'Troubleshooting';
        $toc['related'] = 'Related';
        ?>
        <section class="wg-guide" id="<?= $id ?>" data-wg-guide data-guide="<?= $id ?>" aria-labelledby="<?= $p ?>-title">
            <nav class="wg-crumbs d-print-none" aria-label="Breadcrumb"><a href="#guides">All guides</a> <span aria-hidden="true">/</span> <span><?= wgH($cats[$d->category] ?? $d->category) ?></span> <span aria-hidden="true">/</span> <span><?= wgH($d->name) ?></span></nav>
            <header class="wg-guide-head">
                <?= wgBadge($d, 'wg-badge-lg') ?>
                <div class="wg-guide-titles">
                    <h2 id="<?= $p ?>-title"><?= wgH($d->name) ?></h2>
                    <div class="wg-meta">
                        <span class="wg-chip wg-chip-cat"><i class="fas <?= wgH(wgCategoryIcon($d->category)) ?>" aria-hidden="true"></i> <?= wgH($cats[$d->category] ?? $d->category) ?></span>
                        <span class="wg-chip"><i class="far fa-clock" aria-hidden="true"></i> <?= wgH(wgSetupTime($d)) ?></span>
                        <span class="wg-chip"><code><?= wgH($d->method) ?></code> <code><?= wgH($d->format) ?></code></span>
                        <?php if ($docs) { ?><a class="wg-chip wg-chip-link" href="<?= wgH($d->docsUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="fas fa-external-link-alt" aria-hidden="true"></i> Vendor documentation</a><?php } ?>
                    </div>
                    <p class="wg-lead"><?= wgH($d->description) ?></p>
                </div>
                <a class="btn btn-primary d-print-none" href="settings_webhooks.php?add=<?= wgH(rawurlencode($d->id)) ?>"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add <?= wgH($d->name) ?> webhook</a>
            </header>

            <div class="wg-guide-grid">
                <div class="wg-guide-main">
                    <section id="<?= $id ?>--need" class="wg-block" aria-labelledby="<?= $p ?>-h-need" data-wg-toc="<?= wgH($toc['need']) ?>">
                        <h3 id="<?= $p ?>-h-need"><i class="fas fa-clipboard-check" aria-hidden="true"></i> What you need</h3>
                        <ul class="wg-checklist" data-wg-checks="<?= $id ?>:need">
                            <li><label><input type="checkbox"> <span>A destination URL that looks like <code class="text-break"><?= wgH($d->urlHint) ?></code></span></label></li>
                            <?php foreach ($d->extraFields as $f) { if (!$f->required) { continue; } ?>
                            <li><label><input type="checkbox"> <span><strong><?= wgH($f->label) ?></strong>: <?= wgH($f->help) ?><?= $f->type === 'secret' ? ' <em>(kept secret)</em>' : '' ?></span></label></li>
                            <?php } ?>
                            <li><label><input type="checkbox"> <span>Authentication, optional: <?= wgH(implode(', ', array_map(static fn (string $m): string => $authLabels[$m] ?? $m, $d->authModes))) ?></span></label></li>
                            <?php if (preg_grep('/internal network/i', $d->notes)) { ?>
                            <li><label><input type="checkbox"> <span>If it runs on your own network, add that network under <a href="settings_webhooks.php#internal-networks">Internal network access</a> first</span></label></li>
                            <?php } ?>
                        </ul>
                    </section>

                    <section id="<?= $id ?>--setup" class="wg-block" aria-labelledby="<?= $p ?>-h-setup" data-wg-toc="<?= wgH($toc['setup']) ?>">
                        <h3 id="<?= $p ?>-h-setup"><i class="fas fa-list-ol" aria-hidden="true"></i> Setup steps <span class="wg-progress" data-wg-progress aria-live="polite"></span></h3>
                        <ol class="wg-steps" data-wg-checks="<?= $id ?>:steps">
                            <?php foreach ($d->setupSteps as $i => $step) { ?>
                            <li class="wg-step"><label class="wg-step-card"><span class="wg-step-num"><?= $i + 1 ?></span><span class="wg-step-text"><?= wgH($step) ?></span><input type="checkbox" class="wg-step-check" aria-label="Mark step <?= $i + 1 ?> done"></label></li>
                            <?php } ?>
                        </ol>
                    </section>

                    <?php if ($n8nWalkthrough) { ?>
                    <section id="n8n-walkthrough" class="wg-block" aria-labelledby="<?= $p ?>-h-walkthrough" data-wg-toc="Receiving in n8n">
                        <h3 id="<?= $p ?>-h-walkthrough"><i class="fas fa-route" aria-hidden="true"></i> Receiving in n8n: walk-through</h3>
                        <ol class="wg-steps" data-wg-checks="n8n:walkthrough">
                            <?php
                            $wt = [
                                'In n8n choose <strong>Create workflow</strong>, then <strong>Add first step</strong> and pick the <strong>Webhook</strong> trigger.',
                                'Set <strong>HTTP Method</strong> to <code>POST</code> (n8n defaults to GET) and give it a <strong>Path</strong>, for example <code>rivetit</code>.',
                                'Under <strong>Authentication</strong> choose <em>Header Auth</em> or <em>Basic Auth</em> if you want n8n to check who is calling, create the credential, and enter the same header or user and password here under <em>Authentication</em>. Leave it on <em>None</em> and rely on our signature if you prefer.',
                                'Open <strong>Options</strong> and add <strong>Raw Body</strong> (on) if you want to verify <code>X-Rivet-Signature-V2</code>; set <strong>Respond</strong> to <em>Immediately</em>.',
                                'Click <strong>Listen for test event</strong> (the node now waits on the <code>/webhook-test/</code> URL), then in RivetIT press <strong>Send test</strong>. The sample ticket appears in n8n: the JSON is under <code>{{ $json.body }}</code> (for example <code>{{ $json.body.data.ticket_number }}</code>) and the headers under <code>{{ $json.headers }}</code>.',
                                'Build the rest of the workflow (an <em>IF</em> node on <code>{{ $json.body.data.ticket_priority }}</code>, a Slack, e-mail or HTTP Request node ...). To check our signature add the <em>n8n Code</em> snippet below right after the Webhook node.',
                                '<strong>Activate</strong> the workflow, copy the <strong>Production URL</strong> (it contains <code>/webhook/</code>, not <code>/webhook-test/</code>) into the URL field here and save.',
                                'If n8n runs on your own network, add that network under <em>Internal network access</em> on the Webhooks page first, otherwise the address is refused.',
                            ];
                            foreach ($wt as $i => $html) { ?>
                            <li class="wg-step"><label class="wg-step-card"><span class="wg-step-num"><?= $i + 1 ?></span><span class="wg-step-text"><?= $html /* static, trusted markup */ ?></span><input type="checkbox" class="wg-step-check" aria-label="Mark walk-through step <?= $i + 1 ?> done"></label></li>
                            <?php } ?>
                        </ol>
                    </section>
                    <?php } ?>

                    <?php if ($d->notes) { ?>
                    <section id="<?= $id ?>--notes" class="wg-block" aria-labelledby="<?= $p ?>-h-notes" data-wg-toc="<?= wgH($toc['notes']) ?>">
                        <h3 id="<?= $p ?>-h-notes"><i class="fas fa-lightbulb" aria-hidden="true"></i> Things to know</h3>
                        <?php foreach ($d->notes as $note) { $lv = wgNoteLevel($note); $ic = ['danger' => 'fa-exclamation-circle', 'warning' => 'fa-exclamation-triangle', 'info' => 'fa-info-circle'][$lv]; ?>
                        <div class="wg-callout wg-callout-<?= $lv ?>" role="note"><i class="fas <?= $ic ?>" aria-hidden="true"></i><div><strong class="wg-callout-label"><?= ['danger' => 'Important', 'warning' => 'Watch out', 'info' => 'Good to know'][$lv] ?></strong> <?= wgH($note) ?></div></div>
                        <?php } ?>
                    </section>
                    <?php } ?>

                    <section id="<?= $id ?>--try" class="wg-block wg-try" aria-labelledby="<?= $p ?>-h-try" data-wg-toc="<?= wgH($toc['try']) ?>">
                        <h3 id="<?= $p ?>-h-try"><i class="fas fa-terminal" aria-hidden="true"></i> Try it</h3>
                        <p class="text-secondary">What this platform receives, as a curl command. Replace the placeholders, run it from a shell, then add the webhook here.</p>
                        <?php wgCode($d->sampleCurl, 'bash', $p . '-curl', 'curl (bash)'); ?>
                        <a class="btn btn-primary d-print-none" href="settings_webhooks.php?add=<?= wgH(rawurlencode($d->id)) ?>"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add this webhook</a>
                    </section>

                    <section id="<?= $id ?>--verify" class="wg-block" aria-labelledby="<?= $p ?>-h-verify" data-wg-toc="<?= wgH($toc['verify']) ?>">
                        <h3 id="<?= $p ?>-h-verify"><i class="fas fa-signature" aria-hidden="true"></i> Verify our signature</h3>
                        <?php if ($canVerify) { ?>
                        <p class="text-secondary">Every request carries <code>X-Rivet-Timestamp</code> and <code>X-Rivet-Signature-V2</code> (HMAC-SHA256 of <code>&lt;timestamp&gt;.&lt;raw body&gt;</code> with this webhook's signing secret). Check it against the raw body and reject anything older than 5 minutes. <a href="#signing">How signing works</a></p>
                        <?php wgSnippetTabs($d->verifySnippets, $p . '-v'); ?>
                        <?php } else { ?>
                        <div class="wg-callout wg-callout-warning" role="note"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><div>This platform cannot check a signature (it does not expose the raw request). The signature headers are still sent; the destination URL is the secret, so keep it private.</div></div>
                        <?php } ?>
                    </section>

                    <section id="<?= $id ?>--example" class="wg-block" aria-labelledby="<?= $p ?>-h-example" data-wg-toc="<?= wgH($toc['example']) ?>">
                        <h3 id="<?= $p ?>-h-example"><i class="fas fa-file-code" aria-hidden="true"></i> Example payload</h3>
                        <details class="wg-details" data-wg-details>
                            <summary>Show what <?= wgH($d->name) ?> receives for a new ticket</summary>
                            <?php if ($ex['error'] !== '') { ?>
                            <p class="text-secondary mb-0">The example needs a value this guide cannot supply (<?= wgH($ex['error']) ?>); use <em>Preview payload</em> in the Add webhook form.</p>
                            <?php } else { ?>
                            <p class="text-secondary small">Sample event <code>ticket.created</code> formatted as <code><?= wgH($d->format) ?></code> (<code><?= wgH($ex['type']) ?></code>). Headers added by the format<?= $ex['headers'] ? ':' : ': none' ?> <?= wgH(implode(', ', array_keys($ex['headers']))) ?>. No secrets are included.</p>
                            <?php wgCode($ex['body'], $ex['lang'], $p . '-example', 'Example body'); ?>
                            <?php } ?>
                        </details>
                    </section>

                    <section id="<?= $id ?>--trouble" class="wg-block" aria-labelledby="<?= $p ?>-h-trouble" data-wg-toc="<?= wgH($toc['trouble']) ?>">
                        <h3 id="<?= $p ?>-h-trouble"><i class="fas fa-wrench" aria-hidden="true"></i> Troubleshooting</h3>
                        <div class="wg-table-wrap">
                            <table class="wg-table">
                                <thead><tr><th scope="col">Symptom</th><th scope="col">Likely cause</th><th scope="col">Fix</th></tr></thead>
                                <tbody>
                                <?php foreach (wgTroubleshooting($d) as $r) { ?>
                                <tr<?= $r[3] === 'platform' ? ' class="wg-row-platform"' : '' ?>><th scope="row"><?= wgH($r[0]) ?><?= $r[3] === 'platform' ? ' <span class="wg-chip wg-chip-cat">' . wgH($d->name) . '</span>' : '' ?></th><td><?= wgH($r[1]) ?></td><td><?= wgH($r[2]) ?></td></tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section id="<?= $id ?>--related" class="wg-block d-print-none" aria-labelledby="<?= $p ?>-h-related" data-wg-toc="<?= wgH($toc['related']) ?>">
                        <h3 id="<?= $p ?>-h-related"><i class="fas fa-link" aria-hidden="true"></i> Related</h3>
                        <ul class="wg-related">
                            <?php foreach ($nav['related'] as $r) { ?><li><a href="#<?= wgH($r->id) ?>"><?= wgBadge($r, 'wg-badge-sm') ?> <?= wgH($r->name) ?></a></li><?php } ?>
                            <li><a href="#signing"><i class="fas fa-signature" aria-hidden="true"></i> How signing works</a></li>
                            <li><a href="#networks"><i class="fas fa-network-wired" aria-hidden="true"></i> Allowed internal networks</a></li>
                            <li><a href="settings_webhooks.php"><i class="fas fa-plug" aria-hidden="true"></i> Your webhooks</a></li>
                        </ul>
                    </section>

                    <nav class="wg-pager d-print-none" aria-label="Guide navigation">
                        <?php if ($nav['prev']) { ?><a class="wg-pager-link" rel="prev" href="#<?= wgH($nav['prev']->id) ?>"><span class="wg-pager-dir"><i class="fas fa-arrow-left" aria-hidden="true"></i> Previous</span><span><?= wgH($nav['prev']->name) ?></span></a><?php } else { ?><span></span><?php } ?>
                        <?php if ($nav['next']) { ?><a class="wg-pager-link wg-pager-next" rel="next" href="#<?= wgH($nav['next']->id) ?>"><span class="wg-pager-dir">Next <i class="fas fa-arrow-right" aria-hidden="true"></i></span><span><?= wgH($nav['next']->name) ?></span></a><?php } ?>
                    </nav>
                </div>
                <aside class="wg-toc d-print-none" aria-label="On this page">
                    <div class="wg-toc-inner">
                        <div class="wg-side-title">On this page</div>
                        <ul>
                            <?php foreach ($toc as $k => $label) { ?><li><a href="#<?= $k === 'walkthrough' ? 'n8n-walkthrough' : $id . '--' . $k ?>"><?= wgH($label) ?></a></li><?php } ?>
                        </ul>
                    </div>
                </aside>
            </div>
        </section>
        <?php
    }
}
