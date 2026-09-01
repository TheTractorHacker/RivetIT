// Adds Rich Text / Markdown / HTML tabs around a TinyMCE editor whose
// underlying textarea has the "tinymce-builder" class. TinyMCE stays the
// canonical source of truth: switching tabs always syncs edits made in the
// Markdown/HTML boxes back into the editor first, then regenerates the
// target box from the editor's current content.
function initDocBuilder(editor) {
    var textarea = editor.getElement();
    if (!textarea.classList.contains('tinymce-builder') || editor.docBuilderInitialized) {
        return;
    }
    editor.docBuilderInitialized = true;

    var container = editor.getContainer();

    var tabBar = document.createElement('div');
    tabBar.className = 'btn-group btn-group-sm doc-builder-tabs mb-2';
    tabBar.setAttribute('role', 'group');
    tabBar.innerHTML =
        '<button type="button" class="btn btn-outline-secondary active" data-mode="richtext">Rich Text</button>' +
        '<button type="button" class="btn btn-outline-secondary" data-mode="markdown">Markdown</button>' +
        '<button type="button" class="btn btn-outline-secondary" data-mode="html">HTML</button>';

    var mdTextarea = document.createElement('textarea');
    mdTextarea.className = 'form-control doc-builder-source';
    mdTextarea.rows = 14;
    mdTextarea.style.display = 'none';
    mdTextarea.style.fontFamily = 'monospace';
    mdTextarea.placeholder = 'Write Markdown here...';

    var htmlTextarea = document.createElement('textarea');
    htmlTextarea.className = 'form-control doc-builder-source';
    htmlTextarea.rows = 14;
    htmlTextarea.style.display = 'none';
    htmlTextarea.style.fontFamily = 'monospace';
    htmlTextarea.placeholder = 'Raw HTML...';

    container.parentNode.insertBefore(tabBar, container);
    container.parentNode.insertBefore(mdTextarea, container.nextSibling);
    container.parentNode.insertBefore(htmlTextarea, mdTextarea.nextSibling);

    var turndownService = null;
    function getTurndown() {
        if (!turndownService) {
            turndownService = new TurndownService({ headingStyle: 'atx', codeBlockStyle: 'fenced', bulletListMarker: '-' });
            if (window.turndownPluginGfm) {
                turndownService.use(turndownPluginGfm.gfm);
            }
        }
        return turndownService;
    }

    var currentMode = 'richtext';

    function showMode(mode) {
        container.style.display = (mode === 'richtext') ? '' : 'none';
        mdTextarea.style.display = (mode === 'markdown') ? '' : 'none';
        htmlTextarea.style.display = (mode === 'html') ? '' : 'none';

        tabBar.querySelectorAll('button').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });
    }

    // Pull fresh content INTO a source tab from the editor (the canonical model)
    function syncFromEditor(mode) {
        if (mode === 'markdown') {
            mdTextarea.value = getTurndown().turndown(editor.getContent());
        } else if (mode === 'html') {
            htmlTextarea.value = editor.getContent();
        }
    }

    // Push edits FROM a source tab back into the editor (the canonical model)
    function syncToEditor(mode) {
        if (mode === 'markdown') {
            editor.setContent(marked.parse(mdTextarea.value || ''));
            editor.save();
        } else if (mode === 'html') {
            editor.setContent(htmlTextarea.value || '');
            editor.save();
        }
    }

    tabBar.addEventListener('click', function(e) {
        var btn = e.target.closest('button[data-mode]');
        if (!btn || btn.dataset.mode === currentMode) {
            return;
        }

        var nextMode = btn.dataset.mode;

        if (currentMode !== 'richtext') {
            syncToEditor(currentMode);
        }
        if (nextMode !== 'richtext') {
            syncFromEditor(nextMode);
        }

        showMode(nextMode);
        currentMode = nextMode;
    });

    // Whatever tab the user was last typing in, make sure it lands in the
    // hidden textarea TinyMCE posts, even if they never switch back to Rich Text.
    var form = textarea.form;
    if (form) {
        form.addEventListener('submit', function() {
            if (currentMode !== 'richtext') {
                syncToEditor(currentMode);
            }
        });
    }
}

// Auto-submit filter selects/checkboxes app-wide. These used to be ~50 separate
// inline onchange="this.form.submit()" attributes across every list/report page -
// silently blocked by this app's strict CSP (no unsafe-inline), so every filter
// dropdown in the app did nothing when changed. One delegated listener replaces
// all of them; mark any auto-submitting control with the auto-submit-select class.
document.addEventListener('change', function (e) {
    var el = e.target.closest ? e.target.closest('.auto-submit-select') : null;
    if (el && el.form) {
        el.form.submit();
    }
});

