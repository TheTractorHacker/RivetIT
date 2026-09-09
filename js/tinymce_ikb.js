/*
 * js/tinymce_ikb.js - AUTHORING for the interactive KB vocabulary.
 *
 * Loaded by TinyMCE itself, from the `.tinymce` init in js/app.js:
 *     external_plugins: { ikb: '/js/tinymce_ikb.js' }
 * That is a plain <script src> TinyMCE creates for us, so it is same-origin and
 * legal under the agent shell's script-src 'self' (includes/header.php:13). No
 * build step, no npm, no CDN, nothing fetched at runtime.
 *
 * The vocabulary this file writes is defined by \ITFlow\KB\InteractiveBlocks
 * (src/KB/InteractiveBlocks.php). Everything emitted here is shaped so that
 * InteractiveBlocks::normalise() is a NO-OP on it: same classes, same attribute
 * names, same key grammar, same regenerated tree href. If a shape changes here,
 * it changes there in the same commit, and the round-trip proof is re-run.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS IN HERE, IN THE ORDER IT MATTERS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. THE STRUCTURAL GUARD. A measured hazard, not a theoretical one: with the
 *    caret at the START of a part's label, ONE Backspace merges the label into
 *    the block title and DELETES the <h5 class="ikb-label"> element outright,
 *    leaving a part with a key and no title. The guard makes the wrapper divs
 *    non-editable furniture and re-enables the title/label/body/node elements
 *    inside them, so words stay editable in place and structure cannot dissolve.
 *    The marker is stripped on the way out, so `contenteditable` never reaches
 *    storage - and that filter is load-bearing, not belt and braces. Measured
 *    against the bundled HTMLPurifier 4.15.0 with the KB config:
 *        IN  <div data-ikb="sequence" ... contenteditable="false">
 *              <h5 class="ikb-label" contenteditable="true">x</h5></div>
 *        OUT <div data-ikb="sequence" ... contenteditable="false">
 *              <h5 class="ikb-label">x</h5></div>
 *    contenteditable="false" SURVIVES the purifier (it registers
 *    HTMLPurifier_AttrDef_HTML_ContentEditable, HTMLPurifier.standalone.php:12891,
 *    which accepts "false" and rejects "true"). This file's serializer filter and
 *    InteractiveBlocks::normalise() are the only two things between an editor
 *    marker and the database.
 *
 * 2. PATH 1 - CONVERT WHAT IS ALREADY THERE. Select an existing <ul>/<ol>, pick
 *    a display, and it becomes a block with no retyping at all. This is the one
 *    that will actually get used: the KB is already full of lists. The same
 *    action on a <pre> marks it copy-to-clipboard.
 *
 * 3. PATH 2 - THE CREATE DIALOG. TinyMCE's own windowManager. A real form with
 *    no HTML in it anywhere: Title, a Display dropdown, and one textarea where a
 *    flush-left line is a label and an indented line is body text. The Display
 *    dropdown is the payoff for collapsing four block types into one primitive.
 *
 * 4. THE DECISION-TREE DIALOG. An indented outline - odd levels are choices,
 *    even levels are questions or the final answer - with the same live preview.
 *
 * 5. RE-EDIT. WORDS are edited inline like any other text; that is what the
 *    guard's contenteditable="true" is for. STRUCTURE - reorder, add, delete,
 *    rename, switch display - reopens the dialog with the block's current steps
 *    already in the textarea and EVERY EXISTING PART KEY CARRIED THROUGH, so a
 *    reader's saved ticks survive a structural edit. A LABEL kept word-for-word
 *    keeps its own serialised HTML too - not just its key - so a link or
 *    inline code in a label survives the same way a rich body already does; a
 *    reworded label falls back to plain text, which is correct, since it is
 *    no longer the label a reader ticked. A body that is not plain paragraphs
 *    (an image, a table, inline code, a nested block) is NOT flattened into
 *    the textarea: it stays attached to its key and is re-attached on save.
 *    A decision tree re-edits the same way, from its own dialog: the outline
 *    is rebuilt from the tree's current nodes, and a question kept
 *    word-for-word keeps its node key, so a reader's saved position in the
 *    tree survives too. Trees have no display modes, so there is no "Show
 *    this block as" for one.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * EVERY INSERT IS A DOM WRITE FOLLOWED BY A RE-PARSE
 * ─────────────────────────────────────────────────────────────────────────────
 * Neither half is enough on its own, and reparse() carries the measurements:
 * editor.insertContent() runs the parser but inserts at the SELECTION, which
 * nests a whole block inside the <li> an agent is standing in; a raw DOM write
 * places the block correctly but never sees editor.parser, so the new block is
 * unguarded and one Backspace destroys the label the guard exists to protect.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE BUTTON IS NOT ON EVERY EDITOR
 * ─────────────────────────────────────────────────────────────────────────────
 * The `.tinymce` selector matches 16 textareas (ticket replies, bulk email,
 * contract and document templates, ...). Only the KB's two purify with the
 * interactive vocabulary registered, so a block authored anywhere else would be
 * silently flattened to prose on save. isKbEditor() gates the whole UI, and
 * TinyMCE simply omits a toolbar item nobody registered. The guard is
 * registered unconditionally - it costs nothing where no block exists, and it
 * protects a block that arrives by copy-paste into an editor with no button.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ADDING A FIFTH BLOCK TYPE COSTS, IN THIS FILE:
 * ─────────────────────────────────────────────────────────────────────────────
 *   * one entry in MENU_BUILDERS (a label plus the function that opens its
 *     dialog to CREATE one), and
 *   * one build<Type>() that returns a markup string.
 * That is the whole cost of CREATING a fifth type: not the guard, not
 * isKbEditor(), not the menu assembly, not the preview, not the insert path.
 * The render layer's matching cost is one ITFlowKB.register() call
 * (js/kb_interactive.js) and the purifier's is one addAttribute()
 * (src/KB/InteractiveBlocks.php).
 *
 * STRUCTURAL RE-EDIT and UN-BLOCKING are NOT covered by that seam - they are
 * wired per shape, not generic, and a fifth type needs its own:
 *   * read<Type>() for the re-edit dialog (readSequence() and readTree() share
 *     nothing beyond "read the block's own data-ikb-key back out"), plus its
 *     own branch in the Interactive menu's fetch(), gated on the type's own
 *     `data-ikb` value;
 *   * a "Show this block as" submenu ONLY if the type has display modes at
 *     all - it is sequence-only by construction, not something every type
 *     gets for free;
 *   * a branch in removeInteractivity() for its own shape - `.ikb-part` and
 *     `.ikb-node` are two separate branches today, each stripping that
 *     shape's own dead interactive attributes, and a type un-blocked by
 *     neither branch keeps whatever markup its innerHTML happens to carry.
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT WAS ACTUALLY RUN
 * ─────────────────────────────────────────────────────────────────────────────
 * Headless Chromium 149 against this repo's own plugins/tinymce/tinymce.min.js
 * (TinyMCE 8.5.0), driven by the REAL js/app.js, under a CSP at least as strict
 * as the agent shell's - script-src 'self', with the nonce, 'unsafe-inline' and
 * 'unsafe-eval' all withheld:
 *
 *   p0  the button appears on the KB editor and on NO other .tinymce editor,
 *       with no console warning for the toolbar item it does not register
 *   p1  the guard, against a control with the plugin absent: without it,
 *       Backspace at a part label destroys the <h5 class="ikb-label"> and
 *       Backspace at a tree node destroys the question; with it, both are no-ops
 *       and typing into a label still works
 *   p2  setContent -> getContent byte-identical, still identical after editing a
 *       neighbouring paragraph, idempotent on a second pass, and
 *       "contenteditable" appears in no getContent() output
 *   p3  PATH 1 end to end, then the browser's own output through PHP:
 *       InteractiveBlocks::normalise() no-op with ZERO warnings, the KB purifier
 *       keeps every attribute, and every word lands in kb_article_content_raw
 *   p4  PATH 2: both dialogs driven by real typing, the live preview mounted by
 *       the real js/kb_interactive.js, the mode dropdown re-rendering the same
 *       five lines four ways, zero network requests from ticking a preview
 *   p5  structural re-edit: block key and every part key survive a reorder, a
 *       new step gets a new key, a table body is kept and typed lines are added
 *       above it rather than replacing it
 *   p6  the Doc Builder: Markdown measured destroying 100% of the vocabulary,
 *       then refused; the HTML tab round-trips it byte-for-byte and comes back
 *       guarded, including after a hand-edit
 *   p7  mode flip changes exactly one attribute; un-blocking keeps every word;
 *       a re-parse costs 31-38 ms on a 22 KB article with a 40-part block
 *   p8  THE REAL CONTEXT: the dialog opened from an editor inside an
 *       ajax_modal.js-built Bootstrap modal takes real typing (Bootstrap's
 *       FocusTrap does not steal it - ajax_modal.js already suppresses that)
 *   p9  the New Article form is recognised too, and the preview degrades to
 *       readable structure when kb_interactive.js is not on the page
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * REMEDIATION PASS (round-2 review findings #15-20, #22-23)
 * ─────────────────────────────────────────────────────────────────────────────
 * Added: label-HTML carry-through on a sequence re-edit and its help-text
 * twin (collect(), #15); Object.create(null) for both key-continuity maps and
 * a try/catch around each dialog's preview onInit (#16); a full decision-tree
 * re-edit path - readTree(), reconcileTreeKeys(), the menu wiring, the
 * dialog's existing/block parameters (#17); interactive-attribute stripping
 * when un-blocking a tree (#19); the honest static-preview notice when
 * window.ITFlowKB is absent (#18/#39); the editor.ikbRefreshTabs hook
 * markCopy() and the mode flip now call, so js/app.js's Markdown-tab
 * signposting does not go stale after either (#20); the five-call-site
 * correction here and in js/portal_tinymce_init.js (#23); and the
 * documentation fixes to the "ADDING A FIFTH BLOCK TYPE" and "THE EXTENSION
 * SEAM" comments above (#17's documentation half).
 *
 * VERIFIED, NOT REPRODUCING p0-p9: this pass did not re-run headless Chromium
 * against the real TinyMCE 8.5.0 windowManager - p0-p9 above are the PRIOR
 * round's proofs and are unchanged. What WAS run: this file's own source,
 * loaded unmodified into a jsdom-backed Node vm context (no reimplementation)
 * with a mock `editor` object (getBody/selection/windowManager/undoManager/
 * notificationManager), driving the real fetch()/onAction/onInit/onSubmit
 * functions against real DOM fixtures built from this file's own
 * buildSequence()/buildTree() output - 46 assertions across 7 cases:
 * a label carrying a real <a href> survives a no-op re-edit unchanged, incl.
 * in the live preview panel (not double-escaped); a step or tree question
 * worded exactly "constructor" opens its re-edit dialog without throwing; a
 * genuinely reworded label/question falls back to plain text and mints a new
 * key while an untouched sibling keeps its old one; "Edit this block…" now
 * exists for a tree and reconstructs its outline (including stripping the
 * synthetic "Answer:" prefix back out); "Remove interactivity" on a tree
 * strips data-ikb-go and href from every choice while keeping the words;
 * markCopy() and the mode flip each call editor.ikbRefreshTabs() exactly
 * once when it exists, and neither throws when it does not; the preview
 * shows the honest degrade note (and skips window.ITFlowKB.boot()) precisely
 * when window.ITFlowKB is absent, and does the opposite when it is present.
 * NOT covered by this harness: TinyMCE's own dialog lifecycle, its real
 * windowManager/htmlpanel rendering, and the guard's parser/serializer
 * filters (p1-p2 above) - those need the real TinyMCE integration harness
 * the prior round used, which this pass did not have available. That gap
 * should close before this ships.
 */
