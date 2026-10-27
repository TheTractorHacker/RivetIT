/* Interactive chart for agent/org_chart.php. The list and filters work without it. */
(function () {
    'use strict';

    var vendorFiles = [
        'js/vendor/org-chart/d3-7.9.0.min.js',
        'js/vendor/org-chart/d3-flextree-2.1.2.min.js',
        'js/vendor/org-chart/d3-org-chart-3.1.1.min.js'
    ];
    var libraryPromise;

    function loadLibraries() {
        if (!libraryPromise) {
            libraryPromise = vendorFiles.reduce(function (chain, path) {
                return chain.then(function () {
                    return new Promise(function (resolve, reject) {
                        var script = document.createElement('script');
                        script.src = path;
                        script.onload = resolve;
                        script.onerror = function () { reject(new Error('Could not load ' + path)); };
                        document.head.appendChild(script);
                    });
                });
            }, Promise.resolve()).then(function () {
                if (!window.d3 || !window.d3.OrgChart) {
                    throw new Error('Chart library did not initialize');
                }
            });
        }
        return libraryPromise;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
        });
    }

    function collectChartData(source) {
        var people = [];
        var groups = new Map();
        var rootDepartments = {};
        var nodes = source.querySelectorAll('.org-node');
        Array.prototype.forEach.call(nodes, function (element) {
            var id = element.getAttribute('data-contact-id');
            var ancestors = (element.getAttribute('data-ancestor-path') || '').trim().split(/\s+/).filter(Boolean);
            var department = element.getAttribute('data-dept') || 'No department';
            if (!ancestors.length) { rootDepartments[id] = department; }
            var groupName = ancestors.length ? (rootDepartments[ancestors[0]] || department) : department;
            var group = groups.get(groupName);
            if (!group) {
                group = { id: 'department:' + groups.size, parentId: 'organization', name: groupName, kind: 'department', count: 0 };
                groups.set(groupName, group);
            }
            group.count++;
            var link = element.querySelector('.org-node-name');
            var image = element.querySelector('.org-node-avatar-img');
            var initials = element.querySelector('.org-node-initials');
            var person = {
                id: 'person:' + id,
                parentId: ancestors.length ? 'person:' + ancestors[ancestors.length - 1] : group.id,
                kind: 'person',
                name: element.getAttribute('data-name') || '',
                title: element.getAttribute('data-title') || '',
                team: element.getAttribute('data-group') || '',
                department: department,
                status: element.getAttribute('data-status') || '',
                manager: element.getAttribute('data-manager-name') || '',
                reports: element.getAttribute('data-report-count') || '0',
                href: link ? link.getAttribute('href') : '',
                photo: image ? image.getAttribute('src') : '',
                initials: initials ? initials.textContent.trim() : '',
                context: !!element.querySelector('.org-context-badge') && element.querySelector('.org-context-badge').textContent.trim() === 'Context',
                managerUnavailable: !!element.querySelector('.text-bg-warning.org-context-badge')
            };
            person.searchText = [person.name, person.title, person.team, person.department, person.status, person.manager].join(' ').toLocaleLowerCase();
            people.push(person);
        });
        var orgName = (document.getElementById('orgChartCanvas') || {}).dataset ? document.getElementById('orgChartCanvas').dataset.orgName : '';
        var data = [{ id: 'organization', parentId: null, name: orgName || 'Organization', kind: 'organization', count: people.length }];
        groups.forEach(function (group) {
            data.push(group);
        });
        data = data.concat(people);
        var kids = {}, byId = {};
        data.forEach(function (item) { byId[item.id] = item; (kids[item.parentId] = kids[item.parentId] || []).push(item); });
        return { data: data, people: people, kids: kids, byId: byId, shown: {}, more: {} };
    }

    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    // Stable avatar colour per person (not one colour for everybody); every entry keeps white text above 4.5:1.
    var AVATAR_CLASSES = 8;
    function avatarClass(name) {
        var hash = 0;
        for (var i = 0; i < name.length; i++) { hash = (hash * 31 + name.charCodeAt(i)) >>> 0; }
        return 'org-av-' + (hash % AVATAR_CLASSES);
    }

    // Card sizes by kind. The chart lays out left to right, so each level is one fixed-width column.
    var SIZES = { organization: { w: 210, h: 56 }, department: { w: 230, h: 44 }, person: { w: 260, h: 56 }, more: { w: 260, h: 40 } };
    function sizeOf(node) { return SIZES[node.data.kind] || SIZES.person; }
    var PAGE_SIZE = 15;    // reports shown per person/department before a "Show more" card
    var BUTTON = 24;       // toggle diameter (100% scale)
    var BUTTON_GAP = 5;    // clear space between a card's right edge and its toggle
    var LEVEL_GAP = 78;    // card edge to the next column; the connector trunk sits mid-way through the gap
    var ROW_GAP = 10;      // vertical space between stacked cards

    function card(node) {
        var person = node.data;
        var selected = person._highlighted || person._upToTheRootHighlighted ? ' org-d3-selected' : '';
        if (person.kind === 'more') {
            return '<button type="button" class="org-d3-more" aria-label="Show ' + Math.min(PAGE_SIZE, person.remaining) + ' more of ' + person.remaining + ' hidden people"><i class="fas fa-plus-circle" aria-hidden="true"></i> Show ' + Math.min(PAGE_SIZE, person.remaining) + ' more <span>(' + person.remaining + ' hidden)</span></button>';
        }
        if (person.kind === 'organization') {
            return '<div class="org-d3-group org-d3-root' + selected + '" title="' + escapeHtml(person.name) + '"><strong>' + escapeHtml(person.name) + '</strong><span>' + plural(person.count, 'person', 'people') + '</span></div>';
        }
        if (person.kind !== 'person') {
            return '<div class="org-d3-group' + selected + '" title="' + escapeHtml(person.name) + '"><strong>' + escapeHtml(person.name) + '</strong><span>' + plural(person.count, 'person', 'people') + '</span></div>';
        }
        var avatar = person.photo
            ? '<img loading="lazy" decoding="async" src="' + escapeHtml(person.photo) + '" alt="">'
            : '<span>' + escapeHtml(person.initials || person.name.slice(0, 2).toUpperCase()) + '</span>';
        // Never repeat the department as a role: fall back to the team only when it adds something, else say so.
        var team = person.team && person.team !== person.department ? person.team : '';
        var subtitle = person.title || team;
        var subtitleHtml = subtitle
            ? '<span title="' + escapeHtml(subtitle) + '">' + escapeHtml(subtitle) + '</span>'
            : '<span class="org-d3-notitle">No title</span>';
        var reports = Number(person.reports);
        var reportsLabel = plural(reports, 'direct report', 'direct reports');
        var count = reports > 0 ? '<b class="org-d3-count" title="' + reportsLabel + '" aria-label="' + reportsLabel + '"><i class="fas fa-users" aria-hidden="true"></i> ' + reports + '</b>' : '';
        var note = person.context ? 'Context' : (person.managerUnavailable ? 'Manager unavailable' : '');
        var context = note ? '<i class="org-d3-context" title="' + escapeHtml(note) + '">' + escapeHtml(note) + '</i>' : '';
        var tip = [person.name, person.title, person.team, person.department].filter(function (part, i, all) { return part && all.indexOf(part) === i; }).join(' · ');
        return '<div class="org-d3-person' + selected + '" title="' + escapeHtml(tip) + '"><div class="org-d3-avatar ' + avatarClass(person.name) + '">' + avatar + '</div><div class="org-d3-text"><a href="' + escapeHtml(person.href) + '">' + escapeHtml(person.name) + '</a>' + subtitleHtml + '</div>' + count + context + '</div>';
    }

    // One clean elbow from the parent's right edge to each child: a shared vertical trunk, small rounded corners, no loops.
    function elbow(child, parent) {
        var cx = child.x, cy = child.y, px = parent.x, py = parent.y;
        var mid = px + BUTTON_GAP + BUTTON + 14;
        if (Math.abs(cy - py) < 1 || cx - px < 2) { return 'M' + cx + ' ' + cy + 'H' + px; }
        var r = Math.min(8, Math.abs(cy - py) / 2), dir = cy > py ? 1 : -1;
        return 'M' + cx + ' ' + cy + 'H' + (mid + r) +
            'Q' + mid + ' ' + cy + ' ' + mid + ' ' + (cy - dir * r) +
            'V' + (py + dir * r) +
            'Q' + mid + ' ' + py + ' ' + (mid - r) + ' ' + py +
            'H' + px;
    }

    function wireListSearch() {
        var input = document.getElementById('orgChartListSearch');
        var empty = document.getElementById('orgChartListEmpty');
        if (!input) { return; }
        var rows = Array.prototype.map.call(document.querySelectorAll('.org-list-row'), function (row) {
            return { row: row, text: row.textContent.toLocaleLowerCase() };
        });
        input.addEventListener('input', function () {
            var query = input.value.trim().toLocaleLowerCase();
            var matches = 0;
            rows.forEach(function (item) {
                var found = !query || item.text.indexOf(query) !== -1;
                item.row.hidden = !found;
                if (found) { matches++; }
            });
            if (empty) { empty.hidden = matches !== 0; }
        });
    }

    function init() {
        wireListSearch();
        var source = document.getElementById('orgChartSource');
        var canvas = document.getElementById('orgChartCanvas');
        var root = document.getElementById('orgChartRoot');
        var list = document.getElementById('orgChartList');
        var listButton = document.getElementById('orgChartShowList');
        var chartButton = document.getElementById('orgChartShowMap');
        var controls = document.getElementById('orgChartControls');
        var searchWrap = document.getElementById('orgChartMapSearchWrap');
        var loading = document.getElementById('orgChartLoading');
        if (!source || !canvas || !root || !list || !listButton || !chartButton) { return; }

        var chart = null;
        var model = null;
        var matches = [];
        var matchIndex = -1;
        var selected = null;
        var chartSearch = document.getElementById('orgChartSearch');
        var counter = document.getElementById('orgChartSearchCounter');
        var countText = document.getElementById('orgChartSearchCount');

        var userMoved = false;
        var zoomLabel = document.getElementById('orgChartZoomReset');

        // Fit the whole drawn tree into the frame without ever zooming in past 100% (a one-department chart must not blow up).
        function fitChart() {
            if (!chart) { return; }
            var state = chart.getChartState();
            var node = state.centerG.node();
            var local = node.getBBox();
            var m = node.transform.baseVal.consolidate() ? node.transform.baseVal.consolidate().matrix : { a: 1, e: 0, f: 0 };
            // bounding box in the zoomed group's own coordinates (the centre group is itself offset by the lib)
            var box = { x: local.x * m.a + m.e, y: local.y * m.a + m.f, width: local.width * m.a, height: local.height * m.a };
            var width = canvas.clientWidth, pad = 24;
            if (!width || !box.width || !box.height) { return; }
            // Readable first: never shrink below MIN_FIT; instead let the frame grow (the page scrolls) up to MAX_FRAME, and only
            // below MIN_FIT's height budget fall back to a smaller scale. Never above 100%.
            var MIN_FIT = .85, MAX_FRAME = 1400;
            var viewportH = Math.max(440, Math.min(1000, window.innerHeight - 300));
            var byWidth = (width - pad * 2) / box.width;
            var scale = Math.min(1, byWidth, Math.max(MIN_FIT, (viewportH - pad * 2) / box.height));
            var height = Math.max(viewportH, Math.min(MAX_FRAME, Math.ceil(box.height * scale + pad * 2)));
            scale = Math.max(.1, Math.min(scale, (height - pad * 2) / box.height));
            canvas.style.height = height + 'px';
            state.svg.attr('height', height);
            state.svgHeight = height;
            var tx = (width - box.width * scale) / 2 - box.x * scale;
            var ty = (height - box.height * scale) / 2 - box.y * scale;
            state.svg.call(state.zoomBehavior.transform, window.d3.zoomIdentity.translate(tx, ty).scale(scale));
            userMoved = false;
        }
        // The library places nodes on the next frame, so measure after it has.
        function fitSoon() { requestAnimationFrame(function () { requestAnimationFrame(fitChart); }); }
        // "Expand all" opens level by level and stops before it would draw more than MAX_CARDS cards (179 people must not become 179 unreadable cards).
        var MAX_CARDS = 36;
        var note = document.createElement('p');
        note.className = 'small text-secondary mb-1';
        note.setAttribute('role', 'status');
        note.hidden = true;
        canvas.parentNode.insertBefore(note, canvas);
        // The cards actually drawn: every parent shows its first PAGE_SIZE reports plus one "Show more" card for the rest.
        function visibleData() {
            var out = [];
            (function walk(item) {
                out.push(item);
                var list = model.kids[item.id] || [];
                var limit = item.kind === 'organization' ? list.length : (model.shown[item.id] || PAGE_SIZE);
                list.slice(0, limit).forEach(walk);
                if (list.length > limit) {
                    var more = model.more[item.id] || (model.more[item.id] = { id: 'more:' + item.id, parentId: item.id, kind: 'more', name: '', _expanded: true });
                    more.remaining = list.length - limit;
                    out.push(more);
                }
            }(model.data[0]));
            return out;
        }
        function redraw() {
            chart.data(visibleData()).render();
            fitSoon();
        }
        function showMore(parentId) {
            var list = model.kids[parentId] || [];
            var from = model.shown[parentId] || PAGE_SIZE;
            model.shown[parentId] = from + PAGE_SIZE;
            list.slice(from, from + PAGE_SIZE).forEach(function (item) { item._expanded = true; });
            redraw();
        }
        // Open a person (or department) for search: page far enough in that it is drawn, even when it sat behind "Show more".
        function reveal(item) {
            for (var cur = item; cur && cur.parentId; cur = model.byId[cur.parentId]) {
                var parent = model.byId[cur.parentId];
                var index = (model.kids[parent.id] || []).indexOf(cur);
                if (parent.kind !== 'organization' && index >= (model.shown[parent.id] || PAGE_SIZE)) { model.shown[parent.id] = index + 1; }
            }
        }
        function expandLevels() {
            model.data.forEach(function (item) { item._expanded = false; });
            model.shown = {};
            var frontier = [model.data[0]], total = 1, level = 0, truncated = false;
            model.data[0]._expanded = true;
            while (frontier.length) {
                var next = [];
                frontier.forEach(function (parent) {
                    var children = model.kids[parent.id] || [];
                    (parent.kind === 'organization' ? children : children.slice(0, PAGE_SIZE)).forEach(function (child) { next.push(child); });
                });
                if (!next.length) { break; }
                if (level >= 1 && total + next.length > MAX_CARDS) { truncated = true; break; }
                next.forEach(function (child) { child._expanded = true; });
                total += next.length;
                frontier = next;
                level++;
            }
            note.hidden = !truncated;
            note.textContent = truncated ? 'Opened ' + level + ' levels (' + total + ' cards). Use + on a card, or search, to open deeper levels.' : '';
            redraw();
        }
        function resizeChart() {
            if (!chart || root.hidden) { return; }
            var state = chart.getChartState();
            state.svg.attr('width', canvas.clientWidth).attr('height', canvas.clientHeight);
            state.svgWidth = canvas.clientWidth;
            state.svgHeight = canvas.clientHeight;
            if (!userMoved) { fitChart(); }
        }
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(resizeChart, 120);
        });

        function showChartView(active) {
            root.hidden = !active;
            list.hidden = active;
            if (controls) { controls.hidden = !active; }
            if (searchWrap) { searchWrap.hidden = !active; }
            var hint = document.getElementById('orgChartHint');
            if (hint) { hint.classList.toggle('d-none', !active); }
            listButton.classList.toggle('is-active', !active);
            chartButton.classList.toggle('is-active', active);
            listButton.setAttribute('aria-pressed', String(!active));
            chartButton.setAttribute('aria-pressed', String(active));
        }

        function navigateMatch(step) {
            if (!chart || !matches.length) { return; }
            matchIndex = (matchIndex + step + matches.length) % matches.length;
            model.data.forEach(function (item) {
                item._highlighted = false;
                item._upToTheRootHighlighted = false;
            });
            selected = matches[matchIndex];
            reveal(selected);
            chart.data(visibleData());
            chart.setUpToTheRootHighlighted(selected.id).setCentered(selected.id).render();
            countText.textContent = (matchIndex + 1) + ' of ' + matches.length + ' matches';
        }

        function updateSearch() {
            if (!model) { return; }
            var query = chartSearch.value.trim().toLocaleLowerCase();
            matches = query ? model.people.filter(function (person) { return person.searchText.indexOf(query) !== -1; }) : [];
            matchIndex = -1;
            if (selected) {
                model.data.forEach(function (item) {
                    item._highlighted = false;
                    item._upToTheRootHighlighted = false;
                });
                selected = null;
                if (chart) { chart.render(); }
            }
            counter.hidden = !query;
            countText.textContent = matches.length + (matches.length === 1 ? ' match' : ' matches');
        }

        listButton.addEventListener('click', function () { showChartView(false); });
        chartButton.addEventListener('click', function () {
            if (chart) { showChartView(true); requestAnimationFrame(resizeChart); return; }
            chartButton.disabled = true;
            loading.textContent = 'Opening chart…';
            loading.hidden = false;
            showChartView(true);
            loadLibraries().then(function () {
                // Give the newly visible container a measured width before D3 renders.
                return new Promise(function (resolve) { requestAnimationFrame(resolve); });
            }).then(function () {
                if (root.hidden) { loading.hidden = true; return; }
                if (!model) { model = collectChartData(source); }
                chart = new window.d3.OrgChart()
                    .container(canvas)
                    .data(visibleData())
                    .layout('left')
                    .compact(false)
                    .nodeWidth(function (d) { return sizeOf(d).w; })
                    .nodeHeight(function (d) { return sizeOf(d).h; })
                    .siblingsMargin(function () { return ROW_GAP; })
                    .childrenMargin(function () { return LEVEL_GAP; })
                    .neighbourMargin(function () { return ROW_GAP; })
                    // The toggle sits beside the card (right edge, vertically centred) so it can never touch the card above or below.
                    .nodeButtonWidth(function () { return BUTTON; })
                    .nodeButtonHeight(function () { return BUTTON; })
                    .nodeButtonX(function () { return BUTTON_GAP; })
                    .nodeButtonY(function (d) { return -BUTTON / 2; })
                    .minPagingVisibleNodes(function () { return 1e9; })   // paging is done in visibleData(): the library's own paging cannot open a node whose reports are all hidden
                    .onNodeClick(function (d) { if (d && d.data && d.data.kind === 'more') { showMore(d.data.parentId); } })
                    .linkUpdate(function (d) {
                        var path = window.d3.select(this);
                        path.classed('link-hl', !!d.data._upToTheRootHighlighted);
                        if (d.data._upToTheRootHighlighted) { path.raise(); }
                    })
                    .initialExpandLevel(1)
                    .duration(0)
                    .scaleExtent([.1, 3])
                    .svgHeight(canvas.clientHeight || 520)
                    .onExpandOrCollapse(function () { setTimeout(fitChart, 60); })
                    .onZoom(function (event) {
                        if (event.sourceEvent) { userMoved = true; }
                        if (zoomLabel) { zoomLabel.textContent = Math.round(event.transform.k * 100) + '%'; }
                    })
                    .nodeContent(card)
                    .buttonContent(function (_ref) {
                        var node = _ref.node;
                        var expanded = !!node.children;
                        var who = node.data.name || 'group';
                        return '<span class="org-d3-toggle" role="button" tabindex="0" aria-expanded="' + expanded + '" aria-label="' + (expanded ? 'Collapse' : 'Expand') + ' ' + escapeHtml(who) + '" title="' + (expanded ? 'Collapse' : 'Expand') + ' ' + escapeHtml(who) + '">' + (expanded ? '−' : '+') + '</span>';
                    });
                chart.getChartState().layoutBindings.left.diagonal = elbow;
                chart.render();
                fitSoon();
                setTimeout(function () { if (!userMoved) { resizeChart(); } }, 250);
                canvas.addEventListener('keydown', function (event) {
                    if ((event.key === 'Enter' || event.key === ' ') && event.target.classList && event.target.classList.contains('org-d3-toggle')) {
                        event.preventDefault();
                        event.target.click();
                    }
                });
                source.remove();
                loading.hidden = true;
                if (chartSearch.value) { updateSearch(); }
            }).catch(function (error) {
                chart = null;
                showChartView(false);
                loading.textContent = 'The chart could not open. The employee list is still available.';
                loading.hidden = false;
                if (window.console && console.error) { console.error('[OrgChart] chart unavailable', error); }
            }).then(function () { chartButton.disabled = false; });
        });

        if (chartSearch) {
            chartSearch.addEventListener('input', updateSearch);
            chartSearch.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); navigateMatch(event.shiftKey ? -1 : 1); }
            });
            document.getElementById('orgChartSearchPrev').addEventListener('click', function () { navigateMatch(-1); });
            document.getElementById('orgChartSearchNext').addEventListener('click', function () { navigateMatch(1); });
        }
        document.getElementById('orgChartExpandAll').addEventListener('click', function () { if (chart) { expandLevels(); } });
        document.getElementById('orgChartCollapseAll').addEventListener('click', function () {
            if (!chart) { return; }
            model.shown = {};
            model.data.forEach(function (item) {
                item._expanded = item.kind === 'organization';
                item._highlighted = false;
                item._upToTheRootHighlighted = false;
                item._centered = false;
            });
            selected = null;
            matchIndex = -1;
            note.hidden = true;
            redraw();
        });
        document.getElementById('orgChartZoomOut').addEventListener('click', function () { if (chart) { chart.zoomOut(); } });
        document.getElementById('orgChartZoomIn').addEventListener('click', function () { if (chart) { chart.zoomIn(); } });
        document.getElementById('orgChartZoomFit').addEventListener('click', function () { fitChart(); });
        document.getElementById('orgChartZoomReset').addEventListener('click', function () {
            if (!chart) { return; }
            var state = chart.getChartState();
            userMoved = true;
            state.svg.call(state.zoomBehavior.scaleTo, 1);
        });
        chartButton.hidden = false;
    }

    window.OrgChart = { init: init, plural: plural };
}());