// Report date-range filters: editing the From/To date manually should flip the
// paired "canned range" select (Today/This Week/etc) to "custom" so it stops
// silently reporting a canned range while showing a hand-picked one.
document.addEventListener('change', function (e) {
    var el = e.target.closest ? e.target.closest('.js-canned-date-input') : null;
    if (!el) { return; }
    var target = document.getElementById(el.dataset.cannedTarget);
    if (target) { target.value = 'custom'; }
});

// "Select all" checkbox that toggles every .<data-target-class> checkbox within
// the same .tab-pane (software license assignment, etc).
document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('.js-select-all-in-tab-pane') : null;
    if (!el) { return; }
    var pane = el.closest('.tab-pane');
    if (!pane) { return; }
    pane.querySelectorAll('.' + el.dataset.targetClass).forEach(function (cb) { cb.checked = el.checked; });
});

// Plain native confirm() gate for delete links nested inside another modal,
// where .confirm-link's own modal-on-modal stacking doesn't work.
document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('.js-confirm-native') : null;
    if (el && !confirm(el.dataset.confirmMessage || 'Are you sure?')) {
        e.preventDefault();
    }
});

// Force-uppercase as-you-type (client abbreviation field, etc).
document.addEventListener('input', function (e) {
    if (e.target.closest && e.target.closest('.js-uppercase-input')) {
        e.target.value = e.target.value.toUpperCase();
    }
});

