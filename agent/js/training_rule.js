/*
 * Training › Assignments › Rule editor (Phase 2 spec §5.2, mockup Admin-AssignmentRule).
 *
 * Built for a Safety Manager, not a database: pick a course, say who in plain terms
 * ("Department is any of Fabrication, CNC Machining"), see who matches live, read the rule
 * back as a sentence, save. Criteria are AND across kinds and OR within a kind; "Everyone on
 * the roster" is exclusive. rule_preview is debounced 400 ms and stale answers are dropped.
 * Save sends the request_uid made on page load (a double click or a retry cannot create two
 * rules) and the version for an edit (409 conflict -> reload prompt).
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var UI = window.TrainingUi;
        var Ops = window.TrainingOps;
        if (!UI || !Ops || !document.getElementById('tro-rule-page')) { return; }
        var u = Ops.u;
        var el = u.el;
        var D = UI.readJson('tr-page-data');
        Ops.init(D);

        var $ = function (id) { return document.getElementById(id); };
        var form = $('tro-rule-form');
        var alerts = $('tro-rule-alerts');
        var previewEl = $('tro-rule-preview');
        var today = u.today();

        var KIND_ORDER = ['department', 'odoo_job', 'odoo_location', 'jobgroup', 'contact'];
        var KINDS = {
            department: { label: 'Department', icon: 'fas fa-sitemap', add: 'People in one or more departments', noun: 'departments' },
            odoo_job: { label: 'Job position', icon: 'fas fa-hard-hat', add: 'Job positions from Odoo, like Welder', noun: 'job positions' },
            odoo_location: { label: 'Work location', icon: 'fas fa-map-marker-alt', add: 'Work locations from Odoo, like Main Shop', noun: 'work locations' },
            jobgroup: { label: 'Job group', icon: 'fas fa-users-cog', add: 'Your own groups of titles or people', noun: 'job groups' },
            contact: { label: 'Specific people', icon: 'fas fa-user', add: 'Pick people by name', noun: 'people' }
        };

        // ---- option lists ------------------------------------------------------------------
        function listFrom(spec, key) {
            if (!spec || spec.state !== 'ok' || !spec.data) { return null; }
            var v = spec.data[key];
            return Array.isArray(v) ? v : null;
        }
        var OPTS = {
            department: (D.departments || []).map(function (d) { return { id: Number(d.id), name: d.name, count: d.people }; }),
            odoo_job: null, odoo_location: null, jobgroup: null
        };
        function setAttrOptions(data) {
            OPTS.odoo_job = ((data && data.jobs) || []).map(function (j) { return { id: Number(j.id), name: j.name, count: j.count }; });
            OPTS.odoo_location = ((data && data.locations) || []).map(function (j) { return { id: Number(j.id), name: j.name, count: j.count }; });
        }
        function setGroups(data) {
            var g = (data && (data.groups || data.rows || data.job_groups)) || (Array.isArray(data) ? data : []);
            OPTS.jobgroup = g.filter(function (x) { return !x.archived; }).map(function (x) {
                return { id: Number(x.id), name: x.name, count: x.member_count !== undefined ? x.member_count : x.members_count };
            });
        }
        if (listFrom(D.attr_options, 'jobs') !== null) { setAttrOptions(D.attr_options.data); }
        if (D.jobgroups && D.jobgroups.state === 'ok') { setGroups(D.jobgroups.data); }

        // ---- state -------------------------------------------------------------------------
        var st = {
            id: D.rule_id || null, version: null, isNew: !D.rule_id, requestUid: u.uid32(),
            canEdit: !!D.can_edit, readOnly: !D.can_edit, archived: false, manual: false,
            name: '', nameTouched: false,
            courseId: null, courseLocked: false,
            allPeople: false,
            kinds: [],
            values: { department: [], odoo_job: [], odoo_location: [], jobgroup: [], contact: [] },
            labels: {},                 // kind -> {id: {name, known}} from a saved rule
            contactPeople: [],          // PersonRef for the contact kind
            contactHidden: 0,
            newHiresOnly: false,
            dueDays: 30, baseline: u.addDays(today, 30), hireDays: 7,
            required: true, oneTime: false, note: '',
            effectiveOn: today,
            stats: null,
            preview: null, previewSeq: 0, previewState: 'idle', previewError: null,
            dirty: false, saving: false
        };
        var rows = {};   // kind -> {root, ts, picker}

        // ---- helpers -----------------------------------------------------------------------
        function course() { return st.courseId ? u.courseById(st.courseId) : null; }
        function optName(kind, id) {
            var list = OPTS[kind] || [];
            for (var i = 0; i < list.length; i++) { if (list[i].id === Number(id)) { return list[i].name; } }
            var l = st.labels[kind] && st.labels[kind][id];
            return l ? l.name : (KINDS[kind].label + ' #' + id);
        }
        function isKnown(kind, id) {
            if (kind === 'contact' || kind === 'department') { return true; }
            var l = st.labels[kind] && st.labels[kind][id];
            if (l && l.known === false) { return false; }
            var list = OPTS[kind];
            if (list === null) { return true; }   // options not loaded: cannot judge
            return list.some(function (o) { return o.id === Number(id); });
        }
        function activeKinds() { return st.allPeople ? [] : st.kinds.filter(function (k) { return st.values[k].length > 0; }); }
        function criteria() {
            var c = { department: [], odoo_job: [], odoo_location: [], jobgroup: [], contact: [] };
            if (!st.allPeople) {
                st.kinds.forEach(function (k) { c[k] = st.values[k].map(Number); });
            }
            return c;
        }
        function hasCriteria() { return st.allPeople || activeKinds().length > 0; }
        function joinOr(names) {
            if (names.length <= 1) { return names.join(''); }
            if (names.length === 2) { return names[0] + ' or ' + names[1]; }
            return names.slice(0, -1).join(', ') + ' or ' + names[names.length - 1];
        }
        function joinAnd(parts) {
            if (parts.length <= 1) { return parts.join(''); }
            return parts.slice(0, -1).join(', ') + (parts.length > 2 ? ',' : '') + ' and ' + parts[parts.length - 1];
        }
        function shortList(names, max) {
            if (names.length <= max) { return names; }
            return names.slice(0, max).concat([(names.length - max) + ' more']);
        }
        function markDirty() {
            st.dirty = true;
            refreshName();
            refreshSaveBar();
            schedulePreview();
        }

        // ---- the sentence ------------------------------------------------------------------
        function whoPhrase() {
            if (st.allPeople) { return 'Everyone on the training roster'; }
            var kinds = activeKinds();
            if (!kinds.length) { return ''; }
            var contactNames = st.contactPeople.map(function (p) { return p.name; });
            if (kinds.length === 1 && kinds[0] === 'contact') {
                return joinAnd(shortList(contactNames, 3)) + (st.contactHidden ? ' (and ' + u.plural(st.contactHidden, 'person', 'people') + ' outside your departments)' : '');
            }
            var parts = kinds.map(function (k) {
                var names = st.values[k].map(function (id) { return optName(k, id); });
                switch (k) {
                    case 'department': return 'is in ' + joinOr(shortList(names, 4));
                    case 'odoo_job': return 'works as ' + joinOr(shortList(names, 4));
                    case 'odoo_location': return 'is based at ' + joinOr(shortList(names, 4));
                    case 'jobgroup': return 'is in the ' + joinOr(shortList(names, 4)) + ' job group' + (names.length > 1 ? 's' : '');
                    case 'contact': return 'is one of the ' + u.plural(names.length, 'person', 'people') + ' picked by name';
                }
                return '';
            });
            return 'Everyone who ' + joinAnd(parts);
        }
        function sentence() {
            var c = course();
            var who = whoPhrase();
            if (!c || !who) { return null; }
            var s = who + (st.newHiresOnly ? ', once they are hired (hire date on or after ' + u.fmtDate(st.effectiveOn) + '),' : '');
            s += ' must ' + (c.kind === 'document' ? 'read and sign ' : 'complete ') + c.name + '.';
            if (!st.required) { s += ' It is optional: it shows on the kiosk but does not count toward compliance.'; }
            if (st.newHiresOnly) {
                s += ' Each new hire is due ' + u.plural(st.hireDays, 'day') + ' after their hire date.';
            } else {
                s += ' People on file today are due by ' + u.fmtDate(st.baseline) + '; anyone hired from now on is due ' + u.plural(st.hireDays, 'day') + ' after their hire date.';
            }
            if (c.validity_months) {
                s += st.oneTime ? ' The certificate lasts ' + u.plural(c.validity_months, 'month') + ', but this rule does not assign renewals.'
                    : ' It must be renewed every ' + u.plural(c.validity_months, 'month') + '.';
            }
            return s;
        }
        function autoName() {
            var c = course();
            if (!c) { return ''; }
            var who;
            if (st.allPeople) { who = 'Everyone'; }
            else {
                var k = activeKinds()[0];
                if (!k) { return c.name; }
                var names = st.values[k].map(function (id) { return optName(k, id); });
                who = k === 'contact' ? shortList(st.contactPeople.map(function (p) { return p.name; }), 2).join(', ') : shortList(names, 2).join(', ');
            }
            var n = c.name + ': ' + who + (st.newHiresOnly ? ' (new hires)' : '');
            return n.length > 150 ? n.slice(0, 149) + '…' : n;
        }
        function refreshName() {
            var input = $('tro-rule-name');
            if (st.isNew && !st.nameTouched) {
                st.name = autoName();
                input.value = st.name;
            }
            $('tro-rule-name-hint').hidden = !st.isNew || st.nameTouched || st.readOnly;
        }

        // ---- 1 What ------------------------------------------------------------------------
        function renderCourse() {
            var host = $('tro-rule-course');
            while (host.firstChild) { host.removeChild(host.firstChild); }
            var c = course();
            if (c && !st.picking) {
                var isDoc = c.kind === 'document';
                var change = null;
                if (!st.readOnly) {
                    change = st.courseLocked
                        ? el('span', { class: 'tro-sub tro-course__change', text: 'Fixed after saving' })
                        : el('button', { type: 'button', class: 'btn btn-link tro-course__change', on: { click: function () { st.picking = true; renderCourse(); } } }, ['Change ', u.icon('fas fa-chevron-down ms-1')]);
                }
                host.appendChild(el('div', { class: 'tro-course' }, [
                    el('span', { class: 'tro-course__icon' + (isDoc ? ' tro-course__icon--document' : '') }, [u.icon(isDoc ? 'fas fa-file-signature' : 'fas fa-graduation-cap')]),
                    el('div', { class: 'tro-course__body' }, [
                        el('div', { class: 'tro-course__name' }, [el('span', { text: c.name }), u.chip(isDoc ? 'Document' : 'Training', 'outline', null, { class: 'tro-chip tro-chip--outline tro-chip--sm' }),
                            c.code ? el('span', { class: 'tro-mono text-muted', text: c.code }) : null]),
                        el('div', { class: 'tro-course__meta', text: u.courseSummary(c) })
                    ]),
                    change
                ]));
                return;
            }
            if (st.courseId && !c) {
                // A saved rule whose course is no longer published.
                host.appendChild(el('div', { class: 'tro-course-empty' }, [u.icon('fas fa-exclamation-triangle'), el('span', { text: 'This rule\'s course is not published any more, so the rule assigns nothing.' })]));
                return;
            }
            if (!D.courses || !D.courses.length) {
                host.appendChild(el('div', { class: 'tro-course-empty' }, [u.icon('fas fa-book'), el('span', { text: 'No published courses yet. Publish a course first, then come back to require it.' })]));
                return;
            }
            var sel = u.courseSelect({ id: 'tro-rule-course-select', value: st.courseId, placeholder: 'Choose a course or document…' });
            sel.classList.add('tro-course-pick');
            sel.setAttribute('aria-labelledby', 'tro-step-what');
            host.appendChild(sel);
            host.appendChild(el('div', { class: 'tro-hint', text: 'Only published courses can be required. The course cannot change after the rule is saved.' }));
            sel.addEventListener('change', function () {
                st.courseId = sel.value ? Number(sel.value) : null;
                st.picking = false;
                renderCourse();
                renderRenewal();
                markDirty();
            });
            if (st.picking) {
                host.appendChild(el('button', { type: 'button', class: 'btn btn-link px-0', text: 'Keep ' + (c ? c.name : 'the current course'), on: { click: function () { st.picking = false; renderCourse(); } } }));
                sel.focus();
            }
        }
        function renderRenewal() {
            var host = $('tro-rule-renewal');
            while (host.firstChild) { host.removeChild(host.firstChild); }
            var c = course();
            var onetime = $('tro-rule-onetime');
            if (!c) {
                host.appendChild(el('span', { class: 'text-muted', text: 'Choose a course first' }));
                onetime.disabled = true;
                return;
            }
            if (c.validity_months) {
                host.appendChild(el('span', { class: 'tro-renew', title: 'Renewal is assigned ' + u.plural(c.renewal_lead_days || 0, 'day') + ' before a certificate expires. Change the period on the course\'s Settings tab.' }, [
                    u.icon('fas fa-redo-alt'), el('b', { text: 'Every ' + u.plural(c.validity_months, 'month') }), el('span', { class: 'tro-renew__src', text: '(from course settings)' })
                ]));
                onetime.disabled = st.readOnly;
                $('tro-rule-onetime-hint').textContent = 'Do not assign a renewal when the certificate expires';
            } else {
                host.appendChild(el('span', { class: 'tro-renew' }, [u.icon('fas fa-infinity'), el('b', { text: 'Does not expire' }), el('span', { class: 'tro-renew__src', text: '(from course settings)' })]));
                onetime.disabled = true;
                $('tro-rule-onetime-hint').textContent = 'The course does not expire, so there is nothing to renew';
            }
        }

        // ---- 2 Who -------------------------------------------------------------------------
        function tsFor(kind, select) {
            if (!window.TomSelect) { return null; }
            return new window.TomSelect(select, {
                plugins: ['remove_button'],
                maxOptions: 500,
                hideSelected: true,
                closeAfterSelect: false,
                placeholder: 'Choose ' + KINDS[kind].noun + '…',
                render: {
                    option: function (d, escape) {
                        return '<div class="d-flex align-items-center gap-2"><span class="flex-grow-1">' + escape(d.text) + '</span>'
                            + (d.count !== undefined && d.count !== null && d.count !== '' ? '<span class="text-muted small">' + escape(String(d.count)) + '</span>' : '') + '</div>';
                    },
                    item: function (d, escape) {
                        return '<div' + (d.unknown ? ' class="text-warning" title="Not found in Odoo any more"' : '') + '>' + escape(d.text) + '</div>';
                    },
                    no_results: function () { return '<div class="no-results">No matches</div>'; }
                },
                onChange: function (vals) {
                    st.values[kind] = (Array.isArray(vals) ? vals : (vals ? [vals] : [])).map(Number).filter(function (n) { return n >= 0; });
                    renderWarn(kind);
                    markDirty();
                }
            });
        }
        function buildRow(kind) {
            var k = KINDS[kind];
            var valueHost = el('div', { class: 'tro-cond__value' });
            var warn = el('div', { class: 'tro-cond__warn' });
            var opLabel = el('span', { class: 'tro-cond__op', text: kind === 'contact' ? 'are any of' : 'is any of' });
            var removeBtn = st.readOnly ? el('span') : el('button', { type: 'button', class: 'tro-cond__remove', 'aria-label': 'Remove the ' + k.label + ' condition', on: { click: function () { removeKind(kind); } } }, [u.icon('fas fa-times')]);
            var root = el('div', { class: 'tro-cond', dataset: { kind: kind } }, [
                el('div', { class: 'tro-cond__label' }, [el('span', { class: 'tro-cond__kind', id: 'tro-cond-' + kind }, [u.icon(k.icon), k.label]), opLabel]),
                el('div', {}, [valueHost, warn]),
                removeBtn
            ]);
            var row = { root: root, ts: null, picker: null, warn: warn, op: opLabel };
            if (kind === 'contact') {
                row.picker = new Ops.PeoplePicker({
                    initial: st.contactPeople, placeholder: 'Type a name to add people',
                    onChange: function (people) {
                        st.contactPeople = people;
                        st.values.contact = people.map(function (p) { return Number(p.contact_id); });
                        markDirty();
                    }
                });
                row.picker.input.setAttribute('aria-labelledby', 'tro-cond-contact');
                valueHost.appendChild(row.picker.root);
                if (st.contactHidden) { warn.appendChild(u.chip('+' + u.plural(st.contactHidden, 'person', 'people') + ' outside your departments', 'outline', 'fas fa-eye-slash')); }
                if (st.readOnly) { row.picker.input.hidden = true; Array.prototype.forEach.call(row.picker.chips.querySelectorAll('button'), function (b) { b.hidden = true; }); }
                return row;
            }
            var list = OPTS[kind];
            if (list === null || (list.length === 0 && !st.values[kind].length)) {
                var msg = list === null
                    ? (kind === 'jobgroup' ? 'Job groups are not available yet.' : 'Odoo job and location data is not available yet. Run the directory sync first.')
                    : (kind === 'jobgroup' ? 'No job groups yet. Create them under People › Job groups.' : 'Nothing synced from Odoo yet. Run the directory sync first.');
                valueHost.appendChild(el('div', { class: 'form-control-plaintext text-muted small', text: msg }));
                return row;
            }
            var select = el('select', { multiple: true, 'aria-labelledby': 'tro-cond-' + kind });
            var have = {};
            list.forEach(function (o) {
                have[o.id] = true;
                select.appendChild(el('option', { value: String(o.id), text: o.name, dataset: { count: o.count === undefined || o.count === null ? '' : String(o.count) } }));
            });
            st.values[kind].forEach(function (id) {
                if (!have[id]) { select.appendChild(el('option', { value: String(id), text: optName(kind, id) + (isKnown(kind, id) ? '' : ' (not in Odoo)'), dataset: { unknown: '1' } })); }
            });
            st.values[kind].forEach(function (id) {
                Array.prototype.forEach.call(select.options, function (o) { if (Number(o.value) === Number(id)) { o.selected = true; } });
            });
            valueHost.appendChild(select);
            row.ts = tsFor(kind, select);
            if (row.ts) {
                Object.keys(row.ts.options).forEach(function (key) {
                    var opt = row.ts.options[key];
                    var src = list.filter(function (o) { return String(o.id) === key; })[0];
                    opt.count = src ? src.count : '';
                    opt.unknown = !src || !isKnown(kind, Number(key));
                });
                if (st.readOnly) { row.ts.lock(); }
            } else {
                select.classList.add('form-select');
                select.addEventListener('change', function () {
                    st.values[kind] = Array.prototype.filter.call(select.options, function (o) { return o.selected; }).map(function (o) { return Number(o.value); });
                    markDirty();
                });
                if (st.readOnly) { select.disabled = true; }
            }
            renderWarn(kind, row);
            return row;
        }
        function renderWarn(kind, rowArg) {
            var row = rowArg || rows[kind];
            if (!row || kind === 'contact') { return; }
            while (row.warn.firstChild) { row.warn.removeChild(row.warn.firstChild); }
            var unknown = st.values[kind].filter(function (id) { return !isKnown(kind, id); });
            if (unknown.length) {
                row.warn.appendChild(u.chip(u.plural(unknown.length, 'value') + ' no longer in Odoo', 'warn', 'fas fa-exclamation-triangle'));
            }
            row.op.textContent = (kind === 'odoo_location' && st.values[kind].length <= 1) ? 'is' : 'is any of';
        }
        function renderRows() {
            var host = $('tro-rule-rows');
            Object.keys(rows).forEach(function (k) { if (rows[k].ts) { rows[k].ts.destroy(); } });
            rows = {};
            while (host.firstChild) { host.removeChild(host.firstChild); }
            if (st.allPeople) {
                host.appendChild(el('div', { class: 'tro-cond tro-cond--everyone' }, [
                    el('div', { class: 'tro-cond__label' }, [el('span', { class: 'tro-cond__kind' }, [u.icon('fas fa-globe'), 'Everyone on the roster']),
                        el('span', { class: 'tro-cond__op', text: 'Every person on the training roster, in every department. Other conditions are turned off.' })]),
                    st.readOnly ? el('span') : el('button', { type: 'button', class: 'tro-cond__remove', 'aria-label': 'Turn off Everyone on the roster', on: { click: function () { st.allPeople = false; renderRows(); markDirty(); } } }, [u.icon('fas fa-times')])
                ]));
            }
            var shown = st.allPeople ? [] : st.kinds;
            shown.forEach(function (kind, i) {
                if (i > 0) { host.appendChild(el('div', { class: 'tro-and', 'aria-hidden': 'true' }, [el('span', { text: 'AND' })])); }
                var row = buildRow(kind);
                rows[kind] = row;
                host.appendChild(row.root);
            });
            if (!st.allPeople && !shown.length) {
                host.appendChild(el('div', { class: 'tro-course-empty' }, [u.icon('fas fa-user-plus'), el('span', { text: st.readOnly ? 'No conditions.' : 'Add a condition to say who this applies to, for example a department.' })]));
            }
            renderAddMenu();
        }
        function renderAddMenu() {
            var menu = $('tro-rule-add-menu');
            var btn = $('tro-rule-add');
            while (menu.firstChild) { menu.removeChild(menu.firstChild); }
            $('tro-rule-conds-foot').hidden = st.readOnly;
            if (st.readOnly) { return; }
            var free = KIND_ORDER.filter(function (k) { return st.kinds.indexOf(k) === -1; });
            if (!st.allPeople) {
                free.forEach(function (k) {
                    menu.appendChild(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { addKind(k); } } }, [
                        u.icon(KINDS[k].icon), el('span', {}, [KINDS[k].label, el('span', { class: 'tro-dd-hint', text: KINDS[k].add })])
                    ])]));
                });
                menu.appendChild(el('li', {}, [el('hr', { class: 'dropdown-divider' })]));
                menu.appendChild(el('li', {}, [el('button', { type: 'button', class: 'dropdown-item', on: { click: function () { st.allPeople = true; renderRows(); markDirty(); } } }, [
                    u.icon('fas fa-globe'), el('span', {}, ['Everyone on the roster', el('span', { class: 'tro-dd-hint', text: 'Every person on the training roster. Replaces the other conditions.' })])
                ])]));
            }
            btn.disabled = st.allPeople;
            $('tro-rule-conds-hint').textContent = st.allPeople ? '' : (st.kinds.length > 1 ? 'People must match every condition.' : '');
        }
        function addKind(kind) {
            if (st.kinds.indexOf(kind) !== -1) { return; }
            st.kinds.push(kind);
            st.kinds.sort(function (a, b) { return KIND_ORDER.indexOf(a) - KIND_ORDER.indexOf(b); });
            renderRows();
            var row = rows[kind];
            setTimeout(function () {
                if (row && row.ts) { row.ts.focus(); row.ts.open(); }
                else if (row && row.picker) { row.picker.input.focus(); }
            }, 30);
            markDirty();
        }
        function removeKind(kind) {
            st.kinds = st.kinds.filter(function (k) { return k !== kind; });
            st.values[kind] = [];
            if (kind === 'contact') { st.contactPeople = []; }
            renderRows();
            markDirty();
        }

        // ---- 3 When ------------------------------------------------------------------------
        var dueDays = $('tro-rule-due-days'), dueDate = $('tro-rule-due-date'), hireDays = $('tro-rule-hire-days');
        function clampDays(v) { var n = parseInt(v, 10); return isNaN(n) ? null : Math.max(0, Math.min(365, n)); }
        function renderWhen() {
            dueDays.value = String(st.dueDays);
            dueDate.value = st.baseline;
            dueDate.min = today;
            hireDays.value = String(st.hireDays);
            $('tro-rule-required').checked = st.required;
            $('tro-rule-onetime').checked = st.oneTime;
            $('tro-rule-newhires').checked = st.newHiresOnly;
            $('tro-rule-note').value = st.note;
            refreshStaffRow();
        }
        function refreshStaffRow() {
            var row = $('tro-rule-staff-row');
            var hint = $('tro-rule-staff-hint');
            row.classList.toggle('tro-when__row--muted', st.newHiresOnly);
            if (st.newHiresOnly) {
                hint.textContent = 'Not used: this rule only applies to people hired from now on.';
            } else if (st.baseline < today) {
                hint.textContent = 'This date has passed. Anyone matched from today is due ' + u.plural(st.dueDays, 'day') + ' after they match.';
            } else {
                hint.textContent = st.isNew ? 'People already on file when you save' : 'People on file when the rule was saved';
            }
        }
        dueDays.addEventListener('input', function () {
            var n = clampDays(dueDays.value);
            if (n === null) { return; }
            st.dueDays = n;
            st.baseline = u.addDays(today, n);
            dueDate.value = st.baseline;
            refreshStaffRow();
            markDirty();
        });
        dueDays.addEventListener('blur', function () { dueDays.value = String(st.dueDays); });
        dueDate.addEventListener('change', function () {
            if (!u.isYmd(dueDate.value)) { dueDate.value = st.baseline; return; }
            st.baseline = dueDate.value;
            var d = u.diffDays(today, st.baseline);
            if (d !== null && d >= 0) { st.dueDays = Math.min(365, d); dueDays.value = String(st.dueDays); }
            refreshStaffRow();
            markDirty();
        });
        hireDays.addEventListener('input', function () { var n = clampDays(hireDays.value); if (n !== null) { st.hireDays = n; markDirty(); } });
        hireDays.addEventListener('blur', function () { hireDays.value = String(st.hireDays); });
        $('tro-rule-required').addEventListener('change', function (e) { st.required = e.target.checked; markDirty(); });
        $('tro-rule-onetime').addEventListener('change', function (e) { st.oneTime = e.target.checked; markDirty(); });
        $('tro-rule-newhires').addEventListener('change', function (e) { st.newHiresOnly = e.target.checked; refreshStaffRow(); markDirty(); });
        $('tro-rule-note').addEventListener('input', function (e) { st.note = e.target.value; st.dirty = true; refreshSaveBar(); });
        $('tro-rule-name').addEventListener('input', function (e) { st.name = e.target.value; st.nameTouched = e.target.value.trim() !== ''; st.dirty = true; $('tro-rule-name-hint').hidden = true; refreshSaveBar(); });
        $('tro-rule-name').addEventListener('blur', function () { if ($('tro-rule-name').value.trim() === '') { st.nameTouched = false; refreshName(); refreshSaveBar(); } });

        // ---- preview -----------------------------------------------------------------------
        var previewTimer = null;
        function schedulePreview() {
            if (previewTimer) { clearTimeout(previewTimer); }
            if (!st.canEdit || st.readOnly) { renderPreview(); return; }
            if (!course() || !hasCriteria()) { st.preview = null; st.previewState = 'idle'; renderPreview(); return; }
            st.previewState = 'busy';
            renderPreviewState();
            previewTimer = setTimeout(runPreview, 400);
        }
        function previewPayload(extra) {
            var p = {
                course_id: st.courseId, all_people: st.allPeople, criteria: criteria(),
                new_hires_only: st.newHiresOnly, due_days: st.dueDays, baseline_due_on: st.baseline,
                due_days_from_hire: st.hireDays, required: st.required
            };
            if (st.id) { p.requirement_id = st.id; }
            return Object.assign(p, extra || {});
        }
        function runPreview() {
            previewTimer = null;
            var seq = ++st.previewSeq;
            window.TrainingApi.post('rule_preview', previewPayload()).then(function (d) {
                if (seq !== st.previewSeq) { return; }
                st.preview = d || null;
                st.previewState = 'ok';
                st.previewError = null;
                renderPreview();
            }, function (err) {
                if (seq !== st.previewSeq) { return; }
                if (err && err.status === 404 && /unknown action/i.test(err.message || '')) { err.code = 'unavailable'; }
                st.previewState = 'error';
                st.previewError = err;
                renderPreview();
            });
        }
        function renderPreviewState() {
            var live = previewEl.querySelector('.tro-live');
            if (live) {
                live.classList.toggle('is-busy', st.previewState === 'busy');
                live.classList.toggle('is-error', st.previewState === 'error');
                live.lastChild.textContent = st.previewState === 'busy' ? 'Updating…' : st.previewState === 'error' ? 'Not updated' : 'Updates as you edit';
            }
            previewEl.classList.toggle('is-stale', st.previewState === 'busy');
        }
        var OUTCOME = {
            assign: ['outline', 'fas fa-plus', 'Will assign'],
            current: ['ok', 'fas fa-check', 'Current'],
            assigned: ['info', null, 'Assigned'],
            waived: ['outline', 'fas fa-pause', 'Waived'],
            new_hire: ['warn', 'fas fa-user-clock', 'New hire']
        };
        function outcomeChip(s) {
            var m = OUTCOME[s.outcome] || ['outline', null, String(s.outcome || '')];
            var label = m[2];
            if ((s.outcome === 'assign' || s.outcome === 'new_hire') && s.due_on) { label += ' · ' + u.fmtDate(s.due_on, true); }
            return u.chip(label, m[0], m[1]);
        }
        var WARN_TEXT = {
            criteria_required: 'Add at least one condition, or choose Everyone on the roster.',
            everyone: 'This rule applies to everyone on the training roster.',
            unknown_odoo_id: 'Some job positions or work locations are no longer in Odoo. People cannot match them until they are fixed.',
            course_unpublished: 'This course is not published, so the rule would assign nothing.',
            baseline_past: 'The current-staff due date has passed. Anyone matched from today gets the "due within" days instead.'
        };
        function renderPreview() {
            while (previewEl.firstChild) { previewEl.removeChild(previewEl.firstChild); }
            var head = el('div', { class: 'tro-preview__head' }, [el('h2', { class: 'tro-preview__title', text: st.readOnly ? 'Who this rule covers' : 'Preview' })]);
            if (!st.readOnly) { head.appendChild(el('span', { class: 'tro-live', 'aria-hidden': 'true' }, [el('span', { text: 'Updates as you edit' })])); }
            previewEl.appendChild(head);

            if (st.readOnly && st.isNew) { previewEl.appendChild(el('div', { class: 'tro-preview__placeholder' }, [u.icon('fas fa-lock'), 'The preview is for people who create rules.'])); return; }
            if (st.readOnly) { renderStats(); return; }
            var c = course();
            var sent = sentence();
            if (!c) { previewEl.appendChild(el('div', { class: 'tro-preview__placeholder' }, [u.icon('fas fa-book-open'), 'Choose a course to see who this rule applies to.'])); return; }
            if (!hasCriteria()) {
                previewEl.appendChild(el('div', { class: 'tro-preview__placeholder' }, [u.icon('fas fa-users'), 'Say who this is for, and the people it matches appear here.']));
                previewEl.appendChild(el('ul', { class: 'tro-warnings' }, [el('li', {}, [u.icon('fas fa-exclamation-triangle'), el('span', { text: WARN_TEXT.criteria_required })])]));
                return;
            }
            var p = st.preview;
            if (st.previewState === 'error' && !p) {
                previewEl.appendChild(el('div', { class: 'tro-preview__placeholder' }, [u.icon('fas fa-plug'),
                    st.previewError && st.previewError.code === 'unavailable' ? 'The live preview is not available yet. You can still read the rule below.' : u.errorText(st.previewError)]));
                if (sent) { previewEl.appendChild(el('div', { class: 'tro-preview__body' }, [plainBox(sent)])); }
                if (!(st.previewError && st.previewError.code === 'unavailable')) {
                    previewEl.appendChild(el('div', { class: 'tro-preview__more' }, [el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Try again', on: { click: function () { schedulePreview(); } } })]));
                }
                renderPreviewState();
                return;
            }
            if (!p) {
                previewEl.appendChild(el('div', { class: 'tro-preview__count' }, [el('span', { class: 'tro-preview__n', text: '…' }), el('span', { class: 'tro-preview__label', text: 'people match' })]));
                previewEl.appendChild(el('div', { class: 'tro-preview__body' }, [el('div', { class: 'tro-meter' }), sent ? plainBox(sent) : null]));
                renderPreviewState();
                return;
            }
            var matched = Number(p.matched || 0);
            previewEl.appendChild(el('div', { class: 'tro-preview__count' }, [
                el('span', { class: 'tro-preview__n', text: String(matched) }),
                el('span', { class: 'tro-preview__label', text: matched === 1 ? 'person matches' : 'people match' })
            ]));
            var segs = [
                ['assign', Number(p.will_assign || 0), 'tro-meter__assign', 'will be assigned', p.due_on_current_staff ? 'due ' + u.fmtDate(p.due_on_current_staff) : ''],
                ['current', Number(p.already_current || 0), 'tro-meter__current', 'already current', 'renew on their own dates'],
                ['assigned', Number(p.already_assigned || 0), 'tro-meter__assigned', 'already assigned', 'keep the due date they have'],
                ['waived', Number(p.waived || 0), 'tro-meter__waived', 'waived', 'not asked while the waiver lasts']
            ];
            var total = segs.reduce(function (a, s) { return a + s[1]; }, 0) || 1;
            var meter = el('div', { class: 'tro-meter', role: 'img', 'aria-label': segs.filter(function (s) { return s[1] > 0; }).map(function (s) { return s[1] + ' ' + s[3]; }).join(', ') || 'Nobody matches' },
                segs.filter(function (s) { return s[1] > 0; }).map(function (s) { return el('span', { class: s[2], style: { width: (100 * s[1] / total) + '%' } }); }));
            var legend = el('ul', { class: 'tro-legend' }, segs.filter(function (s, i) { return s[1] > 0 || i === 0; }).map(function (s) {
                return el('li', {}, [el('span', { class: 'tro-legend__sw ' + s[2], 'aria-hidden': 'true' }),
                    el('span', {}, [el('b', { text: s[1] + ' ' + s[3] }), s[4] ? el('span', { class: 'tro-legend__sub', text: ' · ' + s[4] }) : null])]);
            }));
            previewEl.appendChild(el('div', { class: 'tro-preview__body' }, [meter, legend, p.due_rule_text ? el('div', { class: 'tro-hint mt-2', text: p.due_rule_text }) : null, sent ? plainBox(sent) : null]));

            var sample = p.sample || [];
            if (sample.length) {
                previewEl.appendChild(el('ul', { class: 'tro-sample', 'aria-label': 'People this rule matches' }, sample.slice(0, 6).map(sampleRow)));
                var totalSample = Number(p.sample_total || matched);
                if (totalSample > Math.min(6, sample.length)) {
                    previewEl.appendChild(el('div', { class: 'tro-preview__more' }, [el('button', { type: 'button', class: 'btn btn-outline-secondary w-100', on: { click: showAll } }, ['Show all ' + totalSample + ' ', u.icon('fas fa-arrow-right ms-1')])]));
                }
            } else if (matched === 0) {
                previewEl.appendChild(el('div', { class: 'tro-preview__placeholder' }, [u.icon('fas fa-user-slash'), st.newHiresOnly ? 'Nobody yet. New hires join as their hire dates are set.' : 'Nobody on the roster matches all of these conditions.']));
            }
            var overlaps = p.overlaps || {};
            if (overlaps.people > 0) {
                previewEl.appendChild(el('div', { class: 'tro-preview__note' }, [u.icon('fas fa-info-circle'),
                    el('span', { text: Number(overlaps.people) + ' of these people already ' + (Number(overlaps.people) === 1 ? 'matches' : 'match') + ' another rule for this course. They get one assignment, with the earlier due date.' })]));
            }
            var warnings = (p.warnings || []).filter(function (w) { return w && w.code; });
            if (warnings.length) {
                previewEl.appendChild(el('ul', { class: 'tro-warnings' }, warnings.map(function (w) {
                    var text = WARN_TEXT[w.code] || w.message || w.code;
                    if (w.code === 'everyone') { text = 'This rule applies to everyone on the training roster (' + u.plural(matched, 'person', 'people') + ').'; }
                    return el('li', { class: w.code === 'everyone' ? 'is-info' : null }, [u.icon(w.code === 'everyone' ? 'fas fa-globe' : 'fas fa-exclamation-triangle'), el('span', { text: text })]);
                })));
            }
            renderPreviewState();
            refreshSaveBar();
        }
        function plainBox(text) {
            return el('div', { class: 'tro-plain' }, [el('span', { class: 'tro-plain__label', text: 'In plain words' }), el('span', { text: text })]);
        }
        function sampleRow(s) {
            var p = s.person || {};
            return el('li', {}, [u.avatar(p.name), el('div', { class: 'tro-sample__text' }, [
                el('div', { class: 'tro-sample__name', text: p.name || '' }), el('div', { class: 'tro-sample__meta', text: u.personMeta(p) })
            ]), outcomeChip(s)]);
        }
        function showAll() {
            var list = el('ul', { class: 'tro-sample px-0' });
            var search = el('input', { type: 'search', class: 'form-control mb-2', placeholder: 'Filter by name, title or department', 'aria-label': 'Filter people' });
            var note = el('div', { class: 'tro-hint mb-2' });
            var body = el('div', {}, [search, note, list]);
            var all = [];
            function draw() {
                var q = search.value.trim().toLowerCase();
                while (list.firstChild) { list.removeChild(list.firstChild); }
                all.filter(function (s) { var p = s.person || {}; return !q || ((p.name || '') + ' ' + u.personMeta(p)).toLowerCase().indexOf(q) !== -1; })
                    .forEach(function (s) { list.appendChild(sampleRow(s)); });
            }
            search.addEventListener('input', draw);
            var h = Ops.sheet({ title: 'People this rule matches', subtitle: course() ? course().name : '', body: body, foot: [] });
            list.appendChild(el('li', { class: 'text-muted', text: 'Loading…' }));
            window.TrainingApi.post('rule_preview', previewPayload({ sample_limit: 1000 })).then(function (d) {
                all = (d && d.sample) || [];
                var total = Number((d && d.sample_total) || all.length);
                note.textContent = all.length < total ? 'Showing ' + all.length + ' of ' + total + '.' : u.plural(total, 'person', 'people') + '.';
                draw();
            }, function (err) { h.setBody(u.failState(err)); });
        }
        function renderStats() {
            var s = st.stats || {};
            var sent = sentence();
            previewEl.appendChild(el('div', { class: 'tro-preview__count' }, [el('span', { class: 'tro-preview__n', text: String(Number(s.matched || 0)) }),
                el('span', { class: 'tro-preview__label', text: Number(s.matched || 0) === 1 ? 'person matches' : 'people match' })]));
            previewEl.appendChild(el('div', { class: 'tro-preview__body' }, [
                el('ul', { class: 'tro-legend' }, [
                    el('li', {}, [el('span', { class: 'tro-legend__sw tro-meter__assign' }), el('span', {}, [el('b', { text: Number(s.open || 0) + ' open' }), el('span', { class: 'tro-legend__sub', text: ' · still to complete' })])]),
                    el('li', {}, [el('span', { class: 'tro-legend__sw', style: { background: 'var(--tro-err-fg)' } }), el('span', {}, [el('b', { text: Number(s.overdue || 0) + ' overdue' })])]),
                    el('li', {}, [el('span', { class: 'tro-legend__sw tro-meter__current' }), el('span', {}, [el('b', { text: Number(s.current || 0) + ' current' })])])
                ]),
                D.scope !== 'all' ? el('div', { class: 'tro-hint mt-2', text: 'Counts include only people in your departments.' }) : null,
                sent ? plainBox(sent) : null,
                st.id ? el('a', { class: 'btn btn-outline-secondary w-100 mt-3', href: '/agent/training_assignments.php?requirement_id=' + encodeURIComponent(st.id) + '&status=all', text: 'View its assignments' }) : null
            ]));
        }

        // ---- save bar ----------------------------------------------------------------------
        function problems() {
            var out = [];
            if (!course()) { out.push('choose a course'); }
            if (!hasCriteria()) { out.push('add a condition'); }
            if (!$('tro-rule-name').value.trim()) { out.push('name the rule'); }
            return out;
        }
        function refreshSaveBar() {
            var bar = $('tro-rule-savebar');
            bar.hidden = st.readOnly;
            if (st.readOnly) { return; }
            var msg = $('tro-rule-savemsg');
            var save = $('tro-rule-save');
            while (msg.firstChild) { msg.removeChild(msg.firstChild); }
            var todo = problems();
            var noRoute = D.routes && D.routes.rule_save === false;
            var unchanged = !st.isNew && !st.dirty;
            save.disabled = st.saving || todo.length > 0 || noRoute || unchanged || (st.preview && (st.preview.warnings || []).some(function (w) { return w.code === 'criteria_required'; }));
            if (noRoute) { msg.textContent = 'Saving rules is not available yet.'; return; }
            if (todo.length) { msg.textContent = 'To save, ' + joinAnd(todo) + '.'; return; }
            if (unchanged) { msg.textContent = 'No changes to save.'; return; }
            var n = st.preview ? Number(st.preview.will_assign || 0) : null;
            if (n === null) { msg.textContent = st.isNew ? 'Saving assigns this course right away.' : 'Saving updates assignments right away.'; return; }
            msg.appendChild(document.createTextNode(st.isNew ? 'Saving assigns this course to ' : 'Saving assigns it to '));
            msg.appendChild(el('b', { text: u.plural(n, 'person', 'people') }));
            msg.appendChild(document.createTextNode(n > 0 ? ' right away.' : ' now; more join as they match.'));
        }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            save();
        });
        function banner(type, iconCls, title, text, actions) {
            while (alerts.firstChild) { alerts.removeChild(alerts.firstChild); }
            if (!title) { return; }
            alerts.appendChild(el('div', { class: 'tr-banner alert alert-' + type + ' d-flex flex-wrap align-items-center gap-2 tro-rule-result', role: type === 'danger' ? 'alert' : 'status' }, [
                u.icon(iconCls), el('div', { class: 'me-auto' }, [el('div', { class: 'fw-semibold', text: title }), text ? el('div', { text: text }) : null])
            ].concat(actions || [])));
        }
        function save() {
            if (st.saving || st.readOnly) { return; }
            if (problems().length) { refreshSaveBar(); return; }
            var btn = $('tro-rule-save');
            st.saving = true;
            u.busy(btn, true, 'Saving…');
            u.clearFieldErrors(form);
            var body = {
                name: $('tro-rule-name').value.trim(), course_id: st.courseId, all_people: st.allPeople, criteria: criteria(),
                new_hires_only: st.newHiresOnly, due_days: st.dueDays, baseline_due_on: st.baseline, due_days_from_hire: st.hireDays,
                one_time: st.oneTime, required: st.required, note: st.note.trim() || null
            };
            if (st.id) { body.requirement_id = st.id; body.version = st.version; } else { body.request_uid = st.requestUid; }
            u.post('rule_save', body).then(function (d) {
                st.saving = false;
                st.dirty = false;
                u.busy(btn, false);
                var rule = (d && d.rule) || {};
                var rec = (d && d.reconcile) || {};
                var wasNew = st.isNew;
                if (rule.id) {
                    st.id = rule.id;
                    st.version = rule.version;
                    st.isNew = false;
                    st.courseLocked = true;
                    try { window.history.replaceState(null, '', '/agent/training_rule.php?id=' + encodeURIComponent(rule.id)); } catch (e) { /* ignore */ }
                }
                var draft = $('tro-rule-draft');
                if (draft) { draft.hidden = true; }
                var h1 = document.querySelector('#tro-rule-page .it-page-title');
                if (h1) { h1.textContent = rule.name || $('tro-rule-name').value.trim(); }
                var crumb = document.querySelector('#tro-rule-page .breadcrumb-item.active');
                if (crumb) { crumb.textContent = 'Rule'; }
                var c = course();
                var actions = [
                    el('a', { class: 'btn btn-sm btn-outline-dark', href: '/agent/training_assignments.php?requirement_id=' + encodeURIComponent(st.id || '') + '&status=open', text: 'View assignments' }),
                    el('a', { class: 'btn btn-sm btn-outline-dark', href: '/agent/training_assignments.php?tab=rules', text: 'Back to rules' })
                ];
                if (rec.error) {
                    UI.toast('Rule saved. Assignments will update on the next recalculation.', { type: 'warning' });
                    banner('warning', 'fas fa-check-circle', 'Rule saved.', 'Assignments will update on the next recalculation.', actions);
                } else {
                    var created = Number(rec.created || 0) + Number(rec.reopened || 0);
                    var text = created > 0
                        ? u.plural(created, 'person was', 'people were') + ' assigned ' + (c ? c.name : 'the course') + '.'
                        : 'No new assignments were needed' + (Number(rec.cancelled || 0) ? '; ' + u.plural(Number(rec.cancelled), 'assignment') + ' no longer required ' + (Number(rec.cancelled) === 1 ? 'was' : 'were') + ' closed' : '') + '.';
                    UI.toast(wasNew ? 'Rule saved.' : 'Rule updated.');
                    banner('success', 'fas fa-check-circle', wasNew ? 'Rule saved.' : 'Changes saved.', text, actions);
                }
                refreshName();
                renderCourse();
                refreshSaveBar();
                schedulePreview();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }, function (err) {
                st.saving = false;
                u.busy(btn, false);
                refreshSaveBar();
                if (err.code === 'conflict') {
                    banner('warning', 'fas fa-exclamation-triangle', 'Someone else changed this rule while you were editing.', 'Reload to see their version. Your changes on this page are not saved.', [
                        el('button', { type: 'button', class: 'btn btn-sm btn-outline-dark', text: 'Reload', on: { click: function () { st.dirty = false; window.location.reload(); } } })
                    ]);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    return;
                }
                var fields = Object.assign({}, err.fields || {});
                if (err.code === 'criteria_required') { fields.criteria = err.message || WARN_TEXT.criteria_required; }
                if (err.code === 'course_unpublished' || err.code === 'course_immutable') { fields.course_id = err.message; }
                var shown = u.showFieldErrors(form, { fields: fields });
                if (!shown || err.code !== 'validation') {
                    banner('danger', 'fas fa-exclamation-circle', 'The rule was not saved.', u.errorText(err));
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }
        window.addEventListener('beforeunload', function (e) {
            if (st.dirty && !st.readOnly) { e.preventDefault(); e.returnValue = ''; return ''; }
            return undefined;
        });
        $('tro-rule-cancel').addEventListener('click', function () { st.dirty = false; });

        // ---- archive (existing rule, level 3) ----------------------------------------------
        function wireArchive() {
            var btn = $('tro-rule-archive');
            if (!btn) { return; }
            btn.hidden = !(st.canEdit && st.id && !st.archived && D.routes && D.routes.rule_archive);
            btn.addEventListener('click', function () {
                var reason = u.reasonInput(5, 255, 'Why is this rule no longer needed?');
                var yes = el('button', { type: 'button', class: 'btn btn-sm btn-danger', text: 'Archive rule', disabled: true });
                var no = el('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', text: 'Cancel' });
                var msg = el('div', { class: 'small fw-semibold' });
                var bar = el('div', { class: 'tr-confirm-bar tro-reason-bar alert alert-warning', role: 'alertdialog', 'aria-label': 'Archive this rule' }, [
                    el('div', { class: 'fw-semibold', text: 'Archive this rule?' }),
                    el('div', { class: 'small', text: 'It stops assigning the course. Open assignments that no other rule requires are cancelled; completed records stay.' }),
                    reason.input, reason.counter, msg, el('div', { class: 'tro-reason-bar__row' }, [el('span', { class: 'me-auto' }), no, yes])
                ]);
                while (alerts.firstChild) { alerts.removeChild(alerts.firstChild); }
                alerts.appendChild(bar);
                reason.input.focus();
                reason.input.addEventListener('input', function () { yes.disabled = !reason.ok(); });
                no.addEventListener('click', function () { if (bar.parentNode) { bar.parentNode.removeChild(bar); } });
                yes.addEventListener('click', function () {
                    u.busy(yes, true, 'Archiving…');
                    u.post('rule_archive', { requirement_id: st.id, reason: reason.input.value.trim() }).then(function (d) {
                        var rec = (d && d.reconcile) || {};
                        UI.toast(rec.error ? 'Rule archived. Assignments will update on the next recalculation.' : 'Rule archived.');
                        st.dirty = false;
                        window.location.href = '/agent/training_assignments.php?tab=rules&archived=1';
                    }, function (err) {
                        u.busy(yes, false);
                        msg.textContent = u.errorText(err);
                    });
                });
            });
        }

        // ---- load --------------------------------------------------------------------------
        function applyRule(r) {
            st.id = Number(r.id);
            st.version = r.version;
            st.isNew = false;
            st.archived = !!r.archived;
            st.manual = !!r.is_manual;
            st.readOnly = !st.canEdit || st.archived || st.manual;
            st.name = r.name || '';
            st.nameTouched = true;
            st.courseId = r.course ? Number(r.course.id) : null;
            st.courseLocked = true;
            if (r.course && !u.courseById(st.courseId)) { Ops.state.courses.push(Object.assign({ published: false }, r.course)); }
            st.allPeople = !!r.all_people;
            var crit = r.criteria || {};
            var labels = r.labels || {};
            st.labels = {};
            Object.keys(labels).forEach(function (k) {
                st.labels[k] = {};
                (labels[k] || []).forEach(function (l) { st.labels[k][l.id] = { name: l.name, known: l.known !== false }; });
            });
            st.kinds = [];
            KIND_ORDER.forEach(function (k) {
                st.values[k] = (crit[k] || []).map(Number);
                if (st.values[k].length) { st.kinds.push(k); }
            });
            st.contactPeople = (labels.contact || []).map(function (l) { return { contact_id: Number(l.id), name: l.name }; });
            st.contactHidden = Number(r.contact_hidden || 0);
            if (st.contactHidden && st.kinds.indexOf('contact') === -1) { st.kinds.push('contact'); }
            st.newHiresOnly = !!r.new_hires_only;
            st.dueDays = Number(r.due_days !== undefined ? r.due_days : 30);
            st.baseline = r.baseline_due_on || u.addDays(today, st.dueDays);
            st.hireDays = Number(r.due_days_from_hire !== undefined ? r.due_days_from_hire : 7);
            st.required = r.required !== false;
            st.oneTime = !!r.one_time;
            st.note = r.note || '';
            st.effectiveOn = r.effective_on || today;
            st.stats = r.stats || null;
            $('tro-rule-name').value = st.name;
            var metaBits = [];
            if (r.created_by_name) { metaBits.push('Created by ' + r.created_by_name + (r.created_at ? ' on ' + u.fmtDate(String(r.created_at).slice(0, 10)) : '')); }
            if (st.archived) {
                banner('secondary', 'fas fa-archive', 'This rule is archived.', 'It no longer assigns anything. ' + metaBits.join(''));
            } else if (st.manual) {
                banner('info', 'fas fa-hand-pointer', 'Created with Assign training.', 'Rules made by hand are read-only. To change who is assigned, assign or waive people on the Assignments tab. ' + metaBits.join(''));
            } else if (!st.canEdit) {
                banner('info', 'fas fa-eye', 'You can view this rule.', 'Only people with Full Training access can change rules. ' + metaBits.join(''));
            }
        }
        function lockInputs() {
            if (!st.readOnly) { return; }
            Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select'), function (n) { n.disabled = true; });
        }
        function start() {
            renderCourse();
            renderRenewal();
            renderRows();
            renderWhen();
            refreshName();
            lockInputs();
            refreshSaveBar();
            wireArchive();
            if (st.canEdit && !st.readOnly && (!OPTS.odoo_job || !OPTS.jobgroup)) {
                // Option lists from other lanes: fetch when the page could not embed them.
                var waits = [];
                if (OPTS.odoo_job === null) { waits.push(u.fetchAction('odoo_attr_options', {}).then(setAttrOptions, function () { /* rows explain */ })); }
                if (OPTS.jobgroup === null) { waits.push(u.fetchAction('jobgroup_list', {}).then(setGroups, function () { /* rows explain */ })); }
                Promise.all(waits).then(function () { renderRows(); lockInputs(); });
            }
            schedulePreview();
            if (st.isNew) { setTimeout(function () { var s = $('tro-rule-course-select'); if (s) { s.focus(); } }, 50); }
        }

        if (D.rule_id) {
            u.load(D.rule).then(function (r) {
                applyRule(r || {});
                start();
            }, function (err) {
                var host = $('tro-rule');
                while (host.firstChild) { host.removeChild(host.firstChild); }
                host.style.display = 'block';
                host.appendChild(el('div', { class: 'tro-card' }, [u.failState(err, function () { window.location.reload(); })]));
            });
        } else {
            if (!st.canEdit) {
                banner('info', 'fas fa-lock', 'Only people with Full Training access can create rules.', 'Ask an administrator if you need to require training.');
                st.readOnly = true;
            }
            var pre = new URLSearchParams(window.location.search).get('course_id');
            if (pre && u.courseById(pre)) { st.courseId = Number(pre); }
            start();
        }
    });
})();
