<?php

require_once "includes/inc_all_admin.php";

$mt_events = mailTemplateEvents();
$mt_defaults = mailTemplateDefaults();
$mt_hf = mailTemplateHeaderFooter($mysqli);
$mt_rows = [];
$sql_mt = mysqli_query($mysqli, "SELECT * FROM mail_templates");
while ($row = mysqli_fetch_assoc($sql_mt)) {
    $mt_rows[$row['mail_template_event']] = $row;
}

?>

<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-envelope-open-text me-2"></i>Email Templates</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-0">These are the emails <?= nullable_htmlentities(APP_NAME) ?> sends to a client contact or a technician for a ticket event, and the department portal's password reset flow. The text below is the default - nothing has changed unless you edit and save one. Placeholders in curly braces (e.g. <code>{contact_name}</code>) are filled in when the email is sent; an unrecognized one is simply left blank, never shown as raw text.</p>
    </div>
</div>

<?php foreach ($mt_events as $mt_event => $mt_label) {
    $mt_row = $mt_rows[$mt_event] ?? null;
    $mt_subject = $mt_row['mail_template_subject'] ?? $mt_defaults[$mt_event]['subject'];
    $mt_body = $mt_row['mail_template_body'] ?? $mt_defaults[$mt_event]['body'];
    $mt_enabled = $mt_row === null || (int) $mt_row['mail_template_enabled'] === 1;
    $mt_needs_marker = mailTemplateRequiresReplyMarker($mt_event);
    $mt_needs_link = mailTemplateRequiresPortalLink($mt_event);
    $mt_placeholders = mailTemplatePlaceholdersFor($mt_event);
    ?>
    <div class="card mb-3 mail-template-card" id="mt-card-<?= nullable_htmlentities($mt_event) ?>">
        <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0"><?= nullable_htmlentities($mt_label) ?></h3>
            <span class="badge <?= $mt_enabled ? 'bg-success' : 'bg-secondary' ?>"><?= $mt_enabled ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" class="mail-template-form" data-event="<?= nullable_htmlentities($mt_event) ?>">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="event" value="<?= nullable_htmlentities($mt_event) ?>">

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="enabled" id="mt-enabled-<?= nullable_htmlentities($mt_event) ?>" value="1" <?= $mt_enabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mt-enabled-<?= nullable_htmlentities($mt_event) ?>">Send this email</label>
                </div>

                <div class="form-group mb-3">
                    <label class="small text-muted">Subject</label>
                    <input type="text" class="form-control" name="subject" maxlength="500" value="<?= nullable_htmlentities($mt_subject) ?>" required>
                </div>

                <div class="mb-2">
                    <span class="small text-muted me-2">Insert:</span>
                    <?php foreach ($mt_placeholders as $mt_ph) { ?>
                        <button type="button" class="btn btn-xs btn-outline-secondary mb-1 mt-placeholder-btn" data-placeholder="{<?= nullable_htmlentities($mt_ph) ?>}"><?= nullable_htmlentities($mt_ph) ?></button>
                    <?php } ?>
                </div>

                <div class="form-group mb-2">
                    <textarea class="form-control tinymceMailTemplate" name="body" rows="10"><?= nullable_htmlentities($mt_body) ?></textarea>
                </div>

                <div class="small mb-3">
                    <?php if ($mt_needs_marker) { ?>
                    <div class="mt-warning text-warning d-none" data-warn="marker"><i class="fas fa-exclamation-triangle me-1"></i>This body no longer has the "##- Please type your reply above this line -##" marker. A reply to this email will not be parsed correctly - keep that line (it can be anywhere in the body, but it must stay wrapped in an &lt;i&gt; tag).</div>
                    <?php } ?>
                    <?php if ($mt_needs_link) { ?>
                    <div class="mt-warning text-warning d-none" data-warn="link"><i class="fas fa-exclamation-triangle me-1"></i>This body no longer has a <code>{portal_link}</code> placeholder. The recipient will have no way back into the ticket/portal from this email.</div>
                    <?php } ?>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <button type="submit" name="save_mail_template" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button>
                    <button type="button" class="btn btn-outline-secondary js-mt-preview" data-event="<?= nullable_htmlentities($mt_event) ?>"><i class="fas fa-eye me-1"></i>Preview</button>
                    <button type="button" class="btn btn-outline-secondary js-mt-test" data-event="<?= nullable_htmlentities($mt_event) ?>" data-csrf="<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-paper-plane me-1"></i>Send test email to me</button>
                    <button type="submit" name="reset_mail_template" class="btn btn-outline-danger ms-auto" formnovalidate><i class="fas fa-undo me-1"></i>Reset to default</button>
                </div>
            </form>
        </div>
    </div>
<?php } ?>