function initSelect2Widgets() {
    // Initialize select boxes (Tom Select replaces Select2). Deliberately NOT
    // gated behind DOMContentLoaded below: this whole script is re-executed
    // every time an AJAX modal loads (ajax_modal.js's executeInjectedScripts
    // re-runs modal_footer.php's <script src="/js/app.js">), but
    // DOMContentLoaded only ever fires once per page - a listener added after
    // it has already fired never runs, so every modal-loaded .select2
    // (including multi-selects like a client's Tags field) was silently stuck
    // as a plain native <select>, where clicking a second option without
    // holding Ctrl/Cmd deselects the first. Calling this directly re-runs it
    // on every modal load and picks up newly-added elements; the el.tomselect
    // guard makes repeat calls safe for ones already initialized.
    document.querySelectorAll('.select2').forEach(function (el) {
        if (el.tomselect) { return; }
        var opts = {
            plugins: el.multiple ? ['remove_button'] : [],
            allowEmptyOption: true,
            // Keep the browser's native option order rather than re-sorting
            sortField: { field: '$order' }
        };
        var ph = el.getAttribute('data-placeholder');
        if (ph) { opts.placeholder = ph; }
        try { new TomSelect(el, opts); } catch (err) { /* noop */ }
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSelect2Widgets);
} else {
    initSelect2Widgets();
}

document.addEventListener('DOMContentLoaded', function() {
    // Prevents resubmit on forms
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    // Slide alert up after 4 secs (jQuery kept as a coexistence shim)
    if (window.jQuery) {
        jQuery("#alert").fadeTo(5000, 500).slideUp(500, function() {
            jQuery("#alert").slideUp(500);
        });
    }
});

function initTinyMCEEditors() {
    // Initialize every TinyMCE editor on the page. Deliberately NOT gated
    // behind DOMContentLoaded (see initSelect2Widgets above for the full
    // explanation): this whole script re-executes on every AJAX modal load,
    // but DOMContentLoaded only fires once per page, so any .tinymce* field
    // inside a modal (document/contract/ticket templates, canned responses,
    // the ticket-reply-edit modal, etc.) silently stayed a plain unstyled
    // <textarea> instead of a rich-text editor. Calling this directly re-runs
    // it on every modal load. Safe to call repeatedly: tinymce.init() skips
    // elements that already have an active editor attached (verified live -
    // re-running this while the ticket page's own editor is open does not
    // duplicate or disturb it).

    // Initialize TinyMCE
    tinymce.init({
        selector: '.tinymce-simple',
        browser_spellcheck: true,
        contextmenu: false,
        resize: true,
        min_height: 300,
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
            { name: 'alignment', items: ['alignleft', 'aligncenter', 'alignright', 'alignjustify'] },
            { name: 'indentation', items: ['outdent', 'indent'] },
            { name: 'table', items: ['table'] },
            { name: 'extra', items: ['code', 'fullscreen'] }
        ],
        mobile: {
            menubar: false,
            plugins: 'autosave lists autolink',
            toolbar: 'bold italic styles'
        },
        convert_urls: false,
        plugins: 'link image lists table code codesample fullscreen autoresize',
        setup: function (editor) {
            editor.on('init', function() {
                window.onbeforeunload = function() {
                    // If editor is dirty AND not inside a visible modal → warn
                    const inVisibleModal = editor.getContainer()?.closest('.modal.show');
                    if (!inVisibleModal && editor.isDirty()) {
                        return "You have unsaved changes. Are you sure you want to leave?";
                    }
                };

                // When the modal closes, mark editor clean
                const modal = editor.getContainer()?.closest('.modal');
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', () => {
                        editor.undoManager.clear();
                        editor.setDirty(false);
                    });
                }
            });
        },
        license_key: 'gpl'
    });

    // Initialize TinyMCE with AI
    tinymce.init({
        selector: '.tinymce',
        browser_spellcheck: true,
        contextmenu: false,
        resize: true,
        min_height: 300,
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
            { name: 'alignment', items: ['alignleft', 'aligncenter', 'alignright', 'alignjustify'] },
            { name: 'indentation', items: ['outdent', 'indent'] },
            { name: 'table', items: ['table'] },
            { name: 'media', items: ['image'] },
            { name: 'extra', items: ['code', 'fullscreen'] },
            { name: 'ai', items: ['reword', 'undo', 'redo'] }
        ],
        mobile: {
            menubar: false,
            plugins: 'autosave lists autolink',
            toolbar: 'bold italic styles'
        },
        convert_urls: false,
        plugins: 'link image lists table code codesample fullscreen autoresize',
        images_upload_url: '/agent/kb_article_upload.php',
        images_upload_credentials: true,
        license_key: 'gpl',
        setup: function(editor) {
            editor.on('init', function() {
                window.onbeforeunload = function() {
                    // If editor is dirty AND not inside a visible modal → warn
                    const inVisibleModal = editor.getContainer()?.closest('.modal.show');
                    if (!inVisibleModal && editor.isDirty()) {
                        return "You have unsaved changes. Are you sure you want to leave?";
                    }
                };

                // When the modal closes, mark editor clean
                const modal = editor.getContainer()?.closest('.modal');
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', () => {
                        editor.undoManager.clear();
                        editor.setDirty(false);
                    });
                }

                initDocBuilder(editor);
            });

            var rewordButtonApi;

            editor.ui.registry.addButton('reword', {
                icon: 'ai',
                tooltip: 'Reword Text',
                onAction: function() {
                    var content = editor.getContent();

                    // Disable the Reword button
                    rewordButtonApi.setEnabled(false);

                    // Show the progress indicator
                    editor.setProgressState(true);

                    fetch('/agent/ajax.php?ai_reword', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ text: content, csrf_token: window.csrfToken }),
                    })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(data => {
                            editor.setProgressState(false);
                            rewordButtonApi.setEnabled(true);

                            if (data.ok && data.rewordedText) {
                                editor.undoManager.transact(function() {
                                    editor.setContent(data.rewordedText);
                                });
                                editor.notificationManager.open({
                                    text: 'Text reworded successfully!',
                                    type: 'success',
                                    timeout: 3000
                                });
                            } else {
                                // Leave the editor's existing content untouched on failure.
                                editor.notificationManager.open({
                                    text: data.error || 'Could not reword the text.',
                                    type: 'error',
                                    timeout: 5000
                                });
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            editor.setProgressState(false);
                            rewordButtonApi.setEnabled(true);
                            editor.notificationManager.open({
                                text: 'An error occurred while rewording the text.',
                                type: 'error',
                                timeout: 5000
                            });
                        });
                },
                onSetup: function(buttonApi) {
                    rewordButtonApi = buttonApi;
                    return function() {};
                }
            });
        }
    });

    // Initialize TinyMCE AI for Tickets
    tinymce.init({
        selector: '.tinymceTicket',
        browser_spellcheck: true,
        contextmenu: false,
        resize: true,
        min_height: 200,
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
            { name: 'ai', items: ['reword', 'undo', 'redo'] },
            { name: 'custom', items: ['redactButton'] },
            { name: 'code', items: ['code'] },
        ],
        mobile: {
            menubar: false,
            toolbar: [
                { name: 'styles', items: ['styles'] },
                { name: 'formatting', items: ['bold', 'italic', 'forecolor'] },
                { name: 'link', items: ['link'] },
                { name: 'lists', items: ['bullist', 'numlist'] },
                { name: 'indentation', items: ['outdent', 'indent'] },
                { name: 'ai', items: ['reword', 'undo', 'redo'] },
                { name: 'custom', items: ['redactButton'] },
                { name: 'code', items: ['code'] },
            ],
        },
        convert_urls: false,
        plugins: 'link image lists table code codesample fullscreen autoresize code',
        license_key: 'gpl',
        setup: function(editor) {
            editor.on('init', function() {
                window.onbeforeunload = function() {
                    // If editor is dirty AND not inside a visible modal → warn
                    const inVisibleModal = editor.getContainer()?.closest('.modal.show');
                    if (!inVisibleModal && editor.isDirty()) {
                        return "You have unsaved changes. Are you sure you want to leave?";
                    }
                };

                // When the modal closes, mark editor clean
                const modal = editor.getContainer()?.closest('.modal');
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', () => {
                        editor.undoManager.clear();
                        editor.setDirty(false);
                    });
                }

            });

            var rewordButtonApi;

            editor.ui.registry.addButton('reword', {
                icon: 'ai',
                tooltip: 'Reword Text',
                onAction: function() {
                    var content = editor.getContent();
                    rewordButtonApi.setEnabled(false);
                    editor.setProgressState(true);

                    fetch('/agent/ajax.php?ai_reword', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ text: content, csrf_token: window.csrfToken }),
                    })
                        .then(response => {
                            if (!response.ok) throw new Error('Network response was not ok');
                            return response.json();
                        })
                        .then(data => {
                            editor.setProgressState(false);
                            rewordButtonApi.setEnabled(true);

                            if (data.ok && data.rewordedText) {
                                editor.undoManager.transact(function() {
                                    editor.setContent(data.rewordedText);
                                });
                                editor.notificationManager.open({
                                    text: 'Text reworded successfully!',
                                    type: 'success',
                                    timeout: 3000
                                });
                            } else {
                                // Leave the editor's existing content untouched on failure.
                                editor.notificationManager.open({
                                    text: data.error || 'Could not reword the text.',
                                    type: 'error',
                                    timeout: 5000
                                });
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            editor.setProgressState(false);
                            rewordButtonApi.setEnabled(true);
                            editor.notificationManager.open({
                                text: 'An error occurred while rewording the text.',
                                type: 'error',
                                timeout: 5000
                            });
                        });
                },
                onSetup: function(buttonApi) {
                    rewordButtonApi = buttonApi;
                    return function() {};
                }
            });

            editor.ui.registry.addButton('redactButton', {
                icon: 'permanent-pen',
                tooltip: 'Redact Text',
                onAction: function() {
                    var selectedText = editor.selection.getContent({ format: 'text' });
                    if (selectedText) {
                        var newContent = '<span style="font-weight: bold; color: red;">[REDACTED]</span>';
                        editor.selection.setContent(newContent);
                    } else {
                        alert('Please select a word to redact');
                    }
                }
            });
        }
    });

    // Initialize TinyMCE for the ticket reply/note editor - same as .tinymceTicket above,
    // but with a screenshot-paste-to-upload flow layered in. Only the actual reply box on
    // an existing ticket carries this class (it needs a real ticket_id to upload into), so
    // canned responses/ticket templates/new-ticket forms above are untouched.
    tinymce.init({
        selector: '.tinymceTicketReply',
        browser_spellcheck: true,
        contextmenu: false,
        resize: true,
        min_height: 200,
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
            { name: 'media', items: ['image'] },
            { name: 'ai', items: ['reword', 'undo', 'redo'] },
            { name: 'custom', items: ['redactButton'] },
            { name: 'code', items: ['code'] },
        ],
        mobile: {
            menubar: false,
            toolbar: [
                { name: 'styles', items: ['styles'] },
                { name: 'formatting', items: ['bold', 'italic', 'forecolor'] },
                { name: 'link', items: ['link'] },
                { name: 'lists', items: ['bullist', 'numlist'] },
                { name: 'indentation', items: ['outdent', 'indent'] },
                { name: 'media', items: ['image'] },
                { name: 'ai', items: ['reword', 'undo', 'redo'] },
                { name: 'custom', items: ['redactButton'] },
                { name: 'code', items: ['code'] },
            ],
        },
        convert_urls: false,
        plugins: 'link image lists table code codesample fullscreen autoresize code',
        license_key: 'gpl',
        // Let a pasted screenshot (e.g. clipboard from Win+Shift+S / Cmd+Shift+4) through
        // as a blob instead of being silently dropped - images_upload_handler below then
        // ships that blob to the server and swaps it for a real <img src> pointing at the
        // uploaded file, same as typing/dragging one in via the toolbar image button.
        paste_data_images: true,
        images_upload_handler: function (blobInfo) {
            return new Promise(function (resolve, reject) {
                var editorEl = document.getElementById('ticket-reply-editor');
                var ticketId = editorEl ? editorEl.getAttribute('data-ticket-id') : null;
                var csrfInput = document.querySelector('#ticketReplyForm input[name="csrf_token"]');

                if (!ticketId || !csrfInput) {
                    reject('Image upload is not available here.');
                    return;
                }

                var formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                formData.append('ticket_id', ticketId);
                formData.append('csrf_token', csrfInput.value);

                fetch('ticket_attachment_upload.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.location) {
                            resolve(data.location);
                        } else {
                            reject((data && data.error && data.error.message) || 'Image upload failed.');
                        }
                    })
                    .catch(function () {
                        reject('Image upload failed.');
                    });
            });
        },
        setup: function(editor) {
            editor.on('init', function() {
                window.onbeforeunload = function() {
                    // If editor is dirty AND not inside a visible modal → warn
                    const inVisibleModal = editor.getContainer()?.closest('.modal.show');
                    if (!inVisibleModal && editor.isDirty()) {
                        return "You have unsaved changes. Are you sure you want to leave?";
                    }
                };

                // When the modal closes, mark editor clean
                const modal = editor.getContainer()?.closest('.modal');
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', () => {
                        editor.undoManager.clear();
                        editor.setDirty(false);
                    });
                }

            });

            var rewordButtonApi;

            editor.ui.registry.addButton('reword', {
                icon: 'ai',
                tooltip: 'Reword Text',
                onAction: function() {
                    var content = editor.getContent();
                    rewordButtonApi.setEnabled(false);
                    editor.setProgressState(true);

                    fetch('ajax.php?ai_reword', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ text: content, csrf_token: window.csrfToken }),
                    })
                        .then(response => {
                            if (!response.ok) throw new Error('Network response was not ok');
                            return response.json();
                        })
                        .then(data => {
                            editor.setProgressState(false);
                            rewordButtonApi.setEnabled(true);

                            if (data.ok && data.rewordedText) {
                                editor.undoManager.transact(function() {
                                    editor.setContent(data.rewordedText);
                                });
                                editor.notificationManager.open({
                                    text: 'Text reworded successfully!',
                                    type: 'success',
                                    timeout: 3000
                                });
                            } else {
                                // Leave the editor's existing content untouched on failure.
                                editor.notificationManager.open({
                                    text: data.error || 'Could not reword the text.',
                                    type: 'error',
                                    timeout: 5000
                                });
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            editor.setProgressState(false);
                            rewordButtonApi.setEnabled(true);
                            editor.notificationManager.open({
                                text: 'An error occurred while rewording the text.',
                                type: 'error',
                                timeout: 5000
                            });
                        });
                },
                onSetup: function(buttonApi) {
                    rewordButtonApi = buttonApi;
                    return function() {};
                }
            });

            editor.ui.registry.addButton('redactButton', {
                icon: 'permanent-pen',
                tooltip: 'Redact Text',
                onAction: function() {
                    var selectedText = editor.selection.getContent({ format: 'text' });
                    if (selectedText) {
                        var newContent = '<span style="font-weight: bold; color: red;">[REDACTED]</span>';
                        editor.selection.setContent(newContent);
                    } else {
                        alert('Please select a word to redact');
                    }
                }
            });
        }
    });

    // Initialize TinyMCE for the email signature editor. Deliberately separate from
    // .tinymceTicket above - that toolbar has no font size or clear-formatting control
    // (fine for short ticket replies), but a signature often gets pasted in from
    // Outlook/Word with inconsistent inline font sizes that need fixing, so this one
    // gets real sizing/formatting/paste-cleanup tools instead.
    tinymce.init({
        selector: '.tinymceSignature',
        browser_spellcheck: true,
        // Unlike .tinymceTicket, this stays enabled - pasted signatures (Outlook/Word)
        // often bring empty spacer rows/cells along, and right-click > table row/column
        // controls is the most direct way to clean those out.
        contextmenu: 'link image table',
        resize: true,
        min_height: 150,
        max_height: 500,
        promotion: false,
        branding: false,
        menubar: false,
        statusbar: false,
        toolbar: [
            { name: 'fonts', items: ['fontfamily', 'fontsize'] },
            { name: 'formatting', items: ['bold', 'italic', 'underline', 'forecolor'] },
            { name: 'align', items: ['alignleft', 'aligncenter', 'alignright'] },
            { name: 'clear', items: ['removeformat'] },
            { name: 'table', items: ['tableinsertrowbefore', 'tableinsertrowafter', 'tabledeleterow', 'tabledeletecol', 'tabledelete'] },
            { name: 'insert', items: ['link', 'image'] },
            { name: 'history', items: ['undo', 'redo'] },
            { name: 'code', items: ['code'] },
        ],
        mobile: {
            menubar: false,
            toolbar: [
                { name: 'fonts', items: ['fontfamily', 'fontsize'] },
                { name: 'formatting', items: ['bold', 'italic', 'underline', 'forecolor'] },
                { name: 'clear', items: ['removeformat'] },
                { name: 'table', items: ['tabledeleterow', 'tabledeletecol'] },
                { name: 'insert', items: ['link', 'image'] },
                { name: 'code', items: ['code'] },
            ],
        },
        convert_urls: false,
        content_style: 'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;}',
        // Strip Word/Outlook cruft (mso-* styles, conditional comments, stray <font> tags)
        // on paste so a pasted signature starts from something the toolbar can actually control.
        paste_remove_styles_if_webkit: true,
        paste_webkit_styles: 'none',
        paste_word_valid_elements: 'p,br,span,strong,b,em,i,u,a[href],img[src|alt|width|height],table,tr,td,th,tbody,thead',
        font_size_formats: '8pt 9pt 10pt 11pt 12pt 14pt 16pt 18pt 24pt 36pt',
        plugins: 'link image lists table code',
        license_key: 'gpl',
        // The single biggest reason pasted Outlook/Word signatures "can't be resized": every
        // word/line carries its own explicit inline font-size/font-family, which then fights
        // the toolbar's font-size control (it sets a wrapping style, but the more specific
        // nested style underneath still wins visually). Strip those two properties - and
        // legacy <font size/face> tags - on the way in, so what lands in the editor is plain
        // text you can actually resize/style with the toolbar. Bold/italic/color/links/images
        // are left alone.
        paste_preprocess: function (plugin, args) {
            // &quot; (an HTML entity for a literal quote inside style="...") itself contains
            // a semicolon, which breaks a naive split(';') mid-entity - decode before
            // splitting, re-encode before reinserting into the attribute.
            function decodeQuotes(s) { return s.replace(/&quot;/gi, '"'); }
            function encodeQuotes(s) { return s.replace(/"/g, '&quot;'); }

            args.content = args.content
                .replace(/<font[^>]*>/gi, '<span>')
                .replace(/<\/font>/gi, '</span>')
                .replace(/style\s*=\s*"([^"]*)"/gi, function (match, styleStr) {
                    var kept = decodeQuotes(styleStr).split(';').filter(function (rule) {
                        var prop = rule.split(':')[0].trim().toLowerCase();
                        return prop
                            && prop.indexOf('font-size') !== 0
                            && prop.indexOf('font-family') !== 0
                            && prop.indexOf('line-height') !== 0
                            && prop.indexOf('mso-') !== 0
                            && prop !== 'font';
                    }).join(';');
                    return kept.trim() ? 'style="' + encodeQuotes(kept) + '"' : '';
                });
        },
    });

    // Initialize TinyMCE Redact-only
    tinymce.init({
        selector: '.tinymceRedact',
        browser_spellcheck: true,
        contextmenu: false,
        resize: true,
        min_height: 300,
        max_height: 600,
        promotion: false,
        branding: false,
        menubar: false,
        statusbar: false,
        toolbar: 'redactButton',
        mobile: {
            menubar: false,
            plugins: 'autosave lists autolink',
            toolbar: 'redactButton'
        },
        convert_urls: false,
        plugins: 'link image lists table code fullscreen autoresize',
        license_key: 'gpl',
        setup: function(editor) {

            editor.on('init', function() {
                window.onbeforeunload = function() {
                    // If editor is dirty AND not inside a visible modal → warn
                    const inVisibleModal = editor.getContainer()?.closest('.modal.show');
                    if (!inVisibleModal && editor.isDirty()) {
                        return "You have unsaved changes. Are you sure you want to leave?";
                    }
                };

                // When the modal closes, mark editor clean
                const modal = editor.getContainer()?.closest('.modal');
                if (modal) {
                    modal.addEventListener('hidden.bs.modal', () => {
                        editor.undoManager.clear();
                        editor.setDirty(false);
                    });
                }
            });

            editor.on('keydown', function(e) {
                e.preventDefault();
            });

            editor.ui.registry.addButton('redactButton', {
                icon: 'permanent-pen',
                tooltip: 'Redact',
                text: 'REDACT',
                onAction: function() {
                    var selectedText = editor.selection.getContent({ format: 'text' });
                    if (selectedText) {
                        var newContent = '<span style="font-weight: bold; color: red;">[REDACTED]</span>';
                        editor.selection.setContent(newContent);
                    } else {
                        alert('Please select a word to redact');
                    }
                }
            });
        }
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTinyMCEEditors);
} else {
    initTinyMCEEditors();
}

