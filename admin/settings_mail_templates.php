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
                    <input type="text" class="form-control mt-subject-input" name="subject" maxlength="500" value="<?= nullable_htmlentities($mt_subject) ?>" required>
                    <div class="mt-2">
                        <span class="small text-muted me-2">Insert into subject:</span>
                        <?php foreach ($mt_placeholders as $mt_ph) { ?>
                            <button type="button" class="btn btn-xs btn-outline-secondary mb-1 mt-subject-placeholder-btn" data-placeholder="{<?= nullable_htmlentities($mt_ph) ?>}"><?= nullable_htmlentities($mt_ph) ?></button>
                        <?php } ?>
                    </div>
                </div>

                <label class="small text-muted d-block mb-1">Body - drag blocks to reorder</label>
                <div class="mt-block-list mb-2" id="mt-blocks-<?= nullable_htmlentities($mt_event) ?>"
                     data-event="<?= nullable_htmlentities($mt_event) ?>"
                     data-needs-marker="<?= $mt_needs_marker ? '1' : '0' ?>"
                     data-needs-link="<?= $mt_needs_link ? '1' : '0' ?>"
                     data-placeholders='<?= htmlspecialchars(json_encode($mt_placeholders), ENT_QUOTES, "UTF-8") ?>'></div>

                <div class="mb-2">
                    <span class="small text-muted me-2">Add block:</span>
                    <button type="button" class="btn btn-xs btn-outline-secondary mt-add-block" data-type="text"><i class="fas fa-font me-1"></i>Text</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary mt-add-block" data-type="button"><i class="fas fa-square me-1"></i>Button</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary mt-add-block" data-type="divider"><i class="fas fa-minus me-1"></i>Divider</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary mt-add-block" data-type="spacer"><i class="fas fa-arrows-alt-v me-1"></i>Spacer</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary mt-add-block" data-type="html"><i class="fas fa-code me-1"></i>Advanced HTML</button>
                </div>

                <textarea class="d-none mt-body-hidden" name="body" id="mt-body-<?= nullable_htmlentities($mt_event) ?>"><?= nullable_htmlentities($mt_body) ?></textarea>

                <div class="small mb-3">
                    <?php if ($mt_needs_marker) { ?>
                    <div class="mt-warning text-warning d-none" data-warn="marker"><i class="fas fa-exclamation-triangle me-1"></i>This body no longer has the reply marker. A reply to this email will not be parsed correctly - add back a "Reply marker" block.</div>
                    <?php } ?>
                    <?php if ($mt_needs_link) { ?>
                    <div class="mt-warning text-warning d-none" data-warn="link"><i class="fas fa-exclamation-triangle me-1"></i>This body no longer has a <code>{portal_link}</code> anywhere. The recipient will have no way back into the ticket/portal from this email.</div>
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
                <ul class="nav nav-tabs nav-tabs-sm mb-2 mt-editor-tabs" role="tablist">
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link active small" data-bs-toggle="tab" data-bs-target="#mt-visual-header" data-pane="visual">Visual</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link small" data-bs-toggle="tab" data-bs-target="#mt-source-header" data-pane="source" data-for="mt-header-html">HTML source</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane show active" id="mt-visual-header">
                        <textarea class="form-control tinymceMailTemplate" id="mt-header-html" name="header_html" rows="4"><?= nullable_htmlentities($mt_hf['header']) ?></textarea>
                    </div>
                    <div class="tab-pane" id="mt-source-header">
                        <textarea class="form-control mt-source-view" data-for="mt-header-html" rows="4" style="font-family:monospace;font-size:.8rem;" spellcheck="false"></textarea>
                    </div>
                </div>
            </div>
            <div class="form-group mb-3">
                <label class="small text-muted">Footer HTML</label>
                <ul class="nav nav-tabs nav-tabs-sm mb-2 mt-editor-tabs" role="tablist">
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link active small" data-bs-toggle="tab" data-bs-target="#mt-visual-footer" data-pane="visual">Visual</button></li>
                    <li class="nav-item" role="presentation"><button type="button" class="nav-link small" data-bs-toggle="tab" data-bs-target="#mt-source-footer" data-pane="source" data-for="mt-footer-html">HTML source</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane show active" id="mt-visual-footer">
                        <textarea class="form-control tinymceMailTemplate" id="mt-footer-html" name="footer_html" rows="4"><?= nullable_htmlentities($mt_hf['footer']) ?></textarea>
                    </div>
                    <div class="tab-pane" id="mt-source-footer">
                        <textarea class="form-control mt-source-view" data-for="mt-footer-html" rows="4" style="font-family:monospace;font-size:.8rem;" spellcheck="false"></textarea>
                    </div>
                </div>
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

<script src="../plugins/SortableJS/Sortable.min.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// tinymce.min.js loads with `defer` (includes/footer.php), so it is NOT yet defined while this inline script is parsed -
// deferred scripts only run after the document finishes parsing, which is also when DOMContentLoaded fires (always after
// every deferred script, in document order), so everything below waits for that instead of running immediately.
document.addEventListener('DOMContentLoaded', function () {
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
    ],
    convert_urls: false,
    plugins: 'link lists fullscreen',
    license_key: 'gpl',
    setup: function (editor) {
        editor.on('change keyup SetContent', function () {
            editor.save();
            checkMailTemplateWarnings(editor.getElement());
        });
    },
});

