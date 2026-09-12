// Adds Rich Text / Markdown / HTML tabs around a TinyMCE editor whose
// underlying textarea has the "tinymce-builder" class. TinyMCE stays the
// canonical source of truth: switching tabs always syncs edits made in the
// Markdown/HTML boxes back into the editor first, then regenerates the
// target box from the editor's current content.

// Does this content contain interactive KB blocks? The tab bar below needs to
// know, because ONE of its three tabs destroys them. See docBuilderBlocksMarkdown().
// The attribute name is the vocabulary's own marker - \ITFlow\KB\InteractiveBlocks
// and js/tinymce_ikb.js both key on data-ikb - and matching the attribute rather
// than the class means content that lost its classes is still recognised.
//
// Tests the PARSED document (a real element carrying the attribute), not a
// substring match on the serialised markup. A substring match also fires on
// an article that merely MENTIONS the vocabulary - e.g. a <pre><code> sample
// showing how to write one, in an article documenting this very feature -
// where getBody().querySelector('[data-ikb]') is null because the text sits
// inside a <code> block as escaped characters, not as a real attribute on a
// real element. Querying the live body is also cheaper than serialising the
// whole article with getContent() on every SetContent.
function docBuilderHasInteractiveBlocks(root) {
    return !!(root && root.querySelector
        && root.querySelector('[data-ikb], [data-ikb-part], [data-ikb-node]'));
}