<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-palette me-2"></i>Shared header and footer</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">Optional. When set, this is prepended/appended to every email above (a logo, a disclaimer, etc.) - it is not a template of its own and has no placeholders.</p>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group mb-2">
                <label class="small text-muted">Header HTML</label>
                <textarea class="form-control tinymceMailTemplate" name="header_html" rows="4"><?= nullable_htmlentities($mt_hf['header']) ?></textarea>
            </div>
            <div class="form-group mb-3">
                <label class="small text-muted">Footer HTML</label>
                <textarea class="form-control tinymceMailTemplate" name="footer_html" rows="4"><?= nullable_htmlentities($mt_hf['footer']) ?></textarea>
            </div>
            <button type="submit" name="save_mail_template_header_footer" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button>
        </form>
    </div>
</div>

<div class="modal fade" id="mtPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Preview (sample data)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2" id="mtPreviewSubject"></div>
                <iframe id="mtPreviewFrame" style="width:100%;height:60vh;border:1px solid var(--color-border,#dee2e6);border-radius:.375rem;"></iframe>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
tinymce.init({
    selector: '.tinymceMailTemplate',
    browser_spellcheck: true,
    contextmenu: false,
    resize: true,
    min_height: 220,
    max_height: 600,
    promotion: false,
    branding: false,
    menubar: false,
    statusbar: false,
    toolbar: [
        { name: 'styles', items: ['styles'] },
        { name: 'formatting', items: ['bold', 'italic', 'forecolor'] },
        { name: 'link', items: ['link'] },
        { name: 'lists', items: ['bullist', 'numlist'] },
        { name: 'indentation', items: ['outdent', 'indent'] },
        { name: 'undo', items: ['undo', 'redo'] },
        { name: 'code', items: ['code'] },
    ],
    convert_urls: false,
    plugins: 'link lists code fullscreen',
    license_key: 'gpl',
    setup: function (editor) {
        editor.on('change keyup SetContent', function () {
            editor.save();
            checkMailTemplateWarnings(editor.getElement());
        });
    },
});

function checkMailTemplateWarnings(textarea) {
    var form = textarea.closest('form');
    if (!form) { return; }
    var body = textarea.value;
    var markerOk = /<i[^>]*>\s*##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##\s*<\/i>/i.test(body);
    var linkOk = body.indexOf('{portal_link}') !== -1;
    var warn = form.querySelector('.mt-warning[data-warn="marker"]');
    if (warn) { warn.classList.toggle('d-none', markerOk); }
    warn = form.querySelector('.mt-warning[data-warn="link"]');
    if (warn) { warn.classList.toggle('d-none', linkOk); }
}

document.addEventListener('click', function (e) {
    var btn = e.target.closest('.mt-placeholder-btn');
    if (btn) {
        var form = btn.closest('form');
        var ta = form.querySelector('textarea.tinymceMailTemplate');
        var ed = tinymce.get(ta.id);
        if (ed) {
            ed.insertContent(btn.dataset.placeholder);
            ed.save();
            checkMailTemplateWarnings(ta);
        }
        return;
    }
    var preview = e.target.closest('.js-mt-preview');
    if (preview) {
        var event = preview.dataset.event;
        var form2 = preview.closest('form');
        var ta2 = form2.querySelector('textarea.tinymceMailTemplate');
        tinymce.get(ta2.id)?.save();
        var subject = form2.querySelector('[name="subject"]').value;
        var body2 = ta2.value;
        var fd = new FormData();
        fd.append('preview_mail_template', '1');
        fd.append('event', event);
        fd.append('subject', subject);
        fd.append('body', body2);
        fd.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
        fetch('post.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { RivetDialog.alert(j.error || 'Could not render the preview.'); return; }
                document.getElementById('mtPreviewSubject').textContent = 'Subject: ' + j.subject;
                document.getElementById('mtPreviewFrame').srcdoc = j.body;
                new bootstrap.Modal(document.getElementById('mtPreviewModal')).show();
            })
            .catch(function () { RivetDialog.alert('Network error rendering the preview.'); });
        return;
    }
    var test = e.target.closest('.js-mt-test');
    if (test) {
        (async function () {
            if (!(await RivetDialog.confirm('Send a test copy of this email to your own address now?'))) { return; }
            var event2 = test.dataset.event;
            var form3 = test.closest('form');
            var ta3 = form3.querySelector('textarea.tinymceMailTemplate');
            tinymce.get(ta3.id)?.save();
            fetch('post.php', {
                method: 'POST',
                body: (function () {
                    var fd2 = new FormData();
                    fd2.append('preview_mail_template', '1');
                    fd2.append('event', event2);
                    fd2.append('subject', form3.querySelector('[name="subject"]').value);
                    fd2.append('body', ta3.value);
                    fd2.append('send_test', '1');
                    fd2.append('csrf_token', test.dataset.csrf);
                    return fd2;
                })(),
            })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    RivetDialog.alert(j.ok ? 'Test email queued - check your inbox in a minute.' : (j.error || 'Could not send the test email.'));
                })
                .catch(function () { RivetDialog.alert('Network error sending the test email.'); });
        })();
    }
});
</script>
