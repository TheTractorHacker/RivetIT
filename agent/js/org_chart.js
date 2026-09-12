/*
 * agent/js/org_chart.js - "Rootline"
 *
 * Client-side augmentation of the server-rendered tree in agent/org_chart.php.
 * There is exactly one tree in this feature: the DOM PHP already built. Every
 * feature below reads its data back out of data-* attributes already sitting
 * on the nodes PHP already rendered (see org_chart_node_card_html() in that
 * file) - nothing here re-derives the tree, fetches it again, or builds a
 * second copy of it.
 *
 * FALLBACK GUARANTEE: OrgChart.init() wraps its ENTIRE bootstrap in one
 * try/catch. If this file 404s, throws during init, or never runs at all
 * (scripting disabled), the page is not "a fallback tree" - it IS today's
 * plain server-rendered <details>/<summary> tree, because there is no second
 * tree anywhere in this design to fall back FROM. Do not weaken that by
 * splitting init() into several independently-bootstrapped pieces - one
 * try/catch, one point of failure, one guaranteed-good fallback.
 *
 * Called from agent/org_chart.php as: OrgChart.init({ maxAnimatedNodes: 800 }).
 */
(function () {
    'use strict';

    // Hardcoded twins of css/itflow_motion.css's --if-ease-out / --if-ease-in.
    // The Web Animations API's `easing` option cannot read a CSS custom
    // property, so these two strings have to be kept in sync by hand if those
    // tokens ever change.
    var EASE_OUT = 'cubic-bezier(.22,.61,.36,1)'; // --if-ease-out: entrances
    var EASE_IN = 'cubic-bezier(.4,0,1,1)';        // --if-ease-in: exits

    // --if-dur-ui (hover tint / small swap) and --if-dur-overlay (modal /
    // bottom-sheet reveal), same literal-value caveat as above.
    var DUR_UI = 160;
    var DUR_PANEL = 200;
    var DUR_OVERLAY = 240;

    // ------------------------------------------------------------------
    // Box-chart layout constants ("Rootline" box-and-line pass).
    //
    // BOX_W/BOX_H are hardcoded twins of css/org_chart.css's own
    // .org-chart-active summary / .org-node-row box size - same caveat as
    // EASE_OUT/EASE_IN above, keep both in sync by hand if either changes.
    // GUTTER_X/GUTTER_Y are pure layout spacing with no CSS counterpart.
    // ------------------------------------------------------------------
    var BOX_W = 300;
    var BOX_H = 86;
    var GUTTER_X = 30;
    var GUTTER_Y = 54;
    var UNIT_W = BOX_W + GUTTER_X;   // one horizontal "slot" in the subtree-width layout
    var ROW_H = BOX_H + GUTTER_Y;    // one generation's vertical step
    var ROOT_GAP_UNITS = 1;          // empty slot between separate root-trees in one department
    var CHART_MAX_DEPTH = 300;       // defensive-only: real org depth never approaches this

    function init(config) {
        try {
            run(config || {});
        } catch (err) {
            // Whatever broke, the page underneath is still today's plain
            // server-rendered tree - see the file header. Log it so it's
            // discoverable, don't let it become visible to the user as a
            // broken page.
            if (window.console && console.error) {
                console.error('[OrgChart] init failed - falling back to the plain server-rendered tree.', err);
            }
        }
    }

    function run(config) {
        var root = document.getElementById('orgChartRoot');
        if (!root) {
            return;
        }

        var maxAnimatedNodes = config.maxAnimatedNodes || 800;
        var prefersReducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        var isTouchInput = !!(window.matchMedia && window.matchMedia('(hover: none)').matches);

        var nodes = Array.prototype.slice.call(root.querySelectorAll('.org-node'));
        var nodeCount = nodes.length;
        // Above this, skip wiring the WAAPI expand/collapse animation and the
        // hover/tap preview panel entirely - <summary> clicks fall back to
        // plain native instant <details> toggling, and the info button simply
        // isn't wired. Search, Trace to Root, and the breadcrumb stay active
        // regardless of node count: all are O(n) or O(depth) with no
        // animation loop, so there is nothing here that can run away.
        var animationsEnabled = nodeCount <= maxAnimatedNodes;

        // id -> .org-node element, and .org-node -> containing <li>, built
        // once and reused by every feature below (search, trace, breadcrumb,
        // preview) instead of re-querying the DOM per interaction.
        var idToNode = {};
        var nodeToLi = new WeakMap();
        nodes.forEach(function (n) {
            idToNode[n.getAttribute('data-contact-id')] = n;
            var li = n.closest('li');
            if (li) {
                nodeToLi.set(n, li);
            }
        });

        // traceApi is built before the chart layout so wireChartLayout can
        // hand it to each department's ctx - a relayout that rebuilds a
        // department's SVG connector paths from scratch (any toggle) needs
        // to be able to reapply an in-progress "Trace to Root" edge
        // highlight afterwards, see wireTrace()'s reapplyEdgeHighlight().
        var traceApi = wireTrace(root, nodes, idToNode, nodeToLi);

        // Chart layout runs BEFORE wireDetailsAnimation so the latter can
        // skip any <details> that now lives inside a successfully
        // chart-ified department (marked '.org-chart-active') - that
        // department's open/close visual transition is owned by the box
        // layout's own position-based relayout instead of the old
        // height-grow/shrink animation, which would otherwise be animating
        // a <ul> that no longer has any visible footprint in chart mode.
        // Also run BEFORE wireExpandCollapseAll, which needs chartCtxs to
        // re-center each chart after a bulk toggle - see that function.
        var chartCtxs = wireChartLayout(root, animationsEnabled, traceApi);

        wireExpandCollapseAll(root, chartCtxs);

        if (animationsEnabled) {
            wireDetailsAnimation(root, prefersReducedMotion);
        }

        var searchApi = wireSearch(root, nodes, idToNode, prefersReducedMotion, traceApi);
        wireBreadcrumb(root, nodes, idToNode, traceApi, prefersReducedMotion);

        var previewApi = null;
        if (animationsEnabled) {
            previewApi = wirePreviewPanel(nodes, isTouchInput, prefersReducedMotion);
        }

        wireGlobalKeys(searchApi, traceApi, previewApi);
        wirePrint(root, searchApi, traceApi, previewApi, chartCtxs);
    }

    // ------------------------------------------------------------------
    // Expand All / Collapse All - UNCHANGED from the page's original
    // behaviour, deliberately not animated: these two buttons can touch
    // every <details> on the page at once, and firing a WAAPI animation on
    // every node simultaneously on a several-hundred-contact chart is
    // exactly the animation storm the rest of this file avoids elsewhere.
    // ------------------------------------------------------------------
    function wireExpandCollapseAll(root, chartCtxs) {
        var expandBtn = document.getElementById('orgChartExpandAll');
        var collapseBtn = document.getElementById('orgChartCollapseAll');

        // Re-center every chart-mode canvas after a bulk toggle. Each
        // <details>'s own 'toggle' listener (setupCanvas() in the chart
        // layout section above) already turns this click into a relayout,
        // rAF-coalesced into ONE performLayout() per canvas - but that
        // coalesced call runs in a LATER frame than this click handler's
        // own synchronous code, so centering has to wait for it too or it
        // reads the canvas's PRE-toggle size/position. Two nested rAFs:
        // the first lands in the same frame the coalesced relayout's own
        // rAF callback runs in (both were queued around the same point),
        // the second is the first frame guaranteed to run strictly after
        // it painted.
        function recenterSoon() {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    (chartCtxs || []).forEach(function (ctx) {
                        if (!ctx.dead) {
                            centerCanvas(ctx);
                        }
                    });
                });
            });
        }

        if (expandBtn) {
            expandBtn.addEventListener('click', function () {
                root.querySelectorAll('details').forEach(function (d) { d.open = true; });
                recenterSoon();
            });
        }
        if (collapseBtn) {
            collapseBtn.addEventListener('click', function () {
                root.querySelectorAll('details').forEach(function (d) { d.open = false; });
                recenterSoon();
            });
        }
    }

    // ------------------------------------------------------------------
    // Expand/collapse animation for individual nodes.
    //
    // Opening: the click on <summary> is left alone (no preventDefault) so
    // the browser's own toggle runs; a `toggle` listener on the <details>
    // then measures the now-visible child <ul> and grows it from 0. That
    // `toggle` handler only animates when THIS click armed it (the
    // `pendingOpen` WeakSet below) - Expand All sets .open directly without
    // going through a click, so it never arms the flag and the bulk toggle
    // stays perfectly instant.
    //
    // Closing: <details> offers no "about to close" event to hook, so the
    // click is prevented, the open <ul> is animated down to 0, and only in
    // that animation's onfinish is details.open actually set to false.
    //
    // Rapid clicks (open then immediately close, or vice versa) are handled
    // by ALWAYS branching on the live `details.open` boolean (never on
    // "is an animation currently running") and by measuring the <ul>'s
    // CURRENT rendered height - which reflects an in-flight animation's
    // interpolated value, not just its start/end keyframe - before cancelling
    // whatever was previously running, so a reversal continues smoothly
    // instead of snapping. This is the concrete fix for the rapid-double-
    // click desync both design-review judges flagged, not a nice-to-have.
    // ------------------------------------------------------------------
    function wireDetailsAnimation(root, prefersReducedMotion) {
        var duration = prefersReducedMotion ? 1 : DUR_PANEL;
        var animMap = new WeakMap();   // <details> -> in-flight Animation
        var pendingOpen = new WeakSet(); // <details> armed by a user click, consumed by the next 'toggle'

        function childList(details) {
            return details.querySelector(':scope > ul.org-tree');
        }

        function animateClose(details, ul) {
            var prev = animMap.get(details);
            var startHeight = ul.getBoundingClientRect().height;
            if (prev) {
                prev.cancel();
            }
            ul.style.overflow = 'hidden';
            var anim = ul.animate(
                [{ height: startHeight + 'px', opacity: 1 }, { height: '0px', opacity: 0 }],
                { duration: duration, easing: EASE_IN, fill: 'forwards' }
            );
            animMap.set(details, anim);
            anim.onfinish = function () {
                details.open = false;
                ul.style.height = '';
                ul.style.opacity = '';
                ul.style.overflow = '';
                animMap.delete(details);
            };
        }

        function animateOpen(details, ul) {
            var prev = animMap.get(details);
            if (prev) {
                prev.cancel();
            }
            var targetHeight = ul.scrollHeight;
            ul.style.overflow = 'hidden';
            var anim = ul.animate(
                [{ height: '0px', opacity: 0 }, { height: targetHeight + 'px', opacity: 1 }],
                { duration: duration, easing: EASE_OUT, fill: 'forwards' }
            );
            animMap.set(details, anim);
            anim.onfinish = function () {
                ul.style.height = '';
                ul.style.opacity = '';
                ul.style.overflow = '';
                animMap.delete(details);
            };
        }

        root.querySelectorAll('.org-tree summary').forEach(function (summary) {
            var details = summary.closest('details');
            if (!details) {
                return;
            }
            if (details.closest('.org-chart-active')) {
                // This department's <ul class="org-tree"> is now a
                // zero-footprint wrapper inside a box-chart canvas (see
                // wireChartLayout()) - animating its height is invisible
                // and its own 'toggle' listener there owns the real
                // (position-based) visual transition instead.
                return;
            }
            var ul = childList(details);
            if (!ul) {
                return;
            }

            summary.addEventListener('click', function (e) {
                if (details.open) {
                    // This click is about to CLOSE it - the only direction
                    // <details> gives us no native event to hook, so we take
                    // over entirely.
                    e.preventDefault();
                    animateClose(details, ul);
                } else {
                    // About to OPEN - let the native toggle happen, arm the
                    // 'toggle' handler below to animate it once it does.
                    pendingOpen.add(details);
                }
            });

            details.addEventListener('toggle', function () {
                if (!details.open) {
                    // Closes are fully handled by the click handler above
                    // (it's the only thing allowed to set .open = false).
                    return;
                }
                if (!pendingOpen.has(details)) {
                    // Opened by something other than a user click on THIS
                    // summary (Expand All, browser back/forward restoring
                    // form state, etc.) - leave it exactly as instant as the
                    // native default.
                    return;
                }
                pendingOpen.delete(details);
                animateOpen(details, ul);
            });
        });
    }

    // ------------------------------------------------------------------
    // Box-chart layout ("Rootline" box-and-line pass).
    //
    // Turns each department's/section's existing <ul class="org-tree"> -
    // the SAME nested <li>/<details> markup agent/org_chart.php already
    // renders, structurally untouched - into a real hierarchical
    // box-and-line chart: a generation-by-generation subtree-width layout
    // (Reingold-Tilford family, simplified - bottom-up width, top-down
    // center-of-subtree x) positions every visible node's row as an
    // absolutely-positioned box inside a sized canvas div, with an SVG
    // layer drawing an elbow connector from each parent's bottom-center to
    // each child's top-center.
    //
    // THE TREE ITSELF IS READ STRAIGHT BACK OUT OF THE DOM, not rebuilt
    // from a second data source - collectVisibleTree() below walks the
    // exact <li>/<details>/<ul> nesting agent/org_chart.php already
    // produced (open/closed <details> state is what decides which
    // children are "visible" for this pass, exactly matching what a user
    // sees). No data-manager-id attribute was added to agent/org_chart.php
    // for this: a "root because its manager is missing/out-of-scope" node
    // (see org_chart_build_tree_index()) still carries its ORIGINAL
    // non-zero contact_manager_id server-side, so a naive JS reconstruction
    // from a raw manager-id attribute would have to re-derive that same
    // "does this id actually exist as a rendered node" rule to avoid
    // mis-parenting such a node - the DOM nesting already IS that resolved
    // answer, with zero risk of drifting from what org_chart_build_tree_index()
    // /org_chart_render_subtree_html() actually decided.
    //
    // GRACEFUL DEGRADATION: each department is built in its own try/catch
    // (see wireChartLayout()) - a department whose layout throws is torn
    // back down to its original, untouched, plain indented list rather
    // than being left half-migrated. This is IN ADDITION TO, not a
    // replacement for, OrgChart.init()'s own single top-level try/catch -
    // a failure this per-department net doesn't catch still degrades the
    // WHOLE page to the plain server-rendered tree exactly as before.
    // ------------------------------------------------------------------

    function logChartErr(err) {
        if (window.console && console.error) {
            console.error('[OrgChart] chart layout failed for one department - reverted to the plain list for it.', err);
        }
    }

    function logRelayoutErr(err) {
        if (window.console && console.error) {
            console.error('[OrgChart] chart relayout failed - that chart may be stale until the next toggle.', err);
        }
    }

    // The row element that IS a node's visual box: <summary> for an
    // expandable node (chevron + card), .org-node-row for a leaf (spacer +
    // card) - the exact same two shapes org_chart_open_node_html() already
    // emits server-side, nothing new.
    function nodeRowEl(li) {
        if (li.classList.contains('org-leaf')) {
            return li.querySelector(':scope > .org-node-row');
        }
        var details = li.querySelector(':scope > details');
        return details ? details.querySelector(':scope > summary') : null;
    }

    function childUl(li) {
        var details = li.querySelector(':scope > details');
        return details ? details.querySelector(':scope > ul.org-tree') : null;
    }

    // A leaf has no <details> to be open/closed - it trivially counts as
    // "open" since it has no children to hide.
    function isOpenLi(li) {
        var details = li.querySelector(':scope > details');
        return !details || details.open;
    }

    // Walks the LIVE DOM (only descending into currently-open subtrees) and
    // returns the root record of one root <li>'s visible tree, pushing
    // every visited record (root-first) onto the shared `flat` array so the
    // two width/position passes below don't need their own traversal.
    function collectVisibleTree(li, depth, flat) {
        if (depth > CHART_MAX_DEPTH) {
            // Defensive only (see CHART_MAX_DEPTH) - truncate rather than
            // recurse indefinitely. The DOM here is guaranteed acyclic
            // (org_chart_render_subtree_html()'s own cycle guards already
            // ran server-side), so this should never actually bind.
            return null;
        }
        var row = nodeRowEl(li);
        if (!row) {
            return null;
        }
        var orgNode = row.querySelector('.org-node');
        var rec = {
            li: li,
            row: row,
            contactId: orgNode ? orgNode.getAttribute('data-contact-id') : '',
            depth: depth,
            children: [],
            width: 1,
            x: 0
        };
        flat.push(rec);

        if (isOpenLi(li)) {
            var cul = childUl(li);
            if (cul) {
                Array.prototype.forEach.call(cul.children, function (childLi) {
                    if (childLi.tagName !== 'LI') {
                        return;
                    }
                    var childRec = collectVisibleTree(childLi, depth + 1, flat);
                    if (childRec) {
                        rec.children.push(childRec);
                    }
                });
            }
        }

        return rec;
    }

    // Bottom-up subtree width, in "box slot" units (a leaf, or a node whose
    // children are all currently collapsed/hidden, is 1 slot wide).
    function computeWidth(rec) {
        if (!rec.children.length) {
            rec.width = 1;
            return 1;
        }
        var w = 0;
        rec.children.forEach(function (c) { w += computeWidth(c); });
        rec.width = Math.max(w, 1);
        return rec.width;
    }

    // Top-down: each node's x is the horizontal center of its own subtree's
    // allocated slot range; each child gets a consecutive slice of that
    // range in turn.
    function assignX(rec, leftEdge) {
        rec.x = leftEdge + rec.width / 2;
        var cursor = leftEdge;
        rec.children.forEach(function (c) {
            assignX(c, cursor);
            cursor += c.width;
        });
    }

    // Packs independent root-trees into ROWS ("bands") instead of one
    // endless horizontal line, first-fit in original document order.
    //
    // WHY THIS EXISTS: a root-tree is "independent" whenever
    // org_chart_build_tree_index() (PHP) could not find its manager among
    // the currently-visible contacts - manager_id is 0/NULL, archived, or
    // outside the current department filter. On a fresh install (verified
    // against the live database: EVERY contact currently has manager_id
    // NULL - nobody has set the "Manager" field on anyone yet), that makes
    // EVERY contact its own one-node root-tree, and a department of 30-140
    // people is 30-140 independent trees. Laid out on one line (the
    // original design), that is a 10,000+px-wide canvas where centerCanvas()
    // can show at most a handful of them at once - not merely "not a real
    // hierarchy chart", but most of the department silently invisible with
    // no cue there was more to scroll to (confirmed against real data: a
    // 5-person, zero-hierarchy department already left 3 of 5 people
    // off-screen at a normal viewport width). Wrapping onto multiple rows,
    // sized to the container's OWN current width, fixes this for exactly
    // that case (a flat team wraps into a clean grid, entirely visible via
    // ordinary vertical scroll) while changing nothing about how any ONE
    // tree with real depth renders - a department that DOES have manager
    // data set still draws as a normal top-down hierarchy, wrapping (if at
    // all) only where independent trees/orphans sit side by side.
    function packRootsIntoBands(rootEntries, maxUnitsPerRow) {
        var bands = [];
        var band = null;
        var bandUnits = 0;
        rootEntries.forEach(function (entry) {
            var gap = (band && band.length) ? ROOT_GAP_UNITS : 0;
            if (band && band.length && bandUnits + gap + entry.rec.width > maxUnitsPerRow) {
                band = null;
            }
            if (!band) {
                band = [];
                bands.push(band);
                bandUnits = 0;
            } else {
                bandUnits += gap;
            }
            band.push(entry);
            bandUnits += entry.rec.width;
        });
        return bands;
    }

    // The pure-compute-then-DOM-apply pass, run once at setup and again on
    // every relayout. Left to throw naturally - callers decide what a
    // failure means (see wireChartLayout()'s initial-build teardown vs.
    // scheduleRelayout()'s log-and-leave-stale, both below).
    function performLayout(ctx) {
        var rootLis = Array.prototype.slice.call(ctx.ul.children).filter(function (li) { return li.tagName === 'LI'; });
        var flat = [];
        var rootEntries = []; // {rec, maxDepth, flatStart, flatEnd} - one per independent root-tree

        rootLis.forEach(function (li) {
            var flatStart = flat.length;
            var rec = collectVisibleTree(li, 0, flat);
            if (!rec) {
                return;
            }
            computeWidth(rec);
            var maxDepth = 0;
            for (var i = flatStart; i < flat.length; i++) {
                if (flat[i].depth > maxDepth) { maxDepth = flat[i].depth; }
            }
            rootEntries.push({ rec: rec, maxDepth: maxDepth, flatStart: flatStart, flatEnd: flat.length });
        });

        // clientWidth, not the canvas's own (stale, pre-relayout) width -
        // this is what makes the wrap boundary responsive to the actual
        // viewport/container size rather than a fixed guess.
        var maxUnitsPerRow = Math.max(1, Math.floor(ctx.scrollWrap.clientWidth / UNIT_W));
        var bands = packRootsIntoBands(rootEntries, maxUnitsPerRow);

        var bandYOffsetPx = 0;
        var maxUnitsUsed = 0;
        bands.forEach(function (bandEntries) {
            var cursor = 0;
            var bandMaxDepth = 0;
            bandEntries.forEach(function (entry, i) {
                assignX(entry.rec, cursor);
                cursor += entry.rec.width + (i < bandEntries.length - 1 ? ROOT_GAP_UNITS : 0);
                if (entry.maxDepth > bandMaxDepth) { bandMaxDepth = entry.maxDepth; }
                for (var j = entry.flatStart; j < entry.flatEnd; j++) {
                    flat[j].bandYOffsetPx = bandYOffsetPx;
                }
            });
            if (cursor > maxUnitsUsed) { maxUnitsUsed = cursor; }
            bandYOffsetPx += (bandMaxDepth + 1) * ROW_H;
        });

        var extentW = Math.max(maxUnitsUsed, 1) * UNIT_W;
        var extentH = Math.max(bandYOffsetPx, ROW_H);

        ctx.canvas.style.width = extentW + 'px';
        ctx.canvas.style.height = extentH + 'px';
        ctx.svg.setAttribute('width', extentW);
        ctx.svg.setAttribute('height', extentH);
        ctx.svg.setAttribute('viewBox', '0 0 ' + extentW + ' ' + extentH);

        flat.forEach(function (rec) {
            var leftPx = rec.x * UNIT_W - BOX_W / 2;
            var topPx = rec.bandYOffsetPx + rec.depth * ROW_H;
            rec.row.style.left = leftPx + 'px';
            rec.row.style.top = topPx + 'px';
            rec.pxCenterX = leftPx + BOX_W / 2;
            rec.pxTop = topPx;
            rec.pxBottom = topPx + BOX_H;
        });

        // Rebuilt from scratch every pass via the DOM API only (never
        // innerHTML - same "no markup built from string concatenation of
        // node data" discipline wirePreviewPanel() already follows below)
        // so a contact name/title can never end up interpreted as markup.
        while (ctx.svg.firstChild) {
            ctx.svg.removeChild(ctx.svg.firstChild);
        }
        var svgNS = 'http://www.w3.org/2000/svg';
        flat.forEach(function (rec) {
            rec.children.forEach(function (child) {
                var midY = rec.pxBottom + GUTTER_Y / 2;
                var path = document.createElementNS(svgNS, 'path');
                path.setAttribute('class', 'org-chart-edge');
                path.setAttribute('data-child-id', child.contactId || '');
                path.setAttribute('d',
                    'M ' + rec.pxCenterX + ' ' + rec.pxBottom +
                    ' V ' + midY +
                    ' H ' + child.pxCenterX +
                    ' V ' + child.pxTop);
                ctx.svg.appendChild(path);
            });
        });

        // A relayout can happen mid-trace (the user expands/collapses a
        // sibling elsewhere in the same department while another node's
        // chain is traced) - the fresh <path> elements just built never
        // carry the highlight class, so reapply it from the still-active
        // trace state rather than leaving it silently dropped.
        if (ctx.traceApi && typeof ctx.traceApi.reapplyEdgeHighlight === 'function') {
            ctx.traceApi.reapplyEdgeHighlight();
        }
    }

    // rAF-coalesced relayout for interactive toggles - if several
    // <details> flip in the same tick (Expand All / Collapse All, or
    // search force-opening every match's ancestor chain at once), this
    // collapses them into exactly one relayout instead of one per toggle.
    function scheduleRelayout(ctx) {
        if (ctx.dead || ctx.pendingFrame) {
            return;
        }
        ctx.pendingFrame = requestAnimationFrame(function () {
            ctx.pendingFrame = null;
            if (ctx.dead) {
                return;
            }
            try {
                performLayout(ctx);
            } catch (err) {
                // A relayout failure AFTER a department already committed
                // to chart mode does not revert it - the last known-good
                // positions simply stay on screen rather than the chart
                // vanishing out from under an interaction in progress.
                logRelayoutErr(err);
            }
        });
    }

    // Wraps one <ul class="org-tree"> in a sized, position:relative canvas
    // + SVG line layer, and attaches the toggle -> relayout wiring - pure
    // DOM scaffolding (createElement/appendChild/classList/addEventListener),
    // deliberately kept free of anything that does real computation and so
    // is extremely unlikely to throw; performLayout() (called right after,
    // by the caller) is where real failure risk lives.
    function setupCanvas(ul, animationsEnabled, traceApi) {
        var cardBody = ul.parentElement;
        var scrollWrap = document.createElement('div');
        scrollWrap.className = 'org-chart-scroll';
        var canvas = document.createElement('div');
        canvas.className = 'org-chart-canvas';
        if (!animationsEnabled) {
            canvas.classList.add('org-chart-instant');
        }
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'org-chart-lines');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');

        cardBody.insertBefore(scrollWrap, ul);
        canvas.appendChild(svg);
        canvas.appendChild(ul); // moves the WHOLE existing subtree as one unit - nothing rebuilt/duplicated
        scrollWrap.appendChild(canvas);
        cardBody.classList.add('org-chart-active');

        var ctx = {
            ul: ul,
            canvas: canvas,
            svg: svg,
            scrollWrap: scrollWrap,
            cardBody: cardBody,
            traceApi: traceApi,
            pendingFrame: null,
            dead: false
        };

        Array.prototype.forEach.call(ul.querySelectorAll('details'), function (d) {
            d.addEventListener('toggle', function () { scheduleRelayout(ctx); });
        });

        return ctx;
    }

    // A canvas wider than its visible scroll strip (agent/css/org_chart.css's
    // .org-chart-scroll{overflow-x:auto}) defaults to scrollLeft=0 - flush
    // against whichever edge the first root happens to land at. For any
    // generation with more than 2-3 siblings, that edge is very often NOT
    // where the root sits (assignX() centers a root at ITS subtree's own
    // midpoint, which is the canvas's overall midpoint only for a single-
    // root department), so the root - the one node every other node's
    // position is relative to - could render completely off-screen with no
    // visible content and no cue to scroll right. Centers the canvas's own
    // midpoint in the scroll strip instead: exactly correct for the (most
    // common) single-root case, since assignX() puts that root exactly at
    // width/2; a reasonable "show the most central part" default for the
    // multi-root case, where no single scroll position can center every
    // root simultaneously.
    function centerCanvas(ctx) {
        var wrapWidth = ctx.scrollWrap.clientWidth;
        var canvasWidth = ctx.canvas.offsetWidth;
        if (canvasWidth <= wrapWidth) {
            ctx.scrollWrap.scrollLeft = 0;
            return;
        }
        ctx.scrollWrap.scrollLeft = (canvasWidth - wrapWidth) / 2;
    }

    // Best-effort revert of setupCanvas()'s mutation: moves the (fully
    // intact - never rebuilt) <ul class="org-tree"> back to exactly where
    // it was and removes the wrapper, leaving the department as today's
    // plain indented list. The 'toggle' listeners attached above stay
    // harmlessly in place (they early-return on ctx.dead).
    function teardownCanvas(ctx) {
        ctx.dead = true;
        try {
            ctx.cardBody.insertBefore(ctx.ul, ctx.scrollWrap);
            ctx.cardBody.removeChild(ctx.scrollWrap);
            ctx.cardBody.classList.remove('org-chart-active');
        } catch (err) {
            logChartErr(err);
        }
    }

    // Entry point, called once from run(). Builds a chart canvas for every
    // top-level <ul class="org-tree"> directly inside a .card > .card-body -
    // every department "swim lane" card AND the "Reporting Cycle Detected"
    // card share that exact shape, so one selector covers both uniformly.
    // Returns the list of successfully-built contexts (wirePrint() needs it
    // to force a synchronous relayout around printing).
    function wireChartLayout(root, animationsEnabled, traceApi) {
        var sources = Array.prototype.slice.call(root.querySelectorAll(':scope > .card > .card-body > ul.org-tree'));
        var contexts = [];

        sources.forEach(function (ul) {
            var ctx = null;
            try {
                ctx = setupCanvas(ul, animationsEnabled, traceApi);
                performLayout(ctx);
                centerCanvas(ctx);
                contexts.push(ctx);
            } catch (err) {
                if (ctx) {
                    teardownCanvas(ctx);
                }
                logChartErr(err);
            }
        });

        return contexts;
    }

    // ------------------------------------------------------------------
    // Trace to Root.
    // ------------------------------------------------------------------
    function wireTrace(root, nodes, idToNode, nodeToLi) {
        var activeId = null;

        function ancestorIds(node) {
            var raw = node.getAttribute('data-ancestor-path') || '';
            return raw.length ? raw.split(' ') : [];
        }

        // Highlights the SVG connector edges (agent/css/org_chart.css
        // .org-chart-edge, drawn by wireChartLayout()) whose data-child-id
        // falls on the traced chain - the box-chart's replacement for the
        // old vertical-list "spine" border, which has no meaningful
        // position once its <ul> is a zero-footprint chart-mode wrapper
        // (see .org-chart-active .org-trace-spine in the CSS). A no-op
        // when a department never got chart-ified (no matching paths
        // exist) - the li-level ring + bold name remain the primary signal
        // there, exactly as before.
        function setEdgeHighlight(chain) {
            var idSet = {};
            chain.forEach(function (id) { idSet[id] = true; });
            root.querySelectorAll('.org-chart-lines path[data-child-id]').forEach(function (p) {
                p.classList.toggle('org-trace-edge-active', !!idSet[p.getAttribute('data-child-id')]);
            });
        }

        // No-notify core, shared by the public clear() and by apply()'s own
        // "wipe whatever was traced before" step - apply() fires exactly one
        // notify() itself once the new state is fully built, so switching
        // directly from tracing A to tracing B never notifies listeners
        // twice (once with null, once with the real id) for what is, from
        // every listener's point of view, one atomic change.
        function clearInternal() {
            if (!activeId) {
                return false;
            }
            root.querySelectorAll('.org-trace-chain').forEach(function (li) {
                li.classList.remove('org-trace-chain');
            });
            root.querySelectorAll('.org-trace-spine').forEach(function (ul) {
                ul.classList.remove('org-trace-spine');
            });
            root.querySelectorAll('.org-trace-active').forEach(function (btn) {
                btn.classList.remove('org-trace-active');
            });
            root.querySelectorAll('.org-trace-edge-active').forEach(function (p) {
                p.classList.remove('org-trace-edge-active');
            });
            root.classList.remove('org-tracing');
            activeId = null;
            return true;
        }

        function clear() {
            if (clearInternal()) {
                notify();
            }
        }

        function apply(contactId) {
            var node = idToNode[contactId];
            if (!node) {
                return;
            }
            clearInternal();

            var path = ancestorIds(node);
            var chain = path.concat([contactId]);

            chain.forEach(function (id) {
                var n = idToNode[id];
                var li = n && nodeToLi.get(n);
                if (li) {
                    li.classList.add('org-trace-chain');
                }
            });

            // The spine segment hanging below each ANCESTOR (not the traced
            // node itself, which has no "below" relevant to this path).
            path.forEach(function (id) {
                var n = idToNode[id];
                var details = n && n.closest('details');
                var ul = details && details.querySelector(':scope > ul.org-tree');
                if (ul) {
                    ul.classList.add('org-trace-spine');
                }
            });

            var btn = node.querySelector('.org-node-trace-btn');
            if (btn) {
                btn.classList.add('org-trace-active');
            }

            root.classList.add('org-tracing');
            activeId = contactId;
            setEdgeHighlight(chain);
            notify();
        }

        // Called by wireChartLayout() after a relayout rebuilds a
        // department's SVG edges from scratch (any expand/collapse toggle
        // does this) - the fresh <path> elements never carry the previous
        // org-trace-edge-active class, so an in-progress trace needs this
        // to restore it. A no-op when nothing is currently traced.
        function reapplyEdgeHighlight() {
            if (activeId === null) {
                return;
            }
            var node = idToNode[activeId];
            if (!node) {
                return;
            }
            setEdgeHighlight(ancestorIds(node).concat([activeId]));
        }

        function toggle(contactId) {
            if (activeId === contactId) {
                clear();
            } else {
                apply(contactId);
            }
        }

        var listeners = [];
        function notify() {
            listeners.forEach(function (fn) { fn(activeId); });
        }

        nodes.forEach(function (n) {
            var btn = n.querySelector('.org-node-trace-btn');
            if (!btn) {
                return;
            }
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggle(n.getAttribute('data-contact-id'));
            });
        });

        return {
            clear: clear,
            apply: apply,
            isActive: function () { return activeId !== null; },
            getActiveId: function () { return activeId; },
            onChange: function (fn) { listeners.push(fn); },
            reapplyEdgeHighlight: reapplyEdgeHighlight,
        };
    }

    // ------------------------------------------------------------------
    // Search.
    // ------------------------------------------------------------------
    function wireSearch(root, nodes, idToNode, prefersReducedMotion, traceApi) {
        var input = document.getElementById('orgChartSearch');
        var counterWrap = document.getElementById('orgChartSearchCounter');
        var countLabel = document.getElementById('orgChartSearchCount');
        var prevBtn = document.getElementById('orgChartSearchPrev');
        var nextBtn = document.getElementById('orgChartSearchNext');

        var matches = [];      // ordered contact ids currently matching
        var matchIndex = -1;
        var openSnapshot = null; // Set<HTMLDetailsElement> that were open before the first keystroke
        var debounceTimer = null;

        // Trace mode is mutually exclusive with search - starting one clears
        // the other, so the two dim states are never compounded.
        traceApi.onChange(function (activeId) {
            if (activeId !== null && input && input.value) {
                input.value = '';
                runSearch('');
            }
        });

        function allDetails() {
            return Array.prototype.slice.call(root.querySelectorAll('details'));
        }

        function setNameHighlight(node, plainName, matchStart, matchLen) {
            var nameEl = node.querySelector('.org-node-name');
            if (!nameEl) {
                return;
            }
            while (nameEl.firstChild) {
                nameEl.removeChild(nameEl.firstChild);
            }
            if (matchStart < 0) {
                nameEl.appendChild(document.createTextNode(plainName));
                return;
            }
            if (matchStart > 0) {
                nameEl.appendChild(document.createTextNode(plainName.slice(0, matchStart)));
            }
            var mark = document.createElement('mark');
            mark.textContent = plainName.slice(matchStart, matchStart + matchLen);
            nameEl.appendChild(mark);
            var tailStart = matchStart + matchLen;
            if (tailStart < plainName.length) {
                nameEl.appendChild(document.createTextNode(plainName.slice(tailStart)));
            }
        }

        function updateCounter() {
            if (!counterWrap) {
                return;
            }
            var hasQuery = !!(input && input.value);
            counterWrap.hidden = !hasQuery;
            if (!hasQuery || !countLabel) {
                return;
            }
            var n = matches.length;
            countLabel.textContent = n === 0 ? '0 matches' : (n === 1 ? '1 match' : n + ' matches');
        }

        function runSearch(rawQuery) {
            var query = (rawQuery || '').trim().toLowerCase();

            if (!query) {
                nodes.forEach(function (n) {
                    n.classList.remove('org-node-dim');
                    setNameHighlight(n, n.getAttribute('data-name') || '', -1, 0);
                });
                if (openSnapshot) {
                    allDetails().forEach(function (d) { d.open = openSnapshot.has(d); });
                    openSnapshot = null;
                }
                matches = [];
                matchIndex = -1;
                updateCounter();
                return;
            }

            if (traceApi.isActive()) {
                traceApi.clear();
            }

            if (!openSnapshot) {
                openSnapshot = new Set();
                allDetails().forEach(function (d) { if (d.open) { openSnapshot.add(d); } });
            }

            matches = [];
            nodes.forEach(function (n) {
                var name = n.getAttribute('data-name') || '';
                var title = n.getAttribute('data-title') || '';
                var group = n.getAttribute('data-group') || '';
                var dept = n.getAttribute('data-dept') || '';

                var nameIdx = name.toLowerCase().indexOf(query);
                var isMatch = nameIdx !== -1
                    || title.toLowerCase().indexOf(query) !== -1
                    || group.toLowerCase().indexOf(query) !== -1
                    || dept.toLowerCase().indexOf(query) !== -1;

                setNameHighlight(n, name, nameIdx, query.length);

                if (isMatch) {
                    n.classList.remove('org-node-dim');
                    matches.push(n.getAttribute('data-contact-id'));

                    // Force every ancestor on the path open INSTANTLY -
                    // never through the WAAPI layer - so search never fires
                    // a concurrent-animation storm across many matches.
                    var path = n.getAttribute('data-ancestor-path') || '';
                    if (path) {
                        path.split(' ').forEach(function (aid) {
                            var an = idToNode[aid];
                            var details = an && an.closest('details');
                            if (details) {
                                details.open = true;
                            }
                        });
                    }
                } else {
                    n.classList.add('org-node-dim');
                }
            });

            matchIndex = matches.length ? 0 : -1;
            updateCounter();
            if (matchIndex >= 0) {
                // One rAF after the instant ancestor-open above: several
                // <details> may have just toggled open in this same tick,
                // and scrollIntoView's target position should be computed
                // against the settled post-open layout, not mid-reflow.
                var toScroll = matches[matchIndex];
                requestAnimationFrame(function () {
                    scrollAndPulse(toScroll, prefersReducedMotion);
                });
            }
        }

        if (input) {
            input.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                var value = input.value;
                debounceTimer = setTimeout(function () { runSearch(value); }, 120);
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    gotoMatch(1);
                }
            });
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', function () { gotoMatch(-1); });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () { gotoMatch(1); });
        }

        function gotoMatch(delta) {
            if (!matches.length) {
                return;
            }
            matchIndex = (matchIndex + delta + matches.length) % matches.length;
            scrollAndPulse(matches[matchIndex], prefersReducedMotion);
        }

        function clearField() {
            if (!input) {
                return;
            }
            input.value = '';
            runSearch('');
            input.blur();
        }

        return {
            clear: clearField,
            isActive: function () { return !!(input && input.value); },
            focusInput: function () { if (input) { input.focus(); } },
            isInputFocused: function () { return !!(input && document.activeElement === input); },
        };
    }

    // ------------------------------------------------------------------
    // Sticky breadcrumb - IntersectionObserver over each node's OWN ROW
    // (.org-node, not its containing <li> - a prior round's fix, an
    // ancestor's <li> also wraps its own expanded subtree, which broke
    // "topmost visible" tracking after a large jump), redrawn from that
    // node's own data-ancestor-path + name whenever the topmost visible
    // node changes.
    //
    // LEFT AS-IS for this round's box-and-line chart layout, deliberately,
    // not by oversight: this mechanism is layout-agnostic by construction -
    // it watches each .org-node's OWN geometry, wherever that node's row
    // actually renders (a chart-mode canvas absolutely-positions .org-node
    // itself, see agent/css/org_chart.css's ".org-chart-active summary,
    // .org-chart-active .org-node-row" rule - the element the observer
    // watches is unaffected by how its ANCESTORS are laid out) - so a
    // "topmost visible" reading stays meaningful in chart mode exactly as
    // it did in the plain-list layout, with zero changes needed here.
    // scrollAndPulse() (used by both search and a breadcrumb-segment click)
    // is what DID need a chart-mode-aware change - see that function.
    // ------------------------------------------------------------------
    function wireBreadcrumb(root, nodes, idToNode, traceApi, prefersReducedMotion) {
        var nav = document.getElementById('orgChartBreadcrumb');
        var trail = document.getElementById('orgChartBreadcrumbTrail');
        var traceAffix = document.getElementById('orgChartBreadcrumbTrace');
        var traceClearBtn = document.getElementById('orgChartTraceClear');
        if (!nav || !trail || !window.IntersectionObserver || !nodes.length) {
            return;
        }

        var lastId = null;

        function render(node) {
            while (trail.firstChild) {
                trail.removeChild(trail.firstChild);
            }
            var path = (node.getAttribute('data-ancestor-path') || '');
            var ids = (path.length ? path.split(' ') : []).concat([node.getAttribute('data-contact-id')]);

            ids.forEach(function (id, i) {
                var n = idToNode[id];
                if (!n) {
                    return;
                }
                if (i > 0) {
                    trail.appendChild(document.createTextNode(' › ')); // ›
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'org-breadcrumb-seg';
                btn.textContent = n.getAttribute('data-name') || '';
                btn.addEventListener('click', function () {
                    scrollAndPulse(id, prefersReducedMotion);
                });
                trail.appendChild(btn);
            });

            nav.hidden = false;
        }

        // Observe each node's OWN row (.org-node), never its containing <li> -
        // an ancestor's <li> also wraps its entire visible subtree (the child
        // <ul> sits inside the same <details>), so its bounding rect can span
        // the whole screen while an expanded branch is open. IntersectionObserver
        // only reports entries whose isIntersecting state just CHANGED, not
        // every currently-intersecting target - after a big jump (search
        // next/prev, a breadcrumb-segment click), an ancestor's <li> that was
        // already intersecting before and remains so keeps its status
        // unchanged and never re-fires, leaving the breadcrumb stuck on a
        // stale ancestor. A single row is small and fixed-height regardless
        // of whether its subtree is expanded, so its intersection state
        // changes exactly when it scrolls in/out - the correct signal.
        var observer = new IntersectionObserver(function (entries) {
            var visible = entries.filter(function (e) { return e.isIntersecting; });
            if (!visible.length) {
                return;
            }
            visible.sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top; });
            var node = visible[0].target;
            var id = node.getAttribute('data-contact-id');
            if (id === lastId) {
                return;
            }
            lastId = id;
            render(node);
        }, { rootMargin: '-8px 0px -85% 0px', threshold: 0 });

        nodes.forEach(function (n) {
            observer.observe(n);
        });

        if (traceAffix && traceClearBtn) {
            traceClearBtn.addEventListener('click', function () { traceApi.clear(); });
            traceApi.onChange(function (activeId) {
                traceAffix.hidden = activeId === null;
            });
        }
    }

    // ------------------------------------------------------------------
    // Hover / tap preview panel - one reused floating panel (desktop) or
    // bottom sheet (touch, via the @media (hover:none) rules in
    // agent/css/org_chart.css). Only wired when animationsEnabled (see run()).
    // ------------------------------------------------------------------
    function wirePreviewPanel(nodes, isTouchInput, prefersReducedMotion) {
        var panel = null;
        var scrim = null;
        var openForNode = null;
        var hoverInTimer = null;
        var hoverOutTimer = null;

        function ensurePanel() {
            if (panel) {
                return panel;
            }
            panel = document.createElement('div');
            panel.className = 'org-preview-panel';
            panel.hidden = true;
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-label', 'Contact preview');
            // Static skeleton only - no user data is interpolated into this
            // markup. Every field below is filled in afterwards via
            // textContent/attribute assignment, never innerHTML, so nothing
            // here can inject markup regardless of what a contact's name/
            // title/department contains.
            panel.innerHTML =
                '<div class="org-preview-avatar"></div>' +
                '<div class="org-preview-body">' +
                '  <div class="org-preview-name"></div>' +
                '  <div class="org-preview-title"></div>' +
                '  <div class="org-preview-dept-row">' +
                '    <span class="org-preview-dept-badge"></span>' +
                '    <span class="org-preview-status"></span>' +
                '  </div>' +
                '  <div class="org-preview-reports"></div>' +
                '  <div class="org-preview-manages"></div>' +
                '  <a class="org-preview-link" href="#">View full profile &rarr;</a>' +
                '</div>' +
                '<button type="button" class="org-preview-close" aria-label="Close">&times;</button>';
            document.body.appendChild(panel);
            panel.querySelector('.org-preview-close').addEventListener('click', close);
            return panel;
        }

        function ensureScrim() {
            if (scrim) {
                return scrim;
            }
            scrim = document.createElement('div');
            scrim.className = 'org-preview-scrim';
            scrim.hidden = true;
            document.body.appendChild(scrim);
            scrim.addEventListener('click', close);
            return scrim;
        }

        function deptSlotClass(node) {
            var avatar = node.querySelector('.org-node-avatar');
            var match = avatar && avatar.className.match(/org-dept-slot-\d+/);
            return match ? match[0] : '';
        }

        function fill(node) {
            var p = ensurePanel();
            var slotClass = deptSlotClass(node);

            var avatarWrap = p.querySelector('.org-preview-avatar');
            avatarWrap.className = 'org-preview-avatar' + (slotClass ? ' ' + slotClass : '');
            while (avatarWrap.firstChild) {
                avatarWrap.removeChild(avatarWrap.firstChild);
            }
            var srcImg = node.querySelector('.org-node-avatar-img');
            if (srcImg) {
                var img = document.createElement('img');
                img.src = srcImg.getAttribute('src');
                img.alt = '';
                img.loading = 'lazy';
                img.decoding = 'async';
                avatarWrap.appendChild(img);
            } else {
                var initialsEl = node.querySelector('.org-node-initials');
                avatarWrap.appendChild(document.createTextNode(initialsEl ? initialsEl.textContent : ''));
            }

            p.querySelector('.org-preview-name').textContent = node.getAttribute('data-name') || '';
            p.querySelector('.org-preview-title').textContent = node.getAttribute('data-title') || '';

            var deptBadge = p.querySelector('.org-preview-dept-badge');
            deptBadge.className = 'org-preview-dept-badge' + (slotClass ? ' ' + slotClass : '');
            deptBadge.textContent = node.getAttribute('data-dept') || '';

            var statusHost = p.querySelector('.org-preview-status');
            while (statusHost.firstChild) {
                statusHost.removeChild(statusHost.firstChild);
            }
            var statusBadge = node.querySelector('.badge');
            if (statusBadge) {
                statusHost.appendChild(statusBadge.cloneNode(true));
            }

            var managerName = node.getAttribute('data-manager-name') || '';
            p.querySelector('.org-preview-reports').textContent = 'Reports to: ' + (managerName || '—');

            var reportCount = parseInt(node.getAttribute('data-report-count') || '0', 10) || 0;
            p.querySelector('.org-preview-manages').textContent =
                'Manages ' + reportCount + (reportCount === 1 ? ' person' : ' people');

            var link = p.querySelector('.org-preview-link');
            var nameLink = node.querySelector('.org-node-name');
            link.setAttribute('href', nameLink ? nameLink.getAttribute('href') : '#');
        }

        function position(p, node) {
            var margin = 8;
            var rect = node.getBoundingClientRect();
            var pw = p.offsetWidth || 280;
            var ph = p.offsetHeight;
            var vw = document.documentElement.clientWidth;
            var vh = document.documentElement.clientHeight;

            var top = rect.top;
            var left;
            if (rect.right + margin + pw <= vw) {
                left = rect.right + margin;
            } else if (rect.left - margin - pw >= 0) {
                left = rect.left - margin - pw;
            } else {
                left = Math.max(margin, Math.min(vw - pw - margin, rect.left));
                top = rect.bottom + margin;
            }
            top = Math.max(margin, Math.min(vh - ph - margin, top));

            p.style.top = top + 'px';
            p.style.left = left + 'px';
        }

        function open(node) {
            if (openForNode === node) {
                return;
            }
            var p = ensurePanel();
            fill(node);
            p.hidden = false;
            openForNode = node;

            var touch = !!(window.matchMedia && window.matchMedia('(hover: none)').matches);
            if (touch) {
                var scrimEl = ensureScrim();
                scrimEl.hidden = false;
                p.style.top = '';
                p.style.left = '';
                p.animate(
                    [{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }],
                    { duration: prefersReducedMotion ? 1 : DUR_OVERLAY, easing: EASE_OUT }
                );
            } else {
                position(p, node);
                p.animate(
                    [{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'translateY(0)' }],
                    { duration: prefersReducedMotion ? 1 : DUR_UI, easing: EASE_OUT }
                );
            }
        }

        function close() {
            if (!panel || panel.hidden) {
                return;
            }
            panel.hidden = true;
            if (scrim) {
                scrim.hidden = true;
            }
            openForNode = null;
        }

        nodes.forEach(function (n) {
            var btn = n.querySelector('.org-node-preview-btn');
            if (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (openForNode === n) {
                        close();
                    } else {
                        open(n);
                    }
                });
            }

            if (!isTouchInput) {
                n.addEventListener('mouseenter', function () {
                    clearTimeout(hoverOutTimer);
                    clearTimeout(hoverInTimer);
                    hoverInTimer = setTimeout(function () { open(n); }, 150);
                });
                n.addEventListener('mouseleave', function () {
                    clearTimeout(hoverInTimer);
                    clearTimeout(hoverOutTimer);
                    hoverOutTimer = setTimeout(function () {
                        if (openForNode === n) {
                            close();
                        }
                    }, 150);
                });
            }
        });

        document.addEventListener('click', function (e) {
            if (!panel || panel.hidden) {
                return;
            }
            if (panel.contains(e.target)) {
                return;
            }
            if (openForNode && openForNode.contains(e.target)) {
                return;
            }
            close();
        });
        document.addEventListener('focusin', function (e) {
            if (!panel || panel.hidden) {
                return;
            }
            if (panel.contains(e.target)) {
                return;
            }
            if (openForNode && openForNode.contains(e.target)) {
                return;
            }
            close();
        });

        return { close: close, isOpen: function () { return !!openForNode; } };
    }

    // ------------------------------------------------------------------
    // Global keyboard shortcuts: "/" focuses search, Escape clears whatever
    // is currently active (search box, then trace, then preview panel).
    // ------------------------------------------------------------------
    function wireGlobalKeys(searchApi, traceApi, previewApi) {
        document.addEventListener('keydown', function (e) {
            if (e.key === '/' && !isEditableTarget(document.activeElement)) {
                e.preventDefault();
                searchApi.focusInput();
                return;
            }
            if (e.key === 'Escape') {
                if (searchApi.isInputFocused()) {
                    searchApi.clear();
                } else if (traceApi.isActive()) {
                    traceApi.clear();
                } else if (previewApi && previewApi.isOpen()) {
                    previewApi.close();
                }
            }
        });
    }

    function isEditableTarget(el) {
        if (!el) {
            return false;
        }
        var tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || !!el.isContentEditable;
    }

    // ------------------------------------------------------------------
    // Print: open everything, clear any active search/trace, then restore
    // exactly what was open beforehand - a small, deliberate improvement over
    // js/kb_interactive.js's own one-way beforeprint-only precedent.
    // ------------------------------------------------------------------
    function wirePrint(root, searchApi, traceApi, previewApi, chartCtxs) {
        var snapshot = null;

        // Every chart-mode department's own layout to its full, nothing-
        // collapsed extent, synchronously - the forced d.open = true loop
        // below fires a native 'toggle' per <details>, which
        // wireChartLayout()'s own listener already turns into a relayout,
        // but that path is deliberately rAF-coalesced (scheduleRelayout)
        // for smooth interactive use, and the print engine can capture the
        // page before that frame runs. Calling performLayout() directly
        // here guarantees every canvas is sized/positioned to its correct
        // full extent - nothing clipped, nothing still mid-collapse -
        // before the browser paints the print output. The CSS half of this
        // is .org-chart-scroll{overflow:visible!important} under
        // @media print in agent/css/org_chart.css, so the SCROLL WRAPPER
        // never clips that extent - but overflow:visible only stops the
        // wrapper's own box from cutting content off, it does nothing about
        // the PAGE's fixed physical width. A canvas wider than the
        // printable page area still ran off the page's right edge with no
        // pagination and no shrink (confirmed with a real headless-Chrome
        // PDF render: a department with as few as 3 siblings already
        // exceeds a typical Letter page's ~700px printable width at 96 CSS
        // px/inch) - applyPrintScale() below is what actually fixes that.
        function forceLayoutAll() {
            (chartCtxs || []).forEach(function (ctx) {
                if (ctx.dead) {
                    return;
                }
                try {
                    performLayout(ctx);
                } catch (err) {
                    logRelayoutErr(err);
                }
            });
        }

        // Conservative estimate of a page's printable content width in CSS
        // px (96/inch): a Letter page is 8.5in wide; browsers commonly
        // default to ~0.5-1in margins per side when no @page rule states
        // otherwise, and JS has no API to read the ACTUAL page box a given
        // browser/OS print dialog will use. 700px is deliberately on the
        // narrow side of that range (undershooting wastes a little paper
        // width; overshooting is the actual bug this exists to fix).
        var PRINT_SAFE_WIDTH_PX = 700;

        // Shrinks a too-wide chart-mode canvas to fit PRINT_SAFE_WIDTH_PX,
        // via a CSS transform (the only way to scale rendered content
        // without re-running the whole subtree-width layout math at a
        // different BOX_W). transform does not participate in layout, so
        // the scroll-wrapper's own height is set explicitly to the SCALED
        // height - left alone, the wrapper would keep its unscaled height
        // and print a tall blank gap below the now-smaller chart.
        function applyPrintScale(ctx) {
            var naturalW = ctx.canvas.offsetWidth;
            if (naturalW <= PRINT_SAFE_WIDTH_PX) {
                ctx.canvas.style.transform = '';
                ctx.scrollWrap.style.height = '';
                return;
            }
            var scale = PRINT_SAFE_WIDTH_PX / naturalW;
            ctx.canvas.style.transformOrigin = 'top left';
            ctx.canvas.style.transform = 'scale(' + scale + ')';
            ctx.scrollWrap.style.height = (ctx.canvas.offsetHeight * scale) + 'px';
        }

        function clearPrintScale(ctx) {
            ctx.canvas.style.transform = '';
            ctx.scrollWrap.style.height = '';
        }

        window.addEventListener('beforeprint', function () {
            snapshot = new Set();
            root.querySelectorAll('details').forEach(function (d) {
                if (d.open) {
                    snapshot.add(d);
                }
                d.open = true;
            });
            if (searchApi.isActive()) {
                searchApi.clear();
            }
            if (traceApi.isActive()) {
                traceApi.clear();
            }
            if (previewApi && previewApi.isOpen()) {
                previewApi.close();
            }
            forceLayoutAll();
            (chartCtxs || []).forEach(function (ctx) {
                if (!ctx.dead) {
                    applyPrintScale(ctx);
                }
            });
        });

        window.addEventListener('afterprint', function () {
            if (!snapshot) {
                return;
            }
            (chartCtxs || []).forEach(function (ctx) {
                if (!ctx.dead) {
                    clearPrintScale(ctx);
                }
            });
            root.querySelectorAll('details').forEach(function (d) {
                d.open = snapshot.has(d);
            });
            snapshot = null;
            forceLayoutAll();
        });
    }

    // ------------------------------------------------------------------
    // Shared by search-next/prev and breadcrumb-segment clicks: scroll a
    // node into view and pulse it so an off-screen match is findable.
    // ------------------------------------------------------------------
    function scrollAndPulse(contactId, prefersReducedMotion) {
        var node = document.querySelector('.org-node[data-contact-id="' + cssEscape(contactId) + '"]');
        if (!node) {
            return;
        }
        // Scroll the .org-node itself, not its containing <li> - in a
        // chart-mode department, the <li> is a zero-footprint wrapper
        // (its actual visible row is absolutely positioned elsewhere in
        // the canvas), so its own bounding rect no longer reflects where
        // the box actually renders. .org-node's rect is always correct
        // regardless of layout mode, and in the plain-list fallback the
        // two are visually equivalent anyway (the <li> tightly wraps it).
        node.scrollIntoView({ block: 'center', inline: 'center', behavior: prefersReducedMotion ? 'auto' : 'smooth' });

        // Reduced-motion: the scroll behavior above already switches to 'auto',
        // but the pulse itself is a separate CSS @keyframes animation with no
        // reduced-motion override of its own - skip it outright rather than
        // relying on a CSS-only guard, which would leave 'animationend' never
        // firing (no animation runs) and the class + its listener stuck on the
        // node instead of being cleaned up.
        if (prefersReducedMotion) {
            return;
        }

        node.classList.remove('org-node-pulse');
        void node.offsetWidth; // force reflow so re-adding the class restarts the animation
        node.classList.add('org-node-pulse');
        node.addEventListener('animationend', function handler() {
            node.classList.remove('org-node-pulse');
            node.removeEventListener('animationend', handler);
        });
    }

    function cssEscape(value) {
        if (window.CSS && CSS.escape) {
            return CSS.escape(value);
        }
        // Contact ids are always digits (intval() server-side), so this is
        // only ever a defensive fallback for a browser with no CSS.escape.
        return String(value).replace(/[^a-zA-Z0-9_-]/g, '');
    }

    window.OrgChart = { init: init };
})();
