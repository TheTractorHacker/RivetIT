/*
 * Training › Assignments (Phase 2 spec §5.2): the assignments list and the rules list.
 *
 * Renders the server's first page (#tr-page-data list/rules) and refetches through
 * assignment_list / rule_list as filters change, keeping the URL in step (replaceState).
 * Row actions (level 2): Extend…, Waive…, History; header: Assign training, Recalculate now.
 * Rules (level 3): Archive… with a reason in an inline .tr-confirm-bar row.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var UI = window.TrainingUi;
        var Ops = window.TrainingOps;
        var page = document.getElementById('tro-assignments-page');
        if (!UI || !Ops || !page) { return; }
        var u = Ops.u;
        var el = u.el;
        var D = UI.readJson('tr-page-data');
        Ops.init(D);
        var level = Number(D.level || 0);
        var routes = D.routes || {};
        var $ = function (id) { return document.getElementById(id); };

        function kebab(label, items) {
            items = items.filter(Boolean);
            if (!items.length) { return null; }
            var menu = el('ul', { class: 'dropdown-menu dropdown-menu-end' }, items.map(function (it) {
                if (it === '-') { return el('li', {}, [el('hr', { class: 'dropdown-divider' })]); }
                var node = it.href
                    ? el('a', { class: 'dropdown-item' + (it.danger ? ' text-danger' : ''), href: it.href }, [u.icon(it.icon + ' fa-fw me-2'), it.label])
                    : el('button', { type: 'button', class: 'dropdown-item' + (it.danger ? ' text-danger' : ''), on: { click: it.onClick } }, [u.icon(it.icon + ' fa-fw me-2'), it.label]);
                return el('li', {}, [node]);
            }));
            return el('div', { class: 'dropdown' }, [
                el('button', { type: 'button', class: 'btn btn-sm btn-ghost-secondary btn-icon', 'aria-label': label, 'aria-expanded': 'false', dataset: { bsToggle: 'dropdown', bsPopperConfig: '{"strategy":"fixed"}' } }, [u.icon('fas fa-ellipsis-v')]),
                menu
            ]);
        }
        function clear(node) { while (node && node.firstChild) { node.removeChild(node.firstChild); } }
        function setUrl(params) {
            var qs = new URLSearchParams();
            Object.keys(params).forEach(function (k) { if (params[k] !== null && params[k] !== undefined && params[k] !== '') { qs.set(k, String(params[k])); } });
            try { window.history.replaceState(null, '', window.location.pathname + (qs.toString() ? '?' + qs.toString() : '')); } catch (e) { /* ignore */ }
        }

        // ---- header actions --------------------------------------------------------------
        var assignBtn = $('tro-assign');
        if (assignBtn) {
            assignBtn.hidden = routes.assign_manual === false;
            assignBtn.addEventListener('click', function () { openAssign([]); });
        }
        function openAssign(people, courseId) {
            Ops.open('assign', { people: people, courseId: courseId }).then(function (res) {
                if (res && Array.isArray(res.outcomes) && res.outcomes.length && assignments) { assignments.refetch(); }
            });
        }
        var recalc = $('tro-recalc');
        if (recalc) {
            recalc.hidden = routes.reconcile_now === false;
            recalc.addEventListener('click', function () {
                u.busy(recalc, true, 'Recalculating…');
                u.post('reconcile_now', {}).then(function (r) {
                    u.busy(recalc, false);
                    r = r || {};
                    if (r.skipped_busy) {
                        UI.toast('A recalculation is already running. Try again in a minute.', { type: 'warning' });
                        return;
                    }
                    var bits = [];
                    if (r.created) { bits.push(r.created + ' assigned'); }
                    if (r.reopened) { bits.push(r.reopened + ' reopened'); }
                    if (r.completed) { bits.push(r.completed + ' completed'); }
                    if (r.cancelled) { bits.push(r.cancelled + ' no longer required'); }
                    UI.toast(bits.length ? 'Recalculated: ' + bits.join(', ') + '.' : 'Recalculated. Everything was already up to date.', { type: r.failed_chunks ? 'warning' : 'success' });
                    if (assignments) { assignments.refetch(); }
                    if (rules) { rules.refetch(); }
                }, function (err) {
                    u.busy(recalc, false);
                    UI.toast(u.errorText(err), { type: 'error' });
                });
            });
        }

        // ============================================================================
        // Assignments tab
        // ============================================================================
        var assignments = null;
        if ($('tro-a-body')) {
            assignments = (function () {
                var f = Object.assign({ status: 'open', page: 1 }, D.filters || {});
                var body = $('tro-a-body'), empty = $('tro-a-empty'), pager = $('tro-a-pager'), seg = $('tro-a-status');
                var q = $('tro-a-q'), dept = $('tro-a-dept'), courseSel = $('tro-a-course'), chips = $('tro-a-chips');
                var counts = {};
                var lastRows = [];
                var seq = 0;
                var flashId = null;
                var STATUSES = [
                    ['overdue', 'Overdue'], ['lapsed', 'Not qualified'], ['due_soon', 'Due soon'], ['open', 'Open'], ['waived', 'Waived'],
                    ['completed', 'Completed'], ['cancelled', 'Cancelled'], ['cancelled_overdue', 'Cancelled while overdue'], ['all', 'All']
                ];

                function renderSeg() {
                    clear(seg);
                    STATUSES.forEach(function (s) {
                        var n = counts[s[0]];
                        // "Not qualified" (open renewals whose certificate already expired) shows only when there are some.
                        if (s[0] === 'lapsed' && !n && f.status !== 'lapsed') { return; }
                        seg.appendChild(el('button', {
                            type: 'button', class: 'tro-seg__btn tro-seg__btn--' + s[0], 'aria-pressed': f.status === s[0] ? 'true' : 'false',
                            title: s[0] === 'cancelled_overdue' ? 'Assignments that were cancelled after their due date had passed'
                                : (s[0] === 'lapsed' ? 'Renewals whose certificate has already expired: not qualified until renewed, even when the renewal is not due yet' : null),
                            on: { click: function () { if (f.status !== s[0]) { f.status = s[0]; f.page = 1; refetch(); } } }
                        }, [['overdue', 'lapsed', 'due_soon', 'open', 'completed'].indexOf(s[0]) !== -1 ? el('span', { class: 'tro-seg__dot', 'aria-hidden': 'true' }) : null,
                            el('span', { text: s[1] }), n !== undefined && n !== null ? el('span', { class: 'tro-seg__n', text: String(n) }) : null]));
                    });
                }
                function renderChips() {
                    clear(chips);
                    if (f.requirement_id) {
                        var rname = D.rule_filter && D.rule_filter.state === 'ok' && D.rule_filter.data ? D.rule_filter.data.name : null;
                        if (!rname) { lastRows.some(function (r) { if (r.requirement && Number(r.requirement.id) === Number(f.requirement_id)) { rname = r.requirement.name; return true; } return false; }); }
                        chips.appendChild(filterChip('Rule: ' + (rname || '#' + f.requirement_id), function () { f.requirement_id = null; f.page = 1; refetch(); }));
                    }
                    if (f.contact_id) {
                        var pname = null;
                        lastRows.some(function (r) { if (r.person && Number(r.person.contact_id) === Number(f.contact_id)) { pname = r.person.name; return true; } return false; });
                        chips.appendChild(filterChip('Person: ' + (pname || '#' + f.contact_id), function () { f.contact_id = null; f.page = 1; refetch(); }));
                    }
                }
                function filterChip(text, onClear) {
                    return el('span', { class: 'tro-chip tro-chip--accent' }, [el('span', { text: text }),
                        el('button', { type: 'button', class: 'btn-close btn-close-sm ms-1', style: { fontSize: '9px' }, 'aria-label': 'Clear filter ' + text, on: { click: onClear } })]);
                }
                function params() {
                    var p = {};
                    ['status', 'course_id', 'client_id', 'requirement_id', 'contact_id', 'q', 'page'].forEach(function (k) {
                        if (f[k] !== null && f[k] !== undefined && f[k] !== '') { p[k] = f[k]; }
                    });
                    if (p.page === 1) { delete p.page; }
                    return p;
                }
                function refetch() {
                    var mySeq = ++seq;
                    renderSeg();
                    u.skeletonRows(body, 8, 6);
                    clear(empty);
                    var p = params();
                    setUrl(p);
                    return u.fetchAction('assignment_list', p).then(function (d) {
                        if (mySeq === seq) { render(d || {}); }
                    }, function (err) {
                        if (mySeq === seq) { fail(err); }
                    });
                }
                function fail(err) {
                    clear(body);
                    clear(empty);
                    pager.hidden = true;
                    empty.appendChild(u.failState(err, refetch));
                }
                function render(d) {
                    var rows = d.rows || [];
                    lastRows = rows;
                    counts = d.counts || {};
                    renderSeg();
                    renderChips();
                    clear(body);
                    clear(empty);
                    rows.forEach(function (a) { body.appendChild(row(a)); });
                    if (!rows.length) {
                        var filtered = !!(f.q || f.course_id || f.client_id !== null && f.client_id !== undefined && f.client_id !== '' || f.requirement_id || f.contact_id);
                        if (D.scope === 'none') {
                            empty.appendChild(u.emptyState({ icon: 'fas fa-user-lock', title: 'No people to show', text: 'Ask an administrator to grant department access to see people.' }));
                        } else if (filtered) {
                            empty.appendChild(u.emptyState({ icon: 'fas fa-filter', title: 'No assignments match', text: 'Try another status, or clear the filters.',
                                actions: [el('button', { type: 'button', class: 'btn btn-outline-secondary', text: 'Clear filters', on: { click: clearFilters } })] }));
                        } else if (f.status === 'overdue') {
                            empty.appendChild(u.emptyState({ icon: 'fas fa-check-circle', title: 'Nothing overdue', text: 'Everyone is on time with their required training.' }));
                        } else if (f.status === 'open' && !Object.keys(counts).some(function (k) { return counts[k] > 0; })) {
                            empty.appendChild(u.emptyState({ icon: 'fas fa-clipboard-check', title: 'No assignments yet', text: 'Assignments appear when a rule requires a course, or when you assign training by hand.',
                                actions: [level >= 3 ? el('a', { class: 'btn btn-primary', href: '/agent/training_rule.php', text: 'Create a rule' }) : null,
                                    level >= 2 && routes.assign_manual !== false ? el('button', { type: 'button', class: 'btn btn-outline-secondary', text: 'Assign training', on: { click: function () { openAssign([]); } } }) : null].filter(Boolean) }));
                        } else {
                            empty.appendChild(u.emptyState({ icon: 'fas fa-inbox', title: 'Nothing here', text: 'No assignments have this status.' }));
                        }
                    }
                    renderPager(Number(d.total || 0), rows.length);
                    if (flashId) {
                        var tr = body.querySelector('tr[data-id="' + flashId + '"]');
                        if (tr) { tr.classList.add('is-flash'); }
                        flashId = null;
                    }
                }
                function renderPager(total, shown) {
                    clear(pager);
                    var per = 50;
                    var pageNo = Number(f.page || 1);
                    pager.hidden = total === 0;
                    if (!total) { return; }
                    var from = (pageNo - 1) * per + 1;
                    pager.appendChild(el('span', { text: 'Showing ' + from + '–' + (from + shown - 1) + ' of ' + total }));
                    if (total > per) {
                        var prev = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', disabled: pageNo <= 1, on: { click: function () { f.page = pageNo - 1; refetch(); } } }, [u.icon('fas fa-chevron-left me-1'), 'Previous']);
                        var next = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', disabled: pageNo * per >= total, on: { click: function () { f.page = pageNo + 1; refetch(); } } }, ['Next', u.icon('fas fa-chevron-right ms-1')]);
                        pager.appendChild(el('div', { class: 'btn-group' }, [prev, next]));
                    }
                }
                function row(a) {
                    var dueCell = el('td', { class: 'tro-nowrap' }, [el('span', { text: u.fmtDate(a.due_on) })]);
                    if (a.original_due_on && a.original_due_on !== a.due_on) {
                        dueCell.title = 'Originally due ' + u.fmtDate(a.original_due_on);
                        dueCell.appendChild(el('span', { class: 'tro-sub', text: 'moved from ' + u.fmtDate(a.original_due_on, true) }));
                    } else if (a.status === 'completed' && a.closed_at) {
                        dueCell.appendChild(el('span', { class: 'tro-sub', text: 'done ' + u.fmtDateTime(a.closed_at).replace(/,? \d{1,2}:\d{2}.*$/, '') }));
                    }
                    var open = a.status === 'open';
                    var who = (a.person && a.person.name) || 'this person';
                    var actions = level >= 2 ? [
                        open && routes.assignment_extend !== false ? { label: 'Extend…', icon: 'far fa-calendar-plus', onClick: function () { act('extend', a); } } : null,
                        open && routes.assignment_waive !== false ? { label: 'Waive…', icon: 'fas fa-pause-circle', onClick: function () { act('waive', a); } } : null,
                        open ? '-' : null,
                        { label: 'History', icon: 'fas fa-history', onClick: function () { Ops.open('history', { assignmentId: a.id, assignment: a }); } }
                    ] : [{ label: 'History', icon: 'fas fa-history', onClick: function () { Ops.open('history', { assignmentId: a.id, assignment: a }); } }];
                    if (actions[0] === null && actions[1] === null) { actions = actions.filter(function (x) { return x !== '-'; }); }
                    var reason = el('td', {}, [el('span', { text: u.readableDates(a.anchor_label || '') })]);
                    if (a.requirement && a.requirement.is_manual && a.anchor === 'initial') { reason.appendChild(el('span', { class: 'tro-sub', text: 'Assigned by hand' })); }
                    return el('tr', { dataset: { id: a.id } }, [
                        el('td', {}, [u.personCell(a.person, { sub: a.person ? (a.person.title || '') : '' })]),
                        el('td', { text: a.person && a.person.department ? a.person.department.name : 'No department' }),
                        el('td', {}, [el('span', { class: 'fw-medium', text: a.course ? a.course.name : '' }),
                            a.required === false ? el('span', { class: 'tro-sub' }, [u.chip('Optional', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm' })]) : null]),
                        reason,
                        dueCell,
                        el('td', {}, [u.statusChip(a.display_status || a.status, a, { days: false })]),
                        el('td', { class: 'tro-num', text: a.days_overdue > 0 ? String(a.days_overdue) : '—' }),
                        el('td', { class: 'tro-actions' }, [kebab('Actions for ' + who + ', ' + (a.course ? a.course.name : ''), actions)])
                    ]);
                }
                function act(kind, a) {
                    Ops.open(kind, { assignment: a }).then(function (res) {
                        if (res) { flashId = a.id; refetch(); }
                    });
                }
                function clearFilters() {
                    f.q = null; f.course_id = null; f.client_id = null; f.requirement_id = null; f.contact_id = null; f.page = 1;
                    q.value = ''; dept.value = ''; courseSel.value = '';
                    refetch();
                }
                var onSearch = UI.debounce(function () { f.q = q.value.trim() || null; f.page = 1; refetch(); }, 300);
                q.addEventListener('input', onSearch);
                dept.addEventListener('change', function () { f.client_id = dept.value === '' ? null : Number(dept.value); f.page = 1; refetch(); });
                courseSel.addEventListener('change', function () { f.course_id = courseSel.value ? Number(courseSel.value) : null; f.page = 1; refetch(); });
                $('tro-a-filters').addEventListener('submit', function (e) { e.preventDefault(); onSearch.flush(); });

                renderSeg();
                u.skeletonRows(body, 8, 6);
                u.load(D.list).then(render, fail);
                return { refetch: refetch };
            })();
        }

        // ============================================================================
        // Rules tab
        // ============================================================================
        var rules = null;
        if ($('tro-r-body')) {
            rules = (function () {
                var view = D.rule_view || 'active';
                var body = $('tro-r-body'), empty = $('tro-r-empty'), seg = $('tro-r-view');
                var all = [];
                var seq = 0;
                var VIEWS = [['active', 'Active'], ['manual', 'Made by hand'], ['archived', 'Archived']];

                function renderSeg() {
                    clear(seg);
                    VIEWS.forEach(function (v) {
                        seg.appendChild(el('button', { type: 'button', class: 'tro-seg__btn', 'aria-pressed': view === v[0] ? 'true' : 'false',
                            on: { click: function () { if (view !== v[0]) { view = v[0]; refetch(); } } } }, [el('span', { text: v[1] })]));
                    });
                }
                function refetch() {
                    var mySeq = ++seq;
                    renderSeg();
                    u.skeletonRows(body, 7, 4);
                    clear(empty);
                    setUrl({ tab: 'rules', view: view === 'active' ? null : view });
                    var p = view === 'manual' ? { include_archived: 1, manual: 1 } : { include_archived: 1 };
                    return u.fetchAction('rule_list', p).then(function (d) { if (mySeq === seq) { render(d || {}); } }, function (err) {
                        if (mySeq === seq) { clear(body); clear(empty); empty.appendChild(u.failState(err, refetch)); }
                    });
                }
                function visible(r) {
                    if (view === 'archived') { return !!r.archived; }
                    if (view === 'manual') { return !r.archived && !!r.is_manual; }
                    return !r.archived && !r.is_manual;
                }
                function render(d) {
                    all = d.rules || [];
                    var list = all.filter(visible);
                    clear(body);
                    clear(empty);
                    list.forEach(function (r) { body.appendChild(row(r)); });
                    if (!list.length) {
                        if (view === 'active') {
                            empty.appendChild(u.emptyState({ icon: 'fas fa-clipboard-check', title: 'No rules yet', text: 'A rule says who must complete a course, for example "Everyone in Fabrication completes Lockout/Tagout within 30 days".',
                                actions: level >= 3 ? [el('a', { class: 'btn btn-primary', href: '/agent/training_rule.php' }, [u.icon('fas fa-plus me-2'), 'Create your first rule'])] : [] }));
                        } else if (view === 'manual') {
                            empty.appendChild(u.emptyState({ icon: 'fas fa-hand-pointer', title: 'Nothing assigned by hand', text: 'Each use of Assign training is kept here as a one-time rule.' }));
                        } else {
                            empty.appendChild(u.emptyState({ icon: 'fas fa-archive', title: 'No archived rules' }));
                        }
                    }
                }
                function critChips(r) {
                    var wrap = el('div', { class: 'tro-chips' });
                    if (r.all_people) { wrap.appendChild(el('span', { class: 'tro-crit' }, [u.icon('fas fa-globe me-1'), 'Everyone'])); }
                    var labels = r.labels || {};
                    ['department', 'odoo_job', 'odoo_location', 'jobgroup'].forEach(function (k) {
                        var l = labels[k] || [];
                        if (!l.length) { return; }
                        var names = l.map(function (x) { return x.name || ('#' + x.id); });
                        var text = names.length > 3 ? names.slice(0, 3).join(', ') + ' +' + (names.length - 3) : names.join(', ');
                        var unknown = l.some(function (x) { return x.known === false; });
                        wrap.appendChild(el('span', { class: 'tro-crit' + (unknown ? ' tro-crit--warn' : ''), title: ({ department: 'Department', odoo_job: 'Job position', odoo_location: 'Work location', jobgroup: 'Job group' })[k] + ': ' + names.join(', ') + (unknown ? ' (some are no longer in Odoo)' : '') },
                            [unknown ? u.icon('fas fa-exclamation-triangle me-1') : null, text]));
                    });
                    var people = (labels.contact || []).length + Number(r.contact_hidden || 0);
                    if (people) { wrap.appendChild(el('span', { class: 'tro-crit', title: (labels.contact || []).map(function (x) { return x.name || ('#' + x.id); }).join(', ') }, [u.icon('fas fa-user me-1'), u.plural(people, 'person', 'people')])); }
                    if (r.new_hires_only) { wrap.appendChild(u.chip('New hires', 'info', null, { class: 'tro-chip tro-chip--info tro-chip--sm' })); }
                    if (r.required === false) { wrap.appendChild(u.chip('Optional', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm' })); }
                    return wrap;
                }
                function policy(r) {
                    var lines = [];
                    if (r.is_manual) { lines.push('Due ' + u.fmtDate(r.baseline_due_on)); }
                    else if (r.new_hires_only) { lines.push('New hires: ' + u.plural(Number(r.due_days_from_hire || 0), 'day') + ' after hire'); }
                    else {
                        lines.push('Current staff: within ' + u.plural(Number(r.due_days || 0), 'day') + (r.baseline_due_on ? ' (' + u.fmtDate(r.baseline_due_on, true) + ')' : ''));
                        lines.push('New hires: ' + u.plural(Number(r.due_days_from_hire || 0), 'day') + ' after hire');
                    }
                    if (r.one_time) { lines.push('Once only, no renewals'); }
                    return el('div', { class: 'tro-policy' }, lines.map(function (t) { return el('div', { text: t }); }));
                }
                function row(r) {
                    var s = r.stats || {};
                    var tr = el('tr', { dataset: { id: r.id } }, [
                        el('td', {}, [el('a', { class: 'tro-link-strong', href: '/agent/training_rule.php?id=' + encodeURIComponent(r.id), text: r.name }),
                            r.note ? el('span', { class: 'tro-sub', text: r.note }) : null,
                            r.archived ? u.chip('Archived', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm mt-1' }) : null]),
                        el('td', {}, [el('span', { text: r.course ? r.course.name : '' }), r.course && r.course.published === false ? el('span', { class: 'tro-sub text-warning', text: 'Not published' }) : null]),
                        el('td', {}, [critChips(r)]),
                        el('td', {}, [el('span', { class: 'tro-stat-trio' }, [
                            el('span', { title: 'People this rule matches' + (D.scope !== 'all' ? ' in your departments' : '') }, [el('b', { text: String(Number(s.matched || 0)) }), ' matched']),
                            el('a', { href: '/agent/training_assignments.php?requirement_id=' + encodeURIComponent(r.id), title: 'Open assignments' }, [el('b', { text: String(Number(s.open || 0)) }), ' open']),
                            el('span', { class: Number(s.overdue || 0) > 0 ? 'is-bad' : null }, [el('b', { text: String(Number(s.overdue || 0)) }), ' overdue'])
                        ])]),
                        el('td', {}, [policy(r)]),
                        el('td', { class: 'tro-nowrap' }, [el('span', { text: r.created_by_name || '' }), el('span', { class: 'tro-sub', text: r.created_at ? u.fmtDate(String(r.created_at).slice(0, 10)) : '' })]),
                        el('td', { class: 'tro-actions' }, [kebab('Actions for rule ' + r.name, [
                            { label: 'Open', icon: 'fas fa-external-link-alt', href: '/agent/training_rule.php?id=' + encodeURIComponent(r.id) },
                            { label: 'View assignments', icon: 'fas fa-list', href: '/agent/training_assignments.php?requirement_id=' + encodeURIComponent(r.id) + '&status=all' },
                            level >= 3 && !r.archived && routes.rule_archive !== false ? '-' : null,
                            level >= 3 && !r.archived && routes.rule_archive !== false ? { label: 'Archive…', icon: 'fas fa-archive', danger: true, onClick: function () { archive(r, tr); } } : null
                        ])])
                    ]);
                    return tr;
                }
                function archive(r, tr) {
                    var old = body.querySelector('tr.tro-inline-row');
                    if (old) { old.parentNode.removeChild(old); }
                    var reason = u.reasonInput(5, 255, 'Why is this rule no longer needed?');
                    var yes = el('button', { type: 'button', class: 'btn btn-sm btn-danger', text: 'Archive rule', disabled: true });
                    var no = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel' });
                    var msg = el('div', { class: 'small fw-semibold' });
                    var bar = el('div', { class: 'tr-confirm-bar tro-reason-bar alert alert-warning mb-0', role: 'alertdialog', 'aria-label': 'Archive rule ' + r.name }, [
                        el('div', { class: 'fw-semibold', text: 'Archive "' + r.name + '"?' }),
                        el('div', { class: 'small', text: 'It stops assigning ' + (r.course ? r.course.name : 'the course') + '. Open assignments that no other rule requires are cancelled; completed records stay.' }),
                        reason.input, reason.counter, msg, el('div', { class: 'tro-reason-bar__row' }, [el('span', { class: 'me-auto' }), no, yes])
                    ]);
                    var inline = el('tr', { class: 'tro-inline-row' }, [el('td', { colspan: '7', class: 'tro-inline-cell' }, [bar])]);
                    tr.parentNode.insertBefore(inline, tr.nextSibling);
                    reason.input.focus();
                    reason.input.addEventListener('input', function () { yes.disabled = !reason.ok(); });
                    function close() { if (inline.parentNode) { inline.parentNode.removeChild(inline); } }
                    no.addEventListener('click', close);
                    bar.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
                    yes.addEventListener('click', function () {
                        u.busy(yes, true, 'Archiving…');
                        u.post('rule_archive', { requirement_id: r.id, reason: reason.input.value.trim() }).then(function (d) {
                            var rec = (d && d.reconcile) || {};
                            UI.toast(rec.error ? 'Rule archived. Assignments will update on the next recalculation.' : 'Rule archived.', { type: rec.error ? 'warning' : 'success' });
                            refetch();
                        }, function (err) {
                            u.busy(yes, false);
                            msg.textContent = u.errorText(err);
                        });
                    });
                }
                renderSeg();
                u.skeletonRows(body, 7, 4);
                if (new URLSearchParams(window.location.search).get('archived') === '1') { UI.toast('Rule archived.'); setUrl({ tab: 'rules' }); }
                u.load(D.rules).then(render, function (err) { clear(body); empty.appendChild(u.failState(err, refetch)); });
                return { refetch: refetch };
            })();
        }

        // ---- ?assign=<contact_id>: open "Assign training" with that person -------------
        if (D.assign && level >= 2 && routes.assign_manual !== false) {
            var pre = D.assign_person;
            var go = function (person) { openAssign(person ? [person] : [], null); };
            if (pre && pre.state === 'ok' && pre.data && pre.data.person) {
                go(pre.data.person);
            } else {
                u.fetchAction('person_status', { contact_id: D.assign }).then(function (d) { go(d && d.person); }, function () { go(null); });
            }
        }
    });
})();