// THE MARKDOWN TAB DESTROYS INTERACTIVE BLOCKS, COMPLETELY AND SILENTLY.
//
// Measured, headless Chromium, this repo's own plugins/turndown/turndown.js and
// plugins/marked/marked.min.js, on the full vocabulary (a checklist, a decision
// tree, a copy marker and an embed):
//
//   turndown(getContent())  ->  data-ikb present: NO
//   marked.parse(that)      ->  data-ikb present: NO
//   every one of data-ikb="sequence", data-ikb-part, data-ikb="tree",
//   data-ikb-go, data-ikb="copy", data-ikb="embed" and class="ikb-label": GONE.
//
// The WORDS all survive - the round trip leaves a readable document - but every
// block, every part key and therefore every reader's saved progress is gone,
// with no error and nothing on screen to say it happened. Turndown has never
// heard of .ikb-part and never will; a Turndown passthrough rule would only
// survive a clean there-and-back, not an agent editing the Markdown around a
// block, which is the whole reason the tab exists.
//
// So the tab is refused while the article contains blocks. That is a usability
// regression, deliberately chosen over silent destruction. The HTML tab stays
// open and is the escape hatch for hand-editing: it round-trips through
// editor.setContent(), which re-runs editor.parser, so the tinymce_ikb guard is
// re-applied on the way back and the vocabulary survives byte-for-byte
// (measured, proof p6).
function docBuilderBlocksMarkdown(editor) {
    return docBuilderHasInteractiveBlocks(editor.getBody());
}

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

    // Keep the Markdown tab's appearance honest about whether it can be used.
    // Deliberately NOT the `disabled` attribute: a dead button that swallows the
    // click can never explain itself, and "why is Markdown greyed out" is a
    // support question. It stays clickable, looks unavailable, carries the reason
    // in its tooltip, and says the reason out loud if clicked.
    var MARKDOWN_REASON = 'This article contains interactive blocks. Markdown cannot represent them, '
        + 'and converting would delete every block and every reader\'s saved progress. '
        + 'Use the HTML tab to hand-edit instead.';

    var markdownBtn = tabBar.querySelector('button[data-mode="markdown"]');

    function refreshMarkdownAvailability() {
        if (!markdownBtn) {
            return false;
        }
        var blocked = docBuilderBlocksMarkdown(editor);
        markdownBtn.classList.toggle('disabled', blocked);
        markdownBtn.setAttribute('aria-disabled', blocked ? 'true' : 'false');
        markdownBtn.title = blocked ? MARKDOWN_REASON : '';
        return blocked;
    }

    // Exposed so js/tinymce_ikb.js can re-check availability after the two
    // Interactive-menu actions that mutate the block DOM WITHOUT firing
    // SetContent (markCopy(), and the "Show this block as" mode flip) - both
    // run inside undoManager.transact() with no reparse, so the SetContent/
    // Undo/Redo binding below never sees them and this button would otherwise
    // go stale until something else happened to trigger one of those events.
    // Guarded with typeof on the caller's side, since not every .tinymce
    // editor this plugin loads into has a Doc Builder (or the tinymce-ikb
    // menu) at all.
    editor.ikbRefreshTabs = refreshMarkdownAvailability;

    // The states that can add or remove a block are all of these: the article
    // being loaded in, the ikb plugin's own inserts (which end in setContent),
    // undo/redo, and a round trip through the HTML tab. NodeChange is
    // deliberately not in the list - it fires on every caret move and would run
    // getContent() over the whole article each time.
    editor.on('SetContent Undo Redo', refreshMarkdownAvailability);
    refreshMarkdownAvailability();

    tabBar.addEventListener('click', function(e) {
        var btn = e.target.closest('button[data-mode]');
        if (!btn || btn.dataset.mode === currentMode) {
            return;
        }

        var nextMode = btn.dataset.mode;

        if (currentMode !== 'richtext') {
            syncToEditor(currentMode);
        }

        // Checked AFTER syncToEditor, so a block pasted into the HTML tab and not
        // yet pushed into the editor is still seen. This is the hard stop; the
        // greyed-out button above is only the signposting.
        if (nextMode === 'markdown' && refreshMarkdownAvailability()) {
            editor.notificationManager.open({ text: MARKDOWN_REASON, type: 'warning', timeout: 8000 });
            showMode(currentMode);
            return;
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
    //
    // 'select.select2, input.select2', NOT bare '.select2': TomSelect copies
    // the source element's own classList onto the wrapper it builds
    // (measured: a <select class="select2"> wrapped by TomSelect produces
    // <div class="ts-wrapper ... select2 ...">) - a bare '.select2' query
    // re-matches that WRAPPER on every later call to this function (every
    // AJAX modal load, per the comment above), including for a <select>
    // some other script already wrapped in its own custom TomSelect config
    // before this ran (agent/js/tickets_add_modal.js's #contactSelect does
    // exactly this). new TomSelect() on a wrapper <div> - not a real form
    // control - is nonsense input, and el.tomselect above cannot save this,
    // since it is a property of the ORIGINAL <select>, not its wrapper.
    // Scoping to real form elements makes a wrapper structurally impossible
    // to match here, for this case and any future one shaped the same way.
    document.querySelectorAll('select.select2, input.select2').forEach(function (el) {
        if (el.tomselect) { return; }
        var opts = {
            plugins: el.multiple ? ['remove_button'] : [],
            allowEmptyOption: true,
            // Keep the browser's native option order rather than re-sorting
            sortField: { field: '$order' }
        };
        var ph = el.getAttribute('data-placeholder');
        if (ph) { opts.placeholder = ph; }
        // data-searchable="0" - a plain click-to-pick dropdown, no typeahead
        // text box. For short, fixed option lists (ticket filter pills, etc.)
        // the search input is friction, not help - clicking should show the
        // full list immediately, not ask the user to type first.
        if (el.getAttribute('data-searchable') === '0') {
            opts.controlInput = null;
        }
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

    // Not every page that loads app.js also loads tinymce.min.js (e.g.
    // setup/index.php has its own trimmed script list) - skip quietly
    // instead of throwing on those pages.
    if (typeof tinymce === 'undefined') {
        return;
    }

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
            // 'ikb' is the interactive-KB authoring menu (js/tinymce_ikb.js). The
            // plugin registers it ONLY on the KB's two editors - isKbEditor() in
            // that file decides - and TinyMCE silently omits a toolbar item that
            // nothing registered, so the other 14 .tinymce editors in the app
            // (ticket replies, bulk email, contract/document templates) get no
            // new button and no empty group. Verified in headless Chromium.
            //
            // BESIDE THE LIST BUTTONS, AND THAT POSITION IS MEASURED, NOT TASTE.
            // The KB editor opens in a modal-lg, which makes the editor 766px
            // wide, and TinyMCE's floating toolbar overflows there after
            // 'Justify': in the real modal, everything from 'indentation'
            // rightwards - table, image, source, fullscreen, undo, redo - sits
            // behind the "Reveal or hide additional toolbar items" chevron. Put
            // in the 'extra' group, the one control this whole feature exists
            // for would have been invisible until an agent went looking for it.
            // Next to the list buttons it is both visible and where it belongs:
            // its highest-value action turns the list you are standing in into a
            // saved-progress runbook.
            { name: 'lists', items: ['bullist', 'numlist', 'ikb'] },
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
        // The interactive-KB authoring plugin: the structural guard that stops a
        // stray Backspace destroying a block, plus the Interactive menu. This is
        // a plain same-origin <script src> TinyMCE creates for us, so it is legal
        // under this shell's script-src 'self' (includes/header.php:13) - no build
        // step, no npm, no CDN.
        //
        // Deliberately NO valid_elements / extended_valid_elements: measured on
        // this repo's own TinyMCE 8.5.0, the default schema already round-trips
        // the whole data-ikb vocabulary byte-for-byte, and valid_elements would
        // REPLACE the schema for every editor sharing this init.
        external_plugins: { ikb: '/js/tinymce_ikb.js' },
        // Make an interactive block LOOK like one while it is being edited.
        // Without this it is a heading and some paragraphs, and the first thing
        // an agent notices about it is that parts of it will not take a caret -
        // which is the guard working, and reads as the editor being broken.
        // Generated content only: ::before is not in the DOM, so getContent() is
        // untouched and nothing here can reach storage. Injected by TinyMCE into
        // its own iframe, which inherits this page's CSP, and the agent shell
        // sends style-src 'self' 'unsafe-inline' (includes/header.php:13), so it
        // is legal. The selectors match nothing in the other 15 .tinymce editors.
        content_style:
            '[data-ikb="sequence"],[data-ikb="tree"],[data-ikb="embed"]{'
            + 'position:relative;border:1px dashed #adb5bd;border-radius:4px;'
            + 'padding:2.1rem .75rem .75rem;margin:1rem 0;}'
            + '[data-ikb="sequence"]::before,[data-ikb="tree"]::before,[data-ikb="embed"]::before{'
            + 'content:attr(data-ikb-mode) " interactive block - use the Interactive toolbar button to edit it";'
            + 'position:absolute;top:.3rem;left:.75rem;font:11px/1.4 system-ui,sans-serif;'
            + 'letter-spacing:.02em;text-transform:uppercase;color:#6c757d;}'
            + '[data-ikb="tree"]::before{content:"decision tree - use the Interactive toolbar button to edit it";}'
            + '[data-ikb="embed"]::before{content:"embedded tool";}'
            + 'pre[data-ikb="copy"]{border-left:3px solid #adb5bd;padding-left:.6rem;}',
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