document.addEventListener('DOMContentLoaded', function() {
    // ---- Date/time pickers (Tempus Dominus 6) ----
    document.querySelectorAll('.datetimepicker').forEach(function (el) {
        try { new tempusDominus.TempusDominus(el); } catch (err) { /* noop */ }
    });

    // ---- Input masks (vanilla Inputmask 5) ----
    if (window.Inputmask) {
        Inputmask().mask(document.querySelectorAll('[data-mask]'));
    }

    // ---- Tooltips & popovers (Bootstrap 5) ----
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        bootstrap.Tooltip.getOrCreateInstance(el);
    });
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        bootstrap.Popover.getOrCreateInstance(el, { container: 'body' });
    });

    // ---- Clipboard copy with BS5 tooltip feedback ----
    if (window.ClipboardJS) {
        var clipboard = new ClipboardJS('.clipboardjs');
        var flashTooltip = function (el, message) {
            var tip = bootstrap.Tooltip.getOrCreateInstance(el, { trigger: 'manual', placement: 'bottom', title: message });
            tip.setContent({ '.tooltip-inner': message });
            tip.show();
            setTimeout(function () { tip.hide(); }, 1000);
        };
        clipboard.on('success', function (e) { flashTooltip(e.trigger, 'Copied!'); e.clearSelection(); });
        clipboard.on('error', function (e) { flashTooltip(e.trigger, 'Failed!'); });
    }

    // ---- Tables (simple-datatables) ----
    document.querySelectorAll('.dataTables').forEach(function (el) {
        try { new simpleDatatables.DataTable(el, { searchable: true, perPageSelect: [10, 25, 50, 100] }); } catch (err) { /* noop */ }
    });

    // ---- Date-range filter (#dateFilter) via Litepicker (replaces daterangepicker/date_filter.js) ----
    initDateRangeFilter();

    // ---- .table-responsive dropdown reparent so menus aren't clipped (BS5, vanilla) ----
    initTableResponsiveDropdowns();

    // ---- BS4 -> BS5 attribute coexistence shims for un-ported markup ----
    initLegacyBootstrapShims();

    // ---- toastr fallback (BS5 toasts) + password show/hide toggle ----
    initToastrShim();
    initPasswordToggles();
    initButtonGroupToggle();
    initPrintButtons();
});