// Visual / HTML source tabs (header/footer only - the per-template body is the block builder below): switching TO the
// source tab dumps the editor's current HTML into the plain textarea; switching back pushes it back into the editor.
document.addEventListener('show.bs.tab', function (e) {
    var btn = e.target;
    if (!btn.classList.contains('nav-link') || !btn.closest('.mt-editor-tabs')) { return; }
    var pane = btn.dataset.pane;
    var forId = btn.dataset.for;
    if (pane === 'source' && forId) {
        var ed = tinymce.get(forId);
        var sourceTa = document.querySelector('.mt-source-view[data-for="' + forId + '"]');
        if (ed && sourceTa) { sourceTa.value = ed.getContent(); }
        return;
    }
    if (pane === 'visual') {
        var group = btn.closest('.mt-editor-tabs');
        var sourceBtn = group.querySelector('[data-pane="source"]');
        var forId2 = sourceBtn ? sourceBtn.dataset.for : null;
        if (!forId2) { return; }
        var ed2 = tinymce.get(forId2);
        var sourceTa2 = document.querySelector('.mt-source-view[data-for="' + forId2 + '"]');
        if (ed2 && sourceTa2) {
            ed2.setContent(sourceTa2.value);
            ed2.save();
        }
    }
});

function checkMailTemplateWarnings(elForForm) {
    var form = elForForm.closest ? elForForm.closest('form') : null;
    if (!form) { return; }
    var hidden = form.querySelector('.mt-body-hidden');
    var body = hidden ? hidden.value : '';
    var markerOk = /<i[^>]*>\s*##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##\s*<\/i>/i.test(body);
    var linkOk = body.indexOf('{portal_link}') !== -1;
    var warn = form.querySelector('.mt-warning[data-warn="marker"]');
    if (warn) { warn.classList.toggle('d-none', markerOk); }
    warn = form.querySelector('.mt-warning[data-warn="link"]');
    if (warn) { warn.classList.toggle('d-none', linkOk); }
}

