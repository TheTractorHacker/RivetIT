/*
 * INTERACTIVE KB BLOCKS - the render layer.
 *
 * Turns the semantic markup defined by src/KB/InteractiveBlocks.php into a
 * working checklist, wizard, tab set, accordion, decision tree or copy button.
 * Nothing here is stored: every control on the page is built by this file at
 * render time, because HTMLPurifier 4.15.0 deletes <input>, unwraps <button>
 * and <details>, and strips every id (Attr.EnableID is off). That constraint is
 * not a limitation being worked around - it is what keeps author HTML unable to
 * declare a control at all.
 *
 * WHY AN EXTERNAL FILE WITH NO INLINE ANYTHING. The portal sends
 *     content-security-policy: default-src 'self'; img-src 'self' data:
 * with NO script-src, NO nonce and NO 'unsafe-inline'. So on client/kb_article.php
 * an inline <script> does not run, an inline <style> does not apply, and a
 * style="" attribute is dead even though HTMLPurifier keeps it. The agent shell
 * is stricter about scripts (script-src 'self' plus a nonce) and looser about
 * styles, so a design that satisfies the portal satisfies both, and this is one
 * file with no per-lane branching.
 *   - Sizing that must vary (the checklist progress bar) is set with
 *     el.style.width, which is a CSSOM property write and is NOT governed by
 *     style-src. Everything else is a class toggle against css/itflow_kb.css.
 *   - Configuration arrives on data attributes on the content wrapper, which is
 *     the precedent js/live_ticket.js already sets (it reads root.dataset.csrf).
 *     Never an inline JSON blob (CSP) and never a .js config endpoint (that is
 *     XSSI-shaped: any third-party page could <script src> it and read a
 *     contact's progress).
 *
 * INERT EVERYWHERE ELSE. The agent shell loads this file on every page; it does
 * nothing at all unless the page contains [data-ikb-root]. Same precedent as
 * js/asset_metrics.js.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE EXTENSION SEAM - what adding a fifth block type costs
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ITFlowKB.register(type, binder) maps a data-ikb value to the function that
 * brings it to life. mountWithin() looks the value up in that map, so a type
 * with no binder is simply left as the ordinary HTML it already is - which is
 * the whole point of storing semantic markup, and it means an article authored
 * against a newer vocabulary degrades on an older install instead of breaking.
 *
 * ADDING ONE COSTS EXACTLY THREE THINGS:
 *   1. one literal in InteractiveBlocks::BLOCK_TYPES (PHP), so the purifier
 *      keeps the attribute;
 *   2. one ITFlowKB.register('yourtype', function (el, ctx) { ... }) call in
 *      this file, or in any script loaded after it;
 *   3. its rules in css/itflow_kb.css.
 * No change to mountWithin, to the boot sequence, to the save path, to the
 * print handling or to any existing binder. The escape-hatch embed is
 * deliberately NOT registered here - it is a separate piece of work, and when
 * it lands it is one register('embed', ...) call and nothing else. Until then
 * an embed block renders as its fallback paragraph, which is the correct
 * no-JavaScript behaviour anyway.
 *
 * A binder is called ONCE per element, with the element and the page context,
 * and inside a try/catch: one block that throws is marked and skipped, and
 * every other block on the article still mounts.
 */