/* ============================================================
   Helpers (hoisted; called from the DOMContentLoaded block above)
   ============================================================ */

// Litepicker range picker bound to #dateFilter, writing #canned_date/#dtf/#dtt
// and auto-submitting — mirrors the semantics of the old date_filter.js.
function initDateRangeFilter() {
    var input = document.getElementById('dateFilter');
    if (!input || !window.Litepicker) { return; }

    var cannedEl = document.getElementById('canned_date');
    var dtfEl = document.getElementById('dtf');
    var dttEl = document.getElementById('dtt');

    var hasValues = (dtfEl && dttEl && dtfEl.value && dttEl.value) ||
                    (cannedEl && cannedEl.value && cannedEl.value !== '');
    if (!hasValues) {
        if (cannedEl) { cannedEl.value = 'alltime'; }
        if (dtfEl) { dtfEl.value = '1970-01-01'; }
        if (dttEl) { dttEl.value = '2099-12-31'; }
    }

    function setDisplay(start, end) {
        if (start === '1970-01-01' && end === '2099-12-31') {
            input.value = 'All Time';
        } else {
            input.value = start + ' — ' + end;
        }
    }
    setDisplay((dtfEl && dtfEl.value) || '1970-01-01', (dttEl && dttEl.value) || '2099-12-31');

    /* eslint-disable no-new */
    new Litepicker({
        element: input,
        singleMode: false,
        numberOfMonths: 2,
        numberOfColumns: 2,
        format: 'YYYY-MM-DD',
        firstDay: 1,
        startDate: (dtfEl && dtfEl.value) || null,
        endDate: (dttEl && dttEl.value) || null,
        setup: function (picker) {
            picker.on('selected', function (d1, d2) {
                var s = d1.format('YYYY-MM-DD');
                var e = d2.format('YYYY-MM-DD');
                if (cannedEl) { cannedEl.value = 'custom'; }
                if (dtfEl) { dtfEl.value = s; }
                if (dttEl) { dttEl.value = e; }
                setDisplay(s, e);
                if (input.form) { input.form.submit(); }
            });
        }
    });
}

