<?php
// The Add / Edit webhook form for one platform (RivetCore destination preset): fields, events picker and the setup guide panel.
// Expects: $d (RivetCore\Webhooks\Destination), $wh (the webhooks row, or null when adding), $editing (bool).
// Secrets are never echoed: a stored secret shows a "saved, leave blank to keep" hint and the server keeps it when the field is blank.

use ITFlow\Webhooks\ChatFormatter;
use ITFlow\Webhooks\DestinationConfig;
use RivetCore\Webhooks\Authentication;
use RivetCore\Webhooks\PayloadTemplate;

require_once __DIR__ . '/../../../includes/event_picker.php';
require_once __DIR__ . '/../../../includes/webhook_guide.php';

$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$wh = $wh ?? null;
$same = $editing && $wh !== null && DestinationConfig::effectiveDestinationId($wh) === $d->id;
$stored_extra = $same ? DestinationConfig::extra($wh) : [];
$stored_auth = $same ? DestinationConfig::authConfig($wh) : ['mode' => $d->defaultAuth];
$type = $d->id === 'slack' ? 'slack' : ($d->id === 'teams' ? 'teams' : 'generic');
$is_chat_edition = ChatFormatter::isChatType($type);
$url_secret = $is_chat_edition || DestinationConfig::urlIsSecret($d->id);
$has_url_fields = false;
foreach ($d->extraFields as $f) {
    if ($f->target === 'url') {
        $has_url_fields = true;
    }
}
$stored_url = $same ? decryptSetting((string) ($wh['webhook_url'] ?? '')) : '';
$url_prefill = '';
if (!$editing && (str_contains($d->urlHint, '{') || $has_url_fields)) {
    $url_prefill = $d->urlHint;       // keeps {topic}, {txn} ... in view so it is clear what the preset builds
} elseif ($editing && $same && !$url_secret) {
    $url_prefill = $stored_url;       // a plain-URL preset (generic JSON/form, custom template) is shown and editable
}
$auth_mode = (string) ($stored_auth['mode'] ?? $d->defaultAuth);
if (!in_array($auth_mode, $d->authModes, true)) {
    $auth_mode = $d->defaultAuth;
}
$wh_events = $wh ? array_values(array_filter(array_map('trim', explode(',', (string) $wh['webhook_events'])))) : ['ticket.created'];
$wh_method = $same ? (string) ($wh['webhook_method'] ?? '') : '';
$methods = DestinationConfig::allowedMethods($d);
$wh_method = in_array($wh_method, $methods, true) ? $wh_method : $methods[0];
$tpl = $same && (string) ($wh['webhook_template'] ?? '') !== '' ? (string) $wh['webhook_template'] : (string) ($d->formatOptions['template'] ?? '');
$tpl_enc = (string) ($stored_extra['template_encoding'] ?? ($d->formatOptions['template_encoding'] ?? 'json'));
$has_secret = $same && (string) ($wh['webhook_secret'] ?? '') !== '';
$signs = $d->verifySnippets !== [] && !$is_chat_edition;
$gen_secret = !$editing && $signs ? bin2hex(random_bytes(20)) : '';
$routing = in_array($d->category, ['chat', 'notify'], true);
$dest_min = $same ? (string) ($wh['webhook_min_priority'] ?? '') : '';
$dest_clients = $same ? array_map('intval', array_filter(explode(',', (string) ($wh['webhook_client_ids'] ?? '')))) : [];
$uid = 'whf' . bin2hex(random_bytes(3));
?>
<input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
<input type="hidden" name="webhook_destination" value="<?= $h($d->id) ?>">
<input type="hidden" name="webhook_type" value="<?= $h($type) ?>">
<?php if ($editing) { ?><input type="hidden" name="webhook_id" value="<?= intval($wh['webhook_id']) ?>"><?php } ?>