(function () {
    'use strict';

    /* ALREADY LOADED ON THIS PAGE. The version-history modal injects its own
     * <script src> for this file (ajax_modal.js re-creates and runs injected
     * script tags), and that modal can be opened from the article page, which
     * has already loaded it. Re-running the whole IIFE would replace the
     * registry other code may have added to. Boot whatever is new instead. */
    if (window.ITFlowKB && typeof window.ITFlowKB.boot === 'function') {
        window.ITFlowKB.boot();
        return;
    }

    /* The reserved part key holding a wizard's saved position. Must match
     * \ITFlow\KB\InteractiveBlocks::POSITION_KEY. The leading underscore is
     * outside the content-key charset [a-z0-9][a-z0-9-]{0,23}, so it cannot
     * collide with an authored part - by construction, not by convention. */
    var POSITION_KEY = '_at';

    /* Bounds, mirroring the PHP constants. A stored value that predates them,
     * or one hand-written into the markup, is clamped here as well as there. */
    var STATE_MAX = 9999;

    /* Coalescing window for saves. Long enough that ticking five boxes in a row
     * is one request, short enough that a reader who ticks and immediately
     * closes the tab has almost always already been saved. */
    var SAVE_DELAY_MS = 400;

    /* Cap on one request, kept BELOW the endpoint's own KB_PROGRESS_MAX_ITEMS
     * (100, agent/includes/kb_progress_store.php:109) so a legitimate burst is
     * never refused as items_too_many. The endpoint's cap is the authority; this
     * one stops a pathological article building an oversized body at all. */
    var MAX_CHANGES_PER_REQUEST = 90;

    /* The grammar version this file understands. Must track
     * \ITFlow\KB\InteractiveBlocks::VERSION, which the render sites emit as
     * data-ikb-version on the root. */
    var VOCAB_VERSION = 1;

    var binders = {};
    var revealers = [];   // called on beforeprint, undone on afterprint
    var uid = 0;
    var printWired = false;

    // ─────────────────────────────────────────────────────────────────────────
    // Small DOM helpers. Plain ES5 - no jQuery (the portal loads it, the agent
    // side is moving off it, and this file should not care either way).
    // ─────────────────────────────────────────────────────────────────────────

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }

    /* DIRECT children carrying an attribute. Direct, so a block nested inside
     * another block cannot steal its parent's parts. InteractiveBlocks.php
     * applies the same rule server-side. */
    function childrenWith(parent, attribute, className) {
        var out = [];
        var kids = parent.children;
        for (var i = 0; i < kids.length; i++) {
            if (kids[i].hasAttribute(attribute) || (className && kids[i].classList.contains(className))) {
                out.push(kids[i]);
            }
        }
        return out;
    }

    /* First descendant matching a selector, but never one that belongs to a
     * NESTED block - .closest() walks back up and must land on this block. */
    function firstIn(scope, selector, block) {
        var found = scope.querySelectorAll(selector);
        for (var i = 0; i < found.length; i++) {
            if (found[i].closest('[data-ikb]') === block) { return found[i]; }
        }
        return null;
    }

    /* The caption for a tab. Nested-block-aware for the same reason firstIn() is:
       a tab set inside a wizard step must not take its caption from a block
       nested inside its own pane. */
    function labelText(part, block) {
        var label = firstIn(part, '.ikb-label', block);
        var text = label ? label.textContent : '';
        text = text.replace(/\s+/g, ' ').trim();
        return text === '' ? 'Untitled' : text;
    }

    function button(className, text) {
        var b = el('button', className, text);
        b.type = 'button';   // never 'submit': these live inside forms on some pages
        return b;
    }

    function nextId(prefix) {
        uid++;
        /* Always prefixed. An id equal to a bare global name is a DOM-clobbering
         * primitive against window.csrfToken (includes/footer.php:114); the
         * "ikb-" prefix means a generated id can never be one. These ids exist
         * only in the live DOM and are never stored - the purifier would strip
         * them if they were. */
        return 'ikb-' + prefix + '-' + uid;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Registry + mount
    // ─────────────────────────────────────────────────────────────────────────

    function register(type, binder) {
        binders[type] = binder;
    }

    function mountWithin(scope, ctx) {
        if (!scope) { return; }
        var nodes = scope.querySelectorAll('[data-ikb]');
        for (var i = 0; i < nodes.length; i++) {
            var node = nodes[i];
            if (node.ikbMounted) { continue; }
            var binder = binders[node.getAttribute('data-ikb')];
            if (!binder) { continue; }   // unknown type: leave it as plain HTML
            node.ikbMounted = true;
            try {
                binder(node, ctx);
            } catch (e) {
                /* One bad block must never take an article down. The block keeps
                 * its readable markup; only the interactivity is lost. */
                node.classList.add('ikb-failed');
                if (window.console && window.console.error) {
                    window.console.error('kb_interactive: block failed to mount', e);
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Saving progress
    // ─────────────────────────────────────────────────────────────────────────

    /*
     * ONE COALESCED POST PER BURST.
     *
     * THE ENDPOINT CONTRACT, which belongs to agent/kb_progress.php and
     * client/kb_progress.php (shared core: agent/includes/kb_progress_store.php).
     * This side matches it exactly; if it ever changes, kbProgressParseItems()
     * is the definition:
     *
     *     POST <data-ikb-endpoint>
     *     csrf_token = <session token>
     *     a          = <kb_article_id>
     *     items      = JSON [{"b":blockKey,"p":partKey,"s":state,"h":hash}, ...]
     *
     * "h" is the part hash AS THIS PAGE RENDERED IT, taken from
     * data-ikb-hashes. Sending it is what lets a re-tick clear a stale mark
     * permanently: the row is rewritten with the words the reader actually just
     * read. It is "" for the reserved position key, which has no words.
     *
     * data-ikb-endpoint is relative, so the agent side can only ever reach
     * agent/kb_progress.php and the portal side only client/kb_progress.php.
     * Their caps (100 items, 16 KB) are the authority; MAX_CHANGES_PER_REQUEST
     * below keeps this side under them.
     *
     * NOTHING IS SENT IN READ-ONLY MODE. An admin previewing a department
     * portal has contact_id 0, so a write would be meaningless as well as
     * refused; issuing the request would only fill the audit log with blocked
     * writes for someone looking at a checkbox. The tick still happens on
     * screen - a preview is for seeing the article work.
     */
    function makeStore(ctx) {
        var pending = {};
        var timer = null;

        function body() {
            var items = [];
            for (var blockKey in pending) {
                if (!Object.prototype.hasOwnProperty.call(pending, blockKey)) { continue; }
                for (var partKey in pending[blockKey]) {
                    if (!Object.prototype.hasOwnProperty.call(pending[blockKey], partKey)) { continue; }
                    if (items.length >= MAX_CHANGES_PER_REQUEST) { break; }
                    items.push({
                        b: blockKey,
                        p: partKey,
                        s: pending[blockKey][partKey],
                        h: currentHash(ctx, blockKey, partKey)
                    });
                }
            }
            pending = {};
            if (!items.length) { return null; }
            return 'csrf_token=' + encodeURIComponent(ctx.csrf)
                + '&a=' + encodeURIComponent(ctx.article)
                + '&items=' + encodeURIComponent(JSON.stringify(items));
        }

        function flush() {
            timer = null;
            if (ctx.readOnly || !ctx.endpoint) { pending = {}; return; }
            var payload = body();
            if (!payload) { return; }
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ctx.endpoint, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.withCredentials = true;
            xhr.send(payload);
        }

        return {
            /* Recorded in memory even when read-only, so anything reading back
             * from the store behaves identically in a preview. */
            set: function (blockKey, partKey, state) {
                state = Math.max(0, Math.min(STATE_MAX, state | 0));
                if (!pending[blockKey]) { pending[blockKey] = {}; }
                pending[blockKey][partKey] = state;
                if (ctx.readOnly || !ctx.endpoint) { return; }
                if (timer) { window.clearTimeout(timer); }
                timer = window.setTimeout(flush, SAVE_DELAY_MS);
            },
            flushNow: function () {
                if (timer) { window.clearTimeout(timer); timer = null; }
                if (ctx.readOnly || !ctx.endpoint) { pending = {}; return; }
                var payload = body();
                if (!payload) { return; }
                /* pagehide: a normal XHR is routinely cancelled here, sendBeacon
                 * is not. Same URL, same body, same cookie. */
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(ctx.endpoint, new Blob([payload], { type: 'application/x-www-form-urlencoded' }));
                }
            }
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Context
    // ─────────────────────────────────────────────────────────────────────────

    /*
     * TWO MAPS, AND THE PAIR IS WHAT DETECTS A STALE TICK.
     *
     *   data-ikb-progress  {"<block>":{"<part>":{"s":<int>,"h":"<16 hex>"}}}
     *                      what THIS reader stored, straight out of
     *                      kbProgressLoad() via InteractiveBlocks::progressAttribute().
     *   data-ikb-hashes    {"<block>":{"<part>":"<16 hex>"}}
     *                      what the article says RIGHT NOW, from
     *                      InteractiveBlocks::hashesAttribute().
     *
     * A tick is stale when both hashes exist and differ: the reader ticked a
     * step whose words have since changed. Neither silently keeping the tick
     * against different words nor silently dropping someone's progress is
     * acceptable; saying so is.
     *
     * The comparison is on the client because both values are already in the
     * page and neither is a secret. It could not be done here anyway - the
     * hash is sha1 and crypto.subtle requires a secure context, which this app
     * over plain HTTP is not.
     *
     * A bare integer is accepted in place of the object, so a caller with no
     * hash information can emit the simpler shape.
     */
    function parseMap(raw) {
        if (!raw) { return {}; }
        try {
            var parsed = JSON.parse(raw);
            return (parsed && typeof parsed === 'object') ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function currentHash(ctx, blockKey, partKey) {
        if (partKey === POSITION_KEY) { return ''; }
        var block = ctx.hashes[blockKey];
        var hash = block ? block[partKey] : '';
        return (typeof hash === 'string') ? hash : '';
    }

    function readState(ctx, blockKey, partKey) {
        var block = ctx.progress[blockKey];
        if (!block) { return null; }
        var value = block[partKey];
        if (value === undefined || value === null) { return null; }
        if (typeof value === 'number') { return { state: value | 0, stale: false }; }
        if (typeof value !== 'object') { return null; }

        var stored = (typeof value.h === 'string') ? value.h : '';
        var now = currentHash(ctx, blockKey, partKey);

        return {
            state: value.s | 0,
            stale: (stored !== '' && now !== '' && stored !== now)
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BINDER: sequence - checklist / steps / tabs / accordion
    //
    // One shape, four displays. This is the whole reason three of Blake's four
    // block types collapsed into one primitive: an agent turns a runbook from
    // an accordion into a guided wizard by changing one attribute, and no
    // stored content, no key and no saved tick moves.
    // ─────────────────────────────────────────────────────────────────────────

    function bindSequence(block, ctx) {
        var mode = block.getAttribute('data-ikb-mode') || 'checklist';
        var blockKey = block.getAttribute('data-ikb-key') || '';
        var parts = childrenWith(block, 'data-ikb-part', 'ikb-part');
        if (!parts.length) { return; }

        block.classList.add('ikb-js', 'ikb-seq', 'ikb-mode-' + mode);

        if (mode === 'steps') { return bindSteps(block, ctx, blockKey, parts); }
        if (mode === 'tabs') { return bindTabs(block, ctx, blockKey, parts); }
        if (mode === 'accordion') { return bindAccordion(block, ctx, blockKey, parts); }
        return bindChecklist(block, ctx, blockKey, parts);
    }

    function bindChecklist(block, ctx, blockKey, parts) {
        var bar = el('div', 'ikb-progress');
        var track = el('div', 'ikb-progress-track');
        var fill = el('div', 'ikb-progress-fill');
        var count = el('span', 'ikb-progress-text');
        track.appendChild(fill);
        bar.appendChild(track);
        bar.appendChild(count);
        block.insertBefore(bar, parts[0]);

        var boxes = [];

        function redraw() {
            var done = 0;
            for (var i = 0; i < boxes.length; i++) { if (boxes[i].checked) { done++; } }
            var pct = boxes.length ? Math.round((done / boxes.length) * 100) : 0;
            /* A CSSOM property write, not an inline style attribute: this is the
             * one varying dimension on the page and it is why the portal's
             * nonce-free CSP does not block it. */
            fill.style.width = pct + '%';
            count.textContent = done + ' of ' + boxes.length + ' done';
            block.classList.toggle('ikb-complete', boxes.length > 0 && done === boxes.length);
        }

        for (var i = 0; i < parts.length; i++) {
            (function (part) {
                var partKey = part.getAttribute('data-ikb-part') || '';
                var label = firstIn(part, '.ikb-label', block);
                if (!label) { return; }

                var wrap = el('label', 'ikb-tick');
                var box = el('input', 'ikb-tick-box');
                box.type = 'checkbox';
                var text = el('span', 'ikb-tick-text');
                while (label.firstChild) { text.appendChild(label.firstChild); }
                wrap.appendChild(box);
                wrap.appendChild(text);
                label.appendChild(wrap);

                var saved = readState(ctx, blockKey, partKey);
                if (saved && saved.state > 0) {
                    box.checked = true;
                    part.classList.add('ikb-done');
                    if (saved.stale) {
                        part.classList.add('ikb-stale');
                        var note = el('p', 'ikb-stale-note', 'This step changed since you ticked it — re-read it and tick it again.');
                        label.parentNode.insertBefore(note, label.nextSibling);
                    }
                }

                box.addEventListener('change', function () {
                    part.classList.toggle('ikb-done', box.checked);
                    if (box.checked) {
                        part.classList.remove('ikb-stale');
                        var stale = part.querySelector('.ikb-stale-note');
                        if (stale) { stale.parentNode.removeChild(stale); }
                    }
                    redraw();
                    ctx.store.set(blockKey, partKey, box.checked ? 1 : 0);
                });

                boxes.push(box);
            }(parts[i]));
        }

        redraw();
    }

    function bindSteps(block, ctx, blockKey, parts) {
        var saved = readState(ctx, blockKey, POSITION_KEY);
        var at = saved ? Math.max(0, Math.min(parts.length - 1, saved.state)) : 0;

        var nav = el('div', 'ikb-nav');
        var back = button('ikb-btn', 'Back');
        var next = button('ikb-btn ikb-btn-primary', 'Next');
        var status = el('span', 'ikb-status');
        status.setAttribute('aria-live', 'polite');
        nav.appendChild(back);
        nav.appendChild(status);
        nav.appendChild(next);
        block.appendChild(nav);

        function show(index, save) {
            at = Math.max(0, Math.min(parts.length - 1, index));
            for (var i = 0; i < parts.length; i++) {
                parts[i].hidden = (i !== at);
            }
            status.textContent = 'Step ' + (at + 1) + ' of ' + parts.length;
            back.disabled = (at === 0);
            next.disabled = (at === parts.length - 1);
            /* A nested block inside a step is invisible until the step is shown,
             * so it is mounted the first time it is revealed rather than at boot. */
            ctx.mountWithin(parts[at]);
            if (save) { ctx.store.set(blockKey, POSITION_KEY, at); }
        }

        back.addEventListener('click', function () { show(at - 1, true); });
        next.addEventListener('click', function () { show(at + 1, true); });

        /* Printing must not print "Step 2 of 6" and one step. */
        revealers.push({
            reveal: function () {
                for (var i = 0; i < parts.length; i++) { parts[i].hidden = false; }
            },
            restore: function () { show(at, false); }
        });

        show(at, false);
    }

    function bindTabs(block, ctx, blockKey, parts) {
        var strip = el('div', 'ikb-tabs');
        strip.setAttribute('role', 'tablist');
        block.insertBefore(strip, parts[0]);

        var tabs = [];

        function select(index) {
            for (var i = 0; i < parts.length; i++) {
                var on = (i === index);
                parts[i].hidden = !on;
                tabs[i].classList.toggle('ikb-tab-active', on);
                tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
                tabs[i].tabIndex = on ? 0 : -1;
            }
            ctx.mountWithin(parts[index]);
        }

        for (var i = 0; i < parts.length; i++) {
            (function (part, index) {
                var panelId = nextId('panel');
                var tabId = nextId('tab');
                var tab = button('ikb-tab', labelText(part, block));
                tab.setAttribute('role', 'tab');
                tab.id = tabId;
                tab.setAttribute('aria-controls', panelId);
                part.id = panelId;
                part.setAttribute('role', 'tabpanel');
                part.setAttribute('aria-labelledby', tabId);
                tab.addEventListener('click', function () { select(index); });
                tab.addEventListener('keydown', function (ev) {
                    if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
                        ev.preventDefault();
                        var move = (ev.key === 'ArrowRight' ? 1 : -1);
                        var target = (index + move + parts.length) % parts.length;
                        select(target);
                        tabs[target].focus();
                    }
                });
                strip.appendChild(tab);
                tabs.push(tab);

                /* The caption is now in the strip; showing it twice is noise. */
                var label = firstIn(part, '.ikb-label', block);
                if (label) { label.classList.add('ikb-label-hidden'); }
            }(parts[i], i));
        }

        revealers.push({
            reveal: function () {
                for (var j = 0; j < parts.length; j++) { parts[j].hidden = false; }
            },
            restore: function () {
                for (var j = 0; j < parts.length; j++) {
                    parts[j].hidden = !tabs[j].classList.contains('ikb-tab-active');
                }
            }
        });

        select(0);
    }

    function bindAccordion(block, ctx, blockKey, parts) {
        for (var i = 0; i < parts.length; i++) {
            (function (part) {
                var label = firstIn(part, '.ikb-label', block);
                var body = firstIn(part, '.ikb-body', block);
                if (!label || !body) { return; }

                var panelId = nextId('acc');
                body.id = panelId;
                body.hidden = true;

                var toggle = button('ikb-acc-toggle', null);
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('aria-controls', panelId);
                var caret = el('span', 'ikb-acc-caret');
                caret.setAttribute('aria-hidden', 'true');
                var text = el('span', 'ikb-acc-text');
                while (label.firstChild) { text.appendChild(label.firstChild); }
                toggle.appendChild(caret);
                toggle.appendChild(text);
                label.appendChild(toggle);

                toggle.addEventListener('click', function () {
                    var open = body.hidden;
                    body.hidden = !open;
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    part.classList.toggle('ikb-open', open);
                    if (open) { ctx.mountWithin(body); }
                });

                revealers.push({
                    reveal: function () { body.hidden = false; },
                    restore: function () { body.hidden = (toggle.getAttribute('aria-expanded') !== 'true'); }
                });
            }(parts[i]));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BINDER: tree - a real branching troubleshooter
    //
    // The nodes are a FLAT list, not a nesting, so two branches can converge on
    // one answer. With JavaScript off every node is visible in document order
    // and every choice is an ordinary anchor, so the whole tree still reads and
    // still lands in the FULLTEXT index - including the answers a reader would
    // only reach by choosing "No". That is the honest weakness of this block:
    // the no-JS form is readable but it is not good, and it is what the Android
    // app gets (its WebView has JavaScript disabled).
    // ─────────────────────────────────────────────────────────────────────────

    function bindTree(block, ctx) {
        var nodes = childrenWith(block, 'data-ikb-node', 'ikb-node');
        if (!nodes.length) { return; }

        block.classList.add('ikb-js', 'ikb-tree-js');

        var byKey = {};
        for (var i = 0; i < nodes.length; i++) {
            byKey[nodes[i].getAttribute('data-ikb-node')] = nodes[i];
            /* Focus lands here on every branch, so each node has to be able to
             * take it without becoming a tab stop of its own. */
            nodes[i].tabIndex = -1;
        }

        var start = block.getAttribute('data-ikb-start');
        if (!byKey[start]) { start = nodes[0].getAttribute('data-ikb-node'); }

        /* The live region is what stops this being a silent DOM mutation for a
         * screen-reader user. Without it, and without the focus move in show(),
         * enhancing the tree makes it LESS accessible than the plain markup -
         * the exact inverse of what progressive enhancement is for. */
        block.setAttribute('aria-live', 'polite');

        var controls = el('div', 'ikb-tree-controls');
        var backBtn = button('ikb-btn', 'Back');
        var restartBtn = button('ikb-btn', 'Start over');
        controls.appendChild(backBtn);
        controls.appendChild(restartBtn);
        block.appendChild(controls);

        var trail = [];

        function show(key, focus) {
            if (!byKey[key]) { return; }
            for (var j = 0; j < nodes.length; j++) {
                nodes[j].hidden = (nodes[j] !== byKey[key]);
            }
            backBtn.disabled = (trail.length < 2);
            restartBtn.disabled = (trail.length < 2);
            ctx.mountWithin(byKey[key]);
            if (focus) { byKey[key].focus(); }
        }

        function go(key, focus) {
            trail.push(key);
            show(key, focus);
        }

        block.addEventListener('click', function (ev) {
            var choice = ev.target.closest ? ev.target.closest('a[data-ikb-go]') : null;
            if (!choice || choice.closest('[data-ikb]') !== block) { return; }
            ev.preventDefault();
            go(choice.getAttribute('data-ikb-go'), true);
        });

        backBtn.addEventListener('click', function () {
            if (trail.length < 2) { return; }
            trail.pop();
            show(trail[trail.length - 1], true);
        });

        restartBtn.addEventListener('click', function () {
            trail = [start];
            show(start, true);
        });

        revealers.push({
            reveal: function () {
                for (var j = 0; j < nodes.length; j++) { nodes[j].hidden = false; }
            },
            restore: function () { show(trail[trail.length - 1], false); }
        });

        go(start, false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BINDER: copy - a marker on a <pre>, not a block
    // ─────────────────────────────────────────────────────────────────────────

    function bindCopy(pre, ctx) {
        pre.classList.add('ikb-js');

        var wrap = el('div', 'ikb-copy-wrap');
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);

        var btn = button('ikb-copy-btn', 'Copy');
        wrap.insertBefore(btn, pre);

        function source() {
            var code = pre.querySelector('code');
            return (code || pre).textContent;
        }

        function say(message, ok) {
            btn.textContent = message;
            btn.classList.toggle('ikb-copy-ok', !!ok);
            window.setTimeout(function () {
                btn.textContent = 'Copy';
                btn.classList.remove('ikb-copy-ok');
            }, 1600);
        }

        btn.addEventListener('click', function () {
            var text = source();

            /* navigator.clipboard requires a secure context. This app is reached
             * over plain HTTP on the LAN, where it is undefined - so the
             * execCommand path is the one that actually runs here, and the
             * "Press Ctrl+C" message is the honest last resort rather than a
             * button that silently does nothing. */
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    say('Copied', true);
                }, function () {
                    say('Press Ctrl+C', false);
                });
                return;
            }

            var scratch = document.createElement('textarea');
            scratch.value = text;
            scratch.setAttribute('readonly', 'readonly');
            scratch.className = 'ikb-copy-scratch';
            document.body.appendChild(scratch);
            scratch.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(scratch);
            say(ok ? 'Copied' : 'Press Ctrl+C', ok);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Print: every binder un-hides itself, then puts itself back.
    //
    // A runbook that prints as "Step 2 of 6" and one visible step is a broken
    // runbook, and a printed decision tree that only shows the branch someone
    // happened to walk is worse than the un-enhanced markup would have been.
    // css/itflow_kb.css hides the injected controls under @media print.
    // ─────────────────────────────────────────────────────────────────────────

    function beforePrint() {
        for (var i = 0; i < revealers.length; i++) {
            try { revealers[i].reveal(); } catch (e) { /* one block must not stop a print */ }
        }
    }

    function afterPrint() {
        for (var i = 0; i < revealers.length; i++) {
            try { revealers[i].restore(); } catch (e) { /* nor a restore */ }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────────────

    register('sequence', bindSequence);
    register('tree', bindTree);
    register('copy', bindCopy);
    /* 'embed' is deliberately absent - see the extension seam note at the top. */

    /* One root, one context. A function rather than a loop body because `var` is
     * function-scoped: a closure built inside the loop would capture the LAST
     * root's context, and a page with two roots would then save one article's
     * ticks against the other's id. */
    function bootRoot(root) {
        if (root.ikbBooted) { return; }
        root.ikbBooted = true;

        var ctx = {
            root: root,
            article: root.getAttribute('data-ikb-article') || '0',
            readOnly: root.getAttribute('data-ikb-readonly') === '1',
            endpoint: root.getAttribute('data-ikb-endpoint') || '',
            csrf: root.getAttribute('data-ikb-csrf') || '',
            progress: parseMap(root.getAttribute('data-ikb-progress')),
            hashes: parseMap(root.getAttribute('data-ikb-hashes'))
        };
        ctx.store = makeStore(ctx);
        ctx.mountWithin = function (scope) { mountWithin(scope, ctx); };

        /* The markup can be NEWER than this file - a cached copy of
         * kb_interactive.js against an article saved by a later vocabulary. Say
         * so once rather than silently leaving the new block type unbound. */
        var declared = parseInt(root.getAttribute('data-ikb-version') || '1', 10);
        if (declared > VOCAB_VERSION && window.console && window.console.warn) {
            window.console.warn('kb_interactive: article uses vocabulary v' + declared
                + ' but this file understands v' + VOCAB_VERSION + '; some blocks will render as plain HTML.');
        }

        root.classList.add('ikb-root-js');
        mountWithin(root, ctx);

        window.addEventListener('pagehide', function () { ctx.store.flushNow(); });
    }

    function boot() {
        var roots = document.querySelectorAll('[data-ikb-root]');
        if (!roots.length) { return; }   // inert on every other page in the app

        for (var i = 0; i < roots.length; i++) {
            bootRoot(roots[i]);
        }

        /* beforeprint/afterprint is the modern pair; the print media query is
         * the fallback for engines that only ever implemented that. Both are
         * idempotent, so a browser firing both does no harm. Wired once per
         * page, not once per boot() - boot() runs again whenever an AJAX modal
         * injects a new root. */
        if (printWired) { return; }
        printWired = true;

        if (window.matchMedia) {
            var query = window.matchMedia('print');
            if (query.addEventListener) {
                query.addEventListener('change', function (ev) {
                    if (ev.matches) { beforePrint(); } else { afterPrint(); }
                });
            }
        }
        window.addEventListener('beforeprint', beforePrint);
        window.addEventListener('afterprint', afterPrint);
    }

    window.ITFlowKB = {
        register: register,
        mountWithin: mountWithin,
        boot: boot,
        positionKey: POSITION_KEY,
        version: VOCAB_VERSION
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();   // loaded with defer, or injected into an already-parsed modal
    }
}());