// Dropdowns inside a .table-responsive scroll container get clipped. BS5's Popper
// flips but can't escape the ancestor's overflow, so reparent the menu to <body>
// on show (before BS5 creates its Popper) and restore it on hide.
function initTableResponsiveDropdowns() {
    document.addEventListener('show.bs.dropdown', function (e) {
        var toggle = e.target;
        if (!toggle || !toggle.closest) { return; }
        var dropdown = toggle.closest('.dropdown');
        if (!dropdown || !dropdown.closest('.table-responsive')) { return; }
        var menu = dropdown.querySelector(':scope > .dropdown-menu');
        if (!menu) { return; }

        var placeholder = document.createComment('trf-menu');
        menu.parentNode.insertBefore(placeholder, menu.nextSibling);
        menu.style.maxHeight = '70vh';
        menu.style.overflowY = 'auto';
        toggle._trfMenu = menu;
        menu._trfPlaceholder = placeholder;
        document.body.appendChild(menu);
    });

    document.addEventListener('hidden.bs.dropdown', function (e) {
        var toggle = e.target;
        var menu = toggle && toggle._trfMenu;
        if (menu && menu._trfPlaceholder && menu._trfPlaceholder.parentNode) {
            menu.style.position = '';
            menu.style.inset = '';
            menu.style.transform = '';
            menu.style.margin = '';
            menu.style.maxHeight = '';
            menu.style.overflowY = '';
            menu._trfPlaceholder.parentNode.insertBefore(menu, menu._trfPlaceholder);
            menu._trfPlaceholder.remove();
            menu._trfPlaceholder = null;
            toggle._trfMenu = null;
        }
    });
}