<div class="wh-form-wrap">
<div class="wh-form-main">

    <div class="form-group mb-3">
        <label class="form-label" for="<?= $uid ?>-name">Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="<?= $uid ?>-name" name="webhook_name" maxlength="200" required value="<?= $h($wh['webhook_name'] ?? '') ?>" placeholder="e.g. <?= $h($d->name) ?> alerts">
    </div>

    <div class="form-group mb-3">
        <label class="form-label" for="<?= $uid ?>-url"><?= $d->id === 'custom-template' || !$url_secret ? 'Endpoint URL' : $h($d->name) . ' URL' ?> <span class="text-danger">*</span></label>
        <input type="text" inputmode="url" class="form-control font-monospace" id="<?= $uid ?>-url" name="webhook_url" autocomplete="off" spellcheck="false"
               value="<?= $h($url_prefill) ?>" placeholder="<?= $h($editing && $url_secret ? 'Saved. Leave blank to keep it.' : $d->urlHint) ?>"
               <?= $editing && $url_secret ? '' : 'required' ?>>
        <small class="text-secondary d-block">
            Looks like <code class="text-break"><?= $h($d->urlHint) ?></code>.
            <?php if ($url_secret) { ?>It is stored encrypted and never shown again.<?php } ?>
            <?php if ($has_url_fields) { ?>Parts in braces are filled from the fields below.<?php } ?>
        </small>
    </div>

    <?php foreach ($d->extraFields as $f) {
        $fid = $uid . '-x-' . $f->name;
        $is_url = $f->target === 'url';
        $val = !$is_url && $f->type !== 'secret' ? (string) ($stored_extra[$f->name] ?? '') : '';
        $saved = $editing && $same && ($is_url || $f->type === 'secret');
        ?>
    <div class="form-group mb-3">
        <label class="form-label" for="<?= $h($fid) ?>"><?= $h($f->label) ?><?= $f->required ? ' <span class="text-danger">*</span>' : ' <small class="text-secondary">(optional)</small>' ?></label>
        <input type="<?= $f->type === 'secret' ? 'password' : ($f->type === 'number' ? 'number' : 'text') ?>" class="form-control<?= $f->type === 'secret' ? ' font-monospace' : '' ?>" id="<?= $h($fid) ?>" name="extra[<?= $h($f->name) ?>]"
               value="<?= $h($val) ?>" autocomplete="off" spellcheck="false" maxlength="200"
               placeholder="<?= $h($saved ? 'Saved. Leave blank to keep it.' : $f->example) ?>"
               <?= $f->required && !$saved && !$has_url_fields ? 'required' : '' ?>>
        <small class="text-secondary"><?= $h($f->help) ?></small>
    </div>
    <?php } ?>

    <?php if (count($d->authModes) > 1 || $d->authModes[0] !== 'none') { ?>
    <fieldset class="mb-3" data-wh-auth>
        <legend class="form-label fs-6 mb-1">Authentication <small class="text-secondary">(what <?= $h($d->name) ?> expects from us)</small></legend>
        <select class="form-select mb-2" name="webhook_auth_mode" aria-label="Authentication mode" data-wh-auth-mode>
            <?php $labels = ['none' => 'None', 'hmac' => 'None, rely on our signature', 'bearer' => 'Bearer token', 'basic' => 'Basic (username and password)', 'header' => 'Custom header'];
            foreach ($d->authModes as $m) { ?>
            <option value="<?= $h($m) ?>" <?= $auth_mode === $m ? 'selected' : '' ?>><?= $h($labels[$m] ?? $m) ?></option>
            <?php } ?>
        </select>
        <?php $keep = $editing && $same ? 'Saved. Leave blank to keep it.' : ''; ?>
        <div data-wh-auth-fields="bearer">
            <input type="password" class="form-control font-monospace" name="auth_token" autocomplete="new-password" aria-label="Bearer token" maxlength="2048" placeholder="<?= $h(($stored_auth['token'] ?? '') !== '' ? $keep : 'Token') ?>">
        </div>
        <div data-wh-auth-fields="basic">
            <div class="row g-2">
                <div class="col-sm-6"><input type="text" class="form-control" name="auth_username" autocomplete="off" aria-label="Username" maxlength="256" value="<?= $h($stored_auth['username'] ?? '') ?>" placeholder="Username"></div>
                <div class="col-sm-6"><input type="password" class="form-control" name="auth_password" autocomplete="new-password" aria-label="Password" maxlength="1024" placeholder="<?= $h(($stored_auth['password'] ?? '') !== '' ? $keep : 'Password') ?>"></div>
            </div>
        </div>
        <div data-wh-auth-fields="header">
            <div class="row g-2">
                <div class="col-sm-5"><input type="text" class="form-control font-monospace" name="auth_header_name" autocomplete="off" aria-label="Header name" maxlength="64" value="<?= $h(($stored_auth['header_name'] ?? '') !== '' ? $stored_auth['header_name'] : ($d->defaultAuthHeader ?? '')) ?>" placeholder="Header name, e.g. <?= $h($d->defaultAuthHeader ?? 'X-Api-Key') ?>"></div>
                <div class="col-sm-7"><input type="password" class="form-control font-monospace" name="auth_header_value" autocomplete="new-password" aria-label="Header value" maxlength="2048" placeholder="<?= $h(($stored_auth['header_value'] ?? '') !== '' ? $keep : 'Header value') ?>"></div>
            </div>
            <small class="text-secondary">Host, Content-Length, Content-Type, Connection and our own X-Rivet-* / *-Signature headers cannot be used.</small>
        </div>
        <small class="text-secondary d-block mt-1" data-wh-auth-none>Our signature headers (<code>X-Rivet-Signature-V2</code>) are always sent.</small>
    </fieldset>
    <?php } ?>

    <?php if ($signs || $type === 'slack') { ?>
    <div class="form-group mb-3">
        <label class="form-label" for="<?= $uid ?>-secret"><?= $type === 'slack' ? 'Slack Signing Secret <small class="text-secondary">(optional; turns on the Acknowledge / Assign to me buttons)</small>' : 'Signing secret <small class="text-secondary">(HMAC-SHA256 key for the signature headers)</small>' ?></label>
        <div class="input-group">
            <input type="<?= $editing || $type === 'slack' ? 'password' : 'text' ?>" class="form-control font-monospace" id="<?= $uid ?>-secret" name="webhook_secret" autocomplete="new-password" maxlength="200"
                   value="<?= $h($gen_secret) ?>" placeholder="<?= $h($has_secret ? 'Saved. Leave blank to keep it.' : ($type === 'slack' ? 'Signing Secret' : 'your-secret-here')) ?>">
            <?php if ($type !== 'slack') { ?><button type="button" class="btn btn-outline-secondary" data-wh-gen-secret="#<?= $uid ?>-secret"><i class="fas fa-dice me-1" aria-hidden="true"></i><?= $editing ? 'Generate new' : 'Generate' ?></button><?php } ?>
        </div>
        <?php if ($type !== 'slack') { ?><small class="wh-secret-note"><?= $editing ? 'Generating a new secret replaces the saved one when you save; update the receiver too.' : 'Copy it now into your receiver (n8n, your script): it is stored encrypted and not shown again.' ?></small><?php } ?>
        <?php if ($type === 'slack' && $has_secret) { ?>
        <div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="webhook_secret_clear" value="1" id="<?= $uid ?>-sclear"><label class="form-check-label small" for="<?= $uid ?>-sclear">Remove the saved Signing Secret (turns the interactive buttons off)</label></div>
        <?php } ?>
    </div>
    <?php } ?>

    <?php if (count($methods) > 1) { ?>
    <div class="form-group mb-3">
        <label class="form-label" for="<?= $uid ?>-method">HTTP method</label>
        <select class="form-select" id="<?= $uid ?>-method" name="webhook_method">
            <?php foreach ($methods as $m) { ?><option value="<?= $h($m) ?>" <?= $wh_method === $m ? 'selected' : '' ?>><?= $h($m) ?></option><?php } ?>
        </select>
    </div>
    <?php } else { ?>
    <input type="hidden" name="webhook_method" value="<?= $h($methods[0]) ?>">
    <?php } ?>

    <?php if ($d->id === 'custom-template') {
        $ctx = PayloadTemplate::sampleContext('ticket.created');
        $paths = ['event', 'timestamp'];
        foreach (array_keys($ctx['data']) as $k) { $paths[] = 'data.' . $k; }
        foreach (['title', 'summary', 'url', 'severity', 'actor', 'client'] as $k) { $paths[] = 'summary.' . $k; }
        ?>
    <div class="form-group mb-3" data-wh-template>
        <label class="form-label" for="<?= $uid ?>-tpl">Body template <span class="text-danger">*</span></label>
        <div class="d-flex gap-2 mb-1 align-items-center">
            <select class="form-select form-select-sm w-auto" name="webhook_template_encoding" aria-label="Template encoding" data-wh-tpl-enc>
                <?php foreach (PayloadTemplate::ENCODINGS as $enc) { ?><option value="<?= $h($enc) ?>" <?= $tpl_enc === $enc ? 'selected' : '' ?>><?= $h($enc === 'json' ? 'JSON body' : ($enc === 'text' ? 'Plain text body' : 'Form-encoded body')) ?></option><?php } ?>
            </select>
            <span class="small text-secondary" data-wh-tpl-state aria-live="polite"></span>
        </div>
        <textarea class="form-control font-monospace" id="<?= $uid ?>-tpl" name="webhook_template" rows="7" spellcheck="false" maxlength="8192" data-wh-tpl-input><?= $h($tpl) ?></textarea>
        <details class="mt-1">
            <summary class="small">Placeholders you can use (click to insert)</summary>
            <div class="small mt-1" data-wh-tpl-cheat>
                <?php foreach ($paths as $pth) { ?><button type="button" class="btn btn-sm btn-outline-secondary py-0 me-1 mb-1 font-monospace" data-wh-insert="{{<?= $h($pth) ?>}}">{{<?= $h($pth) ?>}}</button><?php } ?>
                <p class="mb-1 mt-1">Filters: <code>|default:"x"</code> <code>|upper</code> <code>|lower</code> <code>|trim</code> <code>|truncate:80</code> <code>|json</code> (last; inserts numbers, lists and objects as JSON). In a JSON body a plain <code>{{x}}</code> belongs between quotes. No loops, conditions or code. Up to 8 KB.</p>
            </div>
        </details>
    </div>
    <?php } else { ?>
    <p class="small text-secondary mb-3"><i class="fas fa-file-code me-1" aria-hidden="true"></i>Body format: <code><?= $h($d->format) ?></code>, set by the <?= $h($d->name) ?> preset.</p>
    <?php } ?>

    <?php if ($routing) { ?>
    <div class="row g-3 mb-3">
        <div class="col-md-5">
            <label class="form-label" for="<?= $uid ?>-prio">Minimum ticket priority</label>
            <select class="form-select" id="<?= $uid ?>-prio" name="webhook_min_priority">
                <option value="">Any priority</option>
                <?php foreach (array_keys(ChatFormatter::PRIORITIES) as $pr) { ?>
                <option value="<?= $h($pr) ?>" <?= $dest_min === $pr ? 'selected' : '' ?>><?= $h($pr) ?> and above</option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-7">
            <label class="form-label" for="<?= $uid ?>-clients">Only these clients <small class="text-secondary">(none = all)</small></label>
            <select class="form-select" id="<?= $uid ?>-clients" name="webhook_client_ids[]" multiple size="4">
                <?php
                $dest_sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC LIMIT 500");
                while ($dest_sql && ($dc = mysqli_fetch_assoc($dest_sql))) { ?>
                <option value="<?= intval($dc['client_id']) ?>" <?= in_array((int) $dc['client_id'], $dest_clients, true) ? 'selected' : '' ?>><?= nullable_htmlentities($dc['client_name']) ?></option>
                <?php } ?>
            </select>
        </div>
    </div>
    <?php } ?>

    <div class="form-group mb-3">
        <label class="form-label">Events <span class="text-danger">*</span></label>
        <?php eventPickerField('webhook_events[]', $wh_events, ['id' => $uid . '-events']); ?>
    </div>

    <div class="form-check form-switch mb-2">
        <input type="checkbox" class="form-check-input" id="<?= $uid ?>-enabled" name="webhook_enabled" value="1" <?= !$wh || !empty($wh['webhook_enabled']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="<?= $uid ?>-enabled">Enabled</label>
    </div>

    <div class="wh-result" data-wh-result hidden role="status" aria-live="polite"></div>
</div>

<aside class="wh-guide" aria-label="Setup guide for <?= $h($d->name) ?>">
    <div class="d-flex align-items-center mb-2">
        <strong class="me-auto"><i class="fas <?= $h(webhookGuideIcon($d)) ?> me-1" aria-hidden="true"></i>Setup guide: <?= $h($d->name) ?></strong>
        <a class="small" href="settings_webhook_guides.php#<?= $h($d->id) ?>" target="_blank" rel="noopener">All guides <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
    </div>
    <?php webhookGuideHtml($d, false); ?>
</aside>
</div>
