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
        var data = [{ id: 'organization', parentId: null, name: 'Organization', kind: 'organization', count: people.length }];
        groups.forEach(function (group) {
            if (groups.size === 1) { group._expanded = true; }
            data.push(group);
        });
        return { data: data.concat(people), people: people };
    }

    function card(node) {
        var person = node.data;
        var selected = person._highlighted || person._upToTheRootHighlighted ? ' org-d3-selected' : '';
        if (person.kind !== 'person') {
            return '<div class="org-d3-group' + selected + '"><strong>' + escapeHtml(person.name) + '</strong><span>' + person.count + ' people</span></div>';
        }
        var avatar = person.photo
            ? '<img loading="lazy" decoding="async" src="' + escapeHtml(person.photo) + '" alt="">'
            : '<span>' + escapeHtml(person.initials || person.name.slice(0, 2).toUpperCase()) + '</span>';
        var subtitle = person.title || person.team || person.department;
        var count = Number(person.reports) > 0 ? '<b class="org-d3-count" title="Direct reports">' + escapeHtml(person.reports) + '</b>' : '';
        var note = person.context ? 'Context' : (person.managerUnavailable ? 'Manager unavailable' : '');
        var context = note ? '<i class="org-d3-context" title="' + escapeHtml(note) + '">' + escapeHtml(note) + '</i>' : '';
        return '<div class="org-d3-person' + selected + '"><div class="org-d3-avatar">' + avatar + '</div><div class="org-d3-text"><a href="' + escapeHtml(person.href) + '" title="' + escapeHtml(person.name) + '">' + escapeHtml(person.name) + '</a><span title="' + escapeHtml(subtitle) + '">' + escapeHtml(subtitle) + '</span></div>' + count + context + '</div>';
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

        function showChartView(active) {
            root.hidden = !active;
            list.hidden = active;
            if (controls) { controls.hidden = !active; }
            if (searchWrap) { searchWrap.hidden = !active; }
            listButton.classList.toggle('btn-primary', !active);
            listButton.classList.toggle('btn-secondary', active);
            chartButton.classList.toggle('btn-primary', active);
            chartButton.classList.toggle('btn-secondary', !active);
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
            if (chart) { showChartView(true); return; }
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
                    .data(model.data)
                    .nodeWidth(function () { return 214; })
                    .nodeHeight(function () { return 64; })
                    .siblingsMargin(function () { return 14; })
                    .childrenMargin(function () { return 35; })
                    .neighbourMargin(function () { return 24; })
                    .compactMarginPair(function () { return 30; })
                    .compactMarginBetween(function () { return 12; })
                    .nodeButtonWidth(function () { return 24; })
                    .nodeButtonHeight(function () { return 24; })
                    .initialExpandLevel(1)
                    .duration(0)
                    .svgHeight(Math.max(420, Math.min(window.innerHeight * .68, 700)))
                    .nodeContent(card)
                    .buttonContent(function (_ref) {
                        var node = _ref.node;
                        var expanded = !!node.children;
                        return '<span class="org-d3-toggle" aria-hidden="true">' + (expanded ? '−' : '+') + '</span>';
                    })
                    .render();
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
        document.getElementById('orgChartExpandAll').addEventListener('click', function () { if (chart) { chart.expandAll(); } });
        document.getElementById('orgChartCollapseAll').addEventListener('click', function () {
            if (!chart) { return; }
            model.data.forEach(function (item) {
                item._expanded = item.kind === 'organization';
                item._highlighted = false;
                item._upToTheRootHighlighted = false;
                item._centered = false;
            });
            selected = null;
            matchIndex = -1;
            chart.initialExpandLevel(1).render();
        });
        document.getElementById('orgChartZoomOut').addEventListener('click', function () { if (chart) { chart.zoomOut(); } });
        document.getElementById('orgChartZoomIn').addEventListener('click', function () { if (chart) { chart.zoomIn(); } });
        document.getElementById('orgChartZoomFit').addEventListener('click', function () { if (chart) { chart.fit({ animate: false }); } });
        document.getElementById('orgChartZoomReset').addEventListener('click', function () {
            if (!chart) { return; }
            var state = chart.getChartState();
            state.svg.call(state.zoomBehavior.scaleTo, 1);
        });
        chartButton.hidden = false;
    }

    window.OrgChart = { init: init };
}());