// Keep un-ported (BS4-attribute) markup functional during the migration sweep.
// Only fires for legacy attributes that BS5 no longer recognizes.
function initLegacyBootstrapShims() {
    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest(
            '[data-dismiss="modal"], [data-toggle="modal"], [data-toggle="dropdown"], [data-toggle="tab"], [data-toggle="pill"], [data-toggle="collapse"]'
        );
        if (!el || el.getAttribute('data-bs-toggle') || el.getAttribute('data-bs-dismiss')) { return; }

        // Legacy modal dismiss
        if (el.matches('[data-dismiss="modal"]')) {
            var modalEl = el.closest('.modal');
            if (modalEl && window.bootstrap) { bootstrap.Modal.getOrCreateInstance(modalEl).hide(); }
            return;
        }
        if (!window.bootstrap) { return; }
        var targetSel = el.getAttribute('data-target') || el.getAttribute('href');

        if (el.matches('[data-toggle="modal"]') && targetSel) {
            var m = document.querySelector(targetSel);
            if (m) { e.preventDefault(); bootstrap.Modal.getOrCreateInstance(m).show(); }
        } else if (el.matches('[data-toggle="dropdown"]')) {
            e.preventDefault();
            bootstrap.Dropdown.getOrCreateInstance(el).toggle();
        } else if ((el.matches('[data-toggle="tab"]') || el.matches('[data-toggle="pill"]'))) {
            e.preventDefault();
            bootstrap.Tab.getOrCreateInstance(el).show();
        } else if (el.matches('[data-toggle="collapse"]') && targetSel) {
            var c = document.querySelector(targetSel);
            if (c) { e.preventDefault(); bootstrap.Collapse.getOrCreateInstance(c).toggle(); }
        }
    });
}