(function () {
    'use strict';

    /* ─────────────────────────────────────────────────────────────────────────
     * The grammar. Mirrors src/KB/InteractiveBlocks.php - the constants named in
     * each comment are the authority, and this file must not drift from them.
     * ───────────────────────────────────────────────────────────────────────── */

    /** InteractiveBlocks::KEY_REGEX */
    var KEY_REGEX = /^[a-z0-9][a-z0-9-]{0,23}$/;

    /** InteractiveBlocks::SEQUENCE_MODES, in the order the dropdown shows them. */
    var MODES = [
        { value: 'checklist', text: 'Checklist - tick boxes that remember' },
        { value: 'steps', text: 'Guided steps - one at a time, with Next and Back' },
        { value: 'tabs', text: 'Tabs - one pane at a time' },
        { value: 'accordion', text: 'Accordion - collapsible sections' }
    ];

    /** Short names, for menu items and notifications. */
    var MODE_NAMES = {
        checklist: 'Checklist',
        steps: 'Guided steps',
        tabs: 'Tabs',
        accordion: 'Accordion'
    };

    /** InteractiveBlocks::DEFAULT_MODE */
    var DEFAULT_MODE = 'checklist';

    /* One tab, or two or more spaces, marks a body line in the steps textarea.
     * Two rather than one because a single leading space is what a fast typist
     * leaves by accident, and a stray space must not silently demote a step to
     * body text. */
    var INDENT = /^(?:\t| {2,})/;

    /* Re-rendering the preview on literally every keystroke would re-mount the
     * block through kb_interactive.js each time. 200 ms is under the ~250 ms at
     * which a redraw stops feeling live, and it collapses a burst of typing into
     * one mount. */
    var PREVIEW_DELAY_MS = 200;

    /* ─────────────────────────────────────────────────────────────────────────
     * Small helpers
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Escape for HTML text content AND for an attribute value in one function.
     * Every string an agent types goes through this before it is concatenated
     * into markup; nothing else in this file may build markup out of typed text.
     */
    function esc(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * A fresh 8-hex-character key, matching InteractiveBlocks::mintKey().
     *
     * EXACTLY 8 HEX CHARACTERS IS LOAD-BEARING, not a style choice: a wizard's
     * saved position lives in the same table under the reserved part key '_at'
     * (InteractiveBlocks::POSITION_KEY), whose leading underscore is outside the
     * content-key charset, and a minted key must stay inside it.
     *
     * crypto where available. Math.random is not a uniqueness guarantee across
     * two agents authoring at once, and a duplicate key means two parts sharing
     * one reader's tick until the server re-mints one of them.
     */
    function mintKey() {
        var bytes = new Uint8Array(4);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < 4; i++) { bytes[i] = Math.floor(Math.random() * 256); }
        }
        var out = '';
        for (var j = 0; j < 4; j++) { out += ('0' + bytes[j].toString(16)).slice(-2); }
        return out;
    }

    function isKey(value) {
        return typeof value === 'string' && KEY_REGEX.test(value);
    }

    /** Collapse whitespace (U+00A0 included) the way InteractiveBlocks::normaliseText() does. */
    function flatten(text) {
        return String(text === undefined || text === null ? '' : text)
            .replace(/ /g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /** Serialised HTML of a list of nodes, without disturbing the originals. */
    function nodesToHtml(nodes) {
        var host = document.createElement('div');
        for (var i = 0; i < nodes.length; i++) {
            host.appendChild(nodes[i].cloneNode(true));
        }
        return host.innerHTML;
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Markup builders. THESE ARE THE SHAPES. Each is exactly what
     * InteractiveBlocks::normalise() expects to find, so normalise() returns
     * them byte-identical and reports nothing (proof p3).
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * parts: [{ key, label, labelIsHtml, body }]
     *   label       plain text, unless labelIsHtml
     *   labelIsHtml the label is already-serialised inline HTML lifted out of the
     *               editor's own DOM (the Path 1 conversion, which keeps a
     *               step's <code>, <strong> and links). It is never typed text.
     *   body        already-built HTML, or '' for a part that is a label alone.
     *
     * A part with no body emits NO .ikb-body: normalise() deletes an empty one,
     * so emitting it would make the first save a lossy round trip.
     *
     * blockKey is passed in rather than minted here so the caller can find the
     * block it just inserted, after the re-parse that insert() performs replaces
     * every element reference it was holding.
     */
    function buildSequence(blockKey, title, mode, parts) {
        var html = '<div class="ikb" data-ikb="sequence" data-ikb-mode="' + esc(mode)
            + '" data-ikb-key="' + esc(blockKey) + '">';

        if (flatten(title) !== '') {
            html += '<h4 class="ikb-title">' + esc(title) + '</h4>';
        }

        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            var key = isKey(part.key) ? part.key : mintKey();

            html += '<div class="ikb-part" data-ikb-part="' + esc(key) + '">'
                + '<h5 class="ikb-label">' + (part.labelIsHtml ? part.label : esc(part.label)) + '</h5>';
            if (part.body) {
                html += '<div class="ikb-body">' + part.body + '</div>';
            }
            html += '</div>';
        }

        return html + '</div>';
    }

    /**
     * nodes: [{ key, question, answer, choices: [{ text, to }] }]
     *
     * The href is generated exactly as InteractiveBlocks::normaliseTree()
     * regenerates it. It cannot actually jump - Attr.EnableID is off in all four
     * KB purifier configs, so no node carries the matching id - and that is
     * accepted: it exists so a choice looks, copies and prints like a link, and
     * data-ikb-go is what the render layer binds.
     */
    function buildTree(blockKey, title, nodes) {
        var html = '<div class="ikb" data-ikb="tree" data-ikb-key="' + esc(blockKey) + '"';

        if (nodes.length) {
            html += ' data-ikb-start="' + esc(nodes[0].key) + '"';
        }
        html += '>';

        if (flatten(title) !== '') {
            html += '<h4 class="ikb-title">' + esc(title) + '</h4>';
        }

        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            html += '<div class="ikb-node" data-ikb-node="' + esc(node.key) + '">';
            html += '<p>' + (node.answer ? '<strong>Answer:</strong> ' : '') + esc(node.question) + '</p>';

            if (node.choices.length) {
                html += '<ul>';
                for (var c = 0; c < node.choices.length; c++) {
                    var choice = node.choices[c];
                    html += '<li><a href="#ikb-' + esc(blockKey) + '-' + esc(choice.to)
                        + '" data-ikb-go="' + esc(choice.to) + '">' + esc(choice.text) + '</a></li>';
                }
                html += '</ul>';
            }
            html += '</div>';
        }

        return html + '</div>';
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * The steps textarea: one plain-text format, parsed and printed.
     *
     *     Take a config backup          <- flush left: a step label
     *       Export the running config.  <- indented: that step's body
     *     Power down the old unit
     *
     * This is how people already write runbooks into a text box, which is the
     * whole argument for it over a repeating row widget.
     * ───────────────────────────────────────────────────────────────────────── */

    function parseItems(text) {
        var lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
        var parts = [];
        var current = null;
        var i;

        for (i = 0; i < lines.length; i++) {
            if (flatten(lines[i]) === '') { continue; }

            if (INDENT.test(lines[i]) && current) {
                current.bodyLines.push(flatten(lines[i]));
            } else {
                current = { key: '', label: flatten(lines[i].replace(INDENT, '')), bodyLines: [] };
                parts.push(current);
            }
        }

        for (i = 0; i < parts.length; i++) {
            var body = '';
            for (var b = 0; b < parts[i].bodyLines.length; b++) {
                body += '<p>' + esc(parts[i].bodyLines[b]) + '</p>';
            }
            parts[i].body = body;
            delete parts[i].bodyLines;
        }

        return parts;
    }

    /**
     * The inverse, for re-editing an existing block. A body that is anything
     * other than a run of plain-text paragraphs is deliberately NOT written into
     * the textarea - see readSequence() - so a step holding an image, a table or
     * inline code shows its label alone and keeps its detail untouched.
     */
    function printItems(parts) {
        var out = [];
        for (var i = 0; i < parts.length; i++) {
            out.push(parts[i].label);
            var lines = parts[i].bodyText;
            for (var j = 0; lines && j < lines.length; j++) {
                out.push('  ' + lines[j]);
            }
        }
        return out.join('\n');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * The decision-tree outline.
     *
     *     Is the power LED lit?            level 0  - the first question
     *         Yes                          level 1  - a choice
     *             Does it POST?            level 2  - the next question
     *                 No                   level 3  - a choice
     *                     Reseat the RAM.  level 4  - an answer
     *         No                           level 1
     *             Replace the PSU.         level 2  - an answer
     *
     * ODD levels are choice labels, EVEN levels are questions or answers; a node
     * with no choices beneath it is an answer. One level is four spaces, and a
     * tab counts as four so a mixed file still parses.
     *
     * A branching graph has no simpler faithful plain-text input, which is
     * exactly why this is the one dialog whose live preview is not optional.
     * ───────────────────────────────────────────────────────────────────────── */

    function outlineDepth(line) {
        var width = 0;
        for (var i = 0; i < line.length; i++) {
            if (line.charAt(i) === '\t') { width += 4; }
            else if (line.charAt(i) === ' ') { width += 1; }
            else { break; }
        }
        return Math.floor(width / 4);
    }

    /**
     * Returns { nodes, errors }. NEVER throws and never returns a half-built
     * tree: the dialog shows the first error under a live preview of whatever
     * did parse, while the agent is still typing.
     */
    function parseOutline(text) {
        var lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
        var nodes = [];
        var errors = [];

        /* stack[level] is the node (even levels) or the pending choice (odd
         * levels) that a line one level deeper attaches to. */
        var stack = [];
        var i;

        for (i = 0; i < lines.length; i++) {
            if (flatten(lines[i]) === '') { continue; }

            var level = outlineDepth(lines[i]);
            var words = flatten(lines[i]);

            if (level === 0 && nodes.length > 0) {
                errors.push('Line ' + (i + 1) + ': "' + words + '" is a second question at the left margin. '
                    + 'A tree has one first question - indent this one under a choice.');
                continue;
            }
            if (level > 0 && !stack[level - 1]) {
                errors.push('Line ' + (i + 1) + ': "' + words + '" is indented ' + level + ' level'
                    + (level === 1 ? '' : 's') + ', but nothing above it sits at level ' + (level - 1) + '.');
                continue;
            }

            if (level % 2 === 0) {
                var node = { key: mintKey(), question: words, answer: false, choices: [] };
                nodes.push(node);
                if (level > 0) { stack[level - 1].to = node.key; }
                stack[level] = node;
            } else {
                var choice = { text: words, to: '' };
                stack[level - 1].choices.push(choice);
                stack[level] = choice;
            }
            stack.length = level + 1;
        }

        /* A node nobody branches out of is an answer, and gets the "Answer:"
         * lead-in that makes the JavaScript-off fallback readable in document
         * order - which is the weakest of the four fallbacks and needs the help.
         * A choice with nothing indented under it would have its data-ikb-go
         * dropped by normalise() server-side, so it is caught here, where the
         * agent can still see why. */
        for (var n = 0; n < nodes.length; n++) {
            if (!nodes[n].choices.length) { nodes[n].answer = true; }
            for (var c = 0; c < nodes[n].choices.length; c++) {
                if (!nodes[n].choices[c].to) {
                    errors.push('The choice "' + nodes[n].choices[c].text
                        + '" has nothing indented under it, so it leads nowhere.');
                }
            }
        }

        if (!nodes.length && flatten(text) !== '') {
            errors.push('Nothing here is at the left margin, so there is no first question.');
        }

        return { nodes: nodes, errors: errors };
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Reading an existing block back out of the editor DOM
     * ───────────────────────────────────────────────────────────────────────── */

    /** The block the caret is in, or null. */
    function blockAtCaret(editor) {
        var node = editor.selection.getNode();
        var found = (node && node.closest) ? node.closest('div[data-ikb]') : null;
        return (found && editor.getBody().contains(found)) ? found : null;
    }

    /** DIRECT children carrying an attribute - the render layer's own rule, so a
     *  nested block cannot have its parts read as its parent's. */
    function childrenWith(parent, attribute, className) {
        var out = [];
        for (var i = 0; i < parent.children.length; i++) {
            var kid = parent.children[i];
            if (kid.hasAttribute(attribute) || (className && kid.classList.contains(className))) {
                out.push(kid);
            }
        }
        return out;
    }

    function firstChildByClass(parent, className) {
        for (var i = 0; i < parent.children.length; i++) {
            if (parent.children[i].classList.contains(className)) { return parent.children[i]; }
        }
        return null;
    }

    /**
     * A sequence block -> { title, mode, parts }.
     *
     * bodyText (an array of plain lines) is set ONLY when the body is a run of
     * paragraphs holding nothing but text. Anything richer keeps bodyHtml
     * instead and stays out of the textarea entirely - that is what stops a
     * structural edit destroying an image, a table, inline code or a nested
     * block, and it is the reason bodies are not simply flattened.
     */
    function readSequence(block) {
        var titleEl = firstChildByClass(block, 'ikb-title');
        var partEls = childrenWith(block, 'data-ikb-part', 'ikb-part');
        var parts = [];

        for (var i = 0; i < partEls.length; i++) {
            var partEl = partEls[i];
            var labelEl = firstChildByClass(partEl, 'ikb-label');
            var bodyEl = firstChildByClass(partEl, 'ikb-body');
            var bodyText = null;

            if (bodyEl) {
                bodyText = [];
                for (var c = 0; c < bodyEl.children.length; c++) {
                    var child = bodyEl.children[c];
                    if (child.tagName !== 'P' || child.children.length > 0) { bodyText = null; break; }
                    var line = flatten(child.textContent);
                    if (line !== '') { bodyText.push(line); }
                }
                /* Text sitting directly inside .ikb-body, outside any paragraph,
                 * is not something printItems() can round-trip either. */
                if (bodyText && bodyEl.children.length === 0 && flatten(bodyEl.textContent) !== '') {
                    bodyText = null;
                }
            }

            parts.push({
                key: partEl.getAttribute('data-ikb-part') || '',
                label: labelEl ? flatten(labelEl.textContent) : '',
                /* The label's own serialised HTML, alongside the flattened
                 * plain text above. collect() re-attaches this (and sets
                 * labelIsHtml) whenever a step is matched to an unchanged
                 * label, so a <a href>/<code>/<strong> in a label survives a
                 * structural re-edit the same way a rich body already does -
                 * only a genuinely reworded label falls back to escaped plain
                 * text typed into the textarea. */
                labelHtml: labelEl ? labelEl.innerHTML : '',
                bodyText: bodyText,
                bodyHtml: (bodyEl && bodyText === null) ? bodyEl.innerHTML : null
            });
        }

        return {
            /* THE BLOCK KEY IS CARRIED THROUGH TOO, and it matters more than any
             * part key: every progress row is (article, BLOCK key, part key,
             * principal). Minting a fresh one on a structural edit would orphan
             * every reader's ticks on the whole block at once - measured, and
             * fixed here rather than left to the reader to discover. */
            key: block.getAttribute('data-ikb-key') || '',
            title: titleEl ? flatten(titleEl.textContent) : '',
            mode: block.getAttribute('data-ikb-mode') || DEFAULT_MODE,
            parts: parts
        };
    }

    /**
     * A decision-tree block -> { key, title, outline, oldNodes }.
     *
     * outline is plain text in exactly parseOutline()'s grammar, rebuilt by
     * walking the tree from data-ikb-start (falling back to the first node in
     * document order - the same rule bindTree() uses in js/kb_interactive.js
     * when data-ikb-start is missing or dangling) and printing each
     * question/choice at the depth the walk reaches it.
     *
     * oldNodes is that same walk, in outline order, as { key, question } -
     * what reconcileTreeKeys() matches a freshly re-parsed outline against so
     * a question whose wording is unchanged keeps its node key, and a
     * reader's saved position (\ITFlow\KB\InteractiveBlocks::POSITION_KEY,
     * '_at' - js/kb_interactive.js:78) survives the edit instead of snapping
     * back to the first question.
     */
    function readTree(block) {
        var titleEl = firstChildByClass(block, 'ikb-title');
        var nodeEls = childrenWith(block, 'data-ikb-node', 'ikb-node');
        /* Object.create(null) for both maps below - a node key of exactly
         * "constructor" is unlikely to be TYPED (mintKey() only ever
         * produces 8 lowercase hex characters) but a hand-edited HTML import
         * is not bound by that, and the failure mode is silent: `visited[k]`
         * on a plain {} resolves an untouched "constructor" key to the
         * INHERITED Object.prototype member before this function ever visits
         * it, so walk() would treat a node it has never seen as already
         * walked and silently drop it - and everything under it - from the
         * reconstructed outline, with no error. Same reasoning as byLabel in
         * collect() and byQuestion/remap in reconcileTreeKeys(), below. */
        var byKey = Object.create(null);
        var i;
        for (i = 0; i < nodeEls.length; i++) {
            byKey[nodeEls[i].getAttribute('data-ikb-node')] = nodeEls[i];
        }

        var startKey = block.getAttribute('data-ikb-start');
        if (!byKey[startKey] && nodeEls.length) {
            startKey = nodeEls[0].getAttribute('data-ikb-node');
        }

        var lines = [];
        var oldNodes = [];
        var visited = Object.create(null);

        /* buildTree() prepends "<strong>Answer:</strong> " to a node with no
         * choices; that is presentation DERIVED from the structure
         * (parseOutline() sets .answer from whether choices exist), not
         * something the agent typed, so it is stripped back out here rather
         * than round-tripped as if it were part of the question. */
        function nodeQuestion(el) {
            var p = null;
            var c;
            for (c = 0; c < el.children.length; c++) {
                if (el.children[c].tagName === 'P') { p = el.children[c]; break; }
            }
            if (!p) { return ''; }
            var clone = p.cloneNode(true);
            var first = clone.firstElementChild;
            if (first && first.tagName === 'STRONG' && flatten(first.textContent) === 'Answer:') {
                clone.removeChild(first);
            }
            return flatten(clone.textContent);
        }

        function walk(key, level) {
            var el = byKey[key];
            /* A dangling data-ikb-go (normaliseTree() drops one whose target
             * vanished, but a hand-edited HTML import could still have one)
             * or a cycle (illegal from this dialog, but not from an import):
             * stop rather than loop forever or read past the end of byKey. */
            if (!el || visited[key]) { return; }
            visited[key] = true;

            var question = nodeQuestion(el);
            lines.push(new Array(level * 4 + 1).join(' ') + question);
            oldNodes.push({ key: key, question: question });

            var ul = null;
            var c;
            for (c = 0; c < el.children.length; c++) {
                if (el.children[c].tagName === 'UL') { ul = el.children[c]; break; }
            }
            if (!ul) { return; }

            for (var li = 0; li < ul.children.length; li++) {
                var a = ul.children[li].firstElementChild;
                if (!a || a.tagName !== 'A') { continue; }
                lines.push(new Array((level + 1) * 4 + 1).join(' ') + flatten(a.textContent));
                var go = a.getAttribute('data-ikb-go');
                if (go) { walk(go, level + 2); }
            }
        }

        if (startKey && byKey[startKey]) { walk(startKey, 0); }

        return {
            /* Carried through for the same reason readSequence() carries the
             * block key: every saved position row is keyed on it. */
            key: block.getAttribute('data-ikb-key') || '',
            title: titleEl ? flatten(titleEl.textContent) : '',
            outline: lines.join('\n'),
            oldNodes: oldNodes
        };
    }

    /**
     * Re-parsing an outline always mints brand-new node keys - parseOutline()
     * has no idea an "existing" tree exists, and cannot: it only ever sees
     * the textarea's current text. This matches the FRESH nodes back onto the
     * OLD ones by question text, in outline order (the same rule collect()
     * uses for a sequence's part labels, just above), and rewrites every
     * choice.to through the same table, so a reader's saved position keeps
     * pointing at a real node whenever that node's wording did not change.
     */
    function reconcileTreeKeys(nodes, oldNodes) {
        if (!oldNodes || !oldNodes.length) { return; }

        /* Object.create(null): a question of exactly "constructor" or
         * "__proto__" must not resolve to an inherited Object.prototype
         * member here either - see collect()'s byLabel, above, for the
         * reproduction of what that does to this dialog. */
        var byQuestion = Object.create(null);
        var i;
        for (i = 0; i < oldNodes.length; i++) {
            if (!byQuestion[oldNodes[i].question]) { byQuestion[oldNodes[i].question] = []; }
            byQuestion[oldNodes[i].question].push(oldNodes[i]);
        }

        var remap = Object.create(null);
        for (i = 0; i < nodes.length; i++) {
            var queue = byQuestion[nodes[i].question];
            if (queue && queue.length) {
                var match = queue.shift();
                remap[nodes[i].key] = match.key;
                nodes[i].key = match.key;
            }
        }
        for (i = 0; i < nodes.length; i++) {
            for (var c = 0; c < nodes[i].choices.length; c++) {
                var to = nodes[i].choices[c].to;
                if (to && remap[to]) { nodes[i].choices[c].to = remap[to]; }
            }
        }
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Inserting
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * PUT THE DOCUMENT BACK THROUGH THE PARSER, which is where the guard's
     * attribute filters live.
     *
     * This is the second half of every insert here, and it is not belt and
     * braces - it is the only mechanism that gets both halves right. Measured:
     *
     *   editor.insertContent() DOES run the parser (so the block comes out
     *   guarded), but it inserts at the SELECTION, and with the caret inside a
     *   list item - which is exactly where it is when an agent converts the list
     *   they are standing in - it nested the whole block inside that <li>:
     *       <ul><li><div class="ikb" ...>…</div></li></ul>
     *
     *   Writing the markup straight into the editor's DOM places it correctly,
     *   but bypasses editor.parser entirely, so the new block carries no
     *   contenteditable at all and a single Backspace destroys the label the
     *   guard exists to protect - until the next full re-parse.
     *
     * DOM write for placement, then this for the guard. getContent() runs the
     * serializer (which strips contenteditable) and setContent() runs the parser
     * (which re-applies it), and that round trip is proved byte-identical in
     * proof p2, so nothing else in the article moves. Inside an
     * undoManager.transact() the whole thing is still ONE undo level: measured,
     * one Ctrl+Z restores both the heading and the list.
     */
    function reparse(editor) {
        editor.setContent(editor.getContent());
    }

    /** Replace one element with generated markup, then re-parse. */
    function replaceElement(editor, element, html) {
        element.insertAdjacentHTML('afterend', html);
        element.parentNode.removeChild(element);
        reparse(editor);
    }

    /**
     * Insert generated markup AFTER the top-level element the caret is in.
     *
     * Not at the caret: a block dropped at the caret inside a list item, a table
     * cell or another block is legal HTML that no author asked for. The
     * top-level element is the paragraph, list or block the agent can see
     * themselves standing in, and "it appeared under that" is predictable.
     */
    function insertBlock(editor, html) {
        var body = editor.getBody();
        var node = editor.selection.getNode();

        while (node && node.parentNode && node.parentNode !== body) {
            node = node.parentNode;
        }

        if (node && node.parentNode === body) {
            node.insertAdjacentHTML('afterend', html);
        } else {
            body.insertAdjacentHTML('beforeend', html);
        }
        reparse(editor);
    }

    /** Select a block by its key, after a re-parse has replaced every reference. */
    function selectByKey(editor, blockKey) {
        var block = editor.getBody().querySelector('[data-ikb-key="' + blockKey + '"]');
        if (!block) { return; }
        try {
            editor.selection.select(block);
            block.scrollIntoView({ block: 'nearest' });
        } catch (e) { /* selection is a convenience; never let it break the insert */ }
        editor.nodeChanged();
    }

    function notify(editor, text, type) {
        editor.notificationManager.open({ text: text, type: type || 'info', timeout: 5000 });
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * PATH 1 - convert what is already there
     * ───────────────────────────────────────────────────────────────────────── */

    /** The outermost <ul>/<ol> the selection is in or contains, or null. */
    function listInSelection(editor) {
        var node = editor.selection.getNode();
        if (!node) { return null; }

        var list = node.closest ? node.closest('ul,ol') : null;
        if (list && editor.getBody().contains(list) && !list.closest('div[data-ikb]')) {
            /* The OUTERMOST list: a caret in a nested list must still convert the
             * whole thing, or the nesting becomes the body text of nothing. */
            var outer = list;
            var up = list.parentNode;
            while (up && up !== editor.getBody()) {
                if (up.tagName === 'UL' || up.tagName === 'OL') { outer = up; }
                up = up.parentNode;
            }
            return outer;
        }

        /* A list selected by dragging across it: getNode() is then the common
         * ancestor, so look inside it too - but ONLY for a real range. With a
         * collapsed caret getNode() can be the body itself, and querySelector
         * would then offer to convert the first list in the article, wherever
         * the agent happens to be standing. */
        if (editor.selection.isCollapsed()) { return null; }
        var inside = node.querySelector ? node.querySelector('ul,ol') : null;
        return (inside && !inside.closest('div[data-ikb]')) ? inside : null;
    }

    /** The <pre> the selection is in or contains, or null. */
    function preInSelection(editor) {
        var node = editor.selection.getNode();
        if (!node) { return null; }
        var pre = node.closest ? node.closest('pre') : null;
        if (pre && editor.getBody().contains(pre)) { return pre; }
        /* Same rule as listInSelection(): only a real range may look downwards. */
        if (editor.selection.isCollapsed()) { return null; }
        return node.querySelector ? node.querySelector('pre') : null;
    }

    /**
     * One <li> -> one part. The item's own inline content is the label; the
     * first block-level child, and everything after it, is the body. Inline
     * formatting in the label is KEPT - <code>, <strong> and a link are exactly
     * what a runbook step contains, and <h5> may hold them.
     */
    function listItemToPart(li) {
        var labelNodes = [];
        var bodyNodes = [];
        var seenBlock = false;

        for (var node = li.firstChild; node; node = node.nextSibling) {
            var tag = node.nodeType === 1 ? node.tagName : '';
            if (/^(UL|OL|P|PRE|TABLE|BLOCKQUOTE|DIV|H[1-6])$/.test(tag)) { seenBlock = true; }
            (seenBlock ? bodyNodes : labelNodes).push(node);
        }

        var label = nodesToHtml(labelNodes).replace(/^(?:\s|&nbsp;)+|(?:\s|&nbsp;)+$/g, '');
        var body = nodesToHtml(bodyNodes);

        /* An item that is nothing but a nested block - "<li><p>text</p></li>",
         * which is what a blank line inside a list item produces - would become
         * a part with no title. Promote its first block, the same repair
         * InteractiveBlocks::promoteLabel() makes server-side. */
        if (flatten(label.replace(/<[^>]*>/g, '')) === '' && bodyNodes.length) {
            var first = bodyNodes[0];
            if (first.nodeType === 1 && /^(P|H[1-6])$/.test(first.tagName)) {
                label = first.innerHTML;
                body = nodesToHtml(bodyNodes.slice(1));
            }
        }

        return { key: mintKey(), label: label, labelIsHtml: true, body: body };
    }

    /**
     * ONE CLICK, ZERO TYPING. A heading immediately before the list becomes the
     * block's title and is REMOVED, because the block draws that title itself
     * and leaving it would print the same words twice. The whole conversion is
     * one undoManager transaction, so a single Ctrl+Z puts the heading and the
     * list back exactly as they were.
     */
    function convertList(editor, list, mode) {
        var parts = [];
        for (var i = 0; i < list.children.length; i++) {
            if (list.children[i].tagName === 'LI') {
                parts.push(listItemToPart(list.children[i]));
            }
        }

        if (!parts.length) {
            notify(editor, 'That list has no items in it.', 'warning');
            return;
        }

        var heading = list.previousElementSibling;
        var title = '';
        var usedHeading = false;
        if (heading && /^H[1-6]$/.test(heading.tagName) && flatten(heading.textContent) !== '') {
            title = flatten(heading.textContent);
            usedHeading = true;
        }

        var blockKey = mintKey();
        var html = buildSequence(blockKey, title, mode, parts);

        editor.undoManager.transact(function () {
            if (usedHeading) { heading.parentNode.removeChild(heading); }
            replaceElement(editor, list, html);
        });
        selectByKey(editor, blockKey);

        notify(editor, parts.length + ' item' + (parts.length === 1 ? '' : 's') + ' became a '
            + MODE_NAMES[mode].toLowerCase() + (usedHeading ? ', titled from the heading above it.' : '.'), 'success');
    }

    function markCopy(editor, pre) {
        editor.undoManager.transact(function () {
            pre.setAttribute('data-ikb', 'copy');
            pre.classList.add('ikb-copy');
        });
        editor.nodeChanged();
        /* This mutates the DOM without going through setContent(), so
         * js/app.js's SetContent-driven Markdown-tab signposting never sees
         * it on its own - and a code block just became exactly the shape the
         * Markdown tab cannot represent. Nudge it directly instead of firing
         * a synthetic SetContent, which would also cost a full re-parse. */
        if (typeof editor.ikbRefreshTabs === 'function') { editor.ikbRefreshTabs(); }
        notify(editor, 'Readers now get a Copy button on that code block.', 'success');
    }

    /**
     * Un-block, without losing a word. The furniture goes; the title, every
     * label, every body and every tree node stay, in document order, as ordinary
     * headings and paragraphs.
     *
     * Saved ticks are deliberately NOT deleted here: an accidental click must
     * not wipe every reader's progress. The rows are simply never selected
     * again, and the cron orphan sweep clears them.
     */
    function removeInteractivity(editor, block) {
        var replacement = '';
        var titleEl = firstChildByClass(block, 'ikb-title');
        if (titleEl) { replacement += '<h4>' + titleEl.innerHTML + '</h4>'; }

        for (var i = 0; i < block.children.length; i++) {
            var kid = block.children[i];
            if (kid === titleEl) { continue; }
            if (kid.classList.contains('ikb-part')) {
                var label = firstChildByClass(kid, 'ikb-label');
                var body = firstChildByClass(kid, 'ikb-body');
                if (label) { replacement += '<h5>' + label.innerHTML + '</h5>'; }
                if (body) { replacement += body.innerHTML; }
            } else if (kid.classList.contains('ikb-node')) {
                /* A tree node's choices are <a data-ikb-go="..." href="#ikb-
                 * <blockkey>-<node>">, and that href can never resolve
                 * (Attr.EnableID is off in every KB purifier config - see
                 * buildTree()) even while the block was still interactive.
                 * Copying the node's innerHTML verbatim, as the .ikb-part
                 * branch above does for a sequence, would leave those dead
                 * attributes on plain prose: a link that looks live and goes
                 * nowhere, with no data-ikb-* left anywhere to say why.
                 * Strip them from a clone so the block itself is untouched
                 * until the transact below replaces it. */
                var nodeClone = kid.cloneNode(true);
                var anchors = nodeClone.querySelectorAll('a[data-ikb-go]');
                for (var a = 0; a < anchors.length; a++) {
                    anchors[a].removeAttribute('data-ikb-go');
                    anchors[a].removeAttribute('href');
                }
                replacement += nodeClone.innerHTML;
            } else {
                replacement += kid.innerHTML;
            }
        }

        editor.undoManager.transact(function () {
            replaceElement(editor, block, replacement || '<p></p>');
        });
        notify(editor, 'That block is ordinary text again. Its words are untouched.', 'success');
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * Live preview
     *
     * Rendered by js/kb_interactive.js ITSELF - the same file the reader gets -
     * by dropping the generated markup into a [data-ikb-root] carrying
     * data-ikb-readonly="1" and NO data-ikb-endpoint, then calling
     * ITFlowKB.boot(). Read-only with no endpoint means the store never issues a
     * request (kb_interactive.js flush(): `if (ctx.readOnly || !ctx.endpoint)`),
     * so ticking a box in a preview can never write anybody's progress.
     *
     * If kb_interactive.js is not on the page, the preview degrades to the same
     * markup unmounted - still the title, the steps and their bodies in order,
     * which is a legible preview of the STRUCTURE, and structure is what the
     * dialog is asking about.
     *
     * EVERY STRING REACHING innerHTML HERE IS BUILT BY buildSequence()/
     * buildTree() OUT OF esc()'d TEXT. Rich bodies carried over from an existing
     * block are replaced by an escaped note (previewBody()) rather than being
     * re-serialised into this panel, so no editor HTML is ever re-parsed here.
     * ───────────────────────────────────────────────────────────────────────── */

    var PREVIEW_CLASS = 'ikb-authoring-preview';

    /* One fixed key for every preview. A preview is never stored and never
     * carries a reader's progress, so minting a fresh key on each redraw would
     * only churn. It is inside the content-key grammar so kb_interactive.js
     * binds it exactly as it binds a real block. */
    var PREVIEW_KEY = 'preview';

    /* tox-label is TinyMCE's own field-label class, so the preview is captioned
     * in the dialog's own type rather than in something invented here. */
    function previewPanelHtml() {
        return '<label class="tox-label">Preview</label>'
            + '<div class="' + PREVIEW_CLASS + '" data-ikb-root data-ikb-readonly="1" data-ikb-article="0"></div>';
    }

    /**
     * The body a part shows IN THE PREVIEW.
     *
     * A carried-over rich body is shown as an escaped NOTE, never re-serialised
     * into the panel: that keeps the innerHTML in renderPreview() fed only by
     * strings this file built out of esc()'d text, and it is also the honest
     * thing to show, because the dialog cannot edit that detail.
     */
    function previewBody(part) {
        if (part.carriedRichBody) {
            return (part.typedBody || '')
                + '<p class="ikb-authoring-note">'
                + esc('(this step’s existing detail is kept, below anything you type here)')
                + '</p>';
        }
        return part.body || '';
    }

    function previewParts(parts) {
        var out = [];
        for (var i = 0; i < parts.length; i++) {
            /* labelIsHtml carried through too: without it, a label re-attached
             * as real markup (collect(), below) would be shown here escaped
             * as literal "&lt;a href..." rather than the link it actually is. */
            out.push({
                key: parts[i].key,
                label: parts[i].label,
                labelIsHtml: !!parts[i].labelIsHtml,
                body: previewBody(parts[i])
            });
        }
        return out;
    }

    function renderPreview(host, html, errors) {
        if (!host) { return; }

        var message = (errors && errors.length)
            ? '<p class="ikb-authoring-error">' + esc(errors[0]) + '</p>'
            : '';

        /* THE HONEST-DEGRADE NOTICE. window.ITFlowKB is defined only by
         * js/kb_interactive.js, and that file is not on every page that opens
         * this dialog: agent/kb_articles.php - the ONLY page that opens both
         * the New Article modal and the list page's own Edit modal - does not
         * load it. Falling back to the raw markup with no comment left the
         * Display dropdown previewing IDENTICALLY for checklist / steps /
         * tabs / accordion, and a decision tree showing every node at once
         * with nothing ticked or hidden - indistinguishable from a real,
         * walkable preview unless the agent already knew to distrust it. Say
         * so instead. The real fix is loading kb_interactive.js on that page
         * too (see handoff notes); this is the honest fallback for wherever
         * it is still missing. */
        var live = !!(window.ITFlowKB && typeof window.ITFlowKB.boot === 'function');
        var degraded = (!live && html)
            ? '<p class="ikb-authoring-note">Static preview only on this page - ticking, tabs, steps and the'
                + ' decision tree do not animate here. Save the article, then reopen Edit to see the live'
                + ' version.</p>'
            : '';

        /* Wiped and rebuilt rather than patched: a re-boot must not find a
         * half-mounted block left over from the previous keystroke. */
        host.innerHTML = message + degraded + html;
        host.ikbBooted = false;

        if (live) {
            try {
                window.ITFlowKB.boot();
            } catch (e) {
                /* A preview that fails to animate is still a readable preview. */
                if (window.console && window.console.error) {
                    window.console.error('tinymce_ikb: preview mount failed', e);
                }
            }
        }
    }

    /** Debounced preview, one timer per dialog. */
    function makePreviewer(getHost, build) {
        var timer = null;
        return function (api) {
            if (timer) { window.clearTimeout(timer); }
            timer = window.setTimeout(function () {
                timer = null;
                var shape = build(api.getData());
                renderPreview(getHost(), shape.html, shape.errors);
            }, PREVIEW_DELAY_MS);
        };
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * PATH 2 - the create dialog, and the structural re-edit that reuses it
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * existing: null to create, or readSequence(block) plus that block to edit
     * its structure.
     */
    function openSequenceDialog(editor, existing, block) {
        var previewHost = null;
        var richCount = 0;
        var labelRichCount = 0;
        var i;

        if (existing) {
            for (i = 0; i < existing.parts.length; i++) {
                if (existing.parts[i].bodyHtml !== null) { richCount++; }
                /* A text-only innerHTML never contains a literal "<" - the
                 * browser entity-encodes any character content that could be
                 * mistaken for one - so this is true only for a label holding
                 * a real element (an <a href>, <code>, <strong>, ...). */
                if (/</.test(existing.parts[i].labelHtml || '')) { labelRichCount++; }
            }
        }

        var help = 'One step per line. Indent a line by two spaces to add detail under it.';
        if (existing) {
            help += ' Reorder, rename, add and delete freely: a step keeps its readers’ saved ticks'
                + ' for as long as its wording is unchanged.';
        }
        if (richCount > 0) {
            help += ' ' + richCount + ' step' + (richCount === 1 ? ' has' : 's have')
                + ' detail that is more than plain text - an image, a table, formatting or a nested block.'
                + ' That detail is not shown here, is kept exactly as it is, and stays below anything'
                + ' you type under that step.';
        }
        if (labelRichCount > 0) {
            help += ' ' + labelRichCount + ' step label' + (labelRichCount === 1 ? '' : 's')
                + ' contain' + (labelRichCount === 1 ? 's' : '') + ' formatting or a link - kept exactly as'
                + ' it is unless you actually reword that step here.';
        }

        /**
         * KEY CONTINUITY, and it is the whole reason a structural edit is safe:
         * a step whose LABEL is unchanged keeps its part key, so every reader's
         * tick on it survives a reorder, a rename of its neighbours, a new step
         * above it or a change of display mode. A genuinely new or reworded step
         * gets a new key and starts unticked, which is correct - it is not the
         * step that was ticked. Identical labels are matched in order, so a
         * runbook with two "Reboot" steps keeps both keys the right way round.
         */
        function collect(data) {
            var parts = parseItems(data.items);

            if (existing) {
                /* Object.create(null), NOT {} - a step labelled exactly
                 * "constructor", "toString", "valueOf" or "__proto__" would
                 * otherwise resolve byLabel[old.label] to an INHERITED
                 * Object.prototype member instead of undefined; the
                 * `if (!byLabel[...])` guard then never creates the array,
                 * and .push() on that inherited value throws. Measured: the
                 * throw happened inside this dialog's own preview onInit (see
                 * below), which took the whole dialog's footer down with it -
                 * no Cancel, no Save, an unusable dialog with no way out but
                 * the window's own close button. */
                var byLabel = Object.create(null);
                var k;
                for (k = 0; k < existing.parts.length; k++) {
                    var old = existing.parts[k];
                    if (!byLabel[old.label]) { byLabel[old.label] = []; }
                    byLabel[old.label].push(old);
                }
                for (k = 0; k < parts.length; k++) {
                    var queue = byLabel[parts[k].label];
                    if (queue && queue.length) {
                        var match = queue.shift();
                        parts[k].key = match.key;
                        /* The label survives too, by the same rule as the body
                         * just below: byLabel is keyed on the FLATTENED label,
                         * so reaching this branch already means the typed
                         * label matches match.label exactly. Re-attach the
                         * ORIGINAL serialised label - plain text verbatim if
                         * that is all it ever was, or a real <a href>/<code>/
                         * <strong> if the step came from converting a list
                         * (listItemToPart()) - instead of the escaped
                         * plain-text line the textarea forced it through. A
                         * step whose wording actually changed never reaches
                         * this branch, so it correctly keeps the typed plain
                         * text: see readSequence(). */
                        if (match.labelHtml) {
                            parts[k].label = match.labelHtml;
                            parts[k].labelIsHtml = true;
                        }
                        /* The rich body is re-attached to the key it belongs to,
                         * and anything the agent typed under that step goes
                         * ABOVE it rather than replacing it. Replacing would be
                         * a silent deletion of an image or a table the dialog
                         * deliberately never showed them - the one thing a
                         * structural edit must never do. */
                        if (match.bodyHtml !== null) {
                            parts[k].typedBody = parts[k].body;
                            parts[k].body = (parts[k].body || '') + match.bodyHtml;
                            parts[k].carriedRichBody = true;
                        }
                    }
                }
            }

            return { title: data.title, mode: data.mode, parts: parts };
        }

        var preview = makePreviewer(function () { return previewHost; }, function (data) {
            var shape = collect(data);
            return {
                html: shape.parts.length ? buildSequence(PREVIEW_KEY, shape.title, shape.mode, previewParts(shape.parts)) : '',
                errors: shape.parts.length ? [] : ['Type at least one step and the preview appears here.']
            };
        });

        editor.windowManager.open({
            title: existing ? 'Edit interactive block' : 'New interactive block',
            size: 'large',
            initialData: {
                title: existing ? existing.title : '',
                mode: existing ? existing.mode : DEFAULT_MODE,
                items: existing ? printItems(existing.parts) : ''
            },
            body: {
                type: 'panel',
                items: [
                    { type: 'input', name: 'title', label: 'Title' },
                    { type: 'selectbox', name: 'mode', label: 'Display', items: MODES },
                    {
                        type: 'textarea',
                        name: 'items',
                        label: 'Steps',
                        placeholder: 'Take a config backup\n  Export the running config first.\nPower down the old unit'
                    },
                    { type: 'htmlpanel', name: 'help', html: '<p class="ikb-authoring-help">' + esc(help) + '</p>' },
                    {
                        type: 'htmlpanel',
                        name: 'preview',
                        html: previewPanelHtml(),
                        /* try/catch is not belt and braces: a throw in here used
                         * to abort TinyMCE's own dialog construction mid-way,
                         * leaving a footer with only a Close button and no way
                         * to Cancel or Save normally - reproduced for a step
                         * labelled "constructor" before the Object.create(null)
                         * fix in collect(), above. A future throw here should
                         * degrade the preview, not decapitate the dialog. */
                        onInit: function (element) {
                            previewHost = element.classList.contains(PREVIEW_CLASS)
                                ? element
                                : element.querySelector('.' + PREVIEW_CLASS);
                            try {
                                if (existing) {
                                    var shape = collect({ title: existing.title, mode: existing.mode, items: printItems(existing.parts) });
                                    renderPreview(previewHost, buildSequence(PREVIEW_KEY, shape.title, shape.mode, previewParts(shape.parts)), []);
                                }
                            } catch (e) {
                                if (window.console && window.console.error) {
                                    window.console.error('tinymce_ikb: preview onInit failed', e);
                                }
                                renderPreview(previewHost, '', ['Preview unavailable for this block; editing still works.']);
                            }
                        }
                    }
                ]
            },
            buttons: [
                { type: 'cancel', name: 'cancel', text: 'Cancel' },
                { type: 'submit', name: 'save', text: existing ? 'Save block' : 'Insert block', primary: true }
            ],
            onChange: preview,
            onSubmit: function (api) {
                var shape = collect(api.getData());
                if (!shape.parts.length) {
                    notify(editor, 'Nothing to insert - type at least one step.', 'warning');
                    return;
                }
                /* Editing keeps the block's own key; only a brand-new block
                 * mints one. See readSequence(). */
                var blockKey = (existing && isKey(existing.key)) ? existing.key : mintKey();
                var html = buildSequence(blockKey, shape.title, shape.mode, shape.parts);
                api.close();
                if (existing && block) {
                    editor.undoManager.transact(function () {
                        replaceElement(editor, block, html);
                    });
                } else {
                    editor.undoManager.transact(function () {
                        insertBlock(editor, html);
                    });
                }
                selectByKey(editor, blockKey);
            }
        });
    }

    /* Seeded, not blank. An empty outline box would have to be explained; a
     * worked example that is already a valid tree explains itself, and the
     * preview under it is showing the shape before a key is pressed. */
    var TREE_EXAMPLE = [
        'Is the power LED lit?',
        '    Yes',
        '        Does it POST?',
        '            No',
        '                Reseat the RAM, then log a hardware ticket.',
        '    No',
        '        Replace the PSU.'
    ].join('\n');

    /**
     * existing: null to create, or readTree(block) plus that block to re-edit
     * its structure - the same shape openSequenceDialog() takes.
     */
    function openTreeDialog(editor, existing, block) {
        var previewHost = null;

        var help = 'One question at the left margin. Indent each choice under it by four spaces, then indent'
            + ' the next question - or the final answer - under that choice. Odd levels are choices; even'
            + ' levels are questions and answers.';
        if (existing) {
            help += ' A question kept word-for-word keeps a reader’s current place in the tree; a reworded'
                + ' or new question starts them over from there, same as inserting a fresh one.';
        }

        /* Mirrors collect() in openSequenceDialog(): parse, then - for a
         * re-edit only - reconcile the freshly minted node keys against the
         * block's previous ones by question text, so an unchanged question
         * keeps the key a reader's saved position ('_at') may point at. */
        function shapeOf(data) {
            var parsed = parseOutline(data.outline);
            if (existing) { reconcileTreeKeys(parsed.nodes, existing.oldNodes); }
            return {
                nodes: parsed.nodes,
                html: parsed.nodes.length ? buildTree(PREVIEW_KEY, data.title, parsed.nodes) : '',
                errors: parsed.errors.length
                    ? parsed.errors
                    : (parsed.nodes.length ? [] : ['Type an outline and the tree appears here.'])
            };
        }

        var preview = makePreviewer(function () { return previewHost; }, shapeOf);

        editor.windowManager.open({
            title: existing ? 'Edit decision tree' : 'New decision tree',
            size: 'large',
            initialData: {
                title: existing ? existing.title : '',
                outline: existing ? existing.outline : TREE_EXAMPLE
            },
            body: {
                type: 'panel',
                items: [
                    { type: 'input', name: 'title', label: 'Title' },
                    { type: 'textarea', name: 'outline', label: 'Outline' },
                    { type: 'htmlpanel', name: 'help', html: '<p class="ikb-authoring-help">' + esc(help) + '</p>' },
                    {
                        type: 'htmlpanel',
                        name: 'preview',
                        html: previewPanelHtml(),
                        /* Same defensive wrap as the sequence dialog's preview
                         * onInit, above: a throw here must degrade the
                         * preview, not take the dialog's Cancel/Save footer
                         * with it. */
                        onInit: function (element) {
                            previewHost = element.classList.contains(PREVIEW_CLASS)
                                ? element
                                : element.querySelector('.' + PREVIEW_CLASS);
                            try {
                                var seeded = shapeOf({
                                    title: existing ? existing.title : '',
                                    outline: existing ? existing.outline : TREE_EXAMPLE
                                });
                                renderPreview(previewHost, seeded.html, seeded.errors);
                            } catch (e) {
                                if (window.console && window.console.error) {
                                    window.console.error('tinymce_ikb: preview onInit failed', e);
                                }
                                renderPreview(previewHost, '', ['Preview unavailable for this block; editing still works.']);
                            }
                        }
                    }
                ]
            },
            buttons: [
                { type: 'cancel', name: 'cancel', text: 'Cancel' },
                { type: 'submit', name: 'save', text: existing ? 'Save tree' : 'Insert tree', primary: true }
            ],
            onChange: preview,
            onSubmit: function (api) {
                var data = api.getData();
                var shape = shapeOf(data);
                if (!shape.nodes.length) {
                    notify(editor, 'Nothing to insert - the outline has no first question.', 'warning');
                    return;
                }
                /* Editing keeps the block's own key; only a brand-new tree
                 * mints one. See readTree(). */
                var blockKey = (existing && isKey(existing.key)) ? existing.key : mintKey();
                var html = buildTree(blockKey, data.title, shape.nodes);
                api.close();
                if (existing && block) {
                    editor.undoManager.transact(function () {
                        replaceElement(editor, block, html);
                    });
                } else {
                    editor.undoManager.transact(function () {
                        insertBlock(editor, html);
                    });
                }
                selectByKey(editor, blockKey);
            }
        });
    }

    /* ─────────────────────────────────────────────────────────────────────────
     * THE EXTENSION SEAM - for CREATING a fifth block type from the menu.
     *
     * One entry here plus one build<Type>() above is the whole cost of
     * offering a fifth type in the "new block" menu, and of previewing and
     * inserting it. The guard, isKbEditor() and the menu assembly all stay
     * exactly as they are. Re-editing that type's structure, giving it a
     * "Show this block as" submenu, and un-blocking it correctly are each a
     * separate, per-shape cost - see the header comment's "ADDING A FIFTH
     * BLOCK TYPE COSTS" section for what those actually require.
     * ───────────────────────────────────────────────────────────────────────── */

    var MENU_BUILDERS = [
        {
            text: 'Checklist, steps, tabs or accordion…',
            open: function (editor) { openSequenceDialog(editor, null, null); }
        },
        {
            text: 'Decision tree…',
            open: function (editor) { openTreeDialog(editor, null, null); }
        }
    ];

    /* ─────────────────────────────────────────────────────────────────────────
     * Wiring
     * ───────────────────────────────────────────────────────────────────────── */

    /**
     * Is this the KB editor?
     *
     * Only agent/modals/kb_article/kb_article_add.php and kb_article_edit.php
     * write kb_articles.kb_article_content, and only that column is purified
     * with the interactive vocabulary registered (InteractiveBlocks::apply(),
     * five call sites: the four KB renderers - agent/kb_article.php,
     * client/kb_article.php, agent/modals/kb_article/kb_article_version_view.php,
     * api/v1/kb.php - plus the HTML-import save path,
     * agent/post/kb_article.php:808). Offering the button anywhere else would
     * let an agent author a block that is silently flattened to prose the
     * moment they save.
     *
     * The submit-button names are the discriminator because they are the one
     * thing in those two forms that is unique to the KB and greppable:
     *     $ grep -rn 'name="add_kb_article"\|name="edit_kb_article"' --include=*.php .
     *     agent/modals/kb_article/kb_article_add.php:81
     *     agent/modals/kb_article/kb_article_edit.php:119
     * A `tinymce-ikb` class is checked first, so a future KB-content editor can
     * opt in without this function having to learn about it.
     */
    function isKbEditor(editor) {
        var element = editor.getElement();
        if (!element) { return false; }
        if (element.classList && element.classList.contains('tinymce-ikb')) { return true; }
        var form = element.form || (element.closest ? element.closest('form') : null);
        return !!(form && form.querySelector('[name="add_kb_article"], [name="edit_kb_article"]'));
    }

    tinymce.PluginManager.add('ikb', function (editor) {

        /* ── THE STRUCTURAL GUARD ──────────────────────────────────────────────
         * Registered on EVERY editor this plugin loads into, not only the KB's:
         * it costs nothing where no block exists, and it protects a block that
         * arrives by copy-paste into an editor that has no Interactive button.
         *
         * IN  - each block wrapper becomes non-editable furniture, so a caret
         *       cannot dissolve it...
         * IN  - ...but the title, every label, every body and every tree node
         *       stay editable, so fixing a typo in place still works. That pair
         *       IS the guard. Measured: without it, one Backspace at the start
         *       of a part label merges the label into the block title and
         *       DESTROYS the <h5 class="ikb-label">; with it, the same keystroke
         *       is a no-op.
         * OUT - the marker is stripped on serialisation, so contenteditable
         *       never reaches storage. Load-bearing, and measured: the KB
         *       purifier KEEPS contenteditable="false"
         *       (HTMLPurifier_AttrDef_HTML_ContentEditable,
         *       HTMLPurifier.standalone.php:12891). This filter and
         *       InteractiveBlocks::normalise() are the only two things that stop
         *       an editor marker being written to the database.
         * ────────────────────────────────────────────────────────────────────── */
        editor.on('PreInit', function () {
            editor.parser.addAttributeFilter('data-ikb', function (nodes) {
                nodes.forEach(function (node) {
                    if (node.name === 'div') { node.attr('contenteditable', 'false'); }
                });
            });
            editor.parser.addAttributeFilter('class', function (nodes) {
                nodes.forEach(function (node) {
                    if (/\bikb-(label|body|title|node)\b/.test(node.attr('class') || '')) {
                        node.attr('contenteditable', 'true');
                    }
                });
            });
            editor.serializer.addAttributeFilter('contenteditable', function (nodes) {
                nodes.forEach(function (node) { node.attr('contenteditable', null); });
            });
        });

        if (!isKbEditor(editor)) {
            /* Nothing registered under the name 'ikb', so TinyMCE omits it from
             * this editor's toolbar and the app's other 14 .tinymce editors show
             * nothing new. */
            return;
        }

        editor.ui.registry.addMenuButton('ikb', {
            text: 'Interactive',
            tooltip: 'Turn what is selected into an interactive block, or make a new one',
            fetch: function (callback) {
                var items = [];
                var block = blockAtCaret(editor);
                var list = block ? null : listInSelection(editor);
                var pre = block ? null : preInSelection(editor);

                var blockType = block ? block.getAttribute('data-ikb') : null;

                if (blockType === 'sequence') {
                    items.push({
                        type: 'menuitem',
                        text: 'Edit this block…',
                        onAction: function () { openSequenceDialog(editor, readSequence(block), block); }
                    });
                    items.push({
                        type: 'nestedmenuitem',
                        text: 'Show this block as',
                        getSubmenuItems: function () {
                            return MODES.map(function (mode) {
                                return {
                                    type: 'togglemenuitem',
                                    text: MODE_NAMES[mode.value],
                                    active: block.getAttribute('data-ikb-mode') === mode.value,
                                    onAction: function () {
                                        /* THE MODE FLIP, AND IT IS ONE ATTRIBUTE.
                                         * No key moves, no stored tick moves, no
                                         * word is retyped - the product feature
                                         * that falls out of collapsing four
                                         * block types into one primitive. */
                                        editor.undoManager.transact(function () {
                                            block.setAttribute('data-ikb-mode', mode.value);
                                        });
                                        editor.nodeChanged();
                                        /* Does not change whether the article
                                         * "contains interactive blocks" (the
                                         * block was already data-ikb before and
                                         * after), but it is DOM mutation with no
                                         * SetContent all the same - keep the
                                         * Markdown-tab signposting in js/app.js
                                         * from being the one thing in this menu
                                         * that does not refresh it. */
                                        if (typeof editor.ikbRefreshTabs === 'function') { editor.ikbRefreshTabs(); }
                                        notify(editor, 'Now shown as ' + MODE_NAMES[mode.value].toLowerCase()
                                            + '. Nobody’s saved progress moved.', 'success');
                                    }
                                };
                            });
                        }
                    });
                } else if (blockType === 'tree') {
                    items.push({
                        type: 'menuitem',
                        text: 'Edit this block…',
                        onAction: function () { openTreeDialog(editor, readTree(block), block); }
                    });
                }

                if (block) {
                    items.push({
                        type: 'menuitem',
                        text: 'Remove interactivity (keeps the words)',
                        onAction: function () { removeInteractivity(editor, block); }
                    });
                }

                if (list) {
                    items.push({
                        type: 'nestedmenuitem',
                        text: 'Turn this list into',
                        getSubmenuItems: function () {
                            return MODES.map(function (mode) {
                                return {
                                    type: 'menuitem',
                                    text: MODE_NAMES[mode.value],
                                    onAction: function () { convertList(editor, list, mode.value); }
                                };
                            });
                        }
                    });
                }

                if (pre && pre.getAttribute('data-ikb') !== 'copy') {
                    items.push({
                        type: 'menuitem',
                        text: 'Add a Copy button to this code block',
                        onAction: function () { markCopy(editor, pre); }
                    });
                }

                if (items.length) { items.push({ type: 'separator' }); }

                MENU_BUILDERS.forEach(function (builder) {
                    items.push({
                        type: 'menuitem',
                        text: builder.text,
                        onAction: function () { builder.open(editor); }
                    });
                });

                callback(items);
            }
        });
    });
}());