// ------------------------------------------------------------------------------------------------------------------
// Block builder: a {placeholder}-aware drag-and-drop editor for the body of one template. Block types mirror
// includes/mail_templates.php's mailTemplateCompileBlock() exactly, and the hidden "body" textarea this writes into
// is a plain, ordinary form field - Save/Preview/Send-test/the backend never know blocks exist.
var MT = {
    uid: 0,
    nextId: function (prefix) { return prefix + '-' + (++MT.uid); },

    compileOne: function (b) {
        switch (b.type) {
            case 'reply_marker':
                return "<i style='color: #808080'>##- Please type your reply above this line -##</i>";
            case 'button':
                return "<a href='" + (b.link || '') + "'>" + (b.label || 'Click here') + '</a>';
            case 'divider':
                return '<hr>';
            case 'spacer':
                return '<div style="height:' + (parseInt(b.size, 10) || 16) + 'px;"></div>';
            default: // 'text', 'html'
                return b.html || '';
        }
    },
    compileHtml: function (blocks) { return blocks.map(MT.compileOne).join('<br><br>'); },
    serialize: function (blocks) { return '<!--RIVETIT_BLOCKS:' + JSON.stringify({ v: 1, blocks: blocks }) + '-->' + MT.compileHtml(blocks); },
    decompile: function (raw) {
        var m = /^<!--RIVETIT_BLOCKS:([\s\S]*?)-->/.exec(raw || '');
        if (m) {
            try {
                var parsed = JSON.parse(m[1]);
                if (parsed && Array.isArray(parsed.blocks)) { return parsed.blocks; }
            } catch (err) { /* fall through to the raw-HTML fallback below */ }
        }
        var stripped = (raw || '').replace(/^<!--RIVETIT_BLOCKS:[\s\S]*?-->\s*/, '');
        return [{ type: 'html', html: stripped }];
    },

    labelFor: { text: 'Text', button: 'Button', divider: 'Divider', spacer: 'Spacer', html: 'Advanced HTML', reply_marker: 'Reply marker' },
    iconFor: { text: 'font', button: 'square', divider: 'minus', spacer: 'arrows-alt-v', html: 'code', reply_marker: 'reply' },

    placeholders: function (container) {
        try { return JSON.parse(container.dataset.placeholders || '[]'); } catch (e) { return []; }
    },

    // A small "Insert: {x} {y} ..." row scoped to one field, not a global one - every block gets its own.
    placeholderRow: function (placeholders, targetId) {
        var row = document.createElement('div');
        row.className = 'mt-inline-placeholders mt-1';
        var label = document.createElement('span');
        label.className = 'small text-muted me-1';
        label.textContent = 'Insert:';
        row.appendChild(label);
        placeholders.forEach(function (p) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-xs btn-outline-secondary mt-1 me-1 mt-inline-placeholder-btn';
            b.dataset.target = targetId;
            b.dataset.placeholder = '{' + p + '}';
            b.textContent = p;
            row.appendChild(b);
        });
        return row;
    },

    // Builds one block's DOM. `data` is the block's own state (type plus its type-specific fields); fields read their
    // live values straight back out of these elements when MT.collect() runs (the DOM IS the state - no parallel store).
    renderBlock: function (data, placeholders) {
        var type = data.type;
        var el = document.createElement('div');
        el.className = 'mt-block card card-sm mb-2';
        el.dataset.type = type;

        var head = document.createElement('div');
        head.className = 'card-header d-flex align-items-center py-1 px-2';
        head.innerHTML = '<span class="mt-drag-handle me-2" style="cursor:grab;" aria-hidden="true"><i class="fas fa-grip-vertical text-muted"></i></span>'
            + '<span class="small fw-bold flex-grow-1"><i class="fas fa-' + (MT.iconFor[type] || 'cube') + ' me-1 text-muted"></i>' + (MT.labelFor[type] || type) + '</span>';
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'btn btn-xs btn-outline-danger mt-block-delete';
        del.innerHTML = '<i class="fas fa-trash"></i>';
        head.appendChild(del);
        el.appendChild(head);

        var body = document.createElement('div');
        body.className = 'card-body py-2 px-2';

        if (type === 'reply_marker') {
            body.innerHTML = '<span class="small text-muted"><i style="color:#808080;">##- Please type your reply above this line -##</i> (added automatically - this exact line is how a reply to this email is told apart from the quoted original)</span>';
        } else if (type === 'divider') {
            body.innerHTML = '<hr class="my-1">';
        } else if (type === 'spacer') {
            var sel = document.createElement('select');
            sel.className = 'form-select form-select-sm mt-block-field';
            sel.dataset.field = 'size';
            [['8', 'Small'], ['16', 'Medium'], ['32', 'Large']].forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o[0]; opt.textContent = o[1];
                if (String(data.size) === o[0]) { opt.selected = true; }
                sel.appendChild(opt);
            });
            body.appendChild(sel);
        } else if (type === 'button') {
            var labelInput = document.createElement('input');
            labelInput.type = 'text'; labelInput.className = 'form-control form-control-sm mb-2 mt-block-field'; labelInput.dataset.field = 'label';
            labelInput.placeholder = 'Button text'; labelInput.value = data.label || 'Click here';
            body.appendChild(labelInput);
            var linkSel = document.createElement('select');
            linkSel.className = 'form-select form-select-sm mt-block-field'; linkSel.dataset.field = 'link';
            placeholders.forEach(function (p) {
                var opt = document.createElement('option');
                opt.value = '{' + p + '}'; opt.textContent = 'Links to: {' + p + '}';
                if (data.link === '{' + p + '}') { opt.selected = true; }
                linkSel.appendChild(opt);
            });
            body.appendChild(linkSel);
        } else { // text or html
            var taId = MT.nextId('mt-field');
            var ta = document.createElement('textarea');
            ta.id = taId; ta.className = 'form-control mt-block-field mt-block-editor-' + type; ta.dataset.field = 'html';
            ta.rows = type === 'html' ? 6 : 3;
            ta.value = data.html || '';
            body.appendChild(ta);
            body.appendChild(MT.placeholderRow(placeholders, taId));
            // Deferred: the textarea must be in the DOM before TinyMCE can attach to it.
            setTimeout(function () { MT.initBlockEditor(taId, type); }, 0);
        }

        el.appendChild(body);
        return el;
    },

    initBlockEditor: function (id, type) {
        var toolbar = type === 'html'
            ? [{ name: 'formatting', items: ['bold', 'italic', 'forecolor'] }, { name: 'link', items: ['link'] }, { name: 'lists', items: ['bullist', 'numlist'] }, { name: 'code', items: ['code'] }]
            : [{ name: 'formatting', items: ['bold', 'italic'] }, { name: 'link', items: ['link'] }];
        tinymce.init({
            selector: '#' + id,
            browser_spellcheck: true, contextmenu: false, resize: true,
            min_height: type === 'html' ? 160 : 90, max_height: 400,
            promotion: false, branding: false, menubar: false, statusbar: false,
            toolbar: toolbar, convert_urls: false,
            plugins: type === 'html' ? 'link lists code' : 'link lists',
            license_key: 'gpl',
            setup: function (editor) {
                editor.on('change keyup SetContent', function () {
                    editor.save();
                    var container = editor.getElement().closest('.mt-block-list');
                    if (container) { MT.sync(container); }
                });
            },
        });
    },

    defaultBlock: function (type, placeholders) {
        if (type === 'button') { return { type: 'button', label: 'Click here', link: placeholders.length ? '{' + placeholders[0] + '}' : '' }; }
        if (type === 'spacer') { return { type: 'spacer', size: 16 }; }
        if (type === 'text' || type === 'html') { return { type: type, html: '' }; }
        return { type: type };
    },

    render: function (container) {
        var hidden = document.getElementById(container.dataset.event ? 'mt-body-' + container.dataset.event : '');
        var placeholders = MT.placeholders(container);
        var blocks = MT.decompile(hidden ? hidden.value : '');
        container.innerHTML = '';
        blocks.forEach(function (b) { container.appendChild(MT.renderBlock(b, placeholders)); });
        new Sortable(container, { handle: '.mt-drag-handle', animation: 150, onEnd: function () { MT.sync(container); } });
    },

    collect: function (container) {
        return Array.from(container.children).map(function (el) {
            var type = el.dataset.type;
            var b = { type: type };
            el.querySelectorAll('.mt-block-field').forEach(function (f) {
                if (f.tagName === 'TEXTAREA') { var ed = tinymce.get(f.id); if (ed) { ed.save(); } }
                b[f.dataset.field] = f.value;
            });
            return b;
        });
    },

    sync: function (container) {
        var hiddenId = 'mt-body-' + container.dataset.event;
        var hidden = document.getElementById(hiddenId);
        if (!hidden) { return; }
        var blocks = MT.collect(container);
        hidden.value = MT.serialize(blocks);
        checkMailTemplateWarnings(hidden);
    },
};

