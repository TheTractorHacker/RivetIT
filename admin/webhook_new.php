<?php
require_once "includes/inc_all_admin.php";
require_once "includes/webhook_events.php";
require_once "../includes/event_bus.php";
require_once "includes/webhook_form_parts.php";

use RivetCore\Webhooks\Destinations;

// Add webhook, as a four-step flow: Platform > Connect > Events > Review & test. One page, one <form>; js/webhook_wizard.js shows one step at a
// time, validates each before it continues and keeps the address in step with ?dest=<platform>&step=<step> so every step can be linked.
// The form still posts the plain way (add_webhook, admin/post/webhook_new.php, same handler as the list) when a script posts it without wh_ajax.
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$d = Destinations::get((string) ($_GET['dest'] ?? ($_GET['destination'] ?? '')));
$step = (string) ($_GET['step'] ?? '');
$step = $d === null ? 'platform' : (in_array($step, ['platform', 'connect', 'events', 'review'], true) ? $step : 'connect');
$ctx = $d !== null ? webhookFormContext($d, null, false) : null;
$popular = ['n8n', 'slack', 'discord', 'teams', 'ntfy', 'home-assistant', 'generic-json'];
$cat_labels = Destinations::categoryLabels();
$asset = static fn (string $f): string => '/' . $f . '?v=' . (is_file(__DIR__ . '/../' . $f) ? filemtime(__DIR__ . '/../' . $f) : time());

$card = static function (\RivetCore\Webhooks\Destination $dd, string $extra = '') use ($h): void {
    [$fs, $fi] = webhookBrandIcon($dd);
    ?>
    <a class="wz-pcard<?= $extra ?>" href="webhook_new.php?dest=<?= $h(rawurlencode($dd->id)) ?>&amp;step=connect" data-wz-dest="<?= $h($dd->id) ?>" data-wz-cat="<?= $h($dd->category) ?>"
       data-wz-search="<?= $h(strtolower($dd->name . ' ' . $dd->id . ' ' . $dd->description . ' ' . $dd->format . ' ' . $dd->category)) ?>" title="<?= $h($dd->description) ?>">
        <span class="wz-pcard-icon"><i class="<?= $h($fs . ' ' . $fi) ?>" aria-hidden="true"></i></span>
        <span class="wz-pcard-text"><span class="wz-pcard-name"><?= $h($dd->name) ?></span><span class="wz-pcard-desc"><?= $h($dd->description) ?></span></span>
    </a>
    <?php
};
?>
<link rel="stylesheet" href="<?= $asset('css/webhook_form.css') ?>">