// Minimal toastr-compatible fallback backed by BS5 toasts, used only if the real
// toastr (jQuery plugin) isn't present. Keeps window.toastr.{success,error,info,warning}.
function initToastrShim() {
    if (window.toastr) { return; }
    var container;
    function ensureContainer() {
        if (container) { return container; }
        container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1090';
        document.body.appendChild(container);
        return container;
    }
    function show(message, title, variant) {
        if (!window.bootstrap) { return; }
        var wrap = document.createElement('div');
        wrap.className = 'toast align-items-center text-bg-' + variant + ' border-0';
        wrap.setAttribute('role', 'alert');
        var inner = document.createElement('div');
        inner.className = 'd-flex';
        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = (title ? title + ': ' : '') + (message || '');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-close btn-close-white me-2 m-auto';
        btn.setAttribute('data-bs-dismiss', 'toast');
        inner.appendChild(body);
        inner.appendChild(btn);
        wrap.appendChild(inner);
        ensureContainer().appendChild(wrap);
        var t = bootstrap.Toast.getOrCreateInstance(wrap, { delay: 4000 });
        wrap.addEventListener('hidden.bs.toast', function () { wrap.remove(); });
        t.show();
    }
    window.toastr = {
        success: function (m, t) { show(m, t, 'success'); },
        error:   function (m, t) { show(m, t, 'danger'); },
        info:    function (m, t) { show(m, t, 'info'); },
        warning: function (m, t) { show(m, t, 'warning'); }
    };
}

// Bootstrap 5 dropped the BS4 data-toggle="buttons" radio/checkbox-styled-as-
// button-group plugin entirely (no data-bs-toggle equivalent exists) - this
// shim restores the "clicking a label toggles which one looks active" behavior
// for every .js-btn-group-toggle group app-wide (reply-type toggles, theme
// picker, permission-level pickers, etc).
function initButtonGroupToggle() {
    document.addEventListener('change', function (e) {
        var input = e.target;
        if (input.type !== 'radio' && input.type !== 'checkbox') { return; }
        var group = input.closest('.js-btn-group-toggle');
        if (!group) { return; }
        group.querySelectorAll('label.btn').forEach(function (label) {
            label.classList.remove('active');
        });
        var label = input.closest('label.btn');
        if (label) { label.classList.add('active'); }
    });
}

// Delegated listener for .js-print-page (CSP forbids inline onclick="window.print();").
function initPrintButtons() {
    document.addEventListener('click', function (e) {
        if (e.target.closest('.js-print-page')) { window.print(); }
    });
}

// Vanilla password show/hide toggle for [data-toggle="password"] (was the
// bootstrap-show-password jQuery plugin). Toggles the sibling input's type.
function initPasswordToggles() {
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest && e.target.closest('[data-toggle="password"]');
        if (!toggle) { return; }
        e.preventDefault();
        var input = null;
        var group = toggle.closest('.input-group');
        if (group) { input = group.querySelector('input[type="password"], input[type="text"][data-pw]'); }
        if (!input && toggle.previousElementSibling && toggle.previousElementSibling.tagName === 'INPUT') {
            input = toggle.previousElementSibling;
        }
        if (!input) { return; }
        var icon = toggle.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            input.setAttribute('data-pw', '1');
            if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
        } else {
            input.type = 'password';
            if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
        }
    });
}