document.querySelectorAll('.mt-block-list').forEach(function (c) { MT.render(c); });

document.addEventListener('input', function (e) {
    var field = e.target.closest('.mt-block-field');
    if (field && field.tagName !== 'TEXTAREA') { // textareas sync via their own tinymce change handler
        var container = field.closest('.mt-block-list');
        if (container) { MT.sync(container); }
    }
});
document.addEventListener('change', function (e) {
    var field = e.target.closest('.mt-block-field');
    if (field) {
        var container = field.closest('.mt-block-list');
        if (container) { MT.sync(container); }
    }
});

document.addEventListener('click', function (e) {
    var del = e.target.closest('.mt-block-delete');
    if (del) {
        var block = del.closest('.mt-block');
        var container = block.closest('.mt-block-list');
        block.remove();
        MT.sync(container);
        return;
    }
    var add = e.target.closest('.mt-add-block');
    if (add) {
        var card = add.closest('.mail-template-card');
        var container2 = card.querySelector('.mt-block-list');
        var placeholders = MT.placeholders(container2);
        container2.appendChild(MT.renderBlock(MT.defaultBlock(add.dataset.type, placeholders), placeholders));
        MT.sync(container2);
        return;
    }
    var inline = e.target.closest('.mt-inline-placeholder-btn');
    if (inline) {
        var ta = document.getElementById(inline.dataset.target);
        var ed2 = tinymce.get(inline.dataset.target);
        if (ed2) { ed2.insertContent(inline.dataset.placeholder); ed2.save(); }
        else if (ta) { ta.value += inline.dataset.placeholder; }
        var container3 = inline.closest('.mt-block-list');
        if (container3) { MT.sync(container3); }
        return;
    }
    var subjBtn = e.target.closest('.mt-subject-placeholder-btn');
    if (subjBtn) {
        var subjInput = subjBtn.closest('.form-group').querySelector('.mt-subject-input');
        if (subjInput) {
            subjInput.value += subjBtn.dataset.placeholder;
            subjInput.focus();
        }
        return;
    }
    var preview = e.target.closest('.js-mt-preview');
    if (preview) {
        var event = preview.dataset.event;
        var form2 = preview.closest('form');
        MT.sync(form2.querySelector('.mt-block-list'));
        var subject = form2.querySelector('.mt-subject-input').value;
        var body2 = document.getElementById('mt-body-' + event).value;
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
            MT.sync(form3.querySelector('.mt-block-list'));
            fetch('post.php', {
                method: 'POST',
                body: (function () {
                    var fd2 = new FormData();
                    fd2.append('preview_mail_template', '1');
                    fd2.append('event', event2);
                    fd2.append('subject', form3.querySelector('.mt-subject-input').value);
                    fd2.append('body', document.getElementById('mt-body-' + event2).value);
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

// Belt and suspenders: a genuine form submit (Save/Reset) serializes whatever is in the hidden textarea right now, but
// make sure it reflects the on-screen blocks even if a block's change event hasn't fired yet (e.g. focus still in a field).
document.querySelectorAll('.mail-template-form').forEach(function (form) {
    form.addEventListener('submit', function () {
        var container = form.querySelector('.mt-block-list');
        if (container) { MT.sync(container); }
    });
});
}); // end DOMContentLoaded
</script>

<?php require_once "../includes/footer.php"; ?>