<div class="wz" data-wz data-wz-mode="add" data-wz-step="<?= $h($step) ?>" data-wz-dest="<?= $h($d->id ?? '') ?>" data-wz-dest-name="<?= $h($d->name ?? '') ?>"
     data-wz-action="modals/webhook/webhook_action.php" data-wz-guide="modals/webhook/webhook_guide.php" data-wz-list="settings_webhooks.php">
    <div class="wz-head">
        <div class="me-auto">
            <h1 class="wz-title"><i class="fas fa-fw fa-satellite-dish me-2" aria-hidden="true"></i>Add webhook</h1>
            <p class="wz-sub">Send selected events to another service. Four short steps; nothing is saved until the last one.</p>
        </div>
        <a href="settings_webhook_guides.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-book me-1" aria-hidden="true"></i>Guides</a>
        <a href="settings_webhooks.php" class="btn btn-outline-secondary btn-sm" data-wz-cancel>Cancel</a>
    </div>

    <nav class="wz-stepper" aria-label="Progress">
        <div class="wz-progress" aria-hidden="true"><div class="wz-progress-bar" data-wz-bar></div></div>
        <ol class="wz-steps">
            <?php foreach (['platform' => 'Platform', 'connect' => 'Connect', 'events' => 'Events', 'review' => 'Review & test'] as $k => $label) { ?>
            <li data-wz-stepnav="<?= $k ?>"><button type="button" class="wz-stepbtn"><span class="wz-num" aria-hidden="true"><?= array_search($k, ['platform', 'connect', 'events', 'review'], true) + 1 ?></span><span class="wz-steplabel"><?= $h($label) ?></span></button></li>
            <?php } ?>
        </ol>
        <div class="wz-stepcount" data-wz-stepcount aria-live="polite"></div>
    </nav>

    <div class="wz-layout" data-wz-layout>
    <form action="post.php" method="post" autocomplete="off" novalidate data-wz-form class="wz-main">
        <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
        <?php if ($d !== null) { ?>
        <input type="hidden" name="webhook_destination" value="<?= $h($d->id) ?>">
        <input type="hidden" name="webhook_type" value="<?= $h($ctx['type']) ?>">
        <?php } ?>

        <section class="wz-step" data-wz-step-panel="platform" aria-labelledby="wz-h-platform">
            <h2 class="wz-h" id="wz-h-platform" tabindex="-1">Where should the events go?</h2>
            <div class="wz-chooser" data-wz-chooser>
                <div class="wz-tools">
                    <input type="search" class="form-control" placeholder="Search platforms (n8n, ntfy, Discord, Telegram ...)" aria-label="Search platforms" autocomplete="off" data-wz-psearch>
                    <div class="wz-catchips" role="group" aria-label="Filter by kind">
                        <button type="button" class="wz-chip is-on" data-wz-catchip="" aria-pressed="true">All</button>
                        <?php foreach ($cat_labels as $cat => $label) { ?><button type="button" class="wz-chip" data-wz-catchip="<?= $h($cat) ?>" aria-pressed="false"><?= $h($label) ?></button><?php } ?>
                    </div>
                </div>
                <div class="wz-recent" data-wz-recent hidden>
                    <div class="wz-cat-title">Recently used</div>
                    <div class="wz-pgrid" data-wz-recent-grid></div>
                </div>
                <div class="wz-popular" data-wz-popular>
                    <div class="wz-cat-title">Popular</div>
                    <div class="wz-pgrid">
                        <?php foreach ($popular as $pid) { if (($pd = Destinations::get($pid)) !== null) { $card($pd, ' is-popular'); } } ?>
                    </div>
                </div>
                <?php foreach (Destinations::byCategory() as $cat => $list) { ?>
                <div class="wz-cat" data-wz-cat-block="<?= $h($cat) ?>">
                    <div class="wz-cat-title"><?= $h($cat_labels[$cat] ?? $cat) ?></div>
                    <div class="wz-pgrid">
                        <?php foreach ($list as $dd) { $card($dd); } ?>
                    </div>
                </div>
                <?php } ?>
                <p class="text-secondary mt-3" data-wz-pnone hidden>No platform matches. Use <strong>Generic JSON</strong> or <strong>Custom template</strong> for anything else.</p>
            </div>
        </section>

        <?php if ($d !== null) { [$fs, $fi] = webhookBrandIcon($d); ?>
        <section class="wz-step" data-wz-step-panel="connect" aria-labelledby="wz-h-connect" hidden>
            <div class="wz-stephead">
                <h2 class="wz-h" id="wz-h-connect" tabindex="-1">Connect <?= $h($d->name) ?></h2>
                <span class="wz-platform"><span class="wz-pcard-icon"><i class="<?= $h($fs . ' ' . $fi) ?>" aria-hidden="true"></i></span><?= $h($d->name) ?> <a href="webhook_new.php" data-wz-change>Change</a></span>
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-wz-help><i class="far fa-life-ring me-1" aria-hidden="true"></i>Need help?</button>
            </div>
            <p class="wz-lead"><?= $h($d->description) ?></p>
            <div class="wz-errbox" data-wz-steperrors role="alert" hidden></div>
            <?php webhookPartConnect($ctx); ?>

            <details class="wz-advanced" data-wz-advanced>
                <summary><span class="wz-adv-title"><i class="fas fa-sliders-h me-2" aria-hidden="true"></i>Advanced options</span>
                    <span class="wz-adv-sub">Method, signing secret, extra headers, payload format, routing filters</span></summary>
                <div class="wz-adv-body"><?php webhookPartAdvanced($ctx); ?></div>
            </details>
        </section>

        <section class="wz-step" data-wz-step-panel="events" aria-labelledby="wz-h-events" hidden>
            <div class="wz-stephead">
                <h2 class="wz-h" id="wz-h-events" tabindex="-1">Which events?</h2>
                <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-wz-help><i class="far fa-life-ring me-1" aria-hidden="true"></i>Need help?</button>
            </div>
            <p class="wz-lead">Pick a quick selection, then fine-tune it. Nothing is chosen by default.</p>
            <div class="wz-errbox" data-wz-steperrors role="alert" hidden></div>
            <?php webhookPartEvents($ctx); ?>
        </section>

        <section class="wz-step" data-wz-step-panel="review" aria-labelledby="wz-h-review" hidden>
            <h2 class="wz-h" id="wz-h-review" tabindex="-1">Review and test</h2>
            <div class="wz-errbox" data-wz-steperrors role="alert" hidden></div>
            <div class="wz-card">
                <div class="wz-card-title">What will be created</div>
                <dl class="wz-summary" data-wz-summary></dl>
                <div class="form-check form-switch mt-2">
                    <input type="checkbox" class="form-check-input" id="wz-enabled" name="webhook_enabled" value="1" checked>
                    <label class="form-check-label" for="wz-enabled">Enabled: start sending as soon as it is created</label>
                </div>
            </div>
            <div class="wz-card">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <div class="wz-card-title me-auto mb-0">Payload preview</div>
                    <label class="small text-secondary mb-0" for="wz-sample">Sample event</label>
                    <select class="form-select form-select-sm w-auto" id="wz-sample" data-wz-sample aria-label="Sample event"></select>
                </div>
                <div data-wz-preview aria-live="polite"><span class="text-secondary small">Building the preview...</span></div>
            </div>
            <div class="wz-card">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="me-auto"><div class="wz-card-title mb-0">Try it before you save</div><div class="small text-secondary">Sends one clearly labelled test event through the real delivery path. Nothing is saved.</div></div>
                    <button type="button" class="btn btn-outline-primary" data-wz-test><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Send test</button>
                </div>
                <div class="wz-result" data-wz-result hidden role="status" aria-live="polite"></div>
            </div>
        </section>

        <section class="wz-step wz-done" data-wz-step-panel="done" aria-labelledby="wz-h-done" hidden>
            <div class="wz-done-icon" aria-hidden="true"><i class="fas fa-check"></i></div>
            <h2 class="wz-h" id="wz-h-done" tabindex="-1" data-wz-donetitle>Webhook created</h2>
            <p class="wz-lead" data-wz-donesub></p>
            <div class="wz-result" data-wz-doneresult hidden role="status" aria-live="polite"></div>
            <div class="wz-actions">
                <button type="button" class="btn btn-primary" data-wz-testagain><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Send test again</button>
                <a class="btn btn-outline-secondary" href="webhook_new.php"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add another</a>
                <a class="btn btn-outline-secondary" data-wz-deliveries href="settings_webhooks.php"><i class="fas fa-bolt me-1" aria-hidden="true"></i>View deliveries</a>
                <button type="button" class="btn btn-outline-secondary" data-wz-help><i class="far fa-life-ring me-1" aria-hidden="true"></i>Open guide</button>
                <a class="btn btn-link" href="settings_webhooks.php">Back to all webhooks</a>
            </div>
        </section>
        <?php } ?>

        <div class="wz-footer" data-wz-footer>
            <button type="button" class="btn btn-outline-secondary" data-wz-back><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Back</button>
            <span class="wz-keyhint d-none d-md-inline" data-wz-keyhint>Enter to continue, Esc to go back</span>
            <button type="button" class="btn btn-outline-primary" data-wz-create-test hidden><i class="fas fa-paper-plane me-1" aria-hidden="true"></i>Create and send test</button>
            <button type="button" class="btn btn-primary wz-next" data-wz-next>Continue<i class="fas fa-arrow-right ms-2" aria-hidden="true"></i></button>
        </div>
    </form>

    <aside class="wz-slideover" data-wz-slideover hidden aria-label="Setup guide" role="dialog" aria-modal="false">
        <div class="wz-slideover-head">
            <strong class="me-auto"><i class="far fa-life-ring me-1" aria-hidden="true"></i>Setup guide<?= $d !== null ? ': ' . $h($d->name) : '' ?></strong>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-wz-guide-close aria-label="Close the guide"><i class="fas fa-times" aria-hidden="true"></i></button>
        </div>
        <div class="wz-slideover-body" data-wz-guide-body><span class="text-secondary small">Loading the guide...</span></div>
    </aside>
    </div>
    <div class="wz-backdrop" data-wz-backdrop hidden></div>
</div>
<script src="<?= $asset('js/webhook_wizard.js') ?>" defer></script>
<?php require_once "../includes/footer.php"; ?>
